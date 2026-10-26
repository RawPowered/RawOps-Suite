<?php
/**
 * SACP Assign Tag API
 * Assigns a tag to a lead
 * 
 * @version 1.0.0
 * @prefix sacp1015_
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../required/config.php';
start_session();

// Require authentication
$uid = current_user_id();
if (!$uid) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$pdo = db();

// Get request data
$input = json_decode(file_get_contents('php://input'), true);
$leadId = (int)($input['lead_id'] ?? 0);
$tagId = (int)($input['tag_id'] ?? 0);

if (!$leadId || !$tagId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing lead_id or tag_id']);
    exit;
}

try {
    // Verify lead belongs to agent
    $stmt = $pdo->prepare('SELECT id FROM crm_leads WHERE id = ? AND assigned_agent_id = ?');
    $stmt->execute([$leadId, $uid]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Lead not found or access denied']);
        exit;
    }
    
    // Verify tag exists
    $stmt = $pdo->prepare('SELECT tag_name FROM crm_lead_tags WHERE id = ? AND is_active = 1');
    $stmt->execute([$tagId]);
    $tag = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tag) {
        http_response_code(404);
        echo json_encode(['error' => 'Tag not found']);
        exit;
    }
    
    // Assign tag (will not duplicate thanks to UNIQUE KEY)
    $stmt = $pdo->prepare('
        INSERT INTO crm_lead_tag_assignments (lead_id, tag_id, assigned_by_agent_id, is_auto_assigned)
        VALUES (?, ?, ?, 0)
        ON DUPLICATE KEY UPDATE assigned_by_agent_id = ?, is_auto_assigned = 0
    ');
    $stmt->execute([$leadId, $tagId, $uid, $uid]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Tag assigned successfully',
        'tag_name' => $tag['tag_name']
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}




