<?php
// change-password.php - Enforce mandatory first-time password update for users with temporary/OTP credentials
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

// User must be logged in to access this page
if (!Security::isLoggedIn()) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}

$role = $_SESSION['user_role'] ?? 'teacher';
$baseRoleDir = in_array($role, ['super_admin', 'admin', 'head_teacher', 'supervisor'], true) ? 'admin' : $role;

// If user does not need to change password, redirect directly to dashboard
if (empty($_SESSION['must_change_password'])) {
    header('Location: ' . BASE_URL . '/' . $baseRoleDir . '/dashboard');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$userName = $_SESSION['user_name'] ?? 'User';
$userEmail = $_SESSION['user_email'] ?? '';
$roleTitle = Security::roleBadge();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $error = 'Invalid security token. Please refresh the page and try again.';
        } else {
            $currentPassword = (string)($_POST['current_password'] ?? '');
            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
                $error = 'Please fill in all required password fields.';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'The new password and confirmation password do not match.';
            } elseif ($newPassword === $currentPassword) {
                $error = 'Your new password cannot be the same as your temporary one-time password.';
            } elseif ($pwErr = strong_password($newPassword)) {
                $error = $pwErr;
            } else {
                $auth = new Auth();
                $result = $auth->changePassword($userId, $currentPassword, $newPassword);
                if ($result['success']) {
                    $_SESSION['must_change_password'] = 0;
                    flash_set('Your confidential password has been set successfully! Welcome to your dashboard.', 'success');
                    header('Location: ' . BASE_URL . '/' . $baseRoleDir . '/dashboard');
                    exit;
                } else {
                    $error = $result['message'] ?? 'Failed to update password. Please check your current temporary password.';
                }
            }
        }
    } catch (Throwable $e) {
        $error = 'An unexpected error occurred. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password - <?php echo e(SITE_NAME); ?></title>
    <link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/images/favicon-32x32.png">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/vendor/fontawesome/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/fonts.css?v=34">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css?v=34">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/mobile.css?v=34">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/polish.css?v=34">
    <style>
        :root {
            --brand-navy: #002147;
            --brand-navy-deep: #001630;
            --brand-gold: #d4af37;
            --brand-red: #c41e3a;
        }

        body.cp-page-body {
            min-height: 100vh;
            min-height: 100dvh;
            margin: 0;
            padding: 24px 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, rgba(0, 33, 71, 0.94), rgba(0, 22, 48, 0.97)),
                        url('<?php echo BASE_URL; ?>/assets/images/background.png') center/cover fixed no-repeat;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            box-sizing: border-box;
        }

        .cp-card-wrapper {
            width: 100%;
            max-width: 480px;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 20px 50px rgba(0, 15, 35, 0.45);
            padding: 32px 28px;
            box-sizing: border-box;
        }

        .cp-header {
            text-align: center;
            margin-bottom: 22px;
        }

        .cp-logo {
            width: 68px;
            height: 68px;
            object-fit: contain;
            margin-bottom: 12px;
            filter: drop-shadow(0 3px 6px rgba(0,0,0,0.12));
        }

        .cp-title {
            margin: 0 0 6px 0;
            font-size: 1.45rem;
            font-weight: 800;
            color: var(--brand-navy);
            letter-spacing: -0.01em;
        }

        .cp-subtitle {
            margin: 0;
            font-size: 0.92rem;
            color: #64748b;
            line-height: 1.45;
        }

        .cp-user-badge {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 10px 14px;
            margin: 18px 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .cp-user-info {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .cp-user-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #002147;
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
            flex-shrink: 0;
        }

        .cp-user-details {
            min-width: 0;
        }

        .cp-user-name {
            display: block;
            font-size: 0.9rem;
            font-weight: 700;
            color: #0f172a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .cp-user-email {
            display: block;
            font-size: 0.78rem;
            color: #64748b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .cp-role-tag {
            font-size: 0.75rem;
            font-weight: 700;
            background: #e2e8f0;
            color: #1e293b;
            padding: 4px 10px;
            border-radius: 999px;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .cp-notice {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 20px;
            font-size: 0.86rem;
            color: #92400e;
            line-height: 1.5;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .cp-notice i {
            color: #d97706;
            margin-top: 2px;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .cp-alert-error {
            background: #fee2e2;
            border: 1px solid #fca5a5;
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 20px;
            color: #b91c1c;
            font-size: 0.88rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .cp-form-group {
            margin-bottom: 16px;
        }

        .cp-label {
            display: block;
            margin-bottom: 6px;
            font-size: 0.88rem;
            font-weight: 700;
            color: #1e293b;
        }

        .cp-input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .cp-input {
            width: 100%;
            height: 44px;
            padding: 0 42px 0 14px;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.95rem;
            color: #0f172a;
            box-sizing: border-box;
            background: #f8fafc;
            transition: all 0.2s ease;
        }

        .cp-input:focus {
            outline: none;
            border-color: #002147;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(0, 33, 71, 0.12);
        }

        .cp-pw-toggle {
            position: absolute;
            right: 12px;
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            transition: color 0.15s ease;
        }

        .cp-pw-toggle:hover {
            color: #002147;
        }

        .cp-requirements {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 14px;
            margin-bottom: 22px;
            font-size: 0.78rem;
            color: #475569;
        }

        .cp-requirements ul {
            margin: 6px 0 0 0;
            padding-left: 20px;
        }

        .cp-requirements li {
            margin-bottom: 2px;
        }

        .cp-submit-btn {
            width: 100%;
            height: 46px;
            border: none;
            border-radius: 8px;
            background: #c41e3a;
            color: #ffffff;
            font-size: 0.96rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(196, 30, 58, 0.25);
        }

        .cp-submit-btn:hover {
            background: #9b1429;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(196, 30, 58, 0.35);
        }

        .cp-footer-links {
            text-align: center;
            margin-top: 18px;
            font-size: 0.85rem;
        }

        .cp-footer-links a {
            color: #64748b;
            text-decoration: none;
            transition: color 0.15s ease;
        }

        .cp-footer-links a:hover {
            color: #002147;
            text-decoration: underline;
        }
    </style>
</head>
<body class="cp-page-body">
    <div class="cp-card-wrapper">
        <div class="cp-header">
            <img src="<?php echo BASE_URL; ?>/assets/images/logo.png" alt="St. Benedict's Academy Crest" class="cp-logo">
            <h1 class="cp-title">Create Your New Password</h1>
            <p class="cp-subtitle">Mandatory Security Setup for First-Time Login</p>
        </div>

        <div class="cp-user-badge">
            <div class="cp-user-info">
                <span class="cp-user-icon"><i class="fas fa-user-shield"></i></span>
                <div class="cp-user-details">
                    <span class="cp-user-name"><?php echo e($userName); ?></span>
                    <span class="cp-user-email"><?php echo e($userEmail); ?></span>
                </div>
            </div>
            <span class="cp-role-tag"><?php echo e($roleTitle); ?></span>
        </div>

        <div class="cp-notice">
            <i class="fas fa-lock"></i>
            <div>
                You logged in with a temporary generated password. To ensure your account security and confidentiality, please set a personal password before accessing your dashboard.
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="cp-alert-error" role="alert">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo e($error); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?php echo BASE_URL; ?>/change-password" autocomplete="off" id="changePasswordForm">
            <?php echo csrf_field(); ?>

            <div class="cp-form-group">
                <label class="cp-label" for="current_password">Temporary One-Time Password *</label>
                <div class="cp-input-group">
                    <input type="password" id="current_password" name="current_password" class="cp-input" placeholder="Enter the OTP you logged in with" required autocomplete="current-password">
                    <button type="button" class="cp-pw-toggle" onclick="toggleVisibility('current_password', this)" aria-label="Toggle password visibility">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <div class="cp-form-group">
                <label class="cp-label" for="new_password">New Confidential Password *</label>
                <div class="cp-input-group">
                    <input type="password" id="new_password" name="new_password" class="cp-input" placeholder="At least 8 characters with letters &amp; numbers" required minlength="8" autocomplete="new-password">
                    <button type="button" class="cp-pw-toggle" onclick="toggleVisibility('new_password', this)" aria-label="Toggle password visibility">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <div class="cp-form-group">
                <label class="cp-label" for="confirm_password">Confirm New Password *</label>
                <div class="cp-input-group">
                    <input type="password" id="confirm_password" name="confirm_password" class="cp-input" placeholder="Re-enter your new password" required minlength="8" autocomplete="new-password">
                    <button type="button" class="cp-pw-toggle" onclick="toggleVisibility('confirm_password', this)" aria-label="Toggle password visibility">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <div class="cp-requirements">
                <strong>Password Requirements:</strong>
                <ul>
                    <li>Minimum 8 characters</li>
                    <li>Must contain at least one letter and one number</li>
                    <li>Cannot be identical to your temporary OTP</li>
                </ul>
            </div>

            <button type="submit" class="cp-submit-btn" id="submitBtn">
                <i class="fas fa-key"></i>
                <span>Save Password &amp; Continue</span>
            </button>
        </form>

        <div class="cp-footer-links">
            <a href="<?php echo BASE_URL; ?>/logout"><i class="fas fa-sign-out-alt"></i> Sign out and continue later</a>
        </div>
    </div>

    <script>
        function toggleVisibility(inputId, btn) {
            const input = document.getElementById(inputId);
            if (!input) return;
            const icon = btn.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                if (icon) {
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                }
            } else {
                input.type = 'password';
                if (icon) {
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            }
        }

        document.getElementById('changePasswordForm').addEventListener('submit', function(e) {
            const p1 = document.getElementById('new_password').value;
            const p2 = document.getElementById('confirm_password').value;
            if (p1 !== p2) {
                e.preventDefault();
                alert('The new password and confirmation password do not match.');
                document.getElementById('confirm_password').focus();
            }
        });
    </script>
</body>
</html>
