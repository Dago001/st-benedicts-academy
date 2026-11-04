<?php
// admin/dashboard.php
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/security.php';

Security::requireRole('admin');

$pageTitle = 'Admin Dashboard';
$extraCSS = ['dashboard.css'];
$extraJS = ['charts.js', 'dashboard.js'];

include __DIR__ . '/../includes/header.php';

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
    $pendingAdmissions = ($db->getRow("SELECT COUNT(*) as count FROM admissions WHERE status = 'pending'")['count'] ?? 0)
        + ($db->getRow("SELECT COUNT(*) as count FROM applications WHERE status = 'pending'")['count'] ?? 0);

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

    // Get class distribution (deduplicated by class name & section, with actual capacity & student enrollment)
    $classDistribution = $db->getRows(
        "SELECT MIN(c.id) as id,
                c.class_name,
                COALESCE(c.section, '') as section,
                MAX(COALESCE(c.capacity, 25)) as capacity,
                COUNT(s.id) as student_count
         FROM classes c
         LEFT JOIN students s ON c.id = s.class_id
         WHERE c.is_active = 1
         GROUP BY c.class_name, COALESCE(c.section, '')
         ORDER BY c.class_name, section"
    ) ?: [];

    $totalClassStudents = 0;
    $totalClassCapacity = 0;
    foreach ($classDistribution as $cd) {
        $totalClassStudents += (int)$cd['student_count'];
        $totalClassCapacity += (int)$cd['capacity'];
    }
    $overallFillPercent = $totalClassCapacity > 0 ? round(($totalClassStudents / $totalClassCapacity) * 100) : 0;

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
    $totalClassStudents = 0;
    $totalClassCapacity = 0;
    $overallFillPercent = 0;
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
    <?php render_sidebar('admin'); ?>

    <!-- Main Content -->
    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>Dashboard</h1>
            <div class="user-dropdown-wrapper" id="userDropdownWrapper">
                <button type="button" class="user-dropdown-toggle" id="userDropdownToggle" aria-haspopup="true" aria-expanded="false">
                    <span class="user-avatar-circle">
                        <i class="fas fa-user-circle"></i>
                    </span>
                    <span class="user-meta-info">
                        <span class="user-display-name"><?php echo e($_SESSION['user_name'] ?? 'Admin'); ?></span>
                        <small class="user-display-role"><?php echo e(ucfirst($_SESSION['user_role'] ?? 'Administrator')); ?></small>
                    </span>
                    <i class="fas fa-chevron-down user-dropdown-caret"></i>
                </button>
                <div class="user-dropdown-popover" id="userDropdownPopover" role="menu">
                    <div class="user-popover-header">
                        <div class="popover-avatar">
                            <i class="fas fa-user-circle"></i>
                        </div>
                        <div class="popover-details">
                            <span class="popover-name"><?php echo e($_SESSION['user_name'] ?? 'Admin'); ?></span>
                            <span class="popover-role"><?php echo e(ucfirst($_SESSION['user_role'] ?? 'Administrator')); ?></span>
                        </div>
                    </div>
                    <div class="user-popover-divider"></div>
                    <a href="<?php echo BASE_URL; ?>/admin/profile" class="user-popover-link" role="menuitem">
                        <i class="fas fa-user-cog"></i>
                        <span>Profile &amp; Settings</span>
                    </a>
                    <div class="user-popover-divider"></div>
                    <a href="<?php echo BASE_URL; ?>/logout" class="user-popover-link logout" role="menuitem">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </div>
        </div>
        <script nonce="<?php echo CSP_NONCE; ?>">
        (function() {
            function initUserDropdown() {
                var w = document.getElementById('userDropdownWrapper');
                var btn = document.getElementById('userDropdownToggle');
                if (!w || !btn) return;
                if (btn.dataset.dropdownBound) return;
                btn.dataset.dropdownBound = '1';
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var isOpen = w.classList.toggle('open');
                    btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                });
                document.addEventListener('click', function(e) {
                    if (!w.contains(e.target)) {
                        w.classList.remove('open');
                        btn.setAttribute('aria-expanded', 'false');
                    }
                });
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && w.classList.contains('open')) {
                        w.classList.remove('open');
                        btn.setAttribute('aria-expanded', 'false');
                    }
                });
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initUserDropdown);
            } else {
                initUserDropdown();
            }
        })();
        </script>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(0, 40, 85, 0.1);">
                    <i class="fas fa-user-graduate" style="color: #002855;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo e($stats['total_students']); ?></h3>
                    <p>Total Students</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(196, 30, 58, 0.1);">
                    <i class="fas fa-chalkboard-teacher" style="color: #c41e3a;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo e($stats['total_teachers']); ?></h3>
                    <p>Total Teachers</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(255, 215, 0, 0.1);">
                    <i class="fas fa-school" style="color: #ffd700;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo e($stats['total_classes']); ?></h3>
                    <p>Active Classes</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(0, 128, 0, 0.1);">
                    <i class="fas fa-clock" style="color: #008000;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo e($stats['pending_admissions']); ?></h3>
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
                    <h3><?php echo e($stats['today_attendance']); ?></h3>
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

        <!-- Class Distribution & Enrollment -->
        <?php if (!empty($classDistribution)): ?>
        <div class="card mt-4 class-distribution-card" style="margin-top:24px; border:1px solid #e2e8f0; border-radius:14px; box-shadow:0 1px 4px rgba(0,33,71,0.06); background:#ffffff; overflow:hidden;">
            <div class="card-header" style="padding:16px 20px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
                <div style="display:flex; align-items:center; gap:12px;">
                    <div style="width:38px; height:38px; border-radius:10px; background:rgba(0,33,71,0.08); color:#002147; display:flex; align-items:center; justify-content:center; font-size:17px;">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <div>
                        <h3 style="margin:0; font-size:1.15rem; font-weight:700; color:#0f172a;">Class Distribution &amp; Enrollment</h3>
                        <p style="margin:2px 0 0; font-size:0.8rem; color:#64748b;">Active classes, student enrollment, and capacity utilization</p>
                    </div>
                </div>
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <span style="display:inline-flex; align-items:center; gap:6px; background:#eff6ff; color:#1e40af; font-size:12px; font-weight:600; padding:5px 12px; border-radius:999px; border:1px solid #bfdbfe;">
                        <i class="fas fa-layer-group"></i> <?php echo count($classDistribution); ?> Classes
                    </span>
                    <span style="display:inline-flex; align-items:center; gap:6px; background:#f0fdf4; color:#166534; font-size:12px; font-weight:600; padding:5px 12px; border-radius:999px; border:1px solid #bbf7d0;">
                        <i class="fas fa-user-graduate"></i> <?php echo $totalClassStudents; ?> / <?php echo $totalClassCapacity; ?> Enrolled (<?php echo $overallFillPercent; ?>%)
                    </span>
                    <a href="classes" class="btn btn-sm btn-outline" style="font-size:12px; padding:5px 12px; border-radius:8px;">
                        <i class="fas fa-cog"></i> Manage Classes
                    </a>
                </div>
            </div>
            <div class="card-body" style="padding:20px;">
                <div class="class-distribution-grid" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap:16px;">
                    <?php foreach ($classDistribution as $class):
                        $capacity = max(1, (int)$class['capacity']);
                        $count = (int)$class['student_count'];
                        $pct = min(100, round(($count / $capacity) * 100));
                        $section = trim((string)($class['section'] ?? ''));
                        $availableSeats = max(0, $capacity - $count);
                    ?>
                    <div class="class-enrollment-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:16px; transition:all 0.2s ease; display:flex; flex-direction:column; gap:12px; box-shadow:0 1px 2px rgba(0,0,0,0.03);">
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                            <div style="display:flex; align-items:center; gap:8px;">
                                <div style="width:34px; height:34px; border-radius:8px; background:rgba(0,40,85,0.06); color:#002855; display:flex; align-items:center; justify-content:center; font-size:14px; font-weight:700;">
                                    <i class="fas fa-chalkboard"></i>
                                </div>
                                <div>
                                    <h4 style="margin:0; font-size:0.95rem; font-weight:700; color:#0f172a; line-height:1.2;">
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </h4>
                                    <?php if ($section !== '' && strtolower($section) !== 'null'): ?>
                                    <span style="display:inline-block; margin-top:3px; font-size:11px; font-weight:600; color:#0284c7; background:#e0f2fe; padding:1px 7px; border-radius:4px;">
                                        Section <?php echo htmlspecialchars($section); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div style="text-align:right;">
                                <span style="font-size:1.15rem; font-weight:800; color:<?php echo $count > 0 ? '#002855' : '#64748b'; ?>; line-height:1;">
                                    <?php echo $count; ?>
                                </span>
                                <span style="font-size:0.75rem; color:#94a3b8; font-weight:600;">/ <?php echo $capacity; ?></span>
                                <div style="font-size:11px; font-weight:700; color:<?php echo $pct >= 90 ? '#dc2626' : ($pct >= 50 ? '#d97706' : '#64748b'); ?>;">
                                    <?php echo $pct; ?>% full
                                </div>
                            </div>
                        </div>

                        <!-- Progress Bar (Zero-sliver on 0%) -->
                        <div style="width:100%; height:7px; background:#f1f5f9; border-radius:999px; overflow:hidden; position:relative;">
                            <?php if ($count > 0): ?>
                            <div style="height:100%; width:<?php echo $pct; ?>%; background:linear-gradient(90deg, #002855, #2563eb); border-radius:999px; transition:width 0.4s ease;"></div>
                            <?php endif; ?>
                        </div>

                        <div style="display:flex; align-items:center; justify-content:space-between; font-size:11.5px; padding-top:2px;">
                            <?php if ($pct >= 100): ?>
                                <span style="color:#dc2626; font-weight:600; display:inline-flex; align-items:center; gap:5px;">
                                    <i class="fas fa-circle" style="font-size:7px;"></i> Class Full
                                </span>
                            <?php elseif ($count > 0): ?>
                                <span style="color:#16a34a; font-weight:600; display:inline-flex; align-items:center; gap:5px;">
                                    <i class="fas fa-circle" style="font-size:7px;"></i> <?php echo $availableSeats; ?> seats available
                                </span>
                            <?php else: ?>
                                <span style="color:#94a3b8; font-weight:500; display:inline-flex; align-items:center; gap:5px;">
                                    <i class="far fa-circle" style="font-size:7px;"></i> All <?php echo $capacity; ?> seats open
                                </span>
                            <?php endif; ?>

                            <a href="students?class_id=<?php echo (int)$class['id']; ?>" style="color:#2563eb; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                                View <i class="fas fa-chevron-right" style="font-size:9px;"></i>
                            </a>
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

<script nonce="<?php echo CSP_NONCE; ?>">
// Attendance Chart
<?php if (!empty($attendanceData)): ?>
const attendanceCtx = document.getElementById('attendanceChart')?.getContext('2d');
if (attendanceCtx) {
    new Chart(attendanceCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_column($attendanceData, 'date'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            datasets: [{
                label: 'Present',
                data: <?php echo json_encode(array_column($attendanceData, 'present'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                borderColor: '#28a745',
                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                tension: 0.4,
                fill: true
            }, {
                label: 'Absent',
                data: <?php echo json_encode(array_column($attendanceData, 'absent'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                borderColor: '#dc3545',
                backgroundColor: 'rgba(220, 53, 69, 0.1)',
                tension: 0.4,
                fill: true
            }, {
                label: 'Late',
                data: <?php echo json_encode(array_column($attendanceData, 'late'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
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
            labels: <?php echo json_encode(array_column($feeData, 'month'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            datasets: [{
                label: 'Fee Collection (₦)',
                data: <?php echo json_encode(array_column($feeData, 'total'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
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

/* Modern Class Distribution & Enrollment Styles */
.class-distribution-card {
    transition: box-shadow 0.2s ease;
}

.class-distribution-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
}

.class-enrollment-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px;
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    display: flex;
    flex-direction: column;
    gap: 12px;
    box-shadow: 0 1px 3px rgba(0, 33, 71, 0.04);
}

.class-enrollment-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 33, 71, 0.08);
    border-color: #cbd5e1;
}

@media (max-width: 768px) {
    .class-distribution-grid {
        grid-template-columns: 1fr !important;
        gap: 12px !important;
    }
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

<?php include __DIR__ . '/../includes/footer.php'; ?>
