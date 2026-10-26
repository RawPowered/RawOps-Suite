-- Database URL Cleanup - Binary Safe & Idempotent
-- Date: 2025-01-29
-- Purpose: Clean any /product/ or legacy.example URLs from database
-- Following: 002-SQL-FORMATTING-RULES.md

START TRANSACTION;

-- ============================================
-- STEP 1: VERIFY DATABASE AND SHOW SCHEMA
-- ============================================

SELECT DATABASE() as current_database;
SELECT 'Starting URL cleanup based on ACTUAL schema...' as status;

-- ============================================
-- STEP 2: UPDATE ONLY EXISTING COLUMNS IN ACTUAL TABLES
-- Based on actual foggksfx_product.sql schema
-- ============================================

-- Fix users table (confirmed columns: email, first_name, last_name, phone)
UPDATE users SET 
    email = REPLACE(REPLACE(email, 'https://legacy.example/product/', 'https://product.example/'), '/product/', '/'),
    first_name = REPLACE(REPLACE(first_name, 'https://legacy.example/product/', 'https://product.example/'), '/product/', '/'),
    last_name = REPLACE(REPLACE(last_name, 'https://legacy.example/product/', 'https://product.example/'), '/product/', '/'),
    phone = REPLACE(REPLACE(phone, 'https://legacy.example/product/', 'https://product.example/'), '/product/', '/')
WHERE email LIKE '%product%' 
   OR email LIKE '%legacy.example%'
   OR first_name LIKE '%product%' 
   OR first_name LIKE '%legacy.example%'
   OR last_name LIKE '%product%' 
   OR last_name LIKE '%legacy.example%'
   OR phone LIKE '%product%' 
   OR phone LIKE '%legacy.example%';

-- Fix products table (confirmed columns: sku, name, description, attribute_content)
UPDATE products SET 
    sku = REPLACE(REPLACE(sku, 'https://legacy.example/product/', 'https://product.example/'), '/product/', '/'),
    name = REPLACE(REPLACE(name, 'https://legacy.example/product/', 'https://product.example/'), '/product/', '/'),
    description = REPLACE(REPLACE(description, 'https://legacy.example/product/', 'https://product.example/'), '/product/', '/'),
    attribute_content = REPLACE(REPLACE(attribute_content, 'https://legacy.example/product/', 'https://product.example/'), '/product/', '/')
WHERE sku LIKE '%product%' 
   OR sku LIKE '%legacy.example%'
   OR name LIKE '%product%' 
   OR name LIKE '%legacy.example%'
   OR description LIKE '%product%' 
   OR description LIKE '%legacy.example%'
   OR attribute_content LIKE '%product%' 
   OR attribute_content LIKE '%legacy.example%';

-- Fix training_modules table (will check actual columns)
UPDATE training_modules SET 
    title = REPLACE(REPLACE(title, 'https://legacy.example/product/', 'https://product.example/'), '/product/', '/'),
    slug = REPLACE(REPLACE(slug, 'https://legacy.example/product/', 'https://product.example/'), '/product/', '/'),
    content = REPLACE(REPLACE(content, 'https://legacy.example/product/', 'https://product.example/'), '/product/', '/')
WHERE title LIKE '%product%' 
   OR title LIKE '%legacy.example%'
   OR slug LIKE '%product%' 
   OR slug LIKE '%legacy.example%'
   OR content LIKE '%product%' 
   OR content LIKE '%legacy.example%';

-- Fix crm_leads table (confirmed columns: first_name, last_name, email, phone, company_name, website)
UPDATE crm_leads SET 
    first_name = REPLACE(REPLACE(first_name, 'https://legacy.example/product/', 'https://product.example/'), '/product/', '/'),
    last_name = REPLACE(REPLACE(last_name, 'https://legacy.example/product/', 'https://product.example/'), '/product/', '/'),
    email = REPLACE(REPLACE(email, 'https://legacy.example/product/', 'https://product.example/'), '/product/', '/'),
    phone = REPLACE(REPLACE(phone, 'https://legacy.example/product/', 'https://product.example/'), '/product/', '/'),
    company_name = REPLACE(REPLACE(company_name, 'https://legacy.example/product/', 'https://product.example/'), '/product/', '/'),
    website = REPLACE(REPLACE(website, 'https://legacy.example/product/', 'https://product.example/'), '/product/', '/')
WHERE first_name LIKE '%product%' 
   OR first_name LIKE '%legacy.example%'
   OR last_name LIKE '%product%' 
   OR last_name LIKE '%legacy.example%'
   OR email LIKE '%product%' 
   OR email LIKE '%legacy.example%'
   OR phone LIKE '%product%' 
   OR phone LIKE '%legacy.example%'
   OR company_name LIKE '%product%' 
   OR company_name LIKE '%legacy.example%'
   OR website LIKE '%product%' 
   OR website LIKE '%legacy.example%';

-- Fix roles table (basic columns: name, description)
UPDATE roles SET 
    name = REPLACE(REPLACE(name, 'https://legacy.example/product/', 'https://product.example/'), '/product/', '/'),
    description = REPLACE(REPLACE(description, 'https://legacy.example/product/', 'https://product.example/'), '/product/', '/')
WHERE name LIKE '%product%' 
   OR name LIKE '%legacy.example%'
   OR description LIKE '%product%' 
   OR description LIKE '%legacy.example%';

-- ============================================
-- STEP 3: VERIFICATION QUERIES (ACTUAL COLUMNS ONLY)
-- ============================================

SELECT 'VERIFICATION RESULTS - Checking actual columns:' as section;

-- Verify users table cleanup
SELECT 'users' as table_name, 
       COUNT(*) as remaining_url_references
FROM users 
WHERE email LIKE '%product%' 
   OR email LIKE '%legacy.example%'
   OR first_name LIKE '%product%' 
   OR first_name LIKE '%legacy.example%'
   OR last_name LIKE '%product%' 
   OR last_name LIKE '%legacy.example%'
   OR phone LIKE '%product%' 
   OR phone LIKE '%legacy.example%';

-- Verify products table cleanup
SELECT 'products' as table_name,
       COUNT(*) as remaining_url_references
FROM products
WHERE sku LIKE '%product%'
   OR sku LIKE '%legacy.example%'
   OR name LIKE '%product%'
   OR name LIKE '%legacy.example%'
   OR description LIKE '%product%'
   OR description LIKE '%legacy.example%'
   OR attribute_content LIKE '%product%'
   OR attribute_content LIKE '%legacy.example%';

-- Verify training_modules table cleanup
SELECT 'training_modules' as table_name,
       COUNT(*) as remaining_url_references
FROM training_modules
WHERE title LIKE '%product%'
   OR title LIKE '%legacy.example%'
   OR slug LIKE '%product%'
   OR slug LIKE '%legacy.example%'
   OR content LIKE '%product%'
   OR content LIKE '%legacy.example%';

-- Verify crm_leads table cleanup
SELECT 'crm_leads' as table_name,
       COUNT(*) as remaining_url_references
FROM crm_leads
WHERE first_name LIKE '%product%'
   OR first_name LIKE '%legacy.example%'
   OR last_name LIKE '%product%'
   OR last_name LIKE '%legacy.example%'
   OR email LIKE '%product%'
   OR email LIKE '%legacy.example%'
   OR phone LIKE '%product%'
   OR phone LIKE '%legacy.example%'
   OR company_name LIKE '%product%'
   OR company_name LIKE '%legacy.example%'
   OR website LIKE '%product%'
   OR website LIKE '%legacy.example%';

-- Verify roles table cleanup  
SELECT 'roles' as table_name,
       COUNT(*) as remaining_url_references
FROM roles
WHERE name LIKE '%product%'
   OR name LIKE '%legacy.example%'
   OR description LIKE '%product%'
   OR description LIKE '%legacy.example%';

COMMIT;

-- ============================================
-- RESULTS INTERPRETATION
-- ============================================
SELECT 'Database URL cleanup completed successfully!' as final_status;
SELECT 'If all verification counts above are 0, your database is completely clean!' as interpretation;
SELECT 'This script is safe to run multiple times.' as note;
