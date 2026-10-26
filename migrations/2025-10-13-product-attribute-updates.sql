-- Migration: 2025-10-13 Product specification Updates
-- Updates GOLD-30 to GOLD-40 and BLACK-60 to BLACK-80
-- Recalculates all dependent fields and bulk pricing

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS=0;

-- ============================================================================
-- 1. UPDATE GOLD-30-32 TO GOLD-40-32
-- ============================================================================
-- Product B = 40mg attribute per 0.5oz serving = 80mg per oz
UPDATE `products` SET
  `name` = REPLACE(`name`, 'Gold 30', 'Product B'),
  `attribute_per_oz` = 80.00,
  `total_mg` = 80.00 * `size_oz`,
  `servings_10mg` = FLOOR((80.00 * `size_oz`) / 10),
  `ml_per_10mg_serving` = (10.0 / 80.00) * 29.5735,
  `oz_per_10mg_serving` = 10.0 / 80.00,
  `drinks_per_oz` = 80.00 / 10.0,
  `cost_per_10mg_serving` = `wholesale_price` / FLOOR((80.00 * `size_oz`) / 10),
  `attribute_content` = '40mg attribute per 0.5oz serving (80mg per 1oz)',
  `description` = REPLACE(`description`, '30mg attribute', '40mg attribute'),
  `description` = REPLACE(`description`, '60mg per 1oz', '80mg per 1oz'),
  `description` = REPLACE(`description`, 'Gold 30', 'Product B'),
  `updated_at` = NOW()
WHERE `sku` = 'GOLD-30-32';

-- ============================================================================
-- 2. UPDATE BLACK-60-32 TO BLACK-80-32
-- ============================================================================
-- Black 80 = 80mg attribute per 0.5oz serving = 160mg per oz
UPDATE `products` SET
  `name` = REPLACE(`name`, 'Black 60', 'Black 80'),
  `attribute_per_oz` = 160.00,
  `total_mg` = 160.00 * `size_oz`,
  `servings_10mg` = FLOOR((160.00 * `size_oz`) / 10),
  `ml_per_10mg_serving` = (10.0 / 160.00) * 29.5735,
  `oz_per_10mg_serving` = 10.0 / 160.00,
  `drinks_per_oz` = 160.00 / 10.0,
  `cost_per_10mg_serving` = `wholesale_price` / FLOOR((160.00 * `size_oz`) / 10),
  `attribute_content` = '80mg attribute per 0.5oz serving (160mg per 1oz)',
  `description` = REPLACE(`description`, '60mg attribute', '80mg attribute'),
  `description` = REPLACE(`description`, '120mg per 1oz', '160mg per 1oz'),
  `description` = REPLACE(`description`, 'Black 60', 'Black 80'),
  `updated_at` = NOW()
WHERE `sku` = 'BLACK-60-32';

-- ============================================================================
-- 3. UPDATE SAMPLE PRODUCTS
-- ============================================================================
UPDATE `products` SET
  `name` = REPLACE(`name`, 'Gold-30', 'Gold-40'),
  `description` = REPLACE(`description`, 'Gold-30', 'Gold-40'),
  `updated_at` = NOW()
WHERE `sku` LIKE 'SAMPLE-GOLD-30%';

-- ============================================================================
-- 4. UPDATE BULK PRICING FOR GOLD-40-32
-- ============================================================================
-- Keep existing pricing structure but update SKU references and recalculate if needed
UPDATE `bulk_pricing` SET
  `sku` = 'GOLD-40-32',
  `updated_at` = NOW()
WHERE `sku` = 'GOLD-30-32';

-- ============================================================================
-- 5. UPDATE BULK PRICING FOR BLACK-80-32
-- ============================================================================
UPDATE `bulk_pricing` SET
  `sku` = 'BLACK-80-32',
  `updated_at` = NOW()
WHERE `sku` = 'BLACK-60-32';

-- ============================================================================
-- 6. UPDATE TRAINING MODULE EXAMPLES
-- ============================================================================
UPDATE `training_module_examples` SET
  `product_sku` = 'GOLD-40-32'
WHERE `product_sku` = 'GOLD-30-32';

UPDATE `training_module_examples` SET
  `product_sku` = 'BLACK-80-32'
WHERE `product_sku` = 'BLACK-60-32';

-- ============================================================================
-- 7. ARCHIVE OLD BLEND-* PRODUCTS
-- ============================================================================
UPDATE `products` SET
  `active` = 0,
  `use_in_training` = 0,
  `updated_at` = NOW()
WHERE `sku` IN ('BLEND-32-20', 'BLEND-128-20', 'BLEND-32-75', 'BLEND-128-75')
  AND `active` = 1;

-- ============================================================================
-- 8. UPDATE SKU REFERENCES IN STORED DATA
-- ============================================================================
-- Update any JSON fields that might reference old SKUs
UPDATE `training_modules` SET
  `content` = REPLACE(`content`, 'GOLD-30-32', 'GOLD-40-32')
WHERE `content` LIKE '%GOLD-30-32%';

UPDATE `training_modules` SET
  `content` = REPLACE(`content`, 'BLACK-60-32', 'BLACK-80-32')
WHERE `content` LIKE '%BLACK-60-32%';

UPDATE `training_modules` SET
  `content` = REPLACE(`content`, 'Gold 30', 'Product B')
WHERE `content` LIKE '%Gold 30%';

UPDATE `training_modules` SET
  `content` = REPLACE(`content`, 'Black 60', 'Black 80')
WHERE `content` LIKE '%Black 60%';

SET FOREIGN_KEY_CHECKS=1;

-- Migration complete: Product potencies updated to Product B and Black 80

