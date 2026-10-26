-- Binary-Safe Database URL Cleanup Migration
-- Date: 2025-01-29
-- Purpose: Clean /product/ and legacy.example URLs from actual database schema
-- Rules: Following 002-SQL-FORMATTING-RULES.md (binary-safe, idempotent)
-- Schema: Based on foggksfx_product.sql actual structure

START TRANSACTION;

-- ============================================
-- STEP 1: VERIFY DATABASE SCHEMA
-- ============================================

SELECT DATABASE() as current_database;
SELECT 'Binary-safe URL cleanup starting...' as status;

-- ============================================
-- STEP 2: CLEAN ACTUAL TABLES WITH CONFIRMED COLUMNS
-- Based on foggksfx_product.sql schema analysis
-- ============================================

-- Fix users table (confirmed columns from schema)
-- Columns: email, first_name, last_name, phone
UPDATE users 
SET 
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

SELECT ROW_COUNT() as users_rows_updated;

-- Fix products table (confirmed columns from schema)
-- Columns: sku, name, description, attribute_content
UPDATE products 
SET 
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

SELECT ROW_COUNT() as products_rows_updated;

-- Fix training_modules table (confirmed columns from schema)
-- Columns: slug, title, content
UPDATE training_modules 
SET 
    slug = REPLACE(REPLACE(slug, 'https://legacy.example/product/', 'https://product.example/'), '/product/', '/'),
    title = REPLACE(REPLACE(title, 'https://legacy.example/product/', 'https://product.example/'), '/product/', '/'),
    content = REPLACE(REPLACE(content, 'https://legacy.example/product/', 'https://product.example/'), '/product/', '/')
WHERE slug LIKE '%product%' 
   OR slug LIKE '%legacy.example%'
   OR title LIKE '%product%' 
   OR title LIKE '%legacy.example%'
   OR content LIKE '%product%' 
   OR content LIKE '%legacy.example%';

SELECT ROW_COUNT() as training_modules_rows_updated;

-- Fix crm_leads table (confirmed columns from schema)
-- Columns: first_name, last_name, email, phone, company_name, website
UPDATE crm_leads 
SET 
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

SELECT ROW_COUNT() as crm_leads_rows_updated;

-- Fix roles table (checked actual schema - only 'name' column exists)
UPDATE roles 
SET name = REPLACE(REPLACE(name, 'https://legacy.example/product/', 'https://product.example/'), '/product/', '/')
WHERE name LIKE '%product%' OR name LIKE '%legacy.example%';

SELECT ROW_COUNT() as roles_rows_updated;

-- ============================================
-- STEP 3: VERIFICATION QUERIES (EXPLICIT COLUMNS)
-- ============================================

SELECT 'VERIFICATION: Checking for remaining URL references...' as verification_header;

-- Check users table
SELECT 'users' as table_name, 
       COUNT(*) as remaining_references,
       GROUP_CONCAT(DISTINCT 
           CASE 
               WHEN email LIKE '%product%' OR email LIKE '%legacy.example%' THEN 'email'
               WHEN first_name LIKE '%product%' OR first_name LIKE '%legacy.example%' THEN 'first_name'
               WHEN last_name LIKE '%product%' OR last_name LIKE '%legacy.example%' THEN 'last_name'
               WHEN phone LIKE '%product%' OR phone LIKE '%legacy.example%' THEN 'phone'
           END
       ) as affected_columns
FROM users 
WHERE email LIKE '%product%' 
   OR email LIKE '%legacy.example%'
   OR first_name LIKE '%product%' 
   OR first_name LIKE '%legacy.example%'
   OR last_name LIKE '%product%' 
   OR last_name LIKE '%legacy.example%'
   OR phone LIKE '%product%' 
   OR phone LIKE '%legacy.example%';

-- Check products table
SELECT 'products' as table_name,
       COUNT(*) as remaining_references,
       GROUP_CONCAT(DISTINCT 
           CASE 
               WHEN sku LIKE '%product%' OR sku LIKE '%legacy.example%' THEN 'sku'
               WHEN name LIKE '%product%' OR name LIKE '%legacy.example%' THEN 'name'
               WHEN description LIKE '%product%' OR description LIKE '%legacy.example%' THEN 'description'
               WHEN attribute_content LIKE '%product%' OR attribute_content LIKE '%legacy.example%' THEN 'attribute_content'
           END
       ) as affected_columns
FROM products
WHERE sku LIKE '%product%'
   OR sku LIKE '%legacy.example%'
   OR name LIKE '%product%'
   OR name LIKE '%legacy.example%'
   OR description LIKE '%product%'
   OR description LIKE '%legacy.example%'
   OR attribute_content LIKE '%product%'
   OR attribute_content LIKE '%legacy.example%';

-- Check training_modules table
SELECT 'training_modules' as table_name,
       COUNT(*) as remaining_references,
       GROUP_CONCAT(DISTINCT 
           CASE 
               WHEN slug LIKE '%product%' OR slug LIKE '%legacy.example%' THEN 'slug'
               WHEN title LIKE '%product%' OR title LIKE '%legacy.example%' THEN 'title'
               WHEN content LIKE '%product%' OR content LIKE '%legacy.example%' THEN 'content'
           END
       ) as affected_columns
FROM training_modules
WHERE slug LIKE '%product%'
   OR slug LIKE '%legacy.example%'
   OR title LIKE '%product%'
   OR title LIKE '%legacy.example%'
   OR content LIKE '%product%'
   OR content LIKE '%legacy.example%';

-- Check crm_leads table
SELECT 'crm_leads' as table_name,
       COUNT(*) as remaining_references,
       GROUP_CONCAT(DISTINCT 
           CASE 
               WHEN first_name LIKE '%product%' OR first_name LIKE '%legacy.example%' THEN 'first_name'
               WHEN last_name LIKE '%product%' OR last_name LIKE '%legacy.example%' THEN 'last_name'
               WHEN email LIKE '%product%' OR email LIKE '%legacy.example%' THEN 'email'
               WHEN phone LIKE '%product%' OR phone LIKE '%legacy.example%' THEN 'phone'
               WHEN company_name LIKE '%product%' OR company_name LIKE '%legacy.example%' THEN 'company_name'
               WHEN website LIKE '%product%' OR website LIKE '%legacy.example%' THEN 'website'
           END
       ) as affected_columns
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

COMMIT;

-- ============================================
-- SUMMARY
-- ============================================
SELECT 'Database URL cleanup completed successfully!' as final_status;
SELECT 'Binary-safe migration following 002-SQL-FORMATTING-RULES.md' as compliance;
SELECT 'Safe to run multiple times - idempotent design' as safety_note;
