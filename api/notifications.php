<?php
// api/notifications.php
header('Content-Type: application/json');
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/security.php';

Security::requireLogin();

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    $db = db();
    $userId = $_SESSION['user_id'];
    
    switch ($action) {
        case 'get_unread':
            // Get unread messages
            $messages = $db->getRows(
                "SELECT m.*, 
                        CONCAT(u.first_name, ' ', u.last_name) as sender_name,
                        u.role as sender_role
                 FROM messages m
                 JOIN users u ON m.sender_id = u.id
                 WHERE m.receiver_id = ? AND m.is_read = 0
                 ORDER BY m.created_at DESC",
                [$userId]
            );
            
            // Get recent announcements
            $announcements = $db->getRows(
                "SELECT * FROM announcements 
                 WHERE (audience = 'all' OR audience = ?) 
                   AND is_published = 1 
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                 ORDER BY created_at DESC",
                [$_SESSION['user_role']]
            );
            
            $response['success'] = true;
            $response['data'] = [
                'messages' => $messages,
                'announcements' => $announcements,
                'total_unread' => count($messages)
            ];
            break;
            
        case 'mark_read':
            $messageId = Security::sanitize($_GET['message_id'] ?? '');
            
            $db->query(
                "UPDATE messages SET is_read = 1, read_at = NOW() 
                 WHERE id = ? AND receiver_id = ?",
                [$messageId, $userId]
            );
            
            $response['success'] = true;
            break;
            
        case 'get_count':
            $count = $db->getRow(
                "SELECT COUNT(*) as count FROM messages 
                 WHERE receiver_id = ? AND is_read = 0",
                [$userId]
            )['count'];
            
            $response['success'] = true;
            $response['count'] = $count;
            break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $response['message'] = 'Invalid security token';
        echo json_encode($response);
        exit;
    }
    
    $db = db();
    $userId = $_SESSION['user_id'];
    
    switch ($action) {
        case 'send_message':
            $receiverId = Security::sanitize($_POST['receiver_id'] ?? '');
            $subject = Security::sanitize($_POST['subject'] ?? '');
            $message = Security::sanitize($_POST['message'] ?? '');
            
            if (empty($receiverId) || empty($message)) {
                $response['message'] = 'Receiver and message required';
                break;
            }
            
            $db->insert(
                "INSERT INTO messages (sender_id, receiver_id, subject, message) 
                 VALUES (?, ?, ?, ?)",
                [$userId, $receiverId, $subject, $message]
            );
            
            // Send email notification if enabled
            $receiver = $db->getRow(
                "SELECT email, first_name FROM users WHERE id = ?",
                [$receiverId]
            );
            
            if ($receiver) {
                $emailSubject = "New Message from " . SCHOOL_NAME;
                $emailMessage = "
                <html>
                <body>
                    <h3>You have a new message</h3>
                    <p>Dear {$receiver['first_name']},</p>
                    <p>You have received a new message from {$_SESSION['user_name']}.</p>
                    <p>Please login to your dashboard to view the message.</p>
                    <p><a href='" . BASE_URL . "/login.php'>Login Here</a></p>
                </body>
                </html>
                ";
                sendEmail($receiver['email'], $emailSubject, $emailMessage);
            }
            
            Security::logAudit('SENT_MESSAGE', 'messages');
            
            $response['success'] = true;
            $response['message'] = 'Message sent successfully';
            break;
    }
}

echo json_encode($response);
?>