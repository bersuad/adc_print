CREATE TABLE llx_adc_auth_tokens (
    rowid integer AUTO_INCREMENT PRIMARY KEY,
    token text NOT NULL,
    expires_at datetime NOT NULL,
    date_creation datetime NOT NULL
);
