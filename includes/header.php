<?php
// includes/header.php
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - <?php echo $pageTitle ?? 'Home'; ?></title>
    
    <!-- Meta Tags -->
    <meta name="description" content="ST. BENEDICT'S EARLY YEARS BRITISH ACADEMY - Providing quality British Early Years education in Enugu, Nigeria">
    <meta name="keywords" content="school, early years, british curriculum, enugu, nursery, primary">
    <meta name="author" content="ST. BENEDICT'S EARLY YEARS BRITISH ACADEMY">
    
    <!-- Favicon -->
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="manifest" href="/site.webmanifest">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/jquery.dataTables.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <?php if (isset($extraCSS)): ?>
        <?php foreach ($extraCSS as $css): ?>
            <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/<?php echo $css; ?>">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body>
    <div class="site-wrapper">
        <!-- Navigation -->
        <nav class="main-nav">
            <div class="nav-container">
                <div class="logo">
                    <a href="<?php echo BASE_URL; ?>/index.php">
                        <img src="<?php echo BASE_URL; ?>/assets/images/logo.png" alt="ST. BENEDICT'S ACADEMY">
                        <span class="school-name">ST. BENEDICT'S<br><small>Early Years British Academy</small></span>
                    </a>
                </div>
                
                <button class="mobile-menu-toggle" id="mobileMenuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                
                <ul class="nav-menu" id="navMenu">
                    <li class="<?php echo $currentPage == 'index.php' ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/index.php">Home</a>
                    </li>
                    <li class="<?php echo $currentPage == 'about.php' ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/public/about.php">About Us</a>
                    </li>
                    <li class="<?php echo $currentPage == 'admissions.php' ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/public/admissions.php">Admissions</a>
                    </li>
                    <li class="<?php echo $currentPage == 'academics.php' ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/public/academics.php">Academics</a>
                    </li>
                    <li class="<?php echo $currentPage == 'news.php' ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/public/news.php">News & Events</a>
                    </li>
                    <li class="<?php echo $currentPage == 'gallery.php' ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/public/gallery.php">Gallery</a>
                    </li>
                    <li class="<?php echo $currentPage == 'contact.php' ? 'active' : ''; ?>">
                        <a href="<?php echo BASE_URL; ?>/public/contact.php">Contact</a>
                    </li>
                    
                    <?php if (Security::isLoggedIn()): ?>
                        <li class="nav-item dropdown">
                            <a href="#" class="nav-link dropdown-toggle">
                                <i class="fas fa-user"></i> <?php echo $_SESSION['user_name']; ?>
                            </a>
                            <ul class="dropdown-menu">
                                <li><a href="<?php echo BASE_URL; ?>/<?php echo $_SESSION['user_role']; ?>/dashboard.php">Dashboard</a></li>
                                <li><a href="<?php echo BASE_URL; ?>/logout.php">Logout</a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-buttons">
                            <a href="<?php echo BASE_URL; ?>../login.php" class="btn btn-primary">Login</a>
                            <a href="<?php echo BASE_URL; ?>/public/apply.php" class="btn btn-accent">Apply Now</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </nav>
        
        <main class="main-content">