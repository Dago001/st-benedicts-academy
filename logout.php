<?php
// logout.php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/includes/auth.php';

(new Auth())->logout();

header('Location: ' . BASE_URL . '/login');
exit;
