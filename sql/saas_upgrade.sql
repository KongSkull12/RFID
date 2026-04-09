USE school_rfid_attendance;

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
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

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

INSERT INTO tenants (id, slug, school_name, status)
VALUES (1, 'default-school', 'Default School', 'active')
ON DUPLICATE KEY UPDATE school_name = VALUES(school_name);

ALTER TABLE tenants ADD COLUMN IF NOT EXISTS plan_name VARCHAR(60) NOT NULL DEFAULT 'Starter' AFTER background_url;
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS company_logo_url VARCHAR(255) NULL AFTER logo_url;
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS max_users INT NOT NULL DEFAULT 100 AFTER plan_name;
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS max_cards INT NOT NULL DEFAULT 300 AFTER max_users;
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS billing_status ENUM('trial', 'paid', 'past_due', 'suspended') NOT NULL DEFAULT 'trial' AFTER max_cards;
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS trial_ends_at DATE NULL AFTER billing_status;

ALTER TABLE courses ADD COLUMN IF NOT EXISTS tenant_id INT NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE grade_levels ADD COLUMN IF NOT EXISTS tenant_id INT NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE sections ADD COLUMN IF NOT EXISTS tenant_id INT NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE users ADD COLUMN IF NOT EXISTS tenant_id INT NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE users ADD COLUMN IF NOT EXISTS photo_path VARCHAR(255) NULL AFTER phone;
ALTER TABLE users ADD COLUMN IF NOT EXISTS birth_date DATE NULL AFTER photo_path;
ALTER TABLE users ADD COLUMN IF NOT EXISTS nationality VARCHAR(80) NULL AFTER birth_date;
ALTER TABLE users ADD COLUMN IF NOT EXISTS full_address VARCHAR(255) NULL AFTER nationality;
ALTER TABLE users ADD COLUMN IF NOT EXISTS region VARCHAR(120) NULL AFTER full_address;
ALTER TABLE users ADD COLUMN IF NOT EXISTS province VARCHAR(120) NULL AFTER region;
ALTER TABLE users ADD COLUMN IF NOT EXISTS city VARCHAR(120) NULL AFTER province;
ALTER TABLE users ADD COLUMN IF NOT EXISTS barangay VARCHAR(120) NULL AFTER city;
ALTER TABLE users ADD COLUMN IF NOT EXISTS zipcode VARCHAR(20) NULL AFTER barangay;
ALTER TABLE users ADD COLUMN IF NOT EXISTS facebook_link VARCHAR(255) NULL AFTER zipcode;
ALTER TABLE users ADD COLUMN IF NOT EXISTS religion VARCHAR(100) NULL AFTER facebook_link;
ALTER TABLE users ADD COLUMN IF NOT EXISTS lrn VARCHAR(40) NULL AFTER religion;
ALTER TABLE users ADD COLUMN IF NOT EXISTS stay_with VARCHAR(120) NULL AFTER lrn;
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_transferee TINYINT(1) NOT NULL DEFAULT 0 AFTER stay_with;
ALTER TABLE users ADD COLUMN IF NOT EXISTS teacher_user_id INT NULL AFTER parent_user_id;
ALTER TABLE rfid_cards ADD COLUMN IF NOT EXISTS tenant_id INT NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE announcements ADD COLUMN IF NOT EXISTS tenant_id INT NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE attendance_logs ADD COLUMN IF NOT EXISTS tenant_id INT NOT NULL DEFAULT 1 AFTER id;

UPDATE courses SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE grade_levels SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE sections SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE users SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE rfid_cards SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE announcements SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE attendance_logs SET tenant_id = 1 WHERE tenant_id IS NULL;

ALTER TABLE users DROP INDEX username;
ALTER TABLE users ADD UNIQUE KEY uk_users_tenant_username (tenant_id, username);
ALTER TABLE rfid_cards DROP INDEX uid;
ALTER TABLE rfid_cards ADD UNIQUE KEY uk_cards_tenant_uid (tenant_id, uid);
ALTER TABLE courses DROP INDEX code;
ALTER TABLE courses ADD UNIQUE KEY uk_courses_tenant_code (tenant_id, code);
ALTER TABLE grade_levels DROP INDEX name;
ALTER TABLE grade_levels ADD UNIQUE KEY uk_grades_tenant_name (tenant_id, name);

ALTER TABLE courses
  ADD CONSTRAINT fk_courses_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
  ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE grade_levels
  ADD CONSTRAINT fk_grades_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
  ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE sections
  ADD CONSTRAINT fk_sections_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
  ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE users
  ADD CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
  ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE rfid_cards
  ADD CONSTRAINT fk_cards_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
  ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE announcements
  ADD CONSTRAINT fk_ann_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
  ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE attendance_logs
  ADD CONSTRAINT fk_logs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE attendance_logs ADD INDEX idx_logs_tenant (tenant_id);

UPDATE users
SET password_hash = '$2y$10$BfizPGXIRGHuMOpY.fn13e1nOIfPKyuPNZ/TaGAvkccVaTYWSnZri', status = 'active'
WHERE tenant_id = 1 AND username = 'admin';

INSERT INTO users (tenant_id, role, first_name, last_name, gender, username, password_hash, status)
VALUES
(1, 'teacher', 'Ana', 'Teacher', 'female', 'teacher1', '$2y$10$HGMrweG9jG.o90FGl8E43e3roXX3GcRu.z6IsZxE.k/IIurDHhaPe', 'active'),
(1, 'employee', 'Mark', 'Staff', 'male', 'employee1', '$2y$10$G8oiAtGLI5KkvtFE/1shb.J4vDYxaB68enbU/39.aIePp8F5Pwzn.', 'active'),
(1, 'parent', 'Leah', 'Parent', 'female', 'parent1', '$2y$10$ssQQLZJ13ci5.zYqDY7hjuJvdEk14UwBDrv/r.yT9.cXn70huZNvm', 'active')
ON DUPLICATE KEY UPDATE status = VALUES(status);
