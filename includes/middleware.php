<?php
// includes/middleware.php - Middleware Functions

class Middleware {
    
    /**
     * Check if user is authenticated
     */
    public static function auth() {
        if (!Security::isLoggedIn()) {
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                // AJAX request
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized', 'redirect' => BASE_URL . '/login.php']);
                exit;
            } else {
                // Normal request
                $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
                header('Location: ' . BASE_URL . '/login.php');
                exit;
            }
        }
    }
    
    /**
     * Check if user has specific role
     */
    public static function role($roles) {
        self::auth();
        
        $roles = is_array($roles) ? $roles : [$roles];
        
        if (!in_array($_SESSION['user_role'], $roles)) {
            self::forbidden();
        }
    }
    
    /**
     * Check if user has permission
     */
    public static function permission($permission) {
        self::auth();
        
        $auth = new Auth();
        if (!$auth->hasPermission($_SESSION['user_id'], $permission)) {
            self::forbidden();
        }
    }
    
    /**
     * Check CSRF token
     */
    public static function csrf() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            
            if (!Security::verifyCSRFToken($token)) {
                if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                    http_response_code(419);
                    echo json_encode(['error' => 'CSRF token mismatch']);
                    exit;
                } else {
                    die('Invalid CSRF token');
                }
            }
        }
    }
    
    /**
     * Rate limiting
     */
    public static function rateLimit($key, $limit = 60, $minutes = 1) {
        $redis = null; // Implement Redis if available
        
        if ($redis) {
            $current = $redis->get($key);
            
            if ($current >= $limit) {
                http_response_code(429);
                die('Too many requests');
            }
            
            $redis->incr($key);
            $redis->expire($key, $minutes * 60);
        } else {
            // Fallback to session-based rate limiting
            $key = 'rate_limit_' . $key;
            
            if (!isset($_SESSION[$key])) {
                $_SESSION[$key] = [
                    'count' => 1,
                    'time' => time()
                ];
            } else {
                if (time() - $_SESSION[$key]['time'] > $minutes * 60) {
                    $_SESSION[$key] = [
                        'count' => 1,
                        'time' => time()
                    ];
                } else {
                    $_SESSION[$key]['count']++;
                    
                    if ($_SESSION[$key]['count'] > $limit) {
                        http_response_code(429);
                        die('Too many requests');
                    }
                }
            }
        }
    }
    
    /**
     * Check HTTPS
     */
    public static function https() {
        if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
            $redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            header('Location: ' . $redirect);
            exit;
        }
    }
    
    /**
     * Check maintenance mode
     */
    public static function maintenance($enabled = false, $allowedIps = []) {
        if ($enabled) {
            $ip = $_SERVER['REMOTE_ADDR'];
            
            if (!in_array($ip, $allowedIps)) {
                http_response_code(503);
                include 'maintenance.php';
                exit;
            }
        }
    }
    
    /**
     * Validate request method
     */
    public static function method($allowed) {
        $allowed = is_array($allowed) ? $allowed : [$allowed];
        $method = $_SERVER['REQUEST_METHOD'];
        
        if (!in_array($method, $allowed)) {
            http_response_code(405);
            header('Allow: ' . implode(', ', $allowed));
            die('Method not allowed');
        }
    }
    
    /**
     * Check input validation
     */
    public static function validate($data, $rules) {
        $validation = Validator::validate($data, $rules);
        
        if (!$validation['valid']) {
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                http_response_code(422);
                echo json_encode(['errors' => $validation['errors']]);
                exit;
            } else {
                $_SESSION['validation_errors'] = $validation['errors'];
                $_SESSION['old_input'] = $data;
                header('Location: ' . $_SERVER['HTTP_REFERER']);
                exit;
            }
        }
        
        return true;
    }
    
    /**
     * Return 403 Forbidden
     */
    private static function forbidden() {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        } else {
            http_response_code(403);
            include 'error/403.php';
            exit;
        }
    }
    
    /**
     * Log request
     */
    public static function logRequest() {
        $log = [
            'time' => date('Y-m-d H:i:s'),
            'method' => $_SERVER['REQUEST_METHOD'],
            'url' => $_SERVER['REQUEST_URI'],
            'ip' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'user_id' => $_SESSION['user_id'] ?? null
        ];
        
        $logFile = LOG_PATH . '/requests.log';
        file_put_contents($logFile, json_encode($log) . PHP_EOL, FILE_APPEND);
    }
    
    /**
     * CORS headers for API
     */
    public static function cors() {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit(0);
        }
    }
    
    /**
     * Cache headers
     */
    public static function cache($seconds = 3600) {
        header('Cache-Control: public, max-age=' . $seconds);
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $seconds) . ' GMT');
    }
    
    /**
     * No cache headers
     */
    public static function noCache() {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
    }
    
    /**
     * GZIP compression
     */
    public static function gzip() {
        if (extension_loaded('zlib') && !ob_start('ob_gzhandler')) {
            ob_start();
        }
    }
}