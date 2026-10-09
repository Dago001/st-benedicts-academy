<?php
// teacher/profile.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/profile_page.php';
Security::requireRole('teacher');

$pageTitle = 'My Profile';
$extraCSS = ['dashboard.css'];
include __DIR__ . '/../includes/header.php';
render_profile_page('teacher');
include __DIR__ . '/../includes/footer.php';
