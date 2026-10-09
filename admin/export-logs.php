<?php
// admin/export-logs.php - CSV export of audit logs (honours the page's filters)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
Security::requireRole('admin');

$where = ['1=1'];
$params = [];
if (($uid = (int)($_GET['user_id'] ?? 0)) > 0) { $where[] = 'a.user_id = ?'; $params[] = $uid; }
if (($act = Security::sanitize($_GET['action'] ?? '')) !== '') { $where[] = 'a.action LIKE ?'; $params[] = '%' . $act . '%'; }
if ($from = valid_date($_GET['date_from'] ?? '')) { $where[] = 'DATE(a.created_at) >= ?'; $params[] = $from; }
if ($to = valid_date($_GET['date_to'] ?? '')) { $where[] = 'DATE(a.created_at) <= ?'; $params[] = $to; }

$rows = db()->getRows(
    "SELECT a.id, a.created_at, CONCAT(u.first_name, ' ', u.last_name) AS user_name, u.role, a.action,
            a.table_affected, a.record_id, a.ip_address
     FROM audit_logs a LEFT JOIN users u ON a.user_id = u.id
     WHERE " . implode(' AND ', $where) . " ORDER BY a.created_at DESC LIMIT 20000",
    $params
);

while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="audit-logs-' . date('Ymd-His') . '.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, ['ID', 'Date/Time', 'User', 'Role', 'Action', 'Table', 'Record ID', 'IP']);
foreach ($rows as $r) {
    // Neutralise spreadsheet formula injection
    $r = array_map(function ($v) { return (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v; }, $r);
    fputcsv($out, $r);
}
Security::logAudit('EXPORTED_AUDIT_LOGS', 'audit_logs');
exit;
