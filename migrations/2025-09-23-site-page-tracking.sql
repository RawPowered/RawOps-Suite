-- Site Page Tracking & Interaction Events
-- Date: 2025-09-23
-- Notes: Idempotent; follows SQL-FORMATTING-RULES.md. Tracks per-page sessions and interactions.

START TRANSACTION;

-- Page sessions (one row per client page view)
CREATE TABLE IF NOT EXISTS `site_page_sessions` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_page_id` varchar(64) NOT NULL,
  `session_id` varchar(64) NOT NULL,
  `user_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `path` varchar(500) NOT NULL,
  `page_title` varchar(255) DEFAULT NULL,
  `referrer` varchar(500) DEFAULT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_heartbeat` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `total_ms` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `engaged_ms` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `idle_ms` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `max_scroll` tinyint(3) UNSIGNED NOT NULL DEFAULT 0, -- 0-100
  `interactions_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `ended` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_client_page_id` (`client_page_id`),
  KEY `idx_page_sess_last_heartbeat` (`last_heartbeat`),
  KEY `idx_page_sess_path_last` (`path`(191), `last_heartbeat`),
  KEY `idx_page_sess_user_last` (`user_id`, `last_heartbeat`),
  KEY `idx_page_sess_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Interaction events (clicks, submits, etc.)
CREATE TABLE IF NOT EXISTS `site_interactions` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_page_id` varchar(64) DEFAULT NULL,
  `page_session_id` bigint(20) UNSIGNED DEFAULT NULL,
  `session_id` varchar(64) NOT NULL,
  `user_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `path` varchar(500) DEFAULT NULL,
  `event_type` varchar(40) NOT NULL,
  `target_tag` varchar(32) DEFAULT NULL,
  `target_id` varchar(80) DEFAULT NULL,
  `target_class` varchar(120) DEFAULT NULL,
  `target_text` varchar(160) DEFAULT NULL,
  `target_href` varchar(255) DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_site_int_created` (`created_at`),
  KEY `idx_site_int_path_created` (`path`(191), `created_at`),
  KEY `idx_site_int_page` (`page_session_id`),
  KEY `idx_site_int_type_created` (`event_type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;


