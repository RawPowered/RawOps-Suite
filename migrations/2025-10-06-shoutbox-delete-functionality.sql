-- Shoutbox Message Deletion Functionality
-- Date: 2025-10-06
-- Purpose: Add soft delete capability to legal_case_notes for shoutbox messages
-- Rules: Legal users can delete their own messages within 1 hour, admins can delete any message

START TRANSACTION;

-- Add soft delete columns to legal_case_notes
ALTER TABLE `legal_case_notes`
ADD COLUMN `deleted_at` DATETIME DEFAULT NULL AFTER `created_at`,
ADD COLUMN `deleted_by` BIGINT UNSIGNED DEFAULT NULL AFTER `deleted_at`,
ADD COLUMN `deletion_reason` VARCHAR(500) DEFAULT NULL AFTER `deleted_by`,
ADD KEY `idx_notes_deleted` (`deleted_at`),
ADD CONSTRAINT `legal_case_notes_deleted_by_fk` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

COMMIT;












