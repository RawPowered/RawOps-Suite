-- Migration: Fix Line-Item Commission System
-- Date: 2025-09-11
-- Purpose: Implement per-line-item commission calculations with correct rates

-- Add commission tracking columns to order_items table
ALTER TABLE `order_items` 
ADD COLUMN `commission_rate` decimal(5,2) DEFAULT NULL COMMENT 'Sales agent commission rate for this line item';

ALTER TABLE `order_items` 
ADD COLUMN `commission_amount` decimal(10,2) DEFAULT NULL COMMENT 'Sales agent commission amount for this line item';

ALTER TABLE `order_items` 
ADD COLUMN `developer_commission_amount` decimal(10,2) DEFAULT NULL COMMENT 'Developer commission amount for this line item';

-- Create line_item_commissions table for detailed tracking
CREATE TABLE IF NOT EXISTS `line_item_commissions` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `order_item_id` bigint(20) UNSIGNED NOT NULL,
  `sales_agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `commission_type` enum('sales_agent','developer') NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `line_total` decimal(10,2) NOT NULL,
  `commission_rate` decimal(5,2) NOT NULL,
  `commission_amount` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_line_commissions_order` (`order_id`),
  KEY `idx_line_commissions_item` (`order_item_id`),
  KEY `idx_line_commissions_agent` (`sales_agent_id`),
  KEY `idx_line_commissions_type` (`commission_type`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Update commission settings to match new requirements
UPDATE `commission_settings` SET `setting_value` = '5.00', `description` = 'Developer commission percentage (5% of total invoice)' WHERE `setting_key` = 'developer_commission';

-- Update commission tiers to reflect correct quantity-based rates
UPDATE `commission_tiers` SET `commission_rate` = 20.00, `description` = 'Single item orders (1-4 items) - 20% commission rate' WHERE `name` = 'Single Item (1-4)';
UPDATE `commission_tiers` SET `commission_rate` = 20.00, `description` = 'Small volume orders (5-9 items) - 20% commission rate' WHERE `name` = 'Small Volume (5-9)';
UPDATE `commission_tiers` SET `commission_rate` = 15.00, `description` = 'Medium volume orders (10-14 items) - 15% commission rate' WHERE `name` = 'Medium Volume (10-14)';
UPDATE `commission_tiers` SET `commission_rate` = 12.00, `description` = 'Large volume orders (15-19 items) - 12% commission rate' WHERE `name` = 'Large Volume (15-19)';
UPDATE `commission_tiers` SET `commission_rate` = 10.00, `description` = 'Bulk volume orders (20+ items) - 10% commission rate' WHERE `name` = 'Bulk Volume (20+)';

-- Add foreign key constraints
ALTER TABLE `line_item_commissions` 
ADD CONSTRAINT `fk_line_commissions_order` 
FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;

ALTER TABLE `line_item_commissions` 
ADD CONSTRAINT `fk_line_commissions_item` 
FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE CASCADE;

ALTER TABLE `line_item_commissions` 
ADD CONSTRAINT `fk_line_commissions_agent` 
FOREIGN KEY (`sales_agent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- Add table comment
ALTER TABLE `line_item_commissions` COMMENT = 'Tracks commission calculations per order line item';

-- Create view for commission reporting
CREATE OR REPLACE VIEW `commission_summary` AS
SELECT 
    o.id as order_id,
    o.order_number,
    o.total as order_total,
    u.first_name,
    u.last_name,
    u.email,
    SUM(CASE WHEN lic.commission_type = 'sales_agent' THEN lic.commission_amount ELSE 0 END) as total_sales_commission,
    SUM(CASE WHEN lic.commission_type = 'developer' THEN lic.commission_amount ELSE 0 END) as total_developer_commission,
    COUNT(lic.id) as line_items_count,
    o.created_at
FROM orders o
LEFT JOIN line_item_commissions lic ON lic.order_id = o.id
LEFT JOIN users u ON u.id = lic.sales_agent_id
WHERE lic.commission_type = 'sales_agent' OR lic.commission_type IS NULL
GROUP BY o.id, o.order_number, o.total, u.first_name, u.last_name, u.email, o.created_at;
