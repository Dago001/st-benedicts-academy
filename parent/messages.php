<?php
// parent/messages.php - Parent Messaging System
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/security.php';

Security::requireRole('parent');

$pageTitle = 'Messages';
$extraCSS = ['dashboard.css'];
$extraJS = ['messages.js'];

include '../includes/header.php';

$db = db();
$userId = $_SESSION['user_id'];
$message = '';
$messageType = '';

// Get parent info
$parent = $db->getRow(
    "SELECT p.*, u.first_name, u.last_name 
     FROM parents p 
     JOIN users u ON p.user_id = u.id 
     WHERE p.user_id = ?",
    [$userId]
);

// Get children of this parent
$children = $db->getRows(
    "SELECT s.id, u.first_name, u.last_name, c.class_name, c.section
     FROM students s 
     JOIN users u ON s.user_id = u.id 
     LEFT JOIN classes c ON s.class_id = c.id 
     WHERE s.parent_id = ? AND u.is_active = 1",
    [$parent['id']]
);

// Handle message sending
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token';
        $messageType = 'error';
    } else {
        $receiverId = Security::sanitize($_POST['receiver_id']);
        $subject = Security::sanitize($_POST['subject']);
        $messageText = Security::sanitize($_POST['message']);
        
        if (empty($receiverId) || empty($messageText)) {
            $message = 'Please fill in all required fields';
            $messageType = 'error';
        } else {
            try {
                $db->insert(
                    "INSERT INTO messages (sender_id, receiver_id, subject, message) 
                     VALUES (?, ?, ?, ?)",
                    [$userId, $receiverId, $subject, $messageText]
                );
                
                Security::logAudit('SENT_MESSAGE', 'messages');
                
                $message = 'Message sent successfully';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Error sending message';
                $messageType = 'error';
            }
        }
    }
}

// Get teachers for messaging
$teachers = $db->getRows(
    "SELECT u.id, u.first_name, u.last_name, t.employee_id,
            (SELECT GROUP_CONCAT(c.class_name SEPARATOR ', ') 
             FROM classes c WHERE c.teacher_id = t.id) as classes
     FROM users u
     JOIN teachers t ON u.id = t.user_id
     WHERE u.role = 'teacher' AND u.is_active = 1
     ORDER BY u.first_name"
);

// Get conversation list
$conversations = $db->getRows(
    "SELECT 
        m.id,
        m.sender_id,
        m.receiver_id,
        m.subject,
        m.message,
        m.is_read,
        m.created_at,
        CASE 
            WHEN m.sender_id = ? THEN CONCAT(u2.first_name, ' ', u2.last_name)
            ELSE CONCAT(u1.first_name, ' ', u1.last_name)
        END as other_user,
        CASE 
            WHEN m.sender_id = ? THEN u2.role
            ELSE u1.role
        END as other_role
     FROM messages m
     JOIN users u1 ON m.sender_id = u1.id
     JOIN users u2 ON m.receiver_id = u2.id
     WHERE m.sender_id = ? OR m.receiver_id = ?
     GROUP BY CASE 
         WHEN m.sender_id = ? THEN m.receiver_id 
         ELSE m.sender_id 
     END
     ORDER BY MAX(m.created_at) DESC",
    [$userId, $userId, $userId, $userId, $userId]
);

// Get selected conversation
$conversationId = $_GET['conversation'] ?? null;
$messages = [];

if ($conversationId) {
    // Mark messages as read
    $db->query(
        "UPDATE messages SET is_read = 1, read_at = NOW() 
         WHERE sender_id = ? AND receiver_id = ? AND is_read = 0",
        [$conversationId, $userId]
    );
    
    // Get messages
    $messages = $db->getRows(
        "SELECT m.*, 
                CONCAT(u.first_name, ' ', u.last_name) as sender_name,
                u.role as sender_role
         FROM messages m
         JOIN users u ON m.sender_id = u.id
         WHERE (m.sender_id = ? AND m.receiver_id = ?) 
            OR (m.sender_id = ? AND m.receiver_id = ?)
         ORDER BY m.created_at ASC",
        [$userId, $conversationId, $conversationId, $userId]
    );
}

// Get unread count
$unreadCount = $db->getRow(
    "SELECT COUNT(*) as count FROM messages 
     WHERE receiver_id = ? AND is_read = 0",
    [$userId]
)['count'];
?>

<div class="dashboard-container">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <h3>Parent Portal</h3>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="child-performance.php"><i class="fas fa-chart-line"></i> Child Performance</a></li>
                <li><a href="fees.php"><i class="fas fa-money-bill"></i> Fee Status</a></li>
                <li class="active"><a href="messages.php"><i class="fas fa-envelope"></i> Messages 
                    <?php if ($unreadCount > 0): ?>
                    <span class="badge badge-danger"><?php echo $unreadCount; ?></span>
                    <?php endif; ?>
                </a></li>
                <li><a href="profile.php"><i class="fas fa-user-cog"></i> Profile</a></li>
            </ul>
        </nav>
    </aside>
    
    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>Messages</h1>
            <button class="btn btn-primary" onclick="showComposeModal()">
                <i class="fas fa-plus"></i> New Message
            </button>
        </div>
        
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible">
            <?php echo $message; ?>
            <button type="button" class="close" onclick="this.parentElement.remove()">&times;</button>
        </div>
        <?php endif; ?>
        
        <div class="messages-container">
            <!-- Conversations List -->
            <div class="conversations-list">
                <div class="list-header">
                    <h3>Conversations</h3>
                </div>
                <div class="list-body">
                    <?php foreach ($conversations as $conv): ?>
                    <a href="?conversation=<?php echo $conv['sender_id'] == $userId ? $conv['receiver_id'] : $conv['sender_id']; ?>" 
                       class="conversation-item <?php echo (!$conv['is_read'] && $conv['receiver_id'] == $userId) ? 'unread' : ''; ?>
                              <?php echo ($conversationId && ($conv['sender_id'] == $conversationId || $conv['receiver_id'] == $conversationId)) ? 'active' : ''; ?>">
                        <div class="conversation-avatar">
                            <i class="fas fa-user-circle"></i>
                        </div>
                        <div class="conversation-info">
                            <div class="conversation-name">
                                <?php echo htmlspecialchars($conv['other_user']); ?>
                                <small class="role-badge"><?php echo ucfirst($conv['other_role']); ?></small>
                            </div>
                            <div class="conversation-preview">
                                <?php echo substr(htmlspecialchars($conv['message']), 0, 50); ?>...
                            </div>
                            <div class="conversation-time">
                                <?php echo timeAgo($conv['created_at']); ?>
                            </div>
                        </div>
                        <?php if (!$conv['is_read'] && $conv['receiver_id'] == $userId): ?>
                        <span class="unread-badge"></span>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                    
                    <?php if (empty($conversations)): ?>
                    <div class="text-center p-3 text-muted">
                        No conversations yet
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Messages View -->
            <div class="messages-view">
                <?php if ($conversationId && !empty($messages)): ?>
                <div class="messages-header">
                    <h3>
                        Conversation with 
                        <?php 
                        $otherUser = $db->getRow(
                            "SELECT first_name, last_name, role FROM users WHERE id = ?",
                            [$conversationId]
                        );
                        echo htmlspecialchars($otherUser['first_name'] . ' ' . $otherUser['last_name']);
                        ?>
                        <small class="role-badge"><?php echo ucfirst($otherUser['role']); ?></small>
                    </h3>
                </div>
                
                <div class="messages-body" id="messagesBody">
                    <?php foreach ($messages as $msg): ?>
                    <div class="message <?php echo $msg['sender_id'] == $userId ? 'message-out' : 'message-in'; ?>">
                        <div class="message-content">
                            <?php if ($msg['subject']): ?>
                            <div class="message-subject"><?php echo htmlspecialchars($msg['subject']); ?></div>
                            <?php endif; ?>
                            <div class="message-text"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div>
                            <div class="message-time">
                                <?php echo date('d M Y, h:i A', strtotime($msg['created_at'])); ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="messages-footer">
                    <form method="POST" class="reply-form">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                        <input type="hidden" name="receiver_id" value="<?php echo $conversationId; ?>">
                        <div class="input-group">
                            <input type="text" name="subject" class="form-control" placeholder="Subject (optional)">
                            <textarea name="message" class="form-control" rows="2" placeholder="Type your reply..." required></textarea>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Send
                            </button>
                        </div>
                    </form>
                </div>
                
                <?php elseif ($conversationId): ?>
                <div class="text-center p-5 text-muted">
                    <i class="fas fa-comments fa-3x mb-3"></i>
                    <p>No messages in this conversation yet.</p>
                </div>
                <?php else: ?>
                <div class="text-center p-5 text-muted">
                    <i class="fas fa-envelope-open fa-3x mb-3"></i>
                    <p>Select a conversation to start messaging</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<!-- Compose Modal -->
<div id="composeModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>New Message</h3>
            <button type="button" class="close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                
                <div class="form-group">
                    <label for="receiver_id">To *</label>
                    <select id="receiver_id" name="receiver_id" class="form-control" required>
                        <option value="">-- Select Recipient --</option>
                        <optgroup label="Teachers">
                            <?php foreach ($teachers as $teacher): ?>
                            <option value="<?php echo $teacher['id']; ?>">
                                <?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?>
                                <?php if ($teacher['classes']): ?>
                                (<?php echo $teacher['classes']; ?>)
                                <?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="subject">Subject</label>
                    <input type="text" id="subject" name="subject" class="form-control">
                </div>
                
                <div class="form-group">
                    <label for="message">Message *</label>
                    <textarea id="message" name="message" class="form-control" rows="5" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Send Message</button>
            </div>
        </form>
    </div>
</div>

<style>
.messages-container {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 20px;
    background: white;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    height: calc(100vh - 200px);
}

.conversations-list {
    border-right: 1px solid #eee;
    background: #f8f9fa;
    overflow-y: auto;
}

.list-header {
    padding: 15px;
    border-bottom: 1px solid #eee;
    background: white;
}

.list-header h3 {
    margin: 0;
    font-size: 1.1rem;
}

.list-body {
    overflow-y: auto;
}

.conversation-item {
    display: flex;
    padding: 15px;
    border-bottom: 1px solid #eee;
    text-decoration: none;
    color: inherit;
    transition: background 0.3s ease;
    position: relative;
}

.conversation-item:hover {
    background: #f0f0f0;
}

.conversation-item.active {
    background: #e3f2fd;
    border-left: 3px solid var(--navy);
}

.conversation-item.unread {
    background: #fff3e0;
}

.conversation-avatar {
    width: 40px;
    height: 40px;
    background: var(--navy);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--gold);
    margin-right: 10px;
    flex-shrink: 0;
}

.conversation-info {
    flex: 1;
    min-width: 0;
}

.conversation-name {
    font-weight: 600;
    margin-bottom: 3px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.role-badge {
    font-size: 0.7rem;
    background: #e0e0e0;
    padding: 2px 6px;
    border-radius: 10px;
    margin-left: 5px;
    font-weight: normal;
}

.conversation-preview {
    font-size: 0.85rem;
    color: #666;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 3px;
}

.conversation-time {
    font-size: 0.7rem;
    color: #999;
}

.unread-badge {
    position: absolute;
    top: 15px;
    right: 15px;
    width: 10px;
    height: 10px;
    background: var(--red);
    border-radius: 50%;
}

.messages-view {
    display: flex;
    flex-direction: column;
    height: 100%;
    background: white;
}

.messages-header {
    padding: 15px 20px;
    border-bottom: 1px solid #eee;
    background: #f8f9fa;
}

.messages-header h3 {
    margin: 0;
    font-size: 1.1rem;
}

.messages-body {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
    background: #f5f5f5;
}

.message {
    display: flex;
    margin-bottom: 20px;
}

.message-out {
    justify-content: flex-end;
}

.message-in {
    justify-content: flex-start;
}

.message-content {
    max-width: 70%;
    padding: 12px 15px;
    border-radius: 15px;
    position: relative;
}

.message-out .message-content {
    background: var(--navy);
    color: white;
    border-bottom-right-radius: 5px;
}

.message-in .message-content {
    background: white;
    border-bottom-left-radius: 5px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
}

.message-subject {
    font-weight: 600;
    margin-bottom: 5px;
    font-size: 0.9rem;
}

.message-out .message-subject {
    color: var(--gold);
}

.message-text {
    line-height: 1.5;
    word-wrap: break-word;
}

.message-time {
    font-size: 0.7rem;
    margin-top: 5px;
    opacity: 0.7;
    text-align: right;
}

.messages-footer {
    padding: 15px;
    border-top: 1px solid #eee;
    background: #f8f9fa;
}

.reply-form .input-group {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.reply-form input,
.reply-form textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 5px;
}

.reply-form button {
    align-self: flex-end;
    padding: 10px 30px;
}

@media (max-width: 768px) {
    .messages-container {
        grid-template-columns: 1fr;
        height: auto;
    }
    
    .conversations-list {
        max-height: 300px;
    }
    
    .message-content {
        max-width: 85%;
    }
}
</style>

<script>
function showComposeModal() {
    document.getElementById('composeModal').style.display = 'block';
}

function closeModal() {
    document.getElementById('composeModal').style.display = 'none';
}

// Scroll to bottom of messages
document.addEventListener('DOMContentLoaded', function() {
    const messagesBody = document.getElementById('messagesBody');
    if (messagesBody) {
        messagesBody.scrollTop = messagesBody.scrollHeight;
    }
});

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('composeModal');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
}

function timeAgo(datetime) {
    const date = new Date(datetime);
    const now = new Date();
    const seconds = Math.floor((now - date) / 1000);
    
    if (seconds < 60) return 'just now';
    if (seconds < 3600) return Math.floor(seconds / 60) + ' minutes ago';
    if (seconds < 86400) return Math.floor(seconds / 3600) + ' hours ago';
    return date.toLocaleDateString();
}
</script>

<?php
include '../includes/footer.php';
?>