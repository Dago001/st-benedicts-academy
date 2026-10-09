<?php
// teacher/students.php - View Students (Teacher View)
require_once '../config/config.php';
require_once '../config/security.php';

Security::requireRole('teacher');

$pageTitle = 'My Students';
$extraJS = ['students.js'];

include __DIR__ . '/../includes/header.php';

$db = db();
$userId = (int)$_SESSION['user_id'];
$teacherId = Security::currentTeacherId() ?? 0;

// Classes the teacher leads or teaches in
$classes = $db->getRows(
    "SELECT c.* FROM classes c WHERE c.is_active = 1
       AND c.id IN (SELECT id FROM classes WHERE teacher_id = ? UNION SELECT class_id FROM subjects WHERE teacher_id = ?)
     ORDER BY c.class_name, c.section", [$teacherId, $teacherId]);

$selectedClass = (int)($_GET['class_id'] ?? $_GET['class'] ?? ($classes[0]['id'] ?? 0));
if ($selectedClass && !Security::canAccessClass($selectedClass)) {
    $selectedClass = (int)($classes[0]['id'] ?? 0);
}

// Get students for selected class
$students = [];
if ($selectedClass) {
    $students = $db->getRows(
        "SELECT s.*, u.first_name, u.last_name, u.email, u.phone, u.profile_image,
                s.admission_number, s.date_of_birth, s.gender, s.address,
                s.blood_group, s.medical_notes,
                CONCAT(pu.first_name, ' ', pu.last_name) as parent_name,
                pu.phone as parent_phone
         FROM students s
         JOIN users u ON s.user_id = u.id
         LEFT JOIN parents p ON s.parent_id = p.id
         LEFT JOIN users pu ON p.user_id = pu.id
         WHERE s.class_id = ? AND u.is_active = 1 AND u.deleted_at IS NULL
         ORDER BY u.first_name, u.last_name",
        [$selectedClass]
    );
}

// Get class info
$classInfo = null;
if ($selectedClass) {
    $classInfo = $db->getRow(
        "SELECT * FROM classes WHERE id = ?",
        [$selectedClass]
    );
}
?>

<div class="dashboard-container">
    <?php render_sidebar('teacher'); ?>

    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>My Students</h1>
        </div>

        <!-- Class Selection -->
        <?php if (count($classes) > 0): ?>
        <div class="card">
            <div class="card-body">
                <form method="GET" class="form-inline">
                    <div class="form-group">
                        <label for="class">Select Class:</label>
                        <select name="class" id="class" class="form-control" onchange="this.form.submit()">
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo e($class['id']); ?>"
                                <?php echo ($selectedClass == $class['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name'] . ' ' . $class['section']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
        </div>

        <!-- Class Overview -->
        <?php if ($classInfo): ?>
        <div class="class-overview">
            <div class="overview-stats">
                <div class="stat-box">
                    <span class="stat-label">Total Students</span>
                    <span class="stat-value"><?php echo count($students); ?></span>
                </div>
                <div class="stat-box">
                    <span class="stat-label">Boys</span>
                    <span class="stat-value"><?php echo count(array_filter($students, fn($s) => $s['gender'] === 'male')); ?></span>
                </div>
                <div class="stat-box">
                    <span class="stat-label">Girls</span>
                    <span class="stat-value"><?php echo count(array_filter($students, fn($s) => $s['gender'] === 'female')); ?></span>
                </div>
                <div class="stat-box">
                    <span class="stat-label">Class Capacity</span>
                    <span class="stat-value"><?php echo e($classInfo['capacity']); ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Students List -->
        <div class="card">
            <div class="card-header">
                <h3>
                    <i class="fas fa-users"></i>
                    Student List - <?php echo $classInfo ? htmlspecialchars($classInfo['class_name'] . ' ' . $classInfo['section']) : ''; ?>
                </h3>
                <div class="card-tools">
                    <input type="text" id="searchStudent" class="form-control" placeholder="Search students..." style="width: 250px;">
                    <button class="btn btn-outline" onclick="exportStudentList()">
                        <i class="fas fa-download"></i> Export
                    </button>
                </div>
            </div>
            <div class="card-body">
                <?php if (!empty($students)): ?>
                <div class="table-responsive">
                    <table class="data-table" id="studentsTable">
                        <thead>
                            <tr>
                                <th>Photo</th>
                                <th>Admission No.</th>
                                <th>Name</th>
                                <th>Date of Birth</th>
                                <th>Age</th>
                                <th>Gender</th>
                                <th>Parent/Guardian</th>
                                <th>Parent Phone</th>
                                <th>Blood Group</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student):
                                $dob = new DateTime($student['date_of_birth']);
                                $now = new DateTime();
                                $age = $now->diff($dob)->y;
                            ?>
                            <tr>
                                <td>
                                    <?php if ($student['profile_image']): ?>
                                    <img src="<?php echo BASE_URL; ?>/uploads/students/<?php echo e($student['profile_image']); ?>"
                                         alt="Profile" class="student-thumbnail">
                                    <?php else: ?>
                                    <div class="thumbnail-placeholder">
                                        <i class="fas fa-user-graduate"></i>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?php echo htmlspecialchars($student['admission_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                                <td><?php echo date('d M Y', strtotime($student['date_of_birth'])); ?></td>
                                <td><?php echo e($age); ?> years</td>
                                <td><?php echo e(ucfirst($student['gender'])); ?></td>
                                <td><?php echo htmlspecialchars($student['parent_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($student['parent_phone'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php if ($student['blood_group']): ?>
                                    <span class="blood-badge"><?php echo e($student['blood_group']); ?></span>
                                    <?php else: ?>
                                    --
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn-icon" onclick="viewStudent(<?php echo e($student['id']); ?>)" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn-icon" onclick="markAttendance(<?php echo e($student['id']); ?>)" title="Mark Attendance">
                                            <i class="fas fa-calendar-check"></i>
                                        </button>
                                        <button class="btn-icon" onclick="contactParent(<?php echo e($student['id']); ?>)" title="Contact Parent">
                                            <i class="fas fa-envelope"></i>
                                        </button>
                                        <?php if ($student['medical_notes']): ?>
                                        <button class="btn-icon medical" data-notes="<?php echo e($student['medical_notes']); ?>" onclick="showMedicalNotes(this.dataset.notes)" title="Medical Notes">
                                            <i class="fas fa-notes-medical"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-users" style="font-size: 3rem; color: var(--light-gray); margin-bottom: 15px;"></i>
                    <p>No students found in this class.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php else: ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            You have no classes assigned. Please contact the administrator.
        </div>
        <?php endif; ?>
    </main>
</div>

<!-- Student Details Modal -->
<div id="studentModal" class="modal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3>Student Details</h3>
            <button type="button" class="close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="studentDetails">
            <!-- Loaded via AJAX -->
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal()">Close</button>
        </div>
    </div>
</div>

<!-- Medical Notes Modal -->
<div id="medicalModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Medical Notes</h3>
            <button type="button" class="close" onclick="closeMedicalModal()">&times;</button>
        </div>
        <div class="modal-body" id="medicalNotes">
            <!-- Content will be inserted here -->
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeMedicalModal()">Close</button>
        </div>
    </div>
</div>

<style>
.student-thumbnail {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
}

.thumbnail-placeholder {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--light-gray);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--gray);
}

.blood-badge {
    display: inline-block;
    padding: 3px 8px;
    background: var(--navy);
    color: var(--white);
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 600;
}

.class-overview {
    margin-bottom: 25px;
}

.overview-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
}

.stat-box {
    background: var(--white);
    border-radius: var(--radius-lg);
    padding: 20px;
    text-align: center;
    box-shadow: var(--shadow-sm);
    border-bottom: 3px solid transparent;
    transition: all 0.3s ease;
}

.stat-box:hover {
    transform: translateY(-5px);
    border-bottom-color: var(--gold);
    box-shadow: var(--shadow-md);
}

.stat-box .stat-label {
    display: block;
    color: var(--gray);
    font-size: 0.9rem;
    margin-bottom: 5px;
}

.stat-box .stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--navy);
    line-height: 1.2;
}

.action-buttons {
    display: flex;
    gap: 5px;
}

.btn-icon.medical {
    background: rgba(23, 162, 184, 0.1);
    color: var(--info);
}

.btn-icon.medical:hover {
    background: var(--info);
    color: var(--white);
}

.no-data {
    text-align: center;
    padding: 50px;
    color: var(--gray);
}

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    z-index: 1050;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    overflow: auto;
}

.modal-content {
    background: var(--white);
    margin: 50px auto;
    border-radius: var(--radius-lg);
    width: 90%;
    max-width: 500px;
    box-shadow: var(--shadow-xl);
    animation: slideInDown 0.3s ease;
}

.modal-content.modal-lg {
    max-width: 900px;
}

.modal-header {
    padding: 20px;
    border-bottom: 1px solid var(--light-gray);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
    color: var(--navy);
}

.modal-header .close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--gray);
}

.modal-body {
    padding: 20px;
    max-height: 70vh;
    overflow-y: auto;
}

.modal-footer {
    padding: 20px;
    border-top: 1px solid var(--light-gray);
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

@keyframes slideInDown {
    from {
        transform: translateY(-50px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

/* Responsive */
@media (max-width: 992px) {
    .overview-stats {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .overview-stats {
        grid-template-columns: 1fr;
    }

    .card-tools {
        flex-direction: column;
        gap: 10px;
    }

    #searchStudent {
        width: 100% !important;
    }
}
</style>

<script nonce="<?php echo CSP_NONCE; ?>">
// Search functionality
document.getElementById('searchStudent')?.addEventListener('keyup', function() {
    const searchTerm = this.value.toLowerCase();
    const table = document.getElementById('studentsTable');
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

    for (let row of rows) {
        const name = row.cells[2].textContent.toLowerCase();
        const admission = row.cells[1].textContent.toLowerCase();

        if (name.includes(searchTerm) || admission.includes(searchTerm)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    }
});

// View student details
function viewStudent(studentId) {
    fetch(`${BASE_URL}/api/get-student-details?id=${encodeURIComponent(studentId)}`, {credentials: 'same-origin'})
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayStudentDetails(data.student);
            } else {
                alert('Error loading student details');
            }
        });
}

function displayStudentDetails(student) {
    const modal = document.getElementById('studentModal');
    const details = document.getElementById('studentDetails');

    details.innerHTML = `
        <div class="student-detail-grid">
            <div class="detail-section">
                <h4><i class="fas fa-user"></i> Personal Information</h4>
                <div class="detail-row">
                    <span class="detail-label">Full Name:</span>
                    <span class="detail-value">${escapeHtml(student.first_name)} ${escapeHtml(student.last_name)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Admission No:</span>
                    <span class="detail-value">${escapeHtml(student.admission_number)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Date of Birth:</span>
                    <span class="detail-value">${new Date(student.date_of_birth).toLocaleDateString()}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Gender:</span>
                    <span class="detail-value">${escapeHtml(student.gender)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Blood Group:</span>
                    <span class="detail-value">${escapeHtml(student.blood_group || 'Not specified')}</span>
                </div>
            </div>

            <div class="detail-section">
                <h4><i class="fas fa-address-card"></i> Contact Information</h4>
                <div class="detail-row">
                    <span class="detail-label">Email:</span>
                    <span class="detail-value">${escapeHtml(student.email)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Phone:</span>
                    <span class="detail-value">${escapeHtml(student.phone || 'Not provided')}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Address:</span>
                    <span class="detail-value">${escapeHtml(student.address || 'Not provided')}</span>
                </div>
            </div>

            <div class="detail-section">
                <h4><i class="fas fa-users"></i> Parent/Guardian Information</h4>
                <div class="detail-row">
                    <span class="detail-label">Name:</span>
                    <span class="detail-value">${escapeHtml(student.parent_name || 'Not assigned')}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Parent Phone:</span>
                    <span class="detail-value">${escapeHtml(student.parent_phone || 'Not provided')}</span>
                </div>
            </div>

            <div class="detail-section">
                <h4><i class="fas fa-notes-medical"></i> Medical Notes</h4>
                <p>${escapeHtml(student.medical_notes || 'No medical notes')}</p>
            </div>
        </div>
    `;

    modal.style.display = 'block';
}

// Mark attendance
function markAttendance(studentId) {
    window.location.href = `attendance?class_id=${<?php echo (int)$selectedClass; ?>}`;
}

// Contact parent
function contactParent(studentId) {
    window.location.href = `messages`;
}

// Show medical notes
function showMedicalNotes(notes) {
    const modal = document.getElementById('medicalModal');
    const notesDiv = document.getElementById('medicalNotes');

    notesDiv.innerHTML = `<p>${escapeHtml(notes)}</p>`;
    modal.style.display = 'block';
}

// Export student list
function exportStudentList() {
    const classId = document.getElementById('class').value;
    window.location.href = `export?type=students&class=${classId}`;
}

// Close modals
function closeModal() {
    document.getElementById('studentModal').style.display = 'none';
}

function closeMedicalModal() {
    document.getElementById('medicalModal').style.display = 'none';
}

// Close modals when clicking outside
window.onclick = function(event) {
    const studentModal = document.getElementById('studentModal');
    const medicalModal = document.getElementById('medicalModal');

    if (event.target === studentModal) {
        studentModal.style.display = 'none';
    }
    if (event.target === medicalModal) {
        medicalModal.style.display = 'none';
    }
}

// Initialize DataTable if available
$(document).ready(function() {
    if ($.fn.DataTable) {
        $('#studentsTable').DataTable({
            paging: false,
            searching: false,
            ordering: true,
            info: false
        });
    }
});
</script>

<?php
include '../includes/footer.php';
?>