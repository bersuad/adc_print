<?php

/**
 * \file    class/AdcInvoiceMapper.php
 * \ingroup adceinvoice
 * \brief   Maps Dolibarr Facture objects to the ADC API JSON payload structure.
 */

class AdcInvoiceMapper
{
    /**
     * Map a Dolibarr invoice to the ADC API payload.
     *
     * @param Facture $invoice The Dolibarr Invoice object.
     * @param string $tin The configured TIN.
     * @param string $clientType The client type (e.g., WEB, POS).
     * @return array The JSON-ready array payload.
     */
    public static function mapInvoiceToPayload($invoice, $tin, $clientType = 'WEB')
    {
        global $db, $conf;
        
        $invoice->fetch_lines();
        
        // Map line items
        $items = [];
        $lineNumber = 1;
        
        foreach ($invoice->lines as $line) {
            $taxRate = $line->tva_tx;
            // Simplified tax mapping based on typical Ethiopian MoR standards
            $taxCode = 'VAT' . (int)$taxRate; 
            if ($taxRate == 0) {
                $taxCode = 'EXEMPT'; // Adjust based on actual ADC API specs
            }
            
            $items[] = [
                'ItemCode' => $line->product_ref ?: 'ITEM-' . $line->rowid,
                'ProductDescription' => $line->product_label ?: $line->desc,
                'NatureOfSupplies' => $line->product_type == 1 ? 'services' : 'goods',
                'Unit' => 'PCS', // You might want to map this to Dolibarr's dictionary
                'Quantity' => $line->qty,
                'UnitPrice' => $line->subprice,
                'PreTaxValue' => $line->total_ht,
                'TaxCode' => $taxCode,
                'TaxAmount' => $line->total_tva,
                'TotalLineAmount' => $line->total_ttc,
                'Discount' => $line->total_ht * ($line->remise_percent / 100),
                'ExciseTaxValue' => 0, // Implement if Dolibarr uses local taxes for excise
                'HarmonizationCode' => null,
                'LineNumber' => $lineNumber++
            ];
        }
        
        // Determine transaction type
        // In Dolibarr: type 0 = standard invoice, type 2 = credit note
        $transactionType = 1; // Default to standard invoice
        if ($invoice->type == Facture::TYPE_CREDIT_NOTE) {
            $transactionType = 2; // Credit Note
        }

        $payload = [
            'tax_payer_tin' => $tin,
            'client' => $clientType,
            'transaction_type' => $transactionType,
            'info' => [
                'InvoiceNumber' => $invoice->ref,
                'IssueDate' => date('Y-m-d H:i:s', $invoice->date),
                'CustomerName' => $invoice->thirdparty->name,
                'CustomerTIN' => $invoice->thirdparty->idprof1, // Assuming idprof1 holds TIN
                'ItemList' => $items,
                'price_details' => [
                    'total_taxable_amount' => $invoice->total_ht,
                    'total_tax_amount' => $invoice->total_tva,
                    'grand_total' => $invoice->total_ttc
                ]
            ]
        ];

        return $payload;
    }
}
