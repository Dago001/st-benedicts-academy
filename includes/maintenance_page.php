<?php
// includes/maintenance_page.php - Frontend maintenance screen displayed to public visitors
$settings = maintenance_get_settings();
$schoolName = defined('SITE_NAME') ? SITE_NAME : "ST. BENEDICT'S EARLY YEARS BRITISH ACADEMY";
$schoolPhone = function_exists('school_phone') ? school_phone() : '09044472688';
$schoolEmail = function_exists('school_email') ? school_email() : 'info@stbenedicts.edu.ng';
$schoolMotto = function_exists('school_motto') ? school_motto() : 'Christo Duce, Una Sapientia et Virtute Crescimus';
$nonce = defined('CSP_NONCE') ? CSP_NONCE : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#002855">
    <title>Maintenance in Progress - <?php echo e($schoolName); ?></title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo BASE_URL; ?>/assets/images/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo BASE_URL; ?>/assets/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo BASE_URL; ?>/assets/images/favicon-16x16.png">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/fonts.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/vendor/fontawesome/all.min.css">
    <style>
        :root {
            --brand: #002855;
            --brand-deep: #001a3a;
            --gold: #ffd700;
            --gold-alt: #ffd23f;
            --red: #c41e3a;
            --ink: #1f2937;
            --ink-mute: #6b7280;
            --card-bg: #ffffff;
            --surface-alt: #f4f6fa;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: linear-gradient(145deg, #001a3a 0%, #002855 50%, #001633 100%);
            color: #ffffff;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 24px 16px;
            text-align: center;
            position: relative;
            overflow-x: hidden;
        }
        body::before {
            content: '';
            position: absolute;
            top: -200px;
            right: -200px;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(255, 215, 0, 0.12) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        body::after {
            content: '';
            position: absolute;
            bottom: -200px;
            left: -200px;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(196, 30, 58, 0.15) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        .maintenance-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 20px;
            padding: 44px 32px;
            max-width: 640px;
            width: 100%;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.35);
            position: relative;
            z-index: 2;
            animation: fadeIn 0.8s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .school-header {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            margin-bottom: 28px;
        }
        .school-logo {
            width: 72px;
            height: 72px;
            object-fit: contain;
            border-radius: 14px;
            background: #ffffff;
            padding: 6px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.25);
        }
        .school-name {
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #ffffff;
            line-height: 1.3;
        }
        .school-name small {
            display: block;
            font-size: 0.76rem;
            color: var(--gold-alt);
            letter-spacing: 0.08em;
            font-weight: 600;
            margin-top: 2px;
        }
        .icon-badge {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            border-radius: 50%;
            background: rgba(255, 215, 0, 0.14);
            border: 2px solid rgba(255, 215, 0, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.2rem;
            color: var(--gold-alt);
            box-shadow: 0 0 30px rgba(255, 215, 0, 0.2);
            animation: pulseGlow 3s infinite ease-in-out;
        }
        @keyframes pulseGlow {
            0%, 100% { transform: scale(1); box-shadow: 0 0 20px rgba(255, 215, 0, 0.2); }
            50% { transform: scale(1.04); box-shadow: 0 0 35px rgba(255, 215, 0, 0.4); }
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 16px;
            border-radius: 999px;
            background: rgba(255, 193, 7, 0.18);
            border: 1px solid rgba(255, 193, 7, 0.4);
            color: var(--gold-alt);
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 18px;
        }
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--gold);
            animation: blink 1.5s infinite ease-in-out;
        }
        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }
        h1 {
            font-size: clamp(1.4rem, 4vw, 1.85rem);
            font-weight: 800;
            color: #ffffff;
            line-height: 1.25;
            margin-bottom: 14px;
        }
        .message {
            font-size: 0.98rem;
            line-height: 1.65;
            color: rgba(255, 255, 255, 0.85);
            margin-bottom: 24px;
            white-space: pre-line;
        }
        .eta-card {
            background: rgba(0, 0, 0, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 0.92rem;
            color: rgba(255, 255, 255, 0.9);
        }
        .eta-card strong {
            color: var(--gold-alt);
        }
        .contact-box {
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.12);
            margin-top: 20px;
        }
        .contact-box p {
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: rgba(255, 255, 255, 0.65);
            margin-bottom: 12px;
            font-weight: 600;
        }
        .contact-buttons {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .contact-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 18px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.18);
            color: #ffffff;
            font-size: 0.88rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.25s ease;
        }
        .contact-btn:hover {
            background: rgba(255, 255, 255, 0.18);
            border-color: var(--gold);
            color: var(--gold);
            transform: translateY(-2px);
        }
        .footer-note {
            margin-top: 28px;
            font-size: 0.78rem;
            color: rgba(255, 255, 255, 0.55);
            line-height: 1.5;
        }
        .footer-note .motto {
            font-style: italic;
            color: var(--gold-alt);
            margin-bottom: 6px;
        }
        .admin-link {
            display: inline-block;
            margin-top: 12px;
            color: rgba(255, 255, 255, 0.45);
            font-size: 0.75rem;
            text-decoration: none;
            transition: color 0.2s ease;
        }
        .admin-link:hover {
            color: var(--gold);
            text-decoration: underline;
        }
        @media (max-width: 480px) {
            .maintenance-card { padding: 32px 20px; }
            .icon-badge { width: 68px; height: 68px; font-size: 1.8rem; }
            .contact-buttons { flex-direction: column; width: 100%; }
            .contact-btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
    <div class="maintenance-card">
        <header class="school-header">
            <img src="<?php echo BASE_URL; ?>/assets/images/logo.png" alt="School Logo" class="school-logo">
            <div class="school-name">
                <?php echo e($schoolName); ?>
                <small>Excellence in Early Years Education</small>
            </div>
        </header>

        <div class="icon-badge">
            <i class="fas fa-tools"></i>
        </div>

        <div class="status-pill">
            <span class="status-dot"></span>
            Scheduled Maintenance
        </div>

        <h1><?php echo e(!empty($settings['title']) ? $settings['title'] : 'System Maintenance in Progress'); ?></h1>

        <div class="message"><?php echo nl2br(e(!empty($settings['message']) ? $settings['message'] : 'Our website is currently undergoing scheduled maintenance. We will be back online shortly!')); ?></div>

        <?php if (!empty($settings['until'])): ?>
        <div class="eta-card">
            <i class="fas fa-clock" style="color: var(--gold-alt);"></i>
            <span>Expected back online: <strong><?php echo e($settings['until']); ?></strong></span>
        </div>
        <?php endif; ?>

        <?php if (($settings['show_contact'] ?? '1') === '1'): ?>
        <div class="contact-box">
            <p>Need urgent assistance?</p>
            <div class="contact-buttons">
                <a href="tel:<?php echo e($schoolPhone); ?>" class="contact-btn">
                    <i class="fas fa-phone-alt" style="color: var(--gold-alt);"></i>
                    <span>Call <?php echo e($schoolPhone); ?></span>
                </a>
                <a href="mailto:<?php echo e($schoolEmail); ?>" class="contact-btn">
                    <i class="fas fa-envelope" style="color: var(--gold-alt);"></i>
                    <span><?php echo e($schoolEmail); ?></span>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <div class="footer-note">
            <div class="motto">&ldquo;<?php echo e($schoolMotto); ?>&rdquo;</div>
            <p>&copy; <?php echo date('Y'); ?> <?php echo e($schoolName); ?>. All rights reserved.</p>
            <a href="<?php echo BASE_URL; ?>/login" class="admin-link">
                <i class="fas fa-lock"></i> Staff &amp; Administrator Login
            </a>
        </div>
    </div>
</body>
</html>
