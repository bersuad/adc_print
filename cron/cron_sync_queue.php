<?php

// Dolibarr CLI bootstrap
if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', 1);
if (!defined('NOCSRFCHECK')) define('NOCSRFCHECK', 1);
if (!defined('NOLOGIN')) define('NOLOGIN', 1);
if (!defined('NOREQUIREMENU')) define('NOREQUIREMENU', 1);
if (!defined('NOREQUIREHTML')) define('NOREQUIREHTML', 1);
if (!defined('NOREQUIREAJAX')) define('NOREQUIREAJAX', 1);
if (!defined('NOSESSION')) define('NOSESSION', 1);

// Change to Dolibarr root
chdir(dirname(__DIR__, 2)); // Adjust path as needed
require_once __DIR__ . '/../../main.inc.php';

// Only run if module enabled
if (empty($conf->adceinvoice->enabled)) {
    echo "ADC eInvoicing module not enabled\n";
    exit(0);
}

// Load required classes
dol_include_once('/custom/adceinvoice/class/adceinvoice_api.class.php');
dol_include_once('/custom/adceinvoice/lib/adceinvoice.lib.php');
dol_include_once('/custom/adceinvoice/class/adceinvoice_printer.class.php');

// Configuration
$MAX_ITEMS_PER_RUN = (int) getDolGlobalInt('ADCEINVOICE_CRON_BATCH_SIZE', 20);
$LOG_LEVEL = getDolGlobalString('ADCEINVOICE_CRON_LOG_LEVEL', 'info');
$ENABLE_PRINTING = getDolGlobalInt('ADCEINVOICE_PRINT_ENABLED', 0);

// Output header
echo sprintf(
    "[%s] ADC eInvoicing Queue Processor v1.0 - Starting (max %d items)\n",
    date('c'),
    $MAX_ITEMS_PER_RUN
);

// Initialize
$db->begin();
$api = new AdcEinvoiceApi($db);
$printer = $ENABLE_PRINTING ? new AdcEinvoicePrinter() : null;

$processed = 0;
$success = 0;
$failed = 0;
$skipped = 0;

// Fetch pending items ready for retry
$pendingItems = adceinvoice_get_pending_queue($db, $MAX_ITEMS_PER_RUN);

if (empty($pendingItems)) {
    echo "No pending items to process\n";
    $db->commit();
    exit(0);
}

echo sprintf("Found %d pending items to process\n", count($pendingItems));

// Process each item
foreach ($pendingItems as $item) {
    $processed++;
    $queueId = $item['rowid'];
    $trnxId = $item['trnx_id'];
    
    echo sprintf("[%s] Processing #%d: %s (%s) for %s#%d\n", 
        date('H:i:s'),
        $queueId,
        $trnxId,
        $item['transaction_type'],
        $item['elementtype'],
        $item['fk_element']
    );
    
    // Mark as processing
    $sql = "UPDATE ".MAIN_DB_PREFIX."adceinvoice_queue";
    $sql .= " SET status = 'processing', last_sync_attempt = NOW()";
    $sql .= " WHERE rowid = ".(int)$queueId;
    $db->query($sql);
    
    $result = false;
    $errorMsg = '';
    $responseData = null;
    
    try {
        // Execute based on transaction type
        switch ($item['transaction_type']) {
            case 'INV':
            case 'CRE':
            case 'DEB':
                $payload = $item['payload'];
                $buyerDetails = $payload['buyer_details'] ?? null;
                
                $result = $api->registerTransaction(
                    $item['transaction_type'],
                    $payload,
                    $buyerDetails,
                    $item['business_type']
                );
                $responseData = $result ? $result : null;
                break;
                
            case 'RECEIPT':
                $payload = $item['payload'];
                $result = $api->registerSalesReceipt(
                    $payload['invoice_no_main'],
                    $payload['InvoicePaidAmount'],
                    $payload['trnx_id']
                );
                $responseData = $result ? $result : null;
                break;
                
            case 'CANCEL':
                $payload = $item['payload'];
                $result = $api->cancelInvoice(
                    $payload['invoice_no_main'],
                    $payload['customer_phone'] ?? '',
                    $payload['customer_email'] ?? ''
                );
                $responseData = $result ? $result : null;
                break;
                
            default:
                $errorMsg = 'Unknown transaction type: ' . $item['transaction_type'];
                break;
        }
        
    } catch (Exception $e) {
        $errorMsg = 'Exception: ' . $e->getMessage();
        adceinvoice_log('Cron exception for '.$trnxId, 'error', [
            'queue_id' => $queueId,
            'exception' => $e->getMessage()
        ]);
    }
    
    // Handle result
    if ($result) {
        // Success
        $success++;
        $morData = $result['data']['mor_data'] ?? [];
        
        // Update queue as synced
        adceinvoice_update_queue($db, $queueId, 'synced', $result);
        
        // Update original Dolibarr document
        if ($item['elementtype'] === 'invoice' && $item['fk_element'] > 0) {
            require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
            $invoice = new Facture($db);
            if ($invoice->fetch($item['fk_element']) > 0) {
                $invoice->array_options['options_adceinvoice_irn'] = $morData['irn'] ?? null;
                $invoice->array_options['options_adceinvoice_invoice_no'] = $morData['invoice_no'] ?? null;
                $invoice->array_options['options_adceinvoice_voucher'] = $morData['voucher_no'] ?? null;
                $invoice->array_options['options_adceinvoice_qr'] = $morData['qr_data'] ?? null;
                $invoice->update($user ?? null); // $user may not be set in CLI
            }
        }
        
        echo sprintf("  ✓ Synced - IRN: %s\n", $morData['irn'] ?? 'N/A');
        
        // Print if enabled and printer available
        if ($printer && !empty($morData)) {
            if ($printer->printInvoice($morData, $result['data']['payment_data'] ?? [])) {
                echo "  ✓ Printed receipt\n";
            } else {
                $printErrors = $printer->getErrors();
                echo sprintf("  ⚠ Print failed: %s\n", implode('; ', $printErrors));
                adceinvoice_log('Print failed for IRN '.$morData['irn'], 'warning', $printErrors);
            }
        }
        
    } else {
        // Failed
        $failed++;
        $errors = $api->getErrors();
        $errorMsg = $errorMsg ?: implode('; ', $errors);
        
        // Update queue as failed with error
        adceinvoice_update_queue($db, $queueId, 'failed', null, $errorMsg);
        
        echo sprintf("  ✗ Failed: %s\n", dol_trunc($errorMsg, 100));
        adceinvoice_log('Sync failed for '.$trnxId, 'error', [
            'queue_id' => $queueId,
            'errors' => $errors
        ]);
    }
    
    // Small delay to avoid rate limiting
    usleep(100000); // 100ms
}

// Commit transaction
$db->commit();

// Summary
echo "\n--- Summary ---\n";
echo sprintf("Processed: %d | Success: %d | Failed: %d | Skipped: %d\n", 
    $processed, $success, $failed, $skipped);
echo sprintf("Completed at: %s\n\n", date('c'));

// Exit with appropriate code
exit($failed > 0 ? 1 : 0);