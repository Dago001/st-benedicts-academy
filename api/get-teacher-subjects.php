<?php
// api/get-teacher-subjects.php
header('Content-Type: application/json');
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/security.php';

Security::requireLogin();

$response = ['success' => false];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $teacherId = Security::sanitize($_GET['teacher_id'] ?? '');
    
    if (!$teacherId) {
        $response['message'] = 'Teacher ID required';
        echo json_encode($response);
        exit;
    }
    
    $db = db();
    
    $subjects = $db->getRows(
        "SELECT s.*, c.class_name 
         FROM subjects s
         JOIN classes c ON s.class_id = c.id
         WHERE s.teacher_id = ? AND s.is_active = 1
         ORDER BY c.class_name, s.subject_name",
        [$teacherId]
    );
    
    $response['success'] = true;
    $response['subjects'] = $subjects;
}

echo json_encode($response);
?>