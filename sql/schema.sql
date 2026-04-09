USE u406832387_RFID_system;

CREATE TABLE IF NOT EXISTS tenants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(80) NOT NULL UNIQUE,
  school_name VARCHAR(180) NOT NULL,
  logo_url VARCHAR(255) NULL,
  company_logo_url VARCHAR(255) NULL,
  background_url VARCHAR(255) NULL,
  plan_name VARCHAR(60) NOT NULL DEFAULT 'Starter',
  max_users INT NOT NULL DEFAULT 100,
  max_cards INT NOT NULL DEFAULT 300,
  billing_status ENUM('trial', 'paid', 'past_due', 'suspended') NOT NULL DEFAULT 'trial',
  trial_ends_at DATE NULL,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  sms_enabled TINYINT(1) NOT NULL DEFAULT 0,
  sms_template_in TEXT NULL,
  sms_template_out TEXT NULL,
  sms_poll_secret VARCHAR(64) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO tenants (id, slug, school_name, status)
VALUES (1, 'default-school', 'Default School', 'active')
ON DUPLICATE KEY UPDATE school_name = VALUES(school_name);

CREATE TABLE IF NOT EXISTS platform_admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(120) NULL,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO platform_admins (username, password_hash, display_name, status)
VALUES (
  'superadmin',
  '$2y$10$lNhWWhls8ivlN./hX5V73Oa/qWcQHMqEigWbIDrX4S.3v7RTbkBKK',
  'Platform Super Admin',
  'active'
)
ON DUPLICATE KEY UPDATE status = VALUES(status);

CREATE TABLE IF NOT EXISTS courses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  code VARCHAR(50) NOT NULL,
  name VARCHAR(100) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_courses_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  UNIQUE KEY uk_courses_tenant_code (tenant_id, code)
);

CREATE TABLE IF NOT EXISTS grade_levels (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  name VARCHAR(50) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_grades_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  UNIQUE KEY uk_grades_tenant_name (tenant_id, name)
);

CREATE TABLE IF NOT EXISTS sections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  name VARCHAR(50) NOT NULL,
  grade_level_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sections_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_sections_grade
    FOREIGN KEY (grade_level_id) REFERENCES grade_levels(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  role ENUM('student', 'teacher', 'employee', 'parent', 'admin') NOT NULL,
  first_name VARCHAR(60) NOT NULL,
  last_name VARCHAR(60) NOT NULL,
  middle_name VARCHAR(60) NULL,
  gender ENUM('male', 'female', 'other') DEFAULT 'other',
  email VARCHAR(120) NULL,
  phone VARCHAR(40) NULL,
  photo_path VARCHAR(255) NULL,
  birth_date DATE NULL,
  nationality VARCHAR(80) NULL,
  full_address VARCHAR(255) NULL,
  region VARCHAR(120) NULL,
  province VARCHAR(120) NULL,
  city VARCHAR(120) NULL,
  barangay VARCHAR(120) NULL,
  zipcode VARCHAR(20) NULL,
  facebook_link VARCHAR(255) NULL,
  religion VARCHAR(100) NULL,
  lrn VARCHAR(40) NULL,
  stay_with VARCHAR(120) NULL,
  is_transferee TINYINT(1) NOT NULL DEFAULT 0,
  course_id INT NULL,
  grade_level_id INT NULL,
  section_id INT NULL,
  parent_user_id INT NULL,
  teacher_user_id INT NULL,
  username VARCHAR(60) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  status ENUM('active', 'inactive') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_users_course
    FOREIGN KEY (course_id) REFERENCES courses(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_users_grade
    FOREIGN KEY (grade_level_id) REFERENCES grade_levels(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_users_section
    FOREIGN KEY (section_id) REFERENCES sections(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_users_parent
    FOREIGN KEY (parent_user_id) REFERENCES users(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_users_teacher
    FOREIGN KEY (teacher_user_id) REFERENCES users(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  UNIQUE KEY uk_users_tenant_username (tenant_id, username)
);

CREATE TABLE IF NOT EXISTS rfid_cards (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  uid VARCHAR(80) NOT NULL,
  label_name VARCHAR(80) NULL,
  is_assigned TINYINT(1) NOT NULL DEFAULT 0,
  user_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cards_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_cards_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  UNIQUE KEY uk_cards_tenant_uid (tenant_id, uid)
);

CREATE TABLE IF NOT EXISTS announcements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  title VARCHAR(150) NOT NULL,
  content TEXT NOT NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ann_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ann_created_by
    FOREIGN KEY (created_by) REFERENCES users(id)
    ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS attendance_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  user_id INT NULL,
  rfid_uid VARCHAR(80) NOT NULL,
  scan_type ENUM('IN', 'OUT') NOT NULL DEFAULT 'IN',
  device_name VARCHAR(120) NULL,
  remarks VARCHAR(255) NULL,
  scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_logs_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_logs_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX idx_logs_tenant (tenant_id),
  INDEX idx_logs_scan_date (scanned_at),
  INDEX idx_logs_user (user_id),
  INDEX idx_logs_uid (rfid_uid)
);

CREATE TABLE IF NOT EXISTS sms_outbox (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  attendance_log_id BIGINT NULL,
  destination_phone VARCHAR(32) NOT NULL,
  message_body VARCHAR(700) NOT NULL,
  status ENUM('pending', 'processing', 'sent', 'failed') NOT NULL DEFAULT 'pending',
  error_message VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  CONSTRAINT fk_sms_outbox_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_sms_outbox_log
    FOREIGN KEY (attendance_log_id) REFERENCES attendance_logs(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX idx_sms_outbox_tenant_status (tenant_id, status),
  INDEX idx_sms_outbox_created (created_at)
);

INSERT INTO grade_levels (tenant_id, name) VALUES
(1, 'Grade 7'),
(1, 'Grade 8'),
(1, 'Grade 9'),
(1, 'Grade 10'),
(1, 'Grade 11'),
(1, 'Grade 12')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO courses (tenant_id, code, name) VALUES
(1, 'JHS', 'Junior High School'),
(1, 'SHS-STEM', 'Senior High - STEM'),
(1, 'SHS-ABM', 'Senior High - ABM')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO sections (tenant_id, name, grade_level_id)
SELECT 1, 'A', gl.id FROM grade_levels gl WHERE gl.tenant_id = 1 AND gl.name IN ('Grade 7', 'Grade 8', 'Grade 9')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO users (
  tenant_id, role, first_name, last_name, gender, username, password_hash, status
) VALUES
(
  1, 'admin', 'System', 'Administrator', 'other', 'admin',
  '$2y$10$BfizPGXIRGHuMOpY.fn13e1nOIfPKyuPNZ/TaGAvkccVaTYWSnZri', 'active'
),
(
  1, 'teacher', 'Ana', 'Teacher', 'female', 'teacher1',
  '$2y$10$HGMrweG9jG.o90FGl8E43e3roXX3GcRu.z6IsZxE.k/IIurDHhaPe', 'active'
),
(
  1, 'employee', 'Mark', 'Staff', 'male', 'employee1',
  '$2y$10$G8oiAtGLI5KkvtFE/1shb.J4vDYxaB68enbU/39.aIePp8F5Pwzn.', 'active'
),
(
  1, 'parent', 'Leah', 'Parent', 'female', 'parent1',
  '$2y$10$ssQQLZJ13ci5.zYqDY7hjuJvdEk14UwBDrv/r.yT9.cXn70huZNvm', 'active'
)
ON DUPLICATE KEY UPDATE
  password_hash = VALUES(password_hash),
  status = VALUES(status);
