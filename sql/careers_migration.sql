-- sql/careers_migration.sql
-- Migration for Careers and Vacancies system
-- ST. BENEDICT'S EARLY YEARS BRITISH ACADEMY

-- 1. Table for Job Vacancies
CREATE TABLE IF NOT EXISTS job_vacancies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(150) NOT NULL,
    department VARCHAR(100) NOT NULL,
    job_type VARCHAR(50) NOT NULL DEFAULT 'Full-time',
    location VARCHAR(100) NOT NULL DEFAULT 'Main Campus, Abuja',
    experience_level VARCHAR(50) NULL,
    description TEXT NOT NULL,
    requirements TEXT NULL,
    deadline DATE NULL,
    status ENUM('published', 'draft', 'closed') NOT NULL DEFAULT 'published',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_vacancy_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Table for Candidate Applications
CREATE TABLE IF NOT EXISTS career_applications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    vacancy_id INT NULL,
    reference_no VARCHAR(50) NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    position_applied VARCHAR(150) NOT NULL,
    qualification VARCHAR(100) NULL,
    experience_years VARCHAR(50) NULL,
    cover_letter TEXT NULL,
    resume_path VARCHAR(255) NOT NULL,
    additional_doc_path VARCHAR(255) NULL,
    status ENUM('pending', 'reviewing', 'shortlisted', 'interviewed', 'rejected', 'hired') NOT NULL DEFAULT 'pending',
    admin_notes TEXT NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_app_vacancy (vacancy_id),
    INDEX idx_app_status (status),
    INDEX idx_app_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Initial Sample Vacancies (if table is newly created)
INSERT INTO job_vacancies (title, department, job_type, location, experience_level, description, requirements, deadline, status)
SELECT * FROM (
    SELECT 
        'Early Years / Nursery Lead Educator' AS title,
        'Early Years / Academic' AS department,
        'Full-time' AS job_type,
        'Main Campus, Abuja' AS location,
        '3+ Years' AS experience_level,
        'We are seeking an enthusiastic, passionate, and certified Early Years Educator with experience in the British Early Years Foundation Stage (EYFS) curriculum to nurture, guide, and inspire our pupils.' AS description,
        '- Minimum of B.Ed or PGDE in Early Childhood Education or related field.\n- Demonstrated knowledge of the British EYFS framework.\n- Excellent communication and creative pedagogical skills.\n- Genuine love for early childhood development and child safety.' AS requirements,
        DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY) AS deadline,
        'published' AS status
) AS tmp
WHERE NOT EXISTS (
    SELECT 1 FROM job_vacancies WHERE title = 'Early Years / Nursery Lead Educator'
) LIMIT 1;

INSERT INTO job_vacancies (title, department, job_type, location, experience_level, description, requirements, deadline, status)
SELECT * FROM (
    SELECT 
        'Primary Key Stage 1 / 2 Class Teacher' AS title,
        'Academic' AS department,
        'Full-time' AS job_type,
        'Main Campus, Abuja' AS location,
        '2+ Years' AS experience_level,
        'We are looking for a qualified Primary Teacher who can deliver inspiring lessons across English, Mathematics, and Science according to the British National Curriculum.' AS description,
        '- Bachelor\'s Degree in Education (B.Ed) or relevant discipline with PGDE.\n- Solid understanding of Cambridge Primary / British Curriculum.\n- Strong classroom management and digital teaching tool proficiency.\n- Proven track record of student engagement and academic excellence.' AS requirements,
        DATE_ADD(CURRENT_DATE, INTERVAL 45 DAY) AS deadline,
        'published' AS status
) AS tmp
WHERE NOT EXISTS (
    SELECT 1 FROM job_vacancies WHERE title = 'Primary Key Stage 1 / 2 Class Teacher'
) LIMIT 1;
