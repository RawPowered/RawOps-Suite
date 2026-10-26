-- Sample Request System Migration
-- Date: 2025-10-11
-- Purpose: Comprehensive sample request system with shipping integration, inventory tracking, and accounting
-- Binary-safe, ON DUPLICATE KEY UPDATE patterns, no duplicate errors

START TRANSACTION;

-- ============================================
-- EXTEND PRODUCTS TABLE
-- ============================================

-- Add sample product flags to products table
ALTER TABLE `products` 
ADD COLUMN IF NOT EXISTS `is_sample` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Flag indicating this is a sample product',
ADD COLUMN IF NOT EXISTS `sample_parent_product_id` BIGINT UNSIGNED NULL COMMENT 'Parent product this sample is based on',
ADD INDEX IF NOT EXISTS `idx_is_sample` (`is_sample`),
ADD INDEX IF NOT EXISTS `idx_sample_parent` (`sample_parent_product_id`);

-- ============================================
-- CREATE SAMPLE PRODUCTS
-- ============================================

-- Insert Blue-10 2oz Sample Product
INSERT INTO `products` (
    `product_group_id`, `sku`, `name`, `description`, 
    `attribute_content`, `weight_grams`, `wholesale_price`, `msrp`, 
    `stock_quantity`, `low_stock_threshold`, `active`, 
    `size_oz`, `attribute_per_oz`, `ml_per_10mg_serving`, 
    `oz_per_10mg_serving`, `drinks_per_oz`, `servings_10mg`, 
    `total_mg`, `material_cost`, `bottle_cost`, `label_cost`, 
    `labor_cost`, `mix_cost`, `total_production_cost`, 
    `is_sample`, `created_at`, `updated_at`
) VALUES (
    1, -- Products product group
    'SAMPLE-BLUE-10-2OZ',
    'Blue-10 Sample (2oz)',
    'Premium 2oz sample of our Blue-10 Product. Perfect for introducing customers to our catalog-ready attribute product concentrate. 20mg attribute per oz for precise, gentle dosing.',
    10.00, -- 10mg attribute per oz * 2oz = 20mg total (stored as mg_per_oz * size_oz / 2)
    60.00, -- ~2oz in grams
    0.00, -- No wholesale price (promotional)
    0.00, -- No MSRP (promotional)
    50, -- Initial stock
    10, -- Low stock threshold
    1, -- Active
    2.00, -- 2oz size
    10.00, -- 10mg attribute per oz (Blue-10 = 10mg/oz)
    2.95735, -- ml per 10mg serving
    0.1, -- oz per 10mg serving
    10, -- 10 servings per oz
    20, -- 20 total servings (2oz * 10)
    20.00, -- Total 20mg attribute
    1.50, -- Material cost (inherit from product group defaults)
    2.50, -- Bottle cost
    2.50, -- Label cost
    2.50, -- Labor cost (reduced for sample size)
    0.75, -- Mix cost
    9.75, -- Total production cost
    1, -- Is sample
    NOW(),
    NOW()
) ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `attribute_content` = VALUES(`attribute_content`),
    `is_sample` = VALUES(`is_sample`),
    `stock_quantity` = VALUES(`stock_quantity`),
    `updated_at` = NOW();

-- Insert Gold-30 2oz Sample Product
INSERT INTO `products` (
    `product_group_id`, `sku`, `name`, `description`, 
    `attribute_content`, `weight_grams`, `wholesale_price`, `msrp`, 
    `stock_quantity`, `low_stock_threshold`, `active`, 
    `size_oz`, `attribute_per_oz`, `ml_per_10mg_serving`, 
    `oz_per_10mg_serving`, `drinks_per_oz`, `servings_10mg`, 
    `total_mg`, `material_cost`, `bottle_cost`, `label_cost`, 
    `labor_cost`, `mix_cost`, `total_production_cost`, 
    `is_sample`, `created_at`, `updated_at`
) VALUES (
    1, -- Products product group
    'SAMPLE-GOLD-30-2OZ',
    'Gold-30 Sample (2oz)',
    'Premium 2oz sample of our Gold-30 Product. Experience our mid-product specification catalog-ready attribute product concentrate. 30mg attribute per oz for balanced, effective dosing.',
    30.00, -- 30mg attribute per oz * 2oz = 60mg total
    60.00, -- ~2oz in grams
    0.00, -- No wholesale price (promotional)
    0.00, -- No MSRP (promotional)
    50, -- Initial stock
    10, -- Low stock threshold
    1, -- Active
    2.00, -- 2oz size
    30.00, -- 30mg attribute per oz (Gold-30 = 30mg/oz)
    0.98578, -- ml per 10mg serving
    0.0333, -- oz per 10mg serving
    30, -- 30 servings per oz
    60, -- 60 total servings (2oz * 30)
    60.00, -- Total 60mg attribute
    4.50, -- Material cost (3x Blue-10 due to 3x product specification)
    2.50, -- Bottle cost
    2.50, -- Label cost
    2.50, -- Labor cost
    0.75, -- Mix cost
    12.75, -- Total production cost
    1, -- Is sample
    NOW(),
    NOW()
) ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `attribute_content` = VALUES(`attribute_content`),
    `is_sample` = VALUES(`is_sample`),
    `stock_quantity` = VALUES(`stock_quantity`),
    `updated_at` = NOW();

-- ============================================
-- SAMPLE INVENTORY TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS `sample_inventory` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to products table',
    `quantity_available` INT NOT NULL DEFAULT 0 COMMENT 'Total quantity available for requests',
    `quantity_reserved` INT NOT NULL DEFAULT 0 COMMENT 'Quantity reserved for pending requests',
    `quantity_shipped` INT NOT NULL DEFAULT 0 COMMENT 'Cumulative quantity shipped',
    `low_stock_threshold` INT NOT NULL DEFAULT 10 COMMENT 'Alert threshold',
    `last_restocked_at` TIMESTAMP NULL COMMENT 'Last restock date',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_product` (`product_id`),
    KEY `idx_quantity_available` (`quantity_available`),
    CONSTRAINT `fk_sample_inventory_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initialize inventory for sample products
INSERT INTO `sample_inventory` (`product_id`, `quantity_available`, `quantity_reserved`, `quantity_shipped`, `low_stock_threshold`, `last_restocked_at`)
SELECT 
    p.id,
    50, -- Initial quantity
    0, -- No reservations yet
    0, -- No shipments yet
    10, -- Low stock threshold
    NOW()
FROM `products` p
WHERE p.sku IN ('SAMPLE-BLUE-10-2OZ', 'SAMPLE-GOLD-30-2OZ')
ON DUPLICATE KEY UPDATE
    `quantity_available` = VALUES(`quantity_available`),
    `last_restocked_at` = VALUES(`last_restocked_at`);

-- ============================================
-- SAMPLE REQUESTS TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS `sample_requests` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `agent_id` BIGINT UNSIGNED NOT NULL COMMENT 'Sales agent requesting sample',
    `lead_id` BIGINT UNSIGNED NULL COMMENT 'Associated lead (if applicable)',
    `account_id` BIGINT UNSIGNED NULL COMMENT 'Associated account (if applicable)',
    `sample_product_id` BIGINT UNSIGNED NOT NULL COMMENT 'Sample product being requested',
    `request_type` ENUM('shipping','drop_off') COLLATE utf8mb4_bin NOT NULL DEFAULT 'shipping',
    `quantity` INT NOT NULL DEFAULT 1,
    `recipient_name` VARCHAR(200) COLLATE utf8mb4_bin NOT NULL,
    `recipient_address_line1` VARCHAR(255) COLLATE utf8mb4_bin NULL,
    `recipient_address_line2` VARCHAR(255) COLLATE utf8mb4_bin NULL,
    `recipient_city` VARCHAR(100) COLLATE utf8mb4_bin NULL,
    `recipient_state` VARCHAR(50) COLLATE utf8mb4_bin NULL,
    `recipient_zip` VARCHAR(20) COLLATE utf8mb4_bin NULL,
    `recipient_country` VARCHAR(50) COLLATE utf8mb4_bin DEFAULT 'USA',
    `recipient_phone` VARCHAR(50) COLLATE utf8mb4_bin NULL,
    `shipping_carrier` VARCHAR(50) COLLATE utf8mb4_bin NULL COMMENT 'UPS, USPS, FedEx',
    `shipping_service` VARCHAR(100) COLLATE utf8mb4_bin NULL COMMENT 'Service level selected',
    `shipping_cost` DECIMAL(10,2) NULL COMMENT 'Actual shipping cost charged',
    `tracking_number` VARCHAR(100) COLLATE utf8mb4_bin NULL,
    `status` ENUM('pending','approved','shipped','delivered','rejected','cancelled') COLLATE utf8mb4_bin NOT NULL DEFAULT 'pending',
    `admin_notes` TEXT COLLATE utf8mb4_bin NULL,
    `agent_notes` TEXT COLLATE utf8mb4_bin NULL COMMENT 'Notes from sales agent',
    `requested_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `approved_at` TIMESTAMP NULL,
    `approved_by` BIGINT UNSIGNED NULL COMMENT 'Admin who approved',
    `shipped_at` TIMESTAMP NULL,
    `delivered_at` TIMESTAMP NULL,
    `rejected_at` TIMESTAMP NULL,
    `expense_id` BIGINT UNSIGNED NULL COMMENT 'Reference to expenses table',
    `journal_entry_id` BIGINT UNSIGNED NULL COMMENT 'Reference to journal_entries table',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_agent` (`agent_id`),
    KEY `idx_lead` (`lead_id`),
    KEY `idx_account` (`account_id`),
    KEY `idx_sample_product` (`sample_product_id`),
    KEY `idx_status` (`status`),
    KEY `idx_request_type` (`request_type`),
    KEY `idx_approved_by` (`approved_by`),
    KEY `idx_requested_at` (`requested_at`),
    KEY `idx_shipped_at` (`shipped_at`),
    CONSTRAINT `fk_sample_request_agent` FOREIGN KEY (`agent_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sample_request_lead` FOREIGN KEY (`lead_id`) REFERENCES `crm_leads`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_sample_request_account` FOREIGN KEY (`account_id`) REFERENCES `wholesale_accounts`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_sample_request_product` FOREIGN KEY (`sample_product_id`) REFERENCES `products`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_sample_request_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- SAMPLE SHIPPING QUOTES TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS `sample_shipping_quotes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sample_request_id` BIGINT UNSIGNED NOT NULL,
    `carrier` VARCHAR(50) COLLATE utf8mb4_bin NOT NULL COMMENT 'UPS, USPS, FedEx',
    `service_name` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `service_code` VARCHAR(50) COLLATE utf8mb4_bin NULL,
    `delivery_days` INT NULL COMMENT 'Estimated delivery days',
    `cost` DECIMAL(10,2) NOT NULL,
    `currency` VARCHAR(3) COLLATE utf8mb4_bin DEFAULT 'USD',
    `quoted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` TIMESTAMP NULL COMMENT 'Quote expiration',
    `quote_data` JSON NULL COMMENT 'Full API response',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sample_request` (`sample_request_id`),
    KEY `idx_carrier` (`carrier`),
    KEY `idx_cost` (`cost`),
    KEY `idx_expires_at` (`expires_at`),
    CONSTRAINT `fk_shipping_quote_request` FOREIGN KEY (`sample_request_id`) REFERENCES `sample_requests`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- EXTEND CRM DEMO DROPOFFS TABLE
-- ============================================

-- Link demo dropoffs to sample requests
ALTER TABLE `crm_demo_dropoffs`
ADD COLUMN IF NOT EXISTS `sample_request_id` BIGINT UNSIGNED NULL COMMENT 'Link to sample_requests table',
ADD INDEX IF NOT EXISTS `idx_sample_request` (`sample_request_id`);

-- Add foreign key if not exists (check first to avoid errors)
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'crm_demo_dropoffs' 
    AND CONSTRAINT_NAME = 'fk_demo_sample_request');

SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `crm_demo_dropoffs` ADD CONSTRAINT `fk_demo_sample_request` FOREIGN KEY (`sample_request_id`) REFERENCES `sample_requests`(`id`) ON DELETE SET NULL',
    'SELECT "Foreign key already exists" AS message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- ACCOUNTING: CHART OF ACCOUNTS EXTENSION
-- ============================================

-- Add Sample Shipping Expense account
INSERT INTO `chart_of_accounts` (
    `account_code`, `account_name`, `account_type`, `is_system`, 
    `is_active`, `description`, `tax_category`, `normal_balance`, 
    `created_at`
) VALUES (
    '6150',
    'Sample Shipping Expense',
    'expense',
    1,
    1,
    'Shipping costs for product samples sent to prospects and customers',
    'Operating Expenses',
    'debit',
    NOW()
) ON DUPLICATE KEY UPDATE
    `account_name` = VALUES(`account_name`),
    `description` = VALUES(`description`),
    `is_active` = 1,
    `updated_at` = NOW();

-- Add Sample Inventory asset account
INSERT INTO `chart_of_accounts` (
    `account_code`, `account_name`, `account_type`, `is_system`, 
    `is_active`, `description`, `tax_category`, `normal_balance`, 
    `created_at`
) VALUES (
    '1150',
    'Sample Inventory',
    'asset',
    1,
    1,
    'Inventory of product samples for promotional and sales purposes',
    'Current Assets',
    'debit',
    NOW()
) ON DUPLICATE KEY UPDATE
    `account_name` = VALUES(`account_name`),
    `description` = VALUES(`description`),
    `is_active` = 1,
    `updated_at` = NOW();

-- Add Marketing/Promotional Expense account (if not exists)
INSERT INTO `chart_of_accounts` (
    `account_code`, `account_name`, `account_type`, `is_system`, 
    `is_active`, `description`, `tax_category`, `normal_balance`, 
    `created_at`
) VALUES (
    '6200',
    'Marketing & Promotional Expense',
    'expense',
    1,
    1,
    'Marketing materials, promotional items, advertising, and sample giveaways',
    'Operating Expenses',
    'debit',
    NOW()
) ON DUPLICATE KEY UPDATE
    `account_name` = VALUES(`account_name`),
    `description` = VALUES(`description`),
    `is_active` = 1,
    `updated_at` = NOW();

-- ============================================
-- EXPENSE CATEGORIES FOR SAMPLES
-- ============================================

-- Get account IDs for expense categories
SET @shipping_account_id = (SELECT id FROM chart_of_accounts WHERE account_code = '6150' LIMIT 1);
SET @marketing_account_id = (SELECT id FROM chart_of_accounts WHERE account_code = '6200' LIMIT 1);

-- Sample Shipping expense category
INSERT INTO `expense_categories` (
    `category_name`, `account_id`, `is_system`, `is_active`, 
    `allocation_method`, `description`, `created_at`
) VALUES (
    'Sample Shipping',
    @shipping_account_id,
    1,
    1,
    'direct',
    'Shipping costs for product samples',
    NOW()
) ON DUPLICATE KEY UPDATE
    `account_id` = VALUES(`account_id`),
    `is_active` = 1,
    `updated_at` = NOW();

-- Sample Promotional expense category
INSERT INTO `expense_categories` (
    `category_name`, `account_id`, `is_system`, `is_active`, 
    `allocation_method`, `description`, `created_at`
) VALUES (
    'Sample - Promotional',
    @marketing_account_id,
    1,
    1,
    'direct',
    'Cost of sample products given to prospects',
    NOW()
) ON DUPLICATE KEY UPDATE
    `account_id` = VALUES(`account_id`),
    `is_active` = 1,
    `updated_at` = NOW();

-- ============================================
-- INVENTORY MOVEMENT LOG TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS `sample_inventory_movements` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `movement_type` ENUM('restock','reservation','release','shipment','adjustment') COLLATE utf8mb4_bin NOT NULL,
    `quantity_change` INT NOT NULL COMMENT 'Positive for additions, negative for deductions',
    `quantity_after` INT NOT NULL COMMENT 'Quantity after this movement',
    `reference_type` VARCHAR(50) COLLATE utf8mb4_bin NULL COMMENT 'sample_request, manual_adjustment',
    `reference_id` BIGINT UNSIGNED NULL,
    `reason` TEXT COLLATE utf8mb4_bin NULL,
    `performed_by` BIGINT UNSIGNED NULL COMMENT 'User who performed this movement',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_product` (`product_id`),
    KEY `idx_movement_type` (`movement_type`),
    KEY `idx_reference` (`reference_type`, `reference_id`),
    KEY `idx_performed_by` (`performed_by`),
    KEY `idx_created_at` (`created_at`),
    CONSTRAINT `fk_inventory_movement_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_inventory_movement_user` FOREIGN KEY (`performed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

-- ============================================
-- VERIFICATION QUERIES (for manual check)
-- ============================================

-- View created sample products
-- SELECT * FROM products WHERE is_sample = 1;

-- View sample inventory
-- SELECT si.*, p.sku, p.name 
-- FROM sample_inventory si 
-- JOIN products p ON p.id = si.product_id;

-- View chart of accounts additions
-- SELECT * FROM chart_of_accounts WHERE account_code IN ('1150', '6150', '6200');





