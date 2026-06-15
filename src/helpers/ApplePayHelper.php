<?php

namespace Cardlink_Checkout;

use Configuration;

class ApplePayHelper
{
    private const CACHE_TTL = 1800;
    private static $transactionMap = [];

    public static function isEnabled(): bool
    {
        return Constants::ENABLE_APPLEPAY
            && boolval(Configuration::get(Constants::CONFIG_APPLEPAY_ENABLE, null, null, null, '0'));
    }

    public static function getMerchantId(): string
    {
        return Configuration::get(Constants::CONFIG_APPLEPAY_MERCHANT_ID, null, null, null, '') ?: '';
    }

    public static function getSharedSecret(): string
    {
        return Configuration::get(Constants::CONFIG_APPLEPAY_SHARED_SECRET, null, null, null, '') ?: '';
    }

    public static function getBusinessPartner(): string
    {
        return Constants::BUSINESS_PARTNER_WORLDLINE;
    }

    public static function getTransactionEnvironment(): string
    {
        return Configuration::get(Constants::CONFIG_APPLEPAY_TRANSACTION_ENVIRONMENT, null, null, null, Constants::TRANSACTION_ENVIRONMENT_SANDBOX) ?: Constants::TRANSACTION_ENVIRONMENT_SANDBOX;
    }

    public static function getMpiPrivateKey(): string
    {
        return Configuration::get(Constants::CONFIG_APPLEPAY_MPI_PRIVATE_KEY, null, null, null, '') ?: '';
    }

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

    public static function getDirectScriptUrl(): string
    {
        return self::getVposDomain() . '/vpos/js/applepaydirect.js';
    }

    public static function getMpiUrl(): string
    {
        return self::getVposDomain() . '/mdpaympi/MerchantServer';
    }

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

    public static function calculateXID(string $txId, string $trExtId, string $trMpiCounts): string
    {
        $raw = 'VPOS'
            . self::padCutLeft($txId, 7)
            . '-'
            . self::padCutLeft($trExtId, 6)
            . self::padCutLeft($trMpiCounts, 2);

        return base64_encode($raw);
    }

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

    private static function formatPurchaseAmount(string $amount, int $exponent): string
    {
        $numericAmount = (float) $amount;
        $multiplier = pow(10, $exponent);
        $intAmount = (int) round($numericAmount * $multiplier);
        return (string) $intAmount;
    }

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

    private static function signWithHmac(array $data, string $formattedAmount, string $sharedSecret, string $mpiVersion): string
    {
        $payload = self::buildSignPayload($data, $formattedAmount, $mpiVersion);
        return base64_encode(hash('sha1', $payload . $sharedSecret, true));
    }

    public static function storeTransactionInfo(string $xid, array $info): void
    {
        self::$transactionMap[$xid] = $info;
    }

    public static function getTransactionInfo(string $xid): ?array
    {
        return self::$transactionMap[$xid] ?? null;
    }

    public static function removeTransactionInfo(string $xid): void
    {
        unset(self::$transactionMap[$xid]);
    }

    private static function getCacheFilePath(string $xid): string
    {
        $cacheDir = _PS_CACHE_DIR_ . 'cardlink_apay/';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        return $cacheDir . md5($xid) . '.json';
    }

    public static function persistToSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['apay_transaction_map'] = self::$transactionMap;
    }

    public static function restoreFromSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (isset($_SESSION['apay_transaction_map']) && is_array($_SESSION['apay_transaction_map'])) {
            self::$transactionMap = $_SESSION['apay_transaction_map'];
        }
    }

    public static function persistToCache(): void
    {
        foreach (self::$transactionMap as $xid => $info) {
            $info['_cache_time'] = time();
            $filePath = self::getCacheFilePath($xid);
            @file_put_contents($filePath, json_encode($info), LOCK_EX);
        }
    }

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

        $cacheTime = $info['_cache_time'] ?? 0;
        if ((time() - $cacheTime) > self::CACHE_TTL) {
            @unlink($filePath);
            return null;
        }

        unset($info['_cache_time']);
        self::$transactionMap[$xid] = $info;
        return $info;
    }

    public static function removeFromCache(string $xid): void
    {
        $filePath = self::getCacheFilePath($xid);
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }

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
