<?php
// api/results.php - Results API
require_once __DIR__ . '/../includes/api.php';

$input = api_init(['GET', 'POST']);
$db = db();
$role = $_SESSION['user_role'];
$staff = in_array($role, ['admin', 'teacher'], true);

$termOk = function ($v) { return is_string($v) && $v !== '' && strlen($v) <= 20; };
$yearOk = function ($v) { return is_string($v) && preg_match('/^\d{4}(-\d{4})?$/', $v); };

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    switch ($_GET['action'] ?? '') {
        case 'get_student_results':
            $studentId = api_int($_GET['student_id'] ?? null);
            $term = Security::sanitize($_GET['term'] ?? '');
            $year = Security::sanitize($_GET['academic_year'] ?? '');
            if (!$studentId || !$termOk($term) || !$yearOk($year)) api_error('Missing or invalid parameters');
            if (!Security::canAccessStudent($studentId)) api_error('Permission denied', 403);
            // Only approved results are visible to students and parents
            $sql = "SELECT r.*, s.subject_name FROM results r JOIN subjects s ON r.subject_id = s.id
                    WHERE r.student_id = ? AND r.term = ? AND r.academic_year = ?";
            if (!$staff) $sql .= ' AND r.is_approved = 1';
            api_ok(['data' => $db->getRows($sql . ' ORDER BY s.subject_name', [$studentId, $term, $year])]);

        case 'get_class_results':
            if (!$staff) api_error('Permission denied', 403);
            $classId = api_int($_GET['class_id'] ?? null);
            $term = Security::sanitize($_GET['term'] ?? '');
            $year = Security::sanitize($_GET['academic_year'] ?? '');
            $subjectId = api_int($_GET['subject_id'] ?? null);
            if (!$classId || !$termOk($term) || !$yearOk($year)) api_error('Missing or invalid parameters');
            if (!Security::canAccessClass($classId)) api_error('Permission denied', 403);
            $sql = "SELECT r.*, CONCAT(u.first_name, ' ', u.last_name) AS student_name, s.admission_number, sub.subject_name
                    FROM results r
                    JOIN students s ON r.student_id = s.id
                    JOIN users u ON s.user_id = u.id
                    JOIN subjects sub ON r.subject_id = sub.id
                    WHERE r.class_id = ? AND r.term = ? AND r.academic_year = ?";
            $params = [$classId, $term, $year];
            if ($subjectId) { $sql .= ' AND r.subject_id = ?'; $params[] = $subjectId; }
            api_ok(['data' => $db->getRows($sql . ' ORDER BY sub.subject_name, u.first_name', $params)]);

        case 'get_terms':
            api_ok(['data' => $db->getRows('SELECT DISTINCT term, academic_year FROM results ORDER BY academic_year DESC, term DESC')]);

        default:
            api_error('Invalid action');
    }
}

// ---- POST ----
switch ($input['action'] ?? '') {
    case 'add_result':
        if (!$staff) api_error('Permission denied', 403);
        $studentId = api_int($input['student_id'] ?? null);
        $subjectId = api_int($input['subject_id'] ?? null);
        $classId = api_int($input['class_id'] ?? null);
        $term = Security::sanitize($input['term'] ?? '');
        $year = Security::sanitize($input['academic_year'] ?? '');
        $type = $input['assessment_type'] ?? '';
        $score = filter_var($input['score'] ?? null, FILTER_VALIDATE_FLOAT);
        $max = filter_var($input['max_score'] ?? 100, FILTER_VALIDATE_FLOAT);

        if (!$studentId || !$subjectId || !$classId || !$termOk($term) || !$yearOk($year)) api_error('Missing or invalid required fields');
        if (!in_array($type, ['test', 'exam', 'assignment', 'project'], true)) api_error('Invalid assessment type');
        if ($score === false || $max === false || $max <= 0 || $max > 1000 || $score < 0 || $score > $max) api_error('Score must be between 0 and the maximum score');

        $student = $db->getRow('SELECT class_id FROM students WHERE id = ?', [$studentId]);
        $subject = $db->getRow('SELECT class_id, teacher_id FROM subjects WHERE id = ?', [$subjectId]);
        if (!$student || !$subject || (int)$student['class_id'] !== $classId || (int)$subject['class_id'] !== $classId) {
            api_error('Student and subject must belong to the selected class');
        }
        // Teachers may only enter results for subjects they teach
        if ($role === 'teacher' && (int)$subject['teacher_id'] !== Security::currentTeacherId()) {
            api_error('You are not assigned to this subject', 403);
        }

        $grade = letterGrade($score, $max);
        try {
            $id = $db->insert(
                "INSERT INTO results (student_id, subject_id, class_id, term, academic_year, assessment_type, score, max_score, grade, remarks, entered_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$studentId, $subjectId, $classId, $term, $year, $type, $score, $max, $grade, Security::sanitize($input['remarks'] ?? ''), $_SESSION['user_id']]
            );
            Security::logAudit('ADDED_RESULT_API', 'results', $id);
            api_ok(['message' => 'Result added successfully', 'grade' => $grade, 'id' => (int)$id]);
        } catch (Throwable $e) {
            api_exception($e);
        }

    case 'approve_results':
        if ($role !== 'admin') api_error('Permission denied', 403);
        $ids = $input['result_ids'] ?? [];
        if (!is_array($ids)) $ids = [$ids];
        $ids = array_values(array_filter(array_map('api_int', $ids)));
        if (!$ids) api_error('No results selected');
        if (count($ids) > 500) api_error('Too many results selected');
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $db->query("UPDATE results SET is_approved = 1, approved_by = ?, approved_at = NOW() WHERE id IN ($in)", array_merge([$_SESSION['user_id']], $ids));
            Security::logAudit('APPROVED_RESULTS_API', 'results');
            api_ok(['message' => 'Results approved successfully']);
        } catch (Throwable $e) {
            api_exception($e);
        }

    default:
        api_error('Invalid action');
}
