<?php
/**
 * ApplicationHelper
 * Unified schema management, sequential document catalog, payment processing,
 * and receipt lookup for Online Admissions (`admissions` and `applications` tables).
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
            'passport_photo' => [
                'key'      => 'passport_photo',
                'label'    => 'Child\'s Passport Photograph (Required)',
                'short'    => 'Passport Photograph',
                'required' => true,
                'allowed'  => ['jpg', 'jpeg', 'png'],
                'accept'   => '.jpg,.jpeg,.png',
                'hint'     => 'Recent clear passport-sized photograph of the child (JPG, PNG — Max 5MB)'
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
     * Self-healing schema migration for both `admissions` and `applications` tables.
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
                'payment_method'         => "VARCHAR(50) DEFAULT 'online_card'",
                'payment_reference'      => "VARCHAR(100) DEFAULT NULL",
                'receipt_number'         => "VARCHAR(60) DEFAULT NULL",
                'amount_paid'            => "DECIMAL(10,2) DEFAULT 15000.00",
                'payment_status'         => "VARCHAR(30) DEFAULT 'paid'",
                'receipt_token'          => "VARCHAR(64) DEFAULT NULL",
                'paid_at'                => "DATETIME DEFAULT NULL"
            ];

            self::addMissingColumns($db, 'admissions', $commonExtraColumns);
            self::addMissingColumns($db, 'applications', $commonExtraColumns);
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

    public static function generatePaymentReference(string $prefix = 'TXN-STB'): string
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
            'paystack_ussd'     => 'Paystack USSD Instant Channel',
            'online_card'       => 'Paystack Online Card Payment (Debit / Credit Card)',
            'online_transfer'   => 'Paystack Instant Bank Transfer',
            'online_ussd'       => 'Paystack USSD Instant Channel',
            'bank_transfer'     => 'Direct Bank Transfer (' . SCHOOL_BANK_NAME . ' - ' . SCHOOL_ACCOUNT_NUMBER . ')'
        ];
        return $map[$method ?? 'paystack_card'] ?? ucwords(str_replace('_', ' ', (string)$method));
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
            'payment_method'         => $row['payment_method'] ?? 'online_card',
            'payment_method_label'   => self::formatPaymentMethod($row['payment_method'] ?? 'online_card'),
            'payment_reference'      => !empty($row['payment_reference']) ? $row['payment_reference'] : ('TXN-STB-' . strtoupper(substr(md5($appNo), 0, 8))),
            'receipt_number'         => !empty($row['receipt_number']) ? $row['receipt_number'] : ('RCP-APP-' . strtoupper(substr(md5('rcp' . $appNo), 0, 8))),
            'amount_paid'            => (float)($row['amount_paid'] ?? self::APPLICATION_FEE),
            'payment_status'         => $row['payment_status'] ?? 'paid',
            'receipt_token'          => !empty($row['receipt_token']) ? $row['receipt_token'] : self::generateReceiptToken($appNo, $email),
            'paid_at'                => $row['paid_at'] ?? $createdAt,
            'status'                 => $row['status'] ?? 'pending',
            'created_at'             => $createdAt
        ];
    }
}
