-- Migration: Fix New Product Calculated Fields
-- Date: 2025-01-09
-- Description: Update calculated fields for new product line (BLUE-10-32, GOLD-30-32, BLACK-60-32)
-- Based on serving size: 0.5oz per serving, 32oz bottles = 64 servings per bottle

-- Update BLUE-10-32 (10mg attribute per 0.5oz serving = 20mg per 1oz)
UPDATE `products` SET
  `attribute_per_oz` = 20.00,
  `size_oz` = 32.00,
  `total_mg` = 640,
  `servings_10mg` = 64,
  `ml_per_10mg_serving` = 14.787,
  `oz_per_10mg_serving` = 0.5000,
  `drinks_per_oz` = 2.00,
  `cost_per_10mg_serving` = 0.86,
  `updated_at` = NOW()
WHERE `sku` = 'BLUE-10-32';

-- Update GOLD-30-32 (30mg attribute per 0.5oz serving = 60mg per 1oz)
UPDATE `products` SET
  `attribute_per_oz` = 60.00,
  `size_oz` = 32.00,
  `total_mg` = 1920,
  `servings_10mg` = 192,
  `ml_per_10mg_serving` = 4.929,
  `oz_per_10mg_serving` = 0.1667,
  `drinks_per_oz` = 6.00,
  `cost_per_10mg_serving` = 0.47,
  `updated_at` = NOW()
WHERE `sku` = 'GOLD-30-32';

-- Update BLACK-60-32 (60mg attribute per 0.5oz serving = 120mg per 1oz)
UPDATE `products` SET
  `attribute_per_oz` = 120.00,
  `size_oz` = 32.00,
  `total_mg` = 3840,
  `servings_10mg` = 384,
  `ml_per_10mg_serving` = 2.464,
  `oz_per_10mg_serving` = 0.0833,
  `drinks_per_oz` = 12.00,
  `cost_per_10mg_serving` = 0.37,
  `updated_at` = NOW()
WHERE `sku` = 'BLACK-60-32';

-- Enable training for all new products
UPDATE `products` SET
  `use_in_training` = 1,
  `training_context` = 'all',
  `training_features` = JSON_OBJECT(
    'consistency_advantage', true,
    'lab_tested', true,
    'tasteless', true,
    'standardization', true,
    'product_grade', true,
    'beginner_friendly', true,
    'high_attribute', false,
    'bulk_value', true
  ),
  `updated_at` = NOW()
WHERE `sku` IN ('BLUE-10-32', 'GOLD-30-32', 'BLACK-60-32');

-- Update training features for GOLD-30-32 (intermediate level)
UPDATE `products` SET
  `training_context` = 'intermediate',
  `training_features` = JSON_OBJECT(
    'consistency_advantage', true,
    'lab_tested', true,
    'tasteless', true,
    'standardization', true,
    'product_grade', true,
    'beginner_friendly', false,
    'high_attribute', true,
    'bulk_value', true
  ),
  `updated_at` = NOW()
WHERE `sku` = 'GOLD-30-32';

-- Update training features for BLACK-60-32 (advanced level)
UPDATE `products` SET
  `training_context` = 'advanced',
  `training_features` = JSON_OBJECT(
    'consistency_advantage', true,
    'lab_tested', true,
    'tasteless', true,
    'standardization', true,
    'product_grade', true,
    'beginner_friendly', false,
    'high_attribute', true,
    'bulk_value', true
  ),
  `updated_at` = NOW()
WHERE `sku` = 'BLACK-60-32';

-- Ensure bulk pricing tables have the correct data
INSERT IGNORE INTO `bulk_pricing` (`sku`, `min_quantity`, `max_quantity`, `discount_percent`, `active`, `created_at`) VALUES
-- Blue 10 pricing tiers
('BLUE-10-32', 1, 5, 0.00, 1, NOW()),
('BLUE-10-32', 6, 10, 8.00, 1, NOW()),
('BLUE-10-32', 15, NULL, 12.00, 1, NOW()),
-- Gold 30 pricing tiers
('GOLD-30-32', 1, 5, 0.00, 1, NOW()),
('GOLD-30-32', 6, 10, 8.00, 1, NOW()),
('GOLD-30-32', 15, NULL, 12.00, 1, NOW()),
-- Black 60 pricing tiers
('BLACK-60-32', 1, 5, 0.00, 1, NOW()),
('BLACK-60-32', 6, 10, 8.00, 1, NOW()),
('BLACK-60-32', 15, NULL, 12.00, 1, NOW());

-- Update bulk pricing if it exists
UPDATE `bulk_pricing` SET
  `discount_percent` = CASE 
    WHEN `min_quantity` = 1 AND `max_quantity` = 5 THEN 0.00
    WHEN `min_quantity` = 6 AND `max_quantity` = 10 THEN 8.00
    WHEN `min_quantity` = 15 AND `max_quantity` IS NULL THEN 12.00
    ELSE `discount_percent`
  END,
  `active` = 1,
  `updated_at` = NOW()
WHERE `sku` IN ('BLUE-10-32', 'GOLD-30-32', 'BLACK-60-32');

-- Log the migration completion
INSERT INTO `migration_log` (`migration_name`, `execution_date`, `status`, `notes`) VALUES
('20250109-fix-new-product-calculated-fields', NOW(), 'completed', 'Successfully updated calculated fields for new product line. Fixed attribute_per_oz, size_oz, total_mg, servings_10mg, ml_per_10mg_serving, oz_per_10mg_serving, drinks_per_oz, and cost_per_10mg_serving for BLUE-10-32, GOLD-30-32, and BLACK-60-32. Enabled training integration with appropriate context levels.');


