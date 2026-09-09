-- SAMEH 12.1 Professional — additive foundation (never DROP user/site data)
-- Safe to re-run partially: Migrator records version; statements use IF NOT EXISTS / ADD COLUMN guards where possible.

CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(64) NOT NULL PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- users: session_version for revoke-all-sessions on password reset
-- (MySQL <8.0.29 lacks IF NOT EXISTS on ADD COLUMN — Migrator may skip duplicate column errors)

ALTER TABLE users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE audit_log ADD COLUMN site_id INT UNSIGNED NULL;
ALTER TABLE audit_log ADD INDEX idx_audit_site (site_id);

CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip_address VARCHAR(45) NULL,
  UNIQUE KEY uq_prt_hash (token_hash),
  INDEX idx_prt_user (user_id),
  INDEX idx_prt_expires (expires_at),
  CONSTRAINT fk_prt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evidence (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  site_id INT UNSIGNED NOT NULL,
  source VARCHAR(64) NOT NULL DEFAULT 'discover',
  title VARCHAR(255) NOT NULL DEFAULT '',
  payload_json LONGTEXT NOT NULL,
  content_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_evidence_site (site_id),
  INDEX idx_evidence_source (source),
  CONSTRAINT fk_evidence_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_brain (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  site_id INT UNSIGNED NOT NULL,
  key_name VARCHAR(128) NOT NULL,
  value_json LONGTEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_brain_site_key (site_id, key_name),
  CONSTRAINT fk_brain_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS missions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  site_id INT UNSIGNED NOT NULL,
  type VARCHAR(64) NOT NULL,
  title VARCHAR(255) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  summary_ar TEXT NULL,
  findings_json LONGTEXT NULL,
  evidence_ids_json TEXT NULL,
  created_by INT UNSIGNED NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  error_message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_missions_site (site_id),
  INDEX idx_missions_status (status),
  INDEX idx_missions_type (type),
  CONSTRAINT fk_missions_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mission_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mission_id INT UNSIGNED NOT NULL,
  agent_name VARCHAR(64) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  input_json LONGTEXT NULL,
  output_json LONGTEXT NULL,
  error_message TEXT NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_mruns_mission (mission_id),
  CONSTRAINT fk_mruns_mission FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS decisions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  site_id INT UNSIGNED NULL,
  mission_id INT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'proposed',
  payload_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_decisions_site (site_id),
  INDEX idx_decisions_mission (mission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- approvals already has site_id; ensure helpful index exists
ALTER TABLE approvals ADD INDEX idx_approvals_site (site_id);

-- AI / app settings keys (values live in settings table)
INSERT INTO settings (setting_key, setting_value) VALUES
  ('app_version', '12.1.0-dev'),
  ('ai_cloud_enabled', '0'),
  ('ai_base_url', ''),
  ('ai_api_key_enc', ''),
  ('ai_model', 'gpt-4o-mini'),
  ('ai_timeout_seconds', '30'),
  ('ai_last_status', 'never_tested')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
