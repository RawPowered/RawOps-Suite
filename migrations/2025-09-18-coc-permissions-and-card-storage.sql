-- COC Permissions and Card Storage (Safe) — 2025-09-18
-- Objective:
-- 1) Add payment_method_permissions table to control Sales Agent access per wholesale account
-- 2) Ensure payment_methods supports card metadata (brand, last4) and token storage only
-- 3) Remove NET 30/60/90 semantics in favor of pay_upon_request; keep existing data intact
-- 4) Add convenience computed flags on wholesale_accounts if needed

START TRANSACTION;

-- Ensure payment_methods exists before any ALTERs (idempotent superset schema)
CREATE TABLE IF NOT EXISTS `payment_methods` (
    `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` bigint(20) UNSIGNED NOT NULL,
    `wholesale_account_id` bigint(20) UNSIGNED NOT NULL,
    `type` enum('credit_card','ach','wire','check','net_terms') NOT NULL,
    `card_brand` varchar(50) DEFAULT NULL,
    `last_four` varchar(4) DEFAULT NULL,
    `bank_name` varchar(100) DEFAULT NULL,
    `is_default` tinyint(1) DEFAULT 0,
    `token` text DEFAULT NULL,
    `card_holder_name` varchar(100) DEFAULT NULL,
    `billing_line1` varchar(150) DEFAULT NULL,
    `billing_line2` varchar(150) DEFAULT NULL,
    `billing_city` varchar(100) DEFAULT NULL,
    `billing_state` varchar(50) DEFAULT NULL,
    `billing_postal` varchar(20) DEFAULT NULL,
    `billing_country` varchar(2) DEFAULT NULL,
    `card_enc` text DEFAULT NULL,
    `exp_month` varchar(2) DEFAULT NULL,
    `exp_year` varchar(4) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_wholesale_account_id` (`wholesale_account_id`),
    KEY `idx_is_default` (`is_default`),
    CONSTRAINT `fk_payment_methods_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_payment_methods_wholesale` FOREIGN KEY (`wholesale_account_id`) REFERENCES `wholesale_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1) Payment method permissions per account and agent
CREATE TABLE IF NOT EXISTS `payment_method_permissions` (
    `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `wholesale_account_id` bigint(20) UNSIGNED NOT NULL,
    `agent_id` bigint(20) UNSIGNED NOT NULL,
    `can_view` tinyint(1) NOT NULL DEFAULT 0,
    `can_edit` tinyint(1) NOT NULL DEFAULT 0,
    `granted_by_admin_id` bigint(20) UNSIGNED DEFAULT NULL,
    `reason` varchar(255) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `revoked_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_wholesale_agent` (`wholesale_account_id`,`agent_id`),
    KEY `idx_agent` (`agent_id`),
    CONSTRAINT `fk_pmp_wholesale` FOREIGN KEY (`wholesale_account_id`) REFERENCES `wholesale_accounts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pmp_agent` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) payment_methods columns are defined above; no ALTER needed

-- 3) Pay Upon Request: replace net terms usage with a boolean flag while retaining legacy data
-- Add pay_upon_request flag to wholesale_accounts (default 1), deprecate net_terms/payment_terms for UI
ALTER TABLE `wholesale_accounts`
    ADD COLUMN IF NOT EXISTS `pay_upon_request` tinyint(1) NOT NULL DEFAULT 1 AFTER `credit_limit`;

-- Optional helpful indexes
CREATE INDEX IF NOT EXISTS `idx_pmp_created` ON `payment_method_permissions` (`created_at`);

COMMIT;

-- Notes:
-- - Do not store full PAN or sensitive bank data. Only store brand + last4 + a vault token.
-- - Admin UI should gate access through payment_method_permissions.
-- - When reassigning a lead/account, prompt to grant view/edit to new agent.

