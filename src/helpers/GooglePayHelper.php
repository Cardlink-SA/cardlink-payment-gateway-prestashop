<?php

/**
 * Cardlink Checkout - A Payment Module for PrestaShop 1.7
 *
 * Google Pay Helper - Provides configuration accessors, URL builders,
 * XID calculation, MPI signing, and transaction info management.
 *
 * @author Cardlink S.A. <ecommerce_support@cardlink.gr>
 * @license https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

namespace Cardlink_Checkout;

use Configuration;
use Context;

class GooglePayHelper
{
    // =====================================================================
    //  CONFIGURATION ACCESSORS
    // =====================================================================

    /**
     * Check if Google Pay is enabled.
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return Constants::ENABLE_GOOGLEPAY
            && boolval(Configuration::get(Constants::CONFIG_GOOGLEPAY_ENABLE, null, null, null, '0'));
    }

    /**
     * Get the Google Pay Merchant ID.
     *
     * @return string
     */
    public static function getMerchantId(): string
    {
        return Configuration::get(Constants::CONFIG_GOOGLEPAY_MERCHANT_ID, null, null, null, '') ?: '';
    }

    /**
     * Get the Google Pay Shared Secret.
     *
     * @return string
     */
    public static function getSharedSecret(): string
    {
        return Configuration::get(Constants::CONFIG_GOOGLEPAY_SHARED_SECRET, null, null, null, '') ?: '';
    }

    /**
     * Get the Google Pay Business Partner.
     *
     * @return string
     */
    public static function getBusinessPartner(): string
    {
        return Constants::BUSINESS_PARTNER_WORLDLINE;
    }

    /**
     * Get the Google Pay Transaction Environment.
     *
     * @return string
     */
    public static function getTransactionEnvironment(): string
    {
        return Configuration::get(Constants::CONFIG_GOOGLEPAY_TRANSACTION_ENVIRONMENT, null, null, null, Constants::TRANSACTION_ENVIRONMENT_SANDBOX) ?: Constants::TRANSACTION_ENVIRONMENT_SANDBOX;
    }

    /**
     * Get the MPI private key for RSA signing (v4.0).
     *
     * @return string
     */
    public static function getMpiPrivateKey(): string
    {
        return Configuration::get(Constants::CONFIG_GOOGLEPAY_MPI_PRIVATE_KEY, null, null, null, '') ?: '';
    }

    // =====================================================================
    //  URL BUILDERS
    // =====================================================================

    /**
     * Get the VPOS base domain URL based on business partner and environment.
     *
     * @return string
     */
    public static function getVposDomain(): string
    {
        $businessPartner = self::getBusinessPartner();
        $isProduction = (self::getTransactionEnvironment() === Constants::TRANSACTION_ENVIRONMENT_PRODUCTION);

        if ($isProduction) {
            switch ($businessPartner) {
                case Constants::BUSINESS_PARTNER_CARDLINK:
                    return 'https://ecommerce.cardlink.gr';
                case Constants::BUSINESS_PARTNER_NEXI:
                    return 'https://www.alphaecommerce.gr';
                case Constants::BUSINESS_PARTNER_WORLDLINE:
                    return 'https://vpos.eurocommerce.gr';
            }
        } else {
            switch ($businessPartner) {
                case Constants::BUSINESS_PARTNER_CARDLINK:
                    return 'https://ecommerce-test.cardlink.gr';
                case Constants::BUSINESS_PARTNER_NEXI:
                    return 'https://alphaecommerce-test.cardlink.gr';
                case Constants::BUSINESS_PARTNER_WORLDLINE:
                    return 'https://eurocommerce-test.cardlink.gr';
            }
        }

        return 'https://ecommerce-test.cardlink.gr';
    }

    /**
     * Get the Google Pay Direct script base URL.
     *
     * @return string
     */
    public static function getDirectScriptUrl(): string
    {
        return self::getVposDomain() . '/vpos/js/googlepaydirect.js';
    }

    /**
     * Get the MPI URL for 3D-Secure authentication.
     *
     * @return string
     */
    public static function getMpiUrl(): string
    {
        return self::getVposDomain() . '/mdpaympi/MerchantServer';
    }

    // =====================================================================
    //  SCRIPT AUTHENTICATION (for googlepaydirect.js)
    // =====================================================================

    /**
     * Build the query string for loading the Google Pay Direct script.
     *
     * The script URL requires authentication via a digest parameter:
     * digest = base64(sha256(version + mid + timestamp + sharedSecret, raw=true))
     * Timestamp must be in Europe/Athens timezone, format YYYYMMDDHHmm.
     *
     * @return array ['queryString' => '?version=...', 'mid' => '...', 'vposVersion' => '2']
     */
    public static function getScriptInitData(): array
    {
        $mid = self::getMerchantId();
        $sharedSecret = self::getSharedSecret();
        $vposVersion = '2';

        $athensTime = new \DateTime('now', new \DateTimeZone('Europe/Athens'));
        $timestamp = $athensTime->format('YmdHi');
        $hashData = $vposVersion . $mid . $timestamp . $sharedSecret;
        $hash = base64_encode(hash('sha256', $hashData, true));

        $queryString = '?' . http_build_query([
            'version' => $vposVersion,
            'mid' => $mid,
            'date' => $timestamp,
            'digest' => $hash,
        ]);

        return [
            'mid' => $mid,
            'queryString' => $queryString,
            'vposVersion' => $vposVersion,
        ];
    }

    // =====================================================================
    //  XID CALCULATION (3D-Secure Transaction Identifier)
    // =====================================================================

    /**
     * Calculate the 3D-Secure XID from VPOS transaction fields.
     *
     * Format: "VPOS" (4) + padCutLeft(txId, 7) (7) + "-" (1)
     *       + padCutLeft(trExtId, 6) (6) + padCutLeft(mpiCounts, 2) (2) = 20 bytes
     * Then Base64-encoded.
     *
     * @param string $txId      Transaction ID from VPOS response
     * @param string $trExtId   External transaction reference from VPOS response
     * @param string $trMpiCounts MPI counts value from VPOS response
     * @return string Base64-encoded XID string
     */
    public static function calculateXID(string $txId, string $trExtId, string $trMpiCounts): string
    {
        $raw = 'VPOS'
            . self::padCutLeft($txId, 7)
            . '-'
            . self::padCutLeft($trExtId, 6)
            . self::padCutLeft($trMpiCounts, 2);

        return base64_encode($raw);
    }

    /**
     * Left-pad or left-cut a string to exactly $length characters.
     *
     * @param string $str    Input string
     * @param int    $length Desired output length
     * @return string Exactly $length characters
     */
    private static function padCutLeft(string $str, int $length): string
    {
        $len = strlen($str);
        if ($len > $length) {
            return substr($str, -$length);
        }
        if ($len < $length) {
            return str_pad($str, $length, '0', STR_PAD_LEFT);
        }
        return $str;
    }

    // =====================================================================
    //  MPI SIGNING (3D-Secure form data)
    // =====================================================================

    /**
     * Sign the MPI form data for 3D-Secure authentication.
     *
     * If an MPI private key is configured → RSA-SHA256 (v4.0).
     * Otherwise → HMAC-SHA1 with shared secret (v2.0).
     *
     * @param array $data MPI form field data
     * @return array ['signature' => '...', 'purchaseAmountFormatted' => '...', 'mpiVersion' => '...']
     */
    public static function signMpiData(array $data): array
    {
        $purchaseAmountFormatted = self::formatPurchaseAmount(
            $data['purchAmount'] ?? '0',
            (int) ($data['exponent'] ?? 2)
        );

        $mpiKey = self::getMpiPrivateKey();
        $secret = self::getSharedSecret();

        if (!empty($mpiKey) && strpos($mpiKey, '-----BEGIN') !== false) {
            $mpiVersion = '4.0';
            $signature = self::signWithRsa($data, $purchaseAmountFormatted, $mpiKey, $mpiVersion);
        } else {
            $mpiVersion = '2.0';
            $signature = self::signWithHmac($data, $purchaseAmountFormatted, $secret, $mpiVersion);
        }

        return [
            'signature' => $signature,
            'purchaseAmountFormatted' => $purchaseAmountFormatted,
            'mpiVersion' => $mpiVersion,
        ];
    }

    /**
     * Format purchase amount: remove decimal separator, as integer string.
     *
     * @param string $amount   Decimal amount string (e.g. "55.55")
     * @param int    $exponent Number of decimal places (typically 2)
     * @return string Formatted amount without decimal separator (e.g. "5555")
     */
    private static function formatPurchaseAmount(string $amount, int $exponent): string
    {
        $numericAmount = (float) $amount;
        $multiplier = pow(10, $exponent);
        $intAmount = (int) round($numericAmount * $multiplier);
        return (string) $intAmount;
    }

    /**
     * Build the signing payload string from MPI form fields.
     *
     * @param array  $data            Request data
     * @param string $formattedAmount Formatted purchase amount
     * @param string|null $mpiVersion MPI version override
     * @return string Concatenated payload for signing
     */
    private static function buildSignPayload(array $data, string $formattedAmount, ?string $mpiVersion = null): string
    {
        $fields = [
            $mpiVersion ?? $data['mpiVersion'] ?? '',
            $data['pan'] ?? '',
            $data['expiry'] ?? '',
            $data['cardEncData'] ?? '',
            $data['devCat'] ?? '0',
            $formattedAmount,
            $data['exponent'] ?? '2',
            $data['description'] ?? '',
            $data['currMpi'] ?? '978',
            $data['merchantID'] ?? '',
            $data['xidb64'] ?? '',
            $data['okUrl'] ?? '',
            $data['failUrl'] ?? '',
        ];

        if (!empty($data['recurFreq'])) {
            $fields[] = $data['recurFreq'];
        }
        if (!empty($data['recurEnd'])) {
            $fields[] = $data['recurEnd'];
        }
        if (!empty($data['installments'])) {
            $fields[] = $data['installments'];
        }

        return implode('', $fields);
    }

    /**
     * Sign with RSA-SHA256 (v4.x).
     *
     * @param array  $data
     * @param string $formattedAmount
     * @param string $privateKeyPem
     * @param string $mpiVersion
     * @return string Base64-encoded signature or error string
     */
    private static function signWithRsa(array $data, string $formattedAmount, string $privateKeyPem, string $mpiVersion): string
    {
        $payload = self::buildSignPayload($data, $formattedAmount, $mpiVersion);

        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if ($privateKey === false) {
            return 'Error: Invalid private key — ' . openssl_error_string();
        }

        $signatureBytes = '';
        $success = openssl_sign($payload, $signatureBytes, $privateKey, OPENSSL_ALGO_SHA256);

        if (!$success) {
            return 'Error: Signing failed — ' . openssl_error_string();
        }

        return base64_encode($signatureBytes);
    }

    /**
     * Sign with HMAC-SHA1 (v2.x).
     *
     * @param array  $data
     * @param string $formattedAmount
     * @param string $sharedSecret
     * @param string $mpiVersion
     * @return string Base64-encoded HMAC signature
     */
    private static function signWithHmac(array $data, string $formattedAmount, string $sharedSecret, string $mpiVersion): string
    {
        $payload = self::buildSignPayload($data, $formattedAmount, $mpiVersion);
        return base64_encode(hash('sha1', $payload . $sharedSecret, true));
    }

    // =====================================================================
    //  TRANSACTION INFO MANAGEMENT (session + file cache)
    // =====================================================================

    /** Cache TTL: 30 minutes */
    private const CACHE_TTL = 1800;

    /** In-memory map: XID => transaction info array */
    private static $transactionMap = [];

    /**
     * Store transaction info for a given XID.
     *
     * @param string $xid  Base64-encoded XID
     * @param array  $info Transaction info array
     */
    public static function storeTransactionInfo(string $xid, array $info): void
    {
        self::$transactionMap[$xid] = $info;
    }

    /**
     * Retrieve transaction info for a given XID.
     *
     * @param string $xid Base64-encoded XID
     * @return array|null
     */
    public static function getTransactionInfo(string $xid): ?array
    {
        return self::$transactionMap[$xid] ?? null;
    }

    /**
     * Remove transaction info for a given XID.
     *
     * @param string $xid Base64-encoded XID
     */
    public static function removeTransactionInfo(string $xid): void
    {
        unset(self::$transactionMap[$xid]);
    }

    /**
     * Get the cache file path for a given XID.
     *
     * @param string $xid
     * @return string
     */
    private static function getCacheFilePath(string $xid): string
    {
        $cacheDir = _PS_CACHE_DIR_ . 'cardlink_gpay/';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        return $cacheDir . md5($xid) . '.json';
    }

    /**
     * Persist the current in-memory map to the PHP session.
     */
    public static function persistToSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['gpay_transaction_map'] = self::$transactionMap;
    }

    /**
     * Restore the in-memory map from the PHP session.
     */
    public static function restoreFromSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (isset($_SESSION['gpay_transaction_map']) && is_array($_SESSION['gpay_transaction_map'])) {
            self::$transactionMap = $_SESSION['gpay_transaction_map'];
        }
    }

    /**
     * Persist all in-memory entries to file cache.
     * Survives cross-domain MPI redirects where the session is lost.
     */
    public static function persistToCache(): void
    {
        foreach (self::$transactionMap as $xid => $info) {
            $info['_cache_time'] = time();
            $filePath = self::getCacheFilePath($xid);
            @file_put_contents($filePath, json_encode($info), LOCK_EX);
        }
    }

    /**
     * Restore a single entry from file cache.
     *
     * @param string $xid
     * @return array|null
     */
    public static function restoreFromCache(string $xid): ?array
    {
        $filePath = self::getCacheFilePath($xid);
        if (!file_exists($filePath)) {
            return null;
        }

        $raw = @file_get_contents($filePath);
        if ($raw === false) {
            return null;
        }

        $info = json_decode($raw, true);
        if (!is_array($info)) {
            return null;
        }

        // Check TTL
        $cacheTime = $info['_cache_time'] ?? 0;
        if ((time() - $cacheTime) > self::CACHE_TTL) {
            @unlink($filePath);
            return null;
        }

        unset($info['_cache_time']);
        self::$transactionMap[$xid] = $info;
        return $info;
    }

    /**
     * Remove a single entry from file cache.
     *
     * @param string $xid
     */
    public static function removeFromCache(string $xid): void
    {
        $filePath = self::getCacheFilePath($xid);
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }

    /**
     * Clean up expired cache files.
     */
    public static function cleanupExpiredCache(): void
    {
        $cacheDir = _PS_CACHE_DIR_ . 'cardlink_gpay/';
        if (!is_dir($cacheDir)) {
            return;
        }

        $files = glob($cacheDir . '*.json');
        $now = time();

        foreach ($files as $file) {
            if (($now - filemtime($file)) > self::CACHE_TTL) {
                @unlink($file);
            }
        }
    }

    // =====================================================================
    //  CURRENCY HELPERS
    // =====================================================================

    /**
     * Get the ISO 4217 numeric currency code for MPI.
     *
     * @param string $isoCode Three-letter ISO code (e.g. "EUR")
     * @return string Numeric code (e.g. "978")
     */
    public static function getCurrencyNumericCode(string $isoCode): string
    {
        $map = [
            'EUR' => '978',
            'USD' => '840',
            'GBP' => '826',
            'CHF' => '756',
            'SEK' => '752',
            'NOK' => '578',
            'DKK' => '208',
            'PLN' => '985',
            'CZK' => '203',
            'HUF' => '348',
            'RON' => '946',
            'BGN' => '975',
            'HRK' => '191',
            'TRY' => '949',
        ];

        return $map[strtoupper($isoCode)] ?? '978';
    }
}
