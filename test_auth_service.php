<?php
define('NOLOGIN', 1);
define('NOREQUIREMENU', 1);
require '/var/www/html/dolibarr-adc-main/htdocs/main.inc.php';
require_once '/var/www/html/dolibarr-adc-main/htdocs/custom/adceinvoice/lib/AdcApiClient.php';
require_once '/var/www/html/dolibarr-adc-main/htdocs/custom/adceinvoice/lib/AdcAuthService.php';

$username = 'adc_erp_api';
$password = 'kJTFH+l?vjC34qfY';
$tin = '0026716178';
$deviceId = '0964400970';
$url = 'https://trcp-2.adc.com.et/trcp_test';

echo "Testing with:\n";
echo "Username: $username\n";
echo "Password: $password\n";
echo "TIN: $tin\n";
echo "Device ID: $deviceId\n";
echo "URL: $url\n";

$apiClient = new AdcApiClient($url);
$authService = new AdcAuthService($db, $apiClient);

$result = $authService->authenticate($username, $password, $tin, $deviceId);

echo "\nResult:\n";
var_dump($result);

// Let's also check if AdcApiClient throws an error directly
echo "\nManual call:\n";
$apiClient->setBasicAuth($username, $password);
try {
    $res = $apiClient->post('/authenticate', ['tin_no' => $tin, 'client' => 'WEB'], ['Device-ID: ' . $deviceId]);
    var_dump($res);
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
