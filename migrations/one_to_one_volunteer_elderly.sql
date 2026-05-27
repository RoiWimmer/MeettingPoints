-- Enforce one-to-one assignment between volunteers and elderly people.
-- Run after migrations/permissions_and_profile.sql and migrations/demo_accounts_and_auth.sql.

CREATE TABLE IF NOT EXISTS volunteer_elderly_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NULL,
  volunteer_id INT NULL,
  elderly_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_assignment_org (organization_id),
  INDEX idx_assignment_elderly (elderly_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE volunteer_elderly_assignments
  ADD COLUMN IF NOT EXISTS organization_id INT NULL,
  ADD COLUMN IF NOT EXISTS volunteer_id INT NULL,
  ADD COLUMN IF NOT EXISTS elderly_id INT NULL,
  ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

CREATE TEMPORARY TABLE mp_keep_assignment_by_volunteer AS
SELECT MIN(id) AS keep_id
FROM volunteer_elderly_assignments
WHERE volunteer_id IS NOT NULL
  AND elderly_id IS NOT NULL
GROUP BY volunteer_id;

DELETE vea
FROM volunteer_elderly_assignments vea
LEFT JOIN mp_keep_assignment_by_volunteer keep_rows ON keep_rows.keep_id = vea.id
WHERE vea.volunteer_id IS NOT NULL
  AND vea.elderly_id IS NOT NULL
  AND keep_rows.keep_id IS NULL;

DROP TEMPORARY TABLE mp_keep_assignment_by_volunteer;

CREATE TEMPORARY TABLE mp_keep_assignment_by_elderly AS
SELECT MIN(id) AS keep_id
FROM volunteer_elderly_assignments
WHERE volunteer_id IS NOT NULL
  AND elderly_id IS NOT NULL
GROUP BY elderly_id;

DELETE vea
FROM volunteer_elderly_assignments vea
LEFT JOIN mp_keep_assignment_by_elderly keep_rows ON keep_rows.keep_id = vea.id
WHERE vea.volunteer_id IS NOT NULL
  AND vea.elderly_id IS NOT NULL
  AND keep_rows.keep_id IS NULL;

DROP TEMPORARY TABLE mp_keep_assignment_by_elderly;

SET @schema_name := DATABASE();

SET @has_unique_volunteer_assignment := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = @schema_name
    AND table_name = 'volunteer_elderly_assignments'
    AND index_name = 'uniq_assignment_volunteer'
);

SET @add_unique_volunteer_assignment := IF(
  @has_unique_volunteer_assignment = 0,
  'ALTER TABLE volunteer_elderly_assignments ADD UNIQUE KEY uniq_assignment_volunteer (volunteer_id)',
  'SELECT 1'
);

PREPARE add_unique_volunteer_assignment_stmt FROM @add_unique_volunteer_assignment;
EXECUTE add_unique_volunteer_assignment_stmt;
DEALLOCATE PREPARE add_unique_volunteer_assignment_stmt;

SET @has_unique_elderly_assignment := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = @schema_name
    AND table_name = 'volunteer_elderly_assignments'
    AND index_name = 'uniq_assignment_elderly'
);

SET @add_unique_elderly_assignment := IF(
  @has_unique_elderly_assignment = 0,
  'ALTER TABLE volunteer_elderly_assignments ADD UNIQUE KEY uniq_assignment_elderly (elderly_id)',
  'SELECT 1'
);

PREPARE add_unique_elderly_assignment_stmt FROM @add_unique_elderly_assignment;
EXECUTE add_unique_elderly_assignment_stmt;
DEALLOCATE PREPARE add_unique_elderly_assignment_stmt;
