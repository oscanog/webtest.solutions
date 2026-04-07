USE web_test;

SET @schema_name = DATABASE();

SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'issues'
      AND COLUMN_NAME = 'workflow_status'
  ),
  'SELECT 1',
  "ALTER TABLE issues ADD COLUMN workflow_status ENUM('unassigned','with_senior','with_junior','done_by_junior','with_qa','with_senior_qa','with_qa_lead','approved','rejected','closed') NOT NULL DEFAULT 'unassigned' AFTER assigned_dev_id"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE issues
SET workflow_status = CASE
  WHEN COALESCE(NULLIF(TRIM(assign_status), ''), '') IN (
    'unassigned',
    'with_senior',
    'with_junior',
    'done_by_junior',
    'with_qa',
    'with_senior_qa',
    'with_qa_lead',
    'approved',
    'rejected',
    'closed'
  ) THEN TRIM(assign_status)
  WHEN COALESCE(status, '') = 'closed' THEN 'closed'
  ELSE 'unassigned'
END
WHERE workflow_status IS NULL
   OR workflow_status = ''
   OR workflow_status = 'unassigned';

SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'issues'
      AND INDEX_NAME = 'idx_issues_assign_status'
  ),
  'ALTER TABLE issues DROP INDEX idx_issues_assign_status',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'issues'
      AND INDEX_NAME = 'idx_issues_workflow_status'
  ),
  'SELECT 1',
  'ALTER TABLE issues ADD INDEX idx_issues_workflow_status (workflow_status)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'issues'
      AND INDEX_NAME = 'idx_issues_assigned_junior'
  ),
  'SELECT 1',
  'ALTER TABLE issues ADD INDEX idx_issues_assigned_junior (assigned_junior_id)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'issues'
      AND INDEX_NAME = 'idx_issues_assigned_senior_qa'
  ),
  'SELECT 1',
  'ALTER TABLE issues ADD INDEX idx_issues_assigned_senior_qa (assigned_senior_qa_id)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'issues'
      AND INDEX_NAME = 'idx_issues_assigned_qa_lead'
  ),
  'SELECT 1',
  'ALTER TABLE issues ADD INDEX idx_issues_assigned_qa_lead (assigned_qa_lead_id)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'issues'
      AND INDEX_NAME = 'idx_issues_pm_id'
  ),
  'SELECT 1',
  'ALTER TABLE issues ADD INDEX idx_issues_pm_id (pm_id)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'issues'
      AND COLUMN_NAME = 'status'
  ),
  'ALTER TABLE issues DROP COLUMN status',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'issues'
      AND COLUMN_NAME = 'assign_status'
  ),
  'ALTER TABLE issues DROP COLUMN assign_status',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
