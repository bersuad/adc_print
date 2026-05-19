<?php
define('NOLOGIN', 1);
define('NOREQUIREMENU', 1);
require '/var/www/html/dolibarr-adc-main/htdocs/main.inc.php';

$queries = [
    "CREATE TABLE IF NOT EXISTS llx_adc_invoice_logs (
        rowid SERIAL PRIMARY KEY,
        fk_facture integer NOT NULL,
        adc_invoice_number varchar(255),
        request_payload jsonb,
        response_payload jsonb,
        status varchar(50) NOT NULL,
        error_message text,
        date_creation timestamp NOT NULL,
        tms timestamp DEFAULT CURRENT_TIMESTAMP
    )"
];

foreach ($queries as $sql) {
    $res = $db->query($sql);
    if (!$res) {
        echo "Error creating table: " . $db->lasterror() . "\n";
    } else {
        echo "Table created successfully.\n";
    }
}
