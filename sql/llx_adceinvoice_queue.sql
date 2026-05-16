-- ============================================================================
-- ADC eInvoicing: Offline Transaction Queue Table

-- Stores transactions pending sync when API is unavailable
-- ============================================================================

CREATE TABLE IF NOT EXISTS llx_adceinvoice_queue (
    rowid               INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity              INTEGER DEFAULT 1 NOT NULL,
    
    -- Reference to Dolibarr document
    fk_element          INTEGER NOT NULL,
    elementtype         VARCHAR(32) NOT NULL,  -- 'invoice', 'credit_note', etc.
    
    -- Transaction details
    trnx_id             VARCHAR(64) NOT NULL UNIQUE,
    transaction_type    VARCHAR(16) NOT NULL,  -- INV, CRE, DEB, RECEIPT, etc.
    business_type       VARCHAR(8) DEFAULT 'B2C',  -- B2B or B2C
    
    -- ADC API payload (JSON)
    payload             TEXT NOT NULL,
    
    -- Sync status
    status              VARCHAR(32) DEFAULT 'pending' NOT NULL,  -- pending, processing, synced, failed
    sync_attempts       INTEGER DEFAULT 0,
    last_sync_attempt   DATETIME DEFAULT NULL,
    next_retry_at       DATETIME DEFAULT NULL,
    
    -- API response data
    api_response        TEXT DEFAULT NULL,
    irn                 VARCHAR(255) DEFAULT NULL,  -- Invoice Registration Number
    invoice_no          VARCHAR(64) DEFAULT NULL,   -- ADC invoice number
    qr_data             TEXT DEFAULT NULL,
    voucher_no          VARCHAR(32) DEFAULT NULL,
    
    -- Error tracking
    error_message       TEXT DEFAULT NULL,
    error_code          VARCHAR(32) DEFAULT NULL,
    
    -- Metadata
    datec               DATETIME NOT NULL,
    tms                 TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_creat       INTEGER DEFAULT NULL,
    fk_user_modif       INTEGER DEFAULT NULL,
    
    -- Indexes
    INDEX idx_queue_status (status),
    INDEX idx_queue_retry (next_retry_at, status),
    INDEX idx_element (fk_element, elementtype),
    INDEX idx_trnx (trnx_id),
    INDEX idx_irn (irn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;