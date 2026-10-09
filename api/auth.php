<?php
// api/auth.php - login / logout / session check
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/auth.php';

$action = $_REQUEST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'csrf') {
    api_ok(['csrf_token' => Security::generateCSRFToken()]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Method not allowed', 405);
}

$input = $_POST;
$json = json_decode(file_get_contents('php://input'), true);
if (is_array($json)) { $input = $json + $input; }
$action = $input['action'] ?? '';

switch ($action) {
    case 'login':
        // Login needs the token issued with the session (see ?action=csrf)
        if (!Security::verifyCSRFToken($input[CSRF_TOKEN_NAME] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
            api_error('Invalid security token', 419);
        }
        $result = (new Auth())->login(Security::sanitize($input['email'] ?? ''), (string)($input['password'] ?? ''));
        if (!$result['success']) {
            api_error($result['message'], 401);
        }
        api_ok(['role' => $result['role'], 'redirect' => $result['redirect'], 'csrf_token' => Security::generateCSRFToken()]);

    case 'logout':
        if (!Security::verifyCSRFToken($input[CSRF_TOKEN_NAME] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
            api_error('Invalid security token', 419);
        }
        (new Auth())->logout();
        api_ok();

    case 'check_session':
        if (!Security::isLoggedIn()) {
            json_response(['success' => false, 'message' => 'Not logged in']);
        }
        api_ok(['user' => ['name' => $_SESSION['user_name'], 'role' => $_SESSION['user_role']]]);

    default:
        api_error('Invalid action');
}
