CREATE TABLE IF NOT EXISTS openclaw_machine_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  allowed_actions JSON NOT NULL,
  risk_level ENUM('low') NOT NULL DEFAULT 'low',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY openclaw_machine_tokens_token_hash_unique (token_hash),
  KEY openclaw_machine_tokens_active_idx (is_active)
);

CREATE TABLE IF NOT EXISTS openclaw_agent_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  action VARCHAR(80) NOT NULL,
  payload JSON NULL,
  status ENUM('queued', 'running', 'completed', 'failed', 'blocked') NOT NULL DEFAULT 'queued',
  risk_level ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'low',
  requested_by_type ENUM('machine', 'human') NOT NULL,
  requested_by_id BIGINT UNSIGNED NULL,
  result JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY openclaw_agent_jobs_status_idx (status),
  KEY openclaw_agent_jobs_action_idx (action),
  KEY openclaw_agent_jobs_created_idx (created_at)
);

CREATE TABLE IF NOT EXISTS openclaw_audit_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_type ENUM('machine', 'human', 'system') NOT NULL,
  actor_id BIGINT UNSIGNED NULL,
  action VARCHAR(120) NOT NULL,
  risk_level ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'low',
  outcome ENUM('allowed', 'denied', 'queued', 'triggered', 'failed') NOT NULL,
  metadata JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY openclaw_audit_events_actor_idx (actor_type, actor_id),
  KEY openclaw_audit_events_action_idx (action),
  KEY openclaw_audit_events_created_idx (created_at)
);

CREATE TABLE IF NOT EXISTS openclaw_daily_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_type ENUM('approval_reminder', 'decision_report') NOT NULL,
  run_date DATE NOT NULL,
  status ENUM('queued', 'running', 'completed', 'failed') NOT NULL DEFAULT 'queued',
  triggered_by_type ENUM('machine', 'human', 'system') NOT NULL,
  triggered_by_id BIGINT UNSIGNED NULL,
  payload JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY openclaw_daily_runs_type_date_unique (run_type, run_date),
  KEY openclaw_daily_runs_status_idx (status)
);
