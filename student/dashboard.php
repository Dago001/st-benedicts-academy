<?php
// student/dashboard.php
require_once '../config/config.php';
require_once '../config/security.php';

Security::requireRole('student');

$pageTitle = 'Student Dashboard';
$extraCSS = ['dashboard.css'];

include '../includes/header.php';

$db = db();
$userId = $_SESSION['user_id'];

// Get student info
$student = $db->getRow(
    "SELECT s.*, u.first_name, u.last_name, u.email, u.phone, u.profile_image,
            c.class_name, c.section
     FROM students s
     JOIN users u ON s.user_id = u.id
     LEFT JOIN classes c ON s.class_id = c.id
     WHERE s.user_id = ?",
    [$userId]
);

if (!$student) {
    echo '<div class="container" style="padding:24px"><div class="alert alert-error">Your student profile is incomplete. Please contact the school office.</div></div>';
    include '../includes/footer.php';
    exit;
}

// Get recent results
$recentResults = $db->getRows(
    "SELECT r.*, s.subject_name, r.score, r.max_score, r.grade, r.term, r.academic_year
     FROM results r
     JOIN subjects s ON r.subject_id = s.id
     WHERE r.student_id = ? AND r.is_approved = 1
     ORDER BY r.created_at DESC LIMIT 5",
    [$student['id']]
);

// Get attendance summary
$attendanceSummary = $db->getRow(
    "SELECT
        COUNT(*) as total_days,
        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
        SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late
     FROM attendance
     WHERE student_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
    [$student['id']]
);

// Get pending assignments
$pendingAssignments = $db->getRows(
    "SELECT a.*, s.subject_name, c.class_name,
            (SELECT submission_date FROM homework_submissions
             WHERE homework_id = a.id AND student_id = ?) as submitted
     FROM homework a
     JOIN subjects s ON a.subject_id = s.id
     JOIN classes c ON a.class_id = c.id
     WHERE a.class_id = ? AND a.is_published = 1 AND a.due_date >= CURDATE()
     ORDER BY a.due_date ASC",
    [$student['id'], $student['class_id']]
);

// Get recent announcements
$announcements = $db->getRows(
    "SELECT * FROM announcements
     WHERE (audience = 'all' OR audience = 'students')
       AND is_published = 1
       AND (expires_at IS NULL OR expires_at > NOW())
     ORDER BY created_at DESC LIMIT 3"
);

// Fee status: everything charged to the class vs. completed payments
$feeStatus = $db->getRow(
    "SELECT
        COALESCE((SELECT SUM(amount) FROM payments WHERE student_id = ? AND status = 'completed'), 0) AS paid,
        GREATEST(COALESCE((SELECT SUM(amount) FROM fee_structure WHERE class_id = ?), 0)
               - COALESCE((SELECT SUM(amount) FROM payments WHERE student_id = ? AND status = 'completed'), 0), 0) AS outstanding",
    [$student['id'], $student['class_id'] ?? 0, $student['id']]
);
?>

<div class="dashboard-container">
    <?php render_sidebar('student'); ?>

    <!-- Main Content -->
    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>Welcome, <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h1>
            <div class="user-info">
                <i class="fas fa-user-graduate"></i>
                <span><?php echo htmlspecialchars($student['admission_number']); ?></span>
                <small><?php echo htmlspecialchars($student['class_name'] . ' ' . $student['section']); ?></small>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(0, 40, 85, 0.1);">
                    <i class="fas fa-calendar-check" style="color: #002855;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php
                        $attendanceRate = $attendanceSummary['total_days'] > 0
                            ? round(($attendanceSummary['present'] / $attendanceSummary['total_days']) * 100, 1)
                            : 0;
                        echo $attendanceRate . '%';
                    ?></h3>
                    <p>Attendance Rate</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(196, 30, 58, 0.1);">
                    <i class="fas fa-star" style="color: #c41e3a;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo count($recentResults); ?></h3>
                    <p>Results Available</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(255, 215, 0, 0.1);">
                    <i class="fas fa-clock" style="color: #ffd700;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo count($pendingAssignments); ?></h3>
                    <p>Pending Assignments</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(0, 128, 0, 0.1);">
                    <i class="fas fa-money-bill" style="color: #008000;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo formatCurrency($feeStatus['outstanding'] ?? 0); ?></h3>
                    <p>Outstanding Fees</p>
                </div>
            </div>
        </div>

        <div class="dashboard-grid">
            <!-- Recent Results -->
            <div class="dashboard-card">
                <h3><i class="fas fa-chart-line"></i> Recent Results</h3>
                <?php if (!empty($recentResults)): ?>
                <div class="results-list">
                    <?php foreach ($recentResults as $result): ?>
                    <div class="result-item">
                        <div class="result-info">
                            <h4><?php echo htmlspecialchars($result['subject_name']); ?></h4>
                            <p><?php echo e($result['term']); ?> - <?php echo e($result['academic_year']); ?></p>
                        </div>
                        <div class="result-score <?php echo $result['score'] >= 70 ? 'high' : ($result['score'] >= 50 ? 'medium' : 'low'); ?>">
                            <?php echo e($result['score']); ?>/<?php echo e($result['max_score']); ?>
                            <small>Grade <?php echo e($result['grade']); ?></small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <a href="results" class="btn-link">View All Results <i class="fas fa-arrow-right"></i></a>
                <?php else: ?>
                <p class="no-data">No results available yet.</p>
                <?php endif; ?>
            </div>

            <!-- Pending Assignments -->
            <div class="dashboard-card">
                <h3><i class="fas fa-tasks"></i> Pending Assignments</h3>
                <?php if (!empty($pendingAssignments)): ?>
                <div class="assignments-list">
                    <?php foreach ($pendingAssignments as $assignment): ?>
                    <div class="assignment-item">
                        <h4><?php echo htmlspecialchars($assignment['title']); ?></h4>
                        <p><?php echo htmlspecialchars($assignment['subject_name']); ?></p>
                        <div class="assignment-meta">
                            <span class="due-date <?php echo strtotime($assignment['due_date']) < time() ? 'overdue' : ''; ?>">
                                <i class="fas fa-clock"></i> Due: <?php echo formatDate($assignment['due_date']); ?>
                            </span>
                            <?php if ($assignment['submitted']): ?>
                                <span class="badge success">Submitted</span>
                            <?php else: ?>
                                <a href="assignments?submit=<?php echo e($assignment['id']); ?>"
                                   class="btn btn-small btn-primary">Submit</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="no-data">No pending assignments.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Announcements -->
        <div class="dashboard-card full-width">
            <h3><i class="fas fa-bullhorn"></i> Announcements</h3>
            <?php if (!empty($announcements)): ?>
            <div class="announcements-list">
                <?php foreach ($announcements as $announcement): ?>
                <div class="announcement-item priority-<?php echo e($announcement['priority']); ?>">
                    <div class="announcement-header">
                        <h4><?php echo htmlspecialchars($announcement['title']); ?></h4>
                        <span class="announcement-date">
                            <i class="far fa-clock"></i> <?php echo timeAgo($announcement['created_at']); ?>
                        </span>
                    </div>
                    <p><?php echo nl2br(htmlspecialchars($announcement['content'])); ?></p>
                    <?php if ($announcement['attachment']): ?>
                    <a href="<?php echo BASE_URL; ?>/uploads/announcements/<?php echo e($announcement['attachment']); ?>"
                       class="btn-link" target="_blank">
                        <i class="fas fa-paperclip"></i> View Attachment
                    </a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="no-data">No announcements at this time.</p>
            <?php endif; ?>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <h3>Quick Actions</h3>
            <div class="actions-grid">
                <a href="results?download=all" class="action-card">
                    <i class="fas fa-download"></i>
                    <span>Download Report Card</span>
                </a>
                <a href="assignments" class="action-card">
                    <i class="fas fa-upload"></i>
                    <span>Submit Assignment</span>
                </a>
                <a href="messages?compose" class="action-card">
                    <i class="fas fa-envelope"></i>
                    <span>Message Teacher</span>
                </a>
                <a href="profile" class="action-card">
                    <i class="fas fa-user-edit"></i>
                    <span>Update Profile</span>
                </a>
            </div>
        </div>
    </main>
</div>

<?php
include '../includes/footer.php';
?>