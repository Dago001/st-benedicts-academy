<?php
// public/privacy.php - privacy notice (NDPR / Nigeria Data Protection Act 2023)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';

$pageTitle = 'Privacy Notice';
$pageDescription = 'How ' . SCHOOL_NAME . ' collects, uses and protects pupil and family information.';
include __DIR__ . '/../includes/header.php';
?>
<main id="main-content">
<section class="section" style="padding:60px 0">
  <div class="container" style="max-width:820px">
    <h1>Privacy Notice</h1>
    <p class="text-muted">Last updated: <?php echo date('F Y'); ?></p>

    <p><?php echo e(SCHOOL_NAME); ?> ("the School", "we") is the data controller for the personal information described below. We process it in line with the Nigeria Data Protection Act 2023 and the NDPR.</p>

    <h2>What we collect</h2>
    <ul>
      <li><strong>Admissions:</strong> child's name, date of birth, gender, previous school, birth certificate and passport photograph; parent/guardian names, phone, email, address and emergency contact.</li>
      <li><strong>School records:</strong> attendance, assessment results, homework, fee payments, and messages between parents and teachers.</li>
      <li><strong>Website use:</strong> a session cookie for signed-in users, and a short log of questions asked to the website assistant (email addresses and phone numbers are removed before saving).</li>
    </ul>

    <h2>Why we use it</h2>
    <p>To process admissions, teach and care for pupils, report progress to parents, manage fees, keep pupils safe, and meet legal obligations. Our lawful bases are performance of the enrolment contract, legal obligation, and legitimate interests in running the school. We do not sell personal data and do not use it for advertising.</p>

    <h2>Who sees it</h2>
    <p>Only staff who need it for their role. Parents see only their own children. Admission documents are stored privately and are visible to school administrators only. We share information with third parties only where required by law or to deliver a service you have asked for (for example, email delivery).</p>

    <h2>How long we keep it</h2>
    <ul>
      <li>Pupil records: for the period of enrolment and a further 7 years.</li>
      <li>Unsuccessful applications: 12 months.</li>
      <li>Website assistant logs: 90 days. Security audit logs: 24 months.</li>
    </ul>

    <h2>How we protect it</h2>
    <p>Encrypted connections (HTTPS), hashed passwords, optional two-step verification, role-based access, activity audit logs, and regular backups.</p>

    <h2>Your rights</h2>
    <p>You may ask to access, correct, export, restrict or erase your or your child's data, withdraw consent, or complain to the Nigeria Data Protection Commission. Contact us at <a href="mailto:<?php echo e(SCHOOL_EMAIL); ?>"><?php echo e(SCHOOL_EMAIL); ?></a> or <a href="tel:<?php echo e(SCHOOL_PHONE); ?>"><?php echo e(SCHOOL_PHONE); ?></a>; we respond within 30 days.</p>

    <h2>Contact</h2>
    <p><?php echo e(SCHOOL_NAME); ?>, <?php echo e(SCHOOL_ADDRESS); ?>.</p>
  </div>
</section>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
