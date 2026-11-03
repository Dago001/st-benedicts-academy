<?php
// includes/footer.php
$isLandingPage = empty($inPortal) && (
    ($currentPage ?? basename($_SERVER['PHP_SELF'] ?? '', '.php')) === 'index' &&
    !preg_match('#/(admin|teacher|parent|student|public)/#i', $_SERVER['SCRIPT_NAME'] ?? '')
);
$isBackendPortal = !empty($inPortal) ||
    (isset($_SESSION['user_id']) && preg_match('#/(admin|teacher|parent|student)/#i', $_SERVER['SCRIPT_NAME'] ?? '')) ||
    preg_match('#/(admin|teacher|parent|student)/#i', $_SERVER['SCRIPT_NAME'] ?? '');
?>
        </main>

        <?php if (!$isBackendPortal): ?>
        <!-- Footer (Public / Frontend Pages Only) -->
        <footer class="main-footer<?php echo $isLandingPage ? '' : ' footer-minimal'; ?>">
            <div class="footer-container">
                <?php if ($isLandingPage): ?>
                <div class="footer-grid">
                    <div class="footer-info">
                        <h3><?php echo SCHOOL_NAME; ?></h3>
                        <p><i class="fas fa-map-marker-alt"></i> <?php echo school_address(); ?></p>
                        <p><i class="fas fa-phone"></i> <?php echo school_phone(); ?></p>
                        <p><i class="fas fa-envelope"></i> <?php echo school_email(); ?></p>
                        <p class="motto"><?php echo school_motto(); ?></p>
                        <div class="footer-accreditation" style="margin-top: 14px; font-size: 0.82rem; color: rgba(255,255,255,0.75); line-height: 1.45;">
                            <strong style="color: var(--gold, #ffd700);"><i class="fas fa-award"></i> British Curriculum Standards</strong><br>
                            Early Years Foundation Stage (EYFS) &amp; British Primary Academic Framework
                        </div>
                    </div>

                    <div class="footer-links">
                        <h4>Quick Links</h4>
                        <ul>
                            <li><a href="<?php echo BASE_URL; ?>/public/about">About Us</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/admissions">Admissions</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/academics">Academics</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/careers">Careers</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/news">News &amp; Events</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/gallery">School Gallery</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/contact">Contact Us</a></li>
                        </ul>
                    </div>

                    <div class="footer-links">
                        <h4>For Parents</h4>
                        <ul>
                            <li><a href="<?php echo BASE_URL; ?>/public/apply">Apply Online</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/login">Parent Portal</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/admissions#fees">Tuition &amp; Fees</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/academics#curriculum">Curriculum Details</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/contact#visit">Visit Campus</a></li>
                        </ul>
                    </div>

                    <div class="footer-links">
                        <h4>School Policies</h4>
                        <ul>
                            <li><a href="<?php echo BASE_URL; ?>/public/policies#safeguarding">Child Safeguarding</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/policies#admissions">Admissions Policy</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/policies#behavior">Code of Conduct</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/policies#health-safety">Health &amp; Safety</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/policies#antibullying">Anti-Bullying Policy</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/privacy">Privacy &amp; Data Notice</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/policies#terms">Terms of Enrolment</a></li>
                        </ul>
                    </div>

                    <div class="footer-social">
                        <h4>School Hours &amp; Connect</h4>
                        <div class="school-hours" style="font-size: 0.82rem; color: rgba(255,255,255,0.8); line-height: 1.5; margin-bottom: 14px;">
                            <p style="margin: 0 0 4px;"><strong style="color: #fff;">Early Years:</strong> 7:30 AM – 1:30 PM</p>
                            <p style="margin: 0 0 4px;"><strong style="color: #fff;">Primary Academy:</strong> 7:30 AM – 2:30 PM</p>
                            <p style="margin: 0 0 8px;"><strong style="color: #fff;">Office:</strong> 8:00 AM – 4:00 PM (Mon–Fri)</p>
                        </div>
                        <div class="social-icons">
                            <a href="tel:<?php echo e(school_phone()); ?>" aria-label="Call us"><i class="fas fa-phone"></i></a>
                            <a href="mailto:<?php echo e(school_email()); ?>" aria-label="Email us"><i class="fas fa-envelope"></i></a>
                            <a href="<?php echo BASE_URL; ?>/public/contact" aria-label="Contact page"><i class="fas fa-map-marker-alt"></i></a>
                            <?php foreach (['facebook' => 'fa-facebook-f', 'instagram' => 'fa-instagram', 'twitter' => 'fa-x-twitter', 'youtube' => 'fa-youtube'] as $net => $ico): $u = cms('school.' . $net); if ($u !== ''): ?>
                            <a href="<?php echo e($u); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo e(ucfirst($net)); ?>"><i class="fab <?php echo $ico; ?>"></i></a>
                            <?php endif; endforeach; ?>
                            <?php $wa = preg_replace('/\D/', '', cms('school.whatsapp')); if ($wa !== ''): ?>
                            <a href="https://wa.me/<?php echo e($wa); ?>" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp"><i class="fab fa-whatsapp"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="footer-bottom">
                    <p>&copy; <?php echo date('Y'); ?> <?php echo SCHOOL_NAME; ?>. All rights reserved.
                       <br class="mobile-only"> <a href="<?php echo BASE_URL; ?>/public/privacy">Privacy Notice</a>
                    </p>
                </div>
            </div>
        </footer>
        <?php endif; ?>
    </div>

    <?php if (!Security::isLoggedIn() && !defined('NO_CHATBOT')) { include __DIR__ . '/chatbot_widget.php'; } ?>
    <!-- JavaScript -->
    <script src="<?php echo BASE_URL; ?>/assets/js/main.js?v=<?php echo $assetVersion ?? '20'; ?>"></script>
    <?php foreach (($extraJS ?? []) as $js): if (is_file(ROOT_PATH . '/assets/js/' . basename($js))): ?>
        <script src="<?php echo BASE_URL; ?>/assets/js/<?php echo e(basename($js)); ?>?v=<?php echo $assetVersion ?? '20'; ?>"></script>
    <?php endif; endforeach; ?>
</body>
</html>
