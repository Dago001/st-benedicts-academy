<?php
// parent/dashboard.php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/security.php';

Security::requireRole('parent');

$pageTitle = 'Parent Dashboard';
$extraCSS = ['dashboard.css'];

include '../includes/header.php';

$db = db();
$userId = $_SESSION['user_id'];

// Get parent info
$parent = $db->getRow(
    "SELECT p.*, u.first_name, u.last_name, u.email, u.phone 
     FROM parents p 
     JOIN users u ON p.user_id = u.id 
     WHERE p.user_id = ?",
    [$userId]
);

// Get children (students) of this parent
$children = $db->getRows(
    "SELECT s.*, u.first_name, u.last_name, u.email, u.profile_image,
            c.class_name, c.section
     FROM students s 
     JOIN users u ON s.user_id = u.id 
     LEFT JOIN classes c ON s.class_id = c.id 
     WHERE s.parent_id = ? AND u.is_active = 1",
    [$parent['id']]
);

// Get selected child
$selectedChildId = $_GET['child'] ?? ($children[0]['id'] ?? null);

// Get child's performance summary if child selected
$childPerformance = [];
$recentResults = [];
$attendanceSummary = [];
$feeStatus = [];

if ($selectedChildId) {
    // Get recent results
    $recentResults = $db->getRows(
        "SELECT r.*, s.subject_name, r.score, r.max_score, r.grade, r.term, r.academic_year
         FROM results r
         JOIN subjects s ON r.subject_id = s.id
         WHERE r.student_id = ? AND r.is_approved = 1
         ORDER BY r.created_at DESC LIMIT 5",
        [$selectedChildId]
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
        [$selectedChildId]
    );
    
    // Get fee status
    $feeStatus = $db->getRow(
        "SELECT 
            SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as paid,
            SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as outstanding
         FROM payments 
         WHERE student_id = ? AND academic_year = ?",
        [$selectedChildId, date('Y') . '-' . (date('Y') + 1)]
    );
    
    // Calculate performance metrics
    if (!empty($recentResults)) {
        $totalPercentage = 0;
        foreach ($recentResults as $result) {
            $totalPercentage += ($result['score'] / $result['max_score']) * 100;
        }
        $childPerformance['average'] = $totalPercentage / count($recentResults);
    }
}

// Get recent announcements for parents
$announcements = $db->getRows(
    "SELECT * FROM announcements 
     WHERE (audience = 'all' OR audience = 'parents') 
       AND is_published = 1 
       AND (expires_at IS NULL OR expires_at > NOW())
     ORDER BY created_at DESC LIMIT 5"
);
?>

<div class="dashboard-container">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <h3>Parent Portal</h3>
        </div>
        
        <nav class="sidebar-nav">
            <ul>
                <li class="active"><a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="children.php"><i class="fas fa-child"></i> My Children</a></li>
                <li><a href="fees.php"><i class="fas fa-money-bill"></i> Fee Status</a></li>
                <li><a href="messages.php"><i class="fas fa-envelope"></i> Messages</a></li>
                <li><a href="profile.php"><i class="fas fa-user-cog"></i> Profile</a></li>
            </ul>
        </nav>
    </aside>
    
    <!-- Main Content -->
    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>Welcome, <?php echo htmlspecialchars($parent['first_name'] . ' ' . $parent['last_name']); ?></h1>
            <div class="user-info">
                <i class="fas fa-users"></i>
                <span><?php echo count($children); ?> Child(ren) Enrolled</span>
            </div>
        </div>
        
        <!-- Child Selection -->
        <?php if (count($children) > 1): ?>
        <div class="card">
            <div class="card-body">
                <form method="GET" class="form-inline">
                    <div class="form-group">
                        <label for="child">Select Child:</label>
                        <select name="child" id="child" onchange="this.form.submit()">
                            <?php foreach ($children as $child): ?>
                            <option value="<?php echo $child['id']; ?>" 
                                <?php echo ($selectedChildId == $child['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($child['first_name'] . ' ' . $child['last_name']); ?> 
                                - <?php echo htmlspecialchars($child['class_name'] . ' ' . $child['section']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($selectedChildId && !empty($children)): 
            $selectedChild = array_filter($children, function($c) use ($selectedChildId) {
                return $c['id'] == $selectedChildId;
            });
            $selectedChild = reset($selectedChild);
        ?>
        
        <!-- Child Overview -->
        <div class="child-header">
            <div class="child-info">
                <div class="child-avatar">
                    <?php if ($selectedChild['profile_image']): ?>
                    <img src="<?php echo BASE_URL; ?>/uploads/students/<?php echo $selectedChild['profile_image']; ?>" 
                         alt="<?php echo htmlspecialchars($selectedChild['first_name']); ?>">
                    <?php else: ?>
                    <i class="fas fa-user-graduate"></i>
                    <?php endif; ?>
                </div>
                <div class="child-details">
                    <h2><?php echo htmlspecialchars($selectedChild['first_name'] . ' ' . $selectedChild['last_name']); ?></h2>
                    <p class="class-info">
                        <i class="fas fa-school"></i> <?php echo htmlspecialchars($selectedChild['class_name'] . ' ' . $selectedChild['section']); ?>
                        <span class="separator">|</span>
                        <i class="fas fa-id-card"></i> Adm No: <?php echo $selectedChild['admission_number']; ?>
                    </p>
                </div>
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
                    <i class="fas fa-trophy" style="color: #ffd700;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo isset($childPerformance['average']) ? number_format($childPerformance['average'], 1) . '%' : 'N/A'; ?></h3>
                    <p>Average Performance</p>
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
                            <p><?php echo $result['term']; ?> - <?php echo $result['academic_year']; ?></p>
                        </div>
                        <div class="result-score <?php echo $result['score'] >= 70 ? 'high' : ($result['score'] >= 50 ? 'medium' : 'low'); ?>">
                            <?php echo $result['score']; ?>/<?php echo $result['max_score']; ?>
                            <small>Grade <?php echo $result['grade']; ?></small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <a href="child-performance.php?child=<?php echo $selectedChildId; ?>" class="btn-link">
                    View All Results <i class="fas fa-arrow-right"></i>
                </a>
                <?php else: ?>
                <p class="no-data">No results available yet.</p>
                <?php endif; ?>
            </div>
            
            <!-- Attendance Summary -->
            <div class="dashboard-card">
                <h3><i class="fas fa-calendar-alt"></i> Attendance (Last 30 Days)</h3>
                <?php if ($attendanceSummary['total_days'] > 0): ?>
                <div class="attendance-summary">
                    <div class="attendance-chart">
                        <?php
                        $presentPercent = ($attendanceSummary['present'] / $attendanceSummary['total_days']) * 100;
                        $absentPercent = ($attendanceSummary['absent'] / $attendanceSummary['total_days']) * 100;
                        $latePercent = ($attendanceSummary['late'] / $attendanceSummary['total_days']) * 100;
                        ?>
                        <div class="progress-circle" data-value="<?php echo $presentPercent; ?>">
                            <span><?php echo round($presentPercent); ?>%</span>
                        </div>
                    </div>
                    <div class="attendance-stats">
                        <div class="stat-row">
                            <span class="label present"><i class="fas fa-circle"></i> Present:</span>
                            <span class="value"><?php echo $attendanceSummary['present']; ?> days</span>
                        </div>
                        <div class="stat-row">
                            <span class="label absent"><i class="fas fa-circle"></i> Absent:</span>
                            <span class="value"><?php echo $attendanceSummary['absent']; ?> days</span>
                        </div>
                        <div class="stat-row">
                            <span class="label late"><i class="fas fa-circle"></i> Late:</span>
                            <span class="value"><?php echo $attendanceSummary['late']; ?> days</span>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <p class="no-data">No attendance data available.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Fee Status -->
        <div class="dashboard-card">
            <h3><i class="fas fa-money-check-alt"></i> Fee Status - Current Term</h3>
            <?php if ($feeStatus): ?>
            <div class="fee-progress">
                <div class="fee-info">
                    <div class="fee-item">
                        <label>Total Paid:</label>
                        <span class="amount paid"><?php echo formatCurrency($feeStatus['paid'] ?? 0); ?></span>
                    </div>
                    <div class="fee-item">
                        <label>Outstanding:</label>
                        <span class="amount outstanding <?php echo ($feeStatus['outstanding'] ?? 0) > 0 ? 'warning' : 'success'; ?>">
                            <?php echo formatCurrency($feeStatus['outstanding'] ?? 0); ?>
                        </span>
                    </div>
                </div>
                <?php 
                $totalFees = ($feeStatus['paid'] ?? 0) + ($feeStatus['outstanding'] ?? 0);
                if ($totalFees > 0):
                    $paidPercent = ($feeStatus['paid'] / $totalFees) * 100;
                ?>
                <div class="progress-bar large">
                    <div class="progress-fill success" style="width: <?php echo $paidPercent; ?>%">
                        <?php echo round($paidPercent); ?>% Paid
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <a href="fees.php?child=<?php echo $selectedChildId; ?>" class="btn-link">View Fee Details</a>
            <?php else: ?>
            <p class="no-data">No fee records available.</p>
            <?php endif; ?>
        </div>
        
        <?php else: ?>
        <!-- No children or no child selected -->
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            <?php if (empty($children)): ?>
                No children are currently linked to your account. Please contact the school administration.
            <?php else: ?>
                Please select a child to view their information.
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- School Announcements -->
        <div class="dashboard-card full-width">
            <h3><i class="fas fa-bullhorn"></i> School Announcements</h3>
            <?php if (!empty($announcements)): ?>
            <div class="announcements-list">
                <?php foreach ($announcements as $announcement): ?>
                <div class="announcement-item priority-<?php echo $announcement['priority']; ?>">
                    <div class="announcement-header">
                        <h4><?php echo htmlspecialchars($announcement['title']); ?></h4>
                        <span class="announcement-date">
                            <i class="far fa-clock"></i> <?php echo timeAgo($announcement['created_at']); ?>
                        </span>
                    </div>
                    <p><?php echo nl2br(htmlspecialchars($announcement['content'])); ?></p>
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
                <a href="messages.php?compose" class="action-card">
                    <i class="fas fa-envelope"></i>
                    <span>Message Teacher</span>
                </a>
                <a href="fees.php" class="action-card">
                    <i class="fas fa-credit-card"></i>
                    <span>Pay Fees</span>
                </a>
                <a href="schedule.php" class="action-card">
                    <i class="fas fa-calendar-alt"></i>
                    <span>View Schedule</span>
                </a>
                <a href="documents.php" class="action-card">
                    <i class="fas fa-file-alt"></i>
                    <span>Download Reports</span>
                </a>
            </div>
        </div>
    </main>
</div>

<?php
include '../includes/footer.php';
?>