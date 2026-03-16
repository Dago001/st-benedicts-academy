<?php
// student/messages.php - View School Announcements/Messages
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require student role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'student') {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$pageTitle = 'Messages & Announcements';
$extraCSS = ['dashboard.css'];
$extraJS = ['messages.js'];

// Check if header exists
$headerPath = __DIR__ . '/../includes/header.php';
if (file_exists($headerPath)) {
    include $headerPath;
} else {
    // Fallback header if file doesn't exist
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo $pageTitle; ?> - School Management System</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <link rel="stylesheet" href="../assets/css/style.css">
        <link rel="stylesheet" href="../assets/css/dashboard.css">
    </head>
    <body>
    <?php
}

// Get database instance
try {
    $db = Database::getInstance();
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Database connection error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}

$userId = $_SESSION['user_id'];

// Get student info
$student = $db->getRow(
    "SELECT s.*, u.first_name, u.last_name, u.email, u.profile_image,
            c.class_name, c.section
     FROM students s 
     JOIN users u ON s.user_id = u.id 
     LEFT JOIN classes c ON s.class_id = c.id 
     WHERE s.user_id = ?",
    [$userId]
);

if (!$student) {
    echo '<div class="alert alert-danger">Student record not found.</div>';
    exit;
}

// Initialize read announcements in session if not exists
if (!isset($_SESSION['read_announcements'])) {
    $_SESSION['read_announcements'] = [];
}

// Handle mark as read
if (isset($_GET['mark_read']) && isset($_GET['id'])) {
    $announcementId = (int)$_GET['id'];
    if (!in_array($announcementId, $_SESSION['read_announcements'])) {
        $_SESSION['read_announcements'][] = $announcementId;
    }
    
    // Redirect to remove query parameters
    $redirectUrl = 'messages.php';
    if (isset($_GET['view'])) {
        $redirectUrl .= '?view=' . urlencode($_GET['view']);
    }
    header('Location: ' . $redirectUrl);
    exit;
}

// Handle mark all as read
if (isset($_GET['mark_all_read'])) {
    // Get all announcements for this student
    $allAnnouncements = $db->getRows(
        "SELECT id FROM announcements 
         WHERE (audience = 'all' OR audience = 'students' OR audience = ?)
           AND (expires_at IS NULL OR expires_at >= CURDATE())
           AND is_published = 1",
        [$student['class_name'] ?? '']
    );
    
    foreach ($allAnnouncements as $ann) {
        if (!in_array($ann['id'], $_SESSION['read_announcements'])) {
            $_SESSION['read_announcements'][] = $ann['id'];
        }
    }
    
    header('Location: messages.php');
    exit;
}

// Get filter parameters
$view = isset($_GET['view']) ? $_GET['view'] : 'all';
$priority = isset($_GET['priority']) ? $_GET['priority'] : 'all';
$search = isset($_GET['search']) ? Security::sanitize($_GET['search']) : '';

// Build query conditions
$conditions = ["(a.audience = 'all' OR a.audience = 'students' OR a.audience = ?)"];
$params = [$student['class_name'] ?? ''];

// Add published condition
$conditions[] = "a.is_published = 1";

// Add expiry condition
$conditions[] = "(a.expires_at IS NULL OR a.expires_at >= CURDATE())";

// Add priority filter
if ($priority !== 'all') {
    $conditions[] = "a.priority = ?";
    $params[] = $priority;
}

// Add search filter
if (!empty($search)) {
    $conditions[] = "(a.title LIKE ? OR a.content LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Build WHERE clause
$whereClause = implode(" AND ", $conditions);

// Get announcements
$sql = "SELECT a.*, u.first_name, u.last_name 
        FROM announcements a
        JOIN users u ON a.created_by = u.id
        WHERE $whereClause
        ORDER BY 
            CASE a.priority 
                WHEN 'urgent' THEN 1
                WHEN 'high' THEN 2
                WHEN 'normal' THEN 3
                WHEN 'low' THEN 4
            END,
            a.created_at DESC";

$announcements = $db->getRows($sql, $params);

// Add read status to each announcement
foreach ($announcements as &$ann) {
    $ann['is_read'] = in_array($ann['id'], $_SESSION['read_announcements']);
}

// Count unread
$unreadCount = 0;
foreach ($announcements as $ann) {
    if (!$ann['is_read']) {
        $unreadCount++;
    }
}

// Get counts by priority
$priorityCounts = [
    'urgent' => 0,
    'high' => 0,
    'normal' => 0,
    'low' => 0
];

foreach ($announcements as $ann) {
    if (isset($priorityCounts[$ann['priority']])) {
        $priorityCounts[$ann['priority']]++;
    }
}

// Filter by view type
$filteredAnnouncements = $announcements;
if ($view === 'unread') {
    $filteredAnnouncements = array_filter($announcements, function($ann) {
        return !$ann['is_read'];
    });
} elseif ($view === 'archived') {
    $filteredAnnouncements = array_filter($announcements, function($ann) {
        return strtotime($ann['created_at']) < strtotime('-30 days');
    });
}

// Get featured announcement (latest urgent/high priority unread)
$featuredAnnouncement = null;
foreach ($announcements as $ann) {
    if (in_array($ann['priority'], ['urgent', 'high']) && !$ann['is_read']) {
        $featuredAnnouncement = $ann;
        break;
    }
}

// Define upload path for attachments
define('ANNOUNCEMENT_UPLOAD_PATH', BASE_URL . '/uploads/announcements/');
?>

<style>
/* Messages page specific styles */
:root {
    --navy: #002855;
    --navy-light: #1a3a6e;
    --gold: #ffd700;
    --gold-light: #ffe44d;
    --success: #28a745;
    --warning: #ffc107;
    --danger: #dc3545;
    --info: #17a2b8;
    --light: #f8f9fa;
    --dark: #343a40;
    --gray: #6c757d;
    --gray-light: #e9ecef;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    display: flex;
    align-items: center;
    gap: 15px;
    transition: all 0.3s ease;
    border-bottom: 3px solid transparent;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
    border-bottom-color: var(--gold);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    flex-shrink: 0;
}

.stat-content {
    flex: 1;
}

.stat-content h3 {
    font-size: 28px;
    font-weight: 700;
    margin: 0;
    color: var(--navy);
    line-height: 1.2;
}

.stat-content p {
    margin: 5px 0 0;
    color: var(--gray);
    font-size: 14px;
}

/* User info */
.user-info {
    display: flex;
    align-items: center;
    gap: 15px;
    background: white;
    padding: 10px 20px;
    border-radius: 50px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.user-info i {
    font-size: 2rem;
    color: var(--navy);
}

.user-info span {
    font-weight: 600;
    color: var(--navy);
}

.user-info small {
    color: var(--gray);
    font-size: 0.8rem;
    display: block;
}

/* Dashboard header */
.dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    flex-wrap: wrap;
    gap: 15px;
}

.dashboard-header h1 {
    margin: 0;
    font-size: 28px;
    color: var(--navy);
}

/* Header actions */
.header-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.btn {
    padding: 8px 16px;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    transition: all 0.3s ease;
}

.btn-primary {
    background: var(--navy);
    color: white;
}

.btn-primary:hover {
    background: var(--navy-light);
}

.btn-outline {
    background: white;
    border: 1px solid #ddd;
    color: #333;
}

.btn-outline:hover {
    background: var(--light);
    border-color: var(--navy);
}

.btn-success {
    background: var(--success);
    color: white;
}

.btn-success:hover {
    background: #218838;
}

.btn-sm {
    padding: 5px 10px;
    font-size: 12px;
}

/* Filter bar */
.filter-bar {
    background: white;
    border-radius: 10px;
    padding: 15px 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    align-items: center;
    justify-content: space-between;
}

.filter-group {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}

.filter-select {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
    min-width: 150px;
}

.filter-select:focus {
    outline: none;
    border-color: var(--gold);
    box-shadow: 0 0 0 2px rgba(255, 215, 0, 0.2);
}

.search-box {
    display: flex;
    gap: 5px;
}

.search-box input {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px 0 0 4px;
    font-size: 14px;
    width: 250px;
}

.search-box input:focus {
    outline: none;
    border-color: var(--gold);
}

.search-box button {
    padding: 8px 12px;
    background: var(--navy);
    color: white;
    border: none;
    border-radius: 0 4px 4px 0;
    cursor: pointer;
}

.search-box button:hover {
    background: var(--navy-light);
}

/* View tabs */
.view-tabs {
    display: flex;
    gap: 5px;
    background: white;
    padding: 5px;
    border-radius: 8px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
}

.view-tab {
    padding: 8px 16px;
    border-radius: 6px;
    text-decoration: none;
    color: var(--gray);
    font-weight: 500;
    transition: all 0.3s ease;
}

.view-tab:hover {
    background: var(--light);
    color: var(--navy);
}

.view-tab.active {
    background: var(--navy);
    color: white;
}

.view-tab .badge {
    background: var(--gold);
    color: var(--navy);
    margin-left: 5px;
    padding: 2px 6px;
    border-radius: 10px;
    font-size: 11px;
}

/* Featured announcement */
.featured-announcement {
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-light) 100%);
    color: white;
    border-radius: 10px;
    padding: 25px;
    margin-bottom: 30px;
    box-shadow: 0 10px 30px rgba(0,40,85,0.3);
    position: relative;
    overflow: hidden;
}

.featured-announcement::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 100%;
    height: 200%;
    background: radial-gradient(circle, rgba(255,215,0,0.2) 0%, transparent 70%);
    transform: rotate(45deg);
}

.featured-badge {
    display: inline-block;
    background: var(--gold);
    color: var(--navy);
    padding: 5px 15px;
    border-radius: 50px;
    font-weight: 600;
    font-size: 0.85rem;
    margin-bottom: 15px;
    position: relative;
}

.featured-announcement h2 {
    font-size: 1.8rem;
    margin-bottom: 10px;
    position: relative;
}

.featured-announcement .meta {
    display: flex;
    gap: 20px;
    margin-bottom: 15px;
    opacity: 0.9;
    font-size: 0.9rem;
    position: relative;
    flex-wrap: wrap;
}

.featured-announcement .content {
    margin-bottom: 20px;
    line-height: 1.6;
    position: relative;
}

/* Announcement cards */
.announcements-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.announcement-card {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    overflow: hidden;
    transition: all 0.3s ease;
    border-left: 4px solid transparent;
    position: relative;
}

.announcement-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.1);
}

.announcement-card.priority-urgent {
    border-left-color: var(--danger);
}

.announcement-card.priority-high {
    border-left-color: var(--warning);
}

.announcement-card.priority-normal {
    border-left-color: var(--navy);
}

.announcement-card.priority-low {
    border-left-color: var(--gray);
}

.announcement-card.unread {
    background: linear-gradient(to right, rgba(255,215,0,0.05), white);
}

.unread-indicator {
    position: absolute;
    top: 15px;
    right: 15px;
    width: 10px;
    height: 10px;
    background: var(--gold);
    border-radius: 50%;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% {
        box-shadow: 0 0 0 0 rgba(255, 215, 0, 0.7);
    }
    70% {
        box-shadow: 0 0 0 10px rgba(255, 215, 0, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(255, 215, 0, 0);
    }
}

.card-header {
    padding: 15px 20px;
    border-bottom: 1px solid var(--gray-light);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.priority-badge {
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}

.priority-urgent .priority-badge {
    background: rgba(220, 53, 69, 0.1);
    color: var(--danger);
}

.priority-high .priority-badge {
    background: rgba(255, 193, 7, 0.1);
    color: #856404;
}

.priority-normal .priority-badge {
    background: rgba(0, 40, 85, 0.1);
    color: var(--navy);
}

.priority-low .priority-badge {
    background: rgba(108, 117, 125, 0.1);
    color: var(--gray);
}

.audience-badge {
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.7rem;
    background: var(--gray-light);
    color: var(--gray);
}

.card-body {
    padding: 20px;
}

.card-body h3 {
    margin: 0 0 10px;
    font-size: 1.2rem;
    color: var(--navy);
    display: flex;
    align-items: center;
    gap: 8px;
}

.card-body h3 i {
    color: var(--gold);
    font-size: 1rem;
}

.card-body .meta {
    display: flex;
    gap: 15px;
    margin-bottom: 15px;
    font-size: 0.8rem;
    color: var(--gray);
    flex-wrap: wrap;
}

.card-body .meta i {
    margin-right: 3px;
}

.card-body .content-preview {
    color: var(--dark);
    line-height: 1.5;
    margin-bottom: 15px;
    max-height: 80px;
    overflow: hidden;
    position: relative;
}

.card-body .content-preview::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 100%;
    height: 30px;
    background: linear-gradient(to bottom, transparent, white);
}

.attachment-info {
    margin-bottom: 15px;
    font-size: 0.85rem;
}

.attachment-info a {
    color: var(--navy);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.attachment-info a:hover {
    color: var(--gold);
}

.card-footer {
    padding: 15px 20px;
    border-top: 1px solid var(--gray-light);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}

.btn-read {
    background: none;
    border: 1px solid var(--navy);
    color: var(--navy);
    padding: 6px 12px;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    font-size: 0.85rem;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.btn-read:hover {
    background: var(--navy);
    color: white;
}

.btn-read i {
    margin-right: 5px;
}

.btn-read.marked {
    background: var(--success);
    border-color: var(--success);
    color: white;
}

.date-info {
    font-size: 0.8rem;
    color: var(--gray);
}

/* Modal styles */
.modal {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0,0,0,0.5);
}

.modal-content {
    background-color: #fefefe;
    margin: 5% auto;
    padding: 0;
    border: 1px solid #888;
    width: 90%;
    max-width: 800px;
    border-radius: 10px;
    box-shadow: 0 5px 30px rgba(0,0,0,0.3);
    position: relative;
    z-index: 10000;
}

.modal-header {
    padding: 15px 20px;
    border-bottom: 1px solid #dee2e6;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(135deg, var(--navy), var(--navy-light));
    color: white;
    border-radius: 10px 10px 0 0;
}

.modal-header h2 {
    margin: 0;
    color: white;
    font-size: 1.5rem;
}

.modal-header .close {
    background: none;
    border: none;
    color: white;
    font-size: 28px;
    cursor: pointer;
    opacity: 0.8;
}

.modal-header .close:hover {
    opacity: 1;
}

.modal-body {
    padding: 30px;
    max-height: 70vh;
    overflow-y: auto;
}

.modal-body .meta {
    display: flex;
    gap: 20px;
    margin: 20px 0;
    padding: 15px;
    background: var(--light);
    border-radius: 8px;
    color: var(--gray);
    font-size: 0.9rem;
    flex-wrap: wrap;
}

.modal-body .meta i {
    margin-right: 5px;
    color: var(--gold);
}

.modal-body .content {
    line-height: 1.8;
    color: var(--dark);
}

.modal-body .attachment-section {
    margin-top: 30px;
    padding: 20px;
    background: var(--light);
    border-radius: 8px;
}

.modal-body .attachment-section h4 {
    color: var(--navy);
    margin-bottom: 10px;
}

.modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #dee2e6;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

/* Empty state */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.empty-state i {
    font-size: 4rem;
    color: var(--gray-light);
    margin-bottom: 20px;
}

.empty-state h3 {
    color: var(--navy);
    margin-bottom: 10px;
}

.empty-state p {
    color: var(--gray);
}

/* Responsive */
@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .filter-bar {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-group {
        flex-direction: column;
        width: 100%;
    }
    
    .filter-select {
        width: 100%;
    }
    
    .search-box {
        width: 100%;
    }
    
    .search-box input {
        width: 100%;
    }
    
    .announcements-grid {
        grid-template-columns: 1fr;
    }
    
    .dashboard-header {
        flex-direction: column;
        text-align: center;
    }
    
    .header-actions {
        justify-content: center;
    }
    
    .view-tabs {
        width: 100%;
        justify-content: center;
    }
    
    .card-footer {
        flex-direction: column;
        align-items: stretch;
    }
    
    .btn-read {
        text-align: center;
        justify-content: center;
    }
}
</style>

<div class="dashboard-container">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <h3>Student Panel</h3>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="results.php"><i class="fas fa-chart-line"></i> My Results</a></li>
                <li><a href="attendance.php"><i class="fas fa-calendar-check"></i> Attendance</a></li>
                <li><a href="assignments.php"><i class="fas fa-tasks"></i> Assignments</a></li>
                <li><a href="fees.php"><i class="fas fa-money-bill"></i> Fees</a></li>
                <li class="active"><a href="messages.php"><i class="fas fa-envelope"></i> Messages</a></li>
                <li><a href="profile.php"><i class="fas fa-user-cog"></i> Profile</a></li>
            </ul>
        </nav>
    </aside>
    
    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>Messages & Announcements</h1>
            <div class="user-info">
                <i class="fas fa-user-graduate"></i>
                <div>
                    <span><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></span>
                    <small><?php echo htmlspecialchars(($student['class_name'] ?? 'No Class') . ' ' . ($student['section'] ?? '')); ?></small>
                </div>
            </div>
        </div>
        
        <!-- Summary Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(0,40,85,0.1);">
                    <i class="fas fa-bullhorn" style="color: #002855;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo count($announcements); ?></h3>
                    <p>Total Messages</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(255,215,0,0.1);">
                    <i class="fas fa-envelope" style="color: #ffd700;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo $unreadCount; ?></h3>
                    <p>Unread</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(220,53,69,0.1);">
                    <i class="fas fa-exclamation-circle" style="color: #dc3545;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo $priorityCounts['urgent']; ?></h3>
                    <p>Urgent</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(23,162,184,0.1);">
                    <i class="fas fa-paperclip" style="color: #17a2b8;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php 
                        $withAttachments = array_filter($announcements, function($ann) {
                            return !empty($ann['attachment']);
                        });
                        echo count($withAttachments);
                    ?></h3>
                    <p>With Attachments</p>
                </div>
            </div>
        </div>
        
        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="filter-group">
                <div class="view-tabs">
                    <a href="?view=all<?php echo $priority !== 'all' ? '&priority=' . urlencode($priority) : ''; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="view-tab <?php echo $view === 'all' ? 'active' : ''; ?>">
                        All <span class="badge"><?php echo count($announcements); ?></span>
                    </a>
                    <a href="?view=unread<?php echo $priority !== 'all' ? '&priority=' . urlencode($priority) : ''; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="view-tab <?php echo $view === 'unread' ? 'active' : ''; ?>">
                        Unread <span class="badge"><?php echo $unreadCount; ?></span>
                    </a>
                    <a href="?view=archived<?php echo $priority !== 'all' ? '&priority=' . urlencode($priority) : ''; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="view-tab <?php echo $view === 'archived' ? 'active' : ''; ?>">
                        Archived
                    </a>
                </div>
            </div>
            
            <div class="filter-group">
                <select class="filter-select" onchange="window.location.href = '?priority=' + this.value + '&view=<?php echo urlencode($view); ?>&search=<?php echo urlencode($search); ?>'">
                    <option value="all" <?php echo $priority === 'all' ? 'selected' : ''; ?>>All Priorities</option>
                    <option value="urgent" <?php echo $priority === 'urgent' ? 'selected' : ''; ?>>Urgent (<?php echo $priorityCounts['urgent']; ?>)</option>
                    <option value="high" <?php echo $priority === 'high' ? 'selected' : ''; ?>>High (<?php echo $priorityCounts['high']; ?>)</option>
                    <option value="normal" <?php echo $priority === 'normal' ? 'selected' : ''; ?>>Normal (<?php echo $priorityCounts['normal']; ?>)</option>
                    <option value="low" <?php echo $priority === 'low' ? 'selected' : ''; ?>>Low (<?php echo $priorityCounts['low']; ?>)</option>
                </select>
                
                <form method="GET" class="search-box" id="searchForm">
                    <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
                    <input type="hidden" name="priority" value="<?php echo htmlspecialchars($priority); ?>">
                    <input type="text" name="search" placeholder="Search messages..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit"><i class="fas fa-search"></i></button>
                </form>
                
                <?php if ($unreadCount > 0): ?>
                <a href="?mark_all_read=1" class="btn btn-success btn-sm">
                    <i class="fas fa-check-double"></i> Mark All Read
                </a>
                <?php endif; ?>
                
                <?php if (!empty($search) || $view !== 'all' || $priority !== 'all'): ?>
                <a href="messages.php" class="btn btn-outline btn-sm">
                    <i class="fas fa-times"></i> Clear Filters
                </a>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Featured Announcement (if any) -->
        <?php if ($featuredAnnouncement && $view === 'all' && empty($search)): ?>
        <div class="featured-announcement">
            <span class="featured-badge">
                <i class="fas fa-star"></i> 
                <?php echo strtoupper($featuredAnnouncement['priority']); ?> PRIORITY
            </span>
            <h2><?php echo htmlspecialchars($featuredAnnouncement['title']); ?></h2>
            <div class="meta">
                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($featuredAnnouncement['first_name'] . ' ' . $featuredAnnouncement['last_name']); ?></span>
                <span><i class="fas fa-calendar"></i> <?php echo date('F j, Y', strtotime($featuredAnnouncement['created_at'])); ?></span>
                <span><i class="fas fa-users"></i> For: <?php echo ucfirst($featuredAnnouncement['audience']); ?></span>
            </div>
            <div class="content">
                <?php 
                // Strip tags for preview but keep basic formatting
                $content = strip_tags($featuredAnnouncement['content']);
                echo nl2br(htmlspecialchars(substr($content, 0, 300) . (strlen($content) > 300 ? '...' : '')));
                ?>
            </div>
            <a href="#" onclick="viewAnnouncement(<?php echo $featuredAnnouncement['id']; ?>); return false;" class="btn btn-outline" style="background: white; color: var(--navy); border: none; display: inline-block; margin-top: 15px;">
                <i class="fas fa-eye"></i> Read Full Announcement
            </a>
        </div>
        <?php endif; ?>
        
        <!-- Announcements Grid -->
        <?php if (!empty($filteredAnnouncements)): ?>
        <div class="announcements-grid">
            <?php foreach ($filteredAnnouncements as $announcement): 
                $isUnread = !$announcement['is_read'];
                $previewContent = strip_tags($announcement['content']);
                $previewContent = substr($previewContent, 0, 150) . (strlen($previewContent) > 150 ? '...' : '');
            ?>
            <div class="announcement-card priority-<?php echo $announcement['priority']; ?> <?php echo $isUnread ? 'unread' : ''; ?>" id="announcement-<?php echo $announcement['id']; ?>">
                <?php if ($isUnread): ?>
                <div class="unread-indicator"></div>
                <?php endif; ?>
                
                <div class="card-header">
                    <span class="priority-badge">
                        <i class="fas <?php 
                            echo $announcement['priority'] === 'urgent' ? 'fa-exclamation-circle' : 
                                ($announcement['priority'] === 'high' ? 'fa-arrow-up' : 
                                ($announcement['priority'] === 'normal' ? 'fa-minus' : 'fa-arrow-down')); 
                        ?>"></i>
                        <?php echo ucfirst($announcement['priority']); ?>
                    </span>
                    <span class="audience-badge">
                        <i class="fas fa-users"></i> <?php echo ucfirst($announcement['audience']); ?>
                    </span>
                </div>
                
                <div class="card-body">
                    <h3>
                        <i class="fas fa-bullhorn"></i>
                        <?php echo htmlspecialchars($announcement['title']); ?>
                    </h3>
                    
                    <div class="meta">
                        <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($announcement['first_name']); ?></span>
                        <span><i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($announcement['created_at'])); ?></span>
                        <?php if (!empty($announcement['expires_at'])): ?>
                        <span><i class="fas fa-hourglass-end"></i> <?php echo date('M d, Y', strtotime($announcement['expires_at'])); ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="content-preview">
                        <?php echo nl2br(htmlspecialchars($previewContent)); ?>
                    </div>
                    
                    <?php if (!empty($announcement['attachment'])): ?>
                    <div class="attachment-info">
                        <a href="<?php echo ANNOUNCEMENT_UPLOAD_PATH . urlencode($announcement['attachment']); ?>" target="_blank">
                            <i class="fas fa-paperclip"></i> View Attachment
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="card-footer">
                    <?php if ($isUnread): ?>
                    <a href="?mark_read=1&id=<?php echo $announcement['id']; ?>&view=<?php echo urlencode($view); ?>" class="btn-read">
                        <i class="fas fa-check"></i> Mark as Read
                    </a>
                    <?php else: ?>
                    <span class="btn-read marked">
                        <i class="fas fa-check-circle"></i> Read
                    </span>
                    <?php endif; ?>
                    
                    <a href="#" onclick="viewAnnouncement(<?php echo $announcement['id']; ?>); return false;" class="btn-read">
                        <i class="fas fa-eye"></i> Read More
                    </a>
                    
                    <span class="date-info">
                        <i class="far fa-clock"></i> <?php echo date('h:i A', strtotime($announcement['created_at'])); ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <?php else: ?>
        <!-- Empty State -->
        <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <h3>No Messages Found</h3>
            <p>
                <?php if (!empty($search)): ?>
                    No messages match your search criteria "<strong><?php echo htmlspecialchars($search); ?></strong>". Try different keywords.
                <?php elseif ($view === 'unread'): ?>
                    You've read all your messages! Check back later for new announcements.
                <?php elseif ($view === 'archived'): ?>
                    No archived messages found.
                <?php else: ?>
                    There are no announcements at this time. Check back later!
                <?php endif; ?>
            </p>
            <?php if (!empty($search) || $view !== 'all' || $priority !== 'all'): ?>
            <a href="messages.php" class="btn btn-primary" style="margin-top: 20px;">
                <i class="fas fa-home"></i> View All Messages
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </main>
</div>

<!-- View Message Modal -->
<div id="messageModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle"></h2>
            <button type="button" class="close" onclick="closeMessageModal()">&times;</button>
        </div>
        <div class="modal-body" id="modalBody">
            <!-- Content will be loaded here -->
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeMessageModal()">Close</button>
        </div>
    </div>
</div>

<script>
// Store announcements data for modal viewing
const announcements = <?php echo json_encode(array_values($announcements)); ?>;
const uploadPath = '<?php echo ANNOUNCEMENT_UPLOAD_PATH; ?>';

function viewAnnouncement(id) {
    const announcement = announcements.find(a => a.id == id);
    if (!announcement) return;
    
    // Mark as read if unread (via AJAX)
    if (!announcement.is_read) {
        fetch(`messages.php?mark_read=1&id=${id}`, { 
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(() => {
            // Update UI to show as read
            const card = document.getElementById(`announcement-${id}`);
            if (card) {
                card.classList.remove('unread');
                const indicator = card.querySelector('.unread-indicator');
                if (indicator) indicator.remove();
                
                const markReadBtn = card.querySelector('.btn-read[href*="mark_read"]');
                if (markReadBtn) {
                    const span = document.createElement('span');
                    span.className = 'btn-read marked';
                    span.innerHTML = '<i class="fas fa-check-circle"></i> Read';
                    markReadBtn.parentNode.replaceChild(span, markReadBtn);
                }
            }
            
            // Reload page after a delay to update counts
            setTimeout(() => location.reload(), 1000);
        });
    }
    
    document.getElementById('modalTitle').innerHTML = announcement.title;
    
    // Format date
    const createdDate = new Date(announcement.created_at);
    const formattedDate = createdDate.toLocaleDateString('en-US', { 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
    
    // Get priority color
    let priorityColor = '#6c757d';
    let priorityBg = 'rgba(108,117,125,0.1)';
    
    if (announcement.priority === 'urgent') {
        priorityColor = '#dc3545';
        priorityBg = 'rgba(220,53,69,0.1)';
    } else if (announcement.priority === 'high') {
        priorityColor = '#856404';
        priorityBg = 'rgba(255,193,7,0.1)';
    } else if (announcement.priority === 'normal') {
        priorityColor = '#002855';
        priorityBg = 'rgba(0,40,85,0.1)';
    }
    
    // Format content
    let content = `
        <div class="meta">
            <span><i class="fas fa-user"></i> Posted by: ${announcement.first_name} ${announcement.last_name}</span>
            <span><i class="fas fa-calendar"></i> Date: ${formattedDate}</span>
            <span><i class="fas fa-tag"></i> Priority: <span class="priority-badge" style="background: ${priorityBg}; color: ${priorityColor}">${announcement.priority.toUpperCase()}</span></span>
            <span><i class="fas fa-users"></i> Audience: ${announcement.audience}</span>
        </div>
        
        <div class="content">
            ${announcement.content}
        </div>
    `;
    
    // Add attachment if exists
    if (announcement.attachment) {
        content += `
            <div class="attachment-section">
                <h4><i class="fas fa-paperclip"></i> Attachments</h4>
                <p>
                    <a href="${uploadPath}${encodeURIComponent(announcement.attachment)}" target="_blank" class="btn btn-outline">
                        <i class="fas fa-download"></i> Download Attachment
                    </a>
                </p>
            </div>
        `;
    }
    
    // Add expiry info if exists
    if (announcement.expires_at) {
        const expiryDate = new Date(announcement.expires_at);
        const today = new Date();
        const isExpired = expiryDate < today;
        
        content += `
            <div class="attachment-section" style="background: ${isExpired ? 'rgba(220,53,69,0.1)' : 'rgba(40,167,69,0.1)'};">
                <h4><i class="fas fa-clock"></i> Expiry Information</h4>
                <p>
                    <strong>${isExpired ? 'Expired on:' : 'Expires on:'}</strong> 
                    ${expiryDate.toLocaleDateString('en-US', { 
                        year: 'numeric', 
                        month: 'long', 
                        day: 'numeric' 
                    })}
                    ${isExpired ? ' <span class="badge" style="background: #dc3545; color: white; padding: 3px 8px; border-radius: 4px; margin-left: 10px;">Expired</span>' : ''}
                </p>
            </div>
        `;
    }
    
    document.getElementById('modalBody').innerHTML = content;
    document.getElementById('messageModal').style.display = 'block';
}

function closeMessageModal() {
    document.getElementById('messageModal').style.display = 'none';
}

// Search functionality with debounce
let searchTimeout;
document.querySelector('.search-box input')?.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        document.getElementById('searchForm').submit();
    }, 500);
});

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('messageModal');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // ESC to close modal
    if (e.key === 'Escape') {
        closeMessageModal();
    }
    
    // Ctrl+M to mark all as read
    if (e.key === 'm' && e.ctrlKey) {
        e.preventDefault();
        if (confirm('Mark all messages as read?')) {
            window.location.href = 'messages.php?mark_all_read=1';
        }
    }
});

// Auto-refresh for new messages (every 60 seconds) - only if no modals open
setInterval(function() {
    if (document.getElementById('messageModal').style.display !== 'block') {
        location.reload();
    }
}, 60000);

// Add animation to cards on page load
document.addEventListener('DOMContentLoaded', function() {
    const cards = document.querySelectorAll('.announcement-card');
    cards.forEach((card, index) => {
        card.style.animation = `slideIn 0.3s ease ${index * 0.05}s both`;
    });
});

// Add CSS animation
const style = document.createElement('style');
style.textContent = `
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
`;
document.head.appendChild(style);
</script>

