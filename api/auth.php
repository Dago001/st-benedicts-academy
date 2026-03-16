<?php
// api/auth.php
header('Content-Type: application/json');
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/security.php';

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'login':
            $email = Security::sanitize($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            
            if (!Security::checkLoginAttempts($email)) {
                $response['message'] = 'Account temporarily locked';
                break;
            }
            
            $db = db();
            $user = $db->getRow(
                "SELECT id, password_hash, role, is_active, first_name, last_name 
                 FROM users WHERE email = ? AND deleted_at IS NULL",
                [$email]
            );
            
            if ($user && Security::verifyPassword($password, $user['password_hash'])) {
                if (!$user['is_active']) {
                    $response['message'] = 'Account deactivated';
                    break;
                }
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['user_role'] = $user['role'];
                
                $db->query(
                    "UPDATE users SET last_login = NOW(), login_attempts = 0, locked_until = NULL WHERE id = ?",
                    [$user['id']]
                );
                
                session_regenerate_id(true);
                
                $response['success'] = true;
                $response['role'] = $user['role'];
                $response['redirect'] = BASE_URL . '/' . $user['role'] . '/dashboard.php';
            } else {
                Security::logFailedAttempt($email);
                $response['message'] = 'Invalid email or password';
            }
            break;
            
        case 'logout':
            session_destroy();
            $response['success'] = true;
            break;
            
        case 'check_session':
            $response['success'] = Security::isLoggedIn();
            if ($response['success']) {
                $response['user'] = [
                    'name' => $_SESSION['user_name'],
                    'role' => $_SESSION['user_role']
                ];
            }
            break;
    }
}

echo json_encode($response);
?>