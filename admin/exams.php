<?php
// admin/exams.php - Examination Timetable & Exam Management
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/SystemSchema.php';

Security::requireRole(['super_admin', 'admin', 'head_teacher', 'supervisor']);
SystemSchema::ensureAll();

$db = db();
[$message, $messageType] = flash_get();

// Handle new Exam Schedule POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flash_redirect('Invalid security token.', 'error');
    }

    $examName = trim((string)($_POST['exam_name'] ?? ''));
    $term = trim((string)($_POST['term'] ?? 'Term 1'));
    $session = trim((string)($_POST['academic_year'] ?? '2026/2027'));
    $classId = (int)($_POST['class_id'] ?? 0);
    $subjectId = (int)($_POST['subject_id'] ?? 0);
    $examDate = valid_date($_POST['exam_date'] ?? '');
    $startTime = trim((string)($_POST['start_time'] ?? '09:00'));
    $endTime = trim((string)($_POST['end_time'] ?? '11:00'));
    $room = trim((string)($_POST['room'] ?? 'Hall A'));
    $maxMarks = (float)($_POST['max_marks'] ?? 100);

    if ($examName === '' || !$classId || !$subjectId || !$examDate) {
        flash_redirect('Please fill in all required examination fields.', 'error');
    }

    $db->insert(
        "INSERT INTO exam_schedules (exam_name, term, academic_year, class_id, subject_id, exam_date, start_time, end_time, room, max_marks, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$examName, $term, $session, $classId, $subjectId, $examDate, $startTime, $endTime, $room, $maxMarks, $_SESSION['user_id']]
    );
    Security::logAudit('SCHEDULED_EXAM', 'exam_schedules', null, null, ['exam' => $examName, 'class' => $classId]);
    flash_redirect("Exam '{$examName}' scheduled successfully.", 'success');
}

$allClasses = $db->getRows("SELECT id, class_name, section FROM classes WHERE is_active = 1 ORDER BY class_name, section");
$allSubjects = $db->getRows("SELECT id, subject_name, subject_code FROM subjects WHERE is_active = 1 ORDER BY subject_name");

$schedules = $db->getRows(
    "SELECT es.*, c.class_name, c.section, sub.subject_name, sub.subject_code
     FROM exam_schedules es
     JOIN classes c ON es.class_id = c.id
     JOIN subjects sub ON es.subject_id = sub.id
     ORDER BY es.exam_date ASC, es.start_time ASC LIMIT 100"
);

$pageTitle = 'Exams Management';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('admin', 'Examinations');
render_alert($message, $messageType);
?>

<div class="card" style="margin-bottom:24px;">
    <div class="card-header">
        <h3><i class="fas fa-calendar-plus"></i> Schedule New Examination Paper</h3>
    </div>
    <div class="card-body">
        <form method="POST" action="">
            <?php echo csrf_field(); ?>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:16px;">
                <div class="form-group">
                    <label for="exam_name"><strong>Exam Title *</strong></label>
                    <input type="text" name="exam_name" id="exam_name" class="form-control" required placeholder="e.g. First Term Final Examination">
                </div>
                <div class="form-group">
                    <label for="term"><strong>Term</strong></label>
                    <select name="term" id="term" class="form-control">
                        <option value="Term 1" selected>Term 1 (First Term)</option>
                        <option value="Term 2">Term 2 (Second Term)</option>
                        <option value="Term 3">Term 3 (Third Term)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="class_id"><strong>Target Class *</strong></label>
                    <select name="class_id" id="class_id" class="form-control" required>
                        <option value="">-- Select Class --</option>
                        <?php foreach ($allClasses as $c): ?>
                            <option value="<?php echo e($c['id']); ?>">
                                <?php echo htmlspecialchars($c['class_name'] . ($c['section'] ? ' ' . $c['section'] : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="subject_id"><strong>Subject *</strong></label>
                    <select name="subject_id" id="subject_id" class="form-control" required>
                        <option value="">-- Select Subject --</option>
                        <?php foreach ($allSubjects as $sub): ?>
                            <option value="<?php echo e($sub['id']); ?>">
                                <?php echo htmlspecialchars($sub['subject_name']); ?> (<?php echo e($sub['subject_code']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:16px; margin-top:8px;">
                <div class="form-group">
                    <label for="exam_date"><strong>Date of Exam *</strong></label>
                    <input type="date" name="exam_date" id="exam_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group">
                    <label for="start_time"><strong>Start Time</strong></label>
                    <input type="time" name="start_time" id="start_time" class="form-control" value="09:00">
                </div>
                <div class="form-group">
                    <label for="end_time"><strong>End Time</strong></label>
                    <input type="time" name="end_time" id="end_time" class="form-control" value="11:00">
                </div>
                <div class="form-group">
                    <label for="room"><strong>Exam Hall / Room</strong></label>
                    <input type="text" name="room" id="room" class="form-control" placeholder="e.g. Hall 1">
                </div>
            </div>

            <div style="margin-top:12px; display:flex; justify-content:flex-end;">
                <button type="submit" class="btn btn-primary" style="font-weight:700;">
                    <i class="fas fa-plus-circle"></i> Add Exam Schedule
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-file-alt"></i> Scheduled Examinations Timetable (<?php echo count($schedules); ?>)</h3>
    </div>
    <div class="card-body">
        <?php if (empty($schedules)): ?>
            <p class="text-muted">No examinations scheduled yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Exam Name</th>
                            <th>Class</th>
                            <th>Subject</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Room / Hall</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($schedules as $s): ?>
                        <tr>
                            <td><strong><?php echo e($s['exam_name']); ?></strong> <span class="badge badge-info"><?php echo e($s['term']); ?></span></td>
                            <td><?php echo e($s['class_name'] . ($s['section'] ? ' ' . $s['section'] : '')); ?></td>
                            <td><strong><?php echo e($s['subject_name']); ?></strong> (<?php echo e($s['subject_code']); ?>)</td>
                            <td><?php echo date('d M Y', strtotime($s['exam_date'])); ?></td>
                            <td><?php echo date('h:i A', strtotime($s['start_time'])); ?> &ndash; <?php echo date('h:i A', strtotime($s['end_time'])); ?></td>
                            <td><span class="badge badge-secondary"><?php echo e($s['room'] ?: 'Main Hall'); ?></span></td>
                            <td>
                                <a href="marks-entry?class_id=<?php echo e($s['class_id']); ?>&subject_id=<?php echo e($s['subject_id']); ?>" class="btn btn-sm btn-primary">
                                    <i class="fas fa-edit"></i> Enter Marks
                                </a>
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
