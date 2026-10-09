<?php
// scripts/retention.php - purge data past its retention period (see public/privacy).
// Run daily from cron:  php scripts/retention.php   (add --dry-run to only count)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/database.php';

$dry = in_array('--dry-run', $argv, true);
$pdo = Database::getInstance()->getConnection();
$rules = [
    ['chatbot_logs',   'created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)'],
    ['audit_logs',     'created_at < DATE_SUB(NOW(), INTERVAL 24 MONTH)'],
    ['password_resets', '(expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY) OR used_at IS NOT NULL AND used_at < DATE_SUB(NOW(), INTERVAL 7 DAY))'],
    ['login_throttle', 'created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)'],
    ['applications',   "status = 'rejected' AND created_at < DATE_SUB(NOW(), INTERVAL 12 MONTH)"],
];
foreach ($rules as [$table, $where]) {
    try {
        if ($dry) {
            $n = $pdo->query("SELECT COUNT(*) FROM `$table` WHERE $where")->fetchColumn();
            echo "[dry-run] $table: $n row(s) would be deleted\n";
        } else {
            $n = $pdo->exec("DELETE FROM `$table` WHERE $where");
            echo "$table: deleted $n row(s)\n";
        }
    } catch (PDOException $e) {
        echo "$table: skipped (" . $e->getMessage() . ")\n";
    }
}
