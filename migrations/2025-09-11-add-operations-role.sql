-- Migration: Add Operations Role and Stock Management Features
-- Date: 2025-09-11
-- Description: Adds operations role for shipping department and enhances stock management

-- Add operations role
INSERT INTO `roles` (`id`, `name`) VALUES (5, 'operations') 
ON DUPLICATE KEY UPDATE `name` = 'operations';

-- Add new columns to products table if they don't exist
-- Using conditional column addition to prevent duplicate errors
SET @sql = '';
SELECT COUNT(*) INTO @col_exists FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'products' AND column_name = 'stock_quantity';
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `products` ADD COLUMN `stock_quantity` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `msrp`;', '');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'products' AND column_name = 'low_stock_threshold';
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `products` ADD COLUMN `low_stock_threshold` INT UNSIGNED NOT NULL DEFAULT 10 AFTER `stock_quantity`;', '');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'products' AND column_name = 'attribute_content';
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `products` ADD COLUMN `attribute_content` TEXT NULL AFTER `description`;', '');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'products' AND column_name = 'weight_grams';
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `products` ADD COLUMN `weight_grams` DECIMAL(8,2) NULL AFTER `attribute_content`;', '');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'products' AND column_name = 'dimensions';
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `products` ADD COLUMN `dimensions` VARCHAR(100) NULL AFTER `weight_grams`;', '');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Create stock_movements table for tracking inventory changes
CREATE TABLE IF NOT EXISTS `stock_movements` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `movement_type` enum('in','out','adjustment','return') NOT NULL,
  `quantity` int NOT NULL,
  `reference_type` enum('order','manual','return','damaged','expired') NOT NULL,
  `reference_id` bigint(20) UNSIGNED NULL,
  `notes` text NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_stock_movements_product` (`product_id`),
  KEY `fk_stock_movements_user` (`created_by`),
  KEY `idx_stock_movements_date` (`created_at`),
  KEY `idx_stock_movements_reference` (`reference_type`, `reference_id`),
  CONSTRAINT `fk_stock_movements_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_stock_movements_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Create shipping_labels table for order shipping management
CREATE TABLE IF NOT EXISTS `shipping_labels` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `tracking_number` varchar(100) NULL,
  `carrier` varchar(50) NOT NULL DEFAULT 'USPS',
  `service_type` varchar(50) NULL,
  `label_url` varchar(500) NULL,
  `shipping_cost` decimal(10,2) NULL,
  `weight_oz` decimal(8,2) NULL,
  `status` enum('pending','printed','shipped','delivered','exception') NOT NULL DEFAULT 'pending',
  `shipped_at` timestamp NULL,
  `delivered_at` timestamp NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_shipping_labels_order` (`order_id`),
  KEY `fk_shipping_labels_user` (`created_by`),
  KEY `idx_shipping_labels_tracking` (`tracking_number`),
  KEY `idx_shipping_labels_status` (`status`),
  CONSTRAINT `fk_shipping_labels_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shipping_labels_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- shipping_status already exists in orders table, no need to add

-- Update existing products with default stock values (only if columns exist and values are 0)
-- This will safely handle cases where the columns don't exist yet
SET @sql = '';
SELECT COUNT(*) INTO @col_exists FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'products' AND column_name = 'stock_quantity';
SET @sql = IF(@col_exists > 0, 'UPDATE `products` SET `stock_quantity` = 100 WHERE `stock_quantity` = 0;', '');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'products' AND column_name = 'low_stock_threshold';
SET @sql = IF(@col_exists > 0, 'UPDATE `products` SET `low_stock_threshold` = 10 WHERE `low_stock_threshold` = 0;', '');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Create operations access log table
CREATE TABLE IF NOT EXISTS `operations_log` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `action` varchar(100) NOT NULL,
  `target_type` varchar(50) NOT NULL,
  `target_id` bigint(20) UNSIGNED NULL,
  `details` json NULL,
  `ip_address` varchar(45) NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_operations_log_user` (`user_id`),
  KEY `idx_operations_log_action` (`action`),
  KEY `idx_operations_log_date` (`created_at`),
  CONSTRAINT `fk_operations_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
