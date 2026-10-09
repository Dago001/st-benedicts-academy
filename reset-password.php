<?php
// reset-password.php - choose a new password using an emailed token
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/includes/auth_layout.php';

$db = db();
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$row = null;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $row = $db->getRow(
        'SELECT id, user_id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()',
        [hash('sha256', $token)]
    );
}

$error = '';
if ($row && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw = $_POST['password'] ?? '';
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh and try again.';
    } elseif (strlen($pw) < 8 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
        $error = 'Password must be at least 8 characters and contain a letter and a number.';
    } elseif ($pw !== ($_POST['password_confirm'] ?? '')) {
        $error = 'Passwords do not match.';
    } else {
        $db->beginTransaction();
        $db->query('UPDATE users SET password_hash = ?, login_attempts = 0, locked_until = NULL WHERE id = ?', [Security::hashPassword($pw), $row['user_id']]);
        $db->query('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$row['user_id']]);
        $db->commit();
        header('Location: ' . BASE_URL . '/login?reset=1');
        exit;
    }
}
auth_page_start('Reset Password'); ?>
<h1>Reset password</h1>
<?php if (!$row): ?>
    <div class="alert alert-error">This reset link is invalid or has expired.</div>
    <div class="links"><a href="<?php echo BASE_URL; ?>/forgot-password">Request a new link</a></div>
<?php else: ?>
    <?php if ($error): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="token" value="<?php echo e($token); ?>">
        <div class="form-group"><label for="password">New password</label>
            <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password"></div>
        <div class="form-group"><label for="password_confirm">Confirm password</label>
            <input type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password"></div>
        <button type="submit" class="btn btn-primary">Update password</button>
    </form>
<?php endif;
auth_page_end();
