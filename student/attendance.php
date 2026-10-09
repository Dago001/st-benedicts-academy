<?php
// student/attendance.php - View My Attendance
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';

Security::requireRole('student');

$pageTitle = 'My Attendance';
$extraCSS = ['dashboard.css'];
$extraJS = ['charts.js'];

include __DIR__ . '/../includes/header.php';

// Get database instance
try {
    $db = Database::getInstance();
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Database connection error: ' . htmlspecialchars(DEBUG_MODE ? $e->getMessage() : 'Please try again later.') . '</div>';
    // Don't include footer - just exit
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

// Get date range with validation
$month = isset($_GET['month']) ? str_pad((int)$_GET['month'], 2, '0', STR_PAD_LEFT) : date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Validate month and year
if ($month < 1 || $month > 12) $month = date('m');
if ($year < 2000 || $year > date('Y') + 1) $year = date('Y');

$startDate = "$year-$month-01";
$endDate = date('Y-m-t', strtotime($startDate));

// Get attendance records
$attendanceRecords = $db->getRows(
    "SELECT a.*, c.class_name
     FROM attendance a
     JOIN classes c ON a.class_id = c.id
     WHERE a.student_id = ? AND a.date BETWEEN ? AND ?
     ORDER BY a.date DESC",
    [$student['id'], $startDate, $endDate]
);

// Get attendance summary
$summary = $db->getRow(
    "SELECT
        COALESCE(COUNT(*), 0) as total_days,
        COALESCE(SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END), 0) as present,
        COALESCE(SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END), 0) as absent,
        COALESCE(SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END), 0) as late,
        COALESCE(SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END), 0) as excused
     FROM attendance
     WHERE student_id = ? AND date BETWEEN ? AND ?",
    [$student['id'], $startDate, $endDate]
);

// Ensure summary has default values if null
if (!$summary) {
    $summary = [
        'total_days' => 0,
        'present' => 0,
        'absent' => 0,
        'late' => 0,
        'excused' => 0
    ];
}

// Get monthly statistics for chart (last 6 months)
$monthlyStats = $db->getRows(
    "SELECT
        DATE_FORMAT(date, '%Y-%m') as month,
        COUNT(*) as total,
        COALESCE(SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END), 0) as present,
        COALESCE(SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END), 0) as absent,
        COALESCE(SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END), 0) as late
     FROM attendance
     WHERE student_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
     GROUP BY DATE_FORMAT(date, '%Y-%m')
     ORDER BY month",
    [$student['id']]
);

// Calculate attendance rate
$attendanceRate = $summary['total_days'] > 0
    ? round((($summary['present'] + $summary['late']) / $summary['total_days']) * 100, 1)
    : 0;

// Get color class for attendance rate
$rateColorClass = 'text-danger';
if ($attendanceRate >= 90) {
    $rateColorClass = 'text-success';
} elseif ($attendanceRate >= 75) {
    $rateColorClass = 'text-info';
}

// Get all available months/years for dropdown
$availableMonths = [];
for ($m = 1; $m <= 12; $m++) {
    $availableMonths[str_pad($m, 2, '0', STR_PAD_LEFT)] = date('F', mktime(0, 0, 0, $m, 1));
}
?>

<style>
/* Attendance page specific styles */
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
    border-bottom-color: #ffd700;
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
    color: #002855;
    line-height: 1.2;
}

.stat-content p {
    margin: 5px 0 0;
    color: #6c757d;
    font-size: 14px;
}

/* Attendance rate circle */
.attendance-rate {
    display: flex;
    align-items: center;
    gap: 40px;
    padding: 20px;
    flex-wrap: wrap;
}

.rate-circle {
    flex-shrink: 0;
    margin: 0 auto;
}

.circle-progress {
    width: 180px;
    height: 180px;
    border-radius: 50%;
    background: #f8f9fa;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    box-shadow: 0 10px 25px rgba(0,0,0,0.1);
}

.circle-progress::before {
    content: '';
    position: absolute;
    top: 15px;
    left: 15px;
    right: 15px;
    bottom: 15px;
    border-radius: 50%;
    background: white;
    z-index: 1;
}

.circle-progress::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    border-radius: 50%;
    background: conic-gradient(
        from 0deg,
        #28a745 0deg <?php echo $attendanceRate * 3.6; ?>deg,
        #dc3545 <?php echo $attendanceRate * 3.6; ?>deg 360deg
    );
    mask: radial-gradient(circle at 30% 30%, transparent 68%, black 69%);
    -webkit-mask: radial-gradient(circle at 30% 30%, transparent 68%, black 69%);
}

.percentage {
    position: relative;
    z-index: 2;
    font-size: 2.5rem;
    font-weight: 700;
    color: #002855;
    text-align: center;
    line-height: 1.2;
}

.percentage small {
    display: block;
    font-size: 0.9rem;
    font-weight: 400;
    color: #6c757d;
}

.rate-details {
    flex: 1;
    min-width: 250px;
}

.rate-details p {
    font-size: 1.1rem;
    margin-bottom: 15px;
}

.rate-details i {
    margin-right: 8px;
}

.text-success { color: #28a745; }
.text-info { color: #17a2b8; }
.text-danger { color: #dc3545; }

/* Badge styles */
.badge {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
}

.badge-success {
    background-color: #d4edda;
    color: #155724;
}

.badge-success i {
    color: #28a745;
}

.badge-danger {
    background-color: #f8d7da;
    color: #721c24;
}

.badge-danger i {
    color: #dc3545;
}

.badge-warning {
    background-color: #fff3cd;
    color: #856404;
}

.badge-warning i {
    color: #ffc107;
}

.badge-info {
    background-color: #d1ecf1;
    color: #0c5460;
}

.badge-info i {
    color: #17a2b8;
}

/* Table styles */
.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th {
    background: #002855;
    color: white;
    padding: 12px;
    text-align: left;
    font-weight: 500;
}

.data-table td {
    padding: 12px;
    border-bottom: 1px solid #e9ecef;
}

.data-table tbody tr:hover {
    background-color: #f8f9fa;
}

/* Form styles */
.form-row {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    align-items: flex-end;
}

.form-group {
    flex: 1 1 200px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    color: #002855;
}

.form-control {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.form-control:focus {
    outline: none;
    border-color: #ffd700;
    box-shadow: 0 0 0 2px rgba(255, 215, 0, 0.2);
}

/* Card styles */
.card {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    margin-bottom: 30px;
    overflow: hidden;
}

.card-header {
    padding: 15px 20px;
    border-bottom: 1px solid #e9ecef;
    background: #f8f9fa;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
}

.card-header h3 {
    margin: 0;
    font-size: 18px;
    color: #002855;
    display: flex;
    align-items: center;
    gap: 8px;
}

.card-header h3 i {
    color: #ffd700;
}

.card-body {
    padding: 20px;
}

/* Alert styles */
.alert {
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.alert-info {
    background-color: #d1ecf1;
    color: #0c5460;
    border: 1px solid #bee5eb;
}

.alert-info i {
    color: #17a2b8;
}

/* Table responsive */
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
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
    color: #002855;
}

.user-info span {
    font-weight: 600;
    color: #002855;
}

.user-info small {
    color: #6c757d;
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
    color: #002855;
}

/* Responsive */
@media (max-width: 768px) {
    .attendance-rate {
        flex-direction: column;
        text-align: center;
    }

    .stats-grid {
        grid-template-columns: 1fr;
    }

    .dashboard-header {
        flex-direction: column;
        text-align: center;
    }

    .form-row {
        flex-direction: column;
    }

    .form-group {
        width: 100%;
    }

    .circle-progress {
        width: 150px;
        height: 150px;
    }

    .percentage {
        font-size: 2rem;
    }
}
</style>

<div class="dashboard-container">
    <?php render_sidebar('student'); ?>

    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>My Attendance</h1>
            <div class="user-info">
                <i class="fas fa-user-graduate"></i>
                <div>
                    <span><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></span>
                    <small><?php echo htmlspecialchars(($student['class_name'] ?? '') . ' ' . ($student['section'] ?? '')); ?></small>
                </div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(0,40,85,0.1);">
                    <i class="fas fa-calendar" style="color: #002855;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo e($summary['total_days']); ?></h3>
                    <p>Total School Days</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(40,167,69,0.1);">
                    <i class="fas fa-check-circle" style="color: #28a745;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo e($summary['present']); ?></h3>
                    <p>Present</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(255,193,7,0.1);">
                    <i class="fas fa-clock" style="color: #ffc107;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo e($summary['late']); ?></h3>
                    <p>Late</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(220,53,69,0.1);">
                    <i class="fas fa-times-circle" style="color: #dc3545;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo e($summary['absent']); ?></h3>
                    <p>Absent</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(23,162,184,0.1);">
                    <i class="fas fa-percent" style="color: #17a2b8;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo e($attendanceRate); ?>%</h3>
                    <p>Attendance Rate</p>
                </div>
            </div>
        </div>

        <!-- Attendance Rate Circle -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-chart-pie"></i> Attendance Rate - <?php echo date('F Y', strtotime($startDate)); ?></h3>
            </div>
            <div class="card-body">
                <div class="attendance-rate">
                    <div class="rate-circle">
                        <div class="circle-progress">
                            <span class="percentage">
                                <?php echo e($attendanceRate); ?>%
                                <small>attendance</small>
                            </span>
                        </div>
                    </div>
                    <div class="rate-details">
                        <p>
                            <i class="fas fa-info-circle"></i>
                            Your attendance rate for <strong><?php echo date('F Y', strtotime($startDate)); ?></strong> is
                            <strong class="<?php echo e($rateColorClass); ?>"><?php echo e($attendanceRate); ?>%</strong>
                        </p>

                        <?php if ($attendanceRate >= 90): ?>
                        <p class="text-success">
                            <i class="fas fa-star"></i>
                            Excellent attendance! Keep up the great work!
                        </p>
                        <div class="progress" style="height: 10px; margin-top: 10px;">
                            <div class="progress-bar bg-success" style="width: <?php echo e($attendanceRate); ?>%;"></div>
                        </div>
                        <?php elseif ($attendanceRate >= 75): ?>
                        <p class="text-info">
                            <i class="fas fa-thumbs-up"></i>
                            Good attendance, but there's room for improvement.
                        </p>
                        <div class="progress" style="height: 10px; margin-top: 10px;">
                            <div class="progress-bar bg-info" style="width: <?php echo e($attendanceRate); ?>%;"></div>
                        </div>
                        <?php else: ?>
                        <p class="text-danger">
                            <i class="fas fa-exclamation-triangle"></i>
                            Your attendance needs improvement. Regular attendance is crucial for academic success.
                        </p>
                        <div class="progress" style="height: 10px; margin-top: 10px;">
                            <div class="progress-bar bg-danger" style="width: <?php echo e($attendanceRate); ?>%;"></div>
                        </div>
                        <?php endif; ?>

                        <div style="margin-top: 15px; display: flex; gap: 20px; flex-wrap: wrap;">
                            <div><span class="badge badge-success">Present: <?php echo e($summary['present']); ?></span></div>
                            <div><span class="badge badge-warning">Late: <?php echo e($summary['late']); ?></span></div>
                            <div><span class="badge badge-danger">Absent: <?php echo e($summary['absent']); ?></span></div>
                            <div><span class="badge badge-info">Excused: <?php echo e($summary['excused']); ?></span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Month/Year Selector -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-filter"></i> Select Month</h3>
            </div>
            <div class="card-body">
                <form method="GET" class="form-row">
                    <div class="form-group">
                        <label for="month">Month</label>
                        <select id="month" name="month" class="form-control" onchange="this.form.submit()">
                            <?php foreach ($availableMonths as $monthNum => $monthName): ?>
                            <option value="<?php echo e($monthNum); ?>"
                                <?php echo $month == $monthNum ? 'selected' : ''; ?>>
                                <?php echo e($monthName); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="year">Year</label>
                        <select id="year" name="year" class="form-control" onchange="this.form.submit()">
                            <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                            <option value="<?php echo e($y); ?>" <?php echo $year == $y ? 'selected' : ''; ?>>
                                <?php echo e($y); ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>&nbsp;</label>
                        <a href="attendance" class="btn btn-outline" style="padding: 8px 20px; display: inline-block; background: #f8f9fa; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #333;">
                            <i class="fas fa-redo"></i> Current Month
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Daily Attendance Records -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-calendar-alt"></i> Daily Attendance - <?php echo date('F Y', strtotime($startDate)); ?></h3>
                <?php if (!empty($attendanceRecords)): ?>
                <span class="badge badge-info"><?php echo count($attendanceRecords); ?> records</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!empty($attendanceRecords)): ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Day</th>
                                <th>Class</th>
                                <th>Status</th>
                                <th>Time</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attendanceRecords as $record):
                                $statusClass = '';
                                $statusIcon = '';

                                switch($record['status']) {
                                    case 'present':
                                        $statusClass = 'badge-success';
                                        $statusIcon = 'fa-check-circle';
                                        break;
                                    case 'absent':
                                        $statusClass = 'badge-danger';
                                        $statusIcon = 'fa-times-circle';
                                        break;
                                    case 'late':
                                        $statusClass = 'badge-warning';
                                        $statusIcon = 'fa-clock';
                                        break;
                                    case 'excused':
                                        $statusClass = 'badge-info';
                                        $statusIcon = 'fa-check';
                                        break;
                                }
                            ?>
                            <tr>
                                <td><strong><?php echo date('d/m/Y', strtotime($record['date'])); ?></strong></td>
                                <td><?php echo date('l', strtotime($record['date'])); ?></td>
                                <td><?php echo htmlspecialchars($record['class_name']); ?></td>
                                <td>
                                    <span class="badge <?php echo e($statusClass); ?>">
                                        <i class="fas <?php echo e($statusIcon); ?>"></i>
                                        <?php echo e(ucfirst($record['status'])); ?>
                                    </span>
                                </td>
                                <td><?php echo isset($record['created_at']) ? date('h:i A', strtotime($record['created_at'])) : '-'; ?></td>
                                <td><?php echo htmlspecialchars($record['remarks'] ?? '-'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    No attendance records found for <?php echo date('F Y', strtotime($startDate)); ?>.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Attendance Trend Chart -->
        <?php if (!empty($monthlyStats) && count($monthlyStats) > 1): ?>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-chart-line"></i> Attendance Trend (Last 6 Months)</h3>
            </div>
            <div class="card-body">
                <canvas id="attendanceChart" height="300"></canvas>
            </div>
        </div>
        <?php endif; ?>

        <!-- Monthly Summary Table -->
        <?php if (!empty($monthlyStats)): ?>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-table"></i> Monthly Summary</h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Present</th>
                                <th>Absent</th>
                                <th>Late</th>
                                <th>Total</th>
                                <th>Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($monthlyStats as $stat):
                                $monthRate = $stat['total'] > 0 ? round(($stat['present'] / $stat['total']) * 100, 1) : 0;
                                $monthColor = $monthRate >= 90 ? 'text-success' : ($monthRate >= 75 ? 'text-info' : 'text-danger');
                            ?>
                            <tr>
                                <td><strong><?php echo date('F Y', strtotime($stat['month'] . '-01')); ?></strong></td>
                                <td class="text-success"><?php echo e($stat['present']); ?></td>
                                <td class="text-danger"><?php echo e($stat['absent']); ?></td>
                                <td class="text-warning"><?php echo e($stat['late']); ?></td>
                                <td><?php echo e($stat['total']); ?></td>
                                <td class="<?php echo e($monthColor); ?> font-weight-bold"><?php echo e($monthRate); ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </main>
</div>

<script>
<?php if (!empty($monthlyStats) && count($monthlyStats) > 1): ?>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('attendanceChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_map(function($item) {
                return date('M Y', strtotime($item['month'] . '-01'));
            }, $monthlyStats)); ?>,
            datasets: [
                {
                    label: 'Present',
                    data: <?php echo json_encode(array_column($monthlyStats, 'present'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#28a745',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5
                },
                {
                    label: 'Absent',
                    data: <?php echo json_encode(array_column($monthlyStats, 'absent'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#dc3545',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5
                },
                {
                    label: 'Late',
                    data: <?php echo json_encode(array_column($monthlyStats, 'late'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                    borderColor: '#ffc107',
                    backgroundColor: 'rgba(255, 193, 7, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#ffc107',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        padding: 20
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Number of Days'
                    },
                    grid: {
                        color: 'rgba(0,0,0,0.05)'
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
});
<?php endif; ?>

// Auto-hide alerts after 5 seconds
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        alert.style.transition = 'opacity 0.5s ease';
        alert.style.opacity = '0';
        setTimeout(function() {
            if (alert.parentNode) {
                alert.remove();
            }
        }, 500);
    });
}, 5000);
</script>

<?php
// NO FOOTER INCLUDED - As requested
?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
