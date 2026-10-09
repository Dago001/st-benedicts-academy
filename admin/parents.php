<?php
// admin/parents.php - Parent/Guardian Management
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';

Security::requireRole('admin');

$pageTitle = 'Parent Management';
$extraCSS = ['admin.css'];
$extraJS = ['parents.js'];

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
                $occupation = Security::sanitize($_POST['occupation'] ?? '');
                $address = Security::sanitize($_POST['address'] ?? '');
                $emergencyContact = Security::sanitize($_POST['emergency_contact'] ?? '');
                $relationship = Security::sanitize($_POST['relationship'] ?? '');
                $isActive = isset($_POST['is_active']) ? 1 : 0;

                // Validate required fields
                if (empty($username) || empty($email) || empty($firstName) || empty($lastName)) {
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
                    $message = 'Password is required for new parents';
                    $messageType = 'error';
                    break;
                }

                try {
                    $db->beginTransaction();

                    if ($postAction === 'add') {
                        // Check if username or email already exists
                        $existing = $db->getRow(
                            "SELECT id FROM users WHERE username = ? OR email = ?",
                            [$username, $email]
                        );

                        if ($existing) {
                            throw new Exception("Username or email already exists");
                        }

                        // Create user
                        $userId = $db->insert(
                            "INSERT INTO users (username, email, password_hash, first_name, last_name, phone, role, is_active)
                             VALUES (?, ?, ?, ?, ?, ?, 'parent', ?)",
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

                        // Create parent record
                        $parentId = $db->insert(
                            "INSERT INTO parents (user_id, occupation, address, emergency_contact, relationship)
                             VALUES (?, ?, ?, ?, ?)",
                            [
                                $userId,
                                $occupation,
                                $address,
                                $emergencyContact,
                                $relationship
                            ]
                        );

                        Security::logAudit('ADDED_PARENT', 'parents', $parentId);
                        $message = 'Parent added successfully';
                        $messageType = 'success';

                    } else {
                        if (!$id) {
                            throw new Exception("Invalid parent ID");
                        }

                        // Get current parent data
                        $currentParent = $db->getRow(
                            "SELECT user_id FROM parents WHERE id = ?",
                            [$id]
                        );

                        if (!$currentParent) {
                            throw new Exception("Parent not found");
                        }

                        $userId = $currentParent['user_id'];

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

                        // Update parent
                        $db->query(
                            "UPDATE parents SET occupation = ?, address = ?, emergency_contact = ?, relationship = ? WHERE id = ?",
                            [
                                $occupation,
                                $address,
                                $emergencyContact,
                                $relationship,
                                $id
                            ]
                        );

                        Security::logAudit('UPDATED_PARENT', 'parents', $id);
                        $message = 'Parent updated successfully';
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
                        throw new Exception("Invalid parent ID");
                    }

                    // Get user_id first
                    $parent = $db->getRow("SELECT user_id FROM parents WHERE id = ?", [$id]);

                    if ($parent) {
                        // Check if parent has children
                        $childrenCount = $db->getRow(
                            "SELECT COUNT(*) as count FROM students WHERE parent_id = ?",
                            [$id]
                        )['count'] ?? 0;

                        if ($childrenCount > 0) {
                            // Option 1: Prevent deletion
                            $message = 'Cannot delete parent with linked children. Please reassign children first.';
                            $messageType = 'error';
                            break;

                            // Option 2: Uncomment below to allow deletion and set children parent_id to NULL
                            // $db->query("UPDATE students SET parent_id = NULL WHERE parent_id = ?", [$id]);
                        }

                        // Soft delete user (set is_active to 0)
                        $db->query(
                            "UPDATE users SET is_active = 0 WHERE id = ?",
                            [$parent['user_id']]
                        );

                        Security::logAudit('DEACTIVATED_PARENT', 'parents', $id);
                        $message = 'Parent deactivated successfully';
                        $messageType = 'success';
                    } else {
                        $message = 'Parent not found';
                        $messageType = 'error';
                    }
                } catch (Exception $e) {
                    $message = 'Error: ' . $e->getMessage();
                    $messageType = 'error';
                }
                break;

            case 'activate':
                try {
                    if (!$id) {
                        throw new Exception("Invalid parent ID");
                    }

                    $parent = $db->getRow("SELECT user_id FROM parents WHERE id = ?", [$id]);

                    if ($parent) {
                        $db->query(
                            "UPDATE users SET is_active = 1 WHERE id = ?",
                            [$parent['user_id']]
                        );

                        Security::logAudit('ACTIVATED_PARENT', 'parents', $id);
                        $message = 'Parent activated successfully';
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
    flash_redirect($message, 'success', BASE_URL . '/admin/parents.php');
}

// Get parent for editing
$parent = null;
if ($action === 'edit' && $id) {
    $parent = $db->getRow(
        "SELECT p.*, u.username, u.email, u.first_name, u.last_name, u.phone, u.is_active, u.id as user_id
         FROM parents p
         JOIN users u ON p.user_id = u.id
         WHERE p.id = ?",
        [$id]
    );
}

// Get parents list with pagination
$page = page_param('p');
$limit = 20;
$offset = ($page - 1) * $limit;

// Count total parents (active only)
$totalParents = $db->getRow(
    "SELECT COUNT(*) as count FROM parents p
     JOIN users u ON p.user_id = u.id
     WHERE u.is_active = 1"
)['count'] ?? 0;

$totalPages = $totalParents > 0 ? ceil($totalParents / $limit) : 1;

$parents = $db->getRows(
    "SELECT p.*, u.first_name, u.last_name, u.email, u.phone, u.is_active, u.profile_image,
            (SELECT COUNT(*) FROM students WHERE parent_id = p.id) as children_count
     FROM parents p
     JOIN users u ON p.user_id = u.id
     WHERE u.is_active = 1
     ORDER BY u.first_name, u.last_name
     LIMIT ? OFFSET ?",
    [$limit, $offset]
);

// Get inactive parents for separate listing
$inactiveParents = $db->getRows(
    "SELECT p.*, u.first_name, u.last_name, u.email, u.phone, u.is_active, u.profile_image
     FROM parents p
     JOIN users u ON p.user_id = u.id
     WHERE u.is_active = 0
     ORDER BY u.first_name, u.last_name"
);

// Get all parents for dropdown (for student assignment)
$allParents = $db->getRows(
    "SELECT p.id, u.first_name, u.last_name, u.email
     FROM parents p
     JOIN users u ON p.user_id = u.id
     WHERE u.is_active = 1
     ORDER BY u.first_name, u.last_name"
);

// Helper function to generate parent ID (optional)
if (!function_exists('generateParentId')) {
    function generateParentId() {
        $year = date('Y');
        $random = str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        return "PRT/{$year}/{$random}";
    }
}
?>

<style>
/* Modal positioning fix */
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
    max-width: 700px;
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

.btn-icon.text-success:hover {
    background: #28a745;
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

/* Table styles */
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
.form-section {
    margin-bottom: 25px;
    padding: 15px;
    background-color: #f8f9fa;
    border-radius: 8px;
}

.form-section h3 {
    margin-top: 0;
    margin-bottom: 15px;
    font-size: 16px;
    color: #002855;
    display: flex;
    align-items: center;
    gap: 8px;
}

.form-section h3 i {
    color: #ffd700;
}

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

textarea.form-control {
    resize: vertical;
    min-height: 80px;
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

.mt-4 {
    margin-top: 20px;
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
            <h1>Parent Management</h1>
            <div class="header-actions">
                <?php if ($action === 'add' || $action === 'edit'): ?>
                <a href="parents.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
                <?php else: ?>
                <a href="?action=add" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add New Parent
                </a>
                <a href="export.php?type=parents" class="btn btn-outline">
                    <i class="fas fa-download"></i> Export
                </a>
                <?php endif; ?>
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
        <!-- Parent Form -->
        <div class="card">
            <div class="card-header">
                <h3><?php echo $action === 'add' ? 'Add New Parent/Guardian' : 'Edit Parent/Guardian'; ?></h3>
            </div>
            <div class="card-body">
                <form method="POST" class="form-container" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="<?php echo e($action); ?>">
                    <?php if ($action === 'edit'): ?>
                    <input type="hidden" name="id" value="<?php echo e($id); ?>">
                    <?php endif; ?>

                    <div class="form-section">
                        <h3><i class="fas fa-user"></i> Personal Information</h3>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="first_name">First Name *</label>
                                <input type="text" id="first_name" name="first_name" class="form-control"
                                       value="<?php echo htmlspecialchars($parent['first_name'] ?? ''); ?>" required>
                            </div>

                            <div class="form-group col-md-6">
                                <label for="last_name">Last Name *</label>
                                <input type="text" id="last_name" name="last_name" class="form-control"
                                       value="<?php echo htmlspecialchars($parent['last_name'] ?? ''); ?>" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="email">Email Address *</label>
                                <input type="email" id="email" name="email" class="form-control"
                                       value="<?php echo htmlspecialchars($parent['email'] ?? ''); ?>" required>
                            </div>

                            <div class="form-group col-md-6">
                                <label for="phone">Phone Number</label>
                                <input type="tel" id="phone" name="phone" class="form-control"
                                       value="<?php echo htmlspecialchars($parent['phone'] ?? ''); ?>"
                                       placeholder="e.g., 08012345678">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="occupation">Occupation</label>
                                <input type="text" id="occupation" name="occupation" class="form-control"
                                       value="<?php echo htmlspecialchars($parent['occupation'] ?? ''); ?>"
                                       placeholder="e.g., Business, Teacher, Doctor">
                            </div>

                            <div class="form-group col-md-6">
                                <label for="relationship">Relationship to Child</label>
                                <input type="text" id="relationship" name="relationship" class="form-control"
                                       value="<?php echo htmlspecialchars($parent['relationship'] ?? ''); ?>"
                                       placeholder="e.g., Father, Mother, Guardian">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="address">Address</label>
                            <textarea id="address" name="address" class="form-control" rows="2"><?php echo htmlspecialchars($parent['address'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="emergency_contact">Emergency Contact</label>
                            <input type="text" id="emergency_contact" name="emergency_contact" class="form-control"
                                   value="<?php echo htmlspecialchars($parent['emergency_contact'] ?? ''); ?>"
                                   placeholder="Alternative phone number">
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-lock"></i> Login Information</h3>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="username">Username *</label>
                                <input type="text" id="username" name="username" class="form-control"
                                       value="<?php echo htmlspecialchars($parent['username'] ?? ''); ?>" required>
                            </div>

                            <div class="form-group col-md-6">
                                <label for="password">Password <?php echo $action === 'add' ? '*' : ''; ?></label>
                                <input type="password" id="password" name="password" class="form-control"
                                       <?php echo $action === 'add' ? 'required' : ''; ?>>
                                <?php if ($action === 'edit'): ?>
                                <small class="form-text text-muted">Leave blank to keep current password</small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="is_active" value="1"
                                       <?php echo (!isset($parent['is_active']) || $parent['is_active']) ? 'checked' : ''; ?>>
                                Active Account
                            </label>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> <?php echo $action === 'add' ? 'Add Parent' : 'Update Parent'; ?>
                        </button>
                        <a href="parents.php" class="btn btn-outline">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- Stats Cards -->
        <div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px;">
            <div class="stat-card" style="background: white; border-radius: 10px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); display: flex; align-items: center; gap: 15px;">
                <div class="stat-icon" style="width: 50px; height: 50px; border-radius: 10px; background: rgba(0,40,85,0.1); display: flex; align-items: center; justify-content: center; font-size: 24px; color: #002855;">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-content">
                    <h3 style="font-size: 28px; font-weight: 700; margin: 0; color: #002855;"><?php echo e($totalParents); ?></h3>
                    <p style="margin: 5px 0 0; color: #6c757d;">Active Parents</p>
                </div>
            </div>

            <div class="stat-card" style="background: white; border-radius: 10px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); display: flex; align-items: center; gap: 15px;">
                <div class="stat-icon" style="width: 50px; height: 50px; border-radius: 10px; background: rgba(220,53,69,0.1); display: flex; align-items: center; justify-content: center; font-size: 24px; color: #dc3545;">
                    <i class="fas fa-user-slash"></i>
                </div>
                <div class="stat-content">
                    <h3 style="font-size: 28px; font-weight: 700; margin: 0; color: #dc3545;"><?php echo count($inactiveParents); ?></h3>
                    <p style="margin: 5px 0 0; color: #6c757d;">Inactive Parents</p>
                </div>
            </div>

            <div class="stat-card" style="background: white; border-radius: 10px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); display: flex; align-items: center; gap: 15px;">
                <div class="stat-icon" style="width: 50px; height: 50px; border-radius: 10px; background: rgba(40,167,69,0.1); display: flex; align-items: center; justify-content: center; font-size: 24px; color: #28a745;">
                    <i class="fas fa-child"></i>
                </div>
                <div class="stat-content">
                    <h3 style="font-size: 28px; font-weight: 700; margin: 0; color: #28a745;"><?php
                        $totalChildren = $db->getRow("SELECT COUNT(*) as count FROM students")['count'] ?? 0;
                        echo $totalChildren;
                    ?></h3>
                    <p style="margin: 5px 0 0; color: #6c757d;">Total Children</p>
                </div>
            </div>
        </div>

        <!-- Active Parents List -->
        <div class="card">
            <div class="card-header">
                <h3>Active Parents/Guardians</h3>
                <div class="card-tools">
                    <input type="text" id="searchInput" class="form-control" placeholder="Search parents..." style="width: 250px;">
                </div>
            </div>
            <div class="card-body">
                <?php if (!empty($parents)): ?>
                <div class="table-responsive">
                    <table class="data-table" id="parentsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Photo</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Occupation</th>
                                <th>Children</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($parents as $parent): ?>
                            <tr>
                                <td><?php echo e($parent['id']); ?></td>
                                <td>
                                    <?php if (!empty($parent['profile_image'])): ?>
                                    <img src="<?php echo BASE_URL; ?>/uploads/parents/<?php echo e($parent['profile_image']); ?>"
                                         alt="Profile" class="table-avatar">
                                    <?php else: ?>
                                    <div class="avatar-placeholder">
                                        <i class="fas fa-user"></i>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($parent['first_name'] . ' ' . $parent['last_name']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($parent['email']); ?></td>
                                <td><?php echo htmlspecialchars($parent['phone'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($parent['occupation'] ?? '-'); ?></td>
                                <td>
                                    <span class="badge badge-info"><?php echo e($parent['children_count']); ?></span>
                                </td>
                                <td>
                                    <?php if ($parent['is_active']): ?>
                                    <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                    <span class="badge badge-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="?action=edit&id=<?php echo e($parent['id']); ?>" class="btn-icon" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="view-parent.php?id=<?php echo e($parent['id']); ?>" class="btn-icon" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="students.php?parent_id=<?php echo e($parent['id']); ?>" class="btn-icon" title="View Children">
                                            <i class="fas fa-child"></i>
                                        </a>
                                        <?php if ($parent['children_count'] == 0): ?>
                                        <button type="button" class="btn-icon text-danger"
                                                onclick="confirmDeactivate(<?php echo e($parent['id']); ?>, '<?php echo htmlspecialchars(addslashes($parent['first_name'] . ' ' . $parent['last_name'])); ?>')"
                                                title="Deactivate">
                                            <i class="fas fa-ban"></i>
                                        </button>
                                        <?php else: ?>
                                        <span class="btn-icon text-muted" title="Cannot deactivate - has linked children">
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
                    No active parents found. Click "Add New Parent" to create one.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Inactive Parents Section -->
        <?php if (!empty($inactiveParents)): ?>
        <div class="card mt-4">
            <div class="card-header">
                <h3>Inactive Parents</h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Photo</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Occupation</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inactiveParents as $parent): ?>
                            <tr>
                                <td><?php echo e($parent['id']); ?></td>
                                <td>
                                    <?php if (!empty($parent['profile_image'])): ?>
                                    <img src="<?php echo BASE_URL; ?>/uploads/parents/<?php echo e($parent['profile_image']); ?>"
                                         alt="Profile" class="table-avatar">
                                    <?php else: ?>
                                    <div class="avatar-placeholder">
                                        <i class="fas fa-user"></i>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($parent['first_name'] . ' ' . $parent['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($parent['email']); ?></td>
                                <td><?php echo htmlspecialchars($parent['phone'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($parent['occupation'] ?? '-'); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button type="button" class="btn-icon text-success"
                                                onclick="activateParent(<?php echo e($parent['id']); ?>)"
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
            <button type="button" class="close" onclick="closeModal('deactivateModal')">&times;</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to deactivate parent <strong id="parentName"></strong>?</p>
            <p class="text-warning">The parent will no longer be able to login.</p>
        </div>
        <div class="modal-footer">
            <form method="POST" id="deactivateForm">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deactivateId">
                <button type="button" class="btn btn-secondary" onclick="closeModal('deactivateModal')">Cancel</button>
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

<script>
let selectedItems = [];

// Search functionality
document.getElementById('searchInput')?.addEventListener('keyup', function() {
    const searchTerm = this.value.toLowerCase();
    const table = document.getElementById('parentsTable');
    if (!table) return;

    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

    for (let row of rows) {
        const name = row.cells[2]?.textContent.toLowerCase() || '';
        const email = row.cells[3]?.textContent.toLowerCase() || '';
        const phone = row.cells[4]?.textContent.toLowerCase() || '';

        if (name.includes(searchTerm) || email.includes(searchTerm) || phone.includes(searchTerm)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    }
});

function confirmDeactivate(id, name) {
    document.getElementById('deactivateId').value = id;
    document.getElementById('parentName').textContent = name;
    document.getElementById('deactivateModal').style.display = 'block';
}

function activateParent(id) {
    if (confirm('Are you sure you want to activate this parent?')) {
        document.getElementById('activateId').value = id;
        document.getElementById('activateForm').submit();
    }
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Close modals when clicking outside
window.onclick = function(event) {
    const deactivateModal = document.getElementById('deactivateModal');

    if (event.target === deactivateModal) {
        deactivateModal.style.display = 'none';
    }
}

// Initialize DataTable if available
$(document).ready(function() {
    if (typeof $.fn.DataTable !== 'undefined') {
        $('#parentsTable').DataTable({
            pageLength: 25,
            order: [[2, 'asc']],
            columnDefs: [
                { orderable: false, targets: [1, 8] }
            ],
            paging: false,
            searching: false,
            info: false
        });
    }
});
</script>

<?php
// No footer include - removed as requested
?>