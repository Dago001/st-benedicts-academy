<?php
// includes/functions.php
// Helper functions

// Autoloader for classes
spl_autoload_register(function ($class) {
    $paths = [
        __DIR__ . '/../includes/',
        __DIR__ . '/../config/',
        __DIR__ . '/../'
    ];
    
    foreach ($paths as $path) {
        $file = $path . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// Redirect function
function redirect($url) {
    header("Location: " . BASE_URL . $url);
    exit();
}

// Get current URL
function currentUrl() {
    return (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
}

// Format date
function formatDate($date, $format = 'F j, Y') {
    return date($format, strtotime($date));
}

// Format currency
function formatCurrency($amount) {
    return '₦' . number_format($amount, 2);
}

// Generate random string
function generateRandomString($length = 10) {
    return bin2hex(random_bytes($length / 2));
}

// Generate admission number
function generateAdmissionNumber() {
    $year = date('Y');
    $random = str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    return "STB/{$year}/{$random}";
}

// Generate receipt number
function generateReceiptNumber() {
    return 'RCP-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

// Get user full name
function getUserName($userId) {
    $db = db();
    $user = $db->getRow("SELECT first_name, last_name FROM users WHERE id = ?", [$userId]);
    return $user ? $user['first_name'] . ' ' . $user['last_name'] : 'Unknown';
}

// Time ago function
function timeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return $diff . ' seconds ago';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 2592000) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $time);
    }
}

// Pagination function
function paginate($currentPage, $totalPages, $url) {
    $html = '<ul class="pagination">';
    
    // Previous button
    if ($currentPage > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $url . '?page=' . ($currentPage - 1) . '">Previous</a></li>';
    }
    
    // Page numbers
    for ($i = 1; $i <= $totalPages; $i++) {
        if ($i == $currentPage) {
            $html .= '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
        } else {
            $html .= '<li class="page-item"><a class="page-link" href="' . $url . '?page=' . $i . '">' . $i . '</a></li>';
        }
    }
    
    // Next button
    if ($currentPage < $totalPages) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $url . '?page=' . ($currentPage + 1) . '">Next</a></li>';
    }
    
    $html .= '</ul>';
    return $html;
}

// Send email notification
function sendEmail($to, $subject, $message) {
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: " . SCHOOL_NAME . " <" . SCHOOL_EMAIL . ">\r\n";
    
    return mail($to, $subject, $message, $headers);
}

// Get dashboard statistics
function getDashboardStats() {
    $db = db();
    
    return [
        'total_students' => $db->getRow("SELECT COUNT(*) as count FROM students WHERE deleted_at IS NULL")['count'],
        'total_teachers' => $db->getRow("SELECT COUNT(*) as count FROM teachers WHERE user_id IN (SELECT id FROM users WHERE is_active = 1)")['count'],
        'total_classes' => $db->getRow("SELECT COUNT(*) as count FROM classes WHERE is_active = 1")['count'],
        'pending_fees' => $db->getRow("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'pending'")['total']
    ];
}