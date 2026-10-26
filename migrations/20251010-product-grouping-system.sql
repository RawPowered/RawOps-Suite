-- ============================================================
-- Migration: Product Grouping System
-- Date: 2025-10-10
-- Purpose: Create product hierarchy with group-specific pricing and commission structures
--
-- Features:
-- - Binary-safe collations (utf8mb4_bin) for all keys to prevent foreign character issues
-- - ON DUPLICATE KEY UPDATE for all inserts to prevent duplication errors
-- - Dynamic database detection (no hardcoded database names)
-- - Conditional schema changes (safe to run multiple times)
-- ============================================================

-- Note: Database is auto-detected, no USE statement needed
-- If you need to explicitly set database, uncomment the line below:
-- USE foggksfx_product;

-- ============================================================
-- PART 1: Create Product Groups Table
-- ============================================================

CREATE TABLE IF NOT EXISTS `product_groups` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `group_key` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL UNIQUE COMMENT 'Unique identifier (e.g., product-products)',
  `name` VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Display name (e.g., Products)',
  `description` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `display_order` INT(11) NOT NULL DEFAULT 0 COMMENT 'Sort order for display',
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  
  -- Pricing Model Settings (group-specific overrides of global defaults)
  `ingredient_cost_per_gram` DECIMAL(10,4) DEFAULT NULL COMMENT 'Override global ingredient cost',
  `attribute_percentage` DECIMAL(5,2) DEFAULT NULL COMMENT 'Override global attribute percentage',
  `bottle_cost` DECIMAL(10,2) DEFAULT NULL COMMENT 'Override global bottle cost',
  `label_cost` DECIMAL(10,2) DEFAULT NULL COMMENT 'Override global label cost',
  `labor_cost` DECIMAL(10,2) DEFAULT NULL COMMENT 'Override global labor cost',
  `profit_multiplier` DECIMAL(5,2) DEFAULT NULL COMMENT 'Override global profit multiplier',
  `mix_cost` DECIMAL(10,2) DEFAULT NULL COMMENT 'Override global mix cost',
  
  -- Commission Structure (group-specific)
  `use_custom_commission` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Use custom commission rates vs. global',
  
  -- Metadata
  `image_url` VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `featured` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_group_key` (`group_key`),
  KEY `idx_active` (`active`),
  KEY `idx_display_order` (`display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PART 2: Create Product Group Commission Tiers Table
-- ============================================================

CREATE TABLE IF NOT EXISTS `product_group_commissions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `product_group_id` INT(11) NOT NULL,
  `tier_name` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'e.g., 1-5, 6-10, 15+',
  `quantity_min` INT(11) NOT NULL COMMENT 'Minimum quantity for this tier',
  `quantity_max` INT(11) DEFAULT NULL COMMENT 'Maximum quantity (NULL = unlimited)',
  `bulk_discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Discount on wholesale price',
  `commission_percent` DECIMAL(5,2) NOT NULL DEFAULT 20.00 COMMENT 'Commission rate for sales agent',
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_group_tier` (`product_group_id`, `tier_name`),
  KEY `idx_product_group` (`product_group_id`),
  KEY `idx_quantity` (`quantity_min`, `quantity_max`),
  CONSTRAINT `fk_group_commission_group` FOREIGN KEY (`product_group_id`) 
    REFERENCES `product_groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PART 3: Add Product Group Reference to Products Table
-- ============================================================

-- Add product_group_id column to products table
-- Using ALTER TABLE IF NOT EXISTS pattern (MariaDB 10.0.2+)
-- If column already exists, statement will be ignored

-- Add column (will error if exists, but we'll handle that)
ALTER TABLE `products` 
ADD COLUMN IF NOT EXISTS `product_group_id` INT(11) DEFAULT NULL 
COMMENT 'Foreign key to product_groups table' 
AFTER `id`;

-- Add foreign key constraint
-- Drop first if exists to avoid duplicate key errors (errno 121)
SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0;
ALTER TABLE `products` DROP FOREIGN KEY IF EXISTS `fk_product_group`;
ALTER TABLE `products` 
ADD CONSTRAINT `fk_product_group` 
FOREIGN KEY (`product_group_id`) 
REFERENCES `product_groups` (`id`) 
ON DELETE SET NULL 
ON UPDATE CASCADE;
SET SQL_NOTES=@OLD_SQL_NOTES;

-- Add index for performance
-- Note: Only indexing product_group_id, not active
ALTER TABLE `products` 
ADD INDEX IF NOT EXISTS `idx_product_group` (`product_group_id`);

-- ============================================================
-- PART 4: Insert Default Product Group (Products)
-- ============================================================

INSERT INTO `product_groups` 
  (`group_key`, `name`, `description`, `display_order`, `active`, `use_custom_commission`, `featured`) 
VALUES 
  (
    'product-products',
    'Products',
    'Premium catalog-ready attribute Products. Three product specification levels for precise dosing: Blue 10 (20mg/oz), Gold 30 (60mg/oz), and Black 60 (120mg/oz). Perfect for bars, partner lounges, and wellness centers.',
    1,
    1,
    1,
    1
  )
ON DUPLICATE KEY UPDATE 
  name = VALUES(name),
  description = VALUES(description),
  active = VALUES(active),
  updated_at = CURRENT_TIMESTAMP;

-- ============================================================
-- PART 5: Insert Commission Tiers for Products
-- ============================================================

-- Get the product group ID
SET @group_id = (SELECT id FROM product_groups WHERE group_key = 'product-products' LIMIT 1);

-- Insert commission tiers (from your spreadsheet structure)
INSERT INTO `product_group_commissions` 
  (`product_group_id`, `tier_name`, `quantity_min`, `quantity_max`, `bulk_discount_percent`, `commission_percent`) 
VALUES 
  (@group_id, '1-5', 1, 5, 0.00, 20.00),
  (@group_id, '6-10', 6, 10, 8.00, 12.00),
  (@group_id, '15+', 15, NULL, 12.00, 8.00)
ON DUPLICATE KEY UPDATE 
  quantity_min = VALUES(quantity_min),
  quantity_max = VALUES(quantity_max),
  bulk_discount_percent = VALUES(bulk_discount_percent),
  commission_percent = VALUES(commission_percent),
  updated_at = CURRENT_TIMESTAMP;

-- ============================================================
-- PART 6: Wait for Column Addition (placeholder)
-- ============================================================
-- View creation moved to PART 7 (after all schema changes complete)

-- ============================================================
-- PART 7: Enhanced Pricing View with Product Groups  
-- ============================================================
-- Note: View creation deferred to end of migration (after all schema changes)

-- ============================================================
-- PART 8: Update Mass Pricing Procedure to Support Groups
-- ============================================================

DROP PROCEDURE IF EXISTS `update_product_group_pricing`;

DELIMITER //

CREATE PROCEDURE `update_product_group_pricing`(
  IN p_group_id INT,
  IN p_user_id BIGINT(20),
  IN p_reason TEXT
)
BEGIN
  DECLARE v_ingredient_cost DECIMAL(10,4);
  DECLARE v_attribute_percent DECIMAL(5,2);
  DECLARE v_bottle_cost DECIMAL(10,2);
  DECLARE v_label_cost DECIMAL(10,2);
  DECLARE v_labor_cost DECIMAL(10,2);
  DECLARE v_profit_mult DECIMAL(5,2);
  DECLARE v_mix_cost DECIMAL(10,2);
  DECLARE v_affected_rows INT DEFAULT 0;
  
  -- Fetch global pricing settings as defaults
  SELECT setting_value INTO v_ingredient_cost FROM pricing_settings WHERE setting_key = 'ingredient_cost_per_gram';
  SELECT setting_value INTO v_attribute_percent FROM pricing_settings WHERE setting_key = 'attribute_percentage';
  SELECT setting_value INTO v_bottle_cost FROM pricing_settings WHERE setting_key = 'bottle_cost';
  SELECT setting_value INTO v_label_cost FROM pricing_settings WHERE setting_key = 'label_cost';
  SELECT setting_value INTO v_labor_cost FROM pricing_settings WHERE setting_key = 'labor_cost';
  SELECT setting_value INTO v_profit_mult FROM pricing_settings WHERE setting_key = 'profit_multiplier';
  SELECT setting_value INTO v_mix_cost FROM pricing_settings WHERE setting_key = 'mix_cost';
  
  -- Override with group-specific settings if they exist
  SELECT 
    COALESCE(ingredient_cost_per_gram, v_ingredient_cost),
    COALESCE(attribute_percentage, v_attribute_percent),
    COALESCE(bottle_cost, v_bottle_cost),
    COALESCE(label_cost, v_label_cost),
    COALESCE(labor_cost, v_labor_cost),
    COALESCE(profit_multiplier, v_profit_mult),
    COALESCE(mix_cost, v_mix_cost)
  INTO 
    v_ingredient_cost, v_attribute_percent, v_bottle_cost, v_label_cost, v_labor_cost, v_profit_mult, v_mix_cost
  FROM product_groups 
  WHERE id = p_group_id;
  
  -- Update all products in this group
  UPDATE products p
  SET 
    -- Formula from spreadsheet: GRAMS NEEDED = TOTAL attribute / attribute PER GRAM
    -- attribute per gram = attribute_percentage * 10 (62% = 620mg/gram)
    p.material_cost = ROUND(((p.attribute_per_oz * p.size_oz) / (v_attribute_percent * 10)) * v_ingredient_cost, 2),
    p.bottle_cost = v_bottle_cost,
    p.label_cost = v_label_cost,
    p.labor_cost = v_labor_cost,
    p.mix_cost = v_mix_cost,
    -- Formula from spreadsheet: COST = COST attribute + BOTTLE/LABEL + LABOR
    -- Note: Bottle/Label is COMBINED $2.50, mix_cost is NOT added
    p.total_production_cost = ROUND(
      p.material_cost + 2.50 + v_labor_cost, 
    2),
    p.wholesale_price = ROUND(p.total_production_cost * v_profit_mult, 2),
    p.msrp = ROUND(p.total_production_cost * v_profit_mult, 2),
    p.total_mg = FLOOR(p.attribute_per_oz * p.size_oz),
    p.servings_10mg = FLOOR((p.attribute_per_oz * p.size_oz) / 10),
    p.cost_per_10mg_serving = ROUND(p.wholesale_price / FLOOR((p.attribute_per_oz * p.size_oz) / 10), 2),
    p.ml_per_10mg_serving = ROUND((10 / p.attribute_per_oz) * 29.5735, 3),
    p.oz_per_10mg_serving = ROUND(10 / p.attribute_per_oz, 4),
    p.drinks_per_oz = ROUND(p.attribute_per_oz / 10, 2),
    p.profit_margin_percent = ROUND(((p.wholesale_price - p.total_production_cost) / p.total_production_cost) * 100, 2),
    p.last_price_update = NOW(),
    p.updated_at = NOW()
  WHERE p.product_group_id = p_group_id
    AND p.active = 1 
    AND p.attribute_per_oz IS NOT NULL 
    AND p.size_oz IS NOT NULL
    AND p.attribute_per_oz > 0
    AND p.size_oz > 0;
  
  SET v_affected_rows = ROW_COUNT();
  
  -- Update bulk pricing using group commission structure
  UPDATE bulk_pricing bp
  JOIN products p ON p.sku = bp.sku
  JOIN product_group_commissions pgc ON pgc.product_group_id = p.product_group_id
  SET 
    bp.unit_price = ROUND(p.wholesale_price * (1 - pgc.bulk_discount_percent / 100), 2),
    bp.discount_percent = pgc.bulk_discount_percent,
    bp.commission_percent = pgc.commission_percent,
    bp.updated_at = NOW()
  WHERE p.product_group_id = p_group_id
    AND p.active = 1 
    AND bp.active = 1
    AND bp.quantity_min >= pgc.quantity_min
    AND (pgc.quantity_max IS NULL OR bp.quantity_min <= pgc.quantity_max);
  
  -- Log the update
  INSERT INTO price_change_log (change_type, changed_by_user_id, reason, old_value, new_value)
  VALUES ('mass_update', p_user_id, CONCAT('Product group update: ', COALESCE(p_reason, 'No reason provided')), p_group_id, v_affected_rows);
  
  SELECT v_affected_rows AS products_updated, NOW() AS update_timestamp;
END //

DELIMITER ;

-- ============================================================
-- PART 9: Helper Functions
-- ============================================================

-- Function to get commission rate for a product at a given quantity
DROP FUNCTION IF EXISTS `get_product_commission_rate`;

DELIMITER //

CREATE FUNCTION `get_product_commission_rate`(
  p_product_id BIGINT(20),
  p_quantity INT
)
RETURNS DECIMAL(5,2)
READS SQL DATA
DETERMINISTIC
BEGIN
  DECLARE v_commission DECIMAL(5,2);
  DECLARE v_group_id INT;
  
  -- Get product's group
  SELECT product_group_id INTO v_group_id FROM products WHERE id = p_product_id;
  
  -- Get commission rate based on quantity
  SELECT commission_percent INTO v_commission
  FROM product_group_commissions
  WHERE product_group_id = v_group_id
    AND active = 1
    AND p_quantity >= quantity_min
    AND (quantity_max IS NULL OR p_quantity <= quantity_max)
  ORDER BY quantity_min DESC
  LIMIT 1;
  
  RETURN COALESCE(v_commission, 20.00);
END //

DELIMITER ;

-- Function to get bulk discount for a product at a given quantity
DROP FUNCTION IF EXISTS `get_product_bulk_discount`;

DELIMITER //

CREATE FUNCTION `get_product_bulk_discount`(
  p_product_id BIGINT(20),
  p_quantity INT
)
RETURNS DECIMAL(5,2)
READS SQL DATA
DETERMINISTIC
BEGIN
  DECLARE v_discount DECIMAL(5,2);
  DECLARE v_group_id INT;
  
  -- Get product's group
  SELECT product_group_id INTO v_group_id FROM products WHERE id = p_product_id;
  
  -- Get discount based on quantity
  SELECT bulk_discount_percent INTO v_discount
  FROM product_group_commissions
  WHERE product_group_id = v_group_id
    AND active = 1
    AND p_quantity >= quantity_min
    AND (quantity_max IS NULL OR p_quantity <= quantity_max)
  ORDER BY quantity_min DESC
  LIMIT 1;
  
  RETURN COALESCE(v_discount, 0.00);
END //

DELIMITER ;

-- ============================================================
-- PART 10: Assign Existing Products to Products Group
-- ============================================================

-- Update BLUE, GOLD, BLACK products to belong to Products group
-- Simple approach: Just do the update (column was added in PART 3)
-- If updated_at doesn't exist, the error will be non-critical

UPDATE products 
SET product_group_id = @group_id
WHERE sku IN ('BLUE-10-32', 'GOLD-30-32', 'BLACK-60-32')
  AND (product_group_id IS NULL OR product_group_id != @group_id);

-- ============================================================
-- PART 11: Create Pricing View (After All Schema Changes)
-- ============================================================
-- Note: View creation is commented out since it requires the products table
-- to have specific columns that may not exist yet.
-- You can manually create this view after running the pricing infrastructure migration.

-- DROP VIEW IF EXISTS `v_product_pricing_breakdown`;
-- 
-- CREATE VIEW `v_product_pricing_breakdown` AS
-- SELECT 
--   p.id,
--   p.sku,
--   p.name,
--   p.product_group_id,
--   pg.group_key,
--   pg.name AS group_name,
--   p.wholesale_price,
--   p.msrp,
--   p.active,
--   pg.ingredient_cost_per_gram,
--   pg.attribute_percentage,
--   pg.bottle_cost AS group_bottle_cost,
--   pg.label_cost AS group_label_cost,
--   pg.labor_cost AS group_labor_cost,
--   pg.profit_multiplier AS group_profit_multiplier
-- FROM products p
-- LEFT JOIN product_groups pg ON pg.id = p.product_group_id
-- WHERE p.active = 1
-- ORDER BY pg.display_order ASC, p.sku ASC;

SELECT 'View creation skipped - create manually after pricing migration' AS note;

-- ============================================================
-- PART 12: Summary and Verification
-- ============================================================

-- Show product groups
SELECT 'Product Groups Created:' AS status;
SELECT * FROM product_groups ORDER BY display_order;

-- Show commission tiers
SELECT 'Commission Tiers:' AS status;
SELECT pg.name AS group_name, pgc.* 
FROM product_group_commissions pgc
JOIN product_groups pg ON pg.id = pgc.product_group_id
ORDER BY pg.display_order, pgc.quantity_min;

-- Show assigned products
SELECT 'Products Assigned to Groups:' AS status;
SELECT p.sku, p.name, pg.name AS group_name, p.product_group_id
FROM products p
LEFT JOIN product_groups pg ON pg.id = p.product_group_id
WHERE p.sku IN ('BLUE-10-32', 'GOLD-30-32', 'BLACK-60-32');

-- END OF MIGRATION
SELECT CONCAT('✓ Product Grouping System Migration Complete - ', NOW()) AS status;

