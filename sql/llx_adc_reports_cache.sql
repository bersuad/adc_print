CREATE TABLE llx_adc_reports_cache (
    rowid integer AUTO_INCREMENT PRIMARY KEY,
    report_type varchar(100) NOT NULL,
    report_data jsonb NOT NULL,
    date_creation datetime NOT NULL
);
