<?php
// public/gallery.php - Photo Gallery
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = 'Gallery - Moments at St. Benedict\'s';
$pageDescription = 'View photos and memories from ST. BENEDICT\'S EARLY YEARS BRITISH ACADEMY. See our students learning, playing, and growing.';

// Set meta tags for SEO
$metaTags = [
    'og:title' => 'Gallery - ST. BENEDICT\'S EARLY YEARS BRITISH ACADEMY',
    'og:description' => 'View photos and memories from our school.',
    'og:image' => BASE_URL . '/assets/images/og-image.jpg',
    'og:url' => BASE_URL . '/public/gallery.php',
    'twitter:card' => 'summary_large_image'
];

// Check if header exists
$headerPath = __DIR__ . '/../includes/header.php';
if (!file_exists($headerPath)) {
    die("Error: Header file not found at: $headerPath");
}
include $headerPath;

// Get database instance
try {
    $db = Database::getInstance();
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    $db = null;
}

// Initialize variables
$categories = [];
$images = [];
$featuredImages = [];

if ($db) {
    try {
        // Get gallery categories
        $categories = $db->getRows(
            "SELECT DISTINCT category FROM gallery WHERE is_published = 1 AND category IS NOT NULL AND category != '' ORDER BY category"
        );

        // Get featured images for slideshow
        $featuredImages = $db->getRows(
            "SELECT * FROM gallery WHERE is_published = 1 AND is_featured = 1 ORDER BY uploaded_at DESC LIMIT 5"
        );

        $selectedCategory = isset($_GET['category']) ? Security::sanitize($_GET['category']) : 'all';

        // Get gallery images with pagination
        $page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = 12;
        $offset = ($page - 1) * $limit;

        if ($selectedCategory === 'all') {
            // Get total count for pagination
            $totalResult = $db->getRow(
                "SELECT COUNT(*) as count FROM gallery WHERE is_published = 1"
            );
            $totalImages = $totalResult ? $totalResult['count'] : 0;
            $totalPages = ceil($totalImages / $limit);

            $images = $db->getRows(
                "SELECT * FROM gallery WHERE is_published = 1 ORDER BY uploaded_at DESC LIMIT ? OFFSET ?",
                [$limit, $offset]
            );
        } else {
            // Get total count for pagination with category
            $totalResult = $db->getRow(
                "SELECT COUNT(*) as count FROM gallery WHERE is_published = 1 AND category = ?",
                [$selectedCategory]
            );
            $totalImages = $totalResult ? $totalResult['count'] : 0;
            $totalPages = ceil($totalImages / $limit);

            $images = $db->getRows(
                "SELECT * FROM gallery WHERE is_published = 1 AND category = ? ORDER BY uploaded_at DESC LIMIT ? OFFSET ?",
                [$selectedCategory, $limit, $offset]
            );
        }
    } catch (Exception $e) {
        error_log("Error fetching gallery: " . $e->getMessage());
    }
}

// Fallback categories if none found
if (empty($categories)) {
    $categories = [
        ['category' => 'academics'],
        ['category' => 'events'],
        ['category' => 'sports'],
        ['category' => 'culture']
    ];
}
?>

<!-- Page-Specific Styles -->
<style>
:root {
    --navy: #002855;
    --navy-dark: #001a3a;
    --red: #c41e3a;
    --red-dark: #a01830;
    --gold: #ffd700;
    --white: #ffffff;
    --light-gray: #f8f9fa;
    --medium-gray: #e9ecef;
    --gray: #6c757d;
    --dark-gray: #495057;
    --shadow-sm: 0 2px 10px rgba(0,0,0,0.05);
    --shadow-md: 0 5px 20px rgba(0,0,0,0.1);
    --shadow-lg: 0 10px 30px rgba(0,0,0,0.15);
    --shadow-xl: 0 20px 40px rgba(0,0,0,0.2);
    --spacing-xs: 5px;
    --spacing-sm: 10px;
    --spacing-md: 20px;
    --spacing-lg: 30px;
    --spacing-xl: 40px;
    --spacing-2xl: 60px;
    --spacing-3xl: 80px;
    --text-xs: 0.75rem;
    --text-sm: 0.875rem;
    --text-md: 1rem;
    --text-lg: 1.125rem;
    --text-xl: 1.25rem;
    --text-2xl: 1.5rem;
    --text-3xl: 1.875rem;
    --text-4xl: 2.25rem;
    --text-5xl: 3rem;
    --transition-fast: 0.3s ease;
    --transition-normal: 0.5s ease;
    --transition-slow: 0.8s ease;
    --radius-md: 8px;
    --radius-lg: 15px;
    --radius-xl: 30px;
    --radius-full: 9999px;
}

/* ===== PAGE HEADER ===== */
.page-header {
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-dark) 100%);
    color: var(--white);
    padding: 48px 0;
    text-align: center;
    position: relative;
    overflow: hidden;
    width: 100%;
    margin: 0 auto;
}

.page-header::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    animation: rotate 60s linear infinite;
}

.page-header h1 {
    color: var(--white);
    font-size: 16px;
    line-height: 19.19px;
    margin-bottom: var(--spacing-sm);
    position: relative;
    display: inline-block;
    font-weight: 600;
    height: 19.19px;
}

.page-header h1::after {
    content: '';
    position: absolute;
    bottom: -10px;
    left: 50%;
    transform: translateX(-50%);
    width: 60px;
    height: 3px;
    background-color: var(--gold);
}

.breadcrumb {
    color: rgba(255, 255, 255, 0.8);
    font-size: var(--text-sm);
    position: relative;
    margin-top: 5px;
}

.breadcrumb a {
    color: var(--gold);
    text-decoration: none;
    transition: color var(--transition-fast);
    font-size: 16px;
    line-height: 20px;
    display: inline-block;
    height: 10px;
}

.breadcrumb a:hover {
    color: var(--white);
    text-decoration: underline;
}

@keyframes rotate {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* ===== CONTAINER ===== */
.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
}

.container-fluid {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 20px;
}

/* ===== SECTION HEADER ===== */
.section-header {
    margin-bottom: var(--spacing-2xl);
}

.section-tag {
    display: inline-block;
    font-size: var(--text-sm);
    text-transform: uppercase;
    letter-spacing: 2px;
    color: var(--red);
    margin-bottom: var(--spacing-xs);
    font-weight: 600;
}

.section-title {
    font-size: var(--text-3xl);
    margin-bottom: var(--spacing-lg);
    color: var(--navy);
    line-height: 1.2;
}

.text-highlight {
    color: var(--red);
    position: relative;
    display: inline-block;
}

.text-highlight::after {
    content: '';
    position: absolute;
    bottom: 5px;
    left: 0;
    width: 100%;
    height: 8px;
    background: rgba(255, 215, 0, 0.2);
    z-index: -1;
}

.text-center {
    text-align: center;
}

/* ===== FEATURED SLIDESHOW ===== */
.featured-slideshow {
    padding: var(--spacing-xl) 0 var(--spacing-lg);
}

.slideshow-container {
    position: relative;
    border-radius: var(--radius-xl);
    overflow: hidden;
    box-shadow: var(--shadow-xl);
    background: var(--navy);
    height: 500px;
}

.slideshow-slide {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    transition: opacity var(--transition-normal);
}

.slideshow-slide.active {
    opacity: 1;
}

.slideshow-slide img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.slide-caption {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    background: linear-gradient(to top, rgba(0,40,85,0.9) 0%, transparent 100%);
    color: var(--white);
    padding: var(--spacing-xl);
    transform: translateY(20px);
    transition: transform var(--transition-normal);
}

.active .slide-caption {
    transform: translateY(0);
}

.slide-caption h2 {
    color: var(--white);
    font-size: var(--text-2xl);
    margin-bottom: var(--spacing-xs);
}

.slide-caption p {
    font-size: var(--text-md);
    opacity: 0.9;
    margin: 0;
}

.slideshow-controls {
    position: absolute;
    bottom: var(--spacing-xl);
    right: var(--spacing-xl);
    display: flex;
    gap: var(--spacing-sm);
    z-index: 10;
}

.slideshow-prev,
.slideshow-next {
    width: 50px;
    height: 50px;
    background: rgba(255,255,255,0.2);
    border: 2px solid var(--white);
    border-radius: 50%;
    color: var(--white);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all var(--transition-fast);
    font-size: var(--text-lg);
    backdrop-filter: blur(5px);
}

.slideshow-prev:hover,
.slideshow-next:hover {
    background: var(--gold);
    border-color: var(--gold);
    color: var(--navy);
    transform: scale(1.1);
}

.slideshow-dots {
    position: absolute;
    bottom: var(--spacing-xl);
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: var(--spacing-sm);
    z-index: 10;
}

.slideshow-dot {
    width: 12px;
    height: 12px;
    background: rgba(255,255,255,0.5);
    border-radius: 50%;
    cursor: pointer;
    transition: all var(--transition-fast);
}

.slideshow-dot:hover,
.slideshow-dot.active {
    background: var(--gold);
    transform: scale(1.2);
}

/* ===== GALLERY FILTER ===== */
.gallery-filter {
    padding: var(--spacing-xl) 0 var(--spacing-lg);
}

.filter-buttons {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: var(--spacing-sm);
}

.filter-btn {
    padding: var(--spacing-sm) var(--spacing-xl);
    background: var(--white);
    border: 2px solid var(--light-gray);
    border-radius: var(--radius-full);
    color: var(--dark-gray);
    font-weight: 500;
    cursor: pointer;
    transition: all var(--transition-fast);
    font-size: var(--text-sm);
    text-decoration: none;
    display: inline-block;
}

.filter-btn:hover,
.filter-btn.active {
    background: var(--navy);
    border-color: var(--navy);
    color: var(--white);
}

/* ===== GALLERY GRID ===== */
.gallery-section {
    padding: var(--spacing-lg) 0 var(--spacing-2xl);
}

.gallery-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--spacing-md);
}

.gallery-item {
    position: relative;
    border-radius: var(--radius-lg);
    overflow: hidden;
    aspect-ratio: 1;
    cursor: pointer;
    box-shadow: var(--shadow-sm);
    transition: all var(--transition-fast);
}

.gallery-item:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-lg);
}

.gallery-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform var(--transition-slow);
}

.gallery-item:hover img {
    transform: scale(1.1);
}

.gallery-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(to top, rgba(0,40,85,0.9) 0%, rgba(0,40,85,0.4) 100%);
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    padding: var(--spacing-lg);
    opacity: 0;
    transition: opacity var(--transition-normal);
    color: var(--white);
}

.gallery-item:hover .gallery-overlay {
    opacity: 1;
}

.gallery-info {
    transform: translateY(20px);
    transition: transform var(--transition-normal);
}

.gallery-item:hover .gallery-info {
    transform: translateY(0);
}

.gallery-info h3 {
    color: var(--white);
    font-size: var(--text-md);
    margin-bottom: var(--spacing-xs);
}

.gallery-info p {
    font-size: var(--text-xs);
    opacity: 0.8;
    margin: 0;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.gallery-actions {
    position: absolute;
    top: var(--spacing-lg);
    right: var(--spacing-lg);
    transform: translateY(-20px);
    transition: transform var(--transition-normal);
}

.gallery-item:hover .gallery-actions {
    transform: translateY(0);
}

.gallery-link {
    display: inline-flex;
    width: 40px;
    height: 40px;
    background: var(--gold);
    border-radius: 50%;
    align-items: center;
    justify-content: center;
    color: var(--navy);
    font-size: var(--text-lg);
    transition: all var(--transition-fast);
    text-decoration: none;
}

.gallery-link:hover {
    background: var(--white);
    transform: scale(1.1);
}

/* ===== NO IMAGES ===== */
.no-images {
    text-align: center;
    padding: var(--spacing-2xl) var(--spacing-lg);
}

.no-images i {
    font-size: var(--text-5xl);
    color: var(--light-gray);
    margin-bottom: var(--spacing-lg);
}

.no-images h3 {
    color: var(--navy);
    margin-bottom: var(--spacing-sm);
}

.no-images p {
    color: var(--gray);
}

/* ===== PAGINATION ===== */
.pagination-section {
    padding: var(--spacing-xl) 0;
    text-align: center;
}

.pagination {
    display: inline-flex;
    gap: var(--spacing-xs);
    flex-wrap: wrap;
    justify-content: center;
}

.page-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 40px;
    height: 40px;
    padding: 0 var(--spacing-sm);
    background: var(--white);
    border: 1px solid var(--medium-gray);
    border-radius: var(--radius-md);
    color: var(--navy);
    text-decoration: none;
    transition: all var(--transition-fast);
    font-size: var(--text-sm);
}

.page-link:hover,
.page-link.active {
    background: var(--navy);
    color: var(--white);
    border-color: var(--navy);
}

/* ===== BUTTON STYLES ===== */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--spacing-sm);
    padding: var(--spacing-md) var(--spacing-xl);
    border-radius: var(--radius-md);
    font-weight: 500;
    cursor: pointer;
    transition: all var(--transition-fast);
    border: none;
    text-decoration: none;
    font-size: var(--text-md);
}

.btn-primary {
    background-color: var(--red);
    color: var(--white);
}

.btn-primary:hover {
    background-color: var(--red-dark);
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

/* ===== ANIMATIONS ===== */
@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.gallery-item {
    animation: fadeIn 0.6s ease both;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 1200px) {
    .gallery-grid {
        grid-template-columns: repeat(3, 1fr);
    }

    .slideshow-container {
        height: 400px;
    }
}

@media (max-width: 992px) {
    .page-header h1 {
        font-size: 60px;
        line-height: 19.19px;
    }

    .breadcrumb a {
        font-size: 35px;
        line-height: 20px;
    }

    .section-title {
        font-size: var(--text-2xl);
    }

    .gallery-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .slideshow-container {
        height: 350px;
    }

    .slide-caption h2 {
        font-size: var(--text-xl);
    }
}

@media (max-width: 768px) {
    .page-header {
        padding: 30px 0;
    }

    .page-header h1 {
        font-size: 48px;
        line-height: 19.19px;
    }

    .breadcrumb a {
        font-size: 28px;
        line-height: 20px;
    }

    .section-title {
        font-size: var(--text-xl);
    }

    .slideshow-container {
        height: 300px;
    }

    .slide-caption {
        padding: var(--spacing-lg);
    }

    .slide-caption h2 {
        font-size: var(--text-lg);
    }

    .slide-caption p {
        font-size: var(--text-sm);
    }

    .slideshow-controls {
        bottom: var(--spacing-lg);
        right: var(--spacing-lg);
    }

    .slideshow-prev,
    .slideshow-next {
        width: 40px;
        height: 40px;
        font-size: var(--text-md);
    }

    .slideshow-dots {
        bottom: var(--spacing-lg);
    }

    .filter-buttons {
        flex-direction: row;
        flex-wrap: wrap;
    }

    .filter-btn {
        width: auto;
        padding: var(--spacing-xs) var(--spacing-lg);
    }
}

@media (max-width: 576px) {
    .page-header {
        padding: 20px 0;
    }

    .page-header h1 {
        font-size: 36px;
        line-height: 19.19px;
    }

    .breadcrumb a {
        font-size: 22px;
        line-height: 20px;
    }

    .section-title {
        font-size: var(--text-lg);
    }

    .gallery-grid {
        grid-template-columns: 1fr;
        gap: var(--spacing-sm);
    }

    .slideshow-container {
        height: 250px;
    }

    .slide-caption h2 {
        font-size: var(--text-md);
    }

    .slide-caption p {
        font-size: var(--text-xs);
    }

    .slideshow-controls {
        bottom: var(--spacing-md);
        right: var(--spacing-md);
    }

    .slideshow-prev,
    .slideshow-next {
        width: 35px;
        height: 35px;
        font-size: var(--text-sm);
    }

    .slideshow-dots {
        bottom: var(--spacing-md);
    }

    .slideshow-dot {
        width: 8px;
        height: 8px;
    }

    .filter-buttons {
        flex-direction: column;
        align-items: center;
    }

    .filter-btn {
        width: 200px;
    }

    .pagination {
        gap: var(--spacing-xs);
    }

    .page-link {
        min-width: 35px;
        height: 35px;
        font-size: var(--text-xs);
    }
}
</style>

<!-- Page Header -->
<section class="page-header">
    <div class="container">
        <h1>Photo Gallery</h1>
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>/index.php">Home</a> / Gallery
        </div>
    </div>
</section>

<!-- Featured Slideshow -->
<?php if (!empty($featuredImages)): ?>
<section class="featured-slideshow">
    <div class="container">
        <div class="slideshow-container" id="slideshow">
            <?php foreach ($featuredImages as $index => $image): ?>
            <div class="slideshow-slide <?php echo $index === 0 ? 'active' : ''; ?>" data-index="<?php echo e($index); ?>">
                <img src="<?php echo BASE_URL; ?>/uploads/gallery/<?php echo htmlspecialchars($image['image_path']); ?>"
                     alt="<?php echo htmlspecialchars($image['title']); ?>">
                <div class="slide-caption">
                    <h2><?php echo htmlspecialchars($image['title']); ?></h2>
                    <?php if (!empty($image['description'])): ?>
                    <p><?php echo htmlspecialchars($image['description']); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="slideshow-controls">
                <button class="slideshow-prev" onclick="changeSlide(-1)">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <button class="slideshow-next" onclick="changeSlide(1)">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>

            <div class="slideshow-dots">
                <?php foreach ($featuredImages as $index => $image): ?>
                <span class="slideshow-dot <?php echo $index === 0 ? 'active' : ''; ?>"
                      onclick="currentSlide(<?php echo e($index); ?>)"></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Gallery Filter -->
<section class="gallery-filter">
    <div class="container">
        <div class="filter-buttons">
            <a href="?category=all" class="filter-btn <?php echo $selectedCategory === 'all' ? 'active' : ''; ?>">
                All Photos
            </a>
            <?php foreach ($categories as $cat): ?>
            <a href="?category=<?php echo urlencode($cat['category']); ?>"
               class="filter-btn <?php echo $selectedCategory === $cat['category'] ? 'active' : ''; ?>">
                <?php echo ucfirst(htmlspecialchars($cat['category'])); ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Gallery Grid -->
<section class="gallery-section">
    <div class="container-fluid">
        <?php if (!empty($images)): ?>
        <div class="gallery-grid" id="galleryGrid">
            <?php foreach ($images as $index => $image): ?>
            <div class="gallery-item" data-category="<?php echo htmlspecialchars($image['category'] ?? ''); ?>">
                <img src="<?php echo BASE_URL; ?>/uploads/gallery/<?php echo htmlspecialchars($image['image_path']); ?>"
                     alt="<?php echo htmlspecialchars($image['title']); ?>"
                     loading="lazy">
                <div class="gallery-overlay">
                    <div class="gallery-info">
                        <h3><?php echo htmlspecialchars($image['title']); ?></h3>
                        <?php if (!empty($image['description'])): ?>
                        <p><?php echo htmlspecialchars($image['description']); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="gallery-actions">
                        <a href="<?php echo BASE_URL; ?>/uploads/gallery/<?php echo htmlspecialchars($image['image_path']); ?>"
                           class="gallery-link"
                           data-fancybox="gallery"
                           data-caption="<?php echo htmlspecialchars($image['title']); ?>">
                            <i class="fas fa-search-plus"></i>
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if (isset($totalPages) && $totalPages > 1): ?>
        <div class="pagination-section">
            <div class="pagination">
                <?php if ($page > 1): ?>
                <a href="?category=<?php echo urlencode($selectedCategory); ?>&page=<?php echo $page - 1; ?>" class="page-link">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?category=<?php echo urlencode($selectedCategory); ?>&page=<?php echo e($i); ?>"
                   class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                    <?php echo e($i); ?>
                </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                <a href="?category=<?php echo urlencode($selectedCategory); ?>&page=<?php echo $page + 1; ?>" class="page-link">
                    <i class="fas fa-chevron-right"></i>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="no-images">
            <i class="fas fa-images"></i>
            <h3>No Images Yet</h3>
            <p>Check back soon for new photos.</p>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- Fancybox CSS and JS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fancyapps/ui@5.0/dist/fancybox/fancybox.css">
<script src="https://cdn.jsdelivr.net/npm/@fancyapps/ui@5.0/dist/fancybox/fancybox.umd.js"></script>

<script>
// Initialize Fancybox
document.addEventListener('DOMContentLoaded', function() {
    Fancybox.bind('[data-fancybox="gallery"]', {
        groupAll: true,
        Thumbs: {
            autoStart: false
        },
        Toolbar: {
            display: {
                left: ["infobar"],
                middle: [
                    "zoomIn",
                    "zoomOut",
                    "toggle1to1",
                    "rotateCCW",
                    "rotateCW",
                    "flipX",
                    "flipY",
                ],
                right: ["slideshow", "thumbs", "close"],
            },
        },
    });

    // Initialize slideshow
    initSlideshow();

    // Add animation on scroll
    const elements = document.querySelectorAll('.gallery-item');

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.animation = 'fadeIn 0.6s ease both';
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1 });

    elements.forEach(el => {
        el.style.opacity = '0';
        observer.observe(el);
    });
});

// Slideshow functionality
let slideIndex = 0;
let slideInterval;
const slides = document.querySelectorAll('.slideshow-slide');
const dots = document.querySelectorAll('.slideshow-dot');

function initSlideshow() {
    if (slides.length > 0) {
        startSlideshow();

        // Pause slideshow on hover
        const container = document.getElementById('slideshow');
        if (container) {
            container.addEventListener('mouseenter', pauseSlideshow);
            container.addEventListener('mouseleave', startSlideshow);
        }
    }
}

function startSlideshow() {
    stopSlideshow();
    slideInterval = setInterval(() => {
        changeSlide(1);
    }, 5000);
}

function stopSlideshow() {
    if (slideInterval) {
        clearInterval(slideInterval);
    }
}

function pauseSlideshow() {
    stopSlideshow();
}

function showSlide(index) {
    if (!slides.length) return;

    if (index >= slides.length) {
        slideIndex = 0;
    } else if (index < 0) {
        slideIndex = slides.length - 1;
    } else {
        slideIndex = index;
    }

    slides.forEach(slide => slide.classList.remove('active'));
    dots.forEach(dot => dot.classList.remove('active'));

    slides[slideIndex].classList.add('active');
    if (dots[slideIndex]) {
        dots[slideIndex].classList.add('active');
    }
}

function changeSlide(n) {
    stopSlideshow();
    showSlide(slideIndex + n);
    startSlideshow();
}

function currentSlide(n) {
    stopSlideshow();
    showSlide(n);
    startSlideshow();
}

// Escape HTML for security
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php
// Check if footer exists
$footerPath = __DIR__ . '/../includes/footer.php';
if (file_exists($footerPath)) {
    include $footerPath;
} else {
    echo "<!-- Footer file not found -->";
}
?>