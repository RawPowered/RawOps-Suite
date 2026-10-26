-- Migration to enhance the ai_chat_logs table for better tracking and admin review.
-- Executed: 2025-09-10

-- Drop the old table if it exists to ensure a clean slate with the new structure
DROP TABLE IF EXISTS `ai_chat_logs`;

-- Recreate the table with a more robust structure
CREATE TABLE `ai_chat_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `session_id` VARCHAR(255) NOT NULL COMMENT 'Links all turns of a single conversation',
  `user_id` INT NULL COMMENT 'The logged-in user, if available',
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `user_message` TEXT NULL,
  `ai_response` TEXT NULL,
  `prompt_context` TEXT NULL,
  `action_taken` VARCHAR(255) NULL COMMENT 'e.g., add_to_cart',
  `action_parameters` TEXT NULL,
  `feedback` TINYINT NULL COMMENT '1 for good, -1 for bad',
  `corrected_response` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `session_id_idx` (`session_id`),
  INDEX `user_id_idx` (`user_id`),
  INDEX `created_at_idx` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
