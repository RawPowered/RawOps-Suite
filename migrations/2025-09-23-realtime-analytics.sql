-- Realtime Analytics Foundations (Site-wide, non-LMS)
-- Date: 2025-09-23
-- Notes: Idempotent, binary-safe per SQL-FORMATTING-RULES.md

START TRANSACTION;

-- 1) Active sessions across the entire site (not LMS)
CREATE TABLE IF NOT EXISTS `site_active_sessions` (
  `session_id` varchar(64) NOT NULL,
  `user_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `role` varchar(40) DEFAULT NULL,
  `is_guest` tinyint(1) NOT NULL DEFAULT 1,
  `is_bot` tinyint(1) NOT NULL DEFAULT 0,
  `current_path` varchar(500) NOT NULL,
  `referrer` varchar(500) DEFAULT NULL,
  `first_seen` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_heartbeat` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_idle` tinyint(1) NOT NULL DEFAULT 0,
  `activity_score` int(11) NOT NULL DEFAULT 0,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`session_id`),
  KEY `idx_sess_last_heartbeat` (`last_heartbeat`),
  KEY `idx_sess_user` (`user_id`),
  KEY `idx_sess_is_bot` (`is_bot`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Authentication and access events
CREATE TABLE IF NOT EXISTS `auth_events` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `event_type` enum('login','logout','forgot_password','login_failed','password_reset') NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_auth_events_type_created` (`event_type`, `created_at`),
  KEY `idx_auth_events_user_created` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Crawler pulses (bot activity)
CREATE TABLE IF NOT EXISTS `crawler_pulses` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_name` varchar(100) NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `path` varchar(500) DEFAULT NULL,
  `referer` varchar(500) DEFAULT NULL,
  `bucket_minute` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_bot_fingerprint` (`bot_name`, `ip_address`, `path`, `bucket_minute`),
  KEY `idx_crawler_minute` (`bucket_minute`),
  KEY `idx_crawler_bot_created` (`bot_name`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;


