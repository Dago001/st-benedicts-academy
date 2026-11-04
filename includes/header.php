<?php
// includes/header.php
// Expects config/config.php + config/security.php to be loaded by the page.
require_once __DIR__ . '/layout.php';
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$assetVersion = '34';

/** <link>/<script nonce="<?php echo CSP_NONCE; ?>"> only for assets that actually exist, so a missing file never causes a 404. */
if (!function_exists('asset_exists')) {
    function asset_exists($type, $file) {
        $file = basename($file);
        return is_file(ROOT_PATH . '/assets/' . $type . '/' . $file);
    }
}
$isLoggedIn = Security::isLoggedIn();
$portalRole = $isLoggedIn ? ($_SESSION['user_role'] ?? '') : '';
$inPortal = $isLoggedIn && !empty($portalRole) && (
    strpos($_SERVER['SCRIPT_NAME'] ?? '', '/' . $portalRole . '/') !== false ||
    strpos($_SERVER['PHP_SELF'] ?? '', '/' . $portalRole . '/') !== false ||
    preg_match('#/(admin|teacher|parent|student)/#i', $_SERVER['SCRIPT_NAME'] ?? '') ||
    preg_match('#/(admin|teacher|parent|student)/#i', $_SERVER['PHP_SELF'] ?? '')
);
$dashboardUrl = $isLoggedIn ? BASE_URL . '/' . $portalRole . '/dashboard' : BASE_URL . '/login';

if (function_exists('enforce_maintenance_mode')) {
    enforce_maintenance_mode();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#002855">
    <title><?php echo e(SITE_NAME); ?> - <?php echo e($pageTitle ?? 'Home'); ?></title>

    <meta name="description" content="<?php echo e($pageDescription ?? "ST. BENEDICT'S EARLY YEARS BRITISH ACADEMY - Providing quality British Early Years education in Enugu, Nigeria"); ?>">
    <meta name="keywords" content="school, early years, british curriculum, enugu, nursery, primary">
    <meta name="author" content="<?php echo e(SITE_NAME); ?>">
    <?php if (!$isLoggedIn): ?>
    <meta property="og:title" content="<?php echo e(SITE_NAME); ?>">
    <meta property="og:image" content="<?php echo e(BASE_URL); ?>/assets/images/og-image.jpg">
    <?php else: ?>
    <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>

    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo BASE_URL; ?>/assets/images/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo BASE_URL; ?>/assets/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo BASE_URL; ?>/assets/images/favicon-16x16.png">
    <link rel="manifest" href="<?php echo BASE_URL; ?>/assets/images/site.webmanifest">

    <link rel="preload" href="<?php echo BASE_URL; ?>/assets/vendor/fonts/inter-400-4516.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/fonts.css?v=<?php echo $assetVersion; ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/vendor/fontawesome/all.min.css?v=1">

    <script nonce="<?php echo CSP_NONCE; ?>">
        window.BASE_URL = <?php echo json_encode(BASE_URL, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        window.CSRF_TOKEN = <?php echo json_encode(Security::generateCSRFToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        window.CURRENT_USER = <?php echo json_encode($isLoggedIn ? [
            'name' => $_SESSION['user_name'] ?? Security::roleTitle(),
            'role' => Security::roleTitle(),
            'badge' => Security::roleBadge()
        ] : null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>

    <?php if ($isLoggedIn): ?>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/vendor/datatables/jquery.dataTables.min.css?v=1">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/sidebar-modern.css?v=<?php echo $assetVersion; ?>">
    <script src="<?php echo BASE_URL; ?>/assets/vendor/chartjs/chart.umd.min.js?v=1"></script>
    <script src="<?php echo BASE_URL; ?>/assets/vendor/jquery/jquery-3.6.0.min.js"></script>
    <script src="<?php echo BASE_URL; ?>/assets/vendor/datatables/jquery.dataTables.min.js?v=1"></script>
    <?php endif; ?>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css?v=<?php echo $assetVersion; ?>">
    <?php foreach (($extraCSS ?? []) as $css): if (asset_exists('css', $css)): ?>
        <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/<?php echo e(basename($css)); ?>?v=<?php echo $assetVersion; ?>">
    <?php endif; endforeach; ?>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/mobile.css?v=<?php echo $assetVersion; ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/polish.css?v=<?php echo $assetVersion; ?>">
    <?php if (function_exists('turnstile_script')) echo turnstile_script(); ?>
</head>
<body class="<?php echo $isLoggedIn ? 'is-auth role-' . e($_SESSION['user_role']) . ($inPortal ? ' in-portal' : '') : 'is-public'; ?>">
    <!-- Global Branded Page Transition Spinner (St. Benedict's Crest) -->
    <div id="stbPagePreloader" class="stb-page-preloader" aria-hidden="true">
        <div class="stb-preloader-backdrop"></div>
        <div class="stb-preloader-content">
            <div class="stb-crest-spin-container">
                <div class="stb-crest-aura"></div>
                <div class="stb-crest-ring"></div>
                <img src="<?php echo BASE_URL; ?>/assets/images/logo.png" alt="St. Benedict's Academy Crest" class="stb-crest-image">
            </div>
            <div class="stb-crest-text-wrap">
                <div class="stb-preloader-school">ST. BENEDICT'S ACADEMY</div>
                <div class="stb-preloader-motto">Christo Dulce Simul Crescimus</div>
            </div>
            <div class="stb-preloader-loader-bar">
                <span class="stb-loader-fill"></span>
            </div>
        </div>
    </div>
    <script nonce="<?php echo CSP_NONCE; ?>" src="<?php echo BASE_URL; ?>/assets/js/preloader.js?v=<?php echo $assetVersion; ?>"></script>
    <?php if (function_exists('maintenance_admin_banner')) maintenance_admin_banner(); ?>
    <a class="skip-link" href="#main-content">Skip to content</a>
    <div class="site-wrapper">
        <nav class="main-nav" aria-label="Main navigation">
            <div class="nav-container">
                <div class="logo">
                    <a href="<?php echo BASE_URL; ?>/">
                        <img src="<?php echo BASE_URL; ?>/assets/images/logo.png" alt="<?php echo e(SITE_NAME); ?>">
                        <span class="school-name">ST. BENEDICT'S<br><small>Early Years British Academy</small></span>
                    </a>
                </div>

                <button type="button" class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle menu" aria-expanded="false" aria-controls="navMenu">
                    <i class="fas fa-bars"></i>
                </button>

                <ul class="nav-menu" id="navMenu">
                    <?php
                    // Phones: structured sectioned portal navigation (interactive collapsible dropdowns)
                    $portalSections = $portalRole ? nav_sections($portalRole) : [];
                    $sectionIcons = [
                        'MAIN' => 'fa-home',
                        'STUDENTS' => 'fa-user-graduate',
                        'FEES' => 'fa-money-bill-wave',
                        'FEES & PAYMENTS' => 'fa-money-bill-wave',
                        'ACADEMICS' => 'fa-book-reader',
                        'MY ACADEMICS' => 'fa-graduation-cap',
                        'MY CHILDREN' => 'fa-child',
                        'FINANCE' => 'fa-coins',
                        'STAFF / PAYROLL' => 'fa-user-tie',
                        'COMMUNICATION' => 'fa-bullhorn',
                        'ADMIN' => 'fa-sliders-h',
                        'ACCOUNT' => 'fa-user-cog',
                    ];
                    if (!empty($portalSections)):
                        $portalCurrent = $currentPage;
                        $portalAlias = [
                            'mark-attendance' => 'attendance', 'view-student' => 'students', 'teacher-profile' => 'teachers',
                            'view-parent' => 'parents', 'student-fees' => 'fees', 'print-receipt' => 'fees',
                            'view-receipt' => 'fees', 'attendance-detail' => 'attendance', 'submissions' => 'assignments',
                            'assign-subjects' => 'assign-subjects', 'career-applications' => 'careers', 'vacancies' => 'careers',
                            'quick-fee-collect' => 'quick-fee-collect', 'fee-reminders' => 'fee-reminders', 'fee-deletion-log' => 'fee-deletion-log',
                            'new-admission' => 'new-admission', 'admissions-enquiry' => 'admissions-enquiry', 'passed-out-archive' => 'passed-out-archive',
                            'promotion' => 'promotion', 'session-report' => 'session-report', 'attendance-report' => 'attendance-report',
                            'exams' => 'exams', 'marks-entry' => 'marks-entry', 'expenses' => 'expenses', 'income-expense' => 'income-expense',
                            'salary' => 'salary', 'salary-report' => 'salary-report',
                        ];
                        $portalActive = $portalAlias[$portalCurrent] ?? $portalCurrent;
                        $baseRoleDir = in_array($portalRole, ['super_admin', 'admin', 'head_teacher', 'supervisor'], true) ? 'admin' : $portalRole;
                    ?>
                    <?php foreach ($portalSections as $sectionHeading => $items):
                        $isMain = ($sectionHeading === 'MAIN');
                        $secIcon = $sectionIcons[$sectionHeading] ?? 'fa-folder';
                        $sectionHasActive = false;
                        foreach ($items as $it) {
                            $ck = explode('?', $it[0])[0];
                            if ($inPortal && $portalActive === $ck) {
                                $sectionHasActive = true;
                                break;
                            }
                        }
                    ?>
                        <?php if ($isMain): ?>
                            <?php foreach ($items as $item):
                                $file = $item[0];
                                $label = $item[1];
                                $icon = $item[2];
                                $accent = $item[3] ?? '';
                                $url = (strpos($file, '/') !== false || strpos($file, 'http') === 0)
                                    ? (strpos($file, '../') === 0 ? BASE_URL . substr($file, 2) : $file)
                                    : BASE_URL . '/' . $baseRoleDir . '/' . $file;
                                $checkKey = explode('?', $file)[0];
                                $isActive = ($inPortal && $portalActive === $checkKey);
                            ?>
                            <li class="nav-portal nav-portal-direct<?php echo $isActive ? ' active' : ''; ?>">
                                <a href="<?php echo e($url); ?>" class="nav-portal-link <?php echo e($accent); ?><?php echo $isActive ? ' is-active' : ''; ?>"<?php echo $isActive ? ' aria-current="page"' : ''; ?>>
                                    <i class="fas <?php echo e($icon); ?> nav-portal-icon"></i>
                                    <span class="nav-portal-text"><?php echo e($label); ?></span>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li class="nav-portal nav-portal-section <?php echo $sectionHasActive ? 'open' : ''; ?>">
                                <button type="button" class="nav-portal-section-toggle" aria-expanded="<?php echo $sectionHasActive ? 'true' : 'false'; ?>">
                                    <span class="section-title-wrap">
                                        <i class="fas <?php echo e($secIcon); ?> section-icon"></i>
                                        <span class="section-name"><?php echo e($sectionHeading); ?></span>
                                    </span>
                                    <i class="fas fa-chevron-down section-chevron"></i>
                                </button>
                                <ul class="nav-portal-submenu" <?php echo $sectionHasActive ? '' : 'style="display: none;"'; ?>>
                                    <?php foreach ($items as $item):
                                        $file = $item[0];
                                        $label = $item[1];
                                        $icon = $item[2];
                                        $accent = $item[3] ?? '';
                                        $url = (strpos($file, '/') !== false || strpos($file, 'http') === 0)
                                            ? (strpos($file, '../') === 0 ? BASE_URL . substr($file, 2) : $file)
                                            : BASE_URL . '/' . $baseRoleDir . '/' . $file;
                                        $checkKey = explode('?', $file)[0];
                                        $isActive = ($inPortal && $portalActive === $checkKey);
                                    ?>
                                    <li class="nav-portal-subitem<?php echo $isActive ? ' active' : ''; ?>">
                                        <a href="<?php echo e($url); ?>" class="nav-portal-link <?php echo e($accent); ?><?php echo $isActive ? ' is-active' : ''; ?>"<?php echo $isActive ? ' aria-current="page"' : ''; ?>>
                                            <i class="fas <?php echo e($icon); ?> nav-portal-icon"></i>
                                            <span class="nav-portal-text"><?php echo e($label); ?></span>
                                        </a>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (!$inPortal): ?>
                    <li class="nav-portal-heading" aria-hidden="true">Website</li>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if (!$inPortal): ?>
                    <?php
                    $links = [
                        'index'      => ['/', 'Home'],
                        'about'      => ['/public/about', 'About Us'],
                        'admissions' => ['/public/admissions', 'Admissions'],
                        'academics'  => ['/public/academics', 'Academics'],
                        'news'       => ['/public/news', 'News & Events'],
                        'gallery'    => ['/public/gallery', 'Gallery'],
                        'careers'    => ['/public/careers', 'Career'],
                        'contact'    => ['/public/contact', 'Contact'],
                    ];
                    foreach ($links as $file => [$path, $label]): ?>
                    <li class="<?php echo $currentPage === $file ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL . $path; ?>"<?php echo $currentPage === $file ? ' aria-current="page"' : ''; ?>><?php echo e($label); ?></a>
                    </li>
                    <?php endforeach; ?>
                    <?php foreach (cms_menu_pages() as $mp): $mpActive = $currentPage === 'page' && ($_GET['slug'] ?? '') === $mp['slug']; ?>
                    <li class="<?php echo $mpActive ? 'active' : ''; ?>"><a href="<?php echo BASE_URL . '/public/page?slug=' . rawurlencode($mp['slug']); ?>"<?php echo $mpActive ? ' aria-current="page"' : ''; ?>><?php echo e($mp['title']); ?></a></li>
                    <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if ($isLoggedIn && !$inPortal): ?>
                        <li class="nav-item dropdown">
                            <a href="#" class="nav-link dropdown-toggle" aria-haspopup="true">
                                <i class="fas fa-user"></i> <?php echo e($_SESSION['user_name']); ?>
                            </a>
                            <ul class="dropdown-menu">
                                <li><a href="<?php echo e($dashboardUrl); ?>">Dashboard</a></li>
                                <li><a href="<?php echo BASE_URL; ?>/logout">Logout</a></li>
                            </ul>
                        </li>
                    <?php elseif (!$isLoggedIn): ?>
                        <li class="nav-buttons">
                            <a href="<?php echo BASE_URL; ?>/login" class="btn btn-primary">Login</a>
                            <a href="<?php echo BASE_URL; ?>/public/apply" class="btn btn-accent">Apply Now</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </nav>

        <main class="main-content" id="main-content">
