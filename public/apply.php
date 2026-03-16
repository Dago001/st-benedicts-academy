<?php
// public/apply.php - Online Application Form
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/security.php';

$pageTitle = 'Apply Now - Online Admission Application';
$pageDescription = 'Apply online for admission to ST. BENEDICT\'S EARLY YEARS BRITISH ACADEMY. Start your child\'s educational journey with us.';

include '../includes/header.php';

$db = db();
$message = '';
$messageType = '';

// Get available classes
$classes = $db->getRows(
    "SELECT * FROM classes WHERE is_active = 1 ORDER BY class_name"
);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token';
        $messageType = 'error';
    } else {
        // Sanitize input
        $formData = [
            'child_first_name' => Security::sanitize($_POST['child_first_name'] ?? ''),
            'child_last_name' => Security::sanitize($_POST['child_last_name'] ?? ''),
            'child_dob' => Security::sanitize($_POST['child_dob'] ?? ''),
            'child_gender' => Security::sanitize($_POST['child_gender'] ?? ''),
            'class_applying' => Security::sanitize($_POST['class_applying'] ?? ''),
            'parent_title' => Security::sanitize($_POST['parent_title'] ?? ''),
            'parent_first_name' => Security::sanitize($_POST['parent_first_name'] ?? ''),
            'parent_last_name' => Security::sanitize($_POST['parent_last_name'] ?? ''),
            'parent_email' => Security::sanitize($_POST['parent_email'] ?? ''),
            'parent_phone' => Security::sanitize($_POST['parent_phone'] ?? ''),
            'parent_occupation' => Security::sanitize($_POST['parent_occupation'] ?? ''),
            'address' => Security::sanitize($_POST['address'] ?? ''),
            'city' => Security::sanitize($_POST['city'] ?? ''),
            'state' => Security::sanitize($_POST['state'] ?? ''),
            'previous_school' => Security::sanitize($_POST['previous_school'] ?? ''),
            'reason_applying' => Security::sanitize($_POST['reason_applying'] ?? ''),
            'how_hear' => Security::sanitize($_POST['how_hear'] ?? ''),
            'emergency_name' => Security::sanitize($_POST['emergency_name'] ?? ''),
            'emergency_phone' => Security::sanitize($_POST['emergency_phone'] ?? ''),
            'emergency_relationship' => Security::sanitize($_POST['emergency_relationship'] ?? '')
        ];
        
        // Validate
        $errors = [];
        
        if (empty($formData['child_first_name'])) $errors[] = 'Child\'s first name is required';
        if (empty($formData['child_last_name'])) $errors[] = 'Child\'s last name is required';
        if (empty($formData['child_dob'])) $errors[] = 'Child\'s date of birth is required';
        if (empty($formData['child_gender'])) $errors[] = 'Child\'s gender is required';
        if (empty($formData['class_applying'])) $errors[] = 'Class applying for is required';
        if (empty($formData['parent_first_name'])) $errors[] = 'Parent\'s first name is required';
        if (empty($formData['parent_last_name'])) $errors[] = 'Parent\'s last name is required';
        if (!filter_var($formData['parent_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid parent email is required';
        if (empty($formData['parent_phone'])) $errors[] = 'Parent phone is required';
        if (empty($formData['address'])) $errors[] = 'Address is required';
        
        // Validate child's age
        $dob = new DateTime($formData['child_dob']);
        $now = new DateTime();
        $age = $now->diff($dob)->y;
        
        if ($age < 2 || $age > 7) {
            $errors[] = 'Child must be between 2 and 7 years old';
        }
        
        if (empty($errors)) {
            try {
                // Generate application number
                $appNumber = 'APP' . date('Y') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                // Handle document uploads
                $birthCertPath = null;
                $photoPath = null;
                
                // Upload birth certificate
                if (isset($_FILES['birth_certificate']) && $_FILES['birth_certificate']['error'] === UPLOAD_ERR_OK) {
                    $upload = Validator::file($_FILES['birth_certificate'], ['application/pdf', 'image/jpeg', 'image/png'], 5242880);
                    if ($upload['valid']) {
                        $fileName = 'birth_' . $appNumber . '_' . time() . '.' . pathinfo($_FILES['birth_certificate']['name'], PATHINFO_EXTENSION);
                        $uploadPath = UPLOAD_PATH . 'applications/' . $fileName;
                        if (move_uploaded_file($_FILES['birth_certificate']['tmp_name'], $uploadPath)) {
                            $birthCertPath = $fileName;
                        }
                    }
                }
                
                // Upload passport photo
                if (isset($_FILES['passport_photo']) && $_FILES['passport_photo']['error'] === UPLOAD_ERR_OK) {
                    $upload = Validator::image($_FILES['passport_photo']);
                    if ($upload['valid']) {
                        $fileName = 'photo_' . $appNumber . '_' . time() . '.' . pathinfo($_FILES['passport_photo']['name'], PATHINFO_EXTENSION);
                        $uploadPath = UPLOAD_PATH . 'applications/' . $fileName;
                        if (move_uploaded_file($_FILES['passport_photo']['tmp_name'], $uploadPath)) {
                            $photoPath = $fileName;
                        }
                    }
                }
                
                // Save to database
                $db->insert(
                    "INSERT INTO applications (
                        application_number, child_first_name, child_last_name, child_dob, child_gender,
                        class_applying, parent_title, parent_first_name, parent_last_name,
                        parent_email, parent_phone, parent_occupation, address, city, state,
                        previous_school, reason_applying, how_hear,
                        emergency_name, emergency_phone, emergency_relationship,
                        birth_certificate_path, passport_photo_path
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                    )",
                    [
                        $appNumber,
                        $formData['child_first_name'],
                        $formData['child_last_name'],
                        $formData['child_dob'],
                        $formData['child_gender'],
                        $formData['class_applying'],
                        $formData['parent_title'],
                        $formData['parent_first_name'],
                        $formData['parent_last_name'],
                        $formData['parent_email'],
                        $formData['parent_phone'],
                        $formData['parent_occupation'],
                        $formData['address'],
                        $formData['city'],
                        $formData['state'],
                        $formData['previous_school'],
                        $formData['reason_applying'],
                        $formData['how_hear'],
                        $formData['emergency_name'],
                        $formData['emergency_phone'],
                        $formData['emergency_relationship'],
                        $birthCertPath,
                        $photoPath
                    ]
                );
                
                // Send confirmation email
                $to = $formData['parent_email'];
                $subject = "Application Received - " . SCHOOL_NAME;
                $emailMessage = "
                <html>
                <body style='font-family: Arial, sans-serif;'>
                    <div style='max-width: 600px; margin: 0 auto; padding: 20px; background: #f5f5f5;'>
                        <div style='background: #002855; color: white; padding: 20px; text-align: center;'>
                            <h2>Application Received</h2>
                        </div>
                        <div style='background: white; padding: 30px;'>
                            <p>Dear {$formData['parent_title']} {$formData['parent_last_name']},</p>
                            <p>Thank you for applying to <strong>" . SCHOOL_NAME . "</strong>.</p>
                            <p><strong>Application Details:</strong></p>
                            <ul>
                                <li><strong>Application Number:</strong> {$appNumber}</li>
                                <li><strong>Child's Name:</strong> {$formData['child_first_name']} {$formData['child_last_name']}</li>
                                <li><strong>Class Applying For:</strong> {$formData['class_applying']}</li>
                            </ul>
                            <p>We will review your application and contact you within 3-5 working days to schedule an assessment/interview.</p>
                            <p>If you have any questions, please contact our admissions office at " . SCHOOL_PHONE . ".</p>
                            <p>May God bless you,</p>
                            <p><strong>Admissions Office</strong><br>" . SCHOOL_NAME . "</p>
                        </div>
                        <div style='background: #f5f5f5; padding: 15px; text-align: center; font-size: 12px; color: #666;'>
                            <p>" . SCHOOL_ADDRESS . "<br>Phone: " . SCHOOL_PHONE . " | Email: " . SCHOOL_EMAIL . "</p>
                        </div>
                    </div>
                </body>
                </html>
                ";
                
                $headers = "MIME-Version: 1.0\r\n";
                $headers .= "Content-type:text/html;charset=UTF-8\r\n";
                $headers .= "From: " . SCHOOL_NAME . " <" . SCHOOL_EMAIL . ">\r\n";
                
                mail($to, $subject, $emailMessage, $headers);
                
                // Show success message
                $message = "
                <div style='text-align: center;'>
                    <i class='fas fa-check-circle' style='font-size: 4rem; color: #28a745; margin-bottom: 20px;'></i>
                    <h3>Application Submitted Successfully!</h3>
                    <p>Your application number is: <strong>{$appNumber}</strong></p>
                    <p>We have sent a confirmation email to: <strong>{$formData['parent_email']}</strong></p>
                    <p>Our admissions team will contact you within 3-5 working days.</p>
                    <hr style='margin: 30px 0;'>
                    <h4>Next Steps:</h4>
                    <ol style='text-align: left; max-width: 400px; margin: 20px auto;'>
                        <li>Wait for our call to schedule an assessment</li>
                        <li>Bring your child for the assessment/interview</li>
                        <li>Receive admission decision</li>
                        <li>Complete enrollment if accepted</li>
                    </ol>
                </div>
                ";
                $messageType = 'success';
                
                // Clear POST data
                $_POST = [];
                
            } catch (Exception $e) {
                $message = 'An error occurred. Please try again or contact us directly.';
                $messageType = 'error';
                error_log("Application error: " . $e->getMessage());
            }
        } else {
            $message = '<ul><li>' . implode('</li><li>', $errors) . '</li></ul>';
            $messageType = 'error';
        }
    }
}
?>

<!-- Page Header -->
<section class="page-header">
    <div class="container">
        <h1>Online Application</h1>
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>/index.php">Home</a> / Apply Now
        </div>
    </div>
</section>

<!-- Application Form -->
<section class="application-section">
    <div class="container">
        <?php if ($message && $messageType === 'success'): ?>
        <div class="success-card">
            <?php echo $message; ?>
            <div style="margin-top: 30px;">
                <a href="<?php echo BASE_URL; ?>/index.php" class="btn btn-primary">Return to Home</a>
                <a href="apply.php" class="btn btn-outline">Submit Another Application</a>
            </div>
        </div>
        <?php else: ?>
        
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>">
            <?php echo $message; ?>
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
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                
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
                            <option value="<?php echo $class['class_name']; ?>" 
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
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="city">City *</label>
                            <input type="text" id="city" name="city" 
                                   value="<?php echo htmlspecialchars($_POST['city'] ?? 'Enugu'); ?>" 
                                   class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="state">State *</label>
                            <input type="text" id="state" name="state" 
                                   value="<?php echo htmlspecialchars($_POST['state'] ?? 'Enugu'); ?>" 
                                   class="form-control" required>
                        </div>
                    </div>
                    
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

<script>
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
            const state = document.getElementById('state').value;
            
            if (!parentFirst || !parentLast || !parentEmail || !parentPhone || !address || !city || !state) {
                alert('Please fill in all required fields');
                return false;
            }
            
            if (!isValidEmail(parentEmail)) {
                alert('Please enter a valid email address');
                return false;
            }
            
            if (!isValidPhone(parentPhone)) {
                alert('Please enter a valid Nigerian phone number (e.g., 08012345678)');
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
include '../includes/footer.php';
?>