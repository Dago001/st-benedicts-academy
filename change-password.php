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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $error = 'Invalid security token. Please refresh the page and try again.';
        } else {
            $currentPassword = (string)($_POST['current_password'] ?? '');
            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
                $error = 'Please fill in all password fields.';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'New password and confirmation do not match.';
            } elseif ($newPassword === $currentPassword) {
                $error = 'New password cannot be the same as your temporary OTP.';
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
                    $error = $result['message'] ?? 'Failed to update password. Please check your temporary OTP.';
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
            --navy: #002855;
            --brand-navy: #002147;
            --gold: #d97706;
            --red: #c41e3a;
        }

        /* Identical to login.php container sizing & layout */
        .login-page {
            background: linear-gradient(135deg, rgba(0, 40, 85, 0.88) 0%, rgba(0, 26, 58, 0.92) 100%),
                        url('<?php echo BASE_URL; ?>/assets/images/background.png') center/cover no-repeat fixed;
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            position: relative;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        .login-container {
            width: 100%;
            max-width: 380px;
            margin: 0 auto;
        }

        .login-box {
            background-color: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(12px);
            border-radius: 14px;
            padding: 20px 22px;
            box-shadow: 0 16px 36px rgba(0, 0, 0, 0.28);
            border: 1px solid rgba(255, 255, 255, 0.35);
            box-sizing: border-box;
        }

        .login-header {
            text-align: center;
            margin-bottom: 12px;
        }

        .login-logo {
            width: 50px;
            height: auto;
            margin: 0 auto 8px;
            display: block;
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.1));
        }

        .login-header h1 {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--navy, #002855);
            margin: 0 0 3px;
            line-height: 1.25;
            letter-spacing: -0.01em;
        }

        .login-header p {
            color: #64748b;
            font-size: 0.8rem;
            margin: 0;
            line-height: 1.2;
        }

        /* Compact User Pill */
        .user-chip {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 6px 10px;
            margin: 10px 0 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .chip-avatar {
            color: var(--navy, #002855);
            font-size: 1.15rem;
            flex-shrink: 0;
            display: flex;
            align-items: center;
        }

        .chip-meta {
            min-width: 0;
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }

        .chip-meta strong {
            font-size: 0.82rem;
            color: #0f172a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .chip-meta span {
            font-size: 0.72rem;
            color: #64748b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Compact Notice Banner */
        .cp-compact-notice {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 8px;
            padding: 6px 10px;
            margin-bottom: 11px;
            font-size: 0.75rem;
            color: #92400e;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            line-height: 1.3;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fca5a5;
            padding: 8px 12px;
            border-radius: 8px;
            margin-bottom: 11px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.8rem;
            line-height: 1.3;
        }

        /* Form elements matching login.php */
        .login-form .form-group {
            margin-bottom: 10px;
        }

        .login-form label {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 4px;
            color: var(--navy, #002855);
            font-weight: 600;
            font-size: 0.8rem;
            line-height: 1.2;
        }

        .login-form label i {
            color: var(--gold, #d97706);
            width: 15px;
            font-size: 0.82rem;
        }

        .password-wrapper {
            position: relative;
            width: 100%;
        }

        .password-wrapper input {
            width: 100%;
            height: 38px;
            padding: 6px 36px 6px 12px;
            border: 1.5px solid #dce4ee;
            border-radius: 8px;
            font-size: 0.88rem;
            transition: all 0.2s ease;
            background-color: #ffffff;
            box-sizing: border-box;
            color: #0f172a;
        }

        .password-wrapper input:focus {
            outline: none;
            border-color: var(--navy, #002855);
            box-shadow: 0 0 0 3px rgba(0, 40, 85, 0.1);
            background-color: #ffffff;
        }

        .toggle-password {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #94a3b8;
            font-size: 0.9rem;
            transition: color 0.2s ease;
            background: none;
            border: none;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 26px;
            z-index: 2;
        }

        .toggle-password:hover {
            color: var(--navy, #002855);
        }

        .toggle-password i {
            pointer-events: none;
        }

        .btn-primary {
            background-color: var(--red, #c41e3a);
            color: white;
            padding: 8px 16px;
            font-size: 0.9rem;
            font-weight: 700;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            width: 100%;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-sizing: border-box;
            margin-top: 6px;
        }

        .btn-primary:hover {
            background-color: #9b1429;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(196, 30, 58, 0.3);
        }

        .login-footer {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 10px;
            padding-top: 8px;
            border-top: 1px solid #e2e8f0;
            font-size: 0.8rem;
        }

        .login-footer a {
            color: var(--navy, #002855);
            text-decoration: none;
            transition: color 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-weight: 500;
        }

        .login-footer a:hover {
            color: var(--red, #c41e3a);
        }

        @media (max-width: 480px) {
            .login-page {
                padding: 12px;
            }

            .login-box {
                padding: 18px 16px;
                border-radius: 12px;
            }

            .login-header h1 {
                font-size: 1.05rem;
            }

            .login-logo {
                width: 44px;
                margin-bottom: 6px;
            }
        }
    </style>
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-box">
            <div class="login-header">
                <img src="<?php echo BASE_URL; ?>/assets/images/logo.png" alt="St. Benedict's Academy Crest" class="login-logo">
                <h1>Create New Password</h1>
                <p>Mandatory First-Time Security Setup</p>
            </div>

            <!-- Compact User Pill -->
            <div class="user-chip">
                <div class="chip-avatar"><i class="fas fa-user-circle"></i></div>
                <div class="chip-meta">
                    <strong><?php echo e($userName); ?></strong>
                    <span><?php echo e($roleTitle); ?> &bull; <?php echo e($userEmail); ?></span>
                </div>
            </div>

            <!-- Compact Notice (Shield icon removed) -->
            <div class="cp-compact-notice">
                <span>Set your personal password (min. 8 chars, letters &amp; numbers)</span>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error" role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo e($error); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="<?php echo BASE_URL; ?>/change-password" autocomplete="off" class="login-form" id="changePasswordForm">
                <?php echo csrf_field(); ?>

                <div class="form-group">
                    <label for="current_password"><i class="fas fa-key"></i> Current OTP Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="current_password" name="current_password" required autocomplete="current-password" placeholder="Enter temporary OTP">
                        <button type="button" class="toggle-password" data-toggle="current_password" aria-label="Toggle password visibility">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="new_password"><i class="fas fa-lock"></i> New Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password" placeholder="Min. 8 chars with letters &amp; numbers">
                        <button type="button" class="toggle-password" data-toggle="new_password" aria-label="Toggle password visibility">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password"><i class="fas fa-check-double"></i> Confirm New Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password" placeholder="Re-enter new password">
                        <button type="button" class="toggle-password" data-toggle="confirm_password" aria-label="Toggle password visibility">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <i class="fas fa-save"></i> Save Password &amp; Continue
                </button>
            </form>

            <div class="login-footer">
                <a href="<?php echo BASE_URL; ?>/logout">
                    <i class="fas fa-sign-out-alt"></i> Sign out and continue later
                </a>
            </div>
        </div>
    </div>

    <script nonce="<?php echo CSP_NONCE; ?>">
        document.addEventListener('DOMContentLoaded', function() {
            // CSP-compliant password toggle
            document.querySelectorAll('.toggle-password').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const targetId = this.getAttribute('data-toggle');
                    const input = document.getElementById(targetId);
                    if (!input) return;
                    const isPassword = input.getAttribute('type') === 'password';
                    input.setAttribute('type', isPassword ? 'text' : 'password');
                    const icon = this.querySelector('i');
                    if (icon) {
                        if (isPassword) {
                            icon.classList.remove('fa-eye');
                            icon.classList.add('fa-eye-slash');
                        } else {
                            icon.classList.remove('fa-eye-slash');
                            icon.classList.add('fa-eye');
                        }
                    }
                });
            });

            // Client-side confirmation guard
            const changePasswordForm = document.getElementById('changePasswordForm');
            if (changePasswordForm) {
                changePasswordForm.addEventListener('submit', function(e) {
                    const p1 = document.getElementById('new_password').value;
                    const p2 = document.getElementById('confirm_password').value;
                    if (p1 !== p2) {
                        e.preventDefault();
                        alert('The new password and confirmation password do not match.');
                        document.getElementById('confirm_password').focus();
                    }
                });
            }
        });
    </script>
</body>
</html>
