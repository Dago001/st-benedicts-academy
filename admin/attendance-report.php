<?php
// admin/attendance-report.php - Comprehensive Attendance Analytics & Reports
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/SystemSchema.php';

Security::requireRole(['super_admin', 'admin', 'head_teacher', 'supervisor']);
SystemSchema::ensureAll();

$db = db();
[$message, $messageType] = flash_get();

$allClasses = $db->getRows("SELECT id, class_name, section, academic_year FROM classes WHERE is_active = 1 ORDER BY class_name, section");

$classId = (int)($_GET['class_id'] ?? ($allClasses[0]['id'] ?? 0));
$month   = trim((string)($_GET['month'] ?? date('Y-m')));

$students = [];
$attendanceSummary = [];

if ($classId > 0) {
    $students = $db->getRows(
        "SELECT s.id, s.admission_number, u.first_name, u.last_name, s.gender
         FROM students s
         JOIN users u ON s.user_id = u.id
         WHERE s.class_id = ? AND u.is_active = 1
         ORDER BY u.first_name, u.last_name",
        [$classId]
    );

    // Get attendance counts for this month
    foreach ($students as &$st) {
        $stats = $db->getRow(
            "SELECT COUNT(*) as total_days,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
                    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days
             FROM attendance
             WHERE student_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?",
            [$st['id'], $month]
        );
        $st['total_days'] = (int)($stats['total_days'] ?? 0);
        $st['present_days'] = (int)($stats['present_days'] ?? 0);
        $st['absent_days'] = (int)($stats['absent_days'] ?? 0);
        $st['late_days'] = (int)($stats['late_days'] ?? 0);
        $st['rate'] = ($st['total_days'] > 0)
            ? round(($st['present_days'] / $st['total_days']) * 100, 1)
            : 100;
    }
    unset($st);
}

$pageTitle = 'Attendance Report';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('admin', 'Attendance Report');
render_alert($message, $messageType);
?>

<div class="card" style="margin-bottom:20px;">
    <div class="card-body">
        <form method="GET" class="form-inline" style="display:flex; gap:14px; align-items:center; flex-wrap:wrap;">
            <div class="form-group" style="margin-bottom:0;">
                <label for="class_id"><strong>Select Class:</strong></label>
                <select name="class_id" id="class_id" class="form-control" onchange="this.form.submit()">
                    <?php foreach ($allClasses as $c): ?>
                        <option value="<?php echo e($c['id']); ?>" <?php echo $classId === (int)$c['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['class_name'] . ($c['section'] ? ' ' . $c['section'] : '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:0;">
                <label for="month"><strong>Month:</strong></label>
                <input type="month" id="month" name="month" class="form-control" value="<?php echo e($month); ?>" onchange="this.form.submit()">
            </div>

            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Generate Report</button>
            <button type="button" onclick="window.print()" class="btn btn-outline"><i class="fas fa-print"></i> Print</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <h3><i class="fas fa-chart-line"></i> Monthly Attendance Sheet (<?php echo date('F Y', strtotime($month . '-01')); ?>)</h3>
        <span class="text-muted">Class Register Statistics</span>
    </div>
    <div class="card-body">
        <?php if (empty($students)): ?>
            <p class="text-muted">No students enrolled in this class.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Adm No.</th>
                            <th>Student Full Name</th>
                            <th>Gender</th>
                            <th>Recorded Days</th>
                            <th>Days Present</th>
                            <th>Days Absent</th>
                            <th>Late</th>
                            <th>Attendance %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $st): ?>
                        <tr>
                            <td><strong><?php echo e($st['admission_number']); ?></strong></td>
                            <td><strong><?php echo e($st['first_name'] . ' ' . $st['last_name']); ?></strong></td>
                            <td><?php echo ucfirst($st['gender']); ?></td>
                            <td><?php echo $st['total_days']; ?></td>
                            <td><span class="badge badge-success"><?php echo $st['present_days']; ?></span></td>
                            <td><span class="badge badge-danger"><?php echo $st['absent_days']; ?></span></td>
                            <td><span class="badge badge-warning"><?php echo $st['late_days']; ?></span></td>
                            <td>
                                <strong><?php echo $st['rate']; ?>%</strong>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
dashboard_close();
include __DIR__ . '/../includes/footer.php';
