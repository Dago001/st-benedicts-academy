<?php
// api/notifications.php - unread messages, announcements and sending messages
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/messaging.php';

$input = api_init(['GET', 'POST']);
$db = db();
$userId = (int)$_SESSION['user_id'];
$role = $_SESSION['user_role'];
$audienceFor = ['student' => 'students', 'teacher' => 'teachers', 'parent' => 'parents', 'admin' => 'admins'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    switch ($_GET['action'] ?? '') {
        case 'get_unread':
            $messages = $db->getRows(
                "SELECT m.id, m.subject, m.message, m.created_at,
                        CONCAT(u.first_name, ' ', u.last_name) AS sender_name, u.role AS sender_role
                 FROM messages m JOIN users u ON m.sender_id = u.id
                 WHERE m.receiver_id = ? AND m.is_read = 0 ORDER BY m.created_at DESC",
                [$userId]
            );
            $announcements = $db->getRows(
                "SELECT id, title, content, priority, created_at FROM announcements
                 WHERE (audience = 'all' OR audience = ?) AND is_published = 1
                   AND (expires_at IS NULL OR expires_at > NOW())
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                 ORDER BY created_at DESC",
                [$audienceFor[$role] ?? 'all']
            );
            api_ok(['data' => ['messages' => $messages, 'announcements' => $announcements, 'total_unread' => count($messages)]]);

        case 'mark_read':
            $id = api_int($_GET['message_id'] ?? null);
            if (!$id) api_error('Message ID required');
            $db->query('UPDATE messages SET is_read = 1, read_at = NOW() WHERE id = ? AND receiver_id = ?', [$id, $userId]);
            api_ok();

        case 'get_count':
            api_ok(['count' => (int)$db->getRow('SELECT COUNT(*) c FROM messages WHERE receiver_id = ? AND is_read = 0', [$userId])['c']]);

        default:
            api_error('Invalid action');
    }
}

if (($input['action'] ?? '') !== 'send_message') api_error('Invalid action');

$receiverId = api_int($input['receiver_id'] ?? null);
$subject = mb_substr(Security::sanitize($input['subject'] ?? ''), 0, 200);
$message = Security::sanitize($input['message'] ?? '');
if (!$receiverId || $message === '') api_error('Receiver and message required');
if (mb_strlen($message) > 5000) api_error('Message is too long');
if (!can_message_user($receiverId)) api_error('You cannot message this user', 403);

$id = $db->insert('INSERT INTO messages (sender_id, receiver_id, subject, message) VALUES (?, ?, ?, ?)', [$userId, $receiverId, $subject, $message]);
$receiver = $db->getRow('SELECT email, first_name FROM users WHERE id = ?', [$receiverId]);
if ($receiver) {
    sendEmail($receiver['email'], 'New Message from ' . SCHOOL_NAME,
        '<h3>You have a new message</h3><p>Dear ' . e($receiver['first_name']) . ',</p><p>You have received a new message from '
        . e($_SESSION['user_name']) . '.</p><p><a href="' . e(BASE_URL) . '/login.php">Log in to read it</a></p>');
}
Security::logAudit('SENT_MESSAGE', 'messages', $id);
api_ok(['message' => 'Message sent successfully']);
