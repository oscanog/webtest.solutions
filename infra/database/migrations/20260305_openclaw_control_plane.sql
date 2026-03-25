USE bug_catcher;

CREATE TABLE IF NOT EXISTS openclaw_control_plane_state (
  id TINYINT(1) NOT NULL DEFAULT 1,
  config_version VARCHAR(40) NOT NULL,
  last_runtime_reload_requested_at DATETIME DEFAULT NULL,
  last_runtime_reload_requested_by INT(11) DEFAULT NULL,
  last_runtime_reload_reason VARCHAR(120) DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_openclaw_control_plane_state_requested_by
    FOREIGN KEY (last_runtime_reload_requested_by) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS openclaw_runtime_status (
  id TINYINT(1) NOT NULL DEFAULT 1,
  config_version_applied VARCHAR(40) DEFAULT NULL,
  gateway_state VARCHAR(30) DEFAULT NULL,
  integration_state VARCHAR(30) DEFAULT NULL,
  integration_application_id VARCHAR(64) DEFAULT NULL,
  last_heartbeat_at DATETIME DEFAULT NULL,
  last_reload_at DATETIME DEFAULT NULL,
  last_provider_error TEXT DEFAULT NULL,
  last_integration_error TEXT DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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

INSERT INTO openclaw_control_plane_state
    (id, config_version, last_runtime_reload_requested_at, last_runtime_reload_requested_by, last_runtime_reload_reason, updated_at)
VALUES
    (1, DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%dT%H:%i:%sZ'), NULL, NULL, NULL, NOW())
ON DUPLICATE KEY UPDATE
    config_version = COALESCE(NULLIF(config_version, ''), VALUES(config_version));

INSERT INTO openclaw_runtime_status
    (id, config_version_applied, gateway_state, integration_state, integration_application_id, last_heartbeat_at, last_reload_at, last_provider_error, last_integration_error, updated_at)
VALUES
    (1, NULL, 'unknown', 'unknown', NULL, NULL, NULL, NULL, NULL, NOW())
ON DUPLICATE KEY UPDATE
    id = id;
