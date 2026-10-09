<?php
// index.php - Professional Landing Page with Chatbot
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/helpers.php'; // Add this
require_once 'config/security.php';

$pageTitle = 'ST. BENEDICT\'S EARLY YEARS BRITISH ACADEMY - Excellence in Early Years Education';
$pageDescription = 'ST. BENEDICT\'S EARLY YEARS BRITISH ACADEMY provides quality British Early Years education in Enugu, Nigeria. Join us for a faith-filled learning experience.';

// Set meta tags for SEO
$metaTags = [
    'og:title' => 'ST. BENEDICT\'S EARLY YEARS BRITISH ACADEMY',
    'og:description' => 'Through Christ\'s guidance, we build strong minds and kind hearts for the future.',
    'og:image' => BASE_URL . '/assets/images/og-image.jpg',
    'og:url' => BASE_URL,
    'twitter:card' => 'summary_large_image'
];

// Include header
include 'includes/header.php';

$db = db();

// Fetch quick stats with error handling
try {
    $stats = [
        'students' => $db->getRow("SELECT COUNT(*) as count FROM students s JOIN users u ON s.user_id = u.id WHERE u.deleted_at IS NULL")['count'] ?? 0,
        'teachers' => $db->getRow("SELECT COUNT(*) as count FROM teachers t JOIN users u ON t.user_id = u.id WHERE u.deleted_at IS NULL AND u.is_active = 1")['count'] ?? 0,
        'classes' => $db->getRow("SELECT COUNT(*) as count FROM classes WHERE is_active = 1")['count'] ?? 0,
        'years' => max(1, (int)date('Y') - (int)cms('school.founded_year'))
    ];

    // Fetch latest news
    $news = $db->getRows(
        "SELECT * FROM news_events WHERE type = 'news' AND is_published = 1 ORDER BY created_at DESC LIMIT 3"
    );

    // Fetch upcoming events
    $events = $db->getRows(
        "SELECT * FROM news_events WHERE type = 'event' AND is_published = 1 AND event_date >= CURDATE() ORDER BY event_date ASC LIMIT 3"
    );
} catch (Exception $e) {
    // Fallback if database fails
    $stats = ['students' => 320, 'teachers' => 28, 'classes' => 12, 'years' => 14];
    $news = [];
    $events = [];
}
?>

<!-- Page-Specific Styles -->
<style>
    /* ===== HERO SECTION ===== */
    .hero-section {
        position: relative;
        min-height: 100vh;
        overflow: hidden;
        background-color: var(--navy);
    }

    .hero-slider {
        position: relative;
        height: 100vh;
        width: 100%;
    }

    .hero-slide {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-size: cover;
        background-position: center;
        opacity: 0;
        transition: opacity 1s ease-in-out;
        display: flex;
        align-items: center;
        z-index: 1;
    }

    .hero-slide.active {
        opacity: 1;
        z-index: 2;
    }

    .hero-content {
        color: var(--white);
        max-width: 800px;
        padding: var(--spacing-xl) 0;
        animation: fadeInUp 1s ease;
    }

    .hero-subtitle {
        display: inline-block;
        font-size: 1.2rem;
        text-transform: uppercase;
        letter-spacing: 3px;
        color: var(--gold);
        margin-bottom: var(--spacing-sm);
        font-weight: 600;
    }

    .hero-title {
        font-size: clamp(2rem, 5vw, 3.5rem);
        font-weight: 700;
        margin-bottom: var(--spacing-sm);
        color: var(--white);
        line-height: 1.2;
        text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
    }

    .hero-title span {
        color: var(--gold);
    }

    .hero-motto {
        font-size: clamp(1.2rem, 3vw, 1.5rem);
        margin-bottom: var(--spacing-xs);
        font-family: var(--font-secondary);
        color: var(--gold);
    }

    .hero-translation {
        font-size: clamp(0.9rem, 2vw, 1.1rem);
        opacity: 0.9;
        margin-bottom: var(--spacing-lg);
        font-style: italic;
    }

    .hero-buttons {
        display: flex;
        gap: var(--spacing-sm);
        flex-wrap: wrap;
    }

    .hero-buttons .btn {
        min-width: 180px;
    }

    /* Slider Controls */
    .slider-controls {
        position: absolute;
        bottom: 150px;
        left: 50%;
        transform: translateX(-50%);
        display: flex;
        align-items: center;
        gap: var(--spacing-md);
        z-index: 10;
    }

    .slider-prev,
    .slider-next {
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
        font-size: 1.2rem;
    }

    .slider-prev:hover,
    .slider-next:hover {
        background: var(--gold);
        border-color: var(--gold);
        color: var(--navy);
        transform: scale(1.1);
    }

    .slider-dots {
        display: flex;
        gap: 10px;
    }

    .dot {
        width: 12px;
        height: 12px;
        background: rgba(255,255,255,0.5);
        border-radius: 50%;
        cursor: pointer;
        transition: all var(--transition-fast);
    }

    .dot.active {
        background: var(--gold);
        transform: scale(1.2);
    }

    /* Hero Stats */
    .hero-stats {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        background: rgba(0, 40, 85, 0.95);
        backdrop-filter: blur(10px);
        padding: var(--spacing-md) 0;
        border-top: 3px solid var(--gold);
        z-index: 10;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: var(--spacing-md);
    }

    .stat-item {
        display: flex;
        align-items: center;
        gap: var(--spacing-sm);
        color: var(--white);
    }

    .stat-icon {
        width: 50px;
        height: 50px;
        background: rgba(255, 215, 0, 0.2);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        color: var(--gold);
        flex-shrink: 0;
    }

    .stat-number {
        display: block;
        font-size: clamp(1.2rem, 3vw, 1.8rem);
        font-weight: 700;
        color: var(--gold);
        line-height: 1;
    }

    .stat-label {
        font-size: clamp(0.7rem, 1.5vw, 0.9rem);
        opacity: 0.9;
    }

    /* ===== WELCOME SECTION ===== */
    .welcome-section {
        padding: var(--spacing-2xl) 0;
        background: var(--white);
    }

    .welcome-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: var(--spacing-xl);
        align-items: center;
    }

    .section-tag {
        display: inline-block;
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 2px;
        color: var(--red);
        margin-bottom: var(--spacing-xs);
        font-weight: 600;
    }

    .section-title {
        font-size: clamp(1.8rem, 4vw, 2.5rem);
        margin-bottom: var(--spacing-sm);
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

    .welcome-text {
        font-size: clamp(0.95rem, 2vw, 1.1rem);
        line-height: 1.8;
        color: var(--dark-gray);
        margin-bottom: var(--spacing-lg);
    }

    .features-list {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: var(--spacing-md);
        margin-bottom: var(--spacing-lg);
    }

    .feature-item {
        display: flex;
        gap: var(--spacing-sm);
    }

    .feature-icon {
        color: var(--gold);
        font-size: 1.5rem;
        flex-shrink: 0;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(255, 215, 0, 0.1);
        border-radius: 50%;
    }

    .feature-text h4 {
        font-size: 1.1rem;
        margin-bottom: 5px;
        color: var(--navy);
    }

    .feature-text p {
        font-size: 0.9rem;
        color: var(--gray);
        margin: 0;
    }

    .welcome-image {
        position: relative;
    }

    .image-wrapper {
        position: relative;
        border-radius: var(--radius-xl);
        overflow: hidden;
        box-shadow: var(--shadow-xl);
    }

    .image-wrapper img {
        width: 100%;
        height: auto;
        display: block;
        transition: transform var(--transition-slow);
    }

    .image-wrapper:hover img {
        transform: scale(1.05);
    }

    .experience-badge {
        position: absolute;
        bottom: 30px;
        right: 30px;
        background: linear-gradient(135deg, var(--navy), var(--red));
        color: var(--white);
        padding: 20px;
        border-radius: 50%;
        width: 120px;
        height: 120px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        box-shadow: var(--shadow-lg);
        animation: float 3s ease-in-out infinite;
    }

    .experience-badge .years {
        font-size: 2rem;
        font-weight: 700;
        line-height: 1;
        margin-bottom: 5px;
        color: var(--gold);
    }

    .experience-badge .text {
        font-size: 0.8rem;
        opacity: 0.9;
    }

    /* ===== QUICK STATS SECTION ===== */
    .quick-stats {
        padding: var(--spacing-xl) 0;
        background: linear-gradient(135deg, var(--navy-light), var(--navy));
        color: var(--white);
    }

    .stats-container {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: var(--spacing-lg);
        text-align: center;
    }

    .stat-box {
        padding: var(--spacing-lg);
        background: rgba(255, 255, 255, 0.1);
        border-radius: var(--radius-lg);
        backdrop-filter: blur(10px);
        transition: transform var(--transition-fast);
    }

    .stat-box:hover {
        transform: translateY(-5px);
        background: rgba(255, 255, 255, 0.15);
    }

    .stat-box i {
        font-size: 2.5rem;
        color: var(--gold);
        margin-bottom: var(--spacing-sm);
    }

    .stat-box .number {
        font-size: 2rem;
        font-weight: 700;
        color: var(--gold);
        line-height: 1.2;
        margin-bottom: var(--spacing-xs);
    }

    .stat-box .label {
        font-size: 0.9rem;
        opacity: 0.9;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    /* ===== MVG SLIDESHOW SECTION ===== */
    .mvg-slideshow {
        padding: var(--spacing-2xl) 0;
        background: linear-gradient(135deg, var(--light-gray) 0%, var(--white) 100%);
        position: relative;
        overflow: hidden;
    }

    .mvg-container {
        max-width: 900px;
        margin: 0 auto;
        position: relative;
        min-height: 400px;
    }

    .mvg-slide {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.8s ease, visibility 0.8s ease;
        text-align: center;
        padding: var(--spacing-xl);
        background: var(--white);
        border-radius: var(--radius-xl);
        box-shadow: var(--shadow-lg);
    }

    .mvg-slide.active {
        opacity: 1;
        visibility: visible;
        position: relative;
    }

    .mvg-icon {
        width: 100px;
        height: 100px;
        margin: 0 auto var(--spacing-lg);
        background: linear-gradient(135deg, var(--navy), var(--red));
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 3rem;
        color: var(--gold);
        box-shadow: var(--shadow-md);
        animation: float 3s ease-in-out infinite;
    }

    .mvg-slide h2 {
        color: var(--navy);
        font-size: clamp(2rem, 4vw, 2.5rem);
        margin-bottom: var(--spacing-md);
        position: relative;
        display: inline-block;
    }

    .mvg-slide h2::after {
        content: '';
        position: absolute;
        bottom: -10px;
        left: 50%;
        transform: translateX(-50%);
        width: 80px;
        height: 3px;
        background: linear-gradient(90deg, var(--navy), var(--gold), var(--red));
        border-radius: var(--radius-full);
    }

    .mvg-slide p {
        font-size: clamp(1.1rem, 2.5vw, 1.3rem);
        line-height: 1.8;
        color: var(--dark-gray);
        max-width: 700px;
        margin: 0 auto;
        font-style: italic;
    }

    .mvg-controls {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: var(--spacing-lg);
        margin-top: var(--spacing-xl);
    }

    .mvg-prev,
    .mvg-next {
        width: 50px;
        height: 50px;
        background: var(--white);
        border: 2px solid var(--navy);
        border-radius: 50%;
        color: var(--navy);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all var(--transition-fast);
        font-size: 1.2rem;
    }

    .mvg-prev:hover,
    .mvg-next:hover {
        background: var(--navy);
        color: var(--white);
        transform: scale(1.1);
    }

    .mvg-dots {
        display: flex;
        gap: 12px;
    }

    .mvg-dot {
        width: 12px;
        height: 12px;
        background: var(--light-gray);
        border: 2px solid var(--navy);
        border-radius: 50%;
        cursor: pointer;
        transition: all var(--transition-fast);
    }

    .mvg-dot.active {
        background: var(--gold);
        border-color: var(--gold);
        transform: scale(1.2);
    }

    /* ===== PROGRAMS SECTION ===== */
    .programs-section {
        padding: var(--spacing-2xl) 0;
        background: var(--white);
    }

    .programs-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: var(--spacing-lg);
        margin-top: var(--spacing-lg);
    }

    .program-card {
        background: var(--white);
        border-radius: var(--radius-lg);
        padding: var(--spacing-xl);
        box-shadow: var(--shadow-md);
        text-align: center;
        transition: all var(--transition-normal);
        border-bottom: 3px solid transparent;
        position: relative;
        overflow: hidden;
    }

    .program-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, var(--navy), var(--gold), var(--red));
        transform: translateX(-100%);
        transition: transform var(--transition-normal);
    }

    .program-card:hover {
        transform: translateY(-10px);
        box-shadow: var(--shadow-xl);
    }

    .program-card:hover::before {
        transform: translateX(0);
    }

    .program-icon {
        width: 70px;
        height: 70px;
        margin: 0 auto var(--spacing-md);
        background: linear-gradient(135deg, var(--navy), var(--red));
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        color: var(--gold);
        transition: transform var(--transition-fast);
    }

    .program-card:hover .program-icon {
        transform: rotateY(360deg);
    }

    .program-card h3 {
        color: var(--navy);
        margin-bottom: var(--spacing-xs);
        font-size: 1.2rem;
    }

    .program-age {
        color: var(--red);
        font-weight: 600;
        margin-bottom: var(--spacing-sm);
        font-size: 0.9rem;
    }

    .program-desc {
        color: var(--gray);
        margin-bottom: var(--spacing-md);
        line-height: 1.6;
        font-size: 0.9rem;
    }

    /* ===== NEWS SECTION ===== */
    .news-section {
        padding: var(--spacing-2xl) 0;
        background: var(--light-gray);
    }

    .news-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: var(--spacing-lg);
        margin-top: var(--spacing-lg);
    }

    .news-card {
        background: var(--white);
        border-radius: var(--radius-lg);
        overflow: hidden;
        box-shadow: var(--shadow-md);
        transition: all var(--transition-normal);
    }

    .news-card:hover {
        transform: translateY(-10px);
        box-shadow: var(--shadow-xl);
    }

    .news-image {
        position: relative;
        height: 200px;
        overflow: hidden;
    }

    .news-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform var(--transition-slow);
    }

    .news-card:hover .news-image img {
        transform: scale(1.1);
    }

    .news-date {
        position: absolute;
        top: 15px;
        left: 15px;
        background: linear-gradient(135deg, var(--navy), var(--red));
        color: var(--white);
        padding: 8px 12px;
        border-radius: var(--radius-md);
        text-align: center;
        min-width: 50px;
        box-shadow: var(--shadow-md);
    }

    .news-date .day {
        display: block;
        font-size: 1.2rem;
        font-weight: 700;
        line-height: 1;
    }

    .news-date .month {
        font-size: 0.7rem;
        text-transform: uppercase;
        opacity: 0.9;
    }

    .news-content {
        padding: var(--spacing-lg);
    }

    .news-content h3 {
        font-size: 1.1rem;
        margin-bottom: var(--spacing-xs);
        color: var(--navy);
        line-height: 1.4;
    }

    .news-content h3 a {
        color: var(--navy);
        text-decoration: none;
        transition: color var(--transition-fast);
    }

    .news-content h3 a:hover {
        color: var(--red);
    }

    .news-content p {
        color: var(--gray);
        margin-bottom: var(--spacing-sm);
        font-size: 0.9rem;
        line-height: 1.6;
    }

    .news-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding-top: var(--spacing-sm);
        border-top: 1px solid var(--light-gray);
    }

    .news-author {
        font-size: 0.8rem;
        color: var(--gray);
    }

    .news-author i {
        color: var(--gold);
        margin-right: var(--spacing-xs);
    }

    .read-more {
        color: var(--red);
        font-size: 0.9rem;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: var(--spacing-xs);
        text-decoration: none;
        transition: color var(--transition-fast);
    }

    .read-more:hover {
        color: var(--navy);
    }

    .read-more i {
        font-size: 0.8rem;
        transition: transform var(--transition-fast);
    }

    .read-more:hover i {
        transform: translateX(5px);
    }

    /* ===== CTA SECTION ===== */
    .cta-section {
        padding: var(--spacing-2xl) 0;
        background: linear-gradient(135deg, var(--navy) 0%, var(--red) 100%);
        color: var(--white);
        text-align: center;
        position: relative;
        overflow: hidden;
    }

    .cta-section::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
        animation: rotate 60s linear infinite;
    }

    .cta-content {
        position: relative;
        z-index: 1;
    }

    .cta-content h2 {
        color: var(--white);
        font-size: clamp(1.8rem, 4vw, 2.5rem);
        margin-bottom: var(--spacing-sm);
    }

    .cta-content p {
        font-size: clamp(1rem, 2vw, 1.2rem);
        opacity: 0.9;
        margin-bottom: var(--spacing-lg);
    }

    .cta-buttons {
        display: flex;
        gap: var(--spacing-md);
        justify-content: center;
        flex-wrap: wrap;
    }

    .cta-buttons .btn {
        min-width: 200px;
    }


    /* ===== ANIMATIONS ===== */
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
        }
        to {
            opacity: 1;
        }
    }

    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes pulse {
        0% {
            box-shadow: 0 0 0 0 rgba(196, 30, 58, 0.7);
        }
        70% {
            box-shadow: 0 0 0 15px rgba(196, 30, 58, 0);
        }
        100% {
            box-shadow: 0 0 0 0 rgba(196, 30, 58, 0);
        }
    }

    @keyframes float {
        0%, 100% {
            transform: translateY(0);
        }
        50% {
            transform: translateY(-10px);
        }
    }

    @keyframes rotate {
        from {
            transform: rotate(0deg);
        }
        to {
            transform: rotate(360deg);
        }
    }

    /* ===== RESPONSIVE STYLES ===== */
    @media (max-width: 992px) {
        .hero-title {
            font-size: 2.5rem;
        }

        .welcome-grid {
            grid-template-columns: 1fr;
            gap: var(--spacing-lg);
        }

        .welcome-content {
            padding-right: 0;
        }

        .stats-grid,
        .stats-container {
            grid-template-columns: repeat(2, 1fr);
        }

        .experience-badge {
            width: 100px;
            height: 100px;
        }

        .experience-badge .years {
            font-size: 1.5rem;
        }

        .mvg-container {
            min-height: 450px;
        }
    }

    @media (max-width: 768px) {
        .hero-section {
            min-height: 80vh;
        }

        .hero-title {
            font-size: 2rem;
        }

        .hero-buttons {
            flex-direction: column;
            align-items: stretch;
        }

        .hero-buttons .btn {
            width: 100%;
        }

        .slider-controls {
            bottom: 120px;
        }

        .slider-prev,
        .slider-next {
            width: 40px;
            height: 40px;
        }

        .features-list {
            grid-template-columns: 1fr;
        }

        .programs-grid {
            grid-template-columns: 1fr;
        }

        .news-grid {
            grid-template-columns: 1fr;
        }

        .floating-card {
            display: none;
        }

        .experience-badge {
            width: 80px;
            height: 80px;
            bottom: 15px;
            right: 15px;
        }

        .mvg-container {
            min-height: 500px;
        }

        .mvg-slide {
            padding: var(--spacing-lg);
        }

        .mvg-slide h2 {
            font-size: 1.8rem;
        }

        .mvg-slide p {
            font-size: 1rem;
        }
    }

    @media (max-width: 576px) {
        .hero-title {
            font-size: 1.8rem;
        }

        .section-title {
            font-size: 1.8rem;
        }

        .stats-grid,
        .stats-container {
            grid-template-columns: 1fr;
        }

        .slider-controls {
            bottom: 100px;
            gap: var(--spacing-sm);
        }

        .slider-prev,
        .slider-next {
            width: 35px;
            height: 35px;
        }

        .dot {
            width: 8px;
            height: 8px;
        }

        .experience-badge {
            width: 60px;
            height: 60px;
        }

        .experience-badge .years {
            font-size: 1.2rem;
        }

        .experience-badge .text {
            font-size: 0.6rem;
        }

        .cta-buttons {
            flex-direction: column;
        }

        .cta-buttons .btn {
            width: 100%;
        }

        .mvg-container {
            min-height: 550px;
        }

        .mvg-slide h2 {
            font-size: 1.5rem;
        }

        .mvg-controls {
            gap: var(--spacing-md);
        }

        .mvg-prev,
        .mvg-next {
            width: 40px;
            height: 40px;
        }
    }
</style>

<!-- ===== HERO SECTION WITH SLIDER ===== -->
<section class="hero-section">
    <div class="hero-slider">
        <?php $heroSlides = cms_slides(); $gradients = [
            'linear-gradient(135deg, rgba(0,40,85,0.85) 0%, rgba(196,30,58,0.85) 100%)',
            'linear-gradient(135deg, rgba(196,30,58,0.85) 0%, rgba(255,215,0,0.85) 100%)',
            'linear-gradient(135deg, rgba(255,215,0,0.85) 0%, rgba(0,40,85,0.85) 100%)']; ?>
        <?php foreach ($heroSlides as $i => $sl):
            $bg = $gradients[(int)$sl['grad'] % 3];
            if (!empty($sl['image']) && is_file(UPLOAD_PATH . 'site/' . basename($sl['image']))) {
                $bg = 'linear-gradient(135deg, rgba(0,40,85,0.62) 0%, rgba(0,26,58,0.55) 100%), url(\'' . BASE_URL . '/uploads/site/' . rawurlencode(basename($sl['image'])) . '\') center/cover no-repeat';
            } ?>
        <div class="hero-slide<?php echo $i === 0 ? ' active' : ''; ?>" style="background: <?php echo e($bg); ?>;">
            <div class="container">
                <div class="hero-content">
                    <?php if ($sl['subtitle'] !== '' && $sl['subtitle'] !== null): ?><span class="hero-subtitle"><?php echo e($sl['subtitle']); ?></span><?php endif; ?>
                    <h1 class="hero-title"><?php echo preg_replace('/\*([^*]+)\*/', '<span>$1</span>', e($sl['title'])); ?></h1>
                    <?php if (!empty($sl['use_motto'])): ?>
                        <p class="hero-motto"><?php echo e(school_motto()); ?></p>
                        <p class="hero-translation"><?php echo cms_e('school.motto_translation'); ?></p>
                    <?php elseif (!empty($sl['text'])): ?>
                        <p class="hero-motto"><?php echo e($sl['text']); ?></p>
                    <?php endif; ?>
                    <div class="hero-buttons">
                        <?php if (!empty($sl['btn1_label'])): ?><a href="<?php echo e(cms_link($sl['btn1_url'])); ?>" class="btn btn-primary btn-large"><?php echo e($sl['btn1_label']); ?></a><?php endif; ?>
                        <?php if (!empty($sl['btn2_label'])): ?><a href="<?php echo e(cms_link($sl['btn2_url'])); ?>" class="btn btn-outline-light btn-large"><?php echo e($sl['btn2_label']); ?></a><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Slider Controls -->
    <div class="slider-controls">
        <button class="slider-prev"><i class="fas fa-chevron-left"></i></button>
        <div class="slider-dots">
            <?php foreach ($heroSlides as $di => $_): ?><span class="dot<?php echo $di === 0 ? ' active' : ''; ?>"></span><?php endforeach; ?>
        </div>
        <button class="slider-next"><i class="fas fa-chevron-right"></i></button>
    </div>

    <!-- Hero Stats -->
    <div class="hero-stats">
        <div class="container">
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-content">
                        <span class="stat-number"><?php echo e($stats['students']); ?>+</span>
                        <span class="stat-label">Happy Students</span>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                    <div class="stat-content">
                        <span class="stat-number"><?php echo e($stats['teachers']); ?></span>
                        <span class="stat-label">Expert Teachers</span>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon"><i class="fas fa-school"></i></div>
                    <div class="stat-content">
                        <span class="stat-number"><?php echo e($stats['classes']); ?></span>
                        <span class="stat-label">Classes</span>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon"><i class="fas fa-trophy"></i></div>
                    <div class="stat-content">
                        <span class="stat-number"><?php echo e($stats['years']); ?></span>
                        <span class="stat-label">Years of Excellence</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ===== WELCOME SECTION ===== -->
<section class="welcome-section">
    <div class="container">
        <div class="welcome-grid">
            <div class="welcome-content">
                <span class="section-tag"><?php echo cms_e('home.welcome_tag'); ?></span>
                <h2 class="section-title"><?php echo cms_hl('home.welcome_title'); ?></h2>
                <p class="welcome-text"><?php echo cms_e('home.welcome_text'); ?></p>

                <div class="features-list">
                    <div class="feature-item">
                        <div class="feature-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="feature-text">
                            <h4><?php echo cms_e('home.feat1_title'); ?></h4>
                            <p><?php echo cms_e('home.feat1_text'); ?></p>
                        </div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="feature-text">
                            <h4><?php echo cms_e('home.feat2_title'); ?></h4>
                            <p><?php echo cms_e('home.feat2_text'); ?></p>
                        </div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="feature-text">
                            <h4><?php echo cms_e('home.feat3_title'); ?></h4>
                            <p><?php echo cms_e('home.feat3_text'); ?></p>
                        </div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="feature-text">
                            <h4><?php echo cms_e('home.feat4_title'); ?></h4>
                            <p><?php echo cms_e('home.feat4_text'); ?></p>
                        </div>
                    </div>
                </div>

                <div class="welcome-cta">
                    <a href="<?php echo BASE_URL; ?>/public/about" class="btn btn-primary">
                        Discover More About Us <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>

            <div class="welcome-image">
                <div class="image-wrapper">
                    <img src="<?php echo e(cms_img('home.welcome_image', BASE_URL . '/assets/images/welcome-image.jpg')); ?>" alt="Students learning at St. Benedict's" onerror="this.onerror=null; this.src='<?php echo BASE_URL; ?>/assets/images/placeholder.jpg';">
                    <div class="experience-badge">
                        <span class="years"><?php echo e($stats['years']); ?>+</span>
                        <span class="text">Years of Excellence</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ===== QUICK STATS SECTION ===== -->
<section class="quick-stats">
    <div class="container">
        <div class="stats-container">
            <div class="stat-box">
                <i class="fas fa-users"></i>
                <div class="number"><?php echo e($stats['students']); ?>+</div>
                <div class="label">Students</div>
            </div>
            <div class="stat-box">
                <i class="fas fa-chalkboard-teacher"></i>
                <div class="number"><?php echo e($stats['teachers']); ?></div>
                <div class="label">Teachers</div>
            </div>
            <div class="stat-box">
                <i class="fas fa-school"></i>
                <div class="number"><?php echo e($stats['classes']); ?></div>
                <div class="label">Classes</div>
            </div>
            <div class="stat-box">
                <i class="fas fa-trophy"></i>
                <div class="number"><?php echo e($stats['years']); ?></div>
                <div class="label">Years</div>
            </div>
        </div>
    </div>
</section>

<!-- ===== MVG SLIDESHOW SECTION (MISSION, VISION, GOAL) ===== -->
<section class="mvg-slideshow">
    <div class="container">
        <div class="mvg-container">
            <!-- Mission Slide -->
            <div class="mvg-slide active" id="slide-mission">
                <div class="mvg-icon">
                    <i class="fas fa-cross"></i>
                </div>
                <h2>Our Mission</h2>
                <p><?php echo cms_e('home.mission'); ?></p>
            </div>

            <!-- Vision Slide -->
            <div class="mvg-slide" id="slide-vision">
                <div class="mvg-icon">
                    <i class="fas fa-eye"></i>
                </div>
                <h2>Our Vision</h2>
                <p><?php echo cms_e('home.vision'); ?></p>
            </div>

            <!-- Goal Slide -->
            <div class="mvg-slide" id="slide-goal">
                <div class="mvg-icon">
                    <i class="fas fa-bullseye"></i>
                </div>
                <h2>Our Goal</h2>
                <p><?php echo cms_e('home.goal'); ?></p>
            </div>
        </div>

        <!-- MVG Controls -->
        <div class="mvg-controls">
            <button class="mvg-prev" id="mvgPrev"><i class="fas fa-chevron-left"></i></button>
            <div class="mvg-dots">
                <span class="mvg-dot active" data-slide="0"></span>
                <span class="mvg-dot" data-slide="1"></span>
                <span class="mvg-dot" data-slide="2"></span>
            </div>
            <button class="mvg-next" id="mvgNext"><i class="fas fa-chevron-right"></i></button>
        </div>
    </div>
</section>

<!-- ===== PROGRAMS SECTION ===== -->
<section class="programs-section">
    <div class="container">
        <h2 class="section-title text-center"><?php echo cms_e('home.programs_title'); ?></h2>
        <p class="section-subtitle text-center"><?php echo cms_e('home.programs_subtitle'); ?></p>

        <div class="programs-grid">
            <div class="program-card">
                <div class="program-icon">
                    <i class="fas fa-baby"></i>
                </div>
                <h3><?php echo cms_e('home.prog1_title'); ?></h3>
                <p class="program-age"><?php echo cms_e('home.prog1_age'); ?></p>
                <p class="program-desc"><?php echo cms_e('home.prog1_text'); ?></p>
                <a href="<?php echo BASE_URL; ?>/public/academics" class="btn-link">Learn More <i class="fas fa-arrow-right"></i></a>
            </div>

            <div class="program-card">
                <div class="program-icon">
                    <i class="fas fa-child"></i>
                </div>
                <h3><?php echo cms_e('home.prog2_title'); ?></h3>
                <p class="program-age"><?php echo cms_e('home.prog2_age'); ?></p>
                <p class="program-desc"><?php echo cms_e('home.prog2_text'); ?></p>
                <a href="<?php echo BASE_URL; ?>/public/academics" class="btn-link">Learn More <i class="fas fa-arrow-right"></i></a>
            </div>

            <div class="program-card">
                <div class="program-icon">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <h3><?php echo cms_e('home.prog3_title'); ?></h3>
                <p class="program-age"><?php echo cms_e('home.prog3_age'); ?></p>
                <p class="program-desc"><?php echo cms_e('home.prog3_text'); ?></p>
                <a href="<?php echo BASE_URL; ?>/public/academics" class="btn-link">Learn More <i class="fas fa-arrow-right"></i></a>
            </div>

            <div class="program-card">
                <div class="program-icon">
                    <i class="fas fa-music"></i>
                </div>
                <h3><?php echo cms_e('home.prog4_title'); ?></h3>
                <p class="program-age"><?php echo cms_e('home.prog4_age'); ?></p>
                <p class="program-desc"><?php echo cms_e('home.prog4_text'); ?></p>
                <a href="<?php echo BASE_URL; ?>/public/academics" class="btn-link">Learn More <i class="fas fa-arrow-right"></i></a>
            </div>
        </div>
    </div>
</section>

<!-- ===== NEWS SECTION ===== -->
<section class="news-section">
    <div class="container">
        <h2 class="section-title text-center"><?php echo cms_e('home.news_title'); ?></h2>
        <p class="section-subtitle text-center"><?php echo cms_e('home.news_subtitle'); ?></p>

        <div class="news-grid">
            <?php if (!empty($news)): ?>
                <?php foreach ($news as $item): ?>
                <div class="news-card">
                    <div class="news-image">
                        <?php if (!empty($item['image_path']) && file_exists(__DIR__ . '/../uploads/news/' . $item['image_path'])): ?>
                        <img src="<?php echo BASE_URL; ?>/uploads/news/<?php echo e($item['image_path']); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>" onerror="this.onerror=null; this.src='<?php echo BASE_URL; ?>/assets/images/news-placeholder.jpg';">
                        <?php else: ?>
                        <img src="<?php echo BASE_URL; ?>/assets/images/news-placeholder.jpg" alt="News">
                        <?php endif; ?>
                        <div class="news-date">
                            <span class="day"><?php echo date('d', strtotime($item['created_at'])); ?></span>
                            <span class="month"><?php echo date('M', strtotime($item['created_at'])); ?></span>
                        </div>
                    </div>
                    <div class="news-content">
                        <h3><a href="<?php echo BASE_URL; ?>/public/news-detail?id=<?php echo e($item['id']); ?>"><?php echo htmlspecialchars($item['title']); ?></a></h3>
                        <p><?php echo htmlspecialchars(mb_substr($item['content'], 0, 120)); ?>...</p>
                        <div class="news-meta">
                            <span class="news-author"><i class="far fa-user"></i> Admin</span>
                            <a href="<?php echo BASE_URL; ?>/public/news-detail?id=<?php echo e($item['id']); ?>" class="read-more">Read More <i class="fas fa-arrow-right"></i></a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <!-- Fallback news items -->
                <div class="news-card">
                    <div class="news-image">
                        <img src="<?php echo BASE_URL; ?>/assets/images/news-1.jpg" alt="News" onerror="this.onerror=null; this.src='<?php echo BASE_URL; ?>/assets/images/news-placeholder.jpg';">
                        <div class="news-date">
                            <span class="day">15</span>
                            <span class="month">Jan</span>
                        </div>
                    </div>
                    <div class="news-content">
                        <h3><a href="#">New Academic Year Begins</a></h3>
                        <p>We welcome all students to the 2024-2025 academic year. May it be a year of growth and achievement...</p>
                        <div class="news-meta">
                            <span class="news-author"><i class="far fa-user"></i> Admin</span>
                            <a href="#" class="read-more">Read More <i class="fas fa-arrow-right"></i></a>
                        </div>
                    </div>
                </div>
                <div class="news-card">
                    <div class="news-image">
                        <img src="<?php echo BASE_URL; ?>/assets/images/news-2.jpg" alt="News" onerror="this.onerror=null; this.src='<?php echo BASE_URL; ?>/assets/images/news-placeholder.jpg';">
                        <div class="news-date">
                            <span class="day">10</span>
                            <span class="month">Jan</span>
                        </div>
                    </div>
                    <div class="news-content">
                        <h3><a href="#">Open Day Announcement</a></h3>
                        <p>Join us for our Annual Open Day on January 20th. Meet our teachers and tour our facilities...</p>
                        <div class="news-meta">
                            <span class="news-author"><i class="far fa-user"></i> Admin</span>
                            <a href="#" class="read-more">Read More <i class="fas fa-arrow-right"></i></a>
                        </div>
                    </div>
                </div>
                <div class="news-card">
                    <div class="news-image">
                        <img src="<?php echo BASE_URL; ?>/assets/images/news-3.jpg" alt="News" onerror="this.onerror=null; this.src='<?php echo BASE_URL; ?>/assets/images/news-placeholder.jpg';">
                        <div class="news-date">
                            <span class="day">05</span>
                            <span class="month">Jan</span>
                        </div>
                    </div>
                    <div class="news-content">
                        <h3><a href="#">Sports Day Success</a></h3>
                        <p>Our annual Sports Day was a huge success with all children participating in various fun activities...</p>
                        <div class="news-meta">
                            <span class="news-author"><i class="far fa-user"></i> Admin</span>
                            <a href="#" class="read-more">Read More <i class="fas fa-arrow-right"></i></a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="text-center mt-4">
            <a href="<?php echo BASE_URL; ?>/public/news" class="btn btn-primary">View All News</a>
        </div>
    </div>
</section>

<!-- ===== CTA SECTION ===== -->
<section class="cta-section">
    <div class="container">
        <div class="cta-content">
            <h2><?php echo cms_e('home.cta_title'); ?></h2>
            <p><?php echo cms_e('home.cta_text'); ?></p>
            <div class="cta-buttons">
                <a href="<?php echo BASE_URL; ?>/public/apply" class="btn btn-primary btn-large">
                    <i class="fas fa-graduation-cap"></i> Apply Now
                </a>
                <a href="<?php echo BASE_URL; ?>/public/contact" class="btn btn-outline-light btn-large">
                    <i class="fas fa-calendar-alt"></i> Request Information
                </a>
            </div>
        </div>
    </div>
</section>


<!-- Add a placeholder image if welcome-image.jpg doesn't exist -->
<?php
// Check if welcome-image.jpg exists, if not create a simple instruction
if (!file_exists(__DIR__ . '/assets/images/welcome-image.jpg')) {
    echo '<!-- Note: welcome-image.jpg not found in assets/images/ -->';
}
?>

<!-- MVG Slideshow JavaScript -->
<script nonce="<?php echo CSP_NONCE; ?>">
document.addEventListener('DOMContentLoaded', function() {
    // MVG Slideshow
    const mvgSlides = document.querySelectorAll('.mvg-slide');
    const mvgDots = document.querySelectorAll('.mvg-dot');
    const mvgPrev = document.getElementById('mvgPrev');
    const mvgNext = document.getElementById('mvgNext');
    let mvgCurrentSlide = 0;
    let mvgInterval = setInterval(mvgNextSlide, 5000);

    function mvgShowSlide(index) {
        mvgSlides.forEach(slide => slide.classList.remove('active'));
        mvgDots.forEach(dot => dot.classList.remove('active'));

        mvgSlides[index].classList.add('active');
        mvgDots[index].classList.add('active');
        mvgCurrentSlide = index;
    }

    function mvgNextSlide() {
        let next = (mvgCurrentSlide + 1) % mvgSlides.length;
        mvgShowSlide(next);
    }

    function mvgPrevSlide() {
        let prev = (mvgCurrentSlide - 1 + mvgSlides.length) % mvgSlides.length;
        mvgShowSlide(prev);
    }

    if (mvgPrev) {
        mvgPrev.addEventListener('click', function() {
            clearInterval(mvgInterval);
            mvgPrevSlide();
            mvgInterval = setInterval(mvgNextSlide, 5000);
        });
    }

    if (mvgNext) {
        mvgNext.addEventListener('click', function() {
            clearInterval(mvgInterval);
            mvgNextSlide();
            mvgInterval = setInterval(mvgNextSlide, 5000);
        });
    }

    mvgDots.forEach((dot, index) => {
        dot.addEventListener('click', function() {
            clearInterval(mvgInterval);
            mvgShowSlide(index);
            mvgInterval = setInterval(mvgNextSlide, 5000);
        });
    });

    // Hero Slider
    const slides = document.querySelectorAll('.hero-slide');
    const dots = document.querySelectorAll('.dot');
    const prevBtn = document.querySelector('.slider-prev');
    const nextBtn = document.querySelector('.slider-next');

    if (!slides.length) return;

    let currentSlide = 0;
    let slideInterval = setInterval(nextSlide, 5000);

    function showSlide(index) {
        slides.forEach(slide => slide.classList.remove('active'));
        dots.forEach(dot => dot.classList.remove('active'));

        slides[index].classList.add('active');
        dots[index].classList.add('active');
        currentSlide = index;
    }

    function nextSlide() {
        let next = (currentSlide + 1) % slides.length;
        showSlide(next);
    }

    function prevSlide() {
        let prev = (currentSlide - 1 + slides.length) % slides.length;
        showSlide(prev);
    }

    if (prevBtn) {
        prevBtn.addEventListener('click', function() {
            clearInterval(slideInterval);
            prevSlide();
            slideInterval = setInterval(nextSlide, 5000);
        });
    }

    if (nextBtn) {
        nextBtn.addEventListener('click', function() {
            clearInterval(slideInterval);
            nextSlide();
            slideInterval = setInterval(nextSlide, 5000);
        });
    }

    dots.forEach((dot, index) => {
        dot.addEventListener('click', function() {
            clearInterval(slideInterval);
            showSlide(index);
            slideInterval = setInterval(nextSlide, 5000);
        });
    });

});
</script>

<?php
// Include footer
include 'includes/footer.php';
?>