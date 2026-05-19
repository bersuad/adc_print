# ADC eInvoicing Integration Module for Dolibarr

The **ADC eInvoicing Integration Module** (`modAdcEinvoice`) is a custom Dolibarr module designed to integrate the Dolibarr ERP with the ADC eInvoicing API for Ethiopia MoR (Ministry of Revenue) compliance.

This module provides seamless synchronization between Dolibarr customer invoices and the ADC API, utilizing a robust, retry-safe queue, comprehensive audit logging, and PostgreSQL JSONB data types for structured API payloads.

---

## Features

- **Automated Authentication**: Manages API tokens efficiently, caching them locally in the database and refreshing automatically upon expiration using the configured TIN and Device ID.
- **Invoice Registration (B2B/B2C)**: Synchronizes Dolibarr invoices with the ADC API, automatically structuring line items, taxes, and customer details into the required JSON payload.
- **Price Summary Validation**: Validates the payload against the `/price_summary` endpoint before committing to the `/receive_request` endpoint to ensure tax compliance.
- **Advanced Retry Queue**: Automatically queues failed API requests and retries them via cron, ensuring idempotency and data integrity in case of network failures.
- **Audit Trails**: Complete logging of all API requests, responses, and errors directly in the Dolibarr database.
- **Fiscal Printing Integration**: Support for ADC printing logs and device configurations.
- **Clean Architecture**: Follows PSR-12 coding standards with separated services, API clients, and database mappers.

---

## Requirements

- **Dolibarr ERP**: version 18.0 or higher.
- **PHP**: version 7.4 or higher (with `curl`, `json`, and `openssl` extensions).
- **Database**: PostgreSQL (requires `JSONB` data type support for logging and queues).
- **Network**: Outbound access to the ADC API endpoints (e.g., `https://trcp-2.adc.com.et/trcp_test`).

---

## Installation

1. Clone or copy this repository into your Dolibarr `custom` directory:
   `cp -r adceinvoice /path/to/dolibarr/htdocs/custom/`
2. Log into Dolibarr as an Administrator.
3. Go to **Setup -> Modules/Applications**.
4. In the list of modules, find **ADC eInvoicing Integration**.
5. Click the **Activate** switch.
   *Note: Activating the module automatically triggers the SQL migrations to create the required PostgreSQL tables.*

---

## Configuration

Once installed and activated, click the gear icon next to the module to enter the Setup Page.

You will need to configure the following details:
- **API Base URL**: The ADC API endpoint (defaults to the test environment).
- **Credentials**: Your API Username and Password.
- **TIN**: Your Tax Identification Number.
- **Device ID**: Your registered fiscal device ID.
- **Auto-sync**: Enable this to automatically push invoices to the API upon validation.
- **Print Enabled**: Enable this to automatically attempt to print to the fiscal device upon a successful sync.

After entering your credentials, click **Test Connection** to ensure Dolibarr can successfully authenticate with the ADC servers.

---

## User Flow

Once the module is fully configured and the "Auto-sync" option is enabled, the module operates silently in the background during your normal billing workflow. 

Here is the exact step-by-step user flow:

### 1. Invoice Creation
The user logs into Dolibarr and navigates to the **Billing | Payment** module. They create a standard customer invoice (`Facture`), adding the necessary line items, quantities, prices, and tax rates (e.g., VAT15 or VAT0).

### 2. Invoice Validation
When the invoice is complete, the user clicks the native Dolibarr **Validate** button on the invoice card.

### 3. Background Sync Process
Upon validation, the module intercepts the event via the `BILL_VALIDATE` trigger. 
1. The `AdcInvoiceMapper` translates the Dolibarr invoice data into the specific JSON format required by the ADC API.
2. The `AdcAuthService` securely authenticates with the ADC server and acquires a fresh Bearer token (or reuses a valid cached token).
3. The `AdcInvoiceService` sends the JSON payload to the `/price_summary` endpoint to verify the mathematical accuracy of the taxes and totals.
4. If the summary is accepted, the service immediately posts the payload to the `/receive_request` endpoint to officially register the invoice.

### 4. Status Reporting
The user is immediately presented with a banner at the top of the Dolibarr screen:
- **Success**: "Invoice successfully registered with ADC (Ref: ...)"
- **Failure**: "ADC eInvoicing Sync Failed: [Reason]"

If the invoice fails (due to a missing parameter, network timeout, or invalid tax code), the raw JSON payloads and error messages are saved in the `llx_adc_invoice_logs` database table. In future iterations, failed invoices will be automatically queued in `llx_adc_retry_queue` for background retry attempts.

---

## Architecture & Folder Structure

The module is built using a clean, layered architecture:

```text
htdocs/custom/adceinvoice/
├── admin/                  # Configuration & setup screens for the module
│   └── adceinvoice_setup.php # The main configuration page where users set API URLs, credentials, TIN, and Device IDs. Handles testing the connection to the ADC API.
├── class/                  # Core models, DB wrappers, and data mappers
│   ├── AdcInvoiceMapper.php  # Maps internal Dolibarr invoice data to the precise JSON structure required by the ADC endpoints.
│   ├── AdcInvoiceService.php # Service handler for validating and submitting payloads to the API endpoints.
│   └── AdcRetryQueue.php     # Model handling the persistence and retrieval of failed API requests for idempotent background processing.
├── core/                   # Dolibarr-specific integrations that wire the module into the ERP's lifecycle
│   ├── modules/            
│   │   └── modAdcEinvoice.class.php # The core module descriptor. Defines permissions, menus, version constraints, and handles module activation.
│   ├── hooks/              # UI Hooks
│   │   └── invoicecard.php   # Injects UI elements into the Dolibarr invoice screen.
│   └── triggers/           # Event triggers
│       └── interface_adceinvoice.class.php # Listens to native Dolibarr events like `BILL_VALIDATE` to automatically trigger API submissions.
├── lib/                    # Reusable logic, shared utilities, and core API services
│   ├── AdcApiClient.php    # A lightweight, standalone cURL wrapper that handles all HTTP communication, headers, JSON encoding/decoding, and timeouts.
│   ├── AdcAuthService.php  # Token management service. It authenticates, caches the Bearer token in the DB, and auto-refreshes it.
│   └── AdcLogger.php       # Centralized logging service.
├── sql/                    # PostgreSQL schema migrations (executed automatically upon module activation)
│   ├── llx_adc_auth_tokens.sql   
│   ├── llx_adc_device_config.sql 
│   ├── llx_adc_invoice_logs.sql  # Audit trail table storing complete JSONB request/response payloads for every invoice sent.
│   ├── llx_adc_print_logs.sql    
│   ├── llx_adc_reports_cache.sql 
│   └── llx_adc_retry_queue.sql   
└── README.md               # This documentation file.
```

---

## Troubleshooting & Logs

If invoices are failing to sync or authentication errors occur:
1. **Dolibarr Syslog**: Check the standard Dolibarr syslog (if enabled in *Setup -> Modules -> Logs*). All module logs are prefixed with `modAdcEinvoice`.
2. **Setup Tests**: Visit the module setup page and click "Test Connection" to immediately verify API credentials.
3. **Database Audit**: Access the PostgreSQL database and query the `llx_adc_invoice_logs` table to view the raw JSON request and response payloads to identify exactly what the ADC API rejected.
