USE bug_catcher;

ALTER TABLE users
  MODIFY COLUMN role ENUM('super_admin', 'admin', 'user') DEFAULT 'user';

CREATE TABLE IF NOT EXISTS checklist_batch_attachments (
  id INT(11) NOT NULL AUTO_INCREMENT,
  checklist_batch_id INT(11) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  file_size INT(11) NOT NULL,
  uploaded_by INT(11) DEFAULT NULL,
  source_type ENUM('manual', 'bot') NOT NULL DEFAULT 'bot',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_checklist_batch_attachments_batch (checklist_batch_id),
  KEY idx_checklist_batch_attachments_uploaded_by (uploaded_by),
  CONSTRAINT fk_checklist_batch_attachments_batch
    FOREIGN KEY (checklist_batch_id) REFERENCES checklist_batches(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_checklist_batch_attachments_uploaded_by
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS openclaw_runtime_config (
  id INT(11) NOT NULL AUTO_INCREMENT,
  is_enabled TINYINT(1) NOT NULL DEFAULT 0,
  default_provider_config_id INT(11) DEFAULT NULL,
  default_model_id INT(11) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_by INT(11) NOT NULL,
  updated_by INT(11) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_openclaw_runtime_config_created_by (created_by),
  KEY idx_openclaw_runtime_config_updated_by (updated_by),
  CONSTRAINT fk_openclaw_runtime_config_created_by
    FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_openclaw_runtime_config_updated_by
    FOREIGN KEY (updated_by) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS ai_provider_configs (
  id INT(11) NOT NULL AUTO_INCREMENT,
  provider_key VARCHAR(60) NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  provider_type VARCHAR(60) NOT NULL,
  base_url VARCHAR(255) DEFAULT NULL,
  encrypted_api_key TEXT DEFAULT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  supports_model_sync TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT(11) NOT NULL,
  updated_by INT(11) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_ai_provider_configs_provider_key (provider_key),
  CONSTRAINT fk_ai_provider_configs_created_by
    FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_ai_provider_configs_updated_by
    FOREIGN KEY (updated_by) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS ai_models (
  id INT(11) NOT NULL AUTO_INCREMENT,
  provider_config_id INT(11) NOT NULL,
  model_id VARCHAR(120) NOT NULL,
  display_name VARCHAR(160) NOT NULL,
  supports_vision TINYINT(1) NOT NULL DEFAULT 0,
  supports_json_output TINYINT(1) NOT NULL DEFAULT 1,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  last_synced_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_ai_models_provider_model (provider_config_id, model_id),
  KEY idx_ai_models_provider_default (provider_config_id, is_default),
  CONSTRAINT fk_ai_models_provider_config
    FOREIGN KEY (provider_config_id) REFERENCES ai_provider_configs(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS openclaw_requests (
  id INT(11) NOT NULL AUTO_INCREMENT,
  guild_id VARCHAR(64) DEFAULT NULL,
  channel_id VARCHAR(64) DEFAULT NULL,
  thread_id VARCHAR(64) DEFAULT NULL,
  started_message_id VARCHAR(64) DEFAULT NULL,
  selected_org_id INT(11) DEFAULT NULL,
  selected_project_id INT(11) DEFAULT NULL,
  requested_by_user_id INT(11) NOT NULL,
  status ENUM('collecting', 'waiting_for_image', 'waiting_for_org', 'waiting_for_project', 'analyzing', 'waiting_for_verification', 'waiting_for_duplicate_decision', 'ready_to_submit', 'submitted', 'cancelled', 'failed') NOT NULL DEFAULT 'collecting',
  current_step VARCHAR(80) DEFAULT NULL,
  provider_config_id INT(11) DEFAULT NULL,
  model_id INT(11) DEFAULT NULL,
  batch_title VARCHAR(255) DEFAULT NULL,
  module_name VARCHAR(160) DEFAULT NULL,
  submodule_name VARCHAR(160) DEFAULT NULL,
  request_summary TEXT DEFAULT NULL,
  bot_intro_sent_at DATETIME DEFAULT NULL,
  submitted_batch_id INT(11) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_openclaw_requests_status (status, updated_at),
  KEY idx_openclaw_requests_user (requested_by_user_id, created_at),
  CONSTRAINT fk_openclaw_requests_selected_org
    FOREIGN KEY (selected_org_id) REFERENCES organizations(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_openclaw_requests_selected_project
    FOREIGN KEY (selected_project_id) REFERENCES projects(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_openclaw_requests_requested_by_user
    FOREIGN KEY (requested_by_user_id) REFERENCES users(id),
  CONSTRAINT fk_openclaw_requests_provider_config
    FOREIGN KEY (provider_config_id) REFERENCES ai_provider_configs(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_openclaw_requests_model
    FOREIGN KEY (model_id) REFERENCES ai_models(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_openclaw_requests_submitted_batch
    FOREIGN KEY (submitted_batch_id) REFERENCES checklist_batches(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS openclaw_request_items (
  id INT(11) NOT NULL AUTO_INCREMENT,
  openclaw_request_id INT(11) NOT NULL,
  sequence_no INT(11) NOT NULL,
  title VARCHAR(255) NOT NULL,
  module_name VARCHAR(160) NOT NULL,
  submodule_name VARCHAR(160) DEFAULT NULL,
  description LONGTEXT DEFAULT NULL,
  required_role VARCHAR(60) NOT NULL DEFAULT 'QA Tester',
  priority VARCHAR(20) NOT NULL DEFAULT 'medium',
  duplicate_status ENUM('unique', 'possible_duplicate', 'confirmed_duplicate') NOT NULL DEFAULT 'unique',
  duplicate_summary TEXT DEFAULT NULL,
  duplicate_target_item_ids TEXT DEFAULT NULL,
  user_decision ENUM('pending', 'skip', 'add_again', 'reviewed_add_again') NOT NULL DEFAULT 'pending',
  final_include TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_openclaw_request_items_sequence (openclaw_request_id, sequence_no),
  KEY idx_openclaw_request_items_status (openclaw_request_id, duplicate_status, user_decision),
  CONSTRAINT fk_openclaw_request_items_request
    FOREIGN KEY (openclaw_request_id) REFERENCES openclaw_requests(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS openclaw_request_attachments (
  id INT(11) NOT NULL AUTO_INCREMENT,
  openclaw_request_id INT(11) NOT NULL,
  source_attachment_id VARCHAR(64) DEFAULT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  file_size INT(11) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  is_image TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_openclaw_request_attachments_request (openclaw_request_id),
  CONSTRAINT fk_openclaw_request_attachments_request
    FOREIGN KEY (openclaw_request_id) REFERENCES openclaw_requests(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
