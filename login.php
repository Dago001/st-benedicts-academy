<?php
// login.php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/includes/auth.php';

// Redirect if already logged in
if (Security::isLoggedIn()) {
    if (!empty($_SESSION['must_change_password'])) {
        header('Location: ' . BASE_URL . '/change-password');
        exit;
    }
    $role = $_SESSION['user_role'] ?? 'student';
    $baseRoleDir = in_array($role, ['super_admin', 'admin', 'head_teacher', 'supervisor'], true) ? 'admin' : $role;
    header('Location: ' . BASE_URL . '/' . $baseRoleDir . '/dashboard');
    exit;
}

$error = '';
if (isset($_GET['restart'])) { unset($_SESSION['pending_2fa']); }
$notice = isset($_GET['reset']) ? 'Your password has been reset. Please sign in.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $error = 'Invalid security token. Please refresh the page and try again.';
        } else {
            $auth = new Auth();
            if (isset($_POST['otp'])) {
                $result = $auth->completeTwoFactor($_POST['otp']);
                if ($result['success']) {
                    header('Location: ' . $result['redirect']);
                    exit;
                }
                $error = $result['message'];
            } else {
                $ts = turnstile_verify();
                if (!$ts['success']) {
                    $error = $ts['error'];
                } else {
                    unset($_SESSION['pending_2fa']);
                    $result = $auth->login(Security::sanitize($_POST['email'] ?? ''), $_POST['password'] ?? '');
                    if ($result['success']) {
                        header('Location: ' . $result['redirect']);
                        exit;
                    }
                    // needs_2fa is a prompt, not an error
                    $error = !empty($result['needs_2fa']) && !isset($_POST['otp']) ? '' : $result['message'];
                }
            }
        }
    } catch (Throwable $e) {
        error_log("Login error: " . $e->getMessage());
        $error = 'Authentication failed. Please verify your credentials and try again.';
    }
}

$pending2fa = !empty($_SESSION['pending_2fa']) && $_SESSION['pending_2fa']['expires'] >= time();

// Generate new CSRF token for the form
$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/fonts.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/vendor/fontawesome/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css?v=38">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/mobile.css?v=38">
    <?php if (function_exists('turnstile_script')) echo turnstile_script(); ?>
    <style>
        /* Page-specific styles - Compact & Balanced (Reduced height by 3 steps) */
        .login-page {
            background: linear-gradient(135deg, rgba(0, 40, 85, 0.88) 0%, rgba(0, 26, 58, 0.92) 100%),
                        url('<?php echo BASE_URL; ?>/assets/images/background.png') center/cover no-repeat fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            position: relative;
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
            animation: fadeInUp 0.4s ease;
            border: 1px solid rgba(255, 255, 255, 0.35);
        }

        .login-header {
            text-align: center;
            margin-bottom: 14px;
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

        .login-form .form-group {
            margin-bottom: 11px;
        }

        .login-form label {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 4px;
            color: var(--navy, #002855);
            font-weight: 600;
            font-size: 0.82rem;
            line-height: 1.2;
        }

        .login-form label i {
            color: var(--gold, #d97706);
            width: 16px;
            font-size: 0.85rem;
        }

        .login-form input[type="email"],
        .password-wrapper input {
            width: 100%;
            height: 40px;
            padding: 8px 12px;
            border: 1.5px solid #dce4ee;
            border-radius: 8px;
            font-size: 0.92rem;
            transition: all 0.2s ease;
            background-color: #ffffff;
            box-sizing: border-box;
            color: #0f172a;
        }

        .password-wrapper {
            position: relative;
            width: 100%;
        }

        .password-wrapper input {
            padding-right: 38px;
        }

        .login-form input:focus {
            outline: none;
            border-color: var(--navy, #002855);
            box-shadow: 0 0 0 3px rgba(0, 40, 85, 0.1);
            background-color: #ffffff;
        }

        input:-webkit-autofill,
        input:-webkit-autofill:hover, 
        input:-webkit-autofill:focus {
            -webkit-box-shadow: 0 0 0 1000px #f8fafc inset !important;
            -webkit-text-fill-color: #0f172a !important;
            transition: background-color 5000s ease-in-out 0s;
        }

        .toggle-password {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #94a3b8;
            font-size: 0.95rem;
            transition: color 0.2s ease;
            background: none;
            border: none;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
        }

        .toggle-password:hover {
            color: var(--navy, #002855);
        }

        .cf-turnstile-wrapper {
            margin: 8px 0 10px !important;
            display: flex;
            justify-content: center;
            background: transparent !important;
        }

        .cf-turnstile {
            background: transparent !important;
        }

        .btn-primary {
            background-color: var(--red, #c41e3a);
            color: white;
            padding: 9px 16px;
            font-size: 0.92rem;
            font-weight: 700;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            width: 100%;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-sizing: border-box;
        }

        .btn-primary:hover {
            background-color: #9b1429;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(196, 30, 58, 0.3);
        }

        .alert {
            padding: 8px 12px;
            border-radius: 8px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.82rem;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fca5a5;
        }

        .alert-success {
            background-color: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }

        .alert i {
            font-size: 0.95rem;
        }

        .login-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px solid #e2e8f0;
            font-size: 0.82rem;
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

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(14px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
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

            .login-footer {
                flex-direction: row;
                gap: 8px;
            }
        }
    </style>
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-box">
            <div class="login-header">
                <img src="<?php echo BASE_URL; ?>/assets/images/logo.png" alt="School Logo" class="login-logo">
                <h1><?php echo SITE_NAME; ?></h1>
                <p>Please sign in to continue</p>
            </div>

            <?php if ($notice): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo e($notice); ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo e($error); ?>
            </div>
            <?php endif; ?>

            <?php if ($pending2fa): ?>
            <form method="POST" action="" class="login-form" id="otpForm">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                <p style="margin-bottom:14px;color:#555;font-size:.95rem">Two-step verification: enter the 6-digit code from your authenticator app.</p>
                <div class="form-group">
                    <label for="otp"><i class="fas fa-shield-alt"></i> Authentication code</label>
                    <input type="text" id="otp" name="otp" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" placeholder="123456" required autofocus>
                </div>
                <div class="form-group"><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Verify</button></div>
                <div class="login-footer"><a href="<?php echo BASE_URL; ?>/login?restart=1"><i class="fas fa-arrow-left"></i> Start over</a></div>
            </form>
            <?php else: ?>
            <form method="POST" action="" class="login-form" id="loginForm">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">

                <div class="form-group">
                    <label for="email">
                        <i class="fas fa-envelope"></i>
                        Email Address
                    </label>
                    <input type="email" id="email" name="email"
                           value="<?php echo e($_POST['email'] ?? ''); ?>"
                           placeholder="Enter your email" autocomplete="username" inputmode="email"
                           required autofocus>
                </div>

                <div class="form-group">
                    <label for="password">
                        <i class="fas fa-lock"></i>
                        Password
                    </label>
                    <div class="password-wrapper">
                        <input type="password" id="password" name="password"
                               placeholder="Enter your password" autocomplete="current-password"
                               required>
                        <button type="button" class="toggle-password" id="togglePassword" aria-label="Toggle password visibility">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <?php if (function_exists('turnstile_widget')) echo turnstile_widget('light', 'login'); ?>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-sign-in-alt"></i> Sign In
                    </button>
                </div>

                <div class="login-footer">
                    <a href="<?php echo BASE_URL; ?>/forgot-password">
                        <i class="fas fa-question-circle"></i> Forgot Password?
                    </a>
                    <a href="<?php echo BASE_URL; ?>/">
                        <i class="fas fa-home"></i> Back to Home
                    </a>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <script nonce="<?php echo CSP_NONCE; ?>">
    document.addEventListener('DOMContentLoaded', function() {
        // Toggle password visibility
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');

        if (togglePassword && passwordInput) {
            togglePassword.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);

                const icon = this.querySelector('i');
                if (icon) {
                    icon.classList.toggle('fa-eye');
                    icon.classList.toggle('fa-eye-slash');
                }
            });
        }

        // Form validation
        const loginForm = document.getElementById('loginForm');
        if (loginForm) {
            loginForm.addEventListener('submit', function(e) {
                const email = document.getElementById('email').value.trim();
                const password = document.getElementById('password').value.trim();

                if (!email || !password) {
                    e.preventDefault();
                    alert('Please enter both email and password');
                }
            });
        }
    });
    </script>
<script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>
</body>
</html>