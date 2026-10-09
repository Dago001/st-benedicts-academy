<?php
// login.php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/includes/auth.php';

// Redirect if already logged in
if (Security::isLoggedIn()) {
    header('Location: ' . BASE_URL . '/' . $_SESSION['user_role'] . '/dashboard');
    exit;
}

$error = '';
if (isset($_GET['restart'])) { unset($_SESSION['pending_2fa']); }
$notice = isset($_GET['reset']) ? 'Your password has been reset. Please sign in.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh the page and try again.';
    } else {
        $auth = new Auth();
        if (isset($_POST['otp'])) {
            $result = $auth->completeTwoFactor($_POST['otp']);
        } else {
            unset($_SESSION['pending_2fa']);
            $result = $auth->login(Security::sanitize($_POST['email'] ?? ''), $_POST['password'] ?? '');
        }
        if ($result['success']) {
            header('Location: ' . $result['redirect']);
            exit;
        }
        // needs_2fa is a prompt, not an error
        $error = !empty($result['needs_2fa']) && !isset($_POST['otp']) ? '' : $result['message'];
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
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/mobile.css">
    <style>
        /* Page-specific styles */
        .login-page {
            background: linear-gradient(135deg, rgba(0, 40, 85, 0.85) 0%, rgba(0, 26, 58, 0.9) 100%),
                        url('<?php echo BASE_URL; ?>/assets/images/background.png') center/cover no-repeat fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
        }

        .login-container {
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
        }

        .login-box {
            background-color: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 30px 25px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            animation: fadeInUp 0.5s ease;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .login-header {
            text-align: center;
            margin-bottom: 25px;
        }

        .login-logo {
            width: 80px;
            height: auto;
            margin: 0 auto 15px;
        }

        .login-header h1 {
            font-size: 1.5rem;
            color: var(--navy);
            margin-bottom: 5px;
            line-height: 1.3;
        }

        .login-header p {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .login-form .form-group {
            margin-bottom: 20px;
        }

        .password-wrapper {
            position: relative;
            width: 100%;
        }

        .password-wrapper input {
            width: 100%;
            padding: 12px 45px 12px 15px;
            border: 2px solid var(--medium-gray);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background-color: white;
        }

        .password-wrapper input:focus {
            outline: none;
            border-color: var(--gold);
            box-shadow: 0 0 0 3px rgba(255, 215, 0, 0.1);
        }

        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--gray);
            font-size: 1.2rem;
            transition: color 0.3s ease;
            background: none;
            border: none;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .toggle-password:hover {
            color: var(--gold);
        }

        .btn-primary {
            background-color: var(--red);
            color: white;
            padding: 12px 20px;
            font-size: 1rem;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary:hover {
            background-color: var(--red-dark);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(196, 30, 58, 0.3);
        }

        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
        }

        .alert-error {
            background-color: #fee;
            color: var(--danger);
            border: 1px solid #fcc;
        }

        .alert-success {
            background-color: #e8f5e9;
            color: var(--success);
            border: 1px solid #c8e6c9;
        }

        .alert i {
            font-size: 1.1rem;
        }

        .login-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid var(--light-gray);
            font-size: 0.9rem;
        }

        .login-footer a {
            color: var(--navy);
            text-decoration: none;
            transition: color 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .login-footer a:hover {
            color: var(--gold);
        }

        .login-form label {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            color: var(--navy);
            font-weight: 500;
            font-size: 0.9rem;
        }

        .login-form label i {
            color: var(--gold);
            width: 18px;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 480px) {
            .login-container {
                max-width: 100%;
            }

            .login-box {
                padding: 25px 20px;
            }

            .login-header h1 {
                font-size: 1.3rem;
            }

            .login-logo {
                width: 70px;
            }

            .login-footer {
                flex-direction: column;
                gap: 10px;
                text-align: center;
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