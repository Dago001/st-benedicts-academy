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

echo "\n==========================================\n";
echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
