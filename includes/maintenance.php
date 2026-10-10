<?php
// includes/maintenance.php - System maintenance mode handling and access control

/**
 * Fetch maintenance mode configuration from site_content.
 */
function maintenance_get_settings() {
    static $settings = null;
    if ($settings !== null) return $settings;

    $defaults = [
        'enabled'      => '0',
        'title'        => 'Scheduled Maintenance in Progress',
        'message'      => "We are currently carrying out scheduled system maintenance and upgrades to improve our services.\nOur website will be back online shortly. We apologize for any inconvenience and appreciate your patience!",
        'until'        => '',
        'show_contact' => '1',
        'whitelist'    => '',
        'updated_at'   => null,
        'updated_by'   => null,
    ];

    try {
        $db = Database::getInstance();
        $rows = $db->getRows("SELECT content_key, content_value, updated_at, updated_by FROM site_content WHERE content_key LIKE 'maintenance.%'");
        foreach ($rows as $r) {
            $key = substr($r['content_key'], 12);
            $defaults[$key] = (string)$r['content_value'];
            if (!empty($r['updated_at'])) $defaults['updated_at'] = $r['updated_at'];
            if (!empty($r['updated_by'])) $defaults['updated_by'] = $r['updated_by'];
        }
    } catch (Throwable $e) {
        // Graceful fallback if database is not reachable
    }

    $settings = $defaults;
    return $settings;
}

/**
 * Check if maintenance mode is enabled globally.
 */
function is_maintenance_mode() {
    $s = maintenance_get_settings();
    return ($s['enabled'] ?? '0') === '1';
}

/**
 * Check if the current request should be served the 503 maintenance page.
 */
function should_show_maintenance_page() {
    if (PHP_SAPI === 'cli') return false;
    if (!is_maintenance_mode()) return false;

    // Logged-in admin / super admin bypasses maintenance mode
    if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
        return false;
    }

    // IP whitelist check
    $s = maintenance_get_settings();
    if (!empty($s['whitelist'])) {
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $allowedIps = array_filter(array_map('trim', explode(',', $s['whitelist'])));
        if (in_array($clientIp, $allowedIps, true)) {
            return false;
        }
    }

    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');

    // Always allow admin routes and login/auth flows
    if (strpos($uri, '/admin') === 0 || strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false) {
        return false;
    }

    // Auth endpoints must stay accessible so admins can sign in
    if (in_array($script, ['login', 'logout', 'health', 'forgot-password', 'reset-password'], true)) {
        return false;
    }
    if (preg_match('#^/(login|logout|health|forgot-password|reset-password)#', $uri)) {
        return false;
    }

    // Static assets
    if (preg_match('#^/assets/#', $uri)) {
        return false;
    }

    return true;
}

/**
 * Enforce maintenance mode for public requests.
 */
function enforce_maintenance_mode() {
    if (should_show_maintenance_page()) {
        http_response_code(503);
        header('Retry-After: 3600');
        require __DIR__ . '/maintenance_page.php';
        exit;
    }
}

/**
 * Render sticky warning banner for logged-in admin when maintenance mode is active.
 */
function maintenance_admin_banner() {
    if (!is_maintenance_mode()) return;
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') return;
    ?>
    <div class="maintenance-alert-banner" style="background: linear-gradient(90deg, #ffc107 0%, #ff9800 100%); color: #001a3a; padding: 10px 20px; font-size: 0.92rem; font-weight: 600; text-align: center; position: sticky; top: 0; z-index: 99999; box-shadow: 0 2px 10px rgba(0,0,0,0.15); display: flex; align-items: center; justify-content: center; flex-wrap: wrap; gap: 12px;">
        <span><i class="fas fa-exclamation-triangle" style="margin-right: 6px;"></i> <strong>Maintenance Mode is Active:</strong> Public visitors cannot view the website. You are currently viewing as Super Admin.</span>
        <a href="<?php echo BASE_URL; ?>/admin/maintenance" style="display: inline-block; padding: 4px 12px; background: #002855; color: #fff; border-radius: 6px; text-decoration: none; font-size: 0.82rem; font-weight: 700; transition: background 0.2s ease;">Manage / Turn Off</a>
    </div>
    <?php
}
