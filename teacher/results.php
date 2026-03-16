<?php
// teacher/results.php - Manage Student Results
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/security.php';

Security::requireRole('teacher');

$pageTitle = 'Manage Results';
$extraCSS = ['dashboard.css'];
$extraJS = ['results.js'];

include '../includes/header.php';

$db = db();
$userId = $_SESSION['user_id'];
$message = '';
$messageType = '';

// Get teacher info
$teacher = $db->getRow(
    "SELECT t.*, u.first_name, u.last_name 
     FROM teachers t 
     JOIN users u ON t.user_id = u.id 
     WHERE t.user_id = ?",
    [$userId]
);

// Get teacher's classes
$classes = $db->getRows(
    "SELECT c.* FROM classes c 
     WHERE c.teacher_id = ? AND c.is_active = 1
     ORDER BY c.class_name",
    [$teacher['id']]
);

// Get teacher's subjects
$subjects = $db->getRows(
    "SELECT s.*, c.class_name 
     FROM subjects s
     JOIN classes c ON s.class_id = c.id
     WHERE s.teacher_id = ? AND s.is_active = 1
     ORDER BY c.class_name, s.subject_name",
    [$teacher['id']]
);

// Get selected filters
$selectedClass = $_GET['class_id'] ?? '';
$selectedSubject = $_GET['subject_id'] ?? '';
$selectedTerm = $_GET['term'] ?? 'Term 1';
$selectedYear = $_GET['academic_year'] ?? (date('Y') . '-' . (date('Y') + 1));

// Get students for selected class
$students = [];
if ($selectedClass) {
    $students = $db->getRows(
        "SELECT s.id, u.first_name, u.last_name, s.admission_number
         FROM students s
         JOIN users u ON s.user_id = u.id
         WHERE s.class_id = ? AND u.is_active = 1
         ORDER BY u.first_name",
        [$selectedClass]
    );
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'add_result':
                $studentId = Security::sanitize($_POST['student_id']);
                $subjectId = Security::sanitize($_POST['subject_id']);
                $classId = Security::sanitize($_POST['class_id']);
                $term = Security::sanitize($_POST['term']);
                $academicYear = Security::sanitize($_POST['academic_year']);
                $assessmentType = Security::sanitize($_POST['assessment_type']);
                $score = Security::sanitize($_POST['score']);
                $maxScore = Security::sanitize($_POST['max_score'] ?? 100);
                $remarks = Security::sanitize($_POST['remarks'] ?? '');
                
                // Calculate grade
                $percentage = ($score / $maxScore) * 100;
                if ($percentage >= 70) $grade = 'A';
                elseif ($percentage >= 60) $grade = 'B';
                elseif ($percentage >= 50) $grade = 'C';
                elseif ($percentage >= 45) $grade = 'D';
                elseif ($percentage >= 40) $grade = 'E';
                else $grade = 'F';
                
                try {
                    $db->insert(
                        "INSERT INTO results (student_id, subject_id, class_id, term, academic_year, 
                         assessment_type, score, max_score, grade, remarks, entered_by) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$studentId, $subjectId, $classId, $term, $academicYear, 
                         $assessmentType, $score, $maxScore, $grade, $remarks, $userId]
                    );
                    
                    Security::logAudit('ADDED_RESULT', 'results');
                    $message = 'Result added successfully';
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = 'Error adding result: ' . $e->getMessage();
                    $messageType = 'error';
                }
                break;
                
            case 'update_result':
                $resultId = Security::sanitize($_POST['result_id']);
                $score = Security::sanitize($_POST['score']);
                $maxScore = Security::sanitize($_POST['max_score'] ?? 100);
                $remarks = Security::sanitize($_POST['remarks'] ?? '');
                
                // Recalculate grade
                $percentage = ($score / $maxScore) * 100;
                if ($percentage >= 70) $grade = 'A';
                elseif ($percentage >= 60) $grade = 'B';
                elseif ($percentage >= 50) $grade = 'C';
                elseif ($percentage >= 45) $grade = 'D';
                elseif ($percentage >= 40) $grade = 'E';
                else $grade = 'F';
                
                try {
                    $db->query(
                        "UPDATE results SET score = ?, max_score = ?, grade = ?, remarks = ? 
                         WHERE id = ? AND entered_by = ?",
                        [$score, $maxScore, $grade, $remarks, $resultId, $userId]
                    );
                    
                    Security::logAudit('UPDATED_RESULT', 'results', $resultId);
                    $message = 'Result updated successfully';
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = 'Error updating result: ' . $e->getMessage();
                    $messageType = 'error';
                }
                break;
                
            case 'delete_result':
                $resultId = Security::sanitize($_POST['result_id']);
                
                try {
                    $db->query(
                        "DELETE FROM results WHERE id = ? AND entered_by = ?",
                        [$resultId, $userId]
                    );
                    
                    Security::logAudit('DELETED_RESULT', 'results', $resultId);
                    $message = 'Result deleted successfully';
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = 'Error deleting result: ' . $e->getMessage();
                    $messageType = 'error';
                }
                break;
        }
    }
}

// Get existing results for the selected filters
$existingResults = [];
if ($selectedClass && $selectedSubject && $selectedTerm) {
    $existingResults = $db->getRows(
        "SELECT r.*, u.first_name, u.last_name, s.admission_number
         FROM results r
         JOIN students s ON r.student_id = s.id
         JOIN users u ON s.user_id = u.id
         WHERE r.class_id = ? AND r.subject_id = ? AND r.term = ? AND r.academic_year = ?
         ORDER BY u.first_name",
        [$selectedClass, $selectedSubject, $selectedTerm, $selectedYear]
    );
}
?>

<div class="dashboard-container">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <h3>Teacher Panel</h3>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="classes.php"><i class="fas fa-school"></i> My Classes</a></li>
                <li><a href="attendance.php"><i class="fas fa-calendar-check"></i> Attendance</a></li>
                <li class="active"><a href="results.php"><i class="fas fa-chart-line"></i> Results</a></li>
                <li><a href="assignments.php"><i class="fas fa-tasks"></i> Assignments</a></li>
                <li><a href="students.php"><i class="fas fa-user-graduate"></i> Students</a></li>
                <li><a href="messages.php"><i class="fas fa-envelope"></i> Messages</a></li>
                <li><a href="profile.php"><i class="fas fa-user-cog"></i> Profile</a></li>
            </ul>
        </nav>
    </aside>
    
    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>Manage Results</h1>
        </div>
        
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible">
            <?php echo $message; ?>
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
                            <option value="<?php echo $class['id']; ?>" <?php echo $selectedClass == $class['id'] ? 'selected' : ''; ?>>
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
                            <option value="<?php echo $subject['id']; ?>" <?php echo $selectedSubject == $subject['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subject['subject_name']); ?> (<?php echo $subject['class_name']; ?>)
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
                               value="<?php echo $selectedYear; ?>" placeholder="YYYY-YYYY">
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
                                    <input type="hidden" name="result_id" value="<?php echo $existingResult['id']; ?>">
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
                                               value="<?php echo $existingResult['score']; ?>" step="0.01" required>
                                    </td>
                                    <td>
                                        <input type="number" name="max_score" class="form-control form-control-sm" 
                                               value="<?php echo $existingResult['max_score']; ?>" step="0.01" required>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo strtolower($existingResult['grade']); ?>">
                                            <?php echo $existingResult['grade']; ?>
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
                                                onclick="deleteResult(<?php echo $existingResult['id']; ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </form>
                                
                                <?php else: ?>
                                <!-- Add new result -->
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                    <input type="hidden" name="action" value="add_result">
                                    <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                    <input type="hidden" name="subject_id" value="<?php echo $selectedSubject; ?>">
                                    <input type="hidden" name="class_id" value="<?php echo $selectedClass; ?>">
                                    <input type="hidden" name="term" value="<?php echo $selectedTerm; ?>">
                                    <input type="hidden" name="academic_year" value="<?php echo $selectedYear; ?>">
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
                                <td><?php echo $result['admission_number']; ?></td>
                                <td><?php echo htmlspecialchars($result['first_name'] . ' ' . $result['last_name']); ?></td>
                                <td><?php echo ucfirst($result['assessment_type']); ?></td>
                                <td><?php echo $result['score']; ?>/<?php echo $result['max_score']; ?></td>
                                <td><?php echo round($percentage, 1); ?>%</td>
                                <td><span class="badge badge-<?php echo strtolower($result['grade']); ?>"><?php echo $result['grade']; ?></span></td>
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