<?php
// config/config.php
// Main configuration file

// Error Reporting (turn off in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');

// Timezone
date_default_timezone_set('Africa/Lagos');

// Session Configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.gc_maxlifetime', 7200); // 2 hours

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Base URL (update in production)
define('BASE_URL', 'http://localhost/st-benedicts-academy');
define('SITE_NAME', 'ST. BENEDICT\'S EARLY YEARS BRITISH ACADEMY');

// File Upload Settings
define('MAX_FILE_SIZE', 5242880); // 5MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx']);
define('UPLOAD_PATH', __DIR__ . '/../uploads/'); // ADD THIS LINE

// Pagination
define('ITEMS_PER_PAGE', 20);

// Security
define('BCRYPT_COST', 12);
define('CSRF_TOKEN_NAME', 'csrf_token');
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 900); // 15 minutes

// School Info
define('SCHOOL_NAME', 'ST. BENEDICT\'S EARLY YEARS BRITISH ACADEMY');
define('SCHOOL_ADDRESS', 'No 560 A A New G.R.A TRANS-EKULU, ENUGU');
define('SCHOOL_PHONE', '09044472688');
define('SCHOOL_EMAIL', 'info@stbenedicts.edu.ng');
define('SCHOOL_MOTTO', 'Christo Duce, Una Sapientia et Virtute Crescimus');