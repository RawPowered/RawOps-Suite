-- AI-Assisted Sales Email Composer System
-- Date: 2025-10-08
-- Purpose: Enable sales agents to draft, schedule, and send AI-assisted emails with admin oversight

-- ============================================================================
-- 1. Agent Emails Table - Core email storage and tracking
-- ============================================================================
CREATE TABLE IF NOT EXISTS `agent_emails` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_id` int(11) UNSIGNED NOT NULL COMMENT 'Sales agent who created this email',
  `lead_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'Associated lead if applicable',
  `account_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'Associated account if applicable',
  `recipient_email` varchar(255) NOT NULL COMMENT 'Recipient email address',
  `recipient_name` varchar(255) DEFAULT NULL COMMENT 'Recipient full name',
  `subject` varchar(500) NOT NULL COMMENT 'Email subject line',
  `body_html` mediumtext NOT NULL COMMENT 'HTML email body',
  `body_text` text DEFAULT NULL COMMENT 'Plain text version',
  `template_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'Template used if any',
  `is_ai_assisted` tinyint(1) DEFAULT 0 COMMENT 'Flag: AI was used to draft/enhance this email',
  `ai_contribution_level` enum('full','partial','none') DEFAULT 'none' COMMENT 'How much AI contributed',
  `status` enum('draft','scheduled','sent','delivered','bounced','failed','cancelled') DEFAULT 'draft',
  `scheduled_for` datetime DEFAULT NULL COMMENT 'When to send (NULL = immediate)',
  `sent_at` datetime DEFAULT NULL COMMENT 'Actual send timestamp',
  `sendgrid_message_id` varchar(255) DEFAULT NULL COMMENT 'SendGrid tracking ID',
  `open_count` int(11) DEFAULT 0 COMMENT 'Number of opens',
  `click_count` int(11) DEFAULT 0 COMMENT 'Number of clicks',
  `last_opened_at` datetime DEFAULT NULL,
  `last_clicked_at` datetime DEFAULT NULL,
  `contributed_to_sale` tinyint(1) DEFAULT 0 COMMENT 'Flag: This email contributed to a conversion',
  `related_order_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'Order ID if email led to sale',
  `ai_commission_applied` tinyint(1) DEFAULT 0 COMMENT 'Flag: AI fee has been applied',
  `admin_reviewed` tinyint(1) DEFAULT 0 COMMENT 'Flag: Admin has reviewed this email',
  `admin_reviewed_by` int(11) UNSIGNED DEFAULT NULL,
  `admin_reviewed_at` datetime DEFAULT NULL,
  `admin_notes` text DEFAULT NULL COMMENT 'Admin review notes/flags',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_agent` (`agent_id`),
  KEY `idx_lead` (`lead_id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_status` (`status`),
  KEY `idx_scheduled` (`scheduled_for`),
  KEY `idx_sent` (`sent_at`),
  KEY `idx_ai_assisted` (`is_ai_assisted`),
  KEY `idx_contributed` (`contributed_to_sale`),
  KEY `idx_recipient` (`recipient_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Agent-composed emails with AI assistance tracking';

-- ============================================================================
-- 2. Email Automation Settings - Per-lead and per-account automation controls
-- ============================================================================
CREATE TABLE IF NOT EXISTS `email_automation_settings` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type` enum('lead','account','global') NOT NULL COMMENT 'What this setting applies to',
  `entity_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'Lead or account ID (NULL for global)',
  `automation_enabled` tinyint(1) DEFAULT 0 COMMENT 'Master automation toggle',
  `allow_ai_emails` tinyint(1) DEFAULT 0 COMMENT 'Allow AI-generated automatic emails',
  `allow_scheduled_emails` tinyint(1) DEFAULT 1 COMMENT 'Allow scheduled/drip campaigns',
  `max_emails_per_week` int(11) DEFAULT 3 COMMENT 'Rate limiting',
  `quiet_hours_start` time DEFAULT '22:00:00' COMMENT 'No emails before this hour',
  `quiet_hours_end` time DEFAULT '08:00:00' COMMENT 'No emails after this hour',
  `opted_out_at` datetime DEFAULT NULL COMMENT 'Recipient opted out timestamp',
  `notes` text DEFAULT NULL COMMENT 'Admin notes about automation settings',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_entity` (`entity_type`, `entity_id`),
  KEY `idx_automation_enabled` (`automation_enabled`),
  KEY `idx_opted_out` (`opted_out_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Granular automation controls per lead/account';

-- ============================================================================
-- 3. Email Templates for Sales (extend existing email_templates if needed)
-- ============================================================================
-- Add sales-specific categories to existing email_templates table
-- (No schema change needed - we'll just insert templates)

-- ============================================================================
-- 4. AI Email Generation Logs - Track AI usage for billing/quality
-- ============================================================================
CREATE TABLE IF NOT EXISTS `ai_email_generations` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_id` int(11) UNSIGNED NOT NULL,
  `agent_email_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'Links to agent_emails table',
  `prompt_text` text NOT NULL COMMENT 'What the agent asked AI to write',
  `generated_content` mediumtext NOT NULL COMMENT 'AI-generated email content',
  `model_used` varchar(100) DEFAULT 'gemini-2.5-flash' COMMENT 'AI model identifier',
  `tokens_used` int(11) DEFAULT NULL COMMENT 'API token count',
  `generation_time_ms` int(11) DEFAULT NULL COMMENT 'Generation latency',
  `quality_score` decimal(3,2) DEFAULT NULL COMMENT 'Admin quality rating 0-10',
  `was_used` tinyint(1) DEFAULT 0 COMMENT 'Agent actually used this generated content',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_agent` (`agent_id`),
  KEY `idx_email` (`agent_email_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Track all AI email generations for quality and billing';

-- ============================================================================
-- 5. Email Send Queue - For scheduled emails
-- ============================================================================
CREATE TABLE IF NOT EXISTS `email_send_queue` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_email_id` int(11) UNSIGNED NOT NULL COMMENT 'Reference to agent_emails',
  `scheduled_for` datetime NOT NULL COMMENT 'When to send this email',
  `priority` int(11) DEFAULT 50 COMMENT 'Send priority (0-100)',
  `attempts` int(11) DEFAULT 0 COMMENT 'Send attempt counter',
  `last_attempt_at` datetime DEFAULT NULL,
  `last_error` text DEFAULT NULL COMMENT 'Error from last failed attempt',
  `status` enum('pending','processing','sent','failed','cancelled') DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_scheduled` (`scheduled_for`, `status`),
  KEY `idx_agent_email` (`agent_email_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Queue for scheduled email delivery';

-- ============================================================================
-- 6. Extend commissions table to track AI-assisted sales emails
-- ============================================================================
-- Add column to track which email contributed to the commission (if not exists)
ALTER TABLE `commissions` 
ADD COLUMN IF NOT EXISTS `source_email_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'agent_emails.id that contributed to this sale' AFTER `order_item_id`,
ADD KEY IF NOT EXISTS `idx_source_email` (`source_email_id`);

-- ============================================================================
-- 7. Insert default global automation settings (DISABLED by mandate)
-- ============================================================================
INSERT INTO `email_automation_settings` 
  (`entity_type`, `entity_id`, `automation_enabled`, `allow_ai_emails`, `allow_scheduled_emails`, `notes`)
VALUES 
  ('global', NULL, 0, 0, 1, 'Global default: Automation DISABLED per deployment mandate. Will be enabled after testing phase.')
ON DUPLICATE KEY UPDATE 
  `notes` = VALUES(`notes`);

-- ============================================================================
-- 8. Create email template categories for sales
-- ============================================================================
-- Update email_events table to add sales-specific events
INSERT IGNORE INTO `email_events` (`event_key`, `name`, `description`, `scope`, `is_active`) VALUES
('sales_followup_manual', 'Sales Follow-up (Manual)', 'Agent manually sends follow-up email', 'sales_agent', 1),
('sales_lead_nurture', 'Lead Nurture Email', 'Automated lead nurture sequence', 'sales_agent', 0),
('sales_demo_reminder', 'Demo Follow-up Reminder', 'Remind customer about demo product', 'sales_agent', 0),
('sales_quote_sent', 'Quote/Invoice Sent', 'Notification when quote is sent to customer', 'sales_agent', 1),
('sales_account_welcome', 'New Account Welcome (Sales)', 'Welcome email for newly converted account', 'sales_agent', 1);

-- ============================================================================
-- VERIFICATION QUERIES (for admin to run post-migration)
-- ============================================================================
-- Run these queries in phpMyAdmin to verify the migration:
-- 
-- SELECT COUNT(*) AS total_tables FROM information_schema.tables 
-- WHERE table_schema = DATABASE() AND table_name IN ('agent_emails', 'email_automation_settings', 'ai_email_generations', 'email_send_queue');
-- Expected: 4
--
-- SELECT * FROM email_automation_settings WHERE entity_type = 'global';
-- Expected: 1 row with automation_enabled = 0
--
-- SHOW COLUMNS FROM commissions LIKE 'source_email_id';
-- Expected: 1 row
--
-- SELECT COUNT(*) FROM email_events WHERE scope = 'sales_agent';
-- Expected: 5 or more

-- ============================================================================
-- ROLLBACK INSTRUCTIONS (if needed)
-- ============================================================================
-- To rollback this migration, run:
-- DROP TABLE IF EXISTS `email_send_queue`;
-- DROP TABLE IF EXISTS `ai_email_generations`;
-- DROP TABLE IF EXISTS `email_automation_settings`;
-- DROP TABLE IF EXISTS `agent_emails`;
-- ALTER TABLE `commissions` DROP COLUMN IF EXISTS `source_email_id`;
-- DELETE FROM `email_events` WHERE `event_key` IN ('sales_followup_manual', 'sales_lead_nurture', 'sales_demo_reminder', 'sales_quote_sent', 'sales_account_welcome');





