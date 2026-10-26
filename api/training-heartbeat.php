<?php
/**
 * Training Heartbeat API
 * Handles real-time session updates and idle detection
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../required/config.php';

start_session();
$uid = current_user_id();

if (!$uid) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['user_id']) || !isset($input['module_id']) || !isset($input['session_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input data']);
    exit;
}

// Verify user owns this session
if ($input['user_id'] != $uid) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

try {
    $pdo = db();
    
    // Update or insert active session
    $stmt = $pdo->prepare("
        INSERT INTO training_active_sessions (
            session_id, user_id, module_id, current_page, last_heartbeat, 
            is_active, is_idle, total_idle_seconds
        ) VALUES (?, ?, ?, ?, NOW(), 1, ?, ?)
        ON DUPLICATE KEY UPDATE
            current_page = VALUES(current_page),
            last_heartbeat = NOW(),
            is_active = 1,
            is_idle = VALUES(is_idle),
            total_idle_seconds = VALUES(total_idle_seconds)
    ");
    
    $stmt->execute([
        $input['session_id'],
        $input['user_id'],
        $input['module_id'],
        $input['page_url'] ?? '',
        $input['is_idle'] ? 1 : 0,
        $input['idle_time'] ?? 0
    ]);
    
    // Update time tracking record
    $stmt = $pdo->prepare("
        INSERT INTO training_time_tracking (
            user_id, module_id, session_id, page_url, started_at, 
            last_activity_at, total_time_seconds, active_time_seconds, 
            idle_time_seconds, scroll_percentage, ip_address, user_agent
        ) VALUES (?, ?, ?, ?, NOW(), NOW(), ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            last_activity_at = NOW(),
            total_time_seconds = VALUES(total_time_seconds),
            active_time_seconds = VALUES(active_time_seconds),
            idle_time_seconds = VALUES(idle_time_seconds),
            scroll_percentage = GREATEST(scroll_percentage, VALUES(scroll_percentage))
    ");
    
    $stmt->execute([
        $input['user_id'],
        $input['module_id'],
        $input['session_id'],
        $input['page_url'] ?? '',
        $input['total_time'] ?? 0,
        $input['active_time'] ?? 0,
        $input['idle_time'] ?? 0,
        $input['scroll_percentage'] ?? 0,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
    
    // Check for admin alerts
    $alertsGenerated = [];
    
    // Excessive idle time alert
    if (($input['idle_time'] ?? 0) > 1800) { // 30 minutes
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO training_admin_alerts (
                user_id, module_id, alert_type, alert_message, severity, alert_data
            ) VALUES (?, ?, 'excessive_idle', ?, 'medium', ?)
        ");
        
        $idleMinutes = round($input['idle_time'] / 60);
        $alertMessage = "User has been idle for {$idleMinutes} minutes in module";
        $alertData = json_encode([
            'idle_minutes' => $idleMinutes,
            'module_id' => $input['module_id'],
            'session_id' => $input['session_id']
        ]);
        
        $stmt->execute([$input['user_id'], $input['module_id'], $alertMessage, $alertData]);
        $alertsGenerated[] = 'excessive_idle';
    }
    
    // Stuck on module alert (more than 2x expected duration)
    $stmt = $pdo->prepare("SELECT duration_minutes FROM training_modules WHERE id = ?");
    $stmt->execute([$input['module_id']]);
    $expectedDuration = $stmt->fetchColumn();
    
    if ($expectedDuration && ($input['total_time'] ?? 0) > ($expectedDuration * 120)) { // 2x expected time in seconds
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO training_admin_alerts (
                user_id, module_id, alert_type, alert_message, severity, alert_data
            ) VALUES (?, ?, 'stuck_on_module', ?, 'high', ?)
        ");
        
        $actualMinutes = round($input['total_time'] / 60);
        $alertMessage = "User spending excessive time on module ({$actualMinutes}min vs {$expectedDuration}min expected)";
        $alertData = json_encode([
            'actual_minutes' => $actualMinutes,
            'expected_minutes' => $expectedDuration,
            'module_id' => $input['module_id']
        ]);
        
        $stmt->execute([$input['user_id'], $input['module_id'], $alertMessage, $alertData]);
        $alertsGenerated[] = 'stuck_on_module';
    }
    
    echo json_encode([
        'status' => 'success',
        'timestamp' => time(),
        'alerts_generated' => $alertsGenerated,
        'session_active' => true
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error',
        'message' => $e->getMessage()
    ]);
}
?>
