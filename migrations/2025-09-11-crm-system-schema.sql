-- ============================================================================
-- Product CRM System Database Schema
-- Creates comprehensive CRM functionality for sales agents and admin oversight
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================================
-- LEADS MANAGEMENT
-- ============================================================================

-- Leads table - prospects who haven't converted yet
CREATE TABLE IF NOT EXISTS `crm_leads` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `assigned_agent_id` BIGINT UNSIGNED NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `phone` VARCHAR(30) NULL,
  `company_name` VARCHAR(200) NULL,
  `website` VARCHAR(255) NULL,
  `address_line1` VARCHAR(200) NULL,
  `address_line2` VARCHAR(200) NULL,
  `city` VARCHAR(100) NULL,
  `state` VARCHAR(50) NULL,
  `zip_code` VARCHAR(20) NULL,
  `country` VARCHAR(100) DEFAULT 'USA',
  `lead_source` ENUM('website', 'referral', 'cold_call', 'trade_show', 'social_media', 'email_campaign', 'other') DEFAULT 'website',
  `lead_status` ENUM('new', 'contacted', 'qualified', 'proposal_sent', 'negotiating', 'won', 'lost', 'dormant') DEFAULT 'new',
  `lead_priority` ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
  `estimated_monthly_volume` DECIMAL(10,2) NULL,
  `estimated_deal_value` DECIMAL(10,2) NULL,
  `interest_level` TINYINT DEFAULT 5 COMMENT '1-10 scale',
  `next_follow_up` DATETIME NULL,
  `last_contact_date` DATETIME NULL,
  `converted_to_account_id` BIGINT UNSIGNED NULL,
  `converted_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_leads_email` (`email`),
  KEY `idx_leads_agent` (`assigned_agent_id`),
  KEY `idx_leads_status` (`lead_status`),
  KEY `idx_leads_priority` (`lead_priority`),
  KEY `idx_leads_follow_up` (`next_follow_up`),
  KEY `idx_leads_created` (`created_at`),
  CONSTRAINT `fk_leads_agent` FOREIGN KEY (`assigned_agent_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_leads_account` FOREIGN KEY (`converted_to_account_id`) REFERENCES `wholesale_accounts`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- CALL LOGS & COMMUNICATION TRACKING
-- ============================================================================

-- Call logs and communication history
CREATE TABLE IF NOT EXISTS `crm_call_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_id` BIGINT UNSIGNED NOT NULL,
  `lead_id` BIGINT UNSIGNED NULL,
  `account_id` BIGINT UNSIGNED NULL,
  `contact_type` ENUM('phone_call', 'email', 'meeting', 'text_message', 'video_call', 'other') NOT NULL,
  `direction` ENUM('inbound', 'outbound') NOT NULL,
  `call_outcome` ENUM('answered', 'no_answer', 'busy', 'voicemail', 'meeting_scheduled', 'sale_made', 'not_interested') NULL,
  `duration_minutes` INT DEFAULT 0,
  `subject` VARCHAR(255) NULL,
  `summary` TEXT NULL,
  `next_action` TEXT NULL,
  `call_date` DATETIME NOT NULL,
  `follow_up_required` BOOLEAN DEFAULT FALSE,
  `follow_up_date` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_calls_agent` (`agent_id`),
  KEY `idx_calls_lead` (`lead_id`),
  KEY `idx_calls_account` (`account_id`),
  KEY `idx_calls_date` (`call_date`),
  KEY `idx_calls_follow_up` (`follow_up_date`),
  CONSTRAINT `fk_calls_agent` FOREIGN KEY (`agent_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_calls_lead` FOREIGN KEY (`lead_id`) REFERENCES `crm_leads`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_calls_account` FOREIGN KEY (`account_id`) REFERENCES `wholesale_accounts`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- NOTES & DOCUMENTATION
-- ============================================================================

-- Notes for leads and accounts
CREATE TABLE IF NOT EXISTS `crm_notes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_id` BIGINT UNSIGNED NOT NULL,
  `lead_id` BIGINT UNSIGNED NULL,
  `account_id` BIGINT UNSIGNED NULL,
  `note_type` ENUM('general', 'call_summary', 'meeting_notes', 'follow_up', 'important', 'order_related', 'complaint') DEFAULT 'general',
  `title` VARCHAR(255) NULL,
  `content` TEXT NOT NULL,
  `is_private` BOOLEAN DEFAULT FALSE COMMENT 'Private notes only visible to agent and admins',
  `reminder_date` DATETIME NULL,
  `is_pinned` BOOLEAN DEFAULT FALSE,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notes_agent` (`agent_id`),
  KEY `idx_notes_lead` (`lead_id`),
  KEY `idx_notes_account` (`account_id`),
  KEY `idx_notes_type` (`note_type`),
  KEY `idx_notes_reminder` (`reminder_date`),
  KEY `idx_notes_created` (`created_at`),
  CONSTRAINT `fk_notes_agent` FOREIGN KEY (`agent_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notes_lead` FOREIGN KEY (`lead_id`) REFERENCES `crm_leads`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notes_account` FOREIGN KEY (`account_id`) REFERENCES `wholesale_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- TASKS & FOLLOW-UPS
-- ============================================================================

-- Task management system for sales agents
CREATE TABLE IF NOT EXISTS `crm_tasks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_id` BIGINT UNSIGNED NOT NULL,
  `lead_id` BIGINT UNSIGNED NULL,
  `account_id` BIGINT UNSIGNED NULL,
  `task_type` ENUM('call', 'email', 'meeting', 'follow_up', 'proposal', 'demo', 'other') NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `priority` ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
  `status` ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
  `due_date` DATETIME NOT NULL,
  `completed_at` DATETIME NULL,
  `estimated_duration_minutes` INT DEFAULT 30,
  `actual_duration_minutes` INT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tasks_agent` (`agent_id`),
  KEY `idx_tasks_lead` (`lead_id`),
  KEY `idx_tasks_account` (`account_id`),
  KEY `idx_tasks_due` (`due_date`),
  KEY `idx_tasks_status` (`status`),
  KEY `idx_tasks_priority` (`priority`),
  CONSTRAINT `fk_tasks_agent` FOREIGN KEY (`agent_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tasks_lead` FOREIGN KEY (`lead_id`) REFERENCES `crm_leads`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tasks_account` FOREIGN KEY (`account_id`) REFERENCES `wholesale_accounts`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SALES PIPELINE & OPPORTUNITIES
-- ============================================================================

-- Sales opportunities tracking
CREATE TABLE IF NOT EXISTS `crm_opportunities` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_id` BIGINT UNSIGNED NOT NULL,
  `lead_id` BIGINT UNSIGNED NULL,
  `account_id` BIGINT UNSIGNED NULL,
  `opportunity_name` VARCHAR(255) NOT NULL,
  `estimated_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `probability_percent` TINYINT DEFAULT 50 COMMENT '0-100 percent chance',
  `stage` ENUM('prospecting', 'qualification', 'needs_analysis', 'proposal', 'negotiation', 'closed_won', 'closed_lost') DEFAULT 'prospecting',
  `close_date` DATE NULL,
  `actual_close_date` DATE NULL,
  `actual_value` DECIMAL(10,2) NULL,
  `products_interested` JSON NULL COMMENT 'Array of product IDs',
  `decision_maker` VARCHAR(255) NULL,
  `competition` TEXT NULL,
  `next_steps` TEXT NULL,
  `loss_reason` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_opp_agent` (`agent_id`),
  KEY `idx_opp_lead` (`lead_id`),
  KEY `idx_opp_account` (`account_id`),
  KEY `idx_opp_stage` (`stage`),
  KEY `idx_opp_close_date` (`close_date`),
  KEY `idx_opp_value` (`estimated_value`),
  CONSTRAINT `fk_opp_agent` FOREIGN KEY (`agent_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_opp_lead` FOREIGN KEY (`lead_id`) REFERENCES `crm_leads`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_opp_account` FOREIGN KEY (`account_id`) REFERENCES `wholesale_accounts`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- LEAD ASSIGNMENT & ROUTING
-- ============================================================================

-- Lead assignment history for tracking and admin oversight
CREATE TABLE IF NOT EXISTS `crm_lead_assignments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lead_id` BIGINT UNSIGNED NOT NULL,
  `from_agent_id` BIGINT UNSIGNED NULL,
  `to_agent_id` BIGINT UNSIGNED NULL,
  `assigned_by_admin_id` BIGINT UNSIGNED NULL,
  `assignment_reason` TEXT NULL,
  `assigned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_assignments_lead` (`lead_id`),
  KEY `idx_assignments_from` (`from_agent_id`),
  KEY `idx_assignments_to` (`to_agent_id`),
  KEY `idx_assignments_admin` (`assigned_by_admin_id`),
  KEY `idx_assignments_date` (`assigned_at`),
  CONSTRAINT `fk_assignments_lead` FOREIGN KEY (`lead_id`) REFERENCES `crm_leads`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_assignments_from` FOREIGN KEY (`from_agent_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_assignments_to` FOREIGN KEY (`to_agent_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_assignments_admin` FOREIGN KEY (`assigned_by_admin_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- PERFORMANCE TRACKING
-- ============================================================================

-- Sales agent performance metrics
CREATE TABLE IF NOT EXISTS `crm_agent_metrics` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_id` BIGINT UNSIGNED NOT NULL,
  `metric_date` DATE NOT NULL,
  `leads_assigned` INT DEFAULT 0,
  `leads_contacted` INT DEFAULT 0,
  `calls_made` INT DEFAULT 0,
  `emails_sent` INT DEFAULT 0,
  `meetings_held` INT DEFAULT 0,
  `proposals_sent` INT DEFAULT 0,
  `deals_won` INT DEFAULT 0,
  `deals_lost` INT DEFAULT 0,
  `revenue_generated` DECIMAL(10,2) DEFAULT 0.00,
  `conversion_rate` DECIMAL(5,2) DEFAULT 0.00,
  `average_deal_size` DECIMAL(10,2) DEFAULT 0.00,
  `pipeline_value` DECIMAL(10,2) DEFAULT 0.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_metrics_agent_date` (`agent_id`, `metric_date`),
  KEY `idx_metrics_date` (`metric_date`),
  CONSTRAINT `fk_metrics_agent` FOREIGN KEY (`agent_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SAMPLE DATA FOR TESTING
-- ============================================================================

-- Legacy named sample records excluded from public source.

COMMIT;

-- ============================================================================
-- INDEXES FOR PERFORMANCE
-- ============================================================================

-- Additional indexes for common queries
ALTER TABLE `crm_leads` ADD INDEX `idx_leads_next_follow_up` (`next_follow_up`);
ALTER TABLE `crm_call_logs` ADD INDEX `idx_calls_outcome` (`call_outcome`);
ALTER TABLE `crm_notes` ADD INDEX `idx_notes_pinned` (`is_pinned`);
ALTER TABLE `crm_tasks` ADD INDEX `idx_tasks_overdue` (`due_date`, `status`);

-- ============================================================================
-- VIEWS FOR REPORTING
-- ============================================================================

-- Lead summary view for dashboards
CREATE OR REPLACE VIEW `crm_leads_summary` AS
SELECT 
    l.*,
    CONCAT(l.first_name, ' ', l.last_name) AS full_name,
    CONCAT(a.first_name, ' ', a.last_name) AS agent_name,
    DATEDIFF(NOW(), l.created_at) AS days_since_created,
    DATEDIFF(l.next_follow_up, NOW()) AS days_to_follow_up,
    (SELECT COUNT(*) FROM crm_call_logs cl WHERE cl.lead_id = l.id) AS total_calls,
    (SELECT COUNT(*) FROM crm_notes n WHERE n.lead_id = l.id) AS total_notes,
    (SELECT MAX(cl.call_date) FROM crm_call_logs cl WHERE cl.lead_id = l.id) AS last_call_date
FROM crm_leads l
LEFT JOIN users a ON a.id = l.assigned_agent_id;

-- Agent performance view
CREATE OR REPLACE VIEW `crm_agent_performance` AS
SELECT 
    u.id,
    CONCAT(u.first_name, ' ', u.last_name) AS agent_name,
    u.email,
    COUNT(l.id) AS total_leads,
    COUNT(CASE WHEN l.lead_status IN ('won', 'converted') THEN 1 END) AS won_leads,
    COUNT(CASE WHEN l.lead_status = 'lost' THEN 1 END) AS lost_leads,
    SUM(l.estimated_deal_value) AS pipeline_value,
    AVG(l.interest_level) AS avg_interest_level,
    (SELECT COUNT(*) FROM crm_call_logs cl WHERE cl.agent_id = u.id AND DATE(cl.call_date) = CURDATE()) AS calls_today,
    (SELECT COUNT(*) FROM crm_tasks t WHERE t.agent_id = u.id AND t.status = 'pending' AND t.due_date <= NOW()) AS overdue_tasks
FROM users u
LEFT JOIN crm_leads l ON l.assigned_agent_id = u.id
WHERE u.id IN (SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.name = 'sales_agent')
GROUP BY u.id;
