<?php
// logout.php
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/helpers.php'; // Add this
require_once 'config/security.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Log audit if user was logged in
if (isset($_SESSION['user_id'])) {
    // Check if Security class exists
    if (class_exists('Security')) {
        Security::logAudit('LOGOUT', 'users', $_SESSION['user_id']);
    }
}

// Clear all session variables
$_SESSION = array();

// Delete the session cookie if it exists
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session if it's active
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

// Redirect to login page
header('Location: ' . BASE_URL . '/login.php');
exit;
?>