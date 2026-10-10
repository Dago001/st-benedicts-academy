<?php
// public/apply.php - Online Application Form
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';

$pageTitle = 'Apply Now - Online Admission Application';
$pageDescription = 'Apply online for admission to ST. BENEDICT\'S EARLY YEARS BRITISH ACADEMY. Start your child\'s educational journey with us.';

include __DIR__ . '/../includes/header.php';

$db = db();
$message = '';
$messageType = '';

// Get available classes
$classes = $db->getRows(
    "SELECT * FROM classes WHERE is_active = 1 ORDER BY class_name"
);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session expired. Please reload the page and try again.';
    } elseif (!empty($_POST['website'])) {
        // Honeypot field filled in: pretend success, store nothing
        $message = 'Thank you.';
        $messageType = 'success';
    } elseif (($_SESSION['apply_attempts'][date('YmdH')] ?? 0) >= 5) {
        $errors[] = 'Too many submissions from this device. Please try again later or call the school office.';
    } else {
        $_SESSION['apply_attempts'] = [date('YmdH') => ($_SESSION['apply_attempts'][date('YmdH')] ?? 0) + 1];

        $text = function ($key, $max = 100) {
            return mb_substr(Security::sanitize($_POST[$key] ?? ''), 0, $max);
        };
        $classNames = array_column($classes, 'class_name');
        $formData = [
            'child_first_name' => $text('child_first_name', 50),
            'child_last_name' => $text('child_last_name', 50),
            'child_dob' => $_POST['child_dob'] ?? '',
            'child_gender' => $_POST['child_gender'] ?? '',
            'class_applying' => $text('class_applying', 50),
            'parent_title' => in_array($_POST['parent_title'] ?? '', ['Mr', 'Mrs', 'Ms', 'Dr', 'Chief', 'Prof'], true) ? $_POST['parent_title'] : '',
            'parent_first_name' => $text('parent_first_name', 50),
            'parent_last_name' => $text('parent_last_name', 50),
            'parent_email' => $text('parent_email', 100),
            'parent_phone' => $text('parent_phone', 20),
            'parent_occupation' => $text('parent_occupation', 100),
            'address' => $text('address', 500),
            'city' => $text('city', 100),
            'previous_school' => $text('previous_school', 200),
            'reason_applying' => $text('reason_applying', 1000),
            'how_hear' => $text('how_hear', 100),
            'emergency_name' => $text('emergency_name', 100),
            'emergency_phone' => $text('emergency_phone', 20),
            'emergency_relationship' => $text('emergency_relationship', 50),
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
        if (!$dob) {
            $errors[] = "Child's date of birth is required";
        } else {
            $age = (new DateTime())->diff(new DateTime($dob))->y;
            if ($dob > date('Y-m-d') || $age < 2 || $age > 7) $errors[] = 'Child must be between 2 and 7 years old';
        }

        // Uploads: validated by real content type, stored outside the public web root
        $stored = ['birth_certificate' => null, 'passport_photo' => null];
        $uploadRules = ['birth_certificate' => ['pdf', 'jpg', 'jpeg', 'png'], 'passport_photo' => ['jpg', 'jpeg', 'png']];
        if (!$errors) {
            $privateDir = PRIVATE_PATH . 'applications/';
            if (!is_dir($privateDir)) { @mkdir($privateDir, 0750, true); }
            foreach ($uploadRules as $field => $allowed) {
                if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) continue;
                $check = Security::validateFileUpload($_FILES[$field], $allowed);
                if (!$check['valid']) {
                    $errors[] = ucwords(str_replace('_', ' ', $field)) . ': ' . $check['message'];
                    continue;
                }
                $name = $field . '_' . bin2hex(random_bytes(12)) . '.' . $check['extension'];
                if (move_uploaded_file($_FILES[$field]['tmp_name'], $privateDir . $name)) {
                    @chmod($privateDir . $name, 0640);
                    $stored[$field] = $name;
                } else {
                    $errors[] = 'Could not store ' . str_replace('_', ' ', $field);
                }
            }
        }

        if (!$errors) {
            try {
                $appNumber = generateApplicationNumber();
                $db->insert(
                    "INSERT INTO applications (
                        application_number, child_first_name, child_last_name, child_dob, child_gender,
                        class_applying, parent_title, parent_first_name, parent_last_name,
                        parent_email, parent_phone, parent_occupation, address, city, state, lga, country,
                        previous_school, reason_applying, how_hear,
                        emergency_name, emergency_phone, emergency_relationship,
                        birth_certificate_path, passport_photo_path
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $appNumber, $formData['child_first_name'], $formData['child_last_name'], $dob, $formData['child_gender'],
                        $formData['class_applying'], $formData['parent_title'], $formData['parent_first_name'], $formData['parent_last_name'],
                        $formData['parent_email'], $formData['parent_phone'], $formData['parent_occupation'], $formData['address'],
                        $formData['city'], $loc['state'] ?? '', $loc['lga'] ?? '', $loc['country'] ?? 'Nigeria', $formData['previous_school'], $formData['reason_applying'], $formData['how_hear'],
                        $formData['emergency_name'], $formData['emergency_phone'], $formData['emergency_relationship'],
                        $stored['birth_certificate'], $stored['passport_photo'],
                    ]
                );

                $name = e(trim($formData['parent_title'] . ' ' . $formData['parent_last_name']));
                sendEmail($formData['parent_email'], 'Application Received - ' . SCHOOL_NAME,
                    "<div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto'>"
                    . "<h2 style='background:#002855;color:#fff;padding:16px;text-align:center'>Application Received</h2>"
                    . "<p>Dear $name,</p><p>Thank you for applying to <strong>" . e(SCHOOL_NAME) . "</strong>.</p>"
                    . "<ul><li><strong>Application number:</strong> " . e($appNumber) . "</li>"
                    . "<li><strong>Child:</strong> " . e($formData['child_first_name'] . ' ' . $formData['child_last_name']) . "</li>"
                    . "<li><strong>Class:</strong> " . e($formData['class_applying']) . "</li></ul>"
                    . "<p>We will contact you within 3-5 working days to schedule an assessment. Questions? Call " . e(school_phone()) . ".</p>"
                    . "<p><strong>Admissions Office</strong><br>" . e(SCHOOL_NAME) . "</p></div>");

                $applied = ['number' => $appNumber, 'email' => $formData['parent_email']];
                $messageType = 'success';
                $_POST = [];
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
        <div class="success-card">
            <div style="text-align:center">
                <i class="fas fa-check-circle" style="font-size:4rem;color:#28a745;margin-bottom:20px"></i>
                <h3>Application Submitted Successfully!</h3>
                <?php if (!empty($applied)): ?>
                <p>Your application number is: <strong><?php echo e($applied['number']); ?></strong></p>
                <p>We have sent a confirmation email to <strong><?php echo e($applied['email']); ?></strong>.</p>
                <?php endif; ?>
                <p>Our admissions team will contact you within 3-5 working days.</p>
                <ol style="text-align:left;max-width:400px;margin:20px auto">
                    <li>Wait for our call to schedule an assessment</li>
                    <li>Bring your child for the assessment/interview</li>
                    <li>Receive the admission decision</li>
                    <li>Complete enrolment if accepted</li>
                </ol>
            </div>
            <div style="margin-top: 30px;">
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
                <span class="step-label">Documents</span>
            </div>
        </div>

        <div class="application-card">
            <form method="POST" enctype="multipart/form-data" id="applicationForm">
                <?php echo csrf_field(); ?>
                <div style="position:absolute;left:-9999px" aria-hidden="true"><label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

                <!-- Step 1: Child Information -->
                <div class="form-step active" id="step1">
                    <h3><i class="fas fa-child"></i> Child's Information</h3>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="child_first_name">First Name *</label>
                            <input type="text" id="child_first_name" name="child_first_name"
                                   value="<?php echo htmlspecialchars($_POST['child_first_name'] ?? ''); ?>"
                                   class="form-control" required>
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
                                   max="<?php echo date('Y-m-d', strtotime('-2 years')); ?>"
                                   min="<?php echo date('Y-m-d', strtotime('-7 years')); ?>">
                            <small class="form-text">Child must be between 2 and 7 years old</small>
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
                        <label for="previous_school">Previous School (if any)</label>
                        <input type="text" id="previous_school" name="previous_school"
                               value="<?php echo htmlspecialchars($_POST['previous_school'] ?? ''); ?>"
                               class="form-control">
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

                    <div class="form-group">
                        <label for="parent_title">Title</label>
                        <select id="parent_title" name="parent_title" class="form-control">
                            <option value="Mr" <?php echo ($_POST['parent_title'] ?? '') === 'Mr' ? 'selected' : ''; ?>>Mr.</option>
                            <option value="Mrs" <?php echo ($_POST['parent_title'] ?? '') === 'Mrs' ? 'selected' : ''; ?>>Mrs.</option>
                            <option value="Ms" <?php echo ($_POST['parent_title'] ?? '') === 'Ms' ? 'selected' : ''; ?>>Ms.</option>
                            <option value="Dr" <?php echo ($_POST['parent_title'] ?? '') === 'Dr' ? 'selected' : ''; ?>>Dr.</option>
                        </select>
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

                <!-- Step 4: Documents -->
                <div class="form-step" id="step4">
                    <h3><i class="fas fa-file-upload"></i> Documents Upload</h3>

                    <div class="upload-area">
                        <div class="upload-box">
                            <i class="fas fa-file-pdf"></i>
                            <h4>Birth Certificate</h4>
                            <p>Upload a scanned copy of child's birth certificate (PDF, JPG, PNG, max 5MB)</p>
                            <input type="file" id="birth_certificate" name="birth_certificate"
                                   accept=".pdf,.jpg,.jpeg,.png" class="file-input">
                            <div class="file-info" id="birthCertInfo"></div>
                        </div>

                        <div class="upload-box">
                            <i class="fas fa-camera-retro"></i>
                            <h4>Passport Photograph</h4>
                            <p>Upload a recent passport photo of the child (JPG, PNG, max 5MB)</p>
                            <input type="file" id="passport_photo" name="passport_photo"
                                   accept=".jpg,.jpeg,.png" class="file-input">
                            <div class="file-info" id="photoInfo"></div>
                        </div>
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
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-paper-plane"></i> Submit Application
                        </button>
                    </div>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
</section>

<style>
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

/* Upload Area */
.upload-area {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
    margin-bottom: 30px;
}

.upload-box {
    border: 2px dashed var(--medium-gray);
    border-radius: 15px;
    padding: 30px;
    text-align: center;
    transition: all 0.3s ease;
}

.upload-box:hover {
    border-color: var(--gold);
    background: rgba(255,215,0,0.05);
}

.upload-box i {
    font-size: 3rem;
    color: var(--gold);
    margin-bottom: 15px;
}

.upload-box h4 {
    color: var(--navy);
    margin-bottom: 10px;
}

.upload-box p {
    color: var(--gray);
    font-size: 0.9rem;
    margin-bottom: 15px;
}

.file-input {
    width: 100%;
    padding: 10px;
    border: 1px solid var(--light-gray);
    border-radius: 8px;
    cursor: pointer;
}

.file-info {
    margin-top: 10px;
    font-size: 0.85rem;
    color: var(--success);
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

    .upload-area {
        grid-template-columns: 1fr;
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

function nextStep(step) {
    if (!validateStep(step)) {
        return;
    }

    document.getElementById(`step${step}`).classList.remove('active');
    document.getElementById(`step${step + 1}`).classList.add('active');

    // Update progress indicators
    document.querySelectorAll('.progress-step')[step].classList.add('completed');
    document.querySelectorAll('.progress-step')[step + 1].classList.add('active');

    currentStep = step + 1;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function prevStep(step) {
    document.getElementById(`step${step}`).classList.remove('active');
    document.getElementById(`step${step - 1}`).classList.add('active');

    // Update progress indicators
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

            if (!childFirst || !childLast || !childDob || !childGender || !classApply) {
                alert('Please fill in all required fields');
                return false;
            }

            // Validate age
            const dob = new Date(childDob);
            const today = new Date();
            const age = today.getFullYear() - dob.getFullYear();
            if (age < 2 || age > 7) {
                alert('Child must be between 2 and 7 years old');
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

// File upload preview
document.getElementById('birth_certificate')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        document.getElementById('birthCertInfo').textContent = `Selected: ${file.name} (${(file.size / 1024).toFixed(2)} KB)`;
    }
});

document.getElementById('passport_photo')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        document.getElementById('photoInfo').textContent = `Selected: ${file.name} (${(file.size / 1024).toFixed(2)} KB)`;
    }
});

// Age calculation
document.getElementById('child_dob')?.addEventListener('change', function() {
    const dob = new Date(this.value);
    const today = new Date();
    const age = today.getFullYear() - dob.getFullYear();
    const monthDiff = today.getMonth() - dob.getMonth();

    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
        age--;
    }

    if (age < 2) {
        alert('Child is too young for admission. Minimum age is 2 years.');
    } else if (age > 7) {
        alert('Child is above the maximum age for our early years program.');
    }
});

// Form submission
document.getElementById('applicationForm')?.addEventListener('submit', function(e) {
    if (!validateStep(4)) {
        e.preventDefault();
    }
});
</script>

<?php
$extraJS = ['location-picker.js'];
include '../includes/footer.php';
?>