<?php
/**
 * SystemSchema.php - Database self-healing schema manager for Global Standard School Application
 * Automatically creates and updates tables for:
 * 1. 7-Role Architecture (super_admin, admin, head_teacher, supervisor, teacher, parent, student)
 * 2. New Admission extra fields & student metadata
 * 3. Operational Expenses & Financial Tracking
 * 4. Staff Payroll & Salary Management
 * 5. Fee Deletion Logs & Audit Accountability
 * 6. Student Academic Promotions & Archive
 * 7. Examination Schedules & Timetables
 */
require_once __DIR__ . '/../config/database.php';

class SystemSchema
{
    public static function ensureAll(): void
    {
        static $executed = false;
        if ($executed) {
            return;
        }
        $executed = true;

        try {
            $db = Database::getInstance()->getConnection();

            // 1. Expand users.role column to support all 7 roles
            try {
                $db->exec("ALTER TABLE users MODIFY COLUMN role VARCHAR(30) NOT NULL DEFAULT 'student'");
            } catch (Throwable $e) {}

            // Ensure at least one System Administrator (super_admin) exists
            try {
                $countSuperAdmins = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND deleted_at IS NULL")->fetchColumn();
                if ($countSuperAdmins === 0) {
                    $db->exec("UPDATE users SET role = 'super_admin' WHERE (email = 'admin@stbenedicts.edu.ng' OR username = 'admin') AND deleted_at IS NULL LIMIT 1");
                }
            } catch (Throwable $e) {}

            // Ensure deleted_at, profile_image and must_change_password exist on users
            $userCols = self::getTableColumns($db, 'users');
            if (!in_array('deleted_at', $userCols, true)) {
                $db->exec("ALTER TABLE users ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL");
            }
            if (!in_array('profile_image', $userCols, true)) {
                $db->exec("ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) NULL DEFAULT NULL");
            }
            if (!in_array('must_change_password', $userCols, true)) {
                $db->exec("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0");
            }

            // 2. Expand students table with extra fields from the New Admission form
            $studentCols = self::getTableColumns($db, 'students');
            $newStudentFields = [
                'official_email'     => "VARCHAR(150) NULL DEFAULT NULL",
                'status'             => "VARCHAR(20) NOT NULL DEFAULT 'active'",
                'admission_date'     => "DATE NULL DEFAULT NULL",
                'category'           => "VARCHAR(50) NULL DEFAULT 'General'",
                'religion'           => "VARCHAR(50) NULL DEFAULT 'Christian'",
                'nationality'        => "VARCHAR(50) NULL DEFAULT 'Nigerian'",
                'medium'             => "VARCHAR(50) NULL DEFAULT 'English'",
                'national_id_number' => "VARCHAR(50) NULL DEFAULT NULL",
                'pen_number'         => "VARCHAR(50) NULL DEFAULT NULL",
                'father_name'        => "VARCHAR(100) NULL DEFAULT NULL",
                'mother_name'        => "VARCHAR(100) NULL DEFAULT NULL",
                'father_occupation'  => "VARCHAR(100) NULL DEFAULT NULL",
                'previous_school'    => "VARCHAR(200) NULL DEFAULT NULL",
                'last_class_studied' => "VARCHAR(50) NULL DEFAULT NULL",
                'tc_number'          => "VARCHAR(50) NULL DEFAULT NULL",
                'transport_fee'      => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
                'alt_phone'          => "VARCHAR(30) NULL DEFAULT NULL",
                'postal_code'        => "VARCHAR(30) NULL DEFAULT NULL",
                'lga'                => "VARCHAR(100) NULL DEFAULT NULL",
                'state'              => "VARCHAR(100) NULL DEFAULT NULL",
                'archive_year'       => "VARCHAR(30) NULL DEFAULT NULL"
            ];
            foreach ($newStudentFields as $field => $type) {
                if (!in_array($field, $studentCols, true)) {
                    try {
                        $db->exec("ALTER TABLE students ADD COLUMN {$field} {$type}");
                    } catch (Throwable $e) {}
                }
            }

            // 3. Operational Expenses Table
            $db->exec("CREATE TABLE IF NOT EXISTS expenses (
                id INT PRIMARY KEY AUTO_INCREMENT,
                title VARCHAR(200) NOT NULL,
                category VARCHAR(100) NOT NULL DEFAULT 'General',
                amount DECIMAL(12,2) NOT NULL,
                expense_date DATE NOT NULL,
                payment_method VARCHAR(50) NOT NULL DEFAULT 'bank_transfer',
                recipient VARCHAR(150) NULL,
                receipt_path VARCHAR(255) NULL,
                description TEXT NULL,
                recorded_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_exp_date (expense_date),
                INDEX idx_exp_cat (category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // 4. Staff Salary & Payroll Table
            $db->exec("CREATE TABLE IF NOT EXISTS salary_payments (
                id INT PRIMARY KEY AUTO_INCREMENT,
                staff_id INT NOT NULL,
                user_id INT NULL,
                month_year VARCHAR(20) NOT NULL,
                basic_salary DECIMAL(12,2) NOT NULL,
                allowances DECIMAL(12,2) DEFAULT 0.00,
                deductions DECIMAL(12,2) DEFAULT 0.00,
                net_salary DECIMAL(12,2) NOT NULL,
                payment_date DATE NOT NULL,
                payment_method VARCHAR(50) DEFAULT 'bank_transfer',
                reference VARCHAR(100) NULL,
                status ENUM('paid', 'pending', 'cancelled') DEFAULT 'paid',
                remarks TEXT NULL,
                recorded_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_sal_month (month_year),
                INDEX idx_sal_staff (staff_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // 5. Fee Deletion Log Table
            $db->exec("CREATE TABLE IF NOT EXISTS fee_deletion_logs (
                id INT PRIMARY KEY AUTO_INCREMENT,
                item_type ENUM('structure', 'payment') NOT NULL,
                item_id INT NOT NULL,
                student_name VARCHAR(150) NULL,
                admission_number VARCHAR(50) NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                reference VARCHAR(100) NULL,
                reason TEXT NOT NULL,
                deleted_by INT NOT NULL,
                deleted_by_name VARCHAR(100) NOT NULL,
                ip_address VARCHAR(45) NULL,
                deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_fee_del_type (item_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // 6. Student Academic Promotions Table
            $db->exec("CREATE TABLE IF NOT EXISTS student_promotions (
                id INT PRIMARY KEY AUTO_INCREMENT,
                student_id INT NOT NULL,
                from_class_id INT NOT NULL,
                to_class_id INT NOT NULL,
                from_academic_year VARCHAR(30) NOT NULL,
                to_academic_year VARCHAR(30) NOT NULL,
                status ENUM('promoted', 'retained', 'graduated') NOT NULL DEFAULT 'promoted',
                promoted_by INT NULL,
                promoted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_promo_student (student_id),
                INDEX idx_promo_year (from_academic_year)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // 7. Exam Schedules Table
            $db->exec("CREATE TABLE IF NOT EXISTS exam_schedules (
                id INT PRIMARY KEY AUTO_INCREMENT,
                exam_name VARCHAR(150) NOT NULL,
                term VARCHAR(50) NOT NULL,
                academic_year VARCHAR(30) NOT NULL,
                class_id INT NOT NULL,
                subject_id INT NOT NULL,
                exam_date DATE NOT NULL,
                start_time TIME NOT NULL,
                end_time TIME NOT NULL,
                room VARCHAR(100) NULL,
                max_marks DECIMAL(5,2) DEFAULT 100,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_exam_date (exam_date),
                INDEX idx_exam_class (class_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // 8. Ensure ApplicationHelper schema is also ensured
            if (class_exists('ApplicationHelper') && method_exists('ApplicationHelper', 'ensureSchema')) {
                ApplicationHelper::ensureSchema();
            }

            // 9. Backfill Student Official School Emails (@stbenedictsacademy.com.ng)
            self::backfillStudentOfficialEmails($db);

        } catch (Throwable $e) {
            error_log("SystemSchema::ensureAll error: " . $e->getMessage());
        }
    }

    /**
     * Ensures all students have a valid official email in the domain @stbenedictsacademy.com.ng
     */
    public static function backfillStudentOfficialEmails(PDO $db): int
    {
        $updated = 0;
        try {
            $stmt = $db->query(
                "SELECT s.id, s.admission_number, s.official_email, u.id AS user_id, u.first_name, u.last_name, u.email
                 FROM students s
                 JOIN users u ON s.user_id = u.id
                 WHERE s.official_email IS NULL OR s.official_email = '' OR s.official_email LIKE '%@stbenedicts.edu.ng'"
            );
            $students = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($students as $st) {
                $adm = trim((string)($st['admission_number'] ?? ''));
                $clean = strtolower(preg_replace('/[^a-z0-9]/i', '', $adm));
                if ($clean === '') {
                    $clean = strtolower(preg_replace('/[^a-z0-9]/i', '', ($st['first_name'] ?? '') . '.' . ($st['last_name'] ?? ''))) . $st['id'];
                }
                $officialEmail = $clean . '@stbenedictsacademy.com.ng';

                $uStmt = $db->prepare("UPDATE students SET official_email = ? WHERE id = ?");
                $uStmt->execute([$officialEmail, $st['id']]);

                // Update users.email if it is empty or placeholder domain
                $currentEmail = (string)($st['email'] ?? '');
                if (empty($currentEmail) || strpos($currentEmail, '@stbenedicts.edu.ng') !== false || strpos($currentEmail, 'student.') === 0) {
                    $chk = $db->prepare("SELECT id FROM users WHERE email = ? AND id <> ?");
                    $chk->execute([$officialEmail, $st['user_id']]);
                    if (!$chk->fetch()) {
                        $upU = $db->prepare("UPDATE users SET email = ? WHERE id = ?");
                        $upU->execute([$officialEmail, $st['user_id']]);
                    }
                }
                $updated++;
            }
        } catch (Throwable $e) {
            error_log("backfillStudentOfficialEmails error: " . $e->getMessage());
        }
        return $updated;
    }

    private static function getTableColumns(PDO $db, string $table): array
    {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `{$table}`");
            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
