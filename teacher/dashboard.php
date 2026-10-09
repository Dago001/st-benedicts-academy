<?php
// teacher/dashboard.php - Teacher Dashboard
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';

Security::requireRole('teacher');

$pageTitle = 'Teacher Dashboard';
$extraCSS = ['dashboard.css'];
$extraJS = ['charts.js'];

include __DIR__ . '/../includes/header.php';

// Get database instance
try {
    $db = Database::getInstance();
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Database connection error: ' . htmlspecialchars(DEBUG_MODE ? $e->getMessage() : 'Please try again later.') . '</div>';
    exit;
}

$userId = $_SESSION['user_id'];

// Get teacher info
$teacher = $db->getRow(
    "SELECT t.*, u.first_name, u.last_name, u.email, u.phone, u.profile_image
     FROM teachers t
     JOIN users u ON t.user_id = u.id
     WHERE t.user_id = ?",
    [$userId]
);

if (!$teacher) {
    echo '<div class="alert alert-danger">Teacher record not found. Please contact administrator.</div>';
    exit;
}

// Get assigned classes
$classes = $db->getRows(
    "SELECT c.*,
            (SELECT COUNT(*) FROM students st JOIN users su ON st.user_id = su.id WHERE st.class_id = c.id AND su.is_active = 1) as student_count
     FROM classes c
     WHERE c.is_active = 1
       AND c.id IN (SELECT id FROM classes WHERE teacher_id = ? UNION SELECT class_id FROM subjects WHERE teacher_id = ?)
     ORDER BY c.class_name, c.section",
    [$teacher['id'], $teacher['id']]
);

// Get subjects taught
$subjects = $db->getRows(
    "SELECT s.*, c.class_name
     FROM subjects s
     JOIN classes c ON s.class_id = c.id
     WHERE s.teacher_id = ? AND s.is_active = 1
     ORDER BY c.class_name, s.subject_name",
    [$teacher['id']]
);

// Today's attendance to mark
$todayAttendance = [];
$classIds = [];
if (!empty($classes)) {
    $classIds = array_column($classes, 'id');
    $placeholders = implode(',', array_fill(0, count($classIds), '?'));

    $todayAttendance = $db->getRows(
        "SELECT c.id, c.class_name, c.section,
                (SELECT COUNT(*) FROM students st JOIN users su ON st.user_id = su.id WHERE st.class_id = c.id AND su.is_active = 1) as total_students,
                (SELECT COUNT(*) FROM attendance WHERE class_id = c.id AND date = CURDATE()) as marked_count
         FROM classes c
         WHERE c.id IN ($placeholders)
         ORDER BY c.class_name, c.section",
        $classIds
    );
}

// Recent results uploaded
$recentResults = $db->getRows(
    "SELECT r.*,
            su.first_name, su.last_name, s.admission_number,
            sub.subject_name,
            c.class_name, c.section,
            CONCAT(u.first_name, ' ', u.last_name) as entered_by_name
     FROM results r
     JOIN students s ON r.student_id = s.id
     JOIN users su ON s.user_id = su.id
     JOIN subjects sub ON r.subject_id = sub.id
     JOIN classes c ON r.class_id = c.id
     LEFT JOIN users u ON r.entered_by = u.id
     WHERE r.entered_by = ?
     ORDER BY r.created_at DESC
     LIMIT 10",
    [$userId]
);

// Pending assignments to grade
$pendingAssignments = $db->getRows(
    "SELECT a.*,
            sub.subject_name,
            c.class_name, c.section,
            (SELECT COUNT(*) FROM homework_submissions hs WHERE hs.homework_id = a.id AND hs.obtained_marks IS NULL) as pending_count
     FROM homework a
     JOIN subjects sub ON a.subject_id = sub.id
     JOIN classes c ON a.class_id = c.id
     WHERE a.teacher_id = ?
       AND (SELECT COUNT(*) FROM homework_submissions hs WHERE hs.homework_id = a.id AND hs.obtained_marks IS NULL) > 0
     ORDER BY a.due_date ASC
     LIMIT 5",
    [$teacher['id']]
);

// Upcoming assignments (not yet due)
$upcomingAssignments = $db->getRows(
    "SELECT a.*,
            sub.subject_name,
            c.class_name, c.section,
            (SELECT COUNT(*) FROM students WHERE class_id = a.class_id) as total_students,
            (SELECT COUNT(*) FROM homework_submissions WHERE homework_id = a.id) as submitted_count
     FROM homework a
     JOIN subjects sub ON a.subject_id = sub.id
     JOIN classes c ON a.class_id = c.id
     WHERE a.teacher_id = ?
       AND a.due_date > CURDATE()
     ORDER BY a.due_date ASC
     LIMIT 5",
    [$teacher['id']]
);

// Get today's date for display
$today = date('l, F j, Y');

// Calculate total students across all classes
$totalStudents = 0;
foreach ($classes as $class) {
    $totalStudents += $class['student_count'];
}

// Get recent announcements for teachers
$announcements = $db->getRows(
    "SELECT a.*, u.first_name, u.last_name
     FROM announcements a
     JOIN users u ON a.created_by = u.id
     WHERE (a.audience = 'all' OR a.audience = 'teachers' OR a.audience = 'admins')
       AND a.is_published = 1
       AND (a.expires_at IS NULL OR a.expires_at >= CURDATE())
     ORDER BY a.created_at DESC
     LIMIT 5"
);

?>

<style>
/* Teacher Dashboard Specific Styles */
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

.dashboard-container {
    display: flex;
    min-height: 100vh;
    background: #f4f6f9;
}

/* Sidebar Styles */
.sidebar {
    width: 260px;
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-light) 100%);
    color: white;
    position: fixed;
    height: 100vh;
    overflow-y: auto;
    transition: all 0.3s ease;
    z-index: 1000;
    box-shadow: 2px 0 10px rgba(0,0,0,0.1);
}

.sidebar-header {
    padding: 20px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    text-align: center;
}

.sidebar-header h3 {
    margin: 0;
    font-size: 1.3rem;
    font-weight: 600;
    color: var(--gold);
}

.sidebar-nav ul {
    list-style: none;
    padding: 20px 0;
    margin: 0;
}

.sidebar-nav li {
    margin-bottom: 5px;
}

.sidebar-nav li a {
    display: flex;
    align-items: center;
    padding: 12px 20px;
    color: rgba(255,255,255,0.8);
    text-decoration: none;
    transition: all 0.3s ease;
    border-left: 3px solid transparent;
}

.sidebar-nav li a i {
    width: 24px;
    font-size: 1.1rem;
    margin-right: 10px;
}

.sidebar-nav li:hover a {
    background: rgba(255,255,255,0.1);
    color: white;
    border-left-color: var(--gold);
}

.sidebar-nav li.active a {
    background: rgba(255,215,0,0.15);
    color: var(--gold);
    border-left-color: var(--gold);
}

/* Main Content */
.dashboard-main {
    flex: 1;
    margin-left: 260px;
    padding: 30px;
}

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
    font-size: 1.5rem;
    color: var(--gold);
}

.user-info span {
    font-weight: 600;
    color: var(--navy);
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    border-radius: 10px;
    padding: 25px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    display: flex;
    align-items: center;
    gap: 20px;
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

/* Dashboard Grid */
.dashboard-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 25px;
    margin-bottom: 30px;
}

.dashboard-card {
    background: white;
    border-radius: 10px;
    padding: 25px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.dashboard-card h3 {
    margin: 0 0 20px;
    color: var(--navy);
    font-size: 1.2rem;
    display: flex;
    align-items: center;
    gap: 10px;
}

.dashboard-card h3 i {
    color: var(--gold);
}

.dashboard-card.full-width {
    grid-column: 1 / -1;
}

/* Class List */
.class-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.class-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    background: var(--light);
    border-radius: 8px;
    transition: all 0.3s ease;
}

.class-item:hover {
    background: white;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.class-info h4 {
    margin: 0 0 5px;
    color: var(--navy);
    font-size: 1rem;
}

.class-info p {
    margin: 0;
    color: var(--gray);
    font-size: 0.9rem;
}

.class-status .badge {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.badge.success {
    background: rgba(40, 167, 69, 0.1);
    color: var(--success);
}

.badge.warning {
    background: rgba(255, 193, 7, 0.1);
    color: #856404;
}

.badge.danger {
    background: rgba(220, 53, 69, 0.1);
    color: var(--danger);
}

.badge.info {
    background: rgba(23, 162, 184, 0.1);
    color: var(--info);
}

/* Button Styles */
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

.btn-small {
    padding: 5px 10px;
    font-size: 12px;
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

/* Pending List */
.pending-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.pending-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    background: var(--light);
    border-radius: 8px;
    transition: all 0.3s ease;
}

.pending-item:hover {
    background: white;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.pending-info h4 {
    margin: 0 0 5px;
    color: var(--navy);
    font-size: 1rem;
}

.pending-info p {
    margin: 0 0 5px;
    color: var(--gray);
    font-size: 0.9rem;
}

.pending-info small {
    color: var(--gray);
    font-size: 0.8rem;
}

.pending-status {
    display: flex;
    align-items: center;
    gap: 10px;
}

/* Table Styles */
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th {
    background: var(--navy);
    color: white;
    padding: 12px;
    text-align: left;
    font-weight: 500;
    white-space: nowrap;
}

.data-table td {
    padding: 12px;
    border-bottom: 1px solid var(--gray-light);
}

.data-table tbody tr:hover {
    background: var(--light);
}

/* Quick Actions */
.quick-actions {
    margin-top: 30px;
}

.quick-actions h3 {
    color: var(--navy);
    margin-bottom: 20px;
    font-size: 1.2rem;
}

.actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

.action-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 25px;
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    text-decoration: none;
    color: var(--navy);
    transition: all 0.3s ease;
    border: 2px solid transparent;
}

.action-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
    border-color: var(--gold);
}

.action-card i {
    font-size: 2.5rem;
    color: var(--gold);
    margin-bottom: 10px;
}

.action-card span {
    font-weight: 600;
    text-align: center;
}

/* No Data */
.no-data {
    text-align: center;
    color: var(--gray);
    padding: 20px;
    background: var(--light);
    border-radius: 8px;
    margin: 0;
}

/* Text utilities */
.text-center {
    text-align: center;
}

/* Announcements Section */
.announcements-section {
    margin-top: 30px;
}

.announcement-item {
    display: flex;
    align-items: flex-start;
    gap: 15px;
    padding: 15px;
    background: var(--light);
    border-radius: 8px;
    margin-bottom: 10px;
    border-left: 3px solid transparent;
}

.announcement-item.priority-urgent {
    border-left-color: var(--danger);
}

.announcement-item.priority-high {
    border-left-color: var(--warning);
}

.announcement-item.priority-normal {
    border-left-color: var(--navy);
}

.announcement-icon {
    color: var(--gold);
    font-size: 1.2rem;
}

.announcement-content {
    flex: 1;
}

.announcement-content h4 {
    margin: 0 0 5px;
    color: var(--navy);
}

.announcement-content p {
    margin: 0 0 5px;
    color: var(--dark);
}

.announcement-meta {
    display: flex;
    gap: 15px;
    font-size: 0.8rem;
    color: var(--gray);
}

/* Responsive */
@media (max-width: 768px) {
    .sidebar {
        width: 0;
        transform: translateX(-100%);
    }

    .dashboard-main {
        margin-left: 0;
        padding: 20px;
    }

    .stats-grid {
        grid-template-columns: 1fr;
    }

    .dashboard-grid {
        grid-template-columns: 1fr;
    }

    .dashboard-header {
        flex-direction: column;
        text-align: center;
    }

    .actions-grid {
        grid-template-columns: 1fr 1fr;
    }

    .class-item, .pending-item {
        flex-direction: column;
        text-align: center;
        gap: 10px;
    }

    .pending-status {
        flex-direction: column;
        width: 100%;
    }

    .pending-status .btn {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .actions-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="dashboard-container">
    <?php render_sidebar('teacher'); ?>

    <!-- Main Content -->
    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>Welcome, <?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?>! 👋</h1>
            <div class="user-info">
                <i class="fas fa-chalkboard-teacher"></i>
                <div>
                    <span><?php echo htmlspecialchars($teacher['employee_id']); ?></span>
                    <small style="display: block; font-size: 0.7rem;"><?php echo e($today); ?></small>
                </div>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(0, 40, 85, 0.1);">
                    <i class="fas fa-school" style="color: #002855;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo count($classes); ?></h3>
                    <p>Classes Assigned</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(196, 30, 58, 0.1);">
                    <i class="fas fa-book" style="color: #c41e3a;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo count($subjects); ?></h3>
                    <p>Subjects Taught</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(255, 215, 0, 0.1);">
                    <i class="fas fa-users" style="color: #ffd700;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo e($totalStudents); ?></h3>
                    <p>Total Students</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(220, 53, 69, 0.1);">
                    <i class="fas fa-clock" style="color: #dc3545;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo count($pendingAssignments); ?></h3>
                    <p>Pending Grading</p>
                </div>
            </div>
        </div>

        <!-- Dashboard Grid -->
        <div class="dashboard-grid">
            <!-- Today's Classes -->
            <div class="dashboard-card">
                <h3><i class="fas fa-calendar-day"></i> Today's Classes</h3>
                <div class="class-list">
                    <?php if (!empty($todayAttendance)): ?>
                        <?php foreach ($todayAttendance as $class): ?>
                        <div class="class-item">
                            <div class="class-info">
                                <h4><?php echo htmlspecialchars($class['class_name'] . ' ' . $class['section']); ?></h4>
                                <p><i class="fas fa-users"></i> <?php echo e($class['total_students']); ?> Students</p>
                            </div>
                            <div class="class-status">
                                <?php if ($class['marked_count'] > 0): ?>
                                    <span class="badge success">
                                        <i class="fas fa-check-circle"></i> Attendance Marked
                                    </span>
                                <?php else: ?>
                                    <a href="attendance?class_id=<?php echo e($class['id']); ?>"
                                       class="btn btn-small btn-primary">
                                        <i class="fas fa-calendar-check"></i> Mark Attendance
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="no-data">
                            <i class="fas fa-info-circle"></i><br>
                            No classes assigned for today.
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Pending Assignments to Grade -->
            <div class="dashboard-card">
                <h3><i class="fas fa-tasks"></i> Pending Grading</h3>
                <div class="pending-list">
                    <?php if (!empty($pendingAssignments)): ?>
                        <?php foreach ($pendingAssignments as $assignment): ?>
                        <div class="pending-item">
                            <div class="pending-info">
                                <h4><?php echo htmlspecialchars($assignment['title']); ?></h4>
                                <p>
                                    <i class="fas fa-book"></i> <?php echo htmlspecialchars($assignment['subject_name']); ?> -
                                    <?php echo htmlspecialchars($assignment['class_name'] . ' ' . $assignment['section']); ?>
                                </p>
                                <small>
                                    <i class="fas fa-hourglass-end"></i>
                                    Due: <?php echo formatDate($assignment['due_date']); ?>
                                </small>
                            </div>
                            <div class="pending-status">
                                <span class="badge warning">
                                    <?php echo e($assignment['pending_count']); ?> pending
                                </span>
                                <a href="submissions?assignment_id=<?php echo e($assignment['id']); ?>"
                                   class="btn btn-small btn-outline">
                                    <i class="fas fa-check"></i> Grade
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="no-data">
                            <i class="fas fa-check-circle" style="color: var(--success);"></i><br>
                            No pending assignments to grade!
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Upcoming Assignments -->
            <?php if (!empty($upcomingAssignments)): ?>
            <div class="dashboard-card">
                <h3><i class="fas fa-calendar-alt"></i> Upcoming Assignments</h3>
                <div class="pending-list">
                    <?php foreach ($upcomingAssignments as $assignment): ?>
                    <div class="pending-item">
                        <div class="pending-info">
                            <h4><?php echo htmlspecialchars($assignment['title']); ?></h4>
                            <p>
                                <i class="fas fa-book"></i> <?php echo htmlspecialchars($assignment['subject_name']); ?>
                            </p>
                            <small>
                                <i class="fas fa-clock"></i>
                                Due: <?php echo formatDate($assignment['due_date']); ?>
                            </small>
                        </div>
                        <div class="pending-status">
                            <span class="badge info">
                                <?php echo e($assignment['submitted_count']); ?>/<?php echo e($assignment['total_students']); ?> submitted
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Recent Announcements -->
            <?php if (!empty($announcements)): ?>
            <div class="dashboard-card">
                <h3><i class="fas fa-bullhorn"></i> Latest Announcements</h3>
                <div class="pending-list">
                    <?php foreach ($announcements as $ann): ?>
                    <div class="announcement-item priority-<?php echo e($ann['priority']); ?>">
                        <div class="announcement-icon">
                            <i class="fas fa-bullhorn"></i>
                        </div>
                        <div class="announcement-content">
                            <h4><?php echo htmlspecialchars($ann['title']); ?></h4>
                            <p><?php echo htmlspecialchars(substr(strip_tags($ann['content']), 0, 100)) . '...'; ?></p>
                            <div class="announcement-meta">
                                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($ann['first_name']); ?></span>
                                <span><i class="fas fa-calendar"></i> <?php echo formatDate($ann['created_at']); ?></span>
                                <?php if ($ann['priority'] === 'urgent'): ?>
                                <span class="badge danger">Urgent</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Recent Results -->
        <div class="dashboard-card full-width">
            <h3><i class="fas fa-history"></i> Recently Entered Results</h3>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Student</th>
                            <th>Admission No.</th>
                            <th>Subject</th>
                            <th>Class</th>
                            <th>Score</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recentResults)): ?>
                            <?php foreach ($recentResults as $result): ?>
                            <tr>
                                <td><?php echo formatDate($result['created_at']); ?></td>
                                <td><?php echo htmlspecialchars($result['first_name'] . ' ' . $result['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($result['admission_number']); ?></td>
                                <td><?php echo htmlspecialchars($result['subject_name']); ?></td>
                                <td><?php echo htmlspecialchars($result['class_name'] . ' ' . $result['section']); ?></td>
                                <td>
                                    <strong><?php echo e($result['score']); ?></strong>/<?php echo e($result['max_score']); ?>
                                    (<?php echo round(($result['score'] / $result['max_score']) * 100, 1); ?>%)
                                </td>
                                <td>
                                    <?php if ($result['is_approved']): ?>
                                        <span class="badge success">Approved</span>
                                    <?php else: ?>
                                        <span class="badge warning">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="results?edit=<?php echo e($result['id']); ?>"
                                           class="btn-icon" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="results?view=<?php echo e($result['id']); ?>"
                                           class="btn-icon" title="View">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center">
                                <div style="padding: 30px;">
                                    <i class="fas fa-chart-line" style="font-size: 3rem; color: var(--gray-light);"></i>
                                    <p style="margin-top: 10px; color: var(--gray);">No results entered yet.</p>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <h3>Quick Actions</h3>
            <div class="actions-grid">
                <a href="attendance" class="action-card">
                    <i class="fas fa-calendar-check"></i>
                    <span>Mark Attendance</span>
                </a>
                <a href="results?action=add" class="action-card">
                    <i class="fas fa-plus-circle"></i>
                    <span>Add Results</span>
                </a>
                <a href="assignments?action=add" class="action-card">
                    <i class="fas fa-upload"></i>
                    <span>Post Assignment</span>
                </a>
                <a href="messages?compose" class="action-card">
                    <i class="fas fa-paper-plane"></i>
                    <span>Send Message</span>
                </a>
                <a href="students" class="action-card">
                    <i class="fas fa-user-graduate"></i>
                    <span>View Students</span>
                </a>
                <a href="profile" class="action-card">
                    <i class="fas fa-user-cog"></i>
                    <span>Update Profile</span>
                </a>
            </div>
        </div>
    </main>
</div>

<script>
// Auto-refresh for new data (every 60 seconds)
setTimeout(function() {
    location.reload();
}, 60000);

// Add smooth scrolling
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            target.scrollIntoView({
                behavior: 'smooth'
            });
        }
    });
});

// Initialize tooltips if needed
document.addEventListener('DOMContentLoaded', function() {
    // Add fade-in animation to cards
    const cards = document.querySelectorAll('.stat-card, .dashboard-card, .action-card');
    cards.forEach((card, index) => {
        card.style.animation = `fadeIn 0.5s ease ${index * 0.05}s both`;
    });
});

// Add CSS animation
const style = document.createElement('style');
style.textContent = `
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
`;
document.head.appendChild(style);
</script>

<?php
// Include footer
$footerPath = __DIR__ . '/../includes/footer.php';
if (file_exists($footerPath)) {
    include $footerPath;
}
?>