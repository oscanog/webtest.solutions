-- -----------------------------------------------------------------------------
-- BugCatcher Local Development Bootstrap (MySQL)
-- One-shot import for local development.
--
-- Usage:
--   mysql -u root -p < local_dev_full.sql
--
-- Default login users are seeded under organization: GJC_team
-- Shared password for all seeded users: BugCatcherProd!20260323
-- Note: org_members allows only one role per user per organization, so
-- 52310851@gendejesus.edu.ph is seeded as Project Manager only.
-- -----------------------------------------------------------------------------

SET NAMES utf8mb4;
SET time_zone = '+00:00';

DROP DATABASE IF EXISTS bug_catcher;
CREATE DATABASE IF NOT EXISTS bug_catcher
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;

USE bug_catcher;

CREATE TABLE IF NOT EXISTS users (
  id INT(11) NOT NULL AUTO_INCREMENT,
  username VARCHAR(100) NOT NULL,
  email VARCHAR(255) NOT NULL,
  password VARCHAR(255) NOT NULL,
  role ENUM('super_admin', 'admin', 'user') DEFAULT 'user',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_active_org_id INT(11) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_users_username (username),
  UNIQUE KEY uniq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS password_reset_requests (
  id INT(11) NOT NULL AUTO_INCREMENT,
  user_id INT(11) NOT NULL,
  otp_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  verify_attempt_count INT(11) NOT NULL DEFAULT 0,
  resend_count INT(11) NOT NULL DEFAULT 0,
  last_sent_at DATETIME NOT NULL,
  verified_at DATETIME DEFAULT NULL,
  used_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_password_reset_requests_user_active (user_id, used_at, verified_at, expires_at),
  KEY idx_password_reset_requests_expires (expires_at),
  CONSTRAINT fk_password_reset_requests_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS organizations (
  id INT(11) NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  owner_id INT(11) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_organizations_owner (owner_id),
  CONSTRAINT fk_org_owner
    FOREIGN KEY (owner_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS org_members (
  org_id INT(11) NOT NULL,
  user_id INT(11) NOT NULL,
  role ENUM(
    'owner',
    'member',
    'Project Manager',
    'QA Lead',
    'Senior Developer',
    'Senior QA',
    'Junior Developer',
    'QA Tester'
  ) NOT NULL DEFAULT 'member',
  joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (org_id, user_id),
  UNIQUE KEY uniq_org_user (org_id, user_id),
  KEY idx_org_members_user (user_id),
  CONSTRAINT fk_org_members_org
    FOREIGN KEY (org_id) REFERENCES organizations(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_org_members_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS labels (
  id INT(11) NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  color VARCHAR(20) DEFAULT '#cccccc',
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS issues (
  id INT(11) NOT NULL AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  description TEXT DEFAULT NULL,
  author_id INT(11) DEFAULT NULL,
  org_id INT(11) NOT NULL,
  assigned_dev_id INT(11) DEFAULT NULL,
  workflow_status ENUM('unassigned','with_senior','with_junior','done_by_junior','with_qa','with_senior_qa','with_qa_lead','approved','rejected','closed') NOT NULL DEFAULT 'unassigned',
  assigned_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  assigned_junior_id INT(11) DEFAULT NULL,
  assigned_qa_id INT(11) DEFAULT NULL,
  assigned_senior_qa_id INT(11) DEFAULT NULL,
  assigned_qa_lead_id INT(11) DEFAULT NULL,
  junior_assigned_at DATETIME DEFAULT NULL,
  qa_assigned_at DATETIME DEFAULT NULL,
  senior_qa_assigned_at DATETIME DEFAULT NULL,
  qa_lead_assigned_at DATETIME DEFAULT NULL,
  junior_done_at DATETIME DEFAULT NULL,
  pm_id INT(11) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_issues_author (author_id),
  KEY idx_issues_org (org_id),
  KEY idx_issues_assigned_dev (assigned_dev_id),
  KEY idx_issues_assigned_qa_id (assigned_qa_id),
  KEY idx_issues_assigned_junior (assigned_junior_id),
  KEY idx_issues_assigned_senior_qa (assigned_senior_qa_id),
  KEY idx_issues_assigned_qa_lead (assigned_qa_lead_id),
  KEY idx_issues_pm_id (pm_id),
  KEY idx_issues_workflow_status (workflow_status),
  CONSTRAINT fk_issues_author
    FOREIGN KEY (author_id) REFERENCES users(id),
  CONSTRAINT fk_issues_org
    FOREIGN KEY (org_id) REFERENCES organizations(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS projects (
  id INT(11) NOT NULL AUTO_INCREMENT,
  org_id INT(11) NOT NULL,
  name VARCHAR(160) NOT NULL,
  code VARCHAR(80) DEFAULT NULL,
  description TEXT DEFAULT NULL,
  status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
  created_by INT(11) NOT NULL,
  updated_by INT(11) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_projects_org_name (org_id, name),
  UNIQUE KEY uniq_projects_org_code (org_id, code),
  KEY idx_projects_org_status (org_id, status),
  KEY idx_projects_created_by (created_by),
  KEY idx_projects_updated_by (updated_by),
  CONSTRAINT fk_projects_org
    FOREIGN KEY (org_id) REFERENCES organizations(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_projects_created_by
    FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_projects_updated_by
    FOREIGN KEY (updated_by) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS checklist_batches (
  id INT(11) NOT NULL AUTO_INCREMENT,
  org_id INT(11) NOT NULL,
  project_id INT(11) NOT NULL,
  title VARCHAR(255) NOT NULL,
  module_name VARCHAR(160) NOT NULL,
  submodule_name VARCHAR(160) DEFAULT NULL,
  source_type ENUM('manual', 'bot') NOT NULL DEFAULT 'manual',
  source_channel ENUM('web', 'telegram', 'legacy_chat', 'api') NOT NULL DEFAULT 'web',
  source_reference VARCHAR(255) DEFAULT NULL,
  status ENUM('draft', 'open', 'completed', 'archived') NOT NULL DEFAULT 'open',
  created_by INT(11) NOT NULL,
  updated_by INT(11) DEFAULT NULL,
  assigned_qa_lead_id INT(11) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  page_url VARCHAR(2048) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_checklist_batches_project_status (project_id, status),
  KEY idx_checklist_batches_org_module (org_id, module_name),
  KEY idx_checklist_batches_source (source_type, source_channel),
  KEY idx_checklist_batches_created_by (created_by),
  KEY idx_checklist_batches_updated_by (updated_by),
  KEY idx_checklist_batches_qa_lead (assigned_qa_lead_id),
  CONSTRAINT fk_checklist_batches_org
    FOREIGN KEY (org_id) REFERENCES organizations(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_checklist_batches_project
    FOREIGN KEY (project_id) REFERENCES projects(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_checklist_batches_created_by
    FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_checklist_batches_updated_by
    FOREIGN KEY (updated_by) REFERENCES users(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_checklist_batches_qa_lead
    FOREIGN KEY (assigned_qa_lead_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS checklist_items (
  id INT(11) NOT NULL AUTO_INCREMENT,
  batch_id INT(11) NOT NULL,
  org_id INT(11) NOT NULL,
  project_id INT(11) NOT NULL,
  sequence_no INT(11) NOT NULL,
  title VARCHAR(255) NOT NULL,
  module_name VARCHAR(160) NOT NULL,
  submodule_name VARCHAR(160) DEFAULT NULL,
  full_title VARCHAR(255) NOT NULL,
  description LONGTEXT DEFAULT NULL,
  status ENUM('open', 'in_progress', 'passed', 'failed', 'blocked') NOT NULL DEFAULT 'open',
  priority ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
  required_role ENUM(
    'QA Lead',
    'Senior QA',
    'QA Tester',
    'Project Manager',
    'Senior Developer',
    'Junior Developer',
    'member',
    'owner'
  ) NOT NULL DEFAULT 'QA Tester',
  assigned_to_user_id INT(11) DEFAULT NULL,
  created_by INT(11) NOT NULL,
  updated_by INT(11) DEFAULT NULL,
  issue_id INT(11) DEFAULT NULL,
  started_at DATETIME DEFAULT NULL,
  completed_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_checklist_items_batch_sequence (batch_id, sequence_no),
  KEY idx_checklist_items_project_status (project_id, status),
  KEY idx_checklist_items_org_assignee_status (org_id, assigned_to_user_id, status),
  KEY idx_checklist_items_required_role_status (required_role, status),
  KEY idx_checklist_items_issue (issue_id),
  KEY idx_checklist_items_created_by (created_by),
  KEY idx_checklist_items_updated_by (updated_by),
  CONSTRAINT fk_checklist_items_batch
    FOREIGN KEY (batch_id) REFERENCES checklist_batches(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_checklist_items_org
    FOREIGN KEY (org_id) REFERENCES organizations(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_checklist_items_project
    FOREIGN KEY (project_id) REFERENCES projects(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_checklist_items_assigned_to
    FOREIGN KEY (assigned_to_user_id) REFERENCES users(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_checklist_items_created_by
    FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_checklist_items_updated_by
    FOREIGN KEY (updated_by) REFERENCES users(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_checklist_items_issue
    FOREIGN KEY (issue_id) REFERENCES issues(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS checklist_attachments (
  id INT(11) NOT NULL AUTO_INCREMENT,
  checklist_item_id INT(11) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  storage_key VARCHAR(255) DEFAULT NULL,
  storage_provider VARCHAR(32) DEFAULT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  file_size INT(11) NOT NULL,
  uploaded_by INT(11) DEFAULT NULL,
  source_type ENUM('manual', 'bot') NOT NULL DEFAULT 'manual',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_checklist_attachments_item (checklist_item_id),
  KEY idx_checklist_attachments_uploaded_by (uploaded_by),
  CONSTRAINT fk_checklist_attachments_item
    FOREIGN KEY (checklist_item_id) REFERENCES checklist_items(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_checklist_attachments_uploaded_by
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS checklist_batch_attachments (
  id INT(11) NOT NULL AUTO_INCREMENT,
  checklist_batch_id INT(11) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  storage_key VARCHAR(255) DEFAULT NULL,
  storage_provider VARCHAR(32) DEFAULT NULL,
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

CREATE TABLE IF NOT EXISTS openclaw_control_plane_state (
  id INT(11) NOT NULL,
  config_version VARCHAR(60) NOT NULL,
  last_runtime_reload_requested_at DATETIME DEFAULT NULL,
  last_runtime_reload_requested_by INT(11) DEFAULT NULL,
  last_runtime_reload_reason VARCHAR(120) DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_openclaw_control_plane_requested_by
    FOREIGN KEY (last_runtime_reload_requested_by) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS openclaw_runtime_status (
  id INT(11) NOT NULL,
  config_version_applied VARCHAR(40) DEFAULT NULL,
  gateway_state VARCHAR(40) NOT NULL DEFAULT 'unknown',
  integration_state VARCHAR(40) NOT NULL DEFAULT 'unknown',
  integration_application_id VARCHAR(64) DEFAULT NULL,
  last_heartbeat_at DATETIME DEFAULT NULL,
  last_reload_at DATETIME DEFAULT NULL,
  last_provider_error TEXT DEFAULT NULL,
  last_integration_error TEXT DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS openclaw_reload_requests (
  id INT(11) NOT NULL AUTO_INCREMENT,
  requested_by_user_id INT(11) DEFAULT NULL,
  reason VARCHAR(120) DEFAULT NULL,
  status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME DEFAULT NULL,
  error_message TEXT DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_openclaw_reload_requests_status (status, requested_at),
  CONSTRAINT fk_openclaw_reload_requests_requested_by
    FOREIGN KEY (requested_by_user_id) REFERENCES users(id)
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

CREATE TABLE IF NOT EXISTS ai_runtime_config (
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
  storage_key VARCHAR(255) DEFAULT NULL,
  storage_provider VARCHAR(32) DEFAULT NULL,
  is_image TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_openclaw_request_attachments_request (openclaw_request_id),
  CONSTRAINT fk_openclaw_request_attachments_request
    FOREIGN KEY (openclaw_request_id) REFERENCES openclaw_requests(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS issue_labels (
  issue_id INT(11) NOT NULL,
  label_id INT(11) NOT NULL,
  PRIMARY KEY (issue_id, label_id),
  KEY idx_issue_labels_label (label_id),
  CONSTRAINT fk_issue_labels_issue
    FOREIGN KEY (issue_id) REFERENCES issues(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_issue_labels_label
    FOREIGN KEY (label_id) REFERENCES labels(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS issue_attachments (
  id INT(11) NOT NULL AUTO_INCREMENT,
  issue_id INT(11) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  storage_key VARCHAR(255) DEFAULT NULL,
  storage_provider VARCHAR(32) DEFAULT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  file_size INT(11) NOT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_issue_attachments_issue (issue_id),
  CONSTRAINT fk_issue_attachments_issue
    FOREIGN KEY (issue_id) REFERENCES issues(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS ai_chat_threads (
  id INT(11) NOT NULL AUTO_INCREMENT,
  org_id INT(11) NOT NULL,
  user_id INT(11) NOT NULL,
  title VARCHAR(160) NOT NULL DEFAULT 'New chat',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL,
  last_message_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_ai_chat_threads_owner (user_id, org_id, updated_at),
  CONSTRAINT fk_ai_chat_threads_org
    FOREIGN KEY (org_id) REFERENCES organizations(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_ai_chat_threads_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS ai_chat_messages (
  id INT(11) NOT NULL AUTO_INCREMENT,
  thread_id INT(11) NOT NULL,
  role ENUM('user', 'assistant', 'system') NOT NULL,
  content LONGTEXT DEFAULT NULL,
  status ENUM('pending', 'streaming', 'completed', 'failed') NOT NULL DEFAULT 'completed',
  error_message TEXT DEFAULT NULL,
  provider_config_id INT(11) DEFAULT NULL,
  model_id INT(11) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_ai_chat_messages_thread (thread_id, id),
  CONSTRAINT fk_ai_chat_messages_thread
    FOREIGN KEY (thread_id) REFERENCES ai_chat_threads(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_ai_chat_messages_provider
    FOREIGN KEY (provider_config_id) REFERENCES ai_provider_configs(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_ai_chat_messages_model
    FOREIGN KEY (model_id) REFERENCES ai_models(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS ai_chat_message_attachments (
  id INT(11) NOT NULL AUTO_INCREMENT,
  message_id INT(11) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  storage_key VARCHAR(255) DEFAULT NULL,
  storage_provider VARCHAR(32) DEFAULT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  file_size INT(11) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ai_chat_message_attachments_message (message_id),
  CONSTRAINT fk_ai_chat_message_attachments_message
    FOREIGN KEY (message_id) REFERENCES ai_chat_messages(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id INT(11) NOT NULL AUTO_INCREMENT,
  recipient_user_id INT(11) NOT NULL,
  actor_user_id INT(11) DEFAULT NULL,
  org_id INT(11) DEFAULT NULL,
  project_id INT(11) DEFAULT NULL,
  issue_id INT(11) DEFAULT NULL,
  checklist_batch_id INT(11) DEFAULT NULL,
  checklist_item_id INT(11) DEFAULT NULL,
  type ENUM('issue', 'org', 'project', 'checklist', 'system') NOT NULL DEFAULT 'system',
  event_key VARCHAR(80) NOT NULL,
  title VARCHAR(255) NOT NULL,
  body TEXT DEFAULT NULL,
  link_path VARCHAR(255) NOT NULL,
  severity ENUM('default', 'success', 'alert') NOT NULL DEFAULT 'default',
  meta_json LONGTEXT DEFAULT NULL,
  read_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_recipient_read (recipient_user_id, read_at, created_at),
  KEY idx_notifications_org (org_id, created_at),
  KEY idx_notifications_project (project_id, created_at),
  KEY idx_notifications_issue (issue_id, created_at),
  KEY idx_notifications_batch (checklist_batch_id, created_at),
  CONSTRAINT fk_notifications_recipient
    FOREIGN KEY (recipient_user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_notifications_actor
    FOREIGN KEY (actor_user_id) REFERENCES users(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_notifications_org
    FOREIGN KEY (org_id) REFERENCES organizations(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_notifications_project
    FOREIGN KEY (project_id) REFERENCES projects(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_notifications_issue
    FOREIGN KEY (issue_id) REFERENCES issues(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_notifications_batch
    FOREIGN KEY (checklist_batch_id) REFERENCES checklist_batches(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_notifications_item
    FOREIGN KEY (checklist_item_id) REFERENCES checklist_items(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS contact (
  id INT(11) NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  message VARCHAR(255) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

USE bug_catcher;

INSERT INTO labels (id, name, description, color) VALUES
  (1, 'bug', 'Something is not working', '#d73a4a'),
  (2, 'documentation', 'Improvements or additions to documentation', '#0075ca'),
  (3, 'duplicate', 'This issue already exists', '#cfd3d7'),
  (4, 'enhancement', 'New feature or request', '#a2eeef'),
  (5, 'good first issue', 'Good for newcomers', '#7057ff'),
  (6, 'help wanted', 'Extra attention is needed', '#008672'),
  (7, 'invalid', 'This does not seem right', '#e4e669'),
  (8, 'question', 'Further information is requested', '#d876e3'),
  (9, 'wontfix', 'This will not be worked on', '#000000');

-- bcrypt hash for: BugCatcherProd!20260323
INSERT INTO users (id, username, email, password, role, last_active_org_id) VALUES
  (1, 'm.viner001', 'm.viner001@gmail.com', '$2y$10$dSBKjvwGygMql4uX6I5MS.my.ABsX2C3KZ25C9Fmuli3YHNYmw.li', 'super_admin', 1),
  (2, 'mackrafanan9247', 'mackrafanan9247@gmail.com', '$2y$10$dSBKjvwGygMql4uX6I5MS.my.ABsX2C3KZ25C9Fmuli3YHNYmw.li', 'admin', 1),
  (3, '52310851', '52310851@gendejesus.edu.ph', '$2y$10$dSBKjvwGygMql4uX6I5MS.my.ABsX2C3KZ25C9Fmuli3YHNYmw.li', 'user', 1),
  (4, '52310826', '52310826@gendejesus.edu.ph', '$2y$10$dSBKjvwGygMql4uX6I5MS.my.ABsX2C3KZ25C9Fmuli3YHNYmw.li', 'user', 1),
  (5, 'oscar.nogoy08', 'oscar.nogoy08@gmail.com', '$2y$10$dSBKjvwGygMql4uX6I5MS.my.ABsX2C3KZ25C9Fmuli3YHNYmw.li', 'user', 1),
  (6, '52310085', '52310085@gendejesus.edu.ph', '$2y$10$dSBKjvwGygMql4uX6I5MS.my.ABsX2C3KZ25C9Fmuli3YHNYmw.li', 'user', 1),
  (7, '52310225', '52310225@gendejesus.edu.ph', '$2y$10$dSBKjvwGygMql4uX6I5MS.my.ABsX2C3KZ25C9Fmuli3YHNYmw.li', 'user', 1),
  (8, 'emmanuelmagnosulit', 'emmanuelmagnosulit@gmail.com', '$2y$10$dSBKjvwGygMql4uX6I5MS.my.ABsX2C3KZ25C9Fmuli3YHNYmw.li', 'user', 1),
  (9, '52311077', '52311077@gendejesus.edu.ph', '$2y$10$dSBKjvwGygMql4uX6I5MS.my.ABsX2C3KZ25C9Fmuli3YHNYmw.li', 'user', 1),
  (10, '32212218', '32212218@gendejesus.edu.ph', '$2y$10$dSBKjvwGygMql4uX6I5MS.my.ABsX2C3KZ25C9Fmuli3YHNYmw.li', 'user', 1),
  (11, '52310668', '52310668@gendejesus.edu.ph', '$2y$10$dSBKjvwGygMql4uX6I5MS.my.ABsX2C3KZ25C9Fmuli3YHNYmw.li', 'user', 1),
  (12, 'gemmueldelacruz', 'gemmueldelacruz@gmail.com', '$2y$10$dSBKjvwGygMql4uX6I5MS.my.ABsX2C3KZ25C9Fmuli3YHNYmw.li', 'user', 1),
  (13, 'bulletlangto', 'bulletlangto@gmail.com', '$2y$10$dSBKjvwGygMql4uX6I5MS.my.ABsX2C3KZ25C9Fmuli3YHNYmw.li', 'user', 1);

INSERT INTO organizations (id, name, owner_id) VALUES
  (1, 'GJC_team', 1),
  (2, 'Stark Industries', 1),
  (3, 'Wayne Enterprises', 1),
  (4, 'Oscorp', 1);

INSERT INTO org_members (org_id, user_id, role) VALUES
  (1, 1, 'owner'),
  (1, 2, 'member'),
  (1, 3, 'Project Manager'),
  (1, 4, 'Senior Developer'),
  (1, 5, 'Junior Developer'),
  (1, 6, 'QA Tester'),
  (1, 7, 'Senior QA'),
  (1, 8, 'QA Lead'),
  (1, 9, 'QA Tester'),
  (1, 10, 'QA Tester'),
  (1, 11, 'QA Tester'),
  (1, 12, 'Senior Developer'),
  (1, 13, 'Junior Developer'),
  (2, 1, 'owner'),
  (3, 1, 'owner'),
  (4, 1, 'owner');

INSERT INTO projects (id, org_id, name, code, description, status, created_by, updated_by) VALUES
  (1, 1, 'Website Revamp', 'WEB-REVAMP', 'Primary local dev project', 'active', 3, 3),
  (2, 1, 'Mobile QA Sweep', 'MOBILE-QA', 'Secondary local dev project', 'active', 3, 3);

INSERT INTO issues (
  id, title, description, author_id, org_id,
  assigned_dev_id, assigned_junior_id, assigned_qa_id, assigned_senior_qa_id, assigned_qa_lead_id,
  workflow_status, pm_id, assigned_at, junior_assigned_at, junior_done_at, qa_assigned_at, senior_qa_assigned_at, qa_lead_assigned_at
) VALUES
  (
    1,
    'Login page validation fails on empty submit',
    'Submit button allows request with empty payload in some browsers.',
    3,
    1,
    4,
    5,
    6,
    7,
    8,
    'with_qa_lead',
    3,
    '2026-03-01 09:00:00',
    '2026-03-01 10:00:00',
    '2026-03-01 11:00:00',
    '2026-03-01 12:00:00',
    '2026-03-01 13:00:00',
    '2026-03-01 14:00:00'
  ),
  (
    2,
    'Dashboard sort icon overlaps text on narrow cards',
    'Visual issue visible on smaller laptop widths.',
    3,
    1,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    'unassigned',
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL
  );

INSERT INTO issue_labels (issue_id, label_id) VALUES
  (1, 1),
  (1, 6),
  (2, 4),
  (2, 8);

INSERT INTO checklist_batches (
  id, org_id, project_id, title, module_name, submodule_name,
  source_type, source_channel, status, created_by, updated_by, assigned_qa_lead_id, notes
) VALUES
  (
    1,
    1,
    1,
    'Authentication Smoke Run',
    'Authentication',
    'Login',
    'manual',
    'web',
    'open',
    3,
    3,
    8,
    'Local seed batch for QA workflow testing.'
  );

INSERT INTO checklist_items (
  id, batch_id, org_id, project_id, sequence_no, title, module_name, submodule_name, full_title,
  description, status, priority, required_role, assigned_to_user_id, created_by, updated_by, issue_id
) VALUES
  (
    1,
    1,
    1,
    1,
    1,
    'Valid user can log in',
    'Authentication',
    'Login',
    'Authentication / Login / Valid user can log in',
    'Use valid seeded account and verify redirect to dashboard.',
    'in_progress',
    'high',
    'QA Tester',
    6,
    3,
    6,
    NULL
  ),
  (
    2,
    1,
    1,
    1,
    2,
    'Empty form is blocked',
    'Authentication',
    'Login',
    'Authentication / Login / Empty form is blocked',
    'Submit empty login form and verify inline validation.',
    'failed',
    'medium',
    'QA Tester',
    6,
    3,
    6,
    1
  );

-- Keep OpenClaw runtime off by default in local dev.
INSERT INTO openclaw_runtime_config (
  id, is_enabled, default_provider_config_id, default_model_id, notes, created_by, updated_by
) VALUES (
  1, 0, NULL, NULL, 'Local dev bootstrap default', 1, 1
);

INSERT INTO ai_runtime_config (
  id, is_enabled, default_provider_config_id, default_model_id, assistant_name, system_prompt, created_by, updated_by
) VALUES (
  1, 1, NULL, NULL,
  'BugCatcher AI', 'You are BugCatcher AI. Help the team discuss bugs, tests, checklists, and project delivery clearly and practically.',
  1, 1
);

INSERT INTO openclaw_control_plane_state (
  id, config_version, last_runtime_reload_requested_at, last_runtime_reload_requested_by, last_runtime_reload_reason
) VALUES (
  1, 'v1', NULL, NULL, NULL
);

INSERT INTO openclaw_runtime_status (
  id, config_version_applied, gateway_state, integration_state, integration_application_id, last_heartbeat_at, last_reload_at, last_provider_error, last_integration_error
) VALUES (
  1, 'v1', 'idle', 'offline', NULL, NULL, NULL, NULL, NULL
);

INSERT INTO notifications (
  id, recipient_user_id, actor_user_id, org_id, project_id, issue_id, checklist_batch_id, checklist_item_id,
  type, event_key, title, body, link_path, severity, meta_json, read_at, created_at
) VALUES
  (
    1, 3, 8, 1, 1, 1, NULL, NULL,
    'issue', 'issue_qa_lead_review_ready',
    'Issue ready for PM review',
    'Login page validation fails on empty submit is ready for Project Manager closure.',
    '/app/reports/1', 'alert',
    JSON_OBJECT('issue_id', 1, 'org_id', 1, 'project_id', 1), NULL, '2026-03-21 10:30:00'
  ),
  (
    2, 1, 3, 1, 1, NULL, 1, NULL,
    'checklist', 'checklist_batch_updated',
    'Checklist batch updated',
    'Authentication Smoke Run was updated for local QA verification.',
    '/app/checklist/batches/1', 'success',
    JSON_OBJECT('checklist_batch_id', 1, 'org_id', 1, 'project_id', 1), '2026-03-21 12:00:00', '2026-03-21 11:45:00'
  ),
  (
    3, 4, 3, 1, 1, 1, NULL, NULL,
    'issue', 'issue_assign_dev',
    'Senior Developer assignment',
    'Login page validation fails on empty submit was assigned to you.',
    '/app/reports/1', 'alert',
    JSON_OBJECT('issue_id', 1, 'org_id', 1, 'project_id', 1), NULL, '2026-03-22 09:15:00'
  ),
  (
    4, 6, 4, 1, 1, 1, NULL, NULL,
    'issue', 'issue_assign_qa',
    'QA review requested',
    'Login page validation fails on empty submit needs QA validation.',
    '/app/reports/1', 'default',
    JSON_OBJECT('issue_id', 1, 'org_id', 1, 'project_id', 1), NULL, '2026-03-22 14:20:00'
  ),
  (
    5, 8, 7, 1, 1, 1, NULL, NULL,
    'issue', 'issue_report_qa_lead',
    'QA Lead decision needed',
    'Login page validation fails on empty submit is waiting for QA Lead approval.',
    '/app/reports/1', 'alert',
    JSON_OBJECT('issue_id', 1, 'org_id', 1, 'project_id', 1), NULL, '2026-03-22 16:05:00'
  ),
  (
    6, 1, 1, 1, NULL, NULL, NULL, NULL,
    'system', 'system_welcome',
    'Mobile inbox ready',
    'Your in-app notifications are enabled for BugCatcher mobile web.',
    '/app/notifications', 'success',
    JSON_OBJECT('org_id', 1), NULL, '2026-03-22 08:00:00'
  );
