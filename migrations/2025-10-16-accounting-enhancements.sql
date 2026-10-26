-- Accounting Suite Enhancements
-- Date: 2025-10-16
-- Purpose: Additional fields for expense negation, agent 1099, approval workflows, and performance indexes

START TRANSACTION;

-- ============================================
-- ENHANCE EXISTING TABLES
-- ============================================

-- Add negation and approval workflow to expenses
ALTER TABLE `expenses`
ADD COLUMN IF NOT EXISTS `approval_status` ENUM('pending','approved','rejected','negated') DEFAULT 'pending' AFTER `payment_status`,
ADD COLUMN IF NOT EXISTS `approved_by` BIGINT UNSIGNED NULL AFTER `created_by`,
ADD COLUMN IF NOT EXISTS `approved_at` TIMESTAMP NULL AFTER `created_at`,
ADD COLUMN IF NOT EXISTS `rejection_reason` TEXT NULL AFTER `updated_at`,
ADD COLUMN IF NOT EXISTS `negated_by` BIGINT UNSIGNED NULL AFTER `approval_status`,
ADD COLUMN IF NOT EXISTS `negated_at` TIMESTAMP NULL AFTER `approved_at`,
ADD COLUMN IF NOT EXISTS `negation_reason` TEXT NULL AFTER `rejection_reason`,
ADD COLUMN IF NOT EXISTS `reversal_journal_entry_id` BIGINT UNSIGNED NULL COMMENT 'Journal entry that reverses this expense',
ADD KEY IF NOT EXISTS `idx_approval_status` (`approval_status`),
ADD KEY IF NOT EXISTS `idx_approved_by` (`approved_by`),
ADD KEY IF NOT EXISTS `idx_negated_by` (`negated_by`);

-- Add reversal tracking to journal entries
ALTER TABLE `journal_entries`
ADD COLUMN IF NOT EXISTS `reversal_entry_id` BIGINT UNSIGNED NULL COMMENT 'If this is a reversal, links to original entry' AFTER `reference_id`,
ADD COLUMN IF NOT EXISTS `is_reversal` TINYINT(1) DEFAULT 0 AFTER `is_auto_generated`,
ADD COLUMN IF NOT EXISTS `reversal_reason` TEXT NULL AFTER `void_reason`,
ADD KEY IF NOT EXISTS `idx_reversal` (`reversal_entry_id`),
ADD KEY IF NOT EXISTS `idx_is_reversal` (`is_reversal`);

-- Add 1099 fields to users table
ALTER TABLE `users`
ADD COLUMN IF NOT EXISTS `tax_id_type` ENUM('ssn','ein','none') DEFAULT 'none' AFTER `status`,
ADD COLUMN IF NOT EXISTS `tax_id_last4` VARCHAR(4) NULL COMMENT 'Last 4 digits only for security' AFTER `tax_id_type`,
ADD COLUMN IF NOT EXISTS `tax_id_verified` TINYINT(1) DEFAULT 0 AFTER `tax_id_last4`,
ADD COLUMN IF NOT EXISTS `tax_address_line1` VARCHAR(255) NULL AFTER `tax_id_verified`,
ADD COLUMN IF NOT EXISTS `tax_address_line2` VARCHAR(255) NULL AFTER `tax_address_line1`,
ADD COLUMN IF NOT EXISTS `tax_address_city` VARCHAR(100) NULL AFTER `tax_address_line2`,
ADD COLUMN IF NOT EXISTS `tax_address_state` VARCHAR(50) NULL AFTER `tax_address_city`,
ADD COLUMN IF NOT EXISTS `tax_address_zip` VARCHAR(20) NULL AFTER `tax_address_state`,
ADD COLUMN IF NOT EXISTS `tax_address_country` VARCHAR(100) DEFAULT 'USA' AFTER `tax_address_zip`,
ADD COLUMN IF NOT EXISTS `is_1099_contractor` TINYINT(1) DEFAULT 0 COMMENT 'Subject to 1099 reporting' AFTER `tax_address_country`,
ADD KEY IF NOT EXISTS `idx_1099_contractor` (`is_1099_contractor`);

-- Create 1099 forms tracking table
CREATE TABLE IF NOT EXISTS `form_1099_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tax_year` YEAR NOT NULL,
  `contractor_user_id` BIGINT UNSIGNED NOT NULL,
  `total_compensation` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `federal_tax_withheld` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `state_tax_withheld` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `form_status` ENUM('draft','finalized','filed','corrected') DEFAULT 'draft',
  `finalized_at` TIMESTAMP NULL,
  `finalized_by` BIGINT UNSIGNED NULL,
  `filed_at` DATE NULL,
  `confirmation_number` VARCHAR(100) NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_year_contractor` (`tax_year`, `contractor_user_id`),
  KEY `idx_tax_year` (`tax_year`),
  KEY `idx_contractor` (`contractor_user_id`),
  KEY `idx_form_status` (`form_status`),
  CONSTRAINT `fk_1099_contractor` FOREIGN KEY (`contractor_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create data sync tracking table
CREATE TABLE IF NOT EXISTS `accounting_sync_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sync_type` VARCHAR(50) COLLATE utf8mb4_bin NOT NULL COMMENT 'orders, commissions, production, api_usage, full',
  `sync_status` ENUM('running','completed','failed','cancelled') NOT NULL,
  `records_processed` INT NOT NULL DEFAULT 0,
  `records_created` INT NOT NULL DEFAULT 0,
  `records_failed` INT NOT NULL DEFAULT 0,
  `error_log` LONGTEXT NULL,
  `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` TIMESTAMP NULL,
  `initiated_by` BIGINT UNSIGNED NOT NULL,
  `sync_parameters` JSON NULL COMMENT 'Date ranges, filters used',
  PRIMARY KEY (`id`),
  KEY `idx_sync_type` (`sync_type`),
  KEY `idx_sync_status` (`sync_status`),
  KEY `idx_started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create expense approval log
CREATE TABLE IF NOT EXISTS `expense_approval_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `expense_id` BIGINT UNSIGNED NOT NULL,
  `action` ENUM('approved','rejected','negated','un-negated') NOT NULL,
  `performed_by` BIGINT UNSIGNED NOT NULL,
  `notes` TEXT NULL,
  `previous_status` VARCHAR(50) NULL,
  `new_status` VARCHAR(50) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_expense` (`expense_id`),
  KEY `idx_performed_by` (`performed_by`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_approval_expense` FOREIGN KEY (`expense_id`) REFERENCES `expenses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create test data markers table
CREATE TABLE IF NOT EXISTS `test_data_markers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type` VARCHAR(50) COLLATE utf8mb4_bin NOT NULL COMMENT 'order, commission, expense, journal_entry',
  `entity_id` BIGINT UNSIGNED NOT NULL,
  `marked_as_test` TINYINT(1) DEFAULT 1,
  `marked_by` BIGINT UNSIGNED NOT NULL,
  `marked_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reason` TEXT NULL,
  `incinerated` TINYINT(1) DEFAULT 0,
  `incinerated_at` TIMESTAMP NULL,
  `incinerated_by` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_entity_unique` (`entity_type`, `entity_id`),
  KEY `idx_marked_as_test` (`marked_as_test`),
  KEY `idx_incinerated` (`incinerated`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- PERFORMANCE INDEXES
-- ============================================

-- Optimize journal entry queries
ALTER TABLE `journal_entries`
ADD KEY IF NOT EXISTS `idx_created_by` (`created_by`),
ADD KEY IF NOT EXISTS `idx_approved_by` (`approved_by`),
ADD KEY IF NOT EXISTS `idx_auto_generated` (`is_auto_generated`);

-- Optimize journal entry lines queries
ALTER TABLE `journal_entry_lines`
ADD KEY IF NOT EXISTS `idx_debit_amount` (`debit_amount`),
ADD KEY IF NOT EXISTS `idx_credit_amount` (`credit_amount`);

-- Optimize expense queries
ALTER TABLE `expenses`
ADD KEY IF NOT EXISTS `idx_created_by` (`created_by`),
ADD KEY IF NOT EXISTS `idx_expense_date_range` (`expense_date`, `expense_category_id`);

-- Optimize ledger queries
ALTER TABLE `ledger_accounts`
ADD KEY IF NOT EXISTS `idx_ending_balance` (`ending_balance`),
ADD KEY IF NOT EXISTS `idx_last_transaction` (`last_transaction_date`);

-- Optimize chart of accounts queries
ALTER TABLE `chart_of_accounts`
ADD KEY IF NOT EXISTS `idx_account_type_active` (`account_type`, `is_active`);

-- Optimize commissions for 1099 reporting
ALTER TABLE `commissions`
ADD KEY IF NOT EXISTS `idx_user_date` (`user_id`, `created_at`),
ADD KEY IF NOT EXISTS `idx_status_date` (`status`, `created_at`);

-- ============================================
-- ACCOUNTING SETTINGS TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS `accounting_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
  `setting_value` TEXT NULL,
  `setting_type` ENUM('string','number','boolean','json') DEFAULT 'string',
  `description` TEXT NULL,
  `updated_by` BIGINT UNSIGNED NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default accounting settings
INSERT INTO `accounting_settings` (`setting_key`, `setting_value`, `setting_type`, `description`) VALUES
('auto_post_order_entries', '0', 'boolean', 'Automatically post journal entries for orders'),
('auto_post_commission_entries', '0', 'boolean', 'Automatically post commission expense entries'),
('auto_post_production_entries', '0', 'boolean', 'Automatically post production COGS entries'),
('require_expense_approval', '1', 'boolean', 'Require approval before posting expenses'),
('1099_threshold', '600.00', 'number', 'Minimum earnings for 1099 reporting'),
('fiscal_year_end', '12-31', 'string', 'Fiscal year end date (MM-DD)'),
('default_tax_jurisdiction', 'Federal', 'string', 'Default tax jurisdiction'),
('enable_multi_currency', '0', 'boolean', 'Enable multi-currency support'),
('base_currency', 'USD', 'string', 'Base currency code')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

COMMIT;

