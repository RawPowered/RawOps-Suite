<?php
/**
 * SACP Geographic Data API
 * Returns leads grouped by state and city with stage distribution
 * 
 * @version 1.0.0
 * @prefix sacp1015_
 */

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../required/config.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Configuration error', 'message' => $e->getMessage()]);
    exit;
}

start_session();

// Require authentication
$uid = current_user_id();
if (!$uid) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

try {
    $pdo = db();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed', 'message' => $e->getMessage()]);
    exit;
}

// Get filters from query params
$filterStages = isset($_GET['stages']) ? explode(',', $_GET['stages']) : [];
$filterTags = isset($_GET['tags']) ? explode(',', $_GET['tags']) : [];
$dateFrom = $_GET['date_from'] ?? null;
$dateTo = $_GET['date_to'] ?? null;

// Build WHERE conditions
$whereClauses = ['l.assigned_agent_id = ?'];
$params = [$uid];

// Exclude converted and archived leads
$whereClauses[] = 'l.converted_to_account_id IS NULL';

// Check if archives table exists
try {
    $pdo->query('SELECT 1 FROM crm_lead_agent_archives LIMIT 1');
    $whereClauses[] = 'NOT EXISTS (SELECT 1 FROM crm_lead_agent_archives aa WHERE aa.lead_id = l.id AND aa.agent_id = ?)';
    $params[] = $uid;
} catch (Exception $e) {
    // Archives table doesn't exist, skip this filter
}

// Filter by stages
if (!empty($filterStages)) {
    $stagePlaceholders = implode(',', array_fill(0, count($filterStages), '?'));
    $whereClauses[] = "l.lead_status IN ($stagePlaceholders)";
    $params = array_merge($params, $filterStages);
}

// Filter by tags
if (!empty($filterTags)) {
    $whereClauses[] = 'EXISTS (
        SELECT 1 FROM crm_lead_tag_assignments lta 
        JOIN crm_lead_tags lt ON lta.tag_id = lt.id 
        WHERE lta.lead_id = l.id AND lt.tag_slug IN (' . implode(',', array_fill(0, count($filterTags), '?')) . ')
    )';
    $params = array_merge($params, $filterTags);
}

// Filter by date range
if ($dateFrom) {
    $whereClauses[] = 'l.created_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo) {
    $whereClauses[] = 'l.created_at <= ?';
    $params[] = $dateTo . ' 23:59:59';
}

$whereSQL = implode(' AND ', $whereClauses);

// Get geographic breakdown
$query = "
    SELECT 
        COALESCE(l.state, 'Unknown') as state,
        COALESCE(l.city, 'Unknown') as city,
        l.lead_status,
        COUNT(*) as count,
        GROUP_CONCAT(l.id) as lead_ids
    FROM crm_leads l
    WHERE $whereSQL
    GROUP BY state, city, l.lead_status
    ORDER BY state ASC, city ASC, l.lead_status ASC
";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database query failed',
        'message' => $e->getMessage()
    ]);
    exit;
}

// Get all active stages for reference
try {
    $stagesStmt = $pdo->query('
        SELECT stage_key, stage_label, stage_color, stage_icon, sort_order
        FROM crm_lead_stages 
        WHERE is_active = 1 
        ORDER BY sort_order ASC
    ');
    $stages = $stagesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback to hardcoded stages if table doesn't exist
    $stages = [
        ['stage_key' => 'new', 'stage_label' => 'New', 'stage_color' => '#3b82f6', 'stage_icon' => '✨', 'sort_order' => 1],
        ['stage_key' => 'contacted', 'stage_label' => 'Contacted', 'stage_color' => '#8b5cf6', 'stage_icon' => '📞', 'sort_order' => 2],
        ['stage_key' => 'qualified', 'stage_label' => 'Qualified', 'stage_color' => '#a855f7', 'stage_icon' => '✓', 'sort_order' => 3],
        ['stage_key' => 'proposal_sent', 'stage_label' => 'Proposal Sent', 'stage_color' => '#d946ef', 'stage_icon' => '📄', 'sort_order' => 4],
        ['stage_key' => 'negotiating', 'stage_label' => 'Negotiating', 'stage_color' => '#f59e0b', 'stage_icon' => '💬', 'sort_order' => 5],
        ['stage_key' => 'won', 'stage_label' => 'Won', 'stage_color' => '#10b981', 'stage_icon' => '🎉', 'sort_order' => 6],
        ['stage_key' => 'lost', 'stage_label' => 'Lost', 'stage_color' => '#ef4444', 'stage_icon' => '❌', 'sort_order' => 7],
        ['stage_key' => 'dormant', 'stage_label' => 'Dormant', 'stage_color' => '#6b7280', 'stage_icon' => '💤', 'sort_order' => 8]
    ];
}

// Organize data hierarchically: state > city > stage
$geographic = [];

foreach ($results as $row) {
    $state = $row['state'];
    $city = $row['city'];
    $stage = $row['lead_status'];
    $count = (int)$row['count'];
    $leadIds = explode(',', $row['lead_ids']);
    
    // Initialize state if not exists
    if (!isset($geographic[$state])) {
        $geographic[$state] = [
            'state' => $state,
            'total_count' => 0,
            'cities' => [],
            'stage_distribution' => []
        ];
        
        // Initialize stage counters
        foreach ($stages as $stageInfo) {
            $geographic[$state]['stage_distribution'][$stageInfo['stage_key']] = 0;
        }
    }
    
    // Initialize city if not exists
    if (!isset($geographic[$state]['cities'][$city])) {
        $geographic[$state]['cities'][$city] = [
            'city' => $city,
            'state' => $state,
            'total_count' => 0,
            'stage_distribution' => [],
            'lead_ids' => []
        ];
        
        // Initialize stage counters
        foreach ($stages as $stageInfo) {
            $geographic[$state]['cities'][$city]['stage_distribution'][$stageInfo['stage_key']] = 0;
        }
    }
    
    // Update counts
    $geographic[$state]['total_count'] += $count;
    $geographic[$state]['cities'][$city]['total_count'] += $count;
    
    // Update stage distributions
    if (!isset($geographic[$state]['stage_distribution'][$stage])) {
        $geographic[$state]['stage_distribution'][$stage] = 0;
    }
    $geographic[$state]['stage_distribution'][$stage] += $count;
    
    if (!isset($geographic[$state]['cities'][$city]['stage_distribution'][$stage])) {
        $geographic[$state]['cities'][$city]['stage_distribution'][$stage] = 0;
    }
    $geographic[$state]['cities'][$city]['stage_distribution'][$stage] += $count;
    
    // Store lead IDs
    $geographic[$state]['cities'][$city]['lead_ids'] = array_merge(
        $geographic[$state]['cities'][$city]['lead_ids'],
        $leadIds
    );
}

// Convert cities associative arrays to indexed arrays
foreach ($geographic as $state => &$stateData) {
    $stateData['cities'] = array_values($stateData['cities']);
    
    // Sort cities by total_count descending
    usort($stateData['cities'], function($a, $b) {
        return $b['total_count'] - $a['total_count'];
    });
}
unset($stateData);

// Convert to indexed array and sort by total_count
$geographic = array_values($geographic);
usort($geographic, function($a, $b) {
    return $b['total_count'] - $a['total_count'];
});

// Get full lead details for each state/city (for rendering lead cards)
$leadDetailsQuery = "
    SELECT 
        l.id,
        l.company_name,
        l.first_name,
        l.last_name,
        l.email,
        l.phone,
        l.address_line1,
        l.city,
        l.state,
        l.zip_code,
        l.lead_status,
        l.lead_priority,
        l.created_at,
        l.updated_at,
        COALESCE(l.last_contact_date, l.created_at) as last_interaction,
        DATEDIFF(NOW(), l.created_at) as days_old,
        (SELECT COUNT(*) FROM crm_call_logs cl WHERE cl.lead_id = l.id) as call_count,
        GROUP_CONCAT(DISTINCT CONCAT(lt.tag_icon, ' ', lt.tag_name) SEPARATOR ', ') as tags_display,
        GROUP_CONCAT(DISTINCT lt.tag_color) as tag_colors
    FROM crm_leads l
    LEFT JOIN crm_lead_tag_assignments lta ON l.id = lta.lead_id
    LEFT JOIN crm_lead_tags lt ON lta.tag_id = lt.id
    WHERE $whereSQL
    GROUP BY l.id
    ORDER BY l.state ASC, l.city ASC, 
             FIELD(l.lead_status, 'new', 'contacted', 'qualified', 'proposal_sent', 'negotiating', 'won', 'lost', 'dormant'),
             last_interaction DESC
";

try {
    $leadsDetailsStmt = $pdo->prepare($leadDetailsQuery);
    $leadsDetailsStmt->execute($params);
    $allLeadDetails = $leadsDetailsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch lead details',
        'message' => $e->getMessage()
    ]);
    exit;
}

// Organize leads by state, city, and stage
try {
    foreach ($geographic as &$stateData) {
        foreach ($stateData['cities'] as &$cityData) {
            $cityData['leads_by_stage'] = [];
            
            // Initialize stages
            foreach ($stages as $stage) {
                $cityData['leads_by_stage'][$stage['stage_key']] = [
                    'stage' => $stage,
                    'leads' => []
                ];
            }
            
            // Distribute leads
            foreach ($allLeadDetails as $lead) {
                if ($lead['state'] === $stateData['state'] && $lead['city'] === $cityData['city']) {
                    $stageKey = $lead['lead_status'];
                    if (isset($cityData['leads_by_stage'][$stageKey])) {
                        $cityData['leads_by_stage'][$stageKey]['leads'][] = [
                            'id' => (int)$lead['id'],
                            'company_name' => $lead['company_name'],
                            'contact_name' => trim($lead['first_name'] . ' ' . $lead['last_name']),
                            'email' => $lead['email'],
                            'phone' => $lead['phone'],
                            'address' => $lead['address_line1'],
                            'priority' => $lead['lead_priority'],
                            'status' => $lead['lead_status'],
                            'days_old' => (int)$lead['days_old'],
                            'call_count' => (int)$lead['call_count'],
                            'tags_display' => $lead['tags_display'],
                            'last_interaction' => $lead['last_interaction']
                        ];
                    }
                }
            }
        }
    }
    unset($stateData, $cityData);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to organize geographic data',
        'message' => $e->getMessage()
    ]);
    exit;
}

// Generate map pins from leads with addresses
$mapPins = array_map(function($lead) {
    return [
        'id' => (int)$lead['id'],
        'company_name' => $lead['company_name'],
        'contact_name' => trim($lead['first_name'] . ' ' . $lead['last_name']),
        'address' => trim(implode(', ', array_filter([
            $lead['address_line1'],
            $lead['city'],
            $lead['state'],
            $lead['zip_code']
        ]))),
        'status' => $lead['lead_status'],
        'priority' => $lead['lead_priority'],
        'tags_display' => $lead['tags_display'] ?? 'Untagged'
    ];
}, array_filter($allLeadDetails, function($l) {
    return !empty($l['address_line1']);
}));

echo json_encode([
    'success' => true,
    'agent_id' => $uid,
    'geographic_data' => $geographic,
    'map_pins' => array_values($mapPins),
    'stages' => $stages,
    'filters' => [
        'stages' => $filterStages,
        'tags' => $filterTags,
        'date_from' => $dateFrom,
        'date_to' => $dateTo
    ],
    'meta' => [
        'total_states' => count($geographic),
        'total_pins' => count($mapPins),
        'total_leads' => count($allLeadDetails),
        'pin_limit_reached' => count($mapPins) >= 500
    ],
    'timestamp' => date('c')
]);

