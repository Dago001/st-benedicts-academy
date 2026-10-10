<?php
// admin/maintenance.php - Super Admin Maintenance Mode Control Panel
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
Security::requireRole('admin');

$db = db();
[$message, $messageType] = flash_get();

// Preview mode for admin
if (isset($_GET['preview'])) {
    require_once ROOT_PATH . '/includes/maintenance_page.php';
    exit;
}

$settings = maintenance_get_settings();

// Process POST updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flash_redirect('Invalid security token. Please refresh and try again.', 'error', 'maintenance');
    }

    $action = $_POST['action'] ?? 'save_settings';
    $userId = (int)($_SESSION['user_id'] ?? 0);

    if ($action === 'toggle_quick') {
        $newStatus = ($settings['enabled'] === '1') ? '0' : '1';
        $db->query(
            "INSERT INTO site_content (content_key, content_value, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE content_value = VALUES(content_value), updated_by = VALUES(updated_by)",
            ['maintenance.enabled', $newStatus, $userId]
        );
        Security::logAudit(
            $newStatus === '1' ? 'MAINTENANCE_MODE_ENABLED' : 'MAINTENANCE_MODE_DISABLED',
            'site_content',
            0,
            ['enabled' => $settings['enabled']],
            ['enabled' => $newStatus]
        );
        flash_redirect(
            $newStatus === '1' ? 'Maintenance mode is now ACTIVE. The frontend is displaying the maintenance screen to public visitors.' : 'Maintenance mode has been DISABLED. The website is now live to all visitors.',
            $newStatus === '1' ? 'warning' : 'success',
            'maintenance'
        );
    }

    // Full settings save
    $enabled = isset($_POST['enabled']) ? '1' : '0';
    $title = trim(Security::sanitize($_POST['title'] ?? ''));
    $msg = trim(str_replace("\0", '', (string)($_POST['message'] ?? '')));
    $until = trim(Security::sanitize($_POST['until'] ?? ''));
    $showContact = isset($_POST['show_contact']) ? '1' : '0';
    $whitelist = trim(Security::sanitize($_POST['whitelist'] ?? ''));

    if (mb_strlen($title) > 200) flash_redirect('Headline cannot exceed 200 characters', 'error', 'maintenance');
    if (mb_strlen($msg) > 3000) flash_redirect('Message cannot exceed 3000 characters', 'error', 'maintenance');

    $entries = [
        'maintenance.enabled'      => $enabled,
        'maintenance.title'        => $title,
        'maintenance.message'      => $msg,
        'maintenance.until'        => $until,
        'maintenance.show_contact' => $showContact,
        'maintenance.whitelist'    => $whitelist,
    ];

    foreach ($entries as $key => $val) {
        $db->query(
            "INSERT INTO site_content (content_key, content_value, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE content_value = VALUES(content_value), updated_by = VALUES(updated_by)",
            [$key, (string)$val, $userId]
        );
    }

    Security::logAudit('MAINTENANCE_SETTINGS_UPDATED', 'site_content', 0, null, $entries);

    flash_redirect(
        $enabled === '1' ? 'Settings saved. Maintenance Mode is currently ACTIVE on the frontend.' : 'Settings saved. Maintenance Mode is currently OFF.',
        'success',
        'maintenance'
    );
}

// Refresh settings after possible updates
$settings = maintenance_get_settings();
$isLive = ($settings['enabled'] !== '1');

$pageTitle = 'Maintenance Mode';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';

dashboard_open(
    'admin',
    'Maintenance Mode',
    '<a class="btn btn-outline" href="maintenance?preview=1" target="_blank" rel="noopener"><i class="fas fa-eye"></i> Preview Maintenance Page</a>'
);

render_alert($message, $messageType);
?>

<div class="maintenance-control-grid" style="display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1.2fr); gap: 24px; align-items: start;">

    <!-- Left Column: Primary Controls & Settings -->
    <div>
        <!-- Quick Status Card -->
        <div class="card" style="margin-bottom: 24px; border-top: 4px solid <?php echo $isLive ? '#28a745' : '#dc3545'; ?>;">
            <div class="card-body" style="padding: 24px;">
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                    <div>
                        <div style="display: inline-flex; align-items: center; gap: 8px; font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; padding: 4px 12px; border-radius: 999px; background: <?php echo $isLive ? '#e8f5e9' : '#ffebee'; ?>; color: <?php echo $isLive ? '#2e7d32' : '#c62828'; ?>; margin-bottom: 8px;">
                            <span style="width: 8px; height: 8px; border-radius: 50%; background: <?php echo $isLive ? '#28a745' : '#dc3545'; ?>;"></span>
                            <?php echo $isLive ? 'Website is Online (Normal)' : 'Maintenance Mode is Active'; ?>
                        </div>
                        <h2 style="font-size: 1.4rem; margin: 4px 0 6px; color: var(--navy, #002855);">
                            <?php echo $isLive ? 'The public website is fully accessible' : 'Public frontend is temporarily locked'; ?>
                        </h2>
                        <p class="text-muted" style="margin: 0; font-size: 0.92rem;">
                            <?php echo $isLive ? 'Visitors, parents, and students can view all public pages normally.' : 'Public visitors receive the 503 maintenance notice. As a Super Admin, you can still view all pages.'; ?>
                        </p>
                    </div>

                    <form method="POST" action="maintenance" style="margin: 0;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="toggle_quick">
                        <?php if ($isLive): ?>
                            <button type="submit" class="btn btn-danger" style="padding: 12px 24px; font-weight: 700; display: inline-flex; align-items: center; gap: 8px;" onclick="return confirm('Activate Maintenance Mode? Public visitors will immediately see the maintenance screen.');">
                                <i class="fas fa-power-off"></i> Turn ON Maintenance Mode
                            </button>
                        <?php else: ?>
                            <button type="submit" class="btn btn-success" style="padding: 12px 24px; font-weight: 700; display: inline-flex; align-items: center; gap: 8px;" onclick="return confirm('Deactivate Maintenance Mode? The public website will be restored for all visitors.');">
                                <i class="fas fa-check-circle"></i> Turn OFF Maintenance Mode
                            </button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>

        <!-- Customization Form Card -->
        <div class="card">
            <div class="card-header" style="display: flex; align-items: center; justify-content: space-between;">
                <h3 style="margin: 0; font-size: 1.1rem; color: var(--navy, #002855);">
                    <i class="fas fa-sliders-h" style="color: var(--gold-dark, #e6c200); margin-right: 8px;"></i>
                    Maintenance Screen Configuration
                </h3>
            </div>
            <div class="card-body" style="padding: 24px;">
                <form method="POST" action="maintenance">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save_settings">

                    <!-- Maintenance Switch -->
                    <div class="form-group" style="padding-bottom: 20px; border-bottom: 1px solid var(--line, #e5e9f0); margin-bottom: 20px;">
                        <label style="display: flex; align-items: center; gap: 12px; cursor: pointer;">
                            <input type="checkbox" name="enabled" value="1" <?php echo !$isLive ? 'checked' : ''; ?> style="width: 20px; height: 20px; accent-color: #dc3545;">
                            <div>
                                <strong style="font-size: 1rem; color: var(--navy, #002855);">Enable Maintenance Mode</strong>
                                <p class="text-muted" style="margin: 2px 0 0; font-size: 0.85rem;">When checked, public visitors are redirected to the maintenance page.</p>
                            </div>
                        </label>
                    </div>

                    <!-- Custom Title -->
                    <div class="form-group" style="margin-bottom: 18px;">
                        <label for="title" style="font-weight: 600; color: var(--navy, #002855); margin-bottom: 6px; display: block;">
                            Headline Title
                        </label>
                        <input type="text" id="title" name="title" class="form-control"
                               value="<?php echo e(!empty($settings['title']) ? $settings['title'] : 'Scheduled Maintenance in Progress'); ?>"
                               placeholder="e.g. Scheduled Maintenance in Progress" required>
                        <small class="text-muted">Displayed as the main title on the public maintenance screen.</small>
                    </div>

                    <!-- Custom Message -->
                    <div class="form-group" style="margin-bottom: 18px;">
                        <label for="message" style="font-weight: 600; color: var(--navy, #002855); margin-bottom: 6px; display: block;">
                            Notice / Explanation Message
                        </label>
                        <textarea id="message" name="message" class="form-control" rows="4"
                                  placeholder="Explain why the site is down and when it will return..."><?php echo e($settings['message'] ?? ''); ?></textarea>
                        <small class="text-muted">Provide helpful information for parents and visitors while the site is offline.</small>
                    </div>

                    <!-- Estimated Return Time -->
                    <div class="form-group" style="margin-bottom: 18px;">
                        <label for="until" style="font-weight: 600; color: var(--navy, #002855); margin-bottom: 6px; display: block;">
                            Expected Completion Time (Optional)
                        </label>
                        <input type="text" id="until" name="until" class="form-control"
                               value="<?php echo e($settings['until'] ?? ''); ?>"
                               placeholder="e.g. Today at 6:00 PM, or Saturday, Oct 11, 2026">
                        <small class="text-muted">Leave empty if you do not wish to display an estimated completion time.</small>
                    </div>

                    <!-- Emergency Contacts Toggle -->
                    <div class="form-group" style="margin-bottom: 18px;">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" name="show_contact" value="1" <?php echo ($settings['show_contact'] ?? '1') === '1' ? 'checked' : ''; ?> style="width: 18px; height: 18px; accent-color: var(--navy, #002855);">
                            <div>
                                <strong style="font-size: 0.92rem; color: var(--navy, #002855);">Show Emergency Contact Details</strong>
                                <p class="text-muted" style="margin: 0; font-size: 0.82rem;">Displays quick telephone and email buttons on the maintenance page so parents can still reach the school.</p>
                            </div>
                        </label>
                    </div>

                    <!-- IP Whitelist -->
                    <div class="form-group" style="margin-bottom: 24px;">
                        <label for="whitelist" style="font-weight: 600; color: var(--navy, #002855); margin-bottom: 6px; display: block;">
                            IP Address Whitelist (Optional)
                        </label>
                        <input type="text" id="whitelist" name="whitelist" class="form-control"
                               value="<?php echo e($settings['whitelist'] ?? ''); ?>"
                               placeholder="e.g. 197.210.55.12, 102.89.34.88">
                        <small class="text-muted">Comma-separated list of IP addresses that can browse normally even when maintenance is active. Your current IP is: <code><?php echo e($_SERVER['REMOTE_ADDR'] ?? 'Unknown'); ?></code></small>
                    </div>

                    <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                        <button type="submit" class="btn btn-primary" style="padding: 12px 28px; font-weight: 700;">
                            <i class="fas fa-save" style="margin-right: 6px;"></i> Save Maintenance Settings
                        </button>
                        <a href="maintenance?preview=1" target="_blank" class="btn btn-outline" style="padding: 12px 20px;">
                            <i class="fas fa-external-link-alt" style="margin-right: 6px;"></i> Preview Frontend Page
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Column: Information & Guidelines -->
    <div>
        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header">
                <h3 style="margin: 0; font-size: 1.05rem; color: var(--navy, #002855);">
                    <i class="fas fa-info-circle" style="color: var(--navy, #002855); margin-right: 8px;"></i>
                    How Maintenance Mode Works
                </h3>
            </div>
            <div class="card-body" style="padding: 20px; font-size: 0.92rem; line-height: 1.6; color: var(--ink-soft, #4b5563);">
                <ul style="padding-left: 20px; margin: 0 0 16px;">
                    <li style="margin-bottom: 10px;">
                        <strong>Super Admin Immunity:</strong> As long as you are logged in as Admin, you can browse all public pages and the portal normally to test changes.
                    </li>
                    <li style="margin-bottom: 10px;">
                        <strong>SEO &amp; Google Protection:</strong> Returns standard <code>HTTP 503 Service Unavailable</code> headers, instructing Google and search engines to check back later without dropping your search rankings.
                    </li>
                    <li style="margin-bottom: 10px;">
                        <strong>Staff Login Remains Accessible:</strong> The <code>/login</code> portal remains open so you or other teachers and administrators can sign in at any time.
                    </li>
                    <li>
                        <strong>Yellow Warning Banner:</strong> While active, a sticky yellow banner will appear at the top of every page reminding you that visitors see the maintenance screen.
                    </li>
                </ul>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 style="margin: 0; font-size: 1.05rem; color: var(--navy, #002855);">
                    <i class="fas fa-shield-alt" style="color: var(--gold-dark, #e6c200); margin-right: 8px;"></i>
                    Live Preview Shortcut
                </h3>
            </div>
            <div class="card-body" style="padding: 20px; font-size: 0.9rem;">
                <p style="margin-bottom: 14px; color: var(--ink-soft, #4b5563);">
                    Want to see exactly what public visitors see without turning on maintenance mode for everyone?
                </p>
                <a href="maintenance?preview=1" target="_blank" class="btn btn-outline" style="width: 100%; justify-content: center; display: inline-flex; align-items: center; gap: 8px;">
                    <i class="fas fa-desktop"></i> Open Live Preview in New Tab
                </a>
            </div>
        </div>
    </div>
</div>

<?php
dashboard_close();
include __DIR__ . '/../includes/footer.php';
