-- Cloud Event Registration Database (Capstone Enhanced Schema)
-- Target database name: cloud_event_registration_db
-- Import this file from phpMyAdmin (XAMPP) or MySQL CLI.

CREATE DATABASE IF NOT EXISTS cloud_event_registration_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE cloud_event_registration_db;

SET NAMES utf8mb4;

-- Recreate everything to keep schema consistent for demos.
SET FOREIGN_KEY_CHECKS = 0;
DROP VIEW IF EXISTS v_event_capacity;
DROP VIEW IF EXISTS v_registration_overview;

DROP TABLE IF EXISTS auth_password_reset_log;
DROP TABLE IF EXISTS auth_users;
DROP TABLE IF EXISTS student_event_registrations;
DROP TABLE IF EXISTS registration_form_details;
DROP TABLE IF EXISTS notification_outbox;
DROP TABLE IF EXISTS registration_activity_log;
DROP TABLE IF EXISTS registrations;
DROP TABLE IF EXISTS event_coordinator_map;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS events;
DROP TABLE IF EXISTS event_coordinators;
DROP TABLE IF EXISTS venues;
DROP TABLE IF EXISTS departments;
DROP TABLE IF EXISTS event_categories;
SET FOREIGN_KEY_CHECKS = 1;

-- ====================================
-- 1) Master tables (reference entities)
-- ====================================
CREATE TABLE event_categories (
    category_id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(60) NOT NULL,
    display_order TINYINT UNSIGNED NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_event_categories_name UNIQUE (category_name)
) ENGINE=InnoDB;

CREATE TABLE departments (
    department_id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_code VARCHAR(20) NOT NULL,
    department_name VARCHAR(120) NOT NULL,
    faculty_head VARCHAR(120) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_departments_code UNIQUE (department_code),
    CONSTRAINT uq_departments_name UNIQUE (department_name)
) ENGINE=InnoDB;

CREATE TABLE venues (
    venue_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    venue_code VARCHAR(20) NOT NULL,
    venue_name VARCHAR(140) NOT NULL,
    building_name VARCHAR(120) NULL,
    campus_zone ENUM('North Campus', 'South Campus', 'Central Campus', 'Virtual') NOT NULL DEFAULT 'Central Campus',
    default_capacity INT UNSIGNED NOT NULL,
    is_indoor TINYINT(1) NOT NULL DEFAULT 1,
    google_maps_url VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_venues_code UNIQUE (venue_code),
    CONSTRAINT chk_venue_capacity CHECK (default_capacity > 0)
) ENGINE=InnoDB;

CREATE TABLE event_coordinators (
    coordinator_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(120) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    designation VARCHAR(80) NOT NULL DEFAULT 'Faculty Coordinator',
    department_id SMALLINT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_coordinators_email UNIQUE (email),
    CONSTRAINT fk_coordinator_department
        FOREIGN KEY (department_id)
        REFERENCES departments (department_id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB;

-- ===========================
-- 2) Core transactional tables
-- ===========================
CREATE TABLE events (
    event_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_code VARCHAR(20) NOT NULL,
    event_name VARCHAR(140) NOT NULL,
    category_id TINYINT UNSIGNED NOT NULL,
    description TEXT NULL,
    venue_id INT UNSIGNED NOT NULL,
    event_mode ENUM('OFFLINE', 'ONLINE', 'HYBRID') NOT NULL DEFAULT 'OFFLINE',
    meeting_link VARCHAR(255) NULL,
    event_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    max_capacity INT UNSIGNED NOT NULL,
    registration_deadline DATE NOT NULL,
    poster_url VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_events_code UNIQUE (event_code),
    CONSTRAINT fk_events_category
        FOREIGN KEY (category_id)
        REFERENCES event_categories (category_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_events_venue
        FOREIGN KEY (venue_id)
        REFERENCES venues (venue_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT chk_events_capacity CHECK (max_capacity > 0),
    CONSTRAINT chk_event_time_order CHECK (start_time < end_time),
    INDEX idx_events_active_date (is_active, event_date),
    INDEX idx_events_category_date (category_id, event_date),
    INDEX idx_events_deadline (registration_deadline)
) ENGINE=InnoDB;

CREATE TABLE students (
    student_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(120) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    department_id SMALLINT UNSIGNED NOT NULL,
    academic_year ENUM('1st Year', '2nd Year', '3rd Year', '4th Year', 'Postgraduate') NOT NULL,
    gender ENUM('Prefer not to say', 'Female', 'Male', 'Other') NOT NULL DEFAULT 'Prefer not to say',
    university_roll_no VARCHAR(40) NULL,
    emergency_contact VARCHAR(20) NULL,
    date_of_birth DATE NULL,
    address_line VARCHAR(255) NULL,
    city VARCHAR(80) NULL,
    state VARCHAR(80) NULL,
    postal_code VARCHAR(20) NULL,
    guardian_name VARCHAR(120) NULL,
    linkedin_url VARCHAR(255) NULL,
    github_url VARCHAR(255) NULL,
    skills VARCHAR(255) NULL,
    bio TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_students_email UNIQUE (email),
    CONSTRAINT uq_students_roll_no UNIQUE (university_roll_no),
    CONSTRAINT fk_students_department
        FOREIGN KEY (department_id)
        REFERENCES departments (department_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    INDEX idx_students_name (full_name),
    INDEX idx_students_department (department_id)
) ENGINE=InnoDB;

CREATE TABLE registrations (
    registration_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration_no VARCHAR(30) NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    event_id INT UNSIGNED NOT NULL,
    current_status ENUM('PENDING', 'CONFIRMED', 'WAITLISTED', 'CANCELLED') NOT NULL DEFAULT 'CONFIRMED',
    attendance_state ENUM('NOT_MARKED', 'ATTENDED', 'NO_SHOW') NOT NULL DEFAULT 'NOT_MARKED',
    source_channel ENUM('web_portal', 'mobile_app', 'admin_panel', 'imported') NOT NULL DEFAULT 'web_portal',
    prior_experience TEXT NULL,
    special_requirements TEXT NULL,
    consent_accepted TINYINT(1) NOT NULL DEFAULT 1,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_registration_no UNIQUE (registration_no),
    CONSTRAINT uq_student_event UNIQUE (student_id, event_id),
    CONSTRAINT fk_registrations_student
        FOREIGN KEY (student_id)
        REFERENCES students (student_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_registrations_event
        FOREIGN KEY (event_id)
        REFERENCES events (event_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    INDEX idx_registration_event_status (event_id, current_status),
    INDEX idx_registration_created (registered_at),
    INDEX idx_registration_student (student_id)
) ENGINE=InnoDB;

CREATE TABLE event_coordinator_map (
    event_id INT UNSIGNED NOT NULL,
    coordinator_id INT UNSIGNED NOT NULL,
    role_name VARCHAR(80) NOT NULL DEFAULT 'Coordinator',
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id, coordinator_id),
    CONSTRAINT fk_map_event
        FOREIGN KEY (event_id)
        REFERENCES events (event_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_map_coordinator
        FOREIGN KEY (coordinator_id)
        REFERENCES event_coordinators (coordinator_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE registration_activity_log (
    log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration_id BIGINT UNSIGNED NOT NULL,
    action_type ENUM('CREATED', 'UPDATED', 'STATUS_CHANGED', 'CANCELLED', 'ATTENDANCE_MARKED', 'NOTIFIED') NOT NULL,
    action_note VARCHAR(255) NULL,
    metadata_json JSON NULL,
    changed_by VARCHAR(80) NOT NULL DEFAULT 'system',
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_log_registration
        FOREIGN KEY (registration_id)
        REFERENCES registrations (registration_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    INDEX idx_log_registration_time (registration_id, changed_at)
) ENGINE=InnoDB;

CREATE TABLE registration_form_details (
    detail_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration_id BIGINT UNSIGNED NOT NULL,
    motivation_statement TEXT NOT NULL,
    project_idea TEXT NULL,
    tshirt_size ENUM('XS', 'S', 'M', 'L', 'XL', 'XXL') NOT NULL DEFAULT 'M',
    dietary_preferences VARCHAR(120) NULL,
    accommodation_required TINYINT(1) NOT NULL DEFAULT 0,
    medical_notes TEXT NULL,
    expectations TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_registration_form_details_registration UNIQUE (registration_id),
    CONSTRAINT fk_registration_form_details_registration
        FOREIGN KEY (registration_id)
        REFERENCES registrations (registration_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE notification_outbox (
    notification_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration_id BIGINT UNSIGNED NOT NULL,
    notification_channel ENUM('EMAIL', 'SMS', 'WHATSAPP', 'IN_APP') NOT NULL DEFAULT 'EMAIL',
    template_key VARCHAR(80) NOT NULL,
    payload_json JSON NULL,
    delivery_status ENUM('PENDING', 'SENT', 'FAILED') NOT NULL DEFAULT 'PENDING',
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(255) NULL,
    scheduled_at DATETIME NULL,
    sent_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_registration
        FOREIGN KEY (registration_id)
        REFERENCES registrations (registration_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    INDEX idx_outbox_status_time (delivery_status, created_at),
    INDEX idx_outbox_registration (registration_id)
) ENGINE=InnoDB;

CREATE TABLE auth_users (
    auth_user_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role ENUM('ADMIN', 'CLIENT') NOT NULL,
    username VARCHAR(80) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    email VARCHAR(120) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_auth_users_username UNIQUE (username),
    CONSTRAINT uq_auth_users_email UNIQUE (email),
    INDEX idx_auth_users_role_active (role, is_active)
) ENGINE=InnoDB;

CREATE TABLE auth_password_reset_log (
    reset_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    auth_user_id BIGINT UNSIGNED NOT NULL,
    reset_mode ENUM('SELF_SERVICE', 'FORGOT_PASSWORD') NOT NULL DEFAULT 'FORGOT_PASSWORD',
    reset_by VARCHAR(80) NOT NULL DEFAULT 'system',
    reset_note VARCHAR(255) NULL,
    reset_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_password_reset_user
        FOREIGN KEY (auth_user_id)
        REFERENCES auth_users (auth_user_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    INDEX idx_password_reset_user_time (auth_user_id, reset_at)
) ENGINE=InnoDB;

-- Lightweight table used by the web UI pages (register.php/admin.php).
CREATE TABLE student_event_registrations (
    registration_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(120) NOT NULL,
    event_name ENUM('Tech Fest', 'Cultural Night', 'AI Workshop', 'Robotics Workshop') NOT NULL,
    registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_student_event_registered_at (registered_at),
    INDEX idx_student_event_name (event_name),
    INDEX idx_student_email (email)
) ENGINE=InnoDB;

-- ===========================
-- 3) Reporting / analytics views
-- ===========================
CREATE VIEW v_registration_overview AS
SELECT
    r.registration_id,
    r.registration_no,
    r.current_status,
    r.attendance_state,
    r.registered_at,
    s.full_name,
    s.email,
    s.phone,
    d.department_name,
    s.academic_year,
    e.event_name,
    e.event_code,
    ec.category_name,
    e.event_mode,
    e.event_date,
    v.venue_name,
    v.building_name
FROM registrations r
INNER JOIN students s ON s.student_id = r.student_id
INNER JOIN departments d ON d.department_id = s.department_id
INNER JOIN events e ON e.event_id = r.event_id
INNER JOIN event_categories ec ON ec.category_id = e.category_id
INNER JOIN venues v ON v.venue_id = e.venue_id;

CREATE VIEW v_event_capacity AS
SELECT
    e.event_id,
    e.event_code,
    e.event_name,
    ec.category_name,
    e.event_mode,
    e.event_date,
    e.max_capacity,
    SUM(CASE WHEN r.current_status IN ('PENDING', 'CONFIRMED') THEN 1 ELSE 0 END) AS occupied_count,
    SUM(CASE WHEN r.current_status = 'WAITLISTED' THEN 1 ELSE 0 END) AS waitlisted_count,
    GREATEST(
        e.max_capacity - SUM(CASE WHEN r.current_status IN ('PENDING', 'CONFIRMED') THEN 1 ELSE 0 END),
        0
    ) AS remaining_seats
FROM events e
INNER JOIN event_categories ec ON ec.category_id = e.category_id
LEFT JOIN registrations r ON r.event_id = e.event_id
GROUP BY
    e.event_id,
    e.event_code,
    e.event_name,
    ec.category_name,
    e.event_mode,
    e.event_date,
    e.max_capacity;

-- ===========================
-- 4) Seed reference data
-- ===========================
INSERT INTO event_categories (category_id, category_name, display_order, is_active) VALUES
    (1, 'Tech Fest', 1, 1),
    (2, 'Cultural', 2, 1),
    (3, 'Workshop', 3, 1),
    (4, 'Seminar', 4, 1),
    (5, 'Sports', 5, 1),
    (6, 'Hackathon', 6, 1)
ON DUPLICATE KEY UPDATE
    display_order = VALUES(display_order),
    is_active = VALUES(is_active);

INSERT INTO departments (department_id, department_code, department_name, faculty_head, is_active) VALUES
    (1, 'CSE', 'Computer Science and Engineering', 'Dr. R. Sharma', 1),
    (2, 'ECE', 'Electronics and Communication Engineering', 'Dr. S. Verma', 1),
    (3, 'ME', 'Mechanical Engineering', 'Dr. P. Singh', 1),
    (4, 'CE', 'Civil Engineering', 'Dr. K. Mehta', 1),
    (5, 'BBA', 'Business Administration', 'Prof. N. Trivedi', 1),
    (6, 'BCA', 'Bachelor of Computer Applications', 'Prof. T. Kapoor', 1),
    (7, 'MCA', 'Master of Computer Applications', 'Prof. A. Khan', 1)
ON DUPLICATE KEY UPDATE
    department_name = VALUES(department_name),
    faculty_head = VALUES(faculty_head),
    is_active = VALUES(is_active);

INSERT INTO venues (
    venue_id,
    venue_code,
    venue_name,
    building_name,
    campus_zone,
    default_capacity,
    is_indoor,
    google_maps_url,
    is_active
)
VALUES
    (1, 'AUD-MAIN', 'Main Auditorium', 'Academic Block A', 'Central Campus', 450, 1, NULL, 1),
    (2, 'OAT-01', 'Open Air Theatre', 'Cultural Block', 'Central Campus', 600, 0, NULL, 1),
    (3, 'LAB-AI2', 'AI Innovation Lab 2', 'Lab Complex', 'North Campus', 90, 1, NULL, 1),
    (4, 'LAB-MECH', 'Mechatronics Lab', 'Engineering Wing', 'South Campus', 80, 1, NULL, 1),
    (5, 'INNO-CENTER', 'Innovation Center', 'Startup Cell', 'Central Campus', 250, 1, NULL, 1),
    (6, 'ONLINE', 'Virtual Event Room', 'Online', 'Virtual', 5000, 1, 'https://meet.google.com', 1)
ON DUPLICATE KEY UPDATE
    venue_name = VALUES(venue_name),
    building_name = VALUES(building_name),
    campus_zone = VALUES(campus_zone),
    default_capacity = VALUES(default_capacity),
    is_indoor = VALUES(is_indoor),
    google_maps_url = VALUES(google_maps_url),
    is_active = VALUES(is_active);

INSERT INTO event_coordinators (
    coordinator_id,
    full_name,
    email,
    phone,
    designation,
    department_id,
    is_active
)
VALUES
    (1, 'Dr. Aditi Rao', 'aditi.rao@university.edu', '9876543210', 'Faculty Coordinator', 1, 1),
    (2, 'Prof. Rohan Desai', 'rohan.desai@university.edu', '9876543211', 'Faculty Coordinator', 6, 1),
    (3, 'Dr. Meera Kulkarni', 'meera.k@university.edu', '9876543212', 'Faculty Coordinator', 2, 1),
    (4, 'Prof. Vivek Saini', 'vivek.saini@university.edu', '9876543213', 'Faculty Coordinator', 3, 1),
    (5, 'Dr. Isha Bhatia', 'isha.bhatia@university.edu', '9876543214', 'Cultural Lead', 5, 1)
ON DUPLICATE KEY UPDATE
    full_name = VALUES(full_name),
    phone = VALUES(phone),
    designation = VALUES(designation),
    department_id = VALUES(department_id),
    is_active = VALUES(is_active);

-- ===========================
-- 5) Seed event data
-- ===========================
INSERT INTO events (
    event_id,
    event_code,
    event_name,
    category_id,
    description,
    venue_id,
    event_mode,
    meeting_link,
    event_date,
    start_time,
    end_time,
    max_capacity,
    registration_deadline,
    poster_url,
    is_active
)
VALUES
    (
        1,
        'TF26',
        'TechXplosion 2026',
        1,
        'A flagship campus-wide technology fest featuring coding contests, innovation booths, startup demos, and keynote sessions.',
        1,
        'OFFLINE',
        NULL,
        '2026-08-14',
        '09:30:00',
        '18:00:00',
        300,
        '2026-08-10',
        NULL,
        1
    ),
    (
        2,
        'CULT26',
        'Rhythm & Roots Cultural Night',
        2,
        'An evening of music, dance, and theatre performances by student clubs and invited artists.',
        2,
        'OFFLINE',
        NULL,
        '2026-09-05',
        '16:00:00',
        '21:30:00',
        450,
        '2026-09-02',
        NULL,
        1
    ),
    (
        3,
        'AIWS26',
        'GenAI Bootcamp for Students',
        3,
        'Hands-on bootcamp on prompt engineering, model evaluation, and building responsible AI mini-projects.',
        3,
        'HYBRID',
        'https://meet.google.com/aiws-bootcamp-2026',
        '2026-07-20',
        '10:00:00',
        '15:00:00',
        80,
        '2026-07-18',
        NULL,
        1
    ),
    (
        4,
        'ROBO26',
        'Robotics Rapid Prototyping',
        3,
        'Practical robotics build session covering sensors, controllers, and final mini-bot team presentations.',
        4,
        'OFFLINE',
        NULL,
        '2026-10-12',
        '11:00:00',
        '17:00:00',
        60,
        '2026-10-08',
        NULL,
        1
    ),
    (
        5,
        'HACK26',
        'Campus Hackathon Sprint',
        6,
        '24-hour interdisciplinary hackathon to solve real campus and community challenges.',
        5,
        'OFFLINE',
        NULL,
        '2026-11-06',
        '08:00:00',
        '23:59:00',
        220,
        '2026-11-01',
        NULL,
        1
    ),
    (
        6,
        'CLD26',
        'Cloud Native Starter Seminar',
        4,
        'Intro seminar on cloud-native concepts, serverless architecture, and GCP deployment pathways.',
        6,
        'ONLINE',
        'https://meet.google.com/cloud-native-seminar-2026',
        '2026-07-05',
        '15:00:00',
        '17:00:00',
        500,
        '2026-07-03',
        NULL,
        1
    )
ON DUPLICATE KEY UPDATE
    event_name = VALUES(event_name),
    category_id = VALUES(category_id),
    description = VALUES(description),
    venue_id = VALUES(venue_id),
    event_mode = VALUES(event_mode),
    meeting_link = VALUES(meeting_link),
    event_date = VALUES(event_date),
    start_time = VALUES(start_time),
    end_time = VALUES(end_time),
    max_capacity = VALUES(max_capacity),
    registration_deadline = VALUES(registration_deadline),
    poster_url = VALUES(poster_url),
    is_active = VALUES(is_active);

INSERT INTO event_coordinator_map (event_id, coordinator_id, role_name) VALUES
    (1, 1, 'Lead Coordinator'),
    (1, 2, 'Student Activities Coordinator'),
    (2, 5, 'Cultural Program Coordinator'),
    (3, 1, 'AI Workshop Mentor'),
    (3, 3, 'Technical Session Coordinator'),
    (4, 4, 'Lab Session Coordinator'),
    (5, 2, 'Hackathon Program Lead'),
    (6, 3, 'Seminar Speaker')
ON DUPLICATE KEY UPDATE
    role_name = VALUES(role_name);

-- ===========================
-- 6) Authentication seed data
-- ===========================
INSERT INTO auth_users (
    auth_user_id,
    role,
    username,
    display_name,
    email,
    password_hash,
    is_active
)
VALUES
    (
        1,
        'ADMIN',
        'Admin',
        'System Administrator',
        'admin@college.edu',
        '$2y$10$Wdt0nJWqKnNkJ9ct.owdYeTlKVYr6J0C5XyFOLtAKjhGMxwlOdyVu',
        1
    ),
    (
        2,
        'CLIENT',
        'studentdemo',
        'Student Demo',
        'studentdemo@college.edu',
        '$2y$10$ekCUj1OQej2fnIZp3N.DdOp46pDzRfseygxkuZM8gXOyUn0fb6x/u',
        1
    )
ON DUPLICATE KEY UPDATE
    role = VALUES(role),
    display_name = VALUES(display_name),
    email = VALUES(email),
    password_hash = VALUES(password_hash),
    is_active = VALUES(is_active);
