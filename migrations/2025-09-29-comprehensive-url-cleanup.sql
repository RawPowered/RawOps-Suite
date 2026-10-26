-- ============================================================================
-- Comprehensive Database URL Cleanup Script
-- Date: 2025-09-29
-- Purpose: Complete URL migration from legacy.example/product to product.example
-- Binary-safe and idempotent following 002-SQL-FORMATTING-RULES.md
-- ============================================================================

START TRANSACTION;

-- Verify database
SELECT DATABASE() as current_database;

-- Define URL patterns to replace
SET @old_dev_url = 'https://legacy.example/product/';
SET @new_url = 'https://product.example/';
SET @old_dev_url_http = 'http://legacy.example/product/';
SET @product_path = '/product/';
SET @clean_path = '/';

-- ============================================================================
-- USERS TABLE
-- ============================================================================
UPDATE users 
SET 
    email = REPLACE(REPLACE(email, @old_dev_url, @new_url), @product_path, @clean_path),
    first_name = REPLACE(REPLACE(first_name, @old_dev_url, @new_url), @product_path, @clean_path),
    last_name = REPLACE(REPLACE(last_name, @old_dev_url, @new_url), @product_path, @clean_path)
WHERE 
    email LIKE '%product%' OR email LIKE '%legacy.example%' OR
    first_name LIKE '%product%' OR first_name LIKE '%legacy.example%' OR
    last_name LIKE '%product%' OR last_name LIKE '%legacy.example%';

-- ============================================================================
-- PRODUCTS TABLE
-- ============================================================================
UPDATE products 
SET 
    name = REPLACE(REPLACE(name, @old_dev_url, @new_url), @product_path, @clean_path),
    description = REPLACE(REPLACE(description, @old_dev_url, @new_url), @product_path, @clean_path),
    image_url = REPLACE(REPLACE(image_url, @old_dev_url, @new_url), @product_path, @clean_path)
WHERE 
    name LIKE '%product%' OR name LIKE '%legacy.example%' OR
    description LIKE '%product%' OR description LIKE '%legacy.example%' OR
    image_url LIKE '%product%' OR image_url LIKE '%legacy.example%';

-- ============================================================================
-- TRAINING MODULES
-- ============================================================================
UPDATE training_modules
SET
    title = REPLACE(REPLACE(title, @old_dev_url, @new_url), @product_path, @clean_path),
    content = REPLACE(REPLACE(content, @old_dev_url, @new_url), @product_path, @clean_path),
    resources = REPLACE(REPLACE(resources, @old_dev_url, @new_url), @product_path, @clean_path)
WHERE
    title LIKE '%product%' OR title LIKE '%legacy.example%' OR
    content LIKE '%product%' OR content LIKE '%legacy.example%' OR
    resources LIKE '%product%' OR resources LIKE '%legacy.example%';

-- ============================================================================
-- EMAIL TEMPLATES
-- ============================================================================
UPDATE email_templates
SET
    subject = REPLACE(REPLACE(subject, @old_dev_url, @new_url), @product_path, @clean_path),
    body = REPLACE(REPLACE(body, @old_dev_url, @new_url), @product_path, @clean_path)
WHERE
    subject LIKE '%product%' OR subject LIKE '%legacy.example%' OR
    body LIKE '%product%' OR body LIKE '%legacy.example%';

-- ============================================================================
-- CRM TABLES
-- ============================================================================

-- CRM Contacts
UPDATE crm_contacts
SET
    first_name = REPLACE(REPLACE(first_name, @old_dev_url, @new_url), @product_path, @clean_path),
    last_name = REPLACE(REPLACE(last_name, @old_dev_url, @new_url), @product_path, @clean_path),
    email = REPLACE(REPLACE(email, @old_dev_url, @new_url), @product_path, @clean_path),
    company = REPLACE(REPLACE(company, @old_dev_url, @new_url), @product_path, @clean_path),
    website = REPLACE(REPLACE(website, @old_dev_url, @new_url), @product_path, @clean_path),
    notes = REPLACE(REPLACE(notes, @old_dev_url, @new_url), @product_path, @clean_path)
WHERE
    first_name LIKE '%product%' OR first_name LIKE '%legacy.example%' OR
    last_name LIKE '%product%' OR last_name LIKE '%legacy.example%' OR
    email LIKE '%product%' OR email LIKE '%legacy.example%' OR
    company LIKE '%product%' OR company LIKE '%legacy.example%' OR
    website LIKE '%product%' OR website LIKE '%legacy.example%' OR
    notes LIKE '%product%' OR notes LIKE '%legacy.example%';

-- CRM Notes
UPDATE crm_notes
SET
    content = REPLACE(REPLACE(content, @old_dev_url, @new_url), @product_path, @clean_path)
WHERE
    content LIKE '%product%' OR content LIKE '%legacy.example%';

-- CRM Demo Requests
UPDATE crm_demo_requests
SET
    business_name = REPLACE(REPLACE(business_name, @old_dev_url, @new_url), @product_path, @clean_path),
    website = REPLACE(REPLACE(website, @old_dev_url, @new_url), @product_path, @clean_path),
    notes = REPLACE(REPLACE(notes, @old_dev_url, @new_url), @product_path, @clean_path)
WHERE
    business_name LIKE '%product%' OR business_name LIKE '%legacy.example%' OR
    website LIKE '%product%' OR website LIKE '%legacy.example%' OR
    notes LIKE '%product%' OR notes LIKE '%legacy.example%';

-- CRM Leads
UPDATE crm_leads
SET
    business_name = REPLACE(REPLACE(business_name, @old_dev_url, @new_url), @product_path, @clean_path),
    website = REPLACE(REPLACE(website, @old_dev_url, @new_url), @product_path, @clean_path),
    notes = REPLACE(REPLACE(notes, @old_dev_url, @new_url), @product_path, @clean_path)
WHERE
    business_name LIKE '%product%' OR business_name LIKE '%legacy.example%' OR
    website LIKE '%product%' OR website LIKE '%legacy.example%' OR
    notes LIKE '%product%' OR notes LIKE '%legacy.example%';

-- CRM Places Cache (JSON data)
UPDATE crm_places_cache
SET
    formatted_address = REPLACE(REPLACE(formatted_address, @old_dev_url, @new_url), @product_path, @clean_path),
    website = REPLACE(REPLACE(website, @old_dev_url, @new_url), @product_path, @clean_path),
    raw_data = REPLACE(REPLACE(raw_data, @old_dev_url, @new_url), @product_path, @clean_path)
WHERE
    formatted_address LIKE '%product%' OR formatted_address LIKE '%legacy.example%' OR
    website LIKE '%product%' OR website LIKE '%legacy.example%' OR
    raw_data LIKE '%product%' OR raw_data LIKE '%legacy.example%';

-- ============================================================================
-- ADMIN AND OPERATIONS TABLES
-- ============================================================================

-- Admin Dev Tasks
UPDATE admin_dev_tasks
SET
    title = REPLACE(REPLACE(title, @old_dev_url, @new_url), @product_path, @clean_path),
    description = REPLACE(REPLACE(description, @old_dev_url, @new_url), @product_path, @clean_path),
    notes = REPLACE(REPLACE(notes, @old_dev_url, @new_url), @product_path, @clean_path)
WHERE
    title LIKE '%product%' OR title LIKE '%legacy.example%' OR
    description LIKE '%product%' OR description LIKE '%legacy.example%' OR
    notes LIKE '%product%' OR notes LIKE '%legacy.example%';

-- Admin History
UPDATE admin_history
SET
    action = REPLACE(REPLACE(action, @old_dev_url, @new_url), @product_path, @clean_path),
    details = REPLACE(REPLACE(details, @old_dev_url, @new_url), @product_path, @clean_path),
    metadata = REPLACE(REPLACE(metadata, @old_dev_url, @new_url), @product_path, @clean_path)
WHERE
    action LIKE '%product%' OR action LIKE '%legacy.example%' OR
    details LIKE '%product%' OR details LIKE '%legacy.example%' OR
    metadata LIKE '%product%' OR metadata LIKE '%legacy.example%';

-- Operations Log
UPDATE operations_log
SET
    action = REPLACE(REPLACE(action, @old_dev_url, @new_url), @product_path, @clean_path),
    details = REPLACE(REPLACE(details, @old_dev_url, @new_url), @product_path, @clean_path)
WHERE
    action LIKE '%product%' OR action LIKE '%legacy.example%' OR
    details LIKE '%product%' OR details LIKE '%legacy.example%';

-- ============================================================================
-- ORDERS AND INVOICES
-- ============================================================================

-- Orders
UPDATE orders
SET
    notes = REPLACE(REPLACE(notes, @old_dev_url, @new_url), @product_path, @clean_path),
    shipping_address = REPLACE(REPLACE(shipping_address, @old_dev_url, @new_url), @product_path, @clean_path),
    billing_address = REPLACE(REPLACE(billing_address, @old_dev_url, @new_url), @product_path, @clean_path)
WHERE
    notes LIKE '%product%' OR notes LIKE '%legacy.example%' OR
    shipping_address LIKE '%product%' OR shipping_address LIKE '%legacy.example%' OR
    billing_address LIKE '%product%' OR billing_address LIKE '%legacy.example%';

-- Order Items
UPDATE order_items
SET
    product_name = REPLACE(REPLACE(product_name, @old_dev_url, @new_url), @product_path, @clean_path),
    notes = REPLACE(REPLACE(notes, @old_dev_url, @new_url), @product_path, @clean_path)
WHERE
    product_name LIKE '%product%' OR product_name LIKE '%legacy.example%' OR
    notes LIKE '%product%' OR notes LIKE '%legacy.example%';

-- ============================================================================
-- AI AND CHAT LOGS
-- ============================================================================

-- AI Chat Logs
UPDATE ai_chat_logs
SET
    message = REPLACE(REPLACE(message, @old_dev_url, @new_url), @product_path, @clean_path),
    response = REPLACE(REPLACE(response, @old_dev_url, @new_url), @product_path, @clean_path),
    metadata = REPLACE(REPLACE(metadata, @old_dev_url, @new_url), @product_path, @clean_path)
WHERE
    message LIKE '%product%' OR message LIKE '%legacy.example%' OR
    response LIKE '%product%' OR response LIKE '%legacy.example%' OR
    metadata LIKE '%product%' OR metadata LIKE '%legacy.example%';

-- AI Learning Insights
UPDATE ai_learning_insights
SET
    content = REPLACE(REPLACE(content, @old_dev_url, @new_url), @product_path, @clean_path),
    metadata = REPLACE(REPLACE(metadata, @old_dev_url, @new_url), @product_path, @clean_path)
WHERE
    content LIKE '%product%' OR content LIKE '%legacy.example%' OR
    metadata LIKE '%product%' OR metadata LIKE '%legacy.example%';

-- ============================================================================
-- PROMO AND DEMO TABLES
-- ============================================================================

-- Promo Demo Leads
UPDATE promo_demo_leads
SET
    business_name = REPLACE(REPLACE(business_name, @old_dev_url, @new_url), @product_path, @clean_path),
    website = REPLACE(REPLACE(website, @old_dev_url, @new_url), @product_path, @clean_path),
    notes = REPLACE(REPLACE(notes, @old_dev_url, @new_url), @product_path, @clean_path)
WHERE
    business_name LIKE '%product%' OR business_name LIKE '%legacy.example%' OR
    website LIKE '%product%' OR website LIKE '%legacy.example%' OR
    notes LIKE '%product%' OR notes LIKE '%legacy.example%';

-- Promo Demo Reviews
UPDATE promo_demo_reviews
SET
    comment = REPLACE(REPLACE(comment, @old_dev_url, @new_url), @product_path, @clean_path)
WHERE
    comment LIKE '%product%' OR comment LIKE '%legacy.example%';

-- ============================================================================
-- SITE CONFIG AND SETTINGS
-- ============================================================================

-- Site Config (if exists)
UPDATE site_config
SET
    value = REPLACE(REPLACE(value, @old_dev_url, @new_url), @product_path, @clean_path),
    description = REPLACE(REPLACE(description, @old_dev_url, @new_url), @product_path, @clean_path)
WHERE
    value LIKE '%product%' OR value LIKE '%legacy.example%' OR
    description LIKE '%product%' OR description LIKE '%legacy.example%';

-- LMS Settings
UPDATE lms_settings
SET
    value = REPLACE(REPLACE(value, @old_dev_url, @new_url), @product_path, @clean_path),
    description = REPLACE(REPLACE(description, @old_dev_url, @new_url), @product_path, @clean_path)
WHERE
    value LIKE '%product%' OR value LIKE '%legacy.example%' OR
    description LIKE '%product%' OR description LIKE '%legacy.example%';

-- ============================================================================
-- MIGRATION TRACKING TABLES
-- ============================================================================

-- Migration URL Replacements
UPDATE migration_url_replacements
SET
    old_url = REPLACE(REPLACE(old_url, @old_dev_url, @new_url), @product_path, @clean_path),
    new_url = REPLACE(REPLACE(new_url, @old_dev_url, @new_url), @product_path, @clean_path),
    notes = REPLACE(REPLACE(notes, @old_dev_url, @new_url), @product_path, @clean_path)
WHERE
    old_url LIKE '%product%' OR old_url LIKE '%legacy.example%' OR
    new_url LIKE '%product%' OR new_url LIKE '%legacy.example%' OR
    notes LIKE '%product%' OR notes LIKE '%legacy.example%';

-- Migration 404 Audit
UPDATE migration_404_audit
SET
    url = REPLACE(REPLACE(url, @old_dev_url, @new_url), @product_path, @clean_path),
    referrer = REPLACE(REPLACE(referrer, @old_dev_url, @new_url), @product_path, @clean_path)
WHERE
    url LIKE '%product%' OR url LIKE '%legacy.example%' OR
    referrer LIKE '%product%' OR referrer LIKE '%legacy.example%';

-- ============================================================================
-- WHOLESALE ACCOUNTS
-- ============================================================================

UPDATE wholesale_accounts
SET
    business_name = REPLACE(REPLACE(business_name, @old_dev_url, @new_url), @product_path, @clean_path),
    business_address = REPLACE(REPLACE(business_address, @old_dev_url, @new_url), @product_path, @clean_path),
    website = REPLACE(REPLACE(website, @old_dev_url, @new_url), @product_path, @clean_path),
    notes = REPLACE(REPLACE(notes, @old_dev_url, @new_url), @product_path, @clean_path)
WHERE
    business_name LIKE '%product%' OR business_name LIKE '%legacy.example%' OR
    business_address LIKE '%product%' OR business_address LIKE '%legacy.example%' OR
    website LIKE '%product%' OR website LIKE '%legacy.example%' OR
    notes LIKE '%product%' OR notes LIKE '%legacy.example%';

-- ============================================================================
-- OBJECTION LIBRARY
-- ============================================================================

UPDATE objection_library
SET
    objection = REPLACE(REPLACE(objection, @old_dev_url, @new_url), @product_path, @clean_path),
    response = REPLACE(REPLACE(response, @old_dev_url, @new_url), @product_path, @clean_path)
WHERE
    objection LIKE '%product%' OR objection LIKE '%legacy.example%' OR
    response LIKE '%product%' OR response LIKE '%legacy.example%';

-- ============================================================================
-- SUGGESTION BOX
-- ============================================================================

UPDATE suggestion_box
SET
    suggestion = REPLACE(REPLACE(suggestion, @old_dev_url, @new_url), @product_path, @clean_path),
    response = REPLACE(REPLACE(response, @old_dev_url, @new_url), @product_path, @clean_path)
WHERE
    suggestion LIKE '%product%' OR suggestion LIKE '%legacy.example%' OR
    response LIKE '%product%' OR response LIKE '%legacy.example%';

-- ============================================================================
-- VERIFICATION QUERIES
-- ============================================================================

-- Count remaining /product/ references
SELECT 'Verification: Counting remaining URL issues...' as status;

SELECT 
    'users' as table_name,
    COUNT(*) as remaining_issues
FROM users 
WHERE 
    email LIKE '%/product/%' OR email LIKE '%legacy.example%' OR
    first_name LIKE '%/product/%' OR first_name LIKE '%legacy.example%' OR
    last_name LIKE '%/product/%' OR last_name LIKE '%legacy.example%'
UNION ALL
SELECT 
    'products' as table_name,
    COUNT(*) as remaining_issues
FROM products 
WHERE 
    name LIKE '%/product/%' OR name LIKE '%legacy.example%' OR
    description LIKE '%/product/%' OR description LIKE '%legacy.example%' OR
    image_url LIKE '%/product/%' OR image_url LIKE '%legacy.example%'
UNION ALL
SELECT 
    'training_modules' as table_name,
    COUNT(*) as remaining_issues
FROM training_modules
WHERE
    title LIKE '%/product/%' OR title LIKE '%legacy.example%' OR
    content LIKE '%/product/%' OR content LIKE '%legacy.example%' OR
    resources LIKE '%/product/%' OR resources LIKE '%legacy.example%'
UNION ALL
SELECT 
    'email_templates' as table_name,
    COUNT(*) as remaining_issues
FROM email_templates
WHERE
    subject LIKE '%/product/%' OR subject LIKE '%legacy.example%' OR
    body LIKE '%/product/%' OR body LIKE '%legacy.example%';

-- Final status
SELECT 'Database URLs cleanup completed successfully' AS status, NOW() as completed_at;

COMMIT;

-- ============================================================================
-- POST-MIGRATION NOTES
-- ============================================================================
-- After running this script:
-- 1. Run the URL verification tool at /admin/url-verification.php
-- 2. Check the URL scanner at /admin/ultimate-url-scanner-v2.php
-- 3. Test critical paths (login, API, checkout, etc.)
-- 4. Monitor 404 errors for any missed URLs
-- ============================================================================
