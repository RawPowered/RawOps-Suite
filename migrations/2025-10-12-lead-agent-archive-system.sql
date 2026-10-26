-- Nearby Places Archive System
-- Allows agents to hide irrelevant nearby stores from "Leads Near Here" suggestions
-- Auto-archives for all agents when 3+ agents archive the same place

-- Create table for archived nearby places
CREATE TABLE IF NOT EXISTS `crm_nearby_place_archives` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `place_id` VARCHAR(255) DEFAULT NULL COMMENT 'Google Place ID if available',
  `place_name` VARCHAR(255) NOT NULL COMMENT 'Store name for matching',
  `place_address` VARCHAR(500) NOT NULL COMMENT 'Address for matching',
  `agent_id` BIGINT(20) UNSIGNED NOT NULL,
  `archived_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `archive_note` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_place_agent_archive` (`place_name`, `place_address`, `agent_id`),
  KEY `idx_place_name` (`place_name`),
  KEY `idx_agent_id` (`agent_id`),
  KEY `idx_place_id` (`place_id`),
  CONSTRAINT `fk_nearby_archive_agent` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

