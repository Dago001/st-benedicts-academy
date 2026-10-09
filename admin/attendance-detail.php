<?php
// admin/attendance-detail.php - attendance sheet for one class and date
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
Security::requireRole('admin');

$db = db();
$classId = (int)($_GET['class_id'] ?? 0);
$date = valid_date($_GET['date'] ?? '') ?? date('Y-m-d');
$class = $db->getRow('SELECT id, class_name, section FROM classes WHERE id = ?', [$classId]);
$pageTitle = 'Attendance Detail';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';
if (!$class) {
    dashboard_open('admin', 'Class not found');
    echo '<a class="btn btn-secondary" href="attendance.php">Back</a>';
    dashboard_close();
    include __DIR__ . '/../includes/footer.php';
    exit;
}
$rows = $db->getRows(
    "SELECT s.admission_number, u.first_name, u.last_name, a.status, a.remarks
     FROM students s JOIN users u ON s.user_id = u.id
     LEFT JOIN attendance a ON a.student_id = s.id AND a.date = ?
     WHERE s.class_id = ? AND u.is_active = 1 ORDER BY u.first_name, u.last_name", [$date, $classId]);
$counts = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'unmarked' => 0];
foreach ($rows as $r) { $counts[$r['status'] ?: 'unmarked']++; }

dashboard_open('admin', trim($class['class_name'] . ' ' . $class['section']) . ' attendance',
    '<a href="mark-attendance.php?class_id=' . $classId . '&date=' . urlencode($date) . '" class="btn btn-primary"><i class="fas fa-edit"></i> Mark / edit</a>'
    . '<a href="export.php?type=attendance&class_id=' . $classId . '&date=' . urlencode($date) . '" class="btn btn-outline"><i class="fas fa-download"></i> CSV</a>'
    . '<a href="attendance.php?date=' . urlencode($date) . '" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>');
?>
<form method="GET" class="card"><div class="card-body form-inline">
    <input type="hidden" name="class_id" value="<?php echo $classId; ?>">
    <label for="date">Date</label><input type="date" id="date" name="date" class="form-control" value="<?php echo e($date); ?>" max="<?php echo date('Y-m-d'); ?>" onchange="this.form.submit()">
</div></form>
<div class="stats-grid">
<?php foreach ($counts as $k => $n): ?><div class="stat-card"><div class="stat-content"><h3><?php echo (int)$n; ?></h3><p><?php echo e(ucfirst($k)); ?></p></div></div><?php endforeach; ?>
</div>
<div class="card"><div class="card-body"><div class="table-responsive"><table class="table"><thead><tr><th>#</th><th>Admission No</th><th>Student</th><th>Status</th><th>Remarks</th></tr></thead><tbody>
<?php foreach ($rows as $i => $r): ?><tr><td><?php echo $i + 1; ?></td><td><?php echo e($r['admission_number']); ?></td><td><?php echo e($r['first_name'] . ' ' . $r['last_name']); ?></td>
    <td><span class="badge <?php echo ['present' => 'badge-success', 'absent' => 'badge-danger', 'late' => 'badge-warning', 'excused' => 'badge-info'][$r['status']] ?? 'badge-secondary'; ?>"><?php echo e($r['status'] ? ucfirst($r['status']) : 'Not marked'); ?></span></td>
    <td><?php echo e($r['remarks']); ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted">No students in this class.</td></tr><?php endif; ?>
</tbody></table></div></div></div>
<?php dashboard_close(); include __DIR__ . '/../includes/footer.php';
