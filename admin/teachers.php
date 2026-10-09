<?php
// admin/teachers.php - Teacher Management
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';

Security::requireRole('admin');

$pageTitle = 'Teacher Management';
$extraCSS = ['admin.css'];
$extraJS = ['teachers.js'];

include __DIR__ . '/../includes/header.php';

// Get database instance
$db = Database::getInstance();

[$message, $messageType] = flash_get();

// Handle actions
$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$id = isset($_POST['id']) ? (int)$_POST['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : null);
if (!in_array($action, ['list', 'add', 'edit'], true)) $action = 'list';

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
                $username = Security::sanitize($_POST['username'] ?? '');
                $email = Security::sanitize($_POST['email'] ?? '');
                $firstName = Security::sanitize($_POST['first_name'] ?? '');
                $lastName = Security::sanitize($_POST['last_name'] ?? '');
                $phone = Security::sanitize($_POST['phone'] ?? '');
                $password = $_POST['password'] ?? '';
                $employeeId = Security::sanitize($_POST['employee_id'] ?? '');
                $qualification = Security::sanitize($_POST['qualification'] ?? '');
                $specialization = Security::sanitize($_POST['specialization'] ?? '');
                $dateOfHire = valid_date($_POST['date_of_hire'] ?? '') ?? date('Y-m-d');
                $address = Security::sanitize($_POST['address'] ?? '');
                $emergencyContact = Security::sanitize($_POST['emergency_contact'] ?? '');
                $isActive = isset($_POST['is_active']) ? 1 : 0;

                // Validate required fields
                if (empty($username) || empty($email) || empty($firstName) || empty($lastName) || empty($employeeId)) {
                    $message = 'Please fill in all required fields';
                    $messageType = 'error';
                    break;
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $message = 'Please enter a valid email address';
                    $messageType = 'error';
                    break;
                }
                if (!valid_username($username)) {
                    $message = 'Username must be 3-50 letters, numbers, dots, dashes or underscores';
                    $messageType = 'error';
                    break;
                }
                if ($phone !== '' && !Security::validatePhone($phone)) {
                    $message = 'Please enter a valid Nigerian phone number';
                    $messageType = 'error';
                    break;
                }
                if ($password !== '' && ($pwError = strong_password($password))) {
                    $message = $pwError;
                    $messageType = 'error';
                    break;
                }

                if ($postAction === 'add' && empty($password)) {
                    $message = 'Password is required for new teachers';
                    $messageType = 'error';
                    break;
                }

                try {
                    $db->beginTransaction();

                    if ($postAction === 'add') {
                        // Check if username, email or employee_id already exists
                        $existing = $db->getRow(
                            "SELECT id FROM users WHERE username = ? OR email = ?",
                            [$username, $email]
                        );

                        if ($existing) {
                            throw new Exception("Username or email already exists");
                        }

                        $existingEmp = $db->getRow(
                            "SELECT id FROM teachers WHERE employee_id = ?",
                            [$employeeId]
                        );

                        if ($existingEmp) {
                            throw new Exception("Employee ID already exists");
                        }

                        // Create user
                        $userId = $db->insert(
                            "INSERT INTO users (username, email, password_hash, first_name, last_name, phone, role, is_active)
                             VALUES (?, ?, ?, ?, ?, ?, 'teacher', ?)",
                            [
                                $username,
                                $email,
                                Security::hashPassword($password),
                                $firstName,
                                $lastName,
                                $phone,
                                $isActive
                            ]
                        );

                        if (!$userId) {
                            throw new Exception("Failed to create user");
                        }

                        // Create teacher
                        $teacherId = $db->insert(
                            "INSERT INTO teachers (user_id, employee_id, qualification, specialization, date_of_hire, address, emergency_contact)
                             VALUES (?, ?, ?, ?, ?, ?, ?)",
                            [
                                $userId,
                                $employeeId,
                                $qualification,
                                $specialization,
                                $dateOfHire,
                                $address,
                                $emergencyContact
                            ]
                        );

                        Security::logAudit('ADDED_TEACHER', 'teachers', $teacherId);
                        $message = 'Teacher added successfully';
                        $messageType = 'success';

                    } else {
                        // Edit existing teacher
                        $existingTeacher = $db->getRow('SELECT user_id FROM teachers WHERE id = ?', [$id]);
                        if (!$existingTeacher) {
                            throw new Exception("Teacher not found");
                        }
                        $userId = (int)$existingTeacher['user_id'];
                        if ($db->getRow('SELECT id FROM teachers WHERE employee_id = ? AND id <> ?', [$employeeId, $id])) {
                            throw new Exception("Employee ID already exists");
                        }

                        // Check if username or email already exists for other users
                        $existing = $db->getRow(
                            "SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?",
                            [$username, $email, $userId]
                        );

                        if ($existing) {
                            throw new Exception("Username or email already exists");
                        }

                        // Update user
                        $userParams = [$firstName, $lastName, $email, $phone, $isActive, $userId];
                        $userSql = "UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ?, is_active = ?";

                        if (!empty($password)) {
                            $userSql .= ", password_hash = ?";
                            array_splice($userParams, 5, 0, Security::hashPassword($password));
                        }

                        $userSql .= " WHERE id = ?";
                        $db->query($userSql, $userParams);

                        // Update teacher
                        $db->query(
                            "UPDATE teachers SET employee_id = ?, qualification = ?, specialization = ?,
                             date_of_hire = ?, address = ?, emergency_contact = ? WHERE id = ?",
                            [
                                $employeeId,
                                $qualification,
                                $specialization,
                                $dateOfHire,
                                $address,
                                $emergencyContact,
                                $id
                            ]
                        );

                        Security::logAudit('UPDATED_TEACHER', 'teachers', $id);
                        $message = 'Teacher updated successfully';
                        $messageType = 'success';
                    }

                    $db->commit();

                } catch (Exception $e) {
                    $db->rollback();
                    $message = 'Error: ' . $e->getMessage();
                    $messageType = 'error';
                }
                break;

            case 'delete':
                try {
                    if (!$id) {
                        throw new Exception("Invalid teacher ID");
                    }

                    // Check if teacher has classes
                    $classCount = $db->getRow("SELECT COUNT(*) as count FROM classes WHERE teacher_id = ?", [$id])['count'] ?? 0;

                    if ($classCount > 0) {
                        $message = 'Cannot delete teacher with assigned classes';
                        $messageType = 'error';
                        break;
                    }

                    // Get user_id first
                    $teacher = $db->getRow("SELECT user_id FROM teachers WHERE id = ?", [$id]);

                    if ($teacher) {
                        // Soft delete user (set is_active to 0 instead of actual delete)
                        $db->query(
                            "UPDATE users SET is_active = 0 WHERE id = ?",
                            [$teacher['user_id']]
                        );

                        Security::logAudit('DEACTIVATED_TEACHER', 'teachers', $id);
                        $message = 'Teacher deactivated successfully';
                        $messageType = 'success';
                    } else {
                        $message = 'Teacher not found';
                        $messageType = 'error';
                    }
                } catch (Exception $e) {
                    $message = 'Error: ' . $e->getMessage();
                    $messageType = 'error';
                }
                break;

            case 'activate':
                try {
                    $teacher = $db->getRow("SELECT user_id FROM teachers WHERE id = ?", [$id]);

                    if ($teacher) {
                        $db->query(
                            "UPDATE users SET is_active = 1 WHERE id = ?",
                            [$teacher['user_id']]
                        );

                        Security::logAudit('ACTIVATED_TEACHER', 'teachers', $id);
                        $message = 'Teacher activated successfully';
                        $messageType = 'success';
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
    flash_redirect($message, 'success', BASE_URL . '/admin/teachers.php');
}

// Get teacher for editing
$teacher = null;
if ($action === 'edit' && $id) {
    $teacher = $db->getRow(
        "SELECT t.*, u.username, u.email, u.first_name, u.last_name, u.phone, u.is_active, u.id as user_id
         FROM teachers t
         JOIN users u ON t.user_id = u.id
         WHERE t.id = ?",
        [$id]
    );
}

// Get teachers list with pagination
$page = page_param('p');
$limit = 20;
$offset = ($page - 1) * $limit;

// Count total teachers (active only)
$totalTeachers = $db->getRow(
    "SELECT COUNT(*) as count FROM teachers t
     JOIN users u ON t.user_id = u.id
     WHERE u.is_active = 1"
)['count'] ?? 0;

$totalPages = $totalTeachers > 0 ? ceil($totalTeachers / $limit) : 1;

$teachers = $db->getRows(
    "SELECT t.*, u.first_name, u.last_name, u.email, u.phone, u.is_active, u.profile_image,
            (SELECT COUNT(*) FROM classes WHERE teacher_id = t.id) as class_count,
            (SELECT COUNT(*) FROM subjects WHERE teacher_id = t.id) as subject_count
     FROM teachers t
     JOIN users u ON t.user_id = u.id
     WHERE u.is_active = 1
     ORDER BY u.first_name, u.last_name
     LIMIT ? OFFSET ?",
    [$limit, $offset]
);

// Get inactive teachers for separate listing
$inactiveTeachers = $db->getRows(
    "SELECT t.*, u.first_name, u.last_name, u.email, u.phone, u.is_active, u.profile_image
     FROM teachers t
     JOIN users u ON t.user_id = u.id
     WHERE u.is_active = 0
     ORDER BY u.first_name, u.last_name"
);

// Helper function for employee ID generation if not defined
if (!function_exists('generateEmployeeId')) {
    function generateEmployeeId() {
        $year = date('Y');
        $random = str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
        return "TCH/{$year}/{$random}";
    }
}
?>

<div class="dashboard-container">
    <?php render_sidebar('admin'); ?>

    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>Teacher Management</h1>
            <div class="header-actions">
                <a href="?action=add" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add New Teacher
                </a>
                <a href="export.php?type=teachers" class="btn btn-outline">
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
        <!-- Teacher Form -->
        <div class="card">
            <div class="card-header">
                <h3><?php echo $action === 'add' ? 'Add New Teacher' : 'Edit Teacher'; ?></h3>
            </div>
            <div class="card-body">
                <form method="POST" class="form-container" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="<?php echo e($action); ?>">
                    <?php if ($action === 'edit'): ?>
                    <input type="hidden" name="user_id" value="<?php echo $teacher['user_id'] ?? ''; ?>">
                    <?php endif; ?>

                    <div class="form-section">
                        <h3><i class="fas fa-user"></i> Personal Information</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="first_name">First Name *</label>
                                <input type="text" id="first_name" name="first_name" class="form-control"
                                       value="<?php echo htmlspecialchars($teacher['first_name'] ?? ''); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="last_name">Last Name *</label>
                                <input type="text" id="last_name" name="last_name" class="form-control"
                                       value="<?php echo htmlspecialchars($teacher['last_name'] ?? ''); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="employee_id">Employee ID *</label>
                                <input type="text" id="employee_id" name="employee_id" class="form-control"
                                       value="<?php echo htmlspecialchars($teacher['employee_id'] ?? (function_exists('generateEmployeeId') ? generateEmployeeId() : '')); ?>"
                                       placeholder="TCH/YYYY/000" required>
                                <small class="form-text text-muted">Format: TCH/2024/001</small>
                            </div>

                            <div class="form-group">
                                <label for="date_of_hire">Date of Hire *</label>
                                <input type="date" id="date_of_hire" name="date_of_hire" class="form-control"
                                       value="<?php echo htmlspecialchars($teacher['date_of_hire'] ?? date('Y-m-d')); ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-graduation-cap"></i> Professional Information</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="qualification">Qualification</label>
                                <input type="text" id="qualification" name="qualification" class="form-control"
                                       value="<?php echo htmlspecialchars($teacher['qualification'] ?? ''); ?>"
                                       placeholder="e.g., B.Ed, M.Ed, PGDE">
                            </div>

                            <div class="form-group">
                                <label for="specialization">Specialization</label>
                                <input type="text" id="specialization" name="specialization" class="form-control"
                                       value="<?php echo htmlspecialchars($teacher['specialization'] ?? ''); ?>"
                                       placeholder="e.g., Mathematics, English, Early Years">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-address-card"></i> Contact Information</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="email">Email Address *</label>
                                <input type="email" id="email" name="email" class="form-control"
                                       value="<?php echo htmlspecialchars($teacher['email'] ?? ''); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="phone">Phone Number</label>
                                <input type="tel" id="phone" name="phone" class="form-control"
                                       value="<?php echo htmlspecialchars($teacher['phone'] ?? ''); ?>"
                                       placeholder="e.g., 08012345678">
                            </div>

                            <div class="form-group">
                                <label for="emergency_contact">Emergency Contact</label>
                                <input type="text" id="emergency_contact" name="emergency_contact" class="form-control"
                                       value="<?php echo htmlspecialchars($teacher['emergency_contact'] ?? ''); ?>">
                            </div>

                            <div class="form-group full-width">
                                <label for="address">Address</label>
                                <textarea id="address" name="address" class="form-control" rows="2"><?php echo htmlspecialchars($teacher['address'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-lock"></i> Login Information</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="username">Username *</label>
                                <input type="text" id="username" name="username" class="form-control"
                                       value="<?php echo htmlspecialchars($teacher['username'] ?? ''); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="password">Password <?php echo $action === 'add' ? '*' : ''; ?></label>
                                <input type="password" id="password" name="password" class="form-control"
                                       <?php echo $action === 'add' ? 'required' : ''; ?>>
                                <?php if ($action === 'edit'): ?>
                                <small class="form-text text-muted">Leave blank to keep current password</small>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="is_active" value="1"
                                           <?php echo (!isset($teacher['is_active']) || $teacher['is_active']) ? 'checked' : ''; ?>>
                                    Active Account
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> <?php echo $action === 'add' ? 'Add Teacher' : 'Update Teacher'; ?>
                        </button>
                        <a href="teachers.php" class="btn btn-outline">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>
        <!-- Teachers List -->
        <div class="card">
            <div class="card-header">
                <h3>Active Teachers</h3>
                <div class="card-tools">
                    <input type="text" id="searchInput" class="form-control" placeholder="Search teachers..." style="width: 250px;">
                </div>
            </div>
            <div class="card-body">
                <?php if (!empty($teachers)): ?>
                <div class="table-responsive">
                    <table class="data-table" id="teachersTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Photo</th>
                                <th>Name</th>
                                <th>Employee ID</th>
                                <th>Qualification</th>
                                <th>Specialization</th>
                                <th>Contact</th>
                                <th>Classes</th>
                                <th>Subjects</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teachers as $teacher): ?>
                            <tr>
                                <td><?php echo e($teacher['id']); ?></td>
                                <td>
                                    <?php if (!empty($teacher['profile_image'])): ?>
                                    <img src="<?php echo BASE_URL; ?>/uploads/teachers/<?php echo e($teacher['profile_image']); ?>"
                                         alt="Profile" class="table-avatar">
                                    <?php else: ?>
                                    <div class="avatar-placeholder">
                                        <i class="fas fa-chalkboard-teacher"></i>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($teacher['employee_id']); ?></td>
                                <td><?php echo htmlspecialchars($teacher['qualification'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($teacher['specialization'] ?? '-'); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($teacher['email']); ?><br>
                                    <small><?php echo htmlspecialchars($teacher['phone'] ?? ''); ?></small>
                                </td>
                                <td><?php echo e($teacher['class_count']); ?></td>
                                <td><?php echo e($teacher['subject_count']); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="?action=edit&id=<?php echo e($teacher['id']); ?>" class="btn-icon" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="teacher-profile.php?id=<?php echo e($teacher['id']); ?>" class="btn-icon" title="View Profile">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="assign-subjects.php?teacher_id=<?php echo e($teacher['id']); ?>" class="btn-icon" title="Assign Subjects">
                                            <i class="fas fa-book"></i>
                                        </a>
                                        <?php if ($teacher['class_count'] == 0 && $teacher['subject_count'] == 0): ?>
                                        <button type="button" class="btn-icon text-danger"
                                                onclick="confirmDeactivate(<?php echo e($teacher['id']); ?>, '<?php echo htmlspecialchars(addslashes($teacher['first_name'] . ' ' . $teacher['last_name'])); ?>')"
                                                title="Deactivate">
                                            <i class="fas fa-ban"></i>
                                        </button>
                                        <?php else: ?>
                                        <span class="btn-icon text-muted" title="Cannot deactivate - has classes or subjects">
                                            <i class="fas fa-ban"></i>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                    <a href="?p=<?php echo $page - 1; ?>" class="page-link">
                        <i class="fas fa-chevron-left"></i> Previous
                    </a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?p=<?php echo e($i); ?>" class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                        <?php echo e($i); ?>
                    </a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                    <a href="?p=<?php echo $page + 1; ?>" class="page-link">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    No active teachers found. Click "Add New Teacher" to create one.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Inactive Teachers Section -->
        <?php if (!empty($inactiveTeachers)): ?>
        <div class="card mt-4">
            <div class="card-header">
                <h3>Inactive Teachers</h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Photo</th>
                                <th>Name</th>
                                <th>Employee ID</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inactiveTeachers as $teacher): ?>
                            <tr>
                                <td><?php echo e($teacher['id']); ?></td>
                                <td>
                                    <?php if (!empty($teacher['profile_image'])): ?>
                                    <img src="<?php echo BASE_URL; ?>/uploads/teachers/<?php echo e($teacher['profile_image']); ?>"
                                         alt="Profile" class="table-avatar">
                                    <?php else: ?>
                                    <div class="avatar-placeholder">
                                        <i class="fas fa-chalkboard-teacher"></i>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($teacher['employee_id']); ?></td>
                                <td><?php echo htmlspecialchars($teacher['email']); ?></td>
                                <td><?php echo htmlspecialchars($teacher['phone'] ?? ''); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button type="button" class="btn-icon text-success"
                                                onclick="activateTeacher(<?php echo e($teacher['id']); ?>)"
                                                title="Activate">
                                            <i class="fas fa-check-circle"></i>
                                        </button>
                                    </div>
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

<!-- Deactivate Confirmation Modal -->
<div id="deactivateModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Confirm Deactivate</h3>
            <button type="button" class="close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to deactivate teacher <strong id="teacherName"></strong>?</p>
            <p class="text-warning">The teacher will no longer be able to login.</p>
        </div>
        <div class="modal-footer">
            <form method="POST" id="deactivateForm">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deactivateId">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-warning">Deactivate</button>
            </form>
        </div>
    </div>
</div>

<!-- Activate Form (hidden) -->
<form method="POST" id="activateForm" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
    <input type="hidden" name="action" value="activate">
    <input type="hidden" name="id" id="activateId">
</form>

<style>
.table-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
}

.avatar-placeholder {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background-color: #f0f0f0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #999;
}

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

.btn-icon.text-success:hover {
    background: #28a745;
    color: white;
}

.btn-icon.text-muted {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
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
    width: 250px;
}

.mt-4 {
    margin-top: 20px;
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
function confirmDeactivate(id, name) {
    document.getElementById('deactivateId').value = id;
    document.getElementById('teacherName').textContent = name;
    document.getElementById('deactivateModal').style.display = 'block';
}

function activateTeacher(id) {
    if (confirm('Are you sure you want to activate this teacher?')) {
        document.getElementById('activateId').value = id;
        document.getElementById('activateForm').submit();
    }
}

function closeModal() {
    document.getElementById('deactivateModal').style.display = 'none';
}

// Search functionality
document.getElementById('searchInput')?.addEventListener('keyup', function() {
    const searchTerm = this.value.toLowerCase();
    const table = document.getElementById('teachersTable');
    if (!table) return;

    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

    for (let row of rows) {
        const name = row.cells[2]?.textContent.toLowerCase() || '';
        const empId = row.cells[3]?.textContent.toLowerCase() || '';
        const email = row.cells[6]?.textContent.toLowerCase() || '';

        if (name.includes(searchTerm) || empId.includes(searchTerm) || email.includes(searchTerm)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    }
});

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('deactivateModal');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
}

// Initialize DataTable if available
$(document).ready(function() {
    if (typeof $.fn.DataTable !== 'undefined') {
        $('#teachersTable').DataTable({
            pageLength: 25,
            order: [[2, 'asc']],
            columnDefs: [
                { orderable: false, targets: [1, 9] }
            ],
            paging: true,
            searching: false, // We have custom search
            info: true
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
