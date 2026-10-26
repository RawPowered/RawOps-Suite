-- CRM Leads: enforce cascading deletes and archive reason capture
-- Rules: idempotent, guarded changes per formatting rules

START TRANSACTION;

-- Ensure archive metadata exists on leads
ALTER TABLE crm_leads
  ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER lead_status,
  ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL AFTER updated_at,
  ADD COLUMN IF NOT EXISTS archived_by BIGINT UNSIGNED NULL AFTER archived_at,
  ADD COLUMN IF NOT EXISTS archive_reason VARCHAR(100) NULL AFTER archived_by,
  ADD COLUMN IF NOT EXISTS archive_notes TEXT NULL AFTER archive_reason;

-- Add FK for archived_by if possible
SET @has_fk := (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_leads' AND CONSTRAINT_NAME = 'fk_leads_archived_by'
);
SET @sql := IF(@has_fk = 0,
  'ALTER TABLE crm_leads ADD CONSTRAINT fk_leads_archived_by FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Strengthen FK behaviors for child tables to remove orphan rows when a lead is deleted
-- crm_notes already ON DELETE CASCADE; verify others

-- crm_call_logs.lead_id -> SET NULL is OK; no change
-- crm_tasks.lead_id -> SET NULL is OK; no change
-- crm_opportunities.lead_id -> SET NULL is OK; no change
-- crm_lead_assignments.lead_id -> should be CASCADE (already is per schema)

-- Create a helper procedure to fully purge a lead and all tight-coupled artifacts if needed (safe re-create)
DROP PROCEDURE IF EXISTS crm_purge_lead;
DELIMITER $$
CREATE PROCEDURE crm_purge_lead(IN p_lead_id BIGINT)
BEGIN
  -- Remove tight-coupled rows first (those not covered by CASCADE or with SET NULL semantics)
  DELETE FROM crm_notes WHERE lead_id = p_lead_id; -- ON DELETE CASCADE handles this, but safe
  DELETE FROM crm_lead_assignments WHERE lead_id = p_lead_id; -- CASCADE handles, but safe
  -- Null-out soft references in logs/tasks/opportunities per schema semantics
  UPDATE crm_call_logs SET lead_id = NULL WHERE lead_id = p_lead_id;
  UPDATE crm_tasks SET lead_id = NULL WHERE lead_id = p_lead_id;
  UPDATE crm_opportunities SET lead_id = NULL WHERE lead_id = p_lead_id;
  -- Finally remove the lead
  DELETE FROM crm_leads WHERE id = p_lead_id;
END$$
DELIMITER ;

COMMIT;



