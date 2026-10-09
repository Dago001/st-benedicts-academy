<?php
// teacher/results.php - Manage Student Results
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';

Security::requireRole('teacher');

$pageTitle = 'Manage Results';
$extraCSS = ['dashboard.css'];
$extraJS = ['results.js'];

include __DIR__ . '/../includes/header.php';

$db = db();
$userId = (int)$_SESSION['user_id'];
[$message, $messageType] = flash_get();

$teacher = $db->getRow("SELECT t.*, u.first_name, u.last_name FROM teachers t JOIN users u ON t.user_id = u.id WHERE t.user_id = ?", [$userId]);
if (!$teacher) {
    echo '<div class="container" style="padding:24px"><div class="alert alert-error">Your teacher profile is incomplete. Please contact the school office.</div></div>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}
$teacherId = (int)$teacher['id'];

// Results are entered per subject, so the relevant classes are those the teacher teaches a subject in
$classes = $db->getRows(
    "SELECT c.* FROM classes c WHERE c.is_active = 1 AND c.id IN (SELECT class_id FROM subjects WHERE teacher_id = ? AND is_active = 1)
     ORDER BY c.class_name, c.section", [$teacherId]);
$subjects = $db->getRows(
    "SELECT s.*, c.class_name FROM subjects s JOIN classes c ON s.class_id = c.id
     WHERE s.teacher_id = ? AND s.is_active = 1 ORDER BY c.class_name, s.subject_name", [$teacherId]);

$termList = ['Term 1', 'Term 2', 'Term 3'];
$selectedClass = (int)($_GET['class_id'] ?? 0);
$selectedSubject = (int)($_GET['subject_id'] ?? 0);
$selectedTerm = in_array($_GET['term'] ?? '', $termList, true) ? $_GET['term'] : 'Term 1';
$selectedYear = preg_match('/^\d{4}-\d{4}$/', $_GET['academic_year'] ?? '') ? $_GET['academic_year'] : currentAcademicYear();

/** Subject must be one of this teacher's own, inside the given class. */
$ownsSubject = function ($subjectId, $classId) use ($db, $teacherId) {
    return (bool)$db->getRow('SELECT id FROM subjects WHERE id = ? AND class_id = ? AND teacher_id = ?', [$subjectId, $classId, $teacherId]);
};

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        $score = is_numeric($_POST['score'] ?? null) ? (float)$_POST['score'] : -1;
        $maxScore = is_numeric($_POST['max_score'] ?? null) ? (float)$_POST['max_score'] : 100;
        $remarks = mb_substr(Security::sanitize($_POST['remarks'] ?? ''), 0, 1000);
        $scoreOk = $maxScore > 0 && $maxScore <= 1000 && $score >= 0 && $score <= $maxScore;

        switch ($action) {
            case 'add_result':
                $studentId = (int)($_POST['student_id'] ?? 0);
                $subjectId = (int)($_POST['subject_id'] ?? 0);
                $classId = (int)($_POST['class_id'] ?? 0);
                $term = $_POST['term'] ?? '';
                $academicYear = Security::sanitize($_POST['academic_year'] ?? '');
                $type = $_POST['assessment_type'] ?? '';

                if (!$studentId || !in_array($term, $termList, true) || !preg_match('/^\d{4}-\d{4}$/', $academicYear)
                    || !in_array($type, ['test', 'exam', 'assignment', 'project'], true)) {
                    $message = 'Please complete all fields with valid values';
                    $messageType = 'error';
                } elseif (!$scoreOk) {
                    $message = 'Score must be between 0 and the maximum score';
                    $messageType = 'error';
                } elseif (!$ownsSubject($subjectId, $classId)) {
                    $message = 'You can only enter results for your own subjects';
                    $messageType = 'error';
                } elseif (!$db->getRow('SELECT id FROM students WHERE id = ? AND class_id = ?', [$studentId, $classId])) {
                    $message = 'That student is not in the selected class';
                    $messageType = 'error';
                } elseif ($db->getRow('SELECT id FROM results WHERE student_id = ? AND subject_id = ? AND assessment_type = ? AND term = ? AND academic_year = ?',
                                      [$studentId, $subjectId, $type, $term, $academicYear])) {
                    $message = 'A result already exists for this student, subject, assessment and term';
                    $messageType = 'error';
                } else {
                    try {
                        $newId = $db->insert(
                            "INSERT INTO results (student_id, subject_id, class_id, term, academic_year, assessment_type, score, max_score, grade, remarks, entered_by)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            [$studentId, $subjectId, $classId, $term, $academicYear, $type, $score, $maxScore, letterGrade($score, $maxScore), $remarks, $userId]);
                        Security::logAudit('ADDED_RESULT', 'results', $newId);
                        $message = 'Result added successfully (pending admin approval)';
                        $messageType = 'success';
                    } catch (Exception $e) {
                        $message = 'Error adding result';
                        $messageType = 'error';
                    }
                }
                break;

            case 'update_result':
            case 'delete_result':
                $resultId = (int)($_POST['result_id'] ?? 0);
                // Only the teacher's own, not yet approved results can be changed
                $row = $db->getRow('SELECT r.id, r.is_approved FROM results r JOIN subjects s ON r.subject_id = s.id WHERE r.id = ? AND s.teacher_id = ?', [$resultId, $teacherId]);
                if (!$row) {
                    $message = 'Result not found';
                    $messageType = 'error';
                } elseif ($row['is_approved']) {
                    $message = 'Approved results can only be changed by an administrator';
                    $messageType = 'error';
                } elseif ($action === 'delete_result') {
                    $db->query('DELETE FROM results WHERE id = ?', [$resultId]);
                    Security::logAudit('DELETED_RESULT', 'results', $resultId);
                    $message = 'Result deleted successfully';
                    $messageType = 'success';
                } elseif (!$scoreOk) {
                    $message = 'Score must be between 0 and the maximum score';
                    $messageType = 'error';
                } else {
                    $db->query('UPDATE results SET score = ?, max_score = ?, grade = ?, remarks = ? WHERE id = ?',
                        [$score, $maxScore, letterGrade($score, $maxScore), $remarks, $resultId]);
                    Security::logAudit('UPDATED_RESULT', 'results', $resultId);
                    $message = 'Result updated successfully';
                    $messageType = 'success';
                }
                break;
        }
    }
    if ($messageType === 'success') {
        flash_redirect($message, 'success', BASE_URL . '/teacher/results.php?' . http_build_query([
            'class_id' => $_POST['class_id'] ?? $selectedClass, 'subject_id' => $_POST['subject_id'] ?? $selectedSubject,
            'term' => $_POST['term'] ?? $selectedTerm, 'academic_year' => $_POST['academic_year'] ?? $selectedYear]));
    }
}

// Students for the selected class (only if it is one of the teacher's)
$students = [];
if ($selectedClass && Security::canAccessClass($selectedClass)) {
    $students = $db->getRows(
        "SELECT s.id, u.first_name, u.last_name, s.admission_number FROM students s JOIN users u ON s.user_id = u.id
         WHERE s.class_id = ? AND u.is_active = 1 ORDER BY u.first_name, u.last_name", [$selectedClass]);
}

// Existing results for the selected filters (own subjects only)
$existingResults = [];
if ($selectedClass && $selectedSubject && $ownsSubject($selectedSubject, $selectedClass)) {
    $existingResults = $db->getRows(
        "SELECT r.*, u.first_name, u.last_name, s.admission_number FROM results r
         JOIN students s ON r.student_id = s.id JOIN users u ON s.user_id = u.id
         WHERE r.class_id = ? AND r.subject_id = ? AND r.term = ? AND r.academic_year = ?
         ORDER BY u.first_name, u.last_name", [$selectedClass, $selectedSubject, $selectedTerm, $selectedYear]);
}
?>

<div class="dashboard-container">
    <?php render_sidebar('teacher'); ?>

    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>Manage Results</h1>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?php echo e($messageType); ?> alert-dismissible">
            <?php echo e($message); ?>
            <button type="button" class="close" onclick="this.parentElement.remove()">&times;</button>
        </div>
        <?php endif; ?>

        <!-- Filter Form -->
        <div class="card">
            <div class="card-header">
                <h3>Select Criteria</h3>
            </div>
            <div class="card-body">
                <form method="GET" class="form-row">
                    <div class="form-group col-md-3">
                        <label for="class_id">Class</label>
                        <select id="class_id" name="class_id" class="form-control" required>
                            <option value="">-- Select Class --</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo e($class['id']); ?>" <?php echo $selectedClass == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name'] . ' ' . $class['section']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-md-3">
                        <label for="subject_id">Subject</label>
                        <select id="subject_id" name="subject_id" class="form-control" required>
                            <option value="">-- Select Subject --</option>
                            <?php foreach ($subjects as $subject): ?>
                            <option value="<?php echo e($subject['id']); ?>" <?php echo $selectedSubject == $subject['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subject['subject_name']); ?> (<?php echo e($subject['class_name']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-md-2">
                        <label for="term">Term</label>
                        <select id="term" name="term" class="form-control">
                            <option value="Term 1" <?php echo $selectedTerm == 'Term 1' ? 'selected' : ''; ?>>Term 1</option>
                            <option value="Term 2" <?php echo $selectedTerm == 'Term 2' ? 'selected' : ''; ?>>Term 2</option>
                            <option value="Term 3" <?php echo $selectedTerm == 'Term 3' ? 'selected' : ''; ?>>Term 3</option>
                        </select>
                    </div>

                    <div class="form-group col-md-2">
                        <label for="academic_year">Academic Year</label>
                        <input type="text" id="academic_year" name="academic_year" class="form-control"
                               value="<?php echo e($selectedYear); ?>" placeholder="YYYY-YYYY">
                    </div>

                    <div class="form-group col-md-2">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary form-control">Load</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($selectedClass && $selectedSubject && !empty($students)): ?>
        <!-- Results Entry Form -->
        <div class="card">
            <div class="card-header">
                <h3>Enter Results</h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>S/N</th>
                                <th>Admission No.</th>
                                <th>Student Name</th>
                                <th>Assessment Type</th>
                                <th>Score</th>
                                <th>Max Score</th>
                                <th>Grade</th>
                                <th>Remarks</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $index => $student):
                                // Check if result exists for this student
                                $existingResult = null;
                                foreach ($existingResults as $er) {
                                    if ($er['student_id'] == $student['id']) {
                                        $existingResult = $er;
                                        break;
                                    }
                                }
                            ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($student['admission_number']); ?></td>
                                <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>

                                <?php if ($existingResult): ?>
                                <!-- Edit existing result -->
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                    <input type="hidden" name="action" value="update_result">
                                    <input type="hidden" name="result_id" value="<?php echo e($existingResult['id']); ?>">
                                    <td>
                                        <select name="assessment_type" class="form-control form-control-sm">
                                            <option value="test" <?php echo $existingResult['assessment_type'] == 'test' ? 'selected' : ''; ?>>Test</option>
                                            <option value="exam" <?php echo $existingResult['assessment_type'] == 'exam' ? 'selected' : ''; ?>>Exam</option>
                                            <option value="assignment" <?php echo $existingResult['assessment_type'] == 'assignment' ? 'selected' : ''; ?>>Assignment</option>
                                            <option value="project" <?php echo $existingResult['assessment_type'] == 'project' ? 'selected' : ''; ?>>Project</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" name="score" class="form-control form-control-sm"
                                               value="<?php echo e($existingResult['score']); ?>" step="0.01" required>
                                    </td>
                                    <td>
                                        <input type="number" name="max_score" class="form-control form-control-sm"
                                               value="<?php echo e($existingResult['max_score']); ?>" step="0.01" required>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo e(strtolower($existingResult['grade'])); ?>">
                                            <?php echo e($existingResult['grade']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <input type="text" name="remarks" class="form-control form-control-sm"
                                               value="<?php echo htmlspecialchars($existingResult['remarks'] ?? ''); ?>">
                                    </td>
                                    <td>
                                        <button type="submit" class="btn btn-sm btn-success" title="Update">
                                            <i class="fas fa-save"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger"
                                                onclick="deleteResult(<?php echo e($existingResult['id']); ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </form>

                                <?php else: ?>
                                <!-- Add new result -->
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                    <input type="hidden" name="action" value="add_result">
                                    <input type="hidden" name="student_id" value="<?php echo e($student['id']); ?>">
                                    <input type="hidden" name="subject_id" value="<?php echo e($selectedSubject); ?>">
                                    <input type="hidden" name="class_id" value="<?php echo e($selectedClass); ?>">
                                    <input type="hidden" name="term" value="<?php echo e($selectedTerm); ?>">
                                    <input type="hidden" name="academic_year" value="<?php echo e($selectedYear); ?>">
                                    <td>
                                        <select name="assessment_type" class="form-control form-control-sm" required>
                                            <option value="test">Test</option>
                                            <option value="exam">Exam</option>
                                            <option value="assignment">Assignment</option>
                                            <option value="project">Project</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" name="score" class="form-control form-control-sm"
                                               step="0.01" required>
                                    </td>
                                    <td>
                                        <input type="number" name="max_score" class="form-control form-control-sm"
                                               value="100" step="0.01" required>
                                    </td>
                                    <td>-</td>
                                    <td>
                                        <input type="text" name="remarks" class="form-control form-control-sm">
                                    </td>
                                    <td>
                                        <button type="submit" class="btn btn-sm btn-primary">
                                            <i class="fas fa-plus"></i> Add
                                        </button>
                                    </td>
                                </form>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Results Summary -->
        <?php if (!empty($existingResults)): ?>
        <div class="card">
            <div class="card-header">
                <h3>Results Summary</h3>
                <button class="btn btn-sm btn-success" onclick="exportToExcel()">
                    <i class="fas fa-file-excel"></i> Export
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Admission No.</th>
                                <th>Student Name</th>
                                <th>Assessment</th>
                                <th>Score</th>
                                <th>Percentage</th>
                                <th>Grade</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $totalPercentage = 0;
                            $count = 0;
                            foreach ($existingResults as $result):
                                $percentage = ($result['score'] / $result['max_score']) * 100;
                                $totalPercentage += $percentage;
                                $count++;
                            ?>
                            <tr>
                                <td><?php echo e($result['admission_number']); ?></td>
                                <td><?php echo htmlspecialchars($result['first_name'] . ' ' . $result['last_name']); ?></td>
                                <td><?php echo e(ucfirst($result['assessment_type'])); ?></td>
                                <td><?php echo e($result['score']); ?>/<?php echo e($result['max_score']); ?></td>
                                <td><?php echo round($percentage, 1); ?>%</td>
                                <td><span class="badge badge-<?php echo e(strtolower($result['grade'])); ?>"><?php echo e($result['grade']); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if ($count > 0): ?>
                        <tfoot>
                            <tr>
                                <th colspan="4" class="text-right">Class Average:</th>
                                <th><?php echo round($totalPercentage / $count, 1); ?>%</th>
                                <th></th>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </main>
</div>

<!-- Delete Form (hidden) -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
    <input type="hidden" name="action" value="delete_result">
    <input type="hidden" name="result_id" id="delete_id">
</form>

<script>
function deleteResult(id) {
    if (confirm('Are you sure you want to delete this result?')) {
        document.getElementById('delete_id').value = id;
        document.getElementById('deleteForm').submit();
    }
}

function exportToExcel() {
    const table = document.querySelector('.data-table');
    const rows = [];

    // Get headers
    const headers = [];
    table.querySelectorAll('thead th').forEach(th => {
        headers.push(th.textContent);
    });
    rows.push(headers.join(','));

    // Get data rows
    table.querySelectorAll('tbody tr').forEach(tr => {
        const row = [];
        tr.querySelectorAll('td').forEach(td => {
            row.push('"' + td.textContent.trim() + '"');
        });
        rows.push(row.join(','));
    });

    const csv = rows.join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'results_<?php echo $selectedClass . '_' . $selectedSubject . '_' . $selectedTerm; ?>.csv';
    a.click();
}
</script>

<style>
.badge-a { background-color: #28a745; color: white; }
.badge-b { background-color: #17a2b8; color: white; }
.badge-c { background-color: #ffc107; color: #333; }
.badge-d { background-color: #fd7e14; color: white; }
.badge-e { background-color: #6c757d; color: white; }
.badge-f { background-color: #dc3545; color: white; }

.form-control-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
    width: 100px;
}

.btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}
</style>

<?php
include '../includes/footer.php';
?>