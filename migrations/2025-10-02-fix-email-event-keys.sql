-- Fix email event keys to match what the code actually triggers
-- The code uses 'first_signup', 'first_purchase', 'new_invoice' but database had different keys

START TRANSACTION;

-- Add the events that the code actually triggers
INSERT INTO email_events (event_key, name, description, default_template_slug, scope, is_active)
VALUES
  ('first_signup', 'First Signup (Registration)', 'Triggered by required/registration.php when new user registers', 'welcome-general-sendgrid', 'platform', 1),
  ('first_purchase', 'First Purchase', 'Triggered by shop/checkout.php on user\'s first order', 'first-purchase', 'customer', 1),
  ('new_invoice', 'New Invoice (All)', 'Triggered by shop/checkout.php for every order', 'new-invoice', 'customer', 1)
ON DUPLICATE KEY UPDATE 
  name = VALUES(name),
  description = VALUES(description),
  default_template_slug = VALUES(default_template_slug),
  scope = VALUES(scope);

-- Create default rules for the critical events
-- First signup → welcome template
INSERT INTO email_notification_rules (event_key, template_id, is_active, created_at)
SELECT 'first_signup', t.id, 1, NOW()
FROM email_templates t
WHERE t.slug = 'welcome-general-sendgrid' AND t.is_active = 1
LIMIT 1
ON DUPLICATE KEY UPDATE template_id = VALUES(template_id), is_active = 1, updated_at = NOW();

-- First purchase → thank you template
INSERT INTO email_notification_rules (event_key, template_id, is_active, created_at)
SELECT 'first_purchase', t.id, 1, NOW()
FROM email_templates t
WHERE t.slug = 'first-purchase' AND t.is_active = 1
LIMIT 1
ON DUPLICATE KEY UPDATE template_id = VALUES(template_id), is_active = 1, updated_at = NOW();

-- New invoice → invoice template
INSERT INTO email_notification_rules (event_key, template_id, is_active, created_at)
SELECT 'new_invoice', t.id, 1, NOW()
FROM email_templates t
WHERE t.slug = 'new-invoice' AND t.is_active = 1
LIMIT 1
ON DUPLICATE KEY UPDATE template_id = VALUES(template_id), is_active = 1, updated_at = NOW();

COMMIT;

