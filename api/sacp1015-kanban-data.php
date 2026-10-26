<?php
/**
 * SACP Kanban Data API
 * Returns leads grouped by tag and stage for Kanban view
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
$whereClauses[] = 'NOT EXISTS (SELECT 1 FROM crm_lead_agent_archives aa WHERE aa.lead_id = l.id AND aa.agent_id = ?)';
$params[] = $uid;

// Filter by stages
if (!empty($filterStages)) {
    $stagePlaceholders = implode(',', array_fill(0, count($filterStages), '?'));
    $whereClauses[] = "l.lead_status IN ($stagePlaceholders)";
    $params = array_merge($params, $filterStages);
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

// Get all active tags
$tagsStmt = $pdo->query('
    SELECT id, tag_name, tag_slug, tag_color, tag_icon, sort_order
    FROM crm_lead_tags 
    WHERE is_active = 1 
    ORDER BY sort_order ASC
');
$tags = $tagsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get all active stages
$stagesStmt = $pdo->query('
    SELECT stage_key, stage_label, stage_color, stage_icon, sort_order
    FROM crm_lead_stages 
    WHERE is_active = 1 
    ORDER BY sort_order ASC
');
$stages = $stagesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get leads with their tags
$leadsQuery = "
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
        DATEDIFF(NOW(), l.created_at) as days_old,
        GROUP_CONCAT(DISTINCT lt.id) as tag_ids,
        GROUP_CONCAT(DISTINCT lt.tag_name) as tag_names,
        (SELECT COUNT(*) FROM crm_call_logs cl WHERE cl.lead_id = l.id) as call_count
    FROM crm_leads l
    LEFT JOIN crm_lead_tag_assignments lta ON l.id = lta.lead_id
    LEFT JOIN crm_lead_tags lt ON lta.tag_id = lt.id AND lt.is_active = 1
    WHERE $whereSQL
    GROUP BY l.id
    ORDER BY l.created_at DESC
";

$leadsStmt = $pdo->prepare($leadsQuery);
$leadsStmt->execute($params);
$leads = $leadsStmt->fetchAll(PDO::FETCH_ASSOC);

// Organize leads by tag and stage
$kanbanData = [];

// Initialize structure
foreach ($tags as $tag) {
    $tagSlug = $tag['tag_slug'];
    $kanbanData[$tagSlug] = [
        'tag' => $tag,
        'total_count' => 0,
        'stages' => []
    ];
    
    foreach ($stages as $stage) {
        $kanbanData[$tagSlug]['stages'][$stage['stage_key']] = [
            'stage' => $stage,
            'count' => 0,
            'leads' => []
        ];
    }
}

// Add "Untagged" category for leads without tags
$kanbanData['untagged'] = [
    'tag' => [
        'id' => null,
        'tag_name' => 'Untagged',
        'tag_slug' => 'untagged',
        'tag_color' => '#9ca3af',
        'tag_icon' => '❓',
        'sort_order' => 9999
    ],
    'total_count' => 0,
    'stages' => []
];

foreach ($stages as $stage) {
    $kanbanData['untagged']['stages'][$stage['stage_key']] = [
        'stage' => $stage,
        'count' => 0,
        'leads' => []
    ];
}

// Distribute leads into Kanban structure
foreach ($leads as $lead) {
    $leadStage = $lead['lead_status'];
    $leadTagIds = $lead['tag_ids'] ? explode(',', $lead['tag_ids']) : [];
    
    // Format lead data
    $leadData = [
        'id' => (int)$lead['id'],
        'company_name' => $lead['company_name'],
        'contact_name' => trim($lead['first_name'] . ' ' . $lead['last_name']),
        'email' => $lead['email'],
        'phone' => $lead['phone'],
        'location' => trim(implode(', ', array_filter([
            $lead['city'],
            $lead['state'],
            $lead['zip_code']
        ]))),
        'address' => $lead['address_line1'],
        'priority' => $lead['lead_priority'],
        'days_old' => (int)$lead['days_old'],
        'call_count' => (int)$lead['call_count'],
        'created_at' => $lead['created_at'],
        'updated_at' => $lead['updated_at']
    ];
    
    if (empty($leadTagIds)) {
        // Untagged lead
        if (isset($kanbanData['untagged']['stages'][$leadStage])) {
            $kanbanData['untagged']['stages'][$leadStage]['leads'][] = $leadData;
            $kanbanData['untagged']['stages'][$leadStage]['count']++;
            $kanbanData['untagged']['total_count']++;
        }
    } else {
        // Lead has tags - add to each tag's column
        foreach ($tags as $tag) {
            if (in_array($tag['id'], $leadTagIds)) {
                $tagSlug = $tag['tag_slug'];
                if (isset($kanbanData[$tagSlug]['stages'][$leadStage])) {
                    $kanbanData[$tagSlug]['stages'][$leadStage]['leads'][] = $leadData;
                    $kanbanData[$tagSlug]['stages'][$leadStage]['count']++;
                    $kanbanData[$tagSlug]['total_count']++;
                }
            }
        }
    }
}

// Apply tag filter if specified
if (!empty($filterTags)) {
    $filteredData = [];
    foreach ($filterTags as $filterTagSlug) {
        if (isset($kanbanData[$filterTagSlug])) {
            $filteredData[$filterTagSlug] = $kanbanData[$filterTagSlug];
        }
    }
    $kanbanData = $filteredData;
}

// Remove empty columns if no leads
$kanbanData = array_filter($kanbanData, function($column) {
    return $column['total_count'] > 0;
});

echo json_encode([
    'success' => true,
    'agent_id' => $uid,
    'kanban_data' => array_values($kanbanData),
    'filters' => [
        'stages' => $filterStages,
        'tags' => $filterTags,
        'date_from' => $dateFrom,
        'date_to' => $dateTo
    ],
    'meta' => [
        'total_leads' => count($leads),
        'total_tags' => count($kanbanData),
        'total_stages' => count($stages)
    ],
    'timestamp' => date('c')
]);




