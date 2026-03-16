<?php
// parent/index.php - Parent Dashboard Redirect
require_once '../config/config.php';
require_once '../config/security.php';

if (Security::isLoggedIn() && $_SESSION['user_role'] === 'parent') {
    header('Location: dashboard.php');
    exit;
}

header('Location: ' . BASE_URL . '/login.php');
exit;
?>