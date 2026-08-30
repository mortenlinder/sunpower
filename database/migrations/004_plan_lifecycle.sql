ALTER TABLE plan_approvals ADD COLUMN IF NOT EXISTS expires_at DATETIME(6) NULL AFTER approved_at;
ALTER TABLE plan_approvals MODIFY COLUMN status ENUM('approved_shadow','cancelled','expired') NOT NULL DEFAULT 'approved_shadow';
CREATE TABLE IF NOT EXISTS operational_state (state_key VARCHAR(80) PRIMARY KEY, state_value VARCHAR(255) NOT NULL, reason VARCHAR(500) NOT NULL, updated_at DATETIME(6) NOT NULL);
INSERT INTO operational_state(state_key,state_value,reason,updated_at) VALUES ('requested_battery_mode','load_first','Sikker standardtilstand uden en gyldig manuelt godkendt plan',UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE state_key=VALUES(state_key);
