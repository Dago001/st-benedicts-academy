<?php
// forgot-password.php - request a password reset link
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/includes/auth_layout.php';

if (Security::isLoggedIn()) {
    header('Location: ' . BASE_URL . '/' . $_SESSION['user_role'] . '/dashboard.php');
    exit;
}

$sent = false;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        $email = Security::sanitize($_POST['email'] ?? '');
        if (!Security::validateEmail($email)) {
            $error = 'Please enter a valid email address.';
        } else {
            $db = db();
            // Throttle: at most 3 requests per account per hour
            $user = $db->getRow('SELECT id, first_name FROM users WHERE email = ? AND is_active = 1 AND deleted_at IS NULL', [$email]);
            if ($user) {
                $recent = $db->getRow('SELECT COUNT(*) c FROM password_resets WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)', [$user['id']])['c'];
                if ($recent < 3) {
                    $token = bin2hex(random_bytes(32));
                    $db->query(
                        'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))',
                        [$user['id'], hash('sha256', $token)]
                    );
                    $link = BASE_URL . '/reset-password.php?token=' . $token;
                    sendEmail($email, 'Reset your password',
                        '<p>Hello ' . e($user['first_name']) . ',</p><p>Use the link below to reset your password. It expires in one hour.</p>'
                        . '<p><a href="' . e($link) . '">Reset password</a></p><p>If you did not request this, ignore this email.</p>');
                    if (DEBUG_MODE) {
                        error_log('Password reset link (dev): ' . $link);
                    }
                }
            }
            // Same response whether or not the account exists
            $sent = true;
        }
    }
}
auth_page_start('Forgot Password'); ?>
<h1>Forgot your password?</h1>
<p class="sub">Enter your email and we will send you a reset link.</p>
<?php if ($error): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>
<?php if ($sent): ?>
    <div class="alert alert-success">If an account exists for that email, a reset link has been sent.</div>
<?php else: ?>
<form method="POST">
    <?php echo csrf_field(); ?>
    <div class="form-group">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" required autofocus autocomplete="username" inputmode="email" value="<?php echo e($_POST['email'] ?? ''); ?>">
    </div>
    <button type="submit" class="btn btn-primary">Send reset link</button>
</form>
<?php endif; ?>
<div class="links"><a href="<?php echo BASE_URL; ?>/login.php">Back to sign in</a></div>
<?php auth_page_end();
