-- ============================================================================
-- SACP Lead Tagging & Enhanced Views System
-- Version: 1.0.0
-- Date: 2025-10-15
-- Prefix: sacp1015_
-- ============================================================================
-- Binary-safe migration: Can be run multiple times safely
-- Uses INSERT...ON DUPLICATE KEY UPDATE pattern
-- Proper character encoding and foreign key handling
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================================
-- 1. LEAD TAG SYSTEM (Business Type Tags)
-- ============================================================================

-- Predefined business type tags (admin-managed)
CREATE TABLE IF NOT EXISTS `crm_lead_tags` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tag_name` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tag_slug` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tag_color` VARCHAR(7) DEFAULT '#6b7280',
  `tag_icon` VARCHAR(50) DEFAULT '🏪',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `auto_detect_keywords` JSON NULL COMMENT 'Array of keywords for auto-detection',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tag_slug` (`tag_slug`),
  KEY `idx_tag_active` (`is_active`),
  KEY `idx_tag_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed predefined business type tags
INSERT INTO `crm_lead_tags` (`tag_name`, `tag_slug`, `tag_color`, `tag_icon`, `sort_order`, `auto_detect_keywords`) VALUES
('retail partners', 'smoke-shop', '#ef4444', '🚬', 1, JSON_ARRAY('smoke', 'vape', 'vapor', 'tobacco', 'cigar', 'hookah', 'smoking')),
('partner Bar', 'partner-bar', '#8b5cf6', '🥥', 2, JSON_ARRAY('partner', 'partner bar', 'partner lounge', 'partner cafe')),
('Product Bar', 'product-bar', '#10b981', '🌿', 3, JSON_ARRAY('product', 'product bar', 'product lounge', 'botanical')),
('Head Shop', 'head-shop', '#f59e0b', '🎨', 4, JSON_ARRAY('head shop', 'headshop', 'glass', 'pipe', 'bong')),
('specialty retailer', 'specialty retailer', '#06b6d4', '🌱', 5, JSON_ARRAY('specialty retailer', 'product', 'product', 'medical', 'recreational', 'product', 'product')),
('Convenience Store', 'convenience-store', '#84cc16', '🏪', 6, JSON_ARRAY('convenience', 'c-store', 'gas station', 'gas', 'market', '7-eleven', 'circle k')),
('Herbal Shop', 'herbal-shop', '#14b8a6', '🍵', 7, JSON_ARRAY('herbal', 'herb', 'tea', 'natural', 'wellness', 'health')),
('Other', 'other', '#6b7280', '📍', 99, JSON_ARRAY())
ON DUPLICATE KEY UPDATE
  `tag_name` = VALUES(`tag_name`),
  `tag_color` = VALUES(`tag_color`),
  `tag_icon` = VALUES(`tag_icon`),
  `sort_order` = VALUES(`sort_order`),
  `auto_detect_keywords` = VALUES(`auto_detect_keywords`);

-- Lead to tag assignments (many-to-many)
CREATE TABLE IF NOT EXISTS `crm_lead_tag_assignments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lead_id` BIGINT UNSIGNED NOT NULL,
  `tag_id` BIGINT UNSIGNED NOT NULL,
  `assigned_by_agent_id` BIGINT UNSIGNED NULL COMMENT 'NULL if auto-assigned',
  `is_auto_assigned` TINYINT(1) NOT NULL DEFAULT 0,
  `confidence_score` DECIMAL(5,2) NULL COMMENT 'Auto-detection confidence 0-100',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_lead_tag` (`lead_id`, `tag_id`),
  KEY `idx_tag_lead` (`lead_id`),
  KEY `idx_tag_assignment` (`tag_id`),
  KEY `idx_tag_agent` (`assigned_by_agent_id`),
  CONSTRAINT `fk_lta_lead` FOREIGN KEY (`lead_id`) REFERENCES `crm_leads`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lta_tag` FOREIGN KEY (`tag_id`) REFERENCES `crm_lead_tags`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lta_agent` FOREIGN KEY (`assigned_by_agent_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Agent custom tags (private, per-agent)
CREATE TABLE IF NOT EXISTS `crm_agent_custom_tags` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_id` BIGINT UNSIGNED NOT NULL,
  `tag_name` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tag_color` VARCHAR(7) DEFAULT '#9ca3af',
  `lead_count` INT NOT NULL DEFAULT 0 COMMENT 'Cached count, updated via trigger',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_agent_custom_tag` (`agent_id`, `tag_name`),
  KEY `idx_custom_tag_agent` (`agent_id`),
  CONSTRAINT `fk_act_agent` FOREIGN KEY (`agent_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Custom tag assignments (links leads to agent's custom tags)
CREATE TABLE IF NOT EXISTS `crm_agent_custom_tag_assignments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lead_id` BIGINT UNSIGNED NOT NULL,
  `custom_tag_id` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_lead_custom_tag` (`lead_id`, `custom_tag_id`),
  KEY `idx_lcta_lead` (`lead_id`),
  KEY `idx_lcta_tag` (`custom_tag_id`),
  CONSTRAINT `fk_lcta_lead` FOREIGN KEY (`lead_id`) REFERENCES `crm_leads`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lcta_custom_tag` FOREIGN KEY (`custom_tag_id`) REFERENCES `crm_agent_custom_tags`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tag suggestions (popular custom tags suggested to admin)
CREATE TABLE IF NOT EXISTS `crm_tag_suggestions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tag_name` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `usage_count` INT NOT NULL DEFAULT 0 COMMENT 'Total times used across agents',
  `agent_count` INT NOT NULL DEFAULT 0 COMMENT 'Number of unique agents using this',
  `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
  `reviewed_by_admin_id` BIGINT UNSIGNED NULL,
  `reviewed_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_suggestion_tag` (`tag_name`),
  KEY `idx_suggestion_status` (`status`),
  KEY `idx_suggestion_count` (`agent_count`),
  CONSTRAINT `fk_ts_admin` FOREIGN KEY (`reviewed_by_admin_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. DYNAMIC LEAD STAGES (Admin-Configurable Pipeline)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `crm_lead_stages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stage_key` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Unique key for code reference',
  `stage_label` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `stage_color` VARCHAR(7) DEFAULT '#6b7280',
  `stage_icon` VARCHAR(50) DEFAULT '📋',
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Default stage for new leads',
  `is_closed_won` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Marks successful conversion',
  `is_closed_lost` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Marks failed conversion',
  `description` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_stage_key` (`stage_key`),
  KEY `idx_stage_active` (`is_active`),
  KEY `idx_stage_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed lead stages from existing crm_leads ENUM values
INSERT INTO `crm_lead_stages` (`stage_key`, `stage_label`, `stage_color`, `stage_icon`, `sort_order`, `is_default`, `is_closed_won`, `is_closed_lost`, `description`) VALUES
('new', 'New', '#3b82f6', '✨', 1, 1, 0, 0, 'Newly created lead, not yet contacted'),
('contacted', 'Contacted', '#8b5cf6', '📞', 2, 0, 0, 0, 'Initial contact made with lead'),
('qualified', 'Qualified', '#a855f7', '✓', 3, 0, 0, 0, 'Lead meets qualification criteria'),
('proposal_sent', 'Proposal Sent', '#d946ef', '📄', 4, 0, 0, 0, 'Pricing proposal or quote sent'),
('negotiating', 'Negotiating', '#f59e0b', '💬', 5, 0, 0, 0, 'In active negotiation with lead'),
('won', 'Won', '#10b981', '🎉', 6, 0, 1, 0, 'Successfully converted to customer'),
('lost', 'Lost', '#ef4444', '❌', 7, 0, 0, 1, 'Lead lost or declined'),
('dormant', 'Dormant', '#6b7280', '💤', 8, 0, 0, 0, 'No recent activity, on hold'),
('info_not_gotten', 'Info Not Obtained', '#f97316', '⚠️', 9, 0, 0, 0, 'Unable to get necessary information'),
('info_obtained_demo_dropped', 'Demo Dropped', '#06b6d4', '📦', 10, 0, 0, 0, 'Information obtained, demo product dropped off'),
('info_obtained_demo_pending', 'Demo Pending', '#0ea5e9', '⏳', 11, 0, 0, 0, 'Information obtained, awaiting demo drop-off'),
('drop_successful', 'Drop Successful', '#84cc16', '✅', 12, 0, 0, 0, 'Demo successfully dropped off'),
('customer_review', 'Customer Review', '#a3e635', '⭐', 13, 0, 0, 0, 'Customer reviewing demo product'),
('customer_no_review', 'No Review', '#94a3b8', '👁️', 14, 0, 0, 0, 'Customer has not provided review'),
('customer_follow_up', 'Customer Follow-up', '#fbbf24', '🔄', 15, 0, 0, 0, 'Following up with customer after demo'),
('no_response_follow_up', 'No Response Follow-up', '#fb923c', '📭', 16, 0, 0, 0, 'No response, needs follow-up'),
('objection_legal', 'Objection: Legal', '#dc2626', '⚖️', 17, 0, 0, 1, 'Legal concerns or objections raised'),
('objection_price_reassign', 'Objection: Price (Reassign)', '#b91c1c', '💰', 18, 0, 0, 0, 'Price objection, may need reassignment'),
('objection_other_reassign', 'Objection: Other (Reassign)', '#991b1b', '🔀', 19, 0, 0, 0, 'Other objection, may need reassignment'),
('do_not_contact', 'Do Not Contact', '#450a0a', '🚫', 20, 0, 0, 1, 'Lead requested no further contact')
ON DUPLICATE KEY UPDATE
  `stage_label` = VALUES(`stage_label`),
  `stage_color` = VALUES(`stage_color`),
  `stage_icon` = VALUES(`stage_icon`),
  `sort_order` = VALUES(`sort_order`),
  `description` = VALUES(`description`);

-- ============================================================================
-- 3. PERFORMANCE & ANALYTICS TABLES
-- ============================================================================

-- Cache table for agent performance metrics (updated periodically)
CREATE TABLE IF NOT EXISTS `crm_agent_performance_cache` (
  `agent_id` BIGINT UNSIGNED NOT NULL,
  `leads_today` INT NOT NULL DEFAULT 0,
  `leads_week` INT NOT NULL DEFAULT 0,
  `active_leads` INT NOT NULL DEFAULT 0,
  `total_leads` INT NOT NULL DEFAULT 0,
  `detailed_leads` INT NOT NULL DEFAULT 0,
  `incomplete_leads` INT NOT NULL DEFAULT 0,
  `missing_email` INT NOT NULL DEFAULT 0,
  `missing_phone` INT NOT NULL DEFAULT 0,
  `conversions_today` INT NOT NULL DEFAULT 0,
  `conversions_week` INT NOT NULL DEFAULT 0,
  `conversions_month` INT NOT NULL DEFAULT 0,
  `conversion_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `calls_today` INT NOT NULL DEFAULT 0,
  `calls_week` INT NOT NULL DEFAULT 0,
  `overdue_tasks` INT NOT NULL DEFAULT 0,
  `last_updated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`agent_id`),
  CONSTRAINT `fk_apc_agent` FOREIGN KEY (`agent_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. TRIGGERS FOR AUTO-MAINTENANCE
-- ============================================================================

-- Trigger: Update custom tag lead count when assignment added
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS `trg_custom_tag_count_insert` 
AFTER INSERT ON `crm_agent_custom_tag_assignments`
FOR EACH ROW
BEGIN
  UPDATE `crm_agent_custom_tags` 
  SET `lead_count` = (
    SELECT COUNT(DISTINCT lead_id) 
    FROM `crm_agent_custom_tag_assignments` 
    WHERE custom_tag_id = NEW.custom_tag_id
  )
  WHERE id = NEW.custom_tag_id;
  
  -- Update tag suggestions
  INSERT INTO `crm_tag_suggestions` 
    (tag_name, usage_count, agent_count)
  SELECT 
    ct.tag_name,
    COUNT(DISTINCT cta.lead_id) as usage_count,
    COUNT(DISTINCT ct.agent_id) as agent_count
  FROM crm_agent_custom_tags ct
  JOIN crm_agent_custom_tag_assignments cta ON ct.id = cta.custom_tag_id
  WHERE ct.id = NEW.custom_tag_id
  GROUP BY ct.tag_name
  HAVING agent_count >= 2
  ON DUPLICATE KEY UPDATE
    usage_count = VALUES(usage_count),
    agent_count = VALUES(agent_count),
    updated_at = CURRENT_TIMESTAMP;
END$$
DELIMITER ;

-- Trigger: Update custom tag lead count when assignment deleted
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS `trg_custom_tag_count_delete` 
AFTER DELETE ON `crm_agent_custom_tag_assignments`
FOR EACH ROW
BEGIN
  UPDATE `crm_agent_custom_tags` 
  SET `lead_count` = (
    SELECT COUNT(DISTINCT lead_id) 
    FROM `crm_agent_custom_tag_assignments` 
    WHERE custom_tag_id = OLD.custom_tag_id
  )
  WHERE id = OLD.custom_tag_id;
END$$
DELIMITER ;

-- ============================================================================
-- 5. STORED PROCEDURE: Calculate Agent Performance
-- ============================================================================

DELIMITER $$
CREATE PROCEDURE IF NOT EXISTS `sp_update_agent_performance`(IN p_agent_id BIGINT UNSIGNED)
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
  DECLARE v_conversion_rate DECIMAL(5,2) DEFAULT 0.00;
  DECLARE v_calls_today INT DEFAULT 0;
  DECLARE v_calls_week INT DEFAULT 0;
  DECLARE v_overdue_tasks INT DEFAULT 0;
  
  -- Calculate metrics
  SELECT 
    COUNT(*) INTO v_total_leads
  FROM crm_leads WHERE assigned_agent_id = p_agent_id;
  
  SELECT 
    COUNT(*) INTO v_leads_today
  FROM crm_leads WHERE assigned_agent_id = p_agent_id AND DATE(created_at) = CURDATE();
  
  SELECT 
    COUNT(*) INTO v_leads_week
  FROM crm_leads WHERE assigned_agent_id = p_agent_id AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY);
  
  SELECT 
    COUNT(*) INTO v_active_leads
  FROM crm_leads 
  WHERE assigned_agent_id = p_agent_id 
    AND lead_status IN ('new', 'contacted', 'qualified', 'proposal_sent', 'negotiating', 'customer_review', 'customer_follow_up', 'info_obtained_demo_pending');
  
  SELECT 
    COUNT(*) INTO v_detailed_leads
  FROM crm_leads 
  WHERE assigned_agent_id = p_agent_id
    AND email IS NOT NULL AND email != ''
    AND email NOT LIKE 'sample-009@example.com'
    AND email NOT LIKE 'sample-010@example.com'
    AND phone IS NOT NULL AND phone != '';
  
  SELECT 
    COUNT(*) INTO v_incomplete_leads
  FROM crm_leads 
  WHERE assigned_agent_id = p_agent_id
    AND NOT (
      email IS NOT NULL AND email != ''
      AND email NOT LIKE 'sample-009@example.com'
      AND email NOT LIKE 'sample-010@example.com'
      AND phone IS NOT NULL AND phone != ''
    );
  
  SELECT 
    COUNT(*) INTO v_missing_email
  FROM crm_leads 
  WHERE assigned_agent_id = p_agent_id
    AND (email IS NULL OR email = '' OR email LIKE 'sample-009@example.com' OR email LIKE 'sample-010@example.com');
  
  SELECT 
    COUNT(*) INTO v_missing_phone
  FROM crm_leads 
  WHERE assigned_agent_id = p_agent_id
    AND (phone IS NULL OR phone = '');
  
  SELECT 
    COUNT(*) INTO v_conversions_today
  FROM crm_leads 
  WHERE assigned_agent_id = p_agent_id 
    AND DATE(converted_at) = CURDATE() 
    AND converted_to_account_id IS NOT NULL;
  
  SELECT 
    COUNT(*) INTO v_conversions_week
  FROM crm_leads 
  WHERE assigned_agent_id = p_agent_id 
    AND converted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    AND converted_to_account_id IS NOT NULL;
  
  SELECT 
    COUNT(*) INTO v_conversions_month
  FROM crm_leads 
  WHERE assigned_agent_id = p_agent_id 
    AND MONTH(converted_at) = MONTH(NOW())
    AND YEAR(converted_at) = YEAR(NOW())
    AND converted_to_account_id IS NOT NULL;
  
  IF v_total_leads > 0 THEN
    SET v_conversion_rate = (v_conversions_month / v_total_leads) * 100;
  END IF;
  
  SELECT 
    COUNT(*) INTO v_calls_today
  FROM crm_call_logs 
  WHERE agent_id = p_agent_id AND DATE(call_date) = CURDATE();
  
  SELECT 
    COUNT(*) INTO v_calls_week
  FROM crm_call_logs 
  WHERE agent_id = p_agent_id AND call_date >= DATE_SUB(NOW(), INTERVAL 7 DAY);
  
  SELECT 
    COUNT(*) INTO v_overdue_tasks
  FROM crm_tasks 
  WHERE agent_id = p_agent_id 
    AND status = 'pending' 
    AND due_date < NOW();
  
  -- Insert or update cache
  INSERT INTO crm_agent_performance_cache (
    agent_id, leads_today, leads_week, active_leads, total_leads,
    detailed_leads, incomplete_leads, missing_email, missing_phone,
    conversions_today, conversions_week, conversions_month, conversion_rate,
    calls_today, calls_week, overdue_tasks
  ) VALUES (
    p_agent_id, v_leads_today, v_leads_week, v_active_leads, v_total_leads,
    v_detailed_leads, v_incomplete_leads, v_missing_email, v_missing_phone,
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

-- ============================================================================
-- 6. INDEXES FOR PERFORMANCE
-- ============================================================================

-- Additional indexes for common queries
-- Note: Function-based indexes not supported in older MariaDB versions
-- These columns likely already have indexes from table creation
-- Adding composite indexes for common query patterns
CREATE INDEX IF NOT EXISTS `idx_leads_state_city` ON `crm_leads`(`state`, `city`);

COMMIT;

-- ============================================================================
-- MIGRATION COMPLETE
-- ============================================================================
-- Tables Created: 7
-- Triggers Created: 2
-- Stored Procedures: 1
-- Indexes Added: 1 (composite: state, city)
-- ============================================================================

