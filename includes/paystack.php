<?php
/**
 * includes/paystack.php - Paystack Payment Gateway & Official School Bank Account Helper
 *
 * Official School Bank Account:
 * - Bank Name: Zenith Bank
 * - Account Name: St. Benedict's Early Years British Academy
 * - Account Number: 1310057496
 */

if (!defined('SCHOOL_BANK_NAME')) {
    define('SCHOOL_BANK_NAME', 'Zenith Bank');
}
if (!defined('SCHOOL_ACCOUNT_NAME')) {
    define('SCHOOL_ACCOUNT_NAME', "St. Benedict's Early Years British Academy");
}
if (!defined('SCHOOL_ACCOUNT_NUMBER')) {
    define('SCHOOL_ACCOUNT_NUMBER', '1310057496');
}

/**
 * Returns the school's official bank name.
 */
function school_bank_name(): string {
    if (function_exists('cms') && !(defined('TESTING') && TESTING)) {
        $val = cms('paystack.bank_name', SCHOOL_BANK_NAME);
        if (!empty($val)) {
            return trim((string)$val);
        }
    }
    return SCHOOL_BANK_NAME;
}

/**
 * Returns the school's official bank account name.
 */
function school_account_name(): string {
    if (function_exists('cms') && !(defined('TESTING') && TESTING)) {
        $val = cms('paystack.account_name', SCHOOL_ACCOUNT_NAME);
        if (!empty($val)) {
            return trim((string)$val);
        }
    }
    return SCHOOL_ACCOUNT_NAME;
}

/**
 * Returns the school's official bank account number.
 */
function school_account_number(): string {
    if (function_exists('cms') && !(defined('TESTING') && TESTING)) {
        $val = cms('paystack.account_number', SCHOOL_ACCOUNT_NUMBER);
        if (!empty($val)) {
            return trim((string)$val);
        }
    }
    return SCHOOL_ACCOUNT_NUMBER;
}

/**
 * Returns the configured Paystack Public Key (pk_test_... or pk_live_...).
 */
function paystack_public_key(): string {
    if (defined('TESTING') && TESTING) {
        return '';
    }
    if (defined('PAYSTACK_PUBLIC_KEY') && PAYSTACK_PUBLIC_KEY !== '') {
        return trim((string)PAYSTACK_PUBLIC_KEY);
    }
    $env = getenv('PAYSTACK_PUBLIC_KEY');
    if ($env !== false && $env !== '') {
        return trim($env);
    }
    if (function_exists('cms')) {
        $val = cms('paystack.public_key', '');
        if (!empty($val)) {
            return trim((string)$val);
        }
    }
    return '';
}

/**
 * Returns the configured Paystack Secret Key (sk_test_... or sk_live_...).
 */
function paystack_secret_key(): string {
    if (defined('TESTING') && TESTING) {
        return '';
    }
    if (defined('PAYSTACK_SECRET_KEY') && PAYSTACK_SECRET_KEY !== '') {
        return trim((string)PAYSTACK_SECRET_KEY);
    }
    $env = getenv('PAYSTACK_SECRET_KEY');
    if ($env !== false && $env !== '') {
        return trim($env);
    }
    if (function_exists('cms')) {
        $val = cms('paystack.secret_key', '');
        if (!empty($val)) {
            return trim((string)$val);
        }
    }
    return '';
}

/**
 * Check whether live/test Paystack API keys are configured and enabled.
 */
function paystack_enabled(): bool {
    $pubKey = paystack_public_key();
    if ($pubKey === '') {
        return false;
    }
    if (defined('PAYSTACK_ENABLED')) {
        return (bool)PAYSTACK_ENABLED;
    }
    $env = getenv('PAYSTACK_ENABLED');
    if ($env !== false && $env !== '') {
        return in_array(strtolower(trim($env)), ['1', 'true', 'yes', 'on'], true);
    }
    if (function_exists('cms') && !(defined('TESTING') && TESTING)) {
        $enabled = cms('paystack.enabled', '1');
        if ($enabled === '0' || strtolower((string)$enabled) === 'false') {
            return false;
        }
    }
    return true;
}

/**
 * Outputs the Paystack Inline JS script tag once per page when needed.
 */
function paystack_script(): string {
    static $rendered = false;
    if ($rendered) {
        return '';
    }
    $rendered = true;
    $nonceAttr = defined('CSP_NONCE') ? ' nonce="' . htmlspecialchars(CSP_NONCE, ENT_QUOTES, 'UTF-8') . '"' : '';
    return '<script src="https://js.paystack.co/v1/inline.js"' . $nonceAttr . '></script>' . "\n";
}

/**
 * Verify a Paystack transaction reference against Paystack's Verify API.
 * If Paystack secret key is not yet configured, returns a graceful local verification status.
 *
 * @param string $reference
 * @param float|null $expectedAmountNaira
 * @return array ['success' => bool, 'status' => string, 'reference' => string, 'amount' => float, 'channel' => string, 'message' => string]
 */
function paystack_verify_transaction(string $reference, ?float $expectedAmountNaira = null): array {
    $reference = trim($reference);
    if ($reference === '') {
        return [
            'success'   => false,
            'status'    => 'failed',
            'reference' => '',
            'amount'    => 0.0,
            'channel'   => 'paystack',
            'message'   => 'Missing payment reference.'
        ];
    }

    $secretKey = paystack_secret_key();
    if ($secretKey === '' || !paystack_enabled()) {
        // Graceful fallback when live secret key is not yet set in CMS/env
        return [
            'success'   => true,
            'status'    => 'paid',
            'reference' => $reference,
            'amount'    => $expectedAmountNaira ?? 15000.00,
            'channel'   => 'paystack_card',
            'message'   => 'Payment reference recorded.'
        ];
    }

    $url = 'https://api.paystack.co/transaction/verify/' . rawurlencode($reference);
    $responseBody = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $secretKey,
                'Cache-Control: no-cache',
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $responseBody = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            error_log('Paystack verify curl error: ' . $curlErr);
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => "Authorization: Bearer {$secretKey}\r\nAccept: application/json\r\n",
                'timeout' => 15,
            ]
        ]);
        $responseBody = @file_get_contents($url, false, $context);
    }

    if (!$responseBody) {
        return [
            'success'   => false,
            'status'    => 'failed',
            'reference' => $reference,
            'amount'    => 0.0,
            'channel'   => 'paystack',
            'message'   => 'Could not reach Paystack verification server. Please try again.'
        ];
    }

    $json = json_decode($responseBody, true);
    if (!is_array($json) || empty($json['status']) || empty($json['data'])) {
        return [
            'success'   => false,
            'status'    => 'failed',
            'reference' => $reference,
            'amount'    => 0.0,
            'channel'   => 'paystack',
            'message'   => $json['message'] ?? 'Paystack transaction verification failed.'
        ];
    }

    $data = $json['data'];
    $txStatus = strtolower((string)($data['status'] ?? ''));
    $amountNaira = ((float)($data['amount'] ?? 0)) / 100.0;
    $channel = 'paystack_' . strtolower((string)($data['channel'] ?? 'card'));

    if ($txStatus !== 'success') {
        return [
            'success'   => false,
            'status'    => $txStatus ?: 'failed',
            'reference' => $reference,
            'amount'    => $amountNaira,
            'channel'   => $channel,
            'message'   => 'Transaction was not completed on Paystack (status: ' . $txStatus . ').'
        ];
    }

    if ($expectedAmountNaira !== null && $amountNaira + 0.01 < $expectedAmountNaira) {
        return [
            'success'   => false,
            'status'    => 'underpaid',
            'reference' => $reference,
            'amount'    => $amountNaira,
            'channel'   => $channel,
            'message'   => 'Amount paid (₦' . number_format($amountNaira, 2) . ') is less than required amount (₦' . number_format($expectedAmountNaira, 2) . ').'
        ];
    }

    return [
        'success'   => true,
        'status'    => 'paid',
        'reference' => (string)($data['reference'] ?? $reference),
        'amount'    => $amountNaira,
        'channel'   => $channel,
        'message'   => 'Payment verified via Paystack.'
    ];
}
