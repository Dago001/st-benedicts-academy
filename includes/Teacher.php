<?php
// includes/Teacher.php - Teacher Model Class

require_once __DIR__ . '/Database.php';

class Teacher {
    private $db;
    private $id;
    private $data;
    
    /**
     * Constructor
     * @param int|null $id Teacher ID
     */
    public function __construct($id = null) {
        $this->db = Database::getInstance();
        if ($id) {
            $this->find($id);
        }
    }
    
    /**
     * Find teacher by ID
     * @param int $id Teacher ID
     * @return array|false Teacher data or false
     */
    public function find($id) {
        $this->data = $this->db->getRow(
            "SELECT t.*, u.first_name, u.last_name, u.email, u.phone, u.profile_image,
                    u.username, u.is_active
             FROM teachers t
             JOIN users u ON t.user_id = u.id
             WHERE t.id = ? AND u.deleted_at IS NULL",
            [$id]
        );
        
        if ($this->data) {
            $this->id = $id;
        }
        
        return $this->data;
    }
    
    /**
     * Get teacher by user ID
     * @param int $userId User ID
     * @return array|false Teacher data or false
     */
    public function findByUserId($userId) {
        return $this->db->getRow(
            "SELECT t.* FROM teachers t WHERE t.user_id = ?",
            [$userId]
        );
    }
    
    /**
     * Get all teachers with optional filters
     * @param array $filters Optional filters
     * @return array List of teachers
     */
    public function getAll($filters = []) {
        $sql = "SELECT t.*, u.first_name, u.last_name, u.email, u.phone, u.profile_image,
                       u.is_active, u.username,
                       (SELECT COUNT(*) FROM classes WHERE teacher_id = t.id) as class_count,
                       (SELECT COUNT(*) FROM subjects WHERE teacher_id = t.id) as subject_count
                FROM teachers t
                JOIN users u ON t.user_id = u.id
                WHERE u.deleted_at IS NULL";
        
        $params = [];
        
        if (isset($filters['is_active'])) {
            $sql .= " AND u.is_active = ?";
            $params[] = $filters['is_active'];
        }
        
        if (!empty($filters['search'])) {
            $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR t.employee_id LIKE ? OR u.email LIKE ?)";
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
     * Get teachers available for class
     * @return array List of teachers
     */
    public function getAvailable() {
        return $this->db->getRows(
            "SELECT t.id, u.first_name, u.last_name 
             FROM teachers t
             JOIN users u ON t.user_id = u.id
             WHERE u.is_active = 1 AND u.deleted_at IS NULL
             ORDER BY u.first_name"
        );
    }
    
    /**
     * Create new teacher
     * @param array $data Teacher data
     * @return int|false New teacher ID or false
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
                'role' => 'teacher',
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
            
            // Create teacher record
            $teacherData = [
                'user_id' => $userId,
                'employee_id' => $data['employee_id'] ?? $this->generateEmployeeId(),
                'qualification' => $data['qualification'] ?? null,
                'specialization' => $data['specialization'] ?? null,
                'date_of_hire' => $data['date_of_hire'] ?? date('Y-m-d'),
                'address' => $data['address'] ?? null,
                'emergency_contact' => $data['emergency_contact'] ?? null
            ];
            
            $teacherId = $this->db->insert(
                "INSERT INTO teachers (user_id, employee_id, qualification, specialization, date_of_hire, address, emergency_contact) 
                 VALUES (:user_id, :employee_id, :qualification, :specialization, :date_of_hire, :address, :emergency_contact)",
                $teacherData
            );
            
            $this->db->commit();
            
            Security::logAudit('CREATED_TEACHER', 'teachers', $teacherId, null, $data);
            
            return $teacherId;
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Error creating teacher: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update teacher
     * @param int $id Teacher ID
     * @param array $data Updated data
     * @return bool Success or failure
     */
    public function update($id, $data) {
        try {
            $teacher = $this->find($id);
            if (!$teacher) {
                throw new Exception("Teacher not found");
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
                $userParams[] = $teacher['user_id'];
                $this->db->query(
                    "UPDATE users SET " . implode(', ', $userUpdates) . " WHERE id = ?",
                    $userParams
                );
            }
            
            // Update teacher data
            $teacherUpdates = [];
            $teacherParams = [];
            
            $teacherFields = ['employee_id', 'qualification', 'specialization', 
                             'date_of_hire', 'address', 'emergency_contact'];
            foreach ($teacherFields as $field) {
                if (isset($data[$field])) {
                    $teacherUpdates[] = "$field = ?";
                    $teacherParams[] = $data[$field];
                }
            }
            
            if (!empty($teacherUpdates)) {
                $teacherParams[] = $id;
                $this->db->query(
                    "UPDATE teachers SET " . implode(', ', $teacherUpdates) . " WHERE id = ?",
                    $teacherParams
                );
            }
            
            $this->db->commit();
            
            Security::logAudit('UPDATED_TEACHER', 'teachers', $id, $teacher, $data);
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Error updating teacher: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete teacher (soft delete)
     * @param int $id Teacher ID
     * @return bool Success or failure
     */
    public function delete($id) {
        try {
            $teacher = $this->find($id);
            if (!$teacher) {
                throw new Exception("Teacher not found");
            }
            
            // Check if teacher has classes
            $classCount = $this->db->getRow(
                "SELECT COUNT(*) as count FROM classes WHERE teacher_id = ?",
                [$id]
            )['count'];
            
            if ($classCount > 0) {
                throw new Exception("Cannot delete teacher with assigned classes");
            }
            
            // Soft delete user
            $this->db->query(
                "UPDATE users SET deleted_at = NOW() WHERE id = ?",
                [$teacher['user_id']]
            );
            
            Security::logAudit('DELETED_TEACHER', 'teachers', $id, $teacher);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error deleting teacher: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get teacher's classes
     * @param int $id Teacher ID
     * @return array Classes
     */
    public function getClasses($id = null) {
        $teacherId = $id ?: $this->id;
        if (!$teacherId) {
            return [];
        }
        
        return $this->db->getRows(
            "SELECT c.*, 
                    (SELECT COUNT(*) FROM students WHERE class_id = c.id) as student_count
             FROM classes c
             WHERE c.teacher_id = ? AND c.is_active = 1
             ORDER BY c.class_name",
            [$teacherId]
        );
    }
    
    /**
     * Get teacher's subjects
     * @param int $id Teacher ID
     * @return array Subjects
     */
    public function getSubjects($id = null) {
        $teacherId = $id ?: $this->id;
        if (!$teacherId) {
            return [];
        }
        
        return $this->db->getRows(
            "SELECT s.*, c.class_name 
             FROM subjects s
             JOIN classes c ON s.class_id = c.id
             WHERE s.teacher_id = ? AND s.is_active = 1
             ORDER BY c.class_name, s.subject_name",
            [$teacherId]
        );
    }
    
    /**
     * Assign subject to teacher
     * @param int $teacherId Teacher ID
     * @param int $subjectId Subject ID
     * @return bool Success or failure
     */
    public function assignSubject($teacherId, $subjectId) {
        try {
            $this->db->query(
                "UPDATE subjects SET teacher_id = ? WHERE id = ?",
                [$teacherId, $subjectId]
            );
            
            Security::logAudit('ASSIGNED_SUBJECT', 'subjects', $subjectId, 
                              null, ['teacher_id' => $teacherId]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error assigning subject: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Remove subject from teacher
     * @param int $subjectId Subject ID
     * @return bool Success or failure
     */
    public function removeSubject($subjectId) {
        try {
            $this->db->query(
                "UPDATE subjects SET teacher_id = NULL WHERE id = ?",
                [$subjectId]
            );
            
            Security::logAudit('REMOVED_SUBJECT', 'subjects', $subjectId);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error removing subject: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get teacher's timetable
     * @param int $id Teacher ID
     * @return array Timetable
     */
    public function getTimetable($id = null) {
        $teacherId = $id ?: $this->id;
        if (!$teacherId) {
            return [];
        }
        
        return $this->db->getRows(
            "SELECT tt.*, c.class_name, s.subject_name
             FROM time_table tt
             JOIN classes c ON tt.class_id = c.id
             JOIN subjects s ON tt.subject_id = s.id
             WHERE tt.teacher_id = ?
             ORDER BY FIELD(tt.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'), 
                      tt.start_time",
            [$teacherId]
        );
    }
    
    /**
     * Get teacher's attendance
     * @param int $id Teacher ID
     * @param string $month Month (Y-m)
     * @return array Attendance records
     */
    public function getAttendance($id = null, $month = null) {
        $teacherId = $id ?: $this->id;
        if (!$teacherId) {
            return [];
        }
        
        $sql = "SELECT * FROM staff_attendance WHERE teacher_id = ?";
        $params = [$teacherId];
        
        if ($month) {
            $sql .= " AND DATE_FORMAT(date, '%Y-%m') = ?";
            $params[] = $month;
        }
        
        $sql .= " ORDER BY date DESC";
        
        return $this->db->getRows($sql, $params);
    }
    
    /**
     * Generate unique employee ID
     * @return string Employee ID
     */
    private function generateEmployeeId() {
        $year = date('Y');
        $random = str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
        $empId = "TCH/{$year}/{$random}";
        
        // Check if exists
        $exists = $this->db->getRow(
            "SELECT id FROM teachers WHERE employee_id = ?",
            [$empId]
        );
        
        if ($exists) {
            return $this->generateEmployeeId();
        }
        
        return $empId;
    }
    
    /**
     * Get teacher count
     * @return int Count
     */
    public function getCount() {
        $result = $this->db->getRow(
            "SELECT COUNT(*) as count FROM teachers t
             JOIN users u ON t.user_id = u.id
             WHERE u.deleted_at IS NULL"
        );
        return $result['count'] ?? 0;
    }
    
    /**
     * Get current instance data
     * @return array|null Teacher data
     */
    public function getData() {
        return $this->data;
    }
    
    /**
     * Get teacher ID
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