<?php
// config/security.php
// Security functions and middleware

// Make sure database is loaded
require_once __DIR__ . '/database.php';

// Define security constants if not already defined
if (!defined('CSRF_TOKEN_NAME')) {
    define('CSRF_TOKEN_NAME', 'csrf_token');
}
if (!defined('MAX_LOGIN_ATTEMPTS')) {
    define('MAX_LOGIN_ATTEMPTS', 5);
}
if (!defined('LOCKOUT_TIME')) {
    define('LOCKOUT_TIME', 900);
}
if (!defined('MAX_FILE_SIZE')) {
    define('MAX_FILE_SIZE', 5242880);
}
if (!defined('ALLOWED_EXTENSIONS')) {
    define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx']);
}
if (!defined('BCRYPT_COST')) {
    define('BCRYPT_COST', 12);
}

// Define db function if it doesn't exist
if (!function_exists('db')) {
    function db() {
        return Database::getInstance();
    }
}

class Security {
    
    // Generate CSRF token
    public static function generateCSRFToken() {
        if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
            $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
        }
        return $_SESSION[CSRF_TOKEN_NAME];
    }
    
    // Verify CSRF token
    public static function verifyCSRFToken($token) {
        if (!isset($_SESSION[CSRF_TOKEN_NAME]) || $token !== $_SESSION[CSRF_TOKEN_NAME]) {
            error_log("CSRF token validation failed");
            return false;
        }
        return true;
    }
    
    // Sanitize input
    public static function sanitize($input) {
        if (is_array($input)) {
            return array_map([self::class, 'sanitize'], $input);
        }
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
    
    // Validate email
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }
    
    // Validate phone (Nigerian format)
    public static function validatePhone($phone) {
        return preg_match('/^0[789][01]\d{8}$/', $phone);
    }
    
    // Hash password
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    }
    
    // Verify password
    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
    
    // Check if user is logged in
    public static function isLoggedIn() {
        return isset($_SESSION['user_id']) && isset($_SESSION['user_role']);
    }
    
    // Check role
    public static function hasRole($role) {
        return self::isLoggedIn() && $_SESSION['user_role'] === $role;
    }
    
    // Require login
    public static function requireLogin() {
        if (!self::isLoggedIn()) {
            header('Location: ' . BASE_URL . '/login.php');
            exit();
        }
    }
    
    // Require role
    public static function requireRole($role) {
        self::requireLogin();
        if (!self::hasRole($role)) {
            header('HTTP/1.0 403 Forbidden');
            die('Access Denied: Insufficient permissions');
        }
    }
    
    // Check rate limiting for login attempts
    public static function checkLoginAttempts($email) {
        $db = self::getDB();
        $user = $db->getRow("SELECT id, login_attempts, locked_until FROM users WHERE email = ?", [$email]);
        
        if ($user) {
            if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                return false; // Account is locked
            }
            
            if ($user['login_attempts'] >= MAX_LOGIN_ATTEMPTS) {
                // Lock account
                $db->query(
                    "UPDATE users SET locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?",
                    [LOCKOUT_TIME, $user['id']]
                );
                return false;
            }
        }
        return true;
    }
    
    // Log failed login attempt
    public static function logFailedAttempt($email) {
        $db = self::getDB();
        $db->query(
            "UPDATE users SET login_attempts = login_attempts + 1 WHERE email = ?",
            [$email]
        );
    }
    
    // Reset login attempts on successful login
    public static function resetLoginAttempts($userId) {
        $db = self::getDB();
        $db->query(
            "UPDATE users SET login_attempts = 0, locked_until = NULL WHERE id = ?",
            [$userId]
        );
    }
    
    // Validate file upload
    public static function validateFileUpload($file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'message' => 'Upload failed'];
        }
        
        if ($file['size'] > MAX_FILE_SIZE) {
            return ['valid' => false, 'message' => 'File too large'];
        }
        
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ALLOWED_EXTENSIONS)) {
            return ['valid' => false, 'message' => 'File type not allowed'];
        }
        
        return ['valid' => true, 'extension' => $extension];
    }
    
    // Generate secure filename
    public static function generateSecureFilename($originalName) {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        return bin2hex(random_bytes(16)) . '.' . $extension;
    }
    
    // Get database instance (safer than using global db function)
    private static function getDB() {
        return Database::getInstance();
    }
    
    // Log audit trail
    public static function logAudit($action, $table = null, $recordId = null, $oldValues = null, $newValues = null) {
        if (!isset($_SESSION['user_id'])) {
            return;
        }
        
        try {
            $db = self::getDB();
            $db->insert(
                "INSERT INTO audit_logs (user_id, action, table_affected, record_id, old_values, new_values, ip_address, user_agent) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $_SESSION['user_id'],
                    $action,
                    $table,
                    $recordId,
                    $oldValues ? json_encode($oldValues) : null,
                    $newValues ? json_encode($newValues) : null,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]
            );
        } catch (Exception $e) {
            error_log("Audit log error: " . $e->getMessage());
        }
    }
}
?>