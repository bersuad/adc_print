<?php
/**
 * Module descriptor for ADC eInvoicing Integration
 * 
 * @package Dolibarr\Modules\AdcEinvoice
 * @copyright ADC Research and Development PLC
 * @license AGPL-3.0+
 */

if (!defined('DOL_USE_JQUERY')) define('DOL_USE_JQUERY', 1);

dol_include_once('/core/modules/DolibarrModules.class.php');

class modAdcEinvoice extends DolibarrModules
{
    public function __construct($db)
    {
        global $langs, $conf;
        
        $this->db = $db;
        $this->numero = 500000; // Unique module ID
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->family = "billing";
        $this->module_position = '500000';
        
        $this->version = '1.0.0';
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
        
        $this->special = 0;
        $this->picto = 'adceinvoice@adceinvoice';
        
        // Module description
        $this->description = "ADC eInvoicing API integration for MoR compliance";
        $this->descriptionlong = "Enables Dolibarr to register invoices, credit/debit notes, receipts with ADC eInvoicing system and print via Neka CRM device";
        
        // Dependencies
        $this->hidden = false;
        $this->depends = ['modFacture']; // Requires Invoice module
        $this->requiredby = [];
        $this->conflictwith = [];
        $this->phpmin = [7, 4];
        $this->need_dolibarr_version = [18, 0];
        
        // Language file
        $this->langfiles = ["adceinvoice@adceinvoice"];
        
        // Constants
        $this->const = [
            0 => [
                'ADCEINVOICE_API_URL',
                'chaine',
                'https://crs-cs.adc.com.et/udfs_api',
                'ADC API Base URL',
                0
            ],
            1 => [
                'ADCEINVOICE_API_USERNAME',
                'chaine',
                'adc_erp_api',
                'API Username',
                0
            ],
            2 => [
                'ADCEINVOICE_API_PASSWORD',
                'chaine',
                '',
                'API Password (stored encrypted)',
                0
            ],
            3 => [
                'ADCEINVOICE_TIN',
                'chaine',
                '',
                'Taxpayer TIN',
                0
            ],
            4 => [
                'ADCEINVOICE_DEVICE_ID',
                'chaine',
                '',
                'CRM Device ID',
                0
            ],
            5 => [
                'ADCEINVOICE_CLIENT_TYPE',
                'chaine',
                'WEB',
                'Client identifier for API',
                0
            ],
            6 => [
                'ADCEINVOICE_AUTO_SYNC',
                'bool',
                '1',
                'Auto-sync invoices on validation',
                0
            ],
            7 => [
                'ADCEINVOICE_PRINT_ENABLED',
                'bool',
                '1',
                'Enable Neka printer integration',
                0
            ],
        ];
        
        // Boxes
        $this->boxes = [];
        
        // Permissions
        $this->rights = [
            1 => [
                'id' => 'adceinvoice_read',
                'lib' => 'Read ADC eInvoicing data',
                'default' => 1
            ],
            2 => [
                'id' => 'adceinvoice_write', 
                'lib' => 'Submit transactions to ADC API',
                'default' => 0
            ],
            3 => [
                'id' => 'adceinvoice_admin',
                'lib' => 'Configure ADC module settings',
                'default' => 0
            ],
        ];
        
        // Menu entries
        $this->menu = [
            [
                'fk_menu' => 'fk_mainmenu=billing',
                'type' => 'left',
                'titre' => 'ADC eInvoicing',
                'mainmenu' => 'billing',
                'leftmenu' => 'adceinvoice',
                'url' => '/adceinvoice/admin/adceinvoice_setup.php',
                'langs' => 'adceinvoice@adceinvoice',
                'position' => 100,
                'enabled' => '$conf->adceinvoice->enabled',
                'perms' => '$user->rights->adceinvoice->admin',
                'target' => '',
                'user' => 2
            ],
            [
                'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=adceinvoice',
                'type' => 'left',
                'titre' => 'Sync Queue',
                'mainmenu' => 'billing',
                'leftmenu' => 'adceinvoice_queue',
                'url' => '/adceinvoice/queue_list.php',
                'langs' => 'adceinvoice@adceinvoice',
                'position' => 101,
                'enabled' => '$conf->adceinvoice->enabled',
                'perms' => '$user->rights->adceinvoice->read',
                'target' => '',
                'user' => 2
            ],
        ];
        
        // SQL initialization
        $this->sql = [1 => 'llx_adceinvoice_queue'];
    }
    
    public function init($options = [])
    {
        global $conf, $langs;
        
        $sql = [];
        
        // Create tables
        $result = $this->_load_tables('/adceinvoice/sql/');
        
        // Insert default permissions
        $this->_delete_rights();
        $this->_insert_rights();
        
        return $this->_init($sql, $options);
    }
    
    public function remove($options = [])
    {
        $sql = [];
        return $this->_remove($sql, $options);
    }
}