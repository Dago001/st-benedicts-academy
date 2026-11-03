<?php
// includes/layout.php - Shared dashboard layout pieces (sidebar, alerts, page chrome)
require_once __DIR__ . '/../config/security.php';

if (!function_exists('nav_sections')) {
    /** Structured navigation categories per role */
    function nav_sections($role) {
        $role = $role ?: ($_SESSION['user_role'] ?? 'student');
        $isSuperAdmin = Security::isSuperAdmin();
        $isAdmin = Security::isAdmin();
        $isAcademicLead = in_array($role, ['super_admin', 'admin', 'head_teacher', 'supervisor'], true);

        if ($isAcademicLead) {
            $sections = [
                'MAIN' => [
                    ['dashboard', 'Dashboard', 'fa-home', ''],
                ],
                'STUDENTS' => [
                    ['admissions-enquiry', 'Admission Enquiry', 'fa-clipboard-list', ''],
                    ['new-admission', 'New Admission', 'fa-user-plus', ''],
                    ['students', 'All Students', 'fa-user-graduate', ''],
                    ['passed-out-archive', 'Passed Out Archive', 'fa-archive', ''],
                    ['promotion', 'Promotion', 'fa-graduation-cap', ''],
                    ['session-report', 'Session Report', 'fa-file-invoice', 'accent-orange'],
                ],
            ];

            if ($isAdmin) {
                $sections['FEES'] = [
                    ['fees?tab=structures', 'Fee Structure', 'fa-list-alt', ''],
                    ['fees', 'Fee Collection', 'fa-money-bill-wave', ''],
                    ['quick-fee-collect', 'Quick Fee Collect', 'fa-bolt', 'accent-green'],
                    ['fee-reminders', 'WhatsApp Reminder', 'fa-paper-plane', 'accent-green'],
                    ['fee-deletion-log', 'Fee Deletion Log', 'fa-trash-alt', 'accent-red'],
                ];
            }

            $sections['ACADEMICS'] = [
                ['attendance', 'Attendance', 'fa-calendar-check', ''],
                ['attendance-report', 'Attendance Report', 'fa-chart-line', ''],
                ['exams', 'Exams', 'fa-file-alt', ''],
                ['marks-entry', 'Marks Entry', 'fa-edit', ''],
                ['results', 'Class Result', 'fa-poll', ''],
                ['subjects', 'Subjects', 'fa-book', ''],
                ['assign-subjects', 'Class-Subject Map', 'fa-link', ''],
                ['timetable', 'Exam Schedule', 'fa-clock', ''],
            ];

            if ($isAdmin) {
                $sections['FINANCE'] = [
                    ['expenses', 'Expenses', 'fa-receipt', ''],
                    ['reports', 'All Reports', 'fa-file-contract', ''],
                    ['income-expense', 'Income vs Expense', 'fa-balance-scale', 'accent-purple'],
                ];
            }

            $staffItems = [
                ['teachers', 'Staff Management', 'fa-user-tie', ''],
            ];
            if ($isAdmin) {
                $staffItems[] = ['salary', 'Salary Management', 'fa-credit-card', ''];
                $staffItems[] = ['salary-report', 'Salary Report', 'fa-chart-bar', ''];
            }
            $sections['STAFF / PAYROLL'] = $staffItems;

            $sections['COMMUNICATION'] = [
                ['announcements', 'Notices', 'fa-bullhorn', ''],
                ['email-diagnostics', 'Email System & Test', 'fa-paper-plane', ''],
            ];

            if ($isAdmin) {
                $sections['ADMIN'] = [
                    ['site-content', 'Admin Settings', 'fa-sliders-h', ''],
                    ['site-content', 'Settings', 'fa-cog', ''],
                ];
            }

            $sections['ACCOUNT'] = [
                ['profile', 'Password Change', 'fa-key', ''],
                ['../logout', 'Logout', 'fa-sign-out-alt', 'accent-red'],
            ];

            return $sections;
        }

        if ($role === 'teacher') {
            return [
                'MAIN' => [
                    ['dashboard', 'Dashboard', 'fa-home', ''],
                ],
                'MY ACADEMICS' => [
                    ['classes', 'My Classes', 'fa-school', ''],
                    ['attendance', 'Attendance', 'fa-calendar-check', ''],
                    ['results', 'Marks & Results', 'fa-chart-line', ''],
                    ['assignments', 'Assignments', 'fa-tasks', ''],
                ],
                'STUDENTS' => [
                    ['students', 'My Students', 'fa-user-graduate', ''],
                ],
                'COMMUNICATION' => [
                    ['messages', 'Messages', 'fa-envelope', ''],
                ],
                'ACCOUNT' => [
                    ['profile', 'Profile & Password', 'fa-user-cog', ''],
                    ['../logout', 'Logout', 'fa-sign-out-alt', 'accent-red'],
                ],
            ];
        }

        if ($role === 'parent') {
            return [
                'MAIN' => [
                    ['dashboard', 'Dashboard', 'fa-home', ''],
                ],
                'MY CHILDREN' => [
                    ['children', 'My Children', 'fa-child', ''],
                    ['child-performance', 'Child Performance', 'fa-chart-line', ''],
                    ['schedule', 'Timetable', 'fa-clock', ''],
                ],
                'FEES & PAYMENTS' => [
                    ['fees', 'Fee Status & Paystack', 'fa-money-bill-wave', 'accent-green'],
                ],
                'COMMUNICATION' => [
                    ['messages', 'Messages', 'fa-envelope', ''],
                ],
                'ACCOUNT' => [
                    ['profile', 'Profile & Password', 'fa-user-cog', ''],
                    ['../logout', 'Logout', 'fa-sign-out-alt', 'accent-red'],
                ],
            ];
        }

        // Student default
        return [
            'MAIN' => [
                ['dashboard', 'Dashboard', 'fa-home', ''],
            ],
            'MY ACADEMICS' => [
                ['timetable', 'Timetable', 'fa-clock', ''],
                ['attendance', 'Attendance', 'fa-calendar-check', ''],
                ['assignments', 'Assignments & Homework', 'fa-tasks', ''],
                ['results', 'My Results', 'fa-chart-line', ''],
            ],
            'FEES' => [
                ['fees', 'Fees & Receipts', 'fa-money-bill', ''],
            ],
            'COMMUNICATION' => [
                ['messages', 'Messages', 'fa-envelope', ''],
            ],
            'ACCOUNT' => [
                ['profile', 'Profile & Password', 'fa-user-cog', ''],
                ['../logout', 'Logout', 'fa-sign-out-alt', 'accent-red'],
            ],
        ];
    }
}

if (!function_exists('nav_items')) {
    /** Flattened nav items fallback */
    function nav_items($role) {
        $flat = [];
        foreach (nav_sections($role) as $category => $items) {
            foreach ($items as $item) {
                $flat[] = [$item[0], $item[1], $item[2]];
            }
        }
        return $flat;
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
    /** Outputs the modern global-standard role sidebar (with mobile toggle + overlay). */
    function render_sidebar($role = null) {
        $role = $role ?: ($_SESSION['user_role'] ?? 'student');
        $current = basename($_SERVER['SCRIPT_NAME'], '.php');
        $baseRoleDir = in_array($role, ['super_admin', 'admin', 'head_teacher', 'supervisor'], true) ? 'admin' : $role;

        $alias = [
            'mark-attendance' => 'attendance', 'view-student' => 'students', 'teacher-profile' => 'teachers',
            'view-parent' => 'parents', 'student-fees' => 'fees', 'print-receipt' => 'fees',
            'view-receipt' => 'fees', 'attendance-detail' => 'attendance', 'submissions' => 'assignments',
            'assign-subjects' => 'assign-subjects', 'career-applications' => 'careers', 'vacancies' => 'careers',
            'quick-fee-collect' => 'quick-fee-collect', 'fee-reminders' => 'fee-reminders', 'fee-deletion-log' => 'fee-deletion-log',
            'new-admission' => 'new-admission', 'admissions-enquiry' => 'admissions-enquiry', 'passed-out-archive' => 'passed-out-archive',
            'promotion' => 'promotion', 'session-report' => 'session-report', 'attendance-report' => 'attendance-report',
            'exams' => 'exams', 'marks-entry' => 'marks-entry', 'expenses' => 'expenses', 'income-expense' => 'income-expense',
            'salary' => 'salary', 'salary-report' => 'salary-report',
        ];
        $active = $alias[$current] ?? $current;
        $unread = unread_message_count();
        $sections = nav_sections($role);
        $displayName = $_SESSION['user_name'] ?? Security::roleTitle();
        $userInitial = strtoupper(substr($displayName, 0, 1));
        $badgeText = Security::roleBadge();
        ?>
    <button type="button" class="sidebar-toggle-mobile" id="sidebarToggle" aria-label="Open menu" aria-expanded="false" aria-controls="appSidebar">
        <i class="fas fa-bars"></i><span>Menu</span>
    </button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <aside class="sidebar modern-school-sidebar" id="appSidebar">
        <!-- Brand & System Header -->
        <div class="sidebar-brand-card">
            <a href="<?php echo BASE_URL . '/' . $baseRoleDir . '/dashboard'; ?>" class="sidebar-brand-inner">
                <img src="<?php echo BASE_URL; ?>/assets/images/logo.png" alt="Logo" class="sidebar-school-crest">
                <div class="sidebar-brand-text-block">
                    <span class="brand-school-title">ST. BENEDICT'S ACADEMY</span>
                    <span class="brand-system-sub">Early Years British Academy</span>
                </div>
            </a>
            <button type="button" class="sidebar-close-btn" id="sidebarClose" aria-label="Close menu"><i class="fas fa-times"></i></button>
        </div>

        <!-- Categorized Nav Menu (with interactive collapsible dropdowns) -->
        <nav class="sidebar-nav-scroll" aria-label="Main Navigation">
            <?php
            $sectionIcons = [
                'MAIN' => 'fa-home',
                'STUDENTS' => 'fa-user-graduate',
                'FEES' => 'fa-money-bill-wave',
                'FEES & PAYMENTS' => 'fa-money-bill-wave',
                'ACADEMICS' => 'fa-book-reader',
                'MY ACADEMICS' => 'fa-graduation-cap',
                'MY CHILDREN' => 'fa-child',
                'FINANCE' => 'fa-coins',
                'STAFF / PAYROLL' => 'fa-user-tie',
                'COMMUNICATION' => 'fa-bullhorn',
                'ADMIN' => 'fa-sliders-h',
                'ACCOUNT' => 'fa-user-cog',
            ];
            foreach ($sections as $sectionTitle => $items):
                $isMain = ($sectionTitle === 'MAIN');
                $secIcon = $sectionIcons[$sectionTitle] ?? 'fa-folder';
                $sectionHasActive = false;
                foreach ($items as $it) {
                    $ck = explode('?', $it[0])[0];
                    if ($active === $ck) {
                        $sectionHasActive = true;
                        break;
                    }
                }
            ?>
                <?php if ($isMain): ?>
                <div class="nav-section-group nav-group-direct">
                    <div class="nav-section-heading"><?php echo e($sectionTitle); ?></div>
                    <ul class="nav-section-list">
                        <?php foreach ($items as $item):
                            $file = $item[0];
                            $label = $item[1];
                            $icon = $item[2];
                            $accent = $item[3] ?? '';
                            $url = (strpos($file, '/') !== false || strpos($file, 'http') === 0)
                                ? (strpos($file, '../') === 0 ? BASE_URL . substr($file, 2) : $file)
                                : BASE_URL . '/' . $baseRoleDir . '/' . $file;
                            $checkKey = explode('?', $file)[0];
                            $isActive = ($active === $checkKey);
                        ?>
                        <li class="nav-section-item <?php echo $isActive ? 'active' : ''; ?>">
                            <a href="<?php echo e($url); ?>" class="nav-link-btn <?php echo e($accent); ?><?php echo $isActive ? ' is-active' : ''; ?>"<?php echo $isActive ? ' aria-current="page"' : ''; ?>>
                                <i class="fas <?php echo e($icon); ?> nav-item-icon"></i>
                                <span class="nav-item-label"><?php echo e($label); ?></span>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php else: ?>
                <div class="nav-section-group nav-section-dropdown <?php echo $sectionHasActive ? 'open' : ''; ?>">
                    <button type="button" class="sidebar-section-toggle" aria-expanded="<?php echo $sectionHasActive ? 'true' : 'false'; ?>">
                        <span class="section-toggle-left">
                            <i class="fas <?php echo e($secIcon); ?> section-toggle-icon"></i>
                            <span class="section-toggle-title"><?php echo e($sectionTitle); ?></span>
                        </span>
                        <i class="fas fa-chevron-down section-toggle-caret"></i>
                    </button>
                    <ul class="nav-section-list sidebar-submenu" <?php echo $sectionHasActive ? '' : 'style="display: none;"'; ?>>
                        <?php foreach ($items as $item):
                            $file = $item[0];
                            $label = $item[1];
                            $icon = $item[2];
                            $accent = $item[3] ?? '';
                            $url = (strpos($file, '/') !== false || strpos($file, 'http') === 0)
                                ? (strpos($file, '../') === 0 ? BASE_URL . substr($file, 2) : $file)
                                : BASE_URL . '/' . $baseRoleDir . '/' . $file;
                            $checkKey = explode('?', $file)[0];
                            $isActive = ($active === $checkKey);
                        ?>
                        <li class="nav-section-item <?php echo $isActive ? 'active' : ''; ?>">
                            <a href="<?php echo e($url); ?>" class="nav-link-btn <?php echo e($accent); ?><?php echo $isActive ? ' is-active' : ''; ?>"<?php echo $isActive ? ' aria-current="page"' : ''; ?>>
                                <i class="fas <?php echo e($icon); ?> nav-item-icon"></i>
                                <span class="nav-item-label"><?php echo e($label); ?></span>
                                <?php if ($file === 'messages' && $unread > 0): ?>
                                    <span class="badge badge-danger nav-pill"><?php echo $unread; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <!-- Sidebar Bottom Footer Info Bar -->
        <div class="sidebar-bottom-bar">
            <div class="sbb-phone"><i class="fas fa-phone-alt"></i> <span>09044472688</span></div>
            <div class="sbb-links">
                <a href="<?php echo BASE_URL; ?>" target="_blank" title="Website"><i class="fas fa-globe"></i> Website</a>
                <span class="sbb-dot">&bull;</span>
                <a href="<?php echo BASE_URL; ?>/public/contact" target="_blank" title="Contact"><i class="fas fa-envelope"></i> Contact</a>
            </div>
        </div>
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
        echo '<main class="dashboard-main"><div class="dashboard-header' . ($title === 'Dashboard' ? ' is-home' : '') . '"><h1>' . e($title) . '</h1>';
        if ($actionsHtml !== '') echo '<div class="header-actions">' . $actionsHtml . '</div>';
        echo '</div>';
        if (!empty($_SESSION['must_change_password'])) {
            $profUrl = BASE_URL . '/profile';
            echo '<div class="alert alert-warning" style="margin: 14px 0 20px 0; border: 1px solid #f59e0b; border-radius: 8px; padding: 12px 18px; background: #fffbeb; color: #92400e; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                    <div><i class="fas fa-key" style="margin-right: 8px; color: #d97706;"></i> <strong>One-Time Password Notice:</strong> You are currently signed in with a temporary password. Please update your password to a secure personal password.</div>
                    <a href="' . $profUrl . '" class="btn btn-sm btn-primary" style="white-space: nowrap;"><i class="fas fa-lock"></i> Change Password Now</a>
                  </div>';
        }
    }
}

if (!function_exists('dashboard_close')) {
    function dashboard_close() { echo '</main></div>'; }
}
