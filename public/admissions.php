<?php
// public/admissions.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/ApplicationHelper.php';
require_once __DIR__ . '/../includes/application-components.php';

ApplicationHelper::ensureSchema();

$pageTitle = 'Admissions - Apply to ST. BENEDICT\'S EARLY YEARS BRITISH ACADEMY';
$pageDescription = 'Apply for admission to ST. BENEDICT\'S EARLY YEARS BRITISH ACADEMY. Learn about our admission requirements, process, and start your child\'s educational journey with us.';
$extraJS = ['admissions.js', 'location-picker.js'];

// Set meta tags for SEO
$metaTags = [
    'og:title' => 'Admissions - ST. BENEDICT\'S EARLY YEARS BRITISH ACADEMY',
    'og:description' => 'Apply online for admission to our British Early Years program. Start your child\'s journey with us today.',
    'og:image' => BASE_URL . '/assets/images/og-image.jpg',
    'og:url' => BASE_URL . '/public/admissions',
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

$catalog = ApplicationHelper::getDocumentCatalog();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    $stored = [];
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session expired. Please reload the page and try again.';
    } else {
        $ts = turnstile_verify();
        if (!$ts['success']) {
            $errors[] = $ts['error'];
        }
    }
    if (!empty($_POST['website'])) {
        $messageType = 'success'; // honeypot: bots get a fake success and nothing is stored
    } elseif (($_SESSION['admission_attempts'][date('YmdH')] ?? 0) >= 8) {
        $errors[] = 'Too many submissions from this device. Please try again later or call the school office.';
    } elseif (!$db) {
        $errors[] = 'We could not process your application right now. Please try again later.';
    } else {
        $_SESSION['admission_attempts'] = [date('YmdH') => ($_SESSION['admission_attempts'][date('YmdH')] ?? 0) + 1];
        $t = function ($k, $max = 100) { return mb_substr(Security::sanitize($_POST[$k] ?? ''), 0, $max); };

        $firstName          = $t('first_name', 50);
        $middleName         = $t('middle_name', 50);
        $lastName           = $t('last_name', 50);
        $dob                = valid_date($_POST['dob'] ?? '');
        $gender             = $_POST['gender'] ?? '';
        $class              = $t('class', 50);
        $academicSession    = $t('academic_session', 30) ?: '2026/2027';
        $entryTerm          = $t('entry_term', 50) ?: 'First Term';
        $bloodGroup         = $t('blood_group', 10);
        $genotype           = $t('genotype', 10);
        $medicalConditions  = $t('medical_conditions', 500);
        $previousSchool     = $t('previous_school', 200);

        $parentTitle        = $t('parent_title', 20);
        $parentName         = $t('parent_name', 100);
        $parentRelationship = $t('parent_relationship', 50) ?: 'Parent';
        $parentOccupation   = $t('parent_occupation', 100);
        $parentEmail        = $t('parent_email', 100);
        $parentPhone        = $t('parent_phone', 20);
        $address            = $t('address', 500);
        $city               = $t('city', 100);

        $emergencyName      = $t('emergency_name', 100);
        $emergencyPhone     = $t('emergency_phone', 25);
        $emergencyRel       = $t('emergency_relationship', 50);

        $paymentMethod      = $t('payment_method', 50) ?: 'online_card';
        $paymentReference   = $t('payment_reference', 80) ?: ApplicationHelper::generatePaymentReference();
        $amountPaid         = ApplicationHelper::APPLICATION_FEE;
        $paymentStatus      = ($paymentMethod === 'bank_transfer') ? 'pending_verification' : 'paid';

        if ($firstName === '') $errors[] = 'First name is required';
        if ($lastName === '') $errors[] = 'Last name is required';
        if (!$dob || $dob > date('Y-m-d')) $errors[] = 'A valid date of birth is required';
        if (!in_array($gender, ['male', 'female', 'other'], true)) $errors[] = 'Gender is required';
        if ($class === '') $errors[] = 'Class applying for is required';
        if ($parentName === '') $errors[] = 'Parent/Guardian name is required';
        if (!Security::validateEmail($parentEmail)) $errors[] = 'A valid parent email is required';
        $loc = location_resolve($_POST);
        if (!$loc['ok']) $errors[] = $loc['error'];
        $abroad = ($_POST['state'] ?? '') === LOC_OTHER;
        if ($abroad ? !valid_phone_intl($parentPhone) : !Security::validatePhone($parentPhone)) {
            $errors[] = $abroad ? 'A valid phone number with country code is required (e.g., +447911123456)' : 'A valid Nigerian phone number is required (e.g., 08012345678)';
        }
        if ($address === '') $errors[] = 'Home address is required';

        if (!$errors) {
            $privateDir = PRIVATE_PATH . 'applications/';
            if (!is_dir($privateDir)) { @mkdir($privateDir, 0750, true); }
            foreach ($catalog as $key => $meta) {
                $label = $meta['short'];
                $allowed = $meta['allowed'];
                $required = $meta['required'];

                $has = isset($_FILES[$key]) && $_FILES[$key]['error'] !== UPLOAD_ERR_NO_FILE;
                if (!$has) {
                    if ($required) $errors[] = "$label is required (select it in the Required Documents dropdown and upload)";
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
                $appNumber     = generateApplicationNumber();
                $receiptNumber = ApplicationHelper::generateReceiptNumber();
                $receiptToken  = ApplicationHelper::generateReceiptToken($appNumber, $parentEmail);
                $paidAt        = date('Y-m-d H:i:s');

                $db->insert(
                    "INSERT INTO admissions (
                        application_number, first_name, middle_name, last_name, date_of_birth, gender,
                        class_applying_for, academic_session, entry_term, blood_group, genotype, medical_conditions,
                        parent_title, parent_name, parent_relationship, parent_occupation, parent_email, parent_phone,
                        address, city, state, lga, country,
                        emergency_name, emergency_phone, emergency_relationship,
                        previous_school, documents_path,
                        payment_method, payment_reference, receipt_number, amount_paid, payment_status, receipt_token, paid_at,
                        status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                    [
                        $appNumber, $firstName, $middleName ?: null, $lastName, $dob, $gender,
                        $class, $academicSession, $entryTerm, $bloodGroup ?: null, $genotype ?: null, $medicalConditions ?: null,
                        $parentTitle ?: null, $parentName, $parentRelationship, $parentOccupation ?: null, $parentEmail, $parentPhone,
                        $address, $city ?: null, $loc['state'] ?? '', $loc['lga'] ?? '', $loc['country'] ?? 'Nigeria',
                        $emergencyName ?: null, $emergencyPhone ?: null, $emergencyRel ?: null,
                        $previousSchool ?: null, json_encode($stored),
                        $paymentMethod, $paymentReference, $receiptNumber, $amountPaid, $paymentStatus, $receiptToken, $paidAt
                    ]
                );

                // Dual-write into applications table for unified synchronization
                try {
                    $db->insert(
                        "INSERT INTO applications (
                            application_number, child_first_name, child_middle_name, child_last_name, child_dob, child_gender,
                            class_applying, academic_session, entry_term, blood_group, genotype, medical_conditions,
                            parent_title, parent_first_name, parent_last_name, parent_relationship,
                            parent_email, parent_phone, parent_occupation, address, city, state, lga, country,
                            previous_school, emergency_name, emergency_phone, emergency_relationship,
                            documents_path, payment_method, payment_reference, receipt_number, amount_paid, payment_status, receipt_token, paid_at,
                            status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                        [
                            $appNumber, $firstName, $middleName ?: null, $lastName, $dob, $gender,
                            $class, $academicSession, $entryTerm, $bloodGroup ?: null, $genotype ?: null, $medicalConditions ?: null,
                            $parentTitle ?: null, $firstName, $lastName, $parentRelationship,
                            $parentEmail, $parentPhone, $parentOccupation ?: null, $address, $city ?: null, $loc['state'] ?? '', $loc['lga'] ?? '', $loc['country'] ?? 'Nigeria',
                            $previousSchool ?: null, $emergencyName ?: null, $emergencyPhone ?: null, $emergencyRel ?: null,
                            json_encode($stored), $paymentMethod, $paymentReference, $receiptNumber, $amountPaid, $paymentStatus, $receiptToken, $paidAt
                        ]
                    );
                } catch (Throwable $eApp) {
                    error_log("admissions.php applications mirror: " . $eApp->getMessage());
                }

                $_SESSION['recent_application_receipts'][] = $appNumber;
                $receiptUrl = BASE_URL . '/public/application-receipt?app=' . urlencode($appNumber) . '&token=' . urlencode($receiptToken);

                $fullName = trim($firstName . ' ' . $middleName . ' ' . $lastName);
                ApplicationHelper::syncApplicationPayment(
                    $appNumber,
                    $receiptNumber,
                    $paymentReference,
                    $paymentMethod,
                    (float)$amountPaid,
                    $paymentStatus,
                    $fullName,
                    trim($parentTitle . ' ' . $parentName),
                    $parentEmail,
                    $parentPhone
                );
                $docList = '';
                foreach ($stored as $key => $_) {
                    $docList .= '<li>&#10003; ' . e($catalog[$key]['short'] ?? $key) . '</li>';
                }
                sendEmail($parentEmail, 'Application & Official Receipt - ' . SCHOOL_NAME,
                    "<div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto'>"
                    . "<h2 style='background:#002855;color:#fff;padding:16px;text-align:center'>Application &amp; Payment Receipt</h2>"
                    . "<p>Dear <strong>" . e(trim($parentTitle . ' ' . $parentName)) . "</strong>,</p>"
                    . "<p>Thank you for applying to <strong>" . e(SCHOOL_NAME) . "</strong> for <strong>" . e($fullName) . "</strong> (" . e($class) . ").</p>"
                    . "<p style='font-size:18px;text-align:center;background:#ffd700;padding:12px'>Application No: <strong>" . e($appNumber) . "</strong> | Receipt No: <strong>" . e($receiptNumber) . "</strong></p>"
                    . "<p><strong>Payment Reference:</strong> " . e($paymentReference) . " (&#8358;" . number_format($amountPaid, 2) . ")</p>"
                    . "<p><strong>Documents received:</strong></p><ul>$docList</ul>"
                    . "<p style='text-align:center;margin:20px 0'><a href='" . e($receiptUrl) . "' style='background:#002147;color:#ffd700;padding:12px 20px;text-decoration:none;border-radius:6px;font-weight:bold'>View &amp; Print Official Receipt</a></p>"
                    . "<p>Questions? Call " . e(school_phone()) . ".</p><p><strong>Admissions Team</strong></p></div>");
                sendEmail(school_email(), "New Admission Application - $appNumber ($receiptNumber)",
                    "<h2>New Admission Application</h2><p><strong>Number:</strong> " . e($appNumber) . " | <strong>Receipt:</strong> " . e($receiptNumber) . "</p><p><strong>Child:</strong> " . e($fullName)
                    . " (" . e($dob) . ", " . e($gender) . ")</p><p><strong>Class:</strong> " . e($class) . "</p><p><strong>Parent:</strong> " . e($parentName)
                    . " &middot; " . e($parentEmail) . " &middot; " . e($parentPhone) . "</p><p><a href='" . e(BASE_URL) . "/admin/applications'>Review in the admin panel</a></p>");

                $applied = [
                    'number'      => $appNumber,
                    'receipt'     => $receiptNumber,
                    'reference'   => $paymentReference,
                    'amount'      => $amountPaid,
                    'email'       => $parentEmail,
                    'receipt_url' => $receiptUrl
                ];
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
    margin-bottom: var(--spacing-md);
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

.app-top-utility-bar {
    max-width: 960px;
    margin: 0 auto 22px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    background: #002147;
    color: #fff;
    padding: 14px 20px;
    border-radius: 12px;
}

.app-top-utility-bar .ut-left {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 0.9rem;
}

.app-top-utility-bar .btn-reprint {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #FFD700;
    color: #002147;
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.85rem;
    text-decoration: none;
}

.form-container {
    max-width: 960px;
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
    border: 1px solid #e2e8f0;
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

.form-group small {
    display: block;
    margin-top: var(--spacing-xs);
    color: var(--gray);
    font-size: var(--text-xs);
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
    background-color: #ffffff;
    color: #155724;
    border: 2px solid #86efac;
    box-shadow: 0 15px 40px rgba(15, 23, 42, 0.08);
    max-width: 780px;
    margin: 0 auto 30px;
    padding: 36px 28px;
    border-radius: 16px;
}

.alert-error {
    background-color: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
    max-width: 960px;
    margin: 0 auto 20px;
}

/* Button Styles */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--spacing-sm);
    padding: 0.75rem 1.5rem;
    border-radius: var(--radius-md);
    font-weight: 600;
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
}

@media (max-width: 768px) {
    .form-container, .form-section {
        padding: var(--spacing-lg);
    }
    .form-actions {
        flex-direction: column;
    }
    .form-actions .btn {
        width: 100%;
    }
}
</style>

<?php renderApplicationComponentsAssets(); ?>

<!-- Page Header -->
<section class="page-header">
    <div class="container">
        <h1>Admissions</h1>
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>/">Home</a> / Admissions
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
                    <li>Birth certificate (original &amp; copy) - <strong>Required</strong></li>
                    <li>Recent passport photograph - <strong>Required</strong></li>
                    <li>Immunization / health records - <strong>Recommended</strong></li>
                    <li>Previous school report (if applicable)</li>
                    <li>Parent / Guardian Valid ID Card</li>
                    <li>Application &amp; assessment fee (&#8358;15,000)</li>
                </ul>
            </div>

            <div class="info-card animate-fade-in" style="animation-delay: 0.2s;">
                <i class="fas fa-clock"></i>
                <h3>Admission Process</h3>
                <ol>
                    <li>Complete the online application form</li>
                    <li>Select &amp; upload required documents from dropdown</li>
                    <li>Complete online fee payment &amp; generate receipt</li>
                    <li>Schedule child assessment &amp; family tour</li>
                    <li>Receive admission offer letter</li>
                    <li>Acceptance and enrollment</li>
                </ol>
            </div>

            <div class="info-card animate-fade-in" style="animation-delay: 0.4s;">
                <i class="fas fa-download"></i>
                <h3>Downloads &amp; Receipts</h3>
                <p><a href="<?php echo BASE_URL; ?>/uploads/prospectus.pdf" target="_blank">
                    <i class="fas fa-file-pdf"></i> School Prospectus
                </a></p>
                <p><a href="<?php echo BASE_URL; ?>/uploads/fee-structure.pdf" target="_blank">
                    <i class="fas fa-file-pdf"></i> Fee Structure
                </a></p>
                <p><a href="<?php echo BASE_URL; ?>/public/application-receipt">
                    <i class="fas fa-receipt"></i> Print / Retrieve Application Receipt
                </a></p>
            </div>
        </div>
    </div>
</section>

<!-- Application Form -->
<section class="application-form" id="online-application">
    <div class="container">
        <h2 class="section-title">Online Application</h2>

        <div class="app-top-utility-bar">
            <div class="ut-left">
                <i class="fas fa-check-circle" style="color:#FFD700; font-size:1.2rem;"></i>
                <span>Complete all sections below, upload your documents one after another, and print your official payment receipt immediately.</span>
            </div>
            <a href="<?php echo BASE_URL; ?>/public/application-receipt" class="btn-reprint">
                <i class="fas fa-print"></i> Reprint Existing Receipt
            </a>
        </div>

        <?php if ($messageType === 'error' && $message): ?>
        <div class="alert alert-error" role="alert">
            <ul style="margin:0;padding-left:18px"><?php foreach (explode("\n", $message) as $line): ?><li><?php echo e($line); ?></li><?php endforeach; ?></ul>
        </div>
        <?php endif; ?>

        <?php if ($messageType === 'success'): ?>
        <div class="alert alert-success" style="display:block; text-align:center;">
            <i class="fas fa-check-circle" style="font-size:4rem;color:#16a34a;margin-bottom:16px"></i>
            <h3 style="color:#002147; font-size:1.6rem; margin-bottom:10px;">Application &amp; Payment Completed!</h3>
            <?php if ($applied): ?>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; background:#f8fafc; border:1px solid #cbd5e1; border-radius:12px; padding:18px; margin:20px 0; text-align:left;">
                <div>
                    <div style="font-size:0.75rem; color:#64748b; text-transform:uppercase; font-weight:700;">Application Number</div>
                    <div style="font-size:1.1rem; font-weight:800; color:#c8102e;"><?php echo e($applied['number']); ?></div>
                </div>
                <div>
                    <div style="font-size:0.75rem; color:#64748b; text-transform:uppercase; font-weight:700;">Official Receipt No.</div>
                    <div style="font-size:1.05rem; font-weight:800; color:#002147;"><?php echo e($applied['receipt']); ?></div>
                </div>
                <div>
                    <div style="font-size:0.75rem; color:#64748b; text-transform:uppercase; font-weight:700;">Payment Reference</div>
                    <div style="font-size:0.95rem; font-weight:700; color:#0f172a;"><?php echo e($applied['reference']); ?></div>
                </div>
                <div>
                    <div style="font-size:0.75rem; color:#64748b; text-transform:uppercase; font-weight:700;">Amount Paid</div>
                    <div style="font-size:1.05rem; font-weight:800; color:#15803d;">&#8358;<?php echo number_format($applied['amount'], 2); ?></div>
                </div>
            </div>
            <p style="color:#334155;">A confirmation copy has been sent to <strong><?php echo e($applied['email']); ?></strong>.</p>
            <?php endif; ?>

            <div style="margin-top:24px; display:flex; justify-content:center; gap:14px; flex-wrap:wrap;">
                <?php if (!empty($applied['receipt_url'])): ?>
                <a href="<?php echo e($applied['receipt_url']); ?>" target="_blank" class="btn btn-primary" style="background:#002147; color:#FFD700;">
                    <i class="fas fa-print"></i> View &amp; Print Official Receipt
                </a>
                <?php endif; ?>
                <a href="admissions" class="btn btn-outline">Submit Another Application</a>
                <a href="<?php echo BASE_URL; ?>/" class="btn btn-outline">Return to Home</a>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($messageType !== 'success'): ?>
        <form method="POST" action="" enctype="multipart/form-data" class="form-container" id="admissionForm">
            <?php echo csrf_field(); ?>
            <div style="position:absolute;left:-9999px" aria-hidden="true"><label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

            <!-- SECTION 1: CHILD'S INFORMATION -->
            <div class="form-section">
                <h3><i class="fas fa-child"></i> 1. Child's Personal &amp; Academic Details</h3>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="first_name">Child's First Name *</label>
                        <input type="text" id="first_name" name="first_name"
                               value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>"
                               placeholder="Enter child's first name" required>
                    </div>

                    <div class="form-group">
                        <label for="middle_name">Child's Middle Name</label>
                        <input type="text" id="middle_name" name="middle_name"
                               value="<?php echo htmlspecialchars($_POST['middle_name'] ?? ''); ?>"
                               placeholder="Enter child's middle name (optional)">
                    </div>

                    <div class="form-group">
                        <label for="last_name">Child's Surname / Last Name *</label>
                        <input type="text" id="last_name" name="last_name"
                               value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>"
                               placeholder="Enter child's surname" required>
                    </div>

                    <div class="form-group">
                        <label for="dob">Date of Birth *</label>
                        <input type="date" id="dob" name="dob"
                               value="<?php echo htmlspecialchars($_POST['dob'] ?? ''); ?>"
                               required>
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
                            <?php foreach ($classes as $class):
                                $cLabel = trim($class['class_name'] . ' ' . ($class['section'] ?? ''));
                            ?>
                            <option value="<?php echo htmlspecialchars($cLabel); ?>"
                                <?php echo (($_POST['class'] ?? '') == $cLabel) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cLabel); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="academic_session">Academic Session *</label>
                        <select id="academic_session" name="academic_session">
                            <option value="2026/2027" <?php echo (($_POST['academic_session'] ?? '2026/2027') === '2026/2027') ? 'selected' : ''; ?>>2026/2027 Academic Session</option>
                            <option value="2025/2026" <?php echo (($_POST['academic_session'] ?? '') === '2025/2026') ? 'selected' : ''; ?>>2025/2026 Academic Session</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="entry_term">Entry Term *</label>
                        <select id="entry_term" name="entry_term">
                            <option value="First Term" <?php echo (($_POST['entry_term'] ?? '') === 'First Term') ? 'selected' : ''; ?>>First Term (September)</option>
                            <option value="Second Term" <?php echo (($_POST['entry_term'] ?? '') === 'Second Term') ? 'selected' : ''; ?>>Second Term (January)</option>
                            <option value="Third Term" <?php echo (($_POST['entry_term'] ?? '') === 'Third Term') ? 'selected' : ''; ?>>Third Term (April)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="blood_group">Blood Group</label>
                        <select id="blood_group" name="blood_group">
                            <option value="">Select Blood Group (Optional)</option>
                            <?php foreach (['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'] as $bg): ?>
                                <option value="<?php echo $bg; ?>" <?php echo (($_POST['blood_group'] ?? '') === $bg) ? 'selected' : ''; ?>><?php echo $bg; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="genotype">Genotype</label>
                        <select id="genotype" name="genotype">
                            <option value="">Select Genotype (Optional)</option>
                            <?php foreach (['AA', 'AS', 'AC', 'SS'] as $gt): ?>
                                <option value="<?php echo $gt; ?>" <?php echo (($_POST['genotype'] ?? '') === $gt) ? 'selected' : ''; ?>><?php echo $gt; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full-width">
                        <label for="previous_school">Previous School / Nursery Attended (if any)</label>
                        <input type="text" id="previous_school" name="previous_school"
                               value="<?php echo htmlspecialchars($_POST['previous_school'] ?? ''); ?>"
                               placeholder="Name and city of previous school (if applicable)">
                    </div>

                    <div class="form-group full-width">
                        <label for="medical_conditions">Medical Conditions, Allergies, or Special Dietary Needs (if any)</label>
                        <textarea id="medical_conditions" name="medical_conditions" rows="2"
                                  placeholder="List any known allergies, asthma, or medical instructions our school nurse should know"><?php echo htmlspecialchars($_POST['medical_conditions'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- SECTION 2: PARENT / GUARDIAN & EMERGENCY CONTACT -->
            <div class="form-section">
                <h3><i class="fas fa-users"></i> 2. Parent / Guardian &amp; Emergency Contact</h3>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="parent_title">Title</label>
                        <select id="parent_title" name="parent_title">
                            <option value="">Select Title</option>
                            <?php foreach (['Mr', 'Mrs', 'Ms', 'Dr', 'Engr', 'Barr', 'Prof', 'Chief'] as $tOpt): ?>
                                <option value="<?php echo $tOpt; ?>" <?php echo (($_POST['parent_title'] ?? '') === $tOpt) ? 'selected' : ''; ?>><?php echo $tOpt; ?>.</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="parent_name">Parent / Guardian Full Name *</label>
                        <input type="text" id="parent_name" name="parent_name"
                               value="<?php echo htmlspecialchars($_POST['parent_name'] ?? ''); ?>"
                               placeholder="Enter parent or guardian's full name" required>
                    </div>

                    <div class="form-group">
                        <label for="parent_relationship">Relationship to Child *</label>
                        <select id="parent_relationship" name="parent_relationship">
                            <?php foreach (['Father', 'Mother', 'Both Parents', 'Legal Guardian'] as $relOpt): ?>
                                <option value="<?php echo $relOpt; ?>" <?php echo (($_POST['parent_relationship'] ?? 'Father') === $relOpt) ? 'selected' : ''; ?>><?php echo $relOpt; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="parent_occupation">Occupation / Profession</label>
                        <input type="text" id="parent_occupation" name="parent_occupation"
                               value="<?php echo htmlspecialchars($_POST['parent_occupation'] ?? ''); ?>"
                               placeholder="e.g. Medical Doctor, Civil Servant, Business">
                    </div>

                    <div class="form-group">
                        <label for="parent_email">Email Address *</label>
                        <input type="email" id="parent_email" name="parent_email"
                               value="<?php echo htmlspecialchars($_POST['parent_email'] ?? ''); ?>"
                               placeholder="parent@example.com" required>
                    </div>

                    <div class="form-group">
                        <label for="parent_phone">Primary Phone Number *</label>
                        <input type="tel" id="parent_phone" name="parent_phone"
                               value="<?php echo htmlspecialchars($_POST['parent_phone'] ?? ''); ?>"
                               placeholder="08012345678" required>
                        <small>Nigerian mobile number (e.g., 08012345678) or international with +country code</small>
                    </div>

                    <div class="form-group full-width">
                        <label for="address">Residential Home Address *</label>
                        <textarea id="address" name="address" rows="2"
                                  placeholder="Enter complete street address" required><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group full-width">
                        <?php location_fields($_POST, 'Enugu'); ?>
                    </div>

                    <div class="form-group">
                        <label for="emergency_name">Emergency Contact Name</label>
                        <input type="text" id="emergency_name" name="emergency_name"
                               value="<?php echo htmlspecialchars($_POST['emergency_name'] ?? ''); ?>"
                               placeholder="Alternative person to reach in an emergency">
                    </div>

                    <div class="form-group">
                        <label for="emergency_phone">Emergency Contact Phone &amp; Relationship</label>
                        <input type="tel" id="emergency_phone" name="emergency_phone"
                               value="<?php echo htmlspecialchars($_POST['emergency_phone'] ?? ''); ?>"
                               placeholder="e.g. 08031234567">
                    </div>
                </div>
            </div>

            <!-- SECTION 3: SEQUENTIAL REQUIRED DOCUMENTS DROPDOWN UPLOADER -->
            <div class="form-section">
                <h3><i class="fas fa-file-upload"></i> 3. Required Documents (Select &amp; Upload One After Another)</h3>
                <?php renderSequentialDocumentsUploader(); ?>
            </div>

            <!-- SECTION 4: ONLINE PAYMENT & RECEIPT GENERATION -->
            <div class="form-section">
                <h3><i class="fas fa-credit-card"></i> 4. Online Fee Payment &amp; Instant Receipt</h3>
                <?php renderOnlinePaymentSection(); ?>
            </div>

            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="terms" id="terms" required>
                    <span>I confirm that the information provided is accurate and I agree to the admission terms of St. Benedict's Early Years British Academy.</span>
                </label>
            </div>

            <?php if (function_exists('turnstile_widget')) echo turnstile_widget('light', 'admissions'); ?>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-large" id="submitBtn">
                    <i class="fas fa-paper-plane"></i> Submit Application &amp; Generate Receipt
                </button>
                <button type="reset" class="btn btn-outline" id="resetBtn">
                    <i class="fas fa-undo"></i> Reset Form
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</section>

<script nonce="<?php echo CSP_NONCE; ?>">
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('admissionForm');
    const submitBtn = document.getElementById('submitBtn');
    const dropdown = document.getElementById('requiredDocDropdown');

    if (form) {
        form.addEventListener('submit', function(e) {
            const birthInput = document.getElementById('doc_input_birth_certificate');
            const photoInput = document.getElementById('doc_input_passport_photo');

            if (!birthInput || !birthInput.files || !birthInput.files[0]) {
                e.preventDefault();
                alert('Please select "Birth Certificate (Required)" from the Required Documents dropdown and upload the file.');
                if (dropdown) {
                    dropdown.value = 'birth_certificate';
                    dropdown.dispatchEvent(new Event('change'));
                    dropdown.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return;
            }

            if (!photoInput || !photoInput.files || !photoInput.files[0]) {
                e.preventDefault();
                alert('Please select "Child\'s Passport Photograph (Required)" from the Required Documents dropdown and upload the file.');
                if (dropdown) {
                    dropdown.value = 'passport_photo';
                    dropdown.dispatchEvent(new Event('change'));
                    dropdown.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return;
            }

            if (submitBtn) {
                submitBtn.classList.add('btn-loading');
                submitBtn.disabled = true;
            }
        });
    }
});
</script>

<?php
$footerPath = __DIR__ . '/../includes/footer.php';
if (file_exists($footerPath)) {
    include $footerPath;
}
?>
