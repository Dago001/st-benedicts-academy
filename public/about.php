<?php
// public/about.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = 'About Us - ST. BENEDICT\'S EARLY YEARS BRITISH ACADEMY';
$pageDescription = 'Learn about ST. BENEDICT\'S EARLY YEARS BRITISH ACADEMY - our history, mission, vision, leadership, and facilities. Discover why we are the best choice for your child\'s early years education.';

// Set meta tags for SEO
$metaTags = [
    'og:title' => 'About Us - ST. BENEDICT\'S EARLY YEARS BRITISH ACADEMY',
    'og:description' => 'Learn about our school\'s history, mission, vision, and leadership.',
    'og:image' => BASE_URL . '/assets/images/og-image.jpg',
    'og:url' => BASE_URL . '/public/about',
    'twitter:card' => 'summary_large_image'
];

// Define school statements (if not defined in config)
define('MISSION_STATEMENT', cms('home.mission'));
define('VISION_STATEMENT', cms('home.vision'));
define('GOAL_STATEMENT', cms('home.goal'));

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
    echo '<div class="alert alert-danger">Database connection error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    $db = null;
}

// Fetch leadership team with error handling
$leadership = [];
if ($db) {
    try {
        $leadership = $db->getRows(
            "SELECT u.*, t.qualification, t.specialization
             FROM users u
             JOIN teachers t ON u.id = t.user_id
             WHERE u.role = 'teacher' AND u.is_active = 1
             ORDER BY u.first_name, u.last_name
             LIMIT 4"
        );
    } catch (Exception $e) {
        error_log("Error fetching leadership: " . $e->getMessage());
    }
}

// Fallback leadership data if database fails
if (empty($leadership)) {
    $leadership = [
        [
            'first_name' => 'Sr. Mary',
            'last_name' => 'Benedict',
            'specialization' => 'Head of School',
            'qualification' => 'M.Ed, PGCE',
            'profile_image' => ''
        ],
        [
            'first_name' => 'Mrs. Grace',
            'last_name' => 'Okonkwo',
            'specialization' => 'Academic Coordinator',
            'qualification' => 'B.Ed, Early Years',
            'profile_image' => ''
        ],
        [
            'first_name' => 'Mr. John',
            'last_name' => 'Eze',
            'specialization' => 'Administrative Director',
            'qualification' => 'MBA, B.Sc',
            'profile_image' => ''
        ],
        [
            'first_name' => 'Ms. Patricia',
            'last_name' => 'Nnamdi',
            'specialization' => 'Head of Nursery',
            'qualification' => 'B.Ed, Montessori Certified',
            'profile_image' => ''
        ]
    ];
}
?>

<!-- Page-Specific Styles -->
<style>
/* ===== PAGE HEADER ===== */
.page-header {
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-dark) 100%);
    color: var(--white);
    padding: var(--spacing-3xl) 0;
    text-align: center;
    position: relative;
    overflow: hidden;
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
    font-size: var(--text-4xl);
    margin-bottom: var(--spacing-sm);
    position: relative;
    display: inline-block;
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
}

.breadcrumb a {
    color: var(--gold);
    text-decoration: none;
    transition: color var(--transition-fast);
}

.breadcrumb a:hover {
    color: var(--white);
    text-decoration: underline;
}

/* ===== HISTORY SECTION ===== */
.history-section {
    padding: var(--spacing-2xl) 0;
    background: var(--white);
}

.history-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--spacing-xl);
    align-items: center;
}

.history-content h2 {
    color: var(--navy);
    margin-bottom: var(--spacing-md);
    position: relative;
    display: inline-block;
}

.history-content h2::after {
    content: '';
    position: absolute;
    bottom: -5px;
    left: 0;
    width: 60px;
    height: 3px;
    background: linear-gradient(90deg, var(--navy), var(--gold));
}

.history-content .lead {
    font-size: var(--text-xl);
    color: var(--navy);
    font-weight: 500;
    line-height: 1.6;
    margin-bottom: var(--spacing-lg);
    font-style: italic;
}

.history-content p {
    font-size: var(--text-md);
    color: var(--dark-gray);
    margin-bottom: var(--spacing-md);
    line-height: 1.8;
}

.history-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: var(--spacing-md);
    margin-top: var(--spacing-xl);
}

.history-stats .stat {
    text-align: center;
    padding: var(--spacing-lg);
    background: linear-gradient(135deg, var(--light-gray), var(--white));
    border-radius: var(--radius-lg);
    transition: transform var(--transition-fast);
    box-shadow: var(--shadow-md);
    border-bottom: 3px solid transparent;
}

.history-stats .stat:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-lg);
    border-bottom-color: var(--gold);
}

.history-stats .stat-value {
    display: block;
    font-size: var(--text-3xl);
    font-weight: 700;
    color: var(--navy);
    line-height: 1;
    margin-bottom: var(--spacing-xs);
}

.history-stats .stat-label {
    font-size: var(--text-sm);
    color: var(--gray);
    text-transform: uppercase;
    letter-spacing: 1px;
}

.history-image {
    position: relative;
    border-radius: var(--radius-xl);
    overflow: hidden;
    box-shadow: var(--shadow-xl);
}

.history-image::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, rgba(0,40,85,0.3) 0%, rgba(196,30,58,0.3) 100%);
    z-index: 1;
    pointer-events: none;
}

.history-image img {
    width: 100%;
    height: auto;
    display: block;
    transition: transform var(--transition-slow);
}

.history-image:hover img {
    transform: scale(1.05);
}

/* ===== MISSION VISION GOAL SECTION ===== */
.mission-vision {
    padding: var(--spacing-2xl) 0;
    background: linear-gradient(135deg, var(--light-gray) 0%, var(--white) 100%);
}

.mvg-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: var(--spacing-xl);
}

.mvg-card {
    padding: var(--spacing-2xl);
    background: var(--white);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-lg);
    text-align: center;
    transition: all var(--transition-normal);
    position: relative;
    overflow: hidden;
    border-bottom: 3px solid transparent;
}

.mvg-card:hover {
    transform: translateY(-10px);
    box-shadow: var(--shadow-xl);
    border-bottom-color: var(--gold);
}

.mvg-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--navy), var(--gold), var(--red));
    transform: translateX(-100%);
    transition: transform var(--transition-normal);
}

.mvg-card:hover::before {
    transform: translateX(0);
}

.mvg-card i {
    font-size: 3rem;
    color: var(--gold);
    margin-bottom: var(--spacing-lg);
}

.mvg-card h3 {
    color: var(--navy);
    margin-bottom: var(--spacing-md);
    font-size: var(--text-xl);
}

.mvg-card p {
    color: var(--dark-gray);
    line-height: 1.8;
    margin: 0;
    font-size: var(--text-md);
}

.mvg-card.mission i { color: var(--navy); }
.mvg-card.vision i { color: var(--red); }
.mvg-card.goal i { color: var(--gold); }

/* ===== LEADERSHIP SECTION ===== */
.leadership {
    padding: var(--spacing-2xl) 0;
    background: var(--white);
}

.section-title {
    text-align: center;
    font-size: var(--text-3xl);
    margin-bottom: var(--spacing-xl);
    color: var(--navy);
    position: relative;
    padding-bottom: var(--spacing-md);
}

.section-title::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 80px;
    height: 3px;
    background: linear-gradient(90deg, var(--navy), var(--gold), var(--red));
    border-radius: var(--radius-full);
}

.leadership-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--spacing-xl);
}

.leader-card {
    background: var(--white);
    border-radius: var(--radius-lg);
    overflow: hidden;
    box-shadow: var(--shadow-md);
    transition: all var(--transition-normal);
}

.leader-card:hover {
    transform: translateY(-10px);
    box-shadow: var(--shadow-xl);
}

.leader-image {
    height: 280px;
    overflow: hidden;
    position: relative;
}

.leader-image::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 50%;
    background: linear-gradient(to top, rgba(0,0,0,0.5), transparent);
    pointer-events: none;
}

.leader-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform var(--transition-slow);
}

.leader-card:hover .leader-image img {
    transform: scale(1.1);
}

.leader-image .placeholder-image {
    width: 100%;
    height: 100%;
    background: linear-gradient(135deg, var(--navy), var(--navy-dark));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 5rem;
    color: var(--gold);
}

.leader-info {
    padding: var(--spacing-lg);
    text-align: center;
    background: linear-gradient(to bottom, var(--white), var(--light-gray));
}

.leader-info h3 {
    color: var(--navy);
    margin-bottom: var(--spacing-xs);
    font-size: var(--text-lg);
}

.leader-title {
    color: var(--red);
    font-weight: 500;
    margin-bottom: var(--spacing-xs);
    font-size: var(--text-sm);
}

.leader-qualification {
    color: var(--gray);
    font-size: var(--text-xs);
    margin: 0;
}

/* ===== FACILITIES SECTION ===== */
.facilities {
    padding: var(--spacing-2xl) 0;
    background: linear-gradient(135deg, var(--light-gray) 0%, var(--white) 100%);
}

.facilities-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: var(--spacing-xl);
}

.facility-card {
    padding: var(--spacing-xl);
    background: var(--white);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-md);
    text-align: center;
    transition: all var(--transition-normal);
    border-bottom: 3px solid transparent;
}

.facility-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-lg);
    border-bottom-color: var(--gold);
}

.facility-icon {
    width: 80px;
    height: 80px;
    margin: 0 auto var(--spacing-lg);
    background: linear-gradient(135deg, var(--navy), var(--red));
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    color: var(--gold);
    transition: transform var(--transition-fast);
}

.facility-card:hover .facility-icon {
    transform: rotateY(360deg);
}

.facility-card h3 {
    color: var(--navy);
    margin-bottom: var(--spacing-sm);
    font-size: var(--text-lg);
}

.facility-card p {
    color: var(--gray);
    line-height: 1.6;
    margin: 0;
    font-size: var(--text-sm);
}

/* ===== RESPONSIVE STYLES ===== */
@media (max-width: 1200px) {
    .leadership-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 992px) {
    .history-grid {
        grid-template-columns: 1fr;
        gap: var(--spacing-lg);
    }

    .history-image {
        order: -1;
    }

    .mvg-grid {
        grid-template-columns: 1fr;
        max-width: 600px;
        margin: 0 auto;
    }

    .facilities-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .page-header h1 {
        font-size: var(--text-2xl);
    }

    .history-stats {
        grid-template-columns: 1fr;
    }

    .leadership-grid {
        grid-template-columns: 1fr;
        max-width: 400px;
        margin: 0 auto;
    }

    .facilities-grid {
        grid-template-columns: 1fr;
        max-width: 400px;
        margin: 0 auto;
    }

    .leader-image {
        height: 250px;
    }
}

@media (max-width: 576px) {
    .section-title {
        font-size: var(--text-2xl);
    }

    .history-content .lead {
        font-size: var(--text-lg);
    }

    .mvg-card {
        padding: var(--spacing-lg);
    }

    .facility-card {
        padding: var(--spacing-lg);
    }

    .facility-icon {
        width: 60px;
        height: 60px;
        font-size: 2rem;
    }
}

/* Animation */
@keyframes rotate {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
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

.animate-fade-in {
    animation: fadeInUp 0.6s ease;
}
</style>

<!-- Page Header -->
<section class="page-header">
    <div class="container">
        <h1>About Us</h1>
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>/">Home</a> / About Us
        </div>
    </div>
</section>

<!-- School History -->
<section class="history-section">
    <div class="container">
        <div class="history-grid">
            <div class="history-content animate-fade-in">
                <h2><?php echo cms_e('about.story_title'); ?></h2>
                <p class="lead"><?php echo cms_e('about.story_lead'); ?></p>
                <p><?php echo cms_e('about.story_p1'); ?></p>
                <p><?php echo cms_e('about.story_p2'); ?></p>

                <div class="history-stats">
                    <div class="stat">
                        <span class="stat-value"><?php echo cms_e('about.stat1_value'); ?></span>
                        <span class="stat-label"><?php echo cms_e('about.stat1_label'); ?></span>
                    </div>
                    <div class="stat">
                        <span class="stat-value"><?php echo cms_e('about.stat2_value'); ?></span>
                        <span class="stat-label"><?php echo cms_e('about.stat2_label'); ?></span>
                    </div>
                    <div class="stat">
                        <span class="stat-value"><?php echo cms_e('about.stat3_value'); ?></span>
                        <span class="stat-label"><?php echo cms_e('about.stat3_label'); ?></span>
                    </div>
                </div>
            </div>
            <div class="history-image animate-fade-in" style="animation-delay: 0.2s;">
                <img src="<?php echo e(cms_img('about.history_image', BASE_URL . '/assets/images/school-history.jpg')); ?>"
                     alt="School History">
            </div>
        </div>
    </div>
</section>

<!-- Mission Vision Goal -->
<section class="mission-vision">
    <div class="container">
        <div class="mvg-grid">
            <div class="mvg-card mission animate-fade-in">
                <i class="fas fa-heart"></i>
                <h3>Our Mission</h3>
                <p><?php echo e(MISSION_STATEMENT); ?></p>
            </div>
            <div class="mvg-card vision animate-fade-in" style="animation-delay: 0.2s;">
                <i class="fas fa-eye"></i>
                <h3>Our Vision</h3>
                <p><?php echo e(VISION_STATEMENT); ?></p>
            </div>
            <div class="mvg-card goal animate-fade-in" style="animation-delay: 0.4s;">
                <i class="fas fa-bullseye"></i>
                <h3>Our Goal</h3>
                <p><?php echo e(GOAL_STATEMENT); ?></p>
            </div>
        </div>
    </div>
</section>

<!-- Leadership -->
<section class="leadership">
    <div class="container">
        <h2 class="section-title">Our Leadership</h2>

        <div class="leadership-grid">
            <?php foreach ($leadership as $index => $leader): ?>
            <div class="leader-card animate-fade-in" style="animation-delay: <?php echo $index * 0.1; ?>s;">
                <div class="leader-image">
                    <?php if (!empty($leader['profile_image'])): ?>
                    <img src="<?php echo BASE_URL; ?>/uploads/teachers/<?php echo e($leader['profile_image']); ?>"
                         alt="<?php echo htmlspecialchars($leader['first_name'] . ' ' . $leader['last_name']); ?>">
                    <?php else: ?>
                    <div class="placeholder-image">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="leader-info">
                    <h3><?php echo htmlspecialchars($leader['first_name'] . ' ' . $leader['last_name']); ?></h3>
                    <p class="leader-title"><?php echo htmlspecialchars($leader['specialization'] ?? 'Educator'); ?></p>
                    <p class="leader-qualification"><?php echo htmlspecialchars($leader['qualification'] ?? ''); ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Facilities -->
<section class="facilities">
    <div class="container">
        <h2 class="section-title"><?php echo cms_e('about.facilities_title'); ?></h2>

        <div class="facilities-grid">
            <div class="facility-card animate-fade-in">
                <div class="facility-icon">
                    <i class="fas fa-book"></i>
                </div>
                <h3><?php echo cms_e('about.fac1_title'); ?></h3>
                <p><?php echo cms_e('about.fac1_text'); ?></p>
            </div>
            <div class="facility-card animate-fade-in" style="animation-delay: 0.1s;">
                <div class="facility-icon">
                    <i class="fas fa-laptop"></i>
                </div>
                <h3><?php echo cms_e('about.fac2_title'); ?></h3>
                <p><?php echo cms_e('about.fac2_text'); ?></p>
            </div>
            <div class="facility-card animate-fade-in" style="animation-delay: 0.2s;">
                <div class="facility-icon">
                    <i class="fas fa-dumbbell"></i>
                </div>
                <h3><?php echo cms_e('about.fac3_title'); ?></h3>
                <p><?php echo cms_e('about.fac3_text'); ?></p>
            </div>
            <div class="facility-card animate-fade-in" style="animation-delay: 0.3s;">
                <div class="facility-icon">
                    <i class="fas fa-tree"></i>
                </div>
                <h3><?php echo cms_e('about.fac4_title'); ?></h3>
                <p><?php echo cms_e('about.fac4_text'); ?></p>
            </div>
            <div class="facility-card animate-fade-in" style="animation-delay: 0.4s;">
                <div class="facility-icon">
                    <i class="fas fa-first-aid"></i>
                </div>
                <h3><?php echo cms_e('about.fac5_title'); ?></h3>
                <p><?php echo cms_e('about.fac5_text'); ?></p>
            </div>
            <div class="facility-card animate-fade-in" style="animation-delay: 0.5s;">
                <div class="facility-icon">
                    <i class="fas fa-bus"></i>
                </div>
                <h3><?php echo cms_e('about.fac6_title'); ?></h3>
                <p><?php echo cms_e('about.fac6_text'); ?></p>
            </div>
        </div>
    </div>
</section>

<?php
// Check if footer exists
$footerPath = __DIR__ . '/../includes/footer.php';
if (file_exists($footerPath)) {
    include $footerPath;
} else {
    echo "<!-- Footer file not found -->";
}
?>