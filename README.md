# ADC eInvoicing Integration Module for Dolibarr

The **ADC eInvoicing Integration Module** (`modAdcEinvoice`) is a custom Dolibarr module designed to integrate the Dolibarr ERP with the ADC eInvoicing API for Ethiopia MoR (Ministry of Revenue) compliance.

This module provides seamless synchronization between Dolibarr customer invoices and the ADC API, utilizing a robust, retry-safe queue, comprehensive audit logging, and PostgreSQL `JSONB` data types for structured API payloads.

---

## 🌟 Features

- **Automated Authentication**: Manages API tokens efficiently, caching them locally and refreshing automatically upon expiration.
- **Invoice Registration (B2B/B2C)**: Synchronizes Dolibarr invoices with the ADC API.
- **Advanced Retry Queue**: Automatically queues failed API requests and retries them, ensuring idempotency and data integrity.
- **Audit Trails**: Complete logging of all API requests, responses, and errors.
- **Fiscal Printing Integration**: Support for ADC printing logs and device configurations.
- **Clean Architecture**: Follows PSR-12 coding standards with separated services, API clients, and database mappers.

---

## 📋 Requirements

- **Dolibarr ERP**: version 18.0 or higher.
- **PHP**: version 7.4 or higher (with `curl` and `json` extensions).
- **Database**: PostgreSQL (requires `JSONB` data type support for logging and queues).
- **Network**: Outbound access to the ADC API endpoints (e.g., `https://trcp-2.adc.com.et/trcp_test`).

---

## 🚀 Installation

1. Clone or copy this repository into your Dolibarr `custom` directory:
   ```bash
   cp -r adceinvoice /path/to/dolibarr/htdocs/custom/
   ```
2. Log into Dolibarr as an Administrator.
3. Go to **Setup** (gear icon) -> **Modules/Applications**.
4. In the list of modules, find **ADC eInvoicing Integration**.
5. Click the **Activate** switch.
   - *Activating the module automatically triggers the SQL migrations to create the required PostgreSQL tables.*

---

## ⚙️ Configuration

Once installed and activated, click the **Gear Icon** next to the module to enter the Setup Page.

You will need to configure the following details:
- **API Base URL**: The ADC API endpoint (defaults to the test environment).
- **Credentials**: Your API Username and Password.
- **TIN**: Your Tax Identification Number.
- **Device ID**: Your registered fiscal device ID.
- **Client Type**: Select WEB, POS, or MOBILE.
- **Auto-sync**: Enable this to automatically queue and push invoices upon validation.

After entering your credentials, click **Test Connection** to ensure Dolibarr can successfully authenticate with the ADC servers.

---

## 🏗️ Architecture & Folder Structure

The module is built using a clean, layered architecture:

```text
htdocs/custom/adceinvoice/
├── admin/                  # Configuration & setup screens for the module
│   └── adceinvoice_setup.php # The main configuration page where users set API URLs, credentials, TIN, and Device IDs. Handles testing the connection to the ADC API.
├── class/                  # Core models, DB wrappers, and data mappers
│   ├── AdcInvoiceMapper.php  # (Phase 3) Maps internal Dolibarr invoice data (Facture) to the precise JSON structure required by the ADC MoR endpoints.
│   └── AdcRetryQueue.php     # (Phase 4) Model handling the persistence and retrieval of failed API requests for idempotent background processing.
├── core/                   # Dolibarr-specific integrations that wire the module into the ERP's lifecycle
│   ├── modules/            
│   │   └── modAdcEinvoice.class.php # The core module descriptor. Defines permissions, menus, version constraints, and handles module activation/deactivation.
│   ├── hooks/              # UI Hooks
│   │   └── invoicecard.php   # (Phase 3) Injects UI elements into the Dolibarr invoice screen (e.g., "Send to ADC", "Sync Status").
│   └── triggers/           # Event triggers (e.g., on invoice validation)
│       └── interface_adceinvoice.class.php # (Phase 3) Listens to native Dolibarr events like `BILL_VALIDATE` to automatically trigger API submissions.
├── lib/                    # Reusable logic, shared utilities, and core API services
│   ├── AdcApiClient.php    # A lightweight, standalone cURL wrapper that handles all HTTP communication, headers, JSON encoding/decoding, and timeouts.
│   ├── AdcAuthService.php  # Token management service. It authenticates with `/udfs_api/authenticate`, caches the Bearer token in the DB, and auto-refreshes it when it expires.
│   └── AdcLogger.php       # Centralized logging service. Wraps Dolibarr's `dol_syslog` to ensure all API transactions and module errors are consistently formatted and traceable.
├── sql/                    # PostgreSQL schema migrations (executed automatically upon module activation)
│   ├── llx_adc_auth_tokens.sql   # Table for caching API tokens and expiration times.
│   ├── llx_adc_device_config.sql # Table for storing POS/Branch configurations.
│   ├── llx_adc_invoice_logs.sql  # Audit trail table storing complete JSONB request/response payloads for every invoice sent.
│   ├── llx_adc_print_logs.sql    # Audit trail for physical print commands sent to fiscal devices.
│   ├── llx_adc_reports_cache.sql # Table for caching heavy Z-reports or summary data locally.
│   └── llx_adc_retry_queue.sql   # The queue table used to store transient API failures for later background retry.
└── README.md               # This documentation file providing module overview, installation, and architecture context.
```

### Database Tables

The module uses the following tables (created during activation):
- `llx_adc_auth_tokens`: Stores bearer tokens and expiration details.
- `llx_adc_invoice_logs`: Complete audit trail of invoices sent, storing raw payloads in `JSONB`.
- `llx_adc_retry_queue`: Holds failed requests pending retry via cron/worker.
- `llx_adc_print_logs`: Logs related to fiscal printing actions.
- `llx_adc_reports_cache`: Caches ADC reports locally.
- `llx_adc_device_config`: Device-specific configurations (location, branch).

---

## 📡 API Endpoints Handled

This module interacts with the following ADC endpoints:
- `/udfs_api/authenticate`
- `/udfs_api/receive_request`
- `/udfs_api/price_summary`
- `/udfs_api/cancel`
- `/udfs_api/sales_receipt`
- `/udfs_api/withholding`
- `/udfs_api/report`
- `/udfs_api/copy`

---

## 🛠️ Troubleshooting & Logs

If invoices are failing to sync or authentication errors occur:
1. **Dolibarr Syslog**: Check the standard Dolibarr syslog (if enabled in *Setup -> Modules -> Logs*). All module logs are prefixed with `modAdcEinvoice`.
2. **Setup Tests**: Visit the module setup page and click "Test Connection" to immediately verify API credentials.
3. **Queue Status**: Check the module configuration page to see a summary of pending or failed queue items.

### Manual Queue Processing (Development)
If the cron job is not running, you can manually trigger the retry queue logic (once Phase 4 is fully implemented) by accessing the respective API or CLI script defined in the module.

---
*Developed for Dolibarr / ADC Integration - 2026*
