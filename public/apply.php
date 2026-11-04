<?php
// public/apply.php - Online Application Form
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/ApplicationHelper.php';
require_once __DIR__ . '/../includes/application-components.php';

ApplicationHelper::ensureSchema();

$pageTitle = 'Apply Now - Online Admission Application';
$pageDescription = 'Apply online for admission to ST. BENEDICT\'S EARLY YEARS BRITISH ACADEMY. Start your child\'s educational journey with us.';

include __DIR__ . '/../includes/header.php';

$db = db();
$message = '';
$messageType = '';
$applied = null;

// Get available classes
$classes = $db->getRows(
    "SELECT * FROM classes WHERE is_active = 1 ORDER BY class_name"
);

$catalog = ApplicationHelper::getDocumentCatalog();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session expired. Please reload the page and try again.';
    } else {
        $ts = turnstile_verify();
        if (!$ts['success']) {
            $errors[] = $ts['error'];
        }
    }
    if (!empty($_POST['website'])) {
        // Honeypot field filled in: pretend success, store nothing
        $message = 'Thank you.';
        $messageType = 'success';
    } elseif (($_SESSION['apply_attempts'][date('YmdH')] ?? 0) >= 8) {
        $errors[] = 'Too many submissions from this device. Please try again later or call the school office.';
    } else {
        $_SESSION['apply_attempts'] = [date('YmdH') => ($_SESSION['apply_attempts'][date('YmdH')] ?? 0) + 1];

        $text = function ($key, $max = 100) {
            return mb_substr(Security::sanitize($_POST[$key] ?? ''), 0, $max);
        };
        $classNames = array_column($classes, 'class_name');
        $formData = [
            'child_first_name'       => $text('child_first_name', 50),
            'child_middle_name'      => $text('child_middle_name', 50),
            'child_last_name'        => $text('child_last_name', 50),
            'child_dob'              => $_POST['child_dob'] ?? '',
            'child_gender'           => $_POST['child_gender'] ?? '',
            'class_applying'         => $text('class_applying', 50),
            'academic_session'       => $text('academic_session', 30) ?: '2026/2027',
            'entry_term'             => $text('entry_term', 50) ?: 'First Term',
            'blood_group'            => $text('blood_group', 10),
            'genotype'               => $text('genotype', 10),
            'medical_conditions'     => $text('medical_conditions', 500),
            'parent_title'           => in_array($_POST['parent_title'] ?? '', ['Mr', 'Mrs', 'Ms', 'Dr', 'Chief', 'Prof', 'Engr', 'Barr'], true) ? $_POST['parent_title'] : '',
            'parent_first_name'      => $text('parent_first_name', 50),
            'parent_last_name'       => $text('parent_last_name', 50),
            'parent_relationship'    => $text('parent_relationship', 50) ?: 'Parent',
            'parent_email'           => $text('parent_email', 100),
            'parent_phone'           => $text('parent_phone', 20),
            'parent_occupation'      => $text('parent_occupation', 100),
            'address'                => $text('address', 500),
            'city'                   => $text('city', 100),
            'previous_school'        => $text('previous_school', 200),
            'reason_applying'        => $text('reason_applying', 1000),
            'how_hear'               => $text('how_hear', 100),
            'emergency_name'         => $text('emergency_name', 100),
            'emergency_phone'        => $text('emergency_phone', 20),
            'emergency_relationship' => $text('emergency_relationship', 50),
            'payment_method'         => $text('payment_method', 50) ?: 'online_card',
            'payment_reference'      => $text('payment_reference', 80) ?: ApplicationHelper::generatePaymentReference(),
        ];

        if ($formData['child_first_name'] === '') $errors[] = "Child's first name is required";
        if ($formData['child_last_name'] === '') $errors[] = "Child's last name is required";
        if (!in_array($formData['child_gender'], ['male', 'female'], true)) $errors[] = "Child's gender is required";
        if (!in_array($formData['class_applying'], $classNames, true)) $errors[] = 'Please choose a class to apply for';
        if ($formData['parent_first_name'] === '') $errors[] = "Parent's first name is required";
        if ($formData['parent_last_name'] === '') $errors[] = "Parent's last name is required";
        if (!Security::validateEmail($formData['parent_email'])) $errors[] = 'A valid parent email is required';
        $loc = location_resolve($_POST);
        if (!$loc['ok']) $errors[] = $loc['error'];
        $abroad = ($_POST['state'] ?? '') === LOC_OTHER;
        if ($abroad ? !valid_phone_intl($formData['parent_phone']) : !Security::validatePhone($formData['parent_phone'])) {
            $errors[] = $abroad ? 'A valid parent phone number with country code is required (e.g. +447911123456)' : 'A valid Nigerian parent phone number is required (e.g. 08012345678)';
        }
        if ($formData['emergency_phone'] !== '' && !Security::validatePhone($formData['emergency_phone']) && !valid_phone_intl($formData['emergency_phone'])) $errors[] = 'The emergency contact phone number is not valid';
        if ($formData['address'] === '') $errors[] = 'Address is required';

        $dob = valid_date($formData['child_dob']);
        if (!$dob || $dob > date('Y-m-d')) {
            $errors[] = "A valid child's date of birth is required";
        }

        // Uploads: validated by real content type, stored outside the public web root
        $stored = [];
        if (!$errors) {
            $privateDir = PRIVATE_PATH . 'applications/';
            if (!is_dir($privateDir)) { @mkdir($privateDir, 0750, true); }

            // Handle dedicated Step 1 Child Passport Photograph
            if (isset($_FILES['child_passport_photo']) && $_FILES['child_passport_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
                $checkPhoto = Security::validateFileUpload($_FILES['child_passport_photo'], ['jpg', 'jpeg', 'png']);
                if (!$checkPhoto['valid']) {
                    $errors[] = "Child's Passport Photograph: " . $checkPhoto['message'];
                } else {
                    $photoName = 'passport_photo_' . bin2hex(random_bytes(12)) . '.' . $checkPhoto['extension'];
                    if (move_uploaded_file($_FILES['child_passport_photo']['tmp_name'], $privateDir . $photoName)) {
                        @chmod($privateDir . $photoName, 0640);
                        $stored['passport_photo'] = $photoName;
                        $pubPassportsDir = __DIR__ . '/../uploads/passports/';
                        if (!is_dir($pubPassportsDir)) { @mkdir($pubPassportsDir, 0755, true); }
                        @copy($privateDir . $photoName, $pubPassportsDir . $photoName);
                    } else {
                        $errors[] = "Could not store Child's Passport Photograph";
                    }
                }
            }

            foreach ($catalog as $field => $meta) {
                if ($field === 'passport_photo' && !empty($stored['passport_photo'])) continue;
                if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) continue;
                $check = Security::validateFileUpload($_FILES[$field], $meta['allowed']);
                if (!$check['valid']) {
                    $errors[] = $meta['short'] . ': ' . $check['message'];
                    continue;
                }
                $name = $field . '_' . bin2hex(random_bytes(12)) . '.' . $check['extension'];
                if (move_uploaded_file($_FILES[$field]['tmp_name'], $privateDir . $name)) {
                    @chmod($privateDir . $name, 0640);
                    $stored[$field] = $name;
                } else {
                    $errors[] = 'Could not store ' . $meta['short'];
                }
            }
        }

        if (empty($stored['passport_photo'])) {
            $errors[] = "Child's Passport Photograph is required (Step 1)";
        }

        if (!$errors) {
            try {
                $appNumber     = generateApplicationNumber();
                $receiptNumber = ApplicationHelper::generateReceiptNumber();
                $receiptToken  = ApplicationHelper::generateReceiptToken($appNumber, $formData['parent_email']);
                $amountPaid    = ApplicationHelper::APPLICATION_FEE;
                $payMethod     = $formData['payment_method'];

                if ($payMethod === 'pay_at_school') {
                    $payStatus = 'pending_school_payment';
                    $paidAt    = null;
                } elseif ($payMethod === 'bank_transfer') {
                    $payStatus = 'pending_verification';
                    $paidAt    = null;
                } else {
                    // Paystack Online: Strictly validate that payment was completed before proceeding!
                    $ref = $formData['payment_reference'];
                    $isVerified = ApplicationHelper::isPaymentVerified($ref);
                    if (!$isVerified) {
                        $vRes = paystack_verify_transaction($ref, (float)$amountPaid);
                        if (!empty($vRes['success'])) {
                            ApplicationHelper::recordPaymentVerification($ref, $vRes);
                            $isVerified = true;
                        }
                    }
                    if (!$isVerified) {
                        throw new RuntimeException("PAYMENT_NOT_VERIFIED");
                    }
                    $payStatus = 'paid';
                    $paidAt    = date('Y-m-d H:i:s');
                }

                $db->insert(
                    "INSERT INTO applications (
                        application_number, child_first_name, child_middle_name, child_last_name, child_dob, child_gender,
                        class_applying, academic_session, entry_term, blood_group, genotype, medical_conditions,
                        parent_title, parent_first_name, parent_last_name, parent_relationship,
                        parent_email, parent_phone, parent_occupation, address, city, state, lga, country,
                        previous_school, reason_applying, how_hear,
                        emergency_name, emergency_phone, emergency_relationship,
                        birth_certificate_path, passport_photo_path, documents_path,
                        payment_method, payment_reference, receipt_number, amount_paid, payment_status, receipt_token, paid_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $appNumber, $formData['child_first_name'], $formData['child_middle_name'] ?: null, $formData['child_last_name'], $dob, $formData['child_gender'],
                        $formData['class_applying'], $formData['academic_session'], $formData['entry_term'], $formData['blood_group'] ?: null, $formData['genotype'] ?: null, $formData['medical_conditions'] ?: null,
                        $formData['parent_title'], $formData['parent_first_name'], $formData['parent_last_name'], $formData['parent_relationship'],
                        $formData['parent_email'], $formData['parent_phone'], $formData['parent_occupation'], $formData['address'],
                        $formData['city'], $loc['state'] ?? '', $loc['lga'] ?? '', $loc['country'] ?? 'Nigeria', $formData['previous_school'], $formData['reason_applying'], $formData['how_hear'],
                        $formData['emergency_name'], $formData['emergency_phone'], $formData['emergency_relationship'],
                        $stored['birth_certificate'] ?? null, $stored['passport_photo'] ?? null, json_encode($stored),
                        $formData['payment_method'], $formData['payment_reference'], $receiptNumber, $amountPaid, $payStatus, $receiptToken, $paidAt
                    ]
                );

                ApplicationHelper::syncApplicationPayment(
                    $appNumber,
                    $receiptNumber,
                    $formData['payment_reference'],
                    $formData['payment_method'],
                    (float)$amountPaid,
                    $payStatus,
                    trim($formData['child_first_name'] . ' ' . $formData['child_middle_name'] . ' ' . $formData['child_last_name']),
                    trim($formData['parent_title'] . ' ' . $formData['parent_first_name'] . ' ' . $formData['parent_last_name']),
                    $formData['parent_email'],
                    $formData['parent_phone']
                );

                $_SESSION['recent_application_receipts'][] = $appNumber;
                $receiptUrl = BASE_URL . '/public/application-receipt?app=' . urlencode($appNumber) . '&token=' . urlencode($receiptToken);

                $name = e(trim($formData['parent_title'] . ' ' . $formData['parent_last_name']));
                sendEmail($formData['parent_email'], 'Application & Official Receipt - ' . SCHOOL_NAME,
                    "<div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto'>"
                    . "<h2 style='background:#002855;color:#fff;padding:16px;text-align:center'>Application &amp; Payment Receipt</h2>"
                    . "<p>Dear $name,</p><p>Thank you for applying to <strong>" . e(SCHOOL_NAME) . "</strong>.</p>"
                    . "<ul><li><strong>Application number:</strong> " . e($appNumber) . "</li>"
                    . "<li><strong>Official Receipt:</strong> " . e($receiptNumber) . "</li>"
                    . "<li><strong>Payment Reference:</strong> " . e($formData['payment_reference']) . " (&#8358;" . number_format($amountPaid, 2) . ")</li>"
                    . "<li><strong>Child:</strong> " . e($formData['child_first_name'] . ' ' . $formData['child_last_name']) . "</li>"
                    . "<li><strong>Class:</strong> " . e($formData['class_applying']) . "</li></ul>"
                    . "<p style='text-align:center;margin:20px 0'><a href='" . e($receiptUrl) . "' style='background:#002147;color:#ffd700;padding:12px 20px;text-decoration:none;border-radius:6px;font-weight:bold'>View &amp; Print Official Receipt</a></p>"
                    . "<p>We will contact you within 3-5 working days to schedule an assessment. Questions? Call " . e(school_phone()) . ".</p>"
                    . "<p><strong>Admissions Office</strong><br>" . e(SCHOOL_NAME) . "</p></div>");

                $applied = [
                    'number'         => $appNumber,
                    'receipt'        => $receiptNumber,
                    'reference'      => $formData['payment_reference'],
                    'amount'         => $amountPaid,
                    'payment_status' => $payStatus,
                    'payment_method' => $formData['payment_method'],
                    'email'          => $formData['parent_email'],
                    'receipt_url'    => $receiptUrl
                ];
                $messageType = 'success';
                $_POST = [];
            } catch (RuntimeException $re) {
                foreach ($stored as $f) { if ($f) @unlink(PRIVATE_PATH . 'applications/' . $f); }
                if ($re->getMessage() === 'PAYMENT_NOT_VERIFIED') {
                    $errors[] = "Online Payment Required: You selected online payment via Paystack, but payment of ₦" . number_format(ApplicationHelper::APPLICATION_FEE, 2) . " has not been verified. Please click 'Pay ₦" . number_format(ApplicationHelper::APPLICATION_FEE, 2) . " on Paystack' before submitting, or select 'Pay at School & Print Application Slip' to pay at the school admissions office.";
                } else {
                    $errors[] = 'An error occurred during submission: ' . $re->getMessage();
                }
            } catch (Exception $e) {
                foreach ($stored as $f) { if ($f) @unlink(PRIVATE_PATH . 'applications/' . $f); }
                error_log('Application error: ' . $e->getMessage());
                $errors[] = 'An error occurred. Please try again or contact us directly.';
            }
        } else {
            foreach ($stored as $f) { if ($f) @unlink(PRIVATE_PATH . 'applications/' . $f); }
        }
    }
    if ($errors) {
        $messageType = 'error';
        $message = implode("\n", $errors);
    }
}
?>

<?php renderApplicationComponentsAssets(); ?>

<!-- Page Header -->
<section class="page-header">
    <div class="container">
        <h1>Online Application</h1>
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>/">Home</a> / Apply Now
        </div>
    </div>
</section>

<!-- Application Form -->
<section class="application-section">
    <div class="container">
        <?php if ($messageType === 'success'): ?>
        <?php $isSchoolPayment = (($applied['payment_status'] ?? '') === 'pending_school_payment'); ?>
        <div class="success-card">
            <div style="text-align:center">
                <?php if ($isSchoolPayment): ?>
                    <i class="fas fa-file-invoice" style="font-size:3.8rem; color:#d97706; margin-bottom:16px;"></i>
                    <h3 style="color:#002147; font-size:1.45rem;">Application Registered &mdash; Official Application Slip Ready!</h3>
                    <p style="color:#475569; font-size:0.95rem;">Your admission application has been recorded. Please print your official application slip below and take it to the school admissions office to complete payment and validate your child's application.</p>
                    <div style="display:inline-flex; align-items:center; gap:6px; background:#fef3c7; color:#92400e; padding:6px 16px; border-radius:999px; font-weight:700; font-size:0.85rem; margin-bottom:16px;">
                        <i class="fas fa-clock"></i> Payment Pending &mdash; Pay at School Admissions Office
                    </div>
                <?php else: ?>
                    <i class="fas fa-check-circle" style="font-size:3.8rem; color:#16a34a; margin-bottom:16px;"></i>
                    <h3 style="color:#002147; font-size:1.45rem;">Application Submitted &amp; Fee Payment Verified!</h3>
                    <p style="color:#475569; font-size:0.95rem;">Thank you! Your application and payment have been successfully verified.</p>
                    <div style="display:inline-flex; align-items:center; gap:6px; background:#dcfce7; color:#15803d; padding:6px 16px; border-radius:999px; font-weight:700; font-size:0.85rem; margin-bottom:16px;">
                        <i class="fas fa-shield-alt"></i> Official Receipt Issued
                    </div>
                <?php endif; ?>

                <?php if (!empty($applied)): ?>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:12px; background:#f8fafc; border:1px solid #cbd5e1; border-radius:12px; padding:16px; margin:18px 0; text-align:left;">
                    <div>
                        <div style="font-size:0.75rem; color:#64748b; text-transform:uppercase; font-weight:700;">Application Number</div>
                        <div style="font-size:1.05rem; font-weight:800; color:#002147;"><?php echo e($applied['number']); ?></div>
                    </div>
                    <div>
                        <div style="font-size:0.75rem; color:#64748b; text-transform:uppercase; font-weight:700;">Document Ref / Receipt</div>
                        <div style="font-size:0.95rem; font-weight:700; color:#0f172a;"><?php echo e($applied['receipt']); ?></div>
                    </div>
                    <div>
                        <div style="font-size:0.75rem; color:#64748b; text-transform:uppercase; font-weight:700;">Amount <?php echo $isSchoolPayment ? 'Payable' : 'Paid'; ?></div>
                        <div style="font-size:1.05rem; font-weight:800; color:<?php echo $isSchoolPayment ? '#d97706' : '#15803d'; ?>;">&#8358;<?php echo number_format($applied['amount'], 2); ?></div>
                    </div>
                </div>

                <?php if ($isSchoolPayment): ?>
                <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:12px; padding:18px 20px; text-align:left; margin:18px 0;">
                    <div style="font-weight:800; color:#002147; font-size:0.95rem; margin-bottom:8px;">
                        <i class="fas fa-university" style="color:#c8102e;"></i> Next Steps to Complete Admission &amp; Validate Application:
                    </div>
                    <ol style="margin:0; padding-left:20px; font-size:0.88rem; color:#334155; line-height:1.65;">
                        <li>Click <strong>Print Official Admission Application Slip</strong> below and print or save the PDF slip.</li>
                        <li>Take the printed slip to St. Benedict's Early Years British Academy Admissions Office (No 560 A A New G.R.A Trans-Ekulu, Enugu).</li>
                        <li>Pay the <strong>&#8358;15,000</strong> fee via POS / Cash at the bursary or via bank transfer to Zenith Bank: <strong>1310057496</strong> (St. Benedict's Early Years British Academy).</li>
                        <li>The Admissions Office validates the application and confirms the child's entrance interview schedule with teachers.</li>
                    </ol>
                </div>
                <?php endif; ?>

                <p>We have sent a copy of this confirmation to <strong><?php echo e($applied['email']); ?></strong>.</p>
                <?php endif; ?>
            </div>
            <div style="margin-top: 26px; display:flex; justify-content:center; gap:12px; flex-wrap:wrap;">
                <?php if (!empty($applied['receipt_url'])): ?>
                <a href="<?php echo e($applied['receipt_url']); ?>" target="_blank" class="btn btn-primary" style="background:#002147; color:#FFD700; font-weight:700; padding:12px 24px;">
                    <i class="fas fa-print"></i> <?php echo $isSchoolPayment ? 'Print Official Admission Application Slip (PDF)' : 'View &amp; Print Official Receipt'; ?>
                </a>
                <?php endif; ?>
                <a href="<?php echo BASE_URL; ?>/" class="btn btn-primary">Return to Home</a>
                <a href="apply" class="btn btn-outline">Submit Another Application</a>
            </div>
        </div>
        <?php else: ?>

        <?php if ($message): ?>
        <div class="alert alert-<?php echo e($messageType); ?>" role="alert">
            <ul style="margin:0;padding-left:18px"><?php foreach (explode("\n", $message) as $line): ?><li><?php echo e($line); ?></li><?php endforeach; ?></ul>
        </div>
        <?php endif; ?>

        <div class="application-progress">
            <div class="progress-step active">
                <span class="step-number">1</span>
                <span class="step-label">Child Information</span>
            </div>
            <div class="progress-step">
                <span class="step-number">2</span>
                <span class="step-label">Parent Information</span>
            </div>
            <div class="progress-step">
                <span class="step-number">3</span>
                <span class="step-label">Emergency Contact</span>
            </div>
            <div class="progress-step">
                <span class="step-number">4</span>
                <span class="step-label">Documents &amp; Payment</span>
            </div>
        </div>

        <div class="application-card">
            <form method="POST" enctype="multipart/form-data" id="applicationForm">
                <?php echo csrf_field(); ?>
                <div style="position:absolute;left:-9999px" aria-hidden="true"><label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

                <!-- Step 1: Child Information -->
                <div class="form-step active" id="step1">
                    <h3><i class="fas fa-child"></i> Child's Information</h3>

                    <!-- Dedicated Step 1 Child Passport Photograph Upload with Live Preview -->
                    <div class="child-passport-card" id="childPassportCard">
                        <div class="passport-avatar-wrap">
                            <div class="passport-avatar-box" id="passportAvatarBox">
                                <img id="passportLivePreviewImg" src="" alt="Child Passport Preview" style="display:none;">
                                <div id="passportPlaceholderBox" class="passport-placeholder-box">
                                    <i class="fas fa-camera"></i>
                                    <span>Passport Photo</span>
                                </div>
                            </div>
                        </div>
                        <div class="passport-details-wrap">
                            <label class="passport-input-title" for="child_passport_photo">
                                <i class="fas fa-id-badge"></i> Child's Passport Photograph <span style="color:#dc2626;">*</span>
                            </label>
                            <p class="passport-input-hint">
                                Upload a clear, recent color passport-sized photograph of the child with a white or plain light background (JPG or PNG, max 5MB). This official photograph will appear on the printed application slip and candidate file.
                            </p>
                            <div class="passport-action-controls">
                                <label for="child_passport_photo" class="btn-passport-pick">
                                    <i class="fas fa-upload"></i> Choose Passport Photograph
                                </label>
                                <input type="file" id="child_passport_photo" name="child_passport_photo" accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="passport-hidden-input">
                                <span id="passportSelectedFilename" class="passport-filename-label">No photo chosen yet</span>
                                <button type="button" id="clearPassportPhotoBtn" class="btn-passport-clear" style="display:none;">
                                    <i class="fas fa-times"></i> Change
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="child_first_name">First Name *</label>
                            <input type="text" id="child_first_name" name="child_first_name"
                                   value="<?php echo htmlspecialchars($_POST['child_first_name'] ?? ''); ?>"
                                   class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="child_middle_name">Middle Name</label>
                            <input type="text" id="child_middle_name" name="child_middle_name"
                                   value="<?php echo htmlspecialchars($_POST['child_middle_name'] ?? ''); ?>"
                                   class="form-control">
                        </div>

                        <div class="form-group">
                            <label for="child_last_name">Last Name *</label>
                            <input type="text" id="child_last_name" name="child_last_name"
                                   value="<?php echo htmlspecialchars($_POST['child_last_name'] ?? ''); ?>"
                                   class="form-control" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="child_dob">Date of Birth *</label>
                            <input type="date" id="child_dob" name="child_dob"
                                   value="<?php echo htmlspecialchars($_POST['child_dob'] ?? ''); ?>"
                                   class="form-control" required
                                   max="<?php echo date('Y-m-d'); ?>">
                            <small class="form-text">All age groups and classes accepted</small>
                        </div>

                        <div class="form-group">
                            <label for="child_gender">Gender *</label>
                            <select id="child_gender" name="child_gender" class="form-control" required>
                                <option value="">Select Gender</option>
                                <option value="male" <?php echo ($_POST['child_gender'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                                <option value="female" <?php echo ($_POST['child_gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="class_applying">Class Applying For *</label>
                            <select id="class_applying" name="class_applying" class="form-control" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo e($class['class_name']); ?>"
                                    <?php echo ($_POST['class_applying'] ?? '') === $class['class_name'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['class_name'] . ' ' . $class['section']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="academic_session">Academic Session</label>
                            <select id="academic_session" name="academic_session" class="form-control">
                                <option value="2026/2027">2026/2027 Session</option>
                                <option value="2025/2026">2025/2026 Session</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="blood_group">Blood Group</label>
                            <select id="blood_group" name="blood_group" class="form-control">
                                <option value="">Optional</option>
                                <?php foreach (['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'] as $bg): ?>
                                    <option value="<?php echo $bg; ?>"><?php echo $bg; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="genotype">Genotype</label>
                            <select id="genotype" name="genotype" class="form-control">
                                <option value="">Optional</option>
                                <?php foreach (['AA', 'AS', 'AC', 'SS'] as $gt): ?>
                                    <option value="<?php echo $gt; ?>"><?php echo $gt; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="previous_school">Previous School (if any)</label>
                        <input type="text" id="previous_school" name="previous_school"
                               value="<?php echo htmlspecialchars($_POST['previous_school'] ?? ''); ?>"
                               class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="medical_conditions">Medical Conditions / Allergies (if any)</label>
                        <textarea id="medical_conditions" name="medical_conditions" class="form-control" rows="2"><?php echo htmlspecialchars($_POST['medical_conditions'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-navigation">
                        <button type="button" class="btn btn-primary" onclick="nextStep(1)">
                            Next Step <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <!-- Step 2: Parent Information -->
                <div class="form-step" id="step2">
                    <h3><i class="fas fa-users"></i> Parent/Guardian Information</h3>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="parent_title">Title</label>
                            <select id="parent_title" name="parent_title" class="form-control">
                                <option value="Mr" <?php echo ($_POST['parent_title'] ?? '') === 'Mr' ? 'selected' : ''; ?>>Mr.</option>
                                <option value="Mrs" <?php echo ($_POST['parent_title'] ?? '') === 'Mrs' ? 'selected' : ''; ?>>Mrs.</option>
                                <option value="Ms" <?php echo ($_POST['parent_title'] ?? '') === 'Ms' ? 'selected' : ''; ?>>Ms.</option>
                                <option value="Dr" <?php echo ($_POST['parent_title'] ?? '') === 'Dr' ? 'selected' : ''; ?>>Dr.</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="parent_relationship">Relationship to Child</label>
                            <select id="parent_relationship" name="parent_relationship" class="form-control">
                                <option value="Father">Father</option>
                                <option value="Mother">Mother</option>
                                <option value="Guardian">Legal Guardian</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="parent_first_name">First Name *</label>
                            <input type="text" id="parent_first_name" name="parent_first_name"
                                   value="<?php echo htmlspecialchars($_POST['parent_first_name'] ?? ''); ?>"
                                   class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="parent_last_name">Last Name *</label>
                            <input type="text" id="parent_last_name" name="parent_last_name"
                                   value="<?php echo htmlspecialchars($_POST['parent_last_name'] ?? ''); ?>"
                                   class="form-control" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="parent_email">Email Address *</label>
                            <input type="email" id="parent_email" name="parent_email"
                                   value="<?php echo htmlspecialchars($_POST['parent_email'] ?? ''); ?>"
                                   class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="parent_phone">Phone Number *</label>
                            <input type="tel" id="parent_phone" name="parent_phone"
                                   value="<?php echo htmlspecialchars($_POST['parent_phone'] ?? ''); ?>"
                                   class="form-control" placeholder="08012345678" required>
                            <small class="form-text text-muted">Outside Nigeria? Include your country code, e.g. +447911123456</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="parent_occupation">Occupation</label>
                        <input type="text" id="parent_occupation" name="parent_occupation"
                               value="<?php echo htmlspecialchars($_POST['parent_occupation'] ?? ''); ?>"
                               class="form-control">
                    </div>

                    <h4 style="margin-top: 20px;">Residential Address</h4>

                    <div class="form-group">
                        <label for="address">Street Address *</label>
                        <textarea id="address" name="address" class="form-control" rows="2" required><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="city">City / Town *</label>
                        <input type="text" id="city" name="city"
                               value="<?php echo htmlspecialchars($_POST['city'] ?? 'Enugu'); ?>"
                               class="form-control" required>
                    </div>
                    <?php location_fields($_POST, 'Enugu'); ?>

                    <div class="form-group">
                        <label for="how_hear">How did you hear about us?</label>
                        <select id="how_hear" name="how_hear" class="form-control">
                            <option value="">Select an option</option>
                            <option value="friend" <?php echo ($_POST['how_hear'] ?? '') === 'friend' ? 'selected' : ''; ?>>Friend/Family</option>
                            <option value="social" <?php echo ($_POST['how_hear'] ?? '') === 'social' ? 'selected' : ''; ?>>Social Media</option>
                            <option value="internet" <?php echo ($_POST['how_hear'] ?? '') === 'internet' ? 'selected' : ''; ?>>Internet Search</option>
                            <option value="newspaper" <?php echo ($_POST['how_hear'] ?? '') === 'newspaper' ? 'selected' : ''; ?>>Newspaper</option>
                            <option value="radio" <?php echo ($_POST['how_hear'] ?? '') === 'radio' ? 'selected' : ''; ?>>Radio</option>
                            <option value="other" <?php echo ($_POST['how_hear'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="reason_applying">Why are you choosing our school?</label>
                        <textarea id="reason_applying" name="reason_applying" class="form-control" rows="3"><?php echo htmlspecialchars($_POST['reason_applying'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-navigation">
                        <button type="button" class="btn btn-outline" onclick="prevStep(2)">
                            <i class="fas fa-arrow-left"></i> Previous
                        </button>
                        <button type="button" class="btn btn-primary" onclick="nextStep(2)">
                            Next Step <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <!-- Step 3: Emergency Contact -->
                <div class="form-step" id="step3">
                    <h3><i class="fas fa-phone-alt"></i> Emergency Contact</h3>
                    <p class="info-text">Please provide an emergency contact person (different from parent/guardian)</p>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="emergency_name">Full Name *</label>
                            <input type="text" id="emergency_name" name="emergency_name"
                                   value="<?php echo htmlspecialchars($_POST['emergency_name'] ?? ''); ?>"
                                   class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="emergency_phone">Phone Number *</label>
                            <input type="tel" id="emergency_phone" name="emergency_phone"
                                   value="<?php echo htmlspecialchars($_POST['emergency_phone'] ?? ''); ?>"
                                   class="form-control" placeholder="08012345678" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="emergency_relationship">Relationship to Child *</label>
                        <input type="text" id="emergency_relationship" name="emergency_relationship"
                               value="<?php echo htmlspecialchars($_POST['emergency_relationship'] ?? ''); ?>"
                               class="form-control" placeholder="e.g., Grandparent, Aunt, Uncle" required>
                    </div>

                    <div class="form-navigation">
                        <button type="button" class="btn btn-outline" onclick="prevStep(3)">
                            <i class="fas fa-arrow-left"></i> Previous
                        </button>
                        <button type="button" class="btn btn-primary" onclick="nextStep(3)">
                            Next Step <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <!-- Step 4: Required Documents Dropdown & Online Payment -->
                <div class="form-step" id="step4">
                    <h3><i class="fas fa-file-upload"></i> Required Documents &amp; Online Fee Payment</h3>

                    <?php renderSequentialDocumentsUploader(); ?>

                    <div style="margin-top: 24px;">
                        <?php renderOnlinePaymentSection(); ?>
                    </div>

                    <div class="terms-section">
                        <h4>Declaration</h4>
                        <div class="checkbox-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="declaration" required>
                                I declare that the information provided is true and accurate to the best of my knowledge.
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="terms" required>
                                I have read and agree to the school's terms and conditions and privacy policy.
                            </label>
                        </div>
                    </div>

                    <div class="form-navigation">
                        <button type="button" class="btn btn-outline" onclick="prevStep(4)">
                            <i class="fas fa-arrow-left"></i> Previous
                        </button>
                        <?php if (function_exists('turnstile_widget')) echo turnstile_widget('auto', 'apply'); ?>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-paper-plane"></i> Submit Application &amp; Generate Receipt
                        </button>
                    </div>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
</section>

<style>
/* Step 1 Child Passport Card */
.child-passport-card {
    display: flex;
    align-items: center;
    gap: 22px;
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    border-radius: 14px;
    padding: 18px 22px;
    margin-bottom: 24px;
    box-shadow: 0 2px 10px rgba(15, 23, 42, 0.03);
}
.passport-avatar-wrap {
    flex-shrink: 0;
}
.passport-avatar-box {
    width: 110px;
    height: 130px;
    border: 2px dashed #94a3b8;
    border-radius: 12px;
    background: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    position: relative;
    box-shadow: inset 0 2px 6px rgba(0,0,0,0.05);
}
.passport-avatar-box img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.passport-placeholder-box {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 8px;
    color: #64748b;
}
.passport-placeholder-box i {
    font-size: 2rem;
    color: #94a3b8;
    margin-bottom: 6px;
}
.passport-placeholder-box span {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #475569;
}
.passport-details-wrap {
    flex: 1;
}
.passport-input-title {
    font-size: 1rem;
    font-weight: 700;
    color: #002147;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.passport-input-hint {
    font-size: 0.85rem;
    color: #475569;
    margin-bottom: 12px;
    line-height: 1.45;
}
.passport-action-controls {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.btn-passport-pick {
    background: #002147;
    color: #FFD700;
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
    margin-bottom: 0;
}
.btn-passport-pick:hover {
    background: #003366;
    color: #ffffff;
}
.passport-hidden-input {
    display: none;
}
.passport-filename-label {
    font-size: 0.82rem;
    color: #64748b;
    font-weight: 600;
}
.btn-passport-clear {
    background: #fef2f2;
    color: #dc2626;
    border: 1px solid #fecaca;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 0.78rem;
    font-weight: 600;
    cursor: pointer;
}
@media (max-width: 640px) {
    .child-passport-card {
        flex-direction: column;
        text-align: center;
        gap: 14px;
    }
    .passport-input-title {
        justify-content: center;
    }
    .passport-action-controls {
        justify-content: center;
    }
}

/* Application Progress */
.application-progress {
    display: flex;
    justify-content: space-between;
    margin: 40px 0;
    position: relative;
}

.application-progress::before {
    content: '';
    position: absolute;
    top: 25px;
    left: 0;
    right: 0;
    height: 2px;
    background: var(--light-gray);
    z-index: 1;
}

.progress-step {
    position: relative;
    z-index: 2;
    background: var(--white);
    text-align: center;
    flex: 1;
}

.step-number {
    display: block;
    width: 50px;
    height: 50px;
    background: var(--light-gray);
    border-radius: 50%;
    margin: 0 auto 10px;
    line-height: 50px;
    font-weight: 700;
    color: var(--gray);
    transition: all 0.3s ease;
}

.step-label {
    font-size: 0.9rem;
    color: var(--gray);
}

.progress-step.active .step-number {
    background: var(--navy);
    color: var(--white);
}

.progress-step.active .step-label {
    color: var(--navy);
    font-weight: 500;
}

.progress-step.completed .step-number {
    background: var(--success);
    color: var(--white);
}

/* Application Card */
.application-card {
    background: var(--white);
    border-radius: 20px;
    padding: 40px;
    box-shadow: var(--shadow-lg);
    margin-bottom: 40px;
}

.form-step {
    display: none;
}

.form-step.active {
    display: block;
    animation: fadeIn 0.5s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.form-step h3 {
    color: var(--navy);
    margin-bottom: 25px;
    padding-bottom: 15px;
    border-bottom: 2px solid var(--gold);
}

.form-step h3 i {
    color: var(--gold);
    margin-right: 10px;
}

.info-text {
    color: var(--info);
    background: rgba(23, 162, 184, 0.1);
    padding: 10px 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.form-navigation {
    display: flex;
    justify-content: space-between;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid var(--light-gray);
}

/* Terms Section */
.terms-section {
    background: var(--light-gray);
    border-radius: 15px;
    padding: 20px;
    margin-top: 20px;
}

.terms-section h4 {
    color: var(--navy);
    margin-bottom: 15px;
}

.checkbox-group {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
}

.checkbox-label input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

/* Success Card */
.success-card {
    background: var(--white);
    border-radius: 20px;
    padding: 50px;
    box-shadow: var(--shadow-lg);
    text-align: center;
    max-width: 800px;
    margin: 40px auto;
}

.success-card h3 {
    color: var(--navy);
    margin: 20px 0 10px;
}

.success-card p {
    color: var(--gray);
    margin-bottom: 10px;
}

/* Responsive */
@media (max-width: 768px) {
    .application-progress {
        flex-direction: column;
        gap: 15px;
    }

    .application-progress::before {
        display: none;
    }

    .progress-step {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .step-number {
        margin: 0;
    }

    .application-card {
        padding: 20px;
    }

    .form-navigation {
        flex-direction: column;
        gap: 10px;
    }

    .form-navigation button {
        width: 100%;
    }
}
</style>

<script nonce="<?php echo CSP_NONCE; ?>">
let currentStep = 1;

// Step 1 Child Passport Photo Live Preview & Sync
document.addEventListener('DOMContentLoaded', function() {
    const passportInput = document.getElementById('child_passport_photo');
    const liveImg = document.getElementById('passportLivePreviewImg');
    const placeholder = document.getElementById('passportPlaceholderBox');
    const filenameLabel = document.getElementById('passportSelectedFilename');
    const clearBtn = document.getElementById('clearPassportPhotoBtn');
    const avatarBox = document.getElementById('passportAvatarBox');

    if (passportInput) {
        passportInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                const file = this.files[0];
                if (file.size > 5 * 1024 * 1024) {
                    alert('Photo file size exceeds 5MB limit. Please select a smaller photo.');
                    this.value = '';
                    return;
                }
                const reader = new FileReader();
                reader.onload = function(e) {
                    liveImg.src = e.target.result;
                    liveImg.style.display = 'block';
                    placeholder.style.display = 'none';
                    avatarBox.style.border = '2px solid #002147';
                    filenameLabel.textContent = file.name + ' (' + Math.round(file.size / 1024) + ' KB)';
                    clearBtn.style.display = 'inline-block';

                    // Sync with Step 4 native passport_photo input if available
                    const step4Inp = document.getElementById('doc_input_passport_photo');
                    if (step4Inp && window.DataTransfer) {
                        try {
                            const dt = new DataTransfer();
                            dt.items.add(file);
                            step4Inp.files = dt.files;
                            step4Inp.dispatchEvent(new Event('change'));
                        } catch(e) {}
                    }
                };
                reader.readAsDataURL(file);
            }
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            if (passportInput) passportInput.value = '';
            liveImg.src = '';
            liveImg.style.display = 'none';
            placeholder.style.display = 'flex';
            avatarBox.style.border = '2px dashed #94a3b8';
            filenameLabel.textContent = 'No photo chosen yet';
            clearBtn.style.display = 'none';
        });
    }

    // Guard on Form Submission: Require online payment to be completed if Paystack is selected
    const appForm = document.getElementById('applicationForm');
    if (appForm) {
        appForm.addEventListener('submit', function(e) {
            const selectedMethodRadio = document.querySelector('input[name="payment_method"]:checked');
            const methodVal = selectedMethodRadio ? selectedMethodRadio.value : 'paystack_card';
            const verifiedUi = document.getElementById('input_payment_verified_ui')?.value;

            const isOnlineMethod = (methodVal.indexOf('paystack') !== -1 || methodVal === 'online_card');

            if (isOnlineMethod && verifiedUi !== '1') {
                e.preventDefault();
                alert("Online Payment Required Before Submission:\n\nYou selected online payment via Paystack, but payment of ₦15,000 has not been made or verified.\n\nPlease click the 'Pay ₦15,000 on Paystack' button to complete payment before submitting.\n\nAlternatively, if you prefer to make payment at the school admissions office, please select 'Pay at School & Print Application Slip'.");
                const paySec = document.getElementById('onlinePaymentSection');
                if (paySec) {
                    paySec.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return false;
            }
        });
    }
});

function nextStep(step) {
    if (!validateStep(step)) {
        return;
    }

    document.getElementById(`step${step}`).classList.remove('active');
    document.getElementById(`step${step + 1}`).classList.add('active');

    document.querySelectorAll('.progress-step')[step].classList.add('completed');
    document.querySelectorAll('.progress-step')[step + 1].classList.add('active');

    currentStep = step + 1;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function prevStep(step) {
    document.getElementById(`step${step}`).classList.remove('active');
    document.getElementById(`step${step - 1}`).classList.add('active');

    document.querySelectorAll('.progress-step')[step - 1].classList.remove('completed');
    document.querySelectorAll('.progress-step')[step - 1].classList.add('active');
    document.querySelectorAll('.progress-step')[step].classList.remove('active');

    currentStep = step - 1;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function validateStep(step) {
    switch(step) {
        case 1:
            const childFirst = document.getElementById('child_first_name').value;
            const childLast = document.getElementById('child_last_name').value;
            const childDob = document.getElementById('child_dob').value;
            const childGender = document.getElementById('child_gender').value;
            const classApply = document.getElementById('class_applying').value;

            const passportInp = document.getElementById('child_passport_photo');
            const liveImg = document.getElementById('passportLivePreviewImg');
            const hasPassport = (passportInp && passportInp.files && passportInp.files.length > 0) || (liveImg && liveImg.src && liveImg.src.indexOf('data:') === 0);

            if (!childFirst || !childLast || !childDob || !childGender || !classApply) {
                alert('Please fill in all required fields');
                return false;
            }

            if (!hasPassport) {
                alert("Please select and upload the Child's Passport Photograph on Step 1 before proceeding.");
                document.getElementById('childPassportCard')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }

            const dob = new Date(childDob);
            const today = new Date();
            if (dob > today) {
                alert('Please enter a valid date of birth');
                return false;
            }
            break;

        case 2:
            const parentFirst = document.getElementById('parent_first_name').value;
            const parentLast = document.getElementById('parent_last_name').value;
            const parentEmail = document.getElementById('parent_email').value;
            const parentPhone = document.getElementById('parent_phone').value;
            const address = document.getElementById('address').value;
            const city = document.getElementById('city').value;
            const state = document.getElementById('loc_state').value;
            const abroad = state === '__other';
            if (abroad && (!document.getElementById('loc_country').value.trim() || !document.getElementById('loc_state_other').value.trim())) {
                alert('Please enter your country and state/province/region');
                return false;
            }
            if (!abroad && !document.getElementById('loc_lga').value) {
                alert('Please select your local government area');
                return false;
            }

            if (!parentFirst || !parentLast || !parentEmail || !parentPhone || !address || !city || !state) {
                alert('Please fill in all required fields');
                return false;
            }

            if (!isValidEmail(parentEmail)) {
                alert('Please enter a valid email address');
                return false;
            }

            if (abroad ? !/^\+?[0-9][0-9\s\-().]{5,22}$/.test(parentPhone) : !isValidPhone(parentPhone)) {
                alert(abroad ? 'Please enter your phone number with country code (e.g., +447911123456)' : 'Please enter a valid Nigerian phone number (e.g., 08012345678)');
                return false;
            }
            break;

        case 3:
            const emergName = document.getElementById('emergency_name').value;
            const emergPhone = document.getElementById('emergency_phone').value;
            const emergRelation = document.getElementById('emergency_relationship').value;

            if (!emergName || !emergPhone || !emergRelation) {
                alert('Please fill in all emergency contact fields');
                return false;
            }

            if (!isValidPhone(emergPhone)) {
                alert('Please enter a valid Nigerian phone number for emergency contact');
                return false;
            }
            break;
    }

    return true;
}

function isValidEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

function isValidPhone(phone) {
    const re = /^0[789][01]\d{8}$/;
    return re.test(phone);
}
</script>

<?php
$extraJS = ['location-picker.js'];
include '../includes/footer.php';
?>
