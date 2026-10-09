<?php
// public/news.php - News & Events Page
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = 'News & Events - Stay Updated';
$pageDescription = 'Latest news and upcoming events at ST. BENEDICT\'S EARLY YEARS BRITISH ACADEMY. Stay informed about school activities and announcements.';

// Set meta tags for SEO
$metaTags = [
    'og:title' => 'News & Events - ST. BENEDICT\'S EARLY YEARS BRITISH ACADEMY',
    'og:description' => 'Latest news and upcoming events at our school.',
    'og:image' => BASE_URL . '/assets/images/og-image.jpg',
    'og:url' => BASE_URL . '/public/news',
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
$news = [];
$events = [];
$featuredNews = null;
$totalNews = 0;
$totalPages = 1;

if ($db) {
    try {
        // Pagination
        $page = isset($_GET['p']) && is_numeric($_GET['p']) ? (int)$_GET['p'] : 1;
        $limit = 6;
        $offset = ($page - 1) * $limit;

        // Get total count for pagination
        $totalNewsResult = $db->getRow(
            "SELECT COUNT(*) as count FROM news_events WHERE is_published = 1 AND type = 'news'"
        );
        $totalNews = $totalNewsResult ? $totalNewsResult['count'] : 0;
        $totalPages = ceil($totalNews / $limit);

        // Get news items
        $news = $db->getRows(
            "SELECT n.*, u.first_name, u.last_name
             FROM news_events n
             LEFT JOIN users u ON n.created_by = u.id
             WHERE n.is_published = 1 AND n.type = 'news'
             ORDER BY n.created_at DESC
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );

        // Get upcoming events
        $events = $db->getRows(
            "SELECT * FROM news_events
             WHERE type = 'event' AND is_published = 1
             AND (event_date >= CURDATE() OR event_date IS NULL)
             ORDER BY event_date ASC
             LIMIT 5"
        );

        // Get featured news
        $featuredNews = $db->getRow(
            "SELECT * FROM news_events
             WHERE is_published = 1 AND type = 'news' AND is_featured = 1
             ORDER BY created_at DESC LIMIT 1"
        );
    } catch (Exception $e) {
        error_log("Error fetching news: " . $e->getMessage());
    }
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

/* ===== FEATURED NEWS ===== */
.featured-news {
    padding: var(--spacing-xl) 0;
}

.featured-card {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--spacing-xl);
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-dark) 100%);
    border-radius: var(--radius-xl);
    overflow: hidden;
    color: var(--white);
    box-shadow: var(--shadow-xl);
}

.featured-image {
    height: 400px;
    overflow: hidden;
}

.featured-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform var(--transition-slow);
}

.featured-card:hover .featured-image img {
    transform: scale(1.05);
}

.featured-content {
    padding: var(--spacing-2xl) var(--spacing-xl);
}

.featured-badge {
    display: inline-block;
    background: var(--gold);
    color: var(--navy);
    padding: var(--spacing-xs) var(--spacing-md);
    border-radius: var(--radius-full);
    font-size: var(--text-sm);
    font-weight: 600;
    margin-bottom: var(--spacing-lg);
}

.featured-content h2 {
    color: var(--white);
    font-size: var(--text-3xl);
    margin-bottom: var(--spacing-md);
}

.featured-meta {
    margin-bottom: var(--spacing-lg);
    color: rgba(255,255,255,0.8);
}

.featured-meta i {
    color: var(--gold);
    margin-right: var(--spacing-xs);
}

.featured-content p {
    font-size: var(--text-md);
    line-height: 1.8;
    margin-bottom: var(--spacing-xl);
    color: rgba(255,255,255,0.9);
}

/* ===== NEWS GRID ===== */
.news-grid-section {
    padding: var(--spacing-2xl) 0;
}

.news-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: var(--spacing-xl);
    margin-bottom: var(--spacing-xl);
}

.news-article {
    background: var(--white);
    border-radius: var(--radius-lg);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    transition: all var(--transition-normal);
}

.news-article:hover {
    transform: translateY(-10px);
    box-shadow: var(--shadow-lg);
}

.article-image {
    position: relative;
    height: 220px;
    overflow: hidden;
}

.article-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform var(--transition-slow);
}

.news-article:hover .article-image img {
    transform: scale(1.1);
}

.article-date {
    position: absolute;
    top: var(--spacing-lg);
    left: var(--spacing-lg);
    background: linear-gradient(135deg, var(--navy), var(--red));
    color: var(--white);
    padding: var(--spacing-sm) var(--spacing-md);
    border-radius: var(--radius-md);
    text-align: center;
    min-width: 60px;
    box-shadow: var(--shadow-md);
}

.article-date .day {
    display: block;
    font-size: var(--text-xl);
    font-weight: 700;
    line-height: 1;
}

.article-date .month {
    font-size: var(--text-xs);
    text-transform: uppercase;
    opacity: 0.9;
}

.article-content {
    padding: var(--spacing-lg);
}

.article-content h3 {
    font-size: var(--text-lg);
    margin-bottom: var(--spacing-sm);
    line-height: 1.4;
}

.article-content h3 a {
    color: var(--navy);
    text-decoration: none;
    transition: color var(--transition-fast);
}

.article-content h3 a:hover {
    color: var(--red);
}

.article-content p {
    color: var(--gray);
    margin-bottom: var(--spacing-md);
    line-height: 1.6;
    font-size: var(--text-sm);
}

.article-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: var(--spacing-md);
    border-top: 1px solid var(--light-gray);
}

.article-author {
    font-size: var(--text-xs);
    color: var(--gray);
}

.article-author i {
    color: var(--gold);
    margin-right: var(--spacing-xs);
}

.read-more {
    color: var(--red);
    font-weight: 500;
    text-decoration: none;
    transition: color var(--transition-fast);
    font-size: var(--text-sm);
}

.read-more:hover {
    color: var(--navy);
}

.read-more i {
    font-size: var(--text-xs);
    transition: transform var(--transition-fast);
}

.read-more:hover i {
    transform: translateX(5px);
}

/* ===== NO NEWS ===== */
.no-news {
    text-align: center;
    padding: var(--spacing-2xl) 0;
}

.no-news i {
    font-size: var(--text-5xl);
    color: var(--light-gray);
    margin-bottom: var(--spacing-lg);
}

.no-news h3 {
    color: var(--navy);
    margin-bottom: var(--spacing-sm);
}

.no-news p {
    color: var(--gray);
}

/* ===== EVENTS TIMELINE ===== */
.events-section {
    padding: var(--spacing-2xl) 0;
    background: linear-gradient(135deg, var(--light-gray) 0%, #ffffff 100%);
}

.events-timeline {
    max-width: 800px;
    margin: 0 auto;
    position: relative;
}

.events-timeline::before {
    content: '';
    position: absolute;
    left: 100px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: linear-gradient(to bottom, var(--gold), var(--navy));
    opacity: 0.3;
}

.timeline-event {
    display: flex;
    gap: var(--spacing-xl);
    margin-bottom: var(--spacing-xl);
    position: relative;
}

.event-date-large {
    width: 100px;
    text-align: center;
    background: var(--white);
    padding: var(--spacing-md);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-md);
    border: 2px solid var(--gold);
}

.event-day {
    display: block;
    font-size: var(--text-3xl);
    font-weight: 700;
    color: var(--navy);
    line-height: 1;
}

.event-month {
    display: block;
    font-size: var(--text-sm);
    color: var(--red);
    text-transform: uppercase;
}

.event-year {
    display: block;
    font-size: var(--text-xs);
    color: var(--gray);
}

.event-details-card {
    flex: 1;
    background: var(--white);
    border-radius: var(--radius-lg);
    padding: var(--spacing-xl);
    box-shadow: var(--shadow-md);
    position: relative;
}

.event-details-card::before {
    content: '';
    position: absolute;
    left: -10px;
    top: 30px;
    width: 20px;
    height: 20px;
    background: var(--white);
    transform: rotate(45deg);
    box-shadow: -5px 5px 10px rgba(0,0,0,0.05);
}

.event-details-card h3 {
    color: var(--navy);
    margin-bottom: var(--spacing-md);
    font-size: var(--text-xl);
}

.event-meta {
    display: flex;
    gap: var(--spacing-lg);
    margin-bottom: var(--spacing-md);
    flex-wrap: wrap;
}

.event-meta span {
    color: var(--gray);
    font-size: var(--text-sm);
}

.event-meta i {
    color: var(--gold);
    margin-right: var(--spacing-xs);
}

.event-details-card p {
    color: var(--dark-gray);
    line-height: 1.6;
    margin-bottom: var(--spacing-lg);
    font-size: var(--text-sm);
}

.event-featured {
    display: inline-block;
    background: var(--gold);
    color: var(--navy);
    padding: var(--spacing-xs) var(--spacing-md);
    border-radius: var(--radius-full);
    font-size: var(--text-xs);
    font-weight: 600;
}

/* ===== NEWSLETTER SECTION ===== */
.newsletter-section {
    padding: var(--spacing-2xl) 0;
}

.newsletter-card {
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-dark) 100%);
    border-radius: var(--radius-xl);
    padding: var(--spacing-2xl);
    text-align: center;
    color: var(--white);
}

.newsletter-content h3 {
    color: var(--white);
    font-size: var(--text-2xl);
    margin-bottom: var(--spacing-md);
}

.newsletter-content p {
    font-size: var(--text-md);
    opacity: 0.9;
    margin-bottom: var(--spacing-xl);
}

.newsletter-form .form-group {
    display: flex;
    max-width: 500px;
    margin: 0 auto;
}

.newsletter-form input {
    flex: 1;
    padding: var(--spacing-md) var(--spacing-lg);
    border: none;
    border-radius: var(--radius-full) 0 0 var(--radius-full);
    font-size: var(--text-md);
    outline: none;
}

.newsletter-form input:focus {
    box-shadow: 0 0 0 3px rgba(255, 215, 0, 0.3);
}

.newsletter-form button {
    border-radius: 0 var(--radius-full) var(--radius-full) 0;
    padding: var(--spacing-md) var(--spacing-xl);
    font-size: var(--text-md);
    cursor: pointer;
    border: none;
}

/* ===== PAGINATION ===== */
.pagination {
    display: flex;
    justify-content: center;
    gap: var(--spacing-sm);
    margin-top: var(--spacing-xl);
    flex-wrap: wrap;
}

.page-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 40px;
    height: 40px;
    padding: 0 var(--spacing-md);
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

.news-article, .timeline-event, .featured-card {
    animation: fadeIn 0.6s ease both;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 1200px) {
    .news-grid {
        grid-template-columns: repeat(2, 1fr);
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

    .featured-card {
        grid-template-columns: 1fr;
    }

    .featured-image {
        height: 300px;
    }

    .news-grid {
        grid-template-columns: repeat(2, 1fr);
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

    .news-grid {
        grid-template-columns: 1fr;
    }

    .timeline-event {
        flex-direction: column;
        gap: var(--spacing-lg);
    }

    .events-timeline::before {
        display: none;
    }

    .event-details-card::before {
        display: none;
    }

    .event-date-large {
        margin: 0 auto;
    }

    .newsletter-card {
        padding: var(--spacing-xl) var(--spacing-lg);
    }

    .newsletter-form .form-group {
        flex-direction: column;
        gap: var(--spacing-sm);
    }

    .newsletter-form input,
    .newsletter-form button {
        border-radius: var(--radius-full);
    }

    .featured-content {
        padding: var(--spacing-xl) var(--spacing-lg);
    }

    .featured-content h2 {
        font-size: var(--text-2xl);
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

    .featured-content h2 {
        font-size: var(--text-xl);
    }

    .event-meta {
        flex-direction: column;
        gap: var(--spacing-xs);
    }

    .pagination {
        gap: var(--spacing-xs);
    }

    .page-link {
        min-width: 35px;
        height: 35px;
        padding: 0 var(--spacing-sm);
        font-size: var(--text-xs);
    }
}
</style>

<!-- Page Header -->
<section class="page-header">
    <div class="container">
        <h1>News & Events</h1>
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>/">Home</a> / News & Events
        </div>
    </div>
</section>

<?php if ($featuredNews): ?>
<!-- Featured News -->
<section class="featured-news">
    <div class="container">
        <div class="featured-card">
            <?php if (!empty($featuredNews['image_path'])): ?>
            <div class="featured-image">
                <img src="<?php echo BASE_URL; ?>/uploads/news/<?php echo htmlspecialchars($featuredNews['image_path']); ?>"
                     alt="<?php echo htmlspecialchars($featuredNews['title']); ?>">
            </div>
            <?php endif; ?>
            <div class="featured-content">
                <span class="featured-badge">Featured Story</span>
                <h2><?php echo htmlspecialchars($featuredNews['title']); ?></h2>
                <div class="featured-meta">
                    <span><i class="far fa-calendar-alt"></i> <?php echo date('F j, Y', strtotime($featuredNews['created_at'])); ?></span>
                </div>
                <p><?php echo htmlspecialchars(substr($featuredNews['content'], 0, 300)); ?>...</p>
                <a href="news-detail?id=<?php echo e($featuredNews['id']); ?>" class="btn btn-primary">
                    Read Full Story <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- News Grid -->
<section class="news-grid-section">
    <div class="container">
        <div class="section-header text-center">
            <span class="section-tag">Latest Updates</span>
            <h2 class="section-title">Recent <span class="text-highlight">News</span></h2>
        </div>

        <?php if (!empty($news)): ?>
        <div class="news-grid">
            <?php foreach ($news as $item): ?>
            <article class="news-article">
                <div class="article-image">
                    <?php if (!empty($item['image_path'])): ?>
                    <img src="<?php echo BASE_URL; ?>/uploads/news/<?php echo htmlspecialchars($item['image_path']); ?>"
                         alt="<?php echo htmlspecialchars($item['title']); ?>">
                    <?php else: ?>
                    <img src="<?php echo BASE_URL; ?>/assets/images/news-placeholder.jpg" alt="News">
                    <?php endif; ?>
                    <div class="article-date">
                        <span class="day"><?php echo date('d', strtotime($item['created_at'])); ?></span>
                        <span class="month"><?php echo date('M', strtotime($item['created_at'])); ?></span>
                    </div>
                </div>
                <div class="article-content">
                    <h3><a href="news-detail?id=<?php echo e($item['id']); ?>"><?php echo htmlspecialchars($item['title']); ?></a></h3>
                    <p><?php echo htmlspecialchars(substr($item['content'], 0, 150)); ?>...</p>
                    <div class="article-footer">
                        <span class="article-author">
                            <i class="far fa-user"></i> <?php echo htmlspecialchars(($item['first_name'] ?? 'Admin') . ' ' . ($item['last_name'] ?? '')); ?>
                        </span>
                        <a href="news-detail?id=<?php echo e($item['id']); ?>" class="read-more">
                            Read More <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="?p=<?php echo $page - 1; ?>" class="page-link">
                <i class="fas fa-chevron-left"></i> Previous
            </a>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?p=<?php echo e($i); ?>" class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                <?php echo e($i); ?>
            </a>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
            <a href="?p=<?php echo $page + 1; ?>" class="page-link">
                Next <i class="fas fa-chevron-right"></i>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="no-news">
            <i class="far fa-newspaper"></i>
            <h3>No News Yet</h3>
            <p>Check back soon for updates and announcements.</p>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- Events Section -->
<?php if (!empty($events)): ?>
<section class="events-section">
    <div class="container">
        <div class="section-header text-center">
            <span class="section-tag">Calendar</span>
            <h2 class="section-title">Upcoming <span class="text-highlight">Events</span></h2>
        </div>

        <div class="events-timeline">
            <?php foreach ($events as $event): ?>
            <div class="timeline-event">
                <div class="event-date-large">
                    <span class="event-day"><?php echo date('d', strtotime($event['event_date'])); ?></span>
                    <span class="event-month"><?php echo date('M', strtotime($event['event_date'])); ?></span>
                    <span class="event-year"><?php echo date('Y', strtotime($event['event_date'])); ?></span>
                </div>
                <div class="event-details-card">
                    <h3><?php echo htmlspecialchars($event['title']); ?></h3>
                    <div class="event-meta">
                        <span><i class="fas fa-clock"></i> <?php echo !empty($event['event_time']) ? date('h:i A', strtotime($event['event_time'])) : 'All day'; ?></span>
                        <?php if (!empty($event['venue'])): ?>
                        <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($event['venue']); ?></span>
                        <?php endif; ?>
                    </div>
                    <p><?php echo htmlspecialchars($event['content']); ?></p>
                    <?php if (!empty($event['is_featured'])): ?>
                    <span class="event-featured">Featured Event</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Newsletter Signup -->
<section class="newsletter-section">
    <div class="container">
        <div class="newsletter-card">
            <div class="newsletter-content">
                <h3>Stay Updated</h3>
                <p>Subscribe to our newsletter to receive the latest news and event updates.</p>
                <form class="newsletter-form" onsubmit="subscribeNewsletter(event)">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                    <div class="form-group">
                        <input type="email" name="email" placeholder="Enter your email address" required>
                        <button type="submit" class="btn btn-primary">Subscribe</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>

<script>
function subscribeNewsletter(event) {
    event.preventDefault();

    const form = event.target;
    const email = form.email.value;
    const csrf = form.csrf_token.value;

    // Show loading state
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Subscribing...';
    submitBtn.disabled = true;

    fetch('../api/subscribe-newsletter', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            email: email,
            csrf_token: csrf
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Thank you for subscribing to our newsletter!');
            form.reset();
        } else {
            alert('Error: ' + (data.message || 'Something went wrong. Please try again.'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    })
    .finally(() => {
        // Restore button state
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
}

// Add animation on scroll
document.addEventListener('DOMContentLoaded', function() {
    const elements = document.querySelectorAll('.news-article, .timeline-event, .featured-card');

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