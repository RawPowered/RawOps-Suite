-- Printify Image Sync and Auto-Publish Enhancement
-- Date: 2025-10-26
-- Description: Add image sync and publish tracking functionality

-- ============================================================================
-- IMAGE TRACKING
-- ============================================================================

CREATE TABLE IF NOT EXISTS `printify_image_uploads` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT(20) UNSIGNED NULL COMMENT 'Local product ID',
  `printify_upload_id` VARCHAR(50) NOT NULL COMMENT 'Printify upload ID',
  `printify_product_id` VARCHAR(50) NULL COMMENT 'Associated Printify product',
  `local_image_url` VARCHAR(500) NULL COMMENT 'Original local image URL',
  `printify_image_url` VARCHAR(500) NULL COMMENT 'Printify CDN URL',
  `filename` VARCHAR(255) NOT NULL,
  `file_size` INT(11) NULL COMMENT 'File size in bytes',
  `mime_type` VARCHAR(100) NULL,
  `width` INT(11) NULL,
  `height` INT(11) NULL,
  `upload_status` ENUM('pending', 'uploading', 'success', 'error') DEFAULT 'pending',
  `upload_error` TEXT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Primary product image',
  `position` INT(11) NOT NULL DEFAULT 0 COMMENT 'Image display order',
  `uploaded_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_printify_upload_id` (`printify_upload_id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_printify_product_id` (`printify_product_id`),
  KEY `idx_upload_status` (`upload_status`),
  CONSTRAINT `fk_piu_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks image uploads to Printify';

-- ============================================================================
-- PUBLISH TRACKING
-- ============================================================================

CREATE TABLE IF NOT EXISTS `printify_publish_stores` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_name` VARCHAR(100) NOT NULL COMMENT 'Store platform name (shopify, etsy, etc)',
  `store_id` VARCHAR(50) NOT NULL COMMENT 'Printify store ID',
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `auto_publish` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Auto-publish new products',
  `publish_on_update` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Auto-publish on product updates',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_store_id` (`store_id`),
  KEY `idx_auto_publish` (`auto_publish`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Connected stores for auto-publish';

CREATE TABLE IF NOT EXISTS `printify_publish_log` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `printify_product_id` VARCHAR(50) NOT NULL COMMENT 'Printify product ID',
  `product_id` BIGINT(20) UNSIGNED NULL COMMENT 'Local product ID',
  `store_id` VARCHAR(50) NOT NULL COMMENT 'Target store ID',
  `store_name` VARCHAR(100) NULL,
  `publish_status` ENUM('pending', 'publishing', 'succeeded', 'failed') NOT NULL DEFAULT 'pending',
  `external_id` VARCHAR(100) NULL COMMENT 'ID in external store (Shopify product ID, etc)',
  `external_handle` VARCHAR(255) NULL COMMENT 'Store-specific handle/slug',
  `publish_error` TEXT NULL,
  `retry_count` INT(11) NOT NULL DEFAULT 0,
  `metadata` JSON NULL COMMENT 'Store-specific publish data',
  `published_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_printify_product` (`printify_product_id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_store` (`store_id`),
  KEY `idx_status` (`publish_status`),
  KEY `idx_published` (`published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Product publish history to stores';

-- ============================================================================
-- UPDATE EXISTING TABLES
-- ============================================================================

-- Add image tracking to products table
ALTER TABLE `products` 
  ADD COLUMN IF NOT EXISTS `printify_images_synced` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Images uploaded to Printify' AFTER `printify_last_synced`,
  ADD COLUMN IF NOT EXISTS `printify_publish_status` VARCHAR(50) NULL COMMENT 'Latest publish status' AFTER `printify_images_synced`,
  ADD COLUMN IF NOT EXISTS `printify_published_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Last successful publish' AFTER `printify_publish_status`;

-- Add image support to printify_products
ALTER TABLE `printify_products`
  ADD COLUMN IF NOT EXISTS `primary_image_url` VARCHAR(500) NULL COMMENT 'Primary product image URL' AFTER `images`;

-- Add indexes
ALTER TABLE `products`
  ADD INDEX IF NOT EXISTS `idx_printify_images_synced` (`printify_images_synced`),
  ADD INDEX IF NOT EXISTS `idx_printify_publish_status` (`printify_publish_status`);

-- ============================================================================
-- INITIAL DATA
-- ============================================================================

-- Log schema update
INSERT IGNORE INTO `printify_sync_log` 
  (`action_type`, `resource_type`, `status`, `details`, `completed_at`)
VALUES 
  ('setup', 'images', 'success', '{"message": "Image sync and publish tracking schema initialized"}', NOW());

