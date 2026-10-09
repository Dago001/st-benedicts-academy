<?php
// admin/results.php - Results Management
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';

Security::requireRole('admin');

$pageTitle = 'Results Management';
$extraCSS = ['admin.css', 'dashboard.css'];
$extraJS = ['results.js', 'charts.js'];

include __DIR__ . '/../includes/header.php';

// Get database instance
try {
    $db = Database::getInstance();
} catch (Exception $e) {
    die("Database connection error: " . $e->getMessage());
}

[$message, $messageType] = flash_get();

// Handle actions
$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$id = isset($_POST['id']) ? (int)$_POST['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : null);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token';
        $messageType = 'error';
    } else {
        $postAction = $_POST['action'] ?? '';

        switch ($postAction) {
            case 'add_result':
            case 'edit_result':
                // Validate required fields
                $studentId = (int)($_POST['student_id'] ?? 0);
                $subjectId = (int)($_POST['subject_id'] ?? 0);
                $classId = (int)($_POST['class_id'] ?? 0);
                $term = Security::sanitize($_POST['term'] ?? '');
                $academicYear = Security::sanitize($_POST['academic_year'] ?? '');
                $assessmentType = Security::sanitize($_POST['assessment_type'] ?? '');
                $score = is_numeric($_POST['score'] ?? null) ? (float)$_POST['score'] : -1;
                $maxScore = is_numeric($_POST['max_score'] ?? null) ? (float)$_POST['max_score'] : 100;
                $remarks = Security::sanitize($_POST['remarks'] ?? '');

                // Validate required fields
                if (!$studentId || !$subjectId || !$classId || empty($term) || empty($academicYear) || empty($assessmentType) || $score < 0) {
                    $message = 'Please fill in all required fields';
                    $messageType = 'error';
                    break;
                }

                // Validate academic year format
                if (!preg_match('/^\d{4}-\d{4}$/', $academicYear)) {
                    $message = 'Academic year must be in format YYYY-YYYY';
                    $messageType = 'error';
                    break;
                }

                if (!in_array($assessmentType, ['test', 'exam', 'assignment', 'project'], true)
                    || !in_array($term, ['Term 1', 'Term 2', 'Term 3'], true)) {
                    $message = 'Invalid term or assessment type';
                    $messageType = 'error';
                    break;
                }
                if ($maxScore <= 0 || $maxScore > 1000 || $score > $maxScore) {
                    $message = 'Score must be between 0 and the maximum score';
                    $messageType = 'error';
                    break;
                }
                $studentRow = $db->getRow('SELECT class_id FROM students WHERE id = ?', [$studentId]);
                $subjectRow = $db->getRow('SELECT class_id FROM subjects WHERE id = ?', [$subjectId]);
                if (!$studentRow || !$subjectRow || (int)$studentRow['class_id'] !== $classId || (int)$subjectRow['class_id'] !== $classId) {
                    $message = 'Student and subject must belong to the selected class';
                    $messageType = 'error';
                    break;
                }

                $grade = letterGrade($score, $maxScore);

                try {
                    if ($postAction === 'add_result') {
                        // Check if result already exists
                        $existing = $db->getRow(
                            "SELECT id FROM results
                             WHERE student_id = ? AND subject_id = ? AND assessment_type = ?
                             AND term = ? AND academic_year = ?",
                            [$studentId, $subjectId, $assessmentType, $term, $academicYear]
                        );

                        if ($existing) {
                            throw new Exception("Result already exists for this student, subject, assessment type and term");
                        }

                        $result = $db->insert(
                            "INSERT INTO results (student_id, subject_id, class_id, term, academic_year,
                             assessment_type, score, max_score, grade, remarks, entered_by, is_approved)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)",
                            [
                                $studentId,
                                $subjectId,
                                $classId,
                                $term,
                                $academicYear,
                                $assessmentType,
                                $score,
                                $maxScore,
                                $grade,
                                $remarks,
                                $_SESSION['user_id']
                            ]
                        );

                        if (!$result) {
                            throw new Exception("Failed to insert result");
                        }

                        Security::logAudit('ADDED_RESULT', 'results');
                        $message = 'Result added successfully';
                        $messageType = 'success';

                    } else {
                        if (!$id || !$db->getRow('SELECT id FROM results WHERE id = ?', [$id])) {
                            throw new Exception("Result not found");
                        }
                        if ($db->getRow('SELECT id FROM results WHERE student_id = ? AND subject_id = ? AND assessment_type = ? AND term = ? AND academic_year = ? AND id <> ?',
                                        [$studentId, $subjectId, $assessmentType, $term, $academicYear, $id])) {
                            throw new Exception("Another result already exists for this student, subject, assessment type and term");
                        }

                        $result = $db->query(
                            "UPDATE results SET student_id = ?, subject_id = ?, class_id = ?, term = ?,
                             academic_year = ?, assessment_type = ?, score = ?, max_score = ?,
                             grade = ?, remarks = ? WHERE id = ?",
                            [
                                $studentId,
                                $subjectId,
                                $classId,
                                $term,
                                $academicYear,
                                $assessmentType,
                                $score,
                                $maxScore,
                                $grade,
                                $remarks,
                                $id
                            ]
                        );

                        if (!$result) {
                            throw new Exception("Failed to update result");
                        }

                        Security::logAudit('UPDATED_RESULT', 'results', $id);
                        $message = 'Result updated successfully';
                        $messageType = 'success';
                    }

                } catch (Exception $e) {
                    $message = 'Error: ' . $e->getMessage();
                    $messageType = 'error';
                    error_log("Result error: " . $e->getMessage());
                }
                break;

            case 'approve_results':
                $resultIds = $_POST['result_ids'] ?? [];
                if (!is_array($resultIds)) $resultIds = explode(',', (string)$resultIds);
                $resultIds = array_values(array_filter(array_map('intval', $resultIds)));

                if (empty($resultIds)) {
                    $message = 'No results selected';
                    $messageType = 'error';
                    break;
                }

                $placeholders = implode(',', array_fill(0, count($resultIds), '?'));
                $params = array_merge([$_SESSION['user_id']], $resultIds);

                try {
                    $db->query(
                        "UPDATE results SET is_approved = 1, approved_by = ?, approved_at = NOW()
                         WHERE id IN ($placeholders)",
                        $params
                    );

                    Security::logAudit('APPROVED_RESULTS', 'results');
                    $message = 'Results approved successfully';
                    $messageType = 'success';

                } catch (Exception $e) {
                    $message = 'Error: ' . $e->getMessage();
                    $messageType = 'error';
                    error_log("Approve results error: " . $e->getMessage());
                }
                break;

            case 'delete_result':
                try {
                    if (!$id) {
                        throw new Exception("Invalid result ID");
                    }

                    $db->query("DELETE FROM results WHERE id = ?", [$id]);
                    if ($db->rowCount() === 0) {
                        throw new Exception("Result not found");
                    }

                    Security::logAudit('DELETED_RESULT', 'results', $id);
                    $message = 'Result deleted successfully';
                    $messageType = 'success';

                } catch (Exception $e) {
                    $message = 'Error: ' . $e->getMessage();
                    $messageType = 'error';
                }
                break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $messageType === 'success') {
    flash_redirect($message, 'success', BASE_URL . '/admin/results.php?' . http_build_query(array_filter([
        'class_id' => $_POST['class_id'] ?? null, 'term' => $_POST['term'] ?? null, 'academic_year' => $_POST['academic_year'] ?? null,
    ])));
}

// Get classes for filter
$classes = $db->getRows(
    "SELECT * FROM classes WHERE is_active = 1 ORDER BY class_name, section"
);

// Get selected filters
$selectedClass = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$selectedTerm = in_array($_GET['term'] ?? '', ['Term 1', 'Term 2', 'Term 3'], true) ? $_GET['term'] : '';
$selectedYear = preg_match('/^\d{4}-\d{4}$/', $_GET['academic_year'] ?? '') ? $_GET['academic_year'] : currentAcademicYear();
$selectedSubject = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;

// Get subjects for selected class
$subjects = [];
if ($selectedClass) {
    $subjects = $db->getRows(
        "SELECT * FROM subjects WHERE class_id = ? AND is_active = 1 ORDER BY subject_name",
        [$selectedClass]
    );
}

// Get students for the selected class (for add/edit form)
$students = [];
if ($selectedClass) {
    $students = $db->getRows(
        "SELECT s.id, s.admission_number, u.first_name, u.last_name
         FROM students s
         JOIN users u ON s.user_id = u.id
         WHERE s.class_id = ? AND u.is_active = 1
         ORDER BY u.first_name, u.last_name",
        [$selectedClass]
    );
}

// Get result for editing
$result = null;
if ($action === 'edit' && $id) {
    $result = $db->getRow(
        "SELECT * FROM results WHERE id = ?",
        [$id]
    );
}

// Get results with filters
$results = [];
if ($selectedClass && $selectedTerm) {
    $query = "SELECT r.*,
                     CONCAT(u.first_name, ' ', u.last_name) as student_name,
                     s.admission_number,
                     sub.subject_name,
                     c.class_name, c.section,
                     CONCAT(au.first_name, ' ', au.last_name) as approved_by_name,
                     CONCAT(eu.first_name, ' ', eu.last_name) as entered_by_name
              FROM results r
              JOIN students s ON r.student_id = s.id
              JOIN users u ON s.user_id = u.id
              JOIN subjects sub ON r.subject_id = sub.id
              JOIN classes c ON r.class_id = c.id
              LEFT JOIN users au ON r.approved_by = au.id
              LEFT JOIN users eu ON r.entered_by = eu.id
              WHERE r.class_id = ? AND r.term = ? AND r.academic_year = ?";

    $params = [$selectedClass, $selectedTerm, $selectedYear];

    if ($selectedSubject) {
        $query .= " AND r.subject_id = ?";
        $params[] = $selectedSubject;
    }

    $query .= " ORDER BY sub.subject_name, u.first_name";

    $results = $db->getRows($query, $params);
}

// Get pending approvals
$pendingApprovals = $db->getRows(
    "SELECT r.*,
            CONCAT(u.first_name, ' ', u.last_name) as student_name,
            s.admission_number,
            sub.subject_name,
            c.class_name, c.section,
            CONCAT(eu.first_name, ' ', eu.last_name) as entered_by_name
     FROM results r
     JOIN students s ON r.student_id = s.id
     JOIN users u ON s.user_id = u.id
     JOIN subjects sub ON r.subject_id = sub.id
     JOIN classes c ON r.class_id = c.id
     LEFT JOIN users eu ON r.entered_by = eu.id
     WHERE r.is_approved = 0
     ORDER BY r.created_at DESC
     LIMIT 20"
);

// Get available terms for dropdown
$availableTerms = [
    'Term 1' => 'First Term',
    'Term 2' => 'Second Term',
    'Term 3' => 'Third Term'
];

// Get class info for display
$classInfo = null;
if ($selectedClass) {
    $classInfo = $db->getRow(
        "SELECT class_name, section FROM classes WHERE id = ?",
        [$selectedClass]
    );
}

// Helper function to get grade class
function getGradeClass($grade) {
    switch($grade) {
        case 'A': return 'badge-success';
        case 'B': return 'badge-info';
        case 'C': return 'badge-warning';
        case 'D': return 'badge-warning';
        case 'E': return 'badge-secondary';
        case 'F': return 'badge-danger';
        default: return 'badge-secondary';
    }
}
?>

<style>
/* Modal positioning fix */
.modal {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0,0,0,0.5);
}

.modal-content {
    background-color: #fefefe;
    margin: 5% auto;
    padding: 0;
    border: 1px solid #888;
    width: 90%;
    max-width: 700px;
    border-radius: 10px;
    box-shadow: 0 5px 30px rgba(0,0,0,0.3);
    position: relative;
    z-index: 10000;
}

.modal-header {
    padding: 15px 20px;
    border-bottom: 1px solid #dee2e6;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(135deg, var(--navy), var(--navy-dark));
    color: white;
    border-radius: 10px 10px 0 0;
}

.modal-header h3 {
    margin: 0;
    color: white;
}

.modal-header .close {
    background: none;
    border: none;
    color: white;
    font-size: 24px;
    cursor: pointer;
    opacity: 0.8;
}

.modal-header .close:hover {
    opacity: 1;
}

.modal-body {
    padding: 20px;
    max-height: 70vh;
    overflow-y: auto;
}

.modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #dee2e6;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

/* Action buttons */
.action-buttons {
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
}

.btn-icon {
    width: 32px;
    height: 32px;
    border-radius: 4px;
    border: none;
    background: #f0f0f0;
    color: #333;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    text-decoration: none;
}

.btn-icon:hover {
    background: var(--gold);
    color: var(--navy);
}

.btn-icon.text-danger:hover {
    background: #dc3545;
    color: white;
}

/* Badge styles */
.badge-success {
    background-color: #d4edda;
    color: #155724;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

.badge-warning {
    background-color: #fff3cd;
    color: #856404;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

.badge-info {
    background-color: #d1ecf1;
    color: #0c5460;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

.badge-danger {
    background-color: #f8d7da;
    color: #721c24;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

.badge-secondary {
    background-color: #e2e3e5;
    color: #383d41;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

/* Grade badges */
.badge-a {
    background-color: #28a745;
    color: white;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

.badge-b {
    background-color: #17a2b8;
    color: white;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

.badge-c {
    background-color: #ffc107;
    color: #333;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

.badge-d {
    background-color: #fd7e14;
    color: white;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

.badge-e {
    background-color: #6c757d;
    color: white;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

.badge-f {
    background-color: #dc3545;
    color: white;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

/* Table row colors */
.table-success { background-color: rgba(40, 167, 69, 0.1); }
.table-info { background-color: rgba(23, 162, 184, 0.1); }
.table-warning { background-color: rgba(255, 193, 7, 0.1); }
.table-danger { background-color: rgba(220, 53, 69, 0.1); }

/* Card tools */
.card-tools {
    display: flex;
    gap: 10px;
}

.btn-sm {
    padding: 5px 10px;
    font-size: 12px;
    border-radius: 4px;
}

/* Form styles */
.form-section {
    margin-bottom: 20px;
    padding: 15px;
    background-color: #f8f9fa;
    border-radius: 8px;
}

.form-section h3 {
    margin-top: 0;
    margin-bottom: 15px;
    font-size: 16px;
    color: var(--navy);
    display: flex;
    align-items: center;
    gap: 8px;
}

.form-section h3 i {
    color: var(--gold);
}

/* Stats grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    display: flex;
    align-items: center;
    gap: 15px;
    transition: all 0.3s ease;
    border-bottom: 3px solid transparent;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
    border-bottom-color: var(--gold);
}

.stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    flex-shrink: 0;
}

.stat-content h3 {
    font-size: 24px;
    font-weight: 700;
    margin: 0;
    color: var(--navy);
    line-height: 1.2;
}

.stat-content p {
    margin: 5px 0 0;
    color: var(--gray);
    font-size: 13px;
}

@media (max-width: 768px) {
    .action-buttons {
        justify-content: center;
    }

    .stats-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="dashboard-container">
    <?php render_sidebar('admin'); ?>

    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>Results Management</h1>
            <div class="header-actions">
                <?php if ($action === 'add'): ?>
                <a href="results.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
                <?php else: ?>
                <a href="?action=add" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add Result
                </a>
                <?php endif; ?>
                <a href="export.php?type=results" class="btn btn-outline">
                    <i class="fas fa-download"></i> Export
                </a>
            </div>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?php echo e($messageType); ?> alert-dismissible">
            <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="close" onclick="this.parentElement.remove()">&times;</button>
        </div>
        <?php endif; ?>

        <!-- Pending Approvals Alert -->
        <?php if (!empty($pendingApprovals) && $action === 'list'): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <strong><?php echo count($pendingApprovals); ?> results pending approval.</strong>
            <a href="#pendingApprovals" class="alert-link">Review now</a>
        </div>
        <?php endif; ?>

        <?php if ($action === 'add' || $action === 'edit'): ?>
        <!-- Add/Edit Result Form -->
        <div class="card">
            <div class="card-header">
                <h3><?php echo $action === 'add' ? 'Add New Result' : 'Edit Result'; ?></h3>
            </div>
            <div class="card-body">
                <form method="POST" class="form-container" id="resultForm">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="<?php echo e($action); ?>">
                    <?php if ($action === 'edit'): ?>
                    <input type="hidden" name="id" value="<?php echo e($id); ?>">
                    <?php endif; ?>

                    <div class="form-section">
                        <h3><i class="fas fa-graduation-cap"></i> Select Student and Subject</h3>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="class_id">Class *</label>
                                <select id="class_id" name="class_id" class="form-control" required onchange="updateStudentsAndSubjects()">
                                    <option value="">-- Select Class --</option>
                                    <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo e($class['id']); ?>"
                                        <?php echo ($selectedClass == $class['id'] || ($result && $result['class_id'] == $class['id'])) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name'] . ' ' . ($class['section'] ?? '')); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group col-md-6">
                                <label for="student_id">Student *</label>
                                <select id="student_id" name="student_id" class="form-control" required>
                                    <option value="">-- Select Student --</option>
                                    <?php if ($selectedClass || ($result && $result['class_id'])):
                                        $classId = $selectedClass ?: ($result ? $result['class_id'] : 0);
                                        $studentList = $db->getRows(
                                            "SELECT s.id, u.first_name, u.last_name, s.admission_number
                                             FROM students s
                                             JOIN users u ON s.user_id = u.id
                                             WHERE s.class_id = ? AND u.is_active = 1
                                             ORDER BY u.first_name",
                                            [$classId]
                                        );
                                        foreach ($studentList as $student):
                                    ?>
                                    <option value="<?php echo e($student['id']); ?>"
                                        <?php echo ($result && $result['student_id'] == $student['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name'] . ' (' . $student['admission_number'] . ')'); ?>
                                    </option>
                                    <?php
                                        endforeach;
                                    endif; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="subject_id">Subject *</label>
                                <select id="subject_id" name="subject_id" class="form-control" required>
                                    <option value="">-- Select Subject --</option>
                                    <?php if ($selectedClass || ($result && $result['class_id'])):
                                        $classId = $selectedClass ?: ($result ? $result['class_id'] : 0);
                                        $subjectList = $db->getRows(
                                            "SELECT * FROM subjects WHERE class_id = ? AND is_active = 1 ORDER BY subject_name",
                                            [$classId]
                                        );
                                        foreach ($subjectList as $subject):
                                    ?>
                                    <option value="<?php echo e($subject['id']); ?>"
                                        <?php echo ($result && $result['subject_id'] == $subject['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </option>
                                    <?php
                                        endforeach;
                                    endif; ?>
                                </select>
                            </div>

                            <div class="form-group col-md-6">
                                <label for="assessment_type">Assessment Type *</label>
                                <select id="assessment_type" name="assessment_type" class="form-control" required>
                                    <option value="">-- Select Type --</option>
                                    <option value="test" <?php echo ($result && $result['assessment_type'] == 'test') ? 'selected' : ''; ?>>Test</option>
                                    <option value="exam" <?php echo ($result && $result['assessment_type'] == 'exam') ? 'selected' : ''; ?>>Exam</option>
                                    <option value="assignment" <?php echo ($result && $result['assessment_type'] == 'assignment') ? 'selected' : ''; ?>>Assignment</option>
                                    <option value="project" <?php echo ($result && $result['assessment_type'] == 'project') ? 'selected' : ''; ?>>Project</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-pencil-alt"></i> Enter Scores</h3>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="score">Score *</label>
                                <input type="number" id="score" name="score" class="form-control"
                                       value="<?php echo $result['score'] ?? ''; ?>" step="0.01" min="0" required
                                       onchange="calculateGrade()">
                            </div>

                            <div class="form-group col-md-6">
                                <label for="max_score">Maximum Score *</label>
                                <input type="number" id="max_score" name="max_score" class="form-control"
                                       value="<?php echo $result['max_score'] ?? 100; ?>" step="0.01" min="0" required
                                       onchange="calculateGrade()">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="grade">Grade (Auto-calculated)</label>
                                <input type="text" id="grade" name="grade_display" class="form-control"
                                       value="<?php
                                            if ($result) {
                                                echo $result['grade'];
                                            } elseif (isset($_POST['score']) && isset($_POST['max_score'])) {
                                                $pct = ($_POST['score'] / $_POST['max_score']) * 100;
                                                if ($pct >= 70) echo 'A';
                                                elseif ($pct >= 60) echo 'B';
                                                elseif ($pct >= 50) echo 'C';
                                                elseif ($pct >= 45) echo 'D';
                                                elseif ($pct >= 40) echo 'E';
                                                else echo 'F';
                                            }
                                       ?>" readonly disabled>
                                <small class="form-text text-muted">Grade is automatically calculated based on score</small>
                            </div>

                            <div class="form-group col-md-6">
                                <label for="term">Term *</label>
                                <select id="term" name="term" class="form-control" required>
                                    <option value="">-- Select Term --</option>
                                    <?php foreach ($availableTerms as $termKey => $termName): ?>
                                    <option value="<?php echo e($termKey); ?>"
                                        <?php echo ($result && $result['term'] == $termKey) ? 'selected' : ''; ?>>
                                        <?php echo e($termName); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="academic_year">Academic Year *</label>
                                <input type="text" id="academic_year" name="academic_year" class="form-control"
                                       value="<?php echo $result['academic_year'] ?? (currentAcademicYear()); ?>"
                                       placeholder="YYYY-YYYY" required>
                                <small class="form-text text-muted">Format: 2024-2025</small>
                            </div>

                            <div class="form-group col-md-6">
                                <label for="remarks">Remarks</label>
                                <input type="text" id="remarks" name="remarks" class="form-control"
                                       value="<?php echo htmlspecialchars($result['remarks'] ?? ''); ?>"
                                       placeholder="Optional remarks">
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> <?php echo $action === 'add' ? 'Save Result' : 'Update Result'; ?>
                        </button>
                        <a href="results.php<?php echo $selectedClass ? '?class_id=' . $selectedClass . '&term=' . $selectedTerm . '&academic_year=' . $selectedYear : ''; ?>" class="btn btn-outline">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <script>
        function updateStudentsAndSubjects() {
            const classId = document.getElementById('class_id').value;
            if (classId) {
                window.location.href = '?action=<?php echo e($action); ?>&class_id=' + classId;
            }
        }

        function calculateGrade() {
            const score = parseFloat(document.getElementById('score').value) || 0;
            const maxScore = parseFloat(document.getElementById('max_score').value) || 100;

            if (score > 0 && maxScore > 0) {
                const percentage = (score / maxScore) * 100;
                let grade = '';

                if (percentage >= 70) grade = 'A';
                else if (percentage >= 60) grade = 'B';
                else if (percentage >= 50) grade = 'C';
                else if (percentage >= 45) grade = 'D';
                else if (percentage >= 40) grade = 'E';
                else grade = 'F';

                document.getElementById('grade').value = grade;
            }
        }
        </script>

        <?php else: ?>

        <!-- Summary Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(0,40,85,0.1);">
                    <i class="fas fa-chart-line" style="color: #002855;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo count($results); ?></h3>
                    <p>Results Loaded</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(40,167,69,0.1);">
                    <i class="fas fa-check-circle" style="color: #28a745;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo count(array_filter($results, function($r) { return $r['is_approved']; })); ?></h3>
                    <p>Approved</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(255,193,7,0.1);">
                    <i class="fas fa-clock" style="color: #ffc107;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo count(array_filter($results, function($r) { return !$r['is_approved']; })); ?></h3>
                    <p>Pending</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(23,162,184,0.1);">
                    <i class="fas fa-percent" style="color: #17a2b8;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo count($pendingApprovals); ?></h3>
                    <p>Awaiting Approval</p>
                </div>
            </div>
        </div>

        <!-- Filter Form -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-filter"></i> Filter Results</h3>
            </div>
            <div class="card-body">
                <form method="GET" class="form-row">
                    <div class="form-group col-md-3">
                        <label for="class_id">Class</label>
                        <select id="class_id" name="class_id" class="form-control" onchange="this.form.submit()">
                            <option value="">-- All Classes --</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo e($class['id']); ?>"
                                <?php echo $selectedClass == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name'] . ' ' . ($class['section'] ?? '')); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-md-3">
                        <label for="term">Term</label>
                        <select id="term" name="term" class="form-control" onchange="this.form.submit()">
                            <option value="">-- All Terms --</option>
                            <?php foreach ($availableTerms as $termKey => $termName): ?>
                            <option value="<?php echo e($termKey); ?>" <?php echo $selectedTerm == $termKey ? 'selected' : ''; ?>>
                                <?php echo e($termName); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-md-3">
                        <label for="academic_year">Academic Year</label>
                        <input type="text" id="academic_year" name="academic_year" class="form-control"
                               value="<?php echo htmlspecialchars($selectedYear); ?>"
                               placeholder="YYYY-YYYY" onchange="this.form.submit()">
                    </div>

                    <div class="form-group col-md-3">
                        <label for="subject_id">Subject</label>
                        <select id="subject_id" name="subject_id" class="form-control" onchange="this.form.submit()">
                            <option value="0">-- All Subjects --</option>
                            <?php foreach ($subjects as $subject): ?>
                            <option value="<?php echo e($subject['id']); ?>"
                                <?php echo $selectedSubject == $subject['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($selectedClass && $selectedTerm): ?>
        <!-- Results Table -->
        <div class="card">
            <div class="card-header">
                <h3>
                    <i class="fas fa-chart-line"></i>
                    Results -
                    <?php if ($classInfo): ?>
                        <?php echo htmlspecialchars($classInfo['class_name'] . ' ' . ($classInfo['section'] ?? '')); ?>
                    <?php else: ?>
                        Selected Class
                    <?php endif; ?>
                    - <?php echo htmlspecialchars($selectedTerm . ' ' . $selectedYear); ?>
                </h3>
                <?php if (!empty($results)): ?>
                <div class="card-tools">
                    <button class="btn btn-sm btn-success" onclick="approveSelected()">
                        <i class="fas fa-check-circle"></i> Approve Selected
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!empty($results)): ?>
                <form id="approveForm" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="approve_results">

                    <div class="table-responsive">
                        <table class="data-table" id="resultsTable">
                            <thead>
                                <tr>
                                    <th width="30"><input type="checkbox" id="selectAll"></th>
                                    <th>Admission No.</th>
                                    <th>Student Name</th>
                                    <th>Subject</th>
                                    <th>Assessment</th>
                                    <th>Score</th>
                                    <th>Max</th>
                                    <th>%</th>
                                    <th>Grade</th>
                                    <th>Status</th>
                                    <th>Entered By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $result):
                                    $percentage = ($result['score'] / $result['max_score']) * 100;
                                    $rowClass = '';
                                    if ($percentage >= 70) $rowClass = 'table-success';
                                    elseif ($percentage >= 50) $rowClass = 'table-info';
                                    elseif ($percentage >= 40) $rowClass = 'table-warning';
                                    else $rowClass = 'table-danger';
                                ?>
                                <tr class="<?php echo e($rowClass); ?>">
                                    <td>
                                        <?php if (!$result['is_approved']): ?>
                                        <input type="checkbox" class="select-item" name="result_ids[]" value="<?php echo e($result['id']); ?>">
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($result['admission_number']); ?></td>
                                    <td><?php echo htmlspecialchars($result['student_name']); ?></td>
                                    <td><?php echo htmlspecialchars($result['subject_name']); ?></td>
                                    <td><?php echo e(ucfirst($result['assessment_type'])); ?></td>
                                    <td><strong><?php echo e($result['score']); ?></strong></td>
                                    <td><?php echo e($result['max_score']); ?></td>
                                    <td><?php echo number_format($percentage, 1); ?>%</td>
                                    <td><span class="badge-<?php echo e(strtolower($result['grade'])); ?>"><?php echo e($result['grade']); ?></span></td>
                                    <td>
                                        <?php if ($result['is_approved']): ?>
                                        <span class="badge-success">Approved</span>
                                        <?php else: ?>
                                        <span class="badge-warning">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($result['entered_by_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="?action=edit&id=<?php echo e($result['id']); ?><?php echo $selectedClass ? '&class_id=' . $selectedClass : ''; ?>" class="btn-icon" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <button class="btn-icon text-danger"
                                                    onclick="deleteResult(<?php echo e($result['id']); ?>)"
                                                    title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>

                <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    No results found for the selected filters. <a href="?action=add&class_id=<?php echo e($selectedClass); ?>">Add a result</a>.
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Pending Approvals Section -->
        <?php if (!empty($pendingApprovals)): ?>
        <div class="card" id="pendingApprovals">
            <div class="card-header">
                <h3><i class="fas fa-clock"></i> Pending Approvals</h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Student</th>
                                <th>Class</th>
                                <th>Subject</th>
                                <th>Score</th>
                                <th>Entered By</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingApprovals as $pending): ?>
                            <tr>
                                <td><?php echo date('d M Y', strtotime($pending['created_at'])); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($pending['student_name']); ?><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($pending['admission_number']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($pending['class_name'] . ' ' . ($pending['section'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars($pending['subject_name']); ?></td>
                                <td><?php echo e($pending['score']); ?>/<?php echo e($pending['max_score']); ?></td>
                                <td><?php echo htmlspecialchars($pending['entered_by_name'] ?? 'N/A'); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-success" onclick="approveSingle(<?php echo e($pending['id']); ?>)">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </main>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Confirm Delete</h3>
            <button type="button" class="close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to delete this result?</p>
            <p class="text-danger">This action cannot be undone.</p>
        </div>
        <div class="modal-footer">
            <form method="POST" id="deleteForm">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="delete_result">
                <input type="hidden" name="id" id="deleteId">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete</button>
            </form>
        </div>
    </div>
</div>

<script>
// Select All functionality
document.getElementById('selectAll')?.addEventListener('change', function(e) {
    const checkboxes = document.querySelectorAll('.select-item');
    checkboxes.forEach(cb => cb.checked = e.target.checked);
});

function approveSelected() {
    const selected = document.querySelectorAll('.select-item:checked');
    if (selected.length === 0) {
        alert('Please select results to approve');
        return;
    }

    if (confirm(`Approve ${selected.length} selected results?`)) {
        document.getElementById('approveForm').submit();
    }
}

function approveSingle(id) {
    if (confirm('Approve this result?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="approve_results">
            <input type="hidden" name="result_ids[]" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function deleteResult(id) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteModal').style.display = 'block';
}

function closeModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('deleteModal');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
}

// Initialize DataTable
$(document).ready(function() {
    if ($.fn.DataTable) {
        $('#resultsTable').DataTable({
            pageLength: 25,
            order: [[2, 'asc']],
            columnDefs: [
                { orderable: false, targets: [0, 11] }
            ],
            language: {
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries"
            }
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
