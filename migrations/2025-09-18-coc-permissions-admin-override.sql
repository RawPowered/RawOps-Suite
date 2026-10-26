-- COC Admin Override + Encrypted Card Storage — 2025-09-18

START TRANSACTION;

-- Agent-wide override: allow full view/edit of payment for any assigned accounts
CREATE TABLE IF NOT EXISTS `payment_agent_overrides` (
    `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `agent_id` bigint(20) UNSIGNED NOT NULL,
    `can_view_full_all_assigned` tinyint(1) NOT NULL DEFAULT 0,
    `can_edit_full_all_assigned` tinyint(1) NOT NULL DEFAULT 0,
    `granted_by_admin_id` bigint(20) UNSIGNED DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_agent_override` (`agent_id`),
    CONSTRAINT `fk_pao_agent` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Encrypted full PAN storage for cards (no CVV stored)
ALTER TABLE `payment_methods`
    ADD COLUMN IF NOT EXISTS `card_holder_name` varchar(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `billing_line1` varchar(150) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `billing_line2` varchar(150) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `billing_city` varchar(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `billing_state` varchar(50) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `billing_postal` varchar(20) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `billing_country` varchar(2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `card_enc` text DEFAULT NULL COMMENT 'base64-encoded encrypted PAN (AES)',
    ADD COLUMN IF NOT EXISTS `exp_month` varchar(2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `exp_year` varchar(4) DEFAULT NULL;

COMMIT;

-- Notes:
-- - Full PAN is stored in `card_enc` encrypted; do NOT store CVV.
-- - Admins always have full visibility.
-- - Agents require either per-account permission (payment_method_permissions.can_view) or an agent-wide override.

