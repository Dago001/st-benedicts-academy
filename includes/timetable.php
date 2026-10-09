<?php
// includes/timetable.php - weekly timetable rendering

const TIMETABLE_DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

function timetable_for_class($classId) {
    return db()->getRows(
        "SELECT tt.*, s.subject_name, CONCAT(u.first_name, ' ', u.last_name) AS teacher_name
         FROM time_table tt JOIN subjects s ON tt.subject_id = s.id
         LEFT JOIN teachers t ON tt.teacher_id = t.id LEFT JOIN users u ON t.user_id = u.id
         WHERE tt.class_id = ? ORDER BY tt.start_time, FIELD(tt.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday')",
        [(int)$classId]);
}

/** Responsive timetable: a grid on desktop, a day-by-day list on phones. */
function render_timetable(array $slots, $deleteForm = false) {
    if (!$slots) { echo '<p class="text-muted">No timetable has been set for this class yet.</p>'; return; }
    $byDay = array_fill_keys(TIMETABLE_DAYS, []);
    foreach ($slots as $s) { $byDay[$s['day_of_week']][] = $s; }
    echo '<div class="timetable">';
    foreach (TIMETABLE_DAYS as $day) {
        echo '<div class="tt-day"><h4>' . e($day) . '</h4>';
        if (!$byDay[$day]) { echo '<p class="text-muted tt-empty">No lessons</p>'; }
        foreach ($byDay[$day] as $s) {
            echo '<div class="tt-slot"><div class="tt-time">' . e(substr($s['start_time'], 0, 5)) . ' - ' . e(substr($s['end_time'], 0, 5)) . '</div>'
                . '<div class="tt-subject">' . e($s['subject_name']) . '</div>'
                . '<div class="tt-meta">' . e($s['teacher_name'] ?: 'No teacher') . ($s['room'] ? ' &middot; ' . e($s['room']) : '') . '</div>';
            if ($deleteForm) {
                echo '<form method="POST" onsubmit="return confirm(\'Remove this lesson?\')">' . csrf_field()
                    . '<input type="hidden" name="action" value="delete_slot"><input type="hidden" name="slot_id" value="' . (int)$s['id'] . '">'
                    . '<button type="submit" class="btn-icon text-danger" title="Remove"><i class="fas fa-trash"></i></button></form>';
            }
            echo '</div>';
        }
        echo '</div>';
    }
    echo '</div>';
}
