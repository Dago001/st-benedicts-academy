<?php
// teacher/attendance-report.php - attendance summary per student for a class and date range
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/export.php';
Security::requireRole('teacher');

$db = db();
$tid = Security::currentTeacherId() ?? 0;
$classes = $db->getRows(
    "SELECT id, class_name, section FROM classes WHERE is_active = 1 AND id IN (SELECT id FROM classes WHERE teacher_id = ? UNION SELECT class_id FROM subjects WHERE teacher_id = ?) ORDER BY class_name, section", [$tid, $tid]);
$classId = (int)($_GET['class_id'] ?? ($classes[0]['id'] ?? 0));
$from = valid_date($_GET['from'] ?? '') ?? date('Y-m-01');
$to = valid_date($_GET['to'] ?? '') ?? date('Y-m-d');
if ($from > $to) { [$from, $to] = [$to, $from]; }

$rows = [];
if ($classId && Security::canAccessClass($classId)) {
    $rows = $db->getRows(
        "SELECT s.admission_number, u.first_name, u.last_name,
                COALESCE(SUM(a.status='present'),0) present, COALESCE(SUM(a.status='absent'),0) absent,
                COALESCE(SUM(a.status='late'),0) late, COALESCE(SUM(a.status='excused'),0) excused, COUNT(a.id) total
         FROM students s JOIN users u ON s.user_id = u.id
         LEFT JOIN attendance a ON a.student_id = s.id AND a.date BETWEEN ? AND ?
         WHERE s.class_id = ? AND u.is_active = 1 GROUP BY s.id, s.admission_number, u.first_name, u.last_name ORDER BY u.first_name, u.last_name",
        [$from, $to, $classId]);
} elseif ($classId) {
    $classId = 0;
}

if (isset($_GET['csv']) && $rows) {
    csv_download("attendance-$from-to-$to.csv", ['Admission No', 'First Name', 'Last Name', 'Present', 'Absent', 'Late', 'Excused', 'Records'],
        array_map(function ($r) { return [$r['admission_number'], $r['first_name'], $r['last_name'], $r['present'], $r['absent'], $r['late'], $r['excused'], $r['total']]; }, $rows));
}

$pageTitle = 'Attendance Report';
$extraCSS = ['dashboard.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('teacher', 'Attendance Report', '<a href="attendance" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>');
?>
<form method="GET" class="card"><div class="card-body form-row">
    <div class="form-group"><label for="class_id">Class</label><select id="class_id" name="class_id" class="form-control">
        <?php foreach ($classes as $c): ?><option value="<?php echo (int)$c['id']; ?>" <?php echo $c['id'] == $classId ? 'selected' : ''; ?>><?php echo e(trim($c['class_name'] . ' ' . $c['section'])); ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label for="from">From</label><input type="date" id="from" name="from" class="form-control" value="<?php echo e($from); ?>"></div>
    <div class="form-group"><label for="to">To</label><input type="date" id="to" name="to" class="form-control" value="<?php echo e($to); ?>"></div>
    <div class="form-group form-actions"><button class="btn btn-primary" type="submit">Show</button>
        <?php if ($rows): ?><button class="btn btn-outline" type="submit" name="csv" value="1">CSV</button><?php endif; ?></div>
</div></form>
<div class="card"><div class="card-body"><div class="table-responsive"><table class="table"><thead><tr><th>Admission No</th><th>Student</th><th>Present</th><th>Absent</th><th>Late</th><th>Excused</th><th>Rate</th></tr></thead><tbody>
<?php foreach ($rows as $r): $rate = $r['total'] > 0 ? round(($r['present'] + $r['late']) / $r['total'] * 100) . '%' : '-'; ?>
<tr><td><?php echo e($r['admission_number']); ?></td><td><?php echo e($r['first_name'] . ' ' . $r['last_name']); ?></td><td><?php echo (int)$r['present']; ?></td><td><?php echo (int)$r['absent']; ?></td><td><?php echo (int)$r['late']; ?></td><td><?php echo (int)$r['excused']; ?></td><td><?php echo e($rate); ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted">No data for this selection.</td></tr><?php endif; ?>
</tbody></table></div></div></div>
<?php dashboard_close(); include __DIR__ . '/../includes/footer.php';
