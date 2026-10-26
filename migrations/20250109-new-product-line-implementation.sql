-- Migration: New Product Line Implementation
-- Date: 2025-01-09
-- Description: Replace existing products with new Blue 10, Gold 30, Black 60 product line
-- Volume pricing: 1-5 (20% commission, 0% discount), 6-10 (12% commission, 8% discount), 15+ (8% commission, 12% discount)

-- Backup existing products before replacement
CREATE TABLE IF NOT EXISTS `products_backup_2025_01_09` LIKE `products`;
INSERT IGNORE INTO `products_backup_2025_01_09` SELECT * FROM `products`;

-- Create volume pricing table for bulk discounts
CREATE TABLE IF NOT EXISTS `volume_pricing` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `min_quantity` int(10) UNSIGNED NOT NULL,
  `max_quantity` int(10) UNSIGNED DEFAULT NULL,
  `bulk_discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `commission_rate_percent` decimal(5,2) NOT NULL DEFAULT 20.00,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_product_quantity` (`product_id`, `min_quantity`, `max_quantity`),
  KEY `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Create product pricing table for different volume tiers
CREATE TABLE IF NOT EXISTS `product_pricing` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `quantity_tier` enum('1-5','6-10','15+') NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `mix_cost` decimal(8,2) NOT NULL DEFAULT 1.50,
  `material_cost` decimal(8,2) NOT NULL,
  `drink_cost` decimal(8,2) NOT NULL,
  `retail_drink_price` decimal(8,2) NOT NULL,
  `revenue_per_bottle` decimal(10,2) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_product_tier` (`product_id`, `quantity_tier`),
  KEY `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Deactivate all existing products
UPDATE `products` SET `active` = 0, `updated_at` = NOW() WHERE `active` = 1;

-- Insert new product line with duplicate handling
INSERT INTO `products` (`id`, `sku`, `name`, `description`, `attribute_content`, `weight_grams`, `dimensions`, `wholesale_price`, `msrp`, `stock_quantity`, `low_stock_threshold`, `active`, `created_at`, `updated_at`) VALUES
(11, 'BLUE-10-32', 'Blue 10 - 32oz', 'Premium Product with 10mg attribute per 0.5oz serving. 32oz bottle contains 64 servings. Perfect for bars and restaurants offering precise 10mg doses. Total: 640mg attribute per bottle.', '10mg attribute per 0.5oz serving (20mg per 1oz)', 946.35, '32oz', 55.05, 55.05, 100, 10, 1, NOW(), NULL),
(12, 'GOLD-30-32', 'Gold 30 - 32oz', 'Premium Product with 30mg attribute per 0.5oz serving. 32oz bottle contains 64 servings. Ideal for bars offering stronger doses or multiple 10mg servings per drink. Total: 1920mg attribute per bottle.', '30mg attribute per 0.5oz serving (60mg per 1oz)', 946.35, '32oz', 90.15, 90.15, 100, 10, 1, NOW(), NULL),
(13, 'BLACK-60-32', 'Black 60 - 32oz', 'Premium Product with 60mg attribute per 0.5oz serving. 32oz bottle contains 64 servings. Maximum strength for bars serving high-dose drinks or multiple servings per customer. Total: 3840mg attribute per bottle.', '60mg attribute per 0.5oz serving (120mg per 1oz)', 946.35, '32oz', 142.79, 142.79, 100, 10, 1, NOW(), NULL)
ON DUPLICATE KEY UPDATE
  `sku` = VALUES(`sku`),
  `name` = VALUES(`name`),
  `description` = VALUES(`description`),
  `attribute_content` = VALUES(`attribute_content`),
  `weight_grams` = VALUES(`weight_grams`),
  `dimensions` = VALUES(`dimensions`),
  `wholesale_price` = VALUES(`wholesale_price`),
  `msrp` = VALUES(`msrp`),
  `stock_quantity` = VALUES(`stock_quantity`),
  `low_stock_threshold` = VALUES(`low_stock_threshold`),
  `active` = VALUES(`active`),
  `updated_at` = NOW();

-- Insert volume pricing rules with duplicate handling
INSERT INTO `volume_pricing` (`product_id`, `min_quantity`, `max_quantity`, `bulk_discount_percent`, `commission_rate_percent`, `active`, `created_at`) VALUES
-- Blue 10 pricing tiers
(11, 1, 5, 0.00, 20.00, 1, NOW()),
(11, 6, 10, 8.00, 12.00, 1, NOW()),
(11, 15, NULL, 12.00, 8.00, 1, NOW()),
-- Gold 30 pricing tiers
(12, 1, 5, 0.00, 20.00, 1, NOW()),
(12, 6, 10, 8.00, 12.00, 1, NOW()),
(12, 15, NULL, 12.00, 8.00, 1, NOW()),
-- Black 60 pricing tiers
(13, 1, 5, 0.00, 20.00, 1, NOW()),
(13, 6, 10, 8.00, 12.00, 1, NOW()),
(13, 15, NULL, 12.00, 8.00, 1, NOW())
ON DUPLICATE KEY UPDATE
  `bulk_discount_percent` = VALUES(`bulk_discount_percent`),
  `commission_rate_percent` = VALUES(`commission_rate_percent`),
  `active` = VALUES(`active`),
  `updated_at` = NOW();

-- Insert detailed pricing for each product and quantity tier with duplicate handling
INSERT INTO `product_pricing` (`product_id`, `quantity_tier`, `price`, `mix_cost`, `material_cost`, `drink_cost`, `retail_drink_price`, `revenue_per_bottle`, `active`, `created_at`) VALUES
-- Blue 10 pricing
(11, '1-5', 55.05, 1.50, 0.86, 2.36, 12.00, 616.95, 1, NOW()),
(11, '6-10', 50.64, 1.50, 0.86, 2.28, 12.00, 622.00, 1, NOW()),
(11, '15+', 48.44, 1.50, 0.86, 2.24, 12.00, 625.00, 1, NOW()),
-- Gold 30 pricing
(12, '1-5', 90.15, 1.50, 1.41, 2.91, 18.00, 965.85, 1, NOW()),
(12, '6-10', 82.93, 1.50, 1.41, 2.68, 18.00, 980.75, 1, NOW()),
(12, '15+', 79.33, 1.50, 1.41, 2.56, 18.00, 988.19, 1, NOW()),
-- Black 60 pricing
(13, '1-5', 142.79, 1.50, 2.23, 3.73, 25.00, 1361.21, 1, NOW()),
(13, '6-10', 131.37, 1.50, 2.23, 3.43, 25.00, 1380.31, 1, NOW()),
(13, '15+', 125.66, 1.50, 2.23, 3.28, 25.00, 1389.86, 1, NOW())
ON DUPLICATE KEY UPDATE
  `price` = VALUES(`price`),
  `mix_cost` = VALUES(`mix_cost`),
  `material_cost` = VALUES(`material_cost`),
  `drink_cost` = VALUES(`drink_cost`),
  `retail_drink_price` = VALUES(`retail_drink_price`),
  `revenue_per_bottle` = VALUES(`revenue_per_bottle`),
  `active` = VALUES(`active`),
  `updated_at` = NOW();

-- Update commission settings to match new volume pricing structure
UPDATE `commission_settings` SET 
  `setting_value` = '20.00',
  `updated_at` = NOW()
WHERE `setting_key` = 'commission_qty_5_9';

UPDATE `commission_settings` SET 
  `setting_value` = '12.00',
  `updated_at` = NOW()
WHERE `setting_key` = 'commission_qty_10_14';

UPDATE `commission_settings` SET 
  `setting_value` = '8.00',
  `updated_at` = NOW()
WHERE `setting_key` = 'commission_qty_15_19';

-- Add new commission settings for the updated tiers
INSERT INTO `commission_settings` (`setting_key`, `setting_value`, `description`, `updated_at`) VALUES
('volume_discount_1_5', '0.00', 'Bulk discount percentage for 1-5 quantity orders', NOW()),
('volume_discount_6_10', '8.00', 'Bulk discount percentage for 6-10 quantity orders', NOW()),
('volume_discount_15_plus', '12.00', 'Bulk discount percentage for 15+ quantity orders', NOW()),
('commission_rate_1_5', '20.00', 'Commission rate for 1-5 quantity orders', NOW()),
('commission_rate_6_10', '12.00', 'Commission rate for 6-10 quantity orders', NOW()),
('commission_rate_15_plus', '8.00', 'Commission rate for 15+ quantity orders', NOW())
ON DUPLICATE KEY UPDATE 
  `setting_value` = VALUES(`setting_value`),
  `updated_at` = NOW();

-- Update commission tiers to match new structure
UPDATE `commission_tiers` SET 
  `name` = 'Volume Tier 1-5',
  `commission_rate` = 20.00,
  `description` = 'Volume orders 1-5 items - 20% commission rate, 0% bulk discount'
WHERE `id` = 1;

UPDATE `commission_tiers` SET 
  `name` = 'Volume Tier 6-10',
  `commission_rate` = 12.00,
  `description` = 'Volume orders 6-10 items - 12% commission rate, 8% bulk discount'
WHERE `id` = 2;

UPDATE `commission_tiers` SET 
  `name` = 'Volume Tier 15+',
  `commission_rate` = 8.00,
  `description` = 'Volume orders 15+ items - 8% commission rate, 12% bulk discount'
WHERE `id` = 3;

-- Note: commission_tiers table doesn't have active or updated_at columns
-- Old tiers (id 4, 5) will remain but won't be used by new system

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS `idx_products_active_sku` ON `products` (`active`, `sku`);
CREATE INDEX IF NOT EXISTS `idx_volume_pricing_product_quantity` ON `volume_pricing` (`product_id`, `min_quantity`, `max_quantity`);
CREATE INDEX IF NOT EXISTS `idx_product_pricing_product_tier` ON `product_pricing` (`product_id`, `quantity_tier`);

-- Update product descriptions with serving information
UPDATE `products` SET 
  `description` = 'Premium Product with 10mg attribute per 0.5oz serving. 32oz bottle contains 64 servings (640mg total attribute). Perfect for bars and restaurants offering precise 10mg doses. Mix cost: $1.50, Material cost: $0.86.',
  `updated_at` = NOW()
WHERE `id` = 11;

UPDATE `products` SET 
  `description` = 'Premium Product with 30mg attribute per 0.5oz serving. 32oz bottle contains 64 servings (1920mg total attribute). Ideal for bars offering stronger doses. Each 0.5oz serving equals 3 standard 10mg doses. Mix cost: $1.50, Material cost: $1.41.',
  `updated_at` = NOW()
WHERE `id` = 12;

UPDATE `products` SET 
  `description` = 'Premium Product with 60mg attribute per 0.5oz serving. 32oz bottle contains 64 servings (3840mg total attribute). Maximum strength for bars serving high-dose drinks. Each 0.5oz serving equals 6 standard 10mg doses. Mix cost: $1.50, Material cost: $2.23.',
  `updated_at` = NOW()
WHERE `id` = 13;

-- Create migration log table if it doesn't exist
CREATE TABLE IF NOT EXISTS `migration_log` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration_name` varchar(255) NOT NULL,
  `execution_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','completed','failed') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_migration_name` (`migration_name`),
  KEY `idx_execution_date` (`execution_date`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Log the migration completion
INSERT INTO `migration_log` (`migration_name`, `execution_date`, `status`, `notes`) VALUES
('20250109-new-product-line-implementation', NOW(), 'completed', 'Successfully implemented new Blue 10, Gold 30, Black 60 product line with volume pricing structure. Deactivated all old products. Created volume_pricing and product_pricing tables for bulk discounts and detailed pricing.');
