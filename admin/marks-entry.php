<?php
// admin/marks-entry.php - Streamlined Tabular Marks & Assessment Score Entry
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/SystemSchema.php';

Security::requireRole(['super_admin', 'admin', 'head_teacher', 'supervisor', 'teacher']);
SystemSchema::ensureAll();

$db = db();
[$message, $messageType] = flash_get();

$allClasses = $db->getRows("SELECT id, class_name, section FROM classes WHERE is_active = 1 ORDER BY class_name, section");
$allSubjects = $db->getRows("SELECT id, subject_name, subject_code FROM subjects WHERE is_active = 1 ORDER BY subject_name");

$classId   = (int)($_GET['class_id'] ?? ($allClasses[0]['id'] ?? 0));
$subjectId = (int)($_GET['subject_id'] ?? ($allSubjects[0]['id'] ?? 0));
$term      = trim((string)($_GET['term'] ?? 'Term 1'));
$assType   = in_array($_GET['type'] ?? '', ['exam', 'test', 'assignment', 'project'], true) ? $_GET['type'] : 'exam';
$session   = '2026/2027';

// Handle Marks Save POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flash_redirect('Invalid security token.', 'error');
    }

    $cId = (int)($_POST['class_id'] ?? 0);
    $subId = (int)($_POST['subject_id'] ?? 0);
    $t = trim((string)($_POST['term'] ?? 'Term 1'));
    $aType = trim((string)($_POST['assessment_type'] ?? 'exam'));
    $scores = $_POST['scores'] ?? [];
    $remarks = $_POST['remarks'] ?? [];

    $savedCount = 0;
    foreach ($scores as $stId => $val) {
        $stId = (int)$stId;
        if ($val === '' || $val === null) continue;
        $score = (float)$val;
        $grade = letterGrade($score, 100);
        $rem = trim((string)($remarks[$stId] ?? ''));

        // Check if result row exists
        $existing = $db->getRow(
            "SELECT id FROM results WHERE student_id = ? AND subject_id = ? AND class_id = ? AND term = ? AND assessment_type = ?",
            [$stId, $subId, $cId, $t, $aType]
        );

        if ($existing) {
            $db->query(
                "UPDATE results SET score = ?, grade = ?, remarks = ?, entered_by = ?, is_approved = 1, approved_at = NOW() WHERE id = ?",
                [$score, $grade, $rem, $_SESSION['user_id'], $existing['id']]
            );
        } else {
            $db->insert(
                "INSERT INTO results (student_id, subject_id, class_id, term, academic_year, assessment_type, score, max_score, grade, remarks, is_approved, entered_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 100, ?, ?, 1, ?)",
                [$stId, $subId, $cId, $t, $session, $aType, $score, $grade, $rem, $_SESSION['user_id']]
            );
        }
        $savedCount++;
    }

    Security::logAudit('ENTERED_MARKS', 'results', null, null, [
        'count'   => $savedCount,
        'class'   => $cId,
        'subject' => $subId,
        'term'    => $t
    ]);

    flash_redirect("Successfully saved marks for {$savedCount} student(s)!", 'success', "marks-entry?class_id={$cId}&subject_id={$subId}&term=" . urlencode($t) . "&type=" . urlencode($aType));
}

// Get enrolled students
$students = [];
if ($classId > 0) {
    $students = $db->getRows(
        "SELECT s.id, s.admission_number, u.first_name, u.last_name
         FROM students s
         JOIN users u ON s.user_id = u.id
         WHERE s.class_id = ? AND u.is_active = 1
         ORDER BY u.first_name, u.last_name",
        [$classId]
    );

    // Fetch existing scores
    foreach ($students as &$st) {
        $res = $db->getRow(
            "SELECT score, grade, remarks FROM results WHERE student_id = ? AND subject_id = ? AND class_id = ? AND term = ? AND assessment_type = ?",
            [$st['id'], $subjectId, $classId, $term, $assType]
        );
        $st['current_score'] = $res ? $res['score'] : '';
        $st['current_grade'] = $res ? $res['grade'] : '';
        $st['current_remarks'] = $res ? $res['remarks'] : '';
    }
    unset($st);
}

$pageTitle = 'Marks Entry';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('admin', 'Marks Entry');
render_alert($message, $messageType);
?>

<div class="card" style="margin-bottom:20px;">
    <div class="card-body">
        <form method="GET" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:14px; align-items:end;">
            <div class="form-group" style="margin-bottom:0;">
                <label for="class_id"><strong>Select Class *</strong></label>
                <select name="class_id" id="class_id" class="form-control" onchange="this.form.submit()">
                    <?php foreach ($allClasses as $c): ?>
                        <option value="<?php echo e($c['id']); ?>" <?php echo $classId === (int)$c['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['class_name'] . ($c['section'] ? ' ' . $c['section'] : '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:0;">
                <label for="subject_id"><strong>Select Subject *</strong></label>
                <select name="subject_id" id="subject_id" class="form-control" onchange="this.form.submit()">
                    <?php foreach ($allSubjects as $sub): ?>
                        <option value="<?php echo e($sub['id']); ?>" <?php echo $subjectId === (int)$sub['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($sub['subject_name']); ?> (<?php echo e($sub['subject_code']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:0;">
                <label for="term"><strong>Term</strong></label>
                <select name="term" id="term" class="form-control" onchange="this.form.submit()">
                    <option value="Term 1" <?php echo $term === 'Term 1' ? 'selected' : ''; ?>>Term 1 (First Term)</option>
                    <option value="Term 2" <?php echo $term === 'Term 2' ? 'selected' : ''; ?>>Term 2 (Second Term)</option>
                    <option value="Term 3" <?php echo $term === 'Term 3' ? 'selected' : ''; ?>>Term 3 (Third Term)</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:0;">
                <label for="type"><strong>Assessment Type</strong></label>
                <select name="type" id="type" class="form-control" onchange="this.form.submit()">
                    <option value="exam" <?php echo $assType === 'exam' ? 'selected' : ''; ?>>Term Exam</option>
                    <option value="test" <?php echo $assType === 'test' ? 'selected' : ''; ?>>Continuous Test</option>
                    <option value="assignment" <?php echo $assType === 'assignment' ? 'selected' : ''; ?>>Assignment</option>
                    <option value="project" <?php echo $assType === 'project' ? 'selected' : ''; ?>>Project</option>
                </select>
            </div>
        </form>
    </div>
</div>

<?php if ($classId > 0 && $subjectId > 0): ?>
<form method="POST" action="">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="class_id" value="<?php echo e($classId); ?>">
    <input type="hidden" name="subject_id" value="<?php echo e($subjectId); ?>">
    <input type="hidden" name="term" value="<?php echo e($term); ?>">
    <input type="hidden" name="assessment_type" value="<?php echo e($assType); ?>">

    <div class="card">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
            <h3><i class="fas fa-edit"></i> Score Entry Grid (<?php echo count($students); ?> Learners)</h3>
            <span class="text-muted">Enter marks out of 100. Grades are computed automatically.</span>
        </div>
        <div class="card-body">
            <?php if (empty($students)): ?>
                <p class="text-muted">No students found in this class.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Adm No.</th>
                                <th>Student Full Name</th>
                                <th style="width:140px;">Score (Max 100)</th>
                                <th style="width:80px;">Grade</th>
                                <th>Teacher Remark</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $st): ?>
                            <tr>
                                <td><strong><?php echo e($st['admission_number']); ?></strong></td>
                                <td><strong><?php echo e($st['first_name'] . ' ' . $st['last_name']); ?></strong></td>
                                <td>
                                    <input type="number" step="0.5" min="0" max="100"
                                           name="scores[<?php echo e($st['id']); ?>]"
                                           class="form-control score-box"
                                           value="<?php echo e($st['current_score']); ?>"
                                           placeholder="0-100"
                                           oninput="updateGradePill(this)">
                                </td>
                                <td>
                                    <span class="badge badge-info grade-tag"><?php echo e($st['current_grade'] ?: '-'); ?></span>
                                </td>
                                <td>
                                    <input type="text" name="remarks[<?php echo e($st['id']); ?>]" class="form-control"
                                           value="<?php echo e($st['current_remarks']); ?>" placeholder="Optional remark (e.g. Excellent work)">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div style="margin-top:20px; display:flex; justify-content:flex-end;">
                    <button type="submit" class="btn btn-primary" style="font-weight:700; padding:12px 28px;">
                        <i class="fas fa-save"></i> Save All Marks
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</form>

<script nonce="<?php echo CSP_NONCE; ?>">
function updateGradePill(input) {
    const row = input.closest('tr');
    const tag = row.querySelector('.grade-tag');
    const val = parseFloat(input.value);
    if (isNaN(val)) {
        tag.textContent = '-';
        tag.className = 'badge badge-secondary grade-tag';
        return;
    }
    let g = 'F';
    let cls = 'badge-danger';
    if (val >= 70) { g = 'A'; cls = 'badge-success'; }
    else if (val >= 60) { g = 'B'; cls = 'badge-primary'; }
    else if (val >= 50) { g = 'C'; cls = 'badge-info'; }
    else if (val >= 45) { g = 'D'; cls = 'badge-warning'; }
    else if (val >= 40) { g = 'E'; cls = 'badge-warning'; }
    tag.textContent = g;
    tag.className = 'badge ' + cls + ' grade-tag';
}
</script>
<?php endif; ?>

<?php
dashboard_close();
include __DIR__ . '/../includes/footer.php';
