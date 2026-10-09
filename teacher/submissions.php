<?php
// teacher/submissions.php?assignment_id=N - submissions for one of the teacher's assignments
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
Security::requireRole('teacher');

$db = db();
$tid = Security::currentTeacherId() ?? 0;
$hid = (int)($_GET['assignment_id'] ?? 0);
$hw = $db->getRow("SELECT h.*, c.class_name, c.section, s.subject_name FROM homework h
                   JOIN classes c ON h.class_id = c.id JOIN subjects s ON h.subject_id = s.id WHERE h.id = ? AND h.teacher_id = ?", [$hid, $tid]);

$pageTitle = 'Submissions';
$extraCSS = ['dashboard.css'];
include __DIR__ . '/../includes/header.php';
if (!$hw) {
    dashboard_open('teacher', 'Assignment not found');
    echo '<a class="btn btn-secondary" href="assignments">Back to assignments</a>';
    dashboard_close();
    include __DIR__ . '/../includes/footer.php';
    exit;
}
$rows = $db->getRows(
    "SELECT st.id AS student_id, st.admission_number, u.first_name, u.last_name,
            hs.id AS sub_id, hs.submission_text, hs.attachment_path, hs.status, hs.submission_date, hs.obtained_marks, hs.feedback
     FROM students st JOIN users u ON st.user_id = u.id
     LEFT JOIN homework_submissions hs ON hs.student_id = st.id AND hs.homework_id = ?
     WHERE st.class_id = ? AND u.is_active = 1 ORDER BY u.first_name, u.last_name", [$hid, $hw['class_id']]);

dashboard_open('teacher', $hw['title'], '<a href="assignments" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>');
?>
<p class="text-muted"><?php echo e($hw['subject_name']); ?> &middot; <?php echo e(trim($hw['class_name'] . ' ' . $hw['section'])); ?> &middot;
    due <?php echo e(formatDate($hw['due_date'], 'd M Y, h:i A')); ?> &middot; out of <?php echo e($hw['total_marks'] + 0); ?></p>
<div class="card"><div class="card-body"><div class="table-responsive"><table class="table"><thead><tr><th>Student</th><th>Status</th><th>Submitted</th><th>Work</th><th>Marks</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr>
    <td><?php echo e($r['first_name'] . ' ' . $r['last_name']); ?><br><small class="text-muted"><?php echo e($r['admission_number']); ?></small></td>
    <td><?php echo $r['sub_id'] ? '<span class="badge badge-' . ($r['status'] === 'graded' ? 'success' : ($r['status'] === 'late' ? 'warning' : 'info')) . '">' . e(ucfirst($r['status'])) . '</span>' : '<span class="badge badge-secondary">Missing</span>'; ?></td>
    <td><?php echo $r['sub_id'] ? e(formatDate($r['submission_date'], 'd M, h:i A')) : '-'; ?></td>
    <td><?php if ($r['submission_text']): ?><details><summary>Read</summary><div><?php echo nl2br(e($r['submission_text'])); ?></div></details><?php endif; ?>
        <?php if ($r['attachment_path']): ?><a href="<?php echo e(BASE_URL . '/uploads/submissions/' . rawurlencode(basename($r['attachment_path']))); ?>" target="_blank" rel="noopener"><i class="fas fa-paperclip"></i> File</a><?php endif; ?>
        <?php if (!$r['submission_text'] && !$r['attachment_path']): ?>-<?php endif; ?></td>
    <td><?php echo $r['obtained_marks'] !== null ? e($r['obtained_marks'] + 0) . '/' . e($hw['total_marks'] + 0) : '-'; ?></td>
    <td><?php if ($r['sub_id']): ?>
        <form method="POST" action="assignments" class="inline-grade">
            <?php echo csrf_field(); ?><input type="hidden" name="action" value="grade_submission"><input type="hidden" name="submission_id" value="<?php echo (int)$r['sub_id']; ?>">
            <input type="number" name="obtained_marks" class="form-control form-control-sm" min="0" max="<?php echo e($hw['total_marks'] + 0); ?>" step="0.5" required value="<?php echo e($r['obtained_marks']); ?>" style="width:90px" aria-label="Marks">
            <input type="text" name="feedback" class="form-control form-control-sm" placeholder="Feedback" maxlength="2000" value="<?php echo e($r['feedback']); ?>">
            <button type="submit" class="btn btn-sm btn-primary">Save</button>
        </form><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted">No students in this class.</td></tr><?php endif; ?>
</tbody></table></div></div></div>
<?php dashboard_close(); include __DIR__ . '/../includes/footer.php';
