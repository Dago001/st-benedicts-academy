<?php
// scripts/backup.php
// Database backup script - Run via cron

require_once __DIR__ . '/../config/config.php';

class DatabaseBackup {
    private $host;
    private $username;
    private $password;
    private $database;
    private $backupPath;
    
    public function __construct() {
        $this->host = 'localhost';
        $this->username = 'root'; // Change in production
        $this->password = ''; // Change in production
        $this->database = 'st_benedicts_academy';
        $this->backupPath = __DIR__ . '/../backups/';
        
        // Create backup directory if it doesn't exist
        if (!file_exists($this->backupPath)) {
            mkdir($this->backupPath, 0755, true);
        }
    }
    
    public function createBackup() {
        $filename = $this->backupPath . 'backup_' . $this->database . '_' . date('Y-m-d_H-i-s') . '.sql';
        
        $command = sprintf(
            'mysqldump --host=%s --user=%s --password=%s %s > %s',
            escapeshellarg($this->host),
            escapeshellarg($this->username),
            escapeshellarg($this->password),
            escapeshellarg($this->database),
            escapeshellarg($filename)
        );
        
        system($command, $output);
        
        if ($output === 0) {
            $this->log("Backup created successfully: $filename");
            $this->compressBackup($filename);
            $this->cleanOldBackups();
            return $filename;
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
            unlink($filename);
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
        $to = 'admin@stbenedicts.edu.ng';
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

// Run backup if called directly
if (php_sapi_name() === 'cli' || isset($_GET['run'])) {
    $backup = new DatabaseBackup();
    $file = $backup->createBackup();
    
    if ($file) {
        echo "Backup completed: $file\n";
    } else {
        echo "Backup failed\n";
    }
}
?>