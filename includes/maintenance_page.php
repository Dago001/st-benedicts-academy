<?php
// includes/maintenance_page.php - Responsive branded maintenance screen displayed to public visitors
$settings = maintenance_get_settings();
$schoolName = defined('SITE_NAME') ? SITE_NAME : "ST. BENEDICT'S EARLY YEARS BRITISH ACADEMY";
$schoolPhone = function_exists('school_phone') ? school_phone() : '09044472688';
$schoolEmail = function_exists('school_email') ? school_email() : 'info@stbenedicts.edu.ng';
$schoolMotto = function_exists('school_motto') ? school_motto() : 'Christo Duce, Una Sapientia et Virtute Crescimus';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover">
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
        }
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        html {
            width: 100%;
            height: 100%;
            overflow-x: hidden;
            -webkit-text-size-adjust: 100%;
        }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: linear-gradient(160deg, #001633 0%, #002855 50%, #001a3a 100%);
            color: #ffffff;
            width: 100%;
            min-height: 100%;
            min-height: 100vh;
            min-height: 100dvh;
            overflow-x: hidden;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 24px 16px;
            text-align: center;
        }
        /* Ambient light glows: clipped in fixed container so mobile layout never expands or zooms out */
        .ambient-glow {
            position: fixed;
            inset: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            pointer-events: none;
            z-index: 0;
        }
        .glow-top-right {
            position: absolute;
            top: -120px;
            right: -120px;
            width: 380px;
            height: 380px;
            background: radial-gradient(circle, rgba(255, 215, 0, 0.14) 0%, transparent 70%);
            border-radius: 50%;
        }
        .glow-bottom-left {
            position: absolute;
            bottom: -120px;
            left: -120px;
            width: 380px;
            height: 380px;
            background: radial-gradient(circle, rgba(196, 30, 58, 0.16) 0%, transparent 70%);
            border-radius: 50%;
        }
        .maintenance-card {
            background: rgba(255, 255, 255, 0.06);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 20px;
            padding: 40px 28px;
            max-width: 600px;
            width: 100%;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.4);
            position: relative;
            z-index: 2;
            margin: auto;
            animation: fadeIn 0.6s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .school-header {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
        }
        .school-logo {
            width: 68px;
            height: 68px;
            object-fit: contain;
            border-radius: 12px;
            background: #ffffff;
            padding: 5px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25);
        }
        .school-name {
            font-size: 0.98rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #ffffff;
            line-height: 1.3;
        }
        .school-name small {
            display: block;
            font-size: 0.74rem;
            color: var(--gold-alt);
            letter-spacing: 0.08em;
            font-weight: 600;
            margin-top: 2px;
        }
        .icon-badge {
            width: 74px;
            height: 74px;
            margin: 0 auto 18px;
            border-radius: 50%;
            background: rgba(255, 215, 0, 0.15);
            border: 2px solid rgba(255, 215, 0, 0.35);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: var(--gold-alt);
            box-shadow: 0 0 25px rgba(255, 215, 0, 0.2);
            animation: pulseGlow 3s infinite ease-in-out;
        }
        @keyframes pulseGlow {
            0%, 100% { transform: scale(1); box-shadow: 0 0 18px rgba(255, 215, 0, 0.2); }
            50% { transform: scale(1.05); box-shadow: 0 0 30px rgba(255, 215, 0, 0.35); }
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
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 16px;
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
            font-size: clamp(1.3rem, 4.8vw, 1.75rem);
            font-weight: 800;
            color: #ffffff;
            line-height: 1.25;
            margin-bottom: 14px;
        }
        .message {
            font-size: 0.95rem;
            line-height: 1.65;
            color: rgba(255, 255, 255, 0.88);
            margin-bottom: 22px;
            white-space: pre-line;
            word-break: break-word;
        }
        .eta-card {
            background: rgba(0, 0, 0, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.9);
            flex-wrap: wrap;
        }
        .eta-card strong {
            color: var(--gold-alt);
        }
        .contact-box {
            padding-top: 18px;
            border-top: 1px solid rgba(255, 255, 255, 0.12);
            margin-top: 18px;
        }
        .contact-box p {
            font-size: 0.8rem;
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
            justify-content: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.18);
            color: #ffffff;
            font-size: 0.86rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .contact-btn:hover, .contact-btn:active {
            background: rgba(255, 255, 255, 0.2);
            border-color: var(--gold);
            color: var(--gold);
        }
        .footer-note {
            margin-top: 24px;
            font-size: 0.76rem;
            color: rgba(255, 255, 255, 0.55);
            line-height: 1.5;
        }
        .footer-note .motto {
            font-style: italic;
            color: var(--gold-alt);
            margin-bottom: 4px;
        }

        /* Mobile specific fixes */
        @media (max-width: 640px) {
            body {
                padding: 16px 12px;
                justify-content: center;
            }
            .maintenance-card {
                padding: 28px 18px;
                border-radius: 16px;
                width: 100%;
                max-width: 100%;
                margin: 0;
            }
            .school-logo {
                width: 58px;
                height: 58px;
            }
            .school-name {
                font-size: 0.88rem;
            }
            .school-name small {
                font-size: 0.68rem;
            }
            .icon-badge {
                width: 64px;
                height: 64px;
                font-size: 1.7rem;
                margin-bottom: 14px;
            }
            h1 {
                font-size: 1.35rem;
            }
            .message {
                font-size: 0.9rem;
            }
            .contact-buttons {
                flex-direction: column;
                width: 100%;
            }
            .contact-btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="ambient-glow" aria-hidden="true">
        <span class="glow-top-right"></span>
        <span class="glow-bottom-left"></span>
    </div>

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

        <h1><?php echo e(!empty($settings['title']) ? $settings['title'] : 'Scheduled Maintenance in Progress'); ?></h1>

        <div class="message"><?php echo nl2br(e(!empty($settings['message']) ? $settings['message'] : 'Our website is currently undergoing scheduled maintenance and upgrades. We will be back online shortly!')); ?></div>

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
        </div>
    </div>
</body>
</html>
