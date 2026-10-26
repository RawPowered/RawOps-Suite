-- Email Automation Hardening
-- Date: 2025-11-15
-- Purpose: Persist workflow selections, improve follow-up diagnostics, and support manual scheduling metadata.

-- ============================================================================
-- 1. Extend agent_emails with workflow metadata
-- ============================================================================
ALTER TABLE `agent_emails`
ADD COLUMN IF NOT EXISTS `workflow_selection_mode` enum('single','conditional') DEFAULT NULL COMMENT 'How the workflow selector was configured' AFTER `admin_notes`,
ADD COLUMN IF NOT EXISTS `workflow_selection_json` text DEFAULT NULL COMMENT 'Serialized workflow selector payload' AFTER `workflow_selection_mode`,
ADD COLUMN IF NOT EXISTS `workflow_schedule_reason` varchar(255) DEFAULT NULL COMMENT 'Why a workflow was manually scheduled (agent note/system reason)' AFTER `workflow_selection_json`;

-- ============================================================================
-- 2. Track scheduling reasons on email_followup_schedule
-- ============================================================================
ALTER TABLE `email_followup_schedule`
ADD COLUMN IF NOT EXISTS `schedule_reason` varchar(255) DEFAULT NULL COMMENT 'Why this follow-up was scheduled' AFTER `status`,
ADD COLUMN IF NOT EXISTS `scheduled_via` enum('auto','manual_single','manual_conditional') DEFAULT 'auto' COMMENT 'What pathway scheduled this follow-up' AFTER `schedule_reason`;

-- ============================================================================
-- 3. Optional tracking token copy on email_send_queue for diagnostics
-- ============================================================================
ALTER TABLE `email_send_queue`
ADD COLUMN IF NOT EXISTS `tracking_token` varchar(64) DEFAULT NULL COMMENT 'Copy of agent_emails.tracking_token for queued sends' AFTER `agent_email_id`,
ADD KEY IF NOT EXISTS `idx_tracking_token` (`tracking_token`);





