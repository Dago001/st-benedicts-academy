<?php
// admin/subjects.php - Subject Management
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';

Security::requireRole('admin');

$pageTitle = 'Subject Management';
$extraCSS = ['admin.css'];
$extraJS = ['subjects.js'];

include __DIR__ . '/../includes/header.php';

// Get database instance
$db = Database::getInstance();

[$message, $messageType] = flash_get();

// Handle actions
$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$id = isset($_POST['id']) ? (int)$_POST['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : null);
if (!in_array($action, ['list', 'add', 'edit'], true)) $action = 'list';
$classFilter = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token';
        $messageType = 'error';
    } else {
        $postAction = $_POST['action'] ?? '';

        switch ($postAction) {
            case 'add':
            case 'edit':
                // Sanitize and validate input
                $subjectName = Security::sanitize($_POST['subject_name'] ?? '');
                $subjectCode = Security::sanitize($_POST['subject_code'] ?? '');
                $description = Security::sanitize($_POST['description'] ?? '');
                $classId = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null;
                $teacherId = !empty($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : null;
                $isActive = isset($_POST['is_active']) ? 1 : 0;

                // Validate required fields
                if (empty($subjectName)) {
                    $message = 'Subject name is required';
                    $messageType = 'error';
                    break;
                }

                if (empty($subjectCode)) {
                    $message = 'Subject code is required';
                    $messageType = 'error';
                    break;
                }

                if (!preg_match('/^[A-Za-z0-9_-]{2,20}$/', $subjectCode)) {
                    $message = 'Subject code must be 2-20 letters, numbers, dashes or underscores';
                    $messageType = 'error';
                    break;
                }
                if (mb_strlen($subjectName) > 100) {
                    $message = 'Subject name is too long';
                    $messageType = 'error';
                    break;
                }
                if ($classId && !$db->getRow('SELECT id FROM classes WHERE id = ?', [$classId])) {
                    $message = 'Selected class does not exist';
                    $messageType = 'error';
                    break;
                }
                if ($teacherId && !$db->getRow('SELECT id FROM teachers WHERE id = ?', [$teacherId])) {
                    $message = 'Selected teacher does not exist';
                    $messageType = 'error';
                    break;
                }

                // Check if subject code already exists
                try {
                    if ($postAction === 'add') {
                        $existing = $db->getRow(
                            "SELECT id FROM subjects WHERE subject_code = ?",
                            [$subjectCode]
                        );

                        if ($existing) {
                            throw new Exception("Subject code already exists");
                        }

                        $db->insert(
                            "INSERT INTO subjects (subject_name, subject_code, description, class_id, teacher_id, is_active)
                             VALUES (?, ?, ?, ?, ?, ?)",
                            [
                                $subjectName,
                                $subjectCode,
                                $description,
                                $classId,
                                $teacherId,
                                $isActive
                            ]
                        );

                        Security::logAudit('ADDED_SUBJECT', 'subjects');
                        $message = 'Subject added successfully';
                        $messageType = 'success';

                    } else {
                        if (!$id || !$db->getRow('SELECT id FROM subjects WHERE id = ?', [$id])) {
                            throw new Exception("Subject not found");
                        }

                        // Check if subject code already exists for other subjects
                        $existing = $db->getRow(
                            "SELECT id FROM subjects WHERE subject_code = ? AND id != ?",
                            [$subjectCode, $id]
                        );

                        if ($existing) {
                            throw new Exception("Subject code already exists");
                        }

                        $db->query(
                            "UPDATE subjects SET subject_name = ?, subject_code = ?, description = ?,
                             class_id = ?, teacher_id = ?, is_active = ? WHERE id = ?",
                            [
                                $subjectName,
                                $subjectCode,
                                $description,
                                $classId,
                                $teacherId,
                                $isActive,
                                $id
                            ]
                        );

                        Security::logAudit('UPDATED_SUBJECT', 'subjects', $id);
                        $message = 'Subject updated successfully';
                        $messageType = 'success';
                    }
                } catch (Exception $e) {
                    $message = 'Error: ' . $e->getMessage();
                    $messageType = 'error';
                }
                break;

            case 'delete':
                try {
                    if (!$id) {
                        throw new Exception("Invalid subject ID");
                    }

                    // Check if subject has results
                    $resultCount = $db->getRow("SELECT COUNT(*) as count FROM results WHERE subject_id = ?", [$id])['count'] ?? 0;

                    if ($resultCount > 0) {
                        $message = 'Cannot delete subject with existing results';
                        $messageType = 'error';
                        break;
                    }

                    // Check if subject has homework
                    $homeworkCount = $db->getRow("SELECT COUNT(*) as count FROM homework WHERE subject_id = ?", [$id])['count'] ?? 0;

                    if ($homeworkCount > 0) {
                        $message = 'Cannot delete subject with existing homework';
                        $messageType = 'error';
                        break;
                    }

                    $db->query("DELETE FROM subjects WHERE id = ?", [$id]);

                    Security::logAudit('DELETED_SUBJECT', 'subjects', $id);
                    $message = 'Subject deleted successfully';
                    $messageType = 'success';

                } catch (Exception $e) {
                    $message = 'Error: ' . $e->getMessage();
                    $messageType = 'error';
                }
                break;

            case 'bulk_assign':
                $classId = (int)($_POST['bulk_class_id'] ?? 0);
                $teacherId = !empty($_POST['bulk_teacher_id']) ? (int)$_POST['bulk_teacher_id'] : null;
                $subjectIds = isset($_POST['subject_ids']) ? explode(',', $_POST['subject_ids']) : [];
                $subjectIds = array_values(array_filter(array_map('intval', $subjectIds)));

                if (!$classId || empty($subjectIds)) {
                    $message = 'Please select a class and at least one subject';
                    $messageType = 'error';
                    break;
                }

                try {
                    if (!$db->getRow('SELECT id FROM classes WHERE id = ?', [$classId])) {
                        throw new Exception('Selected class does not exist');
                    }
                    if ($teacherId && !$db->getRow('SELECT id FROM teachers WHERE id = ?', [$teacherId])) {
                        throw new Exception('Selected teacher does not exist');
                    }
                    $placeholders = implode(',', array_fill(0, count($subjectIds), '?'));
                    $params = [$classId];

                    if ($teacherId) {
                        $params[] = $teacherId;
                    }

                    $params = array_merge($params, $subjectIds);

                    if ($teacherId) {
                        $db->query(
                            "UPDATE subjects SET class_id = ?, teacher_id = ? WHERE id IN ($placeholders)",
                            $params
                        );
                    } else {
                        $db->query(
                            "UPDATE subjects SET class_id = ? WHERE id IN ($placeholders)",
                            $params
                        );
                    }

                    Security::logAudit('BULK_ASSIGNED_SUBJECTS', 'subjects');
                    $message = 'Subjects assigned successfully';
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
    flash_redirect($message, 'success', BASE_URL . '/admin/subjects.php');
}

// Get subject for editing
$subject = null;
if ($action === 'edit' && $id) {
    $subject = $db->getRow(
        "SELECT s.*, c.class_name
         FROM subjects s
         LEFT JOIN classes c ON s.class_id = c.id
         WHERE s.id = ?",
        [$id]
    );
}

// Get all classes for dropdown
$classes = $db->getRows(
    "SELECT * FROM classes WHERE is_active = 1 ORDER BY class_name, section"
);

// Get teachers for dropdown
$teachers = $db->getRows(
    "SELECT t.id, u.first_name, u.last_name
     FROM teachers t
     JOIN users u ON t.user_id = u.id
     WHERE u.is_active = 1
     ORDER BY u.first_name, u.last_name"
);

// Get subjects list with filters
$page = page_param('p');
$limit = 20;
$offset = ($page - 1) * $limit;

// Build query with filters
$whereClause = "WHERE 1=1";
$params = [];

if ($classFilter > 0) {
    $whereClause .= " AND s.class_id = ?";
    $params[] = $classFilter;
}

// Count total subjects
$countQuery = "SELECT COUNT(*) as count FROM subjects s $whereClause";
$totalSubjects = $db->getRow($countQuery, $params)['count'] ?? 0;
$totalPages = $totalSubjects > 0 ? ceil($totalSubjects / $limit) : 1;

// Get subjects
$query = "SELECT s.*,
                 c.class_name, c.section,
                 CONCAT(u.first_name, ' ', u.last_name) as teacher_name,
                 (SELECT COUNT(*) FROM results WHERE subject_id = s.id) as result_count,
                 (SELECT COUNT(*) FROM homework WHERE subject_id = s.id) as homework_count
          FROM subjects s
          LEFT JOIN classes c ON s.class_id = c.id
          LEFT JOIN teachers t ON s.teacher_id = t.id
          LEFT JOIN users u ON t.user_id = u.id
          $whereClause
          ORDER BY c.class_name, s.subject_name
          LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;

$subjects = $db->getRows($query, $params);

// Get unassigned subjects (no class)
$unassignedSubjects = $db->getRows(
    "SELECT s.*,
            CONCAT(u.first_name, ' ', u.last_name) as teacher_name
     FROM subjects s
     LEFT JOIN teachers t ON s.teacher_id = t.id
     LEFT JOIN users u ON t.user_id = u.id
     WHERE s.class_id IS NULL
     ORDER BY s.subject_name"
);

// Helper function to generate subject code
if (!function_exists('generateSubjectCode')) {
    function generateSubjectCode($subjectName) {
        // Take first 3 letters of subject name and add random number
        $prefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $subjectName), 0, 3));
        $random = str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
        return $prefix . $random;
    }
}
?>

<style>
/* Modal positioning fix - ensures modals appear above sidebar */
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

/* Ensure sidebar doesn't block modals */
.sidebar {
    z-index: 1000;
}

.dashboard-main {
    position: relative;
    z-index: 1;
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
    background: #ffd700;
    color: #002855;
}

.btn-icon.text-danger:hover {
    background: #dc3545;
    color: white;
}

.btn-icon.text-muted {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
}

/* Badge styles */
.badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

.badge-success {
    background-color: #d4edda;
    color: #155724;
}

.badge-danger {
    background-color: #f8d7da;
    color: #721c24;
}

.badge-warning {
    background-color: #fff3cd;
    color: #856404;
}

.badge-info {
    background-color: #d1ecf1;
    color: #0c5460;
}

.badge-light {
    background-color: #f8f9fa;
    color: #6c757d;
}

.badge-secondary {
    background-color: #e2e3e5;
    color: #383d41;
}

/* Card tools */
.card-tools {
    display: flex;
    gap: 10px;
}

#searchInput {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 0.9rem;
    width: 250px;
}

/* Bulk action bar */
.bulk-action-bar {
    position: fixed;
    bottom: 20px;
    left: 50%;
    transform: translateX(-50%);
    background: white;
    padding: 15px 30px;
    border-radius: 50px;
    box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
    display: flex;
    align-items: center;
    gap: 20px;
    z-index: 9999;
    border: 2px solid #ffd700;
    animation: slideUp 0.3s ease;
}

@keyframes slideUp {
    from {
        transform: translate(-50%, 100%);
        opacity: 0;
    }
    to {
        transform: translate(-50%, 0);
        opacity: 1;
    }
}

.bulk-info {
    font-weight: 600;
    color: #002855;
}

.bulk-actions {
    display: flex;
    gap: 10px;
}

/* Pagination */
.pagination {
    display: flex;
    justify-content: center;
    gap: 5px;
    margin-top: 20px;
    flex-wrap: wrap;
}

.page-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 35px;
    height: 35px;
    padding: 0 5px;
    background: white;
    border: 1px solid #ddd;
    border-radius: 4px;
    color: #333;
    text-decoration: none;
    transition: all 0.3s ease;
}

.page-link:hover,
.page-link.active {
    background: #002855;
    color: white;
    border-color: #002855;
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

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}

.checkbox-label input[type="checkbox"] {
    width: 16px;
    height: 16px;
    cursor: pointer;
}

.form-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #dee2e6;
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

.alert-warning {
    background-color: #fff3cd;
    color: #856404;
    border: 1px solid #ffeeba;
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

.float-right {
    float: right;
}

/* Responsive */
@media (max-width: 768px) {
    .card-tools {
        width: 100%;
        margin-top: 10px;
    }

    #searchInput {
        width: 100% !important;
    }

    .action-buttons {
        justify-content: center;
    }

    .bulk-action-bar {
        width: 90%;
        flex-direction: column;
        text-align: center;
        padding: 15px;
        border-radius: 10px;
    }

    .modal-content {
        width: 95%;
        margin: 10% auto;
    }
}
</style>

<div class="dashboard-container">
    <?php render_sidebar('admin'); ?>

    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>Subject Management</h1>
            <div class="header-actions">
                <a href="?action=add" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add New Subject
                </a>
                <button type="button" class="btn btn-accent" onclick="showBulkAssignModal()">
                    <i class="fas fa-tasks"></i> Bulk Assign
                </button>
                <a href="export.php?type=subjects" class="btn btn-outline">
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

        <!-- Filter Bar -->
        <div class="card">
            <div class="card-body">
                <form method="GET" class="form-row">
                    <div class="form-group col-md-4">
                        <label for="class_id">Filter by Class</label>
                        <select id="class_id" name="class_id" class="form-control" onchange="this.form.submit()">
                            <option value="0">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo e($class['id']); ?>" <?php echo $classFilter == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name'] . ' ' . ($class['section'] ?? '')); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-2">
                        <label>&nbsp;</label>
                        <a href="subjects.php" class="btn btn-outline form-control">Clear Filter</a>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($action === 'add' || $action === 'edit'): ?>
        <!-- Subject Form -->
        <div class="card">
            <div class="card-header">
                <h3><?php echo $action === 'add' ? 'Add New Subject' : 'Edit Subject'; ?></h3>
            </div>
            <div class="card-body">
                <form method="POST" class="form-container">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="<?php echo e($action); ?>">
                    <?php if ($action === 'edit'): ?>
                    <input type="hidden" name="id" value="<?php echo e($id); ?>">
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="subject_name">Subject Name *</label>
                            <input type="text" id="subject_name" name="subject_name" class="form-control"
                                   value="<?php echo htmlspecialchars($subject['subject_name'] ?? ''); ?>"
                                   placeholder="e.g., Mathematics, English Language" required
                                   onkeyup="generateCode(this.value)">
                        </div>

                        <div class="form-group col-md-6">
                            <label for="subject_code">Subject Code *</label>
                            <input type="text" id="subject_code" name="subject_code" class="form-control"
                                   value="<?php echo htmlspecialchars($subject['subject_code'] ?? ''); ?>"
                                   placeholder="e.g., MATH001" required>
                            <small class="form-text text-muted">Unique identifier for the subject</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" class="form-control" rows="3"><?php echo htmlspecialchars($subject['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="form_class_id">Assign to Class</label>
                            <select id="form_class_id" name="class_id" class="form-control">
                                <option value="">-- Not Assigned --</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo e($class['id']); ?>"
                                    <?php echo (isset($subject['class_id']) && $subject['class_id'] == $class['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['class_name'] . ' ' . ($class['section'] ?? '')); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group col-md-6">
                            <label for="form_teacher_id">Assign Teacher</label>
                            <select id="form_teacher_id" name="teacher_id" class="form-control">
                                <option value="">-- Not Assigned --</option>
                                <?php foreach ($teachers as $teacher): ?>
                                <option value="<?php echo e($teacher['id']); ?>"
                                    <?php echo (isset($subject['teacher_id']) && $subject['teacher_id'] == $teacher['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="is_active" value="1"
                                   <?php echo (!isset($subject['is_active']) || $subject['is_active']) ? 'checked' : ''; ?>>
                            Active Subject
                        </label>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> <?php echo $action === 'add' ? 'Add Subject' : 'Update Subject'; ?>
                        </button>
                        <a href="subjects.php<?php echo $classFilter ? '?class_id=' . $classFilter : ''; ?>" class="btn btn-outline">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- Unassigned Subjects Alert -->
        <?php if (!empty($unassignedSubjects)): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <strong><?php echo count($unassignedSubjects); ?> subject(s) not assigned to any class.</strong>
            <button type="button" class="btn btn-sm btn-accent float-right" onclick="showBulkAssignModal()">
                Assign Now
            </button>
        </div>
        <?php endif; ?>

        <!-- Subjects List -->
        <div class="card">
            <div class="card-header">
                <h3>
                    <?php if ($classFilter > 0):
                        $className = $db->getRow("SELECT class_name, section FROM classes WHERE id = ?", [$classFilter]);
                        echo 'Subjects for ' . htmlspecialchars($className['class_name'] . ' ' . ($className['section'] ?? ''));
                    else: ?>
                        All Subjects
                    <?php endif; ?>
                </h3>
                <div class="card-tools">
                    <input type="text" id="searchInput" class="form-control" placeholder="Search subjects..." style="width: 250px;">
                </div>
            </div>
            <div class="card-body">
                <?php if (!empty($subjects)): ?>
                <div class="table-responsive">
                    <table class="data-table" id="subjectsTable">
                        <thead>
                            <tr>
                                <th width="30"><input type="checkbox" id="selectAll"></th>
                                <th>Code</th>
                                <th>Subject Name</th>
                                <th>Class</th>
                                <th>Teacher</th>
                                <th>Results</th>
                                <th>Homework</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjects as $subject): ?>
                            <tr>
                                <td><input type="checkbox" class="subject-select" value="<?php echo e($subject['id']); ?>"></td>
                                <td><strong><?php echo htmlspecialchars($subject['subject_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                <td>
                                    <?php if ($subject['class_name']): ?>
                                        <?php echo htmlspecialchars($subject['class_name'] . ' ' . ($subject['section'] ?? '')); ?>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Unassigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($subject['teacher_name']): ?>
                                        <?php echo htmlspecialchars($subject['teacher_name']); ?>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">Not Assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($subject['result_count'] > 0): ?>
                                    <span class="badge badge-info"><?php echo e($subject['result_count']); ?></span>
                                    <?php else: ?>
                                    <span class="badge badge-light">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($subject['homework_count'] > 0): ?>
                                    <span class="badge badge-info"><?php echo e($subject['homework_count']); ?></span>
                                    <?php else: ?>
                                    <span class="badge badge-light">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($subject['is_active']): ?>
                                    <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                    <span class="badge badge-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="?action=edit&id=<?php echo e($subject['id']); ?><?php echo $classFilter ? '&class_id=' . $classFilter : ''; ?>" class="btn-icon" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="results.php?subject_id=<?php echo e($subject['id']); ?>" class="btn-icon" title="View Results">
                                            <i class="fas fa-chart-line"></i>
                                        </a>
                                        <?php if ($subject['result_count'] == 0 && $subject['homework_count'] == 0): ?>
                                        <button type="button" class="btn-icon text-danger"
                                                onclick="confirmDelete(<?php echo e($subject['id']); ?>, '<?php echo htmlspecialchars(addslashes($subject['subject_name'])); ?>')"
                                                title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php else: ?>
                                        <span class="btn-icon text-muted" title="Cannot delete - has results or homework">
                                            <i class="fas fa-trash"></i>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Bulk Action Bar -->
                <div id="bulkActionBar" class="bulk-action-bar" style="display: none;">
                    <div class="bulk-info">
                        <span id="selectedCount">0</span> subject(s) selected
                    </div>
                    <div class="bulk-actions">
                        <button type="button" class="btn btn-sm btn-accent" onclick="showBulkAssignModal()">
                            <i class="fas fa-tasks"></i> Assign to Class
                        </button>
                        <button type="button" class="btn btn-sm btn-outline" onclick="clearSelection()">
                            <i class="fas fa-times"></i> Clear
                        </button>
                    </div>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                    <a href="?p=<?php echo $page - 1; ?><?php echo $classFilter ? '&class_id=' . $classFilter : ''; ?>" class="page-link">
                        <i class="fas fa-chevron-left"></i> Previous
                    </a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?p=<?php echo e($i); ?><?php echo $classFilter ? '&class_id=' . $classFilter : ''; ?>" class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                        <?php echo e($i); ?>
                    </a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                    <a href="?p=<?php echo $page + 1; ?><?php echo $classFilter ? '&class_id=' . $classFilter : ''; ?>" class="page-link">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    No subjects found. Click "Add New Subject" to create one.
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </main>
</div>

<!-- Bulk Assign Modal -->
<div id="bulkAssignModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Bulk Assign Subjects</h3>
            <button type="button" class="close" onclick="closeModal('bulkAssignModal')">&times;</button>
        </div>
        <form method="POST" id="bulkAssignForm">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="bulk_assign">
                <input type="hidden" name="subject_ids" id="bulkSubjectIds">

                <div class="form-group">
                    <label for="bulk_class_id">Assign to Class *</label>
                    <select id="bulk_class_id" name="bulk_class_id" class="form-control" required>
                        <option value="">-- Select Class --</option>
                        <?php foreach ($classes as $class): ?>
                        <option value="<?php echo e($class['id']); ?>">
                            <?php echo htmlspecialchars($class['class_name'] . ' ' . ($class['section'] ?? '')); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="bulk_teacher_id">Assign Teacher (Optional)</label>
                    <select id="bulk_teacher_id" name="bulk_teacher_id" class="form-control">
                        <option value="">-- Not Assigned --</option>
                        <?php foreach ($teachers as $teacher): ?>
                        <option value="<?php echo e($teacher['id']); ?>">
                            <?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="alert alert-info" id="selectedSubjectsInfo">
                    <span id="modalSelectedCount">0</span> subjects will be assigned
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('bulkAssignModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Assign Subjects</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Confirm Delete</h3>
            <button type="button" class="close" onclick="closeModal('deleteModal')">&times;</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to delete subject: <strong id="subjectName"></strong>?</p>
            <p class="text-danger">This action cannot be undone.</p>
        </div>
        <div class="modal-footer">
            <form method="POST" id="deleteForm">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteId">
                <button type="button" class="btn btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete</button>
            </form>
        </div>
    </div>
</div>

<script>
let selectedSubjects = [];

// Auto-generate subject code
function generateCode(subjectName) {
    if (!document.getElementById('subject_code').value) {
        const code = subjectName.substring(0, 3).toUpperCase() + Math.floor(Math.random() * 900 + 100);
        document.getElementById('subject_code').value = code;
    }
}

// Select All functionality
document.getElementById('selectAll')?.addEventListener('change', function(e) {
    const checkboxes = document.querySelectorAll('.subject-select');
    checkboxes.forEach(cb => {
        cb.checked = e.target.checked;
    });
    updateSelectedSubjects();
});

// Individual checkbox change
document.querySelectorAll('.subject-select').forEach(cb => {
    cb.addEventListener('change', updateSelectedSubjects);
});

function updateSelectedSubjects() {
    selectedSubjects = Array.from(document.querySelectorAll('.subject-select:checked')).map(cb => cb.value);
    const bar = document.getElementById('bulkActionBar');
    const countSpan = document.getElementById('selectedCount');

    if (selectedSubjects.length > 0) {
        countSpan.textContent = selectedSubjects.length;
        bar.style.display = 'flex';
    } else {
        bar.style.display = 'none';
    }
}

function clearSelection() {
    document.querySelectorAll('.subject-select').forEach(cb => {
        cb.checked = false;
    });
    document.getElementById('selectAll').checked = false;
    updateSelectedSubjects();
}

function showBulkAssignModal() {
    if (selectedSubjects.length === 0) {
        alert('Please select at least one subject to assign');
        return;
    }

    document.getElementById('modalSelectedCount').textContent = selectedSubjects.length;
    document.getElementById('bulkSubjectIds').value = selectedSubjects.join(',');
    document.getElementById('bulkAssignModal').style.display = 'block';
}

function confirmDelete(id, name) {
    document.getElementById('deleteId').value = id;
    document.getElementById('subjectName').textContent = name;
    document.getElementById('deleteModal').style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Search functionality
document.getElementById('searchInput')?.addEventListener('keyup', function() {
    const searchTerm = this.value.toLowerCase();
    const table = document.getElementById('subjectsTable');
    if (!table) return;

    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

    for (let row of rows) {
        const code = row.cells[1]?.textContent.toLowerCase() || '';
        const name = row.cells[2]?.textContent.toLowerCase() || '';
        const className = row.cells[3]?.textContent.toLowerCase() || '';
        const teacher = row.cells[4]?.textContent.toLowerCase() || '';

        if (name.includes(searchTerm) || code.includes(searchTerm) || className.includes(searchTerm) || teacher.includes(searchTerm)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    }
});

// Close modals when clicking outside
window.onclick = function(event) {
    const bulkModal = document.getElementById('bulkAssignModal');
    const deleteModal = document.getElementById('deleteModal');

    if (event.target === bulkModal) {
        bulkModal.style.display = 'none';
    }
    if (event.target === deleteModal) {
        deleteModal.style.display = 'none';
    }
}
</script>

<?php
// No footer include - removed as requested
?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
