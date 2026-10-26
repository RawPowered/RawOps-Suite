-- Printify API Integration Schema
-- Date: 2025-10-26
-- Description: Complete integration for Printify print-on-demand service

-- ============================================================================
-- CORE TABLES
-- ============================================================================

-- Printify Products Catalog
CREATE TABLE IF NOT EXISTS `printify_products` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `printify_product_id` VARCHAR(50) NOT NULL COMMENT 'Unique Printify product ID',
  `printify_shop_id` VARCHAR(50) NOT NULL COMMENT 'Printify shop ID',
  `printify_blueprint_id` VARCHAR(50) NULL COMMENT 'Blueprint/product type ID',
  `printify_print_provider_id` VARCHAR(50) NULL COMMENT 'Print provider ID',
  `title` VARCHAR(500) NOT NULL,
  `description` TEXT NULL,
  `tags` JSON NULL COMMENT 'Product tags array',
  `variants` JSON NULL COMMENT 'Product variants with pricing',
  `images` JSON NULL COMMENT 'Product images array',
  `is_published` TINYINT(1) NOT NULL DEFAULT 0,
  `is_locked` TINYINT(1) NOT NULL DEFAULT 0,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `raw_data` JSON NULL COMMENT 'Full Printify product response',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `last_synced_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_printify_product_id` (`printify_product_id`),
  KEY `idx_shop_id` (`printify_shop_id`),
  KEY `idx_blueprint` (`printify_blueprint_id`),
  KEY `idx_published` (`is_published`),
  KEY `idx_synced` (`last_synced_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Printify product catalog cache';

-- Product Mapping (Links local products to Printify)
CREATE TABLE IF NOT EXISTS `printify_product_mapping` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT(20) UNSIGNED NOT NULL COMMENT 'Local products.id',
  `printify_product_id` BIGINT(20) UNSIGNED NOT NULL COMMENT 'printify_products.id',
  `printify_variant_id` VARCHAR(50) NULL COMMENT 'Specific variant ID if mapped to single variant',
  `sku_override` VARCHAR(100) NULL COMMENT 'Override SKU for Printify (if different from local)',
  `sync_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `sync_direction` ENUM('import', 'export', 'both') NOT NULL DEFAULT 'both',
  `auto_publish` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Auto-publish to Printify stores',
  `price_sync_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `inventory_sync_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `last_synced_at` TIMESTAMP NULL DEFAULT NULL,
  `sync_status` ENUM('pending', 'syncing', 'success', 'error') DEFAULT 'pending',
  `sync_error` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_product_printify` (`product_id`, `printify_product_id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_printify_product_id` (`printify_product_id`),
  KEY `idx_sync_enabled` (`sync_enabled`),
  KEY `idx_sync_status` (`sync_status`),
  CONSTRAINT `fk_ppm_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ppm_printify_product` FOREIGN KEY (`printify_product_id`) REFERENCES `printify_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Maps local products to Printify products';

-- Printify Orders
CREATE TABLE IF NOT EXISTS `printify_orders` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT(20) UNSIGNED NOT NULL COMMENT 'Local orders.id',
  `printify_order_id` VARCHAR(50) NULL COMMENT 'Printify order ID (set after submission)',
  `printify_shop_id` VARCHAR(50) NOT NULL,
  `status` VARCHAR(50) NULL COMMENT 'Printify order status',
  `tracking_number` VARCHAR(255) NULL,
  `tracking_url` VARCHAR(500) NULL,
  `carrier` VARCHAR(100) NULL,
  `shipping_method` VARCHAR(100) NULL,
  `shipping_cost` DECIMAL(10,2) NULL,
  `production_cost` DECIMAL(10,2) NULL COMMENT 'Printify production cost',
  `submitted_at` TIMESTAMP NULL DEFAULT NULL,
  `in_production_at` TIMESTAMP NULL DEFAULT NULL,
  `shipped_at` TIMESTAMP NULL DEFAULT NULL,
  `delivered_at` TIMESTAMP NULL DEFAULT NULL,
  `cancelled_at` TIMESTAMP NULL DEFAULT NULL,
  `submission_error` TEXT NULL,
  `metadata` JSON NULL COMMENT 'Full Printify order response',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_printify_order_id` (`printify_order_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_status` (`status`),
  KEY `idx_submitted` (`submitted_at`),
  KEY `idx_shipped` (`shipped_at`),
  CONSTRAINT `fk_po_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Printify order tracking';

-- Webhook Events
CREATE TABLE IF NOT EXISTS `printify_webhooks` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `webhook_id` VARCHAR(50) NULL COMMENT 'Printify webhook registration ID',
  `event_type` VARCHAR(100) NOT NULL COMMENT 'product:created, order:updated, etc',
  `resource_type` VARCHAR(50) NOT NULL COMMENT 'product, order, shipment',
  `resource_id` VARCHAR(50) NULL COMMENT 'Printify resource ID',
  `shop_id` VARCHAR(50) NULL,
  `payload` JSON NOT NULL COMMENT 'Full webhook payload',
  `processed` TINYINT(1) NOT NULL DEFAULT 0,
  `processed_at` TIMESTAMP NULL DEFAULT NULL,
  `processing_error` TEXT NULL,
  `retry_count` INT(11) NOT NULL DEFAULT 0,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(500) NULL,
  `signature` VARCHAR(500) NULL COMMENT 'Webhook signature for validation',
  `received_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_event_type` (`event_type`),
  KEY `idx_resource` (`resource_type`, `resource_id`),
  KEY `idx_processed` (`processed`),
  KEY `idx_received` (`received_at`),
  KEY `idx_shop_id` (`shop_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Printify webhook event log';

-- Sync Operation Log
CREATE TABLE IF NOT EXISTS `printify_sync_log` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `action_type` VARCHAR(50) NOT NULL COMMENT 'import, export, sync',
  `resource_type` VARCHAR(50) NOT NULL COMMENT 'product, order, inventory',
  `resource_id` VARCHAR(50) NULL COMMENT 'Local or Printify ID',
  `direction` ENUM('import', 'export', 'both') NOT NULL,
  `status` ENUM('pending', 'running', 'success', 'error') NOT NULL DEFAULT 'pending',
  `records_processed` INT(11) NOT NULL DEFAULT 0,
  `records_failed` INT(11) NOT NULL DEFAULT 0,
  `error_message` TEXT NULL,
  `details` JSON NULL COMMENT 'Additional sync details',
  `user_id` BIGINT(20) UNSIGNED NULL COMMENT 'User who triggered sync',
  `started_at` TIMESTAMP NULL DEFAULT NULL,
  `completed_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_action_type` (`action_type`),
  KEY `idx_resource_type` (`resource_type`),
  KEY `idx_status` (`status`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit trail for sync operations';

-- ============================================================================
-- UPDATE EXISTING PRODUCTS TABLE
-- ============================================================================

-- Add Printify fields to products table
ALTER TABLE `products` 
  ADD COLUMN IF NOT EXISTS `printify_product_id` VARCHAR(50) NULL COMMENT 'Printify product ID' AFTER `demo_type_id`,
  ADD COLUMN IF NOT EXISTS `printify_variant_id` VARCHAR(50) NULL COMMENT 'Printify variant ID' AFTER `printify_product_id`,
  ADD COLUMN IF NOT EXISTS `printify_sync_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Enable sync with Printify' AFTER `printify_variant_id`,
  ADD COLUMN IF NOT EXISTS `printify_last_synced` TIMESTAMP NULL DEFAULT NULL COMMENT 'Last sync timestamp' AFTER `printify_sync_enabled`,
  ADD COLUMN IF NOT EXISTS `fulfillment_method` ENUM('internal', 'printify', 'both') DEFAULT 'internal' COMMENT 'How product is fulfilled' AFTER `printify_last_synced`;

-- Add indexes for Printify fields
ALTER TABLE `products`
  ADD INDEX IF NOT EXISTS `idx_printify_product_id` (`printify_product_id`),
  ADD INDEX IF NOT EXISTS `idx_printify_sync` (`printify_sync_enabled`),
  ADD INDEX IF NOT EXISTS `idx_fulfillment_method` (`fulfillment_method`);

-- ============================================================================
-- WEBHOOK REGISTRATION TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `printify_webhook_registrations` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `printify_webhook_id` VARCHAR(50) NOT NULL COMMENT 'Printify webhook registration ID',
  `shop_id` VARCHAR(50) NOT NULL,
  `topic` VARCHAR(100) NOT NULL COMMENT 'Webhook topic/event type',
  `url` VARCHAR(500) NOT NULL COMMENT 'Our endpoint URL',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `registered_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_verified_at` TIMESTAMP NULL DEFAULT NULL,
  `deactivated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_printify_webhook_id` (`printify_webhook_id`),
  KEY `idx_shop_topic` (`shop_id`, `topic`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registered Printify webhooks';

-- ============================================================================
-- INDEXES AND OPTIMIZATIONS
-- ============================================================================

-- Composite indexes for common queries
ALTER TABLE `printify_product_mapping`
  ADD INDEX IF NOT EXISTS `idx_sync_pending` (`sync_enabled`, `sync_status`, `last_synced_at`);

ALTER TABLE `printify_webhooks`
  ADD INDEX IF NOT EXISTS `idx_unprocessed` (`processed`, `received_at`);

-- ============================================================================
-- INITIAL DATA / CONFIGURATION
-- ============================================================================

-- Insert default sync log entry
INSERT IGNORE INTO `printify_sync_log` 
  (`action_type`, `resource_type`, `status`, `details`, `completed_at`)
VALUES 
  ('setup', 'system', 'success', '{"message": "Printify integration schema initialized"}', NOW());

-- ============================================================================
-- CLEANUP (Optional - for development reset)
-- ============================================================================

-- Uncomment these lines only if you need to completely reset the Printify integration:
-- DROP TABLE IF EXISTS `printify_webhook_registrations`;
-- DROP TABLE IF EXISTS `printify_sync_log`;
-- DROP TABLE IF EXISTS `printify_webhooks`;
-- DROP TABLE IF EXISTS `printify_orders`;
-- DROP TABLE IF EXISTS `printify_product_mapping`;
-- DROP TABLE IF EXISTS `printify_products`;
-- ALTER TABLE `products` 
--   DROP COLUMN IF EXISTS `printify_product_id`,
--   DROP COLUMN IF EXISTS `printify_variant_id`,
--   DROP COLUMN IF EXISTS `printify_sync_enabled`,
--   DROP COLUMN IF EXISTS `printify_last_synced`,
--   DROP COLUMN IF EXISTS `fulfillment_method`;

