<?php
/**
 * Training Activity Logging API
 * Detailed activity tracking for comprehensive user behavior analysis
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle OPTIONS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Handle different HTTP methods appropriately
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // GET request - return current training progress
    start_session();
    $uid = current_user_id();
    
    if (!$uid) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required']);
        exit;
    }
    
    try {
        require_once __DIR__ . '/../required/config.php';
        $pdo = db();
        
        // Get user's training progress
        $stmt = $pdo->prepare('
            SELECT tp.*, tm.title, tm.slug 
            FROM training_progress tp 
            JOIN training_modules tm ON tm.id = tp.module_id 
            WHERE tp.user_id = ? 
            ORDER BY tp.started_at DESC
        ');
        $stmt->execute([$uid]);
        $progress = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'user_id' => $uid,
            'progress' => $progress,
            'total_modules' => count($progress)
        ]);
        exit;
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch progress: ' . $e->getMessage()]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use GET for progress or POST for activity logging.']);
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

if (!$input || !isset($input['activity_type']) || !isset($input['user_id']) || !isset($input['module_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input data']);
    exit;
}

// Verify user owns this activity
if ($input['user_id'] != $uid) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

try {
    $pdo = db();
    
    // Insert activity log
    $stmt = $pdo->prepare("
        INSERT INTO training_activity_logs (
            user_id, module_id, activity_type, activity_data, 
            timestamp, session_id, ip_address
        ) VALUES (?, ?, ?, ?, FROM_UNIXTIME(?), ?, ?)
    ");
    
    $stmt->execute([
        $input['user_id'],
        $input['module_id'],
        $input['activity_type'],
        json_encode($input['activity_data'] ?? []),
        ($input['timestamp'] ?? time() * 1000) / 1000, // Convert from JS timestamp
        $input['session_id'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    
    // Handle specific activity types that require additional processing
    switch ($input['activity_type']) {
        case 'quiz_submit':
            // Update training progress with quiz data
            if (isset($input['activity_data']['score']) && isset($input['activity_data']['attempts'])) {
                $stmt = $pdo->prepare("
                    UPDATE training_progress 
                    SET quiz_score = ?, quiz_attempts = ?
                    WHERE user_id = ? AND module_id = ?
                ");
                $stmt->execute([
                    $input['activity_data']['score'],
                    $input['activity_data']['attempts'],
                    $input['user_id'],
                    $input['module_id']
                ]);
            }
            break;
            
        case 'module_complete':
            // Update training progress with completion data
            $activityData = $input['activity_data'] ?? [];
            $stmt = $pdo->prepare("
                UPDATE training_progress 
                SET 
                    total_time_minutes = ?,
                    active_time_minutes = ?,
                    idle_time_minutes = ?,
                    page_views = ?,
                    engagement_score = ?,
                    completed_at = NOW()
                WHERE user_id = ? AND module_id = ?
            ");
            
            $stmt->execute([
                round(($activityData['total_time_seconds'] ?? 0) / 60),
                round(($activityData['active_time_seconds'] ?? 0) / 60),
                round(($activityData['idle_time_seconds'] ?? 0) / 60),
                $activityData['page_views'] ?? 1,
                $activityData['engagement_score'] ?? null,
                $input['user_id'],
                $input['module_id']
            ]);
            
            // Generate completion milestone alert
            $stmt = $pdo->prepare("
                INSERT INTO training_admin_alerts (
                    user_id, module_id, alert_type, alert_message, severity, alert_data
                ) VALUES (?, ?, 'completion_milestone', ?, 'low', ?)
            ");
            
            $alertMessage = "User completed module in " . round(($activityData['total_time_seconds'] ?? 0) / 60) . " minutes";
            $alertData = json_encode([
                'completion_time_minutes' => round(($activityData['total_time_seconds'] ?? 0) / 60),
                'engagement_score' => $activityData['engagement_score'] ?? null,
                'module_id' => $input['module_id']
            ]);
            
            $stmt->execute([$input['user_id'], $input['module_id'], $alertMessage, $alertData]);
            break;
            
        case 'excessive_idle':
            // This is already handled by the heartbeat API, but we can add additional logic here
            break;
    }
    
    echo json_encode([
        'status' => 'success',
        'activity_logged' => true,
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
