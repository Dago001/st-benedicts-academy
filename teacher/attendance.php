<?php
// teacher/attendance.php - mark / edit attendance for the teacher's classes
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
Security::requireRole('teacher');

$db = db();
$userId = (int)$_SESSION['user_id'];
$tid = Security::currentTeacherId() ?? 0;
[$message, $messageType] = flash_get();

$classes = $db->getRows(
    "SELECT c.id, c.class_name, c.section FROM classes c WHERE c.is_active = 1
       AND c.id IN (SELECT id FROM classes WHERE teacher_id = ? UNION SELECT class_id FROM subjects WHERE teacher_id = ?)
     ORDER BY c.class_name, c.section", [$tid, $tid]);

$selectedClass = (int)($_GET['class_id'] ?? $_GET['class'] ?? $_POST['class_id'] ?? 0);
$selectedDate = valid_date($_GET['date'] ?? $_POST['date'] ?? '') ?? date('Y-m-d');
if ($selectedDate > date('Y-m-d')) { $selectedDate = date('Y-m-d'); }
if ($selectedClass && !Security::canAccessClass($selectedClass, true)) {
    $selectedClass = 0;
    $message = 'You do not have access to that class';
    $messageType = 'error';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token';
        $messageType = 'error';
    } elseif (!$selectedClass) {
        $message = $message ?: 'Please choose a class';
        $messageType = 'error';
    } else {
        try {
            $count = save_attendance($selectedClass, $selectedDate,
                is_array($_POST['attendance'] ?? null) ? $_POST['attendance'] : [],
                is_array($_POST['remarks'] ?? null) ? $_POST['remarks'] : [], $userId);
            Security::logAudit('MARKED_ATTENDANCE', 'attendance', $selectedClass, null, ['date' => $selectedDate, 'count' => $count]);
            flash_redirect("Attendance saved for $count students", 'success',
                BASE_URL . '/teacher/attendance?' . http_build_query(['class_id' => $selectedClass, 'date' => $selectedDate]));
        } catch (Exception $e) {
            error_log('teacher attendance: ' . $e->getMessage());
            $message = 'Error saving attendance. Please try again.';
            $messageType = 'error';
        }
    }
}

$students = [];
$existing = [];
if ($selectedClass) {
    $students = $db->getRows(
        "SELECT s.id, s.admission_number, u.first_name, u.last_name FROM students s JOIN users u ON s.user_id = u.id
         WHERE s.class_id = ? AND u.is_active = 1 ORDER BY u.first_name, u.last_name", [$selectedClass]);
    foreach ($db->getRows('SELECT student_id, status, remarks FROM attendance WHERE class_id = ? AND date = ?', [$selectedClass, $selectedDate]) as $a) {
        $existing[$a['student_id']] = $a;
    }
}

$recent = $db->getRows(
    "SELECT a.date, a.class_id, c.class_name, c.section, COUNT(*) AS total, SUM(a.status IN ('present','late')) AS attended
     FROM attendance a JOIN classes c ON a.class_id = c.id
     WHERE a.class_id IN (SELECT id FROM classes WHERE teacher_id = ? UNION SELECT class_id FROM subjects WHERE teacher_id = ?)
     GROUP BY a.date, a.class_id, c.class_name, c.section ORDER BY a.date DESC LIMIT 10", [$tid, $tid]);

$pageTitle = 'Mark Attendance';
$extraCSS = ['dashboard.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('teacher', 'Mark Attendance', '<a href="attendance-report" class="btn btn-outline"><i class="fas fa-chart-bar"></i> Reports</a>');
render_alert($message, $messageType);
?>
<div class="card"><div class="card-body">
    <form method="GET" class="form-row">
        <div class="form-group"><label for="class_id">Class</label>
            <select name="class_id" id="class_id" class="form-control" required>
                <option value="">Choose a class</option>
                <?php foreach ($classes as $c): ?><option value="<?php echo (int)$c['id']; ?>" <?php echo $selectedClass === (int)$c['id'] ? 'selected' : ''; ?>><?php echo e(trim($c['class_name'] . ' ' . $c['section'])); ?></option><?php endforeach; ?>
            </select></div>
        <div class="form-group"><label for="date">Date</label><input type="date" name="date" id="date" class="form-control" value="<?php echo e($selectedDate); ?>" max="<?php echo date('Y-m-d'); ?>" required></div>
        <div class="form-group form-actions"><button type="submit" class="btn btn-primary">Load students</button></div>
    </form>
    <?php if (!$classes): ?><p class="text-muted">You have not been assigned to any class yet.</p><?php endif; ?>
</div></div>

<?php if ($selectedClass && $students): ?>
<div class="card">
    <div class="card-header"><h3><?php echo $existing ? 'Edit' : 'Mark'; ?> attendance for <?php echo e(formatDate($selectedDate, 'D, d M Y')); ?></h3></div>
    <div class="card-body">
        <?php if ($existing): ?><div class="alert alert-info"><i class="fas fa-info-circle"></i> Attendance was already taken for this day. Saving will update it.</div><?php endif; ?>
        <form method="POST" id="attendanceForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="class_id" value="<?php echo $selectedClass; ?>">
            <input type="hidden" name="date" value="<?php echo e($selectedDate); ?>">
            <div class="table-responsive"><table class="table attendance-table"><thead><tr><th>#</th><th>Admission No.</th><th>Student</th><th>Status</th><th>Remarks</th></tr></thead><tbody>
            <?php foreach ($students as $i => $s): $cur = $existing[$s['id']]['status'] ?? ''; ?>
                <tr>
                    <td><?php echo $i + 1; ?></td><td><?php echo e($s['admission_number']); ?></td><td><?php echo e($s['first_name'] . ' ' . $s['last_name']); ?></td>
                    <td><select name="attendance[<?php echo (int)$s['id']; ?>]" class="form-control attendance-status" required aria-label="Status for <?php echo e($s['first_name']); ?>">
                        <option value="">Select</option>
                        <?php foreach (['present', 'absent', 'late', 'excused'] as $st): ?><option value="<?php echo $st; ?>" <?php echo $cur === $st ? 'selected' : ''; ?>><?php echo ucfirst($st); ?></option><?php endforeach; ?>
                    </select></td>
                    <td><input type="text" name="remarks[<?php echo (int)$s['id']; ?>]" class="form-control" maxlength="500" placeholder="Optional" value="<?php echo e($existing[$s['id']]['remarks'] ?? ''); ?>"></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <div class="form-actions">
                <button type="button" class="btn btn-outline" id="markAllPresent"><i class="fas fa-check-circle"></i> Mark all present</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save attendance</button>
            </div>
        </form>
    </div>
</div>
<script>
document.getElementById('markAllPresent').addEventListener('click', function () {
    document.querySelectorAll('.attendance-status').forEach(function (s) { s.value = 'present'; });
});
</script>
<?php elseif ($selectedClass): ?>
<div class="alert alert-info">There are no active students in this class.</div>
<?php endif; ?>

<div class="card"><div class="card-header"><h3>Recent attendance records</h3></div><div class="card-body">
<div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Class</th><th>Attended</th><th>Total</th><th>Rate</th><th></th></tr></thead><tbody>
<?php foreach ($recent as $r): $pct = $r['total'] > 0 ? round($r['attended'] / $r['total'] * 100) : 0; ?>
<tr><td><?php echo e(formatDate($r['date'], 'd M Y')); ?></td><td><?php echo e(trim($r['class_name'] . ' ' . $r['section'])); ?></td><td><?php echo (int)$r['attended']; ?></td><td><?php echo (int)$r['total']; ?></td><td><?php echo $pct; ?>%</td>
<td><a class="btn btn-sm btn-outline" href="?class_id=<?php echo (int)$r['class_id']; ?>&date=<?php echo urlencode($r['date']); ?>">Open</a></td></tr>
<?php endforeach; ?>
<?php if (!$recent): ?><tr><td colspan="6" class="text-center text-muted">No attendance recorded yet.</td></tr><?php endif; ?>
</tbody></table></div></div></div>
<?php dashboard_close(); include __DIR__ . '/../includes/footer.php';
