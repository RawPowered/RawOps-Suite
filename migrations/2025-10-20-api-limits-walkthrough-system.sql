-- ============================================================================
-- API Rate Limiting & Walkthrough System
-- Migration: 2025-10-20
-- Purpose: Implement API usage limits based on lead detail completion rates
--          and create universal walkthrough/tutorial system
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================================
-- API RESTRICTION SETTINGS
-- ============================================================================

-- Agent API restriction settings (global and per-agent)
CREATE TABLE IF NOT EXISTS `agent_api_restriction_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_id` BIGINT UNSIGNED NULL COMMENT 'NULL = global default',
  `restriction_type` ENUM('google_api', 'ai_email') NOT NULL,
  `min_detail_percentage` DECIMAL(5,2) NOT NULL DEFAULT 80.00 COMMENT 'Minimum lead detail completion %',
  `first_n_leads_exempt` INT UNSIGNED NOT NULL DEFAULT 10 COMMENT 'First N leads exempt from restriction',
  `exemption_days` INT UNSIGNED NULL COMMENT 'Temporary exemption for N days from creation',
  `exemption_until` DATETIME NULL COMMENT 'Temporary exemption until specific date',
  `exemption_reason` TEXT NULL COMMENT 'Why exemption was granted',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_agent_restriction` (`agent_id`, `restriction_type`),
  KEY `idx_restriction_type` (`restriction_type`),
  KEY `idx_active_exemptions` (`exemption_until`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert global defaults
INSERT INTO `agent_api_restriction_settings` 
  (`agent_id`, `restriction_type`, `min_detail_percentage`, `first_n_leads_exempt`, `is_active`) 
VALUES 
  (NULL, 'google_api', 80.00, 10, 1),
  (NULL, 'ai_email', 70.00, 10, 1)
ON DUPLICATE KEY UPDATE 
  `min_detail_percentage` = VALUES(`min_detail_percentage`),
  `first_n_leads_exempt` = VALUES(`first_n_leads_exempt`);

-- ============================================================================
-- API USAGE TRACKING ENHANCEMENT
-- ============================================================================

-- Add blocked_by_restriction column to existing api_usage_tracking table
ALTER TABLE `api_usage_tracking` 
  ADD COLUMN IF NOT EXISTS `blocked_by_restriction` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Was this API call blocked by restriction?' AFTER `session_id`;

ALTER TABLE `api_usage_tracking`
  ADD INDEX IF NOT EXISTS `idx_blocked` (`blocked_by_restriction`, `created_at`);

-- ============================================================================
-- AGENT PERFORMANCE CACHE ENHANCEMENT
-- ============================================================================

-- Add detail_completion_rate column to existing crm_agent_performance_cache table
ALTER TABLE `crm_agent_performance_cache` 
  ADD COLUMN IF NOT EXISTS `detail_completion_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Lead detail completion percentage' AFTER `incomplete_leads`;

-- ============================================================================
-- AGENT LEAD STATISTICS VIEW
-- ============================================================================

-- View to calculate per-agent lead detail completion statistics
CREATE OR REPLACE VIEW `agent_lead_detail_statistics` AS
SELECT 
  u.id as agent_id,
  u.first_name,
  u.last_name,
  u.email as agent_email,
  COUNT(l.id) as total_leads,
  SUM(CASE WHEN ds.is_detailed_lead = 1 THEN 1 ELSE 0 END) as detailed_leads,
  SUM(CASE WHEN ds.is_detailed_lead = 0 OR ds.is_detailed_lead IS NULL THEN 1 ELSE 0 END) as incomplete_leads,
  ROUND(
    (SUM(CASE WHEN ds.is_detailed_lead = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(l.id), 0)) * 100, 
    2
  ) as detail_completion_rate,
  GREATEST(0, 10 - COUNT(l.id)) as leads_exempt_remaining,
  -- Check if agent meets Google API threshold (80% by default)
  CASE 
    WHEN COUNT(l.id) <= 10 THEN 1 -- First 10 exempt
    WHEN (SUM(CASE WHEN ds.is_detailed_lead = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(l.id), 0)) * 100 >= 80 THEN 1
    ELSE 0
  END as meets_google_api_threshold,
  -- Check if agent meets AI Email threshold (70% by default)
  CASE 
    WHEN COUNT(l.id) <= 10 THEN 1 -- First 10 exempt
    WHEN (SUM(CASE WHEN ds.is_detailed_lead = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(l.id), 0)) * 100 >= 70 THEN 1
    ELSE 0
  END as meets_ai_email_threshold
FROM users u
LEFT JOIN crm_leads l ON l.assigned_agent_id = u.id
LEFT JOIN crm_leads_detail_status ds ON ds.id = l.id
WHERE EXISTS (
  SELECT 1 FROM user_roles ur 
  JOIN roles r ON r.id = ur.role_id 
  WHERE ur.user_id = u.id AND r.name = 'sales_agent'
)
GROUP BY u.id, u.first_name, u.last_name, u.email;

-- ============================================================================
-- WALKTHROUGH SYSTEM
-- ============================================================================

-- Main walkthrough definitions
CREATE TABLE IF NOT EXISTS `sales_walkthroughs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `slug` VARCHAR(100) NOT NULL COMMENT 'Unique identifier for walkthrough',
  `target_page` VARCHAR(100) NULL COMMENT 'Page identifier (NULL = global/all pages)',
  `target_element_selector` VARCHAR(255) NULL COMMENT 'CSS selector for tooltip anchor',
  `position` ENUM('top', 'right', 'bottom', 'left', 'center', 'modal') DEFAULT 'modal',
  `content` MEDIUMTEXT NULL COMMENT 'HTML content with shortcode support',
  `trigger_condition` JSON NULL COMMENT 'Conditions for when to show: {type: "page_load|element_click|manual", data: {}}',
  `show_for_roles` JSON NULL COMMENT 'Array of role names: ["sales_agent", "admin"]',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_multi_step` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Does this walkthrough have multiple steps?',
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_walkthrough_slug` (`slug`),
  KEY `idx_target_page` (`target_page`, `is_active`),
  KEY `idx_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Walkthrough steps (for multi-step walkthroughs)
CREATE TABLE IF NOT EXISTS `walkthrough_steps` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `walkthrough_id` BIGINT UNSIGNED NOT NULL,
  `step_number` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `content` MEDIUMTEXT NOT NULL COMMENT 'HTML content with shortcode support',
  `target_element_selector` VARCHAR(255) NULL COMMENT 'CSS selector for this step',
  `position` ENUM('top', 'right', 'bottom', 'left', 'center', 'modal') DEFAULT 'modal',
  `highlight_element` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Add spotlight to target element',
  `auto_advance_seconds` INT NULL COMMENT 'Auto-advance to next step after N seconds',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_walkthrough_step` (`walkthrough_id`, `step_number`),
  KEY `idx_walkthrough` (`walkthrough_id`, `step_number`),
  CONSTRAINT `fk_step_walkthrough` FOREIGN KEY (`walkthrough_id`) 
    REFERENCES `sales_walkthroughs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User walkthrough progress tracking
CREATE TABLE IF NOT EXISTS `user_walkthrough_progress` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `walkthrough_id` BIGINT UNSIGNED NOT NULL,
  `status` ENUM('not_started', 'in_progress', 'completed', 'skipped') DEFAULT 'not_started',
  `current_step` INT NOT NULL DEFAULT 0 COMMENT 'Current step number (0 = not started)',
  `started_at` TIMESTAMP NULL,
  `completed_at` TIMESTAMP NULL,
  `skipped_at` TIMESTAMP NULL,
  `last_interaction_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_walkthrough` (`user_id`, `walkthrough_id`),
  KEY `idx_user_status` (`user_id`, `status`),
  KEY `idx_walkthrough` (`walkthrough_id`),
  CONSTRAINT `fk_progress_walkthrough` FOREIGN KEY (`walkthrough_id`) 
    REFERENCES `sales_walkthroughs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Walkthrough interaction events (for analytics)
CREATE TABLE IF NOT EXISTS `walkthrough_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `walkthrough_id` BIGINT UNSIGNED NOT NULL,
  `step_number` INT NULL COMMENT 'NULL for walkthrough-level events',
  `event_type` ENUM('started', 'step_viewed', 'step_completed', 'completed', 'skipped', 'dismissed') NOT NULL,
  `event_data` JSON NULL COMMENT 'Additional event metadata',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_walkthrough` (`user_id`, `walkthrough_id`),
  KEY `idx_event_type` (`event_type`, `created_at`),
  KEY `idx_walkthrough` (`walkthrough_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SAMPLE WALKTHROUGHS (for testing)
-- ============================================================================

-- Welcome walkthrough for new sales agents (using INSERT IGNORE to avoid duplicates)
INSERT IGNORE INTO `sales_walkthroughs` 
  (`title`, `description`, `slug`, `target_page`, `position`, `content`, `trigger_condition`, `show_for_roles`, `is_active`, `is_multi_step`, `sort_order`, `created_by`)
VALUES 
  (
    'Welcome to Sales ACP',
    'Introduction to the Sales Agent Control Panel',
    'welcome-sales-acp',
    'sales-agent-index',
    'modal',
    '<h2>Welcome to the Product Sales ACP!</h2><p>This is your central hub for managing leads, accounts, and sales activities.</p><p>Let''s take a quick tour to help you get started.</p>',
    '{"type": "page_load", "data": {"first_visit": true}}',
    '["sales_agent"]',
    1,
    1,
    1,
    NULL
  ),
  (
    'Lead Detail Completion',
    'Understanding the importance of detailed leads',
    'lead-detail-importance',
    'sales-agent-leads',
    'modal',
    '<h2>Keep Your Leads Detailed</h2><p>To use Google Places search and AI Email tools, you need to maintain at least <strong>80% detailed leads</strong>.</p><p>A detailed lead has both <strong>email</strong> and <strong>phone</strong> information (or marked as unavailable).</p><p>Your first 10 leads are exempt from this requirement.</p>',
    '{"type": "page_load", "data": {"show_once": true}}',
    '["sales_agent"]',
    1,
    0,
    2,
    NULL
  );

-- Sample multi-step walkthrough steps (using INSERT IGNORE to avoid duplicates)
INSERT IGNORE INTO `walkthrough_steps` 
  (`walkthrough_id`, `step_number`, `title`, `content`, `target_element_selector`, `position`, `highlight_element`)
SELECT 
  id,
  1,
  'Your Dashboard',
  '<p>This is your dashboard where you can see your recent leads, tasks, and performance metrics.</p>',
  '.dashboard-summary',
  'bottom',
  1
FROM `sales_walkthroughs` WHERE slug = 'welcome-sales-acp'
UNION ALL
SELECT 
  id,
  2,
  'Lead Management',
  '<p>View and manage all your leads from the leads section. You can add new leads, update contact information, and track your interactions.</p>',
  '.leads-section',
  'right',
  1
FROM `sales_walkthroughs` WHERE slug = 'welcome-sales-acp'
UNION ALL
SELECT 
  id,
  3,
  'Completion Meter',
  '<p>Keep an eye on your lead detail completion rate. Maintaining 80%+ unlocks powerful API tools like Google Places and AI Email.</p>',
  '#lead-completion-meter',
  'left',
  1
FROM `sales_walkthroughs` WHERE slug = 'welcome-sales-acp';

-- ============================================================================
-- VERIFICATION & CLEANUP
-- ============================================================================

-- Verify tables were created
SELECT 
  TABLE_NAME,
  TABLE_ROWS,
  CREATE_TIME
FROM information_schema.tables 
WHERE table_schema = DATABASE() 
  AND table_name IN (
    'agent_api_restriction_settings',
    'sales_walkthroughs',
    'walkthrough_steps',
    'user_walkthrough_progress',
    'walkthrough_events'
  );

COMMIT;

-- ============================================================================
-- VERIFICATION QUERIES (Run after migration)
-- ============================================================================

-- Check global API restrictions
-- SELECT * FROM agent_api_restriction_settings WHERE agent_id IS NULL;

-- Check agent statistics view
-- SELECT * FROM agent_lead_detail_statistics LIMIT 10;

-- Check walkthrough system
-- SELECT * FROM sales_walkthroughs WHERE is_active = 1;

-- Test specific agent's access
-- SELECT 
--   agent_id,
--   total_leads,
--   detailed_leads,
--   detail_completion_rate,
--   meets_google_api_threshold,
--   meets_ai_email_threshold
-- FROM agent_lead_detail_statistics 
-- WHERE agent_id = 123;

-- ============================================================================
-- STORED PROCEDURE UPDATE
-- ============================================================================

-- Update agent performance cache stored procedure to include detail_completion_rate
DROP PROCEDURE IF EXISTS `sp_update_agent_performance`;

DELIMITER $$
CREATE PROCEDURE `sp_update_agent_performance`(IN p_agent_id BIGINT UNSIGNED)
BEGIN
  DECLARE v_leads_today INT DEFAULT 0;
  DECLARE v_leads_week INT DEFAULT 0;
  DECLARE v_active_leads INT DEFAULT 0;
  DECLARE v_total_leads INT DEFAULT 0;
  DECLARE v_detailed_leads INT DEFAULT 0;
  DECLARE v_incomplete_leads INT DEFAULT 0;
  DECLARE v_missing_email INT DEFAULT 0;
  DECLARE v_missing_phone INT DEFAULT 0;
  DECLARE v_conversions_today INT DEFAULT 0;
  DECLARE v_conversions_week INT DEFAULT 0;
  DECLARE v_conversions_month INT DEFAULT 0;
  DECLARE v_detail_completion_rate DECIMAL(5,2) DEFAULT 0.00;
  DECLARE v_conversion_rate DECIMAL(5,2) DEFAULT 0.00;
  DECLARE v_calls_today INT DEFAULT 0;
  DECLARE v_calls_week INT DEFAULT 0;
  DECLARE v_overdue_tasks INT DEFAULT 0;
  
  -- Calculate metrics
  SELECT COUNT(*) INTO v_total_leads FROM crm_leads WHERE assigned_agent_id = p_agent_id;
  SELECT COUNT(*) INTO v_leads_today FROM crm_leads WHERE assigned_agent_id = p_agent_id AND DATE(created_at) = CURDATE();
  SELECT COUNT(*) INTO v_leads_week FROM crm_leads WHERE assigned_agent_id = p_agent_id AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY);
  SELECT COUNT(*) INTO v_active_leads FROM crm_leads WHERE assigned_agent_id = p_agent_id AND lead_status IN ('new', 'contacted', 'qualified', 'proposal_sent', 'negotiating', 'customer_review', 'customer_follow_up', 'info_obtained_demo_pending');
  SELECT COUNT(*) INTO v_detailed_leads FROM crm_leads WHERE assigned_agent_id = p_agent_id AND email IS NOT NULL AND email != '' AND email NOT LIKE 'sample-009@example.com' AND email NOT LIKE 'sample-010@example.com' AND phone IS NOT NULL AND phone != '';
  SELECT COUNT(*) INTO v_incomplete_leads FROM crm_leads WHERE assigned_agent_id = p_agent_id AND NOT (email IS NOT NULL AND email != '' AND email NOT LIKE 'sample-009@example.com' AND email NOT LIKE 'sample-010@example.com' AND phone IS NOT NULL AND phone != '');
  SELECT COUNT(*) INTO v_missing_email FROM crm_leads WHERE assigned_agent_id = p_agent_id AND (email IS NULL OR email = '' OR email LIKE 'sample-009@example.com' OR email LIKE 'sample-010@example.com');
  SELECT COUNT(*) INTO v_missing_phone FROM crm_leads WHERE assigned_agent_id = p_agent_id AND (phone IS NULL OR phone = '');
  SELECT COUNT(*) INTO v_conversions_today FROM crm_leads WHERE assigned_agent_id = p_agent_id AND DATE(converted_at) = CURDATE() AND converted_to_account_id IS NOT NULL;
  SELECT COUNT(*) INTO v_conversions_week FROM crm_leads WHERE assigned_agent_id = p_agent_id AND converted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND converted_to_account_id IS NOT NULL;
  SELECT COUNT(*) INTO v_conversions_month FROM crm_leads WHERE assigned_agent_id = p_agent_id AND MONTH(converted_at) = MONTH(NOW()) AND YEAR(converted_at) = YEAR(NOW()) AND converted_to_account_id IS NOT NULL;
  
  -- Calculate rates
  IF v_total_leads > 0 THEN
    SET v_detail_completion_rate = (v_detailed_leads / v_total_leads) * 100;
    SET v_conversion_rate = (v_conversions_month / v_total_leads) * 100;
  END IF;
  
  SELECT COUNT(*) INTO v_calls_today FROM crm_call_logs WHERE agent_id = p_agent_id AND DATE(call_date) = CURDATE();
  SELECT COUNT(*) INTO v_calls_week FROM crm_call_logs WHERE agent_id = p_agent_id AND call_date >= DATE_SUB(NOW(), INTERVAL 7 DAY);
  
  -- Overdue tasks (with error handling)
  BEGIN
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION SET v_overdue_tasks = 0;
    SELECT COUNT(*) INTO v_overdue_tasks FROM crm_tasks WHERE agent_id = p_agent_id AND status = 'pending' AND due_date < NOW();
  END;
  
  -- Upsert into cache
  INSERT INTO crm_agent_performance_cache (
    agent_id, leads_today, leads_week, active_leads, total_leads,
    detailed_leads, incomplete_leads, detail_completion_rate, missing_email, missing_phone,
    conversions_today, conversions_week, conversions_month, conversion_rate,
    calls_today, calls_week, overdue_tasks
  ) VALUES (
    p_agent_id, v_leads_today, v_leads_week, v_active_leads, v_total_leads,
    v_detailed_leads, v_incomplete_leads, v_detail_completion_rate, v_missing_email, v_missing_phone,
    v_conversions_today, v_conversions_week, v_conversions_month, v_conversion_rate,
    v_calls_today, v_calls_week, v_overdue_tasks
  )
  ON DUPLICATE KEY UPDATE
    leads_today = v_leads_today,
    leads_week = v_leads_week,
    active_leads = v_active_leads,
    total_leads = v_total_leads,
    detailed_leads = v_detailed_leads,
    incomplete_leads = v_incomplete_leads,
    detail_completion_rate = v_detail_completion_rate,
    missing_email = v_missing_email,
    missing_phone = v_missing_phone,
    conversions_today = v_conversions_today,
    conversions_week = v_conversions_week,
    conversions_month = v_conversions_month,
    conversion_rate = v_conversion_rate,
    calls_today = v_calls_today,
    calls_week = v_calls_week,
    overdue_tasks = v_overdue_tasks,
    last_updated = CURRENT_TIMESTAMP;
END$$
DELIMITER ;


