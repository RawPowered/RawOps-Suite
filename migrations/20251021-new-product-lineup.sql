-- =====================================================================
-- New Product Lineup Migration
-- Date: 2025-10-21
-- Description: Add product image support and create 8 new Products
--              (Product B and Product A series in 2oz, 8oz, 16oz, 32oz sizes)
-- =====================================================================

-- Add image_url column to products table
ALTER TABLE `products` 
ADD COLUMN IF NOT EXISTS `image_url` VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL 
COMMENT 'Product image URL/path for display in shop' 
AFTER `description`;

-- =====================================================================
-- Insert New Products with Auto-Calculated Pricing
-- =====================================================================
-- Using product_group product-products settings:
--   ingredient_cost_per_gram: $0.36
--   attribute_percentage: 62% (620mg attribute per gram)
--   bottle_cost: $2.50, label_cost: $5.50, labor_cost: $5.00
--   profit_multiplier: 5.00
--
-- Formula:
--   material_cost = ((attribute_per_oz × size_oz) / 620) × 0.36
--   total_production_cost = material_cost + 2.50 + 5.00
--   wholesale_price = msrp = total_production_cost × 5.00
-- =====================================================================

-- Product B Series (40mg attribute per oz)
-- -----------------------------------------------------------------------

-- ELIX-Product B-2oz: 2oz, 80mg total attribute, 8 servings
INSERT INTO `products` (
    `product_group_id`, `sku`, `name`, `description`, `image_url`,
    `attribute_per_oz`, `size_oz`, `total_mg`, `servings_10mg`,
    `material_cost`, `total_production_cost`, `wholesale_price`, `msrp`,
    `ml_per_10mg_serving`, `oz_per_10mg_serving`, `drinks_per_oz`, `cost_per_10mg_serving`,
    `bottle_cost`, `label_cost`, `labor_cost`, `mix_cost`,
    `profit_margin_percent`, `stock_quantity`, `active`, `use_in_training`
) VALUES (
    1, 'ELIX-Product B-2oz', 'Product B product - 2oz', 
    'Product™ catalog-defined product - Product B formula delivers 40mg attribute per fluid ounce for maximum product specification. Ingredients: Distilled Water, catalog-ready product attribute, Malic Acid, Potassium Sorbate, Sodium Benzoate. Guarantees: High product specification (rapid absorption), product details configured by the operator, product details configured by the operator, zero microbial bacteria, zero contaminents, zero chemicals, 100% operator-configured & organic.',
    '/assets/img/product-placeholder.svg',
    40.00, 2.00, 80, 8,
    ROUND(((40.00 * 2.00) / 620) * 0.36, 2), -- material_cost
    ROUND((ROUND(((40.00 * 2.00) / 620) * 0.36, 2) + 2.50 + 5.00), 2), -- total_production_cost
    ROUND((ROUND(((40.00 * 2.00) / 620) * 0.36, 2) + 2.50 + 5.00) * 5.00, 2), -- wholesale_price
    ROUND((ROUND(((40.00 * 2.00) / 620) * 0.36, 2) + 2.50 + 5.00) * 5.00, 2), -- msrp
    ROUND((10 / 40.00) * 29.5735, 3), -- ml_per_10mg_serving
    ROUND(10 / 40.00, 4), -- oz_per_10mg_serving
    ROUND(40.00 / 10, 2), -- drinks_per_oz
    ROUND((ROUND((ROUND(((40.00 * 2.00) / 620) * 0.36, 2) + 2.50 + 5.00) * 5.00, 2)) / 8, 2), -- cost_per_10mg_serving
    2.50, 5.50, 5.00, 1.50,
    60.00, 100, 1, 1
) ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `image_url` = VALUES(`image_url`),
    `attribute_per_oz` = VALUES(`attribute_per_oz`),
    `size_oz` = VALUES(`size_oz`),
    `total_mg` = VALUES(`total_mg`),
    `servings_10mg` = VALUES(`servings_10mg`),
    `updated_at` = CURRENT_TIMESTAMP;

-- ELIX-Product B-8oz: 8oz, 320mg total attribute, 32 servings
INSERT INTO `products` (
    `product_group_id`, `sku`, `name`, `description`, `image_url`,
    `attribute_per_oz`, `size_oz`, `total_mg`, `servings_10mg`,
    `material_cost`, `total_production_cost`, `wholesale_price`, `msrp`,
    `ml_per_10mg_serving`, `oz_per_10mg_serving`, `drinks_per_oz`, `cost_per_10mg_serving`,
    `bottle_cost`, `label_cost`, `labor_cost`, `mix_cost`,
    `profit_margin_percent`, `stock_quantity`, `active`, `use_in_training`
) VALUES (
    1, 'ELIX-Product B-8oz', 'Product B product - 8oz', 
    'Product™ catalog-defined product - Product B formula delivers 40mg attribute per fluid ounce for maximum product specification. Ingredients: Distilled Water, catalog-ready product attribute, Malic Acid, Potassium Sorbate, Sodium Benzoate. Guarantees: High product specification (rapid absorption), product details configured by the operator, product details configured by the operator, zero microbial bacteria, zero contaminents, zero chemicals, 100% operator-configured & organic.',
    '/assets/img/product-placeholder.svg',
    40.00, 8.00, 320, 32,
    ROUND(((40.00 * 8.00) / 620) * 0.36, 2),
    ROUND((ROUND(((40.00 * 8.00) / 620) * 0.36, 2) + 2.50 + 5.00), 2),
    ROUND((ROUND(((40.00 * 8.00) / 620) * 0.36, 2) + 2.50 + 5.00) * 5.00, 2),
    ROUND((ROUND(((40.00 * 8.00) / 620) * 0.36, 2) + 2.50 + 5.00) * 5.00, 2),
    ROUND((10 / 40.00) * 29.5735, 3),
    ROUND(10 / 40.00, 4),
    ROUND(40.00 / 10, 2),
    ROUND((ROUND((ROUND(((40.00 * 8.00) / 620) * 0.36, 2) + 2.50 + 5.00) * 5.00, 2)) / 32, 2),
    2.50, 5.50, 5.00, 1.50,
    60.00, 100, 1, 1
) ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `image_url` = VALUES(`image_url`),
    `attribute_per_oz` = VALUES(`attribute_per_oz`),
    `size_oz` = VALUES(`size_oz`),
    `total_mg` = VALUES(`total_mg`),
    `servings_10mg` = VALUES(`servings_10mg`),
    `updated_at` = CURRENT_TIMESTAMP;

-- ELIX-Product B-16oz: 16oz, 640mg total attribute, 64 servings
INSERT INTO `products` (
    `product_group_id`, `sku`, `name`, `description`, `image_url`,
    `attribute_per_oz`, `size_oz`, `total_mg`, `servings_10mg`,
    `material_cost`, `total_production_cost`, `wholesale_price`, `msrp`,
    `ml_per_10mg_serving`, `oz_per_10mg_serving`, `drinks_per_oz`, `cost_per_10mg_serving`,
    `bottle_cost`, `label_cost`, `labor_cost`, `mix_cost`,
    `profit_margin_percent`, `stock_quantity`, `active`, `use_in_training`
) VALUES (
    1, 'ELIX-Product B-16oz', 'Product B product - 16oz', 
    'Product™ catalog-defined product - Product B formula delivers 40mg attribute per fluid ounce for maximum product specification. Ingredients: Distilled Water, catalog-ready product attribute, Malic Acid, Potassium Sorbate, Sodium Benzoate. Guarantees: High product specification (rapid absorption), product details configured by the operator, product details configured by the operator, zero microbial bacteria, zero contaminents, zero chemicals, 100% operator-configured & organic.',
    '/assets/img/product-placeholder.svg',
    40.00, 16.00, 640, 64,
    ROUND(((40.00 * 16.00) / 620) * 0.36, 2),
    ROUND((ROUND(((40.00 * 16.00) / 620) * 0.36, 2) + 2.50 + 5.00), 2),
    ROUND((ROUND(((40.00 * 16.00) / 620) * 0.36, 2) + 2.50 + 5.00) * 5.00, 2),
    ROUND((ROUND(((40.00 * 16.00) / 620) * 0.36, 2) + 2.50 + 5.00) * 5.00, 2),
    ROUND((10 / 40.00) * 29.5735, 3),
    ROUND(10 / 40.00, 4),
    ROUND(40.00 / 10, 2),
    ROUND((ROUND((ROUND(((40.00 * 16.00) / 620) * 0.36, 2) + 2.50 + 5.00) * 5.00, 2)) / 64, 2),
    2.50, 5.50, 5.00, 1.50,
    60.00, 100, 1, 1
) ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `image_url` = VALUES(`image_url`),
    `attribute_per_oz` = VALUES(`attribute_per_oz`),
    `size_oz` = VALUES(`size_oz`),
    `total_mg` = VALUES(`total_mg`),
    `servings_10mg` = VALUES(`servings_10mg`),
    `updated_at` = CURRENT_TIMESTAMP;

-- ELIX-Product B-32oz: 32oz, 1280mg total attribute, 128 servings
INSERT INTO `products` (
    `product_group_id`, `sku`, `name`, `description`, `image_url`,
    `attribute_per_oz`, `size_oz`, `total_mg`, `servings_10mg`,
    `material_cost`, `total_production_cost`, `wholesale_price`, `msrp`,
    `ml_per_10mg_serving`, `oz_per_10mg_serving`, `drinks_per_oz`, `cost_per_10mg_serving`,
    `bottle_cost`, `label_cost`, `labor_cost`, `mix_cost`,
    `profit_margin_percent`, `stock_quantity`, `active`, `use_in_training`
) VALUES (
    1, 'ELIX-Product B-32oz', 'Product B product - 32oz', 
    'Product™ catalog-defined product - Product B formula delivers 40mg attribute per fluid ounce for maximum product specification. Ingredients: Distilled Water, catalog-ready product attribute, Malic Acid, Potassium Sorbate, Sodium Benzoate. Guarantees: High product specification (rapid absorption), product details configured by the operator, product details configured by the operator, zero microbial bacteria, zero contaminents, zero chemicals, 100% operator-configured & organic.',
    '/assets/img/product-placeholder.svg',
    40.00, 32.00, 1280, 128,
    ROUND(((40.00 * 32.00) / 620) * 0.36, 2),
    ROUND((ROUND(((40.00 * 32.00) / 620) * 0.36, 2) + 2.50 + 5.00), 2),
    ROUND((ROUND(((40.00 * 32.00) / 620) * 0.36, 2) + 2.50 + 5.00) * 5.00, 2),
    ROUND((ROUND(((40.00 * 32.00) / 620) * 0.36, 2) + 2.50 + 5.00) * 5.00, 2),
    ROUND((10 / 40.00) * 29.5735, 3),
    ROUND(10 / 40.00, 4),
    ROUND(40.00 / 10, 2),
    ROUND((ROUND((ROUND(((40.00 * 32.00) / 620) * 0.36, 2) + 2.50 + 5.00) * 5.00, 2)) / 128, 2),
    2.50, 5.50, 5.00, 1.50,
    60.00, 100, 1, 1
) ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `image_url` = VALUES(`image_url`),
    `attribute_per_oz` = VALUES(`attribute_per_oz`),
    `size_oz` = VALUES(`size_oz`),
    `total_mg` = VALUES(`total_mg`),
    `servings_10mg` = VALUES(`servings_10mg`),
    `updated_at` = CURRENT_TIMESTAMP;

-- Product A Series (20mg attribute per oz)
-- -----------------------------------------------------------------------

-- ELIX-Product A-2oz: 2oz, 40mg total attribute, 4 servings
INSERT INTO `products` (
    `product_group_id`, `sku`, `name`, `description`, `image_url`,
    `attribute_per_oz`, `size_oz`, `total_mg`, `servings_10mg`,
    `material_cost`, `total_production_cost`, `wholesale_price`, `msrp`,
    `ml_per_10mg_serving`, `oz_per_10mg_serving`, `drinks_per_oz`, `cost_per_10mg_serving`,
    `bottle_cost`, `label_cost`, `labor_cost`, `mix_cost`,
    `profit_margin_percent`, `stock_quantity`, `active`, `use_in_training`
) VALUES (
    1, 'ELIX-Product A-2oz', 'Product A product - 2oz', 
    'Product™ catalog-defined product - Product A formula delivers 20mg attribute per fluid ounce for consistent daily use. Ingredients: Distilled Water, catalog-ready product attribute, Malic Acid, Potassium Sorbate, Sodium Benzoate. Guarantees: High product specification (rapid absorption), product details configured by the operator, product details configured by the operator, zero microbial bacteria, zero contaminents, zero chemicals, 100% operator-configured & organic.',
    '/assets/img/product-placeholder.svg',
    20.00, 2.00, 40, 4,
    ROUND(((20.00 * 2.00) / 620) * 0.36, 2),
    ROUND((ROUND(((20.00 * 2.00) / 620) * 0.36, 2) + 2.50 + 5.00), 2),
    ROUND((ROUND(((20.00 * 2.00) / 620) * 0.36, 2) + 2.50 + 5.00) * 5.00, 2),
    ROUND((ROUND(((20.00 * 2.00) / 620) * 0.36, 2) + 2.50 + 5.00) * 5.00, 2),
    ROUND((10 / 20.00) * 29.5735, 3),
    ROUND(10 / 20.00, 4),
    ROUND(20.00 / 10, 2),
    ROUND((ROUND((ROUND(((20.00 * 2.00) / 620) * 0.36, 2) + 2.50 + 5.00) * 5.00, 2)) / 4, 2),
    2.50, 5.50, 5.00, 1.50,
    60.00, 100, 1, 1
) ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `image_url` = VALUES(`image_url`),
    `attribute_per_oz` = VALUES(`attribute_per_oz`),
    `size_oz` = VALUES(`size_oz`),
    `total_mg` = VALUES(`total_mg`),
    `servings_10mg` = VALUES(`servings_10mg`),
    `updated_at` = CURRENT_TIMESTAMP;

-- ELIX-Product A-8oz: 8oz, 160mg total attribute, 16 servings
INSERT INTO `products` (
    `product_group_id`, `sku`, `name`, `description`, `image_url`,
    `attribute_per_oz`, `size_oz`, `total_mg`, `servings_10mg`,
    `material_cost`, `total_production_cost`, `wholesale_price`, `msrp`,
    `ml_per_10mg_serving`, `oz_per_10mg_serving`, `drinks_per_oz`, `cost_per_10mg_serving`,
    `bottle_cost`, `label_cost`, `labor_cost`, `mix_cost`,
    `profit_margin_percent`, `stock_quantity`, `active`, `use_in_training`
) VALUES (
    1, 'ELIX-Product A-8oz', 'Product A product - 8oz', 
    'Product™ catalog-defined product - Product A formula delivers 20mg attribute per fluid ounce for consistent daily use. Ingredients: Distilled Water, catalog-ready product attribute, Malic Acid, Potassium Sorbate, Sodium Benzoate. Guarantees: High product specification (rapid absorption), product details configured by the operator, product details configured by the operator, zero microbial bacteria, zero contaminents, zero chemicals, 100% operator-configured & organic.',
    '/assets/img/product-placeholder.svg',
    20.00, 8.00, 160, 16,
    ROUND(((20.00 * 8.00) / 620) * 0.36, 2),
    ROUND((ROUND(((20.00 * 8.00) / 620) * 0.36, 2) + 2.50 + 5.00), 2),
    ROUND((ROUND(((20.00 * 8.00) / 620) * 0.36, 2) + 2.50 + 5.00) * 5.00, 2),
    ROUND((ROUND(((20.00 * 8.00) / 620) * 0.36, 2) + 2.50 + 5.00) * 5.00, 2),
    ROUND((10 / 20.00) * 29.5735, 3),
    ROUND(10 / 20.00, 4),
    ROUND(20.00 / 10, 2),
    ROUND((ROUND((ROUND(((20.00 * 8.00) / 620) * 0.36, 2) + 2.50 + 5.00) * 5.00, 2)) / 16, 2),
    2.50, 5.50, 5.00, 1.50,
    60.00, 100, 1, 1
) ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `image_url` = VALUES(`image_url`),
    `attribute_per_oz` = VALUES(`attribute_per_oz`),
    `size_oz` = VALUES(`size_oz`),
    `total_mg` = VALUES(`total_mg`),
    `servings_10mg` = VALUES(`servings_10mg`),
    `updated_at` = CURRENT_TIMESTAMP;

-- ELIX-Product A-16oz: 16oz, 320mg total attribute, 32 servings
INSERT INTO `products` (
    `product_group_id`, `sku`, `name`, `description`, `image_url`,
    `attribute_per_oz`, `size_oz`, `total_mg`, `servings_10mg`,
    `material_cost`, `total_production_cost`, `wholesale_price`, `msrp`,
    `ml_per_10mg_serving`, `oz_per_10mg_serving`, `drinks_per_oz`, `cost_per_10mg_serving`,
    `bottle_cost`, `label_cost`, `labor_cost`, `mix_cost`,
    `profit_margin_percent`, `stock_quantity`, `active`, `use_in_training`
) VALUES (
    1, 'ELIX-Product A-16oz', 'Product A product - 16oz', 
    'Product™ catalog-defined product - Product A formula delivers 20mg attribute per fluid ounce for consistent daily use. Ingredients: Distilled Water, catalog-ready product attribute, Malic Acid, Potassium Sorbate, Sodium Benzoate. Guarantees: High product specification (rapid absorption), product details configured by the operator, product details configured by the operator, zero microbial bacteria, zero contaminents, zero chemicals, 100% operator-configured & organic.',
    '/assets/img/product-placeholder.svg',
    20.00, 16.00, 320, 32,
    ROUND(((20.00 * 16.00) / 620) * 0.36, 2),
    ROUND((ROUND(((20.00 * 16.00) / 620) * 0.36, 2) + 2.50 + 5.00), 2),
    ROUND((ROUND(((20.00 * 16.00) / 620) * 0.36, 2) + 2.50 + 5.00) * 5.00, 2),
    ROUND((ROUND(((20.00 * 16.00) / 620) * 0.36, 2) + 2.50 + 5.00) * 5.00, 2),
    ROUND((10 / 20.00) * 29.5735, 3),
    ROUND(10 / 20.00, 4),
    ROUND(20.00 / 10, 2),
    ROUND((ROUND((ROUND(((20.00 * 16.00) / 620) * 0.36, 2) + 2.50 + 5.00) * 5.00, 2)) / 32, 2),
    2.50, 5.50, 5.00, 1.50,
    60.00, 100, 1, 1
) ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `image_url` = VALUES(`image_url`),
    `attribute_per_oz` = VALUES(`attribute_per_oz`),
    `size_oz` = VALUES(`size_oz`),
    `total_mg` = VALUES(`total_mg`),
    `servings_10mg` = VALUES(`servings_10mg`),
    `updated_at` = CURRENT_TIMESTAMP;

-- ELIX-Product A-32oz: 32oz, 640mg total attribute, 64 servings
INSERT INTO `products` (
    `product_group_id`, `sku`, `name`, `description`, `image_url`,
    `attribute_per_oz`, `size_oz`, `total_mg`, `servings_10mg`,
    `material_cost`, `total_production_cost`, `wholesale_price`, `msrp`,
    `ml_per_10mg_serving`, `oz_per_10mg_serving`, `drinks_per_oz`, `cost_per_10mg_serving`,
    `bottle_cost`, `label_cost`, `labor_cost`, `mix_cost`,
    `profit_margin_percent`, `stock_quantity`, `active`, `use_in_training`
) VALUES (
    1, 'ELIX-Product A-32oz', 'Product A product - 32oz', 
    'Product™ catalog-defined product - Product A formula delivers 20mg attribute per fluid ounce for consistent daily use. Ingredients: Distilled Water, catalog-ready product attribute, Malic Acid, Potassium Sorbate, Sodium Benzoate. Guarantees: High product specification (rapid absorption), product details configured by the operator, product details configured by the operator, zero microbial bacteria, zero contaminents, zero chemicals, 100% operator-configured & organic.',
    '/assets/img/product-placeholder.svg',
    20.00, 32.00, 640, 64,
    ROUND(((20.00 * 32.00) / 620) * 0.36, 2),
    ROUND((ROUND(((20.00 * 32.00) / 620) * 0.36, 2) + 2.50 + 5.00), 2),
    ROUND((ROUND(((20.00 * 32.00) / 620) * 0.36, 2) + 2.50 + 5.00) * 5.00, 2),
    ROUND((ROUND(((20.00 * 32.00) / 620) * 0.36, 2) + 2.50 + 5.00) * 5.00, 2),
    ROUND((10 / 20.00) * 29.5735, 3),
    ROUND(10 / 20.00, 4),
    ROUND(20.00 / 10, 2),
    ROUND((ROUND((ROUND(((20.00 * 32.00) / 620) * 0.36, 2) + 2.50 + 5.00) * 5.00, 2)) / 64, 2),
    2.50, 5.50, 5.00, 1.50,
    60.00, 100, 1, 1
) ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `image_url` = VALUES(`image_url`),
    `attribute_per_oz` = VALUES(`attribute_per_oz`),
    `size_oz` = VALUES(`size_oz`),
    `total_mg` = VALUES(`total_mg`),
    `servings_10mg` = VALUES(`servings_10mg`),
    `updated_at` = CURRENT_TIMESTAMP;

