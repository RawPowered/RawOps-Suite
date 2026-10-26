-- Legal Portal Session Tracking
-- Date: 2025-10-07
-- Purpose: Record active/idle duration for legal portal usage and support compliance logging

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `legal_portal_sessions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `session_token` VARCHAR(64) NOT NULL,
    `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_heartbeat_at` DATETIME NULL DEFAULT NULL,
    `ended_at` DATETIME NULL DEFAULT NULL,
    `ended_reason` VARCHAR(48) NULL DEFAULT NULL,
    `total_active_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
    `total_idle_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
    `total_session_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
    `idle_grace_seconds` SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    `idle_timeout_seconds` SMALLINT UNSIGNED NOT NULL DEFAULT 300,
    `last_page_url` VARCHAR(500) NULL DEFAULT NULL,
    `user_agent` VARCHAR(255) NULL DEFAULT NULL,
    `ip_address` VARCHAR(45) NULL DEFAULT NULL,
    `is_idle` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_legal_portal_session_token` (`session_token`),
    KEY `idx_legal_portal_sessions_user` (`user_id`, `started_at`),
    CONSTRAINT `legal_portal_sessions_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;







