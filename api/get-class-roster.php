<?php
// api/get-class-roster.php
header('Content-Type: application/json');
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/security.php';

Security::requireLogin();

$response = ['success' => false];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $classId = Security::sanitize($_GET['class_id'] ?? '');
    
    if (!$classId) {
        $response['message'] = 'Class ID required';
        echo json_encode($response);
        exit;
    }
    
    $db = db();
    
    $roster = $db->getRows(
        "SELECT s.id, s.admission_number, 
                u.first_name, u.last_name, 
                s.gender,
                CONCAT(pu.first_name, ' ', pu.last_name) as parent_name
         FROM students s
         JOIN users u ON s.user_id = u.id
         LEFT JOIN parents p ON s.parent_id = p.id
         LEFT JOIN users pu ON p.user_id = pu.id
         WHERE s.class_id = ? AND u.is_active = 1 AND u.deleted_at IS NULL
         ORDER BY u.first_name, u.last_name",
        [$classId]
    );
    
    $response['success'] = true;
    $response['roster'] = $roster;
}

echo json_encode($response);
?>