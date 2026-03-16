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
                            <li><a href="<?php echo BASE_URL; ?>/public/fees.php">Fee Structure</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/calendar.php">School Calendar</a></li>
                            <li><a href="<?php echo BASE_URL; ?>/public/downloads.php">Downloads</a></li>
                        </ul>
                    </div>
                    
                    <div class="footer-social">
                        <h4>Connect With Us</h4>
                        <div class="social-icons">
                            <a href="#" target="_blank"><i class="fab fa-facebook"></i></a>
                            <a href="#" target="_blank"><i class="fab fa-twitter"></i></a>
                            <a href="#" target="_blank"><i class="fab fa-instagram"></i></a>
                            <a href="#" target="_blank"><i class="fab fa-youtube"></i></a>
                            <a href="#" target="_blank"><i class="fab fa-linkedin"></i></a>
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
    
    <!-- JavaScript -->
    <script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>
    <?php if (isset($extraJS)): ?>
        <?php foreach ($extraJS as $js): ?>
            <script src="<?php echo BASE_URL; ?>/assets/js/<?php echo $js; ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>