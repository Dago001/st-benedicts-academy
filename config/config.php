<?php
// config/config.php
// Main configuration file. Environment specific values come from
// environment variables or an optional, git-ignored config/local.php.

// Buffer output so pages can still redirect (Post/Redirect/Get) after rendering the header
if (PHP_SAPI !== 'cli') {
    // Unlimited-size buffer (a server-level buffer may flush early at e.g. 4 KB)
    ob_start();
}

define('ROOT_PATH', dirname(__DIR__));
define('LOG_PATH', ROOT_PATH . '/logs');
if (!is_dir(LOG_PATH)) {
    @mkdir(LOG_PATH, 0750, true);
}

if (is_file(__DIR__ . '/local.php')) {
    require_once __DIR__ . '/local.php';
}

require_once __DIR__ . '/environment.php';

// Error reporting: never display errors to visitors, always log them
error_reporting(E_ALL);
ini_set('display_errors', DEBUG_MODE ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', LOG_PATH . '/error.log');

date_default_timezone_set('Africa/Lagos');

// HTTPS detection (also behind a proxy / load balancer)
$GLOBALS['__is_https'] = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (($_SERVER['SERVER_PORT'] ?? '') == 443);
function is_https() { return $GLOBALS['__is_https']; }

// Base URL: configured value wins, otherwise derived from the request so the
// app works from any folder (or the domain root) without editing the code.
if (!defined('BASE_URL')) {
    $envBase = getenv('APP_URL');
    if ($envBase) {
        define('BASE_URL', rtrim($envBase, '/'));
    } else {
        $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
        $root = realpath(ROOT_PATH);
        $path = '';
        if ($docRoot !== '' && strpos($root, $docRoot) === 0) {
            $path = str_replace('\\', '/', substr($root, strlen($docRoot)));
        }
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        // Only trust a plain host[:port] value
        if (!preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) {
            $host = 'localhost';
        }
        define('BASE_URL', (is_https() ? 'https' : 'http') . '://' . $host . rtrim($path, '/'));
    }
}
define('SITE_NAME', "ST. BENEDICT'S EARLY YEARS BRITISH ACADEMY");

// Session configuration
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '7200');
    session_name('STBSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Idle timeout (2 hours)
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > 7200) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_activity'] = time();
}

// Per-request nonce for inline <script> blocks (see Content-Security-Policy below)
define('CSP_NONCE', rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='));

// Security headers
if (!headers_sent()) {
    $csp = ["default-src 'self'",
            // Inline <script> blocks need the nonce; injected scripts and external hosts are blocked.
            // script-src-attr keeps the legacy onclick="..." handlers working until they are migrated.
            "script-src 'self' 'nonce-" . CSP_NONCE . "'",
            "script-src-attr 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "media-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'"];
    if (is_https()) $csp[] = 'upgrade-insecure-requests';
    header('Content-Security-Policy: ' . implode('; ', $csp));
    header('Cross-Origin-Opener-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

// Autoload the model/helper classes in includes/ (ClassModel lives in Class.php)
spl_autoload_register(function ($class) {
    $map = ['ClassModel' => 'Class'];
    $file = ROOT_PATH . '/includes/' . ($map[$class] ?? $class) . '.php';
    if (preg_match('/^[A-Za-z]+$/', $class) && is_file($file)) {
        require_once $file;
    }
});

// File upload settings
define('MAX_FILE_SIZE', 5242880); // 5MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx']);
define('UPLOAD_PATH', ROOT_PATH . '/uploads/');
// Private documents (admission applications) live outside the public uploads folder
define('PRIVATE_PATH', ROOT_PATH . '/storage/');

// Pagination
define('ITEMS_PER_PAGE', 20);

// Security
define('BCRYPT_COST', 12);
define('CSRF_TOKEN_NAME', 'csrf_token');
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 900); // 15 minutes

// School info
define('SCHOOL_NAME', "ST. BENEDICT'S EARLY YEARS BRITISH ACADEMY");
define('SCHOOL_ADDRESS', 'No 560 A A New G.R.A TRANS-EKULU, ENUGU');
define('SCHOOL_PHONE', '09044472688');
define('SCHOOL_EMAIL', 'info@stbenedicts.edu.ng');
define('SCHOOL_HOURS', [
    'Monday' => '8:00 AM - 4:00 PM', 'Tuesday' => '8:00 AM - 4:00 PM', 'Wednesday' => '8:00 AM - 4:00 PM',
    'Thursday' => '8:00 AM - 4:00 PM', 'Friday' => '8:00 AM - 2:00 PM', 'Saturday' => 'Closed', 'Sunday' => 'Closed',
]);
define('SCHOOL_MOTTO', 'Christo Duce, Una Sapientia et Virtute Crescimus');
