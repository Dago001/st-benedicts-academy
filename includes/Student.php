<?php
// includes/Student.php - Student Model Class

require_once __DIR__ . '/../config/database.php';

class Student {
    private $db;
    private $id;
    private $data;

    /**
     * Constructor
     * @param int|null $id Student ID
     */
    public function __construct($id = null) {
        $this->db = Database::getInstance();
        if ($id) {
            $this->find($id);
        }
    }

    /**
     * Find student by ID
     * @param int $id Student ID
     * @return array|false Student data or false
     */
    public function find($id) {
        $this->data = $this->db->getRow(
            "SELECT s.*, u.first_name, u.last_name, u.email, u.phone, u.profile_image,
                    u.username, u.is_active,
                    c.class_name, c.section, c.academic_year,
                    CONCAT(pu.first_name, ' ', pu.last_name) as parent_name,
                    pu.email as parent_email, pu.phone as parent_phone
             FROM students s
             JOIN users u ON s.user_id = u.id
             LEFT JOIN classes c ON s.class_id = c.id
             LEFT JOIN parents p ON s.parent_id = p.id
             LEFT JOIN users pu ON p.user_id = pu.id
             WHERE s.id = ? AND u.deleted_at IS NULL",
            [$id]
        );

        if ($this->data) {
            $this->id = $id;
        }

        return $this->data;
    }

    /**
     * Get student by user ID
     * @param int $userId User ID
     * @return array|false Student data or false
     */
    public function findByUserId($userId) {
        return $this->db->getRow(
            "SELECT s.* FROM students s WHERE s.user_id = ?",
            [$userId]
        );
    }

    /**
     * Get all students with optional filters
     * @param array $filters Optional filters (class_id, status, search)
     * @return array List of students
     */
    public function getAll($filters = []) {
        $sql = "SELECT s.*, u.first_name, u.last_name, u.email, u.phone, u.profile_image,
                       u.is_active, u.username,
                       c.class_name, c.section,
                       CONCAT(pu.first_name, ' ', pu.last_name) as parent_name
                FROM students s
                JOIN users u ON s.user_id = u.id
                LEFT JOIN classes c ON s.class_id = c.id
                LEFT JOIN parents p ON s.parent_id = p.id
                LEFT JOIN users pu ON p.user_id = pu.id
                WHERE u.deleted_at IS NULL";

        $params = [];

        if (!empty($filters['class_id'])) {
            $sql .= " AND s.class_id = ?";
            $params[] = $filters['class_id'];
        }

        if (isset($filters['is_active'])) {
            $sql .= " AND u.is_active = ?";
            $params[] = $filters['is_active'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR s.admission_number LIKE ? OR u.email LIKE ?)";
            $search = "%{$filters['search']}%";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $sql .= " ORDER BY u.first_name, u.last_name";

        return $this->db->getRows($sql, $params);
    }

    /**
     * Get students by class
     * @param int $classId Class ID
     * @return array List of students
     */
    public function getByClass($classId) {
        return $this->db->getRows(
            "SELECT s.*, u.first_name, u.last_name, u.email, u.phone, u.profile_image,
                    s.admission_number
             FROM students s
             JOIN users u ON s.user_id = u.id
             WHERE s.class_id = ? AND u.is_active = 1 AND u.deleted_at IS NULL
             ORDER BY u.first_name, u.last_name",
            [$classId]
        );
    }

    /**
     * Create new student
     * @param array $data Student data
     * @return int|false New student ID or false
     */
    public function create($data) {
        try {
            $this->db->beginTransaction();

            // Create user first
            $userData = [
                'username' => $data['username'],
                'email' => $data['email'],
                'password_hash' => Security::hashPassword($data['password']),
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone' => $data['phone'] ?? null,
                'role' => 'student',
                'is_active' => $data['is_active'] ?? 1
            ];

            $userId = $this->db->insert(
                "INSERT INTO users (username, email, password_hash, first_name, last_name, phone, role, is_active)
                 VALUES (:username, :email, :password_hash, :first_name, :last_name, :phone, :role, :is_active)",
                $userData
            );

            if (!$userId) {
                throw new Exception("Failed to create user");
            }

            // Create student record
            $studentData = [
                'user_id' => $userId,
                'admission_number' => $data['admission_number'] ?? $this->generateAdmissionNumber(),
                'class_id' => $data['class_id'] ?? null,
                'parent_id' => $data['parent_id'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'gender' => $data['gender'] ?? null,
                'admission_date' => $data['admission_date'] ?? date('Y-m-d'),
                'address' => $data['address'] ?? null,
                'blood_group' => $data['blood_group'] ?? null,
                'medical_notes' => $data['medical_notes'] ?? null
            ];

            $studentId = $this->db->insert(
                "INSERT INTO students (user_id, admission_number, class_id, parent_id, date_of_birth,
                 gender, admission_date, address, blood_group, medical_notes)
                 VALUES (:user_id, :admission_number, :class_id, :parent_id, :date_of_birth,
                 :gender, :admission_date, :address, :blood_group, :medical_notes)",
                $studentData
            );

            $this->db->commit();

            Security::logAudit('CREATED_STUDENT', 'students', $studentId, null, $data);

            return $studentId;

        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Error creating student: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update student
     * @param int $id Student ID
     * @param array $data Updated data
     * @return bool Success or failure
     */
    public function update($id, $data) {
        try {
            $student = $this->find($id);
            if (!$student) {
                throw new Exception("Student not found");
            }

            $this->db->beginTransaction();

            // Update user data
            $userUpdates = [];
            $userParams = [];

            $userFields = ['first_name', 'last_name', 'email', 'phone'];
            foreach ($userFields as $field) {
                if (isset($data[$field])) {
                    $userUpdates[] = "$field = ?";
                    $userParams[] = $data[$field];
                }
            }

            if (isset($data['password']) && !empty($data['password'])) {
                $userUpdates[] = "password_hash = ?";
                $userParams[] = Security::hashPassword($data['password']);
            }

            if (isset($data['is_active'])) {
                $userUpdates[] = "is_active = ?";
                $userParams[] = $data['is_active'];
            }

            if (!empty($userUpdates)) {
                $userParams[] = $student['user_id'];
                $this->db->query(
                    "UPDATE users SET " . implode(', ', $userUpdates) . " WHERE id = ?",
                    $userParams
                );
            }

            // Update student data
            $studentUpdates = [];
            $studentParams = [];

            $studentFields = ['class_id', 'parent_id', 'date_of_birth', 'gender',
                             'admission_date', 'address', 'blood_group', 'medical_notes'];
            foreach ($studentFields as $field) {
                if (isset($data[$field])) {
                    $studentUpdates[] = "$field = ?";
                    $studentParams[] = $data[$field];
                }
            }

            if (!empty($studentUpdates)) {
                $studentParams[] = $id;
                $this->db->query(
                    "UPDATE students SET " . implode(', ', $studentUpdates) . " WHERE id = ?",
                    $studentParams
                );
            }

            $this->db->commit();

            Security::logAudit('UPDATED_STUDENT', 'students', $id, $student, $data);

            return true;

        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Error updating student: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete student (soft delete)
     * @param int $id Student ID
     * @return bool Success or failure
     */
    public function delete($id) {
        try {
            $student = $this->find($id);
            if (!$student) {
                throw new Exception("Student not found");
            }

            // Soft delete user
            $this->db->query(
                "UPDATE users SET deleted_at = NOW() WHERE id = ?",
                [$student['user_id']]
            );

            Security::logAudit('DELETED_STUDENT', 'students', $id, $student);

            return true;

        } catch (Exception $e) {
            error_log("Error deleting student: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Promote student to next class
     * @param int $id Student ID
     * @param int $newClassId New class ID
     * @param string $newAcademicYear New academic year
     * @return bool Success or failure
     */
    public function promote($id, $newClassId, $newAcademicYear) {
        try {
            $student = $this->find($id);
            if (!$student) {
                throw new Exception("Student not found");
            }

            $oldClassId = $student['class_id'];

            $this->db->query(
                "UPDATE students SET class_id = ? WHERE id = ?",
                [$newClassId, $id]
            );

            Security::logAudit('PROMOTED_STUDENT', 'students', $id,
                              ['class_id' => $oldClassId],
                              ['class_id' => $newClassId, 'academic_year' => $newAcademicYear]);

            return true;

        } catch (Exception $e) {
            error_log("Error promoting student: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get student's attendance record
     * @param int $id Student ID
     * @param string $startDate Start date
     * @param string $endDate End date
     * @return array Attendance records
     */
    public function getAttendance($id, $startDate = null, $endDate = null) {
        $sql = "SELECT a.*, c.class_name
                FROM attendance a
                JOIN classes c ON a.class_id = c.id
                WHERE a.student_id = ?";
        $params = [$id];

        if ($startDate) {
            $sql .= " AND a.date >= ?";
            $params[] = $startDate;
        }

        if ($endDate) {
            $sql .= " AND a.date <= ?";
            $params[] = $endDate;
        }

        $sql .= " ORDER BY a.date DESC";

        return $this->db->getRows($sql, $params);
    }

    /**
     * Get student's results
     * @param int $id Student ID
     * @param string $term Term
     * @param string $academicYear Academic year
     * @return array Results
     */
    public function getResults($id, $term = null, $academicYear = null) {
        $sql = "SELECT r.*, s.subject_name
                FROM results r
                JOIN subjects s ON r.subject_id = s.id
                WHERE r.student_id = ?";
        $params = [$id];

        if ($term) {
            $sql .= " AND r.term = ?";
            $params[] = $term;
        }

        if ($academicYear) {
            $sql .= " AND r.academic_year = ?";
            $params[] = $academicYear;
        }

        $sql .= " ORDER BY r.academic_year DESC, r.term DESC, s.subject_name";

        return $this->db->getRows($sql, $params);
    }

    /**
     * Get student's fee payments
     * @param int $id Student ID
     * @param string $academicYear Academic year
     * @return array Payments
     */
    public function getPayments($id, $academicYear = null) {
        $sql = "SELECT p.* FROM payments p WHERE p.student_id = ?";
        $params = [$id];

        if ($academicYear) {
            $sql .= " AND p.academic_year = ?";
            $params[] = $academicYear;
        }

        $sql .= " ORDER BY p.payment_date DESC";

        return $this->db->getRows($sql, $params);
    }

    /**
     * Get student's fee summary
     * @param int $id Student ID
     * @param string $academicYear Academic year
     * @return array Fee summary
     */
    public function getFeeSummary($id, $academicYear = null) {
        $student = $this->find($id);
        if (!$student || !$student['class_id']) {
            return [
                'total_fees' => 0,
                'total_paid' => 0,
                'balance' => 0,
                'pending' => 0
            ];
        }

        if (!$academicYear) {
            $academicYear = currentAcademicYear();
        }

        // Get fee structure
        $feeStructure = $this->db->getRows(
            "SELECT * FROM fee_structure WHERE class_id = ? AND academic_year = ?",
            [$student['class_id'], $academicYear]
        );

        // Get payments
        $payments = $this->db->getRows(
            "SELECT * FROM payments WHERE student_id = ? AND academic_year = ?",
            [$id, $academicYear]
        );

        $totalFees = 0;
        $totalPaid = 0;
        $pendingAmount = 0;

        foreach ($feeStructure as $fee) {
            $totalFees += $fee['amount'];
        }

        foreach ($payments as $payment) {
            if ($payment['status'] === 'completed') {
                $totalPaid += $payment['amount'];
            } elseif ($payment['status'] === 'pending') {
                $pendingAmount += $payment['amount'];
            }
        }

        return [
            'total_fees' => $totalFees,
            'total_paid' => $totalPaid,
            'balance' => $totalFees - $totalPaid,
            'pending' => $pendingAmount,
            'fee_structure' => $feeStructure,
            'payments' => $payments
        ];
    }

    /**
     * Generate unique admission number
     * @return string Admission number
     */
    private function generateAdmissionNumber() {
        $year = date('Y');
        $random = str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $admission = "STB/{$year}/{$random}";

        // Check if exists
        $exists = $this->db->getRow(
            "SELECT id FROM students WHERE admission_number = ?",
            [$admission]
        );

        if ($exists) {
            return $this->generateAdmissionNumber();
        }

        return $admission;
    }

    /**
     * Get student count
     * @param array $filters Optional filters
     * @return int Count
     */
    public function getCount($filters = []) {
        $sql = "SELECT COUNT(*) as count FROM students s
                JOIN users u ON s.user_id = u.id
                WHERE u.deleted_at IS NULL";
        $params = [];

        if (!empty($filters['class_id'])) {
            $sql .= " AND s.class_id = ?";
            $params[] = $filters['class_id'];
        }

        if (isset($filters['is_active'])) {
            $sql .= " AND u.is_active = ?";
            $params[] = $filters['is_active'];
        }

        $result = $this->db->getRow($sql, $params);
        return $result['count'] ?? 0;
    }

    /**
     * Get current instance data
     * @return array|null Student data
     */
    public function getData() {
        return $this->data;
    }

    /**
     * Get student ID
     * @return int|null
     */
    public function getId() {
        return $this->id;
    }

    /**
     * Get full name
     * @return string
     */
    public function getFullName() {
        return ($this->data['first_name'] ?? '') . ' ' . ($this->data['last_name'] ?? '');
    }
}