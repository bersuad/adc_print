<?php

/**
 * \file    class/AdcInvoiceService.php
 * \ingroup adceinvoice
 * \brief   Service to handle pushing invoices to the ADC API endpoints.
 */

require_once __DIR__ . '/../lib/AdcLogger.php';
require_once __DIR__ . '/AdcInvoiceMapper.php';

class AdcInvoiceService
{
    private $db;
    private $authService;
    private $apiClient;

    public function __construct($db, $authService, $apiClient)
    {
        $this->db = $db;
        $this->authService = $authService;
        $this->apiClient = $apiClient;
    }

    /**
     * Submit an invoice to the ADC API.
     *
     * @param Facture $invoice
     * @return array ['success' => bool, 'message' => string, 'adc_invoice_number' => string|null]
     */
    public function submitInvoice($invoice)
    {
        global $conf;

        $username = getDolGlobalString('ADCEINVOICE_API_USERNAME');
        $password = getDolGlobalString('ADCEINVOICE_API_PASSWORD');
        if (!empty($password)) {
            $password = dolDecrypt($password);
        }
        $tin = getDolGlobalString('ADCEINVOICE_TIN');
        $deviceId = getDolGlobalString('ADCEINVOICE_DEVICE_ID');
        $clientType = 'WEB'; // Usually WEB for ERP backend

        if (empty($tin) || empty($deviceId)) {
            return ['success' => false, 'message' => 'TIN or Device ID is missing in configuration.'];
        }

        // 1. Get valid Auth Token
        $token = $this->authService->getValidToken($username, $password, $tin, $deviceId);
        
        if (!$token) {
            AdcLogger::error("Failed to acquire ADC token for invoice {$invoice->ref}");
            return ['success' => false, 'message' => 'Failed to authenticate with ADC API.'];
        }

        // 2. Map invoice to payload
        $payload = AdcInvoiceMapper::mapInvoiceToPayload($invoice, $tin, $clientType);

        // 3. Send request
        try {
            $headers = [
                'Authorization: Bearer ' . $token,
                'Device-ID: ' . $deviceId
            ];

            // First get Price Summary
            $summaryResponse = $this->apiClient->post('/price_summary', $payload, $headers);
            
            // Log response
            $this->logTransaction($invoice->id, null, $payload, $summaryResponse, 'price_summary_called');

            if (!isset($summaryResponse['status']) || (int)$summaryResponse['status'] !== 0) {
                $errorMsg = $summaryResponse['message'] ?? 'Unknown error during price_summary';
                AdcLogger::error("Price summary failed for {$invoice->ref}: " . $errorMsg);
                return ['success' => false, 'message' => $errorMsg];
            }

            // 4. If summary successful, send receive_request
            $receiveResponse = $this->apiClient->post('/receive_request', $payload, $headers);
            
            $status = (isset($receiveResponse['status']) && (int)$receiveResponse['status'] === 0) ? 'success' : 'failed';
            $adcRef = $receiveResponse['data']['invoice_number'] ?? null;
            $errorMsg = $receiveResponse['message'] ?? '';

            $this->logTransaction($invoice->id, $adcRef, $payload, $receiveResponse, $status, $errorMsg);

            if ($status === 'success') {
                return ['success' => true, 'message' => 'Invoice successfully registered with ADC.', 'adc_invoice_number' => $adcRef];
            } else {
                return ['success' => false, 'message' => $errorMsg];
            }

        } catch (Exception $e) {
            AdcLogger::error("Exception submitting invoice {$invoice->ref}: " . $e->getMessage());
            $this->logTransaction($invoice->id, null, $payload, ['error' => $e->getMessage()], 'failed', $e->getMessage());
            return ['success' => false, 'message' => 'Network or unexpected error occurred.'];
        }
    }

    /**
     * Log transaction to the DB.
     */
    private function logTransaction($factureId, $adcNumber, $requestPayload, $responsePayload, $status, $errorMessage = '')
    {
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "adc_invoice_logs ";
        $sql .= "(fk_facture, adc_invoice_number, request_payload, response_payload, status, error_message, date_creation) ";
        $sql .= "VALUES (";
        $sql .= (int)$factureId . ", ";
        $sql .= "'" . $this->db->escape($adcNumber) . "', ";
        $sql .= "'" . $this->db->escape(json_encode($requestPayload)) . "', ";
        $sql .= "'" . $this->db->escape(json_encode($responsePayload)) . "', ";
        $sql .= "'" . $this->db->escape($status) . "', ";
        $sql .= "'" . $this->db->escape($errorMessage) . "', ";
        $sql .= "'" . $this->db->idate(time()) . "'";
        $sql .= ")";

        if (!$this->db->query($sql)) {
            AdcLogger::error('Failed to insert audit log: ' . $this->db->lasterror());
        }
    }
}
