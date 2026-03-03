CREATE DATABASE IF NOT EXISTS bug_catcher
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;

USE bug_catcher;

CREATE TABLE IF NOT EXISTS users (
  id INT(11) NOT NULL AUTO_INCREMENT,
  username VARCHAR(100) NOT NULL,
  email VARCHAR(255) NOT NULL,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin', 'user') DEFAULT 'user',
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
