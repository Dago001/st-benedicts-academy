<?php
// health.php - uptime-monitor endpoint (UptimeRobot, BetterStack, etc). Exposes no internals.
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
$checks = ['database' => false, 'storage' => is_writable(PRIVATE_PATH) && is_writable(UPLOAD_PATH), 'logs' => is_writable(LOG_PATH)];
try {
    $checks['database'] = (bool)Database::getInstance()->getConnection()->query('SELECT 1')->fetchColumn();
} catch (Throwable $e) { /* leave false */ }
$ok = !in_array(false, $checks, true);
http_response_code($ok ? 200 : 503);
echo json_encode(['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks, 'time' => date('c')]);
