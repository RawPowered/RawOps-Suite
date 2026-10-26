-- Migration: Add welcome walkthrough tracking
-- Date: 2025-09-11
-- Purpose: Track when users complete the welcome walkthrough

-- Create table to track welcome walkthrough completion
CREATE TABLE IF NOT EXISTS welcome_walkthrough_tracking (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    skipped BOOLEAN DEFAULT FALSE,
    INDEX idx_user_id (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add some sample data for testing (optional)
-- This would be populated automatically as users view the walkthrough
