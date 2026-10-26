<?php
/**
 * Walkthrough System API
 * Manages walkthrough/tutorial display and user progress tracking
 * 
 * Endpoints:
 * - GET ?action=get_active - Get active walkthroughs for current user/page
 * - POST ?action=update_progress - Update user progress
 * - POST ?action=skip_all - Mark walkthrough as skipped (auto-disables for admins)
 * - POST ?action=restart - Reset progress
 * - POST ?action=log_event - Log walkthrough interaction event
 * - GET ?action=list_available - List all walkthroughs with status
 * - POST ?action=set_preference - Set user preference (disable_walkthroughs)
 * - GET ?action=get_preference - Get user preference
 * 
 * @author Product Development Team
 * @date 2025-10-20
 * @updated 2025-10-22 - Added database-backed preferences
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/shortcode-engine.php';

try {
    start_session();
} catch (Throwable $e) {}

$userId = current_user_id();
if (!$userId) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$pdo = db();
$action = $_GET['action'] ?? $_POST['action'] ?? 'get_active';

try {
    switch ($action) {
        case 'get_active':
            // Get active walkthroughs for current page and user's roles
            $targetPage = $_GET['page'] ?? null;
            
            // Check if user has disabled walkthroughs
            $stmt = $pdo->prepare('SELECT disable_walkthroughs FROM user_preferences WHERE user_id = ?');
            $stmt->execute([$userId]);
            $walkthroughsDisabled = (bool)$stmt->fetchColumn();
            
            // If disabled (and not in test mode), return empty
            if ($walkthroughsDisabled && !isset($_GET['test_mode'])) {
                echo json_encode([
                    'ok' => true,
                    'walkthroughs' => [],
                    'count' => 0,
                    'disabled' => true
                ]);
                break;
            }
            
            // Get user's roles
            $stmt = $pdo->prepare('
                SELECT r.name FROM user_roles ur 
                JOIN roles r ON r.id = ur.role_id 
                WHERE ur.user_id = ?
            ');
            $stmt->execute([$userId]);
            $userRoles = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // If admin, add ability to see all walkthroughs for testing
            $isAdmin = in_array('admin', $userRoles);
            $testMode = isset($_GET['test_mode']) && $isAdmin;
            
            // Build query to get walkthroughs
            $sql = '
                SELECT 
                    w.id,
                    w.title,
                    w.description,
                    w.slug,
                    w.target_page,
                    w.target_element_selector,
                    w.position,
                    w.content,
                    w.trigger_condition,
                    w.show_for_roles,
                    w.is_multi_step,
                    w.sort_order,
                    p.status as user_status,
                    p.current_step
                FROM sales_walkthroughs w
                LEFT JOIN user_walkthrough_progress p ON p.walkthrough_id = w.id AND p.user_id = ?
                WHERE w.is_active = 1
            ';
            
            $params = [$userId];
            
            // Filter by target page
            if ($targetPage) {
                $sql .= ' AND (w.target_page = ? OR w.target_page IS NULL)';
                $params[] = $targetPage;
            } else {
                $sql .= ' AND w.target_page IS NULL';
            }
            
            // Exclude completed/skipped unless in test mode
            if (!$testMode) {
                $sql .= ' AND (p.status IS NULL OR p.status NOT IN ("completed", "skipped"))';
            }
            
            $sql .= ' ORDER BY w.sort_order ASC, w.id ASC';
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $walkthroughs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Filter by roles and process content
            $filteredWalkthroughs = [];
            foreach ($walkthroughs as $walkthrough) {
                // Check role visibility
                $showForRoles = json_decode($walkthrough['show_for_roles'], true) ?: [];
                $hasAccess = empty($showForRoles) || $testMode;
                
                if (!$hasAccess) {
                    foreach ($userRoles as $role) {
                        if (in_array($role, $showForRoles)) {
                            $hasAccess = true;
                            break;
                        }
                    }
                }
                
                if (!$hasAccess) {
                    continue;
                }
                
                // Process shortcodes in content
                $walkthrough['content'] = process_shortcodes($walkthrough['content']);
                $walkthrough['trigger_condition'] = json_decode($walkthrough['trigger_condition'], true);
                $walkthrough['show_for_roles'] = $showForRoles;
                
                // Get steps if multi-step
                if ($walkthrough['is_multi_step']) {
                    $stepsStmt = $pdo->prepare('
                        SELECT * FROM walkthrough_steps 
                        WHERE walkthrough_id = ? 
                        ORDER BY step_number ASC
                    ');
                    $stepsStmt->execute([$walkthrough['id']]);
                    $steps = $stepsStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Process shortcodes in steps
                    foreach ($steps as &$step) {
                        $step['content'] = process_shortcodes($step['content']);
                    }
                    
                    $walkthrough['steps'] = $steps;
                }
                
                $filteredWalkthroughs[] = $walkthrough;
            }
            
            echo json_encode([
                'ok' => true,
                'walkthroughs' => $filteredWalkthroughs,
                'count' => count($filteredWalkthroughs)
            ]);
            break;
            
        case 'update_progress':
            // Update user progress on a walkthrough
            $input = json_decode(file_get_contents('php://input'), true);
            $walkthroughId = intval($input['walkthrough_id'] ?? 0);
            $status = $input['status'] ?? 'in_progress';
            $currentStep = intval($input['current_step'] ?? 0);
            
            if (!$walkthroughId) {
                throw new Exception('Walkthrough ID required');
            }
            
            if (!in_array($status, ['not_started', 'in_progress', 'completed', 'skipped'])) {
                throw new Exception('Invalid status');
            }
            
            // Upsert progress
            $stmt = $pdo->prepare('
                INSERT INTO user_walkthrough_progress 
                    (user_id, walkthrough_id, status, current_step, started_at, last_interaction_at)
                VALUES (?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    current_step = VALUES(current_step),
                    completed_at = CASE WHEN VALUES(status) = "completed" THEN NOW() ELSE completed_at END,
                    skipped_at = CASE WHEN VALUES(status) = "skipped" THEN NOW() ELSE skipped_at END,
                    last_interaction_at = NOW()
            ');
            
            $stmt->execute([$userId, $walkthroughId, $status, $currentStep]);
            
            echo json_encode([
                'ok' => true,
                'message' => 'Progress updated'
            ]);
            break;
            
        case 'skip_all':
            // Mark a walkthrough as skipped
            $input = json_decode(file_get_contents('php://input'), true);
            $walkthroughId = intval($input['walkthrough_id'] ?? 0);
            
            if (!$walkthroughId) {
                throw new Exception('Walkthrough ID required');
            }
            
            $stmt = $pdo->prepare('
                INSERT INTO user_walkthrough_progress 
                    (user_id, walkthrough_id, status, skipped_at, last_interaction_at)
                VALUES (?, ?, "skipped", NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    status = "skipped",
                    skipped_at = NOW(),
                    last_interaction_at = NOW()
            ');
            
            $stmt->execute([$userId, $walkthroughId]);
            
            // Log event
            $eventStmt = $pdo->prepare('
                INSERT INTO walkthrough_events 
                    (user_id, walkthrough_id, event_type, created_at)
                VALUES (?, ?, "skipped", NOW())
            ');
            $eventStmt->execute([$userId, $walkthroughId]);
            
            // Check if user is admin - if so, auto-disable all future walkthroughs
            $stmt = $pdo->prepare('
                SELECT COUNT(*) FROM user_roles ur 
                JOIN roles r ON r.id = ur.role_id 
                WHERE ur.user_id = ? AND r.name = "admin"
            ');
            $stmt->execute([$userId]);
            $isAdmin = (bool)$stmt->fetchColumn();
            
            if ($isAdmin) {
                // Auto-disable walkthroughs for admin after first skip
                $stmt = $pdo->prepare('
                    INSERT INTO user_preferences (user_id, disable_walkthroughs, created_at)
                    VALUES (?, 1, NOW())
                    ON DUPLICATE KEY UPDATE disable_walkthroughs = 1, updated_at = NOW()
                ');
                $stmt->execute([$userId]);
            }
            
            echo json_encode([
                'ok' => true,
                'message' => 'Walkthrough skipped',
                'admin_auto_disabled' => $isAdmin
            ]);
            break;
            
        case 'list_available':
            // List ALL walkthroughs available to user (including completed)
            // This allows users to see what tutorials they can restart
            $targetPage = $_GET['page'] ?? null;
            
            // Get user's roles
            $stmt = $pdo->prepare('
                SELECT r.name FROM user_roles ur 
                JOIN roles r ON r.id = ur.role_id 
                WHERE ur.user_id = ?
            ');
            $stmt->execute([$userId]);
            $userRoles = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Get all active walkthroughs with user's progress
            $sql = '
                SELECT 
                    w.id,
                    w.title,
                    w.description,
                    w.slug,
                    w.target_page,
                    w.show_for_roles,
                    w.is_multi_step,
                    p.status,
                    p.completed_at,
                    p.skipped_at
                FROM sales_walkthroughs w
                LEFT JOIN user_walkthrough_progress p ON p.walkthrough_id = w.id AND p.user_id = ?
                WHERE w.is_active = 1
            ';
            
            $params = [$userId];
            
            // Filter by target page if specified
            if ($targetPage) {
                $sql .= ' AND (w.target_page = ? OR w.target_page IS NULL)';
                $params[] = $targetPage;
            }
            
            $sql .= ' ORDER BY w.sort_order ASC, w.id ASC';
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $walkthroughs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Filter by roles
            $availableWalkthroughs = [];
            foreach ($walkthroughs as $walkthrough) {
                $showForRoles = json_decode($walkthrough['show_for_roles'], true) ?: [];
                $hasAccess = empty($showForRoles);
                
                if (!$hasAccess) {
                    foreach ($userRoles as $role) {
                        if (in_array($role, $showForRoles)) {
                            $hasAccess = true;
                            break;
                        }
                    }
                }
                
                if ($hasAccess) {
                    $availableWalkthroughs[] = [
                        'id' => $walkthrough['id'],
                        'title' => $walkthrough['title'],
                        'description' => $walkthrough['description'],
                        'slug' => $walkthrough['slug'],
                        'target_page' => $walkthrough['target_page'],
                        'is_multi_step' => (bool)$walkthrough['is_multi_step'],
                        'status' => $walkthrough['status'] ?? 'not_started',
                        'can_restart' => in_array($walkthrough['status'], ['completed', 'skipped'])
                    ];
                }
            }
            
            echo json_encode([
                'ok' => true,
                'walkthroughs' => $availableWalkthroughs
            ]);
            break;
        
        case 'restart':
            // Reset progress on a walkthrough so it can be viewed again
            $input = json_decode(file_get_contents('php://input'), true);
            $walkthroughId = intval($input['walkthrough_id'] ?? 0);
            
            if (!$walkthroughId) {
                throw new Exception('Walkthrough ID required');
            }
            
            // Verify walkthrough exists and user has access
            $stmt = $pdo->prepare('SELECT id, title FROM sales_walkthroughs WHERE id = ? AND is_active = 1');
            $stmt->execute([$walkthroughId]);
            $walkthrough = $stmt->fetch();
            
            if (!$walkthrough) {
                throw new Exception('Walkthrough not found or inactive');
            }
            
            // Delete progress to allow fresh start
            $stmt = $pdo->prepare('
                DELETE FROM user_walkthrough_progress 
                WHERE user_id = ? AND walkthrough_id = ?
            ');
            
            $stmt->execute([$userId, $walkthroughId]);
            
            // Log restart event
            $eventStmt = $pdo->prepare('
                INSERT INTO walkthrough_events 
                    (user_id, walkthrough_id, event_type, event_data, created_at)
                VALUES (?, ?, "started", ?, NOW())
            ');
            $eventStmt->execute([
                $userId, 
                $walkthroughId, 
                json_encode(['action' => 'manual_restart'])
            ]);
            
            echo json_encode([
                'ok' => true,
                'message' => 'Tutorial reset successfully. Refresh the page to see it again.',
                'walkthrough_title' => $walkthrough['title']
            ]);
            break;
            
        case 'log_event':
            // Log walkthrough interaction event
            $input = json_decode(file_get_contents('php://input'), true);
            $walkthroughId = intval($input['walkthrough_id'] ?? 0);
            $eventType = $input['event_type'] ?? 'step_viewed';
            $stepNumber = isset($input['step_number']) ? intval($input['step_number']) : null;
            $eventData = isset($input['event_data']) ? json_encode($input['event_data']) : null;
            
            if (!$walkthroughId) {
                throw new Exception('Walkthrough ID required');
            }
            
            $validEvents = ['started', 'step_viewed', 'step_completed', 'completed', 'skipped', 'dismissed'];
            if (!in_array($eventType, $validEvents)) {
                throw new Exception('Invalid event type');
            }
            
            $stmt = $pdo->prepare('
                INSERT INTO walkthrough_events 
                    (user_id, walkthrough_id, step_number, event_type, event_data, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ');
            
            $stmt->execute([$userId, $walkthroughId, $stepNumber, $eventType, $eventData]);
            
            echo json_encode([
                'ok' => true,
                'message' => 'Event logged'
            ]);
            break;
            
        case 'set_preference':
            // Set user preference for walkthroughs
            $input = json_decode(file_get_contents('php://input'), true);
            $disableWalkthroughs = isset($input['disable_walkthroughs']) ? (bool)$input['disable_walkthroughs'] : false;
            
            $stmt = $pdo->prepare('
                INSERT INTO user_preferences (user_id, disable_walkthroughs, created_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE disable_walkthroughs = ?, updated_at = NOW()
            ');
            
            $stmt->execute([$userId, $disableWalkthroughs, $disableWalkthroughs]);
            
            echo json_encode([
                'ok' => true,
                'message' => 'Preference updated',
                'disable_walkthroughs' => $disableWalkthroughs
            ]);
            break;
            
        case 'get_preference':
            // Get user preference for walkthroughs
            $stmt = $pdo->prepare('SELECT disable_walkthroughs FROM user_preferences WHERE user_id = ?');
            $stmt->execute([$userId]);
            $disabled = (bool)$stmt->fetchColumn();
            
            echo json_encode([
                'ok' => true,
                'disable_walkthroughs' => $disabled
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'ok' => false,
                'error' => 'Invalid action'
            ]);
            break;
    }
    
} catch (Throwable $e) {
    error_log("Walkthrough API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
}


