-- Product Legal Management Systems
-- Date: 2025-10-03
-- Purpose: Foundational schema for legal cases, evidence, access requests, and retention policies

START TRANSACTION;

-- 1) Roles: add legal role if not present
INSERT INTO `roles` (`id`, `name`)
SELECT 6, 'legal'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `name` = 'legal');

-- 2) Legal cases master table
CREATE TABLE IF NOT EXISTS `legal_cases` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_key` VARCHAR(64) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `status` ENUM('draft','active','on_hold','closed','archived') NOT NULL DEFAULT 'draft',
  `jurisdiction` VARCHAR(120) DEFAULT NULL,
  `retention_policy` ENUM('1_year','2_year','3_year','5_year','10_year','20_year','never') NOT NULL DEFAULT 'never',
  `retention_expires_at` DATETIME DEFAULT NULL,
  `regulatory_hold` TINYINT(1) NOT NULL DEFAULT 0,
  `regulatory_notes` TEXT DEFAULT NULL,
  `summary` TEXT DEFAULT NULL,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_case_key` (`case_key`),
  KEY `idx_case_status` (`status`),
  KEY `idx_case_retention` (`retention_policy`,`regulatory_hold`),
  CONSTRAINT `legal_cases_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Case notes / updates
CREATE TABLE IF NOT EXISTS `legal_case_notes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id` BIGINT UNSIGNED NOT NULL,
  `author_id` BIGINT UNSIGNED DEFAULT NULL,
  `visibility` ENUM('team','admin','all') NOT NULL DEFAULT 'team',
  `note_body` TEXT NOT NULL,
  `pinned` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_case_notes_case` (`case_id`,`created_at`),
  KEY `idx_case_notes_visibility` (`visibility`),
  CONSTRAINT `legal_case_notes_case_fk` FOREIGN KEY (`case_id`) REFERENCES `legal_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `legal_case_notes_author_fk` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Case team assignments
CREATE TABLE IF NOT EXISTS `legal_case_team` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `role` ENUM('lead_counsel','co_counsel','paralegal','investigator','observer','admin') NOT NULL DEFAULT 'observer',
  `permissions` JSON DEFAULT NULL,
  `added_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_case_user` (`case_id`,`user_id`),
  KEY `idx_team_role` (`role`),
  CONSTRAINT `legal_case_team_case_fk` FOREIGN KEY (`case_id`) REFERENCES `legal_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `legal_case_team_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `legal_case_team_added_fk` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) Evidence metadata (file-level)
CREATE TABLE IF NOT EXISTS `legal_case_documents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id` BIGINT UNSIGNED NOT NULL,
  `storage_path` VARCHAR(500) NOT NULL,
  `display_name` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `document_type` ENUM('exhibit','analysis','correspondence','financial','media','other') NOT NULL DEFAULT 'exhibit',
  `version_label` VARCHAR(80) DEFAULT NULL,
  `hash_sha256` CHAR(64) DEFAULT NULL,
  `file_size_bytes` BIGINT UNSIGNED DEFAULT NULL,
  `uploaded_by` BIGINT UNSIGNED DEFAULT NULL,
  `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `regulatory_override` ENUM('inherit','1_year','2_year','3_year','5_year','10_year','20_year','never') NOT NULL DEFAULT 'inherit',
  `regulatory_hold` TINYINT(1) NOT NULL DEFAULT 0,
  `download_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `last_accessed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_documents_case` (`case_id`,`document_type`,`uploaded_at`),
  KEY `idx_documents_storage_path` (`storage_path`(191)),
  CONSTRAINT `legal_case_documents_case_fk` FOREIGN KEY (`case_id`) REFERENCES `legal_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `legal_case_documents_uploader_fk` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6) Evidence item details (per file + metadata)
CREATE TABLE IF NOT EXISTS `legal_case_evidence` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `case_id` BIGINT UNSIGNED NOT NULL,
  `exhibit_number` VARCHAR(40) DEFAULT NULL,
  `tags` JSON DEFAULT NULL,
  `chain_of_custody` JSON DEFAULT NULL,
  `captured_on` DATETIME DEFAULT NULL,
  `captured_by` VARCHAR(255) DEFAULT NULL,
  `location` VARCHAR(255) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_evidence_case` (`case_id`,`exhibit_number`),
  CONSTRAINT `legal_case_evidence_doc_fk` FOREIGN KEY (`document_id`) REFERENCES `legal_case_documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `legal_case_evidence_case_fk` FOREIGN KEY (`case_id`) REFERENCES `legal_cases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7) Case activity log
CREATE TABLE IF NOT EXISTS `legal_case_activity` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `activity_type` ENUM('case_created','case_updated','note_added','note_updated','team_assignment','team_removed','document_uploaded','document_updated','document_deleted','document_downloaded','retention_updated','regulatory_hold','access_requested','access_approved','access_denied','guest_request','guest_invited','login','other') NOT NULL DEFAULT 'other',
  `activity_payload` JSON DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_case` (`case_id`,`activity_type`,`created_at`),
  KEY `idx_activity_user` (`user_id`),
  CONSTRAINT `legal_case_activity_case_fk` FOREIGN KEY (`case_id`) REFERENCES `legal_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `legal_case_activity_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8) Legal access requests
CREATE TABLE IF NOT EXISTS `legal_access_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id` BIGINT UNSIGNED DEFAULT NULL,
  `requestor_name` VARCHAR(255) NOT NULL,
  `law_firm` VARCHAR(255) DEFAULT NULL,
  `email` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `desired_username` VARCHAR(120) DEFAULT NULL,
  `status` ENUM('pending','approved','denied','cancelled') NOT NULL DEFAULT 'pending',
  `notes` TEXT DEFAULT NULL,
  `reviewed_by` BIGINT UNSIGNED DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_access_request_status` (`status`,`created_at`),
  KEY `idx_access_request_case` (`case_id`),
  CONSTRAINT `legal_access_requests_case_fk` FOREIGN KEY (`case_id`) REFERENCES `legal_cases` (`id`) ON DELETE SET NULL,
  CONSTRAINT `legal_access_requests_reviewer_fk` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9) Additional invitees per access request
CREATE TABLE IF NOT EXISTS `legal_access_request_members` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `access_request_id` BIGINT UNSIGNED NOT NULL,
  `full_name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `legal_access_request_members_fk` FOREIGN KEY (`access_request_id`) REFERENCES `legal_access_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10) Access tokens for onboarding / password setup
CREATE TABLE IF NOT EXISTS `legal_access_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `case_id` BIGINT UNSIGNED DEFAULT NULL,
  `token` CHAR(64) NOT NULL,
  `purpose` ENUM('initial_access','password_reset') NOT NULL DEFAULT 'initial_access',
  `expires_at` DATETIME NOT NULL,
  `redeemed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token` (`token`),
  KEY `idx_token_user` (`user_id`,`purpose`,`expires_at`),
  CONSTRAINT `legal_access_tokens_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `legal_access_tokens_case_fk` FOREIGN KEY (`case_id`) REFERENCES `legal_cases` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Private case and document seed records excluded from public source.
COMMIT;
