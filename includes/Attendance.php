<?php
// includes/Attendance.php - Attendance Model Class

require_once __DIR__ . '/Database.php';

class Attendance {
    private $db;
    private $id;
    private $data;
    
    /**
     * Constructor
     * @param int|null $id Attendance record ID
     */
    public function __construct($id = null) {
        $this->db = Database::getInstance();
        if ($id) {
            $this->find($id);
        }
    }
    
    /**
     * Find attendance record by ID
     * @param int $id Attendance ID
     * @return array|false Attendance data or false
     */
    public function find($id) {
        $this->data = $this->db->getRow(
            "SELECT a.*, 
                    CONCAT(u.first_name, ' ', u.last_name) as student_name,
                    s.admission_number,
                    c.class_name,
                    CONCAT(mu.first_name, ' ', mu.last_name) as marked_by_name
             FROM attendance a
             JOIN students s ON a.student_id = s.id
             JOIN users u ON s.user_id = u.id
             JOIN classes c ON a.class_id = c.id
             LEFT JOIN users mu ON a.marked_by = mu.id
             WHERE a.id = ?",
            [$id]
        );
        
        if ($this->data) {
            $this->id = $id;
        }
        
        return $this->data;
    }
    
    /**
     * Mark attendance for a student
     * @param array $data Attendance data
     * @return int|false New attendance ID or false
     */
    public function mark($data) {
        try {
            // Check if attendance already marked for this student on this date
            $existing = $this->db->getRow(
                "SELECT id FROM attendance WHERE student_id = ? AND date = ?",
                [$data['student_id'], $data['date']]
            );
            
            if ($existing) {
                // Update existing
                $this->db->query(
                    "UPDATE attendance SET status = ?, remarks = ?, marked_by = ? 
                     WHERE id = ?",
                    [$data['status'], $data['remarks'] ?? null, $data['marked_by'], $existing['id']]
                );
                $id = $existing['id'];
            } else {
                // Insert new
                $id = $this->db->insert(
                    "INSERT INTO attendance (student_id, class_id, date, status, remarks, marked_by) 
                     VALUES (?, ?, ?, ?, ?, ?)",
                    [
                        $data['student_id'],
                        $data['class_id'],
                        $data['date'],
                        $data['status'],
                        $data['remarks'] ?? null,
                        $data['marked_by']
                    ]
                );
            }
            
            Security::logAudit('MARKED_ATTENDANCE', 'attendance', $id, null, $data);
            
            return $id;
            
        } catch (Exception $e) {
            error_log("Error marking attendance: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Mark attendance for multiple students
     * @param array $attendanceList List of attendance data
     * @return bool Success or failure
     */
    public function markBulk($attendanceList) {
        try {
            $this->db->beginTransaction();
            
            foreach ($attendanceList as $attendance) {
                $this->mark($attendance);
            }
            
            $this->db->commit();
            
            Security::logAudit('MARKED_BULK_ATTENDANCE', 'attendance');
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Error marking bulk attendance: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get attendance for a class on a specific date
     * @param int $classId Class ID
     * @param string $date Date (Y-m-d)
     * @return array Attendance records
     */
    public function getByClassAndDate($classId, $date) {
        return $this->db->getRows(
            "SELECT a.*, 
                    CONCAT(u.first_name, ' ', u.last_name) as student_name,
                    s.admission_number
             FROM attendance a
             JOIN students s ON a.student_id = s.id
             JOIN users u ON s.user_id = u.id
             WHERE a.class_id = ? AND a.date = ?
             ORDER BY u.first_name",
            [$classId, $date]
        );
    }
    
    /**
     * Get attendance for a student
     * @param int $studentId Student ID
     * @param string $startDate Start date
     * @param string $endDate End date
     * @return array Attendance records
     */
    public function getByStudent($studentId, $startDate = null, $endDate = null) {
        $sql = "SELECT a.*, c.class_name 
                FROM attendance a
                JOIN classes c ON a.class_id = c.id
                WHERE a.student_id = ?";
        $params = [$studentId];
        
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
     * Get attendance summary for a student
     * @param int $studentId Student ID
     * @param string $startDate Start date
     * @param string $endDate End date
     * @return array Summary statistics
     */
    public function getStudentSummary($studentId, $startDate = null, $endDate = null) {
        $sql = "SELECT 
                    COUNT(*) as total_days,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
                    SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused
                FROM attendance 
                WHERE student_id = ?";
        $params = [$studentId];
        
        if ($startDate) {
            $sql .= " AND date >= ?";
            $params[] = $startDate;
        }
        
        if ($endDate) {
            $sql .= " AND date <= ?";
            $params[] = $endDate;
        }
        
        $result = $this->db->getRow($sql, $params);
        
        if (!$result) {
            $result = [
                'total_days' => 0,
                'present' => 0,
                'absent' => 0,
                'late' => 0,
                'excused' => 0
            ];
        }
        
        // Calculate percentage
        $result['attendance_rate'] = $result['total_days'] > 0 
            ? round((($result['present'] + $result['late']) / $result['total_days']) * 100, 1) 
            : 0;
        
        return $result;
    }
    
    /**
     * Get class attendance summary for a date range
     * @param int $classId Class ID
     * @param string $startDate Start date
     * @param string $endDate End date
     * @return array Daily summary
     */
    public function getClassSummary($classId, $startDate, $endDate) {
        return $this->db->getRows(
            "SELECT 
                date,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
                SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused
             FROM attendance 
             WHERE class_id = ? AND date BETWEEN ? AND ?
             GROUP BY date
             ORDER BY date",
            [$classId, $startDate, $endDate]
        );
    }
    
    /**
     * Get monthly attendance report
     * @param int $classId Class ID
     * @param string $month Month (Y-m)
     * @return array Monthly report
     */
    public function getMonthlyReport($classId, $month) {
        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));
        
        // Get all students in class
        $students = $this->db->getRows(
            "SELECT s.id, u.first_name, u.last_name, s.admission_number
             FROM students s
             JOIN users u ON s.user_id = u.id
             WHERE s.class_id = ? AND u.is_active = 1
             ORDER BY u.first_name",
            [$classId]
        );
        
        $report = [];
        
        foreach ($students as $student) {
            $summary = $this->getStudentSummary($student['id'], $startDate, $endDate);
            $report[] = [
                'student_id' => $student['id'],
                'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                'admission_number' => $student['admission_number'],
                'present' => $summary['present'],
                'absent' => $summary['absent'],
                'late' => $summary['late'],
                'excused' => $summary['excused'],
                'total' => $summary['total_days'],
                'rate' => $summary['attendance_rate']
            ];
        }
        
        return $report;
    }
    
    /**
     * Check if attendance is already marked for a class on a date
     * @param int $classId Class ID
     * @param string $date Date (Y-m-d)
     * @return bool True if marked
     */
    public function isMarked($classId, $date) {
        $result = $this->db->getRow(
            "SELECT COUNT(*) as count FROM attendance WHERE class_id = ? AND date = ?",
            [$classId, $date]
        );
        
        return ($result['count'] ?? 0) > 0;
    }
    
    /**
     * Get attendance statistics for a date range
     * @param string $startDate Start date
     * @param string $endDate End date
     * @param int|null $classId Class ID (optional)
     * @return array Statistics
     */
    public function getStatistics($startDate, $endDate, $classId = null) {
        $sql = "SELECT 
                    COUNT(DISTINCT date) as school_days,
                    COUNT(*) as total_records,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as total_present,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as total_absent,
                    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as total_late,
                    SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as total_excused
                FROM attendance 
                WHERE date BETWEEN ? AND ?";
        $params = [$startDate, $endDate];
        
        if ($classId) {
            $sql .= " AND class_id = ?";
            $params[] = $classId;
        }
        
        $result = $this->db->getRow($sql, $params);
        
        if (!$result) {
            $result = [
                'school_days' => 0,
                'total_records' => 0,
                'total_present' => 0,
                'total_absent' => 0,
                'total_late' => 0,
                'total_excused' => 0
            ];
        }
        
        // Calculate average daily attendance
        if ($result['school_days'] > 0) {
            $result['avg_daily_present'] = round($result['total_present'] / $result['school_days'], 1);
            $result['avg_daily_absent'] = round($result['total_absent'] / $result['school_days'], 1);
        } else {
            $result['avg_daily_present'] = 0;
            $result['avg_daily_absent'] = 0;
        }
        
        return $result;
    }
    
    /**
     * Get students with low attendance
     * @param int $classId Class ID
     * @param int $threshold Percentage threshold
     * @param string $startDate Start date
     * @param string $endDate End date
     * @return array Students with low attendance
     */
    public function getLowAttendance($classId, $threshold = 80, $startDate = null, $endDate = null) {
        if (!$startDate) {
            $startDate = date('Y-m-d', strtotime('-30 days'));
        }
        if (!$endDate) {
            $endDate = date('Y-m-d');
        }
        
        $students = $this->db->getRows(
            "SELECT s.id, u.first_name, u.last_name, s.admission_number,
                    COUNT(a.id) as total_days,
                    SUM(CASE WHEN a.status IN ('present', 'late') THEN 1 ELSE 0 END) as present_days
             FROM students s
             JOIN users u ON s.user_id = u.id
             LEFT JOIN attendance a ON s.id = a.student_id 
                 AND a.date BETWEEN ? AND ?
             WHERE s.class_id = ? AND u.is_active = 1
             GROUP BY s.id
             HAVING (present_days / total_days) * 100 < ?",
            [$startDate, $endDate, $classId, $threshold]
        );
        
        return $students;
    }
    
    /**
     * Delete attendance record
     * @param int $id Attendance ID
     * @return bool Success or failure
     */
    public function delete($id) {
        try {
            $attendance = $this->find($id);
            if (!$attendance) {
                throw new Exception("Attendance record not found");
            }
            
            $this->db->query("DELETE FROM attendance WHERE id = ?", [$id]);
            
            Security::logAudit('DELETED_ATTENDANCE', 'attendance', $id, $attendance);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error deleting attendance: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get attendance by date range
     * @param string $startDate Start date
     * @param string $endDate End date
     * @param int|null $classId Class ID (optional)
     * @return array Attendance records
     */
    public function getByDateRange($startDate, $endDate, $classId = null) {
        $sql = "SELECT a.*, 
                       CONCAT(u.first_name, ' ', u.last_name) as student_name,
                       s.admission_number,
                       c.class_name
                FROM attendance a
                JOIN students s ON a.student_id = s.id
                JOIN users u ON s.user_id = u.id
                JOIN classes c ON a.class_id = c.id
                WHERE a.date BETWEEN ? AND ?";
        $params = [$startDate, $endDate];
        
        if ($classId) {
            $sql .= " AND a.class_id = ?";
            $params[] = $classId;
        }
        
        $sql .= " ORDER BY a.date DESC, c.class_name, u.first_name";
        
        return $this->db->getRows($sql, $params);
    }
    
    /**
     * Get current instance data
     * @return array|null Attendance data
     */
    public function getData() {
        return $this->data;
    }
    
    /**
     * Get attendance ID
     * @return int|null
     */
    public function getId() {
        return $this->id;
    }
}