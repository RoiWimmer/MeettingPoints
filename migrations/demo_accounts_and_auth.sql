-- Demo accounts and auth seed for Meetting_Points / נקודות חיבור.
-- Run after permissions_and_profile.sql, or on a schema that already has equivalent tables.
-- Passwords are bcrypt hashes compatible with PHP password_verify().

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
  username VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  area VARCHAR(190) NULL,
  password_hash VARCHAR(255) NULL,
  notify_urgent_reports TINYINT(1) NOT NULL DEFAULT 1,
  notify_weekly_summary TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  INDEX idx_users_org_role (organization_id, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS organization_id INT NULL,
  ADD COLUMN IF NOT EXISTS role VARCHAR(40) NOT NULL DEFAULT 'volunteer',
  ADD COLUMN IF NOT EXISTS full_name VARCHAR(190) NULL,
  ADD COLUMN IF NOT EXISTS email VARCHAR(190) NULL,
  ADD COLUMN IF NOT EXISTS username VARCHAR(190) NULL,
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
  ADD COLUMN IF NOT EXISTS full_name VARCHAR(190) NULL,
  ADD COLUMN IF NOT EXISTS phone VARCHAR(40) NULL,
  ADD COLUMN IF NOT EXISTS email VARCHAR(190) NULL,
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
  ADD COLUMN IF NOT EXISTS full_name VARCHAR(190) NULL,
  ADD COLUMN IF NOT EXISTS name VARCHAR(190) NULL,
  ADD COLUMN IF NOT EXISTS phone VARCHAR(40) NULL,
  ADD COLUMN IF NOT EXISTS area VARCHAR(190) NULL,
  ADD COLUMN IF NOT EXISTS city VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS address VARCHAR(255) NULL;

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

ALTER TABLE volunteer_elderly_assignments
  ADD COLUMN IF NOT EXISTS organization_id INT NULL,
  ADD COLUMN IF NOT EXISTS volunteer_id INT NULL,
  ADD COLUMN IF NOT EXISTS elderly_id INT NULL,
  ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

INSERT INTO organizations (name)
SELECT 'עמותת חיבורים - דמו'
WHERE NOT EXISTS (
  SELECT 1 FROM organizations WHERE name = 'עמותת חיבורים - דמו'
);

SET @demo_org_id := (
  SELECT id
  FROM organizations
  WHERE name = 'עמותת חיבורים - דמו'
  ORDER BY id ASC
  LIMIT 1
);

UPDATE users
SET
  organization_id = @demo_org_id,
  role = 'volunteer',
  full_name = 'יוסי מתנדב דמו',
  email = 'volunteer.demo@hiburim.local',
  username = 'volunteer.demo@hiburim.local',
  phone = '050-111-2222',
  area = 'חולון ובת ים',
  password_hash = '$2y$10$TsUu44isparwRCjTPXBBQeBE2GkgBfoHty6w5DsTu5HJF9NqkCY8e',
  notify_urgent_reports = 1,
  notify_weekly_summary = 1,
  updated_at = NOW()
WHERE email = 'volunteer.demo@hiburim.local'
   OR username = 'volunteer.demo@hiburim.local';

INSERT INTO users (
  organization_id,
  role,
  full_name,
  email,
  username,
  phone,
  area,
  password_hash,
  notify_urgent_reports,
  notify_weekly_summary,
  created_at
)
SELECT
  @demo_org_id,
  'volunteer',
  'יוסי מתנדב דמו',
  'volunteer.demo@hiburim.local',
  'volunteer.demo@hiburim.local',
  '050-111-2222',
  'חולון ובת ים',
  '$2y$10$TsUu44isparwRCjTPXBBQeBE2GkgBfoHty6w5DsTu5HJF9NqkCY8e',
  1,
  1,
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM users
  WHERE email = 'volunteer.demo@hiburim.local'
     OR username = 'volunteer.demo@hiburim.local'
);

UPDATE users
SET
  organization_id = @demo_org_id,
  role = 'manager',
  full_name = 'מיכל מנהלת דמו',
  email = 'manager.demo@hiburim.local',
  username = 'manager.demo@hiburim.local',
  phone = '050-333-4444',
  area = 'ניהול עמותה',
  password_hash = '$2y$10$Sd2VNCmLMxd0zPR.oYgKOexDm8duTcRTrHqTOSbUPpYP3E0NIUNWG',
  notify_urgent_reports = 1,
  notify_weekly_summary = 1,
  updated_at = NOW()
WHERE email = 'manager.demo@hiburim.local'
   OR username = 'manager.demo@hiburim.local';

INSERT INTO users (
  organization_id,
  role,
  full_name,
  email,
  username,
  phone,
  area,
  password_hash,
  notify_urgent_reports,
  notify_weekly_summary,
  created_at
)
SELECT
  @demo_org_id,
  'manager',
  'מיכל מנהלת דמו',
  'manager.demo@hiburim.local',
  'manager.demo@hiburim.local',
  '050-333-4444',
  'ניהול עמותה',
  '$2y$10$Sd2VNCmLMxd0zPR.oYgKOexDm8duTcRTrHqTOSbUPpYP3E0NIUNWG',
  1,
  1,
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM users
  WHERE email = 'manager.demo@hiburim.local'
     OR username = 'manager.demo@hiburim.local'
);

SET @volunteer_user_id := (
  SELECT id
  FROM users
  WHERE email = 'volunteer.demo@hiburim.local'
     OR username = 'volunteer.demo@hiburim.local'
  ORDER BY id ASC
  LIMIT 1
);

UPDATE volunteers
SET
  organization_id = @demo_org_id,
  full_name = 'יוסי מתנדב דמו',
  phone = '050-111-2222',
  email = 'volunteer.demo@hiburim.local',
  area = 'חולון ובת ים'
WHERE user_id = @volunteer_user_id;

INSERT INTO volunteers (
  user_id,
  organization_id,
  full_name,
  phone,
  email,
  area,
  created_at
)
SELECT
  @volunteer_user_id,
  @demo_org_id,
  'יוסי מתנדב דמו',
  '050-111-2222',
  'volunteer.demo@hiburim.local',
  'חולון ובת ים',
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM volunteers
  WHERE user_id = @volunteer_user_id
);

SET @demo_volunteer_id := (
  SELECT id
  FROM volunteers
  WHERE user_id = @volunteer_user_id
  ORDER BY id ASC
  LIMIT 1
);

UPDATE elderly
SET
  organization_id = @demo_org_id,
  full_name = 'שרה לוי דמו',
  name = 'שרה לוי דמו',
  phone = '03-555-0101',
  area = 'חולון',
  city = 'חולון',
  address = 'רחוב הדוגמה 10'
WHERE (full_name = 'שרה לוי דמו' OR name = 'שרה לוי דמו')
  AND organization_id = @demo_org_id;

INSERT INTO elderly (
  organization_id,
  full_name,
  name,
  phone,
  area,
  city,
  address,
  created_at
)
SELECT
  @demo_org_id,
  'שרה לוי דמו',
  'שרה לוי דמו',
  '03-555-0101',
  'חולון',
  'חולון',
  'רחוב הדוגמה 10',
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM elderly
  WHERE organization_id = @demo_org_id
    AND (full_name = 'שרה לוי דמו' OR name = 'שרה לוי דמו')
);

SET @demo_elderly_id := (
  SELECT id
  FROM elderly
  WHERE organization_id = @demo_org_id
    AND (full_name = 'שרה לוי דמו' OR name = 'שרה לוי דמו')
  ORDER BY id ASC
  LIMIT 1
);

UPDATE volunteer_elderly_assignments
SET organization_id = @demo_org_id
WHERE volunteer_id = @demo_volunteer_id
  AND elderly_id = @demo_elderly_id;

DELETE FROM volunteer_elderly_assignments
WHERE (volunteer_id = @demo_volunteer_id OR elderly_id = @demo_elderly_id)
  AND NOT (volunteer_id = @demo_volunteer_id AND elderly_id = @demo_elderly_id);

INSERT INTO volunteer_elderly_assignments (
  organization_id,
  volunteer_id,
  elderly_id,
  created_at
)
SELECT
  @demo_org_id,
  @demo_volunteer_id,
  @demo_elderly_id,
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM volunteer_elderly_assignments
  WHERE volunteer_id = @demo_volunteer_id
    AND elderly_id = @demo_elderly_id
);
