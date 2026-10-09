<?php
// teacher/assignments.php - Manage Homework/Assignments
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';

Security::requireRole('teacher');

$pageTitle = 'Manage Assignments';
$extraCSS = ['dashboard.css'];
$extraJS = ['assignments.js'];

include __DIR__ . '/../includes/header.php';

$db = db();
$userId = (int)$_SESSION['user_id'];
[$message, $messageType] = flash_get();

$teacher = $db->getRow(
    "SELECT t.*, u.first_name, u.last_name FROM teachers t JOIN users u ON t.user_id = u.id WHERE t.user_id = ?",
    [$userId]
);
if (!$teacher) {
    echo '<div class="container" style="padding:24px"><div class="alert alert-error">Your teacher profile is incomplete. Please contact the school office.</div></div>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}
$teacherId = (int)$teacher['id'];

// Classes the teacher teaches a subject in (or leads)
$classes = $db->getRows(
    "SELECT c.* FROM classes c WHERE c.is_active = 1
       AND c.id IN (SELECT id FROM classes WHERE teacher_id = ? UNION SELECT class_id FROM subjects WHERE teacher_id = ?)
     ORDER BY c.class_name, c.section", [$teacherId, $teacherId]);

// Subjects the teacher teaches
$subjects = $db->getRows(
    "SELECT s.*, c.class_name FROM subjects s JOIN classes c ON s.class_id = c.id
     WHERE s.teacher_id = ? AND s.is_active = 1 ORDER BY c.class_name, s.subject_name", [$teacherId]);

$attachDir = UPLOAD_PATH . 'assignments/';
if (!is_dir($attachDir)) { @mkdir($attachDir, 0755, true); }

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'add_assignment':
            case 'edit_assignment':
                $data = [
                    'class_id' => (int)($_POST['class_id'] ?? 0),
                    'subject_id' => (int)($_POST['subject_id'] ?? 0),
                    'title' => Security::sanitize($_POST['title'] ?? ''),
                    'description' => Security::sanitize($_POST['description'] ?? ''),
                    'instructions' => Security::sanitize($_POST['instructions'] ?? ''),
                    'due_date' => valid_datetime($_POST['due_date'] ?? ''),
                    'total_marks' => is_numeric($_POST['total_marks'] ?? null) ? (float)$_POST['total_marks'] : 0,
                    'is_published' => isset($_POST['is_published']) ? 1 : 0,
                ];
                $assignmentId = (int)($_POST['assignment_id'] ?? 0);

                if ($data['title'] === '' || mb_strlen($data['title']) > 200 || !$data['due_date']) {
                    $message = 'Please enter a title (max 200 characters) and a valid due date';
                    $messageType = 'error';
                    break;
                }
                if ($data['total_marks'] <= 0 || $data['total_marks'] > 1000) {
                    $message = 'Total marks must be between 1 and 1000';
                    $messageType = 'error';
                    break;
                }
                // The subject must be one this teacher teaches, in the chosen class
                $subject = $db->getRow('SELECT id FROM subjects WHERE id = ? AND class_id = ? AND teacher_id = ?', [$data['subject_id'], $data['class_id'], $teacherId]);
                if (!$subject) {
                    $message = 'Choose a class and one of your own subjects taught in it';
                    $messageType = 'error';
                    break;
                }

                $attachmentPath = null;
                if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $upload = Security::validateFileUpload($_FILES['attachment']);
                    if (!$upload['valid']) {
                        $message = 'Attachment rejected: ' . $upload['message'];
                        $messageType = 'error';
                        break;
                    }
                    $fileName = 'assignment_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $upload['extension'];
                    if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $attachDir . $fileName)) {
                        $message = 'Could not store the attachment';
                        $messageType = 'error';
                        break;
                    }
                    $attachmentPath = $fileName;
                }

                try {
                    if ($action === 'add_assignment') {
                        $newId = $db->insert(
                            "INSERT INTO homework (class_id, subject_id, teacher_id, title, description, instructions, attachment_path, due_date, total_marks, is_published)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            [$data['class_id'], $data['subject_id'], $teacherId, $data['title'], $data['description'], $data['instructions'],
                             $attachmentPath, $data['due_date'], $data['total_marks'], $data['is_published']]
                        );
                        Security::logAudit('ADDED_ASSIGNMENT', 'homework', $newId);
                        $message = 'Assignment created successfully';
                    } else {
                        $existing = $db->getRow('SELECT attachment_path FROM homework WHERE id = ? AND teacher_id = ?', [$assignmentId, $teacherId]);
                        if (!$existing) {
                            throw new Exception('Assignment not found');
                        }
                        $sql = "UPDATE homework SET class_id = ?, subject_id = ?, title = ?, description = ?, instructions = ?, due_date = ?, total_marks = ?, is_published = ?";
                        $params = [$data['class_id'], $data['subject_id'], $data['title'], $data['description'], $data['instructions'],
                                   $data['due_date'], $data['total_marks'], $data['is_published']];
                        if ($attachmentPath) {
                            $sql .= ", attachment_path = ?";
                            $params[] = $attachmentPath;
                        }
                        $db->query($sql . " WHERE id = ? AND teacher_id = ?", array_merge($params, [$assignmentId, $teacherId]));
                        if ($attachmentPath && $existing['attachment_path'] && is_file($attachDir . basename($existing['attachment_path']))) {
                            @unlink($attachDir . basename($existing['attachment_path']));
                        }
                        Security::logAudit('UPDATED_ASSIGNMENT', 'homework', $assignmentId);
                        $message = 'Assignment updated successfully';
                    }
                    $messageType = 'success';
                } catch (Exception $e) {
                    if ($attachmentPath) { @unlink($attachDir . $attachmentPath); }
                    $message = 'Error: ' . $e->getMessage();
                    $messageType = 'error';
                }
                break;

            case 'delete_assignment':
                $assignmentId = (int)($_POST['assignment_id'] ?? 0);
                try {
                    $hw = $db->getRow('SELECT attachment_path FROM homework WHERE id = ? AND teacher_id = ?', [$assignmentId, $teacherId]);
                    if (!$hw) {
                        throw new Exception('Assignment not found');
                    }
                    $files = $db->getRows('SELECT attachment_path FROM homework_submissions WHERE homework_id = ? AND attachment_path IS NOT NULL', [$assignmentId]);
                    $db->query("DELETE FROM homework WHERE id = ? AND teacher_id = ?", [$assignmentId, $teacherId]); // submissions cascade
                    foreach ($files as $f) {
                        $path = UPLOAD_PATH . 'submissions/' . basename($f['attachment_path']);
                        if (is_file($path)) { @unlink($path); }
                    }
                    if ($hw['attachment_path'] && is_file($attachDir . basename($hw['attachment_path']))) {
                        @unlink($attachDir . basename($hw['attachment_path']));
                    }
                    Security::logAudit('DELETED_ASSIGNMENT', 'homework', $assignmentId);
                    $message = 'Assignment deleted successfully';
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = 'Error deleting assignment: ' . $e->getMessage();
                    $messageType = 'error';
                }
                break;

            case 'grade_submission':
                $submissionId = (int)($_POST['submission_id'] ?? 0);
                $feedback = mb_substr(Security::sanitize($_POST['feedback'] ?? ''), 0, 2000);
                // Only submissions of this teacher's own assignments
                $sub = $db->getRow(
                    "SELECT hs.id, h.total_marks FROM homework_submissions hs JOIN homework h ON hs.homework_id = h.id
                     WHERE hs.id = ? AND h.teacher_id = ?", [$submissionId, $teacherId]);
                $marks = is_numeric($_POST['obtained_marks'] ?? null) ? (float)$_POST['obtained_marks'] : -1;
                if (!$sub) {
                    $message = 'Submission not found';
                    $messageType = 'error';
                } elseif ($marks < 0 || $marks > (float)$sub['total_marks']) {
                    $message = 'Marks must be between 0 and ' . ($sub['total_marks'] + 0);
                    $messageType = 'error';
                } else {
                    $db->query(
                        "UPDATE homework_submissions SET obtained_marks = ?, feedback = ?, graded_by = ?, graded_at = NOW(), status = 'graded' WHERE id = ?",
                        [$marks, $feedback, $userId, $submissionId]
                    );
                    Security::logAudit('GRADED_SUBMISSION', 'homework_submissions', $submissionId);
                    $message = 'Submission graded successfully';
                    $messageType = 'success';
                }
                break;
        }
    }
    if ($messageType === 'success') {
        flash_redirect($message, 'success', BASE_URL . '/teacher/assignments');
    }
}

// Get assignments list
$assignments = $db->getRows(
    "SELECT h.*, c.class_name, c.section, s.subject_name,
            (SELECT COUNT(*) FROM homework_submissions WHERE homework_id = h.id) as submission_count,
            (SELECT COUNT(*) FROM students WHERE class_id = h.class_id) as total_students
     FROM homework h JOIN classes c ON h.class_id = c.id JOIN subjects s ON h.subject_id = s.id
     WHERE h.teacher_id = ? ORDER BY h.created_at DESC",
    [$teacherId]
);

// Get pending grading
$pendingGrading = $db->getRows(
    "SELECT hs.*, h.title, h.total_marks, s.subject_name, c.class_name,
            CONCAT(u.first_name, ' ', u.last_name) as student_name, u.email as student_email
     FROM homework_submissions hs
     JOIN homework h ON hs.homework_id = h.id
     JOIN subjects s ON h.subject_id = s.id
     JOIN classes c ON h.class_id = c.id
     JOIN students st ON hs.student_id = st.id
     JOIN users u ON st.user_id = u.id
     WHERE h.teacher_id = ? AND hs.obtained_marks IS NULL
     ORDER BY hs.submission_date DESC",
    [$teacherId]
);
?>

<div class="dashboard-container">
    <?php render_sidebar('teacher'); ?>

    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>Manage Assignments</h1>
            <button class="btn btn-primary" onclick="showAssignmentModal()">
                <i class="fas fa-plus"></i> New Assignment
            </button>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?php echo e($messageType); ?> alert-dismissible">
            <?php echo e($message); ?>
            <button type="button" class="close" onclick="this.parentElement.remove()">&times;</button>
        </div>
        <?php endif; ?>

        <!-- Pending Grading Alert -->
        <?php if (!empty($pendingGrading)): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <strong><?php echo count($pendingGrading); ?> submissions pending grading.</strong>
            <a href="#pendingGrading" class="alert-link">Grade now</a>
        </div>
        <?php endif; ?>

        <!-- Assignments List -->
        <div class="card">
            <div class="card-header">
                <h3>My Assignments</h3>
            </div>
            <div class="card-body">
                <?php if (!empty($assignments)): ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Class</th>
                                <th>Subject</th>
                                <th>Due Date</th>
                                <th>Total Marks</th>
                                <th>Submissions</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignments as $assignment):
                                $isOverdue = strtotime($assignment['due_date']) < time();
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($assignment['title']); ?></strong>
                                    <?php if ($assignment['attachment_path']): ?>
                                    <i class="fas fa-paperclip" title="Has attachment"></i>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($assignment['class_name'] . ' ' . $assignment['section']); ?></td>
                                <td><?php echo htmlspecialchars($assignment['subject_name']); ?></td>
                                <td class="<?php echo $isOverdue ? 'text-danger' : ''; ?>">
                                    <?php echo date('d M Y, h:i A', strtotime($assignment['due_date'])); ?>
                                </td>
                                <td><?php echo e($assignment['total_marks']); ?></td>
                                <td>
                                    <?php echo e($assignment['submission_count']); ?>/<?php echo e($assignment['total_students']); ?>
                                    <div class="progress" style="height: 5px; width: 100px;">
                                        <div class="progress-bar bg-success"
                                             style="width: <?php echo ($assignment['submission_count'] / max($assignment['total_students'], 1)) * 100; ?>%;">
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($assignment['is_published']): ?>
                                    <span class="badge badge-success">Published</span>
                                    <?php else: ?>
                                    <span class="badge badge-secondary">Draft</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn-icon" onclick="viewSubmissions(<?php echo e($assignment['id']); ?>)" title="View Submissions">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn-icon" onclick="editAssignment(<?php echo e($assignment['id']); ?>)" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn-icon text-danger"
                                                onclick="deleteAssignment(<?php echo e($assignment['id']); ?>, '<?php echo htmlspecialchars($assignment['title']); ?>')"
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
                <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    No assignments created yet. Click "New Assignment" to create one.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pending Grading Section -->
        <?php if (!empty($pendingGrading)): ?>
        <div class="card" id="pendingGrading">
            <div class="card-header">
                <h3>Pending Grading</h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Assignment</th>
                                <th>Class</th>
                                <th>Subject</th>
                                <th>Submitted</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingGrading as $submission): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($submission['student_name']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($submission['title']); ?></td>
                                <td><?php echo htmlspecialchars($submission['class_name']); ?></td>
                                <td><?php echo htmlspecialchars($submission['subject_name']); ?></td>
                                <td><?php echo date('d M Y, h:i A', strtotime($submission['submission_date'])); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-primary" onclick="gradeSubmission(<?php echo e($submission['id']); ?>, <?php echo e($submission['total_marks']); ?>)">
                                        Grade
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
    </main>
</div>

<!-- Assignment Modal -->
<div id="assignmentModal" class="modal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3 id="modalTitle">Create New Assignment</h3>
            <button type="button" class="close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="assignmentForm">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                <input type="hidden" name="action" id="formAction" value="add_assignment">
                <input type="hidden" name="assignment_id" id="assignment_id">

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="class_id">Class *</label>
                        <select id="class_id" name="class_id" class="form-control" required>
                            <option value="">-- Select Class --</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo e($class['id']); ?>">
                                <?php echo htmlspecialchars($class['class_name'] . ' ' . $class['section']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-md-6">
                        <label for="subject_id">Subject *</label>
                        <select id="subject_id" name="subject_id" class="form-control" required>
                            <option value="">-- Select Subject --</option>
                            <?php foreach ($subjects as $subject): ?>
                            <option value="<?php echo e($subject['id']); ?>">
                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="title">Assignment Title *</label>
                    <input type="text" id="title" name="title" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" class="form-control" rows="3"></textarea>
                </div>

                <div class="form-group">
                    <label for="instructions">Instructions</label>
                    <textarea id="instructions" name="instructions" class="form-control" rows="5"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="due_date">Due Date *</label>
                        <input type="datetime-local" id="due_date" name="due_date" class="form-control" required>
                    </div>

                    <div class="form-group col-md-6">
                        <label for="total_marks">Total Marks *</label>
                        <input type="number" id="total_marks" name="total_marks" class="form-control" min="1" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="attachment">Attachment (Optional)</label>
                    <input type="file" id="attachment" name="attachment" class="form-control-file">
                    <small class="form-text">Allowed: PDF, DOC, DOCX, JPG, PNG (Max: 5MB)</small>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="is_published" value="1" checked>
                        Publish immediately (students can see it)
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Assignment</button>
            </div>
        </form>
    </div>
</div>

<!-- Grade Modal -->
<div id="gradeModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Grade Submission</h3>
            <button type="button" class="close" onclick="closeGradeModal()">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="grade_submission">
                <input type="hidden" name="submission_id" id="grade_submission_id">

                <div class="form-group">
                    <label for="obtained_marks">Marks Obtained *</label>
                    <input type="number" id="obtained_marks" name="obtained_marks" class="form-control"
                           step="0.5" min="0" required>
                    <small class="form-text">Maximum: <span id="max_marks"></span></small>
                </div>

                <div class="form-group">
                    <label for="feedback">Feedback</label>
                    <textarea id="feedback" name="feedback" class="form-control" rows="4"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeGradeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Submit Grade</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Form -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
    <input type="hidden" name="action" value="delete_assignment">
    <input type="hidden" name="assignment_id" id="delete_id">
</form>

<style>
.progress {
    background-color: #f0f0f0;
    border-radius: 10px;
    overflow: hidden;
}

.progress-bar {
    height: 100%;
    transition: width 0.3s ease;
}

.action-buttons {
    display: flex;
    gap: 5px;
}

.btn-icon {
    width: 30px;
    height: 30px;
    border-radius: 5px;
    border: none;
    background: #f0f0f0;
    color: #333;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-icon:hover {
    background: var(--gold);
    color: var(--navy);
}

.btn-icon.text-danger:hover {
    background: #dc3545;
    color: white;
}

.badge-success {
    background: #d4edda;
    color: #155724;
}

.badge-secondary {
    background: #e2e3e5;
    color: #383d41;
}

.text-danger {
    color: #dc3545;
    font-weight: 600;
}
</style>

<script nonce="<?php echo CSP_NONCE; ?>">
function showAssignmentModal() {
    document.getElementById('modalTitle').textContent = 'Create New Assignment';
    document.getElementById('formAction').value = 'add_assignment';
    document.getElementById('assignmentForm').reset();
    document.getElementById('assignmentModal').style.display = 'block';
}

function editAssignment(id) {
    // Fetch assignment data via AJAX
    fetch(`${BASE_URL}/api/get-assignment?id=${encodeURIComponent(id)}`, {credentials: 'same-origin'})
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('modalTitle').textContent = 'Edit Assignment';
                document.getElementById('formAction').value = 'edit_assignment';
                document.getElementById('assignment_id').value = data.assignment.id;
                document.getElementById('class_id').value = data.assignment.class_id;
                document.getElementById('subject_id').value = data.assignment.subject_id;
                document.getElementById('title').value = data.assignment.title;
                document.getElementById('description').value = data.assignment.description;
                document.getElementById('instructions').value = data.assignment.instructions;
                document.getElementById('due_date').value = data.assignment.due_date.replace(' ', 'T');
                document.getElementById('total_marks').value = data.assignment.total_marks;
                document.getElementById('assignmentModal').style.display = 'block';
            }
        });
}

function closeModal() {
    document.getElementById('assignmentModal').style.display = 'none';
}

function deleteAssignment(id, title) {
    if (confirm(`Are you sure you want to delete "${title}"? This will also delete all submissions.`)) {
        document.getElementById('delete_id').value = id;
        document.getElementById('deleteForm').submit();
    }
}

function gradeSubmission(id, maxMarks) {
    document.getElementById('grade_submission_id').value = id;
    document.getElementById('max_marks').textContent = maxMarks;
    document.getElementById('gradeModal').style.display = 'block';
}

function closeGradeModal() {
    document.getElementById('gradeModal').style.display = 'none';
}

function viewSubmissions(assignmentId) {
    window.location.href = `submissions?assignment_id=${assignmentId}`;
}

// Close modals when clicking outside
window.onclick = function(event) {
    const assignmentModal = document.getElementById('assignmentModal');
    const gradeModal = document.getElementById('gradeModal');

    if (event.target === assignmentModal) {
        assignmentModal.style.display = 'none';
    }
    if (event.target === gradeModal) {
        gradeModal.style.display = 'none';
    }
}
</script>

<?php
include '../includes/footer.php';
?>