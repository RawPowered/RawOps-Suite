-- URL Migration System Tables
-- Created: 2025-09-26
-- Purpose: Support safe URL migration with audit trails and rollback capability

-- Table to track migration snapshots
CREATE TABLE IF NOT EXISTS migration_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    snapshot_id VARCHAR(50) UNIQUE NOT NULL,
    type VARCHAR(50) DEFAULT 'manual',
    created_at DATETIME NOT NULL,
    created_by INT,
    status ENUM('active', 'archived', 'restored') DEFAULT 'active',
    metadata JSON,
    file_count INT DEFAULT 0,
    db_changes INT DEFAULT 0,
    notes TEXT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
);

-- Table to track individual file changes for rollback
CREATE TABLE IF NOT EXISTS migration_file_changes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    snapshot_id VARCHAR(50) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    backup_path VARCHAR(500),
    original_hash VARCHAR(64),
    new_hash VARCHAR(64),
    changes_made INT DEFAULT 0,
    risk_score INT DEFAULT 0,
    status ENUM('pending', 'applied', 'rolled_back') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (snapshot_id) REFERENCES migration_snapshots(snapshot_id) ON DELETE CASCADE,
    INDEX idx_snapshot (snapshot_id),
    INDEX idx_status (status)
);

-- Table to track URL replacements made
CREATE TABLE IF NOT EXISTS migration_url_replacements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    snapshot_id VARCHAR(50),
    file_path VARCHAR(500),
    original_url TEXT,
    replacement_url TEXT,
    context VARCHAR(500),
    element_type VARCHAR(50),
    risk_level INT DEFAULT 0,
    applied_at DATETIME,
    FOREIGN KEY (snapshot_id) REFERENCES migration_snapshots(snapshot_id) ON DELETE CASCADE,
    INDEX idx_snapshot (snapshot_id),
    INDEX idx_risk (risk_level)
);

-- Table to track 404 audit results
CREATE TABLE IF NOT EXISTS migration_404_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    audit_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    url VARCHAR(500) NOT NULL,
    status_code INT,
    issue_count INT DEFAULT 0,
    issues JSON,
    link_count INT DEFAULT 0,
    problematic_links JSON,
    fixed BOOLEAN DEFAULT FALSE,
    INDEX idx_audit_date (audit_date),
    INDEX idx_status_code (status_code),
    INDEX idx_fixed (fixed)
);

-- Table to track migration progress
CREATE TABLE IF NOT EXISTS migration_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phase VARCHAR(50) NOT NULL,
    status ENUM('pending', 'in_progress', 'completed', 'failed') DEFAULT 'pending',
    started_at DATETIME,
    completed_at DATETIME,
    total_items INT DEFAULT 0,
    processed_items INT DEFAULT 0,
    error_count INT DEFAULT 0,
    metadata JSON,
    created_by INT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_phase (phase)
);

-- Sample data for testing
INSERT INTO migration_progress (phase, status, total_items, processed_items) VALUES
('404_audit', 'pending', 0, 0),
('link_scan', 'pending', 0, 0),
('url_replacement', 'pending', 0, 0),
('database_update', 'pending', 0, 0),
('verification', 'pending', 0, 0);

-- Grant permissions
-- Ensure admin users can manage migrations
-- (This would typically be handled by your permission system)


