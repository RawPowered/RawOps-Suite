-- ============================================================================
-- Update Stored Procedure: sp_update_agent_performance
-- Run this AFTER the quick fix to update the cached stats calculation
-- ============================================================================

DROP PROCEDURE IF EXISTS `sp_update_agent_performance`;

DELIMITER $$
CREATE PROCEDURE `sp_update_agent_performance`(IN p_agent_id BIGINT UNSIGNED)
BEGIN
  DECLARE v_leads_today INT DEFAULT 0;
  DECLARE v_leads_week INT DEFAULT 0;
  DECLARE v_active_leads INT DEFAULT 0;
  DECLARE v_total_leads INT DEFAULT 0;
  DECLARE v_detailed_leads INT DEFAULT 0;
  DECLARE v_incomplete_leads INT DEFAULT 0;
  DECLARE v_missing_email INT DEFAULT 0;
  DECLARE v_missing_phone INT DEFAULT 0;
  DECLARE v_conversions_today INT DEFAULT 0;
  DECLARE v_conversions_week INT DEFAULT 0;
  DECLARE v_conversions_month INT DEFAULT 0;
  DECLARE v_detail_completion_rate DECIMAL(5,2) DEFAULT 0.00;
  DECLARE v_conversion_rate DECIMAL(5,2) DEFAULT 0.00;
  DECLARE v_calls_today INT DEFAULT 0;
  DECLARE v_calls_week INT DEFAULT 0;
  DECLARE v_overdue_tasks INT DEFAULT 0;
  
  -- Calculate metrics
  SELECT COUNT(*) INTO v_total_leads FROM crm_leads WHERE assigned_agent_id = p_agent_id;
  SELECT COUNT(*) INTO v_leads_today FROM crm_leads WHERE assigned_agent_id = p_agent_id AND DATE(created_at) = CURDATE();
  SELECT COUNT(*) INTO v_leads_week FROM crm_leads WHERE assigned_agent_id = p_agent_id AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY);
  SELECT COUNT(*) INTO v_active_leads FROM crm_leads WHERE assigned_agent_id = p_agent_id AND lead_status IN ('new', 'contacted', 'qualified', 'proposal_sent', 'negotiating', 'customer_review', 'customer_follow_up', 'info_obtained_demo_pending');
  SELECT COUNT(*) INTO v_detailed_leads FROM crm_leads WHERE assigned_agent_id = p_agent_id AND email IS NOT NULL AND email != '' AND email NOT LIKE 'sample-009@example.com' AND email NOT LIKE 'sample-010@example.com' AND phone IS NOT NULL AND phone != '';
  SELECT COUNT(*) INTO v_incomplete_leads FROM crm_leads WHERE assigned_agent_id = p_agent_id AND NOT (email IS NOT NULL AND email != '' AND email NOT LIKE 'sample-009@example.com' AND email NOT LIKE 'sample-010@example.com' AND phone IS NOT NULL AND phone != '');
  SELECT COUNT(*) INTO v_missing_email FROM crm_leads WHERE assigned_agent_id = p_agent_id AND (email IS NULL OR email = '' OR email LIKE 'sample-009@example.com' OR email LIKE 'sample-010@example.com');
  SELECT COUNT(*) INTO v_missing_phone FROM crm_leads WHERE assigned_agent_id = p_agent_id AND (phone IS NULL OR phone = '');
  SELECT COUNT(*) INTO v_conversions_today FROM crm_leads WHERE assigned_agent_id = p_agent_id AND DATE(converted_at) = CURDATE() AND converted_to_account_id IS NOT NULL;
  SELECT COUNT(*) INTO v_conversions_week FROM crm_leads WHERE assigned_agent_id = p_agent_id AND converted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND converted_to_account_id IS NOT NULL;
  SELECT COUNT(*) INTO v_conversions_month FROM crm_leads WHERE assigned_agent_id = p_agent_id AND MONTH(converted_at) = MONTH(NOW()) AND YEAR(converted_at) = YEAR(NOW()) AND converted_to_account_id IS NOT NULL;
  
  -- Calculate rates (NEW: includes detail_completion_rate)
  IF v_total_leads > 0 THEN
    SET v_detail_completion_rate = (v_detailed_leads / v_total_leads) * 100;
    SET v_conversion_rate = (v_conversions_month / v_total_leads) * 100;
  END IF;
  
  SELECT COUNT(*) INTO v_calls_today FROM crm_call_logs WHERE agent_id = p_agent_id AND DATE(call_date) = CURDATE();
  SELECT COUNT(*) INTO v_calls_week FROM crm_call_logs WHERE agent_id = p_agent_id AND call_date >= DATE_SUB(NOW(), INTERVAL 7 DAY);
  
  -- Overdue tasks (with error handling)
  BEGIN
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION SET v_overdue_tasks = 0;
    SELECT COUNT(*) INTO v_overdue_tasks FROM crm_tasks WHERE agent_id = p_agent_id AND status = 'pending' AND due_date < NOW();
  END;
  
  -- Upsert into cache (UPDATED: includes detail_completion_rate)
  INSERT INTO crm_agent_performance_cache (
    agent_id, leads_today, leads_week, active_leads, total_leads,
    detailed_leads, incomplete_leads, detail_completion_rate, missing_email, missing_phone,
    conversions_today, conversions_week, conversions_month, conversion_rate,
    calls_today, calls_week, overdue_tasks
  ) VALUES (
    p_agent_id, v_leads_today, v_leads_week, v_active_leads, v_total_leads,
    v_detailed_leads, v_incomplete_leads, v_detail_completion_rate, v_missing_email, v_missing_phone,
    v_conversions_today, v_conversions_week, v_conversions_month, v_conversion_rate,
    v_calls_today, v_calls_week, v_overdue_tasks
  )
  ON DUPLICATE KEY UPDATE
    leads_today = v_leads_today,
    leads_week = v_leads_week,
    active_leads = v_active_leads,
    total_leads = v_total_leads,
    detailed_leads = v_detailed_leads,
    incomplete_leads = v_incomplete_leads,
    detail_completion_rate = v_detail_completion_rate,
    missing_email = v_missing_email,
    missing_phone = v_missing_phone,
    conversions_today = v_conversions_today,
    conversions_week = v_conversions_week,
    conversions_month = v_conversions_month,
    conversion_rate = v_conversion_rate,
    calls_today = v_calls_today,
    calls_week = v_calls_week,
    overdue_tasks = v_overdue_tasks,
    last_updated = CURRENT_TIMESTAMP;
END$$
DELIMITER ;








