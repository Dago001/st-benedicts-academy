<?php
// admin/new-admission.php - Register a New Student (Full Multi-Section Form)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/Student.php';
require_once __DIR__ . '/../includes/SystemSchema.php';

Security::requireRole(['super_admin', 'admin', 'head_teacher']);
SystemSchema::ensureAll();

$db = db();
[$message, $messageType] = flash_get();

// Auto-fill from enquiry / application if requested
$enquiryId = (int)($_GET['enquiry_id'] ?? 0);
$prefill = [];
if ($enquiryId > 0) {
    $enq = $db->getRow("SELECT * FROM admissions WHERE id = ?", [$enquiryId]);
    if ($enq) {
        $prefill = [
            'form_no'         => $enq['application_number'],
            'first_name'      => $enq['first_name'],
            'middle_name'     => $enq['middle_name'] ?? '',
            'last_name'       => $enq['last_name'],
            'dob'             => $enq['date_of_birth'],
            'gender'          => $enq['gender'],
            'class_applying'  => $enq['class_applying_for'],
            'parent_name'     => $enq['parent_name'],
            'parent_email'    => $enq['parent_email'],
            'parent_phone'    => $enq['parent_phone'],
            'address'         => $enq['address'],
            'state'           => $enq['state'] ?? '',
            'lga'             => $enq['lga'] ?? '',
            'previous_school' => $enq['previous_school'] ?? '',
        ];
    }
}

// Generate new unique Admission Number: ADM-YY-XXXX
$currentYearShort = date('y');
$latestAdm = $db->getRow("SELECT admission_number FROM students WHERE admission_number LIKE 'ADM-{$currentYearShort}-%' ORDER BY id DESC LIMIT 1");
$nextSequence = 1001;
if ($latestAdm && preg_match('/ADM-\d+-(\d+)/', $latestAdm['admission_number'], $matches)) {
    $nextSequence = (int)$matches[1] + 1;
}
$defaultAdmNumber = "ADM-{$currentYearShort}-{$nextSequence}";

// Active Classes list
$classes = $db->getRows("SELECT id, class_name, section, academic_year FROM classes WHERE is_active = 1 ORDER BY class_name, section");

// Handle Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flash_redirect('Invalid security token. Please try again.', 'error');
    }

    $admNo        = trim((string)($_POST['admission_number'] ?? '')) ?: $defaultAdmNumber;
    $firstName    = trim((string)($_POST['first_name'] ?? ''));
    $middleName   = trim((string)($_POST['middle_name'] ?? ''));
    $lastName     = trim((string)($_POST['last_name'] ?? ''));
    $fatherName   = trim((string)($_POST['father_name'] ?? ''));
    $motherName   = trim((string)($_POST['mother_name'] ?? ''));
    $dob          = valid_date($_POST['date_of_birth'] ?? '');
    $gender       = in_array($_POST['gender'] ?? '', ['male', 'female', 'other'], true) ? $_POST['gender'] : 'male';
    $bloodGroup   = trim((string)($_POST['blood_group'] ?? ''));
    $ninNumber    = trim((string)($_POST['national_id_number'] ?? ''));
    $penNumber    = trim((string)($_POST['pen_number'] ?? ''));

    $classId      = (int)($_POST['class_id'] ?? 0);
    $section      = trim((string)($_POST['section'] ?? 'A'));
    $status       = in_array($_POST['status'] ?? '', ['active', 'pending'], true) ? $_POST['status'] : 'active';
    $admDate      = valid_date($_POST['admission_date'] ?? '') ?: date('Y-m-d');
    $category     = trim((string)($_POST['category'] ?? 'General'));
    $religion     = trim((string)($_POST['religion'] ?? 'Christian'));
    $nationality  = trim((string)($_POST['nationality'] ?? 'Nigerian'));

    $medium       = trim((string)($_POST['medium'] ?? 'English'));
    $fatherOcc    = trim((string)($_POST['father_occupation'] ?? ''));
    $prevSchool   = trim((string)($_POST['previous_school'] ?? ''));
    $lastClass    = trim((string)($_POST['last_class_studied'] ?? ''));
    $tcNumber     = trim((string)($_POST['tc_number'] ?? ''));

    $phone        = trim((string)($_POST['phone'] ?? ''));
    $altPhone     = trim((string)($_POST['alt_phone'] ?? ''));
    $email        = trim((string)($_POST['email'] ?? ''));
    $address      = trim((string)($_POST['address'] ?? ''));
    $postalCode   = trim((string)($_POST['postal_code'] ?? ''));
    $lga          = trim((string)($_POST['lga'] ?? ''));
    $state        = trim((string)($_POST['state'] ?? ''));
    $transportFee = (float)($_POST['transport_fee'] ?? 0.00);

    $errors = [];
    if ($firstName === '' || $lastName === '') {
        $errors[] = 'Candidate First and Last Name are required.';
    }
    if (!$classId) {
        $errors[] = 'Please select a Class for this student.';
    }
    if (!$dob) {
        $errors[] = 'Please provide a valid Date of Birth.';
    }
    if ($email !== '' && !Security::validateEmail($email)) {
        $errors[] = 'The contact email address format is invalid.';
    }

    // Check duplicate admission number
    $existing = $db->getRow("SELECT id FROM students WHERE admission_number = ?", [$admNo]);
    if ($existing) {
        $errors[] = "Admission number {$admNo} is already in use by another student.";
    }

    if (!empty($errors)) {
        $message = implode(' ', $errors);
        $messageType = 'error';
    } else {
        try {
            $db->beginTransaction();

            // Generate clean username & student user account
            $usernameBase = strtolower(preg_replace('/[^a-z0-9]/i', '', $firstName . '.' . $lastName));
            $username = $usernameBase ?: 'student.' . mt_rand(100, 999);
            $userEmail = $email ?: ('student.' . strtolower($admNo) . '@stbenedicts.edu.ng');

            // Ensure unique username
            $uExists = $db->getRow("SELECT id FROM users WHERE username = ? OR email = ?", [$username, $userEmail]);
            if ($uExists) {
                $username .= '.' . mt_rand(10, 99);
                if ($email === '') {
                    $userEmail = $username . '@stbenedicts.edu.ng';
                }
            }

            $hashedPassword = Security::hashPassword('Student@123'); // Default initial password
            $userId = $db->insert(
                "INSERT INTO users (username, email, password_hash, first_name, last_name, phone, role, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, 'student', ?)",
                [$username, $userEmail, $hashedPassword, $firstName, $lastName, $phone, ($status === 'active' ? 1 : 0)]
            );

            // Handle parent linkage
            $parentId = null;
            $parentFullName = $fatherName ?: ($motherName ?: ($prefill['parent_name'] ?? ''));
            if ($parentFullName !== '') {
                $parentEmail = $email ?: ('parent.' . $username . '@stbenedicts.edu.ng');
                $pUser = $db->getRow("SELECT id FROM users WHERE email = ? AND role = 'parent'", [$parentEmail]);
                if ($pUser) {
                    $pRec = $db->getRow("SELECT id FROM parents WHERE user_id = ?", [$pUser['id']]);
                    $parentId = $pRec['id'] ?? null;
                } else {
                    $pUserId = $db->insert(
                        "INSERT INTO users (username, email, password_hash, first_name, last_name, phone, role, is_active)
                         VALUES (?, ?, ?, ?, 'Parent', ?, 'parent', 1)",
                        ['p.' . $username, $parentEmail, $hashedPassword, $parentFullName, $phone]
                    );
                    $parentId = $db->insert(
                        "INSERT INTO parents (user_id, occupation, address, emergency_contact, relationship)
                         VALUES (?, ?, ?, ?, 'Parent')",
                        [$pUserId, $fatherOcc, $address, $altPhone]
                    );
                }
            }

            // Insert Student Record
            $studentId = $db->insert(
                "INSERT INTO students (
                    user_id, admission_number, class_id, parent_id, date_of_birth, gender,
                    admission_date, address, blood_group, medical_notes,
                    status, category, religion, nationality, medium,
                    national_id_number, pen_number, father_name, mother_name, father_occupation,
                    previous_school, last_class_studied, tc_number, transport_fee, alt_phone, postal_code, lga, state
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $userId, $admNo, $classId, $parentId, $dob, $gender,
                    $admDate, $address, $bloodGroup, '',
                    $status, $category, $religion, $nationality, $medium,
                    $ninNumber, $penNumber, $fatherName, $motherName, $fatherOcc,
                    $prevSchool, $lastClass, $tcNumber, $transportFee, $altPhone, $postalCode, $lga, $state
                ]
            );

            // If converted from enquiry, mark enquiry as accepted
            if ($enquiryId > 0) {
                $db->query("UPDATE admissions SET status = 'accepted', remarks = CONCAT(COALESCE(remarks, ''), ' [Admitted with Adm No: ', ?) WHERE id = ?", [$admNo, $enquiryId]);
            }

            $db->commit();
            Security::logAudit('NEW_STUDENT_ADMISSION', 'students', $studentId, null, [
                'admission_number' => $admNo,
                'name'             => $firstName . ' ' . $lastName,
                'class_id'         => $classId
            ]);

            flash_redirect("Student admitted successfully! Admission Number: {$admNo}", 'success', BASE_URL . '/admin/students');
        } catch (Throwable $e) {
            $db->rollback();
            error_log("new-admission error: " . $e->getMessage());
            $message = 'Error saving student record: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

$pageTitle = 'New Admission - Register Student';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';
dashboard_open('admin', 'New Admission');
render_alert($message, $messageType);
?>

<style>
.admission-form-wrapper {
    max-width: 1100px;
    margin: 0 auto;
}
.admission-top-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 14px;
    margin-bottom: 22px;
}
.adm-title-sub {
    font-size: 0.88rem;
    color: #64748b;
    margin: 3px 0 0;
}
.adm-top-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}
.session-tag-badge {
    background: #e0f2fe;
    color: #0369a1;
    padding: 6px 14px;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.btn-back-students {
    background: #002147;
    color: #ffffff;
    padding: 8px 16px;
    border-radius: 8px;
    text-decoration: none;
    font-size: 0.88rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: background 0.2s;
}
.btn-back-students:hover {
    background: #003366;
    color: #FFD700;
}

/* Card Form Sections */
.adm-section-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.04);
}
.adm-section-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 18px;
    padding-bottom: 12px;
    border-bottom: 1px solid #f1f5f9;
}
.adm-section-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: #eff6ff;
    color: #0066ff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
}
.adm-section-title {
    font-size: 1.05rem;
    font-weight: 700;
    color: #002147;
    margin: 0;
}
.adm-section-title small {
    font-size: 0.8rem;
    color: #64748b;
    font-weight: 400;
}

/* Grid Layouts */
.adm-grid-4 {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
}
.adm-grid-3 {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
}
.adm-grid-2 {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}
.col-span-2 {
    grid-column: span 2;
}

.adm-form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 12px;
}
.adm-form-group label {
    font-size: 0.8rem;
    font-weight: 700;
    color: #334155;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}
.adm-form-group .form-control {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 0.92rem;
    color: #0f172a;
    background: #ffffff;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.adm-form-group .form-control:focus {
    border-color: #0066ff;
    outline: none;
    box-shadow: 0 0 0 3px rgba(0, 102, 255, 0.15);
}
.adm-form-group .form-control[readonly] {
    background: #f8fafc;
    color: #64748b;
}
.adm-input-hint {
    font-size: 0.76rem;
    color: #64748b;
    margin-top: 2px;
}

/* Transport Fee Special Box */
.transport-card {
    background: #fffdf5;
    border-color: #fed7aa;
}
.transport-card .adm-section-icon {
    background: #ffedd5;
    color: #ea580c;
}

/* Action Bar */
.adm-submit-bar {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 14px;
    margin-top: 10px;
    margin-bottom: 40px;
}
.btn-save-admission {
    background: #0066ff;
    color: #ffffff;
    border: none;
    padding: 14px 28px;
    font-size: 0.98rem;
    font-weight: 700;
    border-radius: 10px;
    cursor: pointer;
    box-shadow: 0 4px 14px rgba(0, 102, 255, 0.35);
    display: inline-flex;
    align-items: center;
    gap: 10px;
    transition: background 0.2s, transform 0.1s;
}
.btn-save-admission:hover {
    background: #0052cc;
    transform: translateY(-1px);
}

@media (max-width: 991px) {
    .adm-grid-4, .adm-grid-3, .adm-grid-2 {
        grid-template-columns: 1fr;
    }
    .col-span-2 { grid-column: span 1; }
    .btn-save-admission { width: 100%; justify-content: center; }
}
</style>

<div class="admission-form-wrapper">
    <div class="admission-top-bar">
        <div>
            <h2 style="margin:0; font-size:1.35rem; color:#002147;">New Admission</h2>
            <p class="adm-title-sub">Register a new student into the Academy database</p>
        </div>
        <div class="adm-top-actions">
            <span class="session-tag-badge"><i class="fas fa-calendar-alt"></i> Academic Session: 2026/2027</span>
            <a href="students" class="btn-back-students"><i class="fas fa-arrow-left"></i> Back to Students</a>
        </div>
    </div>

    <form method="POST" action="">
        <?php echo csrf_field(); ?>

        <!-- SECTION 1: Basic Information -->
        <div class="adm-section-card">
            <div class="adm-section-header">
                <div class="adm-section-icon"><i class="fas fa-id-card"></i></div>
                <h3 class="adm-section-title">Basic Information</h3>
            </div>

            <div class="adm-grid-2">
                <div class="adm-form-group">
                    <label for="admission_number">Adm No *</label>
                    <input type="text" id="admission_number" name="admission_number" class="form-control"
                           value="<?php echo e($defaultAdmNumber); ?>" required readonly>
                    <span class="adm-input-hint">Unique official student enrollment ID</span>
                </div>
                <div class="adm-form-group">
                    <label for="enquiry_number">Admission Form No (Optional — From Enquiry)</label>
                    <input type="text" id="enquiry_number" name="enquiry_number" class="form-control"
                           placeholder="e.g. APP-2026-1001"
                           value="<?php echo e($prefill['form_no'] ?? ''); ?>">
                    <span class="adm-input-hint">Enquiry or portal application reference</span>
                </div>
            </div>

            <div class="adm-grid-3">
                <div class="adm-form-group">
                    <label for="first_name">Candidate First Name *</label>
                    <input type="text" id="first_name" name="first_name" class="form-control" required
                           placeholder="First name" value="<?php echo e($prefill['first_name'] ?? ''); ?>">
                </div>
                <div class="adm-form-group">
                    <label for="middle_name">Middle Name</label>
                    <input type="text" id="middle_name" name="middle_name" class="form-control"
                           placeholder="Middle name" value="<?php echo e($prefill['middle_name'] ?? ''); ?>">
                </div>
                <div class="adm-form-group">
                    <label for="last_name">Last Name *</label>
                    <input type="text" id="last_name" name="last_name" class="form-control" required
                           placeholder="Last name" value="<?php echo e($prefill['last_name'] ?? ''); ?>">
                </div>
            </div>

            <div class="adm-grid-2">
                <div class="adm-form-group">
                    <label for="father_name">Father's Name</label>
                    <input type="text" id="father_name" name="father_name" class="form-control"
                           placeholder="Father's full name" value="<?php echo e($prefill['parent_name'] ?? ''); ?>">
                </div>
                <div class="adm-form-group">
                    <label for="mother_name">Mother's Name</label>
                    <input type="text" id="mother_name" name="mother_name" class="form-control"
                           placeholder="Mother's full name">
                </div>
            </div>

            <div class="adm-grid-4">
                <div class="adm-form-group">
                    <label for="date_of_birth">Date of Birth *</label>
                    <input type="date" id="date_of_birth" name="date_of_birth" class="form-control" required
                           value="<?php echo e($prefill['dob'] ?? ''); ?>">
                </div>
                <div class="adm-form-group">
                    <label for="gender">Gender *</label>
                    <select id="gender" name="gender" class="form-control" required>
                        <option value="male" <?php echo ($prefill['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                        <option value="female" <?php echo ($prefill['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="adm-form-group">
                    <label for="blood_group">Blood Group</label>
                    <select id="blood_group" name="blood_group" class="form-control">
                        <option value="">Select Blood Group</option>
                        <?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $bg): ?>
                            <option value="<?php echo $bg; ?>"><?php echo $bg; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="adm-form-group">
                    <label for="national_id_number">NIN / Birth Reg No</label>
                    <input type="text" id="national_id_number" name="national_id_number" class="form-control"
                           placeholder="11-digit NIN or Reg No">
                </div>
            </div>

            <div class="adm-form-group" style="max-width: 50%;">
                <label for="pen_number">PEN No (Optional)</label>
                <input type="text" id="pen_number" name="pen_number" class="form-control" placeholder="Permanent Education Number">
                <span class="adm-input-hint">Unique national or school PEN ID (can be left blank)</span>
            </div>
        </div>

        <!-- SECTION 2: Class & Admission -->
        <div class="adm-section-card">
            <div class="adm-section-header">
                <div class="adm-section-icon" style="background:#f0fdf4; color:#16a34a;"><i class="fas fa-graduation-cap"></i></div>
                <h3 class="adm-section-title">Class &amp; Admission</h3>
            </div>

            <div class="adm-grid-3">
                <div class="adm-form-group">
                    <label for="class_id">Class *</label>
                    <select id="class_id" name="class_id" class="form-control" required>
                        <option value="">Select Class</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?php echo e($c['id']); ?>">
                                <?php echo htmlspecialchars($c['class_name'] . ($c['section'] ? ' - ' . $c['section'] : '')); ?> (<?php echo e($c['academic_year']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="adm-form-group">
                    <label for="section">Section</label>
                    <select id="section" name="section" class="form-control">
                        <option value="A">Section A</option>
                        <option value="B">Section B</option>
                        <option value="C">Section C</option>
                    </select>
                </div>
                <div class="adm-form-group">
                    <label for="status">Enrollment Status *</label>
                    <select id="status" name="status" class="form-control" required>
                        <option value="active" selected>Active</option>
                        <option value="pending">Pending</option>
                    </select>
                </div>
            </div>

            <div class="adm-grid-4">
                <div class="adm-form-group">
                    <label for="admission_date">Admission Date *</label>
                    <input type="date" id="admission_date" name="admission_date" class="form-control" required
                           value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="adm-form-group">
                    <label for="category">Category</label>
                    <select id="category" name="category" class="form-control">
                        <option value="General" selected>General</option>
                        <option value="Early Years">Early Years (EYFS)</option>
                        <option value="Scholarship">Scholarship</option>
                        <option value="Staff Ward">Staff Ward</option>
                    </select>
                </div>
                <div class="adm-form-group">
                    <label for="religion">Religion</label>
                    <select id="religion" name="religion" class="form-control">
                        <option value="Christian" selected>Christian</option>
                        <option value="Catholic">Catholic</option>
                        <option value="Anglican">Anglican</option>
                        <option value="Pentecostal">Pentecostal</option>
                        <option value="Muslim">Muslim</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="adm-form-group">
                    <label for="nationality">Nationality</label>
                    <select id="nationality" name="nationality" class="form-control">
                        <option value="Nigerian" selected>Nigerian</option>
                        <option value="British">British</option>
                        <option value="American">American</option>
                        <option value="Canadian">Canadian</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- SECTION 3: Other Details (Optional) -->
        <div class="adm-section-card">
            <div class="adm-section-header">
                <div class="adm-section-icon" style="background:#f5f3ff; color:#7c3aed;"><i class="fas fa-users-cog"></i></div>
                <h3 class="adm-section-title">Other Details <small>(optional)</small></h3>
            </div>

            <div class="adm-grid-3">
                <div class="adm-form-group">
                    <label for="medium">Medium of Instruction</label>
                    <select id="medium" name="medium" class="form-control">
                        <option value="English" selected>English (British Curriculum)</option>
                        <option value="Bilingual">Bilingual</option>
                    </select>
                </div>
                <div class="adm-form-group">
                    <label for="father_occupation">Father's Occupation</label>
                    <input type="text" id="father_occupation" name="father_occupation" class="form-control"
                           placeholder="e.g. Business, Doctor, Civil Servant">
                </div>
                <div class="adm-form-group">
                    <label for="previous_school">Previous School</label>
                    <input type="text" id="previous_school" name="previous_school" class="form-control"
                           placeholder="Name of last school attended" value="<?php echo e($prefill['previous_school'] ?? ''); ?>">
                </div>
            </div>

            <div class="adm-grid-2">
                <div class="adm-form-group">
                    <label for="last_class_studied">Last Class Studied</label>
                    <input type="text" id="last_class_studied" name="last_class_studied" class="form-control"
                           placeholder="e.g. Nursery / Creche">
                </div>
                <div class="adm-form-group">
                    <label for="tc_number">TC Number (Transfer Certificate)</label>
                    <input type="text" id="tc_number" name="tc_number" class="form-control"
                           placeholder="Transfer Certificate No.">
                </div>
            </div>
        </div>

        <!-- SECTION 4: Contact Details -->
        <div class="adm-section-card">
            <div class="adm-section-header">
                <div class="adm-section-icon" style="background:#ecfdf5; color:#059669;"><i class="fas fa-phone-alt"></i></div>
                <h3 class="adm-section-title">Contact Details</h3>
            </div>

            <div class="adm-grid-3">
                <div class="adm-form-group">
                    <label for="phone">Primary Mobile No *</label>
                    <input type="text" id="phone" name="phone" class="form-control" required
                           placeholder="e.g. 08012345678" value="<?php echo e($prefill['parent_phone'] ?? ''); ?>">
                </div>
                <div class="adm-form-group">
                    <label for="alt_phone">Alt Mobile No (For ID Card)</label>
                    <input type="text" id="alt_phone" name="alt_phone" class="form-control"
                           placeholder="e.g. 08087654321">
                </div>
                <div class="adm-form-group">
                    <label for="email">Parent / Contact Email</label>
                    <input type="email" id="email" name="email" class="form-control"
                           placeholder="parent@example.com" value="<?php echo e($prefill['parent_email'] ?? ''); ?>">
                </div>
            </div>

            <div class="adm-grid-2">
                <div class="adm-form-group">
                    <label for="address">Full Residential Address *</label>
                    <textarea id="address" name="address" rows="2" class="form-control" required
                              placeholder="Full street address"><?php echo e($prefill['address'] ?? ''); ?></textarea>
                </div>
                <div class="adm-form-group">
                    <label for="postal_code">Pin Code / Postal Code</label>
                    <input type="text" id="postal_code" name="postal_code" class="form-control" placeholder="e.g. 400001">
                </div>
            </div>

            <div class="adm-grid-2">
                <div class="adm-form-group">
                    <label for="lga">District / LGA</label>
                    <input type="text" id="lga" name="lga" class="form-control"
                           placeholder="e.g. Enugu North / Trans-Ekulu" value="<?php echo e($prefill['lga'] ?? ''); ?>">
                </div>
                <div class="adm-form-group">
                    <label for="state">State</label>
                    <input type="text" id="state" name="state" class="form-control"
                           placeholder="e.g. Enugu State" value="<?php echo e($prefill['state'] ?? 'Enugu'); ?>">
                </div>
            </div>
        </div>

        <!-- SECTION 5: Transport Fee -->
        <div class="adm-section-card transport-card">
            <div class="adm-section-header">
                <div class="adm-section-icon"><i class="fas fa-bus"></i></div>
                <h3 class="adm-section-title">Transport Fee</h3>
            </div>

            <div class="adm-form-group" style="max-width: 400px;">
                <label for="transport_fee">Transport Fee Amount (Yearly Total)</label>
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="font-size:1.1rem; font-weight:700; color:#002147;">&#8358;</span>
                    <input type="number" step="0.01" min="0" id="transport_fee" name="transport_fee" class="form-control" value="0.00">
                </div>
                <span class="adm-input-hint"><i class="fas fa-info-circle"></i> Enter the total transport fee for the full year (based on route). Enter 0 for no transport.</span>
            </div>
        </div>

        <!-- Submit Bar -->
        <div class="adm-submit-bar">
            <a href="students" class="btn btn-outline">Cancel</a>
            <button type="submit" class="btn-save-admission">
                <i class="fas fa-check-circle"></i> Save &amp; Admit Student
            </button>
        </div>
    </form>
</div>

<?php
dashboard_close();
include __DIR__ . '/../includes/footer.php';
