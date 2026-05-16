CREATE TABLE llx_adc_device_config (
    rowid integer AUTO_INCREMENT PRIMARY KEY,
    device_id varchar(100) NOT NULL,
    location_id varchar(100) NOT NULL,
    branch_code varchar(100) NOT NULL,
    is_active boolean DEFAULT true,
    date_creation datetime NOT NULL
);
