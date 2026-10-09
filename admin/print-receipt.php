<?php
// admin/print-receipt.php - printable receipt
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/receipt.php';
Security::requireRole('admin');
render_receipt_page($_GET['id'] ?? 0);
