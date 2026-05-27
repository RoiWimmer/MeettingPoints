-- Meetting_Points / נקודות חיבור
-- Safe baseline migration for roles, organization scoping, profile fields and assignments.
-- Review column names against an existing production schema before running on production.

CREATE TABLE IF NOT EXISTS organizations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NULL,
  role VARCHAR(40) NOT NULL DEFAULT 'volunteer',
  full_name VARCHAR(190) NOT NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  area VARCHAR(190) NULL,
  password_hash VARCHAR(255) NULL,
  notify_urgent_reports TINYINT(1) NOT NULL DEFAULT 1,
  notify_weekly_summary TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  INDEX idx_users_org_role (organization_id, role),
  UNIQUE KEY uniq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS organization_id INT NULL,
  ADD COLUMN IF NOT EXISTS role VARCHAR(40) NOT NULL DEFAULT 'volunteer',
  ADD COLUMN IF NOT EXISTS full_name VARCHAR(190) NULL,
  ADD COLUMN IF NOT EXISTS email VARCHAR(190) NULL,
  ADD COLUMN IF NOT EXISTS phone VARCHAR(40) NULL,
  ADD COLUMN IF NOT EXISTS area VARCHAR(190) NULL,
  ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS notify_urgent_reports TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS notify_weekly_summary TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS volunteers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  organization_id INT NULL,
  full_name VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(190) NULL,
  area VARCHAR(190) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_volunteers_user (user_id),
  INDEX idx_volunteers_org (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE volunteers
  ADD COLUMN IF NOT EXISTS user_id INT NULL,
  ADD COLUMN IF NOT EXISTS organization_id INT NULL,
  ADD COLUMN IF NOT EXISTS area VARCHAR(190) NULL;

CREATE TABLE IF NOT EXISTS elderly (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NULL,
  full_name VARCHAR(190) NULL,
  name VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  area VARCHAR(190) NULL,
  city VARCHAR(120) NULL,
  address VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_elderly_org (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE elderly
  ADD COLUMN IF NOT EXISTS organization_id INT NULL,
  ADD COLUMN IF NOT EXISTS area VARCHAR(190) NULL;

CREATE TABLE IF NOT EXISTS volunteer_elderly_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NULL,
  volunteer_id INT NOT NULL,
  elderly_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_assignment (volunteer_id, elderly_id),
  INDEX idx_assignment_org (organization_id),
  INDEX idx_assignment_elderly (elderly_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE reports
  ADD COLUMN IF NOT EXISTS organization_id INT NULL,
  ADD COLUMN IF NOT EXISTS urgency VARCHAR(20) NULL,
  ADD COLUMN IF NOT EXISTS status VARCHAR(40) NOT NULL DEFAULT 'הוגש',
  ADD COLUMN IF NOT EXISTS classification_source VARCHAR(40) NULL;

-- Optional performance indexes for existing reports tables:
-- CREATE INDEX idx_reports_org ON reports (organization_id);
-- CREATE INDEX idx_reports_volunteer ON reports (volunteer_id);
-- CREATE INDEX idx_reports_elderly ON reports (elderly_id);
-- CREATE INDEX idx_reports_status_urgency ON reports (status, urgency);

CREATE TABLE IF NOT EXISTS report_status_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  report_id INT NOT NULL,
  old_status VARCHAR(40) NULL,
  new_status VARCHAR(40) NOT NULL,
  changed_by INT NULL,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_report_status_history_report (report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
