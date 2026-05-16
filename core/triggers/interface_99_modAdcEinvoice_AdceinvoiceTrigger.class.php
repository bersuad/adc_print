<?php
/**
 * Trigger for ADC eInvoicing integration
 * 
 * Hooks into Dolibarr invoice lifecycle to sync with ADC API
 * 
 * @package Dolibarr\Modules\AdcEinvoice
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

class InterfaceAdceinvoiceTrigger extends DolibarrTriggers
{
    public $name;
    public $family;
    public $description;
    public $version;
    
    public $picto = 'adceinvoice@adceinvoice';
    
    public function __construct($db)
    {
        parent::__construct($db);
        
        $this->name = preg_replace('/^Interface/i', '', get_class($this));
        $this->family = "billing";
        $this->description = "Triggers for ADC eInvoicing: auto-sync invoices, handle offline queue";
        $this->version = '1.0.0';
    }
    
    public function runTrigger($action, $object, $user, $langs, $conf)
    {
        // Only process if module enabled
        if (empty($conf->adceinvoice->enabled)) {
            return 0;
        }
        
        // Only process invoice-related actions
        if (!in_array($object->element, ['invoice', 'facture'])) {
            return 0;
        }
        
        dol_include_once('/custom/adceinvoice/class/adceinvoice_api.class.php');
        dol_include_once('/custom/adceinvoice/lib/adceinvoice.lib.php');
        
        try {
            switch ($action) {
                case 'BILL_VALIDATE':
                    return $this->onInvoiceValidate($object, $user, $langs, $conf);
                    
                case 'BILL_DELETE':
                    return $this->onInvoiceDelete($object, $user, $langs, $conf);
                    
                case 'PAYMENT_ADD':
                    return $this->onPaymentAdd($object, $user, $langs, $conf);
            }
        } catch (Exception $e) {
            adceinvoice_log('Trigger error: '.$e->getMessage(), 'error', [
                'action' => $action,
                'object_id' => $object->id ?? null,
                'trace' => $e->getTraceAsString()
            ]);
            // Don't block Dolibarr operation on sync failure
            return 0;
        }
        
        return 0;
    }
    
    /**
     * Handle invoice validation - sync to ADC
     */
    private function onInvoiceValidate($object, $user, $langs, $conf): int
    {
        global $db;
        
        // Check if should sync
        if (!adceinvoice_should_sync($object)) {
            return 0;
        }
        
        // Prepare API payload
        $items = [];
        $lineNumber = 1;
        
        foreach ($object->lines as $line) {
            $items[] = adceinvoice_build_api_item($line, $lineNumber++);
        }
        
        $valueDetails = adceinvoice_build_value_details($object, $items);
        
        $invoiceData = [
            'trnx_id' => AdcEinvoiceApi::generateTrnxId('TRNXINV'),
            'date' => AdcEinvoiceApi::formatDateForApi($object->date),
            'invoice_no_main' => '', // Let ADC assign
            'payment_mode' => 'CASH', // Could map from Dolibarr payment terms
            'customer_phone' => $object->thirdparty->phone ?? '',
            'customer_email' => $object->thirdparty->email ?? '',
            'items' => $items,
            'value_details' => $valueDetails,
            'entity' => $conf->entity,
        ];
        
        // Determine B2B vs B2C
        $businessType = !empty($object->thirdparty->tva_intra) ? 'B2B' : 'B2C';
        $buyerDetails = ($businessType === 'B2B') ? [
            'tin' => $object->thirdparty->tva_intra,
            'legal_name' => $object->thirdparty->name,
            'id_type' => 'KID', // Adjust based on your requirements
        ] : null;
        
        // Try immediate sync if auto-sync enabled
        if (getDolGlobalInt('ADCEINVOICE_AUTO_SYNC')) {
            $api = new AdcEinvoiceApi($db);
            $response = $api->registerTransaction('INV', $invoiceData, $buyerDetails, $businessType);
            
            if ($response) {
                // Success - store IRN and mark synced
                $morData = $response['data']['mor_data'];
                
                // Update invoice with ADC data (using extrafields or notes)
                $object->array_options['options_adceinvoice_irn'] = $morData['irn'] ?? null;
                $object->array_options['options_adceinvoice_invoice_no'] = $morData['invoice_no'] ?? null;
                $object->array_options['options_adceinvoice_voucher'] = $morData['voucher_no'] ?? null;
                $object->array_options['options_adceinvoice_qr'] = $morData['qr_data'] ?? null;
                
                $object->update($user);
                
                adceinvoice_log('Invoice synced successfully', 'info', [
                    'invoice_id' => $object->id,
                    'irn' => $morData['irn'],
                    'invoice_no' => $morData['invoice_no']
                ]);
                
                // Trigger print if enabled
                if (getDolGlobalInt('ADCEINVOICE_PRINT_ENABLED')) {
                    $this->triggerPrint($morData);
                }
                
                return 1;
            } else {
                // Failed - queue for retry
                $errors = $api->getErrors();
                adceinvoice_log('Sync failed, queuing for retry', 'warning', [
                    'invoice_id' => $object->id,
                    'errors' => $errors
                ]);
            }
        }
        
        // Queue for offline sync
        $queueId = adceinvoice_queue_transaction(
            $db,
            'invoice',
            $object->id,
            'INV',
            array_merge($invoiceData, ['buyer_details' => $buyerDetails, 'business_type' => $businessType]),
            $businessType
        );
        
        if ($queueId) {
            adceinvoice_log('Invoice queued for sync', 'info', [
                'invoice_id' => $object->id,
                'queue_id' => $queueId
            ]);
        }
        
        return 1;
    }
    
    /**
     * Handle payment registration - sync receipt if needed
     */
    private function onPaymentAdd($object, $user, $langs, $conf): int
    {
        // Implementation for sales receipt sync
        // Similar pattern to invoice validation
        return 0;
    }
    
    /**
     * Handle invoice deletion - cancel in ADC if synced
     */
    private function onInvoiceDelete($object, $user, $langs, $conf): int
    {
        // Check if was synced to ADC
        $irn = $object->array_options['options_adceinvoice_irn'] ?? null;
        
        if ($irn) {
            // Attempt to cancel in ADC
            // Implementation similar to registerTransaction
            adceinvoice_log('Attempting to cancel synced invoice in ADC', 'info', [
                'invoice_id' => $object->id,
                'irn' => $irn
            ]);
        }
        
        return 0;
    }
    
    /**
     * Trigger print job for Neka device
     */
    private function triggerPrint(array $morData): void
    {
        dol_include_once('/custom/adceinvoice/class/adceinvoice_printer.class.php');
        
        $printer = new AdcEinvoicePrinter();
        $printer->printInvoice($morData);
    }
}