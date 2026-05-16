CREATE TABLE llx_adc_retry_queue (
    rowid integer AUTO_INCREMENT PRIMARY KEY,
    fk_facture integer NOT NULL,
    endpoint varchar(255) NOT NULL,
    payload jsonb NOT NULL,
    retry_count integer DEFAULT 0,
    next_retry_at datetime NOT NULL,
    status varchar(50) DEFAULT 'pending',
    date_creation datetime NOT NULL
);
