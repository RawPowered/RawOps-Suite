-- Foreign Keys and Views for Admin Training System
-- Run this AFTER the main migration tables are created
-- These can be run individually if some fail

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
