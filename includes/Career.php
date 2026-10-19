<?php
// includes/Career.php - Job Vacancies and Career Applications Model

class Career {
    private static $tablesChecked = false;

    /**
     * Ensure database tables exist and seed default vacancies if empty.
     */
    public static function ensureTables() {
        if (self::$tablesChecked) return;
        self::$tablesChecked = true;

        try {
            $db = Database::getInstance();

            // Table: job_vacancies
            $db->query("CREATE TABLE IF NOT EXISTS job_vacancies (
                id INT PRIMARY KEY AUTO_INCREMENT,
                title VARCHAR(150) NOT NULL,
                department VARCHAR(100) NOT NULL,
                job_type VARCHAR(50) NOT NULL DEFAULT 'Full-time',
                location VARCHAR(100) NOT NULL DEFAULT 'Main Campus, Abuja',
                experience_level VARCHAR(50) NULL,
                description TEXT NOT NULL,
                requirements TEXT NULL,
                deadline DATE NULL,
                status ENUM('published', 'draft', 'closed') NOT NULL DEFAULT 'published',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_vacancy_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Table: career_applications
            $db->query("CREATE TABLE IF NOT EXISTS career_applications (
                id INT PRIMARY KEY AUTO_INCREMENT,
                vacancy_id INT NULL,
                reference_no VARCHAR(50) NOT NULL UNIQUE,
                full_name VARCHAR(100) NOT NULL,
                email VARCHAR(100) NOT NULL,
                phone VARCHAR(30) NOT NULL,
                position_applied VARCHAR(150) NOT NULL,
                qualification VARCHAR(100) NULL,
                experience_years VARCHAR(50) NULL,
                cover_letter TEXT NULL,
                resume_path VARCHAR(255) NOT NULL,
                additional_doc_path VARCHAR(255) NULL,
                status ENUM('pending', 'reviewing', 'shortlisted', 'interviewed', 'rejected', 'hired') NOT NULL DEFAULT 'pending',
                admin_notes TEXT NULL,
                reviewed_by INT NULL,
                reviewed_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_app_vacancy (vacancy_id),
                INDEX idx_app_status (status),
                INDEX idx_app_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Ensure storage/careers directory exists
            $careersStorage = PRIVATE_PATH . 'careers/';
            if (!is_dir($careersStorage)) {
                @mkdir($careersStorage, 0750, true);
            }

            // Seed initial vacancies if none exist
            $count = (int)$db->getRow("SELECT COUNT(*) AS c FROM job_vacancies")['c'];
            if ($count === 0) {
                $sampleVacancies = [
                    [
                        'Early Years / Nursery Lead Educator',
                        'Early Years / Academic',
                        'Full-time',
                        'Main Campus, Abuja',
                        '3+ Years',
                        "We are seeking an enthusiastic, passionate, and certified Early Years Educator with experience in the British Early Years Foundation Stage (EYFS) curriculum to nurture, guide, and inspire our pupils.",
                        "- Minimum of B.Ed or PGDE in Early Childhood Education or related field.\n- Demonstrated knowledge of the British EYFS framework.\n- Excellent communication and creative pedagogical skills.\n- Genuine love for early childhood development and child safety.",
                        date('Y-m-d', strtotime('+30 days')),
                        'published'
                    ],
                    [
                        'Primary Key Stage 1 / 2 Class Teacher',
                        'Academic',
                        'Full-time',
                        'Main Campus, Abuja',
                        '2+ Years',
                        "We are looking for a qualified Primary Teacher who can deliver inspiring lessons across English, Mathematics, and Science according to the British National Curriculum.",
                        "- Bachelor's Degree in Education (B.Ed) or relevant discipline with PGDE.\n- Solid understanding of Cambridge Primary / British Curriculum.\n- Strong classroom management and digital teaching tool proficiency.\n- Proven track record of student engagement and academic excellence.",
                        date('Y-m-d', strtotime('+45 days')),
                        'published'
                    ],
                    [
                        'Administrative & Admissions Officer',
                        'Administration',
                        'Full-time',
                        'Main Campus, Abuja',
                        '2+ Years',
                        "Support the school's front desk, admissions coordination, parent communication, and administrative operations with professional warmth and excellence.",
                        "- B.Sc or HND in Business Administration, Mass Communication, or related field.\n- Proficiency in Microsoft Office Suite and school management systems.\n- Exceptional interpersonal, written, and verbal communication skills.\n- High level of confidentiality, punctuality, and organization.",
                        date('Y-m-d', strtotime('+20 days')),
                        'published'
                    ]
                ];

                foreach ($sampleVacancies as $v) {
                    $db->query(
                        "INSERT INTO job_vacancies (title, department, job_type, location, experience_level, description, requirements, deadline, status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        $v
                    );
                }
            }
        } catch (Throwable $e) {
            error_log("Career::ensureTables Error: " . $e->getMessage());
        }
    }

    /**
     * Get vacancies.
     */
    public static function getVacancies($status = null) {
        self::ensureTables();
        $db = Database::getInstance();
        if ($status) {
            return $db->getRows(
                "SELECT v.*, (SELECT COUNT(*) FROM career_applications a WHERE a.vacancy_id = v.id) AS application_count
                 FROM job_vacancies v
                 WHERE v.status = ?
                 ORDER BY v.created_at DESC",
                [$status]
            );
        }
        return $db->getRows(
            "SELECT v.*, (SELECT COUNT(*) FROM career_applications a WHERE a.vacancy_id = v.id) AS application_count
             FROM job_vacancies v
             ORDER BY v.created_at DESC"
        );
    }

    /**
     * Get single vacancy by ID.
     */
    public static function getVacancy($id) {
        self::ensureTables();
        return Database::getInstance()->getRow("SELECT * FROM job_vacancies WHERE id = ?", [(int)$id]);
    }

    /**
     * Save vacancy (insert or update).
     */
    public static function saveVacancy($data, $id = null) {
        self::ensureTables();
        $db = Database::getInstance();

        $title = trim($data['title'] ?? '');
        $department = trim($data['department'] ?? 'General');
        $job_type = trim($data['job_type'] ?? 'Full-time');
        $location = trim($data['location'] ?? 'Main Campus, Abuja');
        $experience_level = trim($data['experience_level'] ?? '');
        $description = trim($data['description'] ?? '');
        $requirements = trim($data['requirements'] ?? '');
        $deadline = !empty($data['deadline']) ? $data['deadline'] : null;
        $status = in_array($data['status'] ?? '', ['published', 'draft', 'closed'], true) ? $data['status'] : 'published';

        if ($id) {
            $db->query(
                "UPDATE job_vacancies
                 SET title = ?, department = ?, job_type = ?, location = ?, experience_level = ?,
                     description = ?, requirements = ?, deadline = ?, status = ?
                 WHERE id = ?",
                [$title, $department, $job_type, $location, $experience_level, $description, $requirements, $deadline, $status, (int)$id]
            );
            return (int)$id;
        } else {
            return (int)$db->insert(
                "INSERT INTO job_vacancies (title, department, job_type, location, experience_level, description, requirements, deadline, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$title, $department, $job_type, $location, $experience_level, $description, $requirements, $deadline, $status]
            );
        }
    }

    /**
     * Delete vacancy.
     */
    public static function deleteVacancy($id) {
        self::ensureTables();
        return Database::getInstance()->query("DELETE FROM job_vacancies WHERE id = ?", [(int)$id]);
    }

    /**
     * Get applications list with optional filters.
     */
    public static function getApplications($vacancyId = null, $status = null, $search = null) {
        self::ensureTables();
        $db = Database::getInstance();

        $where = [];
        $params = [];

        if ($vacancyId !== null && $vacancyId !== '') {
            $where[] = "a.vacancy_id = ?";
            $params[] = (int)$vacancyId;
        }

        if ($status !== null && $status !== '') {
            $where[] = "a.status = ?";
            $params[] = $status;
        }

        if ($search !== null && trim($search) !== '') {
            $s = '%' . trim($search) . '%';
            $where[] = "(a.full_name LIKE ? OR a.email LIKE ? OR a.phone LIKE ? OR a.reference_no LIKE ? OR a.position_applied LIKE ?)";
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
        }

        $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

        return $db->getRows(
            "SELECT a.*, v.title AS vacancy_title, v.department AS vacancy_department,
                    u.first_name AS reviewer_first_name, u.last_name AS reviewer_last_name
             FROM career_applications a
             LEFT JOIN job_vacancies v ON a.vacancy_id = v.id
             LEFT JOIN users u ON a.reviewed_by = u.id
             $whereSql
             ORDER BY a.created_at DESC",
            $params
        );
    }

    /**
     * Get single application by ID.
     */
    public static function getApplication($id) {
        self::ensureTables();
        return Database::getInstance()->getRow(
            "SELECT a.*, v.title AS vacancy_title, v.department AS vacancy_department,
                    u.first_name AS reviewer_first_name, u.last_name AS reviewer_last_name
             FROM career_applications a
             LEFT JOIN job_vacancies v ON a.vacancy_id = v.id
             LEFT JOIN users u ON a.reviewed_by = u.id
             WHERE a.id = ?",
            [(int)$id]
        );
    }

    /**
     * Generate unique application reference.
     */
    public static function generateReference() {
        self::ensureTables();
        $db = Database::getInstance();
        $prefix = 'STB-JOB-' . date('Y') . '-';
        for ($i = 0; $i < 20; $i++) {
            $cand = $prefix . str_pad((string)random_int(1001, 9999), 4, '0', STR_PAD_LEFT);
            if (!$db->getRow("SELECT 1 FROM career_applications WHERE reference_no = ?", [$cand])) {
                return $cand;
            }
        }
        return $prefix . strtoupper(bin2hex(random_bytes(2)));
    }

    /**
     * Submit an application.
     */
    public static function submitApplication($data, $files) {
        self::ensureTables();
        $db = Database::getInstance();

        $errors = [];

        $fullName = trim(Security::sanitize($data['full_name'] ?? ''));
        $email = trim(Security::sanitize($data['email'] ?? ''));
        $phone = trim(Security::sanitize($data['phone'] ?? ''));
        $vacancyId = !empty($data['vacancy_id']) ? (int)$data['vacancy_id'] : null;
        $positionApplied = trim(Security::sanitize($data['position_applied'] ?? ''));
        $qualification = trim(Security::sanitize($data['qualification'] ?? ''));
        $experienceYears = trim(Security::sanitize($data['experience_years'] ?? ''));
        $coverLetter = trim(Security::sanitize($data['cover_letter'] ?? ''));

        if ($fullName === '') $errors[] = "Full Name is required.";
        if (!Security::validateEmail($email)) $errors[] = "A valid email address is required.";
        if ($phone === '' || !preg_match('/^[0-9+\s\-()]{7,25}$/', $phone)) {
            $errors[] = "A valid phone number is required.";
        }

        if ($vacancyId) {
            $vac = self::getVacancy($vacancyId);
            if ($vac) {
                $positionApplied = $vac['title'];
            }
        }

        if ($positionApplied === '') {
            $positionApplied = 'General Application';
        }

        // Validate CV upload (required)
        if (!isset($files['resume']) || $files['resume']['error'] === UPLOAD_ERR_NO_FILE) {
            $errors[] = "Please upload your Curriculum Vitae (CV) / Resume.";
        }

        if ($errors) {
            return ['success' => false, 'errors' => $errors];
        }

        $careersStorage = PRIVATE_PATH . 'careers/';
        if (!is_dir($careersStorage)) {
            @mkdir($careersStorage, 0750, true);
        }

        // Handle Resume Upload
        $resumeCheck = Security::validateFileUpload($files['resume'], ['pdf', 'doc', 'docx']);
        if (!$resumeCheck['valid']) {
            return ['success' => false, 'errors' => ["Resume: " . $resumeCheck['message']]];
        }

        $resumeFileName = 'resume_' . bin2hex(random_bytes(16)) . '.' . $resumeCheck['extension'];
        $resumeDest = $careersStorage . $resumeFileName;

        if (!move_uploaded_file($files['resume']['tmp_name'], $resumeDest)) {
            return ['success' => false, 'errors' => ["Failed to securely save uploaded resume. Please try again."]];
        }
        @chmod($resumeDest, 0640);

        // Handle Additional Document Upload (optional)
        $additionalDocFileName = null;
        if (isset($files['additional_doc']) && $files['additional_doc']['error'] !== UPLOAD_ERR_NO_FILE) {
            $docCheck = Security::validateFileUpload($files['additional_doc'], ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png']);
            if (!$docCheck['valid']) {
                // Remove uploaded resume to avoid orphaned files
                @unlink($resumeDest);
                return ['success' => false, 'errors' => ["Additional Document: " . $docCheck['message']]];
            }
            $additionalDocFileName = 'doc_' . bin2hex(random_bytes(16)) . '.' . $docCheck['extension'];
            $docDest = $careersStorage . $additionalDocFileName;
            if (move_uploaded_file($files['additional_doc']['tmp_name'], $docDest)) {
                @chmod($docDest, 0640);
            }
        }

        $refNo = self::generateReference();

        $appId = $db->insert(
            "INSERT INTO career_applications
             (vacancy_id, reference_no, full_name, email, phone, position_applied, qualification, experience_years, cover_letter, resume_path, additional_doc_path, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
            [
                $vacancyId,
                $refNo,
                $fullName,
                $email,
                $phone,
                $positionApplied,
                $qualification,
                $experienceYears,
                $coverLetter,
                $resumeFileName,
                $additionalDocFileName
            ]
        );

        return [
            'success' => true,
            'application_id' => $appId,
            'reference_no' => $refNo,
            'position' => $positionApplied
        ];
    }

    /**
     * Update application status and admin notes.
     */
    public static function updateApplicationStatus($id, $status, $notes, $adminId) {
        self::ensureTables();
        $validStatuses = ['pending', 'reviewing', 'shortlisted', 'interviewed', 'rejected', 'hired'];
        if (!in_array($status, $validStatuses, true)) {
            return false;
        }

        $db = Database::getInstance();
        $db->query(
            "UPDATE career_applications
             SET status = ?, admin_notes = ?, reviewed_by = ?, reviewed_at = NOW()
             WHERE id = ?",
            [$status, trim((string)$notes), (int)$adminId, (int)$id]
        );

        if (class_exists('Security')) {
            Security::logAudit('UPDATED_CAREER_APPLICATION', 'career_applications', $id, null, [
                'status' => $status,
                'notes_length' => strlen($notes)
            ]);
        }

        return true;
    }

    /**
     * Count pending applications.
     */
    public static function pendingCount() {
        self::ensureTables();
        try {
            $row = Database::getInstance()->getRow("SELECT COUNT(*) AS c FROM career_applications WHERE status = 'pending'");
            return (int)($row['c'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }
}
