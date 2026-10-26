-- ============================================================================
-- Invoice Commission AI Restructure
-- Version: 1.0.0
-- Date: 2025-10-15
-- Prefix: commission_ai_
-- ============================================================================
-- Binary-safe migration: Can be run multiple times safely
-- Uses INSERT...ON DUPLICATE KEY UPDATE pattern
-- Proper character encoding and foreign key handling
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================================
-- 1. AI USAGE COSTS TABLE - Track AI API costs with 30-day review reminders
-- ============================================================================

CREATE TABLE IF NOT EXISTS `ai_usage_costs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `api_service` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Service name: gemini, sendgrid, google_places, etc.',
  `endpoint` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Specific endpoint or operation',
  `cost_per_unit` DECIMAL(10,6) NOT NULL DEFAULT 0.000000 COMMENT 'Cost per API call/unit',
  `unit_type` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'call' COMMENT 'call, email, sms, request',
  `last_verified_at` DATETIME DEFAULT NULL COMMENT 'When admin last verified pricing',
  `next_review_due` DATE DEFAULT NULL COMMENT 'Next review date (30 days after last verification)',
  `verified_by_admin_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Admin who verified',
  `notes` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_api_service_endpoint` (`api_service`, `endpoint`),
  KEY `idx_next_review` (`next_review_due`),
  KEY `idx_active` (`is_active`),
  KEY `fk_verified_by` (`verified_by_admin_id`),
  CONSTRAINT `fk_ai_usage_verified_by` FOREIGN KEY (`verified_by_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed initial AI costs
INSERT INTO `ai_usage_costs` (`api_service`, `endpoint`, `cost_per_unit`, `unit_type`, `last_verified_at`, `next_review_due`, `notes`, `is_active`) VALUES
('gemini', 'generateContent', 0.000500, 'call', NOW(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'Gemini Pro API - Standard generation', 1),
('gemini', 'streamGenerateContent', 0.000500, 'call', NOW(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'Gemini Pro API - Streaming generation', 1),
('sendgrid', 'send_email', 0.000950, 'email', NOW(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'SendGrid email sending cost estimate', 1),
('google_places', 'search', 0.017000, 'request', NOW(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'Google Places API search', 1),
('google_places', 'details', 0.017000, 'request', NOW(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'Google Places API place details', 1),
('twilio', 'sms', 0.007500, 'sms', NOW(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'Twilio SMS sending (estimated)', 1)
ON DUPLICATE KEY UPDATE
  `cost_per_unit` = VALUES(`cost_per_unit`),
  `last_verified_at` = IF(VALUES(`last_verified_at`) IS NOT NULL, VALUES(`last_verified_at`), `last_verified_at`),
  `next_review_due` = IF(VALUES(`next_review_due`) IS NOT NULL, VALUES(`next_review_due`), `next_review_due`),
  `notes` = IF(VALUES(`notes`) IS NOT NULL, VALUES(`notes`), `notes`),
  `is_active` = VALUES(`is_active`);

-- ============================================================================
-- 2. AGENT AI CHARGING SETTINGS - Per-agent AI usage charging configuration
-- ============================================================================

CREATE TABLE IF NOT EXISTS `agent_ai_charging_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_id` BIGINT UNSIGNED NOT NULL COMMENT 'Sales agent user ID',
  `charge_for_ai_calls` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Charge agent for AI chat/call usage',
  `charge_for_ai_emails` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Charge agent for AI email composition',
  `charge_for_ai_sms` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Charge agent for AI SMS messages',
  `enabled_start_date` DATE DEFAULT NULL COMMENT 'When charging starts (NULL = immediate)',
  `enabled_end_date` DATE DEFAULT NULL COMMENT 'When charging ends (NULL = indefinite)',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `notes` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_agent_charging` (`agent_id`),
  KEY `idx_active_agents` (`is_active`, `agent_id`),
  KEY `idx_date_range` (`enabled_start_date`, `enabled_end_date`),
  CONSTRAINT `fk_agent_charging_user` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. ORDER AI CHARGES - Per-invoice AI usage charges
-- ============================================================================

CREATE TABLE IF NOT EXISTS `order_ai_charges` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `agent_id` BIGINT UNSIGNED NOT NULL,
  `ai_call_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `ai_email_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `ai_sms_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_ai_cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `charged_to_agent` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether agent was charged for this usage',
  `posted_to_books` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether posted to agent books',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_ai_charge` (`order_id`),
  KEY `idx_agent_charges` (`agent_id`, `charged_to_agent`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `fk_order_ai_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_ai_agent` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. AGENT AI USAGE LOG - Detailed AI usage logging per agent
-- ============================================================================
-- IMPORTANT: Usage types clarification:
--   'call'  = AI voice agents making actual phone calls (future feature)
--   'email' = AI email composition and generation (currently active)
--   'sms'   = AI text messaging agents (future feature)
--   'other' = Miscellaneous AI usage
-- NOTE: The AI chat support tool (api/ai-chat.php) does NOT track here - it's free
-- ============================================================================

CREATE TABLE IF NOT EXISTS `agent_ai_usage_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Sales agent user ID (NULL for system/customer usage)',
  `usage_type` ENUM('call', 'email', 'sms', 'other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'call' COMMENT 'call=phone agents, email=email gen, sms=text agents',
  `usage_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `api_service` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `endpoint` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estimated_cost` DECIMAL(10,6) NOT NULL DEFAULT 0.000000,
  `order_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Associated order if applicable',
  `lead_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Associated lead if applicable',
  `session_id` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata` JSON DEFAULT NULL COMMENT 'Additional context',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_agent_usage` (`agent_id`, `usage_date`),
  KEY `idx_usage_type` (`usage_type`),
  KEY `idx_order` (`order_id`),
  KEY `idx_lead` (`lead_id`),
  KEY `idx_usage_date` (`usage_date`),
  CONSTRAINT `fk_agent_usage_agent` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_agent_usage_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_agent_usage_lead` FOREIGN KEY (`lead_id`) REFERENCES `crm_leads` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 5. MODIFY EXISTING TABLES
-- ============================================================================

-- Add AI cost review fields to commission_settings
ALTER TABLE `commission_settings` 
ADD COLUMN IF NOT EXISTS `ai_cost_review_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Enable 30-day AI cost review reminders' AFTER `description`,
ADD COLUMN IF NOT EXISTS `ai_cost_last_review` DATE DEFAULT NULL COMMENT 'Last AI cost review date' AFTER `ai_cost_review_enabled`,
ADD COLUMN IF NOT EXISTS `ai_cost_next_review` DATE DEFAULT NULL COMMENT 'Next AI cost review due date' AFTER `ai_cost_last_review`;

-- Add AI usage tracking to orders
ALTER TABLE `orders` 
ADD COLUMN IF NOT EXISTS `ai_usage_charged` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether AI usage was charged to agent' AFTER `commission_rate`,
ADD COLUMN IF NOT EXISTS `ai_usage_cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Total AI usage cost for this order' AFTER `ai_usage_charged`,
ADD COLUMN IF NOT EXISTS `ai_calls_used` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of AI calls used' AFTER `ai_usage_cost`,
ADD COLUMN IF NOT EXISTS `ai_emails_used` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of AI emails used' AFTER `ai_calls_used`,
ADD COLUMN IF NOT EXISTS `ai_sms_used` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of AI SMS used' AFTER `ai_emails_used`;

-- Add calculation basis to commissions table
ALTER TABLE `commissions` 
ADD COLUMN IF NOT EXISTS `calculation_basis` ENUM('subtotal', 'post_agent_commission', 'post_dev_commission') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'subtotal' COMMENT 'What amount was commission calculated from' AFTER `percentage`;

-- ============================================================================
-- 6. INITIALIZE COMMISSION SETTINGS FOR AI REVIEW
-- ============================================================================

INSERT INTO `commission_settings` (`setting_key`, `setting_value`, `description`, `ai_cost_review_enabled`, `ai_cost_last_review`, `ai_cost_next_review`)
VALUES ('ai_cost_review', '1', 'Enable 30-day AI cost review system', 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))
ON DUPLICATE KEY UPDATE 
  `ai_cost_review_enabled` = IF(`ai_cost_review_enabled` IS NULL, 1, `ai_cost_review_enabled`),
  `ai_cost_last_review` = IF(`ai_cost_last_review` IS NULL, CURDATE(), `ai_cost_last_review`),
  `ai_cost_next_review` = IF(`ai_cost_next_review` IS NULL, DATE_ADD(CURDATE(), INTERVAL 30 DAY), `ai_cost_next_review`);

-- ============================================================================
-- 7. CREATE VIEWS FOR REPORTING
-- ============================================================================

-- View: Agent AI usage summary
CREATE OR REPLACE VIEW `v_agent_ai_usage_summary` AS
SELECT 
    a.agent_id,
    u.first_name,
    u.last_name,
    u.email,
    a.usage_type,
    DATE(a.usage_date) AS usage_date,
    COUNT(*) AS usage_count,
    SUM(a.estimated_cost) AS daily_cost,
    a.api_service
FROM agent_ai_usage_log a
LEFT JOIN users u ON u.id = a.agent_id
GROUP BY a.agent_id, u.first_name, u.last_name, u.email, a.usage_type, DATE(a.usage_date), a.api_service;

-- View: Agent AI charging status
CREATE OR REPLACE VIEW `v_agent_ai_charging_status` AS
SELECT 
    u.id AS agent_id,
    u.first_name,
    u.last_name,
    u.email,
    COALESCE(s.charge_for_ai_calls, 0) AS charge_calls,
    COALESCE(s.charge_for_ai_emails, 0) AS charge_emails,
    COALESCE(s.charge_for_ai_sms, 0) AS charge_sms,
    s.enabled_start_date,
    s.enabled_end_date,
    CASE 
        WHEN s.is_active = 1 
        AND (s.enabled_start_date IS NULL OR s.enabled_start_date <= CURDATE())
        AND (s.enabled_end_date IS NULL OR s.enabled_end_date >= CURDATE())
        THEN 1 
        ELSE 0 
    END AS currently_charging,
    s.notes
FROM users u
LEFT JOIN user_roles ur ON ur.user_id = u.id
LEFT JOIN roles r ON r.id = ur.role_id
LEFT JOIN agent_ai_charging_settings s ON s.agent_id = u.id
WHERE r.name = 'sales_agent'
  AND u.status = 'active';

-- View: AI cost review status
CREATE OR REPLACE VIEW `v_ai_cost_review_status` AS
SELECT 
    c.api_service,
    c.endpoint,
    c.cost_per_unit,
    c.unit_type,
    c.last_verified_at,
    c.next_review_due,
    CASE 
        WHEN c.next_review_due < CURDATE() THEN 'overdue'
        WHEN c.next_review_due <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'due_soon'
        ELSE 'current'
    END AS review_status,
    DATEDIFF(c.next_review_due, CURDATE()) AS days_until_review,
    u.first_name AS verified_by_first,
    u.last_name AS verified_by_last,
    COUNT(l.id) AS usage_count_30d,
    SUM(l.estimated_cost) AS total_cost_30d
FROM ai_usage_costs c
LEFT JOIN users u ON u.id = c.verified_by_admin_id
LEFT JOIN agent_ai_usage_log l ON l.api_service = c.api_service 
    AND (l.endpoint = c.endpoint OR (l.endpoint IS NULL AND c.endpoint IS NULL))
    AND l.usage_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
WHERE c.is_active = 1
GROUP BY c.id, c.api_service, c.endpoint, c.cost_per_unit, c.unit_type, 
         c.last_verified_at, c.next_review_due, u.first_name, u.last_name;

COMMIT;

-- ============================================================================
-- Migration Complete
-- ============================================================================
-- Next steps:
-- 1. Update commission-utils.php for cascading calculations
-- 2. Update invoice interfaces to hide dev/AI fees from agents
-- 3. Create admin AI cost management interface
-- 4. Update API endpoints to log usage
-- ============================================================================

