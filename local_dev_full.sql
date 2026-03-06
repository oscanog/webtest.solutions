-- -----------------------------------------------------------------------------
-- BugCatcher Local Development Bootstrap (MySQL)
-- One-shot import for local development.
--
-- Usage:
--   mysql -u root -p < local_dev_full.sql
--
-- Default login users (all share password): DevPass123!
--   superadmin@local.dev (super_admin)
--   admin@local.dev      (admin)
--   pm@local.dev         (user; org role Project Manager)
--   senior@local.dev     (user; org role Senior Developer)
--   junior@local.dev     (user; org role Junior Developer)
--   qa@local.dev         (user; org role QA Tester)
--   seniorqa@local.dev   (user; org role Senior QA)
--   qalead@local.dev     (user; org role QA Lead)
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
  status ENUM('open', 'closed') DEFAULT 'open',
  author_id INT(11) DEFAULT NULL,
  org_id INT(11) NOT NULL,
  assigned_dev_id INT(11) DEFAULT NULL,
  assign_status VARCHAR(20) NOT NULL DEFAULT 'unassigned',
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
  KEY idx_issues_assign_status (assign_status),
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
  source_channel ENUM('web', 'telegram', 'discord', 'api') NOT NULL DEFAULT 'web',
  source_reference VARCHAR(255) DEFAULT NULL,
  status ENUM('draft', 'open', 'completed', 'archived') NOT NULL DEFAULT 'open',
  created_by INT(11) NOT NULL,
  updated_by INT(11) DEFAULT NULL,
  assigned_qa_lead_id INT(11) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
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

CREATE TABLE IF NOT EXISTS discord_user_links (
  id INT(11) NOT NULL AUTO_INCREMENT,
  user_id INT(11) NOT NULL,
  discord_user_id VARCHAR(64) DEFAULT NULL,
  discord_username VARCHAR(100) DEFAULT NULL,
  discord_global_name VARCHAR(100) DEFAULT NULL,
  link_code_hash CHAR(64) DEFAULT NULL,
  link_code_expires_at DATETIME DEFAULT NULL,
  linked_at DATETIME DEFAULT NULL,
  last_seen_at DATETIME DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_discord_user_links_user (user_id),
  UNIQUE KEY uniq_discord_user_links_discord_user (discord_user_id),
  KEY idx_discord_user_links_code (link_code_hash, link_code_expires_at),
  CONSTRAINT fk_discord_user_links_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS discord_channel_bindings (
  id INT(11) NOT NULL AUTO_INCREMENT,
  guild_id VARCHAR(64) NOT NULL,
  guild_name VARCHAR(120) DEFAULT NULL,
  channel_id VARCHAR(64) NOT NULL,
  channel_name VARCHAR(120) DEFAULT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  allow_dm_followup TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT(11) NOT NULL,
  updated_by INT(11) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_discord_channel_bindings_channel (channel_id),
  KEY idx_discord_channel_bindings_guild (guild_id, is_enabled),
  CONSTRAINT fk_discord_channel_bindings_created_by
    FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_discord_channel_bindings_updated_by
    FOREIGN KEY (updated_by) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS openclaw_runtime_config (
  id INT(11) NOT NULL AUTO_INCREMENT,
  is_enabled TINYINT(1) NOT NULL DEFAULT 0,
  encrypted_discord_bot_token TEXT DEFAULT NULL,
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
  discord_user_link_id INT(11) NOT NULL,
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
  CONSTRAINT fk_openclaw_requests_discord_user_link
    FOREIGN KEY (discord_user_link_id) REFERENCES discord_user_links(id)
    ON DELETE CASCADE,
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
  discord_attachment_id VARCHAR(64) DEFAULT NULL,
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

-- bcrypt hash for: DevPass123!
INSERT INTO users (id, username, email, password, role, last_active_org_id) VALUES
  (1, 'superadmin', 'superadmin@local.dev', '$2y$10$Orczv8/nxdbpV70GaimDc.ylQthAweZsg3Re4lSg592QVmWEAPVk.', 'super_admin', 1),
  (2, 'admin', 'admin@local.dev', '$2y$10$Orczv8/nxdbpV70GaimDc.ylQthAweZsg3Re4lSg592QVmWEAPVk.', 'admin', 1),
  (3, 'pm', 'pm@local.dev', '$2y$10$Orczv8/nxdbpV70GaimDc.ylQthAweZsg3Re4lSg592QVmWEAPVk.', 'user', 1),
  (4, 'senior', 'senior@local.dev', '$2y$10$Orczv8/nxdbpV70GaimDc.ylQthAweZsg3Re4lSg592QVmWEAPVk.', 'user', 1),
  (5, 'junior', 'junior@local.dev', '$2y$10$Orczv8/nxdbpV70GaimDc.ylQthAweZsg3Re4lSg592QVmWEAPVk.', 'user', 1),
  (6, 'qa', 'qa@local.dev', '$2y$10$Orczv8/nxdbpV70GaimDc.ylQthAweZsg3Re4lSg592QVmWEAPVk.', 'user', 1),
  (7, 'seniorqa', 'seniorqa@local.dev', '$2y$10$Orczv8/nxdbpV70GaimDc.ylQthAweZsg3Re4lSg592QVmWEAPVk.', 'user', 1),
  (8, 'qalead', 'qalead@local.dev', '$2y$10$Orczv8/nxdbpV70GaimDc.ylQthAweZsg3Re4lSg592QVmWEAPVk.', 'user', 1);

INSERT INTO organizations (id, name, owner_id) VALUES
  (1, 'Local Dev Org', 1);

INSERT INTO org_members (org_id, user_id, role) VALUES
  (1, 1, 'owner'),
  (1, 2, 'member'),
  (1, 3, 'Project Manager'),
  (1, 4, 'Senior Developer'),
  (1, 5, 'Junior Developer'),
  (1, 6, 'QA Tester'),
  (1, 7, 'Senior QA'),
  (1, 8, 'QA Lead');

INSERT INTO projects (id, org_id, name, code, description, status, created_by, updated_by) VALUES
  (1, 1, 'Website Revamp', 'WEB-REVAMP', 'Primary local dev project', 'active', 3, 3),
  (2, 1, 'Mobile QA Sweep', 'MOBILE-QA', 'Secondary local dev project', 'active', 3, 3);

INSERT INTO issues (
  id, title, description, status, author_id, org_id,
  assigned_dev_id, assigned_junior_id, assigned_qa_id, assigned_senior_qa_id, assigned_qa_lead_id,
  assign_status, pm_id, assigned_at, junior_assigned_at, junior_done_at, qa_assigned_at, senior_qa_assigned_at, qa_lead_assigned_at
) VALUES
  (
    1,
    'Login page validation fails on empty submit',
    'Submit button allows request with empty payload in some browsers.',
    'open',
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
    'open',
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
  id, is_enabled, encrypted_discord_bot_token, default_provider_config_id, default_model_id,
  notes, created_by, updated_by
) VALUES (
  1, 0, NULL, NULL, NULL,
  'Local dev bootstrap default',
  1, 1
);
