-- Admin Training Dashboard View
-- Run this AFTER the main migration and foreign keys are in place
-- Creates a view for admin training analytics

CREATE OR REPLACE VIEW `admin_training_dashboard` AS
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
