<?php

/**
 * \file    lib/AdcApiClient.php
 * \ingroup adceinvoice
 * \brief   Core API Client for interacting with the ADC eInvoicing API.
 */

require_once __DIR__ . '/AdcLogger.php';

/**
 * Class AdcApiClient
 * Handles HTTP communication with the ADC API.
 */
class AdcApiClient
{
    /**
     * @var string Base URL for the ADC API
     */
    private $baseUrl;

    /**
     * @var int Timeout for API requests in seconds
     */
    private $timeout = 30;

    /**
     * AdcApiClient constructor.
     *
     * @param string $baseUrl The base URL of the API.
     */
    public function __construct($baseUrl = 'https://trcp-2.adc.com.et/trcp_test')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Set the timeout for API requests.
     *
     * @param int $timeout Timeout in seconds.
     * @return self
     */
    public function setTimeout($timeout)
    {
        $this->timeout = (int)$timeout;
        return $this;
    }

    /**
     * Perform an HTTP POST request.
     *
     * @param string $endpoint The API endpoint (e.g., '/udfs_api/authenticate').
     * @param array $payload The JSON payload as an associative array.
     * @param array $headers Optional HTTP headers.
     * @return array|null The decoded JSON response, or null on error.
     * @throws Exception If a network or curl error occurs.
     */
    public function post($endpoint, array $payload, array $headers = [])
    {
        return $this->request('POST', $endpoint, $payload, $headers);
    }

    /**
     * Perform an HTTP GET request.
     *
     * @param string $endpoint The API endpoint.
     * @param array $headers Optional HTTP headers.
     * @return array|null The decoded JSON response, or null on error.
     * @throws Exception If a network or curl error occurs.
     */
    public function get($endpoint, array $headers = [])
    {
        return $this->request('GET', $endpoint, null, $headers);
    }

    /**
     * Core request method.
     *
     * @param string $method HTTP method (GET, POST).
     * @param string $endpoint API endpoint.
     * @param array|null $payload Request body.
     * @param array $headers HTTP headers.
     * @return array|null Decoded JSON response.
     * @throws Exception If the request fails entirely.
     */
    private function request($method, $endpoint, $payload = null, array $headers = [])
    {
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        // Standard JSON headers
        $defaultHeaders = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        // Merge custom headers
        $requestHeaders = array_merge($defaultHeaders, $headers);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($payload !== null) {
                $jsonPayload = json_encode($payload);
                if ($jsonPayload === false) {
                    AdcLogger::error('Failed to encode JSON payload', ['endpoint' => $endpoint]);
                    throw new Exception('Invalid JSON payload');
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            }
        }

        AdcLogger::debug("Sending $method request to $url", ['payload' => $payload]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($response === false) {
            AdcLogger::error("cURL error on $method $url", ['error' => $error]);
            throw new Exception("Network Error: " . $error);
        }

        AdcLogger::debug("Received response from $url", ['http_code' => $httpCode, 'response' => $response]);

        $decodedResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            AdcLogger::error("Failed to decode JSON response", ['response' => $response]);
            // If the API returns non-JSON, we might want to throw an exception or return null
            throw new Exception('Invalid JSON response from ADC API');
        }

        return $decodedResponse;
    }
}
