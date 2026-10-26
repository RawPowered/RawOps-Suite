<?php
/**
 * Social Media Interactions API
 * Log DMs, comments, and other social interactions with screenshot support
 * 
 * Actions:
 * - add: Add new interaction with optional screenshot
 * - list: Get interactions for a lead
 * - delete: Delete interaction
 */

require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/social-links-helpers.php';

header('Content-Type: application/json');

try {
    start_session();
} catch (Throwable $e) {
    // Continue without session
}

$pdo = db();
$userId = current_user_id();

// Require authentication
if (!$userId) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

// Verify agent has access to lead
function verify_lead_access($pdo, $leadId, $userId) {
    $isAdminSimulation = isset($_SESSION['admin_simulation']);
    $isRealAdmin = current_admin_id() !== null;
    
    if ($isAdminSimulation || $isRealAdmin) {
        $stmt = $pdo->prepare('SELECT 1 FROM crm_leads WHERE id = ? LIMIT 1');
        $stmt->execute([$leadId]);
    } else {
        $stmt = $pdo->prepare('SELECT 1 FROM crm_leads WHERE id = ? AND assigned_agent_id = ? LIMIT 1');
        $stmt->execute([$leadId, $userId]);
    }
    
    return (bool)$stmt->fetch();
}

// ============================================
// ACTION: Add Social Interaction
// ============================================
if ($action === 'add') {
    try {
        $leadId = intval($input['lead_id'] ?? 0);
        $platform = trim($input['platform'] ?? '');
        $interactionType = $input['interaction_type'] ?? 'dm';
        $direction = $input['direction'] ?? 'outbound';
        $content = trim($input['content'] ?? '');
        $screenshotData = $input['screenshot_data'] ?? null;
        
        if (!$leadId || !$platform) {
            throw new Exception('Lead ID and platform required');
        }
        
        if (!verify_lead_access($pdo, $leadId, $userId)) {
            throw new Exception('Access denied');
        }
        
        // Validate enums
        $validTypes = ['dm', 'comment', 'post', 'story', 'other'];
        $validDirections = ['inbound', 'outbound'];
        
        if (!in_array($interactionType, $validTypes)) {
            $interactionType = 'dm';
        }
        
        if (!in_array($direction, $validDirections)) {
            $direction = 'outbound';
        }
        
        // Handle screenshot data (base64 image)
        $screenshotToStore = null;
        if ($screenshotData && strpos($screenshotData, 'data:image') === 0) {
            // Store inline (for simplicity and mobile compatibility)
            // In production, you might want to save to file system and store path
            $screenshotToStore = $screenshotData;
        }
        
        // Insert interaction
        $stmt = $pdo->prepare('
            INSERT INTO crm_social_interactions (
                lead_id, agent_id, platform, interaction_type, direction,
                content, screenshot_data, interaction_date, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ');
        
        $stmt->execute([
            $leadId,
            $userId,
            $platform,
            $interactionType,
            $direction,
            $content,
            $screenshotToStore
        ]);
        
        $interactionId = (int)$pdo->lastInsertId();
        
        echo json_encode([
            'ok' => true,
            'interaction_id' => $interactionId,
            'message' => 'Social interaction logged successfully'
        ]);
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// ACTION: List Social Interactions
// ============================================
if ($action === 'list') {
    try {
        $leadId = intval($input['lead_id'] ?? $_GET['lead_id'] ?? 0);
        
        if (!$leadId) {
            throw new Exception('Lead ID required');
        }
        
        if (!verify_lead_access($pdo, $leadId, $userId)) {
            throw new Exception('Access denied');
        }
        
        $interactions = get_lead_social_interactions($pdo, $leadId);
        
        echo json_encode(['ok' => true, 'interactions' => $interactions]);
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// ACTION: Delete Social Interaction
// ============================================
if ($action === 'delete') {
    try {
        $interactionId = intval($input['interaction_id'] ?? $_GET['interaction_id'] ?? 0);
        
        if (!$interactionId) {
            throw new Exception('Interaction ID required');
        }
        
        // Verify ownership
        $stmt = $pdo->prepare('SELECT lead_id FROM crm_social_interactions WHERE id = ?');
        $stmt->execute([$interactionId]);
        $leadId = $stmt->fetchColumn();
        
        if (!$leadId || !verify_lead_access($pdo, $leadId, $userId)) {
            throw new Exception('Access denied');
        }
        
        // Delete
        $stmt = $pdo->prepare('DELETE FROM crm_social_interactions WHERE id = ?');
        $stmt->execute([$interactionId]);
        
        echo json_encode(['ok' => true, 'message' => 'Interaction deleted successfully']);
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Invalid action
http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Invalid action']);
exit;



