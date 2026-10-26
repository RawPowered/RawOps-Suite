<?php
/**
 * SACP Agent Performance API
 * Returns individual agent's performance metrics
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

// Check if performance cache exists and is recent
$stmt = $pdo->prepare('
    SELECT * FROM crm_agent_performance_cache 
    WHERE agent_id = ? 
    AND last_updated >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
');
$stmt->execute([$uid]);
$cached = $stmt->fetch(PDO::FETCH_ASSOC);

if ($cached) {
    // Return cached data
    echo json_encode([
        'success' => true,
        'agent_id' => $uid,
        'stats' => $cached,
        'from_cache' => true,
        'timestamp' => date('c')
    ]);
    exit;
}

// Calculate fresh stats
try {
    // Call stored procedure to update cache
    $pdo->prepare('CALL sp_update_agent_performance(?)')->execute([$uid]);
    
    // Fetch updated cache
    $stmt = $pdo->prepare('SELECT * FROM crm_agent_performance_cache WHERE agent_id = ?');
    $stmt->execute([$uid]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$stats) {
        // Fallback: Calculate inline if stored procedure failed
        $stats = sacp1015_calculateAgentPerformance($pdo, $uid);
    }
    
    echo json_encode([
        'success' => true,
        'agent_id' => $uid,
        'stats' => $stats,
        'from_cache' => false,
        'timestamp' => date('c')
    ]);
    
} catch (Exception $e) {
    // Fallback calculation
    $stats = sacp1015_calculateAgentPerformance($pdo, $uid);
    
    echo json_encode([
        'success' => true,
        'agent_id' => $uid,
        'stats' => $stats,
        'from_cache' => false,
        'fallback' => true,
        'timestamp' => date('c')
    ]);
}

/**
 * Fallback function to calculate agent performance inline
 */
function sacp1015_calculateAgentPerformance($pdo, $agentId) {
    $stats = [
        'agent_id' => $agentId,
        'leads_today' => 0,
        'leads_week' => 0,
        'active_leads' => 0,
        'total_leads' => 0,
        'detailed_leads' => 0,
        'incomplete_leads' => 0,
        'missing_email' => 0,
        'missing_phone' => 0,
        'conversions_today' => 0,
        'conversions_week' => 0,
        'conversions_month' => 0,
        'detail_completion_rate' => 0,
        'conversion_rate' => 0,
        'calls_today' => 0,
        'calls_week' => 0,
        'overdue_tasks' => 0,
        'last_updated' => date('Y-m-d H:i:s')
    ];
    
    // Total leads
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM crm_leads WHERE assigned_agent_id = ?');
    $stmt->execute([$agentId]);
    $stats['total_leads'] = (int)$stmt->fetchColumn();
    
    // Leads today
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM crm_leads WHERE assigned_agent_id = ? AND DATE(created_at) = CURDATE()');
    $stmt->execute([$agentId]);
    $stats['leads_today'] = (int)$stmt->fetchColumn();
    
    // Leads this week
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM crm_leads WHERE assigned_agent_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)');
    $stmt->execute([$agentId]);
    $stats['leads_week'] = (int)$stmt->fetchColumn();
    
    // Active leads
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM crm_leads 
        WHERE assigned_agent_id = ? 
        AND lead_status IN ('new', 'contacted', 'qualified', 'proposal_sent', 'negotiating', 'customer_review', 'customer_follow_up', 'info_obtained_demo_pending')
    ");
    $stmt->execute([$agentId]);
    $stats['active_leads'] = (int)$stmt->fetchColumn();
    
    // Detailed leads
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM crm_leads 
        WHERE assigned_agent_id = ?
        AND email IS NOT NULL AND email != ''
        AND email NOT LIKE 'sample-009@example.com'
        AND email NOT LIKE 'sample-010@example.com'
        AND phone IS NOT NULL AND phone != ''
    ");
    $stmt->execute([$agentId]);
    $stats['detailed_leads'] = (int)$stmt->fetchColumn();
    
    // Incomplete leads
    $stats['incomplete_leads'] = $stats['total_leads'] - $stats['detailed_leads'];
    
    // Missing email
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM crm_leads 
        WHERE assigned_agent_id = ?
        AND (email IS NULL OR email = '' OR email LIKE 'sample-009@example.com' OR email LIKE 'sample-010@example.com')
    ");
    $stmt->execute([$agentId]);
    $stats['missing_email'] = (int)$stmt->fetchColumn();
    
    // Missing phone
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM crm_leads 
        WHERE assigned_agent_id = ?
        AND (phone IS NULL OR phone = '')
    ");
    $stmt->execute([$agentId]);
    $stats['missing_phone'] = (int)$stmt->fetchColumn();
    
    // Conversions today
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM crm_leads 
        WHERE assigned_agent_id = ? 
        AND DATE(converted_at) = CURDATE() 
        AND converted_to_account_id IS NOT NULL
    ");
    $stmt->execute([$agentId]);
    $stats['conversions_today'] = (int)$stmt->fetchColumn();
    
    // Conversions this week
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM crm_leads 
        WHERE assigned_agent_id = ? 
        AND converted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        AND converted_to_account_id IS NOT NULL
    ");
    $stmt->execute([$agentId]);
    $stats['conversions_week'] = (int)$stmt->fetchColumn();
    
    // Conversions this month
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM crm_leads 
        WHERE assigned_agent_id = ? 
        AND MONTH(converted_at) = MONTH(NOW())
        AND YEAR(converted_at) = YEAR(NOW())
        AND converted_to_account_id IS NOT NULL
    ");
    $stmt->execute([$agentId]);
    $stats['conversions_month'] = (int)$stmt->fetchColumn();
    
    // Lead detail completion rate (primary metric)
    if ($stats['total_leads'] > 0) {
        $stats['detail_completion_rate'] = round(($stats['detailed_leads'] / $stats['total_leads']) * 100, 1);
    } else {
        $stats['detail_completion_rate'] = 0;
    }
    
    // Conversion rate (secondary metric)
    if ($stats['total_leads'] > 0) {
        $stats['conversion_rate'] = round(($stats['conversions_month'] / $stats['total_leads']) * 100, 1);
    } else {
        $stats['conversion_rate'] = 0;
    }
    
    // Calls today
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM crm_call_logs 
        WHERE agent_id = ? AND DATE(call_date) = CURDATE()
    ");
    $stmt->execute([$agentId]);
    $stats['calls_today'] = (int)$stmt->fetchColumn();
    
    // Calls this week
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM crm_call_logs 
        WHERE agent_id = ? AND call_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $stmt->execute([$agentId]);
    $stats['calls_week'] = (int)$stmt->fetchColumn();
    
    // Overdue tasks
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM crm_tasks 
            WHERE agent_id = ? AND status = 'pending' AND due_date < NOW()
        ");
        $stmt->execute([$agentId]);
        $stats['overdue_tasks'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        $stats['overdue_tasks'] = 0;
    }
    
    return $stats;
}




