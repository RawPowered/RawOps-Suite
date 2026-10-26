-- Foreign Keys for Admin Training System
-- Run this AFTER the main migration tables are created
-- These match the existing database structure and naming conventions

-- Add foreign key for welcome_walkthrough_tracking
ALTER TABLE `welcome_walkthrough_tracking` 
ADD CONSTRAINT `fk_walkthrough_user_id` 
FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- Add foreign keys for admin_training_access
ALTER TABLE `admin_training_access` 
ADD CONSTRAINT `fk_admin_training_user_id` 
FOREIGN KEY (`admin_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `admin_training_access` 
ADD CONSTRAINT `fk_admin_training_module_id` 
FOREIGN KEY (`accessed_module_id`) REFERENCES `training_modules` (`id`) ON DELETE SET NULL;

-- Add foreign keys for role_simulation_log
ALTER TABLE `role_simulation_log` 
ADD CONSTRAINT `fk_role_sim_admin_user_id` 
FOREIGN KEY (`admin_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `role_simulation_log` 
ADD CONSTRAINT `fk_role_sim_target_user_id` 
FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- Add foreign key for training_progress override admin
ALTER TABLE `training_progress` 
ADD CONSTRAINT `fk_training_progress_override_admin` 
FOREIGN KEY (`override_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
