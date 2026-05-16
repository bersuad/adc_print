CREATE TABLE llx_adc_print_logs (
    rowid integer AUTO_INCREMENT PRIMARY KEY,
    fk_facture integer NOT NULL,
    device_id varchar(100),
    print_status varchar(50),
    date_creation datetime NOT NULL
);
