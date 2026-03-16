<?php
// api/update-subject-assignment.php
header('Content-Type: application/json');
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/security.php';

Security::requireRole('admin');

$response = ['success' => false];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!Security::verifyCSRFToken($input['csrf_token'] ?? '')) {
        $response['message'] = 'Invalid security token';
        echo json_encode($response);
        exit;
    }
    
    $subjectId = Security::sanitize($input['subject_id'] ?? '');
    $teacherId = Security::sanitize($input['teacher_id'] ?? '');
    $action = $input['action'] ?? '';
    
    if (!$subjectId || !$teacherId || !in_array($action, ['assign', 'unassign'])) {
        $response['message'] = 'Invalid parameters';
        echo json_encode($response);
        exit;
    }
    
    $db = db();
    
    try {
        if ($action === 'assign') {
            $db->query(
                "UPDATE subjects SET teacher_id = ? WHERE id = ?",
                [$teacherId, $subjectId]
            );
        } else {
            $db->query(
                "UPDATE subjects SET teacher_id = NULL WHERE id = ? AND teacher_id = ?",
                [$subjectId, $teacherId]
            );
        }
        
        Security::logAudit('UPDATED_SUBJECT_ASSIGNMENT', 'subjects', $subjectId);
        
        $response['success'] = true;
        
    } catch (Exception $e) {
        $response['message'] = 'Database error: ' . $e->getMessage();
    }
}

echo json_encode($response);
?>