<?php
// parent/messages.php - messaging
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/messaging.php';

Security::requireRole('parent');

$pageTitle = 'Messages';
$extraCSS = ['dashboard.css'];

include __DIR__ . '/../includes/header.php';
render_messages_page('parent');
include __DIR__ . '/../includes/footer.php';
