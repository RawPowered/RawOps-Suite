-- Migration: Update Product Catalog for Product A and Product B products
-- Date: 2025-10-21
-- Description: Add catalog-ready catalog-defined product attribute Products to Products group

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Update product group description to reflect new Product A and Product B lineup
UPDATE `product_groups` 
SET 
    `description` = 'Premium catalog-ready catalog-defined product attribute products. Two precision-dosed product specification levels: Product A (20mg attribute/oz) for consistent daily use, and Product B (40mg attribute/oz) for maximum product specification. Crystal-clear liquid with product details configured by the operator, product details configured by the operator, 100% natural and organic. Perfect for wholesale, retail, bars, partner lounges, and wellness centers.',
    `updated_at` = NOW()
WHERE `id` = 1 AND `group_key` = 'product-products';

-- Deactivate old products (preserve for historical data)
UPDATE `products` 
SET 
    `active` = 0,
    `updated_at` = NOW()
WHERE `active` = 1;

-- Insert Product A Products (20mg attribute per fluid ounce)
-- All products assigned to product_group_id = 1 (Products)

INSERT INTO `products` (
    `product_group_id`, `sku`, `name`, `description`, `attribute_content`, 
    `wholesale_price`, `msrp`, `stock_quantity`, `low_stock_threshold`, 
    `active`, `attribute_per_oz`, `size_oz`, `created_at`
) VALUES 
(
    1,
    'PRODUCT-Product A-16OZ',
    'Product A - catalog-defined product (16oz)',
    'catalog-ready catalog-defined product attribute product. 20mg attribute per fluid ounce. Crystal-clear liquid with product details configured by the operator, product details configured by the operator, zero microbial bacteria. 100% natural and organic. High product specification for rapid absorption. Total attribute content: 320mg per bottle.',
    'INGREDIENTS: Distilled water, potassium sorbate, sodium benzoate, malic acid, catalog-ready product attribute extract. GUARANTEES: High product specification (rapid absorption), product details configured by the operator, product details configured by the operator, Zero microbial bacteria, product details configured by the operator, Zero chemicals, 100% operator-configured & organic.',
    79.99,
    199.99,
    100,
    10,
    1,
    20.00,
    16.00,
    NOW()
)
ON DUPLICATE KEY UPDATE 
    `product_group_id` = VALUES(`product_group_id`),
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `attribute_content` = VALUES(`attribute_content`),
    `wholesale_price` = VALUES(`wholesale_price`),
    `msrp` = VALUES(`msrp`),
    `active` = VALUES(`active`),
    `attribute_per_oz` = VALUES(`attribute_per_oz`),
    `size_oz` = VALUES(`size_oz`),
    `updated_at` = NOW();

INSERT INTO `products` (
    `product_group_id`, `sku`, `name`, `description`, `attribute_content`, 
    `wholesale_price`, `msrp`, `stock_quantity`, `low_stock_threshold`, 
    `active`, `attribute_per_oz`, `size_oz`, `created_at`
) VALUES 
(
    1,
    'PRODUCT-Product A-32OZ',
    'Product A - catalog-defined product (32oz)',
    'catalog-ready catalog-defined product attribute product. 20mg attribute per fluid ounce. Crystal-clear liquid with product details configured by the operator, product details configured by the operator, zero microbial bacteria. 100% natural and organic. High product specification for rapid absorption. Total attribute content: 640mg per bottle.',
    'INGREDIENTS: Distilled water, potassium sorbate, sodium benzoate, malic acid, catalog-ready product attribute extract. GUARANTEES: High product specification (rapid absorption), product details configured by the operator, product details configured by the operator, Zero microbial bacteria, product details configured by the operator, Zero chemicals, 100% operator-configured & organic.',
    139.99,
    349.99,
    100,
    10,
    1,
    20.00,
    32.00,
    NOW()
)
ON DUPLICATE KEY UPDATE 
    `product_group_id` = VALUES(`product_group_id`),
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `attribute_content` = VALUES(`attribute_content`),
    `wholesale_price` = VALUES(`wholesale_price`),
    `msrp` = VALUES(`msrp`),
    `active` = VALUES(`active`),
    `attribute_per_oz` = VALUES(`attribute_per_oz`),
    `size_oz` = VALUES(`size_oz`),
    `updated_at` = NOW();

INSERT INTO `products` (
    `product_group_id`, `sku`, `name`, `description`, `attribute_content`, 
    `wholesale_price`, `msrp`, `stock_quantity`, `low_stock_threshold`, 
    `active`, `attribute_per_oz`, `size_oz`, `created_at`
) VALUES 
(
    1,
    'PRODUCT-Product A-6product-pack',
    'Product A - catalog-defined product (6product-pack)',
    'catalog-ready catalog-defined product attribute product. 20mg attribute per fluid ounce. Crystal-clear liquid with product details configured by the operator, product details configured by the operator, zero microbial bacteria. 100% natural and organic. High product specification for rapid absorption. Total attribute content: 1,280mg per bottle.',
    'INGREDIENTS: Distilled water, potassium sorbate, sodium benzoate, malic acid, catalog-ready product attribute extract. GUARANTEES: High product specification (rapid absorption), product details configured by the operator, product details configured by the operator, Zero microbial bacteria, product details configured by the operator, Zero chemicals, 100% operator-configured & organic.',
    239.99,
    599.99,
    100,
    10,
    1,
    20.00,
    64.00,
    NOW()
)
ON DUPLICATE KEY UPDATE 
    `product_group_id` = VALUES(`product_group_id`),
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `attribute_content` = VALUES(`attribute_content`),
    `wholesale_price` = VALUES(`wholesale_price`),
    `msrp` = VALUES(`msrp`),
    `active` = VALUES(`active`),
    `attribute_per_oz` = VALUES(`attribute_per_oz`),
    `size_oz` = VALUES(`size_oz`),
    `updated_at` = NOW();

INSERT INTO `products` (
    `product_group_id`, `sku`, `name`, `description`, `attribute_content`, 
    `wholesale_price`, `msrp`, `stock_quantity`, `low_stock_threshold`, 
    `active`, `attribute_per_oz`, `size_oz`, `created_at`
) VALUES 
(
    1,
    'PRODUCT-Product A-128OZ',
    'Product A - catalog-defined product (128oz/1gal)',
    'catalog-ready catalog-defined product attribute product. 20mg attribute per fluid ounce. Crystal-clear liquid with product details configured by the operator, product details configured by the operator, zero microbial bacteria. 100% natural and organic. High product specification for rapid absorption. Total attribute content: 2,560mg per bottle.',
    'INGREDIENTS: Distilled water, potassium sorbate, sodium benzoate, malic acid, catalog-ready product attribute extract. GUARANTEES: High product specification (rapid absorption), product details configured by the operator, product details configured by the operator, Zero microbial bacteria, product details configured by the operator, Zero chemicals, 100% operator-configured & organic.',
    399.99,
    999.99,
    50,
    5,
    1,
    20.00,
    128.00,
    NOW()
)
ON DUPLICATE KEY UPDATE 
    `product_group_id` = VALUES(`product_group_id`),
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `attribute_content` = VALUES(`attribute_content`),
    `wholesale_price` = VALUES(`wholesale_price`),
    `msrp` = VALUES(`msrp`),
    `active` = VALUES(`active`),
    `attribute_per_oz` = VALUES(`attribute_per_oz`),
    `size_oz` = VALUES(`size_oz`),
    `updated_at` = NOW();

-- Insert Product B Products (40mg attribute per fluid ounce)

INSERT INTO `products` (
    `product_group_id`, `sku`, `name`, `description`, `attribute_content`, 
    `wholesale_price`, `msrp`, `stock_quantity`, `low_stock_threshold`, 
    `active`, `attribute_per_oz`, `size_oz`, `created_at`
) VALUES 
(
    1,
    'PRODUCT-Product B-16OZ',
    'Product B - catalog-defined product (16oz)',
    'catalog-ready catalog-defined product attribute product. 40mg attribute per fluid ounce. Maximum product specification crystal-clear liquid with product details configured by the operator, product details configured by the operator, zero microbial bacteria. 100% natural and organic. High product specification for rapid absorption. Total attribute content: 640mg per bottle.',
    'INGREDIENTS: Distilled water, potassium sorbate, sodium benzoate, malic acid, catalog-ready product attribute extract. GUARANTEES: High product specification (rapid absorption), product details configured by the operator, product details configured by the operator, Zero microbial bacteria, product details configured by the operator, Zero chemicals, 100% operator-configured & organic.',
    129.99,
    324.99,
    100,
    10,
    1,
    40.00,
    16.00,
    NOW()
)
ON DUPLICATE KEY UPDATE 
    `product_group_id` = VALUES(`product_group_id`),
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `attribute_content` = VALUES(`attribute_content`),
    `wholesale_price` = VALUES(`wholesale_price`),
    `msrp` = VALUES(`msrp`),
    `active` = VALUES(`active`),
    `attribute_per_oz` = VALUES(`attribute_per_oz`),
    `size_oz` = VALUES(`size_oz`),
    `updated_at` = NOW();

INSERT INTO `products` (
    `product_group_id`, `sku`, `name`, `description`, `attribute_content`, 
    `wholesale_price`, `msrp`, `stock_quantity`, `low_stock_threshold`, 
    `active`, `attribute_per_oz`, `size_oz`, `created_at`
) VALUES 
(
    1,
    'PRODUCT-Product B-32OZ',
    'Product B - catalog-defined product (32oz)',
    'catalog-ready catalog-defined product attribute product. 40mg attribute per fluid ounce. Maximum product specification crystal-clear liquid with product details configured by the operator, product details configured by the operator, zero microbial bacteria. 100% natural and organic. High product specification for rapid absorption. Total attribute content: 1,280mg per bottle.',
    'INGREDIENTS: Distilled water, potassium sorbate, sodium benzoate, malic acid, catalog-ready product attribute extract. GUARANTEES: High product specification (rapid absorption), product details configured by the operator, product details configured by the operator, Zero microbial bacteria, product details configured by the operator, Zero chemicals, 100% operator-configured & organic.',
    229.99,
    574.99,
    100,
    10,
    1,
    40.00,
    32.00,
    NOW()
)
ON DUPLICATE KEY UPDATE 
    `product_group_id` = VALUES(`product_group_id`),
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `attribute_content` = VALUES(`attribute_content`),
    `wholesale_price` = VALUES(`wholesale_price`),
    `msrp` = VALUES(`msrp`),
    `active` = VALUES(`active`),
    `attribute_per_oz` = VALUES(`attribute_per_oz`),
    `size_oz` = VALUES(`size_oz`),
    `updated_at` = NOW();

INSERT INTO `products` (
    `product_group_id`, `sku`, `name`, `description`, `attribute_content`, 
    `wholesale_price`, `msrp`, `stock_quantity`, `low_stock_threshold`, 
    `active`, `attribute_per_oz`, `size_oz`, `created_at`
) VALUES 
(
    1,
    'PRODUCT-Product B-6product-pack',
    'Product B - catalog-defined product (6product-pack)',
    'catalog-ready catalog-defined product attribute product. 40mg attribute per fluid ounce. Maximum product specification crystal-clear liquid with product details configured by the operator, product details configured by the operator, zero microbial bacteria. 100% natural and organic. High product specification for rapid absorption. Total attribute content: 2,560mg per bottle.',
    'INGREDIENTS: Distilled water, potassium sorbate, sodium benzoate, malic acid, catalog-ready product attribute extract. GUARANTEES: High product specification (rapid absorption), product details configured by the operator, product details configured by the operator, Zero microbial bacteria, product details configured by the operator, Zero chemicals, 100% operator-configured & organic.',
    399.99,
    999.99,
    100,
    10,
    1,
    40.00,
    64.00,
    NOW()
)
ON DUPLICATE KEY UPDATE 
    `product_group_id` = VALUES(`product_group_id`),
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `attribute_content` = VALUES(`attribute_content`),
    `wholesale_price` = VALUES(`wholesale_price`),
    `msrp` = VALUES(`msrp`),
    `active` = VALUES(`active`),
    `attribute_per_oz` = VALUES(`attribute_per_oz`),
    `size_oz` = VALUES(`size_oz`),
    `updated_at` = NOW();

INSERT INTO `products` (
    `product_group_id`, `sku`, `name`, `description`, `attribute_content`, 
    `wholesale_price`, `msrp`, `stock_quantity`, `low_stock_threshold`, 
    `active`, `attribute_per_oz`, `size_oz`, `created_at`
) VALUES 
(
    1,
    'PRODUCT-Product B-128OZ',
    'Product B - catalog-defined product (128oz/1gal)',
    'catalog-ready catalog-defined product attribute product. 40mg attribute per fluid ounce. Maximum product specification crystal-clear liquid with product details configured by the operator, product details configured by the operator, zero microbial bacteria. 100% natural and organic. High product specification for rapid absorption. Total attribute content: 5,120mg per bottle.',
    'INGREDIENTS: Distilled water, potassium sorbate, sodium benzoate, malic acid, catalog-ready product attribute extract. GUARANTEES: High product specification (rapid absorption), product details configured by the operator, product details configured by the operator, Zero microbial bacteria, product details configured by the operator, Zero chemicals, 100% operator-configured & organic.',
    699.99,
    1749.99,
    50,
    5,
    1,
    40.00,
    128.00,
    NOW()
)
ON DUPLICATE KEY UPDATE 
    `product_group_id` = VALUES(`product_group_id`),
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `attribute_content` = VALUES(`attribute_content`),
    `wholesale_price` = VALUES(`wholesale_price`),
    `msrp` = VALUES(`msrp`),
    `active` = VALUES(`active`),
    `attribute_per_oz` = VALUES(`attribute_per_oz`),
    `size_oz` = VALUES(`size_oz`),
    `updated_at` = NOW();

-- Summary:
-- Product A line: 4 products (16oz, 32oz, 6product-pack, 128oz) at 20mg attribute/oz
-- Product B line: 4 products (16oz, 32oz, 6product-pack, 128oz) at 40mg attribute/oz
-- All 8 products assigned to product_group_id = 1 (Products)
-- Uses ON DUPLICATE KEY UPDATE for idempotent migrations
