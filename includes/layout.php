<?php
// includes/layout.php - shared dashboard layout pieces (sidebar, alerts, page chrome)

if (!function_exists('nav_items')) {
    /** Navigation entries per role: [file, label, icon]. */
    function nav_items($role) {
        $nav = [
            'admin' => [
                ['dashboard', 'Dashboard', 'fa-home'],
                ['students', 'Students', 'fa-user-graduate'],
                ['parents', 'Parents', 'fa-users'],
                ['teachers', 'Teachers', 'fa-chalkboard-teacher'],
                ['classes', 'Classes', 'fa-school'],
                ['subjects', 'Subjects', 'fa-book'],
                ['attendance', 'Attendance', 'fa-calendar-check'],
                ['fees', 'Fees', 'fa-money-bill'],
                ['results', 'Results', 'fa-chart-line'],
                ['applications', 'Applications', 'fa-file-signature'],
                ['announcements', 'Announcements', 'fa-bullhorn'],
                ['news', 'News & Events', 'fa-newspaper'],
                ['gallery', 'Gallery', 'fa-images'],
                ['chatbot', 'Chatbot', 'fa-robot'],
                ['reports', 'Reports', 'fa-file-alt'],
                ['audit-logs', 'Audit Logs', 'fa-history'],
            ],
            'teacher' => [
                ['dashboard', 'Dashboard', 'fa-home'],
                ['classes', 'My Classes', 'fa-school'],
                ['attendance', 'Attendance', 'fa-calendar-check'],
                ['results', 'Results', 'fa-chart-line'],
                ['assignments', 'Assignments', 'fa-tasks'],
                ['students', 'Students', 'fa-user-graduate'],
                ['messages', 'Messages', 'fa-envelope'],
                ['profile', 'Profile', 'fa-user-cog'],
            ],
            'student' => [
                ['dashboard', 'Dashboard', 'fa-home'],
                ['results', 'My Results', 'fa-chart-line'],
                ['attendance', 'Attendance', 'fa-calendar-check'],
                ['assignments', 'Assignments', 'fa-tasks'],
                ['timetable', 'Timetable', 'fa-clock'],
                ['fees', 'Fees', 'fa-money-bill'],
                ['messages', 'Messages', 'fa-envelope'],
                ['profile', 'Profile', 'fa-user-cog'],
            ],
            'parent' => [
                ['dashboard', 'Dashboard', 'fa-home'],
                ['children', 'My Children', 'fa-child'],
                ['child-performance', 'Child Performance', 'fa-chart-line'],
                ['fees', 'Fee Status', 'fa-money-bill'],
                ['schedule', 'Timetable', 'fa-clock'],
                ['messages', 'Messages', 'fa-envelope'],
                ['profile', 'Profile', 'fa-user-cog'],
            ],
        ];
        return $nav[$role] ?? [];
    }
}

if (!function_exists('unread_message_count')) {
    function unread_message_count() {
        if (!Security::isLoggedIn()) return 0;
        try {
            return (int)db()->getRow('SELECT COUNT(*) c FROM messages WHERE receiver_id = ? AND is_read = 0', [$_SESSION['user_id']])['c'];
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('render_sidebar')) {
    /** Outputs the role sidebar (with mobile toggle + overlay). */
    function render_sidebar($role = null) {
        $role = $role ?: ($_SESSION['user_role'] ?? '');
        $titles = ['admin' => 'Admin Panel', 'teacher' => 'Teacher Panel', 'student' => 'Student Panel', 'parent' => 'Parent Portal'];
        $current = basename($_SERVER['SCRIPT_NAME'], '.php');
        // Pages that belong to a nav entry although their file name differs
        $alias = [
            'mark-attendance' => 'attendance', 'view-student' => 'students', 'teacher-profile' => 'teachers',
            'view-parent' => 'parents', 'student-fees' => 'fees', 'print-receipt' => 'fees',
            'view-receipt' => 'fees', 'attendance-report' => 'attendance', 'submissions' => 'assignments',
            'timetable' => 'classes', 'assign-subjects' => 'teachers', 'attendance-detail' => 'attendance',
            'generate-login' => 'students',
        ];
        $active = $alias[$current] ?? $current;
        $unread = unread_message_count();
        ?>
    <button type="button" class="sidebar-toggle-mobile" id="sidebarToggle" aria-label="Open menu" aria-expanded="false" aria-controls="appSidebar">
        <i class="fas fa-bars"></i><span>Menu</span>
    </button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <aside class="sidebar" id="appSidebar">
        <div class="sidebar-header">
            <h3><?php echo e($titles[$role] ?? 'Menu'); ?></h3>
            <button type="button" class="sidebar-close" id="sidebarClose" aria-label="Close menu"><i class="fas fa-times"></i></button>
        </div>
        <nav class="sidebar-nav" aria-label="<?php echo e($titles[$role] ?? 'Menu'); ?>">
            <ul>
                <?php foreach (nav_items($role) as [$file, $label, $icon]): ?>
                <li class="<?php echo $active === $file ? 'active' : ''; ?>">
                    <a href="<?php echo e(BASE_URL . '/' . $role . '/' . $file); ?>"<?php echo $active === $file ? ' aria-current="page"' : ''; ?>>
                        <i class="fas <?php echo e($icon); ?>"></i> <?php echo e($label); ?>
                        <?php if ($file === 'messages' && $unread > 0): ?><span class="badge badge-danger"><?php echo $unread; ?></span><?php endif; ?>
                    </a>
                </li>
                <?php endforeach; ?>
                <li><a href="<?php echo e(BASE_URL); ?>/logout"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </nav>
    </aside>
        <?php
    }
}

if (!function_exists('render_alert')) {
    function render_alert($message, $type = 'info') {
        if ($message === '' || $message === null) return;
        $type = in_array($type, ['success', 'error', 'warning', 'info', 'danger'], true) ? $type : 'info';
        $icons = ['success' => 'fa-check-circle', 'error' => 'fa-exclamation-circle', 'danger' => 'fa-exclamation-circle', 'warning' => 'fa-exclamation-triangle', 'info' => 'fa-info-circle'];
        echo '<div class="alert alert-' . e($type) . ' alert-dismissible" role="alert"><i class="fas ' . $icons[$type] . '"></i> '
            . e($message) . '<button type="button" class="close" aria-label="Dismiss" onclick="this.parentElement.remove()">&times;</button></div>';
    }
}

if (!function_exists('dashboard_open')) {
    /** Start a dashboard page: container + sidebar + main + header row. */
    function dashboard_open($role, $title, $actionsHtml = '') {
        echo '<div class="dashboard-container">';
        render_sidebar($role);
        echo '<main class="dashboard-main"><div class="dashboard-header"><h1>' . e($title) . '</h1>';
        if ($actionsHtml !== '') echo '<div class="header-actions">' . $actionsHtml . '</div>';
        echo '</div>';
    }
}
if (!function_exists('dashboard_close')) {
    function dashboard_close() { echo '</main></div>'; }
}
