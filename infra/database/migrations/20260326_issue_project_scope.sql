USE web_test;

SET @schema_name = DATABASE();

SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'issues'
      AND COLUMN_NAME = 'project_id'
  ),
  'SELECT 1',
  'ALTER TABLE issues ADD COLUMN project_id INT(11) DEFAULT NULL AFTER org_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO projects (org_id, name, code, description, status, created_by, updated_by)
SELECT
  o.id,
  CONCAT(o.name, ' Main Project'),
  CONCAT('ORG-', o.id, '-MAIN'),
  'Auto-created to backfill issue project scope.',
  'active',
  o.owner_id,
  o.owner_id
FROM organizations o
WHERE EXISTS (
    SELECT 1
    FROM issues i
    WHERE i.org_id = o.id
  )
  AND NOT EXISTS (
    SELECT 1
    FROM projects p
    WHERE p.org_id = o.id
  );

UPDATE issues i
JOIN (
  SELECT org_id, MIN(id) AS project_id
  FROM projects
  WHERE status = 'active'
  GROUP BY org_id
) p ON p.org_id = i.org_id
SET i.project_id = p.project_id
WHERE i.project_id IS NULL OR i.project_id = 0;

UPDATE issues i
JOIN (
  SELECT org_id, MIN(id) AS project_id
  FROM projects
  GROUP BY org_id
) p ON p.org_id = i.org_id
SET i.project_id = p.project_id
WHERE i.project_id IS NULL OR i.project_id = 0;

SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'issues'
      AND INDEX_NAME = 'idx_issues_project'
  ),
  'SELECT 1',
  'ALTER TABLE issues ADD INDEX idx_issues_project (project_id)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (
    SELECT COUNT(*)
    FROM issues
    WHERE project_id IS NULL OR project_id = 0
  ) = 0,
  'ALTER TABLE issues MODIFY project_id INT(11) NOT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'issues'
      AND CONSTRAINT_NAME = 'fk_issues_project'
  ),
  'SELECT 1',
  'ALTER TABLE issues ADD CONSTRAINT fk_issues_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
