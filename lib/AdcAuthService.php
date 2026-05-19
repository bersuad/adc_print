<?php

/**
 * \file    lib/AdcAuthService.php
 * \ingroup adceinvoice
 * \brief   Service to handle ADC API Authentication and Token Management.
 */

require_once __DIR__ . '/AdcApiClient.php';
require_once __DIR__ . '/AdcLogger.php';

/**
 * Class AdcAuthService
 */
class AdcAuthService
{
    /**
     * @var DoliDB Database handler.
     */
    private $db;

    /**
     * @var AdcApiClient API Client.
     */
    private $apiClient;

    /**
     * AdcAuthService constructor.
     *
     * @param DoliDB $db Dolibarr database handler.
     * @param AdcApiClient $apiClient Instance of the API client.
     */
    public function __construct($db, AdcApiClient $apiClient)
    {
        $this->db = $db;
        $this->apiClient = $apiClient;
    }

    /**
     * Get a valid bearer token.
     * If an existing token is valid, returns it. Otherwise, authenticates and fetches a new one.
     *
     * @param string $username API username.
     * @param string $password API password.
     * @param string $tin API TIN number.
     * @param string $deviceId Fiscal Device ID.
     * @return string|null The valid token or null on failure.
     */
    public function getValidToken($username, $password, $tin, $deviceId)
    {
        $currentToken = $this->getTokenFromDb();

        if ($currentToken && !$this->isTokenExpired($currentToken['expires_at'])) {
            AdcLogger::debug('Using existing valid auth token');
            return $currentToken['token'];
        }

        AdcLogger::info('Auth token missing or expired, fetching a new one');
        return $this->authenticate($username, $password, $tin, $deviceId);
    }

    /**
     * Perform the authentication API call.
     *
     * @param string $username API username.
     * @param string $password API password.
     * @param string $tin TIN number.
     * @param string $deviceId Fiscal Device ID.
     * @return string|null The new token or null on failure.
     */
    public function authenticate($username, $password, $tin, $deviceId)
    {
        try {
            $payload = [
                'tin_no' => $tin,
                'client' => 'WEB'
            ];

            // Set Basic Auth credentials
            $this->apiClient->setBasicAuth($username, $password);

            $headers = [
                'Device-ID: ' . $deviceId
            ];

            $response = $this->apiClient->post('/authenticate', $payload, $headers);

            if (isset($response['status']) && (int)$response['status'] === 0) {
                $token = $response['data']['access_token'] ?? null;
                $expiresIn = $response['data']['expires_in'] ?? 3600;

                if ($token) {
                    $expiresAt = date('Y-m-d H:i:s', time() + (int)$expiresIn);
                    $this->saveTokenToDb($token, $expiresAt);
                    return $token;
                }
            }

            AdcLogger::error('Invalid auth response or auth failed', ['response' => $response]);
            return null;

        } catch (Exception $e) {
            AdcLogger::error('Authentication failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch the most recent token from the database.
     *
     * @return array|null Associative array with 'token' and 'expires_at' or null if not found.
     */
    private function getTokenFromDb()
    {
        $sql = "SELECT token, expires_at FROM " . MAIN_DB_PREFIX . "adc_auth_tokens ORDER BY rowid DESC LIMIT 1";
        $resql = $this->db->query($sql);

        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj) {
                return [
                    'token' => $obj->token,
                    'expires_at' => $obj->expires_at
                ];
            }
        } else {
            AdcLogger::error('Failed to query auth tokens: ' . $this->db->lasterror());
        }

        return null;
    }

    /**
     * Save a new token to the database.
     *
     * @param string $token The Bearer token.
     * @param string $expiresAt Expiration datetime string (Y-m-d H:i:s).
     * @return bool True if successful, false otherwise.
     */
    private function saveTokenToDb($token, $expiresAt)
    {
        // First, optionally clear old tokens to save space
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "adc_auth_tokens");

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "adc_auth_tokens (token, expires_at, date_creation) ";
        $sql .= "VALUES ('" . $this->db->escape($token) . "', '" . $this->db->escape($expiresAt) . "', '" . $this->db->idate(time()) . "')";

        $resql = $this->db->query($sql);

        if (!$resql) {
            AdcLogger::error('Failed to save auth token: ' . $this->db->lasterror());
            return false;
        }

        return true;
    }

    /**
     * Check if a token expiration date has passed.
     * Adds a 60-second buffer to prevent race conditions.
     *
     * @param string $expiresAt
     * @return bool
     */
    private function isTokenExpired($expiresAt)
    {
        $expirationTime = strtotime($expiresAt);
        $currentTime = time();

        // 60 seconds buffer
        return ($currentTime + 60) >= $expirationTime;
    }
}
