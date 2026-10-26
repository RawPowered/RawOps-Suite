-- Migration: Sales Agent Business Cards System
-- Date: 2025-10-25
-- Description: Complete digital business card system with QR codes, vCard support, and social media links

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 0;

-- Core business card table
CREATE TABLE IF NOT EXISTS `sales_agent_business_cards` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_id` BIGINT(20) UNSIGNED NOT NULL COMMENT 'References users.id',
  `slug` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'URL-friendly identifier',
  `profile_photo_path` VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Path to profile photo',
  `job_title` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT 'Sales Agent' COMMENT 'Job title/position',
  `bio` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Brief bio or tagline',
  `theme_color` VARCHAR(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT '#0369a1' COMMENT 'Hex color for card theme',
  `custom_message` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Optional custom message',
  `show_email` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Display email on card',
  `show_phone` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Display phone on card',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Card is publicly visible',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_agent_id` (`agent_id`),
  UNIQUE KEY `idx_slug` (`slug`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sales agent digital business cards';

-- Social media and contact links
CREATE TABLE IF NOT EXISTS `business_card_social_links` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `card_id` BIGINT(20) UNSIGNED NOT NULL COMMENT 'References sales_agent_business_cards.id',
  `platform` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Platform name: facebook, instagram, linkedin, x, tiktok, youtube, website, custom',
  `url` VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Link URL',
  `display_label` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Custom display label',
  `icon_class` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Font Awesome icon class',
  `display_order` INT(11) NOT NULL DEFAULT 0 COMMENT 'Sort order for display',
  `is_visible` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Show this link',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_card_id` (`card_id`),
  KEY `idx_platform` (`platform`),
  KEY `idx_display_order` (`display_order`),
  KEY `idx_visible` (`is_visible`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Social media links for business cards';

-- QR code tracking and analytics
CREATE TABLE IF NOT EXISTS `business_card_qr_codes` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `card_id` BIGINT(20) UNSIGNED NOT NULL COMMENT 'References sales_agent_business_cards.id',
  `qr_image_path` VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Path to QR code image',
  `qr_url` VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'URL the QR points to',
  `scan_count` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total card views',
  `vcard_download_count` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'vCard downloads',
  `signup_conversion_count` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Signups from card',
  `sample_request_count` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Sample requests from card',
  `last_scanned_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Most recent view',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_card_id` (`card_id`),
  KEY `idx_scan_count` (`scan_count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='QR code tracking and statistics';

-- Business card view log (for detailed analytics)
CREATE TABLE IF NOT EXISTS `business_card_views` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `card_id` BIGINT(20) UNSIGNED NOT NULL COMMENT 'References sales_agent_business_cards.id',
  `viewer_ip` VARCHAR(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'IP address of viewer',
  `viewer_user_agent` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Browser user agent',
  `referer` VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'HTTP referer',
  `action_type` ENUM('view','vcard_download','signup_click','sample_click') CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'view',
  `session_id` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Session identifier',
  `viewed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_card_id` (`card_id`),
  KEY `idx_viewed_at` (`viewed_at`),
  KEY `idx_action_type` (`action_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Detailed view tracking for business cards';

-- Auto-create business cards for existing sales agents
INSERT INTO `sales_agent_business_cards` (`agent_id`, `slug`, `job_title`, `is_active`)
SELECT 
  u.id,
  LOWER(CONCAT(
    REPLACE(REPLACE(REPLACE(TRIM(u.first_name), ' ', '-'), '.', ''), '''', ''),
    '-',
    REPLACE(REPLACE(REPLACE(TRIM(u.last_name), ' ', '-'), '.', ''), '''', '')
  )) AS slug,
  'Sales Agent' AS job_title,
  1 AS is_active
FROM users u
INNER JOIN user_roles ur ON ur.user_id = u.id
WHERE ur.role_id = 2 -- Sales Agent role
AND NOT EXISTS (
  SELECT 1 FROM sales_agent_business_cards sabc WHERE sabc.agent_id = u.id
)
ON DUPLICATE KEY UPDATE sales_agent_business_cards.agent_id = VALUES(agent_id);

-- Handle slug collisions by appending agent_id
UPDATE sales_agent_business_cards sabc1
JOIN sales_agent_business_cards sabc2 ON sabc1.slug = sabc2.slug AND sabc1.id > sabc2.id
SET sabc1.slug = CONCAT(sabc1.slug, '-', sabc1.agent_id);

-- Create QR code records for all business cards (images generated on-demand)
INSERT INTO `business_card_qr_codes` (`card_id`, `qr_image_path`, `qr_url`)
SELECT 
  sabc.id,
  CONCAT('/uploads/business-cards/qr/', sabc.id, '.png') AS qr_image_path,
  CONCAT('https://product.example/agent/', sabc.slug, '/card') AS qr_url
FROM sales_agent_business_cards sabc
WHERE NOT EXISTS (
  SELECT 1 FROM business_card_qr_codes bcqr WHERE bcqr.card_id = sabc.id
)
ON DUPLICATE KEY UPDATE business_card_qr_codes.card_id = VALUES(card_id);

SET FOREIGN_KEY_CHECKS = 1;

-- Migration complete

