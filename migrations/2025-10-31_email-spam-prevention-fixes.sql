-- ================================================================
-- Email Spam Prevention Fixes
-- Date: 2025-10-31
-- 
-- Purpose: Fix email sending configuration to prevent spam flags
-- 
-- Changes:
-- 1. Standardize all email senders to sample-006@example.com
-- 2. Remove AI disclaimer footers from emails (spam trigger)
-- 3. Fix duplicate header issues in email generation
-- 4. Clean up email template content
-- 
-- Implementation Notes:
-- - All PHP code changes have been applied to sending functions
-- - Email templates cleaned of AI references
-- - SendGrid sender domain now consistent across all endpoints
-- 
-- Files Modified:
-- - api/sendgrid.php (default from address)
-- - api/ai-email-composer.php (sender + removed AI disclaimer)
-- - api/email-workflow-processor.php (sender + removed AI disclaimer)
-- - api/email-queue-processor.php (sender domain)
-- - api/sample-request-api.php (sender domain)
-- - required/google_mailer.php (reply-to address)
-- - required/legal-email-service.php (sender domain)
-- - required/email-functions.php (disabled AI disclaimer injection)
-- - admin/emails/welcome-agent-sendgrid.html (removed AI references)
-- 
-- ================================================================

-- No database schema changes required for this migration
-- All changes are in application code layer

-- Update any email_templates that might have AI references in subject lines
UPDATE email_templates 
SET subject = REPLACE(subject, 'AI-assisted', 'Smart')
WHERE subject LIKE '%AI-assisted%'
  AND is_active = 1;

UPDATE email_templates 
SET subject = REPLACE(subject, 'AI-powered', 'Advanced')
WHERE subject LIKE '%AI-powered%'
  AND is_active = 1;

UPDATE email_templates 
SET subject = REPLACE(subject, 'AI ', '')
WHERE subject LIKE '%AI %'
  AND is_active = 1;

-- Clean up any AI references in email body content
UPDATE email_templates 
SET html_body = REPLACE(html_body, 'AI-powered system', 'powerful system'),
    html_body = REPLACE(html_body, 'AI‑powered system', 'powerful system'),
    html_body = REPLACE(html_body, 'AI-assisted selling', 'Smart selling tools'),
    html_body = REPLACE(html_body, 'AI‑assisted selling', 'Smart selling tools'),
    html_body = REPLACE(html_body, ' AI ', ' '),
    text_body = REPLACE(text_body, 'AI-powered system', 'powerful system'),
    text_body = REPLACE(text_body, 'AI-assisted selling', 'Smart selling tools'),
    text_body = REPLACE(text_body, ' AI ', ' ')
WHERE (html_body LIKE '%AI%' OR text_body LIKE '%AI%')
  AND is_active = 1;

-- Log the migration (only if table exists)
-- Note: Skipping activity log insert as table may not exist in all environments
-- If you have an audit/activity log table, uncomment and adjust:
-- INSERT INTO your_audit_table (user_id, action, description, created_at)
-- VALUES (1, 'system_maintenance', 'Email spam prevention fixes applied', NOW());

-- ================================================================
-- VERIFICATION QUERIES
-- Run these to verify changes:
-- ================================================================

-- Check for remaining AI references in active email templates
-- SELECT id, name, subject 
-- FROM email_templates 
-- WHERE (subject LIKE '%AI%' OR html_body LIKE '%AI%' OR text_body LIKE '%AI%')
--   AND is_active = 1;

-- ================================================================
-- EMAIL AUTOMATION TRIGGER VERIFICATION
-- ================================================================

-- Verify SendGrid webhook is configured properly:
-- URL should be: https://product.example/api/sendgrid-webhook.php
-- Events to enable: Processed, Delivered, Opened, Clicked, Bounced, Dropped, Spam Report

-- Check that email open tracking columns exist (OPTIONAL - run manually)
-- SELECT 
--     COLUMN_NAME, 
--     DATA_TYPE 
-- FROM INFORMATION_SCHEMA.COLUMNS 
-- WHERE TABLE_NAME = 'agent_emails' 
-- AND COLUMN_NAME IN ('open_count', 'click_count', 'last_opened_at', 'last_clicked_at', 'sendgrid_message_id')
-- ORDER BY COLUMN_NAME;

-- Verify workflow system is active (OPTIONAL - only if workflow tables exist)
-- SELECT 
--     COUNT(*) as active_workflows,
--     SUM(CASE WHEN trigger_type = 'unopened' THEN 1 ELSE 0 END) as unopened_workflows,
--     SUM(CASE WHEN trigger_type = 'opened_not_clicked' THEN 1 ELSE 0 END) as read_workflows
-- FROM email_followup_workflows 
-- WHERE is_active = 1;

-- Check recent email tracking (OPTIONAL - only if agent_emails table exists)
-- SELECT 
--     DATE(sent_at) as date,
--     COUNT(*) as emails_sent,
--     SUM(open_count > 0) as opened_count,
--     SUM(click_count > 0) as clicked_count,
--     ROUND(AVG(open_count), 2) as avg_opens,
--     ROUND(AVG(click_count), 2) as avg_clicks
-- FROM agent_emails 
-- WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
--     AND status IN ('sent', 'delivered')
-- GROUP BY DATE(sent_at)
-- ORDER BY date DESC;

-- Verify workflow processor is evaluating read triggers (OPTIONAL - only if workflow tables exist)
-- SELECT 
--     trigger_type,
--     COUNT(*) as scheduled_followups,
--     MIN(scheduled_for) as next_followup
-- FROM email_followup_schedule 
-- WHERE status = 'pending'
-- GROUP BY trigger_type
-- ORDER BY trigger_type;

-- ================================================================
-- AUTOMATION SYSTEM CHECKLIST
-- ================================================================
-- 
-- ✓ SendGrid Webhook Setup:
--   1. Log into SendGrid Dashboard
--   2. Settings > Mail Settings > Event Webhook
--   3. POST URL: https://product.example/api/sendgrid-webhook.php
--   4. Enable events: delivered, open, click, bounce, dropped, spamreport
--   5. Test webhook (SendGrid has a test button)
--
-- ✓ Email Tracking Flow:
--   1. Email sent via sendgrid_send() → tracking_token generated
--   2. Recipient opens email → SendGrid webhook fires → open_count incremented
--   3. Recipient clicks link → Redirects through email-track-conversion.php
--   4. Conversion tracking starts (page_view, product_view, cart_add, etc.)
--   5. Workflow processor evaluates trigger type based on engagement
--   6. Follow-up scheduled if matching workflow exists
--
-- ✓ Read Trigger (opened_not_clicked):
--   - Fires when: open_count > 0 AND click_count = 0
--   - Default delay: 120 hours (5 days) after send
--   - Can be customized per agent or per lead/account
--   - Configured in: email_followup_workflows table
--
-- ✓ Workflow Processor:
--   - Runs via: api/email-workflow-processor.php
--   - Called by: Cron job or manual trigger
--   - Evaluates: Emails sent in last 30 days
--   - Schedules: Follow-ups based on engagement patterns
--
-- ================================================================

-- ================================================================
-- ROLLBACK INSTRUCTIONS
-- ================================================================
-- If you need to revert these changes:
-- 1. Restore the PHP files from git
-- 2. Re-enable AI disclaimers in required/email-functions.php
-- 3. Revert sender addresses in API files
-- 
-- Note: Email content changes in database cannot be automatically 
-- rolled back. You would need to manually update templates.
-- ================================================================

