-- Seed Standard Sales Email Templates
-- Run this AFTER main migration: 2025-10-08-ai-email-composer-system.sql
-- This inserts the 3 standard templates into email_templates table

-- ============================================================================
-- Template 1: Sales Follow-up
-- ============================================================================
INSERT INTO `email_templates` 
  (`slug`, `name`, `subject`, `html_body`, `text_body`, `category`, `scope`, `is_active`, `created_at`)
SELECT 
  'sales-followup-template',
  'Sales Follow-up Email',
  'Following Up - Product',
  (SELECT LOAD_FILE('/path/to/admin/emails/sales-followup-template.html')),
  'Hi {{first_name}}, Following up on our conversation about Product wholesale products.',
  'sales',
  'sales_agent',
  1,
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `email_templates` WHERE `slug` = 'sales-followup-template'
);

-- Note: LOAD_FILE requires FILE privilege and absolute path
-- If LOAD_FILE fails, use the "Sync from Disk" button in Email Automation V2 instead

-- ============================================================================
-- Template 2: New Product Announcement
-- ============================================================================
INSERT INTO `email_templates` 
  (`slug`, `name`, `subject`, `html_body`, `text_body`, `category`, `scope`, `is_active`, `created_at`)
SELECT 
  'sales-new-product-announcement',
  'New Product Announcement',
  'New Product Alert - Product',
  (SELECT LOAD_FILE('/path/to/admin/emails/sales-new-product-announcement.html')),
  'Hi {{first_name}}, Exciting news! We have added new products to our premium line.',
  'sales',
  'sales_agent',
  1,
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `email_templates` WHERE `slug` = 'sales-new-product-announcement'
);

-- ============================================================================
-- Template 3: Thank You / Order Confirmation
-- ============================================================================
INSERT INTO `email_templates` 
  (`slug`, `name`, `subject`, `html_body`, `text_body`, `category`, `scope`, `is_active`, `created_at`)
SELECT 
  'sales-thank-you-template',
  'Thank You for Your Order',
  'Thank You for Your Order - Product',
  (SELECT LOAD_FILE('/path/to/admin/emails/sales-thank-you-template.html')),
  'Hi {{first_name}}, Thank you for your order! We appreciate your business and partnership.',
  'sales',
  'sales_agent',
  1,
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `email_templates` WHERE `slug` = 'sales-thank-you-template'
);

-- ============================================================================
-- ALTERNATIVE METHOD (Recommended):
-- ============================================================================
-- Instead of running this SQL, use the admin interface:
-- 
-- 1. Login to admin panel
-- 2. Navigate to: Email Automation V2 > Templates tab
-- 3. Click "Sync from HTML directory" button
-- 4. System will auto-import all .html files from admin/emails/
-- 
-- This method is more reliable and doesn't require FILE privilege

-- ============================================================================
-- Verification Query
-- ============================================================================
SELECT slug, name, category, scope, is_active 
FROM email_templates 
WHERE category = 'sales' OR scope = 'sales_agent'
ORDER BY created_at DESC;

-- Expected: 3 rows for the standard sales templates





