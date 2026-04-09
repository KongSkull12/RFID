-- Parent SMS queue + tenant templates (run once on existing databases).
-- Fresh installs: these are already in sql/schema.sql

USE school_rfid_attendance;

ALTER TABLE tenants
  ADD COLUMN sms_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN sms_template_in TEXT NULL AFTER sms_enabled,
  ADD COLUMN sms_template_out TEXT NULL AFTER sms_template_in,
  ADD COLUMN sms_poll_secret VARCHAR(64) NULL AFTER sms_template_out;

CREATE TABLE IF NOT EXISTS sms_outbox (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  attendance_log_id BIGINT NULL,
  destination_phone VARCHAR(32) NOT NULL,
  message_body VARCHAR(700) NOT NULL,
  status ENUM('pending', 'processing', 'sent', 'failed') NOT NULL DEFAULT 'pending',
  error_message VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  CONSTRAINT fk_sms_outbox_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_sms_outbox_log
    FOREIGN KEY (attendance_log_id) REFERENCES attendance_logs(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX idx_sms_outbox_tenant_status (tenant_id, status),
  INDEX idx_sms_outbox_created (created_at)
);
