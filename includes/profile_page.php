<?php
// includes/profile_page.php - own profile (name, phone) and password change for teacher / parent

require_once __DIR__ . '/totp.php';

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
                if (in_array($role, ['teacher', 'parent'], true)) {
                    $table = $role === 'teacher' ? 'teachers' : 'parents';
                    $db->query("UPDATE $table SET address = ? WHERE user_id = ?", [$address, $uid]);
                }
                $_SESSION['user_name'] = $first . ' ' . $last;
                Security::logAudit('UPDATED_OWN_PROFILE', 'users', $uid);
                flash_redirect('Profile updated', 'success');
            }
        } elseif (($_POST['action'] ?? '') === '2fa_start') {
            $_SESSION['totp_setup'] = Totp::generateSecret();
            flash_redirect('Scan the QR/secret below with your authenticator app, then enter the code to finish.', 'success');
        } elseif (($_POST['action'] ?? '') === '2fa_enable') {
            $secret = $_SESSION['totp_setup'] ?? '';
            if ($secret && Totp::verify($secret, $_POST['code'] ?? '')) {
                $db->query('UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?', [$secret, $uid]);
                unset($_SESSION['totp_setup']);
                Security::logAudit('2FA_ENABLED', 'users', $uid);
                flash_redirect('Two-step verification is now on', 'success');
            }
            $message = 'That code is not correct. Try again.';
            $messageType = 'error';
        } elseif (($_POST['action'] ?? '') === '2fa_disable') {
            $row = $db->getRow('SELECT password_hash FROM users WHERE id = ?', [$uid]);
            if ($row && Security::verifyPassword((string)($_POST['current_password'] ?? ''), $row['password_hash'])) {
                $db->query('UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?', [$uid]);
                unset($_SESSION['totp_setup']);
                Security::logAudit('2FA_DISABLED', 'users', $uid);
                flash_redirect('Two-step verification turned off', 'success');
            }
            $message = 'Password is incorrect';
            $messageType = 'error';
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

    $table = ['teacher' => 'teachers', 'parent' => 'parents'][$role] ?? null;
    $addr = $table ? "x.address" : "NULL";
    $join = $table ? "LEFT JOIN $table x ON x.user_id = u.id" : '';
    $me = $db->getRow("SELECT u.username, u.email, u.first_name, u.last_name, u.phone, $addr AS address, u.last_login, u.totp_enabled FROM users u $join WHERE u.id = ?", [$uid]);

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
            <?php if ($table): ?><div class="form-group"><label for="address">Address</label><textarea class="form-control" id="address" name="address" rows="2"><?php echo e($me['address']); ?></textarea></div><?php endif; ?>
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
    <div class="card"><div class="card-header"><h3>Two-step verification</h3></div><div class="card-body">
    <?php if (!empty($me['totp_enabled'])): ?>
        <p><span class="badge badge-success">On</span> Your account asks for an authenticator code at sign-in.</p>
        <form method="POST" autocomplete="off"><?php echo csrf_field(); ?><input type="hidden" name="action" value="2fa_disable">
            <div class="form-group"><label for="d2_pw">Confirm your password to turn it off</label><input class="form-control" type="password" id="d2_pw" name="current_password" required autocomplete="current-password"></div>
            <button type="submit" class="btn btn-danger"><i class="fas fa-shield-alt"></i> Turn off</button>
        </form>
    <?php elseif (!empty($_SESSION['totp_setup'])): $sec = $_SESSION['totp_setup']; ?>
        <p>1. In Google Authenticator, Microsoft Authenticator or Authy choose <strong>Add account &rarr; Enter a setup key</strong>.</p>
        <p>Key: <code style="font-size:1.1rem;letter-spacing:2px;word-break:break-all"><?php echo e(trim(chunk_split($sec, 4, ' '))); ?></code><br>
        <small class="text-muted">Account: <?php echo e($me['email']); ?> &middot; Time-based &middot; 6 digits</small></p>
        <p>2. Enter the 6-digit code it shows:</p>
        <form method="POST" autocomplete="off"><?php echo csrf_field(); ?><input type="hidden" name="action" value="2fa_enable">
            <div class="form-group"><input class="form-control" name="code" inputmode="numeric" maxlength="7" placeholder="123456" required style="max-width:200px"></div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Turn on</button>
        </form>
    <?php else: ?>
        <p>Add a second step at sign-in using an authenticator app. Recommended for administrators and teachers.</p>
        <form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="action" value="2fa_start">
            <button type="submit" class="btn btn-primary"><i class="fas fa-shield-alt"></i> Set up</button>
        </form>
    <?php endif; ?>
    </div></div>
    <p class="text-muted">Last login: <?php echo e($me['last_login'] ? formatDate($me['last_login'], 'd M Y, h:i A') : 'first login'); ?></p>
    <?php
    dashboard_close();
}
