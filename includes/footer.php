<?php
// includes/footer.php
?>
        </main>

        <!-- Footer -->
        <footer class="main-footer">
            <div class="footer-container">
                <div class="footer-grid">
                    <div class="footer-info">
                        <h3><?php echo SCHOOL_NAME; ?></h3>
                        <p><i class="fas fa-map-marker-alt"></i> <?php echo school_address(); ?></p>
                        <p><i class="fas fa-phone"></i> <?php echo school_phone(); ?></p>
                        <p><i class="fas fa-envelope"></i> <?php echo school_email(); ?></p>
                        <p class="motto"><?php echo school_motto(); ?></p>
                    </div>

                    <div class="footer-links">
                        <h4>Quick Links</h4>
                        <ul>
                            <li><a href="<?php echo BASE_URL; ?>/public/about">About Us</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/admissions">Admissions</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/academics">Academics</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/contact">Contact</a></li>
                        </ul>
                    </div>

                    <div class="footer-links">
                        <h4>For Parents</h4>
                        <ul>
                            <li><a href="<?php echo BASE_URL; ?>/login">Parent Portal</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/apply">Apply Online</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/news">News &amp; Events</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/gallery">Gallery</a></li>
                        </ul>
                    </div>

                    <div class="footer-social">
                        <h4>Connect With Us</h4>
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

                <div class="footer-bottom">
                    <p>&copy; <?php echo date('Y'); ?> <?php echo SCHOOL_NAME; ?>. All rights reserved.
                       <br class="mobile-only"> <a href="<?php echo BASE_URL; ?>/public/privacy">Privacy Notice</a></p>
                </div>
            </div>
        </footer>
    </div>

    <?php if (!Security::isLoggedIn() && !defined('NO_CHATBOT')) { include __DIR__ . '/chatbot_widget.php'; } ?>
    <!-- JavaScript -->
    <script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>
    <?php foreach (($extraJS ?? []) as $js): if (is_file(ROOT_PATH . '/assets/js/' . basename($js))): ?>
        <script src="<?php echo BASE_URL; ?>/assets/js/<?php echo e(basename($js)); ?>"></script>
    <?php endif; endforeach; ?>
</body>
</html>