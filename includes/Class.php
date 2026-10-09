<?php
// includes/Class.php - Class Model Class

require_once __DIR__ . '/../config/database.php';

class ClassModel {
    private $db;
    private $id;
    private $data;

    /**
     * Constructor
     * @param int|null $id Class ID
     */
    public function __construct($id = null) {
        $this->db = Database::getInstance();
        if ($id) {
            $this->find($id);
        }
    }

    /**
     * Find class by ID
     * @param int $id Class ID
     * @return array|false Class data or false
     */
    public function find($id) {
        $this->data = $this->db->getRow(
            "SELECT c.*,
                    CONCAT(u.first_name, ' ', u.last_name) as teacher_name
             FROM classes c
             LEFT JOIN teachers t ON c.teacher_id = t.id
             LEFT JOIN users u ON t.user_id = u.id
             WHERE c.id = ?",
            [$id]
        );

        if ($this->data) {
            $this->id = $id;
        }

        return $this->data;
    }

    /**
     * Get all classes with optional filters
     * @param array $filters Optional filters
     * @return array List of classes
     */
    public function getAll($filters = []) {
        $sql = "SELECT c.*,
                       CONCAT(u.first_name, ' ', u.last_name) as teacher_name,
                       (SELECT COUNT(*) FROM students WHERE class_id = c.id) as student_count,
                       (SELECT COUNT(*) FROM subjects WHERE class_id = c.id) as subject_count
                FROM classes c
                LEFT JOIN teachers t ON c.teacher_id = t.id
                LEFT JOIN users u ON t.user_id = u.id
                WHERE 1=1";

        $params = [];

        if (!empty($filters['academic_year'])) {
            $sql .= " AND c.academic_year = ?";
            $params[] = $filters['academic_year'];
        }

        if (isset($filters['is_active'])) {
            $sql .= " AND c.is_active = ?";
            $params[] = $filters['is_active'];
        }

        if (!empty($filters['teacher_id'])) {
            $sql .= " AND c.teacher_id = ?";
            $params[] = $filters['teacher_id'];
        }

        $sql .= " ORDER BY c.class_name, c.section";

        return $this->db->getRows($sql, $params);
    }

    /**
     * Get active classes
     * @return array List of active classes
     */
    public function getActive() {
        return $this->db->getRows(
            "SELECT * FROM classes WHERE is_active = 1 ORDER BY class_name"
        );
    }

    /**
     * Get classes by teacher
     * @param int $teacherId Teacher ID
     * @return array List of classes
     */
    public function getByTeacher($teacherId) {
        return $this->db->getRows(
            "SELECT * FROM classes WHERE teacher_id = ? AND is_active = 1 ORDER BY class_name",
            [$teacherId]
        );
    }

    /**
     * Create new class
     * @param array $data Class data
     * @return int|false New class ID or false
     */
    public function create($data) {
        try {
            // Validate academic year format
            if (!preg_match('/^\d{4}-\d{4}$/', $data['academic_year'])) {
                throw new Exception("Invalid academic year format");
            }

            $classId = $this->db->insert(
                "INSERT INTO classes (class_name, section, academic_year, teacher_id, capacity, is_active)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $data['class_name'],
                    $data['section'] ?? 'A',
                    $data['academic_year'],
                    $data['teacher_id'] ?? null,
                    $data['capacity'] ?? 30,
                    $data['is_active'] ?? 1
                ]
            );

            Security::logAudit('CREATED_CLASS', 'classes', $classId, null, $data);

            return $classId;

        } catch (Exception $e) {
            error_log("Error creating class: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update class
     * @param int $id Class ID
     * @param array $data Updated data
     * @return bool Success or failure
     */
    public function update($id, $data) {
        try {
            $class = $this->find($id);
            if (!$class) {
                throw new Exception("Class not found");
            }

            $this->db->query(
                "UPDATE classes SET class_name = ?, section = ?, academic_year = ?,
                 teacher_id = ?, capacity = ?, is_active = ? WHERE id = ?",
                [
                    $data['class_name'] ?? $class['class_name'],
                    $data['section'] ?? $class['section'],
                    $data['academic_year'] ?? $class['academic_year'],
                    $data['teacher_id'] ?? $class['teacher_id'],
                    $data['capacity'] ?? $class['capacity'],
                    $data['is_active'] ?? $class['is_active'],
                    $id
                ]
            );

            Security::logAudit('UPDATED_CLASS', 'classes', $id, $class, $data);

            return true;

        } catch (Exception $e) {
            error_log("Error updating class: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete class
     * @param int $id Class ID
     * @return bool Success or failure
     */
    public function delete($id) {
        try {
            $class = $this->find($id);
            if (!$class) {
                throw new Exception("Class not found");
            }

            // Check if class has students
            $studentCount = $this->db->getRow(
                "SELECT COUNT(*) as count FROM students WHERE class_id = ?",
                [$id]
            )['count'];

            if ($studentCount > 0) {
                throw new Exception("Cannot delete class with enrolled students");
            }

            $this->db->query("DELETE FROM classes WHERE id = ?", [$id]);

            Security::logAudit('DELETED_CLASS', 'classes', $id, $class);

            return true;

        } catch (Exception $e) {
            error_log("Error deleting class: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get students in class
     * @param int $id Class ID
     * @return array List of students
     */
    public function getStudents($id = null) {
        $classId = $id ?: $this->id;
        if (!$classId) {
            return [];
        }

        return $this->db->getRows(
            "SELECT s.*, u.first_name, u.last_name, u.email, u.phone,
                    s.admission_number, s.date_of_birth, s.gender
             FROM students s
             JOIN users u ON s.user_id = u.id
             WHERE s.class_id = ? AND u.is_active = 1 AND u.deleted_at IS NULL
             ORDER BY u.first_name, u.last_name",
            [$classId]
        );
    }

    /**
     * Get subjects in class
     * @param int $id Class ID
     * @return array List of subjects
     */
    public function getSubjects($id = null) {
        $classId = $id ?: $this->id;
        if (!$classId) {
            return [];
        }

        return $this->db->getRows(
            "SELECT s.*,
                    CONCAT(u.first_name, ' ', u.last_name) as teacher_name
             FROM subjects s
             LEFT JOIN teachers t ON s.teacher_id = t.id
             LEFT JOIN users u ON t.user_id = u.id
             WHERE s.class_id = ? AND s.is_active = 1
             ORDER BY s.subject_name",
            [$classId]
        );
    }

    /**
     * Get class timetable
     * @param int $id Class ID
     * @return array Timetable
     */
    public function getTimetable($id = null) {
        $classId = $id ?: $this->id;
        if (!$classId) {
            return [];
        }

        return $this->db->getRows(
            "SELECT tt.*, s.subject_name,
                    CONCAT(u.first_name, ' ', u.last_name) as teacher_name
             FROM time_table tt
             JOIN subjects s ON tt.subject_id = s.id
             LEFT JOIN teachers t ON tt.teacher_id = t.id
             LEFT JOIN users u ON t.user_id = u.id
             WHERE tt.class_id = ?
             ORDER BY FIELD(tt.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'),
                      tt.start_time",
            [$classId]
        );
    }

    /**
     * Get class attendance for a date
     * @param int $classId Class ID
     * @param string $date Date (Y-m-d)
     * @return array Attendance records
     */
    public function getAttendance($classId, $date) {
        return $this->db->getRows(
            "SELECT a.*, u.first_name, u.last_name, s.admission_number
             FROM attendance a
             JOIN students s ON a.student_id = s.id
             JOIN users u ON s.user_id = u.id
             WHERE a.class_id = ? AND a.date = ?
             ORDER BY u.first_name",
            [$classId, $date]
        );
    }

    /**
     * Get class statistics
     * @param int $id Class ID
     * @return array Statistics
     */
    public function getStatistics($id = null) {
        $classId = $id ?: $this->id;
        if (!$classId) {
            return [];
        }

        $stats = [];

        // Student count by gender
        $genderStats = $this->db->getRows(
            "SELECT s.gender, COUNT(*) as count
             FROM students s
             WHERE s.class_id = ?
             GROUP BY s.gender",
            [$classId]
        );

        $stats['gender'] = $genderStats;

        // Average age
        $ageStats = $this->db->getRow(
            "SELECT AVG(TIMESTAMPDIFF(YEAR, s.date_of_birth, CURDATE())) as avg_age
             FROM students s
             WHERE s.class_id = ?",
            [$classId]
        );

        $stats['average_age'] = round($ageStats['avg_age'] ?? 0, 1);

        // Total students
        $stats['total_students'] = $this->db->getRow(
            "SELECT COUNT(*) as count FROM students WHERE class_id = ?",
            [$classId]
        )['count'];

        // Attendance rate (last 30 days)
        $attendance = $this->db->getRow(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present
             FROM attendance
             WHERE class_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
            [$classId]
        );

        $stats['attendance_rate'] = $attendance['total'] > 0
            ? round(($attendance['present'] / $attendance['total']) * 100, 1)
            : 0;

        return $stats;
    }

    /**
     * Get class capacity status
     * @param int $id Class ID
     * @return array Capacity status
     */
    public function getCapacityStatus($id = null) {
        $classId = $id ?: $this->id;
        if (!$classId) {
            return [];
        }

        $class = $this->find($classId);
        if (!$class) {
            return [];
        }

        $studentCount = $this->db->getRow(
            "SELECT COUNT(*) as count FROM students WHERE class_id = ?",
            [$classId]
        )['count'];

        $percentage = $class['capacity'] > 0 ? ($studentCount / $class['capacity']) * 100 : 0;

        $status = 'available';
        if ($percentage >= 100) {
            $status = 'full';
        } elseif ($percentage >= 90) {
            $status = 'critical';
        } elseif ($percentage >= 75) {
            $status = 'warning';
        }

        return [
            'capacity' => $class['capacity'],
            'enrolled' => $studentCount,
            'available' => $class['capacity'] - $studentCount,
            'percentage' => round($percentage, 1),
            'status' => $status
        ];
    }

    /**
     * Promote all students to next class
     * @param int $fromClassId Source class ID
     * @param int $toClassId Destination class ID
     * @param string $newAcademicYear New academic year
     * @return int Number of students promoted
     */
    public function promoteStudents($fromClassId, $toClassId, $newAcademicYear) {
        try {
            $this->db->query(
                "UPDATE students SET class_id = ? WHERE class_id = ?",
                [$toClassId, $fromClassId]
            );

            $count = $this->db->rowCount();

            Security::logAudit('PROMOTED_CLASS', 'classes', $fromClassId,
                              ['to_class' => $toClassId, 'academic_year' => $newAcademicYear]);

            return $count;

        } catch (Exception $e) {
            error_log("Error promoting students: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get available academic years
     * @return array List of academic years
     */
    public function getAcademicYears() {
        return $this->db->getRows(
            "SELECT DISTINCT academic_year FROM classes ORDER BY academic_year DESC"
        );
    }

    /**
     * Get class count
     * @param array $filters Optional filters
     * @return int Count
     */
    public function getCount($filters = []) {
        $sql = "SELECT COUNT(*) as count FROM classes WHERE 1=1";
        $params = [];

        if (!empty($filters['academic_year'])) {
            $sql .= " AND academic_year = ?";
            $params[] = $filters['academic_year'];
        }

        if (isset($filters['is_active'])) {
            $sql .= " AND is_active = ?";
            $params[] = $filters['is_active'];
        }

        $result = $this->db->getRow($sql, $params);
        return $result['count'] ?? 0;
    }

    /**
     * Get current instance data
     * @return array|null Class data
     */
    public function getData() {
        return $this->data;
    }

    /**
     * Get class ID
     * @return int|null
     */
    public function getId() {
        return $this->id;
    }

    /**
     * Get full class name with section
     * @return string
     */
    public function getFullName() {
        return ($this->data['class_name'] ?? '') . ' ' . ($this->data['section'] ?? '');
    }
}