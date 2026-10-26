-- Chain Store / Multi-Location Account System
-- Allows linking multiple wholesale accounts under chain relationships
-- Flexible invoice visibility groups and commission sharing

-- Add chain fields to wholesale_accounts
ALTER TABLE `wholesale_accounts` 
ADD COLUMN `chain_id` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Links locations in same chain',
ADD COLUMN `is_master_location` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Primary billing contact for chain',
ADD COLUMN `location_name` VARCHAR(255) DEFAULT NULL COMMENT 'Specific location identifier within chain',
ADD KEY `idx_chain_id` (`chain_id`);

-- Chain metadata table
CREATE TABLE IF NOT EXISTS `wholesale_account_chains` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `chain_name` VARCHAR(255) NOT NULL COMMENT 'Brand/chain name',
  `master_account_id` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Current master location',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `notes` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_master_account` (`master_account_id`),
  CONSTRAINT `fk_chain_master_account` FOREIGN KEY (`master_account_id`) REFERENCES `wholesale_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invoice visibility groups - defines which locations can see each other's invoices
CREATE TABLE IF NOT EXISTS `account_visibility_groups` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` BIGINT(20) UNSIGNED NOT NULL COMMENT 'Account that can view',
  `can_view_account_id` BIGINT(20) UNSIGNED NOT NULL COMMENT 'Account they can see invoices for',
  `granted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `granted_by_user_id` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Admin who granted access',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_visibility_pair` (`account_id`, `can_view_account_id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_can_view` (`can_view_account_id`),
  CONSTRAINT `fk_visibility_account` FOREIGN KEY (`account_id`) REFERENCES `wholesale_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_visibility_can_view` FOREIGN KEY (`can_view_account_id`) REFERENCES `wholesale_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_visibility_granted_by` FOREIGN KEY (`granted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Account contacts table - location-specific contacts beyond primary user
CREATE TABLE IF NOT EXISTS `account_contacts` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` BIGINT(20) UNSIGNED NOT NULL,
  `contact_type` ENUM('manager','owner','billing','shipping','other') DEFAULT 'manager',
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(190) DEFAULT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `can_place_orders` TINYINT(1) NOT NULL DEFAULT 0,
  `can_view_invoices` TINYINT(1) NOT NULL DEFAULT 1,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_email` (`email`),
  CONSTRAINT `fk_contact_account` FOREIGN KEY (`account_id`) REFERENCES `wholesale_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Commission splits table - for shared commission arrangements
CREATE TABLE IF NOT EXISTS `commission_splits` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` BIGINT(20) UNSIGNED NOT NULL COMMENT 'Specific account/location',
  `primary_agent_id` BIGINT(20) UNSIGNED NOT NULL COMMENT 'Primary agent (owns account)',
  `split_agent_id` BIGINT(20) UNSIGNED NOT NULL COMMENT 'Agent sharing commission',
  `split_percentage` DECIMAL(5,2) NOT NULL DEFAULT 50.00 COMMENT 'Percentage to split agent',
  `arrangement_notes` TEXT DEFAULT NULL,
  `effective_from` DATE NOT NULL,
  `effective_until` DATE DEFAULT NULL COMMENT 'NULL = ongoing',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by_user_id` BIGINT(20) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_primary_agent` (`primary_agent_id`),
  KEY `idx_split_agent` (`split_agent_id`),
  CONSTRAINT `fk_split_account` FOREIGN KEY (`account_id`) REFERENCES `wholesale_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_split_primary_agent` FOREIGN KEY (`primary_agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_split_secondary_agent` FOREIGN KEY (`split_agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_split_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Closed locations archive - stores full data when location closes/unlinks
CREATE TABLE IF NOT EXISTS `crm_closed_locations` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `original_account_id` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Original wholesale_account ID',
  `chain_id` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Chain it belonged to',
  `business_name` VARCHAR(255) NOT NULL,
  `location_name` VARCHAR(255) DEFAULT NULL,
  `address_line1` VARCHAR(255) DEFAULT NULL,
  `address_line2` VARCHAR(255) DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `state_region` VARCHAR(100) DEFAULT NULL,
  `postal_code` VARCHAR(20) DEFAULT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `email` VARCHAR(190) DEFAULT NULL,
  `sales_agent_id` BIGINT(20) UNSIGNED DEFAULT NULL,
  `closed_date` DATE NOT NULL,
  `closed_reason` ENUM('out_of_business','unlinked_from_chain','duplicate','sold','other') DEFAULT 'out_of_business',
  `closure_notes` TEXT DEFAULT NULL,
  `closed_by_user_id` BIGINT(20) UNSIGNED DEFAULT NULL,
  `total_lifetime_revenue` DECIMAL(12,2) DEFAULT NULL,
  `total_orders` INT DEFAULT NULL,
  `archived_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_original_account` (`original_account_id`),
  KEY `idx_chain_id` (`chain_id`),
  KEY `idx_sales_agent` (`sales_agent_id`),
  CONSTRAINT `fk_closed_location_agent` FOREIGN KEY (`sales_agent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_closed_location_chain` FOREIGN KEY (`chain_id`) REFERENCES `wholesale_account_chains` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Update foreign key for chain_id in wholesale_accounts
ALTER TABLE `wholesale_accounts`
ADD CONSTRAINT `fk_account_chain` FOREIGN KEY (`chain_id`) REFERENCES `wholesale_account_chains` (`id`) ON DELETE SET NULL;




