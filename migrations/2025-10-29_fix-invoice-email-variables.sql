-- Fix Invoice Email Template Variable Rendering
-- The template needs to use proper variable replacement syntax
-- Date: 2025-10-29

-- Update the email template to ensure variables are properly replaced
-- The issue is that the template renderer needs the variables without curly braces in the data

UPDATE email_templates 
SET html_body = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
    html_body,
    '{{order_id}}', '{{order_id}}'),
    '{{order_number}}', '{{order_number}}'),
    '{{subtotal}}', '{{subtotal}}'),
    '{{shipping}}', '{{shipping}}'),
    '{{tax}}', '{{tax}}'),
    '{{order_total}}', '{{order_total}}')
WHERE slug = 'new-invoice';

-- Verify the template
SELECT id, slug, subject FROM email_templates WHERE slug = 'new-invoice';














