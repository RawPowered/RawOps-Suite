-- Sales Agent Books, Loans, and Sample Shipment System
-- Date: 2025-10-12
-- Purpose: Complete loan/repayment system for sales agents with sample expense tracking
-- Binary-safe, ON DUPLICATE KEY UPDATE patterns, no duplicate errors

START TRANSACTION;

-- ============================================
-- SALES AGENT LOANS TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS `sales_agent_loans` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `agent_id` BIGINT UNSIGNED NOT NULL COMMENT 'Sales agent receiving loan',
    `loan_amount` DECIMAL(10,2) NOT NULL COMMENT 'Original loan amount',
    `balance_remaining` DECIMAL(10,2) NOT NULL COMMENT 'Current balance owed',
    `loan_type` ENUM('startup','sample_expense','advance','other') COLLATE utf8mb4_bin NOT NULL DEFAULT 'startup',
    `loan_date` DATE NOT NULL,
    `repayment_terms` JSON NULL COMMENT 'Structured repayment terms',
    `status` ENUM('active','paid','forgiven','cancelled') COLLATE utf8mb4_bin NOT NULL DEFAULT 'active',
    `approved_by` BIGINT UNSIGNED NOT NULL COMMENT 'Admin who approved loan',
    `approved_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `paid_off_at` TIMESTAMP NULL COMMENT 'Date loan was fully repaid',
    `notes` TEXT COLLATE utf8mb4_bin NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_agent` (`agent_id`),
    KEY `idx_status` (`status`),
    KEY `idx_loan_date` (`loan_date`),
    KEY `idx_approved_by` (`approved_by`),
    CONSTRAINT `fk_loan_agent` FOREIGN KEY (`agent_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_loan_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- LOAN REPAYMENT TRANSACTIONS TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS `loan_repayment_transactions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `loan_id` BIGINT UNSIGNED NOT NULL,
    `agent_id` BIGINT UNSIGNED NOT NULL,
    `transaction_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `amount` DECIMAL(10,2) NOT NULL COMMENT 'Repayment amount',
    `repayment_type` ENUM('commission_deduction','direct_payment','automatic','forgiveness') COLLATE utf8mb4_bin NOT NULL,
    `source_commission_id` BIGINT UNSIGNED NULL COMMENT 'Commission this was deducted from',
    `source_order_id` BIGINT UNSIGNED NULL,
    `journal_entry_id` BIGINT UNSIGNED NULL,
    `notes` TEXT COLLATE utf8mb4_bin NULL,
    `created_by` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_loan` (`loan_id`),
    KEY `idx_agent` (`agent_id`),
    KEY `idx_transaction_date` (`transaction_date`),
    KEY `idx_source_commission` (`source_commission_id`),
    KEY `idx_source_order` (`source_order_id`),
    KEY `idx_journal_entry` (`journal_entry_id`),
    CONSTRAINT `fk_repayment_loan` FOREIGN KEY (`loan_id`) REFERENCES `sales_agent_loans`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_repayment_agent` FOREIGN KEY (`agent_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_repayment_commission` FOREIGN KEY (`source_commission_id`) REFERENCES `commissions`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_repayment_order` FOREIGN KEY (`source_order_id`) REFERENCES `orders`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- AGENT REPAYMENT SETTINGS TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS `agent_repayment_settings` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `agent_id` BIGINT UNSIGNED NOT NULL,
    `loan_id` BIGINT UNSIGNED NULL COMMENT 'Specific loan or NULL for default settings',
    `repayment_method` ENUM('upfront','percentage_per_commission','first_sale_full','hybrid') COLLATE utf8mb4_bin NOT NULL DEFAULT 'percentage_per_commission',
    `percentage_rate` DECIMAL(5,2) NULL COMMENT 'Percentage of each commission (0-100)',
    `grace_period_days` INT NOT NULL DEFAULT 30 COMMENT 'Days before auto-deduction starts',
    `auto_deduct_after_days` INT NOT NULL DEFAULT 30 COMMENT 'Force deduction after X days regardless',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` BIGINT UNSIGNED NOT NULL,
    `approved_by` BIGINT UNSIGNED NULL,
    `approved_at` TIMESTAMP NULL,
    `notes` TEXT COLLATE utf8mb4_bin NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_agent_loan` (`agent_id`, `loan_id`),
    KEY `idx_agent` (`agent_id`),
    KEY `idx_loan` (`loan_id`),
    KEY `idx_is_active` (`is_active`),
    CONSTRAINT `fk_repayment_settings_agent` FOREIGN KEY (`agent_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_repayment_settings_loan` FOREIGN KEY (`loan_id`) REFERENCES `sales_agent_loans`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_repayment_settings_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- AGENT EXPENSE CHARGES TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS `agent_expense_charges` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `agent_id` BIGINT UNSIGNED NOT NULL,
    `sample_request_id` BIGINT UNSIGNED NULL COMMENT 'Related sample request if applicable',
    `charge_amount` DECIMAL(10,2) NOT NULL,
    `charge_reason` VARCHAR(255) COLLATE utf8mb4_bin NOT NULL COMMENT 'Description of charge',
    `charge_status` ENUM('pending','charged_to_loan','deducted_from_commission','forgiven') COLLATE utf8mb4_bin NOT NULL DEFAULT 'pending',
    `charged_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `processed_at` TIMESTAMP NULL COMMENT 'When charge was applied',
    `related_commission_id` BIGINT UNSIGNED NULL COMMENT 'Commission used for deduction',
    `related_loan_id` BIGINT UNSIGNED NULL COMMENT 'Loan this was added to',
    `journal_entry_id` BIGINT UNSIGNED NULL,
    `notes` TEXT COLLATE utf8mb4_bin NULL,
    `created_by` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_agent` (`agent_id`),
    KEY `idx_sample_request` (`sample_request_id`),
    KEY `idx_charge_status` (`charge_status`),
    KEY `idx_related_commission` (`related_commission_id`),
    KEY `idx_related_loan` (`related_loan_id`),
    KEY `idx_journal_entry` (`journal_entry_id`),
    CONSTRAINT `fk_charge_agent` FOREIGN KEY (`agent_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_charge_sample_request` FOREIGN KEY (`sample_request_id`) REFERENCES `sample_requests`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_charge_commission` FOREIGN KEY (`related_commission_id`) REFERENCES `commissions`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_charge_loan` FOREIGN KEY (`related_loan_id`) REFERENCES `sales_agent_loans`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- SAMPLE SHIPMENTS TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS `sample_shipments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sample_request_id` BIGINT UNSIGNED NOT NULL,
    `shipment_date` DATE NOT NULL,
    `carrier` VARCHAR(50) COLLATE utf8mb4_bin NOT NULL COMMENT 'USPS, UPS, FedEx, etc',
    `service_type` VARCHAR(100) COLLATE utf8mb4_bin NULL COMMENT 'Ground, Priority, Express, etc',
    `tracking_number` VARCHAR(100) COLLATE utf8mb4_bin NULL,
    `weight_oz` DECIMAL(6,2) NULL COMMENT 'Package weight in ounces',
    `shipping_cost` DECIMAL(10,2) NOT NULL,
    `estimated_delivery_date` DATE NULL,
    `actual_delivery_date` DATE NULL,
    `delivery_status` ENUM('pending','in_transit','delivered','exception','returned') COLLATE utf8mb4_bin NOT NULL DEFAULT 'pending',
    `delivery_confirmation` VARCHAR(255) COLLATE utf8mb4_bin NULL COMMENT 'Confirmation number or signature',
    `notes` TEXT COLLATE utf8mb4_bin NULL,
    `created_by` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_sample_request` (`sample_request_id`),
    KEY `idx_tracking` (`tracking_number`),
    KEY `idx_shipment_date` (`shipment_date`),
    KEY `idx_estimated_delivery` (`estimated_delivery_date`),
    KEY `idx_delivery_status` (`delivery_status`),
    CONSTRAINT `fk_shipment_sample_request` FOREIGN KEY (`sample_request_id`) REFERENCES `sample_requests`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_shipment_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- EXTEND SAMPLE_REQUESTS TABLE
-- ============================================

ALTER TABLE `sample_requests`
ADD COLUMN IF NOT EXISTS `charge_agent_shipping` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Charge shipping to agent',
ADD COLUMN IF NOT EXISTS `agent_charged_amount` DECIMAL(10,2) NULL COMMENT 'Amount charged to agent',
ADD COLUMN IF NOT EXISTS `invoice_customer` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Invoice customer for sample',
ADD COLUMN IF NOT EXISTS `customer_invoice_id` BIGINT UNSIGNED NULL COMMENT 'Invoice ID if customer billed',
ADD KEY IF NOT EXISTS `idx_charge_agent` (`charge_agent_shipping`),
ADD KEY IF NOT EXISTS `idx_invoice_customer` (`invoice_customer`),
ADD KEY IF NOT EXISTS `idx_customer_invoice` (`customer_invoice_id`);

-- ============================================
-- EXTEND COMMISSIONS TABLE
-- ============================================

ALTER TABLE `commissions`
ADD COLUMN IF NOT EXISTS `loan_repayment_deduction` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Amount deducted for loan repayment',
ADD COLUMN IF NOT EXISTS `net_commission_after_loans` DECIMAL(10,2) NULL COMMENT 'Commission after loan deductions',
ADD KEY IF NOT EXISTS `idx_loan_deduction` (`loan_repayment_deduction`);

-- ============================================
-- EXTEND CHART OF ACCOUNTS - AGENT LOAN RECEIVABLE
-- ============================================

INSERT INTO `chart_of_accounts` (
    `account_code`, `account_name`, `account_type`, `normal_balance`, 
    `is_system`, `is_active`, `description`, `tax_category`
) VALUES (
    '1180',
    'Agent Loan Receivable',
    'asset',
    'debit',
    1,
    1,
    'Money owed by sales agents for advances, samples, and startup costs',
    'Current Assets'
) ON DUPLICATE KEY UPDATE
    `account_name` = VALUES(`account_name`),
    `description` = VALUES(`description`),
    `is_active` = 1,
    `updated_at` = NOW();

-- ============================================
-- EXTEND INVOICES TABLE FOR SAMPLE SUPPORT
-- ============================================

ALTER TABLE `invoices`
ADD COLUMN IF NOT EXISTS `sample_request_id` BIGINT UNSIGNED NULL COMMENT 'Related sample request',
ADD COLUMN IF NOT EXISTS `invoice_category` ENUM('wholesale_order','sample','service','other') COLLATE utf8mb4_bin DEFAULT 'wholesale_order',
ADD KEY IF NOT EXISTS `idx_sample_request` (`sample_request_id`),
ADD KEY IF NOT EXISTS `idx_invoice_category` (`invoice_category`);

-- Foreign key for sample request (check if not exists)
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'invoices' 
    AND CONSTRAINT_NAME = 'fk_invoice_sample_request');

SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `invoices` ADD CONSTRAINT `fk_invoice_sample_request` FOREIGN KEY (`sample_request_id`) REFERENCES `sample_requests`(`id`) ON DELETE SET NULL',
    'SELECT "Foreign key already exists" AS message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- CREATE DEFAULT REPAYMENT SETTINGS FOR EXISTING AGENTS
-- ============================================

-- Create default repayment settings for all sales agents who don't have settings
-- Use a safe subquery approach to ensure created_by references valid user
INSERT INTO `agent_repayment_settings` (
    `agent_id`, `loan_id`, `repayment_method`, `percentage_rate`, 
    `grace_period_days`, `auto_deduct_after_days`, `is_active`, 
    `created_by`, `notes`
)
SELECT DISTINCT
    ur.user_id,
    NULL,
    'percentage_per_commission',
    20.00,
    30,
    30,
    1,
    ur.user_id,  -- Use the agent's own ID as created_by to satisfy FK
    'Default repayment settings - 20% per commission with 30-day grace period'
FROM user_roles ur
JOIN roles r ON r.id = ur.role_id
WHERE r.name = 'sales_agent'
AND NOT EXISTS (
    SELECT 1 FROM agent_repayment_settings ars 
    WHERE ars.agent_id = ur.user_id AND ars.loan_id IS NULL
)
ON DUPLICATE KEY UPDATE
    `agent_id` = VALUES(`agent_id`);

COMMIT;

-- ============================================
-- VERIFICATION QUERIES (for manual check)
-- ============================================

-- View all tables created
-- SELECT TABLE_NAME FROM information_schema.TABLES 
-- WHERE TABLE_SCHEMA = DATABASE() 
-- AND TABLE_NAME IN ('sales_agent_loans', 'loan_repayment_transactions', 'agent_repayment_settings', 'agent_expense_charges', 'sample_shipments');

-- View chart of accounts entry
-- SELECT * FROM chart_of_accounts WHERE account_code = '1180';

-- View default repayment settings
-- SELECT ars.*, u.first_name, u.last_name, u.email 
-- FROM agent_repayment_settings ars 
-- JOIN users u ON u.id = ars.agent_id 
-- WHERE ars.loan_id IS NULL;

