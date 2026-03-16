<?php
// public/contact.php - Contact Page
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = 'Contact Us - Get in Touch';
$pageDescription = 'Contact ST. BENEDICT\'S EARLY YEARS BRITISH ACADEMY. Find our location, phone number, email, and send us a message.';

// Set meta tags for SEO
$metaTags = [
    'og:title' => 'Contact Us - ST. BENEDICT\'S EARLY YEARS BRITISH ACADEMY',
    'og:description' => 'Get in touch with our school. Visit us, call, or send a message.',
    'og:image' => BASE_URL . '/assets/images/og-image.jpg',
    'og:url' => BASE_URL . '/public/contact.php',
    'twitter:card' => 'summary_large_image'
];

// Check if header exists
$headerPath = __DIR__ . '/../includes/header.php';
if (!file_exists($headerPath)) {
    die("Error: Header file not found at: $headerPath");
}
include $headerPath;

// Get database instance
try {
    $db = Database::getInstance();
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    $db = null;
}

$message = '';
$messageType = '';

// Handle contact form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token';
        $messageType = 'error';
    } else {
        $data = [
            'name' => Security::sanitize($_POST['name'] ?? ''),
            'email' => Security::sanitize($_POST['email'] ?? ''),
            'phone' => Security::sanitize($_POST['phone'] ?? ''),
            'subject' => Security::sanitize($_POST['subject'] ?? ''),
            'message' => Security::sanitize($_POST['message'] ?? '')
        ];
        
        // Validate
        $errors = [];
        
        if (empty($data['name'])) {
            $errors[] = 'Name is required';
        }
        
        if (!Security::validateEmail($data['email'])) {
            $errors[] = 'Valid email is required';
        }
        
        if (empty($data['message'])) {
            $errors[] = 'Message is required';
        }
        
        // Validate phone if provided
        if (!empty($data['phone']) && !Security::validatePhone($data['phone'])) {
            $errors[] = 'Valid Nigerian phone number is required (e.g., 08012345678)';
        }
        
        if (empty($errors) && $db) {
            try {
                // Save to database
                $db->insert(
                    "INSERT INTO contact_messages (name, email, phone, subject, message, submitted_at) 
                     VALUES (?, ?, ?, ?, ?, NOW())",
                    [$data['name'], $data['email'], $data['phone'], $data['subject'], $data['message']]
                );
                
                // Send email notification to admin
                $adminEmail = defined('SCHOOL_EMAIL') ? SCHOOL_EMAIL : 'admin@stbenedicts.edu.ng';
                $emailSubject = "New Contact Message from " . $data['name'];
                $emailMessage = "
                <html>
                <head>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: #002855; color: white; padding: 20px; text-align: center; }
                        .content { background: #f9f9f9; padding: 30px; }
                        .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #666; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='header'>
                            <h2>New Contact Form Submission</h2>
                        </div>
                        <div class='content'>
                            <p><strong>Name:</strong> {$data['name']}</p>
                            <p><strong>Email:</strong> {$data['email']}</p>
                            <p><strong>Phone:</strong> " . (!empty($data['phone']) ? $data['phone'] : 'Not provided') . "</p>
                            <p><strong>Subject:</strong> " . (!empty($data['subject']) ? $data['subject'] : 'No subject') . "</p>
                            <p><strong>Message:</strong></p>
                            <p>" . nl2br($data['message']) . "</p>
                        </div>
                        <div class='footer'>
                            <p>This message was sent from the contact form on your website.</p>
                        </div>
                    </div>
                </body>
                </html>
                ";
                
                $headers = "MIME-Version: 1.0" . "\r\n";
                $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
                $headers .= "From: " . (defined('SCHOOL_NAME') ? SCHOOL_NAME : 'ST. BENEDICT\'S ACADEMY') . " <" . $data['email'] . ">\r\n";
                
                mail($adminEmail, $emailSubject, $emailMessage, $headers);
                
                $message = '<div style="text-align: center;">
                    <i class="fas fa-check-circle" style="font-size: 4rem; color: #28a745; margin-bottom: 20px;"></i>
                    <h3>Thank You for Contacting Us!</h3>
                    <p>We have received your message and will get back to you within 24 hours.</p>
                </div>';
                $messageType = 'success';
                
                // Clear form data
                $_POST = [];
                
            } catch (Exception $e) {
                $message = 'An error occurred. Please try again later or contact us directly.';
                $messageType = 'error';
                error_log("Contact form error: " . $e->getMessage());
            }
        } elseif (!empty($errors)) {
            $message = '<ul><li>' . implode('</li><li>', $errors) . '</li></ul>';
            $messageType = 'error';
        } else {
            $message = 'Database connection error. Please try again later.';
            $messageType = 'error';
        }
    }
}

// Get business hours
$businessHours = [
    'Monday' => '8:00 AM - 4:00 PM',
    'Tuesday' => '8:00 AM - 4:00 PM',
    'Wednesday' => '8:00 AM - 4:00 PM',
    'Thursday' => '8:00 AM - 4:00 PM',
    'Friday' => '8:00 AM - 2:00 PM',
    'Saturday' => 'Closed',
    'Sunday' => 'Closed'
];
?>

<!-- Page-Specific Styles -->
<style>
:root {
    --navy: #002855;
    --navy-dark: #001a3a;
    --red: #c41e3a;
    --red-dark: #a01830;
    --gold: #ffd700;
    --white: #ffffff;
    --light-gray: #f8f9fa;
    --medium-gray: #e9ecef;
    --gray: #6c757d;
    --dark-gray: #495057;
    --shadow-sm: 0 2px 10px rgba(0,0,0,0.05);
    --shadow-md: 0 5px 20px rgba(0,0,0,0.1);
    --shadow-lg: 0 10px 30px rgba(0,0,0,0.15);
    --shadow-xl: 0 20px 40px rgba(0,0,0,0.2);
    --spacing-xs: 5px;
    --spacing-sm: 10px;
    --spacing-md: 20px;
    --spacing-lg: 30px;
    --spacing-xl: 40px;
    --spacing-2xl: 60px;
    --spacing-3xl: 80px;
    --text-xs: 0.75rem;
    --text-sm: 0.875rem;
    --text-md: 1rem;
    --text-lg: 1.125rem;
    --text-xl: 1.25rem;
    --text-2xl: 1.5rem;
    --text-3xl: 1.875rem;
    --text-4xl: 2.25rem;
    --text-5xl: 3rem;
    --transition-fast: 0.3s ease;
    --transition-normal: 0.5s ease;
    --transition-slow: 0.8s ease;
    --radius-md: 8px;
    --radius-lg: 15px;
    --radius-xl: 30px;
    --radius-full: 9999px;
}

/* ===== PAGE HEADER ===== */
.page-header {
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-dark) 100%);
    color: var(--white);
    padding: 48px 0;
    text-align: center;
    position: relative;
    overflow: hidden;
    width: 100%;
    margin: 0 auto;
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
    font-size: 16px;
    line-height: 19.19px;
    margin-bottom: var(--spacing-sm);
    position: relative;
    display: inline-block;
    font-weight: 600;
    height: 19.19px;
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
    margin-top: 5px;
}

.breadcrumb a {
    color: var(--gold);
    text-decoration: none;
    transition: color var(--transition-fast);
    font-size: 16px;
    line-height: 20px;
    display: inline-block;
    height: 10px;
}

.breadcrumb a:hover {
    color: var(--white);
    text-decoration: underline;
}

@keyframes rotate {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* ===== CONTAINER ===== */
.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
}

/* ===== SECTION HEADER ===== */
.section-header {
    margin-bottom: var(--spacing-2xl);
}

.section-tag {
    display: inline-block;
    font-size: var(--text-sm);
    text-transform: uppercase;
    letter-spacing: 2px;
    color: var(--red);
    margin-bottom: var(--spacing-xs);
    font-weight: 600;
}

.section-title {
    font-size: var(--text-3xl);
    margin-bottom: var(--spacing-lg);
    color: var(--navy);
    line-height: 1.2;
}

.text-highlight {
    color: var(--red);
    position: relative;
    display: inline-block;
}

.text-highlight::after {
    content: '';
    position: absolute;
    bottom: 5px;
    left: 0;
    width: 100%;
    height: 8px;
    background: rgba(255, 215, 0, 0.2);
    z-index: -1;
}

.text-center {
    text-align: center;
}

/* ===== CONTACT SECTION ===== */
.contact-section {
    padding: var(--spacing-2xl) 0;
}

.contact-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--spacing-2xl);
}

.contact-intro {
    font-size: var(--text-md);
    color: var(--dark-gray);
    margin-bottom: var(--spacing-xl);
    line-height: 1.8;
}

.info-cards {
    display: grid;
    gap: var(--spacing-lg);
    margin-bottom: var(--spacing-xl);
}

.info-card {
    display: flex;
    gap: var(--spacing-lg);
    padding: var(--spacing-lg);
    background: var(--white);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    transition: all var(--transition-normal);
    border-bottom: 3px solid transparent;
}

.info-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-lg);
    border-bottom-color: var(--gold);
}

.info-icon {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, var(--navy), var(--red));
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--gold);
    font-size: var(--text-2xl);
    flex-shrink: 0;
}

.info-content {
    flex: 1;
}

.info-content h3 {
    color: var(--navy);
    margin-bottom: var(--spacing-xs);
    font-size: var(--text-lg);
}

.info-content p {
    color: var(--gray);
    margin-bottom: var(--spacing-xs);
    line-height: 1.6;
}

.info-content a {
    color: var(--red);
    text-decoration: none;
    transition: color var(--transition-fast);
}

.info-content a:hover {
    color: var(--navy);
}

.map-link {
    display: inline-flex;
    align-items: center;
    gap: var(--spacing-xs);
    font-size: var(--text-sm);
}

.small {
    font-size: var(--text-xs);
    color: var(--gray);
}

.hours-list {
    margin-top: var(--spacing-sm);
}

.hours-row {
    display: flex;
    justify-content: space-between;
    padding: var(--spacing-xs) 0;
    border-bottom: 1px dashed var(--light-gray);
}

.hours-row:last-child {
    border-bottom: none;
}

.hours-row .day {
    color: var(--navy);
    font-weight: 500;
    font-size: var(--text-sm);
}

.hours-row .hours {
    color: var(--gray);
    font-size: var(--text-sm);
}

.social-connect h3 {
    color: var(--navy);
    margin-bottom: var(--spacing-md);
    font-size: var(--text-lg);
}

.social-links {
    display: flex;
    gap: var(--spacing-md);
}

.social-link {
    width: 45px;
    height: 45px;
    background: var(--light-gray);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--navy);
    transition: all var(--transition-fast);
    font-size: var(--text-lg);
}

.social-link:hover {
    background: var(--gold);
    color: var(--navy);
    transform: translateY(-3px);
}

/* ===== CONTACT FORM ===== */
.contact-form-container {
    position: relative;
}

.form-card {
    background: var(--white);
    border-radius: var(--radius-xl);
    padding: var(--spacing-2xl);
    box-shadow: var(--shadow-lg);
}

.form-card h3 {
    color: var(--navy);
    margin-bottom: var(--spacing-xl);
    text-align: center;
    font-size: var(--text-2xl);
}

.contact-form .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--spacing-md);
}

.form-group {
    margin-bottom: var(--spacing-lg);
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

.btn-block {
    width: 100%;
}

/* ===== MAP SECTION ===== */
.map-section {
    margin: var(--spacing-2xl) 0;
}

.map-container {
    border-radius: var(--radius-xl);
    overflow: hidden;
    box-shadow: var(--shadow-lg);
    height: 450px;
}

.map-container iframe {
    width: 100%;
    height: 100%;
    border: 0;
}

/* ===== FAQ SECTION ===== */
.faq-section {
    padding: var(--spacing-2xl) 0;
    background: linear-gradient(135deg, var(--light-gray) 0%, #ffffff 100%);
}

.faq-grid {
    max-width: 800px;
    margin: 0 auto;
    display: grid;
    gap: var(--spacing-md);
}

.faq-item {
    background: var(--white);
    border-radius: var(--radius-lg);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--light-gray);
    transition: all var(--transition-fast);
}

.faq-item:hover {
    box-shadow: var(--shadow-md);
}

.faq-question {
    padding: var(--spacing-lg);
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    transition: background var(--transition-fast);
}

.faq-question:hover {
    background: rgba(255, 215, 0, 0.05);
}

.faq-question h3 {
    margin: 0;
    font-size: var(--text-md);
    color: var(--navy);
    font-weight: 600;
}

.faq-question i {
    color: var(--gold);
    transition: transform var(--transition-fast);
}

.faq-item.active .faq-question i {
    transform: rotate(180deg);
}

.faq-answer {
    max-height: 0;
    padding: 0 var(--spacing-lg);
    overflow: hidden;
    transition: all var(--transition-normal);
    background: var(--light-gray);
}

.faq-item.active .faq-answer {
    max-height: 200px;
    padding: var(--spacing-lg);
}

.faq-answer p {
    margin: 0;
    color: var(--dark-gray);
    line-height: 1.8;
    font-size: var(--text-sm);
}

/* ===== ALERT STYLES ===== */
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

.alert ul {
    margin: 0;
    padding-left: var(--spacing-md);
}

.alert li {
    margin-bottom: var(--spacing-xs);
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

/* ===== BUTTON STYLES ===== */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--spacing-sm);
    padding: var(--spacing-md) var(--spacing-xl);
    border-radius: var(--radius-md);
    font-weight: 500;
    cursor: pointer;
    transition: all var(--transition-fast);
    border: none;
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

.btn-block {
    width: 100%;
}

/* ===== ANIMATIONS ===== */
@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.info-card, .faq-item {
    animation: fadeIn 0.6s ease both;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 992px) {
    .page-header h1 {
        font-size: 60px;
        line-height: 19.19px;
    }
    
    .breadcrumb a {
        font-size: 35px;
        line-height: 20px;
    }
    
    .section-title {
        font-size: var(--text-2xl);
    }
    
    .contact-grid {
        grid-template-columns: 1fr;
        gap: var(--spacing-xl);
    }
    
    .contact-form-container {
        order: -1;
    }
}

@media (max-width: 768px) {
    .page-header {
        padding: 30px 0;
    }
    
    .page-header h1 {
        font-size: 48px;
        line-height: 19.19px;
    }
    
    .breadcrumb a {
        font-size: 28px;
        line-height: 20px;
    }
    
    .section-title {
        font-size: var(--text-xl);
    }
    
    .contact-form .form-row {
        grid-template-columns: 1fr;
    }
    
    .form-card {
        padding: var(--spacing-xl);
    }
    
    .form-card h3 {
        font-size: var(--text-xl);
    }
    
    .info-card {
        flex-direction: column;
        text-align: center;
    }
    
    .info-icon {
        margin: 0 auto;
    }
    
    .social-links {
        justify-content: center;
    }
    
    .map-container {
        height: 350px;
    }
}

@media (max-width: 576px) {
    .page-header {
        padding: 20px 0;
    }
    
    .page-header h1 {
        font-size: 36px;
        line-height: 19.19px;
    }
    
    .breadcrumb a {
        font-size: 22px;
        line-height: 20px;
    }
    
    .section-title {
        font-size: var(--text-lg);
    }
    
    .info-card {
        padding: var(--spacing-md);
    }
    
    .info-icon {
        width: 50px;
        height: 50px;
        font-size: var(--text-xl);
    }
    
    .hours-row {
        flex-direction: column;
        align-items: center;
        gap: var(--spacing-xs);
    }
    
    .social-link {
        width: 40px;
        height: 40px;
        font-size: var(--text-md);
    }
    
    .map-container {
        height: 250px;
    }
    
    .faq-question h3 {
        font-size: var(--text-sm);
    }
}
</style>

<!-- Page Header -->
<section class="page-header">
    <div class="container">
        <h1>Contact Us</h1>
        <div class="breadcrumb">
            <a href="<?php echo BASE_URL; ?>/index.php">Home</a> / Contact
        </div>
    </div>
</section>

<!-- Contact Information -->
<section class="contact-section">
    <div class="container">
        <div class="contact-grid">
            <div class="contact-info">
                <div class="section-header">
                    <span class="section-tag">Get in Touch</span>
                    <h2 class="section-title">We'd Love to <span class="text-highlight">Hear From You</span></h2>
                </div>
                
                <p class="contact-intro">Have questions about admissions, curriculum, or anything else? Our team is ready to answer all your questions.</p>
                
                <div class="info-cards">
                    <div class="info-card">
                        <div class="info-icon">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <div class="info-content">
                            <h3>Visit Us</h3>
                            <p><?php echo defined('SCHOOL_ADDRESS') ? SCHOOL_ADDRESS : 'Enugu, Nigeria'; ?></p>
                            <a href="https://maps.google.com/?q=<?php echo urlencode(defined('SCHOOL_ADDRESS') ? SCHOOL_ADDRESS : 'Enugu, Nigeria'); ?>" target="_blank" class="map-link">
                                <i class="fas fa-directions"></i> Get Directions
                            </a>
                        </div>
                    </div>
                    
                    <div class="info-card">
                        <div class="info-icon">
                            <i class="fas fa-phone-alt"></i>
                        </div>
                        <div class="info-content">
                            <h3>Call Us</h3>
                            <p><a href="tel:<?php echo defined('SCHOOL_PHONE') ? SCHOOL_PHONE : '09044472688'; ?>"><?php echo defined('SCHOOL_PHONE') ? SCHOOL_PHONE : '09044472688'; ?></a></p>
                            <p class="small">Monday - Friday: 8:00 AM - 4:00 PM</p>
                        </div>
                    </div>
                    
                    <div class="info-card">
                        <div class="info-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <div class="info-content">
                            <h3>Email Us</h3>
                            <p><a href="mailto:<?php echo defined('SCHOOL_EMAIL') ? SCHOOL_EMAIL : 'info@stbenedicts.edu.ng'; ?>"><?php echo defined('SCHOOL_EMAIL') ? SCHOOL_EMAIL : 'info@stbenedicts.edu.ng'; ?></a></p>
                            <p class="small">We reply within 24 hours</p>
                        </div>
                    </div>
                    
                    <div class="info-card">
                        <div class="info-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="info-content">
                            <h3>Office Hours</h3>
                            <div class="hours-list">
                                <?php foreach ($businessHours as $day => $hours): ?>
                                <div class="hours-row">
                                    <span class="day"><?php echo $day; ?>:</span>
                                    <span class="hours"><?php echo $hours; ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="social-connect">
                    <h3>Connect With Us</h3>
                    <div class="social-links">
                        <a href="#" class="social-link" target="_blank"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" class="social-link" target="_blank"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="social-link" target="_blank"><i class="fab fa-instagram"></i></a>
                        <a href="#" class="social-link" target="_blank"><i class="fab fa-youtube"></i></a>
                        <a href="#" class="social-link" target="_blank"><i class="fab fa-linkedin-in"></i></a>
                    </div>
                </div>
            </div>
            
            <!-- Contact Form -->
            <div class="contact-form-container">
                <div class="form-card">
                    <h3>Send a Message</h3>
                    
                    <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?>">
                        <?php echo $message; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($messageType !== 'success'): ?>
                    <form method="POST" class="contact-form" id="contactForm">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                        
                        <div class="form-group">
                            <label for="name">Your Name *</label>
                            <input type="text" id="name" name="name" class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" 
                                   placeholder="Enter your full name" required>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="email">Email Address *</label>
                                <input type="email" id="email" name="email" class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" 
                                       placeholder="you@example.com" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="phone">Phone Number</label>
                                <input type="tel" id="phone" name="phone" class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" 
                                       placeholder="08012345678">
                                <small>Optional - Nigerian mobile number</small>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="subject">Subject</label>
                            <input type="text" id="subject" name="subject" class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['subject'] ?? ''); ?>" 
                                   placeholder="What is your message about?">
                        </div>
                        
                        <div class="form-group">
                            <label for="message">Message *</label>
                            <textarea id="message" name="message" class="form-control" rows="6" 
                                      placeholder="Type your message here..." required><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fas fa-paper-plane"></i> Send Message
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Map Section -->
<section class="map-section">
    <div class="container">
        <div class="map-container">
            <iframe 
                src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3964.211234567890!2d7.123456789012345!3d6.123456789012345!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zNsKwMDcnMjQuNCJOIDfCsDA3JzI0LjQiRQ!5e0!3m2!1sen!2sng!4v1234567890123!5m2!1sen!2sng" 
                allowfullscreen="" 
                loading="lazy">
            </iframe>
        </div>
    </div>
</section>

<!-- FAQ Section -->
<section class="faq-section">
    <div class="container">
        <div class="section-header text-center">
            <span class="section-tag">FAQ</span>
            <h2 class="section-title">Frequently Asked <span class="text-highlight">Questions</span></h2>
        </div>
        
        <div class="faq-grid">
            <div class="faq-item">
                <div class="faq-question" onclick="toggleFAQ(this)">
                    <h3>What are the admission requirements?</h3>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    <p>Admission requirements include: completed application form, birth certificate, immunization records, recent passport photographs, and previous school reports (if applicable). Please visit our Admissions page for more details.</p>
                </div>
            </div>
            
            <div class="faq-item">
                <div class="faq-question" onclick="toggleFAQ(this)">
                    <h3>What is the school fees structure?</h3>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    <p>Our fee structure varies by class and includes tuition, development levy, sports fee, and other applicable charges. Please contact our admissions office for detailed fee information or download our prospectus.</p>
                </div>
            </div>
            
            <div class="faq-item">
                <div class="faq-question" onclick="toggleFAQ(this)">
                    <h3>Do you offer transportation services?</h3>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    <p>Yes, we provide safe and reliable transportation services with trained drivers and attendants. Our buses cover major routes within Enugu. Please contact the school office for route availability.</p>
                </div>
            </div>
            
            <div class="faq-item">
                <div class="faq-question" onclick="toggleFAQ(this)">
                    <h3>What is the teacher-student ratio?</h3>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    <p>We maintain small class sizes with a typical teacher-student ratio of 1:8 to ensure individual attention and quality learning experiences for every child.</p>
                </div>
            </div>
            
            <div class="faq-item">
                <div class="faq-question" onclick="toggleFAQ(this)">
                    <h3>Do you provide meals?</h3>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    <p>Yes, we provide nutritious meals and snacks prepared in our hygienic kitchen. Our menu is designed by nutritionists to ensure balanced meals for growing children.</p>
                </div>
            </div>
            
            <div class="faq-item">
                <div class="faq-question" onclick="toggleFAQ(this)">
                    <h3>How can I schedule a school tour?</h3>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    <p>You can schedule a school tour by calling our office or using the contact form above. We welcome parents to visit and experience our learning environment firsthand.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
function toggleFAQ(element) {
    const faqItem = element.closest('.faq-item');
    faqItem.classList.toggle('active');
}

// Form validation
document.getElementById('contactForm')?.addEventListener('submit', function(e) {
    const submitBtn = this.querySelector('button[type="submit"]');
    submitBtn.classList.add('btn-loading');
    submitBtn.disabled = true;
    
    const email = document.getElementById('email').value;
    const phone = document.getElementById('phone').value;
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    
    if (!emailRegex.test(email)) {
        e.preventDefault();
        alert('Please enter a valid email address');
        submitBtn.classList.remove('btn-loading');
        submitBtn.disabled = false;
        return false;
    }
    
    if (phone) {
        const phoneRegex = /^0[789][01]\d{8}$/;
        if (!phoneRegex.test(phone)) {
            e.preventDefault();
            alert('Please enter a valid Nigerian phone number (e.g., 08012345678)');
            submitBtn.classList.remove('btn-loading');
            submitBtn.disabled = false;
            return false;
        }
    }
});

// Add animation on scroll
document.addEventListener('DOMContentLoaded', function() {
    const elements = document.querySelectorAll('.info-card, .faq-item');
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.animation = 'fadeIn 0.6s ease both';
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1 });
    
    elements.forEach(el => {
        el.style.opacity = '0';
        observer.observe(el);
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