<?php
// admin/administrators.php - Manage Admin, System Administrator (Super Admin), Head Teacher & Supervisor Roles
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/SystemSchema.php';

Security::requireRole(['super_admin', 'admin']);
SystemSchema::ensureAll();

$db = db();
[$message, $messageType] = flash_get();
$isSuperAdmin = Security::hasExactRole(Security::ROLE_SUPER_ADMIN);
$currentUserId = (int)$_SESSION['user_id'];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flash_redirect('Invalid security token. Please try again.', 'error', 'administrators');
    }

    $action = $_POST['action'] ?? '';

    // ADD NEW ADMIN / SYSTEM ADMINISTRATOR
    if ($action === 'add') {
        $firstName = trim(Security::sanitize($_POST['first_name'] ?? ''));
        $lastName  = trim(Security::sanitize($_POST['last_name'] ?? ''));
        $email     = trim(strtolower(Security::sanitize($_POST['email'] ?? '')));
        $phone     = trim(Security::sanitize($_POST['phone'] ?? ''));
        $username  = trim(Security::sanitize($_POST['username'] ?? ''));
        $role      = trim($_POST['role'] ?? 'admin');
        $password  = trim($_POST['password'] ?? '');
        $isActive  = isset($_POST['is_active']) ? 1 : 0;
        $sendEmail = isset($_POST['send_email']);

        // Allowed admin roles (both super_admin and admin can provision administrative users)
        $allowedRoles = ['super_admin', 'admin', 'head_teacher', 'supervisor'];

        if (!in_array($role, $allowedRoles, true)) {
            flash_redirect('Please select a valid administrator role.', 'error', 'administrators');
        }

        if (empty($firstName) || empty($lastName) || empty($email)) {
            flash_redirect('Please fill in First Name, Last Name, and Email.', 'error', 'administrators');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_redirect('Please enter a valid email address.', 'error', 'administrators');
        }

        // Auto-generate username if not provided
        if (empty($username)) {
            $baseUser = strtolower(preg_replace('/[^a-z0-9]/i', '', $firstName . '.' . $lastName));
            $username = $baseUser ?: 'admin.' . mt_rand(100, 999);
        }

        // Check if username or email already exists
        $existing = $db->getRow("SELECT id FROM users WHERE (email = ? OR username = ?) AND deleted_at IS NULL", [$email, $username]);
        if ($existing) {
            flash_redirect('An account with that email or username already exists.', 'error', 'administrators');
        }

        // Auto-generate password if not provided
        if (empty($password)) {
            $password = 'Admin@' . mt_rand(1000, 9999);
        }

        try {
            $userId = $db->insert(
                "INSERT INTO users (username, email, password_hash, first_name, last_name, phone, role, is_active, must_change_password)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)",
                [
                    $username,
                    $email,
                    Security::hashPassword($password),
                    $firstName,
                    $lastName,
                    $phone,
                    $role,
                    $isActive
                ]
            );

            // Dispatch welcome & one-time password email if selected
            if ($sendEmail) {
                send_account_welcome_email([
                    'id'         => $userId,
                    'first_name' => $firstName,
                    'last_name'  => $lastName,
                    'email'      => $email,
                    'username'   => $username,
                    'role'       => $role
                ], $password, $role);
            }

            Security::logAudit('CREATED_ADMIN_ROLE_USER', 'users', $userId, null, [
                'role'       => $role,
                'email'      => $email,
                'created_by' => $currentUserId
            ]);

            $roleLabels = [
                'super_admin'  => 'System Administrator (Super Admin)',
                'admin'        => 'Administrator',
                'head_teacher' => 'Head Teacher',
                'supervisor'   => 'Academic Supervisor'
            ];
            $rTitle = $roleLabels[$role] ?? $role;

            flash_redirect("Successfully created {$rTitle} account for {$firstName} {$lastName}!" . ($sendEmail ? " Login credentials dispatched via email." : ""), 'success', 'administrators');

        } catch (Throwable $e) {
            error_log("Failed to create admin user: " . $e->getMessage());
            flash_redirect('Error creating administrative user: ' . $e->getMessage(), 'error', 'administrators');
        }
    }

    // EDIT ADMINISTRATOR
    if ($action === 'edit') {
        $editId    = (int)($_POST['user_id'] ?? 0);
        $firstName = trim(Security::sanitize($_POST['first_name'] ?? ''));
        $lastName  = trim(Security::sanitize($_POST['last_name'] ?? ''));
        $email     = trim(strtolower(Security::sanitize($_POST['email'] ?? '')));
        $phone     = trim(Security::sanitize($_POST['phone'] ?? ''));
        $username  = trim(Security::sanitize($_POST['username'] ?? ''));
        $role      = trim($_POST['role'] ?? 'admin');
        $isActive  = isset($_POST['is_active']) ? 1 : 0;
        $newPass   = trim($_POST['password'] ?? '');

        $target = $db->getRow("SELECT id, role, email FROM users WHERE id = ? AND deleted_at IS NULL", [$editId]);
        if (!$target) {
            flash_redirect('Administrative user not found.', 'error', 'administrators');
        }

        $allowedRoles = ['super_admin', 'admin', 'head_teacher', 'supervisor'];
        if (!in_array($role, $allowedRoles, true)) {
            flash_redirect('Please select a valid administrator role.', 'error', 'administrators');
        }

        // Check duplicate email / username
        $dup = $db->getRow("SELECT id FROM users WHERE (email = ? OR username = ?) AND id <> ? AND deleted_at IS NULL", [$email, $username, $editId]);
        if ($dup) {
            flash_redirect('Another user already has that email or username.', 'error', 'administrators');
        }

        try {
            $db->query(
                "UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ?, username = ?, role = ?, is_active = ? WHERE id = ?",
                [$firstName, $lastName, $email, $phone, $username, $role, $isActive, $editId]
            );

            if (!empty($newPass)) {
                $db->query("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?", [Security::hashPassword($newPass), $editId]);
                if (isset($_POST['send_email'])) {
                    send_account_welcome_email([
                        'id'         => $editId,
                        'first_name' => $firstName,
                        'last_name'  => $lastName,
                        'email'      => $email,
                        'username'   => $username,
                        'role'       => $role
                    ], $newPass, $role);
                }
            }

            Security::logAudit('UPDATED_ADMIN_USER', 'users', $editId, null, ['role' => $role, 'email' => $email]);
            flash_redirect('Administrator updated successfully.', 'success', 'administrators');

        } catch (Throwable $e) {
            error_log("Failed to update admin user: " . $e->getMessage());
            flash_redirect('Error updating administrative user: ' . $e->getMessage(), 'error', 'administrators');
        }
    }

    // RESET PASSWORD & DISPATCH EMAIL
    if ($action === 'reset_password') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $target = $db->getRow("SELECT id, first_name, last_name, email, username, role FROM users WHERE id = ? AND deleted_at IS NULL", [$targetId]);

        if (!$target) {
            flash_redirect('User not found.', 'error', 'administrators');
        }

        if ($target['role'] === 'super_admin' && !$isSuperAdmin) {
            flash_redirect('Only a System Administrator can reset another System Administrator password.', 'error', 'administrators');
        }

        $tempPassword = 'Admin#' . mt_rand(1000, 9999);
        $db->query("UPDATE users SET password_hash = ?, must_change_password = 1, login_attempts = 0, locked_until = NULL WHERE id = ?", [Security::hashPassword($tempPassword), $targetId]);

        send_account_welcome_email($target, $tempPassword, $target['role']);
        Security::logAudit('RESET_ADMIN_PASSWORD', 'users', $targetId);

        flash_redirect("New temporary password (<strong>{$tempPassword}</strong>) generated and emailed to {$target['email']}.", 'success', 'administrators');
    }

    // TOGGLE ACTIVE STATUS
    if ($action === 'toggle_status') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        if ($targetId === $currentUserId) {
            flash_redirect('You cannot deactivate your own account.', 'error', 'administrators');
        }

        $target = $db->getRow("SELECT id, is_active, role FROM users WHERE id = ? AND deleted_at IS NULL", [$targetId]);
        if ($target) {
            if ($target['role'] === 'super_admin' && !$isSuperAdmin) {
                flash_redirect('Only a System Administrator can deactivate a System Administrator.', 'error', 'administrators');
            }

            $newStatus = $target['is_active'] ? 0 : 1;
            $db->query("UPDATE users SET is_active = ? WHERE id = ?", [$newStatus, $targetId]);
            Security::logAudit('TOGGLED_ADMIN_STATUS', 'users', $targetId, null, ['is_active' => $newStatus]);
            flash_redirect('Account status updated successfully.', 'success', 'administrators');
        }
    }

    // DELETE / DEACTIVATE USER
    if ($action === 'delete') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        if ($targetId === $currentUserId) {
            flash_redirect('You cannot delete your own account.', 'error', 'administrators');
        }

        $target = $db->getRow("SELECT id, role FROM users WHERE id = ? AND deleted_at IS NULL", [$targetId]);
        if ($target) {
            if ($target['role'] === 'super_admin' && !$isSuperAdmin) {
                flash_redirect('Only a System Administrator can delete another System Administrator.', 'error', 'administrators');
            }

            // Ensure we never delete the last super_admin
            if ($target['role'] === 'super_admin') {
                $countSuperAdmins = (int)$db->getRow("SELECT COUNT(*) c FROM users WHERE role = 'super_admin' AND deleted_at IS NULL")['c'];
                if ($countSuperAdmins <= 1) {
                    flash_redirect('Cannot delete the last remaining System Administrator account.', 'error', 'administrators');
                }
            }

            $db->query("UPDATE users SET deleted_at = NOW(), is_active = 0 WHERE id = ?", [$targetId]);
            Security::logAudit('DELETED_ADMIN_USER', 'users', $targetId);
            flash_redirect('Administrator account removed successfully.', 'success', 'administrators');
        }
    }
}

// Fetch all administrative users
$admins = $db->getRows(
    "SELECT id, username, email, first_name, last_name, phone, role, is_active, last_login, created_at
     FROM users
     WHERE role IN ('super_admin', 'admin', 'head_teacher', 'supervisor')
       AND deleted_at IS NULL
     ORDER BY CASE role
         WHEN 'super_admin' THEN 1
         WHEN 'admin' THEN 2
         WHEN 'head_teacher' THEN 3
         WHEN 'supervisor' THEN 4
         ELSE 5 END, id ASC"
);

$pageTitle = 'Admin & System Administrator Roles';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';

$addBtnHtml = '<button type="button" class="btn btn-primary" id="btnCreateAdminHeader" onclick="openAddModal()"><i class="fas fa-user-plus"></i> Create New Administrator</button>';
dashboard_open('admin', 'Admin & System Roles', $addBtnHtml);
render_alert($message, $messageType);
?>

<div style="max-width: 1100px; margin: 0 auto;">
    <!-- Metric Cards for Administrative Roles -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div class="card" style="margin: 0; padding: 20px; border-radius: 10px; border: 1px solid #e2e8f0; background: #ffffff;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">System Administrators</div>
                    <div style="font-size: 24px; font-weight: 800; color: #002855; margin-top: 4px;">
                        <?php echo count(array_filter($admins, function($a) { return $a['role'] === 'super_admin'; })); ?>
                    </div>
                    <div style="font-size: 12px; color: #b45309; margin-top: 4px;">
                        <i class="fas fa-crown"></i> Full System Authority
                    </div>
                </div>
                <div style="background: #fef3c7; color: #d97706; width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                    <i class="fas fa-user-shield"></i>
                </div>
            </div>
        </div>

        <div class="card" style="margin: 0; padding: 20px; border-radius: 10px; border: 1px solid #e2e8f0; background: #ffffff;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Administrators</div>
                    <div style="font-size: 24px; font-weight: 800; color: #002855; margin-top: 4px;">
                        <?php echo count(array_filter($admins, function($a) { return $a['role'] === 'admin'; })); ?>
                    </div>
                    <div style="font-size: 12px; color: #0284c7; margin-top: 4px;">
                        <i class="fas fa-shield-alt"></i> School Operations
                    </div>
                </div>
                <div style="background: #e0f2fe; color: #0284c7; width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                    <i class="fas fa-users-cog"></i>
                </div>
            </div>
        </div>

        <div class="card" style="margin: 0; padding: 20px; border-radius: 10px; border: 1px solid #e2e8f0; background: #ffffff;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Head Teachers & Leads</div>
                    <div style="font-size: 24px; font-weight: 800; color: #002855; margin-top: 4px;">
                        <?php echo count(array_filter($admins, function($a) { return in_array($a['role'], ['head_teacher', 'supervisor'], true); })); ?>
                    </div>
                    <div style="font-size: 12px; color: #7c3aed; margin-top: 4px;">
                        <i class="fas fa-graduation-cap"></i> Academic Leadership
                    </div>
                </div>
                <div style="background: #ede9fe; color: #7c3aed; width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Administrative Roster Table -->
    <div class="card" style="border-radius: 10px; border: 1px solid #e2e8f0;">
        <div class="card-header" style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <h3 style="margin: 0; font-size: 17px; color: #002855;">
                <i class="fas fa-user-shield" style="margin-right: 8px; color: #c41e3a;"></i> Active Administrative Users & Permissions
            </h3>
            <button type="button" class="btn btn-sm btn-primary" id="btnCreateAdminTable" onclick="openAddModal()">
                <i class="fas fa-plus"></i> Add Admin / System Administrator
            </button>
        </div>
        <div class="card-body" style="padding: 0;">
            <div style="overflow-x: auto;">
                <table class="table" style="width: 100%; border-collapse: collapse; margin: 0; font-size: 14px;">
                    <thead>
                        <tr style="background: #f1f5f9; text-align: left; border-bottom: 1px solid #e2e8f0;">
                            <th style="padding: 12px 18px; color: #475569;">Name</th>
                            <th style="padding: 12px 18px; color: #475569;">Role</th>
                            <th style="padding: 12px 18px; color: #475569;">Email & Username</th>
                            <th style="padding: 12px 18px; color: #475569;">Phone</th>
                            <th style="padding: 12px 18px; color: #475569;">Status</th>
                            <th style="padding: 12px 18px; color: #475569;">Last Login</th>
                            <th style="padding: 12px 18px; color: #475569; text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($admins)): ?>
                            <tr><td colspan="7" style="padding: 24px; text-align: center; color: #64748b;">No administrative users found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($admins as $u): ?>
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 12px 18px; font-weight: 600; color: #002855;">
                                        <?php echo e($u['first_name'] . ' ' . $u['last_name']); ?>
                                        <?php if ((int)$u['id'] === $currentUserId): ?>
                                            <span style="font-size: 11px; background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 4px; margin-left: 6px;">You</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 12px 18px;">
                                        <?php if ($u['role'] === 'super_admin'): ?>
                                            <span style="display: inline-flex; align-items: center; gap: 4px; background: #fef3c7; color: #92400e; padding: 4px 10px; border-radius: 6px; font-weight: 700; font-size: 12px;">
                                                <i class="fas fa-crown"></i> System Administrator
                                            </span>
                                        <?php elseif ($u['role'] === 'admin'): ?>
                                            <span style="display: inline-flex; align-items: center; gap: 4px; background: #e0f2fe; color: #075985; padding: 4px 10px; border-radius: 6px; font-weight: 700; font-size: 12px;">
                                                <i class="fas fa-shield-alt"></i> Administrator
                                            </span>
                                        <?php elseif ($u['role'] === 'head_teacher'): ?>
                                            <span style="display: inline-flex; align-items: center; gap: 4px; background: #ede9fe; color: #5b21b6; padding: 4px 10px; border-radius: 6px; font-weight: 700; font-size: 12px;">
                                                <i class="fas fa-graduation-cap"></i> Head Teacher
                                            </span>
                                        <?php else: ?>
                                            <span style="display: inline-flex; align-items: center; gap: 4px; background: #ccfbf1; color: #115e59; padding: 4px 10px; border-radius: 6px; font-weight: 700; font-size: 12px;">
                                                <i class="fas fa-search"></i> Supervisor
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 12px 18px;">
                                        <div style="font-weight: 500; color: #1e293b;"><?php echo e($u['email']); ?></div>
                                        <div style="font-size: 12px; color: #64748b;">User: <code><?php echo e($u['username']); ?></code></div>
                                    </td>
                                    <td style="padding: 12px 18px; color: #475569;">
                                        <?php echo e($u['phone'] ?: 'N/A'); ?>
                                    </td>
                                    <td style="padding: 12px 18px;">
                                        <?php if ($u['is_active']): ?>
                                            <span style="background: #dcfce7; color: #15803d; padding: 3px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">Active</span>
                                        <?php else: ?>
                                            <span style="background: #fee2e2; color: #b91c1c; padding: 3px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 12px 18px; font-size: 12px; color: #64748b;">
                                        <?php echo $u['last_login'] ? date('M j, Y g:ia', strtotime($u['last_login'])) : 'Never'; ?>
                                    </td>
                                    <td style="padding: 12px 18px; text-align: right; white-space: nowrap;">
                                        <!-- Edit button -->
                                        <button type="button" class="btn btn-sm btn-outline btn-edit-admin" style="padding: 4px 8px; font-size: 12px; margin-right: 4px;"
                                                data-admin='<?php echo htmlspecialchars(json_encode($u), ENT_QUOTES, 'UTF-8'); ?>'
                                                onclick='openEditModal(<?php echo json_encode($u, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                                            <i class="fas fa-edit"></i> Edit
                                        </button>

                                        <!-- Password reset & email button -->
                                        <form method="POST" style="display: inline; margin: 0;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="reset_password">
                                            <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline" style="padding: 4px 8px; font-size: 12px; margin-right: 4px;"
                                                    onclick="return confirm('Generate and email a new temporary password to <?php echo e($u['email']); ?>?')">
                                                <i class="fas fa-key"></i> Reset PW
                                            </button>
                                        </form>

                                        <?php if ((int)$u['id'] !== $currentUserId): ?>
                                            <!-- Toggle status -->
                                            <form method="POST" style="display: inline; margin: 0;">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline" style="padding: 4px 8px; font-size: 12px; margin-right: 4px;"
                                                        title="<?php echo $u['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                                    <i class="fas <?php echo $u['is_active'] ? 'fa-ban text-warning' : 'fa-check text-success'; ?>"></i>
                                                </button>
                                            </form>

                                            <!-- Delete user -->
                                            <form method="POST" style="display: inline; margin: 0;" onsubmit="return confirm('Are you sure you want to remove this administrator account?');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline text-danger" style="padding: 4px 8px; font-size: 12px;">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Create / Edit Administrator -->
<div id="adminModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle" style="display: none;">
    <div class="modal-dialog" style="background: #ffffff; border-radius: 14px; max-width: 580px; width: 100%; box-shadow: 0 25px 60px -12px rgba(0, 20, 50, 0.45); overflow: hidden; margin: auto;">
        <div class="modal-header" style="background: #002855; color: #ffffff; padding: 18px 24px; display: flex; justify-content: space-between; align-items: center; border-bottom: none;">
            <h3 id="modalTitle" style="margin: 0; font-size: 18px; color: #ffffff; font-weight: 700;">Create Administrator</h3>
            <button type="button" class="modal-close" onclick="closeAdminModal()" aria-label="Close modal" style="background: transparent; border: none; color: #ffffff; font-size: 24px; cursor: pointer; padding: 4px 8px; line-height: 1;">&times;</button>
        </div>
        <form method="POST" id="adminForm" style="padding: 24px;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="user_id" id="formUserId" value="">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                <div>
                    <label style="display: block; font-weight: 600; font-size: 13px; color: #334155; margin-bottom: 5px;">First Name *</label>
                    <input type="text" name="first_name" id="formFirstName" class="form-control" required style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-weight: 600; font-size: 13px; color: #334155; margin-bottom: 5px;">Last Name *</label>
                    <input type="text" name="last_name" id="formLastName" class="form-control" required style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 6px;">
                </div>
            </div>

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-weight: 600; font-size: 13px; color: #334155; margin-bottom: 5px;">Role Type *</label>
                <select name="role" id="formRole" class="form-control" required style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-weight: 600;">
                    <option value="super_admin" style="font-weight: 700; color: #d97706;">⭐ System Administrator (Full System Authority)</option>
                    <option value="admin" style="font-weight: 700; color: #0284c7;" selected>🛡️ Admin (School Operations & Management)</option>
                    <option value="head_teacher" style="color: #7c3aed;">🎓 Head Teacher (Academic Lead & Approval Authority)</option>
                    <option value="supervisor" style="color: #0d9488;">🔍 Academic Supervisor (Curriculum & Supervision)</option>
                </select>
                <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                    Both <strong>System Administrator</strong> and <strong>Admin</strong> have access to all backend portals. System Administrator additionally manages server-level controls.
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                <div>
                    <label style="display: block; font-weight: 600; font-size: 13px; color: #334155; margin-bottom: 5px;">Email Address *</label>
                    <input type="email" name="email" id="formEmail" class="form-control" required placeholder="admin@domain.com" style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-weight: 600; font-size: 13px; color: #334155; margin-bottom: 5px;">Phone Number</label>
                    <input type="text" name="phone" id="formPhone" class="form-control" placeholder="08012345678" style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 6px;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                <div>
                    <label style="display: block; font-weight: 600; font-size: 13px; color: #334155; margin-bottom: 5px;">Username</label>
                    <input type="text" name="username" id="formUsername" class="form-control" placeholder="Leave empty to auto-generate" style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-weight: 600; font-size: 13px; color: #334155; margin-bottom: 5px;">
                        <span id="passLabel">One-Time Password</span>
                    </label>
                    <input type="text" name="password" id="formPassword" class="form-control" placeholder="Leave empty for auto-generated" style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 6px;">
                </div>
            </div>

            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; margin-bottom: 18px;">
                <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: #1e293b; margin: 0; cursor: pointer;">
                    <input type="checkbox" name="send_email" id="formSendEmail" value="1" checked>
                    <span><strong>Send credentials email to phone inbox</strong> (includes login URL and one-time password)</span>
                </label>
                <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: #1e293b; margin-top: 8px; cursor: pointer;">
                    <input type="checkbox" name="is_active" id="formIsActive" value="1" checked>
                    <span>Account is active (can log in immediately)</span>
                </label>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeAdminModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <i class="fas fa-save"></i> Save Administrator
                </button>
            </div>
        </form>
    </div>
</div>

<style>
#adminModal {
    display: none;
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    background: rgba(10, 25, 47, 0.72) !important;
    backdrop-filter: blur(6px) !important;
    -webkit-backdrop-filter: blur(6px) !important;
    z-index: 99999 !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 20px !important;
    box-sizing: border-box !important;
}
#adminModal.active,
#adminModal.show {
    display: flex !important;
}
</style>

<script nonce="<?php echo CSP_NONCE; ?>">
function openAddModal() {
    var modal = document.getElementById('adminModal');
    if (!modal) return;
    document.getElementById('modalTitle').textContent = 'Create Admin or System Administrator';
    document.getElementById('formAction').value = 'add';
    document.getElementById('formUserId').value = '';
    document.getElementById('formFirstName').value = '';
    document.getElementById('formLastName').value = '';
    document.getElementById('formEmail').value = '';
    document.getElementById('formPhone').value = '';
    document.getElementById('formUsername').value = '';
    document.getElementById('formPassword').value = '';
    document.getElementById('passLabel').textContent = 'One-Time Password (Leave empty to auto-generate)';
    document.getElementById('formRole').value = 'admin';
    document.getElementById('formIsActive').checked = true;
    document.getElementById('formSendEmail').checked = true;
    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-user-plus"></i> Create Administrator';
    modal.classList.add('active', 'show');
    modal.style.setProperty('display', 'flex', 'important');
}

function openEditModal(user) {
    var modal = document.getElementById('adminModal');
    if (!modal) return;
    if (typeof user === 'string') {
        try { user = JSON.parse(user); } catch (e) { console.error(e); return; }
    }
    document.getElementById('modalTitle').textContent = 'Edit Administrator (' + (user.first_name || '') + ' ' + (user.last_name || '') + ')';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('formUserId').value = user.id || '';
    document.getElementById('formFirstName').value = user.first_name || '';
    document.getElementById('formLastName').value = user.last_name || '';
    document.getElementById('formEmail').value = user.email || '';
    document.getElementById('formPhone').value = user.phone || '';
    document.getElementById('formUsername').value = user.username || '';
    document.getElementById('formPassword').value = '';
    document.getElementById('passLabel').textContent = 'New Password (leave blank to keep current)';
    document.getElementById('formRole').value = user.role || 'admin';
    document.getElementById('formIsActive').checked = parseInt(user.is_active, 10) === 1;
    document.getElementById('formSendEmail').checked = false;
    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save"></i> Save Changes';
    modal.classList.add('active', 'show');
    modal.style.setProperty('display', 'flex', 'important');
}

function closeAdminModal() {
    var modal = document.getElementById('adminModal');
    if (!modal) return;
    modal.classList.remove('active', 'show');
    modal.style.setProperty('display', 'none', 'important');
}

window.openAddModal = openAddModal;
window.openEditModal = openEditModal;
window.closeAdminModal = closeAdminModal;

document.addEventListener('DOMContentLoaded', function() {
    var b1 = document.getElementById('btnCreateAdminHeader');
    if (b1) {
        b1.addEventListener('click', function(e) {
            e.preventDefault();
            openAddModal();
        });
    }
    var b2 = document.getElementById('btnCreateAdminTable');
    if (b2) {
        b2.addEventListener('click', function(e) {
            e.preventDefault();
            openAddModal();
        });
    }

    var modal = document.getElementById('adminModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeAdminModal();
            }
        });
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAdminModal();
        }
    });

    document.querySelectorAll('.btn-edit-admin').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var data = this.getAttribute('data-admin');
            if (data) {
                try {
                    openEditModal(JSON.parse(data));
                } catch (err) {
                    console.error('Error parsing admin data', err);
                }
            }
        });
    });
});
</script>

<?php
dashboard_close();
include __DIR__ . '/../includes/footer.php';
