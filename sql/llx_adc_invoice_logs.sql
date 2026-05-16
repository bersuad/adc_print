CREATE TABLE llx_adc_invoice_logs (
    rowid integer AUTO_INCREMENT PRIMARY KEY,
    fk_facture integer NOT NULL,
    adc_invoice_number varchar(255),
    request_payload jsonb,
    response_payload jsonb,
    status varchar(50) NOT NULL,
    error_message text,
    date_creation datetime NOT NULL,
    tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
