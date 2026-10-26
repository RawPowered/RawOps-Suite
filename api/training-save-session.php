<?php
/**
 * Training Session Save API
 * Handles session data persistence on page unload or module completion
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
    
    // Save comprehensive session data
    $stmt = $pdo->prepare("
        INSERT INTO training_time_tracking (
            user_id, module_id, session_id, page_url, started_at,
            last_activity_at, total_time_seconds, active_time_seconds,
            idle_time_seconds, page_views, scroll_percentage,
            ip_address, user_agent
        ) VALUES (?, ?, ?, ?, NOW(), NOW(), ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            last_activity_at = NOW(),
            total_time_seconds = VALUES(total_time_seconds),
            active_time_seconds = VALUES(active_time_seconds),
            idle_time_seconds = VALUES(idle_time_seconds),
            page_views = VALUES(page_views),
            scroll_percentage = GREATEST(scroll_percentage, VALUES(scroll_percentage))
    ");
    
    $stmt->execute([
        $input['user_id'],
        $input['module_id'],
        $input['session_id'],
        $input['page_url'] ?? '',
        $input['total_time_seconds'] ?? 0,
        $input['active_time_seconds'] ?? 0,
        $input['idle_time_seconds'] ?? 0,
        $input['page_views'] ?? 1,
        $input['scroll_percentage'] ?? 0,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
    
    // Update training progress if this is a completion
    if (isset($input['completion_data'])) {
        $completionData = $input['completion_data'];
        
        $stmt = $pdo->prepare("
            UPDATE training_progress 
            SET 
                total_time_minutes = ?,
                active_time_minutes = ?,
                idle_time_minutes = ?,
                page_views = ?,
                engagement_score = ?,
                completed_at = COALESCE(completed_at, NOW())
            WHERE user_id = ? AND module_id = ?
        ");
        
        $stmt->execute([
            round(($completionData['total_time_seconds'] ?? 0) / 60),
            round(($completionData['active_time_seconds'] ?? 0) / 60),
            round(($completionData['idle_time_seconds'] ?? 0) / 60),
            $completionData['page_views'] ?? 1,
            $completionData['engagement_score'] ?? null,
            $input['user_id'],
            $input['module_id']
        ]);
    }
    
    // Clean up old active sessions (older than 1 hour)
    $stmt = $pdo->prepare("
        DELETE FROM training_active_sessions 
        WHERE last_heartbeat < DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute();
    
    echo json_encode([
        'status' => 'success',
        'session_saved' => true,
        'timestamp' => time()
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error',
        'message' => $e->getMessage()
    ]);
}
?>
