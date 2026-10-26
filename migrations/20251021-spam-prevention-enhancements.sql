-- ============================================================
-- Migration: Spam Prevention Enhancements
-- Date: 2025-10-21
-- Description: Add indexes, constraints, and stored procedures
--              for comprehensive spam prevention system
-- ============================================================
-- 
-- Spam Prevention Rules:
-- 1. Name fields: Max 30 characters, max 3 uppercase letters, max 2 apostrophes
-- 2. Blacklist types: email, domain, phone, name_pattern, ip_address
-- 3. Comprehensive blacklisting on spam marking
-- 4. Registration validation against all blacklist types
--
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 0;

-- Add indexes to spam_blacklist for performance
ALTER TABLE `spam_blacklist`
ADD INDEX `idx_entry_type_active` (`entry_type`, `is_active`),
ADD INDEX `idx_entry_value_active` (`entry_value`, `is_active`),
ADD INDEX `idx_added_by_admin` (`added_by_admin_id`);

-- Add indexes to spam_registration_attempts for tracking
ALTER TABLE `spam_registration_attempts`
ADD INDEX `idx_email_created` (`email`, `created_at`),
ADD INDEX `idx_ip_created` (`ip_address`, `created_at`);

-- ============================================================
-- Stored Procedure: Check Spam Blacklist
-- ============================================================
-- Checks if email, phone, name, or IP is blacklisted
-- Returns number of violations found
-- ============================================================

DROP PROCEDURE IF EXISTS `sp_check_spam_blacklist`;

DELIMITER $$

CREATE PROCEDURE `sp_check_spam_blacklist`(
    IN p_email VARCHAR(190),
    IN p_phone VARCHAR(30),
    IN p_first_name VARCHAR(100),
    IN p_last_name VARCHAR(100),
    IN p_ip_address VARCHAR(45),
    OUT p_is_spam TINYINT(1),
    OUT p_violation_count INT,
    OUT p_violation_types VARCHAR(255)
)
BEGIN
    DECLARE v_violations TEXT DEFAULT '';
    DECLARE v_count INT DEFAULT 0;
    DECLARE v_domain VARCHAR(255);
    DECLARE v_full_name VARCHAR(200);
    
    -- Check email exact match
    IF p_email IS NOT NULL AND p_email != '' THEN
        IF EXISTS (
            SELECT 1 FROM spam_blacklist 
            WHERE is_active = 1 
            AND entry_type = 'email' 
            AND entry_value = p_email
        ) THEN
            SET v_count = v_count + 1;
            SET v_violations = CONCAT(v_violations, 'email,');
        END IF;
        
        -- Check email domain
        SET v_domain = SUBSTRING_INDEX(p_email, '@', -1);
        IF v_domain IS NOT NULL AND v_domain != '' THEN
            IF EXISTS (
                SELECT 1 FROM spam_blacklist 
                WHERE is_active = 1 
                AND entry_type = 'domain' 
                AND entry_value = v_domain
            ) THEN
                SET v_count = v_count + 1;
                SET v_violations = CONCAT(v_violations, 'domain,');
            END IF;
        END IF;
    END IF;
    
    -- Check phone number
    IF p_phone IS NOT NULL AND p_phone != '' THEN
        IF EXISTS (
            SELECT 1 FROM spam_blacklist 
            WHERE is_active = 1 
            AND entry_type = 'phone' 
            AND entry_value = p_phone
        ) THEN
            SET v_count = v_count + 1;
            SET v_violations = CONCAT(v_violations, 'phone,');
        END IF;
    END IF;
    
    -- Check name pattern
    IF p_first_name IS NOT NULL AND p_last_name IS NOT NULL THEN
        SET v_full_name = CONCAT(TRIM(p_first_name), ' ', TRIM(p_last_name));
        IF EXISTS (
            SELECT 1 FROM spam_blacklist 
            WHERE is_active = 1 
            AND entry_type = 'name_pattern' 
            AND (entry_value = v_full_name 
                 OR entry_value = p_first_name 
                 OR entry_value = p_last_name)
        ) THEN
            SET v_count = v_count + 1;
            SET v_violations = CONCAT(v_violations, 'name_pattern,');
        END IF;
    END IF;
    
    -- Check IP address
    IF p_ip_address IS NOT NULL AND p_ip_address != '' THEN
        IF EXISTS (
            SELECT 1 FROM spam_blacklist 
            WHERE is_active = 1 
            AND entry_type = 'ip_address' 
            AND entry_value = p_ip_address
        ) THEN
            SET v_count = v_count + 1;
            SET v_violations = CONCAT(v_violations, 'ip_address,');
        END IF;
    END IF;
    
    -- Set output parameters
    SET p_is_spam = IF(v_count > 0, 1, 0);
    SET p_violation_count = v_count;
    SET p_violation_types = TRIM(BOTH ',' FROM v_violations);
END$$

DELIMITER ;

-- ============================================================
-- Stored Procedure: Add Comprehensive Spam Blacklist
-- ============================================================
-- Adds email, phone, name pattern, and IP to blacklist
-- Used when marking users as spam
-- ============================================================

DROP PROCEDURE IF EXISTS `sp_add_comprehensive_spam_blacklist`;

DELIMITER $$

CREATE PROCEDURE `sp_add_comprehensive_spam_blacklist`(
    IN p_user_id BIGINT UNSIGNED,
    IN p_reason TEXT,
    IN p_admin_id BIGINT UNSIGNED,
    OUT p_entries_added INT,
    OUT p_details TEXT
)
BEGIN
    DECLARE v_email VARCHAR(190);
    DECLARE v_phone VARCHAR(30);
    DECLARE v_first_name VARCHAR(100);
    DECLARE v_last_name VARCHAR(100);
    DECLARE v_full_name VARCHAR(200);
    DECLARE v_ip_address VARCHAR(45);
    DECLARE v_entries INT DEFAULT 0;
    DECLARE v_details TEXT DEFAULT '';
    
    -- Get user data
    SELECT email, phone, first_name, last_name
    INTO v_email, v_phone, v_first_name, v_last_name
    FROM users
    WHERE id = p_user_id;
    
    -- Get registration IP from login_activity
    SELECT ip_address INTO v_ip_address
    FROM login_activity
    WHERE user_id = p_user_id
    ORDER BY created_at ASC
    LIMIT 1;
    
    -- Build full name
    IF v_first_name IS NOT NULL AND v_last_name IS NOT NULL THEN
        SET v_full_name = CONCAT(TRIM(v_first_name), ' ', TRIM(v_last_name));
    END IF;
    
    -- Insert email to blacklist
    IF v_email IS NOT NULL AND v_email != '' THEN
        INSERT INTO spam_blacklist (entry_type, entry_value, reason, added_by_admin_id, is_active)
        VALUES ('email', v_email, p_reason, p_admin_id, 1)
        ON DUPLICATE KEY UPDATE 
            reason = VALUES(reason),
            added_by_admin_id = VALUES(added_by_admin_id),
            is_active = 1;
        
        IF ROW_COUNT() > 0 THEN
            SET v_entries = v_entries + 1;
            SET v_details = CONCAT(v_details, 'email:', v_email, '; ');
        END IF;
    END IF;
    
    -- Insert phone to blacklist
    IF v_phone IS NOT NULL AND v_phone != '' THEN
        INSERT INTO spam_blacklist (entry_type, entry_value, reason, added_by_admin_id, is_active)
        VALUES ('phone', v_phone, p_reason, p_admin_id, 1)
        ON DUPLICATE KEY UPDATE 
            reason = VALUES(reason),
            added_by_admin_id = VALUES(added_by_admin_id),
            is_active = 1;
        
        IF ROW_COUNT() > 0 THEN
            SET v_entries = v_entries + 1;
            SET v_details = CONCAT(v_details, 'phone:', v_phone, '; ');
        END IF;
    END IF;
    
    -- Insert name pattern to blacklist
    IF v_full_name IS NOT NULL AND v_full_name != '' THEN
        INSERT INTO spam_blacklist (entry_type, entry_value, reason, added_by_admin_id, is_active)
        VALUES ('name_pattern', v_full_name, p_reason, p_admin_id, 1)
        ON DUPLICATE KEY UPDATE 
            reason = VALUES(reason),
            added_by_admin_id = VALUES(added_by_admin_id),
            is_active = 1;
        
        IF ROW_COUNT() > 0 THEN
            SET v_entries = v_entries + 1;
            SET v_details = CONCAT(v_details, 'name:', v_full_name, '; ');
        END IF;
    END IF;
    
    -- Insert IP address to blacklist
    IF v_ip_address IS NOT NULL AND v_ip_address != '' THEN
        INSERT INTO spam_blacklist (entry_type, entry_value, reason, added_by_admin_id, is_active)
        VALUES ('ip_address', v_ip_address, p_reason, p_admin_id, 1)
        ON DUPLICATE KEY UPDATE 
            reason = VALUES(reason),
            added_by_admin_id = VALUES(added_by_admin_id),
            is_active = 1;
        
        IF ROW_COUNT() > 0 THEN
            SET v_entries = v_entries + 1;
            SET v_details = CONCAT(v_details, 'ip:', v_ip_address, '; ');
        END IF;
    END IF;
    
    -- Set output parameters
    SET p_entries_added = v_entries;
    SET p_details = TRIM(BOTH '; ' FROM v_details);
END$$

DELIMITER ;

-- ============================================================
-- Add unique constraint to spam_blacklist to prevent duplicates
-- ============================================================

ALTER TABLE `spam_blacklist`
ADD UNIQUE KEY `uk_entry_type_value` (`entry_type`, `entry_value`);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- Migration Complete
-- ============================================================



