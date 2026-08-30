CREATE TABLE IF NOT EXISTS ocpp_charge_points (charge_point_id VARCHAR(100) PRIMARY KEY, remote_ip VARCHAR(45) NULL, status VARCHAR(40) NOT NULL DEFAULT 'Unknown', vendor VARCHAR(100) NULL, model VARCHAR(100) NULL, serial_number VARCHAR(100) NULL, last_seen_at DATETIME(6) NOT NULL);
CREATE TABLE IF NOT EXISTS ocpp_messages (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, charge_point_id VARCHAR(100) NOT NULL, action VARCHAR(80) NOT NULL, payload_json JSON NOT NULL, received_at DATETIME(6) NOT NULL, INDEX idx_ocpp_messages_point_time(charge_point_id,received_at));

