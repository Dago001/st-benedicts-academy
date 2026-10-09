<?php
// includes/profile_page.php - own profile (name, phone) and password change for teacher / parent

function render_profile_page($role) {
    $db = db();
    $uid = (int)$_SESSION['user_id'];
    $message = '';
    $messageType = '';
    [$flashMsg, $flashType] = flash_get();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $message = 'Invalid security token';
            $messageType = 'error';
        } elseif (($_POST['action'] ?? '') === 'profile') {
            $first = Security::sanitize($_POST['first_name'] ?? '');
            $last = Security::sanitize($_POST['last_name'] ?? '');
            $phone = Security::sanitize($_POST['phone'] ?? '');
            $address = Security::sanitize($_POST['address'] ?? '');
            if ($first === '' || $last === '' || mb_strlen($first) > 50 || mb_strlen($last) > 50) {
                $message = 'First and last name are required (max 50 characters)';
                $messageType = 'error';
            } elseif ($phone !== '' && !Security::validatePhone($phone)) {
                $message = 'Please enter a valid Nigerian phone number';
                $messageType = 'error';
            } else {
                $db->query('UPDATE users SET first_name = ?, last_name = ?, phone = ? WHERE id = ?', [$first, $last, $phone, $uid]);
                $table = $role === 'teacher' ? 'teachers' : 'parents';
                $db->query("UPDATE $table SET address = ? WHERE user_id = ?", [$address, $uid]);
                $_SESSION['user_name'] = $first . ' ' . $last;
                Security::logAudit('UPDATED_OWN_PROFILE', 'users', $uid);
                flash_redirect('Profile updated', 'success');
            }
        } elseif (($_POST['action'] ?? '') === 'password') {
            $new = (string)($_POST['new_password'] ?? '');
            if ($new !== (string)($_POST['confirm_password'] ?? '')) {
                $result = ['success' => false, 'message' => 'Passwords do not match'];
            } elseif ($err = strong_password($new)) {
                $result = ['success' => false, 'message' => $err];
            } else {
                $result = (new Auth())->changePassword($uid, $_POST['current_password'] ?? '', $new);
            }
            if ($result['success']) {
                session_regenerate_id(true);
                flash_redirect('Password changed successfully', 'success');
            }
            $message = $result['message'];
            $messageType = 'error';
        }
    }

    $table = $role === 'teacher' ? 'teachers' : 'parents';
    $me = $db->getRow("SELECT u.username, u.email, u.first_name, u.last_name, u.phone, x.address, u.last_login FROM users u LEFT JOIN $table x ON x.user_id = u.id WHERE u.id = ?", [$uid]);

    dashboard_open($role, 'My Profile');
    render_alert($message ?: $flashMsg, $message ? $messageType : $flashType);
    ?>
    <div class="card"><div class="card-header"><h3>Personal details</h3></div><div class="card-body">
        <form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="action" value="profile">
            <div class="form-row">
                <div class="form-group"><label for="first_name">First name *</label><input class="form-control" id="first_name" name="first_name" required maxlength="50" value="<?php echo e($me['first_name']); ?>"></div>
                <div class="form-group"><label for="last_name">Last name *</label><input class="form-control" id="last_name" name="last_name" required maxlength="50" value="<?php echo e($me['last_name']); ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Email (contact the office to change)</label><input class="form-control" value="<?php echo e($me['email']); ?>" disabled></div>
                <div class="form-group"><label for="phone">Phone</label><input class="form-control" id="phone" name="phone" type="tel" inputmode="tel" placeholder="08012345678" value="<?php echo e($me['phone']); ?>"></div>
            </div>
            <div class="form-group"><label for="address">Address</label><textarea class="form-control" id="address" name="address" rows="2"><?php echo e($me['address']); ?></textarea></div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save changes</button>
        </form>
    </div></div>
    <div class="card"><div class="card-header"><h3>Change password</h3></div><div class="card-body">
        <form method="POST" autocomplete="off"><?php echo csrf_field(); ?><input type="hidden" name="action" value="password">
            <div class="form-group"><label for="current_password">Current password</label><input class="form-control" type="password" id="current_password" name="current_password" required autocomplete="current-password"></div>
            <div class="form-row">
                <div class="form-group"><label for="new_password">New password</label><input class="form-control" type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password"></div>
                <div class="form-group"><label for="confirm_password">Confirm new password</label><input class="form-control" type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password"></div>
            </div>
            <small class="form-text text-muted">At least 8 characters with letters and numbers.</small><br>
            <button type="submit" class="btn btn-primary"><i class="fas fa-key"></i> Change password</button>
        </form>
    </div></div>
    <p class="text-muted">Last login: <?php echo e($me['last_login'] ? formatDate($me['last_login'], 'd M Y, h:i A') : 'first login'); ?></p>
    <?php
    dashboard_close();
}
