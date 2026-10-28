<?php
// includes/turnstile.php - Cloudflare Turnstile anti-bot integration

/**
 * Returns the configured Cloudflare Turnstile public Site Key.
 */
function turnstile_site_key() {
    if (defined('TURNSTILE_SITE_KEY') && TURNSTILE_SITE_KEY !== '') {
        return trim((string)TURNSTILE_SITE_KEY);
    }
    $env = getenv('TURNSTILE_SITE_KEY');
    if ($env !== false && $env !== '') {
        return trim($env);
    }
    if (function_exists('cms') && !(defined('TESTING') && TESTING)) {
        $val = cms('turnstile.site_key', '');
        if (!empty($val)) {
            return trim($val);
        }
    }
    return '';
}

/**
 * Returns the configured Cloudflare Turnstile Secret Key.
 */
function turnstile_secret_key() {
    if (defined('TURNSTILE_SECRET_KEY') && TURNSTILE_SECRET_KEY !== '') {
        return trim((string)TURNSTILE_SECRET_KEY);
    }
    $env = getenv('TURNSTILE_SECRET_KEY');
    if ($env !== false && $env !== '') {
        return trim($env);
    }
    if (function_exists('cms') && !(defined('TESTING') && TESTING)) {
        $val = cms('turnstile.secret_key', '');
        if (!empty($val)) {
            return trim($val);
        }
    }
    return '';
}

/**
 * Check whether Cloudflare Turnstile is active.
 * Returns true if both keys are configured and Turnstile is not disabled.
 */
function turnstile_enabled() {
    $siteKey = turnstile_site_key();
    $secretKey = turnstile_secret_key();

    if ($siteKey === '' || $secretKey === '') {
        return false;
    }

    if (defined('TURNSTILE_ENABLED')) {
        return (bool)TURNSTILE_ENABLED;
    }

    $env = getenv('TURNSTILE_ENABLED');
    if ($env !== false && $env !== '') {
        return in_array(strtolower(trim($env)), ['1', 'true', 'yes', 'on'], true);
    }

    if (function_exists('cms') && !(defined('TESTING') && TESTING)) {
        $enabled = cms('turnstile.enabled', '1');
        if ($enabled === '0' || strtolower($enabled) === 'false') {
            return false;
        }
    }

    return true;
}

/**
 * Outputs the Cloudflare Turnstile script tag once per page.
 */
function turnstile_script() {
    static $rendered = false;
    if ($rendered) {
        return '';
    }
    $rendered = true;

    // Load Turnstile script tag (CSP permits https://challenges.cloudflare.com)
    return '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>' . "\n";
}

/**
 * Renders the Turnstile widget inside a form.
 *
 * @param string $theme 'auto' | 'light' | 'dark'
 * @param string $action Optional action name for analytics (e.g. 'login', 'contact')
 * @return string HTML markup
 */
function turnstile_widget($theme = 'auto', $action = '') {
    if (!turnstile_enabled()) {
        return '';
    }

    $siteKey = turnstile_site_key();
    $actionAttr = $action !== '' ? ' data-action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '"' : '';

    return '<div class="cf-turnstile-wrapper" style="margin: 15px 0;">'
        . '<div class="cf-turnstile" data-sitekey="' . htmlspecialchars($siteKey, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-theme="' . htmlspecialchars($theme, ENT_QUOTES, 'UTF-8') . '"'
        . $actionAttr
        . '></div></div>';
}

/**
 * Verifies a submitted Turnstile token with Cloudflare siteverify endpoint.
 *
 * @param string|null $token If null, reads $_POST['cf-turnstile-response']
 * @param string|null $remoteIp If null, reads $_SERVER['REMOTE_ADDR']
 * @return array ['success' => bool, 'error' => string|null]
 */
function turnstile_verify($token = null, $remoteIp = null) {
    if (!turnstile_enabled()) {
        return ['success' => true, 'error' => null];
    }

    $token = $token !== null ? $token : ($_POST['cf-turnstile-response'] ?? '');
    if (empty($token) || !is_string($token)) {
        return [
            'success' => false,
            'error'   => 'Please complete the security verification challenge (Turnstile).'
        ];
    }

    $secretKey = turnstile_secret_key();
    if ($secretKey === '') {
        return ['success' => true, 'error' => null];
    }

    $remoteIp = $remoteIp ?: ($_SERVER['REMOTE_ADDR'] ?? '');

    $postFields = http_build_query([
        'secret'   => $secretKey,
        'response' => $token,
        'remoteip' => $remoteIp
    ]);

    $url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    $rawResponse = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $rawResponse = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log('Turnstile curl error: ' . $curlErr);
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $postFields,
                'timeout' => 10
            ]
        ]);
        $rawResponse = @file_get_contents($url, false, $context);
    }

    if ($rawResponse === false || empty($rawResponse)) {
        error_log('Turnstile siteverify response empty or unreachable.');
        return [
            'success' => false,
            'error'   => 'Could not reach the security verification service. Please try again.'
        ];
    }

    $data = json_decode($rawResponse, true);
    if (!is_array($data) || empty($data['success'])) {
        $errorCodes = isset($data['error-codes']) ? implode(', ', (array)$data['error-codes']) : 'unknown';
        error_log('Turnstile validation failed: ' . $errorCodes);
        return [
            'success' => false,
            'error'   => 'Security check failed. Please refresh the page and try again.'
        ];
    }

    return ['success' => true, 'error' => null];
}
