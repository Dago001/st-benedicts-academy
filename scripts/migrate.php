<?php
// scripts/migrate.php - upgrade an existing (pre-update) database to the current schema.
// Safe to run more than once: every step checks information_schema first.
//   php scripts/migrate.php            # apply
//   php scripts/migrate.php --dry-run  # show what would change
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/database.php';

$dry = in_array('--dry-run', $argv, true);
$pdo = Database::getInstance()->getConnection();
$db = DB_NAME;
$done = 0;

function has_table($pdo, $db, $t) {
    $s = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
    $s->execute([$db, $t]); return (bool)$s->fetchColumn();
}
function has_col($pdo, $db, $t, $c) {
    $s = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?');
    $s->execute([$db, $t, $c]); return (bool)$s->fetchColumn();
}
function run($pdo, $dry, $label, $sql) {
    global $done;
    echo ($dry ? '[dry-run] ' : '') . $label . "\n";
    if (!$dry) $pdo->exec($sql);
    $done++;
}

$columns = [
    ['fee_structure', 'due_date', 'ALTER TABLE fee_structure ADD COLUMN due_date DATE NULL AFTER academic_year'],
    ['payments', 'bank_name', 'ALTER TABLE payments ADD COLUMN bank_name VARCHAR(100) NULL AFTER transaction_id'],
    ['payments', 'cheque_number', 'ALTER TABLE payments ADD COLUMN cheque_number VARCHAR(50) NULL AFTER bank_name'],
    ['payments', 'fee_structure_id', 'ALTER TABLE payments ADD COLUMN fee_structure_id INT NULL AFTER fee_type'],
    ['payments', 'approved_by', 'ALTER TABLE payments ADD COLUMN approved_by INT NULL'],
    ['payments', 'approved_at', 'ALTER TABLE payments ADD COLUMN approved_at DATETIME NULL'],
    ['gallery', 'is_featured', 'ALTER TABLE gallery ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0'],
    ['users', 'totp_secret', 'ALTER TABLE users ADD COLUMN totp_secret VARCHAR(64) NULL'],
    ['users', 'totp_enabled', 'ALTER TABLE users ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0'],
    ['admissions', 'middle_name', 'ALTER TABLE admissions ADD COLUMN middle_name VARCHAR(50) NULL AFTER first_name'],
];
foreach ($columns as [$t, $c, $sql]) {
    if (has_table($pdo, $db, $t) && !has_col($pdo, $db, $t, $c)) run($pdo, $dry, "add column $t.$c", $sql);
}

// The admissions table may have been created at runtime by the old page without every column
$tables = [
'homework' => "CREATE TABLE homework (
    id INT PRIMARY KEY AUTO_INCREMENT, class_id INT NOT NULL, subject_id INT NOT NULL, teacher_id INT NOT NULL,
    title VARCHAR(200) NOT NULL, description TEXT, instructions TEXT, attachment_path VARCHAR(255),
    due_date DATETIME NOT NULL, total_marks DECIMAL(5,2) DEFAULT 100, is_published BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
    INDEX idx_due_date (due_date)) ENGINE=InnoDB",
'homework_submissions' => "CREATE TABLE homework_submissions (
    id INT PRIMARY KEY AUTO_INCREMENT, homework_id INT NOT NULL, student_id INT NOT NULL, submission_text TEXT, attachment_path VARCHAR(255),
    status ENUM('submitted','late','graded') DEFAULT 'submitted', submission_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    obtained_marks DECIMAL(5,2), feedback TEXT, graded_by INT, graded_at DATETIME,
    FOREIGN KEY (homework_id) REFERENCES homework(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (graded_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_submission (homework_id, student_id)) ENGINE=InnoDB",
'applications' => "CREATE TABLE applications (
    id INT PRIMARY KEY AUTO_INCREMENT, application_number VARCHAR(50) UNIQUE NOT NULL,
    child_first_name VARCHAR(50) NOT NULL, child_last_name VARCHAR(50) NOT NULL, child_dob DATE NOT NULL, child_gender VARCHAR(10) NOT NULL,
    class_applying VARCHAR(50) NOT NULL, parent_title VARCHAR(20), parent_first_name VARCHAR(50) NOT NULL, parent_last_name VARCHAR(50) NOT NULL,
    parent_email VARCHAR(100) NOT NULL, parent_phone VARCHAR(20) NOT NULL, parent_occupation VARCHAR(100), address TEXT NOT NULL,
    city VARCHAR(100), state VARCHAR(100), previous_school VARCHAR(200), reason_applying TEXT, how_hear VARCHAR(100),
    emergency_name VARCHAR(100), emergency_phone VARCHAR(20), emergency_relationship VARCHAR(50),
    birth_certificate_path VARCHAR(255), passport_photo_path VARCHAR(255),
    status ENUM('pending','reviewing','accepted','rejected') DEFAULT 'pending', reviewed_by INT, reviewed_at DATETIME, remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL, INDEX idx_app_status (status)) ENGINE=InnoDB",
'time_table' => "CREATE TABLE time_table (
    id INT PRIMARY KEY AUTO_INCREMENT, class_id INT NOT NULL, subject_id INT NOT NULL, teacher_id INT,
    day_of_week ENUM('Monday','Tuesday','Wednesday','Thursday','Friday') NOT NULL, start_time TIME NOT NULL, end_time TIME NOT NULL, room VARCHAR(50),
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE SET NULL) ENGINE=InnoDB",
'staff_attendance' => "CREATE TABLE staff_attendance (
    id INT PRIMARY KEY AUTO_INCREMENT, teacher_id INT NOT NULL, date DATE NOT NULL, status ENUM('present','absent','late','excused') NOT NULL,
    check_in TIME, check_out TIME, remarks TEXT,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE, UNIQUE KEY unique_staff_day (teacher_id, date)) ENGINE=InnoDB",
'password_resets' => "CREATE TABLE password_resets (
    id INT PRIMARY KEY AUTO_INCREMENT, user_id INT NOT NULL, token_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, UNIQUE KEY uniq_token (token_hash)) ENGINE=InnoDB",
'chatbot_logs' => "CREATE TABLE chatbot_logs (
    id INT PRIMARY KEY AUTO_INCREMENT, ip_hash CHAR(64) NOT NULL, question VARCHAR(300) NOT NULL, intent VARCHAR(40),
    matched TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_chat_ip (ip_hash, created_at), INDEX idx_chat_matched (matched, created_at)) ENGINE=InnoDB",
'login_throttle' => "CREATE TABLE login_throttle (
    id INT PRIMARY KEY AUTO_INCREMENT, ip_hash CHAR(64) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_throttle (ip_hash, created_at)) ENGINE=InnoDB",
];
foreach ($tables as $name => $sql) {
    if (!has_table($pdo, $db, $name)) run($pdo, $dry, "create table $name", $sql);
}

// Carry over homework created under the old, unused `assignments` tables (if any)
if (!$dry && has_table($pdo, $db, 'assignments') && has_table($pdo, $db, 'homework')) {
    $n = $pdo->exec("INSERT INTO homework (class_id, subject_id, teacher_id, title, description, attachment_path, due_date, total_marks, created_at)
                     SELECT class_id, subject_id, teacher_id, title, description, file_path, due_date, max_score, created_at FROM assignments a
                     WHERE NOT EXISTS (SELECT 1 FROM homework h WHERE h.title = a.title AND h.class_id = a.class_id AND h.due_date = a.due_date)");
    if ($n) { echo "migrated $n assignment(s) into homework\n"; $done++; }
}

// Users: make sure an installation that still has the placeholder admin hash cannot be logged into
$bad = $pdo->query("SELECT COUNT(*) FROM users WHERE password_hash LIKE '%YourHashedPasswordHere%'")->fetchColumn();
if ($bad) echo "WARNING: $bad account(s) still have the placeholder password hash - reset them from the admin panel.\n";

echo $done ? "\n$done change(s) " . ($dry ? 'would be applied' : 'applied') . ".\n" : "Database is already up to date.\n";
