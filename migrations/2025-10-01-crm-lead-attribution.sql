-- CRM Lead Attribution & Usage Indexes
-- Date: 2025-10-01
-- Notes: Idempotent migration adding attribution table and supporting indexes.

START TRANSACTION;

-- Lead attribution entries (one row per acquisition event)
CREATE TABLE IF NOT EXISTS `crm_lead_attribution` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `lead_id` bigint(20) UNSIGNED DEFAULT NULL,
  `agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `method` enum('manual','nearby_leads','nearby_accounts') NOT NULL,
  `status` enum('created','revived','active_existing') NOT NULL,
  `place_id` varchar(64) DEFAULT NULL,
  `source_page` varchar(255) DEFAULT NULL,
  `context_json` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lead` (`lead_id`),
  KEY `idx_agent_created` (`agent_id`, `created_at`),
  KEY `idx_method_created` (`method`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Supporting indexes for page session analytics
ALTER TABLE `site_page_sessions`
  ADD KEY IF NOT EXISTS `idx_page_sess_user_started` (`user_id`, `started_at`),
  ADD KEY IF NOT EXISTS `idx_page_sess_path_started` (`path`(191), `started_at`),
  ADD KEY IF NOT EXISTS `idx_page_sess_user_path` (`user_id`, `path`(191));

-- Optional daily aggregates (future-proofing for dashboards)
CREATE TABLE IF NOT EXISTS `site_usage_daily` (
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `day` date NOT NULL,
  `engaged_ms` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `idle_ms` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `sessions` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`, `day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;


