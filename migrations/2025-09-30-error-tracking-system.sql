-- Comprehensive Error Tracking System
-- Date: 2025-09-30
-- Purpose: World-class error monitoring integrated with heartbeat/analytics system
-- Notes: Idempotent, binary-safe per SQL-FORMATTING-RULES.md

START TRANSACTION;

-- 1) Main error log table - captures all types of errors
CREATE TABLE IF NOT EXISTS `error_log_detailed` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `error_id` varchar(64) NOT NULL COMMENT 'Unique error identifier for grouping',
  `session_id` varchar(64) DEFAULT NULL COMMENT 'Links to site_active_sessions',
  `user_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `error_type` enum('http','javascript','php','api','redirect','resource','performance','security','custom') NOT NULL,
  `error_code` varchar(50) DEFAULT NULL COMMENT 'HTTP status, error name, etc',
  `error_message` text DEFAULT NULL COMMENT 'Error message or description',
  `error_severity` enum('critical','error','warning','info','debug') NOT NULL DEFAULT 'error',
  
  -- Location information
  `url` varchar(1000) DEFAULT NULL COMMENT 'URL where error occurred',
  `path` varchar(500) DEFAULT NULL COMMENT 'Path component of URL',
  `referrer` varchar(1000) DEFAULT NULL COMMENT 'Referrer URL',
  `method` varchar(10) DEFAULT NULL COMMENT 'HTTP method (GET, POST, etc)',
  
  -- Context information
  `user_agent` varchar(500) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `role` varchar(40) DEFAULT NULL COMMENT 'User role if authenticated',
  `is_guest` tinyint(1) DEFAULT 1,
  
  -- Technical details
  `stack_trace` text DEFAULT NULL COMMENT 'Stack trace for JavaScript/PHP errors',
  `file_path` varchar(500) DEFAULT NULL COMMENT 'File where error occurred',
  `line_number` int(11) DEFAULT NULL COMMENT 'Line number in file',
  `column_number` int(11) DEFAULT NULL COMMENT 'Column number in file',
  
  -- Additional context
  `request_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Request data (sanitized)',
  `response_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Response data (sanitized)',
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Additional context',
  
  -- Browser/Performance context
  `viewport_width` int(11) DEFAULT NULL,
  `viewport_height` int(11) DEFAULT NULL,
  `screen_width` int(11) DEFAULT NULL,
  `screen_height` int(11) DEFAULT NULL,
  `memory_used_mb` decimal(10,2) DEFAULT NULL,
  `connection_type` varchar(50) DEFAULT NULL,
  `page_load_time_ms` int(11) DEFAULT NULL,
  
  -- Resolution tracking
  `is_resolved` tinyint(1) NOT NULL DEFAULT 0,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolved_by` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `resolution_notes` text DEFAULT NULL,
  
  -- Occurrence tracking
  `occurrence_count` int(11) NOT NULL DEFAULT 1 COMMENT 'Number of times this error occurred',
  `first_occurrence` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_occurrence` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_error_id` (`error_id`),
  KEY `idx_error_session` (`session_id`),
  KEY `idx_error_user` (`user_id`),
  KEY `idx_error_type_severity` (`error_type`, `error_severity`, `created_at`),
  KEY `idx_error_code` (`error_code`, `created_at`),
  KEY `idx_error_path` (`path`(255), `created_at`),
  KEY `idx_error_resolved` (`is_resolved`, `error_severity`, `created_at`),
  KEY `idx_error_occurrences` (`occurrence_count`, `last_occurrence`),
  KEY `idx_error_url` (`url`(255), `created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Comprehensive error tracking with full context';

-- 2) Error patterns table - for identifying recurring issues
CREATE TABLE IF NOT EXISTS `error_patterns` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `pattern_hash` varchar(64) NOT NULL COMMENT 'Hash of error signature',
  `error_type` enum('http','javascript','php','api','redirect','resource','performance','security','custom') NOT NULL,
  `error_code` varchar(50) DEFAULT NULL,
  `pattern_template` varchar(500) NOT NULL COMMENT 'Normalized error pattern',
  `affected_paths` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `occurrence_count` int(11) NOT NULL DEFAULT 0,
  `unique_users_affected` int(11) NOT NULL DEFAULT 0,
  `unique_sessions_affected` int(11) NOT NULL DEFAULT 0,
  `first_seen` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_seen` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `severity_score` decimal(5,2) DEFAULT 0.00 COMMENT 'Calculated severity 0-100',
  `is_known_issue` tinyint(1) NOT NULL DEFAULT 0,
  `fix_priority` enum('critical','high','medium','low','ignore') DEFAULT 'medium',
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_pattern_hash` (`pattern_hash`),
  KEY `idx_pattern_type_severity` (`error_type`, `severity_score`, `last_seen`),
  KEY `idx_pattern_priority` (`fix_priority`, `occurrence_count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Identifies recurring error patterns';

-- 3) Error alerts table - for notifying admins
CREATE TABLE IF NOT EXISTS `error_alerts` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `alert_type` enum('critical_error','high_frequency','new_pattern','user_impact','performance_degradation') NOT NULL,
  `error_pattern_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `error_log_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `alert_message` text NOT NULL,
  `severity` enum('critical','high','medium','low') NOT NULL,
  `is_acknowledged` tinyint(1) NOT NULL DEFAULT 0,
  `acknowledged_by` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_alert_severity_ack` (`severity`, `is_acknowledged`, `created_at`),
  KEY `idx_alert_pattern` (`error_pattern_id`),
  KEY `idx_alert_error` (`error_log_id`),
  FOREIGN KEY (`error_pattern_id`) REFERENCES `error_patterns` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`error_log_id`) REFERENCES `error_log_detailed` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`acknowledged_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Error alerts for admin notification';

-- 4) Resource error tracking - for failed assets (images, scripts, CSS, etc.)
CREATE TABLE IF NOT EXISTS `resource_errors` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` varchar(64) DEFAULT NULL,
  `user_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `page_url` varchar(1000) NOT NULL COMMENT 'Page where resource failed',
  `resource_url` varchar(1000) NOT NULL COMMENT 'URL of failed resource',
  `resource_type` enum('script','stylesheet','image','font','media','fetch','xhr','other') NOT NULL,
  `error_message` varchar(500) DEFAULT NULL,
  `http_status` int(11) DEFAULT NULL,
  `load_time_ms` int(11) DEFAULT NULL,
  `occurrence_count` int(11) NOT NULL DEFAULT 1,
  `first_occurrence` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_occurrence` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_resource_url` (`resource_url`(255), `last_occurrence`),
  KEY `idx_resource_type` (`resource_type`, `occurrence_count`),
  KEY `idx_resource_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks failed resource loading';

-- 5) Performance degradation tracking
CREATE TABLE IF NOT EXISTS `performance_issues` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` varchar(64) DEFAULT NULL,
  `user_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `url` varchar(1000) NOT NULL,
  `issue_type` enum('slow_load','memory_leak','high_cpu','failed_api','timeout','network_error') NOT NULL,
  `metric_value` decimal(10,2) DEFAULT NULL COMMENT 'Load time, memory usage, etc.',
  `threshold_value` decimal(10,2) DEFAULT NULL COMMENT 'Expected threshold',
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `occurrence_count` int(11) NOT NULL DEFAULT 1,
  `first_occurrence` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_occurrence` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_perf_type_severity` (`issue_type`, `metric_value`, `last_occurrence`),
  KEY `idx_perf_url` (`url`(255), `last_occurrence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks performance degradation issues';

-- 6) Redirect and loop detection
CREATE TABLE IF NOT EXISTS `redirect_loops` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` varchar(64) NOT NULL,
  `user_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `loop_path` text NOT NULL COMMENT 'JSON array of URLs in the loop',
  `loop_count` int(11) NOT NULL COMMENT 'Number of redirects before detection',
  `initial_url` varchar(1000) NOT NULL,
  `detected_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_redirect_session` (`session_id`),
  KEY `idx_redirect_detected` (`detected_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Detects infinite redirect loops';

-- Insert initial configuration
INSERT IGNORE INTO `error_patterns` 
  (`pattern_hash`, `error_type`, `error_code`, `pattern_template`, `severity_score`, `fix_priority`)
VALUES
  ('404_not_found', 'http', '404', 'Page Not Found', 30.00, 'medium'),
  ('500_server_error', 'http', '500', 'Internal Server Error', 95.00, 'critical'),
  ('403_forbidden', 'http', '403', 'Forbidden Access', 70.00, 'high'),
  ('js_uncaught', 'javascript', 'UncaughtError', 'Uncaught JavaScript Error', 60.00, 'high'),
  ('api_timeout', 'api', 'TIMEOUT', 'API Request Timeout', 75.00, 'high'),
  ('network_failed', 'api', 'NETWORK_ERROR', 'Network Connection Failed', 85.00, 'high');

COMMIT;

-- Create view for quick error dashboard
CREATE OR REPLACE VIEW `error_dashboard_summary` AS
SELECT 
    DATE(e.created_at) as error_date,
    e.error_type,
    e.error_severity,
    COUNT(*) as total_errors,
    COUNT(DISTINCT e.session_id) as affected_sessions,
    COUNT(DISTINCT e.user_id) as affected_users,
    COUNT(DISTINCT e.path) as affected_paths,
    SUM(e.occurrence_count) as total_occurrences
FROM error_log_detailed e
WHERE e.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY DATE(e.created_at), e.error_type, e.error_severity
ORDER BY error_date DESC, total_errors DESC;

-- Create view for unresolved critical errors
CREATE OR REPLACE VIEW `critical_errors_unresolved` AS
SELECT 
    e.id,
    e.error_id,
    e.error_type,
    e.error_code,
    e.error_message,
    e.path,
    e.occurrence_count,
    e.last_occurrence,
    e.created_at,
    u.email as user_email,
    CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as user_name
FROM error_log_detailed e
LEFT JOIN users u ON e.user_id = u.id
WHERE e.is_resolved = 0 
  AND e.error_severity IN ('critical', 'error')
  AND e.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
ORDER BY e.error_severity DESC, e.occurrence_count DESC, e.last_occurrence DESC;
