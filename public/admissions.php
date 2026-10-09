<?php
// public/admissions.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';

$pageTitle = 'Admissions - Apply to ST. BENEDICT\'S EARLY YEARS BRITISH ACADEMY';
$pageDescription = 'Apply for admission to ST. BENEDICT\'S EARLY YEARS BRITISH ACADEMY. Learn about our admission requirements, process, and start your child\'s educational journey with us.';
$extraJS = ['admissions.js'];

// Set meta tags for SEO
$metaTags = [
    'og:title' => 'Admissions - ST. BENEDICT\'S EARLY YEARS BRITISH ACADEMY',
    'og:description' => 'Apply online for admission to our British Early Years program. Start your child\'s journey with us today.',
    'og:image' => BASE_URL . '/assets/images/og-image.jpg',
    'og:url' => BASE_URL . '/public/admissions.php',
    'twitter:card' => 'summary_large_image'
];

include __DIR__ . '/../includes/header.php';

try {
    $db = Database::getInstance();
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    $db = null;
}

$message = '';
$messageType = '';
$applied = null;

$documentTypes = [
    'birth_certificate' => ['Birth Certificate', ['pdf', 'jpg', 'jpeg', 'png'], true],
    'passport_photo' => ['Passport Photograph', ['jpg', 'jpeg', 'png'], true],
    'immunization_record' => ['Immunization Record', ['pdf', 'jpg', 'jpeg', 'png'], false],
    'previous_report' => ['Previous School Report', ['pdf', 'jpg', 'jpeg', 'png'], false],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    $stored = [];
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session expired. Please reload the page and try again.';
    } elseif (!empty($_POST['website'])) {
        $messageType = 'success'; // honeypot: bots get a fake success and nothing is stored
    } elseif (($_SESSION['admission_attempts'][date('YmdH')] ?? 0) >= 5) {
        $errors[] = 'Too many submissions from this device. Please try again later or call the school office.';
    } elseif (!$db) {
        $errors[] = 'We could not process your application right now. Please try again later.';
    } else {
        $_SESSION['admission_attempts'] = [date('YmdH') => ($_SESSION['admission_attempts'][date('YmdH')] ?? 0) + 1];
        $t = function ($k, $max = 100) { return mb_substr(Security::sanitize($_POST[$k] ?? ''), 0, $max); };
        $firstName = $t('first_name', 50);
        $middleName = $t('middle_name', 50);
        $lastName = $t('last_name', 50);
        $dob = valid_date($_POST['dob'] ?? '');
        $gender = $_POST['gender'] ?? '';
        $class = $t('class', 50);
        $parentName = $t('parent_name', 100);
        $parentEmail = $t('parent_email', 100);
        $parentPhone = $t('parent_phone', 20);
        $address = $t('address', 500);
        $previousSchool = $t('previous_school', 200);

        if ($firstName === '') $errors[] = 'First name is required';
        if ($lastName === '') $errors[] = 'Last name is required';
        if (!$dob || $dob > date('Y-m-d')) $errors[] = 'A valid date of birth is required';
        if (!in_array($gender, ['male', 'female', 'other'], true)) $errors[] = 'Gender is required';
        if ($class === '') $errors[] = 'Class is required';
        if ($parentName === '') $errors[] = 'Parent name is required';
        if (!Security::validateEmail($parentEmail)) $errors[] = 'A valid parent email is required';
        if (!Security::validatePhone($parentPhone)) $errors[] = 'A valid Nigerian phone number is required (e.g., 08012345678)';
        if ($address === '') $errors[] = 'Address is required';

        if (!$errors) {
            $privateDir = PRIVATE_PATH . 'applications/';
            if (!is_dir($privateDir)) { @mkdir($privateDir, 0750, true); }
            foreach ($documentTypes as $key => [$label, $allowed, $required]) {
                $has = isset($_FILES[$key]) && $_FILES[$key]['error'] !== UPLOAD_ERR_NO_FILE;
                if (!$has) {
                    if ($required) $errors[] = "$label is required";
                    continue;
                }
                $check = Security::validateFileUpload($_FILES[$key], $allowed);
                if (!$check['valid']) { $errors[] = "$label: " . $check['message']; continue; }
                $name = $key . '_' . bin2hex(random_bytes(12)) . '.' . $check['extension'];
                if (move_uploaded_file($_FILES[$key]['tmp_name'], $privateDir . $name)) {
                    @chmod($privateDir . $name, 0640);
                    $stored[$key] = $name;
                } else {
                    $errors[] = "Failed to upload $label";
                }
            }
        }

        if (!$errors) {
            try {
                $appNumber = generateApplicationNumber();
                $db->insert(
                    "INSERT INTO admissions (application_number, first_name, middle_name, last_name, date_of_birth, gender, class_applying_for, parent_name, parent_email, parent_phone, address, previous_school, documents_path, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                    [$appNumber, $firstName, $middleName ?: null, $lastName, $dob, $gender, $class, $parentName, $parentEmail, $parentPhone, $address, $previousSchool ?: null, json_encode($stored)]
                );

                $fullName = trim($firstName . ' ' . $middleName . ' ' . $lastName);
                $docList = '';
                foreach ($stored as $key => $_) { $docList .= '<li>&#10003; ' . e($documentTypes[$key][0]) . '</li>'; }
                sendEmail($parentEmail, 'Application Received - ' . SCHOOL_NAME,
                    "<div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto'>"
                    . "<h2 style='background:#002855;color:#fff;padding:16px;text-align:center'>Application Received</h2>"
                    . "<p>Dear <strong>" . e($parentName) . "</strong>,</p>"
                    . "<p>Thank you for applying to <strong>" . e(SCHOOL_NAME) . "</strong> for <strong>" . e($fullName) . "</strong> (" . e($class) . ").</p>"
                    . "<p style='font-size:20px;text-align:center;background:#ffd700;padding:12px'><strong>" . e($appNumber) . "</strong></p>"
                    . "<p><strong>Documents received:</strong></p><ul>$docList</ul>"
                    . "<ol><li>We will review your application within 3-5 working days.</li><li>You will be contacted to schedule an assessment.</li><li>You will then receive the admission decision.</li></ol>"
                    . "<p>Questions? Call " . e(SCHOOL_PHONE) . ".</p><p><strong>Admissions Team</strong></p></div>");
                sendEmail(SCHOOL_EMAIL, "New Admission Application - $appNumber",
                    "<h2>New Admission Application</h2><p><strong>Number:</strong> " . e($appNumber) . "</p><p><strong>Child:</strong> " . e($fullName)
                    . " (" . e($dob) . ", " . e($gender) . ")</p><p><strong>Class:</strong> " . e($class) . "</p><p><strong>Parent:</strong> " . e($parentName)
                    . " &middot; " . e($parentEmail) . " &middot; " . e($parentPhone) . "</p><p><a href='" . e(BASE_URL) . "/admin/applications.php'>Review in the admin panel</a></p>");

                $applied = ['number' => $appNumber, 'email' => $parentEmail];
                $messageType = 'success';
                $_POST = [];
            } catch (Exception $e) {
                foreach ($stored as $f) { @unlink(PRIVATE_PATH . 'applications/' . $f); }
                error_log("Admission application error: " . $e->getMessage());
                $errors[] = 'An error occurred while submitting your application. Please try again later or contact us directly.';
            }
        } else {
            foreach ($stored as $f) { @unlink(PRIVATE_PATH . 'applications/' . $f); }
        }
    }
    if ($errors) {
        $messageType = 'error';
        $message = implode("\n", $errors);
    }
}

// Get list of classes for dropdown with error handling
$classes = [];
if ($db) {
    try {
        $classes = $db->getRows("SELECT id, class_name, section FROM classes WHERE is_active = 1 ORDER BY class_name, section");
    } catch (Exception $e) {
        error_log("Error fetching classes: " . $e->getMessage());
    }
}

// Fallback classes if database fails
if (empty($classes)) {
    $classes = [
        ['id' => 1, 'class_name' => 'Nursery 1', 'section' => 'A'],
        ['id' => 2, 'class_name' => 'Nursery 2', 'section' => 'A'],
        ['id' => 3, 'class_name' => 'Reception', 'section' => 'A'],
        ['id' => 4, 'class_name' => 'Year 1', 'section' => 'A'],
        ['id' => 5, 'class_name' => 'Year 2', 'section' => 'A']
    ];
}
?>

<!-- Page-Specific Styles -->
<style>
/* ===== PAGE HEADER ===== */
.page-header {
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-dark) 100%);
    color: var(--white);
    padding: var(--spacing-3xl) 0;
    text-align: center;
    position: relative;
    overflow: hidden;
}

.page-header::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    animation: rotate 60s linear infinite;
}

.page-header h1 {
    color: var(--white);
    font-size: var(--text-4xl);
    margin-bottom: var(--spacing-sm);
    position: relative;
    display: inline-block;
}

.page-header h1::after {
    content: '';
    position: absolute;
    bottom: -10px;
    left: 50%;
    transform: translateX(-50%);
    width: 60px;
    height: 3px;
    background-color: var(--gold);
}

.breadcrumb {
    color: rgba(255, 255, 255, 0.8);
    font-size: var(--text-sm);
    position: relative;
}

.breadcrumb a {
    color: var(--gold);
    text-decoration: none;
    transition: color var(--transition-fast);
}

.breadcrumb a:hover {
    color: var(--white);
    text-decoration: underline;
}

/* ===== ADMISSION INFO SECTION ===== */
.admission-info {
    padding: var(--spacing-2xl) 0;
    background: var(--white);
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: var(--spacing-xl);
}

.info-card {
    padding: var(--spacing-xl);
    background: var(--white);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    transition: all var(--transition-normal);
    border-bottom: 3px solid transparent;
    height: 100%;
}

.info-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-xl);
    border-bottom-color: var(--gold);
}

.info-card i {
    font-size: 2.5rem;
    color: var(--gold);
    margin-bottom: var(--spacing-md);
}

.info-card h3 {
    color: var(--navy);
    margin-bottom: var(--spacing-lg);
    font-size: var(--text-xl);
    position: relative;
    padding-bottom: var(--spacing-sm);
}

.info-card h3::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 40px;
    height: 2px;
    background: linear-gradient(90deg, var(--navy), var(--gold));
}

.info-card ul,
.info-card ol {
    margin: 0;
    padding-left: var(--spacing-md);
}

.info-card li {
    margin-bottom: var(--spacing-sm);
    color: var(--dark-gray);
    line-height: 1.6;
}

.info-card li::marker {
    color: var(--gold);
}

.info-card a {
    color: var(--red);
    text-decoration: none;
    transition: color var(--transition-fast);
    display: inline-flex;
    align-items: center;
    gap: var(--spacing-xs);
}

.info-card a:hover {
    color: var(--navy);
    text-decoration: underline;
}

.info-card a i {
    font-size: var(--text-sm);
    margin: 0;
}

/* ===== APPLICATION FORM SECTION ===== */
.application-form {
    padding: var(--spacing-2xl) 0;
    background: linear-gradient(135deg, var(--light-gray) 0%, var(--white) 100%);
}

.section-title {
    text-align: center;
    font-size: var(--text-3xl);
    margin-bottom: var(--spacing-xl);
    color: var(--navy);
    position: relative;
    padding-bottom: var(--spacing-md);
}

.section-title::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 80px;
    height: 3px;
    background: linear-gradient(90deg, var(--navy), var(--gold), var(--red));
    border-radius: var(--radius-full);
}

.form-container {
    max-width: 900px;
    margin: 0 auto;
    background: var(--white);
    border-radius: var(--radius-xl);
    padding: var(--spacing-2xl);
    box-shadow: var(--shadow-xl);
}

.form-section {
    margin-bottom: var(--spacing-2xl);
    padding: var(--spacing-xl);
    background: var(--light-gray);
    border-radius: var(--radius-lg);
    border-left: 4px solid var(--gold);
}

.form-section h3 {
    color: var(--navy);
    margin-bottom: var(--spacing-lg);
    font-size: var(--text-xl);
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.form-section h3 i {
    color: var(--gold);
    font-size: 1.5rem;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: var(--spacing-md);
}

.form-group {
    margin-bottom: var(--spacing-md);
}

.form-group.full-width {
    grid-column: span 2;
}

.form-group label {
    display: block;
    margin-bottom: var(--spacing-xs);
    font-weight: 500;
    color: var(--navy);
    font-size: var(--text-sm);
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 2px solid var(--medium-gray);
    border-radius: var(--radius-md);
    font-family: var(--font-primary);
    font-size: var(--text-md);
    transition: all var(--transition-fast);
    background: var(--white);
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: var(--gold);
    box-shadow: 0 0 0 3px rgba(255, 215, 0, 0.1);
}

.form-group input.error,
.form-group select.error,
.form-group textarea.error {
    border-color: var(--danger);
}

.form-group small {
    display: block;
    margin-top: var(--spacing-xs);
    color: var(--gray);
    font-size: var(--text-xs);
}

/* Document Upload Section */
.document-upload-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: var(--spacing-lg);
    margin-top: var(--spacing-md);
}

.document-item {
    background: var(--white);
    padding: var(--spacing-lg);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--medium-gray);
    transition: all var(--transition-fast);
}

.document-item:hover {
    border-color: var(--gold);
    box-shadow: var(--shadow-md);
}

.document-item.required {
    border-left: 4px solid var(--red);
}

.document-header {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    margin-bottom: var(--spacing-md);
}

.document-header i {
    font-size: 1.5rem;
    color: var(--gold);
}

.document-header h4 {
    color: var(--navy);
    margin: 0;
    font-size: var(--text-md);
}

.document-required-badge {
    background: var(--red);
    color: white;
    font-size: var(--text-xs);
    padding: 2px 8px;
    border-radius: 12px;
    margin-left: auto;
}

.document-optional-badge {
    background: var(--gray);
    color: white;
    font-size: var(--text-xs);
    padding: 2px 8px;
    border-radius: 12px;
    margin-left: auto;
}

.document-item .file-info {
    margin-top: var(--spacing-sm);
    padding: var(--spacing-sm);
    background: var(--light-gray);
    border-radius: var(--radius-sm);
    font-size: var(--text-xs);
    color: var(--success);
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
}

.document-item .file-info i {
    font-size: var(--text-sm);
}

/* Checkbox Label */
.checkbox-label {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    cursor: pointer;
    font-size: var(--text-sm);
    color: var(--dark-gray);
}

.checkbox-label input[type="checkbox"] {
    width: auto;
    cursor: pointer;
    accent-color: var(--navy);
}

/* Form Actions */
.form-actions {
    display: flex;
    gap: var(--spacing-md);
    justify-content: flex-end;
    margin-top: var(--spacing-xl);
}

/* Alert Styles */
.alert {
    padding: var(--spacing-md) var(--spacing-lg);
    margin-bottom: var(--spacing-lg);
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    animation: slideDown var(--transition-normal);
}

.alert-success {
    background-color: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert-error {
    background-color: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.alert-info {
    background-color: #d1ecf1;
    color: #0c5460;
    border: 1px solid #bee5eb;
}

.alert ul {
    margin: 0;
    padding-left: var(--spacing-md);
}

.alert li {
    margin-bottom: var(--spacing-xs);
}

/* Button Styles */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--spacing-sm);
    padding: 0.75rem 1.5rem;
    border-radius: var(--radius-md);
    font-weight: 500;
    cursor: pointer;
    transition: all var(--transition-fast);
    border: 2px solid transparent;
    text-decoration: none;
    font-size: var(--text-md);
}

.btn-primary {
    background-color: var(--red);
    color: var(--white);
}

.btn-primary:hover {
    background-color: var(--red-dark);
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.btn-outline {
    background-color: transparent;
    border-color: var(--navy);
    color: var(--navy);
}

.btn-outline:hover {
    background-color: var(--navy);
    color: var(--white);
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.btn-large {
    padding: 1rem 2rem;
    font-size: var(--text-lg);
}

/* Animations */
@keyframes rotate {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Responsive Styles */
@media (max-width: 992px) {
    .info-grid {
        grid-template-columns: 1fr;
        max-width: 600px;
        margin: 0 auto;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .form-group.full-width {
        grid-column: auto;
    }

    .document-upload-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .page-header h1 {
        font-size: var(--text-2xl);
    }

    .section-title {
        font-size: var(--text-2xl);
    }

    .form-container {
        padding: var(--spacing-lg);
    }

    .form-section {
        padding: var(--spacing-lg);
    }

    .form-actions {
        flex-direction: column;
    }

    .form-actions .btn {
        width: 100%;
    }

    .info-card {
        padding: var(--spacing-lg);
    }
}

@media (max-width: 576px) {
    .form-container {
        padding: var(--spacing-md);
    }

    .form-section {
        padding: var(--spacing-md);
    }

    .form-section h3 {
        font-size: var(--text-lg);
    }

    .checkbox-label {
        font-size: var(--text-xs);
    }

    .document-item {
        padding: var(--spacing-md);
    }
}

/* Loading State */
.btn-loading {
    position: relative;
    pointer-events: none;
    opacity: 0.7;
}

.btn-loading::after {
    content: '';
    position: absolute;
    width: 20px;
    height: 20px;
    top: 50%;
    left: 50%;
    margin-left: -10px;
    margin-top: -10px;
    border: 2px solid rgba(255,255,255,0.3);
    border-top-color: white;
    border-radius: 50%;
    animation: spinner 0.6s linear infinite;
}

@keyframes spinner {
    to { transform: rotate(360deg); }
}
</style>

<!-- Page Header -->
<section class="page-header">
    <div class="container">
        <h1>Admissions</h1>
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>/index.php">Home</a> / Admissions
        </div>
    </div>
</section>

<!-- Admission Information -->
<section class="admission-info">
    <div class="container">
        <div class="info-grid">
            <div class="info-card animate-fade-in">
                <i class="fas fa-file-alt"></i>
                <h3>Admission Requirements</h3>
                <ul>
                    <li>Birth certificate (original & copy) - <strong>Required</strong></li>
                    <li>Recent passport photographs (2) - <strong>Required</strong></li>
                    <li>Immunization records - <strong>Optional</strong></li>
                    <li>Previous school report (if applicable)</li>
                    <li>Completed application form</li>
                    <li>Non-refundable application fee</li>
                </ul>
            </div>

            <div class="info-card animate-fade-in" style="animation-delay: 0.2s;">
                <i class="fas fa-clock"></i>
                <h3>Admission Process</h3>
                <ol>
                    <li>Submit online application</li>
                    <li>Pay application fee</li>
                    <li>Schedule assessment/visit</li>
                    <li>Child assessment (for Year 1-2)</li>
                    <li>Admission decision</li>
                    <li>Acceptance and enrollment</li>
                </ol>
            </div>

            <div class="info-card animate-fade-in" style="animation-delay: 0.4s;">
                <i class="fas fa-download"></i>
                <h3>Downloads</h3>
                <p><a href="<?php echo BASE_URL; ?>/uploads/prospectus.pdf" target="_blank">
                    <i class="fas fa-file-pdf"></i> School Prospectus
                </a></p>
                <p><a href="<?php echo BASE_URL; ?>/uploads/fee-structure.pdf" target="_blank">
                    <i class="fas fa-file-pdf"></i> Fee Structure
                </a></p>
                <p><a href="<?php echo BASE_URL; ?>/uploads/application-form.pdf" target="_blank">
                    <i class="fas fa-file-pdf"></i> Application Form
                </a></p>
            </div>
        </div>
    </div>
</section>

<!-- Application Form -->
<section class="application-form">
    <div class="container">
        <h2 class="section-title">Online Application</h2>

        <?php if ($messageType === 'error' && $message): ?>
        <div class="alert alert-error" role="alert">
            <ul style="margin:0;padding-left:18px"><?php foreach (explode("\n", $message) as $line): ?><li><?php echo e($line); ?></li><?php endforeach; ?></ul>
        </div>
        <?php endif; ?>

        <?php if ($messageType === 'success'): ?>
        <div class="alert alert-success" style="text-align:center">
            <i class="fas fa-check-circle" style="font-size:4rem;color:#28a745;margin-bottom:20px"></i>
            <h3>Application Submitted Successfully!</h3>
            <?php if ($applied): ?>
            <p>Your application number is: <strong style="font-size:1.5rem;color:#c41e3a"><?php echo e($applied['number']); ?></strong></p>
            <p>We have sent a confirmation email to <strong><?php echo e($applied['email']); ?></strong>.</p>
            <?php endif; ?>
            <p>Our admissions team will contact you within 3-5 working days.</p>
            <div style="margin-top:24px">
                <a href="<?php echo BASE_URL; ?>/index.php" class="btn btn-primary">Return to Home</a>
                <a href="admissions.php" class="btn btn-outline">Submit Another Application</a>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($messageType !== 'success'): ?>
        <form method="POST" action="" enctype="multipart/form-data" class="form-container" id="admissionForm">
            <?php echo csrf_field(); ?>
            <div style="position:absolute;left:-9999px" aria-hidden="true"><label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

            <div class="form-section">
                <h3><i class="fas fa-child"></i> Child's Information</h3>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="first_name">First Name *</label>
                        <input type="text" id="first_name" name="first_name"
                               value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>"
                               placeholder="Enter child's first name" required>
                    </div>

                    <div class="form-group">
                        <label for="middle_name">Middle Name</label>
                        <input type="text" id="middle_name" name="middle_name"
                               value="<?php echo htmlspecialchars($_POST['middle_name'] ?? ''); ?>"
                               placeholder="Enter child's middle name (optional)">
                    </div>

                    <div class="form-group">
                        <label for="last_name">Last Name *</label>
                        <input type="text" id="last_name" name="last_name"
                               value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>"
                               placeholder="Enter child's last name" required>
                    </div>

                    <div class="form-group">
                        <label for="dob">Date of Birth *</label>
                        <input type="date" id="dob" name="dob"
                               value="<?php echo htmlspecialchars($_POST['dob'] ?? ''); ?>"
                               required>
                        <small>Enter child's date of birth</small>
                    </div>

                    <div class="form-group">
                        <label for="gender">Gender *</label>
                        <select id="gender" name="gender" required>
                            <option value="">Select Gender</option>
                            <option value="male" <?php echo (($_POST['gender'] ?? '') == 'male') ? 'selected' : ''; ?>>Male</option>
                            <option value="female" <?php echo (($_POST['gender'] ?? '') == 'female') ? 'selected' : ''; ?>>Female</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="class">Class Applying For *</label>
                        <select id="class" name="class" required>
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo htmlspecialchars($class['class_name'] . ' ' . ($class['section'] ?? '')); ?>"
                                <?php echo (($_POST['class'] ?? '') == ($class['class_name'] . ' ' . ($class['section'] ?? ''))) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name'] . ' ' . ($class['section'] ?? '')); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="previous_school">Previous School (if any)</label>
                        <input type="text" id="previous_school" name="previous_school"
                               value="<?php echo htmlspecialchars($_POST['previous_school'] ?? ''); ?>"
                               placeholder="Name of previous school">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h3><i class="fas fa-users"></i> Parent/Guardian Information</h3>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="parent_name">Parent/Guardian Full Name *</label>
                        <input type="text" id="parent_name" name="parent_name"
                               value="<?php echo htmlspecialchars($_POST['parent_name'] ?? ''); ?>"
                               placeholder="Enter parent's full name" required>
                    </div>

                    <div class="form-group">
                        <label for="parent_email">Email Address *</label>
                        <input type="email" id="parent_email" name="parent_email"
                               value="<?php echo htmlspecialchars($_POST['parent_email'] ?? ''); ?>"
                               placeholder="parent@example.com" required>
                    </div>

                    <div class="form-group">
                        <label for="parent_phone">Phone Number *</label>
                        <input type="tel" id="parent_phone" name="parent_phone"
                               value="<?php echo htmlspecialchars($_POST['parent_phone'] ?? ''); ?>"
                               placeholder="08012345678" required>
                        <small>Nigerian mobile number (e.g., 08012345678)</small>
                    </div>

                    <div class="form-group full-width">
                        <label for="address">Home Address *</label>
                        <textarea id="address" name="address" rows="3"
                                  placeholder="Enter your complete home address" required><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h3><i class="fas fa-file-upload"></i> Required Documents</h3>
                <p style="margin-bottom: var(--spacing-lg); color: var(--gray);">Please upload the following documents. Files must be in PDF, JPG, or PNG format (max 5MB each).</p>

                <div class="document-upload-grid">
                    <!-- Birth Certificate - Required -->
                    <div class="document-item required">
                        <div class="document-header">
                            <i class="fas fa-baby"></i>
                            <h4>Birth Certificate</h4>
                            <span class="document-required-badge">Required</span>
                        </div>
                        <input type="file" id="birth_certificate" name="birth_certificate" accept=".pdf,.jpg,.jpeg,.png" required>
                        <div id="birth-certificate-info" class="file-info"></div>
                    </div>

                    <!-- Passport Photograph - Required -->
                    <div class="document-item required">
                        <div class="document-header">
                            <i class="fas fa-camera-retro"></i>
                            <h4>Passport Photograph</h4>
                            <span class="document-required-badge">Required</span>
                        </div>
                        <input type="file" id="passport_photo" name="passport_photo" accept=".jpg,.jpeg,.png" required>
                        <div id="passport-photo-info" class="file-info"></div>
                    </div>

                    <!-- Immunization Record - Optional -->
                    <div class="document-item">
                        <div class="document-header">
                            <i class="fas fa-syringe"></i>
                            <h4>Immunization Record</h4>
                            <span class="document-optional-badge">Optional</span>
                        </div>
                        <input type="file" id="immunization_record" name="immunization_record" accept=".pdf,.jpg,.jpeg,.png">
                        <div id="immunization-record-info" class="file-info"></div>
                    </div>

                    <!-- Previous School Report - Optional -->
                    <div class="document-item">
                        <div class="document-header">
                            <i class="fas fa-school"></i>
                            <h4>Previous School Report</h4>
                            <span class="document-optional-badge">Optional</span>
                        </div>
                        <input type="file" id="previous_report" name="previous_report" accept=".pdf,.jpg,.jpeg,.png">
                        <div id="previous-report-info" class="file-info"></div>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="terms" id="terms" required>
                    <span>I confirm that the information provided is accurate and I have read the admission requirements.</span>
                </label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-large" id="submitBtn">
                    <i class="fas fa-paper-plane"></i> Submit Application
                </button>
                <button type="reset" class="btn btn-outline" id="resetBtn">
                    <i class="fas fa-undo"></i> Reset Form
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('admissionForm');
    const submitBtn = document.getElementById('submitBtn');
    const resetBtn = document.getElementById('resetBtn');

    // File input elements
    const fileInputs = {
        birth_certificate: document.getElementById('birth_certificate'),
        passport_photo: document.getElementById('passport_photo'),
        immunization_record: document.getElementById('immunization_record'),
        previous_report: document.getElementById('previous_report')
    };

    // File info containers
    const fileInfoContainers = {
        birth_certificate: document.getElementById('birth-certificate-info'),
        passport_photo: document.getElementById('passport-photo-info'),
        immunization_record: document.getElementById('immunization-record-info'),
        previous_report: document.getElementById('previous-report-info')
    };

    // Add file change listeners
    for (const [key, input] of Object.entries(fileInputs)) {
        if (input) {
            input.addEventListener('change', function(e) {
                handleFileSelect(e, key);
            });
        }
    }

    function handleFileSelect(e, fileKey) {
        const file = e.target.files[0];
        const infoContainer = fileInfoContainers[fileKey];

        if (!infoContainer) return;

        if (file) {
            // Check file size
            if (file.size > 5 * 1024 * 1024) {
                alert('File size must be less than 5MB');
                e.target.value = '';
                infoContainer.innerHTML = '';
                return;
            }

            // Check file type
            const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
            if (!allowedTypes.includes(file.type)) {
                alert('File type not allowed. Please upload PDF, JPG, or PNG files.');
                e.target.value = '';
                infoContainer.innerHTML = '';
                return;
            }

            // Show file info
            infoContainer.innerHTML = `<i class="fas fa-check-circle"></i> Selected: ${file.name} (${(file.size / 1024).toFixed(2)} KB)`;
        } else {
            infoContainer.innerHTML = '';
        }
    }

    if (form) {
        form.addEventListener('submit', function(e) {
            // Show loading state
            submitBtn.classList.add('btn-loading');
            submitBtn.disabled = true;

            // Validate required files
            if (!fileInputs.birth_certificate.files[0]) {
                e.preventDefault();
                alert('Birth certificate is required');
                submitBtn.classList.remove('btn-loading');
                submitBtn.disabled = false;
                return;
            }

            if (!fileInputs.passport_photo.files[0]) {
                e.preventDefault();
                alert('Passport photograph is required');
                submitBtn.classList.remove('btn-loading');
                submitBtn.disabled = false;
                return;
            }

            // Validate phone number
            const phone = document.getElementById('parent_phone').value;
            const phoneRegex = /^0[789][01]\d{8}$/;
            if (phone && !phoneRegex.test(phone)) {
                e.preventDefault();
                alert('Please enter a valid Nigerian phone number (e.g., 08012345678)');
                submitBtn.classList.remove('btn-loading');
                submitBtn.disabled = false;
                return;
            }
        });
    }

    // Reset button
    if (resetBtn) {
        resetBtn.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to reset the form? All entered data and uploaded files will be lost.')) {
                e.preventDefault();
            } else {
                // Clear file info containers
                for (const container of Object.values(fileInfoContainers)) {
                    if (container) container.innerHTML = '';
                }
            }
        });
    }

    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            if (alert && !alert.classList.contains('alert-success')) {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(function() {
                    if (alert && alert.parentNode) {
                        alert.remove();
                    }
                }, 500);
            }
        });
    }, 5000);

    // Add animation classes
    document.querySelectorAll('.info-card, .form-section, .document-item').forEach(function(el, index) {
        el.style.animation = `fadeIn 0.6s ease ${index * 0.1}s both`;
    });
});
</script>

<?php
// Check if footer exists
$footerPath = __DIR__ . '/../includes/footer.php';
if (file_exists($footerPath)) {
    include $footerPath;
} else {
    echo "<!-- Footer file not found -->";
}
?>