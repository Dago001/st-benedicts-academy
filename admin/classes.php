<?php
// admin/classes.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';

Security::requireRole('admin');

$pageTitle = 'Class Management';
$extraJS = ['classes.js'];

include __DIR__ . '/../includes/header.php';

// Get database instance
$db = Database::getInstance();

[$message, $messageType] = flash_get();

$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$id = isset($_POST['id']) ? (int)$_POST['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : null);
if (!in_array($action, ['list', 'add', 'edit'], true)) $action = 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token';
        $messageType = 'error';
    } else {
        $postAction = $_POST['action'] ?? '';

        switch ($postAction) {
            case 'add':
            case 'edit':
                // Validate academic year format
                $academicYear = Security::sanitize($_POST['academic_year'] ?? '');
                if (!preg_match('/^\d{4}-\d{4}$/', $academicYear)) {
                    $message = 'Academic year must be in format YYYY-YYYY (e.g., 2024-2025)';
                    $messageType = 'error';
                    break;
                }

                $data = [
                    'class_name' => Security::sanitize($_POST['class_name'] ?? ''),
                    'section' => Security::sanitize($_POST['section'] ?? 'A'),
                    'academic_year' => $academicYear,
                    'teacher_id' => !empty($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : null,
                    'capacity' => (int)Security::sanitize($_POST['capacity'] ?? 30),
                    'is_active' => isset($_POST['is_active']) ? 1 : 0
                ];

                // Validate required fields
                if (mb_strlen($data['class_name']) > 50 || mb_strlen($data['section']) > 20) {
                    $message = 'Class name or section is too long';
                    $messageType = 'error';
                    break;
                }
                if ($data['teacher_id'] && !$db->getRow('SELECT id FROM teachers WHERE id = ?', [$data['teacher_id']])) {
                    $message = 'Selected teacher does not exist';
                    $messageType = 'error';
                    break;
                }
                if (empty($data['class_name'])) {
                    $message = 'Class name is required';
                    $messageType = 'error';
                    break;
                }

                if ($data['capacity'] < 1 || $data['capacity'] > 100) {
                    $message = 'Capacity must be between 1 and 100';
                    $messageType = 'error';
                    break;
                }

                try {
                    if ($postAction === 'add') {
                        $db->insert(
                            "INSERT INTO classes (class_name, section, academic_year, teacher_id, capacity, is_active)
                             VALUES (?, ?, ?, ?, ?, ?)",
                            [
                                $data['class_name'],
                                $data['section'],
                                $data['academic_year'],
                                $data['teacher_id'],
                                $data['capacity'],
                                $data['is_active']
                            ]
                        );
                        Security::logAudit('ADDED_CLASS', 'classes');
                        $message = 'Class added successfully';
                        $messageType = 'success';
                    } else {
                        if (!$id || !$db->getRow('SELECT id FROM classes WHERE id = ?', [$id])) {
                            throw new Exception("Class not found");
                        }
                        $enrolled = (int)$db->getRow('SELECT COUNT(*) c FROM students WHERE class_id = ?', [$id])['c'];
                        if ($data['capacity'] < $enrolled) {
                            throw new Exception("Capacity cannot be lower than the $enrolled students already enrolled");
                        }

                        $db->query(
                            "UPDATE classes SET class_name = ?, section = ?, academic_year = ?,
                             teacher_id = ?, capacity = ?, is_active = ? WHERE id = ?",
                            [
                                $data['class_name'],
                                $data['section'],
                                $data['academic_year'],
                                $data['teacher_id'],
                                $data['capacity'],
                                $data['is_active'],
                                $id
                            ]
                        );
                        Security::logAudit('UPDATED_CLASS', 'classes', $id);
                        $message = 'Class updated successfully';
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
                        throw new Exception("Invalid class ID");
                    }

                    // Check if class has students
                    $studentCount = $db->getRow("SELECT COUNT(*) as count FROM students WHERE class_id = ?", [$id])['count'] ?? 0;

                    if ($studentCount > 0) {
                        $message = 'Cannot delete class with enrolled students';
                        $messageType = 'error';
                    } else {
                        // Check if class has subjects
                        $subjectCount = $db->getRow("SELECT COUNT(*) as count FROM subjects WHERE class_id = ?", [$id])['count'] ?? 0;

                        if ($subjectCount > 0) {
                            // Option 1: Prevent deletion
                            $message = 'Cannot delete class with subjects. Remove subjects first.';
                            $messageType = 'error';

                            // Option 2: Uncomment below to allow deletion and set subject class_id to NULL
                            // $db->query("UPDATE subjects SET class_id = NULL WHERE class_id = ?", [$id]);
                        } else {
                            $db->query("DELETE FROM classes WHERE id = ?", [$id]);
                            Security::logAudit('DELETED_CLASS', 'classes', $id);
                            $message = 'Class deleted successfully';
                            $messageType = 'success';
                        }
                    }
                } catch (Exception $e) {
                    $message = 'Error: ' . $e->getMessage();
                    $messageType = 'error';
                }
                break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $messageType === 'success') {
    flash_redirect($message, 'success', BASE_URL . '/admin/classes.php');
}

// Get class for editing
$class = null;
if ($action === 'edit' && $id) {
    $class = $db->getRow("SELECT * FROM classes WHERE id = ?", [$id]);
}

// Get teachers for dropdown (only active teachers)
$teachers = $db->getRows(
    "SELECT t.id, u.first_name, u.last_name
     FROM teachers t
     JOIN users u ON t.user_id = u.id
     WHERE u.is_active = 1
     ORDER BY u.first_name, u.last_name"
);

// Get classes list with statistics
$classes = $db->getRows(
    "SELECT c.*,
            CONCAT(u.first_name, ' ', u.last_name) as teacher_name,
            (SELECT COUNT(*) FROM students WHERE class_id = c.id) as student_count,
            (SELECT COUNT(*) FROM subjects WHERE class_id = c.id) as subject_count
     FROM classes c
     LEFT JOIN teachers t ON c.teacher_id = t.id
     LEFT JOIN users u ON t.user_id = u.id
     ORDER BY c.class_name, c.section"
);
?>

<div class="dashboard-container">
    <?php render_sidebar('admin'); ?>

    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>Class Management</h1>
            <div class="header-actions">
                <a href="?action=add" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add New Class
                </a>
                <a href="export.php?type=classes" class="btn btn-outline">
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

        <?php if ($action === 'add' || $action === 'edit'): ?>
        <!-- Class Form -->
        <div class="card">
            <div class="card-header">
                <h3><?php echo $action === 'add' ? 'Add New Class' : 'Edit Class'; ?></h3>
            </div>
            <div class="card-body">
                <form method="POST" class="form-container">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="<?php echo e($action); ?>">

                    <div class="form-row">
                        <div class="form-group">
                            <label for="class_name">Class Name *</label>
                            <input type="text" id="class_name" name="class_name" class="form-control"
                                   value="<?php echo htmlspecialchars($class['class_name'] ?? ''); ?>"
                                   placeholder="e.g., Nursery 1, Reception, Year 1" required>
                        </div>

                        <div class="form-group">
                            <label for="section">Section</label>
                            <input type="text" id="section" name="section" class="form-control"
                                   value="<?php echo htmlspecialchars($class['section'] ?? 'A'); ?>"
                                   placeholder="e.g., A, B, C">
                        </div>

                        <div class="form-group">
                            <label for="academic_year">Academic Year *</label>
                            <input type="text" id="academic_year" name="academic_year" class="form-control"
                                   value="<?php echo htmlspecialchars($class['academic_year'] ?? (currentAcademicYear())); ?>"
                                   placeholder="YYYY-YYYY" required>
                            <small class="form-text text-muted">Format: 2024-2025</small>
                        </div>

                        <div class="form-group">
                            <label for="teacher_id">Class Teacher</label>
                            <select id="teacher_id" name="teacher_id" class="form-control">
                                <option value="">-- Select Teacher --</option>
                                <?php foreach ($teachers as $teacher): ?>
                                <option value="<?php echo e($teacher['id']); ?>"
                                    <?php echo (isset($class['teacher_id']) && $class['teacher_id'] == $teacher['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="capacity">Capacity *</label>
                            <input type="number" id="capacity" name="capacity" class="form-control"
                                   value="<?php echo htmlspecialchars($class['capacity'] ?? 30); ?>"
                                   min="1" max="100" required>
                        </div>

                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="is_active" value="1"
                                       <?php echo (!isset($class['is_active']) || $class['is_active']) ? 'checked' : ''; ?>>
                                Active Class
                            </label>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> <?php echo $action === 'add' ? 'Add Class' : 'Update Class'; ?>
                        </button>
                        <a href="classes.php" class="btn btn-outline">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>
        <!-- Classes List -->
        <div class="card">
            <div class="card-header">
                <h3>All Classes</h3>
                <div class="card-tools">
                    <input type="text" id="searchInput" class="form-control" placeholder="Search classes..." style="width: 250px;">
                </div>
            </div>
            <div class="card-body">
                <?php if (!empty($classes)): ?>
                <div class="table-responsive">
                    <table class="data-table" id="classesTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Class</th>
                                <th>Section</th>
                                <th>Academic Year</th>
                                <th>Class Teacher</th>
                                <th>Students</th>
                                <th>Subjects</th>
                                <th>Capacity</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($classes as $cls): ?>
                            <tr>
                                <td><?php echo e($cls['id']); ?></td>
                                <td><strong><?php echo htmlspecialchars($cls['class_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($cls['section']); ?></td>
                                <td><?php echo htmlspecialchars($cls['academic_year']); ?></td>
                                <td><?php echo htmlspecialchars($cls['teacher_name'] ?? 'Not Assigned'); ?></td>
                                <td>
                                    <?php echo e($cls['student_count']); ?>/<?php echo e($cls['capacity']); ?>
                                    <?php
                                    $percentage = $cls['capacity'] > 0 ? ($cls['student_count'] / $cls['capacity']) * 100 : 0;
                                    if ($percentage >= 90): ?>
                                        <span class="badge badge-danger">Full</span>
                                    <?php elseif ($percentage >= 75): ?>
                                        <span class="badge badge-warning">Almost Full</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo e($cls['subject_count']); ?></td>
                                <td><?php echo e($cls['capacity']); ?></td>
                                <td>
                                    <?php if ($cls['is_active']): ?>
                                    <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                    <span class="badge badge-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="?action=edit&id=<?php echo e($cls['id']); ?>" class="btn-icon" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="subjects.php?class_id=<?php echo e($cls['id']); ?>" class="btn-icon" title="Manage Subjects">
                                            <i class="fas fa-book"></i>
                                        </a>
                                        <a href="students.php?class_id=<?php echo e($cls['id']); ?>" class="btn-icon" title="View Students">
                                            <i class="fas fa-users"></i>
                                        </a>
                                        <a href="timetable.php?class_id=<?php echo e($cls['id']); ?>" class="btn-icon" title="Timetable">
                                            <i class="fas fa-clock"></i>
                                        </a>
                                        <?php if ($cls['student_count'] == 0 && $cls['subject_count'] == 0): ?>
                                        <button type="button" class="btn-icon text-danger"
                                                onclick="confirmDelete(<?php echo e($cls['id']); ?>, '<?php echo htmlspecialchars(addslashes($cls['class_name'] . ' ' . $cls['section'])); ?>')"
                                                title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php else: ?>
                                        <span class="btn-icon text-muted" title="Cannot delete - has students or subjects">
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
                <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    No classes found. Click "Add New Class" to create one.
                </div>
                <?php endif; ?>
            </div>
        </div>
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
            <p>Are you sure you want to delete class: <strong id="className"></strong>?</p>
            <p class="text-danger">This action cannot be undone.</p>
        </div>
        <div class="modal-footer">
            <form method="POST" id="deleteForm">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteId">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete</button>
            </form>
        </div>
    </div>
</div>

<style>
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

.btn-icon.text-muted {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
}

.badge-success {
    background-color: #d4edda;
    color: #155724;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
}

.badge-danger {
    background-color: #f8d7da;
    color: #721c24;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
}

.badge-warning {
    background-color: #fff3cd;
    color: #856404;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
}

.card-tools {
    display: flex;
    gap: 10px;
}

#searchInput {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 0.9rem;
}

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
}
</style>

<script>
function confirmDelete(id, name) {
    document.getElementById('deleteId').value = id;
    document.getElementById('className').textContent = name;
    document.getElementById('deleteModal').style.display = 'block';
}

function closeModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

// Search functionality
document.getElementById('searchInput')?.addEventListener('keyup', function() {
    const searchTerm = this.value.toLowerCase();
    const table = document.getElementById('classesTable');
    if (!table) return;

    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

    for (let row of rows) {
        const className = row.cells[1]?.textContent.toLowerCase() || '';
        const section = row.cells[2]?.textContent.toLowerCase() || '';
        const teacher = row.cells[4]?.textContent.toLowerCase() || '';

        if (className.includes(searchTerm) || section.includes(searchTerm) || teacher.includes(searchTerm)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    }
});

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('deleteModal');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
}

// Initialize DataTable if available
$(document).ready(function() {
    if (typeof $.fn.DataTable !== 'undefined') {
        $('#classesTable').DataTable({
            pageLength: 25,
            order: [[1, 'asc']],
            columnDefs: [
                { orderable: false, targets: [9] }
            ],
            paging: true,
            searching: false, // We have custom search
            info: true
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
