<?php
// public/admissions.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

// Check if header exists
$headerPath = __DIR__ . '/../includes/header.php';
if (!file_exists($headerPath)) {
    die("Error: Header file not found at: $headerPath");
}
include $headerPath;

// Define upload path if not defined
if (!defined('UPLOAD_PATH')) {
    define('UPLOAD_PATH', __DIR__ . '/../uploads/');
}

// Create upload directory if it doesn't exist
$uploadDir = UPLOAD_PATH . 'admissions/';
if (!file_exists($uploadDir)) {
    if (!mkdir($uploadDir, 0777, true)) {
        error_log("Failed to create upload directory: " . $uploadDir);
    }
}

// Get database instance
try {
    $db = Database::getInstance();
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    $db = null;
}

// Define email function if not exists
if (!function_exists('sendEmail')) {
    function sendEmail($to, $subject, $message) {
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: " . (defined('SCHOOL_NAME') ? SCHOOL_NAME : 'ST. BENEDICT\'S ACADEMY') . " <" . (defined('SCHOOL_EMAIL') ? SCHOOL_EMAIL : 'noreply@stbenedicts.edu.ng') . ">\r\n";
        
        return mail($to, $subject, $message, $headers);
    }
}

// Handle form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token. Please refresh the page and try again.';
        $messageType = 'error';
    } else {
        // Sanitize input
        $firstName = Security::sanitize($_POST['first_name'] ?? '');
        $middleName = Security::sanitize($_POST['middle_name'] ?? '');
        $lastName = Security::sanitize($_POST['last_name'] ?? '');
        $dob = Security::sanitize($_POST['dob'] ?? '');
        $gender = Security::sanitize($_POST['gender'] ?? '');
        $class = Security::sanitize($_POST['class'] ?? '');
        $parentName = Security::sanitize($_POST['parent_name'] ?? '');
        $parentEmail = Security::sanitize($_POST['parent_email'] ?? '');
        $parentPhone = Security::sanitize($_POST['parent_phone'] ?? '');
        $address = Security::sanitize($_POST['address'] ?? '');
        $previousSchool = Security::sanitize($_POST['previous_school'] ?? '');
        
        // Validate
        $errors = [];
        
        if (empty($firstName)) $errors[] = 'First name is required';
        if (empty($lastName)) $errors[] = 'Last name is required';
        if (empty($dob)) $errors[] = 'Date of birth is required';
        if (empty($gender)) $errors[] = 'Gender is required';
        if (empty($class)) $errors[] = 'Class is required';
        if (empty($parentName)) $errors[] = 'Parent name is required';
        
        // Validate email
        if (empty($parentEmail)) {
            $errors[] = 'Parent email is required';
        } elseif (!Security::validateEmail($parentEmail)) {
            $errors[] = 'Valid parent email is required';
        }
        
        // Validate phone
        if (empty($parentPhone)) {
            $errors[] = 'Parent phone is required';
        } elseif (!Security::validatePhone($parentPhone)) {
            $errors[] = 'Valid Nigerian phone number is required (e.g., 08012345678)';
        }
        
        if (empty($address)) $errors[] = 'Address is required';
        
        if (empty($errors)) {
            // Generate application number
            $appNumber = 'APP-' . date('Y') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            // Handle multiple file uploads
            $uploadedFiles = [];
            $uploadErrors = [];
            
            // Document types and their required status
            $documentTypes = [
                'birth_certificate' => 'Birth Certificate',
                'passport_photo' => 'Passport Photograph',
                'immunization_record' => 'Immunization Record',
                'previous_report' => 'Previous School Report'
            ];
            
            foreach ($documentTypes as $key => $label) {
                if (isset($_FILES[$key]) && $_FILES[$key]['error'] !== UPLOAD_ERR_NO_FILE) {
                    if ($_FILES[$key]['error'] === UPLOAD_ERR_OK) {
                        $upload = Security::validateFileUpload($_FILES[$key]);
                        if ($upload['valid']) {
                            $fileName = $key . '_' . $appNumber . '_' . time() . '.' . $upload['extension'];
                            $fullPath = $uploadDir . $fileName;
                            
                            if (move_uploaded_file($_FILES[$key]['tmp_name'], $fullPath)) {
                                $uploadedFiles[$key] = $fileName;
                                chmod($fullPath, 0644);
                            } else {
                                $uploadErrors[] = "Failed to upload $label";
                            }
                        } else {
                            $uploadErrors[] = "$label: " . implode(', ', $upload['errors']);
                        }
                    } else {
                        $uploadErrors[] = "Error uploading $label";
                    }
                }
            }
            
            // Check if at least birth certificate and passport photo are uploaded
            if (!isset($uploadedFiles['birth_certificate'])) {
                $errors[] = 'Birth certificate is required';
            }
            if (!isset($uploadedFiles['passport_photo'])) {
                $errors[] = 'Passport photograph is required';
            }
            
            if (empty($errors) && empty($uploadErrors) && $db) {
                // Save to database
                try {
                    // Store file paths as JSON in database
                    $documentsJson = json_encode($uploadedFiles);
                    
                    // Check if the admissions table exists and has the correct structure
                    $tableCheck = $db->getRow("SHOW TABLES LIKE 'admissions'");
                    
                    if (!$tableCheck) {
                        // Create admissions table if it doesn't exist
                        $db->query("
                            CREATE TABLE IF NOT EXISTS admissions (
                                id INT PRIMARY KEY AUTO_INCREMENT,
                                application_number VARCHAR(50) UNIQUE NOT NULL,
                                first_name VARCHAR(50) NOT NULL,
                                middle_name VARCHAR(50),
                                last_name VARCHAR(50) NOT NULL,
                                date_of_birth DATE NOT NULL,
                                gender ENUM('male', 'female', 'other') NOT NULL,
                                class_applying_for VARCHAR(50) NOT NULL,
                                parent_name VARCHAR(100) NOT NULL,
                                parent_email VARCHAR(100) NOT NULL,
                                parent_phone VARCHAR(20) NOT NULL,
                                address TEXT NOT NULL,
                                previous_school VARCHAR(200),
                                documents_path TEXT,
                                status ENUM('pending', 'reviewing', 'accepted', 'rejected') DEFAULT 'pending',
                                reviewed_by INT,
                                reviewed_at DATETIME,
                                remarks TEXT,
                                submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                INDEX idx_status (status)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                        ");
                    }
                    
                    // Insert into database
                    $inserted = $db->insert(
                        "INSERT INTO admissions (application_number, first_name, middle_name, last_name, date_of_birth, gender, class_applying_for, parent_name, parent_email, parent_phone, address, previous_school, documents_path, status, submitted_at) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())",
                        [
                            $appNumber, 
                            $firstName, 
                            $middleName, 
                            $lastName, 
                            $dob, 
                            $gender, 
                            $class, 
                            $parentName, 
                            $parentEmail, 
                            $parentPhone, 
                            $address, 
                            $previousSchool, 
                            $documentsJson
                        ]
                    );
                    
                    if (!$inserted) {
                        throw new Exception("Failed to insert record");
                    }
                    
                    // Send confirmation email
                    $fullName = trim($firstName . ' ' . $middleName . ' ' . $lastName);
                    $emailSubject = "Application Received - " . (defined('SCHOOL_NAME') ? SCHOOL_NAME : 'ST. BENEDICT\'S ACADEMY');
                    $emailMessage = "
                    <html>
                    <head>
                        <style>
                            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                            .header { background: #002855; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                            .app-number { font-size: 24px; font-weight: bold; color: #c41e3a; text-align: center; padding: 15px; background: #ffd700; border-radius: 5px; margin: 20px 0; }
                            .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #666; }
                            .doc-list { background: white; padding: 15px; border-radius: 5px; margin: 15px 0; }
                            .doc-list li { color: #28a745; }
                        </style>
                    </head>
                    <body>
                        <div class='container'>
                            <div class='header'>
                                <h2>Application Received</h2>
                            </div>
                            <div class='content'>
                                <p>Dear <strong>$parentName</strong>,</p>
                                <p>Thank you for applying to <strong>" . (defined('SCHOOL_NAME') ? SCHOOL_NAME : 'ST. BENEDICT\'S ACADEMY') . "</strong>.</p>
                                <p>We have received your application for:</p>
                                <p><strong>Child's Full Name:</strong> $fullName<br>
                                <strong>Class Applying For:</strong> $class</p>
                                
                                <div class='app-number'>
                                    Your Application Number:<br>
                                    <span style='font-size: 28px;'>$appNumber</span>
                                </div>
                                
                                <div class='doc-list'>
                                    <p><strong>Documents Received:</strong></p>
                                    <ul>
                                        " . (isset($uploadedFiles['birth_certificate']) ? '<li>✓ Birth Certificate</li>' : '') . "
                                        " . (isset($uploadedFiles['passport_photo']) ? '<li>✓ Passport Photograph</li>' : '') . "
                                        " . (isset($uploadedFiles['immunization_record']) ? '<li>✓ Immunization Record</li>' : '') . "
                                        " . (isset($uploadedFiles['previous_report']) ? '<li>✓ Previous School Report</li>' : '') . "
                                    </ul>
                                </div>
                                
                                <p><strong>Next Steps:</strong></p>
                                <ol>
                                    <li>Our admissions team will review your application within 3-5 working days.</li>
                                    <li>You will receive an email to schedule an assessment/interview.</li>
                                    <li>After the assessment, you will receive the admission decision.</li>
                                    <li>If accepted, you will receive enrollment instructions.</li>
                                </ol>
                                
                                <p>If you have any questions, please contact our admissions office at " . (defined('SCHOOL_PHONE') ? SCHOOL_PHONE : '09044472688') . " or email admissions@" . (defined('SCHOOL_EMAIL') ? SCHOOL_EMAIL : 'stbenedicts.edu.ng') . ".</p>
                                
                                <p>May God bless you,</p>
                                <p><strong>Admissions Team</strong><br>" . (defined('SCHOOL_NAME') ? SCHOOL_NAME : 'ST. BENEDICT\'S ACADEMY') . "</p>
                            </div>
                            <div class='footer'>
                                <p>This is an automated message. Please do not reply to this email.</p>
                            </div>
                        </div>
                    </body>
                    </html>
                    ";
                    
                    sendEmail($parentEmail, $emailSubject, $emailMessage);
                    
                    // Also send notification to admin
                    $adminEmail = defined('SCHOOL_EMAIL') ? SCHOOL_EMAIL : 'admin@stbenedicts.edu.ng';
                    $adminSubject = "New Admission Application - $appNumber";
                    $adminMessage = "
                    <html>
                    <body>
                        <h2>New Admission Application</h2>
                        <p><strong>Application Number:</strong> $appNumber</p>
                        <p><strong>Child's Full Name:</strong> $fullName</p>
                        <p><strong>Date of Birth:</strong> $dob</p>
                        <p><strong>Gender:</strong> $gender</p>
                        <p><strong>Class Applying For:</strong> $class</p>
                        <p><strong>Parent Name:</strong> $parentName</p>
                        <p><strong>Parent Email:</strong> $parentEmail</p>
                        <p><strong>Parent Phone:</strong> $parentPhone</p>
                        <p><strong>Address:</strong> $address</p>
                        <p><strong>Previous School:</strong> " . ($previousSchool ?: 'None') . "</p>
                        <p><strong>Documents Uploaded:</strong></p>
                        <ul>
                            " . (isset($uploadedFiles['birth_certificate']) ? '<li>Birth Certificate</li>' : '') . "
                            " . (isset($uploadedFiles['passport_photo']) ? '<li>Passport Photograph</li>' : '') . "
                            " . (isset($uploadedFiles['immunization_record']) ? '<li>Immunization Record</li>' : '') . "
                            " . (isset($uploadedFiles['previous_report']) ? '<li>Previous School Report</li>' : '') . "
                        </ul>
                        <p><a href='" . BASE_URL . "/admin/admissions.php'>View in Admin Panel</a></p>
                    </body>
                    </html>
                    ";
                    
                    sendEmail($adminEmail, $adminSubject, $adminMessage);
                    
                    $message = '<div style="text-align: center;">
                        <i class="fas fa-check-circle" style="font-size: 4rem; color: #28a745; margin-bottom: 20px;"></i>
                        <h3>Application Submitted Successfully!</h3>
                        <p>Your application number is: <strong style="font-size: 1.5rem; color: #c41e3a;">' . $appNumber . '</strong></p>
                        <p>We have sent a confirmation email to: <strong>' . $parentEmail . '</strong></p>
                        <p>Our admissions team will contact you within 3-5 working days.</p>
                        <hr style="margin: 30px 0;">
                        <h4>Next Steps:</h4>
                        <ol style="text-align: left; max-width: 400px; margin: 20px auto;">
                            <li>Wait for our call/email to schedule an assessment</li>
                            <li>Bring your child for the assessment/interview</li>
                            <li>Receive admission decision</li>
                            <li>Complete enrollment if accepted</li>
                        </ol>
                        <div style="margin-top: 30px;">
                            <a href="' . BASE_URL . '/index.php" class="btn btn-primary">Return to Home</a>
                            <a href="admissions.php" class="btn btn-outline" style="margin-left: 10px;">Submit Another Application</a>
                        </div>
                    </div>';
                    $messageType = 'success';
                    
                    // Clear form data
                    $_POST = [];
                    
                } catch (Exception $e) {
                    $message = 'An error occurred while submitting your application. Please try again later or contact us directly.';
                    $messageType = 'error';
                    error_log("Admission application error: " . $e->getMessage());
                }
            } elseif (!empty($errors)) {
                $message = '<ul><li>' . implode('</li><li>', $errors) . '</li></ul>';
                $messageType = 'error';
            } elseif (!empty($uploadErrors)) {
                $message = '<ul><li>' . implode('</li><li>', $uploadErrors) . '</li></ul>';
                $messageType = 'error';
            } else {
                $message = 'Database connection error. Please try again later.';
                $messageType = 'error';
            }
        } else {
            $message = '<ul><li>' . implode('</li><li>', $errors) . '</li></ul>';
            $messageType = 'error';
        }
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
        
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>">
            <?php echo $message; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($messageType !== 'success'): ?>
        <form method="POST" action="" enctype="multipart/form-data" class="form-container" id="admissionForm">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            
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