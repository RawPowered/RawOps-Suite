-- Demo Drop-off Workflow Update
-- Binary migration: Can be run multiple times safely
-- Version: 1.0.0
-- Date: 2025-09-17

-- =============================================
-- 1. Update CRM Leads with Demo-specific Statuses
-- =============================================

-- First, modify the lead_status enum to include demo-specific statuses
ALTER TABLE `crm_leads` 
MODIFY COLUMN `lead_status` ENUM(
    'new',
    'contacted',
    'qualified',
    'proposal_sent',
    'negotiating',
    'won',
    'lost',
    'dormant',
    -- New demo-specific statuses
    'info_not_gotten',
    'info_obtained_demo_dropped',
    'info_obtained_demo_pending',
    'drop_successful',
    'customer_review',
    'customer_no_review', 
    'customer_follow_up',
    'no_response_follow_up',
    'objection_legal',
    'objection_price_reassign',
    'objection_other_reassign',
    'do_not_contact'
) DEFAULT 'new';

-- =============================================
-- 2. Create Demo Drop-off Types Table
-- =============================================

CREATE TABLE IF NOT EXISTS `demo_dropoff_types` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_code` varchar(50) NOT NULL,
  `product_name` varchar(200) NOT NULL,
  `blend_type` varchar(20) NOT NULL,
  `size_oz` decimal(5,2) NOT NULL,
  `total_mg` int(11) NOT NULL,
  `mg_per_oz` decimal(5,2) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_code` (`product_code`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert the two demo types
INSERT IGNORE INTO `demo_dropoff_types` 
(`product_code`, `product_name`, `blend_type`, `size_oz`, `total_mg`, `mg_per_oz`) 
VALUES
('DEMO-product-pack-32-20', 'Product product-pack Demo (32/20)', '32/20', 4.00, 80, 20.00),
('DEMO-product-pack-32-75', 'Product product-pack Demo (32/75)', '32/75', 4.00, 300, 75.00);

-- =============================================
-- 3. Create CRM Demo Drop-offs Table
-- =============================================

CREATE TABLE IF NOT EXISTS `crm_demo_dropoffs` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `lead_id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED NOT NULL,
  `demo_type_id` bigint(20) UNSIGNED NOT NULL,
  `dropoff_date` date NOT NULL,
  `follow_up_date` date DEFAULT NULL,
  `follow_up_status` enum('pending','completed','overdue','cancelled') DEFAULT 'pending',
  `follow_up_task_id` bigint(20) UNSIGNED DEFAULT NULL,
  `demo_status` enum('dropped','confirmed','used','unused','lost') DEFAULT 'dropped',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_lead_demo_type` (`lead_id`, `demo_type_id`),
  KEY `idx_agent` (`agent_id`),
  KEY `idx_follow_up` (`follow_up_date`, `follow_up_status`),
  KEY `idx_demo_type` (`demo_type_id`),
  KEY `idx_follow_task` (`follow_up_task_id`),
  CONSTRAINT `fk_demo_lead` FOREIGN KEY (`lead_id`) REFERENCES `crm_leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_demo_agent` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_demo_type` FOREIGN KEY (`demo_type_id`) REFERENCES `demo_dropoff_types` (`id`),
  CONSTRAINT `fk_demo_task` FOREIGN KEY (`follow_up_task_id`) REFERENCES `crm_tasks` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 4. Create Demo Requests Table (for web form submissions)
-- =============================================

CREATE TABLE IF NOT EXISTS `crm_demo_requests` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_name` varchar(255) NOT NULL,
  `contact_first_name` varchar(100) NOT NULL,
  `contact_last_name` varchar(100) NOT NULL,
  `contact_email` varchar(255) NOT NULL,
  `contact_phone` varchar(50) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(50) DEFAULT NULL,
  `zip_code` varchar(20) DEFAULT NULL,
  `lead_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` enum('pending','fulfilled','cancelled') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `fulfilled_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contact_email` (`contact_email`),
  KEY `idx_status` (`status`),
  KEY `idx_lead` (`lead_id`),
  CONSTRAINT `fk_request_lead` FOREIGN KEY (`lead_id`) REFERENCES `crm_leads` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 5. Add Constraints to Existing Tables
-- =============================================

-- Add crm_lead_id to promo_demo_leads if not exists
ALTER TABLE `promo_demo_leads` 
ADD COLUMN IF NOT EXISTS `crm_lead_id` bigint(20) UNSIGNED DEFAULT NULL,
ADD KEY IF NOT EXISTS `idx_crm_lead` (`crm_lead_id`);

-- Add demo_lead_id to wholesale_accounts for tracking
ALTER TABLE `wholesale_accounts`
ADD COLUMN IF NOT EXISTS `demo_lead_id` bigint(20) UNSIGNED DEFAULT NULL,
ADD KEY IF NOT EXISTS `idx_demo_lead` (`demo_lead_id`);

-- =============================================
-- 6. Create Demo Follow-up View
-- =============================================

CREATE OR REPLACE VIEW `crm_demo_followup_view` AS
SELECT 
    d.id,
    d.lead_id,
    l.first_name,
    l.last_name,
    l.email,
    l.phone,
    l.company_name,
    l.assigned_agent_id,
    CONCAT(a.first_name, ' ', a.last_name) as agent_name,
    d.demo_type_id,
    dt.product_name,
    dt.blend_type,
    d.dropoff_date,
    d.follow_up_date,
    d.follow_up_status,
    d.demo_status,
    DATEDIFF(d.follow_up_date, CURDATE()) as days_until_followup,
    CASE 
        WHEN d.follow_up_status = 'pending' AND d.follow_up_date < CURDATE() THEN 'overdue'
        WHEN d.follow_up_status = 'pending' AND d.follow_up_date = CURDATE() THEN 'due_today'
        WHEN d.follow_up_status = 'pending' AND d.follow_up_date > CURDATE() THEN 'upcoming'
        ELSE d.follow_up_status
    END as followup_urgency
FROM `crm_demo_dropoffs` d
JOIN `crm_leads` l ON l.id = d.lead_id
JOIN `users` a ON a.id = d.agent_id
JOIN `demo_dropoff_types` dt ON dt.id = d.demo_type_id;

-- =============================================
-- 7. Stored Procedures for Demo Management
-- =============================================

DELIMITER $$

-- Procedure to create demo drop-off with validation
CREATE PROCEDURE IF NOT EXISTS `sp_create_demo_dropoff`(
    IN p_lead_id BIGINT,
    IN p_agent_id BIGINT,
    IN p_demo_type_id BIGINT,
    IN p_dropoff_date DATE,
    IN p_notes TEXT,
    OUT p_success BOOLEAN,
    OUT p_message VARCHAR(255),
    OUT p_dropoff_id BIGINT
)
sp_main: BEGIN
    DECLARE v_demo_count INT DEFAULT 0;
    DECLARE v_follow_up_date DATE;
    DECLARE v_task_id BIGINT;
    
    SET p_success = FALSE;
    SET p_dropoff_id = NULL;
    
    -- Check if lead exists
    IF NOT EXISTS (SELECT 1 FROM `crm_leads` WHERE id = p_lead_id) THEN
        SET p_message = 'Lead not found';
        LEAVE sp_main;
    END IF;
    
    -- Check demo limit (max 2 per lead)
    SELECT COUNT(*) INTO v_demo_count
    FROM `crm_demo_dropoffs` 
    WHERE lead_id = p_lead_id;
    
    IF v_demo_count >= 2 THEN
        SET p_message = 'Demo limit reached (max 2 per store)';
        LEAVE sp_main;
    END IF;
    
    -- Check if this specific demo type already dropped
    IF EXISTS (SELECT 1 FROM `crm_demo_dropoffs` WHERE lead_id = p_lead_id AND demo_type_id = p_demo_type_id) THEN
        SET p_message = 'This demo type already dropped for this lead';
        LEAVE sp_main;
    END IF;
    
    -- Calculate follow-up date (3 days from drop-off)
    SET v_follow_up_date = DATE_ADD(p_dropoff_date, INTERVAL 3 DAY);
    
    -- Start transaction
    START TRANSACTION;
    
    -- Create demo drop-off record
    INSERT INTO `crm_demo_dropoffs` (
        lead_id, agent_id, demo_type_id, dropoff_date, 
        follow_up_date, follow_up_status, demo_status, notes
    ) VALUES (
        p_lead_id, p_agent_id, p_demo_type_id, p_dropoff_date,
        v_follow_up_date, 'pending', 'dropped', p_notes
    );
    
    SET p_dropoff_id = LAST_INSERT_ID();
    
    -- Create follow-up task
    INSERT INTO `crm_tasks` (
        agent_id, lead_id, task_type, title, description,
        due_date, priority, status
    ) VALUES (
        p_agent_id, p_lead_id, 'follow_up', 
        CONCAT('Demo Follow-up: ', (SELECT product_name FROM demo_dropoff_types WHERE id = p_demo_type_id)),
        CONCAT('Follow up on demo drop-off from ', p_dropoff_date),
        v_follow_up_date, 'high', 'pending'
    );
    
    SET v_task_id = LAST_INSERT_ID();
    
    -- Link task to drop-off
    UPDATE `crm_demo_dropoffs` 
    SET follow_up_task_id = v_task_id 
    WHERE id = p_dropoff_id;
    
    -- Update lead status if needed
    UPDATE `crm_leads` 
    SET lead_status = 'info_obtained_demo_dropped',
        updated_at = NOW()
    WHERE id = p_lead_id 
    AND lead_status IN ('new', 'info_not_gotten');
    
    COMMIT;
    
    SET p_success = TRUE;
    SET p_message = 'Demo drop-off created successfully';
    
END sp_main$$

-- Procedure to create demo request from product-pack trial page
CREATE PROCEDURE IF NOT EXISTS `sp_create_demo_request`(
    IN p_store_name VARCHAR(255),
    IN p_contact_first_name VARCHAR(100),
    IN p_contact_last_name VARCHAR(100),
    IN p_contact_email VARCHAR(255),
    IN p_contact_phone VARCHAR(50),
    IN p_address VARCHAR(255),
    IN p_city VARCHAR(100),
    IN p_state VARCHAR(50),
    IN p_zip VARCHAR(20),
    IN p_source_id INT,
    OUT p_success BOOLEAN,
    OUT p_message VARCHAR(255),
    OUT p_request_id BIGINT
)
sp_request: BEGIN
    DECLARE v_lead_id BIGINT;
    
    SET p_success = FALSE;
    SET p_request_id = NULL;
    
    -- Check if email already exists
    IF EXISTS (SELECT 1 FROM `promo_demo_leads` WHERE contact_email = p_contact_email) THEN
        SET p_message = 'Email already exists in system';
        LEAVE sp_request;
    END IF;
    
    -- Start transaction
    START TRANSACTION;
    
    -- Create demo lead
    INSERT INTO `promo_demo_leads` (
        source_id, store_name, contact_first_name, contact_last_name,
        contact_email, contact_phone, address, city, state, zip_code,
        created_at
    ) VALUES (
        p_source_id, p_store_name, p_contact_first_name, p_contact_last_name,
        p_contact_email, p_contact_phone, p_address, p_city, p_state, p_zip,
        NOW()
    );
    
    SET p_request_id = LAST_INSERT_ID();
    
    -- Create CRM lead if requested
    INSERT INTO `crm_leads` (
        first_name, last_name, email, phone, company_name,
        address, city, state, postal_code, lead_status,
        lead_source, created_at
    ) VALUES (
        p_contact_first_name, p_contact_last_name, p_contact_email, p_contact_phone,
        p_store_name, p_address, p_city, p_state, p_zip,
        'info_obtained_demo_pending', 'product-sample-request', NOW()
    );
    
    SET v_lead_id = LAST_INSERT_ID();
    
    -- Update demo lead with CRM lead ID
    UPDATE `promo_demo_leads` 
    SET crm_lead_id = v_lead_id
    WHERE id = p_request_id;
    
    COMMIT;
    
    SET p_success = TRUE;
    SET p_message = 'Demo request created successfully';
    
END sp_request$$

DELIMITER ;

-- =============================================
-- 8. Indexes for Performance
-- =============================================

-- Add indexes for common queries
ALTER TABLE `crm_leads` ADD INDEX IF NOT EXISTS `idx_lead_status` (`lead_status`);
ALTER TABLE `crm_leads` ADD INDEX IF NOT EXISTS `idx_assigned_agent` (`assigned_agent_id`, `lead_status`);

-- =============================================
-- 9. Migrate Existing Demo Data
-- =============================================

-- Migrate existing promo_demo_dropoffs to new system
INSERT INTO `crm_demo_dropoffs` (
    lead_id, agent_id, demo_type_id, dropoff_date, 
    follow_up_date, follow_up_status, notes, created_at
)
SELECT 
    pd.crm_lead_id as lead_id,
    pd.agent_id,
    (SELECT id FROM demo_dropoff_types WHERE product_code = 'DEMO-product-pack-32-20') as demo_type_id,
    pd.dropoff_date,
    pd.follow_up_date,
    CASE 
        WHEN pd.follow_up_status = 'pending' AND pd.follow_up_date < CURDATE() THEN 'overdue'
        ELSE pd.follow_up_status 
    END as follow_up_status,
    pd.notes,
    pd.created_at
FROM `promo_demo_dropoffs` pd
WHERE pd.crm_lead_id IS NOT NULL
AND NOT EXISTS (
    SELECT 1 FROM crm_demo_dropoffs cd 
    WHERE cd.lead_id = pd.crm_lead_id 
    AND cd.agent_id = pd.agent_id
)
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- =============================================
-- 10. Admin Views for Unassigned Demos
-- =============================================

CREATE OR REPLACE VIEW `admin_unassigned_demos_view` AS
SELECT 
    pdl.id as demo_lead_id,
    pdl.store_name,
    pdl.contact_first_name,
    pdl.contact_last_name,
    pdl.contact_email,
    pdl.contact_phone,
    pdl.created_at as demo_created,
    pds.name as source_name,
    CASE 
        WHEN pdl.sales_agent_id IS NULL THEN 'unassigned'
        WHEN NOT EXISTS (SELECT 1 FROM crm_leads WHERE email = pdl.contact_email) THEN 'no_crm_lead'
        ELSE 'assigned'
    END as assignment_status
FROM `promo_demo_leads` pdl
JOIN `promo_demo_sources` pds ON pds.id = pdl.source_id
WHERE pdl.sales_agent_id IS NULL
OR NOT EXISTS (SELECT 1 FROM crm_leads WHERE email = pdl.contact_email)
ORDER BY pdl.created_at DESC;

-- =============================================
-- 11. Create Demo Reviews Table
-- =============================================

CREATE TABLE IF NOT EXISTS `promo_demo_reviews` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `demo_lead_id` bigint(20) UNSIGNED NOT NULL,
  `rating` int(11) NOT NULL,
  `review_text` text DEFAULT NULL,
  `preferred_strength` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_demo_lead` (`demo_lead_id`),
  KEY `idx_rating` (`rating`),
  CONSTRAINT `fk_review_demo_lead` FOREIGN KEY (`demo_lead_id`) REFERENCES `promo_demo_leads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add updated_at column and unique constraint to prevent duplicate reviews
ALTER TABLE `promo_demo_reviews` 
ADD COLUMN IF NOT EXISTS `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp();

-- Add unique constraint to prevent duplicate reviews per demo lead
ALTER TABLE `promo_demo_reviews`
ADD UNIQUE KEY IF NOT EXISTS `idx_unique_demo_lead` (`demo_lead_id`);

-- =============================================
-- 12. Lead Archiving Support
-- =============================================

ALTER TABLE `crm_leads` 
ADD COLUMN IF NOT EXISTS `is_archived` tinyint(1) NOT NULL DEFAULT 0,
ADD COLUMN IF NOT EXISTS `archived_at` timestamp NULL DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `archived_by` bigint(20) UNSIGNED DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `archive_reason` ENUM(
    'fake_store',
    'not_in_business', 
    'lost_contact_admin_failed',
    'do_not_contact',
    'duplicate',
    'test_lead',
    'other'
) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `archive_notes` text DEFAULT NULL,
ADD KEY IF NOT EXISTS `idx_archived` (`is_archived`, `archived_at`),
ADD KEY IF NOT EXISTS `idx_archived_by` (`archived_by`),
ADD CONSTRAINT `fk_archived_by_user` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- =============================================
-- 13. Grants and Permissions (if needed)
-- =============================================

-- Add any specific grants here if required

-- =============================================
-- Migration Complete
-- =============================================
