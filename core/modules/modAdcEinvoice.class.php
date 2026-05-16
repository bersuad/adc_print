<?php

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modAdcEinvoice extends DolibarrModules
{
    public function __construct($db)
    {
        $this->db = $db;
        $this->numero = 550001; // Unique module ID (check List of module IDs)
        $this->rights_class = 'adceinvoice';
        $this->family = "others";
        $this->module_position = '90';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = "ADC eInvoicing API Integration for MoR compliance";
        $this->descriptionlong = "Enables Dolibarr to register invoices with ADC eInvoicing system and print fiscal receipts via Neka device";
        $this->version = '1.0.0';
        $this->const_name = 'MAIN_MODULE_ADCEINVOICE';
        $this->picto = 'adceinvoice@adceinvoice';
        $this->config_page_url = array("adceinvoice_setup.php@adceinvoice");
        $this->langfiles = array("adceinvoice@adceinvoice");
        
        $this->module_parts = [
            'js'  => ['/adceinvoice/js/adceinvoice.js'],
            'css' => ['/adceinvoice/css/adceinvoice.css']
        ];
        // Module parts for hooks, triggers, CSS, JS
        $this->module_parts = array(
            'hooks' => array('invoicecard', 'invoicecreate', 'globalcard'),
            'triggers' => 1,
            'css' => array('/adceinvoice/css/adceinvoice.css'),
            'js' => array('/adceinvoice/js/adceinvoice.js')
        );
        
        // Module dependencies
        $this->depends = array();
        $this->requiredby = array();
        $this->phpmin = array(7,4);
        $this->need_dolibarr_version = array(18,0);
        
        // Permissions
        $r = 0;
        $this->rights[$r][0] = $this->numero . $r;
        $this->rights[$r][1] = 'Register invoice with ADC';
        $this->rights[$r][2] = 'r';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'invoice';
        $this->rights[$r][5] = 'register';
        $r++;
        
        $this->rights[$r][0] = $this->numero . $r;
        $this->rights[$r][1] = 'Print fiscal receipt';
        $this->rights[$r][2] = 'r';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'receipt';
        $this->rights[$r][5] = 'print';
        
        // Menu entries
        $this->menu = array();
        $r = 0;
        $this->menu[$r] = array(
            'fk_menu' => 0,
            'type' => 'top',
            'titre' => 'ADC eInvoice',
            'mainmenu' => 'adceinvoice',
            'leftmenu' => 'adceinvoice',
            'url' => '/adceinvoice/admin/adceinvoice_setup.php',
            'langs' => 'adceinvoice@adceinvoice',
            'position' => 100,
            'enabled' => '$conf->adceinvoice->enabled',
            'perms' => '$user->rights->adceinvoice->invoice->register',
            'target' => '',
            'user' => 2
        );
        $r++;
    }
    
    public function init($options = '')
    {
        // Load SQL tables on module activation
        $sql = array();
        return $this->_init($sql, $options);
    }
    
    public function remove($options = '')
    {
        $sql = array();
        return $this->_remove($sql, $options);
    }
}