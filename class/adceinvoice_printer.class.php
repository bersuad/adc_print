<?php

if (!defined('NOLOGIN')) define('NOLOGIN', 1);

class AdcEinvoicePrinter
{
    private $devicePath;
    private $connectionType; // 'usb', 'serial', 'network', 'file'
    private $connection;
    
    // Printer configuration
    private $paperWidth = 80; // mm (standard thermal)
    private $charsPerLine = 42; // Approx for 80mm paper at 12px font
    private $amharicFontSupport = true;
    
    // ESC/POS commands (common thermal printer protocol)
    const ESC = "\x1B";
    const FS  = "\x1C";
    const GS  = "\x1D";
    
    // Text formatting
    const TXT_NORMAL      = self::ESC . "!\\x00";
    const TXT_BOLD        = self::ESC . "!\\x01";
    const TXT_UNDERLINE   = self::ESC . "-\\x01";
    const TXT_UNDERLINE2  = self::ESC . "-\\x02";
    const TXT_DOUBLE_HEIGHT = self::ESC . "!\\x10";
    const TXT_DOUBLE_WIDTH  = self::ESC . "!\\x20";
    const TXT_BIG         = self::ESC . "!\\x30";
    
    // Alignment
    const TXT_ALIGN_LT    = self::ESC . "a\\x00";
    const TXT_ALIGN_CT    = self::ESC . "a\\x01";
    const TXT_ALIGN_RT    = self::ESC . "a\\x02";
    
    // Line feed & cut
    const LF = "\n";
    const CUT = self::GS . "V\\x00";
    
    private $buffer = '';
    private $errors = [];
    
    /**
     * Constructor
     * 
     * @param string $devicePath Device path or connection string
     * @param string $connectionType Connection type
     */
    public function __construct($devicePath = null, $connectionType = 'file')
    {
        global $conf;
        
        $this->devicePath = $devicePath ?? getDolGlobalString('ADCEINVOICE_PRINTER_PATH', $conf->adceinvoice->dir_output.'/print_queue');
        $this->connectionType = $connectionType ?? getDolGlobalString('ADCEINVOICE_PRINTER_TYPE', 'file');
    }
    
    /**
     * Print invoice receipt from ADC API response
     * 
     * @param array $morData ADC API mor_data response
     * @param array $paymentData Optional payment_data response
     * @return bool Success
     */
    public function printInvoice(array $morData, array $paymentData = []): bool
    {
        try {
            $this->reset();
            
            // Header: Merchant info
            $this->printHeader($morData);
            
            // Invoice details
            $this->printInvoiceDetails($morData);
            
            // Line items
            $this->printLineItems($morData['sales_items'] ?? []);
            
            // Totals section
            $this->printTotals($morData);
            
            // Payment info
            $this->printPaymentInfo($morData, $paymentData);
            
            // QR Code & IRN
            $this->printQrAndIrn($morData);
            
            // Footer
            $this->printFooter($morData);
            
            // Cut paper & send
            $this->buffer .= self::CUT . self::LF;
            
            return $this->send();
            
        } catch (Exception $e) {
            $this->errors[] = 'Print error: ' . $e->getMessage();
            adceinvoice_log('Printer exception: ' . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Print sales receipt (payment confirmation)
     * 
     * @param array $receiptData ADC sales_receipt response
     * @return bool
     */
    public function printSalesReceipt(array $receiptData): bool
    {
        $this->reset();
        
        $this->printHeader($receiptData['mor_data'] ?? []);
        
        $this->buffer .= self::TXT_ALIGN_CT . self::TXT_BOLD . "SALES RECEIPT" . self::TXT_NORMAL . self::LF;
        $this->buffer .= self::TXT_ALIGN_CT . "----------------" . self::LF . self::LF;
        
        // Receipt details from QR data JSON
        if (!empty($receiptData['mor_data']['qr_data'])) {
            $qrJson = @json_decode($receiptData['mor_data']['qr_data'], true);
            if ($qrJson) {
                $this->printRow("Receipt No:", $qrJson['ReceiptNumber'] ?? '');
                $this->printRow("Date:", date('Y-m-d H:i', strtotime($qrJson['ReceiptDate'] ?? 'now')));
                $this->printRow("Type:", $qrJson['ReceiptType'] ?? 'Payment');
                $this->buffer .= self::LF;
                $this->printRow("Amount Collected:", number_format($qrJson['CollectedAmount'] ?? 0, 2) . ' ETB', true);
                $this->printRow("Currency:", $qrJson['ReceiptCurrency'] ?? 'ETB');
                $this->buffer .= self::LF;
                $this->printRow("RRN:", $qrJson['RRN'] ?? '');
            }
        }
        
        $this->buffer .= self::LF . self::TXT_ALIGN_CT . "* Thank you for your business *" . self::LF;
        $this->buffer .= self::CUT . self::LF;
        
        return $this->send();
    }
    
    /**
     * Print withholding receipt
     * 
     * @param array $withholdingData ADC withholding response
     * @return bool
     */
    public function printWithholdingReceipt(array $withholdingData): bool
    {
        $this->reset();
        
        $this->printHeader($withholdingData['mor_data'] ?? []);
        
        $this->buffer .= self::TXT_ALIGN_CT . self::TXT_BOLD . "WITHHOLDING RECEIPT" . self::TXT_NORMAL . self::LF;
        $this->buffer .= self::TXT_ALIGN_CT . "----------------------" . self::LF . self::LF;
        
        if (!empty($withholdingData['mor_data']['qr_data'])) {
            $qrJson = @json_decode($withholdingData['mor_data']['qr_data'], true);
            if ($qrJson) {
                $this->printRow("Receipt No:", $qrJson['ReceiptNumber'] ?? '');
                $this->printRow("Date:", date('Y-m-d H:i', strtotime($withholdingData['mor_data']['invoice_date'] ?? 'now')));
                $this->buffer .= self::LF;
                $this->printRow("Pre-Tax Amount:", number_format(floatval($qrJson['PreTaxAmount'] ?? 0), 2) . ' ETB');
                $this->printRow("Withheld Amount:", number_format($qrJson['WithholdAmount'] ?? 0, 2) . ' ETB', true);
                $this->buffer .= self::LF;
                $this->printRow("RRN:", $qrJson['RRN'] ?? '');
            }
        }
        
        $this->buffer .= self::LF . self::TXT_ALIGN_CT . "* Official Withholding Document *" . self::LF;
        $this->buffer .= self::CUT . self::LF;
        
        return $this->send();
    }
    
    /**
     * Print report (Z/X report)
     * 
     * @param array $reportData ADC report response
     * @param string $reportType Report type
     * @return bool
     */
    public function printReport(array $reportData, string $reportType = 'daily_z'): bool
    {
        $this->reset();
        
        $this->buffer .= self::TXT_ALIGN_CT . self::TXT_BIG . "DAILY Z REPORT" . self::TXT_NORMAL . self::LF;
        $this->buffer .= self::TXT_ALIGN_CT . "==================" . self::LF . self::LF;
        
        // Date range
        if (!empty($reportData['Report date']) && is_array($reportData['Report date'])) {
            $this->printRow("From:", $reportData['Report date'][0] ?? '');
            $this->printRow("To:", $reportData['Report date'][1] ?? '');
            $this->buffer .= self::LF;
        }
        
        // Sales summary
        $this->buffer .= self::TXT_BOLD . "SALES SUMMARY" . self::TXT_NORMAL . self::LF;
        $this->buffer .= "----------------" . self::LF;
        $this->printRow("Invoices Issued:", $reportData['Sales invoice issued'] ?? 0);
        $this->printRow("Refunds Issued:", $reportData['Refund invoice issued'] ?? 0);
        $this->buffer .= self::LF;
        
        $this->printRow("Sales Total:", number_format($reportData['Sales Total'] ?? 0, 2) . ' ETB');
        $this->printRow("Taxable Sales:", number_format($reportData['Taxable Sales Total'] ?? 0, 2) . ' ETB');
        $this->printRow("Non-Taxable:", number_format($reportData['Non-taxable Sales Total'] ?? 0, 2) . ' ETB');
        $this->buffer .= self::LF;
        
        $this->printRow("VAT Total:", number_format($reportData['VAT Total'] ?? 0, 2) . ' ETB', true);
        $this->buffer .= self::LF;
        
        // Payment methods
        if (!empty($reportData['via_cash Total'])) {
            $this->buffer .= self::TXT_BOLD . "PAYMENT METHODS" . self::TXT_NORMAL . self::LF;
            $this->buffer .= "----------------" . self::LF;
            $this->printRow("Cash:", number_format($reportData['via_cash Total'] ?? 0, 2) . ' ETB');
            $this->printRow("Cheque:", number_format($reportData['via_cheque Total'] ?? 0, 2) . ' ETB');
            $this->printRow("Credit Card:", number_format($reportData['via_credit Total'] ?? 0, 2) . ' ETB');
            $this->printRow("Transfer:", number_format($reportData['via_direct_transfer Total'] ?? 0, 2) . ' ETB');
            $this->buffer .= self::LF;
        }
        
        // Invoice range
        if (!empty($reportData['First Invoice No'])) {
            $this->printRow("First Invoice:", $reportData['First Invoice No']);
            $this->printRow("Last Invoice:", $reportData['Last Invoice No']);
        }
        
        $this->buffer .= self::LF . self::TXT_ALIGN_CT . "==================" . self::LF;
        $this->buffer .= self::TXT_ALIGN_CT . "* End of Report *" . self::LF . self::LF;
        $this->buffer .= self::CUT . self::LF;
        
        return $this->send();
    }
    
    /**
     * Print receipt header with merchant info
     */
    private function printHeader(array $morData): void
    {
        global $conf, $mysoc;
        
        // Company name (bold, centered, double size)
        $this->buffer .= self::TXT_ALIGN_CT . self::TXT_BIG . self::TXT_BOLD;
        $this->buffer .= dol_trunc($mysoc->name ?? 'MERCHANT', 30) . self::LF;
        $this->buffer .= self::TXT_NORMAL . self::LF;
        
        // Address lines
        if (!empty($mysoc->address)) {
            $this->buffer .= self::TXT_ALIGN_CT;
            foreach (explode("\n", $mysoc->address) as $line) {
                $this->buffer .= dol_trunc($line, $this->charsPerLine) . self::LF;
            }
            $this->buffer .= self::LF;
        }
        
        // TIN & Device info
        $this->buffer .= self::TXT_ALIGN_LT;
        $this->printRow("TIN:", getDolGlobalString('ADCEINVOICE_TIN', $mysoc->tva_intra ?? 'N/A'));
        $this->printRow("Device ID:", getDolGlobalString('ADCEINVOICE_DEVICE_ID', 'N/A'));
        $this->buffer .= self::LF;
        
        // Separator
        $this->buffer .= str_repeat("-", $this->charsPerLine) . self::LF;
    }
    
    /**
     * Print invoice identification details
     */
    private function printInvoiceDetails(array $morData): void
    {
        $this->buffer .= self::TXT_ALIGN_LT;
        
        // Invoice number & type
        $typeLabel = match($morData['invoice_type'] ?? '') {
            'known_item' => 'INVOICE',
            'cnote_item' => 'CREDIT NOTE',
            'dnote_item' => 'DEBIT NOTE',
            default => 'RECEIPT',
        };
        
        $this->printRow("Type:", $typeLabel);
        $this->printRow("Invoice No:", $morData['invoice_no'] ?? $morData['voucher_no'] ?? 'N/A');
        $this->printRow("IRN:", dol_trunc($morData['irn'] ?? '', 30));
        $this->printRow("Date:", date('Y-m-d H:i:s', strtotime($morData['invoice_date'] ?? 'now')));
        
        // Business type & buyer
        if (!empty($morData['bussiness_type'])) {
            $this->printRow("Type:", $morData['bussiness_type']);
        }
        
        if (!empty($morData['Invoice_buyer_name']) && $morData['Invoice_buyer_name'] !== '') {
            $this->buffer .= self::LF . self::TXT_BOLD . "BILL TO:" . self::TXT_NORMAL . self::LF;
            $this->printRow("Name:", $morData['Invoice_buyer_name']);
            if (!empty($morData['Invoice_buyer_tin']) && $morData['Invoice_buyer_tin'] !== 'XXXXXXXXXX') {
                $this->printRow("TIN:", $morData['Invoice_buyer_tin']);
            }
        }
        
        $this->buffer .= self::LF . str_repeat("-", $this->charsPerLine) . self::LF;
    }
    
    /**
     * Print line items table
     */
    private function printLineItems(array $items): void
    {
        if (empty($items)) {
            return;
        }
        
        // Header
        $this->buffer .= self::TXT_ALIGN_LT . self::TXT_BOLD;
        $this->buffer .= sprintf("%-4s %-20s %6s %8s\n", "Qty", "Description", "Price", "Total");
        $this->buffer .= self::TXT_NORMAL . str_repeat("-", $this->charsPerLine) . self::LF;
        
        // Items
        foreach ($items as $item) {
            // Handle both array format [desc, price, qty] and object format
            if (is_array($item) && count($item) === 3) {
                [$desc, $price, $qty] = $item;
                $total = $price * $qty;
            } else {
                $desc = $item['ProductDescription'] ?? 'Item';
                $price = floatval($item['UnitPrice'] ?? 0);
                $qty = floatval($item['Quantity'] ?? 1);
                $total = floatval($item['TotalLineAmount'] ?? ($price * $qty));
            }
            
            // Truncate description to fit
            $desc = dol_trunc($desc, 20);
            
            $this->buffer .= sprintf("%4.0f %-20s %6.2f %8.2f\n", 
                $qty, $desc, $price, $total);
        }
        
        $this->buffer .= str_repeat("-", $this->charsPerLine) . self::LF;
    }
    
    /**
     * Print totals section
     */
    private function printTotals(array $morData): void
    {
        $this->buffer .= self::TXT_ALIGN_RT;
        
        // Subtotal
        $salesTotal = floatval($morData['sales_total'] ?? 0);
        $this->printRowRight("Subtotal:", number_format($salesTotal, 2) . ' ETB');
        
        // VAT
        $vatTotal = floatval($morData['vat_tax_total'] ?? $morData['vat_total'] ?? 0);
        if ($vatTotal > 0) {
            $this->printRowRight("VAT (15%):", number_format($vatTotal, 2) . ' ETB');
        }
        
        // Excise tax
        $exciseTotal = floatval($morData['excise_total'] ?? 0);
        if ($exciseTotal > 0) {
            $this->printRowRight("Excise Tax:", number_format($exciseTotal, 2) . ' ETB');
        }
        
        // Discount
        $discountTotal = floatval($morData['discount_total'] ?? 0);
        if ($discountTotal > 0) {
            $this->printRowRight("Discount:", '-' . number_format($discountTotal, 2) . ' ETB');
        }
        
        $this->buffer .= str_repeat("-", $this->charsPerLine) . self::LF;
        
        // Grand Total (bold, larger)
        $grandTotal = floatval($morData['grand_total'] ?? $salesTotal + $vatTotal);
        $this->buffer .= self::TXT_BOLD . self::TXT_DOUBLE_HEIGHT;
        $this->printRowRight("TOTAL:", number_format($grandTotal, 2) . ' ETB');
        $this->buffer .= self::TXT_NORMAL . self::LF . self::LF;
    }
    
    /**
     * Print payment information
     */
    private function printPaymentInfo(array $morData, array $paymentData): void
    {
        $this->buffer .= self::TXT_ALIGN_LT;
        
        // Payment mode
        $modeMap = [
            'A' => 'Cash',
            'B' => 'Cheque',
            'C' => 'Credit Card',
            'D' => 'Bank Transfer',
            'E' => 'Mobile Payment',
        ];
        $modeCode = $morData['Invoice_payment_mode'] ?? $paymentData['payment_mode'] ?? 'A';
        $modeLabel = $modeMap[$modeCode] ?? 'Cash';
        
        $this->printRow("Payment Method:", $modeLabel);
        
        // Payment reference
        if (!empty($paymentData['payment_code'])) {
            $this->printRow("Payment Ref:", $paymentData['payment_code']);
        }
        
        // Status
        $status = $morData['invoice_status'] ?? 'active';
        $statusLabel = ucfirst($status);
        $this->printRow("Status:", $statusLabel);
        
        $this->buffer .= self::LF;
    }
    
    /**
     * Print QR code placeholder and IRN
     * 
     * Note: Actual QR rendering depends on printer capabilities.
     * This prints the QR data URL and a text fallback.
     */
    private function printQrAndIrn(array $morData): void
    {
        $this->buffer .= self::TXT_ALIGN_CT;
        $this->buffer .= "[QR CODE]" . self::LF;
        
        // Print QR data URL (for verification)
        if (!empty($morData['qr_data'])) {
            $qrData = $morData['qr_data'];
            // Truncate long QR URLs for display
            if (strlen($qrData) > 50) {
                $qrData = substr($qrData, 0, 47) . '...';
            }
            $this->buffer .= self::TXT_ALIGN_LT . self::TXT_UNDERLINE;
            $this->buffer .= dol_trunc($qrData, $this->charsPerLine) . self::LF;
            $this->buffer .= self::TXT_NORMAL . self::LF;
        }
        
        // IRN for manual verification
        if (!empty($morData['irn'])) {
            $this->buffer .= "IRN: " . $morData['irn'] . self::LF;
        }
        
        $this->buffer .= self::LF . str_repeat("=", $this->charsPerLine) . self::LF;
    }
    
    /**
     * Print footer with legal text
     */
    private function printFooter(array $morData): void
    {
        global $langs;
        
        $this->buffer .= self::TXT_ALIGN_CT . self::TXT_UNDERLINE;
        
        // Fiscal compliance notice
        $this->buffer .= "FISCAL RECEIPT - MoR COMPLIANT" . self::LF;
        $this->buffer .= "Generated via ADC eInvoicing System" . self::LF;
        
        $this->buffer .= self::TXT_NORMAL . self::LF;
        
        // Thank you message (support Amharic if configured)
        if ($this->amharicFontSupport) {
            $this->buffer .= "አመሰግናለሁ! / Thank You!" . self::LF;
        } else {
            $this->buffer .= "Thank you for your business!" . self::LF;
        }
        
        $this->buffer .= self::LF . "Copy for Customer" . self::LF;
    }
    
    /**
     * Helper: Print a row with left label and right value
     */
    private function printRow(string $label, string $value, bool $bold = false): void
    {
        if ($bold) {
            $this->buffer .= self::TXT_BOLD;
        }
        
        $labelWidth = 18;
        $valueWidth = $this->charsPerLine - $labelWidth - 2;
        
        $this->buffer .= sprintf("%-{$labelWidth}s %{$valueWidth}s\n", 
            $label . ':', dol_trunc($value, $valueWidth));
        
        if ($bold) {
            $this->buffer .= self::TXT_NORMAL;
        }
    }
    
    /**
     * Helper: Print a row with right-aligned label and value
     */
    private function printRowRight(string $label, string $value): void
    {
        $totalWidth = $this->charsPerLine;
        $labelWidth = 18;
        
        $this->buffer .= sprintf("%{$labelWidth}s %s\n", 
            $label . ':', dol_trunc($value, $totalWidth - $labelWidth - 2));
    }
    
    /**
     * Reset printer buffer and state
     */
    private function reset(): void
    {
        $this->buffer = '';
        $this->errors = [];
        
        // Initialize: normal text, left align
        $this->buffer .= self::TXT_NORMAL . self::TXT_ALIGN_LT;
    }
    
    /**
     * Send buffer to printer based on connection type
     * 
     * @return bool Success
     */
    private function send(): bool
    {
        if (empty($this->buffer)) {
            $this->errors[] = 'Nothing to print';
            return false;
        }
        
        adceinvoice_log('Sending print job', 'debug', [
            'type' => $this->connectionType,
            'path' => $this->devicePath,
            'size' => strlen($this->buffer)
        ]);
        
        switch ($this->connectionType) {
            case 'file':
                return $this->sendToFile();
                
            case 'network':
                return $this->sendToNetwork();
                
            case 'serial':
                return $this->sendToSerial();
                
            case 'usb':
                return $this->sendToUsb();
                
            default:
                $this->errors[] = "Unsupported connection type: {$this->connectionType}";
                return false;
        }
    }
    
    /**
     * Send to file queue (for CUPS or print daemon)
     */
    private function sendToFile(): bool
    {
        $printFile = $this->devicePath . '/print_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.prn';
        
        $result = @file_put_contents($printFile, $this->buffer);
        
        if ($result === false) {
            $this->errors[] = "Failed to write print file: $printFile";
            return false;
        }
        
        // Optional: trigger CUPS print command
        $printerName = getDolGlobalString('ADCEINVOICE_CUPS_PRINTER', 'neka_printer');
        if ($printerName) {
            $cmd = sprintf("lp -d %s %s 2>&1", escapeshellarg($printerName), escapeshellarg($printFile));
            $output = [];
            $returnVar = 0;
            @exec($cmd, $output, $returnVar);
            
            if ($returnVar !== 0) {
                adceinvoice_log("CUPS print command failed: " . implode('; ', $output), 'warning');
                // Don't fail - file is still queued for manual printing
            }
        }
        
        adceinvoice_log('Print job queued to file', 'info', ['file' => $printFile]);
        return true;
    }
    
    /**
     * Send to network printer (raw socket)
     */
    private function sendToNetwork(): bool
    {
        // Parse connection string: tcp://192.168.1.100:9100
        $parsed = parse_url($this->devicePath);
        $host = $parsed['host'] ?? '192.168.1.100';
        $port = $parsed['port'] ?? 9100; // Standard raw printing port
        
        $socket = @fsockopen($host, $port, $errno, $errstr, 5);
        
        if (!$socket) {
            $this->errors[] = "Network connection failed: $errstr ($errno)";
            return false;
        }
        
        // Set timeout
        stream_set_timeout($socket, 10);
        
        // Send data
        $result = @fwrite($socket, $this->buffer);
        @fclose($socket);
        
        if ($result === false || $result !== strlen($this->buffer)) {
            $this->errors[] = "Failed to send data to network printer";
            return false;
        }
        
        adceinvoice_log('Print job sent to network printer', 'info', ['host' => "$host:$port"]);
        return true;
    }
    
    /**
     * Send to serial port printer
     */
    private function sendToSerial(): bool
    {
        // Requires php_serial extension or system command
        $device = $this->devicePath; // e.g., /dev/ttyUSB0
        
        if (!file_exists($device)) {
            $this->errors[] = "Serial device not found: $device";
            return false;
        }
        
        // Use system stty + cat command (Linux)
        $cmd = sprintf(
            'stty -F %s 9600 cs8 -cstopb -parenb raw; echo -n %s > %s',
            escapeshellarg($device),
            escapeshellarg($this->buffer),
            escapeshellarg($device)
        );
        
        $output = [];
        $returnVar = 0;
        @exec($cmd, $output, $returnVar);
        
        if ($returnVar !== 0) {
            $this->errors[] = "Serial print command failed: " . implode('; ', $output);
            return false;
        }
        
        adceinvoice_log('Print job sent to serial printer', 'info', ['device' => $device]);
        return true;
    }
    
    /**
     * Send to USB printer (via libusb or system command)
     */
    private function sendToUsb(): bool
    {
        // USB printing is complex; delegate to CUPS or file queue
        adceinvoice_log('USB printing delegated to file queue', 'debug');
        $this->connectionType = 'file';
        return $this->sendToFile();
    }
    
    /**
     * Get accumulated errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
    
    /**
     * Test printer connection
     * 
     * @return bool
     */
    public function testConnection(): bool
    {
        $this->reset();
        
        $this->buffer .= self::TXT_ALIGN_CT . self::TXT_BOLD;
        $this->buffer .= "PRINTER TEST" . self::LF;
        $this->buffer .= self::TXT_NORMAL . str_repeat("-", $this->charsPerLine) . self::LF;
        $this->buffer .= "Date: " . date('Y-m-d H:i:s') . self::LF;
        $this->buffer .= "Module: ADC eInvoicing v1.0" . self::LF;
        $this->buffer .= "Connection: {$this->connectionType}" . self::LF;
        $this->buffer .= "Path: {$this->devicePath}" . self::LF;
        $this->buffer .= self::LF . "If you see this, printing works!" . self::LF;
        $this->buffer .= self::CUT . self::LF;
        
        return $this->send();
    }
    
    /**
     * Generate raw ESC/POS command for QR code
     * 
     * Note: Implementation depends on printer model.
     * This is a generic example for GS ( k command.
     * 
     * @param string $data QR content
     * @return string ESC/POS commands
     */
    public static function generateQrCommand(string $data): string
    {
        // This is a simplified example - real implementation needs:
        // 1. QR model selection
        // 2. Error correction level
        // 3. Size/module configuration
        // 4. Proper byte encoding
        
        // Placeholder: Most thermal printers support GS ( k for QR
        // Reference: https://reference.epson-biz.com/modules/poscp/index.php?content_id=244
        
        $qr = self::GS . '(k';
        $qr .= "\x04\x00\x31\x41\x32\x00"; // Model 2, Level M
        $qr .= "\x03\x00\x31\x43\x04"; // Module size 4
        $qr .= "\x03\x00\x31\x45\x00"; // No margin
        
        // Encode data (simplified - real implementation needs byte counting)
        $qr .= "\x03\x00\x31\x50\x30"; // Auto encoding
        $qr .= $data;
        $qr .= "\x00"; // Terminator
        
        // Print QR command
        $qr .= self::GS . '(k' . "\x03\x00\x31\x51\x30";
        
        return $qr;
    }
}