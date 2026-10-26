-- ============================================================================
-- Domain Migration: product.example → product.example
-- Date: 2025-10-02
-- Description: Updates all database URLs to new domain
-- ============================================================================

-- Set old and new domains
SET @old_domain = 'https://product.example';
SET @old_domain_http = 'http://product.example';
SET @old_domain_bare = 'product.example';
SET @new_domain = 'https://product.example';
SET @new_domain_bare = 'product.example';

-- Backup warning
SELECT 'IMPORTANT: Please backup your database before running this migration!' AS WARNING;

-- ============================================================================
-- Core Tables: Site Configuration
-- ============================================================================

-- Update site_config table
UPDATE site_config 
SET value = REPLACE(REPLACE(REPLACE(value, @old_domain, @new_domain), @old_domain_http, @new_domain), @old_domain_bare, @new_domain_bare)
WHERE value LIKE CONCAT('%', @old_domain_bare, '%');

-- ============================================================================
-- User Tables
-- ============================================================================

-- Update users bio field
UPDATE users 
SET bio = REPLACE(REPLACE(REPLACE(bio, @old_domain, @new_domain), @old_domain_http, @new_domain), @old_domain_bare, @new_domain_bare)
WHERE bio IS NOT NULL AND bio LIKE CONCAT('%', @old_domain_bare, '%');

-- Update user_meta
UPDATE user_meta 
SET meta_value = REPLACE(REPLACE(REPLACE(meta_value, @old_domain, @new_domain), @old_domain_http, @new_domain), @old_domain_bare, @new_domain_bare)
WHERE meta_value LIKE CONCAT('%', @old_domain_bare, '%');

-- ============================================================================
-- Training/LMS Tables
-- ============================================================================

-- Update training modules
UPDATE modules 
SET content = REPLACE(REPLACE(REPLACE(content, @old_domain, @new_domain), @old_domain_http, @new_domain), @old_domain_bare, @new_domain_bare)
WHERE content LIKE CONCAT('%', @old_domain_bare, '%');

-- Update module sections
UPDATE module_sections 
SET content = REPLACE(REPLACE(REPLACE(content, @old_domain, @new_domain), @old_domain_http, @new_domain), @old_domain_bare, @new_domain_bare)
WHERE content LIKE CONCAT('%', @old_domain_bare, '%');

-- ============================================================================
-- Email Tables
-- ============================================================================

-- Update email templates
UPDATE email_templates 
SET body = REPLACE(REPLACE(REPLACE(body, @old_domain, @new_domain), @old_domain_http, @new_domain), @old_domain_bare, @new_domain_bare),
    subject = REPLACE(REPLACE(REPLACE(subject, @old_domain, @new_domain), @old_domain_http, @new_domain), @old_domain_bare, @new_domain_bare)
WHERE body LIKE CONCAT('%', @old_domain_bare, '%') OR subject LIKE CONCAT('%', @old_domain_bare, '%');

-- ============================================================================
-- CRM Tables
-- ============================================================================

-- Update CRM leads
UPDATE crm_leads 
SET notes = REPLACE(REPLACE(REPLACE(notes, @old_domain, @new_domain), @old_domain_http, @new_domain), @old_domain_bare, @new_domain_bare)
WHERE notes IS NOT NULL AND notes LIKE CONCAT('%', @old_domain_bare, '%');

-- Update CRM accounts
UPDATE crm_accounts 
SET description = REPLACE(REPLACE(REPLACE(description, @old_domain, @new_domain), @old_domain_http, @new_domain), @old_domain_bare, @new_domain_bare)
WHERE description IS NOT NULL AND description LIKE CONCAT('%', @old_domain_bare, '%');

-- ============================================================================
-- Audit Log / History Tables
-- ============================================================================

-- Update admin history (metadata only, keep historical record of old URLs in description)
UPDATE admin_history 
SET metadata = REPLACE(REPLACE(REPLACE(metadata, @old_domain, @new_domain), @old_domain_http, @new_domain), @old_domain_bare, @new_domain_bare)
WHERE metadata LIKE CONCAT('%', @old_domain_bare, '%');

-- ============================================================================
-- Verification Report
-- ============================================================================

-- Count remaining references (should be 0 or close to 0)
SELECT 
    'site_config' AS table_name,
    COUNT(*) AS remaining_references
FROM site_config 
WHERE value LIKE CONCAT('%', @old_domain_bare, '%')

UNION ALL

SELECT 
    'users' AS table_name,
    COUNT(*) AS remaining_references
FROM users 
WHERE bio LIKE CONCAT('%', @old_domain_bare, '%')

UNION ALL

SELECT 
    'modules' AS table_name,
    COUNT(*) AS remaining_references
FROM modules 
WHERE content LIKE CONCAT('%', @old_domain_bare, '%')

UNION ALL

SELECT 
    'email_templates' AS table_name,
    COUNT(*) AS remaining_references
FROM email_templates 
WHERE body LIKE CONCAT('%', @old_domain_bare, '%') OR subject LIKE CONCAT('%', @old_domain_bare, '%');

-- ============================================================================
-- Success Message
-- ============================================================================
SELECT CONCAT('Migration complete! New domain: ', @new_domain) AS STATUS;


