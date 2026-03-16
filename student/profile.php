<?php
// student/profile.php - Student Profile
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/security.php';

Security::requireRole('student');

$pageTitle = 'My Profile';
$extraJS = ['profile.js'];

include '../includes/header.php';

$db = db();
$userId = $_SESSION['user_id'];
$message = '';
$messageType = '';

// Get student profile
$student = $db->getRow(
    "SELECT s.*, u.username, u.email, u.first_name, u.last_name, u.phone, u.profile_image,
            c.class_name, c.section,
            CONCAT(pu.first_name, ' ', pu.last_name) as parent_name,
            pu.email as parent_email, pu.phone as parent_phone
     FROM students s
     JOIN users u ON s.user_id = u.id
     LEFT JOIN classes c ON s.class_id = c.id
     LEFT JOIN parents p ON s.parent_id = p.id
     LEFT JOIN users pu ON p.user_id = pu.id
     WHERE s.user_id = ?",
    [$userId]
);

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'update_profile':
                $data = [
                    'phone' => Security::sanitize($_POST['phone']),
                    'address' => Security::sanitize($_POST['address'])
                ];
                
                try {
                    $db->query(
                        "UPDATE users SET phone = ? WHERE id = ?",
                        [$data['phone'], $userId]
                    );
                    
                    $db->query(
                        "UPDATE students SET address = ? WHERE user_id = ?",
                        [$data['address'], $userId]
                    );
                    
                    Security::logAudit('UPDATED_PROFILE', 'students', $student['id']);
                    
                    $message = 'Profile updated successfully';
                    $messageType = 'success';
                    
                    // Refresh student data
                    $student = $db->getRow(
                        "SELECT s.*, u.username, u.email, u.first_name, u.last_name, u.phone, u.profile_image,
                                c.class_name, c.section,
                                CONCAT(pu.first_name, ' ', pu.last_name) as parent_name,
                                pu.email as parent_email, pu.phone as parent_phone
                         FROM students s
                         JOIN users u ON s.user_id = u.id
                         LEFT JOIN classes c ON s.class_id = c.id
                         LEFT JOIN parents p ON s.parent_id = p.id
                         LEFT JOIN users pu ON p.user_id = pu.id
                         WHERE s.user_id = ?",
                        [$userId]
                    );
                    
                } catch (Exception $e) {
                    $message = 'Error: ' . $e->getMessage();
                    $messageType = 'error';
                }
                break;
                
            case 'change_password':
                $currentPassword = $_POST['current_password'];
                $newPassword = $_POST['new_password'];
                $confirmPassword = $_POST['confirm_password'];
                
                // Validate
                $user = $db->getRow("SELECT password_hash FROM users WHERE id = ?", [$userId]);
                
                if (!Security::verifyPassword($currentPassword, $user['password_hash'])) {
                    $message = 'Current password is incorrect';
                    $messageType = 'error';
                } elseif ($newPassword !== $confirmPassword) {
                    $message = 'New passwords do not match';
                    $messageType = 'error';
                } elseif (strlen($newPassword) < 8) {
                    $message = 'Password must be at least 8 characters';
                    $messageType = 'error';
                } else {
                    $newHash = Security::hashPassword($newPassword);
                    
                    $db->query(
                        "UPDATE users SET password_hash = ? WHERE id = ?",
                        [$newHash, $userId]
                    );
                    
                    Security::logAudit('PASSWORD_CHANGE', 'users', $userId);
                    
                    $message = 'Password changed successfully';
                    $messageType = 'success';
                }
                break;
                
            case 'upload_photo':
                if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                    $upload = Validator::image($_FILES['profile_photo']);
                    
                    if ($upload['valid']) {
                        $extension = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
                        $filename = 'student_' . $student['id'] . '_' . time() . '.' . $extension;
                        $uploadPath = UPLOAD_PATH . 'students/' . $filename;
                        
                        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $uploadPath)) {
                            // Delete old photo if exists
                            if ($student['profile_image'] && file_exists(UPLOAD_PATH . 'students/' . $student['profile_image'])) {
                                unlink(UPLOAD_PATH . 'students/' . $student['profile_image']);
                            }
                            
                            $db->query(
                                "UPDATE users SET profile_image = ? WHERE id = ?",
                                [$filename, $userId]
                            );
                            
                            Security::logAudit('UPDATED_PROFILE_PHOTO', 'users', $userId);
                            
                            $message = 'Profile photo updated successfully';
                            $messageType = 'success';
                            
                            // Refresh student data
                            $student['profile_image'] = $filename;
                        }
                    } else {
                        $message = implode(', ', $upload['errors']);
                        $messageType = 'error';
                    }
                }
                break;
        }
    }
}
?>

<div class="dashboard-container">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <h3>Student Panel</h3>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="results.php"><i class="fas fa-chart-line"></i> My Results</a></li>
                <li><a href="attendance.php"><i class="fas fa-calendar-check"></i> Attendance</a></li>
                <li><a href="assignments.php"><i class="fas fa-tasks"></i> Assignments</a></li>
                <li><a href="fees.php"><i class="fas fa-money-bill"></i> Fees</a></li>
                <li><a href="messages.php"><i class="fas fa-envelope"></i> Messages</a></li>
                <li class="active"><a href="profile.php"><i class="fas fa-user-cog"></i> Profile</a></li>
            </ul>
        </nav>
    </aside>
    
    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>My Profile</h1>
        </div>
        
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible">
            <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
            <?php echo $message; ?>
            <button type="button" class="close" onclick="this.parentElement.remove()">&times;</button>
        </div>
        <?php endif; ?>
        
        <div class="profile-grid">
            <!-- Profile Card -->
            <div class="profile-card">
                <div class="profile-header">
                    <div class="profile-avatar">
                        <?php if ($student['profile_image']): ?>
                        <img src="<?php echo BASE_URL; ?>/uploads/students/<?php echo $student['profile_image']; ?>" 
                             alt="Profile Photo" id="profilePhoto">
                        <?php else: ?>
                        <div class="avatar-placeholder">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <?php endif; ?>
                        
                        <button class="btn-edit-photo" onclick="document.getElementById('photoInput').click()">
                            <i class="fas fa-camera"></i>
                        </button>
                        
                        <form method="POST" enctype="multipart/form-data" id="photoForm" style="display: none;">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                            <input type="hidden" name="action" value="upload_photo">
                            <input type="file" id="photoInput" name="profile_photo" accept="image/*" onchange="this.form.submit()">
                        </form>
                    </div>
                    
                    <h2><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h2>
                    <p class="student-class"><?php echo htmlspecialchars($student['class_name'] . ' ' . $student['section']); ?></p>
                    <p class="student-id">Admission No: <?php echo htmlspecialchars($student['admission_number']); ?></p>
                </div>
                
                <div class="profile-stats">
                    <div class="stat-item">
                        <span class="stat-value"><?php echo $student['age'] ?? '--'; ?></span>
                        <span class="stat-label">Age</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-value"><?php echo ucfirst($student['gender']); ?></span>
                        <span class="stat-label">Gender</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-value"><?php echo $student['blood_group'] ?? '--'; ?></span>
                        <span class="stat-label">Blood Group</span>
                    </div>
                </div>
                
                <div class="profile-info">
                    <div class="info-row">
                        <i class="fas fa-envelope"></i>
                        <span><?php echo htmlspecialchars($student['email']); ?></span>
                    </div>
                    <div class="info-row">
                        <i class="fas fa-phone"></i>
                        <span><?php echo htmlspecialchars($student['phone'] ?? 'Not provided'); ?></span>
                    </div>
                    <div class="info-row">
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?php echo htmlspecialchars($student['address'] ?? 'Not provided'); ?></span>
                    </div>
                    <div class="info-row">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Admitted: <?php echo date('M d, Y', strtotime($student['admission_date'])); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Edit Profile Form -->
            <div class="profile-edit-card">
                <div class="card-header">
                    <h3>Edit Profile</h3>
                </div>
                <div class="card-body">
                    <form method="POST" class="profile-form">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="form-group">
                            <label for="first_name">First Name</label>
                            <input type="text" id="first_name" class="form-control" 
                                   value="<?php echo htmlspecialchars($student['first_name']); ?>" readonly disabled>
                            <small class="form-text">Contact admin to change name</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="last_name">Last Name</label>
                            <input type="text" id="last_name" class="form-control" 
                                   value="<?php echo htmlspecialchars($student['last_name']); ?>" readonly disabled>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" class="form-control" 
                                   value="<?php echo htmlspecialchars($student['email']); ?>" readonly disabled>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <input type="tel" id="phone" name="phone" class="form-control" 
                                   value="<?php echo htmlspecialchars($student['phone']); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="address">Address</label>
                            <textarea id="address" name="address" class="form-control" rows="3"><?php echo htmlspecialchars($student['address']); ?></textarea>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update Profile
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Change Password -->
            <div class="profile-password-card">
                <div class="card-header">
                    <h3>Change Password</h3>
                </div>
                <div class="card-body">
                    <form method="POST" class="password-form" onsubmit="return validatePassword()">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="form-group">
                            <label for="current_password">Current Password</label>
                            <input type="password" id="current_password" name="current_password" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_password">New Password</label>
                            <input type="password" id="new_password" name="new_password" class="form-control" required>
                            <div class="password-strength">
                                <div class="strength-bar" id="strengthBar"></div>
                            </div>
                            <ul class="password-requirements">
                                <li id="req-length">At least 8 characters</li>
                                <li id="                                <li id="req-uppercase">At least one uppercase letter</li>
                                <li id="req-lowercase">At least one lowercase letter</li>
                                <li id="req-number">At least one number</li>
                                <li id="req-special">At least one special character</li>
                            </ul>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                            <div id="passwordMatch" class="password-match"></div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="changePasswordBtn">
                                <i class="fas fa-key"></i> Change Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Parent Information -->
            <div class="profile-parent-card">
                <div class="card-header">
                    <h3>Parent/Guardian Information</h3>
                </div>
                <div class="card-body">
                    <div class="parent-info">
                        <div class="info-row">
                            <i class="fas fa-user"></i>
                            <span><?php echo htmlspecialchars($student['parent_name'] ?? 'Not assigned'); ?></span>
                        </div>
                        <div class="info-row">
                            <i class="fas fa-envelope"></i>
                            <span><?php echo htmlspecialchars($student['parent_email'] ?? 'Not provided'); ?></span>
                        </div>
                        <div class="info-row">
                            <i class="fas fa-phone"></i>
                            <span><?php echo htmlspecialchars($student['parent_phone'] ?? 'Not provided'); ?></span>
                        </div>
                    </div>
                    
                    <div class="emergency-contact">
                        <h4>Emergency Contact</h4>
                        <p>In case of emergency, please contact the school office at:</p>
                        <p class="emergency-phone"><i class="fas fa-phone-alt"></i> <?php echo SCHOOL_PHONE; ?></p>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<style>
.profile-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 25px;
}

.profile-card {
    grid-column: span 2;
    background: var(--white);
    border-radius: var(--radius-lg);
    padding: 30px;
    box-shadow: var(--shadow-md);
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 30px;
    align-items: start;
}

.profile-header {
    text-align: center;
}

.profile-avatar {
    position: relative;
    width: 150px;
    margin: 0 auto 20px;
}

.profile-avatar img {
    width: 150px;
    height: 150px;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid var(--gold);
    box-shadow: var(--shadow-md);
}

.avatar-placeholder {
    width: 150px;
    height: 150px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--navy), var(--navy-dark));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 4rem;
    color: var(--gold);
    border: 4px solid var(--gold);
}

.btn-edit-photo {
    position: absolute;
    bottom: 10px;
    right: 10px;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--gold);
    border: none;
    color: var(--navy);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    transition: all 0.3s ease;
    box-shadow: var(--shadow-md);
}

.btn-edit-photo:hover {
    transform: scale(1.1);
    background: var(--navy);
    color: var(--gold);
}

.profile-header h2 {
    color: var(--navy);
    margin: 10px 0 5px;
}

.student-class {
    color: var(--red);
    font-weight: 500;
    margin-bottom: 5px;
}

.student-id {
    color: var(--gray);
    font-size: 0.9rem;
}

.profile-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
    margin-bottom: 20px;
}

.stat-item {
    text-align: center;
    padding: 15px;
    background: var(--light-gray);
    border-radius: var(--radius-md);
}

.stat-value {
    display: block;
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--navy);
    line-height: 1.2;
}

.stat-label {
    font-size: 0.8rem;
    color: var(--gray);
    text-transform: uppercase;
}

.profile-info {
    background: var(--light-gray);
    border-radius: var(--radius-md);
    padding: 20px;
}

.info-row {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 10px 0;
    border-bottom: 1px solid rgba(0,0,0,0.05);
}

.info-row:last-child {
    border-bottom: none;
}

.info-row i {
    width: 20px;
    color: var(--gold);
    font-size: 1.1rem;
}

.info-row span {
    color: var(--dark-gray);
}

.profile-edit-card,
.profile-password-card,
.profile-parent-card {
    background: var(--white);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-md);
    overflow: hidden;
}

.card-header {
    padding: 20px;
    background: var(--navy);
    color: var(--white);
}

.card-header h3 {
    margin: 0;
    color: var(--white);
    font-size: 1.2rem;
}

.card-header h3 i {
    margin-right: 10px;
    color: var(--gold);
}

.card-body {
    padding: 25px;
}

.password-strength {
    height: 5px;
    background: var(--light-gray);
    border-radius: 5px;
    margin: 10px 0;
    overflow: hidden;
}

.strength-bar {
    height: 100%;
    width: 0;
    transition: width 0.3s ease, background 0.3s ease;
}

.strength-bar.weak { background: var(--danger); width: 25%; }
.strength-bar.medium { background: var(--warning); width: 50%; }
.strength-bar.good { background: var(--info); width: 75%; }
.strength-bar.strong { background: var(--success); width: 100%; }

.password-requirements {
    list-style: none;
    padding: 0;
    margin: 10px 0;
    font-size: 0.85rem;
}

.password-requirements li {
    padding: 3px 0;
    color: var(--gray);
}

.password-requirements li.valid {
    color: var(--success);
}

.password-requirements li.valid:before {
    content: '✓';
    margin-right: 5px;
}

.password-requirements li.invalid:before {
    content: '✗';
    margin-right: 5px;
    color: var(--danger);
}

.password-match {
    margin-top: 5px;
    font-size: 0.85rem;
}

.password-match.match {
    color: var(--success);
}

.password-match.no-match {
    color: var(--danger);
}

.parent-info {
    margin-bottom: 20px;
}

.emergency-contact {
    background: var(--light-gray);
    border-radius: var(--radius-md);
    padding: 15px;
    text-align: center;
}

.emergency-contact h4 {
    color: var(--navy);
    margin-bottom: 10px;
}

.emergency-phone {
    font-size: 1.2rem;
    font-weight: 600;
    color: var(--red);
}

.emergency-phone i {
    color: var(--gold);
    margin-right: 5px;
}

@media (max-width: 992px) {
    .profile-card {
        grid-template-columns: 1fr;
    }
    
    .profile-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .profile-card {
        padding: 20px;
    }
    
    .profile-stats {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
// Password strength checker
document.getElementById('new_password')?.addEventListener('input', function() {
    const password = this.value;
    const strengthBar = document.getElementById('strengthBar');
    
    // Check requirements
    const hasLength = password.length >= 8;
    const hasUppercase = /[A-Z]/.test(password);
    const hasLowercase = /[a-z]/.test(password);
    const hasNumber = /[0-9]/.test(password);
    const hasSpecial = /[!@#$%^&*(),.?":{}|<>]/.test(password);
    
    // Update requirement indicators
    document.getElementById('req-length').className = hasLength ? 'valid' : 'invalid';
    document.getElementById('req-uppercase').className = hasUppercase ? 'valid' : 'invalid';
    document.getElementById('req-lowercase').className = hasLowercase ? 'valid' : 'invalid';
    document.getElementById('req-number').className = hasNumber ? 'valid' : 'invalid';
    document.getElementById('req-special').className = hasSpecial ? 'valid' : 'invalid';
    
    // Calculate strength
    let strength = 0;
    if (hasLength) strength++;
    if (hasUppercase) strength++;
    if (hasLowercase) strength++;
    if (hasNumber) strength++;
    if (hasSpecial) strength++;
    
    // Update strength bar
    strengthBar.className = '';
    if (strength <= 2) {
        strengthBar.classList.add('weak');
    } else if (strength === 3) {
        strengthBar.classList.add('medium');
    } else if (strength === 4) {
        strengthBar.classList.add('good');
    } else if (strength === 5) {
        strengthBar.classList.add('strong');
    }
});

// Password match checker
document.getElementById('confirm_password')?.addEventListener('input', function() {
    const password = document.getElementById('new_password').value;
    const confirm = this.value;
    const matchDiv = document.getElementById('passwordMatch');
    
    if (confirm === '') {
        matchDiv.textContent = '';
        matchDiv.className = 'password-match';
    } else if (password === confirm) {
        matchDiv.textContent = '✓ Passwords match';
        matchDiv.className = 'password-match match';
    } else {
        matchDiv.textContent = '✗ Passwords do not match';
        matchDiv.className = 'password-match no-match';
    }
});

// Form validation
function validatePassword() {
    const password = document.getElementById('new_password').value;
    const confirm = document.getElementById('confirm_password').value;
    
    if (password !== confirm) {
        alert('Passwords do not match!');
        return false;
    }
    
    if (password.length < 8) {
        alert('Password must be at least 8 characters long!');
        return false;
    }
    
    return true;
}

// Auto-hide alerts after 5 seconds
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(alert => {
        alert.style.transition = 'opacity 0.5s ease';
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 500);
    });
}, 5000);
</script>
