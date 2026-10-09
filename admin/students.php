<?php
// admin/students.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';

Security::requireRole('admin');

$pageTitle = 'Student Management';
$extraJS = ['students.js'];

$db = Database::getInstance();

[$message, $messageType] = flash_get();

// Handle actions
$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
if (!in_array($action, ['list', 'add', 'edit'], true)) $action = 'list';
// Posted id (modal forms) takes precedence over the query string
if (isset($_POST['id'])) $id = (int)$_POST['id'];

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

                // Validate required fields
                if (empty($username) || empty($email) || empty($firstName) || empty($lastName)) {
                    $message = 'Please fill in all required fields';
                    $messageType = 'error';
                    break;
                }
                if (!valid_username($username)) {
                    $message = 'Username must be 3-50 letters, numbers, dots, dashes or underscores';
                    $messageType = 'error';
                    break;
                }
                if (!Security::validateEmail($email)) {
                    $message = 'Please enter a valid email address';
                    $messageType = 'error';
                    break;
                }
                if ($phone !== '' && !Security::validatePhone($phone)) {
                    $message = 'Please enter a valid Nigerian phone number';
                    $messageType = 'error';
                    break;
                }
                if ($postAction === 'add' && empty($password)) {
                    $message = 'Password is required for new students';
                    $messageType = 'error';
                    break;
                }
                if ($password !== '' && ($pwError = strong_password($password))) {
                    $message = $pwError;
                    $messageType = 'error';
                    break;
                }

                $studentData = [
                    'admission_number' => Security::sanitize($_POST['admission_number'] ?? ''),
                    'class_id' => !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null,
                    'parent_id' => !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null,
                    'date_of_birth' => valid_date($_POST['date_of_birth'] ?? ''),
                    'gender' => in_array($_POST['gender'] ?? '', ['male', 'female', 'other'], true) ? $_POST['gender'] : null,
                    'admission_date' => valid_date($_POST['admission_date'] ?? '') ?? date('Y-m-d'),
                    'address' => Security::sanitize($_POST['address'] ?? ''),
                    'blood_group' => Security::sanitize($_POST['blood_group'] ?? ''),
                    'medical_notes' => Security::sanitize($_POST['medical_notes'] ?? '')
                ];

                if ($studentData['date_of_birth'] && $studentData['date_of_birth'] > date('Y-m-d')) {
                    $message = 'Date of birth cannot be in the future';
                    $messageType = 'error';
                    break;
                }
                if ($studentData['class_id'] && !$db->getRow('SELECT id FROM classes WHERE id = ?', [$studentData['class_id']])) {
                    $message = 'Selected class does not exist';
                    $messageType = 'error';
                    break;
                }
                if ($studentData['parent_id'] && !$db->getRow('SELECT id FROM parents WHERE id = ?', [$studentData['parent_id']])) {
                    $message = 'Selected parent does not exist';
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
                             VALUES (?, ?, ?, ?, ?, ?, 'student', 1)",
                            [
                                $username,
                                $email,
                                Security::hashPassword($password),
                                $firstName,
                                $lastName,
                                $phone
                            ]
                        );

                        if (!$userId) {
                            throw new Exception("Failed to create user");
                        }

                        // Generate admission number if not provided
                        if (empty($studentData['admission_number'])) {
                            $studentData['admission_number'] = generateAdmissionNumber();
                        } elseif ($db->getRow('SELECT id FROM students WHERE admission_number = ?', [$studentData['admission_number']])) {
                            throw new Exception('Admission number already exists');
                        }

                        // Create student
                        $studentId = $db->insert(
                            "INSERT INTO students (user_id, admission_number, class_id, parent_id, date_of_birth,
                             gender, admission_date, address, blood_group, medical_notes)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            [
                                $userId,
                                $studentData['admission_number'],
                                $studentData['class_id'],
                                $studentData['parent_id'],
                                $studentData['date_of_birth'],
                                $studentData['gender'],
                                $studentData['admission_date'],
                                $studentData['address'],
                                $studentData['blood_group'],
                                $studentData['medical_notes']
                            ]
                        );

                        Security::logAudit('ADDED_STUDENT', 'students', $studentId);
                        $message = 'Student added successfully';
                        $messageType = 'success';

                    } else {
                        // Edit existing student
                        $existingStudent = $db->getRow('SELECT user_id FROM students WHERE id = ?', [$id]);
                        if (!$existingStudent) {
                            throw new Exception("Student not found");
                        }
                        $userId = (int)$existingStudent['user_id'];
                        if ($db->getRow('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $userId])) {
                            throw new Exception('Email is already used by another account');
                        }
                        if ($db->getRow('SELECT id FROM students WHERE admission_number = ? AND id <> ?', [$studentData['admission_number'], $id])) {
                            throw new Exception('Admission number already exists');
                        }

                        // Update user
                        $userParams = [$firstName, $lastName, $email, $phone, $userId];
                        $userSql = "UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ?";

                        if (!empty($password)) {
                            $userSql .= ", password_hash = ?";
                            array_splice($userParams, 4, 0, Security::hashPassword($password));
                        }

                        $userSql .= " WHERE id = ?";
                        $db->query($userSql, $userParams);

                        // Update student
                        $db->query(
                            "UPDATE students SET admission_number = ?, class_id = ?, parent_id = ?,
                             date_of_birth = ?, gender = ?, admission_date = ?, address = ?,
                             blood_group = ?, medical_notes = ? WHERE id = ?",
                            [
                                $studentData['admission_number'],
                                $studentData['class_id'],
                                $studentData['parent_id'],
                                $studentData['date_of_birth'],
                                $studentData['gender'],
                                $studentData['admission_date'],
                                $studentData['address'],
                                $studentData['blood_group'],
                                $studentData['medical_notes'],
                                $id
                            ]
                        );

                        Security::logAudit('UPDATED_STUDENT', 'students', $id);
                        $message = 'Student updated successfully';
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
                    // Get user_id first
                    $student = $db->getRow("SELECT user_id FROM students WHERE id = ?", [$id]);

                    if ($student) {
                        // Instead of soft delete, we can either:
                        // 1. Actually delete the user (not recommended)
                        // 2. Set is_active to 0 (recommended)
                        $db->query(
                            "UPDATE users SET is_active = 0 WHERE id = ?",
                            [$student['user_id']]
                        );

                        Security::logAudit('DEACTIVATED_STUDENT', 'students', $id);
                        $message = 'Student deactivated successfully';
                        $messageType = 'success';
                    } else {
                        $message = 'Student not found';
                        $messageType = 'error';
                    }
                } catch (Exception $e) {
                    $message = 'Error: ' . $e->getMessage();
                    $messageType = 'error';
                }
                break;

            case 'promote':
                $fromClass = (int)($_POST['from_class'] ?? 0);
                $toClass = (int)($_POST['to_class'] ?? 0);
                $academicYear = Security::sanitize($_POST['academic_year'] ?? '');

                if (!$fromClass || !$toClass || !$academicYear) {
                    $message = 'Please select both classes and enter academic year';
                    $messageType = 'error';
                    break;
                }

                try {
                    if ($fromClass === $toClass) {
                        throw new Exception('Source and destination classes must differ');
                    }
                    $result = $db->query(
                        "UPDATE students SET class_id = ? WHERE class_id = ?",
                        [$toClass, $fromClass]
                    );

                    // Get affected rows
                    $count = $result->rowCount();

                    Security::logAudit('PROMOTED_STUDENTS', 'students', null,
                                     ['from' => $fromClass, 'to' => $toClass, 'year' => $academicYear]);

                    $message = "$count students promoted successfully";
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = 'Error: ' . $e->getMessage();
                    $messageType = 'error';
                }
                break;

            case 'activate':
                try {
                    $student = $db->getRow("SELECT user_id FROM students WHERE id = ?", [$id]);

                    if ($student) {
                        $db->query(
                            "UPDATE users SET is_active = 1 WHERE id = ?",
                            [$student['user_id']]
                        );

                        Security::logAudit('ACTIVATED_STUDENT', 'students', $id);
                        $message = 'Student activated successfully';
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

// Post/Redirect/Get so a browser refresh does not resubmit the form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $messageType === 'success') {
    flash_redirect($message, 'success', BASE_URL . '/admin/students.php');
}

// Get data based on action
$classes = $db->getRows("SELECT * FROM classes WHERE is_active = 1 ORDER BY class_name");

// Get parents for dropdown
$parents = $db->getRows(
    "SELECT p.id, u.first_name, u.last_name, u.email
     FROM parents p
     JOIN users u ON p.user_id = u.id
     WHERE u.is_active = 1
     ORDER BY u.first_name"
);

if ($action === 'edit' && $id) {
    $student = $db->getRow(
        "SELECT s.*, u.username, u.email, u.first_name, u.last_name, u.phone, u.id as user_id, u.is_active
         FROM students s
         JOIN users u ON s.user_id = u.id
         WHERE s.id = ?",
        [$id]
    );
}

// Get students list with pagination
$page = page_param('p');
$limit = 20;
$offset = ($page - 1) * $limit;

// Count total students (active only)
$totalStudents = $db->getRow(
    "SELECT COUNT(*) as count FROM students s
     JOIN users u ON s.user_id = u.id
     WHERE u.is_active = 1"
)['count'] ?? 0;

$totalPages = $totalStudents > 0 ? ceil($totalStudents / $limit) : 1;

$students = $db->getRows(
    "SELECT s.*, u.first_name, u.last_name, u.email, u.phone, u.is_active, u.profile_image,
            c.class_name, c.section,
            CONCAT(pu.first_name, ' ', pu.last_name) as parent_name
     FROM students s
     JOIN users u ON s.user_id = u.id
     LEFT JOIN classes c ON s.class_id = c.id
     LEFT JOIN parents p ON s.parent_id = p.id
     LEFT JOIN users pu ON p.user_id = pu.id
     WHERE u.is_active = 1
     ORDER BY u.first_name, u.last_name
     LIMIT ? OFFSET ?",
    [$limit, $offset]
);

?>

<div class="dashboard-container">
    <?php render_sidebar('admin'); ?>

    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>Student Management</h1>
            <div class="header-actions">
                <a href="?action=add" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add New Student
                </a>
                <a href="?action=promote" class="btn btn-accent">
                    <i class="fas fa-arrow-up"></i> Promote Students
                </a>
                <a href="export.php?type=students" class="btn btn-outline">
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
        <!-- Student Form -->
        <div class="card">
            <div class="card-header">
                <h3><?php echo $action === 'add' ? 'Add New Student' : 'Edit Student'; ?></h3>
            </div>
            <div class="card-body">
                <form method="POST" class="form-container" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="<?php echo e($action); ?>">
                    <?php if ($action === 'edit'): ?>
                    <input type="hidden" name="user_id" value="<?php echo $student['user_id'] ?? ''; ?>">
                    <?php endif; ?>

                    <div class="form-section">
                        <h3><i class="fas fa-user"></i> Personal Information</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="first_name">First Name *</label>
                                <input type="text" id="first_name" name="first_name" class="form-control"
                                       value="<?php echo htmlspecialchars($student['first_name'] ?? ''); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="last_name">Last Name *</label>
                                <input type="text" id="last_name" name="last_name" class="form-control"
                                       value="<?php echo htmlspecialchars($student['last_name'] ?? ''); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="date_of_birth">Date of Birth *</label>
                                <input type="date" id="date_of_birth" name="date_of_birth" class="form-control"
                                       value="<?php echo htmlspecialchars($student['date_of_birth'] ?? ''); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="gender">Gender *</label>
                                <select id="gender" name="gender" class="form-control" required>
                                    <option value="">Select Gender</option>
                                    <option value="male" <?php echo (isset($student['gender']) && $student['gender'] === 'male') ? 'selected' : ''; ?>>Male</option>
                                    <option value="female" <?php echo (isset($student['gender']) && $student['gender'] === 'female') ? 'selected' : ''; ?>>Female</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="blood_group">Blood Group</label>
                                <select id="blood_group" name="blood_group" class="form-control">
                                    <option value="">Select Blood Group</option>
                                    <?php
                                    $bloodGroups = ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'];
                                    foreach ($bloodGroups as $bg):
                                    ?>
                                    <option value="<?php echo e($bg); ?>" <?php echo (isset($student['blood_group']) && $student['blood_group'] === $bg) ? 'selected' : ''; ?>>
                                        <?php echo e($bg); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-address-card"></i> Contact Information</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="email">Email Address *</label>
                                <input type="email" id="email" name="email" class="form-control"
                                       value="<?php echo htmlspecialchars($student['email'] ?? ''); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="phone">Phone Number</label>
                                <input type="tel" id="phone" name="phone" class="form-control"
                                       value="<?php echo htmlspecialchars($student['phone'] ?? ''); ?>">
                            </div>

                            <div class="form-group full-width">
                                <label for="address">Home Address</label>
                                <textarea id="address" name="address" class="form-control" rows="3"><?php echo htmlspecialchars($student['address'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-graduation-cap"></i> Academic Information</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="admission_number">Admission Number *</label>
                                <input type="text" id="admission_number" name="admission_number" class="form-control"
                                       value="<?php echo htmlspecialchars($student['admission_number'] ?? (function_exists('generateAdmissionNumber') ? generateAdmissionNumber() : '')); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="admission_date">Admission Date *</label>
                                <input type="date" id="admission_date" name="admission_date" class="form-control"
                                       value="<?php echo htmlspecialchars($student['admission_date'] ?? date('Y-m-d')); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="class_id">Class</label>
                                <select id="class_id" name="class_id" class="form-control">
                                    <option value="">Select Class</option>
                                    <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo e($class['id']); ?>"
                                        <?php echo (isset($student['class_id']) && $student['class_id'] == $class['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name'] . ' ' . $class['section']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="parent_id">Parent/Guardian</label>
                                <select id="parent_id" name="parent_id" class="form-control">
                                    <option value="">Select Parent</option>
                                    <?php foreach ($parents as $parent): ?>
                                    <option value="<?php echo e($parent['id']); ?>"
                                        <?php echo (isset($student['parent_id']) && $student['parent_id'] == $parent['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($parent['first_name'] . ' ' . $parent['last_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group full-width">
                                <label for="medical_notes">Medical Notes</label>
                                <textarea id="medical_notes" name="medical_notes" class="form-control" rows="3"><?php echo htmlspecialchars($student['medical_notes'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-lock"></i> Login Information</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="username">Username *</label>
                                <input type="text" id="username" name="username" class="form-control"
                                       value="<?php echo htmlspecialchars($student['username'] ?? ''); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="password">Password <?php echo $action === 'add' ? '*' : ''; ?></label>
                                <input type="password" id="password" name="password" class="form-control"
                                       <?php echo $action === 'add' ? 'required' : ''; ?>>
                                <?php if ($action === 'edit'): ?>
                                <small class="form-text text-muted">Leave blank to keep current password</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> <?php echo $action === 'add' ? 'Add Student' : 'Update Student'; ?>
                        </button>
                        <a href="students.php" class="btn btn-outline">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <?php elseif ($action === 'promote'): ?>
        <!-- Promote Students Form -->
        <div class="card">
            <div class="card-header">
                <h3>Promote Students</h3>
            </div>
            <div class="card-body">
                <form method="POST" class="form-container">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="promote">

                    <div class="form-row">
                        <div class="form-group">
                            <label for="from_class">From Class *</label>
                            <select id="from_class" name="from_class" class="form-control" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo e($class['id']); ?>">
                                    <?php echo htmlspecialchars($class['class_name'] . ' ' . $class['section']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="to_class">To Class *</label>
                            <select id="to_class" name="to_class" class="form-control" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo e($class['id']); ?>">
                                    <?php echo htmlspecialchars($class['class_name'] . ' ' . $class['section']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="academic_year">Academic Year *</label>
                            <input type="text" id="academic_year" name="academic_year" class="form-control"
                                   value="<?php echo currentAcademicYear(); ?>"
                                   placeholder="YYYY-YYYY" required>
                            <small class="form-text text-muted">Format: 2024-2025</small>
                        </div>
                    </div>

                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Warning:</strong> This will promote all students from the selected class to the new class. This action cannot be undone.
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-arrow-up"></i> Promote Students
                        </button>
                        <a href="students.php" class="btn btn-outline">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>
        <!-- Students List -->
        <div class="card">
            <div class="card-header">
                <h3>All Students</h3>
                <div class="card-tools">
                    <input type="text" id="searchInput" class="form-control" placeholder="Search students..." style="width: 250px;">
                </div>
            </div>
            <div class="card-body">
                <?php if (!empty($students)): ?>
                <div class="table-responsive">
                    <table class="data-table" id="studentsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Photo</th>
                                <th>Name</th>
                                <th>Admission No.</th>
                                <th>Class</th>
                                <th>Parent</th>
                                <th>Contact</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student): ?>
                            <tr>
                                <td><?php echo e($student['id']); ?></td>
                                <td>
                                    <?php if (!empty($student['profile_image'])): ?>
                                    <img src="<?php echo BASE_URL; ?>/uploads/students/<?php echo e($student['profile_image']); ?>"
                                         alt="Profile" class="table-avatar">
                                    <?php else: ?>
                                    <div class="avatar-placeholder">
                                        <i class="fas fa-user-graduate"></i>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($student['admission_number']); ?></td>
                                <td><?php echo htmlspecialchars($student['class_name'] ?? '') . ' ' . htmlspecialchars($student['section'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($student['parent_name'] ?? 'Not Assigned'); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($student['email']); ?><br>
                                    <small><?php echo htmlspecialchars($student['phone'] ?? ''); ?></small>
                                </td>
                                <td>
                                    <?php if ($student['is_active']): ?>
                                    <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                    <span class="badge badge-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="?action=edit&id=<?php echo e($student['id']); ?>" class="btn-icon" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="view-student.php?id=<?php echo e($student['id']); ?>" class="btn-icon" title="View">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="generate-login.php?id=<?php echo e($student['id']); ?>" class="btn-icon" title="Generate Login">
                                            <i class="fas fa-key"></i>
                                        </a>
                                        <?php if ($student['is_active']): ?>
                                        <button type="button" class="btn-icon text-danger"
                                                onclick="confirmDeactivate(<?php echo e($student['id']); ?>, '<?php echo htmlspecialchars(addslashes($student['first_name'] . ' ' . $student['last_name'])); ?>')"
                                                title="Deactivate">
                                            <i class="fas fa-ban"></i>
                                        </button>
                                        <?php else: ?>
                                        <button type="button" class="btn-icon text-success"
                                                onclick="activateStudent(<?php echo e($student['id']); ?>)"
                                                title="Activate">
                                            <i class="fas fa-check-circle"></i>
                                        </button>
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
                    No students found. Click "Add New Student" to create one.
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </main>
</div>

<!-- Delete/Deactivate Confirmation Modal -->
<div id="deactivateModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Confirm Deactivate</h3>
            <button type="button" class="close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to deactivate <strong id="studentName"></strong>?</p>
            <p class="text-warning">The student will no longer be able to login.</p>
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
    background: var(--navy);
    color: white;
    border-color: var(--navy);
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
function confirmDeactivate(id, name) {
    document.getElementById('deactivateId').value = id;
    document.getElementById('studentName').textContent = name;
    document.getElementById('deactivateModal').style.display = 'block';
}

function activateStudent(id) {
    if (confirm('Are you sure you want to activate this student?')) {
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
    const table = document.getElementById('studentsTable');
    if (!table) return;

    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

    for (let row of rows) {
        const name = row.cells[2]?.textContent.toLowerCase() || '';
        const admission = row.cells[3]?.textContent.toLowerCase() || '';
        const email = row.cells[6]?.textContent.toLowerCase() || '';

        if (name.includes(searchTerm) || admission.includes(searchTerm) || email.includes(searchTerm)) {
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
</script>

