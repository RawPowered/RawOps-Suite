<?php
// Comprehensive Error Tracking API
// Receives error reports from JavaScript and logs them with full context
// Integrates with site_active_sessions for correlation

require_once __DIR__ . '/../required/config.php';
start_session();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { 
    http_response_code(204); 
    exit; 
}

try {
    $pdo = db();
    $raw = file_get_contents('php://input');
    $input = json_decode($raw ?: '{}', true);
    if (!is_array($input)) { 
        $input = []; 
    }

    // Basic cleansing function
    $clean = function($v, $max = 500) {
        if (!is_string($v)) return null;
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
        $v = trim($v);
        if ($v === '') return null;
        return substr($v, 0, $max);
    };

    // Get session context
    $sessionId = substr(session_id() ?: ($input['session_id'] ?? ''), 0, 64);
    if ($sessionId === '') {
        $sessionId = bin2hex(random_bytes(16));
        session_id($sessionId);
    }

    $uid = current_user_id();
    $roleList = $uid ? (get_user_roles($uid) ?: []) : [];
    $role = $roleList[0] ?? null;
    $isGuest = $uid ? 0 : 1;

    // Extract error details
    $errorType = in_array($input['error_type'] ?? '', ['http','javascript','php','api','redirect','resource','performance','security','custom']) 
        ? $input['error_type'] 
        : 'custom';
    
    $errorCode = $clean($input['error_code'] ?? null, 50);
    $errorMessage = $clean($input['error_message'] ?? 'Unknown error', 2000);
    
    $errorSeverity = in_array($input['error_severity'] ?? '', ['critical','error','warning','info','debug'])
        ? $input['error_severity']
        : 'error';

    // Location information
    $url = $clean($input['url'] ?? ($_SERVER['REQUEST_URI'] ?? null), 1000);
    $path = $clean($input['path'] ?? parse_url($url ?? '/', PHP_URL_PATH), 500);
    $referrer = $clean($input['referrer'] ?? ($_SERVER['HTTP_REFERER'] ?? null), 1000);
    $method = $clean($input['method'] ?? ($_SERVER['REQUEST_METHOD'] ?? null), 10);

    // Context information
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = $clean($_SERVER['HTTP_USER_AGENT'] ?? '', 500);

    // Technical details
    $stackTrace = $clean($input['stack_trace'] ?? null, 10000);
    $filePath = $clean($input['file_path'] ?? null, 500);
    $lineNumber = isset($input['line_number']) ? intval($input['line_number']) : null;
    $columnNumber = isset($input['column_number']) ? intval($input['column_number']) : null;

    // Browser/Performance context
    $viewportWidth = isset($input['viewport_width']) ? intval($input['viewport_width']) : null;
    $viewportHeight = isset($input['viewport_height']) ? intval($input['viewport_height']) : null;
    $screenWidth = isset($input['screen_width']) ? intval($input['screen_width']) : null;
    $screenHeight = isset($input['screen_height']) ? intval($input['screen_height']) : null;
    $memoryUsedMb = isset($input['memory_used_mb']) ? floatval($input['memory_used_mb']) : null;
    $connectionType = $clean($input['connection_type'] ?? null, 50);
    $pageLoadTimeMs = isset($input['page_load_time_ms']) ? intval($input['page_load_time_ms']) : null;

    // Additional context (sanitize JSON)
    $metadata = null;
    if (isset($input['metadata']) && is_array($input['metadata'])) {
        $metadata = json_encode($input['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($metadata === false) $metadata = null;
    }

    // Create unique error_id for grouping similar errors
    $errorSignature = sprintf('%s:%s:%s:%s', 
        $errorType, 
        $errorCode ?? 'unknown', 
        $path ?? '/',
        substr(md5($errorMessage ?? ''), 0, 8)
    );
    $errorId = hash('sha256', $errorSignature);

    // Check if this error already exists
    $existing = $pdo->prepare('SELECT id, occurrence_count FROM error_log_detailed WHERE error_id = ? LIMIT 1');
    $existing->execute([$errorId]);
    $existingError = $existing->fetch(PDO::FETCH_ASSOC);

    if ($existingError) {
        // Update existing error
        $stmt = $pdo->prepare('
            UPDATE error_log_detailed 
            SET 
                occurrence_count = occurrence_count + 1,
                last_occurrence = NOW(),
                session_id = ?,
                user_id = ?,
                role = ?,
                is_guest = ?,
                url = ?,
                referrer = ?,
                ip_address = ?,
                user_agent = ?,
                metadata = ?
            WHERE id = ?
        ');
        $stmt->execute([
            $sessionId,
            $uid,
            $role,
            $isGuest,
            $url,
            $referrer,
            $ip,
            $ua,
            $metadata,
            $existingError['id']
        ]);
        
        $logId = $existingError['id'];
        $occurrenceCount = $existingError['occurrence_count'] + 1;
    } else {
        // Insert new error
        $stmt = $pdo->prepare('
            INSERT INTO error_log_detailed (
                error_id, session_id, user_id, error_type, error_code, error_message, error_severity,
                url, path, referrer, method,
                user_agent, ip_address, role, is_guest,
                stack_trace, file_path, line_number, column_number,
                metadata,
                viewport_width, viewport_height, screen_width, screen_height,
                memory_used_mb, connection_type, page_load_time_ms,
                occurrence_count, first_occurrence, last_occurrence
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?,
                ?, ?, ?, ?,
                ?, ?, ?,
                1, NOW(), NOW()
            )
        ');
        
        $stmt->execute([
            $errorId,
            $sessionId,
            $uid,
            $errorType,
            $errorCode,
            $errorMessage,
            $errorSeverity,
            $url,
            $path,
            $referrer,
            $method,
            $ua,
            $ip,
            $role,
            $isGuest,
            $stackTrace,
            $filePath,
            $lineNumber,
            $columnNumber,
            $metadata,
            $viewportWidth,
            $viewportHeight,
            $screenWidth,
            $screenHeight,
            $memoryUsedMb,
            $connectionType,
            $pageLoadTimeMs
        ]);
        
        $logId = $pdo->lastInsertId();
        $occurrenceCount = 1;
    }

    // Update or create error pattern
    updateErrorPattern($pdo, $errorId, $errorType, $errorCode, $errorMessage, $path, $sessionId, $uid);

    // Check if we need to create an alert
    checkAndCreateAlert($pdo, $logId, $errorId, $errorType, $errorSeverity, $occurrenceCount);

    // Track resource errors separately if applicable
    if ($errorType === 'resource' && !empty($input['resource_url'])) {
        trackResourceError($pdo, $sessionId, $uid, $url, $input['resource_url'], $input['resource_type'] ?? 'other', $errorMessage);
    }

    // Track performance issues separately if applicable
    if ($errorType === 'performance' && isset($input['metric_value'])) {
        trackPerformanceIssue($pdo, $sessionId, $uid, $url, $input['issue_type'] ?? 'slow_load', $input['metric_value'], $input['threshold_value'] ?? null, $metadata);
    }

    // Return success with error ID for client-side correlation
    echo json_encode([
        'success' => true,
        'error_id' => $errorId,
        'log_id' => $logId,
        'occurrence_count' => $occurrenceCount
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    // Log the error logging error (meta!)
    error_log("Error Tracker API Error: " . $e->getMessage());
    http_response_code(200); // Don't fail the client
    echo json_encode([
        'success' => false, 
        'error' => 'logged'
    ]);
}

// Helper function to update error patterns
function updateErrorPattern($pdo, $errorId, $errorType, $errorCode, $errorMessage, $path, $sessionId, $uid) {
    try {
        // Create pattern hash
        $patternTemplate = normalizeErrorMessage($errorMessage);
        $patternHash = substr($errorId, 0, 32);

        // Check if pattern exists
        $existing = $pdo->prepare('SELECT id, occurrence_count, unique_users_affected, unique_sessions_affected, affected_paths FROM error_patterns WHERE pattern_hash = ? LIMIT 1');
        $existing->execute([$patternHash]);
        $pattern = $existing->fetch(PDO::FETCH_ASSOC);

        if ($pattern) {
            // Update existing pattern
            $affectedPaths = json_decode($pattern['affected_paths'] ?? '[]', true) ?: [];
            if (!in_array($path, $affectedPaths)) {
                $affectedPaths[] = $path;
                $affectedPaths = array_slice(array_unique($affectedPaths), 0, 50); // Keep max 50 unique paths
            }

            $stmt = $pdo->prepare('
                UPDATE error_patterns 
                SET 
                    occurrence_count = occurrence_count + 1,
                    affected_paths = ?,
                    last_seen = NOW()
                WHERE id = ?
            ');
            $stmt->execute([
                json_encode($affectedPaths),
                $pattern['id']
            ]);
        } else {
            // Insert new pattern
            $stmt = $pdo->prepare('
                INSERT INTO error_patterns (
                    pattern_hash, error_type, error_code, pattern_template,
                    affected_paths, occurrence_count, unique_users_affected, unique_sessions_affected,
                    severity_score, fix_priority
                ) VALUES (?, ?, ?, ?, ?, 1, 0, 0, ?, ?)
            ');
            
            $severityScore = calculateSeverityScore($errorType, $errorCode);
            $fixPriority = $severityScore >= 90 ? 'critical' : ($severityScore >= 70 ? 'high' : 'medium');
            
            $stmt->execute([
                $patternHash,
                $errorType,
                $errorCode,
                $patternTemplate,
                json_encode([$path]),
                $severityScore,
                $fixPriority
            ]);
        }
    } catch (Throwable $e) {
        error_log("Error Pattern Update Failed: " . $e->getMessage());
    }
}

// Helper function to check and create alerts
function checkAndCreateAlert($pdo, $logId, $errorId, $errorType, $errorSeverity, $occurrenceCount) {
    try {
        $shouldAlert = false;
        $alertType = 'critical_error';
        $alertMessage = '';
        $alertSeverity = 'medium';

        // Critical errors always alert
        if ($errorSeverity === 'critical') {
            $shouldAlert = true;
            $alertType = 'critical_error';
            $alertMessage = "Critical error detected: {$errorType}";
            $alertSeverity = 'critical';
        }
        // High frequency errors (10+ occurrences in short time)
        elseif ($occurrenceCount >= 10) {
            $shouldAlert = true;
            $alertType = 'high_frequency';
            $alertMessage = "High frequency error: {$occurrenceCount} occurrences";
            $alertSeverity = 'high';
        }
        // New error patterns (first occurrence of critical/error)
        elseif ($occurrenceCount === 1 && in_array($errorSeverity, ['critical', 'error'])) {
            $shouldAlert = true;
            $alertType = 'new_pattern';
            $alertMessage = "New error pattern detected: {$errorType}";
            $alertSeverity = 'medium';
        }

        if ($shouldAlert) {
            // Check if alert already exists for this error in last hour
            $recentAlert = $pdo->prepare('
                SELECT id FROM error_alerts 
                WHERE error_log_id = ? 
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                LIMIT 1
            ');
            $recentAlert->execute([$logId]);
            
            if (!$recentAlert->fetch()) {
                $stmt = $pdo->prepare('
                    INSERT INTO error_alerts (
                        alert_type, error_log_id, alert_message, severity
                    ) VALUES (?, ?, ?, ?)
                ');
                $stmt->execute([
                    $alertType,
                    $logId,
                    $alertMessage,
                    $alertSeverity
                ]);
            }
        }
    } catch (Throwable $e) {
        error_log("Alert Creation Failed: " . $e->getMessage());
    }
}

// Helper function to track resource errors
function trackResourceError($pdo, $sessionId, $uid, $pageUrl, $resourceUrl, $resourceType, $errorMessage) {
    try {
        $validTypes = ['script','stylesheet','image','font','media','fetch','xhr','other'];
        if (!in_array($resourceType, $validTypes)) {
            $resourceType = 'other';
        }

        // Check if this resource error exists
        $existing = $pdo->prepare('
            SELECT id, occurrence_count 
            FROM resource_errors 
            WHERE resource_url = ? AND page_url = ?
            ORDER BY last_occurrence DESC
            LIMIT 1
        ');
        $existing->execute([$resourceUrl, $pageUrl]);
        $existingError = $existing->fetch(PDO::FETCH_ASSOC);

        if ($existingError) {
            $stmt = $pdo->prepare('
                UPDATE resource_errors 
                SET occurrence_count = occurrence_count + 1,
                    last_occurrence = NOW(),
                    session_id = ?,
                    user_id = ?
                WHERE id = ?
            ');
            $stmt->execute([$sessionId, $uid, $existingError['id']]);
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO resource_errors (
                    session_id, user_id, page_url, resource_url, resource_type, error_message
                ) VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $sessionId,
                $uid,
                $pageUrl,
                $resourceUrl,
                $resourceType,
                $errorMessage
            ]);
        }
    } catch (Throwable $e) {
        error_log("Resource Error Tracking Failed: " . $e->getMessage());
    }
}

// Helper function to track performance issues
function trackPerformanceIssue($pdo, $sessionId, $uid, $url, $issueType, $metricValue, $thresholdValue, $context) {
    try {
        $validTypes = ['slow_load','memory_leak','high_cpu','failed_api','timeout','network_error'];
        if (!in_array($issueType, $validTypes)) {
            $issueType = 'slow_load';
        }

        $stmt = $pdo->prepare('
            INSERT INTO performance_issues (
                session_id, user_id, url, issue_type, metric_value, threshold_value, context
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                occurrence_count = occurrence_count + 1,
                last_occurrence = NOW(),
                metric_value = GREATEST(metric_value, VALUES(metric_value))
        ');
        $stmt->execute([
            $sessionId,
            $uid,
            $url,
            $issueType,
            $metricValue,
            $thresholdValue,
            $context
        ]);
    } catch (Throwable $e) {
        error_log("Performance Issue Tracking Failed: " . $e->getMessage());
    }
}

// Helper function to normalize error messages for pattern matching
function normalizeErrorMessage($message) {
    // Remove specific details like IDs, timestamps, etc.
    $normalized = preg_replace('/\d+/', 'N', $message);
    $normalized = preg_replace('/[a-f0-9]{32,}/', 'HASH', $normalized);
    $normalized = preg_replace('/https?:\/\/[^\s]+/', 'URL', $normalized);
    return substr($normalized, 0, 500);
}

// Helper function to calculate severity score
function calculateSeverityScore($errorType, $errorCode) {
    $baseScores = [
        'http' => [
            '404' => 30,
            '403' => 70,
            '500' => 95,
            '502' => 90,
            '503' => 85,
            '504' => 80
        ],
        'javascript' => 60,
        'php' => 80,
        'api' => 70,
        'redirect' => 65,
        'resource' => 40,
        'performance' => 50,
        'security' => 100,
        'custom' => 50
    ];

    if ($errorType === 'http' && isset($baseScores['http'][$errorCode])) {
        return $baseScores['http'][$errorCode];
    }

    return $baseScores[$errorType] ?? 50;
}


