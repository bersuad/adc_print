<?php
/**
 * ADC eInvoicing Module Configuration Page
 * 
 * @package Dolibarr\Modules\AdcEinvoice
 */

require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/../lib/AdcApiClient.php';
require_once __DIR__.'/../lib/AdcAuthService.php';

// Access control
if (!$user->admin) {
    accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

// Language
$langs->loadLangs(['admin', 'adceinvoice@adceinvoice']);

// Initialize API client for testing
$apiClient = new AdcApiClient(getDolGlobalString('ADCEINVOICE_API_URL'));
$authService = new AdcAuthService($db, $apiClient);

/*
 * Actions
 */
if ($action == 'update' && !empty($token)) {
    // Update configuration
    $constNames = [
        'ADCEINVOICE_API_URL',
        'ADCEINVOICE_API_USERNAME', 
        'ADCEINVOICE_API_PASSWORD',
        'ADCEINVOICE_TIN',
        'ADCEINVOICE_DEVICE_ID',
        'ADCEINVOICE_CLIENT_TYPE',
        'ADCEINVOICE_AUTO_SYNC',
        'ADCEINVOICE_PRINT_ENABLED',
    ];
    
    foreach ($constNames as $constName) {
        $value = GETPOST($constName, 'alpha');
        
        // Encrypt password field
        if ($constName === 'ADCEINVOICE_API_PASSWORD') {
            if (!empty($value)) {
                $value = dol_encrypt($value);
            } else {
                // Keep existing value if empty
                continue;
            }
        }
        
        dolibarr_set_const($db, $constName, $value, 'chaine', 0, '', $conf->entity);
    }
    
    // Handle boolean fields
    dolibarr_set_const($db, 'ADCEINVOICE_AUTO_SYNC', GETPOST('ADCEINVOICE_AUTO_SYNC', 'int'), 'bool', 0, '', $conf->entity);
    dolibarr_set_const($db, 'ADCEINVOICE_PRINT_ENABLED', GETPOST('ADCEINVOICE_PRINT_ENABLED', 'int'), 'bool', 0, '', $conf->entity);
    
    // Clear cached token on config change
    $cacheFile = $conf->cache_dir.'/adceinvoice_access_token.cache';
    @unlink($cacheFile);
    
    setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
    header('Location: '.$_SERVER['PHP_SELF']);
    exit;
}

if ($action == 'test_connection') {
    // Test API connection
    $username = getDolGlobalString('ADCEINVOICE_API_USERNAME');
    $password = getDolGlobalString('ADCEINVOICE_API_PASSWORD');
    if (!empty($password)) {
        $password = dol_decrypt($password);
    }
    
    $testResult = $authService->authenticate($username, $password);
    
    if ($testResult) {
        setEventMessages($langs->trans('AdcEinvoiceConnectionSuccess'), null, 'mesgs');
    } else {
        setEventMessages($langs->trans('AdcEinvoiceConnectionFailed'), null, 'errors');
    }
    header('Location: '.$_SERVER['PHP_SELF'].'#connection');
    exit;
}

/*
 * View
 */
llxHeader('', $langs->trans('AdcEinvoiceSetup'), '', '', 0, 0, '', '', '', 'mod-adceinvoice page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans('AdcEinvoiceSetup'), $linkback, 'adceinvoice@adceinvoice');

print '<br>';

// Configuration form
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print load_fiche_titre($langs->trans('ApiConfiguration'), '', '', 0, 0, '', '', '', 'connection');

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("Parameter").'</td><td>'.$langs->trans("Value").'</td><td>'.$langs->trans("Description").'</td></tr>';

// API URL
print '<tr class="oddeven"><td><label for="ADCEINVOICE_API_URL">'.$langs->trans('AdcEinvoiceApiUrl').'</label></td>';
print '<td><input type="url" name="ADCEINVOICE_API_URL" id="ADCEINVOICE_API_URL" class="minwidth300" value="'.getDolGlobalString('ADCEINVOICE_API_URL').'"></td>';
print '<td class="opacitymedium">'.$langs->trans('AdcEinvoiceApiUrlDesc').'</td></tr>';

// Credentials
print '<tr class="oddeven"><td><label for="ADCEINVOICE_API_USERNAME">'.$langs->trans('AdcEinvoiceApiUsername').'</label></td>';
print '<td><input type="text" name="ADCEINVOICE_API_USERNAME" id="ADCEINVOICE_API_USERNAME" class="minwidth300" value="'.getDolGlobalString('ADCEINVOICE_API_USERNAME').'"></td>';
print '<td class="opacitymedium">'.$langs->trans('AdcEinvoiceApiUsernameDesc').'</td></tr>';

print '<tr class="oddeven"><td><label for="ADCEINVOICE_API_PASSWORD">'.$langs->trans('AdcEinvoiceApiPassword').'</label></td>';
print '<td><input type="password" name="ADCEINVOICE_API_PASSWORD" id="ADCEINVOICE_API_PASSWORD" class="minwidth300" value="" placeholder="••••••••"></td>';
print '<td class="opacitymedium">'.$langs->trans('AdcEinvoiceApiPasswordDesc').'</td></tr>';

// TIN
print '<tr class="oddeven"><td><label for="ADCEINVOICE_TIN">'.$langs->trans('AdcEinvoiceTin').'</label></td>';
print '<td><input type="text" name="ADCEINVOICE_TIN" id="ADCEINVOICE_TIN" class="minwidth300" value="'.getDolGlobalString('ADCEINVOICE_TIN').'"></td>';
print '<td class="opacitymedium">'.$langs->trans('AdcEinvoiceTinDesc').'</td></tr>';

// Device ID
print '<tr class="oddeven"><td><label for="ADCEINVOICE_DEVICE_ID">'.$langs->trans('AdcEinvoiceDeviceId').'</label></td>';
print '<td><input type="text" name="ADCEINVOICE_DEVICE_ID" id="ADCEINVOICE_DEVICE_ID" class="minwidth300" value="'.getDolGlobalString('ADCEINVOICE_DEVICE_ID').'"></td>';
print '<td class="opacitymedium">'.$langs->trans('AdcEinvoiceDeviceIdDesc').'</td></tr>';

// Client Type
print '<tr class="oddeven"><td><label for="ADCEINVOICE_CLIENT_TYPE">'.$langs->trans('AdcEinvoiceClientType').'</label></td>';
print '<td>';
print '<select name="ADCEINVOICE_CLIENT_TYPE" id="ADCEINVOICE_CLIENT_TYPE">';
foreach (['WEB', 'POS', 'MOBILE'] as $type) {
    $selected = (getDolGlobalString('ADCEINVOICE_CLIENT_TYPE') === $type) ? ' selected' : '';
    print '<option value="'.$type.'"'.$selected.'>'.$type.'</option>';
}
print '</select></td>';
print '<td class="opacitymedium">'.$langs->trans('AdcEinvoiceClientTypeDesc').'</td></tr>';

print '</table>';

// Test Connection Button
print '<br>';
print '<div class="center">';
print '<button type="submit" name="action" value="test_connection" class="button button-test">';
print img_picto('', 'info').' '.$langs->trans('AdcEinvoiceTestConnection');
print '</button>';
print '</div>';

print '<br><hr><br>';

// Behavior Settings
print load_fiche_titre($langs->trans('BehaviorSettings'), '', '');

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("Parameter").'</td><td>'.$langs->trans("Value").'</td><td>'.$langs->trans("Description").'</td></tr>';

// Auto-sync toggle
print '<tr class="oddeven"><td>'.$langs->trans('AdcEinvoiceAutoSync').'</td>';
print '<td><input type="checkbox" name="ADCEINVOICE_AUTO_SYNC" value="1"'.(getDolGlobalInt('ADCEINVOICE_AUTO_SYNC') ? ' checked' : '').'></td>';
print '<td class="opacitymedium">'.$langs->trans('AdcEinvoiceAutoSyncDesc').'</td></tr>';

// Print enabled toggle
print '<tr class="oddeven"><td>'.$langs->trans('AdcEinvoicePrintEnabled').'</td>';
print '<td><input type="checkbox" name="ADCEINVOICE_PRINT_ENABLED" value="1"'.(getDolGlobalInt('ADCEINVOICE_PRINT_ENABLED') ? ' checked' : '').'></td>';
print '<td class="opacitymedium">'.$langs->trans('AdcEinvoicePrintEnabledDesc').'</td></tr>';

print '</table>';

// Save button
print '<br><div class="center">';
print '<input type="submit" class="button button-save" value="'.$langs->trans("Save").'">';
print '</div>';

print '</form>';

// Status Panel
print '<br><hr><br>';
print load_fiche_titre($langs->trans('ModuleStatus'), '', '');

$statusChecks = [
    'PHP cURL Extension' => extension_loaded('curl'),
    'PHP JSON Extension' => extension_loaded('json'),
    'PHP OpenSSL' => extension_loaded('openssl'),
    'Config Writable' => is_writable($conf->cache_dir),
    'TIN Configured' => !empty(getDolGlobalString('ADCEINVOICE_TIN')),
    'Device ID Configured' => !empty(getDolGlobalString('ADCEINVOICE_DEVICE_ID')),
];

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("Check").'</td><td>'.$langs->trans("Status").'</td></tr>';

foreach ($statusChecks as $check => $passed) {
    print '<tr class="oddeven"><td>'.$check.'</td>';
    print '<td>'.($passed 
        ? img_picto($langs->trans('OK'), 'on', 'class="opacitymedium"').' <span class="opacitymedium">'.$langs->trans('OK').'</span>' 
        : img_picto($langs->trans('KO'), 'off', 'class="error"').' <span class="error">'.$langs->trans('KO').'</span>').'</td></tr>';
}

print '</table>';

// Pending Queue Summary
$sql = "SELECT status, COUNT(*) as nb FROM ".MAIN_DB_PREFIX."adc_retry_queue";
$sql .= " GROUP BY status";
$resql = $db->query($sql);

if ($resql && $db->num_rows($resql) > 0) {
    print '<br>';
    print load_fiche_titre($langs->trans('SyncQueueSummary'), '', '');
    
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre"><td>'.$langs->trans("Status").'</td><td>'.$langs->trans("Count").'</td></tr>';
    
    while ($obj = $db->fetch_object($resql)) {
        $statusLabel = $langs->trans('AdcEinvoiceStatus'.ucfirst($obj->status));
    
        $class = '';
    
        if ($obj->status == 'pending') {
            $class = 'warning';
        } elseif ($obj->status == 'failed') {
            $class = 'error';
        } elseif ($obj->status == 'synced') {
            $class = 'success';
        }
    
        print '<tr class="oddeven"><td><span class="status'.$class.'">'.$statusLabel.'</span></td>';
        print '<td class="right">'.$obj->nb.'</td></tr>';
    }
    print '</table>';
}

llxFooter();
$db->close();