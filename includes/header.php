<?php
// includes/header.php
// Expects config/config.php + config/security.php to be loaded by the page.
$currentPage = basename($_SERVER['PHP_SELF']);
$assetVersion = '1';

/** <link>/<script> only for assets that actually exist, so a missing file never causes a 404. */
if (!function_exists('asset_exists')) {
    function asset_exists($type, $file) {
        $file = basename($file);
        return is_file(ROOT_PATH . '/assets/' . $type . '/' . $file);
    }
}
$isLoggedIn = Security::isLoggedIn();
$dashboardUrl = $isLoggedIn ? BASE_URL . '/' . $_SESSION['user_role'] . '/dashboard.php' : BASE_URL . '/login.php';
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

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <script>
        window.BASE_URL = <?php echo json_encode(BASE_URL, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        window.CSRF_TOKEN = <?php echo json_encode(Security::generateCSRFToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>

    <?php if ($isLoggedIn): ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/jquery.dataTables.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <?php endif; ?>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css?v=<?php echo $assetVersion; ?>">
    <?php foreach (($extraCSS ?? []) as $css): if (asset_exists('css', $css)): ?>
        <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/<?php echo e(basename($css)); ?>?v=<?php echo $assetVersion; ?>">
    <?php endif; endforeach; ?>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/mobile.css?v=<?php echo $assetVersion; ?>">
</head>
<body class="<?php echo $isLoggedIn ? 'is-auth role-' . e($_SESSION['user_role']) : 'is-public'; ?>">
    <a class="skip-link" href="#main-content">Skip to content</a>
    <div class="site-wrapper">
        <nav class="main-nav" aria-label="Main navigation">
            <div class="nav-container">
                <div class="logo">
                    <a href="<?php echo BASE_URL; ?>/index.php">
                        <img src="<?php echo BASE_URL; ?>/assets/images/logo.png" alt="<?php echo e(SITE_NAME); ?>">
                        <span class="school-name">ST. BENEDICT'S<br><small>Early Years British Academy</small></span>
                    </a>
                </div>

                <button type="button" class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle menu" aria-expanded="false" aria-controls="navMenu">
                    <i class="fas fa-bars"></i>
                </button>

                <ul class="nav-menu" id="navMenu">
                    <?php
                    $links = [
                        'index.php'      => ['/index.php', 'Home'],
                        'about.php'      => ['/public/about.php', 'About Us'],
                        'admissions.php' => ['/public/admissions.php', 'Admissions'],
                        'academics.php'  => ['/public/academics.php', 'Academics'],
                        'news.php'       => ['/public/news.php', 'News & Events'],
                        'gallery.php'    => ['/public/gallery.php', 'Gallery'],
                        'contact.php'    => ['/public/contact.php', 'Contact'],
                    ];
                    foreach ($links as $file => [$path, $label]): ?>
                    <li class="<?php echo $currentPage === $file ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL . $path; ?>"<?php echo $currentPage === $file ? ' aria-current="page"' : ''; ?>><?php echo e($label); ?></a>
                    </li>
                    <?php endforeach; ?>

                    <?php if ($isLoggedIn): ?>
                        <li class="nav-item dropdown">
                            <a href="#" class="nav-link dropdown-toggle" aria-haspopup="true">
                                <i class="fas fa-user"></i> <?php echo e($_SESSION['user_name']); ?>
                            </a>
                            <ul class="dropdown-menu">
                                <li><a href="<?php echo e($dashboardUrl); ?>">Dashboard</a></li>
                                <li><a href="<?php echo BASE_URL; ?>/logout.php">Logout</a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-buttons">
                            <a href="<?php echo BASE_URL; ?>/login.php" class="btn btn-primary">Login</a>
                            <a href="<?php echo BASE_URL; ?>/public/apply.php" class="btn btn-accent">Apply Now</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </nav>

        <main class="main-content" id="main-content">
