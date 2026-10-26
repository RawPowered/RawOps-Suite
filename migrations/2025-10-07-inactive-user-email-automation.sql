-- 2025-10-07-inactive-user-email-automation.sql
-- Inactive user email automation system for Product platform
-- Tracks user login activity and manages automated email notifications

-- Add last_login_at column to users table if it doesn't exist
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS last_login_at TIMESTAMP NULL DEFAULT NULL,
ADD INDEX IF NOT EXISTS idx_last_login (last_login_at);

-- Create table to track user login activity and inactive email automation
CREATE TABLE IF NOT EXISTS user_inactive_tracking (
    id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT(20) UNSIGNED NOT NULL,
    last_login_at TIMESTAMP NULL,
    last_activity_at TIMESTAMP NULL,
    days_inactive INT DEFAULT 0,
    email_3_day_sent TINYINT(1) DEFAULT 0,
    email_3_day_sent_at TIMESTAMP NULL,
    email_7_day_sent TINYINT(1) DEFAULT 0,
    email_7_day_sent_at TIMESTAMP NULL,
    email_14_day_sent TINYINT(1) DEFAULT 0,
    email_14_day_sent_at TIMESTAMP NULL,
    email_30_day_sent TINYINT(1) DEFAULT 0,
    email_30_day_sent_at TIMESTAMP NULL,
    email_35_day_sent TINYINT(1) DEFAULT 0,
    email_35_day_sent_at TIMESTAMP NULL,
    pending_review TINYINT(1) DEFAULT 0,
    pending_review_since TIMESTAMP NULL,
    account_suspended TINYINT(1) DEFAULT 0,
    account_suspended_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_tracking (user_id),
    INDEX idx_days_inactive (days_inactive),
    INDEX idx_pending_review (pending_review),
    INDEX idx_last_login_tracking (last_login_at),
    INDEX idx_last_activity (last_activity_at),
    CONSTRAINT fk_user_inactive_tracking_user_id 
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Create email events for inactive user notifications
INSERT IGNORE INTO email_events (event_key, name, description, scope, is_active, created_at) VALUES
('user_inactive_3_days', 'User Inactive 3 Days', 'Sent when user has not logged in for 3 days', 'platform', 1, NOW()),
('user_inactive_7_days', 'User Inactive 7 Days', 'Sent when user has not logged in for 7 days', 'platform', 1, NOW()),
('user_inactive_14_days', 'User Inactive 14 Days', 'Sent when user has not logged in for 14 days', 'platform', 1, NOW()),
('user_inactive_30_days', 'User Inactive 30 Days', 'Sent when user has not logged in for 30 days with review warning', 'platform', 1, NOW()),
('user_inactive_35_days', 'User Inactive 35 Days - Suspension Notice', 'Sent when user account is suspended for inactivity', 'platform', 1, NOW());

-- Initialize tracking for existing users (only trainees and sales agents, not admins)
INSERT IGNORE INTO user_inactive_tracking (user_id, last_login_at, last_activity_at, days_inactive)
SELECT 
    u.id,
    u.last_login_at,
    COALESCE(u.last_login_at, u.created_at) as last_activity_at,
    CASE 
        WHEN u.last_login_at IS NULL THEN DATEDIFF(NOW(), u.created_at)
        ELSE DATEDIFF(NOW(), u.last_login_at)
    END as days_inactive
FROM users u
JOIN user_roles ur ON ur.user_id = u.id
JOIN roles r ON r.id = ur.role_id
WHERE r.name IN ('trainee', 'sales_agent')
  AND u.id NOT IN (SELECT user_id FROM user_inactive_tracking WHERE user_id IS NOT NULL);

-- Create admin view for inactive user management
CREATE OR REPLACE VIEW admin_inactive_users_summary AS
SELECT 
    u.id,
    u.first_name,
    u.last_name,
    u.email,
    r.name as role_name,
    uit.last_login_at,
    uit.last_activity_at,
    uit.days_inactive,
    uit.pending_review,
    uit.pending_review_since,
    uit.account_suspended,
    uit.account_suspended_at,
    CASE 
        WHEN uit.days_inactive >= 35 THEN '35+ days'
        WHEN uit.days_inactive >= 30 THEN '30+ days'
        WHEN uit.days_inactive >= 14 THEN '14+ days'
        WHEN uit.days_inactive >= 7 THEN '7+ days'
        WHEN uit.days_inactive >= 3 THEN '3+ days'
        ELSE 'Active'
    END as inactive_status,
    CASE 
        WHEN uit.email_35_day_sent THEN 'Suspended'
        WHEN uit.email_30_day_sent THEN 'Final Warning Sent'
        WHEN uit.email_14_day_sent THEN '14-Day Notice Sent'
        WHEN uit.email_7_day_sent THEN '7-Day Notice Sent'
        WHEN uit.email_3_day_sent THEN '3-Day Notice Sent'
        ELSE 'No Emails Sent'
    END as email_status
FROM users u
JOIN user_roles ur ON ur.user_id = u.id
JOIN roles r ON r.id = ur.role_id
LEFT JOIN user_inactive_tracking uit ON uit.user_id = u.id
WHERE r.name IN ('trainee', 'sales_agent')
ORDER BY uit.days_inactive DESC, u.last_name, u.first_name;

-- Create procedure to update inactive user tracking
DELIMITER $$
CREATE PROCEDURE IF NOT EXISTS UpdateInactiveUserTracking()
BEGIN
    -- Update days_inactive for all tracked users
    UPDATE user_inactive_tracking uit
    JOIN users u ON u.id = uit.user_id
    SET 
        uit.days_inactive = CASE 
            WHEN u.last_login_at IS NULL THEN DATEDIFF(NOW(), u.created_at)
            ELSE DATEDIFF(NOW(), u.last_login_at)
        END,
        uit.last_login_at = u.last_login_at,
        uit.last_activity_at = COALESCE(u.last_login_at, u.created_at),
        uit.updated_at = NOW();
    
    -- Mark users for pending review at 30 days if not already marked
    UPDATE user_inactive_tracking 
    SET 
        pending_review = 1,
        pending_review_since = NOW()
    WHERE days_inactive >= 30 
        AND pending_review = 0 
        AND account_suspended = 0;
    
    -- Mark users as suspended at 35 days if not already suspended
    UPDATE user_inactive_tracking 
    SET 
        account_suspended = 1,
        account_suspended_at = NOW(),
        pending_review = 0
    WHERE days_inactive >= 35 
        AND account_suspended = 0;
END$$
DELIMITER ;

-- Create function to get inactive user counts by category
DELIMITER $$
CREATE FUNCTION IF NOT EXISTS GetInactiveUserCounts() 
RETURNS JSON
READS SQL DATA
DETERMINISTIC
BEGIN
    DECLARE result JSON;
    
    SELECT JSON_OBJECT(
        'total_inactive', COUNT(*),
        'inactive_3_days', SUM(CASE WHEN days_inactive >= 3 AND days_inactive < 7 THEN 1 ELSE 0 END),
        'inactive_7_days', SUM(CASE WHEN days_inactive >= 7 AND days_inactive < 14 THEN 1 ELSE 0 END),
        'inactive_14_days', SUM(CASE WHEN days_inactive >= 14 AND days_inactive < 30 THEN 1 ELSE 0 END),
        'inactive_30_days', SUM(CASE WHEN days_inactive >= 30 AND days_inactive < 35 THEN 1 ELSE 0 END),
        'suspended_35_days', SUM(CASE WHEN days_inactive >= 35 THEN 1 ELSE 0 END),
        'pending_review', SUM(CASE WHEN pending_review = 1 THEN 1 ELSE 0 END)
    ) INTO result
    FROM user_inactive_tracking uit
    JOIN users u ON u.id = uit.user_id
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    WHERE r.name IN ('trainee', 'sales_agent')
        AND uit.days_inactive >= 3;
    
    RETURN result;
END$$
DELIMITER ;

-- Add additional indexes for performance
ALTER TABLE user_inactive_tracking 
ADD INDEX IF NOT EXISTS idx_email_flags (email_3_day_sent, email_7_day_sent, email_14_day_sent, email_30_day_sent, email_35_day_sent),
ADD INDEX IF NOT EXISTS idx_automation_status (days_inactive, pending_review, account_suspended);

-- Insert initial email notification rules (will be created when templates are ready)
-- These will be populated by the email automation system
