-- Create payment_methods table for checkout functionality
-- Author: Portfolio Developer
-- Date: 2025-09-10

CREATE TABLE IF NOT EXISTS `payment_methods` (
    `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` bigint(20) UNSIGNED NOT NULL,
    `wholesale_account_id` bigint(20) UNSIGNED NOT NULL,
    `type` enum('credit_card','ach','wire','check','net_terms') NOT NULL,
    `last_four` varchar(4) DEFAULT NULL,
    `card_brand` varchar(50) DEFAULT NULL,
    `bank_name` varchar(100) DEFAULT NULL,
    `is_default` tinyint(1) DEFAULT 0,
    `token` text DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_wholesale_account_id` (`wholesale_account_id`),
    KEY `idx_is_default` (`is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Add foreign key constraints (only if tables exist)
ALTER TABLE `payment_methods`
    ADD CONSTRAINT `fk_payment_methods_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    ADD CONSTRAINT `fk_payment_methods_wholesale` FOREIGN KEY (`wholesale_account_id`) REFERENCES `wholesale_accounts` (`id`) ON DELETE CASCADE;
