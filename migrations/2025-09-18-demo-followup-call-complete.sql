-- Demo follow-up call completion linkage (one-time) — 2025-09-18

START TRANSACTION;

ALTER TABLE `crm_demo_dropoffs`
    ADD COLUMN IF NOT EXISTS `follow_up_completed_call_log_id` bigint(20) UNSIGNED NULL AFTER `follow_up_status`,
    ADD KEY IF NOT EXISTS `idx_followup_call_log` (`follow_up_completed_call_log_id`);

-- Add FK if table crm_call_logs exists (safe to ignore if not)
ALTER TABLE `crm_demo_dropoffs`
    ADD CONSTRAINT `fk_demo_followup_call` FOREIGN KEY (`follow_up_completed_call_log_id`) REFERENCES `crm_call_logs` (`id`) ON DELETE SET NULL;

COMMIT;

