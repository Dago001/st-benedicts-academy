<?php
// student/index.php - Student Dashboard Redirect
require_once '../config/config.php';
require_once '../config/security.php';

if (Security::isLoggedIn() && $_SESSION['user_role'] === 'student') {
    header('Location: dashboard');
    exit;
}

header('Location: ' . BASE_URL . '/login');
exit;
?>