-- Separate Notes from Shoutbox Messages
-- Date: 2025-10-06
-- Purpose: Add note_type field to distinguish between formal notes and shoutbox messages

START TRANSACTION;

-- Add note_type column to legal_case_notes
ALTER TABLE `legal_case_notes`
ADD COLUMN `note_type` ENUM('note','shoutbox') NOT NULL DEFAULT 'note' AFTER `visibility`,
ADD KEY `idx_notes_type` (`note_type`, `case_id`);

-- Update existing shoutbox messages (visibility='team' and not pinned are likely shoutbox)
-- This is a best-effort migration - admin can manually correct if needed
UPDATE `legal_case_notes`
SET `note_type` = 'shoutbox'
WHERE `visibility` = 'team' AND `pinned` = 0 AND LENGTH(`note_body`) < 500;

COMMIT;












