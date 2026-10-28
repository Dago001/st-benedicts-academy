<?php
// public/careers.php - Careers & Job Vacancies Page
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/Career.php';

$pageTitle = 'Career Opportunities - ' . SITE_NAME;
$pageDescription = 'Join the exceptional team at St. Benedict\'s Early Years British Academy. Explore our current job vacancies and apply online today.';

$metaTags = [
    'og:title' => 'Careers & Vacancies - ' . SITE_NAME,
    'og:description' => 'Explore exciting teaching and administrative career opportunities at St. Benedict\'s British Academy.',
    'og:image' => BASE_URL . '/assets/images/og-image.jpg',
    'og:url' => BASE_URL . '/public/careers',
    'twitter:card' => 'summary_large_image'
];

$errors = [];
$successData = null;

// Handle job application submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session expired. Please reload the page and try again.';
    } else {
        $ts = turnstile_verify();
        if (!$ts['success']) {
            $errors[] = $ts['error'];
        }
    }
    if (!empty($_POST['website'])) {
        // Honeypot field filled by bot: pretend success
        $successData = [
            'reference_no' => 'STB-JOB-' . date('Y') . '-0000',
            'position' => 'General Application'
        ];
    } elseif (($_SESSION['career_apply_attempts'][date('YmdH')] ?? 0) >= 6) {
        $errors[] = 'Too many submissions from this device. Please try again later or contact the school office directly.';
    } else {
        $_SESSION['career_apply_attempts'] = [date('YmdH') => ($_SESSION['career_apply_attempts'][date('YmdH')] ?? 0) + 1];

        $result = Career::submitApplication($_POST, $_FILES);
        if ($result['success']) {
            $successData = $result;
        } else {
            $errors = $result['errors'];
        }
    }
}

// Fetch active published vacancies
$vacancies = Career::getVacancies('published');
$departments = array_unique(array_filter(array_column($vacancies, 'department')));

include __DIR__ . '/../includes/header.php';
?>

<style>
/* ===== CAREERS HERO ===== */
.careers-hero {
    background: linear-gradient(135deg, var(--navy, #002855) 0%, #001733 100%);
    color: #ffffff;
    padding: 70px 0 60px;
    position: relative;
    overflow: hidden;
    text-align: center;
}
.careers-hero::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 500px;
    height: 500px;
    background: radial-gradient(circle, rgba(255, 215, 0, 0.12) 0%, transparent 70%);
    border-radius: 50%;
    pointer-events: none;
}
.careers-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 215, 0, 0.4);
    color: var(--gold-ui, #ffd700);
    padding: 6px 16px;
    border-radius: 9999px;
    font-size: 0.85rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    margin-bottom: 20px;
}
.careers-hero h1 {
    font-size: clamp(2rem, 4.5vw, 2.9rem);
    font-weight: 800;
    margin: 0 0 16px;
    line-height: 1.2;
    color: #ffffff;
}
.careers-hero p {
    font-size: 1.1rem;
    max-width: 720px;
    margin: 0 auto 30px;
    color: rgba(255, 255, 255, 0.88);
    line-height: 1.6;
}
.hero-cta-buttons {
    display: flex;
    justify-content: center;
    gap: 16px;
    flex-wrap: wrap;
}
.hero-cta-buttons .btn {
    padding: 12px 28px;
    font-weight: 700;
    font-size: 1rem;
    border-radius: 8px;
    text-decoration: none;
}
.btn-gold {
    background: #ffd700;
    color: #002855;
    border: none;
    box-shadow: 0 4px 14px rgba(255, 215, 0, 0.35);
    transition: all 0.25s ease;
}
.btn-gold:hover {
    background: #e6c200;
    color: #001733;
    transform: translateY(-2px);
}
.btn-outline-white {
    background: transparent;
    color: #ffffff;
    border: 2px solid rgba(255, 255, 255, 0.8);
    transition: all 0.25s ease;
}
.btn-outline-white:hover {
    background: #ffffff;
    color: #002855;
    transform: translateY(-2px);
}

/* ===== BENEFITS SECTION ===== */
.careers-benefits {
    padding: 50px 0;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
}
.benefits-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 24px;
}
.benefit-card {
    background: #ffffff;
    padding: 24px;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
    text-align: center;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.benefit-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 20px rgba(0, 40, 85, 0.08);
}
.benefit-icon {
    width: 54px;
    height: 54px;
    margin: 0 auto 16px;
    border-radius: 50%;
    background: rgba(0, 40, 85, 0.08);
    color: var(--navy, #002855);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
}
.benefit-card h4 {
    margin: 0 0 8px;
    font-size: 1.1rem;
    font-weight: 700;
    color: #0f172a;
}
.benefit-card p {
    margin: 0;
    font-size: 0.9rem;
    color: #64748b;
    line-height: 1.5;
}

/* ===== VACANCIES SECTION ===== */
.vacancies-section {
    padding: 60px 0 40px;
}
.section-heading-wrapper {
    text-align: center;
    max-width: 680px;
    margin: 0 auto 40px;
}
.section-tag {
    display: inline-block;
    color: var(--navy, #002855);
    font-size: 0.8rem;
    font-weight: 800;
    letter-spacing: 0.15em;
    text-transform: uppercase;
    margin-bottom: 8px;
}
.section-heading-wrapper h2 {
    font-size: 2.2rem;
    font-weight: 800;
    color: #0f172a;
    margin: 0 0 12px;
}
.section-heading-wrapper p {
    font-size: 1.05rem;
    color: #64748b;
    line-height: 1.6;
    margin: 0;
}

.vacancies-filter-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    background: #f1f5f9;
    padding: 14px 20px;
    border-radius: 12px;
    margin-bottom: 28px;
}
.filter-search-box {
    position: relative;
    flex: 1;
    min-width: 250px;
}
.filter-search-box input {
    width: 100%;
    padding: 10px 14px 10px 38px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 0.95rem;
    outline: none;
}
.filter-search-box i {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
}

.vacancies-list {
    display: flex;
    flex-direction: column;
    gap: 20px;
}
.vacancy-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 24px 28px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
    transition: all 0.25s ease;
}
.vacancy-card:hover {
    border-color: #cbd5e1;
    box-shadow: 0 8px 24px rgba(0, 40, 85, 0.08);
}
.vacancy-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    flex-wrap: wrap;
}
.vacancy-title-area h3 {
    margin: 0 0 8px;
    font-size: 1.35rem;
    font-weight: 700;
    color: var(--navy, #002855);
}
.vacancy-meta-tags {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.meta-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.8rem;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 6px;
}
.meta-tag.dept { background: #e0f2fe; color: #0369a1; }
.meta-tag.type { background: #dcfce7; color: #15803d; }
.meta-tag.loc { background: #f3e8ff; color: #6b21a8; }
.meta-tag.exp { background: #fef3c7; color: #b45309; }

.vacancy-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}
.vacancy-desc-snippet {
    margin: 16px 0 0;
    color: #475569;
    font-size: 0.96rem;
    line-height: 1.6;
}
.vacancy-details-drawer {
    display: none;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #f1f5f9;
}
.vacancy-details-drawer.open {
    display: block;
}
.vacancy-details-drawer h4 {
    font-size: 1rem;
    font-weight: 700;
    color: #1e293b;
    margin: 16px 0 6px;
}
.vacancy-details-drawer ul {
    margin: 0;
    padding-left: 20px;
    color: #475569;
    font-size: 0.93rem;
    line-height: 1.65;
}

.no-vacancies-card {
    text-align: center;
    padding: 50px 24px;
    background: #ffffff;
    border: 2px dashed #cbd5e1;
    border-radius: 16px;
}
.no-vacancies-card i {
    font-size: 3rem;
    color: #94a3b8;
    margin-bottom: 16px;
}
.no-vacancies-card h3 {
    margin: 0 0 8px;
    font-size: 1.4rem;
    color: #1e293b;
}
.no-vacancies-card p {
    margin: 0 0 20px;
    color: #64748b;
    max-width: 500px;
    margin-left: auto;
    margin-right: auto;
}

/* ===== APPLICATION FORM SECTION ===== */
.application-form-section {
    padding: 60px 0 80px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
}
.app-form-wrapper {
    max-width: 820px;
    margin: 0 auto;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
    overflow: hidden;
}
.app-form-header {
    background: linear-gradient(135deg, var(--navy, #002855) 0%, #001733 100%);
    color: #ffffff;
    padding: 32px 36px;
}
.app-form-header h2 {
    margin: 0 0 8px;
    font-size: 1.8rem;
    font-weight: 800;
    color: #ffffff;
}
.app-form-header p {
    margin: 0;
    color: rgba(255, 255, 255, 0.85);
    font-size: 1rem;
    line-height: 1.5;
}
.app-form-body {
    padding: 36px;
}
.app-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}
@media (max-width: 768px) {
    .app-grid-2 {
        grid-template-columns: 1fr;
    }
}
.form-group-field {
    margin-bottom: 20px;
}
.form-group-field label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    font-size: 0.92rem;
    color: #1e293b;
}
.form-group-field label span.req {
    color: #dc2626;
}
.form-group-field input[type="text"],
.form-group-field input[type="email"],
.form-group-field input[type="tel"],
.form-group-field select,
.form-group-field textarea {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 0.95rem;
    color: #0f172a;
    background: #ffffff;
    transition: border-color 0.2s, box-shadow 0.2s;
    font-family: inherit;
    box-sizing: border-box;
}
.form-group-field input:focus,
.form-group-field select:focus,
.form-group-field textarea:focus {
    outline: none;
    border-color: var(--navy, #002855);
    box-shadow: 0 0 0 3px rgba(0, 40, 85, 0.12);
}
.form-group-field small.helper {
    display: block;
    margin-top: 5px;
    font-size: 0.8rem;
    color: #64748b;
}
.upload-box {
    border: 2px dashed #cbd5e1;
    padding: 18px 16px;
    border-radius: 10px;
    background: #f8fafc;
    text-align: center;
    position: relative;
    cursor: pointer;
    transition: all 0.2s ease;
}
.upload-box:hover {
    border-color: var(--navy, #002855);
    background: #f1f5f9;
}
.upload-box i {
    font-size: 1.8rem;
    color: var(--navy, #002855);
    margin-bottom: 6px;
}
.upload-box input[type="file"] {
    position: absolute;
    top: 0; left: 0; width: 100%; height: 100%;
    opacity: 0;
    cursor: pointer;
}
.upload-filename {
    font-size: 0.85rem;
    font-weight: 600;
    color: #0369a1;
    margin-top: 6px;
    word-break: break-all;
}

.success-hero-box {
    text-align: center;
    padding: 40px 24px;
}
.success-icon-badge {
    width: 80px;
    height: 80px;
    background: #dcfce7;
    color: #16a34a;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    margin-bottom: 20px;
}
.ref-pill-card {
    display: inline-block;
    background: #f1f5f9;
    border: 2px dashed #002855;
    padding: 12px 24px;
    border-radius: 12px;
    margin: 20px 0;
}
.ref-pill-card strong {
    font-size: 1.4rem;
    letter-spacing: 0.05em;
    color: #002855;
}
</style>

<!-- Careers Hero Section -->
<section class="careers-hero">
    <div class="container">
        <span class="careers-badge">
            <i class="fas fa-briefcase"></i> Careers at St. Benedict's
        </span>
        <h1>Join Our Exceptional Educational Community</h1>
        <p>
            At St. Benedict's Early Years British Academy, we are dedicated to fostering excellence, innovation, and holistic child development. Discover rewarding career opportunities and help shape future global leaders.
        </p>
        <div class="hero-cta-buttons">
            <a href="#vacancies" class="btn btn-gold">
                <i class="fas fa-search"></i> View Open Positions
            </a>
            <a href="#apply" class="btn btn-outline-white">
                <i class="fas fa-file-signature"></i> Submit Application
            </a>
        </div>
    </div>
</section>

<!-- Why Work With Us Benefits -->
<section class="careers-benefits">
    <div class="container">
        <div class="benefits-grid">
            <div class="benefit-card">
                <div class="benefit-icon"><i class="fas fa-award"></i></div>
                <h4>British Curriculum</h4>
                <p>Deliver the prestigious British EYFS and Cambridge framework with international pedagogical excellence.</p>
            </div>
            <div class="benefit-card">
                <div class="benefit-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                <h4>Continuous Training</h4>
                <p>Access structured Continuous Professional Development (CPD), workshops, and leadership career pathways.</p>
            </div>
            <div class="benefit-card">
                <div class="benefit-icon"><i class="fas fa-heart"></i></div>
                <h4>Supportive Culture</h4>
                <p>Work within a collaborative, respectful culture that values staff well-being, creativity, and child advocacy.</p>
            </div>
            <div class="benefit-card">
                <div class="benefit-icon"><i class="fas fa-laptop-code"></i></div>
                <h4>Modern Resources</h4>
                <p>Enjoy state-of-the-art interactive classrooms, modern multimedia tools, and premier educational materials.</p>
            </div>
        </div>
    </div>
</section>

<!-- Vacancies Section -->
<section class="vacancies-section" id="vacancies">
    <div class="container">
        <div class="section-heading-wrapper">
            <span class="section-tag">Current Openings</span>
            <h2>Published Job Vacancies</h2>
            <p>Explore current career opportunities across our academic and administrative departments.</p>
        </div>

        <?php if (!empty($vacancies)): ?>
            <div class="vacancies-filter-bar">
                <div class="filter-search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="vacancySearchInput" placeholder="Search vacancies by title, keyword...">
                </div>
                <div>
                    <select id="departmentFilter" class="form-control" style="border-radius: 8px; padding: 10px 14px; border: 1px solid #cbd5e1;">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo e(strtolower($dept)); ?>"><?php echo e($dept); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="vacancies-list" id="vacanciesList">
                <?php foreach ($vacancies as $vac): ?>
                    <div class="vacancy-card" data-department="<?php echo e(strtolower($vac['department'])); ?>" data-title="<?php echo e(strtolower($vac['title'])); ?>">
                        <div class="vacancy-header">
                            <div class="vacancy-title-area">
                                <h3><?php echo e($vac['title']); ?></h3>
                                <div class="vacancy-meta-tags">
                                    <span class="meta-tag dept"><i class="fas fa-layer-group"></i> <?php echo e($vac['department']); ?></span>
                                    <span class="meta-tag type"><i class="fas fa-clock"></i> <?php echo e($vac['job_type']); ?></span>
                                    <span class="meta-tag loc"><i class="fas fa-map-marker-alt"></i> <?php echo e($vac['location']); ?></span>
                                    <?php if (!empty($vac['experience_level'])): ?>
                                        <span class="meta-tag exp"><i class="fas fa-user-check"></i> <?php echo e($vac['experience_level']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($vac['deadline'])): ?>
                                        <span class="meta-tag" style="background:#f1f5f9; color:#475569;">
                                            <i class="fas fa-calendar-alt"></i> Closes: <?php echo e(date('d M Y', strtotime($vac['deadline']))); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="vacancy-actions">
                                <button type="button" class="btn btn-sm btn-outline toggle-vacancy-btn" data-target="drawer-<?php echo (int)$vac['id']; ?>">
                                    <i class="fas fa-info-circle"></i> Details
                                </button>
                                <button type="button" class="btn btn-sm btn-primary apply-now-btn" data-vacancy-id="<?php echo (int)$vac['id']; ?>" data-vacancy-title="<?php echo e($vac['title']); ?>">
                                    <i class="fas fa-paper-plane"></i> Apply
                                </button>
                            </div>
                        </div>

                        <div class="vacancy-desc-snippet">
                            <?php echo nl2br(e($vac['description'])); ?>
                        </div>

                        <?php if (!empty($vac['requirements'])): ?>
                            <div class="vacancy-details-drawer" id="drawer-<?php echo (int)$vac['id']; ?>">
                                <h4>Key Qualifications &amp; Requirements:</h4>
                                <ul>
                                    <?php
                                    $reqLines = explode("\n", $vac['requirements']);
                                    foreach ($reqLines as $rLine):
                                        $rTrim = trim(ltrim($rLine, '-* '));
                                        if ($rTrim !== ''):
                                    ?>
                                        <li><?php echo e($rTrim); ?></li>
                                    <?php endif; endforeach; ?>
                                </ul>
                                <div style="margin-top: 16px;">
                                    <button type="button" class="btn btn-sm btn-primary apply-now-btn" data-vacancy-id="<?php echo (int)$vac['id']; ?>" data-vacancy-title="<?php echo e($vac['title']); ?>">
                                        <i class="fas fa-paper-plane"></i> Apply for this Position
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="no-vacancies-card">
                <i class="fas fa-clipboard-check"></i>
                <h3>No Vacancies Currently Published</h3>
                <p>We do not have any open vacancies listed at this moment. However, we are continuously expanding our talent pool. Submit a general application below, and our Human Resources team will review your qualifications!</p>
                <a href="#apply" class="btn btn-primary"><i class="fas fa-file-signature"></i> Submit General Application</a>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- Application Form Section -->
<section class="application-form-section" id="apply">
    <div class="container">
        <div class="app-form-wrapper">
            <div class="app-form-header">
                <h2>Submit Your Application</h2>
                <p>Provide your details, upload your Curriculum Vitae (CV), and attach relevant credentials.</p>
            </div>

            <div class="app-form-body">
                <?php if ($successData): ?>
                    <div class="success-hero-box">
                        <div class="success-icon-badge">
                            <i class="fas fa-check"></i>
                        </div>
                        <h3 style="color: #0f172a; font-size: 1.6rem; font-weight: 800; margin: 0 0 10px;">
                            Application Submitted Successfully!
                        </h3>
                        <p style="color: #475569; font-size: 1.05rem; line-height: 1.6; max-width: 580px; margin: 0 auto;">
                            Thank you for your interest in joining St. Benedict's Early Years British Academy. Your application for <strong><?php echo e($successData['position']); ?></strong> has been safely recorded.
                        </p>

                        <div class="ref-pill-card">
                            <div style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.1em; color: #64748b; margin-bottom: 4px;">
                                Application Reference Number
                            </div>
                            <strong><?php echo e($successData['reference_no']); ?></strong>
                        </div>

                        <p style="font-size: 0.92rem; color: #64748b; margin-top: 10px;">
                            Please keep this reference number for all future inquiries. Our HR and recruitment committee will review your documents and reach out via email or phone.
                        </p>

                        <div style="margin-top: 24px;">
                            <a href="<?php echo BASE_URL; ?>/public/careers" class="btn btn-outline" style="border-radius: 8px;">
                                <i class="fas fa-arrow-left"></i> Return to Careers Page
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger" style="margin-bottom: 24px;">
                            <strong><i class="fas fa-exclamation-circle"></i> Please review the following errors:</strong>
                            <ul style="margin: 8px 0 0; padding-left: 20px;">
                                <?php foreach ($errors as $err): ?>
                                    <li><?php echo e($err); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form action="<?php echo BASE_URL; ?>/public/careers#apply" method="POST" enctype="multipart/form-data" id="careerApplicationForm">
                        <?php echo csrf_field(); ?>
                        <input type="text" name="website" style="display:none !important;" tabindex="-1" autocomplete="off">

                        <div class="app-grid-2">
                            <div class="form-group-field">
                                <label for="appFullName">Full Name <span class="req">*</span></label>
                                <input type="text" id="appFullName" name="full_name" required maxlength="100" placeholder="e.g. Adebayo Okon" value="<?php echo e($_POST['full_name'] ?? ''); ?>">
                            </div>
                            <div class="form-group-field">
                                <label for="appEmail">Email Address <span class="req">*</span></label>
                                <input type="email" id="appEmail" name="email" required maxlength="100" placeholder="e.g. adebayo@example.com" value="<?php echo e($_POST['email'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="app-grid-2">
                            <div class="form-group-field">
                                <label for="appPhone">Phone Number <span class="req">*</span></label>
                                <input type="tel" id="appPhone" name="phone" required maxlength="25" placeholder="e.g. 08012345678 or +234..." value="<?php echo e($_POST['phone'] ?? ''); ?>">
                            </div>
                            <div class="form-group-field">
                                <label for="appVacancy">Position Applied For <span class="req">*</span></label>
                                <select id="appVacancy" name="vacancy_id">
                                    <option value="">General Application / Talent Pool</option>
                                    <?php foreach ($vacancies as $vac): ?>
                                        <option value="<?php echo (int)$vac['id']; ?>" <?php echo ((string)($_POST['vacancy_id'] ?? '') === (string)$vac['id']) ? 'selected' : ''; ?>>
                                            <?php echo e($vac['title']); ?> (<?php echo e($vac['department']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="app-grid-2">
                            <div class="form-group-field">
                                <label for="appQualification">Highest Qualification</label>
                                <select id="appQualification" name="qualification">
                                    <option value="">Select qualification...</option>
                                    <option value="NCE" <?php echo (($_POST['qualification'] ?? '') === 'NCE') ? 'selected' : ''; ?>>NCE (National Certificate in Education)</option>
                                    <option value="B.Ed" <?php echo (($_POST['qualification'] ?? '') === 'B.Ed') ? 'selected' : ''; ?>>B.Ed (Bachelor of Education)</option>
                                    <option value="B.Sc / B.A" <?php echo (($_POST['qualification'] ?? '') === 'B.Sc / B.A') ? 'selected' : ''; ?>>B.Sc / B.A (Bachelor Degree)</option>
                                    <option value="PGDE" <?php echo (($_POST['qualification'] ?? '') === 'PGDE') ? 'selected' : ''; ?>>PGDE (Postgraduate Diploma in Education)</option>
                                    <option value="M.Ed / M.Sc" <?php echo (($_POST['qualification'] ?? '') === 'M.Ed / M.Sc') ? 'selected' : ''; ?>>Master's Degree (M.Ed / M.Sc / M.A)</option>
                                    <option value="HND / OND" <?php echo (($_POST['qualification'] ?? '') === 'HND / OND') ? 'selected' : ''; ?>>HND / OND</option>
                                    <option value="Doctorate / PhD" <?php echo (($_POST['qualification'] ?? '') === 'Doctorate / PhD') ? 'selected' : ''; ?>>Doctorate / PhD</option>
                                    <option value="Other" <?php echo (($_POST['qualification'] ?? '') === 'Other') ? 'selected' : ''; ?>>Other Professional Certification</option>
                                </select>
                            </div>
                            <div class="form-group-field">
                                <label for="appExperience">Relevant Experience</label>
                                <select id="appExperience" name="experience_years">
                                    <option value="">Select experience level...</option>
                                    <option value="Entry / Fresh Graduate" <?php echo (($_POST['experience_years'] ?? '') === 'Entry / Fresh Graduate') ? 'selected' : ''; ?>>Entry / Fresh Graduate (0 - 1 year)</option>
                                    <option value="1 - 3 Years" <?php echo (($_POST['experience_years'] ?? '') === '1 - 3 Years') ? 'selected' : ''; ?>>1 - 3 Years</option>
                                    <option value="3 - 5 Years" <?php echo (($_POST['experience_years'] ?? '') === '3 - 5 Years') ? 'selected' : ''; ?>>3 - 5 Years</option>
                                    <option value="5 - 10 Years" <?php echo (($_POST['experience_years'] ?? '') === '5 - 10 Years') ? 'selected' : ''; ?>>5 - 10 Years</option>
                                    <option value="10+ Years" <?php echo (($_POST['experience_years'] ?? '') === '10+ Years') ? 'selected' : ''; ?>>10+ Years (Senior / Leadership)</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group-field">
                            <label for="appCoverLetter">Cover Letter / Personal Statement</label>
                            <textarea id="appCoverLetter" name="cover_letter" rows="4" placeholder="Highlight your teaching philosophy, achievements, relevant background, or reasons for joining St. Benedict's..."><?php echo e($_POST['cover_letter'] ?? ''); ?></textarea>
                            <small class="helper">Tell us briefly about yourself and why you would be an asset to our school.</small>
                        </div>

                        <div class="app-grid-2">
                            <div class="form-group-field">
                                <label>Curriculum Vitae (CV) / Resume <span class="req">*</span></label>
                                <div class="upload-box" id="resumeUploadBox">
                                    <i class="fas fa-file-pdf"></i>
                                    <div style="font-weight: 600; color: #1e293b; font-size: 0.92rem;">Click or drag CV file here</div>
                                    <small style="color: #64748b;">PDF, DOC, or DOCX (Max 5MB)</small>
                                    <input type="file" name="resume" id="resumeFileInput" accept=".pdf,.doc,.docx" required>
                                    <div class="upload-filename" id="resumeFileNameDisplay"></div>
                                </div>
                            </div>
                            <div class="form-group-field">
                                <label>Additional Documents / Credentials <small style="color:#64748b;">(Optional)</small></label>
                                <div class="upload-box" id="docUploadBox">
                                    <i class="fas fa-paperclip"></i>
                                    <div style="font-weight: 600; color: #1e293b; font-size: 0.92rem;">Certificates / Transcripts / Portfolio</div>
                                    <small style="color: #64748b;">PDF, DOC, DOCX, JPG, PNG (Max 5MB)</small>
                                    <input type="file" name="additional_doc" id="docFileInput" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                    <div class="upload-filename" id="docFileNameDisplay"></div>
                                </div>
                            </div>
                        </div>

                        <div style="margin-top: 20px;">
                            <?php if (function_exists('turnstile_widget')) echo turnstile_widget('auto', 'careers'); ?>
                        </div>
                        <div style="margin-top: 20px; text-align: right;">
                            <button type="submit" class="btn btn-primary" style="padding: 14px 32px; font-size: 1.05rem; font-weight: 700; border-radius: 8px;">
                                <i class="fas fa-paper-plane"></i> Submit Application
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<script nonce="<?php echo CSP_NONCE; ?>">
document.addEventListener('DOMContentLoaded', function() {
    // Vacancy details drawer toggling
    document.querySelectorAll('.toggle-vacancy-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var targetId = this.getAttribute('data-target');
            var drawer = document.getElementById(targetId);
            if (drawer) {
                var isOpen = drawer.classList.toggle('open');
                this.innerHTML = isOpen ? '<i class="fas fa-times"></i> Hide' : '<i class="fas fa-info-circle"></i> Details';
            }
        });
    });

    // Apply button pre-selects vacancy and scrolls smoothly to application form
    document.querySelectorAll('.apply-now-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var vacId = this.getAttribute('data-vacancy-id');
            var select = document.getElementById('appVacancy');
            if (select && vacId) {
                select.value = vacId;
            }
            var formSection = document.getElementById('apply');
            if (formSection) {
                formSection.scrollIntoView({ behavior: 'smooth' });
                var nameInput = document.getElementById('appFullName');
                if (nameInput) setTimeout(function() { nameInput.focus(); }, 400);
            }
        });
    });

    // Client-side search and department filtering
    var searchInput = document.getElementById('vacancySearchInput');
    var deptFilter = document.getElementById('departmentFilter');
    var vacancyCards = document.querySelectorAll('.vacancy-card');

    function filterVacancies() {
        var query = searchInput ? searchInput.value.toLowerCase().trim() : '';
        var dept = deptFilter ? deptFilter.value.toLowerCase().trim() : '';

        vacancyCards.forEach(function(card) {
            var cardTitle = card.getAttribute('data-title') || '';
            var cardDept = card.getAttribute('data-department') || '';
            var cardText = card.textContent.toLowerCase();

            var matchesSearch = !query || cardTitle.indexOf(query) !== -1 || cardText.indexOf(query) !== -1;
            var matchesDept = !dept || cardDept.indexOf(dept) !== -1;

            card.style.display = (matchesSearch && matchesDept) ? 'block' : 'none';
        });
    }

    if (searchInput) searchInput.addEventListener('input', filterVacancies);
    if (deptFilter) deptFilter.addEventListener('change', filterVacancies);

    // File input name displays
    var resumeInput = document.getElementById('resumeFileInput');
    var resumeDisplay = document.getElementById('resumeFileNameDisplay');
    if (resumeInput && resumeDisplay) {
        resumeInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                resumeDisplay.textContent = 'Selected: ' .concat(this.files[0].name);
            } else {
                resumeDisplay.textContent = '';
            }
        });
    }

    var docInput = document.getElementById('docFileInput');
    var docDisplay = document.getElementById('docFileNameDisplay');
    if (docInput && docDisplay) {
        docInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                docDisplay.textContent = 'Selected: ' .concat(this.files[0].name);
            } else {
                docDisplay.textContent = '';
            }
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
