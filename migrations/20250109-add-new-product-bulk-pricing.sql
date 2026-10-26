-- Migration: Add Bulk Pricing for New Product Line
-- Date: 2025-01-09
-- Description: Add bulk pricing tiers for new products (BLUE-10-32, GOLD-30-32, BLACK-60-32)
-- Pricing structure: 1-5 (0% discount), 6-10 (8% discount), 15+ (12% discount)

-- Insert bulk pricing for BLUE-10-32
INSERT INTO `bulk_pricing` (`sku`, `quantity_tier_name`, `quantity_min`, `discount_percent`, `unit_price`, `active`, `created_at`, `updated_at`) VALUES
('BLUE-10-32', 'X1', 1, 0.00, 55.05, 1, NOW(), NOW()),
('BLUE-10-32', 'X5', 5, 0.00, 55.05, 1, NOW(), NOW()),
('BLUE-10-32', 'X10', 10, 8.00, 50.64, 1, NOW(), NOW()),
('BLUE-10-32', 'X15', 15, 12.00, 48.44, 1, NOW(), NOW()),
('BLUE-10-32', 'X20', 20, 12.00, 48.44, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `discount_percent` = VALUES(`discount_percent`),
  `unit_price` = VALUES(`unit_price`),
  `active` = VALUES(`active`),
  `updated_at` = NOW();

-- Insert bulk pricing for GOLD-30-32
INSERT INTO `bulk_pricing` (`sku`, `quantity_tier_name`, `quantity_min`, `discount_percent`, `unit_price`, `active`, `created_at`, `updated_at`) VALUES
('GOLD-30-32', 'X1', 1, 0.00, 90.15, 1, NOW(), NOW()),
('GOLD-30-32', 'X5', 5, 0.00, 90.15, 1, NOW(), NOW()),
('GOLD-30-32', 'X10', 10, 8.00, 82.93, 1, NOW(), NOW()),
('GOLD-30-32', 'X15', 15, 12.00, 79.33, 1, NOW(), NOW()),
('GOLD-30-32', 'X20', 20, 12.00, 79.33, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `discount_percent` = VALUES(`discount_percent`),
  `unit_price` = VALUES(`unit_price`),
  `active` = VALUES(`active`),
  `updated_at` = NOW();

-- Insert bulk pricing for BLACK-60-32
INSERT INTO `bulk_pricing` (`sku`, `quantity_tier_name`, `quantity_min`, `discount_percent`, `unit_price`, `active`, `created_at`, `updated_at`) VALUES
('BLACK-60-32', 'X1', 1, 0.00, 142.79, 1, NOW(), NOW()),
('BLACK-60-32', 'X5', 5, 0.00, 142.79, 1, NOW(), NOW()),
('BLACK-60-32', 'X10', 10, 8.00, 131.37, 1, NOW(), NOW()),
('BLACK-60-32', 'X15', 15, 12.00, 125.66, 1, NOW(), NOW()),
('BLACK-60-32', 'X20', 20, 12.00, 125.66, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `discount_percent` = VALUES(`discount_percent`),
  `unit_price` = VALUES(`unit_price`),
  `active` = VALUES(`active`),
  `updated_at` = NOW();

-- Log the migration completion
INSERT INTO `migration_log` (`migration_name`, `execution_date`, `status`, `notes`) VALUES
('20250109-add-new-product-bulk-pricing', NOW(), 'completed', 'Successfully added bulk pricing tiers for new product line. Added 5 pricing tiers per product (X1, X5, X10, X15, X20) with correct discounts and prices for BLUE-10-32, GOLD-30-32, and BLACK-60-32.');


