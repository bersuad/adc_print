<?php

/**
 * \file    core/triggers/interface_adceinvoice.class.php
 * \ingroup adceinvoice
 * \brief   Triggers for ADC eInvoicing Module
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

class InterfaceAdceinvoice extends DolibarrTriggers
{
    /**
     * @var string Description of the trigger
     */
    public $description = "ADC eInvoicing Triggers";
    
    /**
     * @var string Version of the trigger
     */
    public $version = '1.0.0';
    
    /**
     * @var string Name of the trigger
     */
    public $family = 'adceinvoice';
    
    /**
     * @var string Picto of the trigger
     */
    public $picto = 'adceinvoice@adceinvoice';

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
        $this->name = preg_replace('/^Interface/i', '', get_class($this));
    }

    /**
     * Trigger execution function
     *
     * @param string $action Event action code
     * @param Object $object Object affected
     * @param User $user Object user performing action
     * @param target $langs Object langs
     * @param Conf $conf Object conf
     * @return int <0 if KO, 0 if no action, >0 if OK
     */
    public function runTrigger($action, $object, $user, $langs, $conf)
    {
        // Check if module is enabled and auto-sync is on
        if (empty($conf->adceinvoice->enabled)) {
            return 0; // Module not enabled
        }
        
        $autoSync = getDolGlobalInt('ADCEINVOICE_AUTO_SYNC');

        if ($action == 'BILL_VALIDATE') {
            // Only trigger if Auto Sync is enabled
            if ($autoSync) {
                require_once __DIR__ . '/../../lib/AdcApiClient.php';
                require_once __DIR__ . '/../../lib/AdcAuthService.php';
                require_once __DIR__ . '/../../class/AdcInvoiceService.php';
                
                $apiUrl = getDolGlobalString('ADCEINVOICE_API_URL');
                $apiClient = new AdcApiClient($apiUrl);
                $authService = new AdcAuthService($this->db, $apiClient);
                $invoiceService = new AdcInvoiceService($this->db, $authService, $apiClient);
                
                $result = $invoiceService->submitInvoice($object);
                
                if (!$result['success']) {
                    // We log the error but we don't necessarily block invoice validation in Dolibarr
                    // unless you want strict compliance where an invoice cannot be validated unless synced.
                    // For now, we will add an event message so the user knows the sync failed.
                    setEventMessages("ADC eInvoicing Sync Failed: " . $result['message'], null, 'warnings');
                } else {
                    setEventMessages("Invoice successfully registered with ADC (Ref: {$result['adc_invoice_number']}).", null, 'mesgs');
                }
            }
            return 1;
        }

        return 0;
    }
}
