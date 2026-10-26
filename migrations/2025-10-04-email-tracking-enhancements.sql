-- Email Tracking & Interaction Enhancements
-- Date: 2025-10-04
-- Purpose: Track email sends, deliveries, opens, clicks, and bounces for comprehensive analytics

START TRANSACTION;

-- 1) Email send log - tracks every email sent
CREATE TABLE IF NOT EXISTS `email_send_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` VARCHAR(255) DEFAULT NULL COMMENT 'SendGrid message ID',
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `recipient_email` VARCHAR(255) NOT NULL,
  `recipient_name` VARCHAR(255) DEFAULT NULL,
  `sender_email` VARCHAR(255) NOT NULL,
  `sender_name` VARCHAR(255) DEFAULT NULL,
  `subject` VARCHAR(500) NOT NULL,
  `template_id` BIGINT UNSIGNED DEFAULT NULL,
  `event_key` VARCHAR(64) DEFAULT NULL COMMENT 'Trigger event if applicable',
  `status` ENUM('queued','sent','delivered','opened','clicked','bounced','failed','spam') NOT NULL DEFAULT 'queued',
  `sendgrid_status_code` INT DEFAULT NULL,
  `error_message` TEXT DEFAULT NULL,
  `metadata` JSON DEFAULT NULL COMMENT 'Additional context',
  `sent_at` DATETIME DEFAULT NULL,
  `delivered_at` DATETIME DEFAULT NULL,
  `opened_at` DATETIME DEFAULT NULL,
  `first_clicked_at` DATETIME DEFAULT NULL,
  `bounced_at` DATETIME DEFAULT NULL,
  `open_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `click_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email_log_message_id` (`message_id`),
  KEY `idx_email_log_user` (`user_id`),
  KEY `idx_email_log_recipient` (`recipient_email`),
  KEY `idx_email_log_status` (`status`,`sent_at`),
  KEY `idx_email_log_template` (`template_id`),
  KEY `idx_email_log_event` (`event_key`),
  CONSTRAINT `email_send_log_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `email_send_log_template_fk` FOREIGN KEY (`template_id`) REFERENCES `email_templates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Email interactions - tracks opens and clicks
CREATE TABLE IF NOT EXISTS `email_interactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email_log_id` BIGINT UNSIGNED NOT NULL,
  `interaction_type` ENUM('open','click','bounce','spam_report','unsubscribe') NOT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(500) DEFAULT NULL,
  `clicked_url` VARCHAR(1000) DEFAULT NULL COMMENT 'For click events',
  `metadata` JSON DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email_interactions_log` (`email_log_id`,`interaction_type`,`created_at`),
  KEY `idx_email_interactions_type` (`interaction_type`,`created_at`),
  CONSTRAINT `email_interactions_log_fk` FOREIGN KEY (`email_log_id`) REFERENCES `email_send_log` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Add legal event keys
INSERT INTO `email_events` (`event_key`, `name`, `description`, `default_template_slug`, `scope`, `is_active`)
VALUES
  ('legal_access_requested', 'Legal Access Requested', 'Triggered when someone requests legal portal access', NULL, 'platform', 1),
  ('legal_access_approved', 'Legal Access Approved', 'Triggered when legal access is approved', 'legal-access-approved', 'platform', 1),
  ('legal_access_denied', 'Legal Access Denied', 'Triggered when legal access is denied', 'legal-access-denied', 'platform', 1),
  ('legal_case_assigned', 'Legal Case Assigned', 'Triggered when user is assigned to a legal case', NULL, 'platform', 1),
  ('legal_evidence_uploaded', 'Legal Evidence Uploaded', 'Triggered when new evidence is uploaded to a case', NULL, 'platform', 1)
ON DUPLICATE KEY UPDATE 
  `name` = VALUES(`name`), 
  `description` = VALUES(`description`), 
  `default_template_slug` = VALUES(`default_template_slug`),
  `scope` = VALUES(`scope`);

COMMIT;

-- Create view for email analytics
CREATE OR REPLACE VIEW `email_analytics_summary` AS
SELECT 
    DATE(sent_at) as send_date,
    event_key,
    template_id,
    COUNT(*) as total_sent,
    SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_count,
    SUM(CASE WHEN status = 'opened' THEN 1 ELSE 0 END) as opened_count,
    SUM(CASE WHEN status = 'clicked' THEN 1 ELSE 0 END) as clicked_count,
    SUM(CASE WHEN status = 'bounced' THEN 1 ELSE 0 END) as bounced_count,
    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
    SUM(open_count) as total_opens,
    SUM(click_count) as total_clicks,
    ROUND(AVG(CASE WHEN opened_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, sent_at, opened_at) END), 2) as avg_minutes_to_open
FROM email_send_log
WHERE sent_at IS NOT NULL
GROUP BY DATE(sent_at), event_key, template_id
ORDER BY send_date DESC;
