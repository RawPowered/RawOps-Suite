-- Training Progress Table Creation
-- Ensures the training_progress table exists for proper functionality

CREATE TABLE IF NOT EXISTS training_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    module_id INT NOT NULL,
    status ENUM('not_started', 'in_progress', 'completed', 'failed') DEFAULT 'not_started',
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    score INT DEFAULT NULL,
    attempts INT DEFAULT 0,
    time_spent_seconds INT DEFAULT 0,
    metadata JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_user_module (user_id, module_id),
    KEY idx_user_id (user_id),
    KEY idx_module_id (module_id),
    KEY idx_status (status),
    KEY idx_completed_at (completed_at),
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (module_id) REFERENCES training_modules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create training_activity table as fallback
CREATE TABLE IF NOT EXISTS training_activity (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    module_id INT DEFAULT NULL,
    activity_type VARCHAR(100) NOT NULL,
    activity_data JSON DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    KEY idx_user_id (user_id),
    KEY idx_module_id (module_id),
    KEY idx_activity_type (activity_type),
    KEY idx_created_at (created_at),
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
