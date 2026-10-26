-- 2025-11-16 Fix Sample Suite workflow delays
-- Purpose: Update steps 110 and 111 to correct timing
-- Safe to run multiple times

START TRANSACTION;

-- Fix Step 110: Should trigger at 0.5 hours (30 minutes) after open
UPDATE email_followup_workflows
SET 
    trigger_delay_hours = 0.5,
    description = 'Sends reminder 30 minutes after kickoff is opened without clicking.',
    updated_at = NOW()
WHERE chain_step = 110 
  AND chain_id = (SELECT id FROM email_workflow_chains WHERE chain_name = 'Sample Suite v1' LIMIT 1);

-- Fix Step 111: Should trigger at 2 hours (not 4) after kickoff send
UPDATE email_followup_workflows
SET 
    trigger_delay_hours = 2,
    name = 'Sample Suite · Step 111 — Kickoff Recheck 2h',
    description = 'Sends reminder 2 hours after kickoff if not opened or clicked.',
    updated_at = NOW()
WHERE chain_step = 111 
  AND chain_id = (SELECT id FROM email_workflow_chains WHERE chain_name = 'Sample Suite v1' LIMIT 1);

COMMIT;




