<?php
// config/environment.php
// Environment detection and database settings. No secrets live in this file:
// set DB_HOST / DB_NAME / DB_USER / DB_PASS (and APP_ENV) as environment
// variables, or define them in config/local.php (git-ignored).

if (!defined('ENVIRONMENT')) {
    $appEnv = getenv('APP_ENV');
    if (!$appEnv) {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (PHP_SAPI === 'cli' || preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', $host)) {
            $appEnv = 'development';
        } elseif (strpos($host, 'staging.') === 0) {
            $appEnv = 'staging';
        } else {
            $appEnv = 'production';
        }
    }
    define('ENVIRONMENT', $appEnv);
}

if (!defined('DEBUG_MODE')) {
    define('DEBUG_MODE', ENVIRONMENT === 'development');
}

$__dbDefault = function ($name, $default) {
    $v = getenv($name);
    return ($v === false || $v === '') ? $default : $v;
};

if (!defined('DB_HOST')) define('DB_HOST', $__dbDefault('DB_HOST', 'localhost'));
if (!defined('DB_NAME')) define('DB_NAME', $__dbDefault('DB_NAME', 'st_benedicts_academy'));
if (!defined('DB_USER')) define('DB_USER', $__dbDefault('DB_USER', ENVIRONMENT === 'development' ? 'root' : ''));
if (!defined('DB_PASS')) define('DB_PASS', $__dbDefault('DB_PASS', ''));
unset($__dbDefault);
