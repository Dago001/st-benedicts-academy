<?php
// config/security.php
// Security functions and access control helpers

require_once __DIR__ . '/database.php';

if (!defined('CSRF_TOKEN_NAME')) define('CSRF_TOKEN_NAME', 'csrf_token');
if (!defined('MAX_LOGIN_ATTEMPTS')) define('MAX_LOGIN_ATTEMPTS', 5);
if (!defined('LOCKOUT_TIME')) define('LOCKOUT_TIME', 900);
if (!defined('MAX_FILE_SIZE')) define('MAX_FILE_SIZE', 5242880);
if (!defined('ALLOWED_EXTENSIONS')) define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx']);
if (!defined('BCRYPT_COST')) define('BCRYPT_COST', 12);

if (!function_exists('db')) {
    function db() {
        return Database::getInstance();
    }
}

/** HTML-escape a value for output. */
if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

/** Hidden CSRF input for forms. */
if (!function_exists('csrf_field')) {
    function csrf_field() {
        return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . e(Security::generateCSRFToken()) . '">';
    }
}

class Security {

    public static function generateCSRFToken() {
        if (empty($_SESSION[CSRF_TOKEN_NAME])) {
            $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
        }
        return $_SESSION[CSRF_TOKEN_NAME];
    }

    public static function verifyCSRFToken($token) {
        if (!is_string($token) || empty($_SESSION[CSRF_TOKEN_NAME]) || !hash_equals($_SESSION[CSRF_TOKEN_NAME], $token)) {
            error_log('CSRF token validation failed');
            return false;
        }
        return true;
    }

    /** Token from form field, JSON body or X-CSRF-Token header. */
    public static function requestCSRFToken($jsonBody = null) {
        return $_POST[CSRF_TOKEN_NAME]
            ?? ($jsonBody[CSRF_TOKEN_NAME] ?? null)
            ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    }

    /**
     * Clean user input. Values are trimmed and stripped of control characters;
     * they are escaped on output (use e()), never on input, so data is not
     * double-encoded in the database.
     */
    public static function sanitize($input) {
        if (is_array($input)) {
            return array_map([self::class, 'sanitize'], $input);
        }
        if ($input === null) {
            return '';
        }
        $input = trim((string)$input);
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $input);
    }

    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    // Nigerian mobile format, with or without +234
    public static function validatePhone($phone) {
        $phone = preg_replace('/[\s\-]/', '', (string)$phone);
        return (bool)preg_match('/^(0|\+?234)[789][01]\d{8}$/', $phone);
    }

    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    }

    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }

    public static function isLoggedIn() {
        return isset($_SESSION['user_id']) && isset($_SESSION['user_role']);
    }

    public static function currentRole() {
        return self::isLoggedIn() ? $_SESSION['user_role'] : null;
    }

    /** $role can be a single role or an array of roles. */
    public static function hasRole($role) {
        if (!self::isLoggedIn()) {
            return false;
        }
        return in_array($_SESSION['user_role'], (array)$role, true);
    }

    public static function wantsJson() {
        return (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false)
            || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
            || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);
    }

    public static function requireLogin() {
        if (!self::isLoggedIn()) {
            if (self::wantsJson()) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Authentication required']);
                exit;
            }
            header('Location: ' . BASE_URL . '/login');
            exit;
        }
    }

    public static function requireRole($role) {
        self::requireLogin();
        if (!self::hasRole($role)) {
            http_response_code(403);
            if (self::wantsJson()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }
            die('Access Denied: Insufficient permissions');
        }
    }

    // ---- Login throttling -------------------------------------------------

    public static function checkLoginAttempts($email) {
        $db = self::getDB();
        $user = $db->getRow('SELECT id, login_attempts, locked_until FROM users WHERE email = ?', [$email]);

        if ($user) {
            if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                return false;
            }
            if ($user['locked_until'] && strtotime($user['locked_until']) <= time()) {
                // Lock expired: start fresh
                self::resetLoginAttempts($user['id']);
                return true;
            }
            if ($user['login_attempts'] >= MAX_LOGIN_ATTEMPTS) {
                $db->query(
                    'UPDATE users SET locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?',
                    [LOCKOUT_TIME, $user['id']]
                );
                return false;
            }
        }
        return true;
    }

    // Per-IP throttle: stops one client from guessing across many accounts
    const IP_MAX_FAILURES = 20;
    const IP_WINDOW_SECONDS = 900;

    public static function ipHash() {
        return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (getenv('APP_KEY') ?: ROOT_PATH));
    }

    public static function ipThrottled() {
        try {
            $row = self::getDB()->getRow(
                'SELECT COUNT(*) AS c FROM login_throttle WHERE ip_hash = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)',
                [self::ipHash(), self::IP_WINDOW_SECONDS]
            );
            $n = (int)($row['c'] ?? 0);
            return $n >= self::IP_MAX_FAILURES;
        } catch (Throwable $e) {
            return false; // table missing before migration: fail open, account lockout still applies
        }
    }

    public static function logIpFailure() {
        try {
            self::getDB()->query('INSERT INTO login_throttle (ip_hash) VALUES (?)', [self::ipHash()]);
        } catch (Throwable $e) { /* see ipThrottled() */ }
    }

    public static function logFailedAttempt($email) {
        self::logIpFailure();
        self::getDB()->query('UPDATE users SET login_attempts = login_attempts + 1 WHERE email = ?', [$email]);
    }

    public static function resetLoginAttempts($userId) {
        self::getDB()->query('UPDATE users SET login_attempts = 0, locked_until = NULL WHERE id = ?', [$userId]);
    }

    // ---- Uploads ------------------------------------------------------------

    private static $mimeByExt = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword', 'application/octet-stream', 'application/x-ole-storage', 'application/CDFV2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
    ];

    /**
     * Validate an uploaded file by size, extension and real MIME type.
     * Optional $allowed restricts the extensions further.
     */
    public static function validateFileUpload($file, $allowed = null) {
        if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) {
            return ['valid' => false, 'message' => 'Invalid upload'];
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'message' => 'Upload failed'];
        }
        if ($file['size'] > MAX_FILE_SIZE) {
            return ['valid' => false, 'message' => 'File too large (max 5MB)'];
        }
        if (!is_uploaded_file($file['tmp_name']) && !(defined('TESTING') && TESTING)) {
            return ['valid' => false, 'message' => 'Invalid upload'];
        }

        $allowedExt = $allowed ?: ALLOWED_EXTENSIONS;
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExt, true)) {
            return ['valid' => false, 'message' => 'File type not allowed'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!in_array($mime, self::$mimeByExt[$extension] ?? [], true)) {
            return ['valid' => false, 'message' => 'File content does not match its type'];
        }
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'], true) && @getimagesize($file['tmp_name']) === false) {
            return ['valid' => false, 'message' => 'Invalid image file'];
        }

        return ['valid' => true, 'extension' => $extension, 'mime' => $mime];
    }

    /** Random file name that keeps only a whitelisted extension. */
    public static function generateSecureFilename($originalName, $prefix = '') {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ALLOWED_EXTENSIONS, true)) {
            $extension = 'bin';
        }
        return $prefix . bin2hex(random_bytes(16)) . '.' . $extension;
    }

    /** Create a thumbnail with GD; falls back to copying the file. */
    public static function createThumbnail($src, $dest, $maxWidth = 400) {
        $info = @getimagesize($src);
        if (!$info || !function_exists('imagecreatetruecolor')) {
            return @copy($src, $dest);
        }
        [$w, $h, $type] = $info;
        $create = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_GIF => 'imagecreatefromgif'][$type] ?? null;
        $save = [IMAGETYPE_JPEG => 'imagejpeg', IMAGETYPE_PNG => 'imagepng', IMAGETYPE_GIF => 'imagegif'][$type] ?? null;
        if (!$create || !function_exists($create) || $w <= 0) {
            return @copy($src, $dest);
        }
        $ratio = min(1, $maxWidth / $w);
        $nw = max(1, (int)round($w * $ratio));
        $nh = max(1, (int)round($h * $ratio));
        $img = @$create($src);
        if (!$img) {
            return @copy($src, $dest);
        }
        $thumb = imagecreatetruecolor($nw, $nh);
        if ($type !== IMAGETYPE_JPEG) {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
        }
        imagecopyresampled($thumb, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $ok = $save($thumb, $dest);
        imagedestroy($img);
        imagedestroy($thumb);
        return $ok;
    }

    private static function getDB() {
        return Database::getInstance();
    }

    // ---- Ownership helpers ----------------------------------------------

    /** students.id for the logged-in student, or null. */
    public static function currentStudentId() {
        if (!self::hasRole('student')) return null;
        $r = self::getDB()->getRow('SELECT id FROM students WHERE user_id = ?', [$_SESSION['user_id']]);
        return $r ? (int)$r['id'] : null;
    }

    /** teachers.id for the logged-in teacher, or null. */
    public static function currentTeacherId() {
        if (!self::hasRole('teacher')) return null;
        $r = self::getDB()->getRow('SELECT id FROM teachers WHERE user_id = ?', [$_SESSION['user_id']]);
        return $r ? (int)$r['id'] : null;
    }

    /** parents.id for the logged-in parent, or null. */
    public static function currentParentId() {
        if (!self::hasRole('parent')) return null;
        $r = self::getDB()->getRow('SELECT id FROM parents WHERE user_id = ?', [$_SESSION['user_id']]);
        return $r ? (int)$r['id'] : null;
    }

    /**
     * May the current user see data of this student?
     * admin: always. student: self. parent: own child. teacher: pupils in a
     * class they teach or are form teacher of.
     */
    public static function canAccessStudent($studentId) {
        $studentId = (int)$studentId;
        if ($studentId <= 0 || !self::isLoggedIn()) return false;
        $db = self::getDB();
        switch ($_SESSION['user_role']) {
            case 'admin':
                return true;
            case 'student':
                return self::currentStudentId() === $studentId;
            case 'parent':
                return (bool)$db->getRow('SELECT id FROM students WHERE id = ? AND parent_id = ?', [$studentId, self::currentParentId() ?? 0]);
            case 'teacher':
                return (bool)$db->getRow(
                    'SELECT s.id FROM students s WHERE s.id = ? AND s.class_id IN (' . self::teacherClassSql() . ')',
                    [$studentId, self::currentTeacherId() ?? 0, self::currentTeacherId() ?? 0]
                );
        }
        return false;
    }

    /** May the current user see/manage this class? (admin, its teachers; students/parents of the class for read) */
    public static function canAccessClass($classId, $forWrite = false) {
        $classId = (int)$classId;
        if ($classId <= 0 || !self::isLoggedIn()) return false;
        $db = self::getDB();
        switch ($_SESSION['user_role']) {
            case 'admin':
                return true;
            case 'teacher':
                $tid = self::currentTeacherId() ?? 0;
                return (bool)$db->getRow(
                    'SELECT c.id FROM classes c WHERE c.id = ? AND c.id IN (' . self::teacherClassSql() . ')',
                    [$classId, $tid, $tid]
                );
            case 'student':
                if ($forWrite) return false;
                return (bool)$db->getRow('SELECT id FROM students WHERE id = ? AND class_id = ?', [self::currentStudentId() ?? 0, $classId]);
            case 'parent':
                if ($forWrite) return false;
                return (bool)$db->getRow('SELECT id FROM students WHERE class_id = ? AND parent_id = ? LIMIT 1', [$classId, self::currentParentId() ?? 0]);
        }
        return false;
    }

    /** Classes a teacher is form teacher of or teaches a subject in. Needs two teacher id params. */
    private static function teacherClassSql() {
        return 'SELECT id FROM classes WHERE teacher_id = ? UNION SELECT class_id FROM subjects WHERE teacher_id = ?';
    }

    // ---- Audit ------------------------------------------------------------

    public static function logAudit($action, $table = null, $recordId = null, $oldValues = null, $newValues = null) {
        if (!isset($_SESSION['user_id'])) {
            return;
        }
        try {
            self::getDB()->insert(
                'INSERT INTO audit_logs (user_id, action, table_affected, record_id, old_values, new_values, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $_SESSION['user_id'],
                    $action,
                    $table,
                    $recordId,
                    $oldValues ? json_encode($oldValues) : null,
                    $newValues ? json_encode($newValues) : null,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
                ]
            );
        } catch (Exception $e) {
            error_log('Audit log error: ' . $e->getMessage());
        }
    }
}

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/cms.php';
require_once dirname(__DIR__) . '/includes/layout.php';
