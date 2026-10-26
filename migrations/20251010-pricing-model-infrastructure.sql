-- Migration: Pricing Model Infrastructure
-- Date: 2025-10-10
-- Purpose: Add pricing model fields, global settings table, and mass update procedures

USE foggksfx_product;

-- ============================================================
-- PART 1: Create Global Pricing Settings Table
-- ============================================================

CREATE TABLE IF NOT EXISTS `pricing_settings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `setting_value` DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
  `setting_unit` VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default pricing settings from spreadsheet (binary safe, duplicate safe)
INSERT INTO `pricing_settings` (`setting_key`, `setting_value`, `setting_unit`, `description`) VALUES
('ingredient_cost_per_gram', 3.4000, 'USD', 'Cost per gram of raw product ingredient'),
('attribute_percentage', 62.0000, 'percent', 'attribute content percentage in raw product (62% = 62mg attribute per 100mg product)'),
('bottle_cost', 2.5000, 'USD', 'Cost per bottle container'),
('label_cost', 2.5000, 'USD', 'Cost per product label'),
('labor_cost', 5.0000, 'USD', 'Labor cost per bottle produced'),
('profit_multiplier', 5.0000, 'multiplier', 'Wholesale price multiplier (wholesale = cost × multiplier)'),
('mix_cost', 1.5000, 'USD', 'Base formula mix cost per bottle')
ON DUPLICATE KEY UPDATE 
  setting_value = VALUES(setting_value),
  description = VALUES(description),
  updated_at = CURRENT_TIMESTAMP;

-- ============================================================
-- PART 2: Add Missing Calculated Fields to Products Table
-- ============================================================

-- Add material and production cost tracking fields (binary safe, no duplicates)
SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'foggksfx_product' AND TABLE_NAME = 'products' AND COLUMN_NAME = 'material_cost') = 0,
  'ALTER TABLE `products` ADD COLUMN `material_cost` DECIMAL(10,2) DEFAULT NULL COMMENT ''Raw product material cost per bottle'' AFTER `cost_per_10mg_serving`',
  'SELECT "Column material_cost already exists" AS message'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'foggksfx_product' AND TABLE_NAME = 'products' AND COLUMN_NAME = 'total_production_cost') = 0,
  'ALTER TABLE `products` ADD COLUMN `total_production_cost` DECIMAL(10,2) DEFAULT NULL COMMENT ''Total cost: material + bottle + label + labor + mix'' AFTER `material_cost`',
  'SELECT "Column total_production_cost already exists" AS message'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'foggksfx_product' AND TABLE_NAME = 'products' AND COLUMN_NAME = 'bottle_cost') = 0,
  'ALTER TABLE `products` ADD COLUMN `bottle_cost` DECIMAL(10,2) DEFAULT 2.50 COMMENT ''Cost per bottle (defaults to global setting)'' AFTER `total_production_cost`',
  'SELECT "Column bottle_cost already exists" AS message'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'foggksfx_product' AND TABLE_NAME = 'products' AND COLUMN_NAME = 'label_cost') = 0,
  'ALTER TABLE `products` ADD COLUMN `label_cost` DECIMAL(10,2) DEFAULT 2.50 COMMENT ''Cost per label (defaults to global setting)'' AFTER `bottle_cost`',
  'SELECT "Column label_cost already exists" AS message'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'foggksfx_product' AND TABLE_NAME = 'products' AND COLUMN_NAME = 'labor_cost') = 0,
  'ALTER TABLE `products` ADD COLUMN `labor_cost` DECIMAL(10,2) DEFAULT 5.00 COMMENT ''Labor cost per bottle (defaults to global setting)'' AFTER `label_cost`',
  'SELECT "Column labor_cost already exists" AS message'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'foggksfx_product' AND TABLE_NAME = 'products' AND COLUMN_NAME = 'mix_cost') = 0,
  'ALTER TABLE `products` ADD COLUMN `mix_cost` DECIMAL(10,2) DEFAULT 1.50 COMMENT ''Mix preparation cost per bottle'' AFTER `labor_cost`',
  'SELECT "Column mix_cost already exists" AS message'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'foggksfx_product' AND TABLE_NAME = 'products' AND COLUMN_NAME = 'profit_margin_percent') = 0,
  'ALTER TABLE `products` ADD COLUMN `profit_margin_percent` DECIMAL(5,2) DEFAULT NULL COMMENT ''Calculated profit margin percentage'' AFTER `mix_cost`',
  'SELECT "Column profit_margin_percent already exists" AS message'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'foggksfx_product' AND TABLE_NAME = 'products' AND COLUMN_NAME = 'last_price_update') = 0,
  'ALTER TABLE `products` ADD COLUMN `last_price_update` TIMESTAMP NULL DEFAULT NULL COMMENT ''Last time pricing was recalculated'' AFTER `profit_margin_percent`',
  'SELECT "Column last_price_update already exists" AS message'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add index for performance (binary safe, no duplicate key errors)
SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = 'foggksfx_product' AND TABLE_NAME = 'products' AND INDEX_NAME = 'idx_pricing_calc') = 0,
  'ALTER TABLE `products` ADD INDEX `idx_pricing_calc` (`attribute_per_oz`, `size_oz`, `active`)',
  'SELECT "Index idx_pricing_calc already exists" AS message'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- PART 3: Add Commission Tracking to Bulk Pricing
-- ============================================================

-- Add commission_percent column (binary safe, no duplicates)
SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'foggksfx_product' AND TABLE_NAME = 'bulk_pricing' AND COLUMN_NAME = 'commission_percent') = 0,
  'ALTER TABLE `bulk_pricing` ADD COLUMN `commission_percent` DECIMAL(5,2) DEFAULT NULL COMMENT ''Commission rate for this tier'' AFTER `discount_percent`',
  'SELECT "Column commission_percent already exists" AS message'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Update commission rates for existing tiers (safe for multiple runs)
UPDATE `bulk_pricing` 
SET `commission_percent` = CASE
  WHEN `quantity_tier_name` = 'X1' OR `quantity_min` BETWEEN 1 AND 5 THEN 20.00
  WHEN `quantity_tier_name` = 'X5' OR `quantity_min` BETWEEN 5 AND 9 THEN 20.00
  WHEN `quantity_tier_name` = 'X10' OR `quantity_min` BETWEEN 10 AND 14 THEN 12.00
  WHEN `quantity_tier_name` = 'X15' OR `quantity_min` BETWEEN 15 AND 19 THEN 8.00
  WHEN `quantity_tier_name` = 'X20' OR `quantity_min` >= 20 THEN 8.00
  ELSE 20.00
END
WHERE `commission_percent` IS NULL OR `commission_percent` = 0;

-- ============================================================
-- PART 4: Create Price Audit Log Table
-- ============================================================

CREATE TABLE IF NOT EXISTS `price_change_log` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `change_type` ENUM('global_settings', 'individual_product', 'mass_update', 'bulk_pricing') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_sku` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'NULL for global changes',
  `setting_key` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'For settings changes',
  `old_value` DECIMAL(10,4) DEFAULT NULL,
  `new_value` DECIMAL(10,4) DEFAULT NULL,
  `changed_by_user_id` BIGINT(20) UNSIGNED DEFAULT NULL,
  `reason` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product_sku` (`product_sku`),
  KEY `idx_change_type` (`change_type`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PART 5: Create Mass Price Update Stored Procedure
-- ============================================================

DROP PROCEDURE IF EXISTS `update_all_product_pricing`;

DELIMITER //

CREATE PROCEDURE `update_all_product_pricing`(
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
  
  -- Fetch current global pricing settings
  SELECT setting_value INTO v_ingredient_cost FROM pricing_settings WHERE setting_key = 'ingredient_cost_per_gram';
  SELECT setting_value INTO v_attribute_percent FROM pricing_settings WHERE setting_key = 'attribute_percentage';
  SELECT setting_value INTO v_bottle_cost FROM pricing_settings WHERE setting_key = 'bottle_cost';
  SELECT setting_value INTO v_label_cost FROM pricing_settings WHERE setting_key = 'label_cost';
  SELECT setting_value INTO v_labor_cost FROM pricing_settings WHERE setting_key = 'labor_cost';
  SELECT setting_value INTO v_profit_mult FROM pricing_settings WHERE setting_key = 'profit_multiplier';
  SELECT setting_value INTO v_mix_cost FROM pricing_settings WHERE setting_key = 'mix_cost';
  
  -- Update all active products with calculated pricing
  UPDATE products
  SET 
    -- Step 1: Calculate material cost based on attribute needed
    -- Formula from spreadsheet: GRAMS NEEDED = TOTAL attribute / attribute PER GRAM
    -- attribute per gram = attribute_percentage * 10 (62% = 620mg/gram)
    -- COST attribute = GRAMS NEEDED × COST PER GRAM
    material_cost = ROUND(((attribute_per_oz * size_oz) / (v_attribute_percent * 10)) * v_ingredient_cost, 2),
    
    -- Use product-specific costs if set, otherwise use global defaults
    bottle_cost = COALESCE(bottle_cost, v_bottle_cost),
    label_cost = COALESCE(label_cost, v_label_cost),
    labor_cost = COALESCE(labor_cost, v_labor_cost),
    mix_cost = COALESCE(mix_cost, v_mix_cost),
    
    -- Step 2: Calculate total production cost
    -- Formula from spreadsheet: COST = COST attribute + BOTTLE/LABEL + LABOR
    -- Note: Bottle/Label is COMBINED $2.50, and mix_cost is NOT added
    total_production_cost = ROUND(
      material_cost + 
      2.50 + -- Combined bottle/label cost from spreadsheet
      COALESCE(labor_cost, v_labor_cost), 
    2),
    
    -- Step 3: Calculate wholesale price (cost × multiplier)
    wholesale_price = ROUND(total_production_cost * v_profit_mult, 2),
    msrp = ROUND(total_production_cost * v_profit_mult, 2),
    
    -- Step 4: Calculate all serving metrics
    total_mg = FLOOR(attribute_per_oz * size_oz),
    servings_10mg = FLOOR((attribute_per_oz * size_oz) / 10),
    cost_per_10mg_serving = ROUND(wholesale_price / FLOOR((attribute_per_oz * size_oz) / 10), 2),
    ml_per_10mg_serving = ROUND((10 / attribute_per_oz) * 29.5735, 3),
    oz_per_10mg_serving = ROUND(10 / attribute_per_oz, 4),
    drinks_per_oz = ROUND(attribute_per_oz / 10, 2),
    
    -- Step 5: Calculate profit margin (NET PROFIT / MSRP after 20% commission)
    -- Formula: % PROFIT = (MSRP - COST - 20% COMMISSION) / MSRP
    -- Which is: (MSRP - COST - MSRP*0.20) / MSRP
    profit_margin_percent = ROUND(((wholesale_price - total_production_cost - (wholesale_price * 0.20)) / wholesale_price) * 100, 2),
    
    -- Update timestamps
    last_price_update = NOW(),
    updated_at = NOW()
    
  WHERE active = 1 
    AND attribute_per_oz IS NOT NULL 
    AND size_oz IS NOT NULL
    AND attribute_per_oz > 0
    AND size_oz > 0;
  
  SET v_affected_rows = ROW_COUNT();
  
  -- Update bulk pricing tiers based on new wholesale prices
  UPDATE bulk_pricing bp
  JOIN products p ON p.sku = bp.sku
  SET 
    bp.unit_price = ROUND(p.wholesale_price * (1 - bp.discount_percent / 100), 2),
    bp.updated_at = NOW()
  WHERE p.active = 1 AND bp.active = 1;
  
  -- Log the mass update
  INSERT INTO price_change_log (change_type, changed_by_user_id, reason, old_value, new_value)
  VALUES ('mass_update', p_user_id, CONCAT('Mass price update: ', COALESCE(p_reason, 'No reason provided')), 0, v_affected_rows);
  
  -- Return summary
  SELECT v_affected_rows AS products_updated, NOW() AS update_timestamp;
END //

DELIMITER ;

-- ============================================================
-- PART 6: Create Individual Product Price Recalculation
-- ============================================================

DROP PROCEDURE IF EXISTS `recalculate_product_price`;

DELIMITER //

CREATE PROCEDURE `recalculate_product_price`(
  IN p_product_id BIGINT(20),
  IN p_user_id BIGINT(20),
  IN p_reason TEXT
)
BEGIN
  DECLARE v_ingredient_cost DECIMAL(10,4);
  DECLARE v_attribute_percent DECIMAL(5,2);
  DECLARE v_profit_mult DECIMAL(5,2);
  DECLARE v_old_price DECIMAL(10,2);
  DECLARE v_new_price DECIMAL(10,2);
  DECLARE v_sku VARCHAR(100);
  
  -- Fetch global settings
  SELECT setting_value INTO v_ingredient_cost FROM pricing_settings WHERE setting_key = 'ingredient_cost_per_gram';
  SELECT setting_value INTO v_attribute_percent FROM pricing_settings WHERE setting_key = 'attribute_percentage';
  SELECT setting_value INTO v_profit_mult FROM pricing_settings WHERE setting_key = 'profit_multiplier';
  
  -- Get current price and SKU
  SELECT wholesale_price, sku INTO v_old_price, v_sku FROM products WHERE id = p_product_id;
  
  -- Update single product (same logic as mass update)
  UPDATE products
  SET 
    material_cost = ROUND(((attribute_per_oz * size_oz) / (v_attribute_percent * 10)) * v_ingredient_cost, 2),
    total_production_cost = ROUND(material_cost + 2.50 + COALESCE(labor_cost, 5.00), 2),
    wholesale_price = ROUND(total_production_cost * v_profit_mult, 2),
    msrp = ROUND(total_production_cost * v_profit_mult, 2),
    total_mg = FLOOR(attribute_per_oz * size_oz),
    servings_10mg = FLOOR((attribute_per_oz * size_oz) / 10),
    cost_per_10mg_serving = ROUND(wholesale_price / servings_10mg, 2),
    ml_per_10mg_serving = ROUND((10 / attribute_per_oz) * 29.5735, 3),
    oz_per_10mg_serving = ROUND(10 / attribute_per_oz, 4),
    drinks_per_oz = ROUND(attribute_per_oz / 10, 2),
    -- Formula: % PROFIT = (MSRP - COST - 20% COMMISSION) / MSRP
    profit_margin_percent = ROUND(((wholesale_price - total_production_cost - (wholesale_price * 0.20)) / wholesale_price) * 100, 2),
    last_price_update = NOW(),
    updated_at = NOW()
  WHERE id = p_product_id 
    AND attribute_per_oz IS NOT NULL 
    AND size_oz IS NOT NULL;
  
  -- Get new price
  SELECT wholesale_price INTO v_new_price FROM products WHERE id = p_product_id;
  
  -- Update bulk pricing for this product
  UPDATE bulk_pricing bp
  JOIN products p ON p.sku = bp.sku
  SET 
    bp.unit_price = ROUND(p.wholesale_price * (1 - bp.discount_percent / 100), 2),
    bp.updated_at = NOW()
  WHERE p.id = p_product_id AND bp.active = 1;
  
  -- Log the change
  INSERT INTO price_change_log (change_type, product_sku, changed_by_user_id, reason, old_value, new_value)
  VALUES ('individual_product', v_sku, p_user_id, COALESCE(p_reason, 'Individual price recalculation'), v_old_price, v_new_price);
  
  SELECT v_old_price AS old_wholesale_price, v_new_price AS new_wholesale_price, v_sku AS product_sku;
END //

DELIMITER ;

-- ============================================================
-- PART 7: Create Helper Function to Get Current Setting
-- ============================================================

DROP FUNCTION IF EXISTS `get_pricing_setting`;

DELIMITER //

CREATE FUNCTION `get_pricing_setting`(p_setting_key VARCHAR(50))
RETURNS DECIMAL(10,4)
DETERMINISTIC
READS SQL DATA
BEGIN
  DECLARE v_value DECIMAL(10,4);
  SELECT setting_value INTO v_value FROM pricing_settings WHERE setting_key = p_setting_key LIMIT 1;
  RETURN COALESCE(v_value, 0);
END //

DELIMITER ;

-- ============================================================
-- PART 8: Initial Price Calculation for Existing Products
-- ============================================================

-- Calculate current products based on existing data
-- This populates the new calculated fields with current values

UPDATE products p
SET 
  -- Calculate material cost for existing products
  material_cost = CASE
    WHEN sku = 'BLUE-10-32' THEN 3.51
    WHEN sku = 'GOLD-30-32' THEN 10.53
    WHEN sku = 'BLACK-60-32' THEN 21.06
    ELSE ROUND(((attribute_per_oz * size_oz / 1000) / 0.62) * 3.40, 2)
  END,
  
  -- Set default component costs
  bottle_cost = 2.50,
  label_cost = 2.50,
  labor_cost = 5.00,
  mix_cost = 1.50,
  
  -- Calculate total production cost (spreadsheet values)
  total_production_cost = CASE
    WHEN sku = 'BLUE-10-32' THEN 11.01
    WHEN sku = 'GOLD-30-32' THEN 18.03
    WHEN sku = 'BLACK-60-32' THEN 28.56
    ELSE material_cost + 2.50 + 2.50 + 5.00 + 1.50
  END,
  
  -- Calculate profit margin (NET PROFIT / MSRP after 20% commission)
  -- Formula: % PROFIT = (MSRP - COST - 20% COMMISSION) / MSRP
  profit_margin_percent = ROUND(((wholesale_price - total_production_cost - (wholesale_price * 0.20)) / wholesale_price) * 100, 2),
  
  last_price_update = NOW()
  
WHERE attribute_per_oz IS NOT NULL AND size_oz IS NOT NULL AND active = 1;

-- ============================================================
-- PART 9: Create View for Easy Price Analysis
-- ============================================================

CREATE OR REPLACE VIEW `v_product_pricing_breakdown` AS
SELECT 
  p.id,
  p.sku,
  p.name,
  p.attribute_per_oz,
  p.size_oz,
  p.total_mg,
  p.servings_10mg,
  
  -- Cost breakdown
  p.material_cost,
  p.bottle_cost,
  p.label_cost,
  p.labor_cost,
  p.mix_cost,
  p.total_production_cost,
  
  -- Pricing
  p.wholesale_price,
  p.msrp,
  p.profit_margin_percent,
  
  -- Per-serving economics
  p.cost_per_10mg_serving,
  p.ml_per_10mg_serving,
  p.oz_per_10mg_serving,
  p.drinks_per_oz,
  
  -- Bulk pricing tiers
  (SELECT unit_price FROM bulk_pricing WHERE sku = p.sku AND quantity_tier_name = 'X1' AND active = 1 LIMIT 1) AS price_tier_1_5,
  (SELECT unit_price FROM bulk_pricing WHERE sku = p.sku AND quantity_tier_name = 'X10' AND active = 1 LIMIT 1) AS price_tier_6_10,
  (SELECT unit_price FROM bulk_pricing WHERE sku = p.sku AND quantity_tier_name = 'X20' AND active = 1 LIMIT 1) AS price_tier_15_plus,
  
  -- Metadata
  p.active,
  p.last_price_update,
  p.updated_at
  
FROM products p
WHERE p.attribute_per_oz IS NOT NULL AND p.size_oz IS NOT NULL
ORDER BY p.attribute_per_oz ASC, p.size_oz ASC;

-- ============================================================
-- PART 10: Grant Permissions (if needed)
-- ============================================================

-- GRANT EXECUTE ON PROCEDURE update_all_product_pricing TO 'your_app_user'@'localhost';
-- GRANT EXECUTE ON PROCEDURE recalculate_product_price TO 'your_app_user'@'localhost';
-- GRANT EXECUTE ON FUNCTION get_pricing_setting TO 'your_app_user'@'localhost';

-- ============================================================
-- Migration Complete
-- ============================================================

SELECT 'Pricing model infrastructure migration completed successfully!' AS status;
SELECT COUNT(*) AS products_with_pricing FROM products WHERE material_cost IS NOT NULL;
SELECT * FROM pricing_settings ORDER BY setting_key;

