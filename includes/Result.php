<?php
// includes/Result.php - Result Model Class

require_once __DIR__ . '/../config/database.php';

class Result {
    private $db;
    private $id;
    private $data;

    /**
     * Constructor
     * @param int|null $id Result ID
     */
    public function __construct($id = null) {
        $this->db = Database::getInstance();
        if ($id) {
            $this->find($id);
        }
    }

    /**
     * Find result by ID
     * @param int $id Result ID
     * @return array|false Result data or false
     */
    public function find($id) {
        $this->data = $this->db->getRow(
            "SELECT r.*,
                    CONCAT(u.first_name, ' ', u.last_name) as student_name,
                    s.admission_number,
                    sub.subject_name,
                    c.class_name,
                    CONCAT(eu.first_name, ' ', eu.last_name) as entered_by_name,
                    CONCAT(au.first_name, ' ', au.last_name) as approved_by_name
             FROM results r
             JOIN students s ON r.student_id = s.id
             JOIN users u ON s.user_id = u.id
             JOIN subjects sub ON r.subject_id = sub.id
             JOIN classes c ON r.class_id = c.id
             LEFT JOIN users eu ON r.entered_by = eu.id
             LEFT JOIN users au ON r.approved_by = au.id
             WHERE r.id = ?",
            [$id]
        );

        if ($this->data) {
            $this->id = $id;
        }

        return $this->data;
    }

    /**
     * Calculate grade based on percentage
     * @param float $score Score obtained
     * @param float $maxScore Maximum score
     * @return string Grade
     */
    public function calculateGrade($score, $maxScore) {
        $percentage = ($score / $maxScore) * 100;

        if ($percentage >= 70) return 'A';
        if ($percentage >= 60) return 'B';
        if ($percentage >= 50) return 'C';
        if ($percentage >= 45) return 'D';
        if ($percentage >= 40) return 'E';
        return 'F';
    }

    /**
     * Add new result
     * @param array $data Result data
     * @return int|false New result ID or false
     */
    public function add($data) {
        try {
            // Check for duplicate
            $existing = $this->db->getRow(
                "SELECT id FROM results
                 WHERE student_id = ? AND subject_id = ? AND assessment_type = ?
                 AND term = ? AND academic_year = ?",
                [
                    $data['student_id'],
                    $data['subject_id'],
                    $data['assessment_type'],
                    $data['term'],
                    $data['academic_year']
                ]
            );

            if ($existing) {
                throw new Exception("Result already exists for this student, subject, assessment type and term");
            }

            // Calculate grade
            $grade = $this->calculateGrade($data['score'], $data['max_score']);

            $id = $this->db->insert(
                "INSERT INTO results (student_id, subject_id, class_id, term, academic_year,
                 assessment_type, score, max_score, grade, remarks, entered_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $data['student_id'],
                    $data['subject_id'],
                    $data['class_id'],
                    $data['term'],
                    $data['academic_year'],
                    $data['assessment_type'],
                    $data['score'],
                    $data['max_score'],
                    $grade,
                    $data['remarks'] ?? null,
                    $data['entered_by']
                ]
            );

            Security::logAudit('ADDED_RESULT', 'results', $id, null, $data);

            return $id;

        } catch (Exception $e) {
            error_log("Error adding result: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update result
     * @param int $id Result ID
     * @param array $data Updated data
     * @return bool Success or failure
     */
    public function update($id, $data) {
        try {
            $result = $this->find($id);
            if (!$result) {
                throw new Exception("Result not found");
            }

            // Recalculate grade if score or max_score changed
            $score = $data['score'] ?? $result['score'];
            $maxScore = $data['max_score'] ?? $result['max_score'];
            $grade = $this->calculateGrade($score, $maxScore);

            $this->db->query(
                "UPDATE results SET score = ?, max_score = ?, grade = ?, remarks = ?
                 WHERE id = ?",
                [
                    $score,
                    $maxScore,
                    $grade,
                    $data['remarks'] ?? $result['remarks'],
                    $id
                ]
            );

            Security::logAudit('UPDATED_RESULT', 'results', $id, $result, $data);

            return true;

        } catch (Exception $e) {
            error_log("Error updating result: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Approve result
     * @param int $id Result ID
     * @param int $approvedBy User ID who approved
     * @return bool Success or failure
     */
    public function approve($id, $approvedBy) {
        try {
            $result = $this->find($id);
            if (!$result) {
                throw new Exception("Result not found");
            }

            $this->db->query(
                "UPDATE results SET is_approved = 1, approved_by = ?, approved_at = NOW()
                 WHERE id = ?",
                [$approvedBy, $id]
            );

            Security::logAudit('APPROVED_RESULT', 'results', $id, $result);

            return true;

        } catch (Exception $e) {
            error_log("Error approving result: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Approve multiple results
     * @param array $resultIds Array of result IDs
     * @param int $approvedBy User ID who approved
     * @return int Number approved
     */
    public function approveBulk($resultIds, $approvedBy) {
        if (empty($resultIds)) {
            return 0;
        }

        try {
            $placeholders = implode(',', array_fill(0, count($resultIds), '?'));
            $params = $resultIds;
            $params[] = $approvedBy;

            $this->db->query(
                "UPDATE results SET is_approved = 1, approved_by = ?, approved_at = NOW()
                 WHERE id IN ($placeholders)",
                $params
            );

            $count = $this->db->rowCount();

            Security::logAudit('APPROVED_BULK_RESULTS', 'results', null, null,
                              ['count' => $count, 'ids' => $resultIds]);

            return $count;

        } catch (Exception $e) {
            error_log("Error approving bulk results: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Delete result
     * @param int $id Result ID
     * @return bool Success or failure
     */
    public function delete($id) {
        try {
            $result = $this->find($id);
            if (!$result) {
                throw new Exception("Result not found");
            }

            $this->db->query("DELETE FROM results WHERE id = ?", [$id]);

            Security::logAudit('DELETED_RESULT', 'results', $id, $result);

            return true;

        } catch (Exception $e) {
            error_log("Error deleting result: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get results by class, term and academic year
     * @param int $classId Class ID
     * @param string $term Term
     * @param string $academicYear Academic year
     * @param int|null $subjectId Subject ID (optional)
     * @return array Results
     */
    public function getByClass($classId, $term, $academicYear, $subjectId = null) {
        $sql = "SELECT r.*,
                       CONCAT(u.first_name, ' ', u.last_name) as student_name,
                       s.admission_number,
                       sub.subject_name
                FROM results r
                JOIN students s ON r.student_id = s.id
                JOIN users u ON s.user_id = u.id
                JOIN subjects sub ON r.subject_id = sub.id
                WHERE r.class_id = ? AND r.term = ? AND r.academic_year = ?";
        $params = [$classId, $term, $academicYear];

        if ($subjectId) {
            $sql .= " AND r.subject_id = ?";
            $params[] = $subjectId;
        }

        $sql .= " ORDER BY sub.subject_name, u.first_name";

        return $this->db->getRows($sql, $params);
    }

    /**
     * Get results by student
     * @param int $studentId Student ID
     * @param string|null $term Term (optional)
     * @param string|null $academicYear Academic year (optional)
     * @return array Results
     */
    public function getByStudent($studentId, $term = null, $academicYear = null) {
        $sql = "SELECT r.*, sub.subject_name, c.class_name
                FROM results r
                JOIN subjects sub ON r.subject_id = sub.id
                JOIN classes c ON r.class_id = c.id
                WHERE r.student_id = ?";
        $params = [$studentId];

        if ($term) {
            $sql .= " AND r.term = ?";
            $params[] = $term;
        }

        if ($academicYear) {
            $sql .= " AND r.academic_year = ?";
            $params[] = $academicYear;
        }

        $sql .= " ORDER BY r.academic_year DESC, r.term DESC, sub.subject_name";

        return $this->db->getRows($sql, $params);
    }

    /**
     * Get student's term summary
     * @param int $studentId Student ID
     * @param string $term Term
     * @param string $academicYear Academic year
     * @return array Summary
     */
    public function getStudentTermSummary($studentId, $term, $academicYear) {
        $results = $this->getByStudent($studentId, $term, $academicYear);

        if (empty($results)) {
            return [
                'total_subjects' => 0,
                'total_score' => 0,
                'total_max' => 0,
                'average' => 0,
                'percentage' => 0,
                'grade' => 'F',
                'results' => []
            ];
        }

        $totalScore = 0;
        $totalMax = 0;

        foreach ($results as $r) {
            $totalScore += $r['score'];
            $totalMax += $r['max_score'];
        }

        $percentage = $totalMax > 0 ? ($totalScore / $totalMax) * 100 : 0;
        $average = count($results) > 0 ? $totalScore / count($results) : 0;

        // Determine overall grade
        if ($percentage >= 70) $grade = 'A';
        elseif ($percentage >= 60) $grade = 'B';
        elseif ($percentage >= 50) $grade = 'C';
        elseif ($percentage >= 45) $grade = 'D';
        elseif ($percentage >= 40) $grade = 'E';
        else $grade = 'F';

        return [
            'total_subjects' => count($results),
            'total_score' => round($totalScore, 2),
            'total_max' => round($totalMax, 2),
            'average' => round($average, 2),
            'percentage' => round($percentage, 2),
            'grade' => $grade,
            'results' => $results
        ];
    }

    /**
     * Get class performance summary
     * @param int $classId Class ID
     * @param string $term Term
     * @param string $academicYear Academic year
     * @return array Summary
     */
    public function getClassSummary($classId, $term, $academicYear) {
        // Get all students in class
        $students = $this->db->getRows(
            "SELECT s.id, u.first_name, u.last_name
             FROM students s
             JOIN users u ON s.user_id = u.id
             WHERE s.class_id = ? AND u.is_active = 1",
            [$classId]
        );

        $summaries = [];
        $totalPercentage = 0;
        $studentCount = 0;

        foreach ($students as $student) {
            $summary = $this->getStudentTermSummary($student['id'], $term, $academicYear);

            if ($summary['total_subjects'] > 0) {
                $summaries[] = [
                    'student_id' => $student['id'],
                    'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                    'average' => $summary['average'],
                    'percentage' => $summary['percentage'],
                    'grade' => $summary['grade']
                ];

                $totalPercentage += $summary['percentage'];
                $studentCount++;
            }
        }

        // Sort by percentage descending
        usort($summaries, function($a, $b) {
            return $b['percentage'] <=> $a['percentage'];
        });

        // Add position
        foreach ($summaries as $index => &$summary) {
            $summary['position'] = $index + 1;
        }

        return [
            'students' => $summaries,
            'class_average' => $studentCount > 0 ? round($totalPercentage / $studentCount, 2) : 0,
            'total_students' => $studentCount
        ];
    }

    /**
     * Get pending approvals
     * @param int|null $classId Class ID (optional)
     * @return array Pending results
     */
    public function getPendingApprovals($classId = null) {
        $sql = "SELECT r.*,
                       CONCAT(u.first_name, ' ', u.last_name) as student_name,
                       s.admission_number,
                       sub.subject_name,
                       c.class_name,
                       CONCAT(eu.first_name, ' ', eu.last_name) as entered_by_name
                FROM results r
                JOIN students s ON r.student_id = s.id
                JOIN users u ON s.user_id = u.id
                JOIN subjects sub ON r.subject_id = sub.id
                JOIN classes c ON r.class_id = c.id
                LEFT JOIN users eu ON r.entered_by = eu.id
                WHERE r.is_approved = 0";
        $params = [];

        if ($classId) {
            $sql .= " AND r.class_id = ?";
            $params[] = $classId;
        }

        $sql .= " ORDER BY r.created_at DESC";

        return $this->db->getRows($sql, $params);
    }

    /**
     * Get available terms for a class
     * @param int $classId Class ID
     * @return array Terms
     */
    public function getAvailableTerms($classId) {
        return $this->db->getRows(
            "SELECT DISTINCT term, academic_year
             FROM results
             WHERE class_id = ?
             ORDER BY academic_year DESC, term DESC",
            [$classId]
        );
    }

    /**
     * Check if results exist for class/term
     * @param int $classId Class ID
     * @param string $term Term
     * @param string $academicYear Academic year
     * @return bool True if exists
     */
    public function existsForClass($classId, $term, $academicYear) {
        $result = $this->db->getRow(
            "SELECT COUNT(*) as count FROM results
             WHERE class_id = ? AND term = ? AND academic_year = ?",
            [$classId, $term, $academicYear]
        );

        return ($result['count'] ?? 0) > 0;
    }

    /**
     * Get current instance data
     * @return array|null Result data
     */
    public function getData() {
        return $this->data;
    }

    /**
     * Get result ID
     * @return int|null
     */
    public function getId() {
        return $this->id;
    }
}