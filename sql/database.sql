-- database.sql
-- ST. BENEDICT'S EARLY YEARS BRITISH ACADEMY Database Schema

CREATE DATABASE IF NOT EXISTS st_benedicts_academy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE st_benedicts_academy;

-- Users table (base table for all roles)
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    phone VARCHAR(20),
    role ENUM('admin', 'teacher', 'student', 'parent') NOT NULL,
    profile_image VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE,
    last_login DATETIME,
    login_attempts INT DEFAULT 0,
    locked_until DATETIME,
    totp_secret VARCHAR(64) NULL,
    totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    INDEX idx_email (email),
    INDEX idx_role (role)
);

-- Teachers table
CREATE TABLE teachers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNIQUE NOT NULL,
    employee_id VARCHAR(20) UNIQUE NOT NULL,
    qualification VARCHAR(255),
    specialization VARCHAR(100),
    date_of_hire DATE,
    address TEXT,
    emergency_contact VARCHAR(50),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_employee_id (employee_id)
);

-- Parents table
CREATE TABLE parents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNIQUE NOT NULL,
    occupation VARCHAR(100),
    address TEXT,
    emergency_contact VARCHAR(50),
    relationship VARCHAR(50),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Classes table
CREATE TABLE classes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    class_name VARCHAR(50) NOT NULL,
    section VARCHAR(20),
    academic_year VARCHAR(20) NOT NULL,
    teacher_id INT,
    capacity INT DEFAULT 30,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE SET NULL,
    INDEX idx_academic_year (academic_year)
);

-- Students table
CREATE TABLE students (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNIQUE NOT NULL,
    admission_number VARCHAR(20) UNIQUE NOT NULL,
    class_id INT,
    parent_id INT,
    date_of_birth DATE,
    gender ENUM('male', 'female', 'other'),
    admission_date DATE,
    address TEXT,
    blood_group VARCHAR(5),
    medical_notes TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
    FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE SET NULL,
    INDEX idx_admission (admission_number)
);

-- Subjects table
CREATE TABLE subjects (
    id INT PRIMARY KEY AUTO_INCREMENT,
    subject_name VARCHAR(100) NOT NULL,
    subject_code VARCHAR(20) UNIQUE NOT NULL,
    description TEXT,
    class_id INT,
    teacher_id INT,
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE SET NULL,
    INDEX idx_subject_code (subject_code)
);

-- Attendance table
CREATE TABLE attendance (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    date DATE NOT NULL,
    status ENUM('present', 'absent', 'late', 'excused') NOT NULL,
    marked_by INT,
    remarks TEXT,
    marked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (marked_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_attendance (student_id, date),
    INDEX idx_date (date)
);

-- Results table
CREATE TABLE results (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    subject_id INT NOT NULL,
    class_id INT NOT NULL,
    term VARCHAR(20) NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    assessment_type ENUM('test', 'exam', 'assignment', 'project') NOT NULL,
    score DECIMAL(5,2) NOT NULL,
    max_score DECIMAL(5,2) DEFAULT 100,
    grade VARCHAR(2),
    remarks TEXT,
    is_approved BOOLEAN DEFAULT FALSE,
    approved_by INT,
    approved_at DATETIME,
    entered_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (entered_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_student_term (student_id, term, academic_year)
);

-- Fees structure
CREATE TABLE fee_structure (
    id INT PRIMARY KEY AUTO_INCREMENT,
    class_id INT NOT NULL,
    fee_type VARCHAR(50) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    term VARCHAR(20),
    academic_year VARCHAR(20) NOT NULL,
    due_date DATE,
    is_mandatory BOOLEAN DEFAULT TRUE,
    description TEXT,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    INDEX idx_class_year (class_id, academic_year)
);

-- Payments table
CREATE TABLE payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    receipt_number VARCHAR(50) UNIQUE NOT NULL,
    payment_date DATE NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('cash', 'bank_transfer', 'card', 'cheque') NOT NULL,
    transaction_id VARCHAR(100),
    bank_name VARCHAR(100),
    cheque_number VARCHAR(50),
    term VARCHAR(20),
    academic_year VARCHAR(20) NOT NULL,
    fee_type VARCHAR(50),
    fee_structure_id INT NULL,
    status ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
    recorded_by INT,
    approved_by INT NULL,
    approved_at DATETIME NULL,
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (fee_structure_id) REFERENCES fee_structure(id) ON DELETE SET NULL,
    INDEX idx_receipt (receipt_number),
    INDEX idx_student_payments (student_id, academic_year)
);

-- Announcements table
CREATE TABLE announcements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    audience ENUM('all', 'students', 'teachers', 'parents', 'admins') DEFAULT 'all',
    created_by INT NOT NULL,
    priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    attachment VARCHAR(255),
    expires_at DATETIME,
    is_published BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_audience (audience)
);

-- Homework / assignments (set by teachers, submitted by students)
CREATE TABLE homework (
    id INT PRIMARY KEY AUTO_INCREMENT,
    class_id INT NOT NULL,
    subject_id INT NOT NULL,
    teacher_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    instructions TEXT,
    attachment_path VARCHAR(255),
    due_date DATETIME NOT NULL,
    total_marks DECIMAL(5,2) DEFAULT 100,
    is_published BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
    INDEX idx_due_date (due_date)
);

CREATE TABLE homework_submissions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    homework_id INT NOT NULL,
    student_id INT NOT NULL,
    submission_text TEXT,
    attachment_path VARCHAR(255),
    status ENUM('submitted', 'late', 'graded') DEFAULT 'submitted',
    submission_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    obtained_marks DECIMAL(5,2),
    feedback TEXT,
    graded_by INT,
    graded_at DATETIME,
    FOREIGN KEY (homework_id) REFERENCES homework(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (graded_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_submission (homework_id, student_id)
);

-- Full online admission applications (public/apply.php)
CREATE TABLE applications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    application_number VARCHAR(50) UNIQUE NOT NULL,
    child_first_name VARCHAR(50) NOT NULL,
    child_last_name VARCHAR(50) NOT NULL,
    child_dob DATE NOT NULL,
    child_gender VARCHAR(10) NOT NULL,
    class_applying VARCHAR(50) NOT NULL,
    parent_title VARCHAR(20),
    parent_first_name VARCHAR(50) NOT NULL,
    parent_last_name VARCHAR(50) NOT NULL,
    parent_email VARCHAR(100) NOT NULL,
    parent_phone VARCHAR(20) NOT NULL,
    parent_occupation VARCHAR(100),
    address TEXT NOT NULL,
    city VARCHAR(100),
    state VARCHAR(100),
    previous_school VARCHAR(200),
    reason_applying TEXT,
    how_hear VARCHAR(100),
    emergency_name VARCHAR(100),
    emergency_phone VARCHAR(20),
    emergency_relationship VARCHAR(50),
    birth_certificate_path VARCHAR(255),
    passport_photo_path VARCHAR(255),
    status ENUM('pending', 'reviewing', 'accepted', 'rejected') DEFAULT 'pending',
    reviewed_by INT,
    reviewed_at DATETIME,
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_app_status (status)
);

-- Weekly timetable
CREATE TABLE time_table (
    id INT PRIMARY KEY AUTO_INCREMENT,
    class_id INT NOT NULL,
    subject_id INT NOT NULL,
    teacher_id INT,
    day_of_week ENUM('Monday','Tuesday','Wednesday','Thursday','Friday') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    room VARCHAR(50),
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE SET NULL
);

-- Staff attendance
CREATE TABLE staff_attendance (
    id INT PRIMARY KEY AUTO_INCREMENT,
    teacher_id INT NOT NULL,
    date DATE NOT NULL,
    status ENUM('present', 'absent', 'late', 'excused') NOT NULL,
    check_in TIME,
    check_out TIME,
    remarks TEXT,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
    UNIQUE KEY unique_staff_day (teacher_id, date)
);

-- Password reset tokens (only a SHA-256 hash of the token is stored)
CREATE TABLE password_resets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_token (token_hash)
);

-- Chatbot question log (emails/phone numbers are redacted before saving)
CREATE TABLE chatbot_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ip_hash CHAR(64) NOT NULL,
    question VARCHAR(300) NOT NULL,
    intent VARCHAR(40),
    matched TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_chat_ip (ip_hash, created_at),
    INDEX idx_chat_matched (matched, created_at)
);

-- Failed-login log used for per-IP throttling (IP stored as a salted hash)
CREATE TABLE login_throttle (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ip_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_throttle (ip_hash, created_at)
);

-- Gallery table
CREATE TABLE gallery (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    image_path VARCHAR(255) NOT NULL,
    thumbnail_path VARCHAR(255),
    category VARCHAR(50),
    uploaded_by INT NOT NULL,
    is_featured BOOLEAN DEFAULT FALSE,
    is_published BOOLEAN DEFAULT TRUE,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
);

-- News/Events table
CREATE TABLE news_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    type ENUM('news', 'event') NOT NULL,
    event_date DATE,
    event_time TIME,
    venue VARCHAR(255),
    image_path VARCHAR(255),
    is_featured BOOLEAN DEFAULT FALSE,
    is_published BOOLEAN DEFAULT TRUE,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_type_date (type, event_date)
);

-- Contact messages
CREATE TABLE contact_messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    subject VARCHAR(200),
    message TEXT NOT NULL,
    status ENUM('new', 'read', 'replied', 'archived') DEFAULT 'new',
    replied_by INT,
    replied_at DATETIME,
    reply_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (replied_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status (status)
);

-- Admissions applications
CREATE TABLE admissions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    application_number VARCHAR(50) UNIQUE NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    middle_name VARCHAR(50),
    last_name VARCHAR(50) NOT NULL,
    date_of_birth DATE NOT NULL,
    gender ENUM('male', 'female', 'other') NOT NULL,
    class_applying_for VARCHAR(50) NOT NULL,
    parent_name VARCHAR(100) NOT NULL,
    parent_email VARCHAR(100) NOT NULL,
    parent_phone VARCHAR(20) NOT NULL,
    address TEXT NOT NULL,
    previous_school VARCHAR(200),
    documents_path VARCHAR(255),
    status ENUM('pending', 'reviewing', 'accepted', 'rejected') DEFAULT 'pending',
    reviewed_by INT,
    reviewed_at DATETIME,
    remarks TEXT,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status (status)
);

-- Audit logs
CREATE TABLE audit_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    table_affected VARCHAR(50),
    record_id INT,
    old_values TEXT,
    new_values TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_action (user_id, action),
    INDEX idx_created_at (created_at)
);

-- Messages (communication)
CREATE TABLE messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    subject VARCHAR(200),
    message TEXT NOT NULL,
    attachment VARCHAR(255),
    is_read BOOLEAN DEFAULT FALSE,
    read_at DATETIME,
    parent_message_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_message_id) REFERENCES messages(id) ON DELETE CASCADE,
    INDEX idx_conversation (sender_id, receiver_id)
);

-- Session management
CREATE TABLE sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    session_token VARCHAR(255) UNIQUE NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (session_token)
);

-- Insert default admin user (password: Admin@123 - CHANGE IT after first login)
INSERT INTO users (username, email, password_hash, first_name, last_name, role, is_active) VALUES
('admin', 'admin@stbenedicts.edu.ng', '$2y$12$hKB4juxd/P4AT7RSNDZVhO.M3bQax5S6m6U9DsrbxkpsH6WxoRyg6', 'System', 'Administrator', 'admin', 1);

-- Insert sample classes
INSERT INTO classes (class_name, section, academic_year, capacity) VALUES
('Nursery 1', 'A', '2024-2025', 20),
('Nursery 2', 'A', '2024-2025', 20),
('Reception', 'A', '2024-2025', 25),
('Year 1', 'A', '2024-2025', 25),
('Year 2', 'A', '2024-2025', 25);

-- Insert fee structure
INSERT INTO fee_structure (class_id, fee_type, amount, term, academic_year) VALUES
(1, 'Tuition Fee', 150000.00, 'Term 1', '2024-2025'),
(1, 'Development Levy', 50000.00, 'Term 1', '2024-2025'),
(1, 'Sports Fee', 25000.00, 'Term 1', '2024-2025');

-- Create indexes for performance
CREATE INDEX idx_users_role_active ON users(role, is_active);
CREATE INDEX idx_attendance_date_status ON attendance(date, status);
CREATE INDEX idx_payments_date_status ON payments(payment_date, status);
CREATE INDEX idx_results_approval ON results(is_approved, term, academic_year);
CREATE INDEX idx_news_published ON news_events(is_published, created_at);