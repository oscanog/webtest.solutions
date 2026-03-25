USE bug_catcher;

SET @schema_name = DATABASE();

SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'issue_attachments'
      AND COLUMN_NAME = 'storage_key'
  ),
  'SELECT 1',
  'ALTER TABLE issue_attachments ADD COLUMN storage_key VARCHAR(255) DEFAULT NULL AFTER file_path'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'issue_attachments'
      AND COLUMN_NAME = 'storage_provider'
  ),
  'SELECT 1',
  'ALTER TABLE issue_attachments ADD COLUMN storage_provider VARCHAR(32) DEFAULT NULL AFTER storage_key'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'checklist_attachments'
      AND COLUMN_NAME = 'storage_key'
  ),
  'SELECT 1',
  'ALTER TABLE checklist_attachments ADD COLUMN storage_key VARCHAR(255) DEFAULT NULL AFTER file_path'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'checklist_attachments'
      AND COLUMN_NAME = 'storage_provider'
  ),
  'SELECT 1',
  'ALTER TABLE checklist_attachments ADD COLUMN storage_provider VARCHAR(32) DEFAULT NULL AFTER storage_key'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'checklist_batch_attachments'
      AND COLUMN_NAME = 'storage_key'
  ),
  'SELECT 1',
  'ALTER TABLE checklist_batch_attachments ADD COLUMN storage_key VARCHAR(255) DEFAULT NULL AFTER file_path'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'checklist_batch_attachments'
      AND COLUMN_NAME = 'storage_provider'
  ),
  'SELECT 1',
  'ALTER TABLE checklist_batch_attachments ADD COLUMN storage_provider VARCHAR(32) DEFAULT NULL AFTER storage_key'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'openclaw_request_attachments'
      AND COLUMN_NAME = 'storage_key'
  ),
  'SELECT 1',
  'ALTER TABLE openclaw_request_attachments ADD COLUMN storage_key VARCHAR(255) DEFAULT NULL AFTER file_path'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'openclaw_request_attachments'
      AND COLUMN_NAME = 'storage_provider'
  ),
  'SELECT 1',
  'ALTER TABLE openclaw_request_attachments ADD COLUMN storage_provider VARCHAR(32) DEFAULT NULL AFTER storage_key'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'ai_chat_message_attachments'
      AND COLUMN_NAME = 'storage_key'
  ),
  'SELECT 1',
  'ALTER TABLE ai_chat_message_attachments ADD COLUMN storage_key VARCHAR(255) DEFAULT NULL AFTER file_path'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'ai_chat_message_attachments'
      AND COLUMN_NAME = 'storage_provider'
  ),
  'SELECT 1',
  'ALTER TABLE ai_chat_message_attachments ADD COLUMN storage_provider VARCHAR(32) DEFAULT NULL AFTER storage_key'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE issue_attachments
SET storage_provider = CASE
  WHEN storage_key IS NULL OR TRIM(storage_key) = '' THEN NULL
  WHEN file_path LIKE '%res.cloudinary.com/%' OR file_path LIKE '%cloudinary.com/%' THEN 'cloudinary'
  WHEN file_path LIKE '%utfs.io/%' OR file_path LIKE '%ufs.sh/%' OR file_path LIKE '%uploadthing%' THEN 'uploadthing'
  ELSE storage_provider
END
WHERE storage_provider IS NULL OR storage_provider = '';

UPDATE checklist_attachments
SET storage_provider = CASE
  WHEN storage_key IS NULL OR TRIM(storage_key) = '' THEN NULL
  WHEN file_path LIKE '%res.cloudinary.com/%' OR file_path LIKE '%cloudinary.com/%' THEN 'cloudinary'
  WHEN file_path LIKE '%utfs.io/%' OR file_path LIKE '%ufs.sh/%' OR file_path LIKE '%uploadthing%' THEN 'uploadthing'
  ELSE storage_provider
END
WHERE storage_provider IS NULL OR storage_provider = '';

UPDATE checklist_batch_attachments
SET storage_provider = CASE
  WHEN storage_key IS NULL OR TRIM(storage_key) = '' THEN NULL
  WHEN file_path LIKE '%res.cloudinary.com/%' OR file_path LIKE '%cloudinary.com/%' THEN 'cloudinary'
  WHEN file_path LIKE '%utfs.io/%' OR file_path LIKE '%ufs.sh/%' OR file_path LIKE '%uploadthing%' THEN 'uploadthing'
  ELSE storage_provider
END
WHERE storage_provider IS NULL OR storage_provider = '';

UPDATE openclaw_request_attachments
SET storage_provider = CASE
  WHEN storage_key IS NULL OR TRIM(storage_key) = '' THEN NULL
  WHEN file_path LIKE '%res.cloudinary.com/%' OR file_path LIKE '%cloudinary.com/%' THEN 'cloudinary'
  WHEN file_path LIKE '%utfs.io/%' OR file_path LIKE '%ufs.sh/%' OR file_path LIKE '%uploadthing%' THEN 'uploadthing'
  ELSE storage_provider
END
WHERE storage_provider IS NULL OR storage_provider = '';

UPDATE ai_chat_message_attachments
SET storage_provider = CASE
  WHEN storage_key IS NULL OR TRIM(storage_key) = '' THEN NULL
  WHEN file_path LIKE '%res.cloudinary.com/%' OR file_path LIKE '%cloudinary.com/%' THEN 'cloudinary'
  WHEN file_path LIKE '%utfs.io/%' OR file_path LIKE '%ufs.sh/%' OR file_path LIKE '%uploadthing%' THEN 'uploadthing'
  ELSE storage_provider
END
WHERE storage_provider IS NULL OR storage_provider = '';
