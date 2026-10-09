<?php
// tests/seed.php - loads predictable demo data for local testing (CLI only).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';

$db = db();
$pw = Security::hashPassword('Test@12345');
$mk = function ($u, $email, $f, $l, $role) use ($db, $pw) {
    $db->query("DELETE FROM users WHERE email = ?", [$email]);
    return $db->insert("INSERT INTO users (username,email,password_hash,first_name,last_name,phone,role,is_active) VALUES (?,?,?,?,?,?,?,1)",
        [$u, $email, $pw, $f, $l, '08031234567', $role]);
};
$db->query("UPDATE users SET password_hash = ? WHERE role='admin'", [$pw]);

$tUser = $mk('teacher1', 'teacher@test.com', 'Tina', 'Teacher', 'teacher');
$db->query("DELETE FROM teachers WHERE employee_id='T001'");
$tid = $db->insert("INSERT INTO teachers (user_id, employee_id, qualification, specialization, date_of_hire) VALUES (?, 'T001','B.Ed','Maths','2020-01-01')", [$tUser]);
$t2User = $mk('teacher2', 'teacher2@test.com', 'Tom', 'Other', 'teacher');
$t2id = $db->insert("INSERT INTO teachers (user_id, employee_id) VALUES (?, 'T002')", [$t2User]);
$pUser = $mk('parent1', 'parent@test.com', 'Paula', 'Parent', 'parent');
$pid = $db->insert("INSERT INTO parents (user_id, occupation) VALUES (?, 'Engineer')", [$pUser]);
$p2User = $mk('parent2', 'parent2@test.com', 'Pete', 'Other', 'parent');
$p2id = $db->insert("INSERT INTO parents (user_id) VALUES (?)", [$p2User]);
$db->query("UPDATE classes SET teacher_id = ? WHERE id = 1", [$tid]);
$class = $db->getRow("SELECT id FROM classes ORDER BY id LIMIT 1")['id'];

$sUser = $mk('student1', 'student@test.com', 'Sam', 'Student', 'student');
$db->query("DELETE FROM students WHERE admission_number IN ('STB/2025/0001','STB/2025/0002')");
$sid = $db->insert("INSERT INTO students (user_id, admission_number, class_id, parent_id, date_of_birth, gender, admission_date) VALUES (?, 'STB/2025/0001', ?, ?, '2019-05-05','male','2024-09-01')", [$sUser, $class, $pid]);
$s2User = $mk('student2', 'student2@test.com', 'Sue', 'Other', 'student');
$s2id = $db->insert("INSERT INTO students (user_id, admission_number, class_id, parent_id, date_of_birth, gender, admission_date) VALUES (?, 'STB/2025/0002', ?, ?, '2019-06-06','female','2024-09-01')", [$s2User, $class, $p2id]);

$db->query("DELETE FROM subjects WHERE subject_code='MTH1'");
$sub = $db->insert("INSERT INTO subjects (subject_name, subject_code, class_id, teacher_id) VALUES ('Mathematics','MTH1',?,?)", [$class, $tid]);
$db->query("INSERT IGNORE INTO attendance (student_id,class_id,date,status,marked_by) VALUES (?,?,CURDATE(),'present',?)", [$sid, $class, $tUser]);
$db->insert("INSERT INTO results (student_id,subject_id,class_id,term,academic_year,assessment_type,score,max_score,grade,is_approved,entered_by) VALUES (?,?,?,'Term 1','2024-2025','exam',75,100,'A',1,?)", [$sid, $sub, $class, $tUser]);
$db->insert("INSERT INTO results (student_id,subject_id,class_id,term,academic_year,assessment_type,score,max_score,grade,is_approved,entered_by) VALUES (?,?,?,'Term 1','2024-2025','test',55,100,'C',0,?)", [$sid, $sub, $class, $tUser]);
$db->insert("INSERT INTO payments (student_id,receipt_number,payment_date,amount,payment_method,term,academic_year,status,recorded_by) VALUES (?,?,CURDATE(),50000,'cash','Term 1','2024-2025','completed',1)", [$sid, 'RCP-SEED-' . mt_rand(1000, 9999)]);
$db->insert("INSERT INTO announcements (title,content,audience,created_by,priority) VALUES ('Welcome','Term begins soon','all',1,'normal')");
$db->insert("INSERT INTO news_events (title,content,type,event_date,created_by,is_published) VALUES ('Sports Day','Join us','event',CURDATE(),1,1)");
$db->insert("INSERT INTO messages (sender_id,receiver_id,subject,message) VALUES (?,?,'Hello','Test message')", [$tUser, $pUser]);
$db->insert("INSERT INTO contact_messages (name,email,subject,message) VALUES ('Visitor','v@test.com','Hi','Hello there')");
echo "seeded: class=$class student=$sid student2=$s2id teacher=$tid teacher2=$t2id parent=$pid parent2=$p2id subject=$sub\n";
