<?php
// scripts/restore.php - restore a backup produced by scripts/backup.php.
//   php scripts/restore.php backups/backup_<db>_<date>.sql.gz --yes
// DESTRUCTIVE: replaces the contents of the configured database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/config.php';

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) { fwrite(STDERR, "Usage: php scripts/restore.php <backup.sql|backup.sql.gz> --yes\n"); exit(2); }
if (!in_array('--yes', $argv, true)) { fwrite(STDERR, "This overwrites database '" . DB_NAME . "'. Re-run with --yes to confirm.\n"); exit(2); }

$reader = preg_match('/\.gz$/', $file) ? 'gzip -dc' : 'cat';
$cmd = sprintf('%s %s | MYSQL_PWD=%s mysql --host=%s --user=%s %s',
    $reader, escapeshellarg($file), escapeshellarg(DB_PASS), escapeshellarg(DB_HOST), escapeshellarg(DB_USER), escapeshellarg(DB_NAME));
system($cmd, $code);
echo $code === 0 ? "Restore complete.\n" : "Restore FAILED (exit $code).\n";
exit($code);
