-- Optional Stored Procedures for Admin Training System
-- These can be added after the main migration if stored procedures are supported
-- Run these individually in phpMyAdmin

-- Procedure to log admin training access
DELIMITER $$
CREATE PROCEDURE LogAdminTrainingAccess(
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
END$$
DELIMITER ;

-- Procedure to start role simulation logging
DELIMITER $$
CREATE PROCEDURE StartRoleSimulation(
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
END$$
DELIMITER ;

-- Procedure to end role simulation
DELIMITER $$
CREATE PROCEDURE EndRoleSimulation(
    IN p_admin_user_id INT,
    IN p_actions_taken TEXT
)
BEGIN
    UPDATE role_simulation_log 
    SET ended_at = NOW(), 
        duration_seconds = TIMESTAMPDIFF(SECOND, started_at, NOW()),
        actions_taken = p_actions_taken
    WHERE admin_user_id = p_admin_user_id AND ended_at IS NULL;
END$$
DELIMITER ;
