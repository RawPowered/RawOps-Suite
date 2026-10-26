-- CRM Places Cache (Binary-safe, rerunnable)
-- Date: 2025-09-19

CREATE TABLE IF NOT EXISTS `crm_places_cache` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lead_id` BIGINT UNSIGNED DEFAULT NULL,
  `lat` DECIMAL(10,7) NOT NULL,
  `lng` DECIMAL(10,7) NOT NULL,
  `radius_m` INT NOT NULL DEFAULT 16093,
  `include_gas` TINYINT(1) NOT NULL DEFAULT 0,
  `category_key` VARCHAR(64) NOT NULL,
  `place_id` VARCHAR(128) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `vicinity` VARCHAR(255) DEFAULT NULL,
  `types` VARCHAR(255) DEFAULT NULL,
  `source` ENUM('nearby','textsearch') NOT NULL DEFAULT 'nearby',
  `place_lat` DECIMAL(10,7) DEFAULT NULL,
  `place_lng` DECIMAL(10,7) DEFAULT NULL,
  `phone` VARCHAR(40) DEFAULT NULL,
  `added_lead_id` BIGINT UNSIGNED DEFAULT NULL,
  `fetched_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_place_lead` (`lead_id`,`place_id`),
  KEY `idx_loc` (`lat`,`lng`,`radius_m`,`include_gas`,`category_key`),
  CONSTRAINT `fk_places_lead` FOREIGN KEY (`lead_id`) REFERENCES `crm_leads`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional helper view for last fetch per lead
DROP VIEW IF EXISTS `v_lead_places_last_fetch`;
CREATE VIEW `v_lead_places_last_fetch` AS
SELECT lead_id, MAX(fetched_at) AS last_fetched
FROM crm_places_cache
GROUP BY lead_id;

-- Idempotent column adds for geometry fields if running after table exists
ALTER TABLE `crm_places_cache`
  ADD COLUMN IF NOT EXISTS `place_lat` DECIMAL(10,7) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `place_lng` DECIMAL(10,7) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `phone` VARCHAR(40) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `added_lead_id` BIGINT UNSIGNED DEFAULT NULL;


