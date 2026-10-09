<?php
// parent/child-performance.php - View Child's Academic Performance
require_once '../config/config.php';
require_once '../config/security.php';

Security::requireRole('parent');

$pageTitle = 'Child Performance';
$extraCSS = ['dashboard.css'];
$extraJS = ['charts.js', 'performance.js'];

include '../includes/header.php';

$db = db();
$userId = $_SESSION['user_id'];

// Get parent info
$parent = $db->getRow(
    "SELECT p.*, u.first_name, u.last_name
     FROM parents p
     JOIN users u ON p.user_id = u.id
     WHERE p.user_id = ?",
    [$userId]
);

if (!$parent) {
    echo '<div class="container" style="padding:24px"><div class="alert alert-error">Your parent profile is incomplete. Please contact the school office.</div></div>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

// Get children of this parent
$children = $db->getRows(
    "SELECT s.*, u.first_name, u.last_name, u.email, u.profile_image,
            c.class_name, c.section
     FROM students s
     JOIN users u ON s.user_id = u.id
     LEFT JOIN classes c ON s.class_id = c.id
     WHERE s.parent_id = ? AND u.is_active = 1",
    [$parent['id']]
);

// Get selected child
// A parent may only look at their own children: unknown ids fall back to the first child
$selectedChildId = $children[0]['id'] ?? null;
if (isset($_GET['child']) && in_array((int)$_GET['child'], array_map('intval', array_column($children, 'id')), true)) {
    $selectedChildId = (int)$_GET['child'];
}
$selectedTerm = $_GET['term'] ?? 'Term 1';
$selectedYear = $_GET['year'] ?? (currentAcademicYear());

// Get available terms and years
$terms = [];
if ($selectedChildId) {
    $terms = $db->getRows(
        "SELECT DISTINCT term, academic_year
         FROM results
         WHERE student_id = ? AND is_approved = 1
         ORDER BY academic_year DESC, term DESC",
        [$selectedChildId]
    );
}

// Get child's performance data
$results = [];
$attendance = [];
$performanceSummary = [];

if ($selectedChildId && $selectedTerm && $selectedYear) {
    // Get academic results
    $results = $db->getRows(
        "SELECT r.*, s.subject_name
         FROM results r
         JOIN subjects s ON r.subject_id = s.id
         WHERE r.student_id = ? AND r.term = ? AND r.academic_year = ? AND r.is_approved = 1
         ORDER BY s.subject_name",
        [$selectedChildId, $selectedTerm, $selectedYear]
    );

    // Get attendance for the term
    $attendance = $db->getRow(
        "SELECT
            COUNT(*) as total_days,
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
            SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late
         FROM attendance
         WHERE student_id = ? AND date BETWEEN ? AND ?",
        [$selectedChildId, $selectedYear . '-01-01', $selectedYear . '-12-31']
    );

    // Calculate performance summary
    if (!empty($results)) {
        $totalScore = 0;
        $totalMaxScore = 0;

        foreach ($results as $r) {
            $totalScore += $r['score'];
            $totalMaxScore += $r['max_score'];
        }

        $average = $totalMaxScore > 0 ? ($totalScore / $totalMaxScore) * 100 : 0;

        // Determine grade
        if ($average >= 70) $grade = 'A';
        elseif ($average >= 60) $grade = 'B';
        elseif ($average >= 50) $grade = 'C';
        elseif ($average >= 45) $grade = 'D';
        elseif ($average >= 40) $grade = 'E';
        else $grade = 'F';

        $performanceSummary = [
            'total_subjects' => count($results),
            'average' => round($average, 2),
            'grade' => $grade,
            'highest' => max(array_column($results, 'score')),
            'lowest' => min(array_column($results, 'score'))
        ];
    }
}

// Get selected child details
$selectedChild = null;
if ($selectedChildId) {
    foreach ($children as $child) {
        if ($child['id'] == $selectedChildId) {
            $selectedChild = $child;
            break;
        }
    }
}
?>

<div class="dashboard-container">
    <?php render_sidebar('parent'); ?>

    <main class="dashboard-main">
        <div class="dashboard-header">
            <h1>Child's Academic Performance</h1>
        </div>

        <?php if (empty($children)): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            No children are linked to your account. Please contact the school administration.
        </div>
        <?php else: ?>

        <!-- Child Selector -->
        <div class="card">
            <div class="card-body">
                <form method="GET" class="form-row">
                    <div class="form-group col-md-4">
                        <label for="child">Select Child</label>
                        <select id="child" name="child" class="form-control" onchange="this.form.submit()">
                            <?php foreach ($children as $child): ?>
                            <option value="<?php echo e($child['id']); ?>" <?php echo $selectedChildId == $child['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($child['first_name'] . ' ' . $child['last_name']); ?>
                                - <?php echo htmlspecialchars($child['class_name'] . ' ' . $child['section']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if (!empty($terms)): ?>
                    <div class="form-group col-md-3">
                        <label for="year">Academic Year</label>
                        <select id="year" name="year" class="form-control" onchange="this.form.submit()">
                            <?php
                            $uniqueYears = array_unique(array_column($terms, 'academic_year'));
                            foreach ($uniqueYears as $year):
                            ?>
                            <option value="<?php echo e($year); ?>" <?php echo $selectedYear == $year ? 'selected' : ''; ?>>
                                <?php echo e($year); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-md-3">
                        <label for="term">Term</label>
                        <select id="term" name="term" class="form-control" onchange="this.form.submit()">
                            <?php foreach ($terms as $t): ?>
                            <?php if ($t['academic_year'] == $selectedYear): ?>
                            <option value="<?php echo e($t['term']); ?>" <?php echo $selectedTerm == $t['term'] ? 'selected' : ''; ?>>
                                <?php echo e($t['term']); ?>
                            </option>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <?php if ($selectedChild): ?>
        <!-- Child Header -->
        <div class="child-header">
            <div class="child-info">
                <div class="child-avatar">
                    <?php if ($selectedChild['profile_image']): ?>
                    <img src="<?php echo BASE_URL; ?>/uploads/students/<?php echo e($selectedChild['profile_image']); ?>"
                         alt="<?php echo htmlspecialchars($selectedChild['first_name']); ?>">
                    <?php else: ?>
                    <div class="avatar-placeholder">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="child-details">
                    <h2><?php echo htmlspecialchars($selectedChild['first_name'] . ' ' . $selectedChild['last_name']); ?></h2>
                    <p class="class-info">
                        <i class="fas fa-school"></i> <?php echo htmlspecialchars($selectedChild['class_name'] . ' ' . $selectedChild['section']); ?>
                        <span class="separator">|</span>
                        <i class="fas fa-id-card"></i> Adm No: <?php echo e($selectedChild['admission_number']); ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Performance Summary Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(0,40,85,0.1);">
                    <i class="fas fa-book" style="color: #002855;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo $performanceSummary['total_subjects'] ?? 0; ?></h3>
                    <p>Subjects</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(40,167,69,0.1);">
                    <i class="fas fa-chart-line" style="color: #28a745;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo isset($performanceSummary['average']) ? $performanceSummary['average'] . '%' : 'N/A'; ?></h3>
                    <p>Average</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(255,193,7,0.1);">
                    <i class="fas fa-star" style="color: #ffc107;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo $performanceSummary['grade'] ?? 'N/A'; ?></h3>
                    <p>Overall Grade</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(23,162,184,0.1);">
                    <i class="fas fa-calendar-check" style="color: #17a2b8;"></i>
                </div>
                <div class="stat-content">
                    <h3><?php echo isset($attendance['present']) ? round(($attendance['present'] / max($attendance['total_days'], 1)) * 100, 1) . '%' : 'N/A'; ?></h3>
                    <p>Attendance</p>
                </div>
            </div>
        </div>

        <!-- Results Table -->
        <div class="card">
            <div class="card-header">
                <h3>Academic Results - <?php echo $selectedTerm . ' ' . $selectedYear; ?></h3>
                <div class="card-tools">
                    <a href="download-report?child=<?php echo e($selectedChildId); ?>&term=<?php echo e($selectedTerm); ?>&year=<?php echo e($selectedYear); ?>"
                       class="btn btn-sm btn-primary">
                        <i class="fas fa-download"></i> Download Report
                    </a>
                </div>
            </div>
            <div class="card-body">
                <?php if (!empty($results)): ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Assessment Type</th>
                                <th>Score</th>
                                <th>Max Score</th>
                                <th>Percentage</th>
                                <th>Grade</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $result):
                                $percentage = ($result['score'] / $result['max_score']) * 100;
                                $progressClass = $percentage >= 70 ? 'progress-high' : ($percentage >= 50 ? 'progress-medium' : 'progress-low');
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($result['subject_name']); ?></strong></td>
                                <td><?php echo e(ucfirst($result['assessment_type'])); ?></td>
                                <td class="text-center"><?php echo e($result['score']); ?></td>
                                <td class="text-center"><?php echo e($result['max_score']); ?></td>
                                <td>
                                    <div class="progress">
                                        <div class="progress-bar <?php echo e($progressClass); ?>"
                                             style="width: <?php echo e($percentage); ?>%">
                                            <?php echo round($percentage, 1); ?>%
                                        </div>
                                    </div>
                                </td>
                                <td class="text-center"><span class="badge badge-<?php echo e(strtolower($result['grade'])); ?>"><?php echo e($result['grade']); ?></span></td>
                                <td><?php echo htmlspecialchars($result['remarks'] ?? '-'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Performance Chart -->
                <div class="mt-4">
                    <canvas id="performanceChart" height="300"></canvas>
                </div>

                <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    No results available for the selected term.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Attendance Summary -->
        <?php if ($attendance && $attendance['total_days'] > 0): ?>
        <div class="card">
            <div class="card-header">
                <h3>Attendance Summary - <?php echo e($selectedYear); ?></h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <canvas id="attendanceChart" height="250"></canvas>
                    </div>
                    <div class="col-md-6">
                        <div class="attendance-stats">
                            <div class="stat-row">
                                <span class="label present"><i class="fas fa-circle"></i> Present:</span>
                                <span class="value"><?php echo e($attendance['present']); ?> days</span>
                                <span class="percentage">(<?php echo round(($attendance['present'] / $attendance['total_days']) * 100, 1); ?>%)</span>
                            </div>
                            <div class="stat-row">
                                <span class="label absent"><i class="fas fa-circle"></i> Absent:</span>
                                <span class="value"><?php echo e($attendance['absent']); ?> days</span>
                                <span class="percentage">(<?php echo round(($attendance['absent'] / $attendance['total_days']) * 100, 1); ?>%)</span>
                            </div>
                            <div class="stat-row">
                                <span class="label late"><i class="fas fa-circle"></i> Late:</span>
                                <span class="value"><?php echo e($attendance['late']); ?> days</span>
                                <span class="percentage">(<?php echo round(($attendance['late'] / $attendance['total_days']) * 100, 1); ?>%)</span>
                            </div>
                            <div class="stat-row total">
                                <span class="label">Total Days:</span>
                                <span class="value"><?php echo e($attendance['total_days']); ?> days</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>
        <?php endif; ?>
    </main>
</div>

<!-- Charts Script -->
<script>
<?php if (!empty($results)): ?>
// Performance Chart
const ctx1 = document.getElementById('performanceChart').getContext('2d');
new Chart(ctx1, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_column($results, 'subject_name'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        datasets: [{
            label: 'Score (%)',
            data: <?php echo json_encode(array_map(function($r) {
                return round(($r['score'] / $r['max_score']) * 100, 1);
            }, $results)); ?>,
            backgroundColor: '#ffd700',
            borderColor: '#002855',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: {
                beginAtZero: true,
                max: 100,
                title: {
                    display: true,
                    text: 'Percentage (%)'
                }
            }
        },
        plugins: {
            legend: {
                display: false
            }
        }
    }
});
<?php endif; ?>

<?php if ($attendance && $attendance['total_days'] > 0): ?>
// Attendance Chart
const ctx2 = document.getElementById('attendanceChart').getContext('2d');
new Chart(ctx2, {
    type: 'doughnut',
    data: {
        labels: ['Present', 'Absent', 'Late'],
        datasets: [{
            data: [
                <?php echo e($attendance['present']); ?>,
                <?php echo e($attendance['absent']); ?>,
                <?php echo e($attendance['late']); ?>
            ],
            backgroundColor: ['#28a745', '#dc3545', '#ffc107'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});
<?php endif; ?>
</script>

<style>
.progress {
    height: 20px;
    background-color: #f0f0f0;
    border-radius: 10px;
    overflow: hidden;
}

.progress-bar {
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 11px;
    font-weight: bold;
    transition: width 0.3s ease;
}

.progress-bar.progress-high {
    background: linear-gradient(90deg, #28a745, #20c997);
}

.progress-bar.progress-medium {
    background: linear-gradient(90deg, #ffc107, #fd7e14);
}

.progress-bar.progress-low {
    background: linear-gradient(90deg, #dc3545, #c82333);
}

.badge-a { background-color: #28a745; color: white; }
.badge-b { background-color: #17a2b8; color: white; }
.badge-c { background-color: #ffc107; color: #333; }
.badge-d { background-color: #fd7e14; color: white; }
.badge-e { background-color: #6c757d; color: white; }
.badge-f { background-color: #dc3545; color: white; }

.attendance-stats {
    padding: 20px;
}

.stat-row {
    display: flex;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid #eee;
}

.stat-row:last-child {
    border-bottom: none;
}

.stat-row .label {
    width: 100px;
    font-weight: 500;
}

.stat-row .label i {
    margin-right: 5px;
}

.stat-row .label.present i { color: #28a745; }
.stat-row .label.absent i { color: #dc3545; }
.stat-row .label.late i { color: #ffc107; }

.stat-row .value {
    flex: 1;
    font-weight: 600;
}

.stat-row .percentage {
    color: #666;
    font-size: 0.9rem;
}

.stat-row.total {
    background: #f8f9fa;
    margin-top: 10px;
    padding: 15px;
    border-radius: 5px;
    font-weight: bold;
}
</style>

<?php
include '../includes/footer.php';
?>