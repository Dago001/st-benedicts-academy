<?php
// includes/auth.php - Authentication Functions

class Auth {
    private $db;
    private $maxAttempts = 5;
    private $lockoutTime = 900; // 15 minutes
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Authenticate user
     */
    public function login($email, $password, $remember = false) {
        // Check if account is locked
        if ($this->isLocked($email)) {
            return [
                'success' => false,
                'message' => 'Account is temporarily locked. Please try again after 15 minutes.'
            ];
        }
        
        // Get user
        $user = $this->db->getRow(
            "SELECT id, username, email, password_hash, first_name, last_name, role, is_active 
             FROM users WHERE email = ? AND deleted_at IS NULL",
            [$email]
        );
        
        if (!$user || !Security::verifyPassword($password, $user['password_hash'])) {
            $this->logFailedAttempt($email);
            return [
                'success' => false,
                'message' => 'Invalid email or password'
            ];
        }
        
        if (!$user['is_active']) {
            return [
                'success' => false,
                'message' => 'Your account has been deactivated. Please contact administration.'
            ];
        }
        
        // Login successful - reset attempts
        $this->resetAttempts($email);
        
        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();
        
        // Update last login
        $this->db->query(
            "UPDATE users SET last_login = NOW() WHERE id = ?",
            [$user['id']]
        );
        
        // Set remember me cookie
        if ($remember) {
            $this->setRememberMe($user['id']);
        }
        
        // Regenerate session ID for security
        session_regenerate_id(true);
        
        // Log activity
        Security::logAudit('LOGIN_SUCCESS', 'users', $user['id']);
        
        return [
            'success' => true,
            'role' => $user['role'],
            'user' => [
                'id' => $user['id'],
                'name' => $user['first_name'] . ' ' . $user['last_name'],
                'email' => $user['email'],
                'role' => $user['role']
            ]
        ];
    }
    
    /**
     * Logout user
     */
    public function logout() {
        if (isset($_SESSION['user_id'])) {
            Security::logAudit('LOGOUT', 'users', $_SESSION['user_id']);
        }
        
        // Clear remember me cookie
        if (isset($_COOKIE['remember_token'])) {
            $this->clearRememberMe($_COOKIE['remember_token']);
            setcookie('remember_token', '', time() - 3600, '/', '', true, true);
        }
        
        // Destroy session
        $_SESSION = array();
        
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
    }
    
    /**
     * Check if account is locked
     */
    private function isLocked($email) {
        $user = $this->db->getRow(
            "SELECT locked_until FROM users WHERE email = ?",
            [$email]
        );
        
        if ($user && $user['locked_until']) {
            return strtotime($user['locked_until']) > time();
        }
        
        return false;
    }
    
    /**
     * Log failed login attempt
     */
    private function logFailedAttempt($email) {
        $user = $this->db->getRow("SELECT id, login_attempts FROM users WHERE email = ?", [$email]);
        
        if ($user) {
            $attempts = $user['login_attempts'] + 1;
            
            if ($attempts >= $this->maxAttempts) {
                // Lock account
                $this->db->query(
                    "UPDATE users SET login_attempts = ?, locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?",
                    [$attempts, $this->lockoutTime, $user['id']]
                );
            } else {
                $this->db->query(
                    "UPDATE users SET login_attempts = ? WHERE id = ?",
                    [$attempts, $user['id']]
                );
            }
        }
    }
    
    /**
     * Reset login attempts
     */
    private function resetAttempts($email) {
        $this->db->query(
            "UPDATE users SET login_attempts = 0, locked_until = NULL WHERE email = ?",
            [$email]
        );
    }
    
    /**
     * Set remember me cookie
     */
    private function setRememberMe($userId) {
        $token = bin2hex(random_bytes(32));
        $hashedToken = password_hash($token, PASSWORD_DEFAULT);
        $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
        
        // Store token in database
        $this->db->query(
            "INSERT INTO user_tokens (user_id, token, expires_at) VALUES (?, ?, ?)",
            [$userId, $hashedToken, $expires]
        );
        
        // Set cookie
        setcookie('remember_token', $token, time() + (86400 * 30), '/', '', true, true);
    }
    
    /**
     * Clear remember me token
     */
    private function clearRememberMe($token) {
        $this->db->query("DELETE FROM user_tokens WHERE token = ?", [$token]);
    }
    
    /**
     * Check remember me cookie
     */
    public function checkRememberMe() {
        if (isset($_COOKIE['remember_token']) && !isset($_SESSION['user_id'])) {
            $token = $_COOKIE['remember_token'];
            
            $userToken = $this->db->getRow(
                "SELECT ut.*, u.id, u.first_name, u.last_name, u.email, u.role 
                 FROM user_tokens ut
                 JOIN users u ON ut.user_id = u.id
                 WHERE ut.expires_at > NOW()",
                []
            );
            
            if ($userToken && password_verify($token, $userToken['token'])) {
                $_SESSION['user_id'] = $userToken['user_id'];
                $_SESSION['user_name'] = $userToken['first_name'] . ' ' . $userToken['last_name'];
                $_SESSION['user_email'] = $userToken['email'];
                $_SESSION['user_role'] = $userToken['role'];
                $_SESSION['logged_in'] = true;
                
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if user has permission
     */
    public function hasPermission($userId, $permission) {
        $user = $this->db->getRow("SELECT role FROM users WHERE id = ?", [$userId]);
        
        if (!$user) {
            return false;
        }
        
        // Admin has all permissions
        if ($user['role'] === 'admin') {
            return true;
        }
        
        // Define role permissions
        $permissions = [
            'teacher' => [
                'view_students',
                'mark_attendance',
                'add_results',
                'view_own_classes',
                'upload_assignments',
                'view_own_schedule'
            ],
            'student' => [
                'view_own_results',
                'view_own_attendance',
                'submit_assignments',
                'view_announcements'
            ],
            'parent' => [
                'view_children',
                'view_fees',
                'communicate',
                'view_attendance'
            ]
        ];
        
        return in_array($permission, $permissions[$user['role']] ?? []);
    }
    
    /**
     * Get user by ID
     */
    public function getUser($userId) {
        return $this->db->getRow(
            "SELECT id, username, email, first_name, last_name, phone, role, profile_image, is_active 
             FROM users WHERE id = ? AND deleted_at IS NULL",
            [$userId]
        );
    }
    
    /**
     * Change password
     */
    public function changePassword($userId, $oldPassword, $newPassword) {
        $user = $this->db->getRow("SELECT password_hash FROM users WHERE id = ?", [$userId]);
        
        if (!$user || !Security::verifyPassword($oldPassword, $user['password_hash'])) {
            return ['success' => false, 'message' => 'Current password is incorrect'];
        }
        
        $newHash = Security::hashPassword($newPassword);
        
        $this->db->query(
            "UPDATE users SET password_hash = ? WHERE id = ?",
            [$newHash, $userId]
        );
        
        Security::logAudit('PASSWORD_CHANGE', 'users', $userId);
        
        return ['success' => true, 'message' => 'Password changed successfully'];
    }
    
    /**
     * Get active sessions for user
     */
    public function getActiveSessions($userId) {
        return $this->db->getRows(
            "SELECT * FROM user_sessions WHERE user_id = ? AND expires_at > NOW() ORDER BY last_activity DESC",
            [$userId]
        );
    }
    
    /**
     * Terminate session
     */
    public function terminateSession($sessionId, $userId) {
        $this->db->query(
            "DELETE FROM user_sessions WHERE id = ? AND user_id = ?",
            [$sessionId, $userId]
        );
    }
}