<?php
// includes/header.php
// Expects config/config.php + config/security.php to be loaded by the page.
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$assetVersion = '16';

/** <link>/<script nonce="<?php echo CSP_NONCE; ?>"> only for assets that actually exist, so a missing file never causes a 404. */
if (!function_exists('asset_exists')) {
    function asset_exists($type, $file) {
        $file = basename($file);
        return is_file(ROOT_PATH . '/assets/' . $type . '/' . $file);
    }
}
$isLoggedIn = Security::isLoggedIn();
$dashboardUrl = $isLoggedIn ? BASE_URL . '/' . $_SESSION['user_role'] . '/dashboard' : BASE_URL . '/login';
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
    </script>

    <?php if ($isLoggedIn): ?>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/vendor/datatables/jquery.dataTables.min.css?v=1">
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
</head>
<body class="<?php echo $isLoggedIn ? 'is-auth role-' . e($_SESSION['user_role']) : 'is-public'; ?>">
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
                    // Phones: the portal menu (what the sidebar offers on desktop) lives inside this one hamburger menu
                    $portalRole = $isLoggedIn ? ($_SESSION['user_role'] ?? '') : '';
                    $portalItems = $portalRole ? nav_items($portalRole) : [];
                    if ($portalItems):
                        $portalCurrent = $currentPage;
                        $portalAlias = ['mark-attendance' => 'attendance', 'view-student' => 'students', 'teacher-profile' => 'teachers', 'view-parent' => 'parents', 'student-fees' => 'fees', 'print-receipt' => 'fees', 'assign-subjects' => 'teachers', 'attendance-detail' => 'attendance', 'generate-login' => 'students', 'student-data' => 'students'];
                        $portalActive = $portalAlias[$portalCurrent] ?? $portalCurrent;
                        $inPortal = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/' . $portalRole . '/') !== false;
                    ?>
                    <li class="nav-portal-label" aria-hidden="true"><?php echo e(ucfirst($portalRole)); ?> menu</li>
                    <?php foreach ($portalItems as [$pf, $pl, $pi]): ?>
                    <li class="nav-portal<?php echo ($inPortal && $portalActive === $pf) ? ' active' : ''; ?>"><a href="<?php echo e(BASE_URL . '/' . $portalRole . '/' . $pf); ?>"<?php echo ($inPortal && $portalActive === $pf) ? ' aria-current="page"' : ''; ?>><i class="fas <?php echo e($pi); ?>"></i> <?php echo e($pl); ?></a></li>
                    <?php endforeach; ?>
                    <li class="nav-portal-label" aria-hidden="true">Website</li>
                    <?php endif; ?>
                    <?php
                    $links = [
                        'index'      => ['/', 'Home'],
                        'about'      => ['/public/about', 'About Us'],
                        'admissions' => ['/public/admissions', 'Admissions'],
                        'academics'  => ['/public/academics', 'Academics'],
                        'news'       => ['/public/news', 'News & Events'],
                        'gallery'    => ['/public/gallery', 'Gallery'],
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

                    <?php if ($isLoggedIn): ?>
                        <li class="nav-item dropdown">
                            <a href="#" class="nav-link dropdown-toggle" aria-haspopup="true">
                                <i class="fas fa-user"></i> <?php echo e($_SESSION['user_name']); ?>
                            </a>
                            <ul class="dropdown-menu">
                                <li><a href="<?php echo e($dashboardUrl); ?>">Dashboard</a></li>
                                <li><a href="<?php echo BASE_URL; ?>/logout">Logout</a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-buttons">
                            <a href="<?php echo BASE_URL; ?>/login" class="btn btn-primary">Login</a>
                            <a href="<?php echo BASE_URL; ?>/public/apply" class="btn btn-accent">Apply Now</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </nav>

        <main class="main-content" id="main-content">
