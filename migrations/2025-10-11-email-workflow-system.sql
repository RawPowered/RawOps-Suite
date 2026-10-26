-- ============================================================================
-- Email Follow-up Workflow Automation System
-- Date: 2025-10-11
-- Purpose: Automated email follow-ups based on recipient behavior tracking
-- ============================================================================

-- ============================================================================
-- 1. Email Conversion Tracking - Track website actions from email clicks
-- ============================================================================
CREATE TABLE IF NOT EXISTS `email_conversions` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_email_id` int(11) UNSIGNED NOT NULL COMMENT 'Reference to agent_emails',
  `session_id` varchar(255) DEFAULT NULL COMMENT 'PHP session ID',
  `tracking_token` varchar(64) DEFAULT NULL COMMENT 'Unique tracking token from email link',
  `user_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'Logged in user if applicable',
  `conversion_type` enum('page_view','product_view','cart_add','checkout_start','order_complete','ai_chat') NOT NULL DEFAULT 'page_view',
  `conversion_value` decimal(10,2) DEFAULT 0.00 COMMENT 'Monetary value if applicable',
  `order_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'Order ID for purchase conversions',
  `metadata` json DEFAULT NULL COMMENT 'Additional tracking data',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`agent_email_id`),
  KEY `idx_session` (`session_id`),
  KEY `idx_token` (`tracking_token`),
  KEY `idx_type` (`conversion_type`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Track website conversions from email click-throughs';

-- ============================================================================
-- 2. Email Follow-up Workflows - Automation templates
-- ============================================================================
CREATE TABLE IF NOT EXISTS `email_followup_workflows` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `trigger_type` enum('unopened','opened_not_clicked','clicked_no_action','clicked_no_sale','clicked_sale_with_agent','clicked_sale_no_agent','clicked_ai_interaction','custom') NOT NULL,
  `trigger_delay_hours` int(11) NOT NULL DEFAULT 72 COMMENT 'Hours to wait before triggering',
  `scope` enum('global','agent','lead','account') DEFAULT 'global' COMMENT 'Who owns this workflow',
  `owner_agent_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'Agent who owns this (if scope=agent)',
  `template_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'Email template to use',
  `allow_ai_generation` tinyint(1) DEFAULT 0 COMMENT 'Allow AI to generate personalized content',
  `priority` int(11) DEFAULT 50 COMMENT 'Priority when multiple workflows match (higher wins)',
  `is_active` tinyint(1) DEFAULT 1 COMMENT 'Active workflows only',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_trigger` (`trigger_type`),
  KEY `idx_scope` (`scope`),
  KEY `idx_owner` (`owner_agent_id`),
  KEY `idx_active` (`is_active`),
  KEY `idx_template` (`template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Email follow-up workflow templates';

-- ============================================================================
-- 3. Follow-up Schedule - Scheduled follow-up instances
-- ============================================================================
CREATE TABLE IF NOT EXISTS `email_followup_schedule` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `workflow_id` int(11) UNSIGNED NOT NULL COMMENT 'Workflow that triggered this',
  `parent_email_id` int(11) UNSIGNED NOT NULL COMMENT 'Original email that triggered this follow-up',
  `entity_type` enum('lead','account') NOT NULL,
  `entity_id` int(11) UNSIGNED NOT NULL COMMENT 'Lead or Account ID',
  `recipient_email` varchar(255) NOT NULL,
  `scheduled_for` datetime NOT NULL COMMENT 'When to send this follow-up',
  `status` enum('pending','cancelled','sent','failed') DEFAULT 'pending',
  `cancel_reason` varchar(500) DEFAULT NULL,
  `sent_email_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'agent_emails.id when sent',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_scheduled` (`scheduled_for`,`status`),
  KEY `idx_parent` (`parent_email_id`),
  KEY `idx_entity` (`entity_type`,`entity_id`),
  KEY `idx_workflow` (`workflow_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Scheduled follow-up email instances';

-- ============================================================================
-- 4. Entity-Specific Workflow Settings - Per-lead/account overrides
-- ============================================================================
CREATE TABLE IF NOT EXISTS `email_followup_entity_settings` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type` enum('lead','account') NOT NULL,
  `entity_id` int(11) UNSIGNED NOT NULL,
  `workflow_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'Specific workflow or NULL for all',
  `is_enabled` tinyint(1) DEFAULT 1 COMMENT 'Enable/disable workflows for this entity',
  `custom_delay_hours` int(11) DEFAULT NULL COMMENT 'Override default delay',
  `custom_template_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'Override template',
  `allow_time_of_day_optimization` tinyint(1) DEFAULT 0 COMMENT 'Optimize send time',
  `preferred_send_hour` int(11) DEFAULT NULL COMMENT '0-23, hour to send in recipient timezone',
  `notes` text DEFAULT NULL COMMENT 'Admin/agent notes',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_entity_workflow` (`entity_type`,`entity_id`,`workflow_id`),
  KEY `idx_entity` (`entity_type`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Per-entity workflow customizations';

-- ============================================================================
-- 5. Extend agent_emails table for tracking
-- ============================================================================
ALTER TABLE `agent_emails` 
ADD COLUMN IF NOT EXISTS `tracking_token` varchar(64) DEFAULT NULL COMMENT 'Unique token for click tracking' AFTER `sendgrid_message_id`,
ADD COLUMN IF NOT EXISTS `has_conversion` tinyint(1) DEFAULT 0 COMMENT 'Flag: conversion event occurred' AFTER `contributed_to_sale`,
ADD COLUMN IF NOT EXISTS `conversion_type` varchar(50) DEFAULT NULL COMMENT 'Type of conversion' AFTER `has_conversion`,
ADD COLUMN IF NOT EXISTS `followup_parent_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'Parent email ID if this is a follow-up' AFTER `related_order_id`;

-- Add indexes if they don't exist
ALTER TABLE `agent_emails`
ADD UNIQUE KEY IF NOT EXISTS `idx_tracking` (`tracking_token`),
ADD KEY IF NOT EXISTS `idx_followup_parent` (`followup_parent_id`),
ADD KEY IF NOT EXISTS `idx_has_conversion` (`has_conversion`);

-- ============================================================================
-- 6. Extend email_automation_settings for workflow controls
-- ============================================================================
ALTER TABLE `email_automation_settings`
ADD COLUMN IF NOT EXISTS `allow_followup_workflows` tinyint(1) DEFAULT 0 COMMENT 'Enable follow-up workflows' AFTER `allow_scheduled_emails`,
ADD COLUMN IF NOT EXISTS `max_followups_per_email` int(11) DEFAULT 3 COMMENT 'Max follow-ups per original email' AFTER `max_emails_per_week`;

-- ============================================================================
-- 7. Insert default global workflow templates
-- ============================================================================
INSERT INTO `email_followup_workflows` 
  (`name`, `description`, `trigger_type`, `trigger_delay_hours`, `scope`, `allow_ai_generation`, `priority`) 
VALUES
  ('Unopened - 3 Day Reminder', 'Gentle reminder for unopened emails after 3 days', 'unopened', 72, 'global', 1, 50),
  ('Opened But Not Clicked - 5 Day', 'Follow up when email was opened but recipient did not click', 'opened_not_clicked', 120, 'global', 1, 50),
  ('Clicked No Action - 2 Day', 'Urgent follow-up when clicked but no website action taken', 'clicked_no_action', 48, 'global', 1, 60),
  ('Clicked No Sale - 3 Day', 'Follow up when clicked and browsed but no sale', 'clicked_no_sale', 72, 'global', 1, 55),
  ('Post-Sale Thank You', 'Thank you email after successful sale with agent involvement', 'clicked_sale_with_agent', 24, 'global', 0, 40),
  ('Sale Without Follow-up Note', 'Note when sale made without agent follow-up needed', 'clicked_sale_no_agent', 48, 'global', 0, 30),
  ('AI Chat Engagement Follow-up', 'Follow up after recipient engaged with AI chat', 'clicked_ai_interaction', 48, 'global', 1, 55)
ON DUPLICATE KEY UPDATE 
  `name` = VALUES(`name`),
  `description` = VALUES(`description`);

-- ============================================================================
-- 8. Update global automation settings
-- ============================================================================
UPDATE `email_automation_settings` 
SET 
  `allow_followup_workflows` = 0,
  `max_followups_per_email` = 3
WHERE `entity_type` = 'global' AND `entity_id` IS NULL;

-- If no global settings exist, create them
INSERT INTO `email_automation_settings` 
  (`entity_type`, `entity_id`, `automation_enabled`, `allow_ai_emails`, `allow_scheduled_emails`, `allow_followup_workflows`, `max_emails_per_week`, `max_followups_per_email`, `notes`)
SELECT 'global', NULL, 0, 0, 1, 0, 3, 3, 'Global defaults: Workflow automation DISABLED by default. Enable per-agent or per-entity after testing.'
WHERE NOT EXISTS (
  SELECT 1 FROM `email_automation_settings` WHERE `entity_type` = 'global' AND `entity_id` IS NULL
);

-- ============================================================================
-- End of migration
-- ============================================================================





