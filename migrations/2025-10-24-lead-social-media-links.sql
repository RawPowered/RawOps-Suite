-- Migration: Lead Social Media Links System
-- Date: 2025-10-24
-- Description: Add social media links tracking for leads with Font Awesome icon support

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 0;

-- Create social media links table
CREATE TABLE IF NOT EXISTS `crm_lead_social_links` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `lead_id` BIGINT(20) UNSIGNED NOT NULL COMMENT 'Reference to crm_leads',
  `platform` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Platform name or custom',
  `url` VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Social media URL',
  `display_label` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Custom label for display',
  `icon_class` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Font Awesome icon class',
  `is_verified` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'URL verified as working',
  `display_order` INT(11) NOT NULL DEFAULT 0 COMMENT 'Sort order for display',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `created_by` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Agent who added',
  PRIMARY KEY (`id`),
  KEY `idx_lead_id` (`lead_id`),
  KEY `idx_platform` (`platform`),
  KEY `idx_display_order` (`display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Social media links for leads';

-- Add flags to crm_leads for marking contact methods as unavailable
ALTER TABLE `crm_leads`
ADD COLUMN IF NOT EXISTS `email_not_available` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Email not available for this lead' AFTER `email`,
ADD COLUMN IF NOT EXISTS `phone_not_available` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Phone not available for this lead' AFTER `phone`;

-- Add indexes only if they don't exist
SET @exist_email_idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'crm_leads' 
    AND INDEX_NAME = 'idx_email_unavailable');

SET @sql_email_idx := IF(@exist_email_idx = 0,
    'ALTER TABLE `crm_leads` ADD INDEX `idx_email_unavailable` (`email_not_available`)',
    'SELECT "Index idx_email_unavailable already exists" AS notice');

PREPARE stmt_email_idx FROM @sql_email_idx;
EXECUTE stmt_email_idx;
DEALLOCATE PREPARE stmt_email_idx;

SET @exist_phone_idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'crm_leads' 
    AND INDEX_NAME = 'idx_phone_unavailable');

SET @sql_phone_idx := IF(@exist_phone_idx = 0,
    'ALTER TABLE `crm_leads` ADD INDEX `idx_phone_unavailable` (`phone_not_available`)',
    'SELECT "Index idx_phone_unavailable already exists" AS notice');

PREPARE stmt_phone_idx FROM @sql_phone_idx;
EXECUTE stmt_phone_idx;
DEALLOCATE PREPARE stmt_phone_idx;

-- Add flag for social-media-only leads
ALTER TABLE `crm_leads`
ADD COLUMN IF NOT EXISTS `is_social_media_lead` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Lead contacted primarily via social media' AFTER `phone_not_available`;

-- Add index only if it doesn't exist
SET @exist_social_idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'crm_leads' 
    AND INDEX_NAME = 'idx_social_media_lead');

SET @sql_social_idx := IF(@exist_social_idx = 0,
    'ALTER TABLE `crm_leads` ADD INDEX `idx_social_media_lead` (`is_social_media_lead`)',
    'SELECT "Index idx_social_media_lead already exists" AS notice');

PREPARE stmt_social_idx FROM @sql_social_idx;
EXECUTE stmt_social_idx;
DEALLOCATE PREPARE stmt_social_idx;

-- Create table for social media interactions (DMs, comments, etc.)
CREATE TABLE IF NOT EXISTS `crm_social_interactions` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `lead_id` BIGINT(20) UNSIGNED NOT NULL COMMENT 'Reference to crm_leads',
  `agent_id` BIGINT(20) UNSIGNED NOT NULL COMMENT 'Agent who logged interaction',
  `platform` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Platform where interaction occurred',
  `interaction_type` ENUM('dm','comment','post','story','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'dm' COMMENT 'Type of interaction',
  `direction` ENUM('inbound','outbound') CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'outbound' COMMENT 'Direction of communication',
  `content` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Interaction content/notes',
  `screenshot_path` VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Path to uploaded screenshot',
  `screenshot_data` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Base64 screenshot data if inline',
  `interaction_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When interaction occurred',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lead_id` (`lead_id`),
  KEY `idx_agent_id` (`agent_id`),
  KEY `idx_platform` (`platform`),
  KEY `idx_interaction_date` (`interaction_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Social media interactions and DMs for leads';

-- Create or update view for lead detail statistics with enhanced social media consideration
DROP VIEW IF EXISTS `agent_lead_detail_statistics`;

CREATE VIEW `agent_lead_detail_statistics` AS
SELECT 
    l.assigned_agent_id AS agent_id,
    COUNT(l.id) AS total_leads,
    SUM(CASE 
        WHEN (
            -- Traditional contact: Has email OR marked as not available
            (l.email IS NOT NULL AND l.email != '') OR l.email_not_available = 1
        ) AND (
            -- Traditional contact: Has phone OR marked as not available
            (l.phone IS NOT NULL AND l.phone != '') OR l.phone_not_available = 1
        ) AND (
            -- If both email and phone unavailable, must have social media links
            NOT (l.email_not_available = 1 AND l.phone_not_available = 1) 
            OR EXISTS (SELECT 1 FROM crm_lead_social_links WHERE lead_id = l.id LIMIT 1)
        ) AND (
            -- Has company name or first/last name
            (l.company_name IS NOT NULL AND l.company_name != '') 
            OR (l.first_name IS NOT NULL AND l.first_name != '')
        ) AND (
            -- Has some address information
            (l.address IS NOT NULL AND l.address != '') 
            OR (l.address_line1 IS NOT NULL AND l.address_line1 != '')
            OR (l.city IS NOT NULL AND l.city != '')
        )
        THEN 1 
        -- OR: Social Media Exclusive Lead (new criteria)
        WHEN (
            -- Marked as social media lead AND has major platform links
            l.is_social_media_lead = 1
            AND EXISTS (
                SELECT 1 FROM crm_lead_social_links 
                WHERE lead_id = l.id 
                AND platform IN ('facebook', 'instagram', 'linkedin', 'x', 'twitter')
                LIMIT 1
            )
            AND (
                -- Has company name or first/last name
                (l.company_name IS NOT NULL AND l.company_name != '') 
                OR (l.first_name IS NOT NULL AND l.first_name != '')
            )
        )
        THEN 1 
        ELSE 0 
    END) AS detailed_leads,
    SUM(CASE 
        WHEN NOT (
            -- Traditional detailed lead
            (
                (l.email IS NOT NULL AND l.email != '') OR l.email_not_available = 1
            ) AND (
                (l.phone IS NOT NULL AND l.phone != '') OR l.phone_not_available = 1
            ) AND (
                NOT (l.email_not_available = 1 AND l.phone_not_available = 1) 
                OR EXISTS (SELECT 1 FROM crm_lead_social_links WHERE lead_id = l.id LIMIT 1)
            ) AND (
                (l.company_name IS NOT NULL AND l.company_name != '') 
                OR (l.first_name IS NOT NULL AND l.first_name != '')
            ) AND (
                (l.address IS NOT NULL AND l.address != '') 
                OR (l.address_line1 IS NOT NULL AND l.address_line1 != '')
                OR (l.city IS NOT NULL AND l.city != '')
            )
        ) AND NOT (
            -- Social media exclusive detailed lead
            l.is_social_media_lead = 1
            AND EXISTS (
                SELECT 1 FROM crm_lead_social_links 
                WHERE lead_id = l.id 
                AND platform IN ('facebook', 'instagram', 'linkedin', 'x', 'twitter')
                LIMIT 1
            )
            AND (
                (l.company_name IS NOT NULL AND l.company_name != '') 
                OR (l.first_name IS NOT NULL AND l.first_name != '')
            )
        )
        THEN 1 ELSE 0 
    END) AS incomplete_leads,
    ROUND(
        (SUM(CASE 
            WHEN (
                (l.email IS NOT NULL AND l.email != '') OR l.email_not_available = 1
            ) AND (
                (l.phone IS NOT NULL AND l.phone != '') OR l.phone_not_available = 1
            ) AND (
                NOT (l.email_not_available = 1 AND l.phone_not_available = 1) 
                OR EXISTS (SELECT 1 FROM crm_lead_social_links WHERE lead_id = l.id LIMIT 1)
            ) AND (
                (l.company_name IS NOT NULL AND l.company_name != '') 
                OR (l.first_name IS NOT NULL AND l.first_name != '')
            ) AND (
                (l.address IS NOT NULL AND l.address != '') 
                OR (l.address_line1 IS NOT NULL AND l.address_line1 != '')
                OR (l.city IS NOT NULL AND l.city != '')
            )
            THEN 1 
            -- OR social media exclusive lead
            WHEN (
                l.is_social_media_lead = 1
                AND EXISTS (
                    SELECT 1 FROM crm_lead_social_links 
                    WHERE lead_id = l.id 
                    AND platform IN ('facebook', 'instagram', 'linkedin', 'x', 'twitter')
                    LIMIT 1
                )
                AND (
                    (l.company_name IS NOT NULL AND l.company_name != '') 
                    OR (l.first_name IS NOT NULL AND l.first_name != '')
                )
            )
            THEN 1
            ELSE 0 
        END) / NULLIF(COUNT(l.id), 0)) * 100, 
    2) AS detail_completion_rate,
    SUM(CASE WHEN l.is_social_media_lead = 1 THEN 1 ELSE 0 END) AS social_media_only_leads
FROM crm_leads l
WHERE l.is_archived = 0
GROUP BY l.assigned_agent_id;

-- Insert default social media platform configurations
INSERT INTO `crm_lead_social_links` (`id`, `lead_id`, `platform`, `url`, `display_label`, `icon_class`, `is_verified`, `display_order`, `created_by`)
VALUES (0, 0, '_TEMPLATE_', '', NULL, NULL, 0, 0, NULL)
ON DUPLICATE KEY UPDATE `id` = `id`;

SET FOREIGN_KEY_CHECKS = 1;

-- Migration complete

