<?php
// includes/auth_layout.php - minimal responsive shell for login-style pages
function auth_page_start($title) { ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($title); ?> - <?php echo e(SITE_NAME); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/responsive.css">
    <style>
        body.auth-page { min-height: 100vh; min-height: 100dvh; display: flex; align-items: center; justify-content: center; padding: 16px;
            background: linear-gradient(135deg, rgba(0,40,85,.9), rgba(0,26,58,.95)), url('<?php echo BASE_URL; ?>/assets/images/background.png') center/cover fixed; }
        .auth-card { background: #fff; width: 100%; max-width: 420px; border-radius: 12px; padding: 28px 22px; box-shadow: 0 20px 40px rgba(0,0,0,.3); }
        .auth-card h1 { font-size: 1.35rem; color: #002855; margin: 0 0 6px; text-align: center; }
        .auth-card p.sub { text-align: center; color: #666; margin: 0 0 20px; font-size: .95rem; }
        .auth-card .form-group { margin-bottom: 16px; }
        .auth-card label { display: block; margin-bottom: 6px; font-weight: 600; }
        .auth-card input { width: 100%; padding: 12px 14px; border: 2px solid #ddd; border-radius: 8px; font-size: 16px; }
        .auth-card .btn { width: 100%; padding: 12px; font-size: 1rem; }
        .auth-card .links { text-align: center; margin-top: 16px; font-size: .9rem; }
        .alert { padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; font-size: .92rem; }
        .alert-error { background: #fdecea; color: #b71c1c; } .alert-success { background: #e8f5e9; color: #1b5e20; }
    </style>
</head>
<body class="auth-page"><div class="auth-card">
<?php }
function auth_page_end() { echo '</div></body></html>'; }
