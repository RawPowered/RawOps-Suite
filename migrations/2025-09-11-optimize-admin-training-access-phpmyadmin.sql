-- Migration: Optimize Admin Training Access and Role Simulation
-- Date: 2025-09-11
-- Purpose: Ensure admin can always access training and role simulation works properly
-- Compatible with phpMyAdmin and Namecheap hosting

-- First, ensure welcome_walkthrough_tracking table exists
CREATE TABLE IF NOT EXISTS welcome_walkthrough_tracking (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    skipped BOOLEAN DEFAULT FALSE,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add admin_training_access table to track admin training reviews
CREATE TABLE IF NOT EXISTS admin_training_access (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_user_id INT NOT NULL,
    accessed_module_id INT NULL,
    access_type ENUM('review', 'simulation', 'direct') DEFAULT 'review',
    simulated_role VARCHAR(50) NULL,
    access_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes TEXT NULL,
    INDEX idx_admin_user (admin_user_id),
    INDEX idx_module (accessed_module_id),
    INDEX idx_access_type (access_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create role_simulation_log table to track admin role simulations
CREATE TABLE IF NOT EXISTS role_simulation_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_user_id INT NOT NULL,
    simulated_role VARCHAR(50) NOT NULL,
    target_user_id INT NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ended_at TIMESTAMP NULL,
    duration_seconds INT NULL,
    actions_taken TEXT NULL,
    INDEX idx_admin_user (admin_user_id),
    INDEX idx_simulated_role (simulated_role),
    INDEX idx_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Update training_progress to allow admin override tracking
-- Note: Run these one at a time if needed
ALTER TABLE training_progress ADD COLUMN admin_override BOOLEAN DEFAULT FALSE COMMENT 'True if admin marked complete for testing/review';
ALTER TABLE training_progress ADD COLUMN override_admin_id INT NULL COMMENT 'Admin who performed override';
ALTER TABLE training_progress ADD COLUMN override_reason VARCHAR(255) NULL COMMENT 'Reason for admin override';
ALTER TABLE training_progress ADD COLUMN override_timestamp TIMESTAMP NULL COMMENT 'When override was applied';

-- Create index for admin overrides
CREATE INDEX idx_training_progress_admin_override ON training_progress (admin_override, override_admin_id);

-- Ensure all required training modules exist
INSERT IGNORE INTO training_modules (title, slug, content, duration_minutes, required, sort_order) VALUES
('Introduction to Product', 'intro-product', 'Comprehensive introduction to Product botany, history, and market positioning', 45, 1, 1),
('Products and product categories', 'products-and-product categories', 'Complete guide to product catalog, product categories characteristics, and quality indicators', 60, 1, 2),
('Compliance and Ethical Sales', 'compliance-sales', 'Legal compliance, ethical sales practices, and regulatory awareness', 30, 1, 3),
('CRM Mastery', 'crm-mastery', 'Customer relationship management and sales toolkit mastery', 40, 1, 4),
('Knowledge Review', 'final-assessment', 'Comprehensive review of key concepts and knowledge check', 20, 1, 5),
('Ten-Step Close', 'ten-step-close', 'Master the proven ten-step sales closing methodology', 90, 1, 6),
('AI-Assisted Sales', 'ai-assisted-sales', 'Leverage AI tools to multiply sales effectiveness', 35, 1, 7),
('Advanced Objection Handling', 'objection-mastery', 'Advanced techniques for handling customer objections', 50, 1, 8),
('Final Examination', 'final-exam', 'Comprehensive final exam covering all training materials', 60, 1, 9);

-- Update any existing incomplete walkthrough records
UPDATE welcome_walkthrough_tracking 
SET completed_at = viewed_at 
WHERE completed_at IS NULL AND skipped = FALSE AND viewed_at < DATE_SUB(NOW(), INTERVAL 1 HOUR);

-- Add comments for documentation
ALTER TABLE welcome_walkthrough_tracking COMMENT = 'Tracks completion of user welcome walkthrough tours';
ALTER TABLE admin_training_access COMMENT = 'Logs admin access to training modules for review and simulation purposes';
ALTER TABLE role_simulation_log COMMENT = 'Tracks admin role simulation sessions for debugging and testing';
