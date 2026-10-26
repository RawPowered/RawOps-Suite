<?php
/**
 * Lead Social Links API
 * CRUD operations for lead social media links
 * 
 * Actions:
 * - add: Add new social link
 * - update: Update existing link
 * - delete: Delete link
 * - list: Get all links for a lead
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
        // Admins have access to all leads
        $stmt = $pdo->prepare('SELECT 1 FROM crm_leads WHERE id = ? LIMIT 1');
        $stmt->execute([$leadId]);
    } else {
        // Regular agents only their assigned leads
        $stmt = $pdo->prepare('SELECT 1 FROM crm_leads WHERE id = ? AND assigned_agent_id = ? LIMIT 1');
        $stmt->execute([$leadId, $userId]);
    }
    
    return (bool)$stmt->fetch();
}

// ============================================
// ACTION: List Social Links
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
        
        $links = get_lead_social_links($pdo, $leadId);
        
        echo json_encode(['ok' => true, 'links' => $links]);
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// ACTION: Add Social Link
// ============================================
if ($action === 'add') {
    try {
        $leadId = intval($input['lead_id'] ?? 0);
        $platform = trim($input['platform'] ?? '');
        $url = trim($input['url'] ?? '');
        $displayLabel = trim($input['display_label'] ?? '');
        
        if (!$leadId || !$url) {
            throw new Exception('Lead ID and URL required');
        }
        
        if (!verify_lead_access($pdo, $leadId, $userId)) {
            throw new Exception('Access denied');
        }
        
        // Auto-detect platform if not provided
        if (!$platform) {
            $platform = detect_social_platform($url);
        }
        
        // Check limit (12 max)
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM crm_lead_social_links WHERE lead_id = ?');
        $stmt->execute([$leadId]);
        $count = (int)$stmt->fetchColumn();
        
        if ($count >= 12) {
            throw new Exception('Maximum 12 social links allowed per lead');
        }
        
        $linkId = add_lead_social_link($pdo, $leadId, $platform, $url, $displayLabel, $userId);
        
        if (!$linkId) {
            throw new Exception('Failed to add social link');
        }
        
        echo json_encode(['ok' => true, 'link_id' => $linkId, 'message' => 'Social link added successfully']);
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// ACTION: Update Social Link
// ============================================
if ($action === 'update') {
    try {
        $linkId = intval($input['link_id'] ?? 0);
        $url = trim($input['url'] ?? '');
        $displayLabel = trim($input['display_label'] ?? '');
        
        if (!$linkId || !$url) {
            throw new Exception('Link ID and URL required');
        }
        
        // Verify ownership
        $stmt = $pdo->prepare('SELECT lead_id FROM crm_lead_social_links WHERE id = ?');
        $stmt->execute([$linkId]);
        $leadId = $stmt->fetchColumn();
        
        if (!$leadId || !verify_lead_access($pdo, $leadId, $userId)) {
            throw new Exception('Access denied');
        }
        
        $success = update_lead_social_link($pdo, $linkId, $url, $displayLabel);
        
        if (!$success) {
            throw new Exception('Failed to update social link');
        }
        
        echo json_encode(['ok' => true, 'message' => 'Social link updated successfully']);
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// ACTION: Delete Social Link
// ============================================
if ($action === 'delete') {
    try {
        $linkId = intval($input['link_id'] ?? $_GET['link_id'] ?? 0);
        
        if (!$linkId) {
            throw new Exception('Link ID required');
        }
        
        // Verify ownership
        $stmt = $pdo->prepare('SELECT lead_id FROM crm_lead_social_links WHERE id = ?');
        $stmt->execute([$linkId]);
        $leadId = $stmt->fetchColumn();
        
        if (!$leadId || !verify_lead_access($pdo, $leadId, $userId)) {
            throw new Exception('Access denied');
        }
        
        $success = delete_lead_social_link($pdo, $linkId, $leadId);
        
        if (!$success) {
            throw new Exception('Failed to delete social link');
        }
        
        echo json_encode(['ok' => true, 'message' => 'Social link deleted successfully']);
        
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



