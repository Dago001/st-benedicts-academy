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

        $user = null;
        try {
            $user = $this->db->getRow(
                'SELECT id, username, email, password_hash, first_name, last_name, role, is_active, totp_enabled, totp_secret
                 FROM users WHERE (email = ? OR username = ?) AND deleted_at IS NULL LIMIT 1',
                [$email, $email]
            );
        } catch (Throwable $e) {
            error_log("Auth::login users query error: " . $e->getMessage());
        }

        // If not found by email or username, check if input matches a student admission number
        if (!$user) {
            try {
                $st = $this->db->getRow(
                    'SELECT user_id FROM students WHERE admission_number = ? LIMIT 1',
                    [$email]
                );
                if ($st && !empty($st['user_id'])) {
                    $user = $this->db->getRow(
                        'SELECT id, username, email, password_hash, first_name, last_name, role, is_active, totp_enabled, totp_secret
                         FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1',
                        [(int)$st['user_id']]
                    );
                }
            } catch (Throwable $e) {
                // Ignore fallback error
            }
        }

        // Safely check must_change_password without throwing if column is not yet migrated
        if ($user) {
            $mustChange = 0;
            try {
                $chk = $this->db->getRow('SELECT must_change_password FROM users WHERE id = ?', [(int)$user['id']]);
                if ($chk && isset($chk['must_change_password'])) {
                    $mustChange = (int)$chk['must_change_password'];
                }
            } catch (Throwable $e) {
                $mustChange = 0;
            }
            $user['must_change_password'] = $mustChange;
        }

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
        $_SESSION['must_change_password'] = (int)($user['must_change_password'] ?? 0);
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
        $baseRoleDir = in_array($role, ['super_admin', 'admin', 'head_teacher', 'supervisor'], true) ? 'admin' : $role;
        $default = BASE_URL . '/' . $baseRoleDir . '/dashboard';
        if (!$target || !is_string($target) || $target[0] !== '/' || strpos($target, '//') === 0 || strpos($target, '\\') !== false) {
            return $default;
        }
        $base = parse_url(BASE_URL, PHP_URL_PATH) ?: '';
        if (strpos($target, $base . '/' . $baseRoleDir . '/') !== 0 && strpos($target, $base . '/' . $role . '/') !== 0) {
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
        if (in_array($user['role'], ['super_admin', 'admin'], true)) return true;

        $permissions = [
            'head_teacher' => ['view_students', 'mark_attendance', 'add_results', 'approve_results', 'view_all_classes', 'upload_assignments', 'view_schedules', 'manage_academics'],
            'supervisor'   => ['view_students', 'mark_attendance', 'view_all_classes', 'view_schedules', 'supervise_academics'],
            'teacher'      => ['view_students', 'mark_attendance', 'add_results', 'view_own_classes', 'upload_assignments', 'view_own_schedule'],
            'student'      => ['view_own_results', 'view_own_attendance', 'submit_assignments', 'view_own_schedule'],
            'parent'       => ['view_children_results', 'view_children_attendance', 'pay_fees', 'view_children_schedule'],
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
        try {
            $this->db->query('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [Security::hashPassword($newPassword), $userId]);
        } catch (Throwable $e) {
            $this->db->query('UPDATE users SET password_hash = ? WHERE id = ?', [Security::hashPassword($newPassword), $userId]);
        }
        if (isset($_SESSION['must_change_password'])) {
            $_SESSION['must_change_password'] = 0;
        }
        Security::logAudit('PASSWORD_CHANGE', 'users', $userId);
        return ['success' => true, 'message' => 'Password changed successfully'];
    }
}
