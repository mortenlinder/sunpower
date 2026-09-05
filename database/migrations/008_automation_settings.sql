INSERT INTO operational_state(state_key,state_value,reason,updated_at) VALUES
('intelligent_control_enabled','0','Intelligent styring aktiveres fra panelet',UTC_TIMESTAMP(6)),
('intelligent_control_until','','Ingen automatisk slutdato',UTC_TIMESTAMP(6)),
('plan_horizon_hours','48','Standard planhorisont',UTC_TIMESTAMP(6)),
('fallback_mode','battery_first','Standardtilstand uden aktiv plan',UTC_TIMESTAMP(6)),
('last_auto_plan_date','','Ingen automatisk plan kørt endnu',UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE state_key=VALUES(state_key);
INSERT IGNORE INTO commands(command_type,payload_json,status,requested_by,reason,idempotency_key,created_at) VALUES('apply_fallback_mode','{"mode":"battery_first","actor":"system:migration"}','pending',NULL,'Ny standardtilstand: Battery First','migration-default-battery-first-v1',UTC_TIMESTAMP(6));
