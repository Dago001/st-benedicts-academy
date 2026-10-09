<?php
// includes/helpers.php
// Shared helper functions. Every function is guarded so pages may still
// define their own variants.

if (!function_exists('db')) {
    function db() { return Database::getInstance(); }
}

if (!function_exists('redirect')) {
    /** Redirect to an absolute URL or a path relative to BASE_URL. */
    function redirect($url) {
        if (!preg_match('#^https?://#i', $url)) {
            $url = BASE_URL . '/' . ltrim($url, '/');
        }
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('currentUrl')) {
    function currentUrl() {
        return (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/');
    }
}

if (!function_exists('formatDate')) {
    function formatDate($date, $format = 'M d, Y') {
        if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') return 'N/A';
        $ts = strtotime($date);
        return $ts ? date($format, $ts) : 'N/A';
    }
}

if (!function_exists('formatTime')) {
    function formatTime($date) {
        if (empty($date)) return '';
        $ts = strtotime($date);
        return $ts ? date('h:i A', $ts) : '';
    }
}

if (!function_exists('formatCurrency')) {
    function formatCurrency($amount) {
        return '₦' . number_format((float)$amount, 2);
    }
}

if (!function_exists('generateRandomString')) {
    function generateRandomString($length = 10) {
        $chars = '0123456789abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $out;
    }
}

if (!function_exists('uniqueNumber')) {
    /**
     * Generate a unique reference such as RCP-20260101-0042 that does not yet
     * exist in $table.$column.
     */
    function uniqueNumber($prefix, $table, $column, $digits = 4) {
        $db = db();
        $allowed = ['payments' => 'receipt_number', 'admissions' => 'application_number', 'students' => 'admission_number'];
        if (($allowed[$table] ?? null) !== $column) {
            throw new InvalidArgumentException('Unsupported table/column');
        }
        for ($i = 0; $i < 20; $i++) {
            $candidate = $prefix . str_pad((string)random_int(1, (int)str_repeat('9', $digits)), $digits, '0', STR_PAD_LEFT);
            if (!$db->getRow("SELECT 1 FROM `$table` WHERE `$column` = ?", [$candidate])) {
                return $candidate;
            }
        }
        return $prefix . bin2hex(random_bytes(4));
    }
}

if (!function_exists('generateReceiptNumber')) {
    function generateReceiptNumber() { return uniqueNumber('RCP-' . date('Ymd') . '-', 'payments', 'receipt_number'); }
}
if (!function_exists('generateAdmissionNumber')) {
    function generateAdmissionNumber() { return uniqueNumber('STB/' . date('Y') . '/', 'students', 'admission_number'); }
}
if (!function_exists('generateApplicationNumber')) {
    function generateApplicationNumber() { return uniqueNumber('APP-' . date('Y') . '-', 'admissions', 'application_number'); }
}

if (!function_exists('getUserName')) {
    function getUserName($userId) {
        $user = db()->getRow('SELECT first_name, last_name FROM users WHERE id = ?', [$userId]);
        return $user ? $user['first_name'] . ' ' . $user['last_name'] : 'Unknown';
    }
}

if (!function_exists('timeAgo')) {
    function timeAgo($datetime) {
        if (!$datetime) return 'N/A';
        $time = strtotime($datetime);
        $diff = time() - $time;
        if ($diff < 60) return max(0, $diff) . ' seconds ago';
        if ($diff < 3600) { $m = floor($diff / 60); return $m . ' minute' . ($m > 1 ? 's' : '') . ' ago'; }
        if ($diff < 86400) { $h = floor($diff / 3600); return $h . ' hour' . ($h > 1 ? 's' : '') . ' ago'; }
        if ($diff < 2592000) { $d = floor($diff / 86400); return $d . ' day' . ($d > 1 ? 's' : '') . ' ago'; }
        return date('M j, Y', $time);
    }
}

if (!function_exists('paginate')) {
    function paginate($currentPage, $totalPages, $url) {
        $sep = strpos($url, '?') === false ? '?' : '&';
        $html = '<ul class="pagination">';
        if ($currentPage > 1) {
            $html .= '<li class="page-item"><a class="page-link" href="' . e($url . $sep . 'page=' . ($currentPage - 1)) . '">Previous</a></li>';
        }
        for ($i = 1; $i <= $totalPages; $i++) {
            $html .= $i == $currentPage
                ? '<li class="page-item active"><span class="page-link">' . $i . '</span></li>'
                : '<li class="page-item"><a class="page-link" href="' . e($url . $sep . 'page=' . $i) . '">' . $i . '</a></li>';
        }
        if ($currentPage < $totalPages) {
            $html .= '<li class="page-item"><a class="page-link" href="' . e($url . $sep . 'page=' . ($currentPage + 1)) . '">Next</a></li>';
        }
        return $html . '</ul>';
    }
}

if (!function_exists('sendEmail')) {
    function sendEmail($to, $subject, $message) {
        require_once __DIR__ . '/mailer.php';
        return Mailer::send($to, $subject, $message);
}
}

if (!function_exists('letterGrade')) {
    /** Single grading scale used everywhere (percentage -> letter). */
    function letterGrade($score, $max = 100) {
        $max = (float)$max;
        if ($max <= 0) return 'F';
        $p = ((float)$score / $max) * 100;
        if ($p >= 70) return 'A';
        if ($p >= 60) return 'B';
        if ($p >= 50) return 'C';
        if ($p >= 45) return 'D';
        if ($p >= 40) return 'E';
        return 'F';
    }
}

if (!function_exists('json_response')) {
    function json_response($data, $status = 200) {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}

if (!function_exists('getDashboardStats')) {
    function getDashboardStats() {
        $db = db();
        return [
            'total_students' => $db->getRow('SELECT COUNT(*) c FROM students s JOIN users u ON s.user_id = u.id WHERE u.deleted_at IS NULL')['c'],
            'total_teachers' => $db->getRow('SELECT COUNT(*) c FROM teachers t JOIN users u ON t.user_id = u.id WHERE u.is_active = 1 AND u.deleted_at IS NULL')['c'],
            'total_classes'  => $db->getRow('SELECT COUNT(*) c FROM classes WHERE is_active = 1')['c'],
            'pending_fees'   => $db->getRow("SELECT COALESCE(SUM(amount), 0) c FROM payments WHERE status = 'pending'")['c'],
        ];
    }
}


// ---- Flash messages & Post/Redirect/Get ----------------------------------
if (!function_exists('flash_set')) {
    function flash_set($message, $type = 'success') {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    }
}
if (!function_exists('flash_get')) {
    /** Returns [message, type] and clears it. */
    function flash_get() {
        $f = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $f ? [$f['message'], $f['type']] : ['', ''];
    }
}
if (!function_exists('flash_redirect')) {
    /** Store a message and redirect (default: same page, GET) so a refresh does not resubmit the form. */
    function flash_redirect($message, $type = 'success', $url = null) {
        if ($message !== '') flash_set($message, $type);
        if ($url === null) {
            $url = strtok($_SERVER['REQUEST_URI'], '#');
        }
        header('Location: ' . $url);
        exit;
    }
}

// ---- Validation helpers ------------------------------------------------------
if (!function_exists('valid_date')) {
    /** Returns the Y-m-d string if valid, otherwise null. */
    function valid_date($value) {
        if (!is_string($value) || $value === '') return null;
        $d = DateTime::createFromFormat('Y-m-d', $value);
        return ($d && $d->format('Y-m-d') === $value) ? $value : null;
    }
}
if (!function_exists('valid_datetime')) {
    /** Accepts Y-m-d, Y-m-d H:i[:s] and the HTML datetime-local format; returns 'Y-m-d H:i:s' or null. */
    function valid_datetime($value) {
        if (!is_string($value) || $value === '') return null;
        $ts = strtotime(str_replace('T', ' ', $value));
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
}
if (!function_exists('valid_username')) {
    function valid_username($u) { return (bool)preg_match('/^[A-Za-z0-9._-]{3,50}$/', (string)$u); }
}
if (!function_exists('strong_password')) {
    /** Returns an error string or null when the password is acceptable. */
    function strong_password($pw) {
        if (strlen($pw) < 8) return 'Password must be at least 8 characters';
        if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) return 'Password must contain letters and numbers';
        return null;
    }
}
if (!function_exists('null_if_empty')) {
    function null_if_empty($v) { return ($v === '' || $v === null) ? null : $v; }
}
if (!function_exists('page_param')) {
    /** Current page number (>= 1) from $_GET[$key]. */
    function page_param($key = 'p') {
        return max(1, (int)($_GET[$key] ?? 1));
    }
}

if (!function_exists('currentAcademicYear')) {
    /**
     * The academic year in use: the latest year among active classes, falling
     * back to the calendar (September - August) when no classes exist.
     */
    function currentAcademicYear() {
        static $year = null;
        if ($year !== null) return $year;
        try {
            $row = db()->getRow("SELECT MAX(academic_year) AS y FROM classes WHERE is_active = 1");
            if (!empty($row['y'])) return $year = $row['y'];
        } catch (Throwable $e) {
            error_log('currentAcademicYear: ' . $e->getMessage());
        }
        $y = (int)date('Y');
        return $year = ((int)date('n') >= 9) ? $y . '-' . ($y + 1) : ($y - 1) . '-' . $y;
    }
}

if (!function_exists('academicYearList')) {
    /** Distinct years known to the system (classes, fees, results), newest first. */
    function academicYearList() {
        $rows = db()->getRows("SELECT academic_year FROM classes UNION SELECT academic_year FROM fee_structure UNION SELECT academic_year FROM results ORDER BY academic_year DESC");
        $years = array_column($rows, 'academic_year');
        if (!in_array(currentAcademicYear(), $years, true)) array_unshift($years, currentAcademicYear());
        return $years;
    }
}

if (!function_exists('save_attendance')) {
    /**
     * Upsert attendance for students of one class on one date.
     * $attendance: [student_id => status], $remarks: [student_id => text].
     * Students outside the class and invalid statuses are skipped.
     * Returns the number of rows written; throws on database failure.
     */
    function save_attendance($classId, $date, array $attendance, array $remarks, $userId) {
        $db = db();
        $classId = (int)$classId;
        $valid = ['present', 'absent', 'late', 'excused'];
        $ids = array_values(array_unique(array_map('intval', array_keys($attendance))));
        if (!$ids) return 0;
        $in = implode(',', array_fill(0, count($ids), '?'));
        $allowed = array_map('intval', array_column(
            $db->getRows("SELECT id FROM students WHERE class_id = ? AND id IN ($in)", array_merge([$classId], $ids)), 'id'));

        $count = 0;
        $db->beginTransaction();
        try {
            foreach ($attendance as $studentId => $status) {
                $studentId = (int)$studentId;
                if (!in_array($studentId, $allowed, true) || !in_array($status, $valid, true)) continue;
                $remark = mb_substr(Security::sanitize($remarks[$studentId] ?? ''), 0, 500);
                $db->query(
                    "INSERT INTO attendance (student_id, class_id, date, status, remarks, marked_by) VALUES (?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE status = VALUES(status), remarks = VALUES(remarks), class_id = VALUES(class_id), marked_by = VALUES(marked_by)",
                    [$studentId, $classId, $date, $status, $remark, $userId]
                );
                $count++;
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
        return $count;
    }
}
