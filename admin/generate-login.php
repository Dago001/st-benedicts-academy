<?php
// admin/generate-login.php - reset a student's password and show the new one once
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
Security::requireRole('admin');

$db = db();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $db->getRow("SELECT s.id, s.user_id, s.admission_number, u.username, u.email, u.first_name, u.last_name
                   FROM students s JOIN users u ON s.user_id = u.id WHERE s.id = ?", [$id]);
$newPassword = null;
$error = '';

if ($st && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        do { $newPassword = generateRandomString(10); } while (!preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/\d/', $newPassword));
        $db->query('UPDATE users SET password_hash = ?, login_attempts = 0, locked_until = NULL WHERE id = ?', [Security::hashPassword($newPassword), $st['user_id']]);
        Security::logAudit('GENERATED_LOGIN', 'users', $st['user_id']);
    }
}

$pageTitle = 'Generate Login';
$extraCSS = ['dashboard.css'];
header('Cache-Control: no-store');
include __DIR__ . '/../includes/header.php';
dashboard_open('admin', 'Student Login');
if (!$st) {
    echo '<div class="alert alert-error">Student not found.</div>';
} else {
    render_alert($error, 'error'); ?>
    <div class="card"><div class="card-body">
        <p><strong><?php echo e($st['first_name'] . ' ' . $st['last_name']); ?></strong> (<?php echo e($st['admission_number']); ?>)<br>
        Username: <code><?php echo e($st['username']); ?></code> &middot; Email: <?php echo e($st['email']); ?></p>
        <?php if ($newPassword): ?>
            <div class="alert alert-success">
                <p><strong>New password (shown once):</strong></p>
                <p style="font-size:1.4rem;letter-spacing:1px"><code id="newPw"><?php echo e($newPassword); ?></code></p>
                <button type="button" class="btn btn-sm btn-outline" onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('newPw').textContent)">Copy</button>
            </div>
        <?php else: ?>
            <p>This creates a new random password for the student. The old password stops working immediately.</p>
            <form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo $id; ?>">
                <button type="submit" class="btn btn-primary" onclick="return confirm('Generate a new password for this student?')"><i class="fas fa-key"></i> Generate new password</button>
            </form>
        <?php endif; ?>
        <p style="margin-top:16px"><a href="students.php" class="btn btn-secondary">Back to students</a></p>
    </div></div>
<?php }
dashboard_close();
include __DIR__ . '/../includes/footer.php';
