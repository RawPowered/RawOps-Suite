-- Migration: Optimize Admin Training Access and Role Simulation
-- Date: 2025-09-11
-- Purpose: Ensure admin can always access training and role simulation works properly

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
ALTER TABLE training_progress 
ADD COLUMN IF NOT EXISTS admin_override BOOLEAN DEFAULT FALSE COMMENT 'True if admin marked complete for testing/review',
ADD COLUMN IF NOT EXISTS override_admin_id INT NULL COMMENT 'Admin who performed override',
ADD COLUMN IF NOT EXISTS override_reason VARCHAR(255) NULL COMMENT 'Reason for admin override',
ADD COLUMN IF NOT EXISTS override_timestamp TIMESTAMP NULL COMMENT 'When override was applied';

-- Add foreign key for override admin will be added separately after table creation

-- Create index for admin overrides
CREATE INDEX IF NOT EXISTS idx_training_progress_admin_override 
ON training_progress (admin_override, override_admin_id);

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

-- Create procedure to log admin training access
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS LogAdminTrainingAccess(
    IN p_admin_user_id INT,
    IN p_module_id INT,
    IN p_access_type VARCHAR(20),
    IN p_simulated_role VARCHAR(50),
    IN p_notes TEXT
)
BEGIN
    INSERT INTO admin_training_access 
    (admin_user_id, accessed_module_id, access_type, simulated_role, notes)
    VALUES 
    (p_admin_user_id, p_module_id, p_access_type, p_simulated_role, p_notes);
END //
DELIMITER ;

-- Create procedure to start role simulation logging
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS StartRoleSimulation(
    IN p_admin_user_id INT,
    IN p_simulated_role VARCHAR(50),
    IN p_target_user_id INT
)
BEGIN
    -- End any existing simulation for this admin
    UPDATE role_simulation_log 
    SET ended_at = NOW(), 
        duration_seconds = TIMESTAMPDIFF(SECOND, started_at, NOW())
    WHERE admin_user_id = p_admin_user_id AND ended_at IS NULL;
    
    -- Start new simulation
    INSERT INTO role_simulation_log 
    (admin_user_id, simulated_role, target_user_id)
    VALUES 
    (p_admin_user_id, p_simulated_role, p_target_user_id);
END //
DELIMITER ;

-- Create procedure to end role simulation
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS EndRoleSimulation(
    IN p_admin_user_id INT,
    IN p_actions_taken TEXT
)
BEGIN
    UPDATE role_simulation_log 
    SET ended_at = NOW(), 
        duration_seconds = TIMESTAMPDIFF(SECOND, started_at, NOW()),
        actions_taken = p_actions_taken
    WHERE admin_user_id = p_admin_user_id AND ended_at IS NULL;
END //
DELIMITER ;

-- Update any existing incomplete walkthrough records
UPDATE welcome_walkthrough_tracking 
SET completed_at = viewed_at 
WHERE completed_at IS NULL AND skipped = FALSE AND viewed_at < DATE_SUB(NOW(), INTERVAL 1 HOUR);

-- Create view for admin training dashboard
CREATE OR REPLACE VIEW admin_training_dashboard AS
SELECT 
    u.id as admin_id,
    u.first_name,
    u.last_name,
    u.email,
    COUNT(DISTINCT ata.accessed_module_id) as modules_reviewed,
    MAX(ata.access_timestamp) as last_training_access,
    COUNT(DISTINCT rsl.id) as simulation_sessions,
    SUM(COALESCE(rsl.duration_seconds, 0)) as total_simulation_time
FROM users u
JOIN user_roles ur ON ur.user_id = u.id
JOIN roles r ON r.id = ur.role_id AND r.name = 'admin'
LEFT JOIN admin_training_access ata ON ata.admin_user_id = u.id
LEFT JOIN role_simulation_log rsl ON rsl.admin_user_id = u.id
GROUP BY u.id, u.first_name, u.last_name, u.email;

-- Grant necessary permissions (if using specific database users)
-- GRANT SELECT, INSERT, UPDATE ON welcome_walkthrough_tracking TO 'product_app'@'%';
-- GRANT SELECT, INSERT, UPDATE ON admin_training_access TO 'product_app'@'%';
-- GRANT SELECT, INSERT, UPDATE ON role_simulation_log TO 'product_app'@'%';
-- GRANT EXECUTE ON PROCEDURE LogAdminTrainingAccess TO 'product_app'@'%';
-- GRANT EXECUTE ON PROCEDURE StartRoleSimulation TO 'product_app'@'%';
-- GRANT EXECUTE ON PROCEDURE EndRoleSimulation TO 'product_app'@'%';

-- Add foreign keys after all tables are created (these may fail if constraints already exist, which is okay)
-- Note: The migration runner will handle these errors gracefully

-- Add foreign keys for welcome_walkthrough_tracking
ALTER TABLE welcome_walkthrough_tracking 
ADD CONSTRAINT fk_walkthrough_user_id 
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Add foreign keys for admin_training_access
ALTER TABLE admin_training_access 
ADD CONSTRAINT fk_admin_training_user_id 
FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE admin_training_access 
ADD CONSTRAINT fk_admin_training_module_id 
FOREIGN KEY (accessed_module_id) REFERENCES training_modules(id) ON DELETE SET NULL;

-- Add foreign keys for role_simulation_log
ALTER TABLE role_simulation_log 
ADD CONSTRAINT fk_role_sim_admin_user_id 
FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE role_simulation_log 
ADD CONSTRAINT fk_role_sim_target_user_id 
FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL;

-- Add foreign key for training_progress override admin
ALTER TABLE training_progress 
ADD CONSTRAINT fk_training_progress_override_admin 
FOREIGN KEY (override_admin_id) REFERENCES users(id) ON DELETE SET NULL;

-- Add comments for documentation
ALTER TABLE welcome_walkthrough_tracking COMMENT = 'Tracks completion of user welcome walkthrough tours';
ALTER TABLE admin_training_access COMMENT = 'Logs admin access to training modules for review and simulation purposes';
ALTER TABLE role_simulation_log COMMENT = 'Tracks admin role simulation sessions for debugging and testing';
