<?php
// includes/messaging.php - one-to-one messaging between school users (parent, teacher pages)

/** Teacher ids (teachers.id) teaching or leading the given class. */
function messaging_teacher_class_sql() {
    return 'SELECT id FROM classes WHERE teacher_id = ? UNION SELECT class_id FROM subjects WHERE teacher_id = ?';
}

/**
 * Users the logged-in user may message: admins anyone; everyone may message
 * admins; teachers <-> parents/students connected through a class.
 */
function messaging_recipients() {
    $db = db();
    $me = (int)$_SESSION['user_id'];
    $role = $_SESSION['user_role'];
    $out = [];

    if ($role === 'admin') {
        $out = $db->getRows("SELECT id, first_name, last_name, role FROM users WHERE is_active = 1 AND deleted_at IS NULL AND id <> ? ORDER BY role, first_name LIMIT 1000", [$me]);
    } else {
        $out = $db->getRows("SELECT id, first_name, last_name, role FROM users WHERE role = 'admin' AND is_active = 1 AND deleted_at IS NULL ORDER BY first_name");
        $tid = Security::currentTeacherId() ?? 0;
        if ($role === 'teacher') {
            $out = array_merge($out, $db->getRows(
                "SELECT DISTINCT u.id, u.first_name, u.last_name, u.role FROM students s
                 JOIN parents p ON s.parent_id = p.id JOIN users u ON p.user_id = u.id
                 WHERE u.is_active = 1 AND s.class_id IN (" . messaging_teacher_class_sql() . ") ORDER BY u.first_name", [$tid, $tid]));
        } elseif ($role === 'parent' || $role === 'student') {
            $classIds = $role === 'parent'
                ? array_column($db->getRows('SELECT DISTINCT class_id FROM students WHERE parent_id = ? AND class_id IS NOT NULL', [Security::currentParentId() ?? 0]), 'class_id')
                : array_column($db->getRows('SELECT class_id FROM students WHERE id = ? AND class_id IS NOT NULL', [Security::currentStudentId() ?? 0]), 'class_id');
            if ($classIds) {
                $in = implode(',', array_fill(0, count($classIds), '?'));
                $out = array_merge($out, $db->getRows(
                    "SELECT DISTINCT u.id, u.first_name, u.last_name, u.role FROM teachers t JOIN users u ON t.user_id = u.id
                     WHERE u.is_active = 1 AND (t.id IN (SELECT teacher_id FROM classes WHERE id IN ($in) AND teacher_id IS NOT NULL)
                        OR t.id IN (SELECT teacher_id FROM subjects WHERE class_id IN ($in) AND teacher_id IS NOT NULL))
                     ORDER BY u.first_name", array_merge($classIds, $classIds)));
            }
        }
    }
    return $out;
}

function can_message_user($receiverId) {
    $receiverId = (int)$receiverId;
    if ($receiverId === (int)$_SESSION['user_id']) return false;
    foreach (messaging_recipients() as $r) {
        if ((int)$r['id'] === $receiverId) return true;
    }
    return false;
}

/** Latest message per conversation partner, newest first. */
function messaging_conversations($uid) {
    return db()->getRows(
        "SELECT m.id, m.sender_id, m.receiver_id, m.subject, m.message, m.is_read, m.created_at,
                o.id AS other_id, CONCAT(o.first_name, ' ', o.last_name) AS other_user, o.role AS other_role,
                (SELECT COUNT(*) FROM messages x WHERE x.sender_id = o.id AND x.receiver_id = ? AND x.is_read = 0) AS unread
         FROM messages m
         JOIN users o ON o.id = IF(m.sender_id = ?, m.receiver_id, m.sender_id)
         WHERE m.id IN (
             SELECT MAX(id) FROM messages WHERE sender_id = ? OR receiver_id = ?
             GROUP BY IF(sender_id = ?, receiver_id, sender_id)
         )
         ORDER BY m.created_at DESC, m.id DESC",
        [$uid, $uid, $uid, $uid, $uid]
    );
}

/** Validate and store a message; returns [ok, text]. */
function messaging_send($receiverId, $subject, $text) {
    $receiverId = (int)$receiverId;
    $subject = mb_substr(Security::sanitize($subject), 0, 200);
    $text = trim(Security::sanitize($text));
    if (!$receiverId || $text === '') return [false, 'Please choose a recipient and write a message'];
    if (mb_strlen($text) > 5000) return [false, 'Message is too long (max 5000 characters)'];
    if (!can_message_user($receiverId)) return [false, 'You cannot message this user'];
    $id = db()->insert('INSERT INTO messages (sender_id, receiver_id, subject, message) VALUES (?, ?, ?, ?)',
        [$_SESSION['user_id'], $receiverId, $subject, $text]);
    Security::logAudit('SENT_MESSAGE', 'messages', $id);
    return [true, 'Message sent successfully'];
}

/** Full messaging page for a role (parent / teacher). */
function render_messages_page($role) {
    $db = db();
    $userId = (int)$_SESSION['user_id'];
    [$message, $messageType] = flash_get();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $message = 'Invalid security token';
            $messageType = 'error';
        } else {
            try {
                [$ok, $message] = messaging_send($_POST['receiver_id'] ?? 0, $_POST['subject'] ?? '', $_POST['message'] ?? '');
                $messageType = $ok ? 'success' : 'error';
            } catch (Exception $e) {
                error_log('messaging_send: ' . $e->getMessage());
                $message = 'Error sending message';
                $messageType = 'error';
            }
        }
        if ($messageType === 'success') {
            flash_redirect($message, 'success', BASE_URL . '/' . $role . '/messages.php' . (!empty($_POST['receiver_id']) ? '?conversation=' . (int)$_POST['receiver_id'] : ''));
        }
    }

    $recipients = messaging_recipients();
    $conversationId = isset($_GET['conversation']) ? (int)$_GET['conversation'] : 0;
    $messages = [];
    $otherUser = null;
    if ($conversationId) {
        $otherUser = $db->getRow('SELECT id, first_name, last_name, role FROM users WHERE id = ? AND deleted_at IS NULL', [$conversationId]);
        if ($otherUser) {
            $db->query('UPDATE messages SET is_read = 1, read_at = NOW() WHERE sender_id = ? AND receiver_id = ? AND is_read = 0', [$conversationId, $userId]);
            $messages = $db->getRows(
                "SELECT m.* FROM messages m
                 WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)
                 ORDER BY m.created_at ASC, m.id ASC",
                [$userId, $conversationId, $conversationId, $userId]);
        }
    }
    $conversations = messaging_conversations($userId);
    $canReply = $otherUser && can_message_user($conversationId);

    echo '<link rel="stylesheet" href="' . e(BASE_URL) . '/assets/css/messages.css">';
    dashboard_open($role, 'Messages', '<button type="button" class="btn btn-primary" onclick="showComposeModal()"><i class="fas fa-plus"></i> New Message</button>');
    render_alert($message, $messageType);
    ?>
    <div class="messages-container">
        <div class="conversations-list">
            <div class="list-header"><h3>Conversations</h3></div>
            <div class="list-body">
                <?php foreach ($conversations as $conv): ?>
                <a href="?conversation=<?php echo (int)$conv['other_id']; ?>"
                   class="conversation-item <?php echo $conv['unread'] > 0 ? 'unread' : ''; ?> <?php echo $conversationId === (int)$conv['other_id'] ? 'active' : ''; ?>">
                    <div class="conversation-avatar"><i class="fas fa-user-circle"></i></div>
                    <div class="conversation-info">
                        <div class="conversation-name"><?php echo e($conv['other_user']); ?> <small class="role-badge"><?php echo e(ucfirst($conv['other_role'])); ?></small></div>
                        <div class="conversation-preview"><?php echo e(mb_substr($conv['message'], 0, 50)); ?></div>
                        <div class="conversation-time"><?php echo e(timeAgo($conv['created_at'])); ?></div>
                    </div>
                    <?php if ($conv['unread'] > 0): ?><span class="unread-badge" title="<?php echo (int)$conv['unread']; ?> unread"></span><?php endif; ?>
                </a>
                <?php endforeach; ?>
                <?php if (!$conversations): ?><div class="text-center p-3 text-muted">No conversations yet</div><?php endif; ?>
            </div>
        </div>

        <div class="messages-view">
            <?php if ($otherUser): ?>
            <div class="messages-header">
                <h3>Conversation with <?php echo e($otherUser['first_name'] . ' ' . $otherUser['last_name']); ?>
                    <small class="role-badge"><?php echo e(ucfirst($otherUser['role'])); ?></small></h3>
            </div>
            <div class="messages-body" id="messagesBody">
                <?php foreach ($messages as $msg): ?>
                <div class="message <?php echo (int)$msg['sender_id'] === $userId ? 'message-out' : 'message-in'; ?>">
                    <div class="message-content">
                        <?php if ($msg['subject']): ?><div class="message-subject"><?php echo e($msg['subject']); ?></div><?php endif; ?>
                        <div class="message-text"><?php echo nl2br(e($msg['message'])); ?></div>
                        <div class="message-time"><?php echo e(date('d M Y, h:i A', strtotime($msg['created_at']))); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (!$messages): ?><div class="text-center p-4 text-muted">No messages yet. Say hello below.</div><?php endif; ?>
            </div>
            <?php if ($canReply): ?>
            <div class="messages-footer">
                <form method="POST" class="reply-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="receiver_id" value="<?php echo (int)$conversationId; ?>">
                    <div class="input-group">
                        <input type="text" name="subject" class="form-control" placeholder="Subject (optional)" maxlength="200">
                        <textarea name="message" class="form-control" rows="2" placeholder="Type your reply..." required maxlength="5000"></textarea>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Send</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <div class="text-center p-5 text-muted">
                <i class="fas fa-envelope-open fa-3x mb-3"></i>
                <p>Select a conversation to start messaging</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php dashboard_close(); ?>

    <div id="composeModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="composeTitle">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="composeTitle">New Message</h3>
                <button type="button" class="close" aria-label="Close" onclick="closeCompose()">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <?php echo csrf_field(); ?>
                    <div class="form-group">
                        <label for="receiver_id">To *</label>
                        <select id="receiver_id" name="receiver_id" class="form-control" required>
                            <option value="">-- Select Recipient --</option>
                            <?php foreach ($recipients as $r): ?>
                            <option value="<?php echo (int)$r['id']; ?>"><?php echo e($r['first_name'] . ' ' . $r['last_name'] . ' (' . ucfirst($r['role']) . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$recipients): ?><small class="form-text text-muted">No recipients are available for your account yet.</small><?php endif; ?>
                    </div>
                    <div class="form-group"><label for="subject">Subject</label><input type="text" id="subject" name="subject" class="form-control" maxlength="200"></div>
                    <div class="form-group"><label for="message">Message *</label><textarea id="message" name="message" class="form-control" rows="5" required maxlength="5000"></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeCompose()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Send Message</button>
                </div>
            </form>
        </div>
    </div>
    <script>
    function showComposeModal() { document.getElementById('composeModal').style.display = 'block'; }
    function closeCompose() { document.getElementById('composeModal').style.display = 'none'; }
    document.addEventListener('DOMContentLoaded', function () {
        var body = document.getElementById('messagesBody');
        if (body) body.scrollTop = body.scrollHeight;
    });
    document.getElementById('composeModal').addEventListener('click', function (e) { if (e.target === this) closeCompose(); });
    </script>
    <?php
}
