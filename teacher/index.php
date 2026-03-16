<?php
// teacher/index.php - Teacher Dashboard Redirect
require_once '../config/config.php';
require_once '../config/security.php';

// Redirect to dashboard if already logged in as teacher
if (Security::isLoggedIn() && $_SESSION['user_role'] === 'teacher') {
    header('Location: dashboard.php');
    exit;
}

// Otherwise redirect to main login page
header('Location: ' . BASE_URL . '/login.php');
exit;
?>