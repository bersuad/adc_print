<?php
/**
 * Hook class for injecting ADC eInvoicing data into Dolibarr Invoice Card
 * 
 * Automatically attaches to 'invoicecard' context to display:
 * - Sync Status Box (IRN, ADC Invoice No, Voucher, QR)
 * - MoR Verification Link
 * - Manual Sync & Print Buttons
 * 
 * @package Dolibarr\Modules\AdcEinvoice
 */

if (!defined('NOLOGIN')) define('NOLOGIN', 1);

class interface_99_modAdcEinvoice_AdceinvoiceInvoice
{
    public $error = '';
    public $errors = [];

    /**
     * Hook: Add extra info below invoice status/header
     */
    public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $langs, $user;

        if (!in_array('invoicecard', explode(':', $parameters['context']))) {
            return 0;
        }

        if (!is_object($object) || $object->element !== 'facture') {
            return 0;
        }

        $irn = $object->array_options['options_adceinvoice_irn'] ?? null;
        $adcInvoiceNo = $object->array_options['options_adceinvoice_invoice_no'] ?? null;
        $voucher = $object->array_options['options_adceinvoice_voucher'] ?? null;
        $qrData = $object->array_options['options_adceinvoice_qr'] ?? null;

        if (empty($irn)) {
            // Show hint if not synced yet
            echo '<br><div class="info-box marginbottomonly">';
            echo img_picto('', 'info', 'class="pictofixedwidth"');
            echo ' <strong>'.$langs->trans('AdcEinvoiceNotSyncedYet').'</strong>';
            echo '</div>';
            return 0;
        }

        // Build status card
        $html = '<br><div class="div-table-responsive-no-min">';
        $html .= '<table class="border centpercent">';
        $html .= '<tr class="liste_titre"><td colspan="2">';
        $html .= img_picto('', 'adceinvoice@adceinvoice', 'class="pictofixedwidth"');
        $html .= ' '.$langs->trans('AdcEinvoiceModule').' - '.$langs->trans('AdcEinvoiceSyncStatus');
        $html .= '</td></tr>';

        // IRN
        $html .= '<tr><td class="fieldrequired">'.$langs->trans('AdcEinvoiceIRN').'</td>';
        $html .= '<td><strong>'.dol_escape_htmltag($irn).'</strong> ';
        $html .= '<span class="statussuccess">'.$langs->trans('AdcEinvoiceStatusSynced').'</span></td></tr>';

        // ADC Invoice No
        if ($adcInvoiceNo) {
            $html .= '<tr><td>'.$langs->trans('AdcEinvoiceInvoiceNo').'</td>';
            $html .= '<td>'.dol_escape_htmltag($adcInvoiceNo).'</td></tr>';
        }

        // Voucher
        if ($voucher) {
            $html .= '<tr><td>'.$langs->trans('AdcEinvoiceVoucherNo').'</td>';
            $html .= '<td>'.dol_escape_htmltag($voucher).'</td></tr>';
        }

        // MoR Portal Link
        $portalUrl = 'https://portal.mor.gov.et/public/invoice?irn='.urlencode($irn);
        $html .= '<tr><td>'.$langs->trans('AdcEinvoiceVerifyPortal').'</td>';
        $html .= '<td><a href="'.$portalUrl.'" target="_blank" rel="noopener noreferrer" class="butAction">';
        $html .= img_picto('', 'globe', 'class="pictofixedwidth"').' '.$langs->trans('AdcEinvoiceViewOnMoR').'</a></td></tr>';

        // QR Code
        if ($qrData) {
            $html .= '<tr><td>'.$langs->trans('AdcEinvoiceQRCode').'</td><td>';
            if (preg_match('/^https?:\/\//', $qrData)) {
                $html .= '<img src="'.dol_escape_htmltag($qrData).'" alt="QR Code" style="max-width:120px; height:auto; border:1px solid #ccc; border-radius:4px;">';
            } else {
                $html .= '<span class="opacitymedium" title="'.dol_escape_htmltag($qrData).'">'.dol_trunc($qrData, 50).'</span>';
            }
            $html .= '</td></tr>';
        }

        $html .= '</table></div>';
        echo $html;
        return 0;
    }

    /**
     * Hook: Add action buttons to invoice header
     */
    public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $langs, $user;

        if (!in_array('invoicecard', explode(':', $parameters['context']))) {
            return 0;
        }

        if (!is_object($object) || $object->element !== 'facture') {
            return 0;
        }

        // Only show for validated invoices
        if ($object->statut != 1) {
            return 0;
        }

        $irn = $object->array_options['options_adceinvoice_irn'] ?? null;
        $hasWritePermission = !empty($user->rights->adceinvoice->write);

        echo '<div class="inline-block divButAction" style="margin-top:10px;">';

        if (empty($irn) && $hasWritePermission) {
            // Manual Sync Button
            echo '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block nopadding" style="margin-right:5px;">';
            echo '<input type="hidden" name="token" value="'.newToken().'">';
            echo '<input type="hidden" name="action" value="adceinvoice_sync_now">';
            echo '<input type="hidden" name="id" value="'.$object->id.'">';
            echo '<button type="submit" class="button buttongen" title="'.$langs->trans('AdcEinvoiceSyncNow').'">';
            echo img_picto('', 'refresh', 'class="pictofixedwidth"').' '.$langs->trans('AdcEinvoiceSyncNow');
            echo '</button></form>';
        } elseif (!empty($irn) && getDolGlobalInt('ADCEINVOICE_PRINT_ENABLED') && $hasWritePermission) {
            // Print Button
            echo '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block nopadding" style="margin-right:5px;">';
            echo '<input type="hidden" name="token" value="'.newToken().'">';
            echo '<input type="hidden" name="action" value="adceinvoice_print_receipt">';
            echo '<input type="hidden" name="id" value="'.$object->id.'">';
            echo '<button type="submit" class="button buttongen" title="'.$langs->trans('AdcEinvoicePrintReceipt').'">';
            echo img_picto('', 'printer', 'class="pictofixedwidth"').' '.$langs->trans('AdcEinvoicePrintReceipt');
            echo '</button></form>';
        }

        echo '</div>';
        return 0;
    }

    /**
     * Hook: Handle custom POST actions
     */
    public function doActions($parameters, &$object, &$action, $hookmanager)
    {
        global $db, $conf, $langs, $user;

        if (!in_array('invoicecard', explode(':', $parameters['context']))) {
            return 0;
        }

        if (!is_object($object) || $object->element !== 'facture') {
            return 0;
        }

        // Verify token & ID match
        if (GETPOSTINT('id') != $object->id) {
            return 0;
        }

        // --- MANUAL SYNC ---
        if ($action == 'adceinvoice_sync_now') {
            dol_include_once('/custom/adceinvoice/class/adceinvoice_api.class.php');
            dol_include_once('/custom/adceinvoice/lib/adceinvoice.lib.php');

            $api = new AdcEinvoiceApi($db);
            $items = [];
            $lineNumber = 1;
            
            foreach ($object->lines as $line) {
                $items[] = adceinvoice_build_api_item($line, $lineNumber++);
            }
            
            $valueDetails = adceinvoice_build_value_details($object, $items);
            $businessType = !empty($object->thirdparty->tva_intra) ? 'B2B' : 'B2C';
            
            $invoiceData = [
                'trnx_id' => AdcEinvoiceApi::generateTrnxId('TRNXINV'),
                'date' => AdcEinvoiceApi::formatDateForApi($object->date),
                'invoice_no_main' => '',
                'payment_mode' => 'CASH',
                'customer_phone' => $object->thirdparty->phone ?? '',
                'customer_email' => $object->thirdparty->email ?? '',
                'items' => $items,
                'value_details' => $valueDetails,
                'entity' => $conf->entity,
            ];

            $buyerDetails = ($businessType === 'B2B') ? [
                'tin' => $object->thirdparty->tva_intra,
                'legal_name' => $object->thirdparty->name,
                'id_type' => 'KID',
            ] : null;

            $result = $api->registerTransaction('INV', $invoiceData, $buyerDetails, $businessType);

            if ($result) {
                $morData = $result['data']['mor_data'];
                $object->fetch($object->id); // Reload to ensure fresh extrafields array
                $object->array_options['options_adceinvoice_irn'] = $morData['irn'] ?? null;
                $object->array_options['options_adceinvoice_invoice_no'] = $morData['invoice_no'] ?? null;
                $object->array_options['options_adceinvoice_voucher'] = $morData['voucher_no'] ?? null;
                $object->array_options['options_adceinvoice_qr'] = $morData['qr_data'] ?? null;
                $object->update($user);

                setEventMessages($langs->trans('AdcEinvoiceSyncSuccess'), null, 'mesgs');

                if (getDolGlobalInt('ADCEINVOICE_PRINT_ENABLED')) {
                    dol_include_once('/custom/adceinvoice/class/adceinvoice_printer.class.php');
                    $printer = new AdcEinvoicePrinter();
                    $printer->printInvoice($morData, $result['data']['payment_data'] ?? []);
                }
            } else {
                setEventMessages($langs->trans('AdcEinvoiceSyncFailed', implode('; ', $api->getErrors())), null, 'errors');
            }

            header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
            exit;
        }

        // --- MANUAL PRINT ---
        if ($action == 'adceinvoice_print_receipt') {
            $irn = $object->array_options['options_adceinvoice_irn'] ?? null;
            $qr = $object->array_options['options_adceinvoice_qr'] ?? null;
            $voucher = $object->array_options['options_adceinvoice_voucher'] ?? null;

            if ($irn && $qr) {
                $morData = [
                    'irn' => $irn,
                    'qr_data' => $qr,
                    'voucher_no' => $voucher,
                    'invoice_no' => $object->array_options['options_adceinvoice_invoice_no'] ?? $object->ref,
                    'invoice_date' => dol_print_date($object->date, 'standard'),
                    'sales_total' => $object->total_ht,
                    'grand_total' => $object->total_ttc,
                    'vat_tax_total' => $object->total_tva,
                    'sales_items' => [],
                    'bussiness_type' => !empty($object->thirdparty->tva_intra) ? 'B2B' : 'B2C',
                    'Invoice_payment_mode' => 'A',
                    'invoice_status' => 'active',
                    'tax_payer_tin' => getDolGlobalString('ADCEINVOICE_TIN'),
                    'Invoice_buyer_name' => $object->thirdparty->name ?? '',
                    'Invoice_buyer_tin' => $object->thirdparty->tva_intra ?? 'XXXXXXXXXX',
                ];

                dol_include_once('/custom/adceinvoice/class/adceinvoice_printer.class.php');
                $printer = new AdcEinvoicePrinter();
                
                if ($printer->printInvoice($morData)) {
                    setEventMessages($langs->trans('AdcEinvoicePrintSuccess'), null, 'mesgs');
                } else {
                    setEventMessages($langs->trans('AdcEinvoicePrintFailed', implode('; ', $printer->getErrors())), null, 'errors');
                }
            } else {
                setEventMessages($langs->trans('AdcEinvoicePrintFailedNoData'), null, 'errors');
            }

            header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
            exit;
        }

        return 0;
    }
}