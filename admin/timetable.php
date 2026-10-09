<?php
// admin/timetable.php - build a class timetable
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/timetable.php';
Security::requireRole('admin');

$db = db();
$classId = (int)($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
$class = $db->getRow('SELECT id, class_name, section FROM classes WHERE id = ?', [$classId]);
$message = '';
$messageType = '';
[$flashMsg, $flashType] = flash_get();

if ($class && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token';
        $messageType = 'error';
    } elseif (($_POST['action'] ?? '') === 'delete_slot') {
        $db->query('DELETE FROM time_table WHERE id = ? AND class_id = ?', [(int)($_POST['slot_id'] ?? 0), $classId]);
        Security::logAudit('DELETED_TIMETABLE_SLOT', 'time_table', (int)($_POST['slot_id'] ?? 0));
        flash_redirect('Lesson removed', 'success');
    } elseif (($_POST['action'] ?? '') === 'add_slot') {
        $day = $_POST['day_of_week'] ?? '';
        $start = $_POST['start_time'] ?? '';
        $end = $_POST['end_time'] ?? '';
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $room = mb_substr(Security::sanitize($_POST['room'] ?? ''), 0, 50);
        $subject = $db->getRow('SELECT id, teacher_id FROM subjects WHERE id = ? AND class_id = ?', [$subjectId, $classId]);
        if (!in_array($day, TIMETABLE_DAYS, true) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end) || $start >= $end) {
            $message = 'Choose a day and a valid start/end time (end after start)';
            $messageType = 'error';
        } elseif (!$subject) {
            $message = 'Choose a subject taught in this class';
            $messageType = 'error';
        } elseif ($db->getRow('SELECT id FROM time_table WHERE class_id = ? AND day_of_week = ? AND start_time < ? AND end_time > ?', [$classId, $day, $end . ':00', $start . ':00'])) {
            $message = 'That time overlaps another lesson for this class';
            $messageType = 'error';
        } elseif ($subject['teacher_id'] && $db->getRow('SELECT id FROM time_table WHERE teacher_id = ? AND day_of_week = ? AND start_time < ? AND end_time > ?', [$subject['teacher_id'], $day, $end . ':00', $start . ':00'])) {
            $message = 'The teacher already has a lesson at that time';
            $messageType = 'error';
        } else {
            $id = $db->insert('INSERT INTO time_table (class_id, subject_id, teacher_id, day_of_week, start_time, end_time, room) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$classId, $subjectId, $subject['teacher_id'], $day, $start . ':00', $end . ':00', $room ?: null]);
            Security::logAudit('ADDED_TIMETABLE_SLOT', 'time_table', $id);
            flash_redirect('Lesson added', 'success');
        }
    }
}

$pageTitle = 'Timetable';
$extraCSS = ['admin.css', 'dashboard.css', 'timetable.css'];
include __DIR__ . '/../includes/header.php';
$classes = $db->getRows('SELECT id, class_name, section FROM classes WHERE is_active = 1 ORDER BY class_name, section');
dashboard_open('admin', 'Timetable' . ($class ? ': ' . trim($class['class_name'] . ' ' . $class['section']) : ''), '<a href="classes" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Classes</a>');
render_alert($message ?: $flashMsg, $message ? $messageType : $flashType);
?>
<form method="GET" class="card"><div class="card-body form-inline">
    <label for="class_id">Class</label>
    <select id="class_id" name="class_id" class="form-control" onchange="this.form.submit()">
        <option value="">-- choose --</option>
        <?php foreach ($classes as $c): ?><option value="<?php echo (int)$c['id']; ?>" <?php echo $c['id'] == $classId ? 'selected' : ''; ?>><?php echo e(trim($c['class_name'] . ' ' . $c['section'])); ?></option><?php endforeach; ?>
    </select>
</div></form>
<?php if ($class):
    $subjects = $db->getRows('SELECT id, subject_name FROM subjects WHERE class_id = ? AND is_active = 1 ORDER BY subject_name', [$classId]); ?>
<div class="card"><div class="card-header"><h3>Add a lesson</h3></div><div class="card-body">
    <form method="POST" class="form-row">
        <?php echo csrf_field(); ?><input type="hidden" name="action" value="add_slot"><input type="hidden" name="class_id" value="<?php echo $classId; ?>">
        <div class="form-group"><label>Day</label><select name="day_of_week" class="form-control" required><?php foreach (TIMETABLE_DAYS as $d): ?><option><?php echo e($d); ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Subject</label><select name="subject_id" class="form-control" required><option value="">-- subject --</option><?php foreach ($subjects as $s): ?><option value="<?php echo (int)$s['id']; ?>"><?php echo e($s['subject_name']); ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Start</label><input type="time" name="start_time" class="form-control" required></div>
        <div class="form-group"><label>End</label><input type="time" name="end_time" class="form-control" required></div>
        <div class="form-group"><label>Room</label><input type="text" name="room" class="form-control" maxlength="50"></div>
        <div class="form-group form-actions"><button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add</button></div>
    </form>
    <?php if (!$subjects): ?><p class="text-muted">Add subjects to this class first (Subjects page).</p><?php endif; ?>
</div></div>
<div class="card"><div class="card-body"><?php render_timetable(timetable_for_class($classId), true); ?></div></div>
<?php endif;
dashboard_close();
include __DIR__ . '/../includes/footer.php';
