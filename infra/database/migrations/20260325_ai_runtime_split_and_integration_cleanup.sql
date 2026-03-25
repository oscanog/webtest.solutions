SET @legacy_chat_value = CONCAT('dis', 'cord');
SET @legacy_link_column = CONCAT(@legacy_chat_value, '_user_link_id');
SET @legacy_link_fk = CONCAT('fk_openclaw_requests_', @legacy_link_column);
SET @legacy_attachment_column = CONCAT(@legacy_chat_value, '_attachment_id');
SET @legacy_state_column = CONCAT(@legacy_chat_value, '_state');
SET @legacy_application_column = CONCAT(@legacy_chat_value, '_application_id');
SET @legacy_error_column = CONCAT('last_', @legacy_chat_value, '_error');
SET @legacy_token_column = CONCAT('encrypted_', @legacy_chat_value, '_bot_token');
SET @legacy_channel_bindings_table = CONCAT(@legacy_chat_value, '_channel_bindings');
SET @legacy_user_links_table = CONCAT(@legacy_chat_value, '_user_links');

SET @has_ai_runtime_config = (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ai_runtime_config'
);
SET @sql = IF(
  @has_ai_runtime_config = 0,
  "CREATE TABLE ai_runtime_config (
      id INT(11) NOT NULL AUTO_INCREMENT,
      is_enabled TINYINT(1) NOT NULL DEFAULT 1,
      default_provider_config_id INT(11) DEFAULT NULL,
      default_model_id INT(11) DEFAULT NULL,
      assistant_name VARCHAR(120) DEFAULT NULL,
      system_prompt TEXT DEFAULT NULL,
      created_by INT(11) NOT NULL,
      updated_by INT(11) DEFAULT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT NULL,
      PRIMARY KEY (id),
      KEY idx_ai_runtime_config_created_by (created_by),
      KEY idx_ai_runtime_config_updated_by (updated_by),
      KEY idx_ai_runtime_config_provider (default_provider_config_id),
      KEY idx_ai_runtime_config_model (default_model_id),
      CONSTRAINT fk_ai_runtime_config_created_by
        FOREIGN KEY (created_by) REFERENCES users(id),
      CONSTRAINT fk_ai_runtime_config_updated_by
        FOREIGN KEY (updated_by) REFERENCES users(id)
        ON DELETE SET NULL,
      CONSTRAINT fk_ai_runtime_config_provider
        FOREIGN KEY (default_provider_config_id) REFERENCES ai_provider_configs(id)
        ON DELETE SET NULL,
      CONSTRAINT fk_ai_runtime_config_model
        FOREIGN KEY (default_model_id) REFERENCES ai_models(id)
        ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
  "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_openclaw_runtime = (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'openclaw_runtime_config'
);
SET @has_openclaw_ai_columns = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'openclaw_runtime_config'
    AND COLUMN_NAME IN (
      'ai_chat_enabled',
      'ai_chat_default_provider_config_id',
      'ai_chat_default_model_id',
      'ai_chat_assistant_name',
      'ai_chat_system_prompt'
    )
);
SET @ai_runtime_empty = (
  SELECT COUNT(*)
  FROM ai_runtime_config
);
SET @sql = IF(
  @has_openclaw_runtime = 1 AND @has_openclaw_ai_columns = 5 AND @ai_runtime_empty = 0,
  "SELECT 1",
  IF(
    @has_openclaw_runtime = 1 AND @has_openclaw_ai_columns = 5,
    "INSERT INTO ai_runtime_config
        (
          is_enabled,
          default_provider_config_id,
          default_model_id,
          assistant_name,
          system_prompt,
          created_by,
          updated_by,
          created_at,
          updated_at
        )
      SELECT ai_chat_enabled,
             ai_chat_default_provider_config_id,
             ai_chat_default_model_id,
             ai_chat_assistant_name,
             ai_chat_system_prompt,
             created_by,
             updated_by,
             created_at,
             updated_at
      FROM openclaw_runtime_config
      ORDER BY id DESC
      LIMIT 1",
    "SELECT 1"
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_link_fk = (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'openclaw_requests'
    AND CONSTRAINT_NAME = @legacy_link_fk
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql = IF(
  @has_link_fk = 1,
  CONCAT('ALTER TABLE openclaw_requests DROP FOREIGN KEY ', @legacy_link_fk),
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_link_column = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'openclaw_requests'
    AND COLUMN_NAME = @legacy_link_column
);
SET @sql = IF(
  @has_link_column = 1,
  CONCAT('ALTER TABLE openclaw_requests DROP COLUMN ', @legacy_link_column),
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_request_attachment_old = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'openclaw_request_attachments'
    AND COLUMN_NAME = @legacy_attachment_column
);
SET @has_request_attachment_new = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'openclaw_request_attachments'
    AND COLUMN_NAME = 'source_attachment_id'
);
SET @sql = IF(
  @has_request_attachment_old = 1 AND @has_request_attachment_new = 0,
  CONCAT(
    'ALTER TABLE openclaw_request_attachments CHANGE COLUMN ',
    @legacy_attachment_column,
    ' source_attachment_id VARCHAR(64) DEFAULT NULL'
  ),
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_runtime_config_version = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'openclaw_runtime_status'
    AND COLUMN_NAME = 'config_version_applied'
);
SET @sql = IF(
  @has_runtime_config_version = 0,
  "ALTER TABLE openclaw_runtime_status ADD COLUMN config_version_applied VARCHAR(40) DEFAULT NULL AFTER id",
  "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_heartbeat_old = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'openclaw_runtime_status'
    AND COLUMN_NAME = 'heartbeat_at'
);
SET @has_heartbeat_new = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'openclaw_runtime_status'
    AND COLUMN_NAME = 'last_heartbeat_at'
);
SET @sql = IF(
  @has_heartbeat_old = 1 AND @has_heartbeat_new = 0,
  "ALTER TABLE openclaw_runtime_status CHANGE COLUMN heartbeat_at last_heartbeat_at DATETIME DEFAULT NULL",
  "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_provider_error_old = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'openclaw_runtime_status'
    AND COLUMN_NAME = 'last_error_message'
);
SET @has_provider_error_new = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'openclaw_runtime_status'
    AND COLUMN_NAME = 'last_provider_error'
);
SET @sql = IF(
  @has_provider_error_old = 1 AND @has_provider_error_new = 0,
  "ALTER TABLE openclaw_runtime_status CHANGE COLUMN last_error_message last_provider_error TEXT DEFAULT NULL",
  "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_integration_state_old = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'openclaw_runtime_status'
    AND COLUMN_NAME = @legacy_state_column
);
SET @has_integration_state_new = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'openclaw_runtime_status'
    AND COLUMN_NAME = 'integration_state'
);
SET @sql = IF(
  @has_integration_state_old = 1 AND @has_integration_state_new = 0,
  CONCAT(
    'ALTER TABLE openclaw_runtime_status CHANGE COLUMN ',
    @legacy_state_column,
    ' integration_state VARCHAR(40) NOT NULL DEFAULT ''unknown'''
  ),
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_application_old = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'openclaw_runtime_status'
    AND COLUMN_NAME = @legacy_application_column
);
SET @has_application_new = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'openclaw_runtime_status'
    AND COLUMN_NAME = 'integration_application_id'
);
SET @sql = IF(
  @has_application_old = 1 AND @has_application_new = 0,
  CONCAT(
    'ALTER TABLE openclaw_runtime_status CHANGE COLUMN ',
    @legacy_application_column,
    ' integration_application_id VARCHAR(64) DEFAULT NULL'
  ),
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_integration_error_old = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'openclaw_runtime_status'
    AND COLUMN_NAME = @legacy_error_column
);
SET @has_integration_error_new = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'openclaw_runtime_status'
    AND COLUMN_NAME = 'last_integration_error'
);
SET @sql = IF(
  @has_integration_error_old = 1 AND @has_integration_error_new = 0,
  CONCAT(
    'ALTER TABLE openclaw_runtime_status CHANGE COLUMN ',
    @legacy_error_column,
    ' last_integration_error TEXT DEFAULT NULL'
  ),
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_runtime_channel = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'checklist_batches'
    AND COLUMN_NAME = 'source_channel'
);
SET @sql = IF(
  @has_runtime_channel = 1,
  CONCAT(
    "ALTER TABLE checklist_batches MODIFY COLUMN source_channel ENUM('web', 'telegram', '",
    @legacy_chat_value,
    "', 'legacy_chat', 'api') NOT NULL DEFAULT 'web'"
  ),
  "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE checklist_batches
SET source_channel = 'legacy_chat'
WHERE source_channel = @legacy_chat_value;

SET @sql = IF(
  @has_runtime_channel = 1,
  "ALTER TABLE checklist_batches MODIFY COLUMN source_channel ENUM('web', 'telegram', 'legacy_chat', 'api') NOT NULL DEFAULT 'web'",
  "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_openclaw_bridge_token = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'openclaw_runtime_config'
    AND COLUMN_NAME = @legacy_token_column
);
SET @sql = IF(
  @has_openclaw_bridge_token = 1,
  CONCAT('ALTER TABLE openclaw_runtime_config DROP COLUMN ', @legacy_token_column),
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @drop_column = 'ai_chat_enabled';
SET @has_column = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'openclaw_runtime_config'
    AND COLUMN_NAME = @drop_column
);
SET @sql = IF(@has_column = 1, CONCAT('ALTER TABLE openclaw_runtime_config DROP COLUMN ', @drop_column), 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @drop_column = 'ai_chat_default_provider_config_id';
SET @has_column = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'openclaw_runtime_config'
    AND COLUMN_NAME = @drop_column
);
SET @sql = IF(@has_column = 1, CONCAT('ALTER TABLE openclaw_runtime_config DROP COLUMN ', @drop_column), 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @drop_column = 'ai_chat_default_model_id';
SET @has_column = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'openclaw_runtime_config'
    AND COLUMN_NAME = @drop_column
);
SET @sql = IF(@has_column = 1, CONCAT('ALTER TABLE openclaw_runtime_config DROP COLUMN ', @drop_column), 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @drop_column = 'ai_chat_assistant_name';
SET @has_column = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'openclaw_runtime_config'
    AND COLUMN_NAME = @drop_column
);
SET @sql = IF(@has_column = 1, CONCAT('ALTER TABLE openclaw_runtime_config DROP COLUMN ', @drop_column), 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @drop_column = 'ai_chat_system_prompt';
SET @has_column = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'openclaw_runtime_config'
    AND COLUMN_NAME = @drop_column
);
SET @sql = IF(@has_column = 1, CONCAT('ALTER TABLE openclaw_runtime_config DROP COLUMN ', @drop_column), 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_legacy_channel_bindings_table = (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @legacy_channel_bindings_table
);
SET @sql = IF(
  @has_legacy_channel_bindings_table = 1,
  CONCAT('DROP TABLE IF EXISTS ', @legacy_channel_bindings_table),
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_legacy_user_links_table = (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = @legacy_user_links_table
);
SET @sql = IF(
  @has_legacy_user_links_table = 1,
  CONCAT('DROP TABLE IF EXISTS ', @legacy_user_links_table),
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
