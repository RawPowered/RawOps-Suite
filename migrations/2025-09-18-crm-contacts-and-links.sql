-- CRM Contacts and Account Links — Binary-safe migration
-- Date: 2025-09-18

START TRANSACTION;

-- 1) Contacts table (distinct from users; can map to multiple accounts)
CREATE TABLE IF NOT EXISTS `crm_contacts` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `title` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_email` (`email`),
  KEY `idx_name` (`last_name`,`first_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) M:N link between contacts and wholesale accounts
CREATE TABLE IF NOT EXISTS `account_contacts` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `wholesale_account_id` bigint(20) UNSIGNED NOT NULL,
  `contact_id` bigint(20) UNSIGNED NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `role` varchar(80) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_account_contact` (`wholesale_account_id`,`contact_id`),
  KEY `idx_contact` (`contact_id`),
  CONSTRAINT `fk_acct_contact_account` FOREIGN KEY (`wholesale_account_id`) REFERENCES `wholesale_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_acct_contact_contact` FOREIGN KEY (`contact_id`) REFERENCES `crm_contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Add optional links on crm_leads for conversion tracking
ALTER TABLE `crm_leads`
  ADD COLUMN IF NOT EXISTS `converted_to_contact_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `converted_to_account_id`,
  ADD KEY IF NOT EXISTS `idx_converted_contact` (`converted_to_contact_id`);

-- 4) Safe FK to contacts if table exists
ALTER TABLE `crm_leads`
  ADD CONSTRAINT `fk_lead_converted_contact` FOREIGN KEY (`converted_to_contact_id`) REFERENCES `crm_contacts` (`id`) ON DELETE SET NULL;

-- 5) Helper view to quickly see lead→contact→account
CREATE OR REPLACE VIEW `crm_lead_contact_account_view` AS
SELECT 
  l.id AS lead_id,
  CONCAT(l.first_name,' ',l.last_name) AS lead_name,
  l.email AS lead_email,
  l.converted_to_contact_id,
  l.converted_to_account_id,
  c.first_name AS contact_first_name,
  c.last_name AS contact_last_name,
  c.email AS contact_email,
  wa.business_name
FROM crm_leads l
LEFT JOIN crm_contacts c ON c.id = l.converted_to_contact_id
LEFT JOIN wholesale_accounts wa ON wa.id = l.converted_to_account_id;

COMMIT;


