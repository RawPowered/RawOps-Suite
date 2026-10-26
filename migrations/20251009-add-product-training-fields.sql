-- Migration: Add Training Module Integration Fields to Products Table
-- Date: 2025-10-09
-- Purpose: Enable dynamic data integration between products and training modules

-- Add training-specific fields to products table
ALTER TABLE `products` 
ADD COLUMN IF NOT EXISTS `use_in_training` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Enable this product for training module examples',
ADD COLUMN IF NOT EXISTS `training_context` ENUM('all','beginner','intermediate','advanced','premium') DEFAULT 'all' COMMENT 'Training level context',
ADD COLUMN IF NOT EXISTS `training_features` JSON NULL COMMENT 'Product features for training (consistency, lab_tested, etc)',
ADD COLUMN IF NOT EXISTS `attribute_per_oz` DECIMAL(8,2) NULL COMMENT 'Milligrams of attribute per fluid ounce',
ADD COLUMN IF NOT EXISTS `size_oz` DECIMAL(8,2) NULL COMMENT 'Bottle size in fluid ounces',
ADD COLUMN IF NOT EXISTS `ml_per_10mg_serving` DECIMAL(8,3) NULL COMMENT 'Milliliters per 10mg attribute serving',
ADD COLUMN IF NOT EXISTS `oz_per_10mg_serving` DECIMAL(8,4) NULL COMMENT 'Fluid ounces per 10mg attribute serving',
ADD COLUMN IF NOT EXISTS `drinks_per_oz` DECIMAL(8,2) NULL COMMENT 'Number of 10mg drinks per fluid ounce',
ADD COLUMN IF NOT EXISTS `cost_per_10mg_serving` DECIMAL(8,2) NULL COMMENT 'Cost per 10mg serving',
ADD COLUMN IF NOT EXISTS `servings_10mg` INT NULL COMMENT 'Total 10mg servings per bottle',
ADD COLUMN IF NOT EXISTS `total_mg` INT NULL COMMENT 'Total attribute content in milligrams',
ADD COLUMN IF NOT EXISTS `recommended_first_dose_mg` DECIMAL(5,1) DEFAULT 5.0 COMMENT 'Recommended first dose for beginners (mg attribute)';

-- Add indexes for training queries (ignore if exists)
ALTER TABLE `products` 
ADD INDEX IF NOT EXISTS `idx_training` (`use_in_training`, `training_context`, `active`);

-- Create training module examples tracking table (skip if exists)
CREATE TABLE IF NOT EXISTS `training_module_examples` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `module_name` VARCHAR(100) NOT NULL COMMENT 'Module filename or identifier',
    `section_name` VARCHAR(255) NOT NULL COMMENT 'Section title or description',
    `line_start` INT NULL COMMENT 'Starting line number in source file',
    `line_end` INT NULL COMMENT 'Ending line number in source file',
    `example_type` ENUM('product_info','pricing','calculation','quiz','scenario') NOT NULL,
    `product_sku` VARCHAR(50) NULL COMMENT 'Related product SKU',
    `calculation_formula` TEXT NULL COMMENT 'Formula used for calculations',
    `quiz_question_num` INT NULL COMMENT 'Quiz question number',
    `correct_answer_key` CHAR(1) NULL COMMENT 'Correct answer (a,b,c,d)',
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_module` (`module_name`, `enabled`),
    INDEX `idx_product` (`product_sku`),
    INDEX `idx_type` (`example_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note: commission_tiers table already exists with columns:
-- id, name, min_monthly_sales, min_sales, commission_rate, bonus_rate, description, sort_order, created_at
-- We'll use the existing structure

-- Create bulk pricing table (skip if exists)
CREATE TABLE IF NOT EXISTS `bulk_pricing` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `sku` VARCHAR(50) NOT NULL,
    `quantity_tier_name` VARCHAR(20) NOT NULL COMMENT 'X5, X10, X15, X20',
    `quantity_min` INT NOT NULL,
    `discount_percent` DECIMAL(5,2) NOT NULL,
    `unit_price` DECIMAL(10,2) NOT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `idx_sku_tier` (`sku`, `quantity_tier_name`),
    INDEX `idx_tier` (`quantity_tier_name`, `active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample bulk pricing data for main products (adjust based on actual pricing)
INSERT INTO `bulk_pricing` (`sku`, `quantity_tier_name`, `quantity_min`, `discount_percent`, `unit_price`)
VALUES
-- 32/20 Blend pricing tiers
('BLEND-32-20', 'X1', 1, 0.00, 79.95),
('BLEND-32-20', 'X5', 5, 5.00, 75.95),
('BLEND-32-20', 'X10', 10, 8.00, 73.55),
('BLEND-32-20', 'X15', 15, 10.00, 71.96),
('BLEND-32-20', 'X20', 20, 12.00, 70.36),

-- 128/20 Blend pricing tiers
('BLEND-128-20', 'X1', 1, 0.00, 179.95),
('BLEND-128-20', 'X5', 5, 5.00, 170.95),
('BLEND-128-20', 'X10', 10, 8.00, 165.56),
('BLEND-128-20', 'X15', 15, 10.00, 161.96),
('BLEND-128-20', 'X20', 20, 15.00, 152.96),

-- 32/75 Blend pricing tiers
('BLEND-32-75', 'X1', 1, 0.00, 169.95),
('BLEND-32-75', 'X5', 5, 5.00, 161.45),
('BLEND-32-75', 'X10', 10, 8.00, 156.36),
('BLEND-32-75', 'X15', 15, 10.00, 152.96),
('BLEND-32-75', 'X20', 20, 12.00, 149.56),

-- 128/75 Blend pricing tiers
('BLEND-128-75', 'X1', 1, 0.00, 519.95),
('BLEND-128-75', 'X5', 5, 5.00, 493.95),
('BLEND-128-75', 'X10', 10, 8.00, 478.35),
('BLEND-128-75', 'X15', 15, 10.00, 467.96),
('BLEND-128-75', 'X20', 20, 12.00, 457.56)
ON DUPLICATE KEY UPDATE 
    `discount_percent`=VALUES(`discount_percent`),
    `unit_price`=VALUES(`unit_price`);

-- Update existing products with calculated training values (only if not already set)
UPDATE `products` SET
    `attribute_per_oz` = CASE 
        WHEN `sku` LIKE '%20' THEN 20.00
        WHEN `sku` LIKE '%75' THEN 75.00
        ELSE `attribute_per_oz`
    END,
    `size_oz` = CASE
        WHEN `sku` LIKE 'BLEND-32-%' THEN 32.00
        WHEN `sku` LIKE 'BLEND-128-%' THEN 128.00
        ELSE `size_oz`
    END
WHERE `sku` LIKE 'BLEND-%' AND `attribute_per_oz` IS NULL;

-- Calculate derived fields for products with attribute data
UPDATE `products` p
SET 
    `total_mg` = `attribute_per_oz` * `size_oz`,
    `servings_10mg` = FLOOR((`attribute_per_oz` * `size_oz`) / 10),
    `ml_per_10mg_serving` = (10 / `attribute_per_oz`) * 29.5735,
    `oz_per_10mg_serving` = 10 / `attribute_per_oz`,
    `drinks_per_oz` = `attribute_per_oz` / 10,
    `cost_per_10mg_serving` = `wholesale_price` / FLOOR((`attribute_per_oz` * `size_oz`) / 10)
WHERE `attribute_per_oz` IS NOT NULL 
    AND `attribute_per_oz` > 0
    AND `size_oz` IS NOT NULL 
    AND `size_oz` > 0;

-- Enable training for main 4 products
UPDATE `products` SET
    `use_in_training` = 1,
    `training_context` = CASE
        WHEN `sku` = 'BLEND-32-20' THEN 'beginner'
        WHEN `sku` = 'BLEND-128-20' THEN 'intermediate'
        WHEN `sku` = 'BLEND-32-75' THEN 'advanced'
        WHEN `sku` = 'BLEND-128-75' THEN 'premium'
        ELSE 'all'
    END,
    `training_features` = CASE
        WHEN `sku` LIKE '%20' THEN JSON_OBJECT(
            'consistency_advantage', true,
            'lab_tested', true,
            'tasteless', true,
            'standardization', true,
            'product_grade', true,
            'beginner_friendly', true,
            'high_attribute', false,
            'bulk_value', `sku` LIKE '%128%'
        )
        WHEN `sku` LIKE '%75' THEN JSON_OBJECT(
            'consistency_advantage', true,
            'lab_tested', true,
            'tasteless', true,
            'standardization', true,
            'product_grade', true,
            'beginner_friendly', false,
            'high_attribute', true,
            'bulk_value', `sku` LIKE '%128%'
        )
        ELSE `training_features`
    END
WHERE `sku` IN ('BLEND-32-20', 'BLEND-128-20', 'BLEND-32-75', 'BLEND-128-75');

-- Insert sample training module example tracking entries
INSERT INTO `training_module_examples` 
    (`module_name`, `section_name`, `line_start`, `line_end`, `example_type`, `product_sku`, `notes`)
VALUES
    ('products-product categories-new.php', 'Main Product Lineup', 47, 118, 'product_info', 'BLEND-32-20', 'Primary product showcase'),
    ('products-product categories-new.php', 'Main Product Lineup', 47, 118, 'product_info', 'BLEND-128-20', 'Primary product showcase'),
    ('products-product categories-new.php', 'Main Product Lineup', 47, 118, 'product_info', 'BLEND-32-75', 'Primary product showcase'),
    ('products-product categories-new.php', 'Main Product Lineup', 47, 118, 'product_info', 'BLEND-128-75', 'Primary product showcase'),
    ('products-product categories-new.php', 'Commission Examples', 153, 159, 'calculation', 'BLEND-32-20', 'Commission calculation example'),
    ('products-product categories-new.php', 'Volume Scenarios', 284, 300, 'calculation', 'BLEND-128-20', 'Volume and commission scenarios'),
    ('products-product categories-new.php', 'Profit Calculator Live PHP', 388, 523, 'calculation', 'BLEND-128-20', 'Dynamic profit table'),
    ('products-product categories-new.php', 'Profit Calculator Live PHP', 388, 523, 'calculation', 'BLEND-128-75', 'Dynamic profit table'),
    ('objection-mastery.php', 'Quick Price Sheet', 79, 119, 'pricing', 'BLEND-32-20', 'Quick reference pricing grid'),
    ('objection-mastery.php', 'Quick Price Sheet', 79, 119, 'pricing', 'BLEND-128-20', 'Quick reference pricing grid'),
    ('objection-mastery.php', 'Quick Price Sheet', 79, 119, 'pricing', 'BLEND-32-75', 'Quick reference pricing grid'),
    ('objection-mastery.php', 'Quick Price Sheet', 79, 119, 'pricing', 'BLEND-128-75', 'Quick reference pricing grid'),
    ('profit-calculator-training.php', 'Magic Numbers', 28, 56, 'product_info', 'BLEND-32-75', 'Key metrics memorization'),
    ('profit-calculator-training.php', 'Magic Numbers', 28, 56, 'product_info', 'BLEND-32-20', 'Key metrics memorization'),
    ('final-exam.php', 'Question 4', 75, 83, 'quiz', 'BLEND-32-20', 'Drinks per bottle calculation'),
    ('final-exam.php', 'Question 17', 211, 218, 'quiz', NULL, 'Commission tier calculation'),
    ('final-assessment.php', 'Question 21', 246, 253, 'quiz', 'BLEND-32-75', 'Drinks per oz calculation'),
    ('final-assessment.php', 'Question 22', 255, 263, 'quiz', 'BLEND-128-20', 'Profit calculation'),
    ('final-assessment.php', 'Question 23', 265, 273, 'quiz', 'BLEND-32-75', 'Net profit calculation')
ON DUPLICATE KEY UPDATE `notes`=VALUES(`notes`);

-- Success message
SELECT 'Migration completed successfully! Training module integration fields added.' AS status;
