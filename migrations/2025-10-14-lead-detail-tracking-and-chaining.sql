-- Lead Detail Tracking and Chaining System
-- Date: 2025-10-14
-- Binary migration: Can be run multiple times safely
-- Tracks lead completion status, email/phone chaining for multi-location stores

START TRANSACTION;

-- ============================================================================
-- 1. CREATE CHAIN TABLES FIRST (needed for foreign keys)
-- ============================================================================

-- Email chains - link multiple leads with same email (multi-location stores)
CREATE TABLE IF NOT EXISTS `crm_lead_email_chains` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `shared_email` VARCHAR(190) NOT NULL COMMENT 'The email address shared by multiple leads',
  `master_lead_id` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Primary lead in this chain',
  `chain_notes` TEXT DEFAULT NULL COMMENT 'Notes about this email chain',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_shared_email` (`shared_email`),
  KEY `idx_master_lead` (`master_lead_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phone chains - link multiple leads with same phone (multi-location stores)
CREATE TABLE IF NOT EXISTS `crm_lead_phone_chains` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `shared_phone` VARCHAR(30) NOT NULL COMMENT 'The phone number shared by multiple leads',
  `master_lead_id` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Primary lead in this chain',
  `chain_notes` TEXT DEFAULT NULL COMMENT 'Notes about this phone chain',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_shared_phone` (`shared_phone`),
  KEY `idx_master_lead` (`master_lead_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. MODIFY crm_leads TABLE
-- ============================================================================

-- Drop unique constraint on email (allow duplicates for chaining)
SET @has_uk := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_leads' AND INDEX_NAME = 'uk_leads_email'
);
SET @sql := IF(@has_uk > 0, 'ALTER TABLE crm_leads DROP INDEX uk_leads_email', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Make email nullable and add detail tracking columns
ALTER TABLE `crm_leads`
  MODIFY COLUMN `email` VARCHAR(190) DEFAULT NULL COMMENT 'Email address, can be NULL or placeholder',
  ADD COLUMN IF NOT EXISTS `email_not_available` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Flag: email marked as not available' AFTER `email`,
  ADD COLUMN IF NOT EXISTS `phone_not_available` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Flag: phone marked as not available' AFTER `phone`,
  ADD COLUMN IF NOT EXISTS `email_completed_at` DATETIME DEFAULT NULL COMMENT 'When valid email was first added' AFTER `email_not_available`,
  ADD COLUMN IF NOT EXISTS `phone_completed_at` DATETIME DEFAULT NULL COMMENT 'When valid phone was first added' AFTER `phone_not_available`,
  ADD COLUMN IF NOT EXISTS `details_completed_at` DATETIME DEFAULT NULL COMMENT 'When both email and phone completed' AFTER `phone_completed_at`,
  ADD COLUMN IF NOT EXISTS `email_chain_id` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Links to email chain if applicable' AFTER `details_completed_at`,
  ADD COLUMN IF NOT EXISTS `phone_chain_id` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Links to phone chain if applicable' AFTER `email_chain_id`;

-- Add indexes for performance
ALTER TABLE `crm_leads`
  ADD KEY IF NOT EXISTS `idx_email_completed` (`email_completed_at`),
  ADD KEY IF NOT EXISTS `idx_phone_completed` (`phone_completed_at`),
  ADD KEY IF NOT EXISTS `idx_details_completed` (`details_completed_at`),
  ADD KEY IF NOT EXISTS `idx_email_chain` (`email_chain_id`),
  ADD KEY IF NOT EXISTS `idx_phone_chain` (`phone_chain_id`),
  ADD KEY IF NOT EXISTS `idx_email_not_available` (`email_not_available`),
  ADD KEY IF NOT EXISTS `idx_phone_not_available` (`phone_not_available`);

-- Add foreign key constraints to chain tables
SET @has_fk_email := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_leads' AND CONSTRAINT_NAME = 'fk_lead_email_chain'
);
SET @sql := IF(@has_fk_email = 0,
  'ALTER TABLE crm_leads ADD CONSTRAINT fk_lead_email_chain FOREIGN KEY (email_chain_id) REFERENCES crm_lead_email_chains(id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk_phone := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_leads' AND CONSTRAINT_NAME = 'fk_lead_phone_chain'
);
SET @sql := IF(@has_fk_phone = 0,
  'ALTER TABLE crm_leads ADD CONSTRAINT fk_lead_phone_chain FOREIGN KEY (phone_chain_id) REFERENCES crm_lead_phone_chains(id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ============================================================================
-- 3. BACKFILL EXISTING DATA
-- ============================================================================

-- Generate placeholder emails for leads with NULL or empty email
UPDATE `crm_leads`
SET 
  `email` = CONCAT('no-email-', id, '@unavailable.internal'),
  `email_not_available` = 1
WHERE `email` IS NULL OR `email` = '';

-- Mark existing invalid/gibberish emails as not available
UPDATE `crm_leads`
SET `email_not_available` = 1
WHERE `email_not_available` = 0
  AND (
    `email` LIKE 'sample-010@example.com'
    OR `email` LIKE 'lead+%'
    OR `email` LIKE 'auto+%'
    OR `email` LIKE 'no-email-%@%'
  );

-- Set email_completed_at for leads with existing valid emails (excluding invalid patterns)
UPDATE `crm_leads`
SET `email_completed_at` = `created_at`
WHERE `email` IS NOT NULL 
  AND `email` != '' 
  AND `email` NOT LIKE 'sample-009@example.com'
  AND `email` NOT LIKE 'sample-010@example.com'
  AND `email` NOT LIKE 'lead+%'
  AND `email` NOT LIKE 'auto+%'
  AND `email` NOT LIKE 'no-email-%'
  AND `email_completed_at` IS NULL;

-- Set phone_completed_at for leads with existing valid phones
UPDATE `crm_leads`
SET `phone_completed_at` = `created_at`
WHERE `phone` IS NOT NULL 
  AND `phone` != ''
  AND `phone_completed_at` IS NULL;

-- Set details_completed_at when both email and phone exist
UPDATE `crm_leads`
SET `details_completed_at` = GREATEST(
  COALESCE(`email_completed_at`, `created_at`), 
  COALESCE(`phone_completed_at`, `created_at`)
)
WHERE `email_completed_at` IS NOT NULL 
  AND `phone_completed_at` IS NOT NULL
  AND `details_completed_at` IS NULL;

-- ============================================================================
-- 4. CREATE EMAIL CHAINS FOR EXISTING DUPLICATES
-- ============================================================================

-- Insert distinct emails that appear more than once (excluding placeholders and invalid emails)
INSERT INTO `crm_lead_email_chains` (`shared_email`, `master_lead_id`, `chain_notes`)
SELECT 
  l.email,
  MIN(l.id) as master_lead_id,
  CONCAT('Auto-created chain with ', COUNT(*), ' leads') as chain_notes
FROM `crm_leads` l
WHERE l.email IS NOT NULL 
  AND l.email NOT LIKE 'sample-009@example.com'
  AND l.email NOT LIKE 'sample-010@example.com'
  AND l.email NOT LIKE 'lead+%'
  AND l.email NOT LIKE 'auto+%'
  AND l.email NOT LIKE 'no-email-%'
GROUP BY l.email
HAVING COUNT(*) > 1
ON DUPLICATE KEY UPDATE `shared_email` = `shared_email`;

-- Link leads to their email chains (only valid emails)
UPDATE `crm_leads` l
INNER JOIN `crm_lead_email_chains` c ON l.email = c.shared_email
SET l.email_chain_id = c.id
WHERE l.email_chain_id IS NULL
  AND l.email NOT LIKE 'sample-009@example.com'
  AND l.email NOT LIKE 'sample-010@example.com'
  AND l.email NOT LIKE 'lead+%'
  AND l.email NOT LIKE 'auto+%'
  AND l.email NOT LIKE 'no-email-%';

-- ============================================================================
-- 5. CREATE PHONE CHAINS FOR EXISTING DUPLICATES
-- ============================================================================

-- Insert distinct phones that appear more than once
INSERT INTO `crm_lead_phone_chains` (`shared_phone`, `master_lead_id`, `chain_notes`)
SELECT 
  l.phone,
  MIN(l.id) as master_lead_id,
  CONCAT('Auto-created chain with ', COUNT(*), ' leads') as chain_notes
FROM `crm_leads` l
WHERE l.phone IS NOT NULL AND l.phone != ''
GROUP BY l.phone
HAVING COUNT(*) > 1
ON DUPLICATE KEY UPDATE `shared_phone` = `shared_phone`;

-- Link leads to their phone chains
UPDATE `crm_leads` l
INNER JOIN `crm_lead_phone_chains` c ON l.phone = c.shared_phone
SET l.phone_chain_id = c.id
WHERE l.phone_chain_id IS NULL
  AND l.phone IS NOT NULL
  AND l.phone != '';

-- ============================================================================
-- 6. CREATE VIEW FOR LEAD DETAIL STATISTICS
-- ============================================================================

CREATE OR REPLACE VIEW `crm_leads_detail_status` AS
SELECT 
  l.id,
  l.assigned_agent_id,
  l.created_at,
  l.email,
  l.phone,
  l.email_not_available,
  l.phone_not_available,
  l.email_completed_at,
  l.phone_completed_at,
  l.details_completed_at,
  l.email_chain_id,
  l.phone_chain_id,
  -- Check if email is valid (not placeholder, not gibberish)
  (
    l.email IS NOT NULL 
    AND l.email != '' 
    AND l.email NOT LIKE 'sample-009@example.com'
    AND l.email NOT LIKE 'sample-010@example.com'
    AND l.email NOT LIKE 'lead+%'
    AND l.email NOT LIKE 'auto+%'
    AND l.email NOT LIKE 'no-email-%'
  ) AS email_is_valid,
  -- Determine if lead is detailed (has both email and phone or marked unavailable)
  (
    (
      (
        l.email IS NOT NULL 
        AND l.email != '' 
        AND l.email NOT LIKE 'sample-009@example.com'
        AND l.email NOT LIKE 'sample-010@example.com'
        AND l.email NOT LIKE 'lead+%'
        AND l.email NOT LIKE 'auto+%'
        AND l.email NOT LIKE 'no-email-%'
      ) 
      OR l.email_not_available = 1
    ) 
    AND 
    (l.phone IS NOT NULL AND l.phone != '' OR l.phone_not_available = 1)
  ) AS is_detailed_lead,
  -- Has email info (valid email or marked unavailable)
  (
    (
      l.email IS NOT NULL 
      AND l.email != '' 
      AND l.email NOT LIKE 'sample-009@example.com'
      AND l.email NOT LIKE 'sample-010@example.com'
      AND l.email NOT LIKE 'lead+%'
      AND l.email NOT LIKE 'auto+%'
      AND l.email NOT LIKE 'no-email-%'
    ) 
    OR l.email_not_available = 1
  ) AS has_email,
  -- Has phone info (valid phone or marked unavailable)
  (l.phone IS NOT NULL AND l.phone != '' OR l.phone_not_available = 1) AS has_phone,
  -- Time to complete email (days)
  CASE 
    WHEN l.email_completed_at IS NOT NULL THEN DATEDIFF(l.email_completed_at, l.created_at)
    WHEN l.email_not_available = 1 THEN 0
    ELSE NULL
  END AS days_to_email,
  -- Time to complete phone (days)
  CASE 
    WHEN l.phone_completed_at IS NOT NULL THEN DATEDIFF(l.phone_completed_at, l.created_at)
    WHEN l.phone_not_available = 1 THEN 0
    ELSE NULL
  END AS days_to_phone,
  -- Time to complete both (days)
  CASE 
    WHEN l.details_completed_at IS NOT NULL THEN DATEDIFF(l.details_completed_at, l.created_at)
    ELSE NULL
  END AS days_to_complete,
  -- Chain membership
  (l.email_chain_id IS NOT NULL) AS in_email_chain,
  (l.phone_chain_id IS NOT NULL) AS in_phone_chain
FROM `crm_leads` l;

COMMIT;

-- ============================================================================
-- VERIFICATION QUERIES (for testing)
-- ============================================================================
-- Run these after migration to verify:
-- SELECT COUNT(*) FROM crm_leads WHERE email IS NULL; -- Should be 0
-- SELECT COUNT(*) FROM crm_leads WHERE email_not_available = 1; -- Placeholder leads
-- SELECT COUNT(*) FROM crm_lead_email_chains; -- Number of email chains
-- SELECT COUNT(*) FROM crm_lead_phone_chains; -- Number of phone chains
-- SELECT is_detailed_lead, COUNT(*) FROM crm_leads_detail_status GROUP BY is_detailed_lead;

