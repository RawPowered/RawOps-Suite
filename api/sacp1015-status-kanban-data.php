<?php
/**
 * SACP Status Kanban Data API
 * Returns leads grouped by status/stage with optional tag filtering
 * Inverse of the tag-based Kanban view
 * 
 * @version 1.0.0
 * @prefix sacp1015_
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display, but log
ini_set('log_errors', 1);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../required/config.php';
    start_session();

    // Require authentication
    $uid = current_user_id();
    if (!$uid) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        exit;
    }

    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get filters from query params
    $filterTags = isset($_GET['tags']) ? explode(',', $_GET['tags']) : [];
    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo = $_GET['date_to'] ?? null;

    // Build WHERE conditions
    $whereClauses = ['l.assigned_agent_id = ?'];
    $params = [$uid];

    // Exclude archived leads (but INCLUDE converted leads for Won status visibility)
    $whereClauses[] = 'NOT EXISTS (SELECT 1 FROM crm_lead_agent_archives aa WHERE aa.lead_id = l.id AND aa.agent_id = ?)';
    $params[] = $uid;

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

    // Get all active stages
    $stagesStmt = $pdo->query('
        SELECT id, stage_key, stage_label, stage_color, stage_icon, sort_order
        FROM crm_lead_stages 
        WHERE is_active = 1 
        ORDER BY sort_order ASC
    ');
    $stages = $stagesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all active tags for filter options
    $tagsStmt = $pdo->query('
        SELECT id, tag_name, tag_slug, tag_color, tag_icon, sort_order
        FROM crm_lead_tags 
        WHERE is_active = 1 
        ORDER BY sort_order ASC
    ');
    $tags = $tagsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get leads with their tags and account conversion info
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
            l.converted_to_account_id,
            l.converted_at,
            DATEDIFF(NOW(), l.created_at) as days_old,
            GROUP_CONCAT(DISTINCT lt.id) as tag_ids,
            GROUP_CONCAT(DISTINCT lt.tag_name) as tag_names,
            GROUP_CONCAT(DISTINCT lt.tag_slug) as tag_slugs,
            (SELECT COUNT(*) FROM crm_call_logs cl WHERE cl.lead_id = l.id) as call_count,
            MAX(wa.business_name) as account_name,
            MAX(wa.id) as account_id
        FROM crm_leads l
        LEFT JOIN crm_lead_tag_assignments lta ON l.id = lta.lead_id
        LEFT JOIN crm_lead_tags lt ON lta.tag_id = lt.id AND lt.is_active = 1
        LEFT JOIN wholesale_accounts wa ON l.converted_to_account_id = wa.id
        WHERE $whereSQL
        GROUP BY l.id, l.company_name, l.first_name, l.last_name, l.email, l.phone, 
                 l.address_line1, l.city, l.state, l.zip_code, l.lead_status, l.lead_priority,
                 l.created_at, l.updated_at, l.converted_to_account_id, l.converted_at
        ORDER BY l.created_at DESC
    ";

    $leadsStmt = $pdo->prepare($leadsQuery);
    $leadsStmt->execute($params);
    $leads = $leadsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Apply tag filter if specified
    if (!empty($filterTags)) {
        $leads = array_filter($leads, function($lead) use ($filterTags) {
            if (!$lead['tag_slugs']) {
                // Include untagged leads if 'untagged' is in filter
                return in_array('untagged', $filterTags);
            }
            $leadTagSlugs = explode(',', $lead['tag_slugs']);
            // Check if lead has any of the filtered tags
            return !empty(array_intersect($filterTags, $leadTagSlugs));
        });
    }

    // Organize leads by status
    $kanbanData = [];

    // Initialize structure for all stages
    foreach ($stages as $stage) {
        $stageKey = $stage['stage_key'];
        $kanbanData[$stageKey] = [
            'stage' => $stage,
            'total_count' => 0,
            'tag_groups' => []
        ];
    }

    // Build tag lookup for grouping
    $tagLookup = [];
    foreach ($tags as $tag) {
        $tagLookup[$tag['tag_slug']] = $tag;
    }

    // Add "Untagged" to tag lookup
    $tagLookup['untagged'] = [
        'id' => null,
        'tag_name' => 'Untagged',
        'tag_slug' => 'untagged',
        'tag_color' => '#9ca3af',
        'tag_icon' => '❓',
        'sort_order' => 9999
    ];

    // Distribute leads into status columns
    foreach ($leads as $lead) {
        $leadStage = $lead['lead_status'];
        $leadTagSlugs = $lead['tag_slugs'] ? explode(',', $lead['tag_slugs']) : [];
        
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
            'updated_at' => $lead['updated_at'],
            'tag_names' => $lead['tag_names'],
            'is_converted' => !empty($lead['converted_to_account_id']),
            'account_id' => $lead['account_id'] ? (int)$lead['account_id'] : null,
            'account_name' => $lead['account_name'],
            'converted_at' => $lead['converted_at']
        ];
        
        // Skip if stage doesn't exist in our structure
        if (!isset($kanbanData[$leadStage])) {
            continue;
        }
        
        // Increment total count for this status
        $kanbanData[$leadStage]['total_count']++;
        
        // If filtering by specific tags, group by tags within the status
        if (!empty($filterTags)) {
            if (empty($leadTagSlugs)) {
                // Untagged lead
                if (!isset($kanbanData[$leadStage]['tag_groups']['untagged'])) {
                    $kanbanData[$leadStage]['tag_groups']['untagged'] = [
                        'tag' => $tagLookup['untagged'],
                        'count' => 0,
                        'leads' => []
                    ];
                }
                $kanbanData[$leadStage]['tag_groups']['untagged']['leads'][] = $leadData;
                $kanbanData[$leadStage]['tag_groups']['untagged']['count']++;
            } else {
                // Add to each tag group this lead belongs to
                foreach ($leadTagSlugs as $tagSlug) {
                    if (!isset($kanbanData[$leadStage]['tag_groups'][$tagSlug])) {
                        $kanbanData[$leadStage]['tag_groups'][$tagSlug] = [
                            'tag' => isset($tagLookup[$tagSlug]) ? $tagLookup[$tagSlug] : null,
                            'count' => 0,
                            'leads' => []
                        ];
                    }
                    $kanbanData[$leadStage]['tag_groups'][$tagSlug]['leads'][] = $leadData;
                    $kanbanData[$leadStage]['tag_groups'][$tagSlug]['count']++;
                }
            }
        } else {
            // No tag filtering - add all leads to a single "all" group
            if (!isset($kanbanData[$leadStage]['tag_groups']['all'])) {
                $kanbanData[$leadStage]['tag_groups']['all'] = [
                    'tag' => null,
                    'count' => 0,
                    'leads' => []
                ];
            }
            $kanbanData[$leadStage]['tag_groups']['all']['leads'][] = $leadData;
            $kanbanData[$leadStage]['tag_groups']['all']['count']++;
        }
    }

    // Remove empty status columns if no leads
    $kanbanData = array_filter($kanbanData, function($column) {
        return $column['total_count'] > 0;
    });

    echo json_encode([
        'success' => true,
        'agent_id' => $uid,
        'kanban_data' => array_values($kanbanData),
        'available_tags' => array_values($tags),
        'filters' => [
            'tags' => $filterTags,
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ],
        'meta' => [
            'total_leads' => count($leads),
            'total_statuses' => count($kanbanData),
            'total_stages' => count($stages)
        ],
        'timestamp' => date('c')
    ]);
    
} catch (PDOException $e) {
    error_log("Status Kanban PDO Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage(),
        'code' => $e->getCode(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
} catch (Exception $e) {
    error_log("Status Kanban Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error: ' . $e->getMessage(),
        'type' => get_class($e),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
} catch (Error $e) {
    error_log("Status Kanban Fatal Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Fatal error: ' . $e->getMessage(),
        'type' => get_class($e),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
}
