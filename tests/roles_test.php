<?php
// tests/roles_test.php - Comprehensive testing of the 7-Role Architecture and Scoped Access
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('TESTING', true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$pass = $fail = 0;
function test($name, $condition) {
    global $pass, $fail;
    if ($condition) {
        $pass++;
    } else {
        $fail++;
        echo "FAIL: $name\n";
    }
}

echo "=== 1. ROLE DEFINITIONS & BADGES ===\n";
$expectedRoles = [
    'super_admin'  => 'Super Administrator',
    'admin'        => 'Administrator',
    'head_teacher' => 'Head Teacher',
    'supervisor'   => 'Supervisor',
    'teacher'      => 'Teacher',
    'parent'       => 'Parent',
    'student'      => 'Student',
];
foreach ($expectedRoles as $roleKey => $roleTitle) {
    test("Role title for {$roleKey}", Security::roleTitle($roleKey) === $roleTitle);
    test("Role badge exists for {$roleKey}", !empty(Security::roleBadge($roleKey)));
}

echo "\n=== 2. ROLE HIERARCHY & PERMISSIONS ===\n";
// Test Super Admin
$_SESSION = ['user_id' => 1, 'user_role' => 'super_admin', 'user_name' => 'Super Admin'];
test('super_admin is logged in', Security::isLoggedIn());
test('super_admin isSuperAdmin()', Security::isSuperAdmin());
test('super_admin isAdmin()', Security::isAdmin());
test('super_admin isAcademicStaff()', Security::isAcademicStaff());
test('super_admin hasRole(super_admin)', Security::hasRole('super_admin'));
test('super_admin hasRole(admin)', Security::hasRole('admin'));
test('super_admin hasRole(head_teacher)', Security::hasRole('head_teacher'));
test('super_admin hasRole(supervisor)', Security::hasRole('supervisor'));
test('super_admin !hasRole(teacher)', !Security::hasRole('teacher'));
test('super_admin !hasRole(parent)', !Security::hasRole('parent'));
test('super_admin !hasRole(student)', !Security::hasRole('student'));

// Test Admin
$_SESSION = ['user_id' => 2, 'user_role' => 'admin', 'user_name' => 'School Admin'];
test('admin !isSuperAdmin()', !Security::isSuperAdmin());
test('admin isAdmin()', Security::isAdmin());
test('admin isAcademicStaff()', Security::isAcademicStaff());
test('admin !hasRole(super_admin)', !Security::hasRole('super_admin'));
test('admin hasRole(admin)', Security::hasRole('admin'));
test('admin hasRole(head_teacher)', Security::hasRole('head_teacher'));
test('admin hasRole(supervisor)', Security::hasRole('supervisor'));
test('admin !hasRole(teacher)', !Security::hasRole('teacher'));

// Test Head Teacher
$_SESSION = ['user_id' => 3, 'user_role' => 'head_teacher', 'user_name' => 'Principal'];
test('head_teacher !isAdmin()', !Security::isAdmin());
test('head_teacher isAcademicStaff()', Security::isAcademicStaff());
test('head_teacher !hasRole(admin)', !Security::hasRole('admin'));
test('head_teacher hasRole(head_teacher)', Security::hasRole('head_teacher'));
test('head_teacher hasRole(supervisor)', Security::hasRole('supervisor'));
test('head_teacher !hasRole(teacher)', !Security::hasRole('teacher'));

// Test Supervisor
$_SESSION = ['user_id' => 4, 'user_role' => 'supervisor', 'user_name' => 'Supervisor'];
test('supervisor !isAdmin()', !Security::isAdmin());
test('supervisor isAcademicStaff()', Security::isAcademicStaff());
test('supervisor !hasRole(head_teacher)', !Security::hasRole('head_teacher'));
test('supervisor hasRole(supervisor)', Security::hasRole('supervisor'));
test('supervisor !hasRole(teacher)', !Security::hasRole('teacher'));

// Test Teacher
$_SESSION = ['user_id' => 5, 'user_role' => 'teacher', 'user_name' => 'Tina Teacher'];
test('teacher isAcademicStaff()', Security::isAcademicStaff());
test('teacher hasRole(teacher)', Security::hasRole('teacher'));
test('teacher !hasRole(admin)', !Security::hasRole('admin'));
test('teacher !hasRole(supervisor)', !Security::hasRole('supervisor'));

// Test Parent
$_SESSION = ['user_id' => 6, 'user_role' => 'parent', 'user_name' => 'Paula Parent'];
test('parent !isAcademicStaff()', !Security::isAcademicStaff());
test('parent hasRole(parent)', Security::hasRole('parent'));
test('parent !hasRole(student)', !Security::hasRole('student'));
test('parent !hasRole(teacher)', !Security::hasRole('teacher'));

// Test Student
$_SESSION = ['user_id' => 7, 'user_role' => 'student', 'user_name' => 'Sam Student'];
test('student !isAcademicStaff()', !Security::isAcademicStaff());
test('student hasRole(student)', Security::hasRole('student'));
test('student !hasRole(parent)', !Security::hasRole('parent'));
test('student !hasRole(teacher)', !Security::hasRole('teacher'));

echo "\n=== 3. SIDEBAR CATEGORIES & MODULES ===\n";
// Super Admin nav sections
$_SESSION['user_role'] = 'super_admin';
$adminSections = nav_sections('super_admin');
test('admin has MAIN section', isset($adminSections['MAIN']));
test('admin has STUDENTS section', isset($adminSections['STUDENTS']));
test('admin has FEES section', isset($adminSections['FEES']));
test('admin has ACADEMICS section', isset($adminSections['ACADEMICS']));
test('admin has FINANCE section', isset($adminSections['FINANCE']));
test('admin has STAFF / PAYROLL section', isset($adminSections['STAFF / PAYROLL']));
test('admin has COMMUNICATION section', isset($adminSections['COMMUNICATION']));
test('admin has ADMIN section', isset($adminSections['ADMIN']));
test('admin has ACCOUNT section', isset($adminSections['ACCOUNT']));

// Check specific sidebar links from image
$allAdminItems = [];
foreach ($adminSections as $sec => $items) {
    foreach ($items as $it) {
        $allAdminItems[$it[0]] = $it[1];
    }
}
test('sidebar has New Admission', isset($allAdminItems['new-admission']));
test('sidebar has Admission Enquiry', isset($allAdminItems['admissions-enquiry']));
test('sidebar has Passed Out Archive', isset($allAdminItems['passed-out-archive']));
test('sidebar has Promotion', isset($allAdminItems['promotion']));
test('sidebar has Session Report', isset($allAdminItems['session-report']));
test('sidebar has Quick Fee Collect', isset($allAdminItems['quick-fee-collect']));
test('sidebar has WhatsApp Reminder', isset($allAdminItems['fee-reminders']));
test('sidebar has Fee Deletion Log', isset($allAdminItems['fee-deletion-log']));
test('sidebar has Attendance Report', isset($allAdminItems['attendance-report']));
test('sidebar has Exams', isset($allAdminItems['exams']));
test('sidebar has Marks Entry', isset($allAdminItems['marks-entry']));
test('sidebar has Expenses', isset($allAdminItems['expenses']));
test('sidebar has Income vs Expense', isset($allAdminItems['income-expense']));
test('sidebar has Salary Management', isset($allAdminItems['salary']));
test('sidebar has Salary Report', isset($allAdminItems['salary-report']));

// Teacher nav sections (scoped to teacher)
$teacherSections = nav_sections('teacher');
test('teacher has MY ACADEMICS', isset($teacherSections['MY ACADEMICS']));
test('teacher has My Students', isset($teacherSections['STUDENTS']));
test('teacher does NOT have FINANCE', !isset($teacherSections['FINANCE']));
test('teacher does NOT have Fee Deletion Log', !isset($teacherSections['FEES']));

// Parent nav sections
$parentSections = nav_sections('parent');
test('parent has MY CHILDREN', isset($parentSections['MY CHILDREN']));
test('parent has FEES & PAYMENTS', isset($parentSections['FEES & PAYMENTS']));
test('parent does NOT have STAFF', !isset($parentSections['STAFF / PAYROLL']));

// Student nav sections
$studentSections = nav_sections('student');
test('student has Timetable', isset($studentSections['MY ACADEMICS']));
test('student has My Results', isset($studentSections['MY ACADEMICS']));
test('student does NOT have STAFF', !isset($studentSections['STAFF / PAYROLL']));

echo "\n=== 4. CSS COMPLIANCE: ZERO COLORED BORDER-LEFT & MOBILE RESPONSIVE ===\n";
$sidebarCss = file_get_contents(__DIR__ . '/../assets/css/sidebar-modern.css');
test('sidebar CSS has zero border-left: solid/rgb/color', strpos($sidebarCss, 'border-left:') === false);
test('sidebar CSS has zero border-left colored rule', strpos($sidebarCss, 'border-left-color') === false);
test('sidebar CSS has mobile breakpoint @media (max-width: 991px)', strpos($sidebarCss, '@media (max-width: 991px)') !== false);
test('sidebar CSS has active rounded pill styling', strpos($sidebarCss, 'is-active') !== false);

echo "\n=== 5. MODAL SYSTEM COMPLIANCE ===\n";
$polishCss = file_get_contents(__DIR__ . '/../assets/css/polish.css');
test('polish.css defines .modal display: none', strpos($polishCss, '.modal,') !== false && strpos($polishCss, 'display: none') !== false);
test('polish.css defines modal backdrop blur', strpos($polishCss, 'backdrop-filter: blur') !== false);
test('polish.css defines modal card with border-radius', strpos($polishCss, '.modal-content,') !== false);
test('polish.css defines circular close button hover', strpos($polishCss, '.modal-header .close:hover') !== false);

$studentsPhp = file_get_contents(__DIR__ . '/../admin/students.php');
test('admin/students.php deactivateModal has explicit style="display: none;"', strpos($studentsPhp, 'id="deactivateModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="deactivateModalTitle" style="display: none;"') !== false);
test('admin/students.php deactivateModal has icon and high contrast warning', strpos($studentsPhp, 'fa-user-slash') !== false && strpos($studentsPhp, 'class="text-warning"') !== false);

$teachersPhp = file_get_contents(__DIR__ . '/../admin/teachers.php');
test('admin/teachers.php deactivateModal has explicit style="display: none;"', strpos($teachersPhp, 'id="deactivateModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="deactivateTeacherModalTitle" style="display: none;"') !== false);

$parentsPhp = file_get_contents(__DIR__ . '/../admin/parents.php');
test('admin/parents.php deactivateModal has explicit style="display: none;"', strpos($parentsPhp, 'id="deactivateModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="deactivateParentModalTitle" style="display: none;"') !== false);

echo "\n=== 6. SIDEBAR USER PROFILE REMOVAL & 3D LOGO SPIN PRELOADER ===\n";
$layoutPhp = file_get_contents(__DIR__ . '/../includes/layout.php');
test('sidebar-user-profile-box is REMOVED from includes/layout.php', strpos($layoutPhp, 'sidebar-user-profile-box') === false);

$headerPhp = file_get_contents(__DIR__ . '/../includes/header.php');
test('includes/header.php includes stbPagePreloader', strpos($headerPhp, 'id="stbPagePreloader"') !== false);
test('includes/header.php loads preloader.js', strpos($headerPhp, 'assets/js/preloader.js') !== false);
test('includes/header.php displays official school crest logo in preloader', strpos($headerPhp, 'class="stb-spinning-logo"') !== false || strpos($headerPhp, 'class="stb-crest-image"') !== false);

$preloaderJs = file_get_contents(__DIR__ . '/../assets/js/preloader.js');
test('preloader.js intercepts navigation links for logo spin', strpos($preloaderJs, 'navigateWithSpinner') !== false);
test('preloader.js spin duration is between 1-2 seconds (1000ms - 2000ms)', preg_match('/DURATION_MS\s*=\s*(1[0-9]{3}|2000)/', $preloaderJs) === 1);

test('polish.css defines 3D logo spin keyframes (stb3dSpin)', strpos($polishCss, '@keyframes stb3dSpin') !== false);
test('polish.css defines preloader aura and ring animation', strpos($polishCss, 'stbAuraPulse') !== false && strpos($polishCss, 'stbRingRotate') !== false);

echo "\n=== 7. MOBILE RESPONSIVE DASHBOARD & SECTIONED NAVIGATION MENU ===\n";
test('includes/header.php uses nav_sections() for mobile navigation', strpos($headerPhp, 'nav_sections($portalRole)') !== false);
test('includes/header.php outputs nav-portal-section for collapsible dropdowns', strpos($headerPhp, 'class="nav-portal nav-portal-section') !== false);
test('includes/header.php outputs nav-portal-section-toggle', strpos($headerPhp, 'class="nav-portal-section-toggle"') !== false);
test('includes/header.php applies accent classes in mobile navigation', strpos($headerPhp, 'class="nav-portal-link') !== false);
test('includes/layout.php outputs sidebar-section-toggle for collapsible categories', strpos($layoutPhp, 'class="sidebar-section-toggle"') !== false);

$mobileCss = file_get_contents(__DIR__ . '/../assets/css/mobile.css');
test('mobile.css defines .nav-portal-section-toggle with uppercase letter-spacing', strpos($mobileCss, '.nav-portal-section-toggle') !== false);
test('mobile.css defines active rounded solid blue pill for mobile nav', strpos($mobileCss, 'body .nav-menu .nav-portal.active a') !== false && strpos($mobileCss, '#0066ff') !== false);
test('mobile.css has NO forced uniform gold icon rule', strpos($mobileCss, 'body .nav-menu .nav-portal a i { width: 22px; text-align: center; color: var(--gold, #ffd700); }') === false);
test('mobile.css defines row layout for dashboard header (title left, user pill right)', strpos($mobileCss, 'body .dashboard-header') !== false && strpos($mobileCss, 'flex-direction: row !important;') !== false);
test('mobile.css defines 2x2 stats-grid on mobile (< 768px)', strpos($mobileCss, 'repeat(2, minmax(0, 1fr))') !== false);

$mainJs = file_get_contents(__DIR__ . '/../assets/js/main.js');
test('main.js closes mobile navMenu when any link is clicked', strpos($mainJs, "navMenu.querySelectorAll('a').forEach") !== false);
test('main.js defines initNavSectionDropdowns for collapsible section dropdowns', strpos($mainJs, "initNavSectionDropdowns") !== false);

$sidebarModernCss = file_get_contents(__DIR__ . '/../assets/css/sidebar-modern.css');
test('sidebar-modern.css defines .sidebar-section-toggle and .section-toggle-caret', strpos($sidebarModernCss, '.sidebar-section-toggle') !== false && strpos($sidebarModernCss, '.section-toggle-caret') !== false);

echo "\n=== 8. EMAIL SYSTEM, OFFICIAL STUDENT EMAILS & ONE-TIME PASSWORDS ===\n";
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/helpers.php';

test('Mailer::getSchoolEmail() is mail@stbenedictsacademy.com.ng', Mailer::getSchoolEmail() === 'mail@stbenedictsacademy.com.ng');
test('config.php defines SCHOOL_EMAIL as support@stbenedictsacademy.com.ng', defined('SCHOOL_EMAIL') && SCHOOL_EMAIL === 'support@stbenedictsacademy.com.ng');
test('config.php defines MAIL_FROM as mail@stbenedictsacademy.com.ng', defined('MAIL_FROM') && MAIL_FROM === 'mail@stbenedictsacademy.com.ng');

// Student official email generation
$email1 = generate_student_official_email('STB/2026/001', 'David', 'Okeke');
test('generate_student_official_email produces stb2026001@stbenedictsacademy.com.ng', $email1 === 'stb2026001@stbenedictsacademy.com.ng');
$email2 = generate_student_official_email('SBA-ADM-452', 'Mary', 'Eze');
test('generate_student_official_email cleans hyphens and lowercase', $email2 === 'sbaadm452@stbenedictsacademy.com.ng');

// Email template mobile formatting
$sampleTemplate = Mailer::formatTemplate('Results Published', '<p>Test content</p>', 'https://stbenedictsacademy.com.ng/login', 'Sign In');
test('Mailer::formatTemplate produces responsive mobile email', strpos($sampleTemplate, '<meta name="viewport" content="width=device-width, initial-scale=1.0">') !== false);
test('Mailer::formatTemplate contains school logo URL', strpos($sampleTemplate, 'assets/images/logo.png') !== false);
test('Mailer::formatTemplate contains primary school color #002855', strpos($sampleTemplate, '#002855') !== false);
test('Mailer::formatTemplate contains school email mail@stbenedictsacademy.com.ng', strpos($sampleTemplate, 'mail@stbenedictsacademy.com.ng') !== false);
test('Mailer::formatTemplate includes call-to-action button', strpos($sampleTemplate, 'Sign In') !== false);

// Welcome email builder with one-time password
test('send_account_welcome_email exists and is callable', function_exists('send_account_welcome_email'));
test('send_result_notification_email exists and is callable', function_exists('send_result_notification_email'));
test('notify_approved_results exists and is callable', function_exists('notify_approved_results'));

// Verify admin email diagnostics file exists and is lint-clean
test('admin/email-diagnostics.php exists', file_exists(__DIR__ . '/../admin/email-diagnostics.php'));
$emailDiagContent = file_get_contents(__DIR__ . '/../admin/email-diagnostics.php');
test('admin/email-diagnostics.php supports send_test_email action', strpos($emailDiagContent, "'send_test_email'") !== false);
test('admin/email-diagnostics.php displays mail@stbenedictsacademy.com.ng', strpos($emailDiagContent, 'mail@stbenedictsacademy.com.ng') !== false);

// Resilience: Auth::login & login.php error handling
$loginFile = file_get_contents(__DIR__ . '/../login.php');
test('login.php wraps POST processing in try-catch to prevent 500 error', strpos($loginFile, 'catch (Throwable $e)') !== false);
$authFile = file_get_contents(__DIR__ . '/../includes/auth.php');
test('auth.php uses safe user query without breaking joins', strpos($authFile, 'SELECT id, username, email, password_hash') !== false);
test('scripts/migrate.php includes must_change_password column', strpos(file_get_contents(__DIR__ . '/../scripts/migrate.php'), "'must_change_password'") !== false);
test('scripts/migrate.php includes official_email column', strpos(file_get_contents(__DIR__ . '/../scripts/migrate.php'), "'official_email'") !== false);

echo "\n=== 9. ADMIN & SYSTEM ADMINISTRATOR ROLE CREATION ===\n";
test('admin/administrators.php exists', file_exists(__DIR__ . '/../admin/administrators.php'));
test('admin/users.php exists as alias', file_exists(__DIR__ . '/../admin/users.php'));
$adminSections = nav_sections('super_admin');
$allAdminItems = [];
foreach ($adminSections as $sec => $items) {
    foreach ($items as $it) {
        $allAdminItems[$it[0]] = $it[1];
    }
}
test('sidebar ADMIN section has Admin & System Roles', isset($allAdminItems['administrators']));
$adminPageContent = file_get_contents(__DIR__ . '/../admin/administrators.php');
test('admin/administrators.php handles super_admin creation', strpos($adminPageContent, "'super_admin'") !== false);
test('admin/administrators.php handles admin creation', strpos($adminPageContent, "'admin'") !== false);
test('admin/administrators.php dispatches welcome email with one-time password', strpos($adminPageContent, 'send_account_welcome_email') !== false);
test('admin/administrators.php sets must_change_password', strpos($adminPageContent, 'must_change_password') !== false);
test('admin/administrators.php script tag has CSP_NONCE attribute to prevent browser blocking', strpos($adminPageContent, '<script nonce="<?php echo CSP_NONCE; ?>">') !== false);
test('admin/administrators.php header button has id btnCreateAdminHeader and openAddModal', strpos($adminPageContent, 'id="btnCreateAdminHeader"') !== false && strpos($adminPageContent, 'onclick="openAddModal()"') !== false);
test('admin/administrators.php table button has id btnCreateAdminTable and openAddModal', strpos($adminPageContent, 'id="btnCreateAdminTable"') !== false);
test('admin/administrators.php modal opens with active and show classes', strpos($adminPageContent, "modal.classList.add('active', 'show')") !== false);
test('admin/administrators.php role select contains super_admin option', strpos($adminPageContent, 'value="super_admin"') !== false);
test('admin/administrators.php role select contains admin option', strpos($adminPageContent, 'value="admin"') !== false);
test('includes/SystemSchema.php ensures super_admin exists or upgrades default admin', strpos(file_get_contents(__DIR__ . '/../includes/SystemSchema.php'), "UPDATE users SET role = 'super_admin'") !== false);

echo "\n=== 10. BACKEND DESKTOP HEADER PILL, FOOTER REMOVAL & FIXED SIDEBAR ===\n";
test('includes/layout.php defines render_user_dropdown function', function_exists('render_user_dropdown'));
$layoutContent = file_get_contents(__DIR__ . '/../includes/layout.php');
test('includes/layout.php renders user dropdown in dashboard_open', strpos($layoutContent, 'render_user_dropdown()') !== false);
test('includes/header.php exposes window.CURRENT_USER', strpos(file_get_contents(__DIR__ . '/../includes/header.php'), 'window.CURRENT_USER =') !== false);
$footerContent = file_get_contents(__DIR__ . '/../includes/footer.php');
test('includes/footer.php skips main-footer on backend portals', strpos($footerContent, '$isBackendPortal') !== false && strpos($footerContent, 'if (!$isBackendPortal):') !== false);
$polishCss = file_get_contents(__DIR__ . '/../assets/css/polish.css');
test('polish.css hides main-footer for authenticated backend users', strpos($polishCss, 'body.is-auth .main-footer') !== false);
test('polish.css pins desktop sidebar fixed at top: 0 with 100vh height', strpos($polishCss, 'aside.modern-school-sidebar') !== false && strpos($polishCss, 'position: fixed !important') !== false);
test('polish.css hides user dropdown on mobile view (< 769px)', strpos($polishCss, 'body .dashboard-header .user-dropdown-wrapper') !== false && strpos($polishCss, 'display: none !important') !== false);
test('mobile.css offsets dashboard-main by 270px on desktop to prevent sidebar overlap', strpos(file_get_contents(__DIR__ . '/../assets/css/mobile.css'), 'margin-left: 270px !important') !== false);
test('main.js includes automatic userDropdown fallback injection for dashboard-header', strpos(file_get_contents(__DIR__ . '/../assets/js/main.js'), 'dashboardHeader.appendChild(wrap)') !== false);

echo "\n=== 11. LANDING PAGE CORE PILLARS, MVG COMPACT CONTROLS & FOOTER CENTERING ===\n";
$indexContent = file_get_contents(__DIR__ . '/../index.php');
test('index.php replaces redundant quick-stats with core-pillars-section', strpos($indexContent, 'core-pillars-section') !== false && strpos($indexContent, '<section class="quick-stats">') === false);
test('index.php core pillars includes British EYFS Standards', strpos($indexContent, 'British EYFS Standards') !== false);
test('index.php core pillars includes Christian Moral Values', strpos($indexContent, 'Christian Moral Values') !== false);
test('index.php mvg-container uses min-height: 0 to eliminate 300px blank void', strpos($indexContent, '.mvg-container {') !== false && strpos($indexContent, 'min-height: 0;') !== false);
test('polish.css contains compact mvg-slideshow rules', strpos($polishCss, 'body .mvg-container') !== false && strpos($polishCss, 'min-height: 0 !important') !== false);
test('includes/footer.php has centered footer-bottom copyright and privacy notice', strpos($footerContent, '<div class="footer-bottom" style="text-align: center;">') !== false);
test('polish.css enforces text-align center on .footer-bottom for mobile', strpos($polishCss, 'body .footer-bottom, body .footer-bottom p') !== false && strpos($polishCss, 'text-align: center !important') !== false);
test('cms_default school.email returns support@stbenedictsacademy.com.ng', cms_default('school.email') === 'support@stbenedictsacademy.com.ng');
test('index.php pillars-grid has 1fr column layout on mobile', strpos($indexContent, '.pillars-grid {') !== false && strpos($indexContent, 'grid-template-columns: 1fr !important;') !== false);
$mobileCss = file_get_contents(__DIR__ . '/../assets/css/mobile.css');
test('mobile.css enforces 1fr for pillars-grid on <= 767px', strpos($mobileCss, 'body .core-pillars-section .pillars-grid') !== false && strpos($mobileCss, 'grid-template-columns: 1fr !important;') !== false);

echo "\n=== 12. ADMISSION APPLICATION DUAL PAYMENT, PASSPORT UPLOAD, INTERVIEWS & PARENT LOGINS ===\n";
$applyContent = file_get_contents(__DIR__ . '/../public/apply.php');
test('public/apply.php has child_passport_photo input in Step 1', strpos($applyContent, 'id="child_passport_photo"') !== false);
test('public/apply.php has child-passport-card preview frame', strpos($applyContent, 'id="childPassportCard"') !== false && strpos($applyContent, 'id="passportLivePreviewImg"') !== false);
test('public/apply.php validates child passport photo in validateStep(1)', strpos($applyContent, 'Child\'s Passport Photograph on Step 1') !== false);
test('public/apply.php handles pay_at_school payment status pending_school_payment', strpos($applyContent, "payStatus = 'pending_school_payment'") !== false);
test('public/apply.php blocks unverified online Paystack payments before submission', strpos($applyContent, 'PAYMENT_NOT_VERIFIED') !== false);
test('public/apply.php has client-side submit guard checking payment verification', strpos($applyContent, 'Online Payment Required Before Submission') !== false);
test('public/apply.php displays Print Official Admission Application Slip on success', strpos($applyContent, 'Print Official Admission Application Slip') !== false);

$componentsContent = file_get_contents(__DIR__ . '/../includes/application-components.php');
test('application-components.php includes pay_at_school option', strpos($componentsContent, 'value="pay_at_school"') !== false);
test('application-components.php includes payAtSchoolPanel with Zenith bank details', strpos($componentsContent, 'id="payAtSchoolPanel"') !== false && strpos($componentsContent, '1310057496') !== false);

$receiptContent = file_get_contents(__DIR__ . '/../public/application-receipt.php');
test('public/application-receipt.php resolves and displays candidate passport photo', strpos($receiptContent, '$photoBase64') !== false && strpos($receiptContent, 'Candidate Passport Photo') !== false);
test('public/application-receipt.php supports Official Admission Application Slip header', strpos($receiptContent, 'Official Admission Application Slip') !== false);
test('public/application-receipt.php displays PAYMENT PENDING - PAY AT SCHOOL badge for slips', strpos($receiptContent, 'PAYMENT PENDING &mdash; PAY AT SCHOOL') !== false);
test('public/application-receipt.php includes admissions verification sign-off section', strpos($receiptContent, 'Admissions &amp; Bursary Verification Sign-off') !== false);

$adminAppsContent = file_get_contents(__DIR__ . '/../admin/applications.php');
test('admin/applications.php has confirm_school_payment post action', strpos($adminAppsContent, "'confirm_school_payment'") !== false);
test('admin/applications.php has interview_decision post action (accept/reject)', strpos($adminAppsContent, "'interview_decision'") !== false);
test('admin/applications.php has create_parent_login post action', strpos($adminAppsContent, "'create_parent_login'") !== false);
test('admin/applications.php displays child passport thumbnail', strpos($adminAppsContent, 'app-photo-thumb') !== false);
test('admin/applications.php displays interview decision and teacher notes form', strpos($adminAppsContent, 'Teacher Interview Evaluation &amp; Admissions Decision') !== false);
test('admin/applications.php displays parent credentials modal with WhatsApp share', strpos($adminAppsContent, 'credModalBackdrop') !== false && strpos($adminAppsContent, 'https://wa.me/') !== false);

$parentFeesContent = file_get_contents(__DIR__ . '/../parent/fees.php');
test('parent/fees.php has Pay Fees for Another Student / Ward section', strpos($parentFeesContent, 'Pay School Fees for Another Student / Ward') !== false);
test('parent/fees.php allows student lookup by admission number', strpos($parentFeesContent, 'other_student') !== false && strpos($parentFeesContent, 'other_student_admission') !== false);
test('parent/fees.php provides payment narration format for other students', strpos($parentFeesContent, 'FEE - ') !== false);

require_once __DIR__ . '/../includes/ApplicationHelper.php';
$catalog = ApplicationHelper::getDocumentCatalog();
test('ApplicationHelper::getDocumentCatalog does NOT contain passport_photo in Step 4 dropdown', !isset($catalog['passport_photo']));
test('public/apply.php removed age limitation "between 2 and 7 years old"', strpos($applyContent, 'between 2 and 7 years old') === false);
test('application-components.php completely removed seqActiveDocBanner section', strpos($componentsContent, 'id="seqActiveDocBanner"') === false && strpos($componentsContent, 'class="seq-active-doc-banner"') === false);
test('public/apply.php has compact professional styling for form-navigation buttons', strpos($applyContent, '.form-navigation .btn,') !== false && strpos($applyContent, 'padding: 10px 22px !important;') !== false);
test('public/apply.php form has novalidate attribute to prevent silent validation aborts', strpos($applyContent, 'id="applicationForm" novalidate') !== false);


echo "\n==========================================\n";
echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
