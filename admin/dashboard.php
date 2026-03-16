<?php
// admin/dashboard.php
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/security.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require admin role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$pageTitle = 'Admin Dashboard';
$extraCSS = ['dashboard.css'];
$extraJS = ['charts.js', 'dashboard.js'];

// Check if header file exists before including
$headerPath = dirname(__DIR__) . '/includes/header.php';
if (!file_exists($headerPath)) {
    die("Error: Header file not found at: $headerPath");
}
include $headerPath;

// Get database instance - FIXED: Use Database::getInstance() instead of db()
$db = Database::getInstance();

// Get statistics with error handling
try {
    // Total students (active only, not deleted)
    $totalStudents = $db->getRow("SELECT COUNT(*) as count FROM students s
                                   JOIN users u ON s.user_id = u.id
                                   WHERE u.is_active = 1")['count'] ?? 0;
    
    // Total teachers (active only)
    $totalTeachers = $db->getRow("SELECT COUNT(*) as count FROM teachers t
                                   JOIN users u ON t.user_id = u.id
                                   WHERE u.is_active = 1")['count'] ?? 0;
    
    // Total active classes
    $totalClasses = $db->getRow("SELECT COUNT(*) as count FROM classes WHERE is_active = 1")['count'] ?? 0;
    
    // Pending admissions
    $pendingAdmissions = $db->getRow("SELECT COUNT(*) as count FROM admissions WHERE status = 'pending'")['count'] ?? 0;
    
    // Pending fees (payments with pending status)
    $pendingFees = $db->getRow("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'pending'")['total'] ?? 0;
    
    // Total completed payments
    $totalPayments = $db->getRow("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'completed'")['total'] ?? 0;
    
    // Today's attendance (students present today)
    $todayAttendance = $db->getRow(
        "SELECT COUNT(DISTINCT student_id) as count FROM attendance WHERE date = CURDATE() AND status = 'present'"
    )['count'] ?? 0;
    
    // Recent activities from audit logs
    $recentActivities = $db->getRows(
        "SELECT a.*, u.first_name, u.last_name, u.role 
         FROM audit_logs a 
         LEFT JOIN users u ON a.user_id = u.id 
         ORDER BY a.created_at DESC LIMIT 10"
    ) ?: [];

    // Compile stats array
    $stats = [
        'total_students' => $totalStudents,
        'total_teachers' => $totalTeachers,
        'total_classes' => $totalClasses,
        'pending_admissions' => $pendingAdmissions,
        'pending_fees' => $pendingFees,
        'total_payments' => $totalPayments,
        'today_attendance' => $todayAttendance,
        'recent_activities' => $recentActivities
    ];

    // Get attendance chart data for last 30 days
    $attendanceData = $db->getRows(
        "SELECT 
            DATE_FORMAT(date, '%Y-%m-%d') as date,
            COUNT(DISTINCT student_id) as total_students,
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
            SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late
         FROM attendance 
         WHERE date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
         GROUP BY date
         ORDER BY date"
    ) ?: [];

    // Get fee collection chart data for last 6 months
    $feeData = $db->getRows(
        "SELECT 
            DATE_FORMAT(payment_date, '%Y-%m') as month,
            COUNT(*) as transaction_count,
            SUM(amount) as total
         FROM payments 
         WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
           AND status = 'completed'
         GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
         ORDER BY month"
    ) ?: [];

    // Get gender distribution
    $genderStats = $db->getRows(
        "SELECT gender, COUNT(*) as count 
         FROM students s
         JOIN users u ON s.user_id = u.id
         WHERE u.is_active = 1 AND gender IS NOT NULL
         GROUP BY gender"
    ) ?: [];

    // Get class distribution
    $classDistribution = $db->getRows(
        "SELECT c.class_name, c.section, COUNT(s.id) as student_count
         FROM classes c
         LEFT JOIN students s ON c.id = s.class_id
         WHERE c.is_active = 1
         GROUP BY c.id
         ORDER BY c.class_name, c.section"
    ) ?: [];

} catch (Exception $e) {
    error_log("Dashboard error: " . $e->getMessage());
    $stats = [
        'total_students' => 0,
        'total_teachers' => 0,
        'total_classes' => 0,
        'pending_admissions' => 0,
        'pending_fees' => 0,
        'total_payments' => 0,
        'today_attendance' => 0,
        'recent_activities' => []
    ];
    $attendanceData = [];
    $feeData = [];
    $genderStats = [];
    $classDistribution = [];
}

// Helper function for currency formatting if not defined
if (!function_exists('formatCurrency')) {
    function formatCurrency($amount) {
        return '₦' . number_format($amount, 2);
    }
}

// Helper function for time ago if not defined
if (!function_exists('timeAgo')) {
    function timeAgo($datetime) {
        if (!$datetime) return 'N/A';
        
        $time = strtotime($datetime);
        $now = time();
        $diff = $now - $time;
        
        if ($diff < 60) {
            return $diff . ' seconds ago';
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 2592000) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else {
            return date('M j, Y', $time);
        }
    }
}
?>

<div class="dashboard-container">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <h3>Admin Panel</h3>
        </div>
        
        <nav class="sidebar-nav"> 
            <ul>
                <li class="active"><a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="students.php"><i class="fas fa-user-graduate"></i> Students</a></li>
                <li><a href="parents.php"><i class="fas fa-users"></i> Parents</a></li>
                <li><a href="teachers.php"><i class="fas fa-chalkboard-teacher"></i> Teachers</a></li>
                <li><a href="classes.php"><i class="fas fa-school"></i> Classes</a></li>
                <li><a href="subjects.php"><i class="fas fa-book"></i> Subjects</a></li>
                <li><a href="attendance.php"><i class="fas fa-calendar-check"></i> Attendance</a></li>
                <li><a href="fees.php"><i class="fas fa-money-bill"></i> Fees</a></li>
                <li><a href="results.php"><i class="fas fa-chart-line"></i> Results</a></li>
                <li><a href="announcements.php"><i class="fas fa-bullhorn"></i> Announcements</a></li>
                <li><a href="gallery.php"><i class="fas fa-images"></i> Gallery</a></li>
                <li><a href="reports.php"><i class="fas fa-file-alt"></i> Reports</a></li>
                <li><a href="audit-logs.php"><i class="fas fa-history"></i> Audit Logs</a></li>
            </ul>
        </nav>
    </aside>
    
    <!-- Main Content -->
    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>Dashboard</h1>
            <div class="user-info">
                <i class="fas fa-user-circle"></i>
                <span><?php echo isset($_SESSION['user_name']) ? htmlspecialchars($_SESSION['user_name']) : 'Admin'; ?></span>
                <small>Administrator</small>
            </div>
        </div>
        
        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(0, 40, 85, 0.1);">
                    <i class="fas fa-user-graduate" style="color: #002855;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo $stats['total_students']; ?></h3>
                    <p>Total Students</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(196, 30, 58, 0.1);">
                    <i class="fas fa-chalkboard-teacher" style="color: #c41e3a;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo $stats['total_teachers']; ?></h3>
                    <p>Total Teachers</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(255, 215, 0, 0.1);">
                    <i class="fas fa-school" style="color: #ffd700;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo $stats['total_classes']; ?></h3>
                    <p>Active Classes</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(0, 128, 0, 0.1);">
                    <i class="fas fa-clock" style="color: #008000;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo $stats['pending_admissions']; ?></h3>
                    <p>Pending Applications</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(255, 165, 0, 0.1);">
                    <i class="fas fa-money-bill-wave" style="color: #ffa500;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo formatCurrency($stats['pending_fees']); ?></h3>
                    <p>Pending Fees</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(0, 100, 0, 0.1);">
                    <i class="fas fa-check-circle" style="color: #006400;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo formatCurrency($stats['total_payments']); ?></h3>
                    <p>Total Collected</p>
                </div>
            </div>
        </div>
        
        <!-- Additional Stats Row -->
        <div class="stats-grid secondary">
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(23, 162, 184, 0.1);">
                    <i class="fas fa-calendar-check" style="color: #17a2b8;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo $stats['today_attendance']; ?></h3>
                    <p>Present Today</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(111, 66, 193, 0.1);">
                    <i class="fas fa-venus-mars" style="color: #6f42c1;"></i>
                </div>
                <div class="stat-content">
                    <h3>
                        <?php 
                        $maleCount = 0;
                        $femaleCount = 0;
                        foreach ($genderStats as $g) {
                            if ($g['gender'] === 'male') $maleCount = $g['count'];
                            if ($g['gender'] === 'female') $femaleCount = $g['count'];
                        }
                        echo $maleCount . 'M / ' . $femaleCount . 'F';
                        ?>
                    </h3>
                    <p>Gender Ratio</p>
                </div>
            </div>
        </div>
        
        <!-- Charts -->
        <div class="charts-grid">
            <div class="chart-card">
                <h3><i class="fas fa-chart-line"></i> Attendance Overview (Last 30 Days)</h3>
                <?php if (!empty($attendanceData)): ?>
                <canvas id="attendanceChart" width="400" height="200"></canvas>
                <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-chart-line fa-3x mb-3"></i>
                    <p>No attendance data available</p>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="chart-card">
                <h3><i class="fas fa-chart-bar"></i> Fee Collection (Last 6 Months)</h3>
                <?php if (!empty($feeData)): ?>
                <canvas id="feeChart" width="400" height="200"></canvas>
                <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-chart-bar fa-3x mb-3"></i>
                    <p>No fee data available</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Class Distribution -->
        <?php if (!empty($classDistribution)): ?>
        <div class="card mt-4">
            <div class="card-header">
                <h3><i class="fas fa-users"></i> Class Distribution</h3>
            </div>
            <div class="card-body">
                <div class="class-distribution">
                    <?php foreach ($classDistribution as $class): ?>
                    <div class="class-stat">
                        <div class="class-info">
                            <span class="class-name"><?php echo htmlspecialchars($class['class_name'] . ' ' . $class['section']); ?></span>
                            <span class="student-count"><?php echo $class['student_count']; ?> students</span>
                        </div>
                        <div class="progress-bar-container">
                            <div class="progress-bar-fill" style="width: <?php echo min(100, ($class['student_count'] / 30) * 100); ?>%;">
                                <span class="progress-percentage"><?php echo round(($class['student_count'] / 30) * 100); ?>%</span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Recent Activities -->
        <div class="recent-activities">
            <h3><i class="fas fa-history"></i> Recent Activities</h3>
            <div class="activities-table">
                <?php if (!empty($stats['recent_activities'])): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['recent_activities'] as $log): ?>
                        <tr>
                            <td><?php echo timeAgo($log['created_at']); ?></td>
                            <td>
                                <?php if ($log['first_name']): ?>
                                    <?php echo htmlspecialchars($log['first_name'] . ' ' . $log['last_name']); ?>
                                    <br><small class="text-muted"><?php echo ucfirst($log['role'] ?? 'System'); ?></small>
                                <?php else: ?>
                                    <em>System</em>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($log['action']); ?></td>
                            <td><?php echo $log['ip_address'] ?? 'N/A'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-history fa-3x mb-3"></i>
                    <p>No recent activities</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<!-- Chart.js Script -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Attendance Chart
<?php if (!empty($attendanceData)): ?>
const attendanceCtx = document.getElementById('attendanceChart')?.getContext('2d');
if (attendanceCtx) {
    new Chart(attendanceCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_column($attendanceData, 'date')); ?>,
            datasets: [{
                label: 'Present',
                data: <?php echo json_encode(array_column($attendanceData, 'present')); ?>,
                borderColor: '#28a745',
                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                tension: 0.4,
                fill: true
            }, {
                label: 'Absent',
                data: <?php echo json_encode(array_column($attendanceData, 'absent')); ?>,
                borderColor: '#dc3545',
                backgroundColor: 'rgba(220, 53, 69, 0.1)',
                tension: 0.4,
                fill: true
            }, {
                label: 'Late',
                data: <?php echo json_encode(array_column($attendanceData, 'late')); ?>,
                borderColor: '#ffc107',
                backgroundColor: 'rgba(255, 193, 7, 0.1)',
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Number of Students'
                    }
                }
            }
        }
    });
}
<?php endif; ?>

// Fee Chart
<?php if (!empty($feeData)): ?>
const feeCtx = document.getElementById('feeChart')?.getContext('2d');
if (feeCtx) {
    new Chart(feeCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($feeData, 'month')); ?>,
            datasets: [{
                label: 'Fee Collection (₦)',
                data: <?php echo json_encode(array_column($feeData, 'total')); ?>,
                backgroundColor: '#ffd700',
                borderColor: '#002855',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '₦' + value.toLocaleString();
                        }
                    },
                    title: {
                        display: true,
                        text: 'Amount (₦)'
                    }
                }
            }
        }
    });
}
<?php endif; ?>
</script>

<style>
/* Dashboard specific styles */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stats-grid.secondary {
    margin-top: -10px;
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

.charts-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 30px;
}

.chart-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.chart-card h3 {
    margin-top: 0;
    margin-bottom: 20px;
    font-size: 16px;
    color: var(--navy);
    display: flex;
    align-items: center;
    gap: 8px;
}

.chart-card h3 i {
    color: var(--gold);
}

.chart-card canvas {
    max-height: 250px;
    width: 100% !important;
}

.recent-activities {
    background: white;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    margin-top: 20px;
}

.recent-activities h3 {
    margin-top: 0;
    margin-bottom: 20px;
    font-size: 18px;
    color: var(--navy);
    display: flex;
    align-items: center;
    gap: 8px;
}

.recent-activities h3 i {
    color: var(--gold);
}

.activities-table {
    overflow-x: auto;
}

.no-data {
    text-align: center;
    color: var(--gray);
    padding: 40px 20px;
    background: var(--light-gray);
    border-radius: 8px;
    font-style: italic;
}

.no-data i {
    color: var(--gold);
    opacity: 0.5;
}

.mt-4 {
    margin-top: 20px;
}

.mb-3 {
    margin-bottom: 15px;
}

.text-muted {
    color: var(--gray);
    font-size: 11px;
}

/* Class Distribution Styles */
.class-distribution {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.class-stat {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.class-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.class-name {
    font-weight: 600;
    color: var(--navy);
}

.student-count {
    font-size: 14px;
    color: var(--gray);
}

.progress-bar-container {
    width: 100%;
    height: 25px;
    background-color: var(--light-gray);
    border-radius: 12px;
    overflow: hidden;
    position: relative;
}

.progress-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--navy), var(--gold));
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding-right: 10px;
    color: white;
    font-size: 12px;
    font-weight: 600;
    transition: width 0.5s ease;
}

.progress-percentage {
    text-shadow: 1px 1px 2px rgba(0,0,0,0.2);
}

/* Responsive */
@media (max-width: 992px) {
    .charts-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .stat-card {
        padding: 15px;
    }
    
    .stat-content h3 {
        font-size: 24px;
    }
}
</style>

