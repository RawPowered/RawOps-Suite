-- Migration: Add product tracking for product-pack trial variants (Product B and Product A)
-- Date: 2025-10-22
-- Description: Adds product_type fields to track which product-pack variant customers received/reviewed

-- Add product_type to promo_demo_leads (safe add with check)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'promo_demo_leads' 
    AND COLUMN_NAME = 'product_type');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `promo_demo_leads` ADD COLUMN `product_type` ENUM(''gold_40'', ''blue_20'') NULL COMMENT ''product-pack product variant: gold_40 (40mg/oz, 120mg total) or blue_20 (20mg/oz, 60mg total)'' AFTER `sales_agent_id`',
    'SELECT "Column product_type already exists in promo_demo_leads"');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add product_type to promo_demo_reviews (safe add with check)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'promo_demo_reviews' 
    AND COLUMN_NAME = 'product_type');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `promo_demo_reviews` ADD COLUMN `product_type` ENUM(''gold_40'', ''blue_20'') NULL COMMENT ''Product variant being reviewed'' AFTER `demo_lead_id`',
    'SELECT "Column product_type already exists in promo_demo_reviews"');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add sample_request_id to promo_demo_reviews if not exists (safe add with check)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'promo_demo_reviews' 
    AND COLUMN_NAME = 'sample_request_id');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `promo_demo_reviews` ADD COLUMN `sample_request_id` BIGINT UNSIGNED NULL AFTER `product_type`, ADD KEY `idx_sample_request` (`sample_request_id`)',
    'SELECT "Column sample_request_id already exists"');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Update existing source from product-sample to product-pack-trial, or insert if neither exists
INSERT INTO `promo_demo_sources` (`slug`, `name`, `description`, `created_at`)
VALUES (
    'product-pack-trial', 
    'Product product-pack Trial (Product B & Product A)',
    'Landing page for product-pack demo bottle recipients - Product B (120mg total, 40mg/oz) and Product A (60mg total, 20mg/oz) variants',
    NOW()
)
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `description` = VALUES(`description`);

-- Clean up old product-sample source if it exists and is different from product-pack-trial
DELETE FROM `promo_demo_sources` WHERE `slug` = 'product-sample' AND `slug` != 'product-pack-trial';


