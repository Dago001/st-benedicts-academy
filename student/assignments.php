<?php
// student/assignments.php - View and Submit Assignments
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';

Security::requireRole('student');

$pageTitle = 'Assignments';
$extraCSS = ['dashboard.css'];
$extraJS = ['assignments.js'];

include __DIR__ . '/../includes/header.php';

// Get database instance
try {
    $db = Database::getInstance();
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Database connection error: ' . htmlspecialchars(DEBUG_MODE ? $e->getMessage() : 'Please try again later.') . '</div>';
    exit;
}

$userId = $_SESSION['user_id'];
[$message, $messageType] = flash_get();

// Submissions are stored in their own upload folder
$uploadDir = UPLOAD_PATH . 'submissions/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
    error_log("Failed to create upload directory: " . $uploadDir);
}

// Get student info
$student = $db->getRow(
    "SELECT s.*, u.first_name, u.last_name, u.email, c.class_name, c.section, c.id as class_id
     FROM students s
     JOIN users u ON s.user_id = u.id
     LEFT JOIN classes c ON s.class_id = c.id
     WHERE s.user_id = ?",
    [$userId]
);

if (!$student) {
    echo '<div class="alert alert-danger">Student record not found.</div>';
    exit;
}

// Handle assignment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_assignment'])) {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token';
        $messageType = 'error';
    } else {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $submissionText = mb_substr(Security::sanitize($_POST['submission_text'] ?? ''), 0, 10000);
        // Only published assignments of the student's own class can be submitted
        $homework = $assignmentId ? $db->getRow('SELECT id, due_date FROM homework WHERE id = ? AND class_id = ? AND is_published = 1', [$assignmentId, $student['class_id'] ?? 0]) : null;
        $hasFile = isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE;

        if (!$homework) {
            $message = 'Assignment not found';
            $messageType = 'error';
        } elseif ($db->getRow("SELECT id FROM homework_submissions WHERE homework_id = ? AND student_id = ?", [$assignmentId, $student['id']])) {
            $message = 'You have already submitted this assignment';
            $messageType = 'error';
        } elseif ($submissionText === '' && !$hasFile) {
            $message = 'Please write your answer or attach a file';
            $messageType = 'error';
        } else {
            $attachmentPath = null;
            if ($hasFile) {
                $upload = Security::validateFileUpload($_FILES['attachment']);
                if (!$upload['valid']) {
                    $message = 'Invalid file: ' . $upload['message'];
                    $messageType = 'error';
                } else {
                    $fileName = 'submission_' . $assignmentId . '_' . $student['id'] . '_' . bin2hex(random_bytes(6)) . '.' . $upload['extension'];
                    if (move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $fileName)) {
                        $attachmentPath = $fileName;
                        @chmod($uploadDir . $fileName, 0644);
                    } else {
                        $message = 'Failed to upload file';
                        $messageType = 'error';
                    }
                }
            }
            if (empty($message)) {
                try {
                    $late = strtotime($homework['due_date']) < time();
                    $newId = $db->insert(
                        "INSERT INTO homework_submissions (homework_id, student_id, submission_text, attachment_path, status, submission_date)
                         VALUES (?, ?, ?, ?, ?, NOW())",
                        [$assignmentId, $student['id'], $submissionText, $attachmentPath, $late ? 'late' : 'submitted']
                    );
                    Security::logAudit('SUBMITTED_ASSIGNMENT', 'homework_submissions', $newId);
                    flash_redirect($late ? 'Assignment submitted (marked late)' : 'Assignment submitted successfully', 'success', BASE_URL . '/student/assignments.php');
                } catch (Exception $e) {
                    if ($attachmentPath) { @unlink($uploadDir . $attachmentPath); }
                    $message = 'Error submitting assignment. Please try again.';
                    $messageType = 'error';
                    error_log("Assignment submission error: " . $e->getMessage());
                }
            }
        }
    }
}

// Get filter
$filter = in_array($_GET['filter'] ?? '', ['pending', 'submitted', 'graded', 'all'], true) ? $_GET['filter'] : 'pending';
$subjectId = isset($_GET['subject']) ? (int)$_GET['subject'] : 0;

// Get subjects for filter
$subjects = [];
if ($student['class_id']) {
    $subjects = $db->getRows(
        "SELECT DISTINCT s.id, s.subject_name
         FROM subjects s
         JOIN homework h ON s.id = h.subject_id
         WHERE h.class_id = ?
         ORDER BY s.subject_name",
        [$student['class_id']]
    );
}

// Get assignments with submission status
$assignments = [];
if ($student['class_id']) {
    $query = "SELECT h.*, s.subject_name,
                     (SELECT id FROM homework_submissions WHERE homework_id = h.id AND student_id = ?) as submitted_id,
                     (SELECT status FROM homework_submissions WHERE homework_id = h.id AND student_id = ?) as submission_status,
                     (SELECT submission_date FROM homework_submissions WHERE homework_id = h.id AND student_id = ?) as submission_date,
                     (SELECT obtained_marks FROM homework_submissions WHERE homework_id = h.id AND student_id = ?) as obtained_marks,
                     (SELECT feedback FROM homework_submissions WHERE homework_id = h.id AND student_id = ?) as feedback
              FROM homework h
              JOIN subjects s ON h.subject_id = s.id
              WHERE h.class_id = ? AND h.is_published = 1";

    $params = [$student['id'], $student['id'], $student['id'], $student['id'], $student['id'], $student['class_id']];

    if ($subjectId > 0) {
        $query .= " AND h.subject_id = ?";
        $params[] = $subjectId;
    }

    if ($filter === 'pending') {
        $query .= " AND h.id NOT IN (SELECT homework_id FROM homework_submissions WHERE student_id = ?)";
        $params[] = $student['id'];
    } elseif ($filter === 'submitted') {
        $query .= " AND h.id IN (SELECT homework_id FROM homework_submissions WHERE student_id = ?)";
        $params[] = $student['id'];
    } elseif ($filter === 'graded') {
        $query .= " AND h.id IN (SELECT homework_id FROM homework_submissions WHERE student_id = ? AND obtained_marks IS NOT NULL)";
        $params[] = $student['id'];
    }

    $query .= " ORDER BY h.due_date ASC";

    $assignments = $db->getRows($query, $params);
}

// Get submissions history
$submissions = $db->getRows(
    "SELECT hs.*, h.title, h.due_date, h.total_marks, s.subject_name,
            CONCAT(u.first_name, ' ', u.last_name) as teacher_name
     FROM homework_submissions hs
     JOIN homework h ON hs.homework_id = h.id
     JOIN subjects s ON h.subject_id = s.id
     LEFT JOIN teachers t ON h.teacher_id = t.id
     LEFT JOIN users u ON t.user_id = u.id
     WHERE hs.student_id = ?
     ORDER BY hs.submission_date DESC",
    [$student['id']]
);
?>

<style>
/* Assignment page specific styles */
.assignments-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
}

.assignment-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    border: 1px solid #eee;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.assignment-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
}

.assignment-card.overdue {
    border-left: 4px solid #dc3545;
}

.assignment-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 15px;
}

.assignment-header h4 {
    margin: 0;
    font-size: 1.1rem;
    color: #002855;
    flex: 1;
}

.subject-badge {
    background: #ffd700;
    color: #002855;
    padding: 3px 10px;
    border-radius: 15px;
    font-size: 0.75rem;
    font-weight: 600;
    white-space: nowrap;
}

.assignment-meta {
    margin-bottom: 15px;
    font-size: 0.9rem;
}

.meta-item {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 5px;
    color: #666;
}

.meta-item i {
    width: 16px;
    color: #ffd700;
}

.assignment-description {
    margin-bottom: 15px;
    font-size: 0.9rem;
    color: #555;
    line-height: 1.6;
    max-height: 100px;
    overflow-y: auto;
}

.assignment-attachment {
    margin-bottom: 15px;
    padding: 8px;
    background: #f8f9fa;
    border-radius: 5px;
    font-size: 0.85rem;
}

.assignment-attachment i {
    color: #ffd700;
    margin-right: 5px;
}

.assignment-attachment a {
    color: #002855;
    text-decoration: none;
}

.assignment-attachment a:hover {
    text-decoration: underline;
}

.assignment-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #eee;
    flex-wrap: wrap;
    gap: 10px;
}

.submission-status {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.feedback {
    margin-top: 10px;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 5px;
    font-size: 0.85rem;
    border-left: 3px solid #ffd700;
}

.feedback strong {
    color: #002855;
    display: block;
    margin-bottom: 5px;
}

.overdue-badge {
    background: #dc3545;
    color: white;
    padding: 3px 10px;
    border-radius: 15px;
    font-size: 0.75rem;
    font-weight: 600;
}

/* Badge styles */
.badge {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 5px;
    font-size: 0.75rem;
    font-weight: 600;
}

.badge-success {
    background: #d4edda;
    color: #155724;
}

.badge-warning {
    background: #fff3cd;
    color: #856404;
}

.badge-info {
    background: #d1ecf1;
    color: #0c5460;
}

.badge-danger {
    background: #f8d7da;
    color: #721c24;
}

/* Modal styles */
.modal {
    display: none;
    position: fixed;
    z-index: 99999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(5px);
}

.modal-content {
    background-color: #fefefe;
    margin: 5% auto;
    padding: 0;
    border: 1px solid #888;
    width: 90%;
    max-width: 600px;
    border-radius: 10px;
    box-shadow: 0 5px 30px rgba(0, 0, 0, 0.3);
    position: relative;
    z-index: 100000;
    animation: modalSlideIn 0.3s ease;
}

@keyframes modalSlideIn {
    from {
        transform: translateY(-50px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.modal-header {
    padding: 15px 20px;
    border-bottom: 1px solid #dee2e6;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(135deg, #002855, #001a3a);
    color: white;
    border-radius: 10px 10px 0 0;
}

.modal-header h3 {
    margin: 0;
    color: white;
    font-size: 18px;
}

.modal-header .close {
    background: none;
    border: none;
    color: white;
    font-size: 24px;
    cursor: pointer;
    opacity: 0.8;
    transition: opacity 0.3s ease;
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

/* Form styles */
.form-row {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 15px;
}

.form-group {
    flex: 1 1 200px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    color: #002855;
}

.form-control {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.form-control:focus {
    outline: none;
    border-color: #ffd700;
    box-shadow: 0 0 0 2px rgba(255, 215, 0, 0.2);
}

.form-control-file {
    padding: 8px 0;
}

.btn-sm {
    padding: 5px 10px;
    font-size: 12px;
    border-radius: 4px;
}

.btn-primary {
    background: #c41e3a;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-primary:hover {
    background: #a01830;
    transform: translateY(-2px);
}

.btn-secondary {
    background: #6c757d;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
}

.btn-secondary:hover {
    background: #5a6268;
}

/* Alert styles */
.alert {
    padding: 15px 20px;
    margin-bottom: 20px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    gap: 10px;
    animation: slideDown 0.3s ease;
}

.alert-success {
    background-color: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert-error {
    background-color: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.alert-info {
    background-color: #d1ecf1;
    color: #0c5460;
    border: 1px solid #bee5eb;
}

.alert .close {
    margin-left: auto;
    background: none;
    border: none;
    font-size: 20px;
    cursor: pointer;
    color: inherit;
    opacity: 0.7;
}

.alert .close:hover {
    opacity: 1;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Card styles */
.card {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    margin-bottom: 30px;
    overflow: hidden;
}

.card-header {
    padding: 15px 20px;
    border-bottom: 1px solid #e9ecef;
    background: #f8f9fa;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
}

.card-header h3 {
    margin: 0;
    font-size: 18px;
    color: #002855;
}

.card-body {
    padding: 20px;
}

/* Table styles */
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th {
    background: #002855;
    color: white;
    padding: 12px;
    text-align: left;
    font-weight: 500;
}

.data-table td {
    padding: 12px;
    border-bottom: 1px solid #e9ecef;
}

.data-table tbody tr:hover {
    background-color: #f8f9fa;
}

/* User info */
.user-info {
    display: flex;
    align-items: center;
    gap: 15px;
    background: white;
    padding: 10px 20px;
    border-radius: 50px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.user-info i {
    font-size: 2rem;
    color: #002855;
}

.user-info span {
    font-weight: 600;
    color: #002855;
}

.user-info small {
    color: #6c757d;
    font-size: 0.8rem;
    display: block;
}

/* Dashboard header */
.dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    flex-wrap: wrap;
    gap: 15px;
}

.dashboard-header h1 {
    margin: 0;
    font-size: 28px;
    color: #002855;
}

/* Responsive */
@media (max-width: 768px) {
    .assignments-grid {
        grid-template-columns: 1fr;
    }

    .dashboard-header {
        flex-direction: column;
        text-align: center;
    }

    .user-info {
        width: 100%;
        justify-content: center;
    }

    .form-row {
        flex-direction: column;
    }

    .form-group {
        width: 100%;
    }

    .modal-content {
        width: 95%;
        margin: 10% auto;
    }
}
</style>

<div class="dashboard-container">
    <?php render_sidebar('student'); ?>

    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>My Assignments</h1>
            <div class="user-info">
                <i class="fas fa-user-graduate"></i>
                <div>
                    <span><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></span>
                    <small><?php echo htmlspecialchars(($student['class_name'] ?? '') . ' ' . ($student['section'] ?? '')); ?></small>
                </div>
            </div>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?php echo e($messageType); ?> alert-dismissible">
            <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="close" onclick="this.parentElement.remove()">&times;</button>
        </div>
        <?php endif; ?>

        <!-- Quick Stats -->
        <?php
        $pendingCount = 0;
        $submittedCount = 0;
        $gradedCount = 0;

        foreach ($assignments as $ass) {
            if ($ass['submitted_id']) {
                if ($ass['obtained_marks'] !== null) {
                    $gradedCount++;
                } else {
                    $submittedCount++;
                }
            } else {
                $pendingCount++;
            }
        }
        ?>

        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 20px;">
            <div style="background: white; border-radius: 8px; padding: 15px; text-align: center; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                <div style="font-size: 24px; font-weight: 700; color: #002855;"><?php echo count($assignments); ?></div>
                <div style="color: #6c757d;">Total</div>
            </div>
            <div style="background: white; border-radius: 8px; padding: 15px; text-align: center; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                <div style="font-size: 24px; font-weight: 700; color: #ffc107;"><?php echo e($pendingCount); ?></div>
                <div style="color: #6c757d;">Pending</div>
            </div>
            <div style="background: white; border-radius: 8px; padding: 15px; text-align: center; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                <div style="font-size: 24px; font-weight: 700; color: #17a2b8;"><?php echo e($submittedCount); ?></div>
                <div style="color: #6c757d;">Submitted</div>
            </div>
            <div style="background: white; border-radius: 8px; padding: 15px; text-align: center; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                <div style="font-size: 24px; font-weight: 700; color: #28a745;"><?php echo e($gradedCount); ?></div>
                <div style="color: #6c757d;">Graded</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-filter"></i> Filter Assignments</h3>
            </div>
            <div class="card-body">
                <form method="GET" class="form-row">
                    <div class="form-group col-md-5">
                        <label for="filter">Filter by Status</label>
                        <select id="filter" name="filter" class="form-control" onchange="this.form.submit()">
                            <option value="all" <?php echo $filter == 'all' ? 'selected' : ''; ?>>All Assignments</option>
                            <option value="pending" <?php echo $filter == 'pending' ? 'selected' : ''; ?>>Pending Assignments</option>
                            <option value="submitted" <?php echo $filter == 'submitted' ? 'selected' : ''; ?>>Submitted (Awaiting Grade)</option>
                            <option value="graded" <?php echo $filter == 'graded' ? 'selected' : ''; ?>>Graded Assignments</option>
                        </select>
                    </div>

                    <div class="form-group col-md-5">
                        <label for="subject">Filter by Subject</label>
                        <select id="subject" name="subject" class="form-control" onchange="this.form.submit()">
                            <option value="0">All Subjects</option>
                            <?php foreach ($subjects as $sub): ?>
                            <option value="<?php echo e($sub['id']); ?>" <?php echo $subjectId == $sub['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($sub['subject_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-md-2">
                        <label>&nbsp;</label>
                        <a href="assignments.php" class="btn btn-secondary" style="display: block; text-align: center; padding: 8px; background: #6c757d; color: white; text-decoration: none; border-radius: 4px;">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Assignments List -->
        <div class="card">
            <div class="card-header">
                <h3>
                    <i class="fas fa-tasks"></i>
                    <?php
                    if ($filter == 'pending') echo 'Pending Assignments';
                    elseif ($filter == 'submitted') echo 'Submitted Assignments';
                    elseif ($filter == 'graded') echo 'Graded Assignments';
                    else echo 'All Assignments';
                    ?>
                </h3>
                <span class="badge badge-info"><?php echo count($assignments); ?> found</span>
            </div>
            <div class="card-body">
                <?php if (!empty($assignments)): ?>
                <div class="assignments-grid">
                    <?php foreach ($assignments as $assignment):
                        $isOverdue = strtotime($assignment['due_date']) < time();
                        $isSubmitted = !is_null($assignment['submitted_id']);
                        $isGraded = !is_null($assignment['obtained_marks']);
                        $statusClass = $isGraded ? 'success' : ($isSubmitted ? 'info' : 'warning');
                    ?>
                    <div class="assignment-card <?php echo $isOverdue && !$isSubmitted ? 'overdue' : ''; ?>">
                        <div class="assignment-header">
                            <h4><?php echo htmlspecialchars($assignment['title']); ?></h4>
                            <span class="subject-badge"><?php echo htmlspecialchars($assignment['subject_name']); ?></span>
                        </div>

                        <div class="assignment-meta">
                            <div class="meta-item">
                                <i class="fas fa-calendar"></i>
                                <span>Due: <?php echo date('d M Y, h:i A', strtotime($assignment['due_date'])); ?></span>
                            </div>
                            <div class="meta-item">
                                <i class="fas fa-star"></i>
                                <span>Total Marks: <?php echo e($assignment['total_marks']); ?></span>
                            </div>
                            <?php if ($isSubmitted && $assignment['submission_date']): ?>
                            <div class="meta-item">
                                <i class="fas fa-check-circle"></i>
                                <span>Submitted: <?php echo date('d M Y, h:i A', strtotime($assignment['submission_date'])); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($assignment['description'])): ?>
                        <div class="assignment-description">
                            <p><?php echo nl2br(htmlspecialchars($assignment['description'])); ?></p>
                        </div>
                        <?php endif; ?>

                        <?php if ($assignment['attachment_path']): ?>
                        <div class="assignment-attachment">
                            <i class="fas fa-paperclip"></i>
                            <a href="<?php echo BASE_URL; ?>/uploads/assignments/<?php echo urlencode($assignment['attachment_path']); ?>" target="_blank">
                                View Assignment Attachment
                            </a>
                        </div>
                        <?php endif; ?>

                        <div class="assignment-footer">
                            <div class="submission-status">
                                <?php if ($isSubmitted): ?>
                                    <span class="badge badge-<?php echo e($statusClass); ?>">
                                        <i class="fas <?php echo $isGraded ? 'fa-check-circle' : 'fa-clock'; ?>"></i>
                                        <?php echo $isGraded ? 'Graded' : 'Submitted'; ?>
                                    </span>
                                    <?php if ($isGraded): ?>
                                        <span class="badge badge-success">
                                            Score: <?php echo e($assignment['obtained_marks']); ?>/<?php echo e($assignment['total_marks']); ?>
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge badge-warning">
                                        <i class="fas fa-hourglass-half"></i> Pending
                                    </span>
                                <?php endif; ?>
                            </div>

                            <?php if (!$isSubmitted): ?>
                                <button class="btn btn-primary btn-sm" onclick="showSubmitModal(<?php echo e($assignment['id']); ?>, '<?php echo htmlspecialchars(addslashes($assignment['title'])); ?>')">
                                    <i class="fas fa-upload"></i> Submit
                                </button>
                            <?php endif; ?>

                            <?php if ($isOverdue && !$isSubmitted): ?>
                                <span class="overdue-badge">Overdue!</span>
                            <?php endif; ?>
                        </div>

                        <?php if ($isGraded && $assignment['feedback']): ?>
                        <div class="feedback">
                            <strong><i class="fas fa-comment"></i> Teacher's Feedback:</strong>
                            <p><?php echo nl2br(htmlspecialchars($assignment['feedback'])); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    No assignments found matching your criteria.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Submission History -->
        <?php if (!empty($submissions)): ?>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-history"></i> Submission History</h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Assignment</th>
                                <th>Subject</th>
                                <th>Status</th>
                                <th>Marks</th>
                                <th>Feedback</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($submissions as $sub): ?>
                            <tr>
                                <td><?php echo date('d M Y, h:i A', strtotime($sub['submission_date'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($sub['title']); ?></strong></td>
                                <td><?php echo htmlspecialchars($sub['subject_name']); ?></td>
                                <td>
                                    <?php if ($sub['obtained_marks'] !== null): ?>
                                    <span class="badge badge-success">Graded</span>
                                    <?php else: ?>
                                    <span class="badge badge-warning">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($sub['obtained_marks'] !== null): ?>
                                        <?php echo e($sub['obtained_marks']); ?> / <?php echo e($sub['total_marks']); ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo nl2br(htmlspecialchars($sub['feedback'] ?? '-')); ?></td>
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

<!-- Submit Modal -->
<div id="submitModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Submit Assignment</h3>
            <button type="button" class="close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="submitForm">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                <input type="hidden" name="submit_assignment" value="1">
                <input type="hidden" name="assignment_id" id="assignment_id">

                <h4 id="assignment_title" style="color: #002855; margin-bottom: 15px;"></h4>

                <div class="form-group">
                    <label for="submission_text">Your Answer / Notes</label>
                    <textarea id="submission_text" name="submission_text" class="form-control" rows="5" placeholder="Type your answer here..."></textarea>
                </div>

                <div class="form-group">
                    <label for="attachment">Attachment (Optional)</label>
                    <input type="file" id="attachment" name="attachment" class="form-control-file">
                    <small class="form-text text-muted">Allowed: PDF, DOC, DOCX, JPG, PNG (Max: 5MB)</small>
                </div>

                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    Make sure your submission is complete before submitting. You cannot submit twice.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Submit Assignment</button>
            </div>
        </form>
    </div>
</div>

<script>
// Global functions for modal handling
function showSubmitModal(id, title) {
    document.getElementById('assignment_id').value = id;
    document.getElementById('assignment_title').textContent = 'Assignment: ' + title;
    document.getElementById('submitModal').style.display = 'block';

    // Prevent body scrolling when modal is open
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    document.getElementById('submitModal').style.display = 'none';
    document.getElementById('submitForm').reset();

    // Restore body scrolling
    document.body.style.overflow = 'auto';
}

// Auto-hide alerts after 5 seconds
setTimeout(function() {
    document.querySelectorAll('.alert-dismissible').forEach(function(alert) {
        if (alert) {
            alert.style.transition = 'opacity 0.5s ease';
            alert.style.opacity = '0';
            setTimeout(function() {
                if (alert && alert.parentNode) {
                    alert.remove();
                }
            }, 500);
        }
    });
}, 5000);

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('submitModal');
    if (event.target === modal) {
        closeModal();
    }
}

// File input validation
document.getElementById('attachment')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const maxSize = 5 * 1024 * 1024; // 5MB
        if (file.size > maxSize) {
            alert('File size must be less than 5MB');
            this.value = '';
        }

        const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'application/msword',
                              'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        if (!allowedTypes.includes(file.type)) {
            alert('File type not allowed. Please upload PDF, DOC, DOCX, JPG, or PNG files.');
            this.value = '';
        }
    }
});

// Form submission validation
document.getElementById('submitForm')?.addEventListener('submit', function(e) {
    const text = document.getElementById('submission_text').value.trim();
    const file = document.getElementById('attachment').files[0];

    if (!text && !file) {
        e.preventDefault();
        alert('Please provide either text answer or upload a file.');
    }
});
</script>

<?php
// Check if footer exists
$footerPath = __DIR__ . '/../includes/footer.php';
if (file_exists($footerPath)) {
    include $footerPath;
} else {
    echo "<!-- Footer file not found -->";
}
?>