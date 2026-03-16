<?php
// public/academics.php - Academics Page
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = 'Academics - British Early Years Curriculum';
$pageDescription = 'Explore our British Early Years curriculum at ST. BENEDICT\'S EARLY YEARS BRITISH ACADEMY. We offer a comprehensive learning approach for children aged 2-7 years.';

// Set meta tags for SEO
$metaTags = [
    'og:title' => 'Academics - ST. BENEDICT\'S EARLY YEARS BRITISH ACADEMY',
    'og:description' => 'Explore our British Early Years curriculum and learning approach.',
    'og:image' => BASE_URL . '/assets/images/og-image.jpg',
    'og:url' => BASE_URL . '/public/academics.php',
    'twitter:card' => 'summary_large_image'
];

// Check if header exists
$headerPath = __DIR__ . '/../includes/header.php';
if (!file_exists($headerPath)) {
    die("Error: Header file not found at: $headerPath");
}
include $headerPath;

// Get database instance - using the same method as admissions.php
try {
    $db = Database::getInstance();
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    $db = null;
}

// Get curriculum info from database or use defaults
$curriculum = [
    'overview' => 'Our curriculum follows the British Early Years Foundation Stage (EYFS) framework, adapted to nurture the whole child - academically, socially, and spiritually. We believe in learning through play, exploration, and guided discovery.',
    'approach' => 'We use a child-centered approach where each child\'s interests and abilities guide their learning journey. Our teachers observe, plan, and assess to ensure every child reaches their full potential.',
    'subjects' => [
        'Communication and Language',
        'Physical Development',
        'Personal, Social and Emotional Development',
        'Literacy',
        'Mathematics',
        'Understanding the World',
        'Expressive Arts and Design',
        'Religious Education'
    ]
];

// Get classes with error handling
$classes = [];
if ($db) {
    try {
        $classes = $db->getRows("SELECT * FROM classes WHERE is_active = 1 ORDER BY class_name");
    } catch (Exception $e) {
        error_log("Error fetching classes: " . $e->getMessage());
    }
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

@keyframes rotate {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* ===== CONTAINER (UPDATED TO MATCH ADMISSIONS.PHP) ===== */
.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 var(--spacing-md);
}

/* Section Header */
.section-header {
    margin-bottom: 50px;
}

.section-tag {
    display: inline-block;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 2px;
    color: #c41e3a;
    margin-bottom: 10px;
    font-weight: 600;
}

.section-title {
    font-size: 2.5rem;
    margin-bottom: 20px;
    color: #002855;
    line-height: 1.2;
}

.text-highlight {
    color: #c41e3a;
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

/* Curriculum Overview */
.curriculum-overview {
    padding: 60px 0;
}

.overview-content {
    max-width: 900px;
    margin: 0 auto;
    text-align: center;
}

.overview-content .lead {
    font-size: 1.2rem;
    color: #002855;
    margin-bottom: 20px;
    font-weight: 500;
}

/* Key Stages */
.key-stages {
    padding: 60px 0;
    background: linear-gradient(135deg, #002855 0%, #001a3a 100%);
    color: white;
}

.stages-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 30px;
}

.stage-card {
    background: rgba(255,255,255,0.1);
    border-radius: 20px;
    padding: 40px 30px;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,0.2);
    transition: transform 0.3s ease;
}

.stage-card:hover {
    transform: translateY(-10px);
}

.stage-age {
    display: inline-block;
    background: #ffd700;
    color: #002855;
    padding: 5px 15px;
    border-radius: 25px;
    font-weight: 600;
    margin-bottom: 20px;
}

.stage-title {
    color: white;
    font-size: 1.8rem;
    margin-bottom: 20px;
}

.stage-content {
    color: rgba(255,255,255,0.9);
}

.stage-content h4 {
    color: #ffd700;
    margin: 20px 0 10px;
    font-size: 1.1rem;
}

.stage-content ul {
    list-style: none;
    padding: 0;
}

.stage-content li {
    padding: 5px 0;
    position: relative;
    padding-left: 20px;
}

.stage-content li::before {
    content: '✓';
    position: absolute;
    left: 0;
    color: #ffd700;
}

/* Subjects Section */
.subjects-section {
    padding: 60px 0;
}

.subjects-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 25px;
    margin-top: 40px;
}

.subject-card {
    background: white;
    border-radius: 15px;
    padding: 30px 20px;
    text-align: center;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    transition: all 0.3s ease;
    border-bottom: 3px solid transparent;
}

.subject-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border-bottom-color: #ffd700;
}

.subject-icon {
    width: 70px;
    height: 70px;
    margin: 0 auto 20px;
    background: linear-gradient(135deg, #002855, #c41e3a);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffd700;
    font-size: 1.8rem;
}

.subject-card h3 {
    font-size: 1.1rem;
    margin-bottom: 10px;
    color: #002855;
}

.subject-card p {
    font-size: 0.9rem;
    color: #6c757d;
    margin: 0;
    line-height: 1.6;
}

/* Approach Section */
.approach-section {
    padding: 60px 0;
    background: #f8f9fa;
}

.approach-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 50px;
    align-items: center;
}

.approach-features {
    margin-top: 30px;
}

.approach-feature {
    display: flex;
    gap: 20px;
    margin-bottom: 25px;
}

.approach-feature i {
    font-size: 2rem;
    color: #ffd700;
}

.approach-feature h4 {
    color: #002855;
    margin-bottom: 5px;
}

.approach-feature p {
    color: #6c757d;
    margin: 0;
}

.approach-image {
    position: relative;
}

.approach-image img {
    width: 100%;
    border-radius: 20px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.approach-quote {
    position: absolute;
    bottom: -30px;
    left: 50%;
    transform: translateX(-50%);
    background: white;
    padding: 25px;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    width: 80%;
    text-align: center;
}

.approach-quote i {
    color: #ffd700;
    font-size: 2rem;
    opacity: 0.3;
    margin-bottom: 10px;
}

.approach-quote p {
    font-style: italic;
    color: #002855;
    margin-bottom: 5px;
}

.approach-quote small {
    color: #6c757d;
}

/* Assessment Section */
.assessment-section {
    padding: 60px 0;
}

.assessment-card {
    background: white;
    border-radius: 30px;
    padding: 50px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    text-align: center;
}

.assessment-card h2 {
    color: #002855;
    margin-bottom: 15px;
    font-size: 2rem;
}

.assessment-card > p {
    color: #6c757d;
    margin-bottom: 40px;
}

.assessment-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 30px;
    margin-bottom: 40px;
}

.assessment-item {
    padding: 20px;
}

.assessment-item i {
    font-size: 2.5rem;
    color: #ffd700;
    margin-bottom: 15px;
}

.assessment-item h4 {
    color: #002855;
    margin-bottom: 10px;
}

.assessment-item p {
    color: #6c757d;
    margin: 0;
    font-size: 0.9rem;
    line-height: 1.6;
}

.assessment-note {
    display: flex;
    align-items: center;
    gap: 15px;
    background: #f8f9fa;
    padding: 20px;
    border-radius: 15px;
    text-align: left;
}

.assessment-note i {
    color: #17a2b8;
    font-size: 1.5rem;
}

.assessment-note p {
    margin: 0;
    color: #495057;
}

/* Responsive */
@media (max-width: 992px) {
    .stages-grid,
    .subjects-grid,
    .assessment-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .approach-grid {
        grid-template-columns: 1fr;
        gap: 30px;
    }
    
    .approach-quote {
        position: relative;
        bottom: 0;
        width: 100%;
        margin-top: 20px;
    }
}

@media (max-width: 768px) {
    .page-header {
        padding: var(--spacing-2xl) 0;
    }
    
    .page-header h1 {
        font-size: var(--text-2xl);
    }
    
    .section-title {
        font-size: 2rem;
    }
    
    .stages-grid,
    .subjects-grid,
    .assessment-grid {
        grid-template-columns: 1fr;
    }
    
    .assessment-card {
        padding: 30px 20px;
    }
    
    .assessment-note {
        flex-direction: column;
        text-align: center;
    }
    
    .approach-feature {
        flex-direction: column;
        text-align: center;
    }
}

@media (max-width: 576px) {
    .page-header h1 {
        font-size: var(--text-xl);
    }
    
    .section-title {
        font-size: 1.5rem;
    }
    
    .stage-title {
        font-size: 1.5rem;
    }
    
    .assessment-item {
        padding: 10px;
    }
}

/* Animation */
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

.stage-card, .subject-card, .approach-feature, .assessment-item {
    animation: fadeIn 0.6s ease both;
}
</style>

<!-- Page Header - UPDATED TO MATCH ADMISSIONS.PHP -->
<section class="page-header">
    <div class="container">
        <h1>Academics</h1>
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>/index.php">Home</a> / Academics
        </div>
    </div>
</section>

<!-- Curriculum Overview -->
<section class="curriculum-overview">
    <div class="container">
        <div class="section-header text-center">
            <span class="section-tag">Our Curriculum</span>
            <h2 class="section-title">British Early Years <span class="text-highlight">Foundation Stage</span></h2>
        </div>
        
        <div class="overview-content">
            <p class="lead"><?php echo $curriculum['overview']; ?></p>
            <p><?php echo $curriculum['approach']; ?></p>
        </div>
    </div>
</section>

<!-- Key Stages -->
<section class="key-stages">
    <div class="container">
        <div class="stages-grid">
            <!-- Nursery Stage -->
            <div class="stage-card">
                <div class="stage-age">Ages 2-3</div>
                <h3 class="stage-title">Nursery</h3>
                <div class="stage-content">
                    <p>The Nursery stage focuses on developing independence, social skills, and early communication through structured play and exploration.</p>
                    <h4>Key Learning Areas:</h4>
                    <ul>
                        <li>Communication and language</li>
                        <li>Physical development</li>
                        <li>Personal, social and emotional development</li>
                        <li>Early literacy and numeracy</li>
                        <li>Creative expression</li>
                    </ul>
                </div>
            </div>
            
            <!-- Reception Stage -->
            <div class="stage-card">
                <div class="stage-age">Ages 4-5</div>
                <h3 class="stage-title">Reception</h3>
                <div class="stage-content">
                    <p>Reception builds on Nursery learning with more structured activities preparing children for formal education.</p>
                    <h4>Key Learning Areas:</h4>
                    <ul>
                        <li>Phonics and early reading</li>
                        <li>Writing development</li>
                        <li>Number concepts and problem-solving</li>
                        <li>Understanding the world</li>
                        <li>Expressive arts and design</li>
                    </ul>
                </div>
            </div>
            
            <!-- Year 1-2 Stage -->
            <div class="stage-card">
                <div class="stage-age">Ages 5-7</div>
                <h3 class="stage-title">Year 1 & 2</h3>
                <div class="stage-content">
                    <p>Years 1 and 2 introduce more formal learning while maintaining a hands-on, engaging approach.</p>
                    <h4>Key Learning Areas:</h4>
                    <ul>
                        <li>Reading comprehension</li>
                        <li>Creative writing</li>
                        <li>Mathematics mastery</li>
                        <li>Science investigation</li>
                        <li>History and geography</li>
                        <li>Computing skills</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Subjects Offered -->
<section class="subjects-section">
    <div class="container">
        <div class="section-header text-center">
            <span class="section-tag">Subjects</span>
            <h2 class="section-title">What We <span class="text-highlight">Teach</span></h2>
        </div>
        
        <div class="subjects-grid">
            <?php foreach ($curriculum['subjects'] as $subject): ?>
            <div class="subject-card">
                <div class="subject-icon">
                    <?php
                    $icons = [
                        'Communication and Language' => 'fa-comments',
                        'Physical Development' => 'fa-running',
                        'Personal, Social and Emotional Development' => 'fa-heart',
                        'Literacy' => 'fa-book-open',
                        'Mathematics' => 'fa-calculator',
                        'Understanding the World' => 'fa-globe',
                        'Expressive Arts and Design' => 'fa-paint-brush',
                        'Religious Education' => 'fa-church'
                    ];
                    $icon = $icons[$subject] ?? 'fa-graduation-cap';
                    ?>
                    <i class="fas <?php echo $icon; ?>"></i>
                </div>
                <h3><?php echo $subject; ?></h3>
                <p>Age-appropriate learning activities designed to develop skills and knowledge in <?php echo strtolower($subject); ?>.</p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Learning Approach -->
<section class="approach-section">
    <div class="container">
        <div class="approach-grid">
            <div class="approach-content">
                <span class="section-tag">Our Approach</span>
                <h2 class="section-title">Learning Through <span class="text-highlight">Play & Discovery</span></h2>
                <p>We believe that children learn best when they are actively engaged and having fun. Our approach combines:</p>
                
                <div class="approach-features">
                    <div class="approach-feature">
                        <i class="fas fa-play-circle"></i>
                        <div>
                            <h4>Child-Initiated Play</h4>
                            <p>Children choose activities that interest them, fostering independence and motivation.</p>
                        </div>
                    </div>
                    
                    <div class="approach-feature">
                        <i class="fas fa-users"></i>
                        <div>
                            <h4>Adult-Guided Activities</h4>
                            <p>Teachers facilitate learning through carefully planned activities and interventions.</p>
                        </div>
                    </div>
                    
                    <div class="approach-feature">
                        <i class="fas fa-seedling"></i>
                        <div>
                            <h4>Outdoor Learning</h4>
                            <p>Regular outdoor sessions for physical development and connection with nature.</p>
                        </div>
                    </div>
                    
                    <div class="approach-feature">
                        <i class="fas fa-pray"></i>
                        <div>
                            <h4>Faith Integration</h4>
                            <p>Christian values and teachings woven throughout the curriculum.</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="approach-image">
                <img src="<?php echo BASE_URL; ?>/assets/images/learning-approach.jpeg" alt="Children learning through play">
                <div class="approach-quote">
                    <i class="fas fa-quote-left"></i>
                    <p>Play is the highest form of research.</p>
                    <small>- Albert Einstein</small>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Assessment -->
<section class="assessment-section">
    <div class="container">
        <div class="assessment-card">
            <h2>Assessment & Progress Tracking</h2>
            <p>We continuously monitor each child's progress through:</p>
            
            <div class="assessment-grid">
                <div class="assessment-item">
                    <i class="fas fa-eye"></i>
                    <h4>Observation</h4>
                    <p>Regular observations of children during play and activities</p>
                </div>
                
                <div class="assessment-item">
                    <i class="fas fa-folder-open"></i>
                    <h4>Learning Journeys</h4>
                    <p>Digital portfolios documenting each child's achievements</p>
                </div>
                
                <div class="assessment-item">
                    <i class="fas fa-chart-line"></i>
                    <h4>Progress Checks</h4>
                    <p>Termly assessments against age-expected outcomes</p>
                </div>
                
                <div class="assessment-item">
                    <i class="fas fa-users-cog"></i>
                    <h4>Parent Consultations</h4>
                    <p>Regular meetings to discuss progress and next steps</p>
                </div>
            </div>
            
            <div class="assessment-note">
                <i class="fas fa-info-circle"></i>
                <p>Parents receive detailed reports at the end of each term and are invited to discuss their child's progress with teachers.</p>
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