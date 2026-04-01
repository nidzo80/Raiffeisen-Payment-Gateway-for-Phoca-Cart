<?php
/**
 * @package    Plg_Pcp_RaiAccept
 * @license    GNU General Public License version 3 or later
 */

namespace YourVendor\Plugin\Pcp\RaiAccept\Helper;

use RuntimeException;

\defined('_JEXEC') or die;

/**
 * RaiAccept API komunikacija.
 *
 * Implementira RaiAccept REST API tok:
 * 1. authenticate()   → dohvata IdToken via Amazon Cognito
 * 2. createOrder()    → POST /orders
 * 3. createSession()  → POST /orders/{id}/checkout → paymentRedirectURL
 * 4. getOrderStatus() → GET  /orders/{id}
 * 5. refund()         → POST /orders/{id}/transactions/{txId}/refund
 *
 * @since 1.0.0
 */
class ApiHelper
{
    /** Amazon Cognito endpoint za autentifikaciju */
    private const AUTH_URL = 'https://authenticate.raiaccept.com';

    /** Cognito Client ID (isti za sve merchantove) */
    private const CLIENT_ID = 'kr2gs4117arvbnaperqff5dml';

    /** RaiAccept Transaction API base URL */
    private const API_BASE = 'https://trapi.raiaccept.com';

    /** @var string API korisničko ime */
    private string $username;

    /** @var string API lozinka */
    private string $password;

    /** @var bool Sandbox mod */
    private bool $sandbox;

    /**
     * @param array $credentials  ['username' => '', 'password' => '', 'sandbox' => false]
     */
    public function __construct(array $credentials)
    {
        $this->username = $credentials['username'] ?? '';
        $this->password = $credentials['password'] ?? '';
        $this->sandbox  = (bool) ($credentials['sandbox'] ?? false);
    }

    // -------------------------------------------------------------------------
    // Public API metodi
    // -------------------------------------------------------------------------

    /**
     * Korak 1: Autentifikacija.
     * Dohvata IdToken koji se koristi kao Bearer token u svim sledećim pozivima.
     *
     * @return string IdToken
     * @throws RuntimeException
     * @since  1.0.0
     */
    public function authenticate(): string
    {
        $payload = json_encode([
            'AuthFlow'       => 'USER_PASSWORD_AUTH',
            'AuthParameters' => [
                'USERNAME' => $this->username,
                'PASSWORD' => $this->password,
            ],
            'ClientId' => self::CLIENT_ID,
        ]);

        // Cognito zahteva tačno ove headere - NE application/json
        $headers = [
            'Content-Type: application/x-amz-json-1.1',
            'X-Amz-Target: AWSCognitoIdentityProviderService.InitiateAuth',
            'Content-Length: ' . strlen($payload),
        ];

        $ch = curl_init(self::AUTH_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body  = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException('RaiAccept cURL error (auth): ' . $error);
        }

        $response = json_decode($body, true);

        if (empty($response['AuthenticationResult']['IdToken'])) {
            throw new RuntimeException(
                'RaiAccept: Authentication failed. ' . $body
            );
        }

        return $response['AuthenticationResult']['IdToken'];
    }

    /**
     * Korak 2: Kreiranje ordera u RaiAccept sistemu.
     *
     * @param  string  $token    IdToken iz authenticate()
     * @param  array   $payload  Order podaci (buildOrderPayload iz Extension klase)
     * @return array   Odgovor sa orderIdentification
     * @throws RuntimeException
     * @since  1.0.0
     */
    public function createOrder(string $token, array $payload): array
    {
        $response = $this->httpPost(
            self::API_BASE . '/orders',
            $payload,
            $this->bearerHeaders($token)
        );

        if (empty($response['orderIdentification'])) {
            throw new RuntimeException(
                'RaiAccept: Order creation failed. ' . json_encode($response)
            );
        }

        return $response;
    }

    /**
     * Korak 3: Kreiranje payment sesije.
     * Vraća paymentRedirectURL na koji se kupac šalje.
     *
     * @param  string  $token               IdToken
     * @param  string  $orderIdentification Order ID iz createOrder()
     * @param  array   $payload             Isti payload kao u createOrder()
     * @return array   Odgovor sa paymentRedirectURL
     * @throws RuntimeException
     * @since  1.0.0
     */
    public function createSession(string $token, string $orderIdentification, array $payload): array
    {
        $url = self::API_BASE . '/orders/' . urlencode($orderIdentification) . '/checkout';

        $response = $this->httpPost($url, $payload, $this->bearerHeaders($token));

        if (empty($response['paymentRedirectURL'])) {
            throw new RuntimeException(
                'RaiAccept: Session creation failed. ' . json_encode($response)
            );
        }

        return $response;
    }

    /**
     * Dohvata detalje i status ordera.
     * Koristi se za verifikaciju posle webhook notifikacije.
     *
     * @param  string  $token               IdToken
     * @param  string  $orderIdentification RaiAccept order ID
     * @return array   Order detalji sa poljem 'status'
     * @throws RuntimeException
     * @since  1.0.0
     */
    public function getOrderStatus(string $token, string $orderIdentification): array
    {
        $url = self::API_BASE . '/orders/' . urlencode($orderIdentification);

        $response = $this->httpGet($url, $this->bearerHeaders($token));

        if (!isset($response['status'])) {
            throw new RuntimeException(
                'RaiAccept: Could not retrieve order status. ' . json_encode($response)
            );
        }

        return $response;
    }

    /**
     * Dohvata sve transakcije za order.
     *
     * @param  string  $token               IdToken
     * @param  string  $orderIdentification RaiAccept order ID
     * @return array   Lista transakcija
     * @throws RuntimeException
     * @since  1.0.0
     */
    public function getTransactions(string $token, string $orderIdentification): array
    {
        $url = self::API_BASE . '/orders/' . urlencode($orderIdentification) . '/transactions';

        return $this->httpGet($url, $this->bearerHeaders($token));
    }

    /**
     * Izdaje refund za transakciju.
     *
     * @param  string  $token               IdToken
     * @param  string  $orderIdentification RaiAccept order ID
     * @param  string  $transactionId       RaiAccept transaction ID
     * @param  float   $amount              Iznos refunda (ne sme biti veći od originala)
     * @param  string  $currency            ISO 4217 valuta (npr. 'RSD')
     * @return array   Odgovor sa transactionId refunda
     * @throws RuntimeException
     * @since  1.0.0
     */
    public function refund(
        string $token,
        string $orderIdentification,
        string $transactionId,
        float $amount,
        string $currency
    ): array {
        $url = self::API_BASE
            . '/orders/' . urlencode($orderIdentification)
            . '/transactions/' . urlencode($transactionId)
            . '/refund';

        $payload = [
            'amount'   => $amount,
            'currency' => strtoupper($currency),
        ];

        $response = $this->httpPost($url, $payload, $this->bearerHeaders($token));

        if (empty($response['transactionId'])) {
            throw new RuntimeException(
                'RaiAccept: Refund failed. ' . json_encode($response)
            );
        }

        return $response;
    }

    // -------------------------------------------------------------------------
    // Private HTTP metodi
    // -------------------------------------------------------------------------

    /**
     * HTTP POST zahtev.
     *
     * @param  string  $url
     * @param  array   $payload
     * @param  array   $headers
     * @return array   Dekodirani JSON odgovor
     * @throws RuntimeException
     */
    private function httpPost(string $url, array $payload, array $headers = []): array
    {
        $json = json_encode($payload);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException('RaiAccept cURL error: ' . $error);
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'RaiAccept: Invalid response (HTTP ' . $status . '): ' . $body
            );
        }

        // HTTP greške
        if ($status >= 400) {
            $msg = $decoded['message'] ?? $decoded['error'] ?? json_encode($decoded);
            throw new RuntimeException('RaiAccept API error (HTTP ' . $status . '): ' . $msg);
        }

        return $decoded;
    }

    /**
     * HTTP GET zahtev.
     *
     * @param  string  $url
     * @param  array   $headers
     * @return array   Dekodirani JSON odgovor
     * @throws RuntimeException
     */
    private function httpGet(string $url, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException('RaiAccept cURL error: ' . $error);
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'RaiAccept: Invalid response (HTTP ' . $status . '): ' . $body
            );
        }

        if ($status >= 400) {
            $msg = $decoded['message'] ?? json_encode($decoded);
            throw new RuntimeException('RaiAccept API error (HTTP ' . $status . '): ' . $msg);
        }

        return $decoded;
    }

    /**
     * Gradi Authorization header niz.
     *
     * @param  string  $token  IdToken
     * @return array
     */
    private function bearerHeaders(string $token): array
    {
        return [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ];
    }
}
