<?php
// api/generate-login.php
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
    
    $studentId = Security::sanitize($input['student_id'] ?? '');
    
    if (!$studentId) {
        $response['message'] = 'Student ID required';
        echo json_encode($response);
        exit;
    }
    
    $db = db();
    
    // Get student info
    $student = $db->getRow(
        "SELECT s.*, u.username, u.email 
         FROM students s 
         JOIN users u ON s.user_id = u.id 
         WHERE s.id = ?",
        [$studentId]
    );
    
    if (!$student) {
        $response['message'] = 'Student not found';
        echo json_encode($response);
        exit;
    }
    
    // Generate new password
    $password = generateRandomString(8);
    $passwordHash = Security::hashPassword($password);
    
    // Update user password
    $db->query(
        "UPDATE users SET password_hash = ? WHERE id = ?",
        [$passwordHash, $student['user_id']]
    );
    
    Security::logAudit('GENERATED_LOGIN', 'users', $student['user_id']);
    
    $response['success'] = true;
    $response['username'] = $student['username'];
    $response['password'] = $password;
}

echo json_encode($response);

function generateRandomString($length = 8) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[random_int(0, $charactersLength - 1)];
    }
    return $randomString;
}
?>