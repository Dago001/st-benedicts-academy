<?php
// includes/auth.php - Authentication (single login path for web form and API)

require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/totp.php';

class Auth {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Authenticate a user and start a session. Returns ['success'=>bool, ...].
     * Failed attempts are counted per account; the account locks after
     * MAX_LOGIN_ATTEMPTS failures. The error text is identical for unknown
     * accounts and wrong passwords.
     */
    public function login($email, $password) {
        $email = trim((string)$email);
        if ($email === '' || (string)$password === '') {
            return ['success' => false, 'message' => 'Please enter both email and password'];
        }

        if (Security::ipThrottled()) {
            return ['success' => false, 'message' => 'Too many failed sign-in attempts from your network. Please try again in 15 minutes.'];
        }
        if (!Security::checkLoginAttempts($email)) {
            return ['success' => false, 'message' => 'Account temporarily locked. Please try again after 15 minutes.'];
        }

        $user = $this->db->getRow(
            'SELECT id, username, email, password_hash, first_name, last_name, role, is_active, totp_enabled, totp_secret
             FROM users WHERE email = ? AND deleted_at IS NULL',
            [$email]
        );

        // Always run a hash comparison to keep timing similar for unknown users
        $hash = $user['password_hash'] ?? '$2y$12$abcdefghijklmnopqrstuuJ0v9m3c7s0QyZ1Zp0rQmWQe1S9pYk2K';
        $ok = Security::verifyPassword($password, $hash) && $user;

        if (!$ok) {
            Security::logFailedAttempt($email);
            return ['success' => false, 'message' => 'Invalid email or password'];
        }
        if (!$user['is_active']) {
            return ['success' => false, 'message' => 'Your account has been deactivated. Please contact administration.'];
        }

        // Upgrade hashes created with an older cost
        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => BCRYPT_COST])) {
            $this->db->query('UPDATE users SET password_hash = ? WHERE id = ?', [Security::hashPassword($password), $user['id']]);
        }

        if (!empty($user['totp_enabled']) && !empty($user['totp_secret'])) {
            // Password is right; hold the session until the authenticator code is verified
            $redirect = $_SESSION['redirect_after_login'] ?? null;
            $_SESSION = ['pending_2fa' => ['user_id' => (int)$user['id'], 'expires' => time() + 300, 'tries' => 0, 'redirect' => $redirect]];
            session_regenerate_id(true);
            Security::generateCSRFToken();
            return ['success' => false, 'needs_2fa' => true, 'message' => 'Enter the 6-digit code from your authenticator app.'];
        }

        return $this->startSession($user);
    }

    /** Second step of a 2FA login. */
    public function completeTwoFactor($code) {
        $p = $_SESSION['pending_2fa'] ?? null;
        if (!$p || $p['expires'] < time()) {
            unset($_SESSION['pending_2fa']);
            return ['success' => false, 'expired' => true, 'message' => 'Your sign-in session expired. Please sign in again.'];
        }
        if (Security::ipThrottled()) {
            return ['success' => false, 'needs_2fa' => true, 'message' => 'Too many failed attempts. Please try again later.'];
        }
        $user = $this->db->getRow(
            'SELECT id, username, email, first_name, last_name, role, is_active, totp_enabled, totp_secret
             FROM users WHERE id = ? AND deleted_at IS NULL', [$p['user_id']]);
        if (!$user || !$user['is_active'] || empty($user['totp_secret'])) {
            unset($_SESSION['pending_2fa']);
            return ['success' => false, 'expired' => true, 'message' => 'Sign-in failed. Please try again.'];
        }
        if (!Totp::verify($user['totp_secret'], $code)) {
            Security::logIpFailure();
            $_SESSION['pending_2fa']['tries']++;
            if ($_SESSION['pending_2fa']['tries'] >= 5) {
                unset($_SESSION['pending_2fa']);
                return ['success' => false, 'expired' => true, 'message' => 'Too many incorrect codes. Please sign in again.'];
            }
            Security::logAudit('LOGIN_2FA_FAILED', 'users', $user['id']);
            return ['success' => false, 'needs_2fa' => true, 'message' => 'That code is not correct. Please try again.'];
        }
        $_SESSION['redirect_after_login'] = $p['redirect'];
        return $this->startSession($user);
    }

    private function startSession($user) {
        $redirect = $_SESSION['redirect_after_login'] ?? ($_SESSION['pending_2fa']['redirect'] ?? null);
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        Security::generateCSRFToken();

        $this->db->query(
            'UPDATE users SET last_login = NOW(), login_attempts = 0, locked_until = NULL WHERE id = ?',
            [$user['id']]
        );
        Security::logAudit('LOGIN_SUCCESS', 'users', $user['id']);

        return [
            'success' => true,
            'role' => $user['role'],
            'redirect' => $this->safeRedirect($redirect, $user['role']),
            'user' => [
                'id' => (int)$user['id'],
                'name' => $_SESSION['user_name'],
                'email' => $user['email'],
                'role' => $user['role'],
            ],
        ];
    }

    /** Only same-site paths are honoured as post-login redirects. */
    private function safeRedirect($target, $role) {
        $default = BASE_URL . '/' . $role . '/dashboard';
        if (!$target || !is_string($target) || $target[0] !== '/' || strpos($target, '//') === 0 || strpos($target, '\\') !== false) {
            return $default;
        }
        $base = parse_url(BASE_URL, PHP_URL_PATH) ?: '';
        if (strpos($target, $base . '/' . $role . '/') !== 0) {
            return $default;
        }
        return (parse_url(BASE_URL, PHP_URL_SCHEME) . '://' . parse_url(BASE_URL, PHP_URL_HOST)
            . (parse_url(BASE_URL, PHP_URL_PORT) ? ':' . parse_url(BASE_URL, PHP_URL_PORT) : '')) . $target;
    }

    public function logout() {
        if (isset($_SESSION['user_id'])) {
            Security::logAudit('LOGOUT', 'users', $_SESSION['user_id']);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function hasPermission($userId, $permission) {
        $user = $this->db->getRow('SELECT role FROM users WHERE id = ?', [$userId]);
        if (!$user) return false;
        if ($user['role'] === 'admin') return true;

        $permissions = [
            'teacher' => ['view_students', 'mark_attendance', 'add_results', 'view_own_classes', 'upload_assignments', 'view_own_schedule'],
            'student' => ['view_own_results', 'view_own_attendance', 'submit_assignments', 'view_announcements'],
            'parent'  => ['view_children', 'view_fees', 'communicate', 'view_attendance'],
        ];
        return in_array($permission, $permissions[$user['role']] ?? [], true);
    }

    public function getUser($userId) {
        return $this->db->getRow(
            'SELECT id, username, email, first_name, last_name, phone, role, profile_image, is_active
             FROM users WHERE id = ? AND deleted_at IS NULL',
            [$userId]
        );
    }

    public function changePassword($userId, $oldPassword, $newPassword) {
        $user = $this->db->getRow('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        if (!$user || !Security::verifyPassword($oldPassword, $user['password_hash'])) {
            return ['success' => false, 'message' => 'Current password is incorrect'];
        }
        if (strlen($newPassword) < 8) {
            return ['success' => false, 'message' => 'New password must be at least 8 characters'];
        }
        $this->db->query('UPDATE users SET password_hash = ? WHERE id = ?', [Security::hashPassword($newPassword), $userId]);
        Security::logAudit('PASSWORD_CHANGE', 'users', $userId);
        return ['success' => true, 'message' => 'Password changed successfully'];
    }
}
