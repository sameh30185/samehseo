-- SAMEH 12.1 Professional Phase C — typed actions, approvals, factory, growth (ADDITIVE ONLY)
-- Never DROP sites pairing/HMAC columns or wipe data.

CREATE TABLE IF NOT EXISTS action_plans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  site_id INT UNSIGNED NOT NULL,
  mission_id INT UNSIGNED NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  risk VARCHAR(32) NOT NULL DEFAULT 'low',
  needs_extra_approval TINYINT(1) NOT NULL DEFAULT 0,
  payload_json LONGTEXT NULL,
  preview_json LONGTEXT NULL,
  before_json LONGTEXT NULL,
  after_json LONGTEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ap_site (site_id),
  INDEX idx_ap_mission (mission_id),
  INDEX idx_ap_status (status),
  CONSTRAINT fk_ap_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS typed_actions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plan_id INT UNSIGNED NOT NULL,
  site_id INT UNSIGNED NOT NULL,
  action_type VARCHAR(64) NOT NULL,
  target_ref VARCHAR(255) NULL,
  params_json LONGTEXT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  result_json LONGTEXT NULL,
  error_message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ta_plan (plan_id),
  INDEX idx_ta_site (site_id),
  INDEX idx_ta_type (action_type),
  CONSTRAINT fk_ta_plan FOREIGN KEY (plan_id) REFERENCES action_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_ta_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing approvals table (12.0): add Phase C columns additively (keep title/status/payload_json)
ALTER TABLE approvals ADD COLUMN plan_id INT UNSIGNED NULL;
ALTER TABLE approvals ADD COLUMN decided_by INT UNSIGNED NULL;
ALTER TABLE approvals ADD COLUMN decision VARCHAR(32) NULL;
ALTER TABLE approvals ADD COLUMN note TEXT NULL;
ALTER TABLE approvals ADD COLUMN totp_confirmed TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE approvals ADD INDEX idx_approvals_plan (plan_id);

CREATE TABLE IF NOT EXISTS factory_plans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  site_id INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  template_key VARCHAR(64) NOT NULL DEFAULT 'blank_page',
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  items_json LONGTEXT NULL,
  qa_json LONGTEXT NULL,
  preview_json LONGTEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_fp_site (site_id),
  INDEX idx_fp_status (status),
  CONSTRAINT fk_fp_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS growth_opportunities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  site_id INT UNSIGNED NOT NULL,
  kind VARCHAR(64) NOT NULL,
  title VARCHAR(255) NOT NULL,
  impact_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
  confidence_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
  effort_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
  risk_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
  evidence_ids_json TEXT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'open',
  mission_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_go_site (site_id),
  INDEX idx_go_kind (kind),
  INDEX idx_go_status (status),
  CONSTRAINT fk_go_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('app_version', '12.1.0-rc1'),
  ('phase_c_actions', '1')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
