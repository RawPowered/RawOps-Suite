-- ============================================================================
-- QUICK FIX: Add detail_completion_rate and update stored procedure
-- Run this in phpMyAdmin to fix the dashboard stats
-- ============================================================================

-- Add the missing column
ALTER TABLE `crm_agent_performance_cache` 
  ADD COLUMN IF NOT EXISTS `detail_completion_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Lead detail completion percentage' AFTER `incomplete_leads`;

-- Clear the cache to force recalculation
TRUNCATE TABLE `crm_agent_performance_cache`;

-- You're done! Refresh your dashboard now.








