-- Comprehensive Accounting & Tax Suite Migration
-- Date: 2025-10-11
-- Purpose: Full-featured accounting system with double-entry bookkeeping, expense tracking, tax management
-- Binary-safe, ON DUPLICATE KEY UPDATE patterns, proper indexing

START TRANSACTION;

-- ============================================
-- CORE ACCOUNTING TABLES
-- ============================================

-- Chart of Accounts: Hierarchical account structure
CREATE TABLE IF NOT EXISTS `chart_of_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_code` VARCHAR(20) COLLATE utf8mb4_bin NOT NULL,
  `account_name` VARCHAR(200) COLLATE utf8mb4_bin NOT NULL,
  `account_type` ENUM('asset','liability','equity','revenue','cogs','expense') NOT NULL,
  `parent_account_id` BIGINT UNSIGNED NULL,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'System accounts cannot be deleted',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `description` TEXT NULL,
  `tax_category` VARCHAR(100) NULL COMMENT 'Tax reporting category',
  `normal_balance` ENUM('debit','credit') NOT NULL COMMENT 'Normal balance side for this account type',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_account_code` (`account_code`),
  KEY `idx_account_type` (`account_type`),
  KEY `idx_parent_account` (`parent_account_id`),
  KEY `idx_active` (`is_active`),
  CONSTRAINT `fk_chart_parent` FOREIGN KEY (`parent_account_id`) REFERENCES `chart_of_accounts`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal Entries: Double-entry bookkeeping foundation
CREATE TABLE IF NOT EXISTS `journal_entries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entry_number` VARCHAR(50) COLLATE utf8mb4_bin NOT NULL,
  `entry_date` DATE NOT NULL,
  `entry_type` ENUM('standard','adjusting','closing','reversing') NOT NULL DEFAULT 'standard',
  `description` TEXT COLLATE utf8mb4_bin NOT NULL,
  `reference_type` VARCHAR(50) NULL COMMENT 'order, work_order, commission, expense, manual',
  `reference_id` BIGINT UNSIGNED NULL,
  `fiscal_period` VARCHAR(20) NULL COMMENT 'YYYY-MM format',
  `status` ENUM('draft','posted','void') NOT NULL DEFAULT 'draft',
  `is_auto_generated` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `approved_by` BIGINT UNSIGNED NULL,
  `voided_by` BIGINT UNSIGNED NULL,
  `voided_at` TIMESTAMP NULL,
  `void_reason` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_entry_number` (`entry_number`),
  KEY `idx_entry_date` (`entry_date`),
  KEY `idx_entry_type` (`entry_type`),
  KEY `idx_status` (`status`),
  KEY `idx_fiscal_period` (`fiscal_period`),
  KEY `idx_reference` (`reference_type`, `reference_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal Entry Lines: Individual debit/credit lines
CREATE TABLE IF NOT EXISTS `journal_entry_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `journal_entry_id` BIGINT UNSIGNED NOT NULL,
  `line_number` INT NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `debit_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `credit_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `description` TEXT NULL,
  `reference_details` JSON NULL COMMENT 'Additional context for this line',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_journal_entry` (`journal_entry_id`),
  KEY `idx_account` (`account_id`),
  CONSTRAINT `fk_jel_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jel_account` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_debit_credit` CHECK ((`debit_amount` >= 0 AND `credit_amount` = 0) OR (`credit_amount` >= 0 AND `debit_amount` = 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Expense Categories: Categorization for all expenses
CREATE TABLE IF NOT EXISTS `expense_categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_name` VARCHAR(200) COLLATE utf8mb4_bin NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL COMMENT 'Links to chart of accounts',
  `parent_category_id` BIGINT UNSIGNED NULL,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `allocation_method` ENUM('direct','overhead','hybrid') NOT NULL DEFAULT 'direct',
  `description` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_category_name` (`category_name`),
  KEY `idx_account` (`account_id`),
  KEY `idx_parent_category` (`parent_category_id`),
  CONSTRAINT `fk_expcat_account` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_expcat_parent` FOREIGN KEY (`parent_category_id`) REFERENCES `expense_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Expenses: Universal expense tracking
CREATE TABLE IF NOT EXISTS `expenses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `expense_date` DATE NOT NULL,
  `expense_category_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `vendor_name` VARCHAR(200) COLLATE utf8mb4_bin NULL,
  `description` TEXT COLLATE utf8mb4_bin NOT NULL,
  `reference_type` VARCHAR(50) NULL COMMENT 'work_order, batch_code, api_call, subscription, manual',
  `reference_id` VARCHAR(100) NULL,
  `allocation_method` ENUM('direct','overhead','hybrid') NULL,
  `is_recurring` TINYINT(1) NOT NULL DEFAULT 0,
  `recurrence_pattern` JSON NULL COMMENT 'For recurring expenses',
  `payment_status` ENUM('unpaid','paid','partial') NOT NULL DEFAULT 'unpaid',
  `payment_date` DATE NULL,
  `payment_method` VARCHAR(50) NULL,
  `receipt_file_path` VARCHAR(500) NULL,
  `journal_entry_id` BIGINT UNSIGNED NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_expense_date` (`expense_date`),
  KEY `idx_category` (`expense_category_id`),
  KEY `idx_payment_status` (`payment_status`),
  KEY `idx_reference` (`reference_type`, `reference_id`),
  KEY `idx_journal_entry` (`journal_entry_id`),
  CONSTRAINT `fk_expense_category` FOREIGN KEY (`expense_category_id`) REFERENCES `expense_categories`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_expense_journal` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Expense Allocations: Track how expenses allocate to products/batches
CREATE TABLE IF NOT EXISTS `expense_allocations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `expense_id` BIGINT UNSIGNED NOT NULL,
  `allocation_type` ENUM('batch','product_group','product','overhead') NOT NULL,
  `target_id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL COMMENT 'ID or code of target',
  `allocated_amount` DECIMAL(15,2) NOT NULL,
  `allocation_percentage` DECIMAL(5,2) NULL,
  `allocation_notes` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_expense` (`expense_id`),
  KEY `idx_allocation_type` (`allocation_type`, `target_id`),
  CONSTRAINT `fk_allocation_expense` FOREIGN KEY (`expense_id`) REFERENCES `expenses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API Usage Tracking: Automatic API expense tracking
CREATE TABLE IF NOT EXISTS `api_usage_tracking` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `api_service` VARCHAR(50) COLLATE utf8mb4_bin NOT NULL COMMENT 'gemini, google_places, google_maps, sendgrid, custom',
  `usage_date` DATETIME NOT NULL,
  `endpoint` VARCHAR(200) NULL,
  `request_count` INT NOT NULL DEFAULT 1,
  `estimated_cost_per_call` DECIMAL(10,6) NOT NULL,
  `total_cost` DECIMAL(10,4) NOT NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `session_id` VARCHAR(100) NULL,
  `metadata` JSON NULL,
  `expense_created` TINYINT(1) NOT NULL DEFAULT 0,
  `expense_id` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_api_service` (`api_service`),
  KEY `idx_usage_date` (`usage_date`),
  KEY `idx_expense_created` (`expense_created`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Subscription Expenses: Recurring subscription management
CREATE TABLE IF NOT EXISTS `subscription_expenses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subscription_name` VARCHAR(200) COLLATE utf8mb4_bin NOT NULL,
  `vendor_name` VARCHAR(200) COLLATE utf8mb4_bin NOT NULL,
  `category_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `billing_cycle` ENUM('monthly','quarterly','annual','other') NOT NULL,
  `start_date` DATE NOT NULL,
  `next_billing_date` DATE NOT NULL,
  `auto_renew` TINYINT(1) NOT NULL DEFAULT 1,
  `payment_method` VARCHAR(50) NULL,
  `account_number_last4` VARCHAR(4) NULL,
  `status` ENUM('active','cancelled','expired','paused') NOT NULL DEFAULT 'active',
  `cancellation_date` DATE NULL,
  `notes` TEXT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_next_billing` (`next_billing_date`, `status`),
  KEY `idx_status` (`status`),
  KEY `idx_category` (`category_id`),
  CONSTRAINT `fk_subscription_category` FOREIGN KEY (`category_id`) REFERENCES `expense_categories`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invoices: Full invoice management beyond orders
CREATE TABLE IF NOT EXISTS `invoices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_number` VARCHAR(50) COLLATE utf8mb4_bin NOT NULL,
  `invoice_date` DATE NOT NULL,
  `due_date` DATE NOT NULL,
  `customer_type` ENUM('wholesale_account','retail','other') NOT NULL,
  `customer_id` BIGINT UNSIGNED NULL,
  `customer_name` VARCHAR(200) COLLATE utf8mb4_bin NOT NULL,
  `customer_email` VARCHAR(200) NULL,
  `subtotal` DECIMAL(10,2) NOT NULL,
  `tax_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `shipping_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(10,2) NOT NULL,
  `payment_status` ENUM('unpaid','partial','paid','overdue') NOT NULL DEFAULT 'unpaid',
  `payment_date` DATE NULL,
  `payment_method` VARCHAR(50) NULL,
  `notes` TEXT NULL,
  `invoice_type` ENUM('product_sale','service','other') NOT NULL DEFAULT 'product_sale',
  `order_id` BIGINT UNSIGNED NULL COMMENT 'Link to orders table if applicable',
  `journal_entry_id` BIGINT UNSIGNED NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_invoice_number` (`invoice_number`),
  KEY `idx_invoice_date` (`invoice_date`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_payment_status` (`payment_status`),
  KEY `idx_customer` (`customer_type`, `customer_id`),
  KEY `idx_journal_entry` (`journal_entry_id`),
  CONSTRAINT `fk_invoice_journal` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tax Configuration: Tax rate and nexus management
CREATE TABLE IF NOT EXISTS `tax_configuration` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `jurisdiction` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL COMMENT 'federal, state name, local',
  `tax_type` ENUM('income','sales','excise','payroll','other') NOT NULL,
  `tax_rate` DECIMAL(5,4) NOT NULL COMMENT 'Decimal rate (0.0725 for 7.25%)',
  `applies_to` VARCHAR(100) NULL COMMENT 'revenue, specific_products, etc',
  `product_category_filter` VARCHAR(200) NULL,
  `effective_date` DATE NOT NULL,
  `expiration_date` DATE NULL,
  `filing_frequency` ENUM('monthly','quarterly','annual','other') NULL,
  `notes` TEXT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_jurisdiction` (`jurisdiction`),
  KEY `idx_tax_type` (`tax_type`),
  KEY `idx_active` (`is_active`),
  KEY `idx_effective_dates` (`effective_date`, `expiration_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tax Calculations: Calculated tax liabilities per period
CREATE TABLE IF NOT EXISTS `tax_calculations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `period_start` DATE NOT NULL,
  `period_end` DATE NOT NULL,
  `tax_type` ENUM('income','sales','excise','payroll','other') NOT NULL,
  `jurisdiction` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
  `taxable_amount` DECIMAL(15,2) NOT NULL,
  `tax_rate` DECIMAL(5,4) NOT NULL,
  `tax_owed` DECIMAL(15,2) NOT NULL,
  `payments_made` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `balance_due` DECIMAL(15,2) NOT NULL,
  `filing_status` ENUM('not_filed','filed','paid') NOT NULL DEFAULT 'not_filed',
  `filed_date` DATE NULL,
  `confirmation_number` VARCHAR(100) NULL,
  `notes` TEXT NULL,
  `calculated_by` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_period` (`period_start`, `period_end`),
  KEY `idx_tax_type` (`tax_type`),
  KEY `idx_jurisdiction` (`jurisdiction`),
  KEY `idx_filing_status` (`filing_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ledger Accounts: Account balances and activity summary
CREATE TABLE IF NOT EXISTS `ledger_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `fiscal_period` VARCHAR(20) NOT NULL COMMENT 'YYYY-MM format',
  `beginning_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_debits` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_credits` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `ending_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `last_transaction_date` DATE NULL,
  `period_closed` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_account_period` (`account_id`, `fiscal_period`),
  KEY `idx_fiscal_period` (`fiscal_period`),
  KEY `idx_period_closed` (`period_closed`),
  CONSTRAINT `fk_ledger_account` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- EXTEND EXISTING TABLES
-- ============================================

-- Extend orders table
ALTER TABLE `orders` 
ADD COLUMN IF NOT EXISTS `journal_entry_id` BIGINT UNSIGNED NULL,
ADD COLUMN IF NOT EXISTS `revenue_recognition_date` DATE NULL,
ADD COLUMN IF NOT EXISTS `tax_jurisdiction` VARCHAR(100) NULL,
ADD COLUMN IF NOT EXISTS `accounting_status` ENUM('pending','posted','void') DEFAULT 'pending',
ADD KEY IF NOT EXISTS `idx_orders_journal` (`journal_entry_id`),
ADD KEY IF NOT EXISTS `idx_orders_accounting_status` (`accounting_status`);

-- Extend work_orders table
ALTER TABLE `work_orders`
ADD COLUMN IF NOT EXISTS `lab_test_expense_id` BIGINT UNSIGNED NULL,
ADD COLUMN IF NOT EXISTS `total_allocated_costs` DECIMAL(15,2) NULL COMMENT 'Total costs allocated from expenses',
ADD COLUMN IF NOT EXISTS `cost_per_unit_actual` DECIMAL(10,4) NULL,
ADD COLUMN IF NOT EXISTS `accounting_status` ENUM('pending','posted','void') DEFAULT 'pending',
ADD KEY IF NOT EXISTS `idx_wo_lab_expense` (`lab_test_expense_id`),
ADD KEY IF NOT EXISTS `idx_wo_accounting_status` (`accounting_status`);

-- Extend commissions table
ALTER TABLE `commissions`
ADD COLUMN IF NOT EXISTS `journal_entry_id` BIGINT UNSIGNED NULL,
ADD COLUMN IF NOT EXISTS `expense_recognition_date` DATE NULL,
ADD COLUMN IF NOT EXISTS `accounting_status` ENUM('pending','posted','void') DEFAULT 'pending',
ADD KEY IF NOT EXISTS `idx_comm_journal` (`journal_entry_id`),
ADD KEY IF NOT EXISTS `idx_comm_accounting_status` (`accounting_status`);

-- Extend ai_chat_logs table
ALTER TABLE `ai_chat_logs`
ADD COLUMN IF NOT EXISTS `api_cost_tracked` TINYINT(1) DEFAULT 0,
ADD COLUMN IF NOT EXISTS `api_usage_id` BIGINT UNSIGNED NULL,
ADD KEY IF NOT EXISTS `idx_ai_cost_tracked` (`api_cost_tracked`);

-- ============================================
-- INSERT DEFAULT CHART OF ACCOUNTS
-- ============================================

-- Assets (1000-1999)
INSERT INTO `chart_of_accounts` (`account_code`, `account_name`, `account_type`, `normal_balance`, `is_system`, `description`) VALUES
('1000', 'Assets', 'asset', 'debit', 1, 'Parent account for all assets'),
('1100', 'Current Assets', 'asset', 'debit', 1, 'Assets convertible to cash within one year'),
('1110', 'Cash', 'asset', 'debit', 1, 'Cash on hand and in bank'),
('1120', 'Accounts Receivable', 'asset', 'debit', 1, 'Money owed by customers'),
('1130', 'Inventory - Raw Materials', 'asset', 'debit', 1, 'Ingredients inventory'),
('1140', 'Inventory - Packaging', 'asset', 'debit', 1, 'Packaging materials'),
('1150', 'Inventory - Finished Goods', 'asset', 'debit', 1, 'Completed products ready for sale'),
('1160', 'Prepaid Expenses', 'asset', 'debit', 1, 'Expenses paid in advance'),
('1200', 'Fixed Assets', 'asset', 'debit', 1, 'Long-term tangible assets'),
('1210', 'Equipment', 'asset', 'debit', 1, 'Manufacturing and office equipment'),
('1220', 'Accumulated Depreciation', 'asset', 'credit', 1, 'Contra-asset for equipment depreciation')
ON DUPLICATE KEY UPDATE `account_name` = VALUES(`account_name`);

-- Liabilities (2000-2999)
INSERT INTO `chart_of_accounts` (`account_code`, `account_name`, `account_type`, `normal_balance`, `is_system`, `description`) VALUES
('2000', 'Liabilities', 'liability', 'credit', 1, 'Parent account for all liabilities'),
('2100', 'Current Liabilities', 'liability', 'credit', 1, 'Liabilities due within one year'),
('2110', 'Accounts Payable', 'liability', 'credit', 1, 'Money owed to vendors'),
('2120', 'Commission Payable', 'liability', 'credit', 1, 'Commissions owed to sales agents'),
('2130', 'Sales Tax Payable', 'liability', 'credit', 1, 'Sales tax collected but not yet paid'),
('2140', 'Income Tax Payable', 'liability', 'credit', 1, 'Income tax owed'),
('2150', 'Labor Payable', 'liability', 'credit', 1, 'Labor costs owed to manufacturers'),
('2200', 'Long-term Liabilities', 'liability', 'credit', 1, 'Liabilities due after one year'),
('2210', 'Loans Payable', 'liability', 'credit', 1, 'Long-term loans and notes')
ON DUPLICATE KEY UPDATE `account_name` = VALUES(`account_name`);

-- Equity (3000-3999)
INSERT INTO `chart_of_accounts` (`account_code`, `account_name`, `account_type`, `normal_balance`, `is_system`, `description`) VALUES
('3000', 'Equity', 'equity', 'credit', 1, 'Parent account for owner equity'),
('3100', 'Owner Equity', 'equity', 'credit', 1, 'Owner investment and retained earnings'),
('3110', 'Retained Earnings', 'equity', 'credit', 1, 'Accumulated profits'),
('3120', 'Owner Draws', 'equity', 'debit', 1, 'Money withdrawn by owner')
ON DUPLICATE KEY UPDATE `account_name` = VALUES(`account_name`);

-- Revenue (4000-4999)
INSERT INTO `chart_of_accounts` (`account_code`, `account_name`, `account_type`, `normal_balance`, `is_system`, `description`) VALUES
('4000', 'Revenue', 'revenue', 'credit', 1, 'Parent account for all revenue'),
('4100', 'Product Sales', 'revenue', 'credit', 1, 'Revenue from product sales'),
('4110', 'Wholesale Sales', 'revenue', 'credit', 1, 'Wholesale product sales'),
('4120', 'Retail Sales', 'revenue', 'credit', 1, 'Direct-to-consumer sales'),
('4200', 'Service Revenue', 'revenue', 'credit', 1, 'Revenue from services'),
('4300', 'Other Income', 'revenue', 'credit', 1, 'Miscellaneous income')
ON DUPLICATE KEY UPDATE `account_name` = VALUES(`account_name`);

-- Cost of Goods Sold (5000-5999)
INSERT INTO `chart_of_accounts` (`account_code`, `account_name`, `account_type`, `normal_balance`, `is_system`, `description`) VALUES
('5000', 'Cost of Goods Sold', 'cogs', 'debit', 1, 'Parent account for COGS'),
('5100', 'Ingredient Costs', 'cogs', 'debit', 1, 'Cost of raw ingredients'),
('5110', 'Product Ingredient Costs', 'cogs', 'debit', 1, 'Product format costs'),
('5120', 'Cannabinoid Ingredient Costs', 'cogs', 'debit', 1, 'product, THC, and other cannabinoid costs'),
('5200', 'Packaging Costs', 'cogs', 'debit', 1, 'Bottles, labels, boxes'),
('5300', 'Materials Costs', 'cogs', 'debit', 1, 'Shipping materials'),
('5400', 'Direct Labor', 'cogs', 'debit', 1, 'Manufacturing labor'),
('5500', 'Lab Testing', 'cogs', 'debit', 1, 'COA and quality testing')
ON DUPLICATE KEY UPDATE `account_name` = VALUES(`account_name`);

-- Operating Expenses (6000-6999)
INSERT INTO `chart_of_accounts` (`account_code`, `account_name`, `account_type`, `normal_balance`, `is_system`, `description`) VALUES
('6000', 'Operating Expenses', 'expense', 'debit', 1, 'Parent account for operating expenses'),
('6100', 'Commission Expenses', 'expense', 'debit', 1, 'Sales commissions'),
('6110', 'Sales Agent Commissions', 'expense', 'debit', 1, 'Commissions to sales agents'),
('6120', 'Developer Commissions', 'expense', 'debit', 1, 'Developer commission share'),
('6130', 'AI Commission Allocation', 'expense', 'debit', 1, 'AI-attributed commission share'),
('6200', 'Technology Expenses', 'expense', 'debit', 1, 'Software and technology costs'),
('6210', 'Software Subscriptions', 'expense', 'debit', 1, 'SaaS and software licenses'),
('6220', 'API Usage Costs', 'expense', 'debit', 1, 'Gemini, Google Places, SendGrid'),
('6230', 'Development Tools', 'expense', 'debit', 1, 'Development software and services'),
('6300', 'Marketing Expenses', 'expense', 'debit', 1, 'Marketing and advertising'),
('6400', 'Shipping & Logistics', 'expense', 'debit', 1, 'Freight and shipping'),
('6500', 'Office Expenses', 'expense', 'debit', 1, 'Office supplies and expenses'),
('6600', 'Utilities', 'expense', 'debit', 1, 'Electric, water, internet'),
('6700', 'Insurance', 'expense', 'debit', 1, 'Business insurance'),
('6800', 'Legal & Professional', 'expense', 'debit', 1, 'Legal, accounting, consulting'),
('6900', 'Other Expenses', 'expense', 'debit', 1, 'Miscellaneous expenses')
ON DUPLICATE KEY UPDATE `account_name` = VALUES(`account_name`);

-- ============================================
-- INSERT DEFAULT EXPENSE CATEGORIES
-- ============================================

INSERT INTO `expense_categories` (`category_name`, `account_id`, `is_system`, `allocation_method`, `description`) VALUES
('Ingredients', (SELECT id FROM chart_of_accounts WHERE account_code = '5100'), 1, 'direct', 'Raw ingredient purchases'),
('Packaging', (SELECT id FROM chart_of_accounts WHERE account_code = '5200'), 1, 'direct', 'Packaging materials'),
('Materials', (SELECT id FROM chart_of_accounts WHERE account_code = '5300'), 1, 'direct', 'Shipping and handling materials'),
('Direct Labor', (SELECT id FROM chart_of_accounts WHERE account_code = '5400'), 1, 'direct', 'Manufacturing labor'),
('Lab Testing', (SELECT id FROM chart_of_accounts WHERE account_code = '5500'), 1, 'direct', 'COA and quality testing'),
('Software Subscriptions', (SELECT id FROM chart_of_accounts WHERE account_code = '6210'), 1, 'overhead', 'SaaS subscriptions'),
('API Usage', (SELECT id FROM chart_of_accounts WHERE account_code = '6220'), 1, 'overhead', 'API call costs'),
('Development Tools', (SELECT id FROM chart_of_accounts WHERE account_code = '6230'), 1, 'overhead', 'Development subscriptions'),
('Marketing', (SELECT id FROM chart_of_accounts WHERE account_code = '6300'), 1, 'overhead', 'Marketing expenses'),
('Shipping', (SELECT id FROM chart_of_accounts WHERE account_code = '6400'), 1, 'overhead', 'Freight and shipping'),
('Office Supplies', (SELECT id FROM chart_of_accounts WHERE account_code = '6500'), 1, 'overhead', 'Office supplies'),
('Utilities', (SELECT id FROM chart_of_accounts WHERE account_code = '6600'), 1, 'overhead', 'Electric, water, internet'),
('Insurance', (SELECT id FROM chart_of_accounts WHERE account_code = '6700'), 1, 'overhead', 'Business insurance'),
('Legal/Professional', (SELECT id FROM chart_of_accounts WHERE account_code = '6800'), 1, 'overhead', 'Legal and accounting')
ON DUPLICATE KEY UPDATE `category_name` = VALUES(`category_name`);

-- ============================================
-- ADD ACCOUNTANT ROLE
-- ============================================

INSERT INTO `roles` (`name`) VALUES
('accountant')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

COMMIT;

