<?php
/**
 * ApplicationHelper
 * Unified schema management, sequential document catalog, payment ledger tracking,
 * live Paystack verification sync, and receipt lookup for Online Admissions.
 */
require_once __DIR__ . '/../config/database.php';

class ApplicationHelper
{
    public const APPLICATION_FEE = 15000.00;

    /**
     * Ordered catalog of documents for the sequential "Required Documents" dropdown uploader.
     */
    public static function getDocumentCatalog(): array
    {
        return [
            'birth_certificate' => [
                'key'      => 'birth_certificate',
                'label'    => 'Birth Certificate (Required)',
                'short'    => 'Birth Certificate',
                'required' => true,
                'allowed'  => ['pdf', 'jpg', 'jpeg', 'png'],
                'accept'   => '.pdf,.jpg,.jpeg,.png',
                'hint'     => 'Official Birth Certificate or NPC Attestation (PDF, JPG, PNG — Max 5MB)'
            ],
            'immunization_record' => [
                'key'      => 'immunization_record',
                'label'    => 'Immunization / Health Record (Recommended)',
                'short'    => 'Immunization / Health Record',
                'required' => false,
                'allowed'  => ['pdf', 'jpg', 'jpeg', 'png'],
                'accept'   => '.pdf,.jpg,.jpeg,.png',
                'hint'     => 'Immunization card or medical fitness report (PDF, JPG, PNG — Max 5MB)'
            ],
            'previous_report' => [
                'key'      => 'previous_report',
                'label'    => 'Previous School Report / Transcript (Optional)',
                'short'    => 'Previous School Report',
                'required' => false,
                'allowed'  => ['pdf', 'jpg', 'jpeg', 'png'],
                'accept'   => '.pdf,.jpg,.jpeg,.png',
                'hint'     => 'Last terminal report sheet if transferring from another school (PDF, JPG, PNG — Max 5MB)'
            ],
            'parent_id' => [
                'key'      => 'parent_id',
                'label'    => 'Parent / Guardian Valid ID Card (Optional)',
                'short'    => 'Parent / Guardian Valid ID',
                'required' => false,
                'allowed'  => ['pdf', 'jpg', 'jpeg', 'png'],
                'accept'   => '.pdf,.jpg,.jpeg,.png',
                'hint'     => 'NIN Slip, International Passport, Driver\'s License, or Voter\'s Card (PDF, JPG, PNG — Max 5MB)'
            ],
            'payment_proof' => [
                'key'      => 'payment_proof',
                'label'    => 'Payment / Bank Transfer Evidence (Optional)',
                'short'    => 'Payment / Transfer Evidence',
                'required' => false,
                'allowed'  => ['pdf', 'jpg', 'jpeg', 'png'],
                'accept'   => '.pdf,.jpg,.jpeg,.png',
                'hint'     => 'Bank transfer slip or teller if paying via Direct Bank Transfer (PDF, JPG, PNG — Max 5MB)'
            ],
        ];
    }

    /**
     * Self-healing schema migration for `admissions`, `applications`, and `application_payments`.
     */
    public static function ensureSchema(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        $ensured = true;

        try {
            $db = Database::getInstance()->getConnection();

            $commonExtraColumns = [
                'middle_name'            => "VARCHAR(100) DEFAULT NULL",
                'child_middle_name'      => "VARCHAR(100) DEFAULT NULL",
                'academic_session'       => "VARCHAR(30) DEFAULT '2026/2027'",
                'entry_term'             => "VARCHAR(50) DEFAULT 'First Term'",
                'blood_group'            => "VARCHAR(10) DEFAULT NULL",
                'genotype'               => "VARCHAR(10) DEFAULT NULL",
                'medical_conditions'     => "TEXT DEFAULT NULL",
                'parent_title'           => "VARCHAR(20) DEFAULT NULL",
                'parent_relationship'    => "VARCHAR(50) DEFAULT 'Parent'",
                'parent_occupation'      => "VARCHAR(120) DEFAULT NULL",
                'city'                   => "VARCHAR(100) DEFAULT NULL",
                'state'                  => "VARCHAR(100) DEFAULT NULL",
                'lga'                    => "VARCHAR(100) DEFAULT NULL",
                'country'                => "VARCHAR(100) DEFAULT 'Nigeria'",
                'emergency_name'         => "VARCHAR(150) DEFAULT NULL",
                'emergency_phone'        => "VARCHAR(30) DEFAULT NULL",
                'emergency_relationship' => "VARCHAR(50) DEFAULT NULL",
                'documents_path'         => "TEXT DEFAULT NULL",
                'payment_method'         => "VARCHAR(50) DEFAULT 'paystack_card'",
                'payment_reference'      => "VARCHAR(100) DEFAULT NULL",
                'receipt_number'         => "VARCHAR(60) DEFAULT NULL",
                'amount_paid'            => "DECIMAL(10,2) DEFAULT 15000.00",
                'payment_status'         => "VARCHAR(30) DEFAULT 'paid'",
                'receipt_token'          => "VARCHAR(64) DEFAULT NULL",
                'paid_at'                => "DATETIME DEFAULT NULL",
                'interview_result'       => "VARCHAR(50) DEFAULT 'pending'",
                'interview_notes'        => "TEXT DEFAULT NULL",
                'interview_date'         => "DATE DEFAULT NULL",
                'interview_teacher'      => "VARCHAR(150) DEFAULT NULL",
                'admitted_class'         => "VARCHAR(100) DEFAULT NULL",
                'parent_user_id'         => "INT DEFAULT NULL",
                'student_id'             => "INT DEFAULT NULL",
                'school_payment_confirmed_by' => "INT DEFAULT NULL",
                'school_payment_confirmed_at' => "DATETIME DEFAULT NULL",
                'school_payment_receipt' => "VARCHAR(60) DEFAULT NULL",
                'school_payment_channel' => "VARCHAR(50) DEFAULT NULL"
            ];

            self::addMissingColumns($db, 'admissions', $commonExtraColumns);
            self::addMissingColumns($db, 'applications', $commonExtraColumns);

            // Dedicated unified payment transactions ledger for backend tracking & Paystack verification
            $db->exec("CREATE TABLE IF NOT EXISTS `application_payments` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `payment_reference` VARCHAR(100) NOT NULL UNIQUE,
                `application_number` VARCHAR(50) DEFAULT NULL,
                `receipt_number` VARCHAR(60) DEFAULT NULL,
                `child_name` VARCHAR(150) DEFAULT NULL,
                `parent_name` VARCHAR(150) DEFAULT NULL,
                `parent_email` VARCHAR(150) DEFAULT NULL,
                `parent_phone` VARCHAR(40) DEFAULT NULL,
                `amount` DECIMAL(10,2) NOT NULL DEFAULT 15000.00,
                `payment_method` VARCHAR(50) DEFAULT 'paystack',
                `payment_channel` VARCHAR(50) DEFAULT NULL,
                `payment_status` VARCHAR(30) DEFAULT 'pending',
                `gateway_response` VARCHAR(255) DEFAULT NULL,
                `verified_by` INT DEFAULT NULL,
                `paid_at` DATETIME DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_app_pay_appno` (`application_number`),
                INDEX `idx_app_pay_status` (`payment_status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
            error_log("ApplicationHelper::ensureSchema warning: " . $e->getMessage());
        }
    }

    private static function addMissingColumns(PDO $db, string $table, array $columns): void
    {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `{$table}`");
            $existing = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $existing[strtolower($row['Field'])] = true;
            }
            foreach ($columns as $col => $def) {
                if (!isset($existing[strtolower($col)])) {
                    $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}");
                }
            }
        } catch (Throwable $e) {
            error_log("ApplicationHelper::addMissingColumns({$table}): " . $e->getMessage());
        }
    }

    public static function generateReceiptNumber(): string
    {
        return 'RCP-APP-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    }

    public static function generatePaymentReference(string $prefix = 'PSTK-STB'): string
    {
        return $prefix . '-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }

    public static function generateReceiptToken(string $applicationNumber, string $email = ''): string
    {
        return substr(hash_hmac('sha256', strtoupper(trim($applicationNumber)) . '|' . strtolower(trim($email)), 'st_benedicts_receipt_key_2026'), 0, 24);
    }

    public static function formatPaymentMethod(?string $method): string
    {
        $map = [
            'paystack'          => 'Paystack Online Checkout',
            'paystack_card'     => 'Paystack Online Card Payment (Debit / Credit Card)',
            'paystack_transfer' => 'Paystack Instant Bank Transfer',
            'paystack_bank'     => 'Paystack Bank Payment',
            'paystack_ussd'     => 'Paystack USSD Instant Channel',
            'online_card'       => 'Paystack Online Card Payment (Debit / Credit Card)',
            'online_transfer'   => 'Paystack Instant Bank Transfer',
            'online_ussd'       => 'Paystack USSD Instant Channel',
            'pay_at_school'     => 'Pay at School Admissions Office (Application Slip / Zenith Bank)',
            'bank_transfer'     => 'Direct Bank Transfer (' . (defined('SCHOOL_BANK_NAME') ? SCHOOL_BANK_NAME : 'Zenith Bank') . ' - ' . (defined('SCHOOL_ACCOUNT_NUMBER') ? SCHOOL_ACCOUNT_NUMBER : '1310057496') . ')'
        ];
        return $map[$method ?? 'paystack_card'] ?? ucwords(str_replace('_', ' ', (string)$method));
    }

    /**
     * Log a Paystack payment initialization immediately so the Admin can see even in-progress checkouts at the backend.
     */
    public static function recordPaymentInit(string $reference, string $email, float $amount, string $childName = '', string $parentName = '', string $phone = ''): void
    {
        self::ensureSchema();
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("INSERT INTO application_payments (
                payment_reference, child_name, parent_name, parent_email, parent_phone, amount, payment_method, payment_status
            ) VALUES (?, ?, ?, ?, ?, ?, 'paystack', 'initialized')
            ON DUPLICATE KEY UPDATE
                child_name = COALESCE(NULLIF(VALUES(child_name), ''), child_name),
                parent_name = COALESCE(NULLIF(VALUES(parent_name), ''), parent_name),
                parent_email = COALESCE(NULLIF(VALUES(parent_email), ''), parent_email),
                parent_phone = COALESCE(NULLIF(VALUES(parent_phone), ''), parent_phone)");
            $stmt->execute([$reference, $childName ?: null, $parentName ?: null, $email ?: null, $phone ?: null, $amount]);
        } catch (Throwable $e) {
            error_log("ApplicationHelper::recordPaymentInit error: " . $e->getMessage());
        }
    }

    /**
     * Record a Paystack verification result in `application_payments` and update any linked `admissions` / `applications` rows.
     */
    public static function recordPaymentVerification(string $reference, array $verifyResult, ?int $verifiedByUserId = null): void
    {
        self::ensureSchema();
        $reference = trim($reference);
        if ($reference === '') {
            return;
        }
        try {
            $db = Database::getInstance()->getConnection();
            $isSuccess = !empty($verifyResult['success']);
            $status    = $isSuccess ? 'verified' : ($verifyResult['status'] ?? 'failed');
            $channel   = $verifyResult['channel'] ?? 'paystack_card';
            $amount    = (float)($verifyResult['amount'] ?? self::APPLICATION_FEE);
            $msg       = mb_substr((string)($verifyResult['message'] ?? ''), 0, 250);
            $paidAt    = $isSuccess ? date('Y-m-d H:i:s') : null;

            $stmt = $db->prepare("INSERT INTO application_payments (
                payment_reference, amount, payment_method, payment_channel, payment_status, gateway_response, verified_by, paid_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                amount = IF(VALUES(amount) > 0, VALUES(amount), amount),
                payment_channel = VALUES(payment_channel),
                payment_status = VALUES(payment_status),
                gateway_response = VALUES(gateway_response),
                verified_by = COALESCE(VALUES(verified_by), verified_by),
                paid_at = COALESCE(paid_at, VALUES(paid_at))");
            $stmt->execute([$reference, $amount > 0 ? $amount : self::APPLICATION_FEE, $channel, $channel, $status, $msg, $verifiedByUserId, $paidAt]);

            if ($isSuccess) {
                $upd1 = $db->prepare("UPDATE admissions SET payment_status = 'verified', payment_method = ?, paid_at = COALESCE(paid_at, NOW()) WHERE payment_reference = ?");
                $upd1->execute([$channel, $reference]);

                $upd2 = $db->prepare("UPDATE applications SET payment_status = 'verified', payment_method = ?, paid_at = COALESCE(paid_at, NOW()) WHERE payment_reference = ?");
                $upd2->execute([$channel, $reference]);
            }
        } catch (Throwable $e) {
            error_log("ApplicationHelper::recordPaymentVerification error: " . $e->getMessage());
        }
    }

    /**
     * Synchronize an application submission with the `application_payments` ledger table.
     */
    public static function syncApplicationPayment(
        string $appNumber,
        string $receiptNumber,
        string $reference,
        string $method,
        float $amount,
        string $status,
        string $childName,
        string $parentName,
        string $email,
        string $phone
    ): void {
        self::ensureSchema();
        try {
            $db = Database::getInstance()->getConnection();
            $paidAt = in_array($status, ['paid', 'verified'], true) ? date('Y-m-d H:i:s') : null;
            $stmt = $db->prepare("INSERT INTO application_payments (
                payment_reference, application_number, receipt_number, child_name, parent_name, parent_email, parent_phone,
                amount, payment_method, payment_channel, payment_status, paid_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                application_number = VALUES(application_number),
                receipt_number = VALUES(receipt_number),
                child_name = VALUES(child_name),
                parent_name = VALUES(parent_name),
                parent_email = VALUES(parent_email),
                parent_phone = VALUES(parent_phone),
                payment_method = VALUES(payment_method),
                payment_status = IF(payment_status = 'verified', 'verified', VALUES(payment_status)),
                paid_at = COALESCE(paid_at, VALUES(paid_at))");
            $stmt->execute([
                $reference, $appNumber, $receiptNumber, $childName, $parentName, $email, $phone,
                $amount, $method, $method, $status, $paidAt
            ]);
        } catch (Throwable $e) {
            error_log("ApplicationHelper::syncApplicationPayment error: " . $e->getMessage());
        }
    }

    /**
     * Find an application by Application Number, Receipt Number, or Payment Reference
     * across both `admissions` and `applications` tables, normalized to a common format.
     */
    public static function findApplication(string $query): ?array
    {
        self::ensureSchema();
        $query = trim($query);
        if ($query === '') {
            return null;
        }

        try {
            $db = Database::getInstance()->getConnection();

            // Check `admissions` table first
            $stmt = $db->prepare("SELECT * FROM admissions WHERE application_number = :q1 OR receipt_number = :q2 OR payment_reference = :q3 LIMIT 1");
            $stmt->execute([':q1' => $query, ':q2' => $query, ':q3' => $query]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return self::normalizeAdmissionRow($row, 'admissions');
            }

            // Check `applications` table second
            $stmt2 = $db->prepare("SELECT * FROM applications WHERE application_number = :q1 OR receipt_number = :q2 OR payment_reference = :q3 LIMIT 1");
            $stmt2->execute([':q1' => $query, ':q2' => $query, ':q3' => $query]);
            $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);
            if ($row2) {
                return self::normalizeAdmissionRow($row2, 'applications');
            }
        } catch (Throwable $e) {
            error_log("ApplicationHelper::findApplication error: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Normalize a row from either `admissions` or `applications` into a unified receipt/admin model.
     */
    public static function normalizeAdmissionRow(array $row, string $sourceTable = 'admissions'): array
    {
        $childFirst  = $row['child_first_name'] ?? $row['first_name'] ?? '';
        $childMiddle = $row['child_middle_name'] ?? $row['middle_name'] ?? '';
        $childLast   = $row['child_last_name'] ?? $row['last_name'] ?? '';
        $childName   = trim($childFirst . ' ' . ($childMiddle ? $childMiddle . ' ' : '') . $childLast);

        $parentTitle = $row['parent_title'] ?? '';
        if (!empty($row['parent_name'])) {
            $parentName = trim(($parentTitle && stripos($row['parent_name'], $parentTitle) !== 0 ? $parentTitle . ' ' : '') . $row['parent_name']);
        } else {
            $parentName = trim(($parentTitle ? $parentTitle . ' ' : '') . ($row['parent_first_name'] ?? '') . ' ' . ($row['parent_last_name'] ?? ''));
        }

        $docs = [];
        if (!empty($row['documents_path'])) {
            $decoded = json_decode($row['documents_path'], true);
            if (is_array($decoded)) {
                $docs = $decoded;
            }
        }
        $individualCols = [
            'birth_certificate' => ['birth_certificate_path', 'birth_certificate'],
            'passport_photo'    => ['passport_photo_path', 'passport_photo'],
        ];
        foreach ($individualCols as $docKey => $colCandidates) {
            if (empty($docs[$docKey])) {
                foreach ($colCandidates as $colName) {
                    if (!empty($row[$colName])) {
                        $docs[$docKey] = $row[$colName];
                        break;
                    }
                }
            }
        }

        $appNo = $row['application_number'] ?? '';
        $email = $row['parent_email'] ?? '';
        $createdAt = $row['created_at'] ?? $row['submitted_at'] ?? date('Y-m-d H:i:s');

        return [
            'id'                     => (int)($row['id'] ?? 0),
            'source_table'           => $sourceTable,
            'application_number'     => $appNo,
            'child_first_name'       => $childFirst,
            'child_middle_name'      => $childMiddle,
            'child_last_name'        => $childLast,
            'child_full_name'        => $childName,
            'date_of_birth'          => $row['child_dob'] ?? $row['date_of_birth'] ?? '',
            'gender'                 => ucfirst((string)($row['child_gender'] ?? $row['gender'] ?? '')),
            'class_applying'         => $row['class_applying'] ?? $row['class_applying_for'] ?? '',
            'academic_session'       => $row['academic_session'] ?? '2026/2027',
            'entry_term'             => $row['entry_term'] ?? 'First Term',
            'blood_group'            => $row['blood_group'] ?? '',
            'genotype'               => $row['genotype'] ?? '',
            'medical_conditions'     => $row['medical_conditions'] ?? $row['medical_notes'] ?? '',
            'previous_school'        => $row['previous_school'] ?? '',
            'nationality'            => $row['country'] ?? $row['nationality'] ?? 'Nigeria',
            'state_of_origin'        => $row['state'] ?? $row['state_of_origin'] ?? '',
            'lga'                    => $row['lga'] ?? '',
            'parent_name'            => $parentName,
            'parent_relationship'    => $row['parent_relationship'] ?? 'Parent',
            'parent_email'           => $email,
            'parent_phone'           => $row['parent_phone'] ?? '',
            'parent_occupation'      => $row['parent_occupation'] ?? '',
            'address'                => $row['address'] ?? '',
            'city'                   => $row['city'] ?? '',
            'emergency_name'         => $row['emergency_name'] ?? '',
            'emergency_phone'        => $row['emergency_phone'] ?? '',
            'emergency_relationship' => $row['emergency_relationship'] ?? '',
            'documents'              => $docs,
            'payment_method'         => $row['payment_method'] ?? 'paystack_card',
            'payment_method_label'   => self::formatPaymentMethod($row['payment_method'] ?? 'paystack_card'),
            'payment_reference'      => !empty($row['payment_reference']) ? $row['payment_reference'] : ('PSTK-STB-' . strtoupper(substr(md5($appNo), 0, 8))),
            'receipt_number'         => !empty($row['receipt_number']) ? $row['receipt_number'] : ('RCP-APP-' . strtoupper(substr(md5('rcp' . $appNo), 0, 8))),
            'amount_paid'            => (float)($row['amount_paid'] ?? self::APPLICATION_FEE),
            'payment_status'         => $row['payment_status'] ?? 'paid',
            'receipt_token'          => !empty($row['receipt_token']) ? $row['receipt_token'] : self::generateReceiptToken($appNo, $email),
            'paid_at'                => $row['paid_at'] ?? $createdAt,
            'status'                 => $row['status'] ?? 'pending',
            'created_at'             => $createdAt
        ];
    }

    /**
     * Update payment status across `application_payments`, `admissions`, and `applications`.
     */
    /**
     * Check if a payment reference has been recorded as paid or verified.
     */
    public static function isPaymentVerified(string $reference): bool
    {
        self::ensureSchema();
        $reference = trim($reference);
        if ($reference === '') {
            return false;
        }
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT payment_status FROM application_payments WHERE payment_reference = ? LIMIT 1");
            $stmt->execute([$reference]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && in_array(strtolower((string)$row['payment_status']), ['verified', 'paid'], true)) {
                return true;
            }
        } catch (Throwable $e) {
            error_log("ApplicationHelper::isPaymentVerified error: " . $e->getMessage());
        }
        return false;
    }

    /**
     * Confirm school-received payment for an application slip brought to the school.
     */
    public static function confirmSchoolPayment(string $appNumber, string $receiptNumber, string $channel = 'cash_at_school', ?int $adminUserId = null): bool
    {
        self::ensureSchema();
        $appNumber = trim($appNumber);
        if ($appNumber === '') {
            return false;
        }
        try {
            $db = Database::getInstance()->getConnection();
            $now = date('Y-m-d H:i:s');

            // Update applications
            $stmt1 = $db->prepare("UPDATE applications SET payment_status = 'paid', payment_method = 'pay_at_school', receipt_number = COALESCE(NULLIF(receipt_number, ''), ?), school_payment_confirmed_by = ?, school_payment_confirmed_at = ?, school_payment_channel = ?, paid_at = COALESCE(paid_at, ?) WHERE application_number = ?");
            $stmt1->execute([$receiptNumber, $adminUserId, $now, $channel, $now, $appNumber]);

            // Update admissions
            $stmt2 = $db->prepare("UPDATE admissions SET payment_status = 'paid', payment_method = 'pay_at_school', receipt_number = COALESCE(NULLIF(receipt_number, ''), ?), school_payment_confirmed_by = ?, school_payment_confirmed_at = ?, school_payment_channel = ?, paid_at = COALESCE(paid_at, ?) WHERE application_number = ?");
            $stmt2->execute([$receiptNumber, $adminUserId, $now, $channel, $now, $appNumber]);

            // Update application_payments ledger
            $stmt3 = $db->prepare("UPDATE application_payments SET payment_status = 'verified', payment_channel = ?, gateway_response = 'Confirmed by Admissions/Bursary at School', verified_by = ?, paid_at = COALESCE(paid_at, ?) WHERE application_number = ?");
            $stmt3->execute([$channel, $adminUserId, $now, $appNumber]);

            return true;
        } catch (Throwable $e) {
            error_log("ApplicationHelper::confirmSchoolPayment error: " . $e->getMessage());
            return false;
        }
    }

    public static function updatePaymentStatus(string $reference, string $newStatus, ?int $adminUserId = null): bool
    {
        self::ensureSchema();
        $reference = trim($reference);
        if ($reference === '') {
            return false;
        }
        $allowed = ['verified', 'paid', 'pending_verification', 'pending', 'failed', 'refunded'];
        if (!in_array($newStatus, $allowed, true)) {
            return false;
        }
        try {
            $db = Database::getInstance()->getConnection();
            $paidAt = in_array($newStatus, ['verified', 'paid'], true) ? date('Y-m-d H:i:s') : null;
            $note = in_array($newStatus, ['verified', 'paid'], true) ? 'Verified by Admin at Backend' : 'Status updated by Admin: ' . $newStatus;

            $stmt = $db->prepare("UPDATE application_payments SET payment_status = ?, gateway_response = ?, verified_by = ?, paid_at = COALESCE(paid_at, ?) WHERE payment_reference = ? OR application_number = ?");
            $stmt->execute([$newStatus, $note, $adminUserId, $paidAt, $reference, $reference]);

            $stmt2 = $db->prepare("UPDATE admissions SET payment_status = ?, paid_at = COALESCE(paid_at, ?) WHERE payment_reference = ? OR application_number = ?");
            $stmt2->execute([$newStatus, $paidAt, $reference, $reference]);

            $stmt3 = $db->prepare("UPDATE applications SET payment_status = ?, paid_at = COALESCE(paid_at, ?) WHERE payment_reference = ? OR application_number = ?");
            $stmt3->execute([$newStatus, $paidAt, $reference, $reference]);

            return true;
        } catch (Throwable $e) {
            error_log("ApplicationHelper::updatePaymentStatus error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Backfill any existing `admissions` and `applications` rows into `application_payments`
     * and return the complete unified payment ledger for the Admin Backend.
     */
    public static function getAllPaymentsLedger(int $limit = 200): array
    {
        self::ensureSchema();
        try {
            $db = Database::getInstance()->getConnection();

            // Backfill from `admissions`
            $admRows = $db->query("SELECT * FROM admissions ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($admRows as $r) {
                $norm = self::normalizeAdmissionRow($r, 'admissions');
                $stmt = $db->prepare("INSERT IGNORE INTO application_payments (
                    payment_reference, application_number, receipt_number, child_name, parent_name, parent_email, parent_phone,
                    amount, payment_method, payment_channel, payment_status, paid_at, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $norm['payment_reference'],
                    $norm['application_number'],
                    $norm['receipt_number'],
                    $norm['child_full_name'],
                    $norm['parent_name'],
                    $norm['parent_email'],
                    $norm['parent_phone'],
                    $norm['amount_paid'],
                    $norm['payment_method'],
                    $norm['payment_method'],
                    $norm['payment_status'],
                    $norm['paid_at'],
                    $norm['created_at']
                ]);
            }

            // Backfill from `applications`
            $appRows = $db->query("SELECT * FROM applications ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($appRows as $r) {
                $norm = self::normalizeAdmissionRow($r, 'applications');
                $stmt = $db->prepare("INSERT IGNORE INTO application_payments (
                    payment_reference, application_number, receipt_number, child_name, parent_name, parent_email, parent_phone,
                    amount, payment_method, payment_channel, payment_status, paid_at, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $norm['payment_reference'],
                    $norm['application_number'],
                    $norm['receipt_number'],
                    $norm['child_full_name'],
                    $norm['parent_name'],
                    $norm['parent_email'],
                    $norm['parent_phone'],
                    $norm['amount_paid'],
                    $norm['payment_method'],
                    $norm['payment_method'],
                    $norm['payment_status'],
                    $norm['paid_at'],
                    $norm['created_at']
                ]);
            }

            $stmtLedger = $db->prepare("SELECT * FROM application_payments ORDER BY id DESC LIMIT " . (int)$limit);
            $stmtLedger->execute();
            return $stmtLedger->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log("ApplicationHelper::getAllPaymentsLedger error: " . $e->getMessage());
            return [];
        }
    }
}
