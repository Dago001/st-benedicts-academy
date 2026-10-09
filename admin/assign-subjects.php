<?php
// admin/assign-subjects.php - choose which subjects a teacher teaches
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
Security::requireRole('admin');

$db = db();
$teacherId = (int)($_GET['teacher_id'] ?? $_POST['teacher_id'] ?? 0);
$t = $db->getRow("SELECT t.id, u.first_name, u.last_name FROM teachers t JOIN users u ON t.user_id = u.id WHERE t.id = ?", [$teacherId]);
$message = '';
$messageType = '';

if ($t && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token';
        $messageType = 'error';
    } else {
        $selected = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['subject_ids'] ?? [])))));
        $db->beginTransaction();
        try {
            // Release this teacher's subjects that were unticked, then claim the ticked ones
            if ($selected) {
                $in = implode(',', array_fill(0, count($selected), '?'));
                $db->query("UPDATE subjects SET teacher_id = NULL WHERE teacher_id = ? AND id NOT IN ($in)", array_merge([$teacherId], $selected));
                $db->query("UPDATE subjects SET teacher_id = ? WHERE id IN ($in)", array_merge([$teacherId], $selected));
            } else {
                $db->query("UPDATE subjects SET teacher_id = NULL WHERE teacher_id = ?", [$teacherId]);
            }
            $db->commit();
            Security::logAudit('UPDATED_SUBJECT_ASSIGNMENT', 'teachers', $teacherId, null, ['subjects' => $selected]);
            flash_redirect('Subject assignments updated', 'success', BASE_URL . '/admin/teachers');
        } catch (Exception $e) {
            $db->rollback();
            $message = 'Could not save the assignments';
            $messageType = 'error';
        }
    }
}

$subjects = $t ? $db->getRows(
    "SELECT s.id, s.subject_name, s.subject_code, s.teacher_id, c.class_name, c.section, CONCAT(u.first_name, ' ', u.last_name) AS other_teacher
     FROM subjects s LEFT JOIN classes c ON s.class_id = c.id
     LEFT JOIN teachers t2 ON s.teacher_id = t2.id LEFT JOIN users u ON t2.user_id = u.id
     WHERE s.is_active = 1 ORDER BY c.class_name, s.subject_name") : [];

$pageTitle = 'Assign Subjects';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('admin', $t ? 'Subjects for ' . $t['first_name'] . ' ' . $t['last_name'] : 'Teacher not found');
render_alert($message, $messageType);
if ($t): ?>
<form method="POST" class="card"><?php echo csrf_field(); ?><input type="hidden" name="teacher_id" value="<?php echo $teacherId; ?>">
    <div class="card-body">
        <p class="text-muted">Tick the subjects this teacher teaches. A subject has one teacher, so ticking one that belongs to someone else reassigns it.</p>
        <div class="check-grid">
        <?php foreach ($subjects as $s): ?>
            <label class="checkbox-label check-card">
                <input type="checkbox" name="subject_ids[]" value="<?php echo (int)$s['id']; ?>" <?php echo (int)$s['teacher_id'] === $teacherId ? 'checked' : ''; ?>>
                <span><strong><?php echo e($s['subject_name']); ?></strong> <small>(<?php echo e($s['subject_code']); ?>)</small><br>
                <small class="text-muted"><?php echo e(trim(($s['class_name'] ?? 'No class') . ' ' . ($s['section'] ?? ''))); ?>
                <?php if ($s['teacher_id'] && (int)$s['teacher_id'] !== $teacherId): ?> &middot; now: <?php echo e($s['other_teacher']); ?><?php endif; ?></small></span>
            </label>
        <?php endforeach; ?>
        <?php if (!$subjects): ?><p class="text-muted">No active subjects exist yet.</p><?php endif; ?>
        </div>
    </div>
    <div class="card-footer"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button> <a href="teachers" class="btn btn-secondary">Cancel</a></div>
</form>
<?php else: ?><a href="teachers" class="btn btn-secondary">Back to teachers</a><?php endif;
dashboard_close();
include __DIR__ . '/../includes/footer.php';
