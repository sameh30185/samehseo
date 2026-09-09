-- SAMEH 12.1.1 FINAL — Local AI workers/jobs + Project Brain + Growth fingerprint (ADDITIVE ONLY)
-- Never DROP sites pairing/HMAC columns or wipe data.

CREATE TABLE IF NOT EXISTS ai_workers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(128) NOT NULL DEFAULT 'local-worker',
  token_hash CHAR(64) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'paired',
  last_heartbeat_at DATETIME NULL,
  models_json LONGTEXT NULL,
  hostname VARCHAR(255) NULL,
  version VARCHAR(64) NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ai_workers_token_hash (token_hash),
  INDEX idx_ai_workers_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  site_id INT UNSIGNED NOT NULL,
  mission_id INT UNSIGNED NULL,
  agent_name VARCHAR(64) NOT NULL DEFAULT '',
  job_type VARCHAR(64) NOT NULL DEFAULT 'chat',
  status VARCHAR(32) NOT NULL DEFAULT 'queued',
  priority INT NOT NULL DEFAULT 100,
  payload_json LONGTEXT NULL,
  result_json LONGTEXT NULL,
  error_message TEXT NULL,
  model_requested VARCHAR(128) NULL,
  model_used VARCHAR(128) NULL,
  attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
  lease_owner VARCHAR(64) NULL,
  lease_expires_at DATETIME NULL,
  idempotency_key VARCHAR(64) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ai_jobs_status_prio (status, priority, id),
  INDEX idx_ai_jobs_site (site_id),
  INDEX idx_ai_jobs_mission (mission_id),
  INDEX idx_ai_jobs_lease (lease_expires_at),
  UNIQUE KEY uq_ai_jobs_idem (idempotency_key),
  CONSTRAINT fk_ai_jobs_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_brain (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  site_id INT UNSIGNED NOT NULL,
  kind VARCHAR(64) NOT NULL,
  label VARCHAR(255) NOT NULL DEFAULT '',
  value_text TEXT NULL,
  value_json LONGTEXT NULL,
  is_inference TINYINT(1) NOT NULL DEFAULT 0,
  approved TINYINT(1) NOT NULL DEFAULT 0,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_brain_site_kind (site_id, kind),
  CONSTRAINT fk_brain_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_pair_nonces (
  nonce CHAR(64) NOT NULL PRIMARY KEY,
  created_at INT UNSIGNED NOT NULL,
  INDEX idx_wpn_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS temp_elevations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  site_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  plan_id INT UNSIGNED NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  used_at DATETIME NULL,
  INDEX idx_te_site_user (site_id, user_id),
  INDEX idx_te_expires (expires_at),
  CONSTRAINT fk_te_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Growth fingerprint for upsert dedupe (additive)
ALTER TABLE growth_opportunities ADD COLUMN fingerprint CHAR(64) NULL;
ALTER TABLE growth_opportunities ADD UNIQUE KEY uq_go_site_fp (site_id, fingerprint);

-- Mission analysis source label
ALTER TABLE missions ADD COLUMN analysis_source VARCHAR(32) NOT NULL DEFAULT 'rules';
ALTER TABLE missions ADD COLUMN goal_text TEXT NULL;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('app_version', '12.1.0-final-rc'),
  ('local_ai_enabled', '0'),
  ('phase_final_local_ai', '1')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
