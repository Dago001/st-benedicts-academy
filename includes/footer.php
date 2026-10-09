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
                        <p><i class="fas fa-map-marker-alt"></i> <?php echo SCHOOL_ADDRESS; ?></p>
                        <p><i class="fas fa-phone"></i> <?php echo SCHOOL_PHONE; ?></p>
                        <p><i class="fas fa-envelope"></i> <?php echo SCHOOL_EMAIL; ?></p>
                        <p class="motto"><?php echo SCHOOL_MOTTO; ?></p>
                    </div>

                    <div class="footer-links">
                        <h4>Quick Links</h4>
                        <ul>
                            <li><a href="<?php echo BASE_URL; ?>/public/about.php">About Us</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/admissions.php">Admissions</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/academics.php">Academics</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/contact.php">Contact</a></li>
                        </ul>
                    </div>

                    <div class="footer-links">
                        <h4>For Parents</h4>
                        <ul>
                            <li><a href="<?php echo BASE_URL; ?>/login.php">Parent Portal</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/apply.php">Apply Online</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/news.php">News &amp; Events</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/gallery.php">Gallery</a></li>
                        </ul>
                    </div>

                    <div class="footer-social">
                        <h4>Connect With Us</h4>
                        <div class="social-icons">
                            <a href="tel:<?php echo e(SCHOOL_PHONE); ?>" aria-label="Call us"><i class="fas fa-phone"></i></a>
                            <a href="mailto:<?php echo e(SCHOOL_EMAIL); ?>" aria-label="Email us"><i class="fas fa-envelope"></i></a>
                            <a href="<?php echo BASE_URL; ?>/public/contact.php" aria-label="Contact page"><i class="fas fa-map-marker-alt"></i></a>
                        </div>
                    </div>
                </div>

                <div class="footer-bottom">
                    <p>&copy; <?php echo date('Y'); ?> <?php echo SCHOOL_NAME; ?>. All rights reserved.
                       <br class="mobile-only"></p>
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