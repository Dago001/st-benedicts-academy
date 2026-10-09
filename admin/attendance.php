<?php
// admin/attendance.php - Attendance Management
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';

Security::requireRole('admin');

$pageTitle = 'Attendance Management';
$extraJS = ['attendance.js', 'charts.js'];

include __DIR__ . '/../includes/header.php';

// Get database instance
$db = Database::getInstance();

[$message, $messageType] = flash_get();

// Get filters with default values
$classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$date = valid_date($_GET['date'] ?? '') ?? date('Y-m-d');
$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

// Get classes for filter (only active classes)
$classes = $db->getRows(
    "SELECT * FROM classes WHERE is_active = 1 ORDER BY class_name, section"
);

// Get attendance summary for selected date
$summary = $db->getRow(
    "SELECT
        COUNT(DISTINCT student_id) as total_students,
        COALESCE(SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END), 0) as present_count,
        COALESCE(SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END), 0) as absent_count,
        COALESCE(SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END), 0) as late_count,
        COALESCE(SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END), 0) as excused_count
     FROM attendance
     WHERE date = ?",
    [$date]
);

// If no summary data, provide defaults
if (!$summary) {
    $summary = [
        'total_students' => 0,
        'present_count' => 0,
        'absent_count' => 0,
        'late_count' => 0,
        'excused_count' => 0
    ];
}

// Get monthly statistics
$monthlyStats = $db->getRows(
    "SELECT
        date,
        COUNT(DISTINCT student_id) as total_students,
        COALESCE(SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END), 0) as present,
        COALESCE(SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END), 0) as absent,
        COALESCE(SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END), 0) as late,
        COALESCE(SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END), 0) as excused
     FROM attendance
     WHERE DATE_FORMAT(date, '%Y-%m') = ?
     GROUP BY date
     ORDER BY date",
    [$month]
);

// Get class-wise attendance for selected date (aggregated per class so joins cannot multiply counts)
$classAttendance = $db->getRows(
    "SELECT
        c.id,
        c.class_name,
        c.section,
        (SELECT COUNT(*) FROM students s JOIN users u ON s.user_id = u.id
          WHERE s.class_id = c.id AND u.is_active = 1) AS total_students,
        COUNT(a.id) AS marked,
        COALESCE(SUM(a.status = 'present'), 0) AS present,
        COALESCE(SUM(a.status = 'absent'), 0) AS absent,
        COALESCE(SUM(a.status = 'late'), 0) AS late,
        COALESCE(SUM(a.status = 'excused'), 0) AS excused
     FROM classes c
     LEFT JOIN attendance a ON a.class_id = c.id AND a.date = ?
     WHERE c.is_active = 1
     GROUP BY c.id, c.class_name, c.section
     ORDER BY c.class_name, c.section",
    [$date]
);

// Get overall attendance statistics for the month
$monthlySummary = $db->getRow(
    "SELECT
        COUNT(DISTINCT date) as school_days,
        COUNT(DISTINCT student_id) as total_students,
        COALESCE(SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END), 0) as total_present,
        COALESCE(SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END), 0) as total_absent,
        COALESCE(SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END), 0) as total_late
     FROM attendance
     WHERE DATE_FORMAT(date, '%Y-%m') = ?",
    [$month]
);

// Calculate average daily attendance
$avgDailyAttendance = 0;
if ($monthlySummary && $monthlySummary['school_days'] > 0) {
    $avgDailyAttendance = round($monthlySummary['total_present'] / $monthlySummary['school_days'], 1);
}
?>

<div class="dashboard-container">
    <?php render_sidebar('admin'); ?>

    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>Attendance Management</h1>
            <div class="header-actions">
                <a href="mark-attendance.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Mark Attendance
                </a>
            </div>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?php echo e($messageType); ?> alert-dismissible">
            <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="close" onclick="this.parentElement.remove()">&times;</button>
        </div>
        <?php endif; ?>

        <!-- Filter Form -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-filter"></i> Filter Attendance</h3>
            </div>
            <div class="card-body">
                <form method="GET" class="form-row">
                    <div class="form-group col-md-3">
                        <label for="date">Select Date:</label>
                        <input type="date" id="date" name="date" class="form-control"
                               value="<?php echo htmlspecialchars($date); ?>"
                               max="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="form-group col-md-3">
                        <label for="month">Select Month:</label>
                        <input type="month" id="month" name="month" class="form-control"
                               value="<?php echo htmlspecialchars($month); ?>"
                               max="<?php echo date('Y-m'); ?>">
                    </div>

                    <div class="form-group col-md-2">
                        <label for="class_id">Class (Optional):</label>
                        <select id="class_id" name="class_id" class="form-control">
                            <option value="0">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo e($class['id']); ?>" <?php echo $classId == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name'] . ' ' . $class['section']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-md-2">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary form-control">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                    </div>

                    <div class="form-group col-md-2">
                        <label>&nbsp;</label>
                        <a href="attendance.php" class="btn btn-outline form-control">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Today's Summary -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(0, 40, 85, 0.1);">
                    <i class="fas fa-users" style="color: #002855;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo e($summary['total_students']); ?></h3>
                    <p>Total Students</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(40, 167, 69, 0.1);">
                    <i class="fas fa-check-circle" style="color: #28a745;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo e($summary['present_count']); ?></h3>
                    <p>Present</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(220, 53, 69, 0.1);">
                    <i class="fas fa-times-circle" style="color: #dc3545;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo e($summary['absent_count']); ?></h3>
                    <p>Absent</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(255, 193, 7, 0.1);">
                    <i class="fas fa-clock" style="color: #ffc107;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo e($summary['late_count']); ?></h3>
                    <p>Late</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(23, 162, 184, 0.1);">
                    <i class="fas fa-calendar-check" style="color: #17a2b8;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo round(($summary['present_count'] / max($summary['total_students'], 1)) * 100, 1); ?>%</h3>
                    <p>Attendance Rate</p>
                </div>
            </div>
        </div>

        <!-- Monthly Summary Stats -->
        <?php if ($monthlySummary && $monthlySummary['school_days'] > 0): ?>
        <div class="stats-grid secondary">
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(108, 117, 125, 0.1);">
                    <i class="fas fa-calendar-alt" style="color: #6c757d;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo e($monthlySummary['school_days']); ?></h3>
                    <p>School Days</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(40, 167, 69, 0.1);">
                    <i class="fas fa-chart-line" style="color: #28a745;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo e($avgDailyAttendance); ?></h3>
                    <p>Avg. Daily Present</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(255, 193, 7, 0.1);">
                    <i class="fas fa-percent" style="color: #ffc107;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo round(($monthlySummary['total_present'] / max($monthlySummary['total_students'] * $monthlySummary['school_days'], 1)) * 100, 1); ?>%</h3>
                    <p>Monthly Rate</p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Attendance Chart -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-chart-line"></i> Monthly Attendance Overview - <?php echo date('F Y', strtotime($month . '-01')); ?></h3>
                <?php if (empty($monthlyStats)): ?>
                <span class="badge badge-warning">No data for this month</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!empty($monthlyStats)): ?>
                <canvas id="attendanceChart" width="400" height="200"></canvas>
                <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-chart-line fa-3x mb-3"></i>
                    <p>No attendance data available for <?php echo date('F Y', strtotime($month . '-01')); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Class-wise Attendance -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-school"></i> Class-wise Attendance for <?php echo date('d M, Y', strtotime($date)); ?></h3>
                <a href="mark-attendance.php?date=<?php echo e($date); ?>" class="btn btn-sm btn-primary">
                    <i class="fas fa-edit"></i> Mark Attendance
                </a>
            </div>
            <div class="card-body">
                <?php if (!empty($classAttendance)): ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Class</th>
                                <th>Total Students</th>
                                <th>Marked</th>
                                <th>Present</th>
                                <th>Absent</th>
                                <th>Late</th>
                                <th>Excused</th>
                                <th>Attendance %</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($classAttendance as $class): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($class['class_name'] . ' ' . $class['section']); ?></strong></td>
                                <td><?php echo e($class['total_students']); ?></td>
                                <td>
                                    <?php if ($class['marked'] > 0): ?>
                                    <span class="badge badge-success"><?php echo e($class['marked']); ?> marked</span>
                                    <?php else: ?>
                                    <span class="badge badge-warning">Not Marked</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-success"><?php echo e($class['present']); ?></td>
                                <td class="text-danger"><?php echo e($class['absent']); ?></td>
                                <td class="text-warning"><?php echo e($class['late']); ?></td>
                                <td class="text-info"><?php echo e($class['excused']); ?></td>
                                <td>
                                    <?php
                                    if ($class['total_students'] > 0) {
                                        $percentage = (($class['present'] + $class['late']) / $class['total_students']) * 100;
                                        $rateClass = $percentage >= 90 ? 'text-success' : ($percentage >= 75 ? 'text-warning' : 'text-danger');
                                        echo '<span class="' . $rateClass . ' font-weight-bold">' . number_format($percentage, 1) . '%</span>';
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <a href="attendance-detail.php?class_id=<?php echo e($class['id']); ?>&date=<?php echo urlencode($date); ?>"
                                       class="btn btn-sm btn-outline">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <?php if ($class['marked'] == 0): ?>
                                    <a href="mark-attendance.php?class=<?php echo e($class['id']); ?>&date=<?php echo urlencode($date); ?>"
                                       class="btn btn-sm btn-primary">
                                        <i class="fas fa-edit"></i> Mark
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    No classes found. Please <a href="classes.php?action=add">create a class</a> first.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Export Options -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-download"></i> Export Reports</h3>
            </div>
            <div class="card-body">
                <div class="export-options">
                    <a href="print-attendance.php?month=<?php echo urlencode($month); ?>&date=<?php echo urlencode($date); ?>"
                       class="btn btn-outline" target="_blank" rel="noopener">
                        <i class="fas fa-file-pdf"></i> Save as PDF
                    </a>
                    <a href="export.php?type=attendance&month=<?php echo urlencode($month); ?>&date=<?php echo urlencode($date); ?>"
                       class="btn btn-outline">
                        <i class="fas fa-file-excel"></i> Export as Excel
                    </a>
                    <a href="export.php?type=attendance&month=<?php echo urlencode($month); ?>&date=<?php echo urlencode($date); ?>"
                       class="btn btn-outline">
                        <i class="fas fa-file-csv"></i> Export as CSV
                    </a>
                    <a href="print-attendance.php?month=<?php echo urlencode($month); ?>&date=<?php echo urlencode($date); ?>"
                       class="btn btn-outline" target="_blank">
                        <i class="fas fa-print"></i> Print Report
                    </a>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
<?php if (!empty($monthlyStats)): ?>
// Attendance Chart
const ctx = document.getElementById('attendanceChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode(array_map(function($item) {
            return date('d M', strtotime($item['date']));
        }, $monthlyStats)); ?>,
        datasets: [
            {
                label: 'Present',
                data: <?php echo json_encode(array_column($monthlyStats, 'present'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                borderColor: '#28a745',
                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                tension: 0.4,
                fill: true
            },
            {
                label: 'Absent',
                data: <?php echo json_encode(array_column($monthlyStats, 'absent'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                borderColor: '#dc3545',
                backgroundColor: 'rgba(220, 53, 69, 0.1)',
                tension: 0.4,
                fill: true
            },
            {
                label: 'Late',
                data: <?php echo json_encode(array_column($monthlyStats, 'late'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                borderColor: '#ffc107',
                backgroundColor: 'rgba(255, 193, 7, 0.1)',
                tension: 0.4,
                fill: true
            }
        ]
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
        },
        plugins: {
            tooltip: {
                mode: 'index',
                intersect: false
            },
            legend: {
                position: 'bottom'
            }
        }
    }
});
<?php endif; ?>
</script>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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

.export-options {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
}

.export-options .btn {
    min-width: 160px;
}

.text-success { color: #28a745; font-weight: 600; }
.text-danger { color: #dc3545; font-weight: 600; }
.text-warning { color: #ffc107; font-weight: 600; }
.text-info { color: #17a2b8; font-weight: 600; }

.font-weight-bold {
    font-weight: 700;
}

.badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}

.badge-success {
    background-color: #d4edda;
    color: #155724;
}

.badge-warning {
    background-color: #fff3cd;
    color: #856404;
}

.badge-info {
    background-color: #d1ecf1;
    color: #0c5460;
}

.btn-sm {
    padding: 5px 10px;
    font-size: 12px;
    border-radius: 4px;
}

.btn-outline {
    background: transparent;
    border: 1px solid var(--navy);
    color: var(--navy);
    padding: 8px 15px;
    border-radius: 5px;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.btn-outline:hover {
    background: var(--navy);
    color: white;
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

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }

    .export-options {
        flex-direction: column;
    }

    .export-options .btn {
        width: 100%;
    }
}
</style>

