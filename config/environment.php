<?php
// config/environment.php
// Environment-specific configuration

// Detect environment
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
    define('ENVIRONMENT', 'development');
} elseif (strpos($host, 'staging.') !== false) {
    define('ENVIRONMENT', 'staging');
} else {
    define('ENVIRONMENT', 'production');
}

// Environment-specific settings
switch (ENVIRONMENT) {
    case 'development':
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        define('DEBUG_MODE', true);
        break;
        
    case 'staging':
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
        ini_set('display_errors', 0);
        ini_set('log_errors', 1);
        define('DEBUG_MODE', true);
        break;
        
    case 'production':
        error_reporting(0);
        ini_set('display_errors', 0);
        ini_set('log_errors', 1);
        define('DEBUG_MODE', false);
        break;
}

// Database configuration based on environment
if (ENVIRONMENT === 'development') {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'st_benedicts_academy');
    define('DB_USER', 'root');
    define('DB_PASS', '');
} elseif (ENVIRONMENT === 'staging') {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'st_benedicts_staging');
    define('DB_USER', 'staging_user');
    define('DB_PASS', 'staging_password');
} else {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'st_benedicts_prod');
    define('DB_USER', 'prod_user');
    define('DB_PASS', 'secure_prod_password');
}