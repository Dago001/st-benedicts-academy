<?php
// teacher/assignments.php - Manage Homework/Assignments
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/security.php';

Security::requireRole('teacher');

$pageTitle = 'Manage Assignments';
$extraCSS = ['dashboard.css'];
$extraJS = ['assignments.js', 'ckeditor.js'];

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
                    'class_id' => Security::sanitize($_POST['class_id']),
                    'subject_id' => Security::sanitize($_POST['subject_id']),
                    'title' => Security::sanitize($_POST['title']),
                    'description' => Security::sanitize($_POST['description']),
                    'instructions' => Security::sanitize($_POST['instructions']),
                    'due_date' => Security::sanitize($_POST['due_date']),
                    'total_marks' => Security::sanitize($_POST['total_marks']),
                    'is_published' => isset($_POST['is_published']) ? 1 : 0
                ];
                
                // Handle file upload
                $attachmentPath = null;
                if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                    $upload = Security::validateFileUpload($_FILES['attachment']);
                    if ($upload['valid']) {
                        $fileName = 'assignment_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $upload['extension'];
                        $uploadPath = UPLOAD_PATH . 'assignments/' . $fileName;
                        
                        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadPath)) {
                            $attachmentPath = $fileName;
                        }
                    }
                }
                
                try {
                    if ($action === 'add_assignment') {
                        $db->insert(
                            "INSERT INTO homework (class_id, subject_id, teacher_id, title, description, 
                             instructions, attachment_path, due_date, total_marks, is_published) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            [$data['class_id'], $data['subject_id'], $teacher['id'], $data['title'],
                             $data['description'], $data['instructions'], $attachmentPath,
                             $data['due_date'], $data['total_marks'], $data['is_published']]
                        );
                        Security::logAudit('ADDED_ASSIGNMENT', 'homework');
                        $message = 'Assignment created successfully';
                    } else {
                        $assignmentId = Security::sanitize($_POST['assignment_id']);
                        $sql = "UPDATE homework SET class_id = ?, subject_id = ?, title = ?, description = ?, 
                                instructions = ?, due_date = ?, total_marks = ?, is_published = ?";
                        $params = [$data['class_id'], $data['subject_id'], $data['title'],
                                  $data['description'], $data['instructions'], $data['due_date'],
                                  $data['total_marks'], $data['is_published']];
                        
                        if ($attachmentPath) {
                            $sql .= ", attachment_path = ?";
                            $params[] = $attachmentPath;
                        }
                        
                        $sql .= " WHERE id = ? AND teacher_id = ?";
                        $params[] = $assignmentId;
                        $params[] = $teacher['id'];
                        
                        $db->query($sql, $params);
                        Security::logAudit('UPDATED_ASSIGNMENT', 'homework', $assignmentId);
                        $message = 'Assignment updated successfully';
                    }
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = 'Error: ' . $e->getMessage();
                    $messageType = 'error';
                }
                break;
                
            case 'delete_assignment':
                $assignmentId = Security::sanitize($_POST['assignment_id']);
                
                try {
                    // Delete submissions first
                    $db->query("DELETE FROM homework_submissions WHERE homework_id = ?", [$assignmentId]);
                    
                    // Delete assignment
                    $db->query(
                        "DELETE FROM homework WHERE id = ? AND teacher_id = ?",
                        [$assignmentId, $teacher['id']]
                    );
                    
                    Security::logAudit('DELETED_ASSIGNMENT', 'homework', $assignmentId);
                    $message = 'Assignment deleted successfully';
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = 'Error deleting assignment: ' . $e->getMessage();
                    $messageType = 'error';
                }
                break;
                
            case 'grade_submission':
                $submissionId = Security::sanitize($_POST['submission_id']);
                $obtainedMarks = Security::sanitize($_POST['obtained_marks']);
                $feedback = Security::sanitize($_POST['feedback']);
                
                try {
                    $db->query(
                        "UPDATE homework_submissions SET obtained_marks = ?, feedback = ?, 
                         graded_by = ?, graded_at = NOW(), status = 'graded' 
                         WHERE id = ?",
                        [$obtainedMarks, $feedback, $userId, $submissionId]
                    );
                    
                    Security::logAudit('GRADED_SUBMISSION', 'homework_submissions', $submissionId);
                    $message = 'Submission graded successfully';
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = 'Error grading submission: ' . $e->getMessage();
                    $messageType = 'error';
                }
                break;
        }
    }
}

// Get assignments list
$assignments = $db->getRows(
    "SELECT h.*, c.class_name, c.section, s.subject_name,
            (SELECT COUNT(*) FROM homework_submissions WHERE homework_id = h.id) as submission_count,
            (SELECT COUNT(*) FROM students WHERE class_id = h.class_id) as total_students
     FROM homework h
     JOIN classes c ON h.class_id = c.id
     JOIN subjects s ON h.subject_id = s.id
     WHERE h.teacher_id = ?
     ORDER BY h.created_at DESC",
    [$teacher['id']]
);

// Get pending grading
$pendingGrading = $db->getRows(
    "SELECT hs.*, h.title, h.total_marks, s.subject_name, c.class_name,
            CONCAT(u.first_name, ' ', u.last_name) as student_name,
            u.email as student_email
     FROM homework_submissions hs
     JOIN homework h ON hs.homework_id = h.id
     JOIN subjects s ON h.subject_id = s.id
     JOIN classes c ON h.class_id = c.id
     JOIN students st ON hs.student_id = st.id
     JOIN users u ON st.user_id = u.id
     WHERE h.teacher_id = ? AND hs.obtained_marks IS NULL
     ORDER BY hs.submission_date DESC",
    [$teacher['id']]
);
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
                <li><a href="results.php"><i class="fas fa-chart-line"></i> Results</a></li>
                <li class="active"><a href="assignments.php"><i class="fas fa-tasks"></i> Assignments</a></li>
                <li><a href="students.php"><i class="fas fa-user-graduate"></i> Students</a></li>
                <li><a href="messages.php"><i class="fas fa-envelope"></i> Messages</a></li>
                <li><a href="profile.php"><i class="fas fa-user-cog"></i> Profile</a></li>
            </ul>
        </nav>
    </aside>
    
    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>Manage Assignments</h1>
            <button class="btn btn-primary" onclick="showAssignmentModal()">
                <i class="fas fa-plus"></i> New Assignment
            </button>
        </div>
        
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible">
            <?php echo $message; ?>
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
                                <td><?php echo $assignment['total_marks']; ?></td>
                                <td>
                                    <?php echo $assignment['submission_count']; ?>/<?php echo $assignment['total_students']; ?>
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
                                        <button class="btn-icon" onclick="viewSubmissions(<?php echo $assignment['id']; ?>)" title="View Submissions">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn-icon" onclick="editAssignment(<?php echo $assignment['id']; ?>)" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn-icon text-danger" 
                                                onclick="deleteAssignment(<?php echo $assignment['id']; ?>, '<?php echo htmlspecialchars($assignment['title']); ?>')"
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
                                    <button class="btn btn-sm btn-primary" onclick="gradeSubmission(<?php echo $submission['id']; ?>, <?php echo $submission['total_marks']; ?>)">
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
                            <option value="<?php echo $class['id']; ?>">
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
                            <option value="<?php echo $subject['id']; ?>">
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

<script>
function showAssignmentModal() {
    document.getElementById('modalTitle').textContent = 'Create New Assignment';
    document.getElementById('formAction').value = 'add_assignment';
    document.getElementById('assignmentForm').reset();
    document.getElementById('assignmentModal').style.display = 'block';
}

function editAssignment(id) {
    // Fetch assignment data via AJAX
    fetch(`../api/get-assignment.php?id=${id}`)
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
    window.location.href = `submissions.php?assignment_id=${assignmentId}`;
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