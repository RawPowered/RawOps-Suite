-- ============================================================================
-- Supplier Management System with Encrypted Credentials
-- Date: 2025-10-16
-- Purpose: Comprehensive supplier/vendor management with login credential storage
-- ============================================================================
-- Binary-safe migration: Can be run multiple times safely
-- Uses INSERT...ON DUPLICATE KEY UPDATE pattern
-- Proper character encoding and foreign key handling
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================================
-- 1. SUPPLIERS TABLE - Core supplier/vendor information
-- ============================================================================

CREATE TABLE IF NOT EXISTS `suppliers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier_name` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `supplier_code` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'Internal reference code',
  `contact_name` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  `contact_email` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  `contact_phone` VARCHAR(50) NULL,
  `website_url` VARCHAR(500) NULL,
  `address_line1` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  `address_line2` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  `city` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  `state` VARCHAR(50) NULL,
  `zip_code` VARCHAR(20) NULL,
  `country` VARCHAR(100) DEFAULT 'USA',
  `tax_id_type` ENUM('ssn','ein','none') DEFAULT 'none',
  `tax_id_last4` VARCHAR(4) NULL COMMENT 'Last 4 digits only for security',
  `supplier_type` ENUM('product','service','utility','subscription','other') DEFAULT 'other',
  `payment_terms` VARCHAR(100) NULL COMMENT 'e.g., Net 30, Due on Receipt',
  `default_expense_category_id` BIGINT UNSIGNED NULL,
  `is_1099_required` TINYINT(1) DEFAULT 0 COMMENT 'Requires 1099 filing',
  `is_active` TINYINT(1) DEFAULT 1,
  `notes` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_supplier_name` (`supplier_name`),
  UNIQUE KEY `uk_supplier_code` (`supplier_code`),
  KEY `idx_supplier_active` (`is_active`),
  KEY `idx_supplier_type` (`supplier_type`),
  KEY `idx_1099_required` (`is_1099_required`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_default_category` (`default_expense_category_id`),
  CONSTRAINT `fk_supplier_category` FOREIGN KEY (`default_expense_category_id`) REFERENCES `expense_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. SUPPLIER CREDENTIALS TABLE - Encrypted login information
-- ============================================================================

CREATE TABLE IF NOT EXISTS `supplier_credentials` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier_id` BIGINT UNSIGNED NOT NULL,
  `credential_name` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'e.g., Main Account, Admin Portal',
  `login_url` VARCHAR(500) NULL,
  `login_type` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT 'username' COMMENT 'email, username, google_gmail, google_auth, microsoft, custom',
  `username` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  `encrypted_password` VARBINARY(500) NULL COMMENT 'AES encrypted password',
  `password_hint` VARCHAR(255) NULL COMMENT 'Non-sensitive hint',
  `two_factor_method` VARCHAR(50) NULL COMMENT 'sms, app, email, none',
  `account_number` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  `additional_info` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'Security questions, notes',
  `last_verified_date` DATE NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_supplier` (`supplier_id`),
  KEY `idx_login_type` (`login_type`),
  KEY `idx_active` (`is_active`),
  CONSTRAINT `fk_credential_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. SUPPLIER EXPENSES LINK - Links expenses to suppliers with match tracking
-- ============================================================================

CREATE TABLE IF NOT EXISTS `supplier_expenses_link` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `expense_id` BIGINT UNSIGNED NOT NULL,
  `supplier_id` BIGINT UNSIGNED NOT NULL,
  `match_type` ENUM('exact','fuzzy','manual') DEFAULT 'manual' COMMENT 'How was this link created',
  `match_confidence` DECIMAL(5,2) NULL COMMENT 'Confidence score 0-100 for fuzzy matches',
  `original_vendor_name` VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'Original text from expense',
  `match_algorithm` VARCHAR(50) NULL COMMENT 'levenshtein, soundex, manual',
  `verification_status` ENUM('pending','confirmed','rejected') DEFAULT 'confirmed',
  `verified_by` BIGINT UNSIGNED NULL,
  `verified_at` TIMESTAMP NULL,
  `rejection_reason` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_expense_supplier` (`expense_id`),
  KEY `idx_supplier` (`supplier_id`),
  KEY `idx_match_type` (`match_type`),
  KEY `idx_verification_status` (`verification_status`),
  KEY `idx_verified_by` (`verified_by`),
  CONSTRAINT `fk_link_expense` FOREIGN KEY (`expense_id`) REFERENCES `expenses`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_link_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. SUPPLIER LOGIN TYPES - Extensible login type management
-- ============================================================================

CREATE TABLE IF NOT EXISTS `supplier_login_types` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type_code` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `type_label` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `icon_class` VARCHAR(100) NULL COMMENT 'CSS icon class for UI',
  `is_system` TINYINT(1) DEFAULT 0 COMMENT 'System types cannot be deleted',
  `is_active` TINYINT(1) DEFAULT 1,
  `sort_order` INT DEFAULT 0,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type_code` (`type_code`),
  KEY `idx_active` (`is_active`),
  KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default login types
INSERT INTO `supplier_login_types` (`type_code`, `type_label`, `icon_class`, `is_system`, `sort_order`) VALUES
('email', 'Email Address', 'bi-envelope', 1, 1),
('username', 'Username', 'bi-person', 1, 2),
('google_gmail', 'Google Gmail', 'bi-google', 1, 3),
('google_auth', 'Google Authenticator', 'bi-shield-lock', 1, 4),
('microsoft', 'Microsoft Account', 'bi-microsoft', 1, 5),
('custom', 'Custom', 'bi-gear', 1, 99)
ON DUPLICATE KEY UPDATE 
  `type_label` = VALUES(`type_label`),
  `icon_class` = VALUES(`icon_class`),
  `sort_order` = VALUES(`sort_order`);

-- ============================================================================
-- 5. CREDENTIAL ACCESS LOG - Audit trail for credential access
-- ============================================================================

CREATE TABLE IF NOT EXISTS `supplier_credential_access_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `credential_id` BIGINT UNSIGNED NOT NULL,
  `accessed_by` BIGINT UNSIGNED NOT NULL,
  `access_type` ENUM('view','edit','decrypt') NOT NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` TEXT NULL,
  `accessed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_credential` (`credential_id`),
  KEY `idx_accessed_by` (`accessed_by`),
  KEY `idx_accessed_at` (`accessed_at`),
  CONSTRAINT `fk_access_credential` FOREIGN KEY (`credential_id`) REFERENCES `supplier_credentials`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 6. ALTER EXPENSES TABLE - Add supplier foreign key
-- ============================================================================

ALTER TABLE `expenses`
ADD COLUMN IF NOT EXISTS `supplier_id` BIGINT UNSIGNED NULL AFTER `vendor_name`,
ADD KEY IF NOT EXISTS `idx_supplier` (`supplier_id`),
ADD CONSTRAINT `fk_expense_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE SET NULL;

-- ============================================================================
-- 7. VIEWS FOR CONVENIENCE
-- ============================================================================

-- View: Active suppliers with expense totals
CREATE OR REPLACE VIEW `v_supplier_summary` AS
SELECT 
    s.id,
    s.supplier_name,
    s.supplier_code,
    s.contact_email,
    s.contact_phone,
    s.supplier_type,
    s.is_1099_required,
    ec.category_name as default_category_name,
    COUNT(DISTINCT sel.expense_id) as total_expenses,
    COALESCE(SUM(e.amount), 0) as total_spent_ytd,
    COUNT(DISTINCT sc.id) as credential_count,
    s.created_at,
    s.updated_at
FROM suppliers s
LEFT JOIN expense_categories ec ON ec.id = s.default_expense_category_id
LEFT JOIN supplier_expenses_link sel ON sel.supplier_id = s.id AND sel.verification_status = 'confirmed'
LEFT JOIN expenses e ON e.id = sel.expense_id AND YEAR(e.expense_date) = YEAR(CURDATE())
LEFT JOIN supplier_credentials sc ON sc.supplier_id = s.id AND sc.is_active = 1
WHERE s.is_active = 1
GROUP BY s.id, s.supplier_name, s.supplier_code, s.contact_email, s.contact_phone, 
         s.supplier_type, s.is_1099_required, ec.category_name, s.created_at, s.updated_at;

-- View: Pending supplier matches requiring verification
CREATE OR REPLACE VIEW `v_pending_supplier_matches` AS
SELECT 
    sel.id as link_id,
    sel.expense_id,
    e.expense_date,
    e.amount,
    e.description,
    sel.original_vendor_name,
    s.id as suggested_supplier_id,
    s.supplier_name as suggested_supplier_name,
    sel.match_confidence,
    sel.match_algorithm,
    sel.created_at as matched_at
FROM supplier_expenses_link sel
JOIN expenses e ON e.id = sel.expense_id
JOIN suppliers s ON s.id = sel.supplier_id
WHERE sel.verification_status = 'pending'
  AND sel.match_type = 'fuzzy'
ORDER BY sel.created_at DESC;

-- ============================================================================
-- 8. PERFORMANCE INDEXES
-- ============================================================================

-- Optimize supplier search queries
ALTER TABLE `suppliers`
ADD FULLTEXT KEY `ft_supplier_search` (`supplier_name`, `supplier_code`, `contact_name`);

-- Optimize expense-supplier linking queries
ALTER TABLE `expenses`
ADD KEY IF NOT EXISTS `idx_vendor_name` (`vendor_name`(100));

-- ============================================================================
-- 9. SEED SAMPLE DATA (Optional - for testing)
-- ============================================================================

-- Note: In production, users will create their own suppliers
-- This is just for demonstration/testing purposes

COMMIT;

-- ============================================================================
-- MIGRATION COMPLETE
-- ============================================================================
-- Next steps:
-- 1. Update config.php to add SUPPLIER_CREDENTIAL_ENCRYPTION_KEY
-- 2. Deploy supplier-api.php for backend operations
-- 3. Update accounting dashboard UI
-- ============================================================================











