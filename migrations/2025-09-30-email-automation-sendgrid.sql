-- Email Automation upgrades for SendGrid library, categories/scopes, and events
-- Safe/idempotent style using IF NOT EXISTS and ON DUPLICATE KEY UPDATE where possible

-- 1) Templates table: add category, scope, owner_user_id
ALTER TABLE email_templates
  ADD COLUMN IF NOT EXISTS category VARCHAR(64) NULL DEFAULT 'general' AFTER name,
  ADD COLUMN IF NOT EXISTS scope ENUM('platform','sales_agent','customer') NULL DEFAULT 'platform' AFTER category,
  ADD COLUMN IF NOT EXISTS owner_user_id INT NULL AFTER scope;

CREATE INDEX IF NOT EXISTS idx_email_templates_category ON email_templates (category);
CREATE INDEX IF NOT EXISTS idx_email_templates_scope ON email_templates (scope);
CREATE INDEX IF NOT EXISTS idx_email_templates_owner ON email_templates (owner_user_id);

-- 2) Rules table: extend scheduling/audience
ALTER TABLE email_notification_rules
  ADD COLUMN IF NOT EXISTS audience_role ENUM('platform_admin','sales_agent','customer') NULL AFTER template_id,
  ADD COLUMN IF NOT EXISTS schedule_cron VARCHAR(64) NULL AFTER condition_sql,
  ADD COLUMN IF NOT EXISTS created_by_user_id INT NULL AFTER is_active;

CREATE INDEX IF NOT EXISTS idx_email_rules_audience ON email_notification_rules (audience_role);
CREATE INDEX IF NOT EXISTS idx_email_rules_schedule ON email_notification_rules (schedule_cron);

-- 3) Events catalog (optional; used for UI/reference)
CREATE TABLE IF NOT EXISTS email_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_key VARCHAR(64) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(500) NULL,
  default_template_slug VARCHAR(120) NULL,
  scope ENUM('platform','sales_agent','customer') NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO email_events (event_key, name, description, default_template_slug, scope, is_active)
VALUES
  ('new_user_registration','New user registration','Triggered when a new account is created','welcome-general-sendgrid','platform',1),
  ('new_lead','New lead','Triggered when a new lead is created',NULL,'sales_agent',1),
  ('new_account','New account','Triggered when a wholesale/customer account is created',NULL,'platform',1),
  ('new_invoice_admin','New invoice (platform admin)','Invoice created notification for platform admins',NULL,'platform',1),
  ('new_invoice_customer','New invoice (customer)','Invoice created notification for customers',NULL,'customer',1),
  ('new_invoice_agent','New invoice (sales agent)','Invoice created notification for agents',NULL,'sales_agent',1),
  ('weekly_sales_report_admin','Weekly sales report (platform admin)','Automated weekly sales report to platform admins',NULL,'platform',1),
  ('weekly_sales_report_agent','Weekly sales report (sales agent)','Automated weekly sales report to agents',NULL,'sales_agent',1),
  ('monthly_purchase_review_customer','Monthly purchase review (customer)','Monthly purchase summary for customers',NULL,'customer',1),
  ('weekly_purchase_followup','Weekly purchase follow-up','Follow-up to encourage repeat purchases',NULL,'customer',1)
ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), default_template_slug=VALUES(default_template_slug), scope=VALUES(scope), is_active=VALUES(is_active);

-- 4) Default slugs mapping for welcomes (optional alias used in triggers)
INSERT INTO email_templates (slug, name, subject, html_body, text_body, is_active, category, scope, created_at)
VALUES ('welcome-general-sendgrid','Welcome — General','Welcome to Product‑It Product','<p>Welcome to Product‑It Product</p>','Welcome to Product‑It Product',1,'welcome','platform',NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject);

-- 5) Configuration (docs): SendGrid API key stored in required/site_config.json under sendgrid_api_key

