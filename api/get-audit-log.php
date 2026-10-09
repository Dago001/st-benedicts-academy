<?php
// api/get-audit-log.php - single audit log entry for the admin viewer
require_once __DIR__ . '/../includes/api.php';

api_init(['GET'], 'admin');
$id = api_int($_GET['id'] ?? null);
if (!$id) api_error('Log ID required');

$log = db()->getRow(
    "SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) AS user_name
     FROM audit_logs a LEFT JOIN users u ON a.user_id = u.id WHERE a.id = ?",
    [$id]
);
if (!$log) api_error('Log not found', 404);
api_ok(['log' => $log]);
