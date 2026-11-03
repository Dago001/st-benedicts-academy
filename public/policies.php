<?php
// public/policies.php - School Policies, Safeguarding & Codes of Practice
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';

$pageTitle = 'School Policies & Procedures';
$pageDescription = 'Institutional policies, safeguarding frameworks, code of conduct, and academic guidelines at ' . SCHOOL_NAME . '.';
include __DIR__ . '/../includes/header.php';
?>
<main id="main-content">
    <!-- Hero / Header Banner -->
    <section class="page-hero" style="background: linear-gradient(135deg, var(--brand-deep, #001835) 0%, var(--brand, #002855) 100%); padding: 50px 0; color: #fff; text-align: center; border-bottom: 4px solid var(--gold-ui, #ffd700);">
        <div class="container">
            <span style="display:inline-block; font-size: 0.8rem; letter-spacing: 0.15em; text-transform: uppercase; color: var(--gold-ui, #ffd700); font-weight: 700; margin-bottom: 8px;">Governance &amp; Care</span>
            <h1 style="font-size: clamp(1.8rem, 3.5vw, 2.5rem); margin: 0 0 10px; font-weight: 800; color: #fff;">School Policies &amp; Standards</h1>
            <p style="max-width: 680px; margin: 0 auto; font-size: 0.98rem; color: rgba(255,255,255,0.85); line-height: 1.6;">
                Our comprehensive policies safeguard pupils, support teachers, and partner with parents in delivering the highest standards of British Early Years and Primary education.
            </p>
        </div>
    </section>

    <!-- Policies Body -->
    <section class="section" style="padding: 40px 0 70px; background: #f8fafc;">
        <div class="container" style="max-width: 1040px;">

            <!-- Quick Navigation Pills -->
            <div class="policy-nav-pills" style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 36px; justify-content: center;">
                <a href="#safeguarding" class="btn" style="background:#fff; color:var(--brand, #002855); border:1px solid #cbd5e1; font-size:0.85rem; font-weight:600; padding:8px 14px; border-radius:30px;"><i class="fas fa-shield-alt" style="color:var(--brand-accent, #c8102e);"></i> Safeguarding</a>
                <a href="#admissions" class="btn" style="background:#fff; color:var(--brand, #002855); border:1px solid #cbd5e1; font-size:0.85rem; font-weight:600; padding:8px 14px; border-radius:30px;"><i class="fas fa-user-check" style="color:#0284c7;"></i> Admissions</a>
                <a href="#behavior" class="btn" style="background:#fff; color:var(--brand, #002855); border:1px solid #cbd5e1; font-size:0.85rem; font-weight:600; padding:8px 14px; border-radius:30px;"><i class="fas fa-hand-holding-heart" style="color:#16a34a;"></i> Code of Conduct</a>
                <a href="#health-safety" class="btn" style="background:#fff; color:var(--brand, #002855); border:1px solid #cbd5e1; font-size:0.85rem; font-weight:600; padding:8px 14px; border-radius:30px;"><i class="fas fa-medkit" style="color:#dc2626;"></i> Health &amp; Safety</a>
                <a href="#antibullying" class="btn" style="background:#fff; color:var(--brand, #002855); border:1px solid #cbd5e1; font-size:0.85rem; font-weight:600; padding:8px 14px; border-radius:30px;"><i class="fas fa-smile" style="color:#d97706;"></i> Anti-Bullying</a>
                <a href="#terms" class="btn" style="background:#fff; color:var(--brand, #002855); border:1px solid #cbd5e1; font-size:0.85rem; font-weight:600; padding:8px 14px; border-radius:30px;"><i class="fas fa-file-contract" style="color:#4f46e5;"></i> Terms of Enrolment</a>
                <a href="<?php echo BASE_URL; ?>/public/privacy" class="btn" style="background:#fff; color:var(--brand, #002855); border:1px solid #cbd5e1; font-size:0.85rem; font-weight:600; padding:8px 14px; border-radius:30px;"><i class="fas fa-lock" style="color:#059669;"></i> Data Privacy</a>
            </div>

            <!-- Policy 1: Child Safeguarding -->
            <div class="policy-card" id="safeguarding" style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding: clamp(20px, 3vw, 36px); margin-bottom: 28px; box-shadow: 0 4px 12px rgba(0,0,0,0.03);">
                <div style="display:flex; align-items:center; gap:12px; margin-bottom: 16px; border-bottom: 2px solid #f1f5f9; padding-bottom: 12px;">
                    <div style="width:44px; height:44px; border-radius:10px; background:rgba(200,16,46,0.1); color:var(--brand-accent, #c8102e); display:flex; align-items:center; justify-content:center; font-size:1.3rem;">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div>
                        <h2 style="font-size: clamp(1.25rem, 2vw, 1.55rem); margin: 0; color: var(--brand, #002855);">Child Protection &amp; Safeguarding Policy</h2>
                        <span style="font-size: 0.8rem; color: #64748b;">Statutory Framework: UK Keeping Children Safe in Education (KCSIE) &amp; Nigeria Child Rights Act</span>
                    </div>
                </div>
                <div style="font-size: 0.95rem; line-height: 1.7; color: #334155;">
                    <p>At <strong><?php echo e(SCHOOL_NAME); ?></strong>, the safety, physical welfare, and emotional well-being of every child are paramount. We operate a zero-tolerance policy towards abuse, neglect, discrimination, and maltreatment.</p>
                    
                    <h4 style="color:var(--brand, #002855); margin-top:16px; margin-bottom:6px;">Key Principles:</h4>
                    <ul style="padding-left: 20px; margin-bottom: 16px;">
                        <li><strong>Safe Recruitment:</strong> All staff, administrators, and regular contractors undergo comprehensive identity, criminal, and background checks before engagement.</li>
                        <li><strong>Designated Safeguarding Lead (DSL):</strong> The Academy maintains a trained Designated Safeguarding Lead responsible for child welfare matters and rapid response.</li>
                        <li><strong>Campus Security &amp; Pick-up Protocols:</strong> Children are released strictly to authorized parents or guardians holding the verified School Pick-Up Tag. Any change requires advance written notice and photo identification.</li>
                        <li><strong>Mandatory Reporting:</strong> All staff are legally and ethically obligated to promptly report any welfare, abuse, or developmental concerns to the DSL and School Leadership.</li>
                    </ul>
                </div>
            </div>

            <!-- Policy 2: Admissions & Enrolment -->
            <div class="policy-card" id="admissions" style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding: clamp(20px, 3vw, 36px); margin-bottom: 28px; box-shadow: 0 4px 12px rgba(0,0,0,0.03);">
                <div style="display:flex; align-items:center; gap:12px; margin-bottom: 16px; border-bottom: 2px solid #f1f5f9; padding-bottom: 12px;">
                    <div style="width:44px; height:44px; border-radius:10px; background:rgba(2,132,199,0.1); color:#0284c7; display:flex; align-items:center; justify-content:center; font-size:1.3rem;">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div>
                        <h2 style="font-size: clamp(1.25rem, 2vw, 1.55rem); margin: 0; color: var(--brand, #002855);">Admissions &amp; Enrolment Policy</h2>
                        <span style="font-size: 0.8rem; color: #64748b;">Equal Opportunity, Age-Appropriate Entry &amp; Academic Placement</span>
                    </div>
                </div>
                <div style="font-size: 0.95rem; line-height: 1.7; color: #334155;">
                    <p><?php echo e(SCHOOL_NAME); ?> welcomes boys and girls from diverse cultures and backgrounds. Enrolment is conducted transparently based on readiness, availability of class spaces, and age suitability.</p>
                    
                    <h4 style="color:var(--brand, #002855); margin-top:16px; margin-bottom:6px;">Enrolment Stages:</h4>
                    <ul style="padding-left: 20px; margin-bottom: 16px;">
                        <li><strong>Crèche &amp; Toddlers (3 months – 2 years):</strong> Developmental milestone observation and family interview.</li>
                        <li><strong>Nursery 1 &amp; 2 (3 – 4 years):</strong> Social interaction, motor skills, and foundational communication assessment.</li>
                        <li><strong>Reception &amp; Primary (5 – 11 years):</strong> Diagnostic literacy, numeracy, and cognitive aptitude evaluation.</li>
                        <li><strong>Required Records:</strong> Certified birth certificate, current immunization records, two passport photographs, and previous academic transcripts where applicable.</li>
                    </ul>
                    <p style="margin-top: 10px;">
                        <a href="<?php echo BASE_URL; ?>/public/apply" class="btn btn-primary btn-sm" style="font-size: 0.85rem; padding: 6px 16px;"><i class="fas fa-edit"></i> Apply for Admission Online</a>
                    </p>
                </div>
            </div>

            <!-- Policy 3: Code of Conduct & Behaviour -->
            <div class="policy-card" id="behavior" style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding: clamp(20px, 3vw, 36px); margin-bottom: 28px; box-shadow: 0 4px 12px rgba(0,0,0,0.03);">
                <div style="display:flex; align-items:center; gap:12px; margin-bottom: 16px; border-bottom: 2px solid #f1f5f9; padding-bottom: 12px;">
                    <div style="width:44px; height:44px; border-radius:10px; background:rgba(22,163,74,0.1); color:#16a34a; display:flex; align-items:center; justify-content:center; font-size:1.3rem;">
                        <i class="fas fa-hand-holding-heart"></i>
                    </div>
                    <div>
                        <h2 style="font-size: clamp(1.25rem, 2vw, 1.55rem); margin: 0; color: var(--brand, #002855);">Code of Conduct &amp; Positive Discipline</h2>
                        <span style="font-size: 0.8rem; color: #64748b;">Nurturing Respect, Responsibility, and Integrity</span>
                    </div>
                </div>
                <div style="font-size: 0.95rem; line-height: 1.7; color: #334155;">
                    <p>We believe in positive reinforcement, emotional intelligence, and mutual respect. Our behavioural framework encourages pupils to make thoughtful choices and respect their peers and teachers.</p>
                    
                    <h4 style="color:var(--brand, #002855); margin-top:16px; margin-bottom:6px;">Expectations:</h4>
                    <ul style="padding-left: 20px; margin-bottom: 16px;">
                        <li><strong>Punctuality &amp; Attendance:</strong> Pupils must arrive promptly before 7:45 AM for registration and morning assembly.</li>
                        <li><strong>Academy Uniform:</strong> Pupils must wear full, clean school uniform in accordance with the seasonal uniform schedule.</li>
                        <li><strong>Respect &amp; Kindness:</strong> Politeness, courtesy, and empathy are expected across all grades and school environments.</li>
                        <li><strong>Zero Tolerance for Corporal Punishment:</strong> Under no circumstances is physical or degrading punishment permitted at our Academy. We practice restorative conversations, reflection periods, and parent collaboration.</li>
                    </ul>
                </div>
            </div>

            <!-- Policy 4: Health, Safety & Medical -->
            <div class="policy-card" id="health-safety" style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding: clamp(20px, 3vw, 36px); margin-bottom: 28px; box-shadow: 0 4px 12px rgba(0,0,0,0.03);">
                <div style="display:flex; align-items:center; gap:12px; margin-bottom: 16px; border-bottom: 2px solid #f1f5f9; padding-bottom: 12px;">
                    <div style="width:44px; height:44px; border-radius:10px; background:rgba(220,38,38,0.1); color:#dc2626; display:flex; align-items:center; justify-content:center; font-size:1.3rem;">
                        <i class="fas fa-medkit"></i>
                    </div>
                    <div>
                        <h2 style="font-size: clamp(1.25rem, 2vw, 1.55rem); margin: 0; color: var(--brand, #002855);">Health, Safety &amp; First Aid Policy</h2>
                        <span style="font-size: 0.8rem; color: #64748b;">Hygiene, Medical Welfare, Emergency Care &amp; Allergy Management</span>
                    </div>
                </div>
                <div style="font-size: 0.95rem; line-height: 1.7; color: #334155;">
                    <p>The health and physical safety of our school community are supported by professional protocols, certified first aid staff, and sanitized facilities.</p>
                    
                    <h4 style="color:var(--brand, #002855); margin-top:16px; margin-bottom:6px;">Guidelines:</h4>
                    <ul style="padding-left: 20px; margin-bottom: 16px;">
                        <li><strong>On-Campus First Aid:</strong> A staffed infirmary provides immediate assessment and first aid for minor illness or injuries. Parents are alerted immediately for any significant incident.</li>
                        <li><strong>Medication Administration:</strong> Prescribed medications will only be administered with written parental consent and clear physician instructions.</li>
                        <li><strong>Infectious Illnesses:</strong> Children exhibiting fever (&ge; 38&deg;C), contagious rashes, vomiting, or eye infections must remain home until cleared by a physician or symptom-free for 24 hours.</li>
                        <li><strong>Emergency Preparedness:</strong> Regular fire evacuation and emergency response drills are conducted termly with both staff and pupils.</li>
                    </ul>
                </div>
            </div>

            <!-- Policy 5: Anti-Bullying -->
            <div class="policy-card" id="antibullying" style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding: clamp(20px, 3vw, 36px); margin-bottom: 28px; box-shadow: 0 4px 12px rgba(0,0,0,0.03);">
                <div style="display:flex; align-items:center; gap:12px; margin-bottom: 16px; border-bottom: 2px solid #f1f5f9; padding-bottom: 12px;">
                    <div style="width:44px; height:44px; border-radius:10px; background:rgba(217,119,6,0.1); color:#d97706; display:flex; align-items:center; justify-content:center; font-size:1.3rem;">
                        <i class="fas fa-smile"></i>
                    </div>
                    <div>
                        <h2 style="font-size: clamp(1.25rem, 2vw, 1.55rem); margin: 0; color: var(--brand, #002855);">Anti-Bullying &amp; Inclusion Policy</h2>
                        <span style="font-size: 0.8rem; color: #64748b;">Creating an Inclusive, Empowering and Kind Academy Community</span>
                    </div>
                </div>
                <div style="font-size: 0.95rem; line-height: 1.7; color: #334155;">
                    <p>Bullying of any kind (verbal, physical, emotional, or cyberbullying) is strictly unacceptable. Every child has an equal right to learn in a welcoming, joyful environment free from intimidation or fear.</p>
                    
                    <h4 style="color:var(--brand, #002855); margin-top:16px; margin-bottom:6px;">Action &amp; Resolution:</h4>
                    <ul style="padding-left: 20px; margin-bottom: 16px;">
                        <li>Pupils are actively taught empathy, friendship skills, and conflict resolution during Personal, Social, Health and Economic (PSHE) sessions.</li>
                        <li>All reported bullying incidents are investigated within 24 hours by class teachers and school leadership.</li>
                        <li>Targeted counselling, structured mediation, and regular follow-up ensure emotional safety and lasting resolution.</li>
                    </ul>
                </div>
            </div>

            <!-- Policy 6: Terms & Conditions -->
            <div class="policy-card" id="terms" style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding: clamp(20px, 3vw, 36px); margin-bottom: 28px; box-shadow: 0 4px 12px rgba(0,0,0,0.03);">
                <div style="display:flex; align-items:center; gap:12px; margin-bottom: 16px; border-bottom: 2px solid #f1f5f9; padding-bottom: 12px;">
                    <div style="width:44px; height:44px; border-radius:10px; background:rgba(79,70,229,0.1); color:#4f46e5; display:flex; align-items:center; justify-content:center; font-size:1.3rem;">
                        <i class="fas fa-file-contract"></i>
                    </div>
                    <div>
                        <h2 style="font-size: clamp(1.25rem, 2vw, 1.55rem); margin: 0; color: var(--brand, #002855);">Terms of Enrolment &amp; Financial Policy</h2>
                        <span style="font-size: 0.8rem; color: #64748b;">Fee Schedules, Notice of Withdrawal &amp; School Regulations</span>
                    </div>
                </div>
                <div style="font-size: 0.95rem; line-height: 1.7; color: #334155;">
                    <p>Enrolment of a student constitutes an agreement between parents/guardians and <?php echo e(SCHOOL_NAME); ?> to adhere to institutional policies and academic regulations.</p>
                    
                    <h4 style="color:var(--brand, #002855); margin-top:16px; margin-bottom:6px;">Terms Summary:</h4>
                    <ul style="padding-left: 20px; margin-bottom: 16px;">
                        <li><strong>Tuition &amp; Fee Payments:</strong> School fees are due prior to the commencement of each academic term. Payment plans may be arranged with the Bursary upon request.</li>
                        <li><strong>Notice of Withdrawal:</strong> A minimum of one full term's written notice is required prior to withdrawing a pupil; otherwise, one term's tuition in lieu of notice is applicable.</li>
                        <li><strong>Digital Systems &amp; Portal Use:</strong> Parents and guardians agree to maintain confidential login credentials for the school management portal.</li>
                    </ul>
                    <p style="margin-top: 10px; font-size: 0.88rem; color: #64748b;">
                        For detailed questions or requests regarding our policies, please contact the Principal's Office at <a href="mailto:<?php echo e(school_email()); ?>"><?php echo e(school_email()); ?></a> or call <a href="tel:<?php echo e(school_phone()); ?>"><?php echo e(school_phone()); ?></a>.
                    </p>
                </div>
            </div>

        </div>
    </section>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
