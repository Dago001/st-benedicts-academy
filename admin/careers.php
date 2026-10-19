<?php
// admin/careers.php - Manage Job Vacancies and Candidate Applications
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/Career.php';

Security::requireRole('admin');

Career::ensureTables();

$db = Database::getInstance();

// ---- Handle Secure Document Download ----
if (isset($_GET['action']) && $_GET['action'] === 'download') {
    $appId = (int)($_GET['id'] ?? 0);
    $type = $_GET['type'] ?? 'resume'; // 'resume' or 'doc'

    $app = Career::getApplication($appId);
    if (!$app) {
        http_response_code(404);
        exit('Application not found');
    }

    $filename = ($type === 'doc') ? $app['additional_doc_path'] : $app['resume_path'];
    if (!$filename || basename($filename) !== $filename) {
        http_response_code(404);
        exit('File not found');
    }

    $filepath = PRIVATE_PATH . 'careers/' . $filename;
    if (!is_file($filepath)) {
        http_response_code(404);
        exit('Physical file missing from storage');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($filepath) ?: 'application/octet-stream';

    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . basename($filename) . '"');
    header('Content-Length: ' . filesize($filepath));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');

    Security::logAudit('DOWNLOADED_CAREER_DOCUMENT', 'career_applications', $appId, null, [
        'type' => $type,
        'filename' => $filename
    ]);

    readfile($filepath);
    exit;
}

[$message, $messageType] = flash_get();

// ---- Handle Form Submissions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flash_redirect('Security token expired. Please try again.', 'error');
    }

    $postAction = $_POST['form_action'] ?? '';

    // 1. Update Application Status & Notes (Attending to candidate)
    if ($postAction === 'update_application') {
        $appId = (int)($_POST['application_id'] ?? 0);
        $status = $_POST['status'] ?? 'pending';
        $notes = trim($_POST['admin_notes'] ?? '');

        if ($appId > 0 && Career::updateApplicationStatus($appId, $status, $notes, $_SESSION['user_id'])) {
            flash_redirect('Application status and notes updated successfully.', 'success', BASE_URL . '/admin/careers?tab=applications#app-' . $appId);
        } else {
            flash_redirect('Failed to update application.', 'error', BASE_URL . '/admin/careers?tab=applications');
        }
    }

    // 2. Delete Application
    if ($postAction === 'delete_application') {
        $appId = (int)($_POST['application_id'] ?? 0);
        $app = Career::getApplication($appId);
        if ($app) {
            // Remove stored files if present
            if (!empty($app['resume_path'])) {
                @unlink(PRIVATE_PATH . 'careers/' . basename($app['resume_path']));
            }
            if (!empty($app['additional_doc_path'])) {
                @unlink(PRIVATE_PATH . 'careers/' . basename($app['additional_doc_path']));
            }
            $db->query("DELETE FROM career_applications WHERE id = ?", [$appId]);
            Security::logAudit('DELETED_CAREER_APPLICATION', 'career_applications', $appId);
            flash_redirect('Application permanently deleted.', 'success', BASE_URL . '/admin/careers?tab=applications');
        } else {
            flash_redirect('Application not found.', 'error', BASE_URL . '/admin/careers?tab=applications');
        }
    }

    // 3. Save / Update Job Vacancy
    if ($postAction === 'save_vacancy') {
        $vacId = !empty($_POST['vacancy_id']) ? (int)$_POST['vacancy_id'] : null;
        $title = trim(Security::sanitize($_POST['title'] ?? ''));
        $department = trim(Security::sanitize($_POST['department'] ?? ''));
        $jobType = trim(Security::sanitize($_POST['job_type'] ?? 'Full-time'));
        $location = trim(Security::sanitize($_POST['location'] ?? 'Main Campus, Abuja'));
        $experienceLevel = trim(Security::sanitize($_POST['experience_level'] ?? ''));
        $deadline = !empty($_POST['deadline']) ? valid_date($_POST['deadline']) : null;
        $status = in_array($_POST['status'] ?? '', ['published', 'draft', 'closed'], true) ? $_POST['status'] : 'published';
        $description = trim(Security::sanitize($_POST['description'] ?? ''));
        $requirements = trim(Security::sanitize($_POST['requirements'] ?? ''));

        if ($title === '') {
            flash_redirect('Job title is required.', 'error', BASE_URL . '/admin/careers?tab=vacancies');
        }
        if ($description === '') {
            flash_redirect('Job description is required.', 'error', BASE_URL . '/admin/careers?tab=vacancies');
        }

        $savedId = Career::saveVacancy([
            'title' => $title,
            'department' => $department ?: 'General',
            'job_type' => $jobType,
            'location' => $location,
            'experience_level' => $experienceLevel,
            'deadline' => $deadline,
            'status' => $status,
            'description' => $description,
            'requirements' => $requirements
        ], $vacId);

        Security::logAudit($vacId ? 'UPDATED_JOB_VACANCY' : 'CREATED_JOB_VACANCY', 'job_vacancies', $savedId);
        flash_redirect($vacId ? 'Job vacancy updated successfully.' : 'New job vacancy published successfully.', 'success', BASE_URL . '/admin/careers?tab=vacancies');
    }

    // 4. Toggle Vacancy Status (e.g. Published <-> Closed)
    if ($postAction === 'toggle_vacancy_status') {
        $vacId = (int)($_POST['vacancy_id'] ?? 0);
        $newStatus = in_array($_POST['new_status'] ?? '', ['published', 'draft', 'closed'], true) ? $_POST['new_status'] : 'closed';
        $db->query("UPDATE job_vacancies SET status = ? WHERE id = ?", [$newStatus, $vacId]);
        Security::logAudit('TOGGLED_JOB_VACANCY_STATUS', 'job_vacancies', $vacId, null, ['status' => $newStatus]);
        flash_redirect('Vacancy status changed to ' . ucfirst($newStatus) . '.', 'success', BASE_URL . '/admin/careers?tab=vacancies');
    }

    // 5. Delete Vacancy
    if ($postAction === 'delete_vacancy') {
        $vacId = (int)($_POST['vacancy_id'] ?? 0);
        Career::deleteVacancy($vacId);
        Security::logAudit('DELETED_JOB_VACANCY', 'job_vacancies', $vacId);
        flash_redirect('Job vacancy removed.', 'success', BASE_URL . '/admin/careers?tab=vacancies');
    }
}

// Active Tab
$currentTab = ($_GET['tab'] ?? 'applications') === 'vacancies' ? 'vacancies' : 'applications';

// Query parameters for applications
$filterStatus = $_GET['status'] ?? '';
$filterVacancy = $_GET['vacancy'] ?? '';
$searchQuery = $_GET['q'] ?? '';

$applications = Career::getApplications($filterVacancy, $filterStatus, $searchQuery);
$vacancies = Career::getVacancies();

// Count stats
$statsTotalApps = (int)$db->getRow("SELECT COUNT(*) AS c FROM career_applications")['c'];
$statsPendingApps = (int)$db->getRow("SELECT COUNT(*) AS c FROM career_applications WHERE status = 'pending'")['c'];
$statsShortlisted = (int)$db->getRow("SELECT COUNT(*) AS c FROM career_applications WHERE status = 'shortlisted'")['c'];
$statsActiveVacancies = (int)$db->getRow("SELECT COUNT(*) AS c FROM job_vacancies WHERE status = 'published'")['c'];

$pageTitle = 'Manage Careers & Applications';
$extraCSS = ['admin.css', 'dashboard.css'];
include __DIR__ . '/../includes/header.php';

dashboard_open('admin', 'Careers & Recruitment');
render_alert($message, $messageType);
?>

<style>
/* Header Action Buttons & Tabs */
.career-nav-tabs {
    display: flex;
    gap: 8px;
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 24px;
}
.career-nav-tab {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    font-weight: 700;
    font-size: 0.95rem;
    color: #64748b;
    text-decoration: none;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
    transition: all 0.2s ease;
}
.career-nav-tab:hover {
    color: var(--navy, #002855);
}
.career-nav-tab.active {
    color: var(--navy, #002855);
    border-bottom-color: var(--navy, #002855);
}
.career-nav-tab .badge {
    font-size: 0.75rem;
    padding: 2px 8px;
    border-radius: 9999px;
}

/* Stats Row */
.career-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.career-stat-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 18px 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    display: flex;
    align-items: center;
    gap: 16px;
}
.career-stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.35rem;
    flex-shrink: 0;
}
.stat-purple { background: #f3e8ff; color: #7e22ce; }
.stat-amber { background: #fef3c7; color: #b45309; }
.stat-emerald { background: #dcfce7; color: #15803d; }
.stat-blue { background: #e0f2fe; color: #0369a1; }
.career-stat-info .num {
    font-size: 1.6rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.1;
}
.career-stat-info .label {
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #64748b;
    margin-top: 2px;
    font-weight: 600;
}

/* Applications Table / Cards */
.application-item {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    margin-bottom: 16px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.03);
    overflow: hidden;
    transition: border-color 0.2s;
}
.application-item:hover {
    border-color: #cbd5e1;
}
.application-item summary {
    padding: 16px 20px;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
    background: #ffffff;
    user-select: none;
}
.application-item summary:hover {
    background: #f8fafc;
}
.app-title-group {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.app-applicant-name {
    font-size: 1.05rem;
    font-weight: 700;
    color: #0f172a;
}
.app-role-badge {
    font-size: 0.82rem;
    background: #f1f5f9;
    color: #334155;
    padding: 3px 10px;
    border-radius: 6px;
    font-weight: 600;
}
.app-ref-tag {
    font-size: 0.78rem;
    color: #64748b;
    font-family: monospace;
}
.app-status-badge {
    font-size: 0.78rem;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 9999px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.status-pending { background: #fef3c7; color: #b45309; }
.status-reviewing { background: #e0f2fe; color: #0284c7; }
.status-shortlisted { background: #dcfce7; color: #15803d; }
.status-interviewed { background: #f3e8ff; color: #7e22ce; }
.status-hired { background: #bbf7d0; color: #14532d; font-weight: 800; }
.status-rejected { background: #fee2e2; color: #b91c1c; }

.app-details-container {
    padding: 20px 24px 24px;
    border-top: 1px solid #f1f5f9;
    background: #ffffff;
}
.app-grid-info {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 20px;
    padding-bottom: 20px;
    border-bottom: 1px solid #f1f5f9;
}
.info-field label {
    display: block;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #64748b;
    font-weight: 700;
    margin-bottom: 3px;
}
.info-field .val {
    font-size: 0.95rem;
    color: #1e293b;
    font-weight: 500;
}
.info-field a {
    color: #0284c7;
    text-decoration: none;
}
.info-field a:hover {
    text-decoration: underline;
}

.cover-letter-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 14px 18px;
    margin-bottom: 20px;
}
.cover-letter-box h5 {
    margin: 0 0 8px;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #475569;
    font-weight: 700;
}
.cover-letter-box p {
    margin: 0;
    font-size: 0.92rem;
    line-height: 1.6;
    color: #334155;
    white-space: pre-wrap;
}

.app-actions-panel {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 18px;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}
.doc-buttons-group {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.btn-doc {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 0.88rem;
    font-weight: 600;
    color: #1e293b;
    text-decoration: none;
    transition: all 0.2s;
}
.btn-doc:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
    color: #002855;
}
.btn-doc.primary {
    background: #002855;
    border-color: #002855;
    color: #ffffff;
}
.btn-doc.primary:hover {
    background: #001a38;
}

/* Modal Form Styles */
.modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0, 16, 40, 0.6);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    padding: 20px;
}
.modal-overlay.open {
    display: flex;
}
.modal-content-card {
    background: #ffffff;
    border-radius: 16px;
    width: 100%;
    max-width: 680px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
}
.modal-header-bar {
    padding: 20px 24px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.modal-header-bar h3 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 700;
    color: #0f172a;
}
.modal-close-btn {
    background: none;
    border: none;
    font-size: 1.4rem;
    color: #64748b;
    cursor: pointer;
}
.modal-body-content {
    padding: 24px;
}
</style>

<!-- Top Statistics Cards -->
<div class="career-stats-grid">
    <div class="career-stat-card">
        <div class="career-stat-icon stat-purple"><i class="fas fa-file-signature"></i></div>
        <div class="career-stat-info">
            <div class="num"><?php echo $statsTotalApps; ?></div>
            <div class="label">Total Applications</div>
        </div>
    </div>
    <div class="career-stat-card">
        <div class="career-stat-icon stat-amber"><i class="fas fa-clock"></i></div>
        <div class="career-stat-info">
            <div class="num"><?php echo $statsPendingApps; ?></div>
            <div class="label">Pending Review</div>
        </div>
    </div>
    <div class="career-stat-card">
        <div class="career-stat-icon stat-emerald"><i class="fas fa-user-check"></i></div>
        <div class="career-stat-info">
            <div class="num"><?php echo $statsShortlisted; ?></div>
            <div class="label">Shortlisted Candidates</div>
        </div>
    </div>
    <div class="career-stat-card">
        <div class="career-stat-icon stat-blue"><i class="fas fa-briefcase"></i></div>
        <div class="career-stat-info">
            <div class="num"><?php echo $statsActiveVacancies; ?></div>
            <div class="label">Active Vacancies</div>
        </div>
    </div>
</div>

<!-- Tabs Navigation -->
<div class="career-nav-tabs">
    <a href="<?php echo BASE_URL; ?>/admin/careers?tab=applications" class="career-nav-tab <?php echo $currentTab === 'applications' ? 'active' : ''; ?>">
        <i class="fas fa-users"></i> Candidate Applications
        <?php if ($statsPendingApps > 0): ?>
            <span class="badge badge-warning"><?php echo $statsPendingApps; ?> new</span>
        <?php endif; ?>
    </a>
    <a href="<?php echo BASE_URL; ?>/admin/careers?tab=vacancies" class="career-nav-tab <?php echo $currentTab === 'vacancies' ? 'active' : ''; ?>">
        <i class="fas fa-bullhorn"></i> Manage Job Vacancies
        <span class="badge badge-info"><?php echo count($vacancies); ?></span>
    </a>
</div>

<?php if ($currentTab === 'applications'): ?>
    <!-- ==================== APPLICATIONS TAB ==================== -->
    <div class="card" style="margin-bottom: 20px;">
        <div class="card-body">
            <form method="GET" action="<?php echo BASE_URL; ?>/admin/careers" class="form-row" style="align-items: flex-end; gap: 12px;">
                <input type="hidden" name="tab" value="applications">
                <div class="form-group" style="flex: 2; margin-bottom: 0;">
                    <label style="font-weight: 600; font-size: 0.85rem;">Search Applicant</label>
                    <input type="text" name="q" class="form-control" placeholder="Name, email, phone, reference..." value="<?php echo e($searchQuery); ?>">
                </div>
                <div class="form-group" style="flex: 1.5; margin-bottom: 0;">
                    <label style="font-weight: 600; font-size: 0.85rem;">Filter by Position</label>
                    <select name="vacancy" class="form-control">
                        <option value="">All Positions</option>
                        <?php foreach ($vacancies as $v): ?>
                            <option value="<?php echo (int)$v['id']; ?>" <?php echo ((string)$filterVacancy === (string)$v['id']) ? 'selected' : ''; ?>>
                                <?php echo e($v['title']); ?>

                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="flex: 1.2; margin-bottom: 0;">
                    <label style="font-weight: 600; font-size: 0.85rem;">Status</label>
                    <select name="status" class="form-control">
                        <option value="">All Statuses</option>
                        <?php foreach (['pending', 'reviewing', 'shortlisted', 'interviewed', 'hired', 'rejected'] as $st): ?>
                            <option value="<?php echo $st; ?>" <?php echo ($filterStatus === $st) ? 'selected' : ''; ?>>
                                <?php echo ucfirst($st); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                    <?php if ($searchQuery || $filterVacancy || $filterStatus): ?>
                        <a href="<?php echo BASE_URL; ?>/admin/careers?tab=applications" class="btn btn-outline">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <?php if (empty($applications)): ?>
        <div class="card">
            <div class="card-body" style="text-align: center; padding: 48px 20px;">
                <i class="fas fa-folder-open" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 12px; display: block;"></i>
                <h4 style="color: #475569; margin: 0 0 6px;">No Applications Found</h4>
                <p style="color: #94a3b8; margin: 0;">There are currently no job applications matching your filter criteria.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="applications-container">
            <?php foreach ($applications as $app): ?>
                <details class="application-item" id="app-<?php echo (int)$app['id']; ?>" <?php echo ($app['status'] === 'pending' || (isset($_GET['open']) && (int)$_GET['open'] === (int)$app['id'])) ? 'open' : ''; ?>>
                    <summary>
                        <div class="app-title-group">
                            <span class="app-applicant-name"><?php echo e($app['full_name']); ?></span>
                            <span class="app-role-badge"><i class="fas fa-briefcase"></i> <?php echo e($app['position_applied']); ?></span>
                            <span class="app-ref-tag"><?php echo e($app['reference_no']); ?></span>
                            <small style="color: #64748b;"><i class="fas fa-calendar-alt"></i> <?php echo date('d M Y, h:i A', strtotime($app['created_at'])); ?></small>
                        </div>
                        <div>
                            <span class="app-status-badge status-<?php echo e($app['status']); ?>">
                                <?php echo ucfirst($app['status']); ?>
                            </span>
                        </div>
                    </summary>

                    <div class="app-details-container">
                        <!-- Key info row -->
                        <div class="app-grid-info">
                            <div class="info-field">
                                <label>Email Address</label>
                                <div class="val">
                                    <a href="mailto:<?php echo e($app['email']); ?>?subject=Application for <?php echo urlencode($app['position_applied']); ?> - St. Benedict's Academy">
                                        <i class="fas fa-envelope"></i> <?php echo e($app['email']); ?>
                                    </a>
                                </div>
                            </div>
                            <div class="info-field">
                                <label>Phone Number</label>
                                <div class="val">
                                    <a href="tel:<?php echo e($app['phone']); ?>"><i class="fas fa-phone"></i> <?php echo e($app['phone']); ?></a>
                                    <?php
                                    $cleanPhone = preg_replace('/\D/', '', $app['phone']);
                                    if ($cleanPhone):
                                    ?>
                                        &middot; <a href="https://wa.me/<?php echo e($cleanPhone); ?>" target="_blank" rel="noopener" style="color: #16a34a;"><i class="fab fa-whatsapp"></i> Chat</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="info-field">
                                <label>Highest Qualification</label>
                                <div class="val"><?php echo e($app['qualification'] ?: 'Not Specified'); ?></div>
                            </div>
                            <div class="info-field">
                                <label>Experience Level</label>
                                <div class="val"><?php echo e($app['experience_years'] ?: 'Not Specified'); ?></div>
                            </div>
                        </div>

                        <!-- Cover letter -->
                        <?php if (!empty($app['cover_letter'])): ?>
                            <div class="cover-letter-box">
                                <h5><i class="fas fa-comment-alt"></i> Cover Letter / Personal Statement:</h5>
                                <p><?php echo e($app['cover_letter']); ?></p>
                            </div>
                        <?php endif; ?>

                        <!-- Documents & Action Panel -->
                        <div class="app-actions-panel">
                            <div class="doc-buttons-group">
                                <a href="<?php echo BASE_URL; ?>/admin/careers?action=download&type=resume&id=<?php echo (int)$app['id']; ?>" target="_blank" class="btn-doc primary">
                                    <i class="fas fa-file-pdf"></i> Download CV / Resume
                                </a>
                                <?php if (!empty($app['additional_doc_path'])): ?>
                                    <a href="<?php echo BASE_URL; ?>/admin/careers?action=download&type=doc&id=<?php echo (int)$app['id']; ?>" target="_blank" class="btn-doc">
                                        <i class="fas fa-paperclip"></i> View Additional Document
                                    </a>
                                <?php endif; ?>
                            </div>

                            <!-- Status & Notes Form -->
                            <form method="POST" action="<?php echo BASE_URL; ?>/admin/careers?tab=applications" style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="form_action" value="update_application">
                                <input type="hidden" name="application_id" value="<?php echo (int)$app['id']; ?>">

                                <div>
                                    <select name="status" class="form-control" style="font-weight: 700; border-radius: 6px; padding: 6px 12px; height: auto;">
                                        <option value="pending" <?php echo $app['status'] === 'pending' ? 'selected' : ''; ?>>Pending Review</option>
                                        <option value="reviewing" <?php echo $app['status'] === 'reviewing' ? 'selected' : ''; ?>>Under Review</option>
                                        <option value="shortlisted" <?php echo $app['status'] === 'shortlisted' ? 'selected' : ''; ?>>Shortlisted</option>
                                        <option value="interviewed" <?php echo $app['status'] === 'interviewed' ? 'selected' : ''; ?>>Interviewed</option>
                                        <option value="hired" <?php echo $app['status'] === 'hired' ? 'selected' : ''; ?>>Hired</option>
                                        <option value="rejected" <?php echo $app['status'] === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                    </select>
                                </div>
                                <div style="min-width: 240px; flex: 1;">
                                    <input type="text" name="admin_notes" class="form-control" placeholder="Internal admin remarks/notes..." value="<?php echo e($app['admin_notes']); ?>" style="padding: 6px 10px; height: auto; border-radius: 6px;">
                                </div>
                                <button type="submit" class="btn btn-sm btn-primary">
                                    <i class="fas fa-save"></i> Save Status
                                </button>
                            </form>

                            <!-- Delete button form -->
                            <form method="POST" action="<?php echo BASE_URL; ?>/admin/careers?tab=applications" onsubmit="return confirm('Are you sure you want to permanently delete this application?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="form_action" value="delete_application">
                                <input type="hidden" name="application_id" value="<?php echo (int)$app['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline text-danger" title="Delete application" style="border-color: #fee2e2;">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </form>
                        </div>

                        <?php if (!empty($app['reviewed_at'])): ?>
                            <div style="font-size: 0.78rem; color: #94a3b8; margin-top: 10px;">
                                <i class="fas fa-history"></i> Last updated <?php echo date('d M Y, h:i A', strtotime($app['reviewed_at'])); ?>
                                <?php if (!empty($app['reviewer_first_name'])): ?>
                                    by <?php echo e($app['reviewer_first_name'] . ' ' . $app['reviewer_last_name']); ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php else: ?>
    <!-- ==================== VACANCIES TAB ==================== -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h3 style="margin: 0; font-size: 1.3rem; font-weight: 800; color: #002855;">Published Vacancies</h3>
        <button type="button" class="btn btn-primary" id="openNewVacancyModalBtn">
            <i class="fas fa-plus-circle"></i> Post New Vacancy
        </button>
    </div>

    <?php if (empty($vacancies)): ?>
        <div class="card">
            <div class="card-body" style="text-align: center; padding: 48px 20px;">
                <i class="fas fa-briefcase" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 12px; display: block;"></i>
                <h4 style="color: #475569; margin: 0 0 6px;">No Job Vacancies Found</h4>
                <p style="color: #94a3b8; margin: 0 0 20px;">You haven't posted any job vacancies yet.</p>
                <button type="button" class="btn btn-primary" onclick="document.getElementById('openNewVacancyModalBtn').click();">
                    <i class="fas fa-plus"></i> Post Your First Vacancy
                </button>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-body" style="padding: 0;">
                <table class="table" style="margin: 0;">
                    <thead>
                        <tr>
                            <th>Job Title</th>
                            <th>Department</th>
                            <th>Type</th>
                            <th>Deadline</th>
                            <th>Applications</th>
                            <th>Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($vacancies as $vac): ?>
                            <tr>
                                <td>
                                    <strong><?php echo e($vac['title']); ?></strong>
                                    <div style="font-size: 0.8rem; color: #64748b;"><i class="fas fa-map-marker-alt"></i> <?php echo e($vac['location']); ?></div>
                                </td>
                                <td><?php echo e($vac['department']); ?></td>
                                <td><span class="badge badge-info"><?php echo e($vac['job_type']); ?></span></td>
                                <td>
                                    <?php echo !empty($vac['deadline']) ? date('d M Y', strtotime($vac['deadline'])) : '<span class="text-muted">Open</span>'; ?>
                                </td>
                                <td>
                                    <a href="<?php echo BASE_URL; ?>/admin/careers?tab=applications&vacancy=<?php echo (int)$vac['id']; ?>" class="badge badge-secondary" style="font-size: 0.82rem; padding: 4px 8px;">
                                        <?php echo (int)$vac['application_count']; ?> applicants
                                    </a>
                                </td>
                                <td>
                                    <?php if ($vac['status'] === 'published'): ?>
                                        <span class="badge badge-success"><i class="fas fa-check-circle"></i> Published</span>
                                    <?php elseif ($vac['status'] === 'draft'): ?>
                                        <span class="badge badge-warning"><i class="fas fa-pencil-alt"></i> Draft</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger"><i class="fas fa-lock"></i> Closed</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <button type="button" class="btn btn-sm btn-outline edit-vacancy-btn"
                                            data-id="<?php echo (int)$vac['id']; ?>"
                                            data-title="<?php echo e($vac['title']); ?>"
                                            data-department="<?php echo e($vac['department']); ?>"
                                            data-job-type="<?php echo e($vac['job_type']); ?>"
                                            data-location="<?php echo e($vac['location']); ?>"
                                            data-experience="<?php echo e($vac['experience_level']); ?>"
                                            data-deadline="<?php echo e($vac['deadline']); ?>"
                                            data-status="<?php echo e($vac['status']); ?>"
                                            data-description="<?php echo e($vac['description']); ?>"
                                            data-requirements="<?php echo e($vac['requirements']); ?>">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>

                                    <!-- Quick toggle status -->
                                    <form method="POST" action="<?php echo BASE_URL; ?>/admin/careers?tab=vacancies" style="display: inline-block;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="form_action" value="toggle_vacancy_status">
                                        <input type="hidden" name="vacancy_id" value="<?php echo (int)$vac['id']; ?>">
                                        <?php if ($vac['status'] === 'published'): ?>
                                            <input type="hidden" name="new_status" value="closed">
                                            <button type="submit" class="btn btn-sm btn-outline" title="Close vacancy" style="color: #b45309;">
                                                <i class="fas fa-lock"></i> Close
                                            </button>
                                        <?php else: ?>
                                            <input type="hidden" name="new_status" value="published">
                                            <button type="submit" class="btn btn-sm btn-outline" title="Publish vacancy" style="color: #15803d;">
                                                <i class="fas fa-check"></i> Publish
                                            </button>
                                        <?php endif; ?>
                                    </form>

                                    <!-- Delete vacancy -->
                                    <form method="POST" action="<?php echo BASE_URL; ?>/admin/careers?tab=vacancies" style="display: inline-block;" onsubmit="return confirm('Are you sure you want to delete this vacancy?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="form_action" value="delete_vacancy">
                                        <input type="hidden" name="vacancy_id" value="<?php echo (int)$vac['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline text-danger" title="Delete vacancy">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Modal for Adding / Editing Vacancy -->
    <div class="modal-overlay" id="vacancyModal">
        <div class="modal-content-card">
            <div class="modal-header-bar">
                <h3 id="vacancyModalTitle"><i class="fas fa-briefcase"></i> Post New Vacancy</h3>
                <button type="button" class="modal-close-btn" id="closeVacancyModalBtn">&times;</button>
            </div>
            <div class="modal-body-content">
                <form method="POST" action="<?php echo BASE_URL; ?>/admin/careers?tab=vacancies" id="vacancyForm">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" value="save_vacancy">
                    <input type="hidden" name="vacancy_id" id="modalVacancyId" value="">

                    <div class="form-group">
                        <label>Job Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" id="modalTitle" class="form-control" required placeholder="e.g. Early Years Teacher">
                    </div>

                    <div class="form-row">
                        <div class="form-group" style="flex: 1;">
                            <label>Department <span class="text-danger">*</span></label>
                            <input type="text" name="department" id="modalDepartment" class="form-control" required placeholder="e.g. Early Years / Academic">
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label>Job Type <span class="text-danger">*</span></label>
                            <select name="job_type" id="modalJobType" class="form-control">
                                <option value="Full-time">Full-time</option>
                                <option value="Part-time">Part-time</option>
                                <option value="Contract">Contract</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group" style="flex: 1;">
                            <label>Location</label>
                            <input type="text" name="location" id="modalLocation" class="form-control" value="Main Campus, Abuja">
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label>Experience Level</label>
                            <input type="text" name="experience_level" id="modalExperience" class="form-control" placeholder="e.g. 2+ Years">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group" style="flex: 1;">
                            <label>Application Deadline</label>
                            <input type="date" name="deadline" id="modalDeadline" class="form-control">
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label>Publication Status</label>
                            <select name="status" id="modalStatus" class="form-control">
                                <option value="published">Published (Visible on Careers Page)</option>
                                <option value="draft">Draft (Hidden)</option>
                                <option value="closed">Closed</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Job Overview / Description <span class="text-danger">*</span></label>
                        <textarea name="description" id="modalDescription" rows="4" class="form-control" required placeholder="Describe the role responsibilities and mission..."></textarea>
                    </div>

                    <div class="form-group">
                        <label>Key Requirements &amp; Qualifications</label>
                        <textarea name="requirements" id="modalRequirements" rows="4" class="form-control" placeholder="List qualifications, each on a new line (e.g. - B.Ed in Early Childhood...)"></textarea>
                        <small class="text-muted">Enter requirements as bullet points or separate lines.</small>
                    </div>

                    <div style="text-align: right; margin-top: 20px;">
                        <button type="button" class="btn btn-outline" id="cancelVacancyModalBtn">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="saveVacancyBtn">
                            <i class="fas fa-check"></i> Save Vacancy
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script nonce="<?php echo CSP_NONCE; ?>">
    document.addEventListener('DOMContentLoaded', function() {
        var modal = document.getElementById('vacancyModal');
        var openBtn = document.getElementById('openNewVacancyModalBtn');
        var closeBtn = document.getElementById('closeVacancyModalBtn');
        var cancelBtn = document.getElementById('cancelVacancyModalBtn');

        function openModal() {
            if (modal) modal.classList.add('open');
        }
        function closeModal() {
            if (modal) modal.classList.remove('open');
        }

        if (openBtn) {
            openBtn.addEventListener('click', function() {
                document.getElementById('vacancyModalTitle').innerHTML = '<i class="fas fa-briefcase"></i> Post New Vacancy';
                document.getElementById('modalVacancyId').value = '';
                document.getElementById('modalTitle').value = '';
                document.getElementById('modalDepartment').value = 'Academic';
                document.getElementById('modalJobType').value = 'Full-time';
                document.getElementById('modalLocation').value = 'Main Campus, Abuja';
                document.getElementById('modalExperience').value = '';
                document.getElementById('modalDeadline').value = '';
                document.getElementById('modalStatus').value = 'published';
                document.getElementById('modalDescription').value = '';
                document.getElementById('modalRequirements').value = '';
                openModal();
            });
        }

        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

        // Edit vacancy buttons
        document.querySelectorAll('.edit-vacancy-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.getElementById('vacancyModalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Job Vacancy';
                document.getElementById('modalVacancyId').value = this.getAttribute('data-id');
                document.getElementById('modalTitle').value = this.getAttribute('data-title');
                document.getElementById('modalDepartment').value = this.getAttribute('data-department');
                document.getElementById('modalJobType').value = this.getAttribute('data-job-type');
                document.getElementById('modalLocation').value = this.getAttribute('data-location');
                document.getElementById('modalExperience').value = this.getAttribute('data-experience');
                document.getElementById('modalDeadline').value = this.getAttribute('data-deadline');
                document.getElementById('modalStatus').value = this.getAttribute('data-status');
                document.getElementById('modalDescription').value = this.getAttribute('data-description');
                document.getElementById('modalRequirements').value = this.getAttribute('data-requirements');
                openModal();
            });
        });
    });
    </script>
<?php endif; ?>

<?php
dashboard_close();
include __DIR__ . '/../includes/footer.php';
?>
