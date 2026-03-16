<?php
// api/results.php - Results API endpoints
header('Content-Type: application/json');
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/security.php';

// Require authentication
if (!Security::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = db();
$response = ['success' => false];

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        $action = $_GET['action'] ?? '';
        
        switch ($action) {
            case 'get_student_results':
                $studentId = Security::sanitize($_GET['student_id'] ?? '');
                $term = Security::sanitize($_GET['term'] ?? '');
                $academicYear = Security::sanitize($_GET['academic_year'] ?? '');
                
                if (!$studentId || !$term || !$academicYear) {
                    $response['message'] = 'Missing required parameters';
                    break;
                }
                
                $results = $db->getRows(
                    "SELECT r.*, s.subject_name 
                     FROM results r
                     JOIN subjects s ON r.subject_id = s.id
                     WHERE r.student_id = ? AND r.term = ? AND r.academic_year = ? AND r.is_approved = 1
                     ORDER BY s.subject_name",
                    [$studentId, $term, $academicYear]
                );
                
                $response['success'] = true;
                $response['data'] = $results;
                break;
                
            case 'get_class_results':
                $classId = Security::sanitize($_GET['class_id'] ?? '');
                $term = Security::sanitize($_GET['term'] ?? '');
                $academicYear = Security::sanitize($_GET['academic_year'] ?? '');
                $subjectId = Security::sanitize($_GET['subject_id'] ?? '');
                
                if (!$classId || !$term || !$academicYear) {
                    $response['message'] = 'Missing required parameters';
                    break;
                }
                
                $query = "SELECT r.*, 
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
                    $query .= " AND r.subject_id = ?";
                    $params[] = $subjectId;
                }
                
                $query .= " ORDER BY sub.subject_name, u.first_name";
                
                $results = $db->getRows($query, $params);
                
                $response['success'] = true;
                $response['data'] = $results;
                break;
                
            case 'get_terms':
                $results = $db->getRows(
                    "SELECT DISTINCT term, academic_year FROM results ORDER BY academic_year DESC, term DESC"
                );
                $response['success'] = true;
                $response['data'] = $results;
                break;
                
            default:
                $response['message'] = 'Invalid action';
        }
        break;
        
    case 'POST':
        // Verify CSRF token for POST requests
        $input = json_decode(file_get_contents('php://input'), true);
        $token = $_POST['csrf_token'] ?? $input['csrf_token'] ?? '';
        
        if (!Security::verifyCSRFToken($token)) {
            http_response_code(419);
            $response['message'] = 'Invalid CSRF token';
            echo json_encode($response);
            exit;
        }
        
        $action = $_POST['action'] ?? $input['action'] ?? '';
        
        switch ($action) {
            case 'add_result':
                // Check permission
                if (!in_array($_SESSION['user_role'], ['admin', 'teacher'])) {
                    $response['message'] = 'Permission denied';
                    break;
                }
                
                $data = [
                    'student_id' => Security::sanitize($_POST['student_id'] ?? $input['student_id'] ?? ''),
                    'subject_id' => Security::sanitize($_POST['subject_id'] ?? $input['subject_id'] ?? ''),
                    'class_id' => Security::sanitize($_POST['class_id'] ?? $input['class_id'] ?? ''),
                    'term' => Security::sanitize($_POST['term'] ?? $input['term'] ?? ''),
                    'academic_year' => Security::sanitize($_POST['academic_year'] ?? $input['academic_year'] ?? ''),
                    'assessment_type' => Security::sanitize($_POST['assessment_type'] ?? $input['assessment_type'] ?? ''),
                    'score' => Security::sanitize($_POST['score'] ?? $input['score'] ?? ''),
                    'max_score' => Security::sanitize($_POST['max_score'] ?? $input['max_score'] ?? 100),
                    'remarks' => Security::sanitize($_POST['remarks'] ?? $input['remarks'] ?? '')
                ];
                
                // Validate required fields
                $required = ['student_id', 'subject_id', 'class_id', 'term', 'academic_year', 'assessment_type', 'score'];
                foreach ($required as $field) {
                    if (empty($data[$field])) {
                        $response['message'] = "Missing required field: $field";
                        echo json_encode($response);
                        exit;
                    }
                }
                
                // Calculate grade
                $percentage = ($data['score'] / $data['max_score']) * 100;
                if ($percentage >= 70) $grade = 'A';
                elseif ($percentage >= 60) $grade = 'B';
                elseif ($percentage >= 50) $grade = 'C';
                elseif ($percentage >= 45) $grade = 'D';
                elseif ($percentage >= 40) $grade = 'E';
                else $grade = 'F';
                
                try {
                    $db->insert(
                        "INSERT INTO results (student_id, subject_id, class_id, term, academic_year, 
                         assessment_type, score, max_score, grade, remarks, entered_by) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$data['student_id'], $data['subject_id'], $data['class_id'], $data['term'], 
                         $data['academic_year'], $data['assessment_type'], $data['score'], 
                         $data['max_score'], $grade, $data['remarks'], $_SESSION['user_id']]
                    );
                    
                    Security::logAudit('ADDED_RESULT_API', 'results');
                    
                    $response['success'] = true;
                    $response['message'] = 'Result added successfully';
                    $response['grade'] = $grade;
                    
                } catch (Exception $e) {
                    $response['message'] = 'Database error: ' . $e->getMessage();
                }
                break;
                
            case 'approve_results':
                if ($_SESSION['user_role'] !== 'admin') {
                    $response['message'] = 'Permission denied';
                    break;
                }
                
                $resultIds = $_POST['result_ids'] ?? $input['result_ids'] ?? [];
                
                if (empty($resultIds)) {
                    $response['message'] = 'No results selected';
                    break;
                }
                
                $placeholders = implode(',', array_fill(0, count($resultIds), '?'));
                $params = $resultIds;
                $params[] = $_SESSION['user_id'];
                
                try {
                    $db->query(
                        "UPDATE results SET is_approved = 1, approved_by = ?, approved_at = NOW() 
                         WHERE id IN ($placeholders)",
                        $params
                    );
                    
                    Security::logAudit('APPROVED_RESULTS_API', 'results');
                    
                    $response['success'] = true;
                    $response['message'] = 'Results approved successfully';
                    
                } catch (Exception $e) {
                    $response['message'] = 'Database error: ' . $e->getMessage();
                }
                break;
        }
        break;
}

echo json_encode($response);
?>