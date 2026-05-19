<?php
/**
 * ADC eInvoicing Helper Functions
 * 
 * @package Dolibarr\Modules\AdcEinvoice
 */

/**
 * Log ADC eInvoicing event
 * 
 * @param string $message
 * @param string $level debug|info|warning|error
 * @param array $context Optional context data
 */
function adceinvoice_log(string $message, string $level = 'info', array $context = []): void
{
    global $conf, $langs;
    
    $logFile = $conf->adceinvoice->dir_output.'/adceinvoice.log';
    
    // Ensure log directory exists
    dol_mkdir(dirname($logFile));
    
    $entry = [
        'timestamp' => date('c'),
        'level' => strtoupper($level),
        'message' => $message,
        'context' => $context,
        'user_id' => isset($user->id) ? $user->id : null,
    ];
    
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
    
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Queue transaction for offline sync
 * 
 * @param DoliDB $db Database handler
 * @param string $elementType Dolibarr element type (invoice, order)
 * @param int $elementId Dolibarr element ID
 * @param string $transactionType INV, CRE, DEB, etc.
 * @param array $payload ADC API payload
 * @param string $businessType B2B or B2C
 * @return int|false Queue ID or false on error
 */
function adceinvoice_queue_transaction($db, string $elementType, int $elementId, 
    string $transactionType, array $payload, string $businessType = 'B2C')
{
    $trnxId = AdcEinvoiceApi::generateTrnxId();
    
    $sql = "INSERT INTO ".MAIN_DB_PREFIX."adceinvoice_queue (";
    $sql .= "entity, fk_element, elementtype, trnx_id, transaction_type, business_type, ";
    $sql .= "payload, status, next_retry_at, datec, fk_user_creat";
    $sql .= ") VALUES (";
    $sql .= (int) $db->escape($payload['entity'] ?? 1).", ";
    $sql .= (int) $elementId.", ";
    $sql .= "'".$db->escape($elementType)."', ";
    $sql .= "'".$db->escape($trnxId)."', ";
    $sql .= "'".$db->escape($transactionType)."', ";
    $sql .= "'".$db->escape($businessType)."', ";
    $sql .= "'".$db->escape(json_encode($payload))."', ";
    $sql .= "'pending', ";
    $sql .= "NOW(), ";
    $sql .= "NOW(), ";
    $sql .= (isset($user->id) ? (int)$user->id : 'NULL');
    $sql .= ")";
    
    if ($db->query($sql)) {
        return $db->last_insert_id(MAIN_DB_PREFIX.'adceinvoice_queue');
    }
    
    return false;
}

/**
 * Update queue item status
 * 
 * @param DoliDB $db
 * @param int $queueId
 * @param string $status New status
 * @param array $responseData Optional API response data
 * @param string $errorMessage Optional error message
 */
function adceinvoice_update_queue($db, int $queueId, string $status, 
    array $responseData = null, string $errorMessage = null): void
{
    $sql = "UPDATE ".MAIN_DB_PREFIX."adceinvoice_queue SET ";
    $sql .= "status = '".$db->escape($status)."', ";
    $sql .= "sync_attempts = sync_attempts + 1, ";
    $sql .= "last_sync_attempt = NOW()";
    
    if ($responseData) {
        $sql .= ", api_response = '".$db->escape(json_encode($responseData))."'";
        
        // Extract key fields if present
        if (isset($responseData['data']['mor_data']['irn'])) {
            $sql .= ", irn = '".$db->escape($responseData['data']['mor_data']['irn'])."'";
        }
        if (isset($responseData['data']['mor_data']['invoice_no'])) {
            $sql .= ", invoice_no = '".$db->escape($responseData['data']['mor_data']['invoice_no'])."'";
        }
        if (isset($responseData['data']['mor_data']['qr_data'])) {
            $sql .= ", qr_data = '".$db->escape($responseData['data']['mor_data']['qr_data'])."'";
        }
        if (isset($responseData['data']['mor_data']['voucher_no'])) {
            $sql .= ", voucher_no = '".$db->escape($responseData['data']['mor_data']['voucher_no'])."'";
        }
    }
    
    if ($errorMessage) {
        $sql .= ", error_message = '".$db->escape($errorMessage)."'";
    }
    
    // Calculate next retry (exponential backoff: 1min, 5min, 15min, 30min, 1hr)
    $sql .= ", next_retry_at = DATE_ADD(NOW(), INTERVAL ";
    $sql .= "CASE sync_attempts + 1 ";
    $sql .= "WHEN 1 THEN 1 MINUTE ";
    $sql .= "WHEN 2 THEN 5 MINUTE ";
    $sql .= "WHEN 3 THEN 15 MINUTE ";
    $sql .= "WHEN 4 THEN 30 MINUTE ";
    $sql .= "ELSE 1 HOUR END)";
    
    $sql .= " WHERE rowid = ".(int)$queueId;
    
    $db->query($sql);
}

/**
 * Get pending queue items ready for retry
 * 
 * @param DoliDB $db
 * @param int $limit Max items to return
 * @return array Array of queue items
 */
function adceinvoice_get_pending_queue($db, int $limit = 10): array
{
    $sql = "SELECT * FROM ".MAIN_DB_PREFIX."adceinvoice_queue";
    $sql .= " WHERE entity = ".$db->escape($conf->entity ?? 1);
    $sql .= " AND status IN ('pending', 'failed')";
    $sql .= " AND (next_retry_at IS NULL OR next_retry_at <= NOW())";
    $sql .= " ORDER BY datec ASC";
    $sql .= " LIMIT ".(int)$limit;
    
    $result = [];
    $resql = $db->query($sql);
    
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $result[] = [
                'rowid' => (int)$obj->rowid,
                'fk_element' => (int)$obj->fk_element,
                'elementtype' => $obj->elementtype,
                'trnx_id' => $obj->trnx_id,
                'transaction_type' => $obj->transaction_type,
                'business_type' => $obj->business_type,
                'payload' => json_decode($obj->payload, true),
                'status' => $obj->status,
                'sync_attempts' => (int)$obj->sync_attempts,
                'irn' => $obj->irn,
                'error_message' => $obj->error_message,
            ];
        }
    }
    
    return $result;
}

/**
 * Format amount for ADC API (2 decimal places, ETB)
 * 
 * @param float $amount
 * @return float
 */
function adceinvoice_format_amount(float $amount): float
{
    return round($amount, 2);
}

/**
 * Build item line for ADC API from Dolibarr product/line
 * 
 * @param object $line Dolibarr invoice line object
 * @param int $lineNumber 1-based line number
 * @return array ADC API item structure
 */
function adceinvoice_build_api_item($line, int $lineNumber): array
{
    global $langs;
    
    // Determine tax code (simplified - extend for your tax setup)
    $taxCode = 'VAT15'; // Default
    $taxRate = 15.0;
    
    if (!empty($line->fk_tva) && $line->fk_tva > 0) {
        // Map Dolibarr tax rate to ADC code
        $taxRate = (float)$line->fk_tva;
        if ($taxRate == 0) {
            $taxCode = 'VAT0';
        } elseif ($taxRate == 15) {
            $taxCode = 'VAT15';
        } else {
            $taxCode = 'VAT'.$taxRate;
        }
    }
    
    $preTaxValue = adceinvoice_format_amount($line->subprice * $line->qty);
    $taxAmount = adceinvoice_format_amount($line->total_tva);
    
    return [
        'LineNumber' => $lineNumber,
        'ItemCode' => $line->ref ?? $line->product_ref ?? 'ITEM'.$lineNumber,
        'ProductDescription' => dol_trunc($line->label ?? $line->product_label ?? '', 200),
        'NatureOfSupplies' => 'goods', // or 'services' based on product type
        'Unit' => $line->unit ?? 'PCS',
        'Quantity' => (float)$line->qty,
        'UnitPrice' => adceinvoice_format_amount($line->subprice),
        'PreTaxValue' => $preTaxValue,
        'TaxCode' => $taxCode,
        'TaxAmount' => $taxAmount,
        'TotalLineAmount' => adceinvoice_format_amount($preTaxValue + $taxAmount),
        'Discount' => adceinvoice_format_amount($line->remise_percent ?? 0),
        'ExciseTaxValue' => 0.0, // Extend if needed
        'HarmonizationCode' => null, // Extend if needed
    ];
}

/**
 * Build ValueDetails for ADC API
 * 
 * @param object $invoice Dolibarr invoice object
 * @param array $items Processed item lines
 * @return array
 */
function adceinvoice_build_value_details($invoice, array $items): array
{
    $totalPreTax = array_sum(array_column($items, 'PreTaxValue'));
    $totalTax = array_sum(array_column($items, 'TaxAmount'));
    
    return [
        'TotalValue' => adceinvoice_format_amount($totalPreTax + $totalTax),
        'TaxValue' => adceinvoice_format_amount($totalTax),
        'Discount' => adceinvoice_format_amount($invoice->remise_percent ?? 0),
        'ExciseValue' => 0.0,
        'IncomeWithholdValue' => 0.0,
        'TransactionWithholdValue' => 0,
        'InvoiceCurrency' => 'ETB',
    ];
}

/**
 * Check if invoice should be synced to ADC
 * 
 * @param object $invoice Dolibarr invoice object
 * @return bool
 */
function adceinvoice_should_sync($invoice): bool
{
    global $conf;
    
    // Skip if module disabled
    if (empty($conf->adceinvoice->enabled)) {
        return false;
    }
    
    // Skip if not validated
    if ($invoice->statut != 1) { // 1 = validated in Dolibarr
        return false;
    }
    
    // Skip if already synced (check for IRN in notes or custom field)
    if (!empty($invoice->array_options['options_adceinvoice_irn'])) {
        return false;
    }
    
    // Skip test/draft invoices based on naming
    if (preg_match('/(test|draft|sample)/i', $invoice->ref ?? '')) {
        return false;
    }
    
    return true;
}