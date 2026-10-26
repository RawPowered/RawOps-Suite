-- Migration: Fix Shop Product Display
-- Date: 2025-10-30
-- Purpose: Ensure product format products exist and are properly configured for shop display
-- Rule: Use binary collation, INSERT...ON DUPLICATE KEY UPDATE to prevent duplicates

SET NAMES utf8mb4 COLLATE utf8mb4_bin;

-- ============================================================================
-- STEP 1: Verify product format product group exists and is active
-- ============================================================================

UPDATE `product_groups`
SET 
    `active` = 1,
    `display_order` = 2,
    `description` = 'Premium catalog-defined Product format. 100% natural and organic. High attribute content for consistent product specification.',
    `updated_at` = CURRENT_TIMESTAMP
WHERE `id` = 3 OR `group_key` = 'product-product format';

-- ============================================================================
-- STEP 2: Create sample product format products if they don't exist
-- ============================================================================

-- 100g product format Product
INSERT INTO `products` (
    `id`,
    `product_type`,
    `is_liquid`,
    `is_dropship`,
    `track_inventory`,
    `display_unit`,
    `product_group_id`,
    `sku`,
    `name`,
    `description`,
    `image_url`,
    `weight_grams`,
    `weight_oz`,
    `wholesale_price`,
    `msrp`,
    `stock_quantity`,
    `low_stock_threshold`,
    `active`,
    `created_at`,
    `updated_at`
) VALUES (
    50,
    'manufactured_solid',
    0,
    0,
    1,
    'grams',
    3,
    'product format-CLEAN-100G',
    'Product™ Clean Product format - 100g',
    'Premium catalog-defined Product format. 100% natural and organic. High attribute content for consistent product specification. Perfect for wholesale and retail customers who prefer traditional product format format. Lab-tested for quality information and consistency.',
    NULL,
    100.00,
    3.53,
    29.99,
    49.99,
    100,
    10,
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
) ON DUPLICATE KEY UPDATE
    `product_group_id` = 3,
    `active` = 1,
    `updated_at` = CURRENT_TIMESTAMP;

-- 250g product format Product
INSERT INTO `products` (
    `id`,
    `product_type`,
    `is_liquid`,
    `is_dropship`,
    `track_inventory`,
    `display_unit`,
    `product_group_id`,
    `sku`,
    `name`,
    `description`,
    `image_url`,
    `weight_grams`,
    `weight_oz`,
    `wholesale_price`,
    `msrp`,
    `stock_quantity`,
    `low_stock_threshold`,
    `active`,
    `created_at`,
    `updated_at`
) VALUES (
    51,
    'manufactured_solid',
    0,
    0,
    1,
    'grams',
    3,
    'product format-CLEAN-250G',
    'Product™ Clean Product format - 250g',
    'Premium catalog-defined Product format. 100% natural and organic. High attribute content for consistent product specification. Larger size for regular users or businesses. Lab-tested for quality information and consistency.',
    NULL,
    250.00,
    8.82,
    59.99,
    99.99,
    100,
    10,
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
) ON DUPLICATE KEY UPDATE
    `product_group_id` = 3,
    `active` = 1,
    `updated_at` = CURRENT_TIMESTAMP;

-- 500g product format Product
INSERT INTO `products` (
    `id`,
    `product_type`,
    `is_liquid`,
    `is_dropship`,
    `track_inventory`,
    `display_unit`,
    `product_group_id`,
    `sku`,
    `name`,
    `description`,
    `image_url`,
    `weight_grams`,
    `weight_oz`,
    `wholesale_price`,
    `msrp`,
    `stock_quantity`,
    `low_stock_threshold`,
    `active`,
    `created_at`,
    `updated_at`
) VALUES (
    52,
    'manufactured_solid',
    0,
    0,
    1,
    'grams',
    3,
    'product format-CLEAN-500G',
    'Product™ Clean Product format - 500g',
    'Premium catalog-defined Product format. 100% natural and organic. High attribute content for consistent product specification. Best value for regular users and wholesale customers. Lab-tested for quality information and consistency.',
    NULL,
    500.00,
    17.64,
    99.99,
    179.99,
    100,
    10,
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
) ON DUPLICATE KEY UPDATE
    `product_group_id` = 3,
    `active` = 1,
    `updated_at` = CURRENT_TIMESTAMP;

-- 1kg product format Product (Wholesale/Bulk)
INSERT INTO `products` (
    `id`,
    `product_type`,
    `is_liquid`,
    `is_dropship`,
    `track_inventory`,
    `display_unit`,
    `product_group_id`,
    `sku`,
    `name`,
    `description`,
    `image_url`,
    `weight_grams`,
    `weight_oz`,
    `weight_kg`,
    `wholesale_price`,
    `msrp`,
    `stock_quantity`,
    `low_stock_threshold`,
    `active`,
    `created_at`,
    `updated_at`
) VALUES (
    53,
    'manufactured_solid',
    0,
    0,
    1,
    'grams',
    3,
    'product format-CLEAN-1KG',
    'Product™ Clean Product format - 1kg',
    'Premium catalog-defined Product format. 100% natural and organic. High attribute content for consistent product specification. Bulk size for wholesale customers and businesses. Lab-tested for quality information and consistency.',
    NULL,
    1000.00,
    35.27,
    1.00,
    179.99,
    329.99,
    50,
    5,
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
) ON DUPLICATE KEY UPDATE
    `product_group_id` = 3,
    `active` = 1,
    `updated_at` = CURRENT_TIMESTAMP;

-- ============================================================================
-- STEP 3: Verify all products have proper configuration
-- ============================================================================

-- Ensure all products have at least basic stock
UPDATE `products`
SET `stock_quantity` = 100
WHERE `stock_quantity` = 0 AND `active` = 1 AND `product_group_id` IN (1, 2, 3);

-- ============================================================================
-- VERIFICATION QUERIES (Run these to verify the migration)
-- ============================================================================

-- Check product format product group
-- SELECT * FROM product_groups WHERE id = 3;

-- Check product format products
-- SELECT id, sku, name, product_group_id, active, stock_quantity, wholesale_price, msrp 
-- FROM products 
-- WHERE product_group_id = 3;

-- Check all active products by group
-- SELECT pg.name as group_name, COUNT(*) as product_count
-- FROM products p
-- JOIN product_groups pg ON p.product_group_id = pg.id
-- WHERE p.active = 1
-- GROUP BY pg.id, pg.name;













