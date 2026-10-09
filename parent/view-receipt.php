<?php
// parent/view-receipt.php - printable receipt
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/receipt.php';
Security::requireRole('parent');
render_receipt_page($_GET['id'] ?? 0);
