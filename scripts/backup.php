<?php
// scripts/backup.php
// Database backup script - run from cron / the command line only:
//   php scripts/backup.php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';

class DatabaseBackup {
    private $host;
    private $username;
    private $password;
    private $database;
    private $backupPath;

    public function __construct() {
        $this->host = DB_HOST;
        $this->username = DB_USER;
        $this->password = DB_PASS;
        $this->database = DB_NAME;
        $this->backupPath = __DIR__ . '/../backups/';

        // Create backup directory if it doesn't exist
        if (!file_exists($this->backupPath)) {
            mkdir($this->backupPath, 0750, true);
        }
    }

    public function createBackup() {
        $filename = $this->backupPath . 'backup_' . $this->database . '_' . date('Y-m-d_H-i-s') . '.sql';

        // Password goes through the environment so it never shows in `ps`
        $command = sprintf(
            'MYSQL_PWD=%s mysqldump --single-transaction --host=%s --user=%s %s > %s',
            escapeshellarg($this->password),
            escapeshellarg($this->host),
            escapeshellarg($this->username),
            escapeshellarg($this->database),
            escapeshellarg($filename)
        );

        system($command, $output);
        @chmod($filename, 0640);

        if ($output === 0) {
            $this->log("Backup created successfully: $filename");
            $final = $this->compressBackup($filename);
            $this->cleanOldBackups();
            return $final;
        } else {
            $this->log("Backup failed for database: " . $this->database);
            return false;
        }
    }

    private function compressBackup($filename) {
        $gzipped = $filename . '.gz';
        $command = sprintf('gzip %s', escapeshellarg($filename));
        system($command, $output);

        if ($output === 0) {
            // gzip already removed the plain .sql file
            $this->log("Backup compressed: $gzipped");
            return $gzipped;
        }

        return $filename;
    }

    private function cleanOldBackups() {
        $files = glob($this->backupPath . '*.sql.gz');
        $now = time();

        foreach ($files as $file) {
            if (is_file($file)) {
                // Delete files older than 30 days
                if ($now - filemtime($file) > 30 * 24 * 60 * 60) {
                    unlink($file);
                    $this->log("Deleted old backup: $file");
                }
            }
        }
    }

    private function log($message) {
        $logFile = $this->backupPath . 'backup.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents(
            $logFile,
            "[$timestamp] $message" . PHP_EOL,
            FILE_APPEND
        );
    }

    public function sendToEmail($backupFile) {
        // Optional: Send backup to email
        $to = SCHOOL_EMAIL;
        $subject = 'Database Backup - ' . date('Y-m-d');
        $message = 'Database backup attached.';

        $headers = "From: " . SCHOOL_EMAIL . "\r\n";

        if (file_exists($backupFile)) {
            $content = file_get_contents($backupFile);
            $content = chunk_split(base64_encode($content));

            $boundary = md5(time());

            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";

            $body = "--$boundary\r\n";
            $body .= "Content-Type: text/plain; charset=\"UTF-8\"\r\n";
            $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $body .= "$message\r\n\r\n";

            $body .= "--$boundary\r\n";
            $body .= "Content-Type: application/octet-stream; name=\"" . basename($backupFile) . "\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= "Content-Disposition: attachment\r\n\r\n";
            $body .= "$content\r\n\r\n";
            $body .= "--$boundary--";

            mail($to, $subject, $body, $headers);
        }
    }
}

// Run backup when invoked from the command line
if (PHP_SAPI === 'cli') {
    $backup = new DatabaseBackup();
    $file = $backup->createBackup();

    if ($file) {
        echo "Backup completed: $file\n";
    } else {
        echo "Backup failed\n";
    }
}
?>