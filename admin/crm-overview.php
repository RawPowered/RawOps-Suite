<?php
/**
 * Admin CRM Overview - View all leads and manage assignments
 */

$SEO = [
    'title' => 'CRM Overview — Admin',
    'description' => 'Admin overview of all CRM leads, accounts, and sales agent performance.',
    'type' => 'website'
];

require_once __DIR__ . '/../required/config.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();
$adminId = current_admin_id();

// Get filter parameters
$filterPriority = $_GET['priority'] ?? 'all';
$filterSource = $_GET['source'] ?? 'all';
$filterStage = $_GET['stage'] ?? 'all';
$searchQuery = $_GET['search'] ?? '';

// Handle AJAX sync single address
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sync_single_address') {
    header('Content-Type: application/json');
    try {
        $leadId = intval($_POST['lead_id'] ?? 0);
        if (!$leadId) {
            throw new Exception('Missing lead_id');
        }
        
        // Get lead address
        $stmt = $pdo->prepare('SELECT id, address, company_name, first_name FROM crm_leads WHERE id = ?');
        $stmt->execute([$leadId]);
        $lead = $stmt->fetch();
        
        if (!$lead || !$lead['address']) {
            throw new Exception('Lead not found or has no address');
        }
        
        global $google_api_key, $gemini_api_key;
        $apiKey = $google_api_key ?: $gemini_api_key;
        
        // Call Google Geocoding API
        $address = urlencode($lead['address']);
        $url = "https://maps.googleapis.com/maps/api/geocode/json?address={$address}&key={$apiKey}";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if (!$response || $httpCode !== 200) {
            throw new Exception('Geocoding API request failed (HTTP ' . $httpCode . ')');
        }
        
        $data = json_decode($response, true);
        
        if (empty($data['results'][0]['address_components'])) {
            throw new Exception('No address components returned from Google');
        }
        
        // Parse address components
        $components = $data['results'][0]['address_components'];
        $parsed = [
            'street_number' => '',
            'route' => '',
            'locality' => '',
            'admin_area_l1' => '',
            'postal_code' => '',
            'country' => ''
        ];
        
        foreach ($components as $component) {
            $types = $component['types'] ?? [];
            if (in_array('street_number', $types)) {
                $parsed['street_number'] = $component['long_name'] ?? '';
            }
            if (in_array('route', $types)) {
                $parsed['route'] = $component['long_name'] ?? '';
            }
            if (in_array('locality', $types)) {
                $parsed['locality'] = $component['long_name'] ?? '';
            }
            if (in_array('administrative_area_level_1', $types)) {
                $parsed['admin_area_l1'] = $component['short_name'] ?? '';
            }
            if (in_array('postal_code', $types)) {
                $parsed['postal_code'] = $component['long_name'] ?? '';
            }
            if (in_array('country', $types)) {
                $parsed['country'] = $component['short_name'] ?? '';
            }
        }
        
        // Build address_line1 from street_number + route
        $addressLine1 = trim($parsed['street_number'] . ' ' . $parsed['route']);
        
        // Update lead
        $updateStmt = $pdo->prepare('
            UPDATE crm_leads 
            SET address_line1 = COALESCE(NULLIF(address_line1, ""), ?),
                city = COALESCE(NULLIF(city, ""), ?),
                state = COALESCE(NULLIF(state, ""), ?),
                zip_code = COALESCE(NULLIF(zip_code, ""), ?),
                postal_code = COALESCE(NULLIF(postal_code, ""), ?),
                country = COALESCE(NULLIF(country, ""), ?),
                updated_at = NOW()
            WHERE id = ?
        ');
        $updateStmt->execute([
            $addressLine1 ?: null,
            $parsed['locality'] ?: null,
            $parsed['admin_area_l1'] ?: null,
            $parsed['postal_code'] ?: null,
            $parsed['postal_code'] ?: null,
            $parsed['country'] ?: 'USA',
            $leadId
        ]);
        
        echo json_encode([
            'ok' => true,
            'parsed' => [
                'address_line1' => $addressLine1,
                'city' => $parsed['locality'],
                'state' => $parsed['admin_area_l1'],
                'zip_code' => $parsed['postal_code'],
                'country' => $parsed['country']
            ]
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Handle AJAX sync addresses action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sync_addresses') {
    header('Content-Type: application/json');
    try {
        $forceResync = !empty($_POST['force_resync']);
        
        // Fetch leads with full address (optionally including those with partial data for re-sync)
        if ($forceResync) {
            // Re-sync ALL leads with addresses
            $stmt = $pdo->prepare('
                SELECT id, address, company_name, first_name, last_name
                FROM crm_leads 
                WHERE address IS NOT NULL 
                AND address <> ""
                LIMIT 100
            ');
        } else {
            // Only sync incomplete leads
            $stmt = $pdo->prepare('
                SELECT id, address, company_name, first_name, last_name
                FROM crm_leads 
                WHERE address IS NOT NULL 
                AND address <> ""
                AND (address_line1 IS NULL OR address_line1 = "" OR city IS NULL OR city = "" OR zip_code IS NULL OR zip_code = "")
                LIMIT 100
            ');
        }
        $stmt->execute();
        $leads = $stmt->fetchAll();
        
        $synced = 0;
        $failed = 0;
        $errors = [];
        
        global $google_api_key, $gemini_api_key;
        $apiKey = $google_api_key ?: $gemini_api_key;
        
        foreach ($leads as $lead) {
            try {
                // Use Google Geocoding API to get structured address
                $address = urlencode($lead['address']);
                $url = "https://maps.googleapis.com/maps/api/geocode/json?address={$address}&key={$apiKey}";
                
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if (!$response || $httpCode !== 200) {
                    $failed++;
                    $errors[] = 'Lead ' . $lead['id'] . ' (' . ($lead['company_name'] ?: $lead['first_name']) . '): API request failed';
                    continue;
                }
                
                $data = json_decode($response, true);
                
                if (empty($data['results'][0]['address_components'])) {
                    $failed++;
                    $errors[] = 'Lead ' . $lead['id'] . ' (' . ($lead['company_name'] ?: $lead['first_name']) . '): No geocoding results';
                    continue;
                }
                
                // Parse address components
                $components = $data['results'][0]['address_components'];
                $parsed = [
                    'street_number' => '',
                    'route' => '',
                    'locality' => '',
                    'admin_area_l1' => '',
                    'postal_code' => '',
                    'country' => ''
                ];
                
                foreach ($components as $component) {
                    $types = $component['types'] ?? [];
                    if (in_array('street_number', $types)) {
                        $parsed['street_number'] = $component['long_name'] ?? '';
                    }
                    if (in_array('route', $types)) {
                        $parsed['route'] = $component['long_name'] ?? '';
                    }
                    if (in_array('locality', $types)) {
                        $parsed['locality'] = $component['long_name'] ?? '';
                    }
                    if (in_array('administrative_area_level_1', $types)) {
                        $parsed['admin_area_l1'] = $component['short_name'] ?? '';
                    }
                    if (in_array('postal_code', $types)) {
                        $parsed['postal_code'] = $component['long_name'] ?? '';
                    }
                    if (in_array('country', $types)) {
                        $parsed['country'] = $component['short_name'] ?? '';
                    }
                }
                
                // Build address_line1 from street_number + route
                $addressLine1 = trim($parsed['street_number'] . ' ' . $parsed['route']);
                
                // Update lead with parsed fields (always update, even if some fields are empty)
                $updateStmt = $pdo->prepare('
                    UPDATE crm_leads 
                    SET address_line1 = COALESCE(NULLIF(address_line1, ""), ?),
                        city = COALESCE(NULLIF(city, ""), ?),
                        state = COALESCE(NULLIF(state, ""), ?),
                        zip_code = COALESCE(NULLIF(zip_code, ""), ?),
                        postal_code = COALESCE(NULLIF(postal_code, ""), ?),
                        country = COALESCE(NULLIF(country, ""), ?),
                        updated_at = NOW()
                    WHERE id = ?
                ');
                $updateStmt->execute([
                    $addressLine1 ?: null,
                    $parsed['locality'] ?: null,
                    $parsed['admin_area_l1'] ?: null,
                    $parsed['postal_code'] ?: null,
                    $parsed['postal_code'] ?: null,
                    $parsed['country'] ?: 'USA',
                    $lead['id']
                ]);
                $synced++;
                
                // Small delay to avoid rate limiting (10 requests per second max)
                usleep(120000); // 120ms delay = ~8 requests/sec
            } catch (Throwable $e) {
                $failed++;
                $errors[] = 'Lead ' . $lead['id'] . ' (' . ($lead['company_name'] ?: $lead['first_name']) . '): ' . $e->getMessage();
            }
        }
        
        echo json_encode([
            'ok' => true,
            'synced' => $synced,
            'failed' => $failed,
            'total' => count($leads),
            'mode' => $forceResync ? 're-sync' : 'fill-empty',
            'errors' => array_slice($errors, 0, 5) // Only return first 5 errors
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Handle lead actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $ajax = !empty($_POST['ajax']);
    $ajaxPayload = null;
    
    try {
        if ($action === 'update_coc_access') {
            $leadId = intval($_POST['lead_id']);
            $accountId = intval($_POST['account_id']);
            $agentId = intval($_POST['agent_id']);
            $canView = isset($_POST['can_view']) ? 1 : 0;
            $canEdit = isset($_POST['can_edit']) ? 1 : 0;

            if ($accountId && $agentId) {
                // Upsert permission row
                $pdo->prepare('
                    INSERT INTO payment_method_permissions (wholesale_account_id, agent_id, can_view, can_edit, granted_by_admin_id, reason)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE can_view = VALUES(can_view), can_edit = VALUES(can_edit), granted_by_admin_id = VALUES(granted_by_admin_id), revoked_at = NULL
                ')->execute([$accountId, $agentId, $canView, $canEdit, $adminId, trim($_POST['reason'] ?? '')]);
                flash_set('success', 'COC access updated.');
            }
            
        } elseif ($action === 'archive_lead') {
            $leadId = intval($_POST['lead_id']);
            $reason = $_POST['archive_reason'];
            $notes = trim($_POST['archive_notes'] ?? '');
            
            if ($leadId && $reason) {
                // Archive the lead
                $stmt = $pdo->prepare('
                    UPDATE crm_leads 
                    SET is_archived = 1, archived_at = NOW(), archived_by = ?, 
                        archive_reason = ?, archive_notes = ? 
                    WHERE id = ?
                ');
                $stmt->execute([$adminId, $reason, $notes, $leadId]);
                // Persist an audit note with the archive reason
                try {
                    $pdo->prepare('INSERT INTO crm_notes (agent_id, lead_id, note_type, title, content, is_private, created_at) VALUES (?, ?, "important", ?, ?, 0, NOW())')
                        ->execute([$adminId ?: 0, $leadId, 'Lead Archived', 'Reason: ' . $reason . ($notes !== '' ? ' — Notes: ' . $notes : '')]);
                } catch (Throwable $eNote) {}
                
                flash_set('success', 'Lead archived successfully.');
            }
            
        } elseif ($action === 'restore_lead') {
            $leadId = intval($_POST['lead_id']);
            
            if ($leadId) {
                // Restore the lead
                $stmt = $pdo->prepare('
                    UPDATE crm_leads 
                    SET is_archived = 0, archived_at = NULL, archived_by = NULL, 
                        archive_reason = NULL, archive_notes = NULL 
                    WHERE id = ?
                ');
                $stmt->execute([$leadId]);
                
                flash_set('success', 'Lead restored successfully.');
            }
            
        } elseif ($action === 'delete_lead_permanently') {
            $leadId = intval($_POST['lead_id']);
            $confirmDelete = $_POST['confirm_delete'] ?? '';
            
            if ($leadId && $confirmDelete === 'DELETE_PERMANENTLY') {
                // Permanently delete the lead and related data using stored procedure when available
                $pdo->beginTransaction();
                try {
                    $check = $pdo->query("SHOW PROCEDURE STATUS WHERE Db = DATABASE() AND Name = 'crm_purge_lead'")->fetch();
                    if ($check) {
                        $call = $pdo->prepare('CALL crm_purge_lead(?)');
                        $call->execute([$leadId]);
                    } else {
                        // Fallback: inline purge mirroring the procedure semantics
                        $pdo->prepare('DELETE FROM crm_notes WHERE lead_id = ?')->execute([$leadId]);
                        $pdo->prepare('DELETE FROM crm_lead_assignments WHERE lead_id = ?')->execute([$leadId]);
                        $pdo->prepare('UPDATE crm_call_logs SET lead_id = NULL WHERE lead_id = ?')->execute([$leadId]);
                        $pdo->prepare('UPDATE crm_tasks SET lead_id = NULL WHERE lead_id = ?')->execute([$leadId]);
                        $pdo->prepare('UPDATE crm_opportunities SET lead_id = NULL WHERE lead_id = ?')->execute([$leadId]);
                        $pdo->prepare('DELETE FROM crm_leads WHERE id = ?')->execute([$leadId]);
                    }
                    $pdo->commit();
                } catch (Throwable $eDel) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $eDel;
                }
                flash_set('success', 'Lead permanently deleted.');
            }
            
        } elseif ($action === 'assign_lead') {
            $leadId = intval($_POST['lead_id']);
            $agentId = intval($_POST['agent_id']) ?: null;
            $reason = trim($_POST['reason'] ?? 'Manual assignment by admin');
            
            if ($leadId) {
                // Get current assignment
                $stmt = $pdo->prepare('SELECT assigned_agent_id FROM crm_leads WHERE id = ?');
                $stmt->execute([$leadId]);
                $currentAgent = $stmt->fetchColumn();
                
                // Update lead assignment
                $stmt = $pdo->prepare('UPDATE crm_leads SET assigned_agent_id = ? WHERE id = ?');
                $stmt->execute([$agentId, $leadId]);
                
                // Log assignment history
                $stmt = $pdo->prepare('INSERT INTO crm_lead_assignments (lead_id, from_agent_id, to_agent_id, assigned_by_admin_id, assignment_reason) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$leadId, $currentAgent, $agentId, $adminId, $reason]);
                
                // Optionally grant COC permissions for converted accounts
                $grantView = isset($_POST['grant_coc_view']) ? 1 : 0;
                $grantEdit = isset($_POST['grant_coc_edit']) ? 1 : 0;
                if (($grantView || $grantEdit) && $agentId) {
                    $accStmt = $pdo->prepare('SELECT converted_to_account_id FROM crm_leads WHERE id = ?');
                    $accStmt->execute([$leadId]);
                    $accountId = (int)$accStmt->fetchColumn();
                    if ($accountId) {
                        // Upsert permission
                        $pdo->prepare('
                            INSERT INTO payment_method_permissions (wholesale_account_id, agent_id, can_view, can_edit, granted_by_admin_id, reason)
                            VALUES (?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE can_view = VALUES(can_view), can_edit = VALUES(can_edit), revoked_at = NULL
                        ')->execute([$accountId, $agentId, $grantView, $grantEdit, $adminId, 'Granted during lead reassignment']);
                    }
                }

                flash_set('success', 'Lead assignment updated successfully.');
            }
        } elseif ($action === 'bulk_assign') {
            $leadIds = array_map('intval', $_POST['lead_ids'] ?? []);
            $agentId = intval($_POST['bulk_agent_id']) ?: null;
            $reason = trim($_POST['bulk_reason'] ?? 'Bulk assignment by admin');
            $grantView = isset($_POST['bulk_grant_coc_view']) ? 1 : 0;
            $grantEdit = isset($_POST['bulk_grant_coc_edit']) ? 1 : 0;
            
            $assignedCount = 0;
            foreach ($leadIds as $leadId) {
                if ($leadId > 0) {
                    // Get current assignment
                    $stmt = $pdo->prepare('SELECT assigned_agent_id FROM crm_leads WHERE id = ?');
                    $stmt->execute([$leadId]);
                    $currentAgent = $stmt->fetchColumn();
                    
                    // Update lead assignment
                    $stmt = $pdo->prepare('UPDATE crm_leads SET assigned_agent_id = ? WHERE id = ?');
                    $stmt->execute([$agentId, $leadId]);
                    
                    // Log assignment history
                    $stmt = $pdo->prepare('INSERT INTO crm_lead_assignments (lead_id, from_agent_id, to_agent_id, assigned_by_admin_id, assignment_reason) VALUES (?, ?, ?, ?, ?)');
                    $stmt->execute([$leadId, $currentAgent, $agentId, $adminId, $reason]);
                    
                    // Optional bulk grant
                    if (($grantView || $grantEdit) && $agentId) {
                        $accStmt = $pdo->prepare('SELECT converted_to_account_id FROM crm_leads WHERE id = ?');
                        $accStmt->execute([$leadId]);
                        $accountId = (int)$accStmt->fetchColumn();
                        if ($accountId) {
                            $pdo->prepare('
                                INSERT INTO payment_method_permissions (wholesale_account_id, agent_id, can_view, can_edit, granted_by_admin_id, reason)
                                VALUES (?, ?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE can_view = VALUES(can_view), can_edit = VALUES(can_edit), revoked_at = NULL
                            ')->execute([$accountId, $agentId, $grantView, $grantEdit, $adminId, 'Granted during bulk reassignment']);
                        }
                    }

                    $assignedCount++;
                }
            }
            
            if ($ajax) {
                $ajaxPayload = [
                    'ok' => true,
                    'assigned_count' => $assignedCount,
                    'agent_id' => $agentId,
                    'lead_ids' => $leadIds,
                ];
            } else {
                flash_set('success', "Successfully reassigned {$assignedCount} leads.");
            }
        }
    } catch (Exception $e) {
        if ($ajax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'Error updating lead assignments: ' . $e->getMessage()]);
            exit;
        }
        flash_set('error', 'Error updating lead assignments: ' . $e->getMessage());
    }
    
    if ($ajax) {
        header('Content-Type: application/json');
        echo json_encode($ajaxPayload ?: ['ok' => true]);
        exit;
    }
    
    header('Location: crm-overview.php');
    exit;
}

require_once __DIR__ . '/../required/header.php';

// Fetch data
$leads = [];
$agents = [];
$stats = [];
$leadAssignments = [];

try {
    // Check if viewing archived leads
    $viewArchived = isset($_GET['view']) && $_GET['view'] === 'archived';
    
    // Build WHERE clause for filters
    $whereConditions = ['l.is_archived = ?'];
    $params = [$viewArchived ? 1 : 0];
    
    if ($filterPriority !== 'all') {
        $whereConditions[] = 'l.lead_priority = ?';
        $params[] = $filterPriority;
    }
    
    if ($filterSource !== 'all') {
        $whereConditions[] = 'l.lead_source = ?';
        $params[] = $filterSource;
    }
    
    if ($filterStage !== 'all') {
        $whereConditions[] = 'l.lead_status = ?';
        $params[] = $filterStage;
    }
    
    if (!empty($searchQuery)) {
        $whereConditions[] = '(l.first_name LIKE ? OR l.last_name LIKE ? OR l.email LIKE ? OR l.company_name LIKE ? OR l.phone LIKE ?)';
        $searchParam = "%{$searchQuery}%";
        $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Get leads with agent info and stage details (active or archived based on view)
        $stmt = $pdo->prepare("
            SELECT l.*, 
                   CONCAT(l.first_name, ' ', l.last_name) as full_name,
                   CONCAT(a.first_name, ' ', a.last_name) as agent_name,
                   a.email as agent_email,
                   DATEDIFF(NOW(), l.created_at) as days_old,
                   DATEDIFF(NOW(), COALESCE(l.last_contact_date, l.created_at)) as days_since_contact,
                   (SELECT COUNT(*) FROM crm_call_logs cl WHERE cl.lead_id = l.id) as call_count,
                   (SELECT MAX(cl.call_date) FROM crm_call_logs cl WHERE cl.lead_id = l.id) as last_call_date,
                   CONCAT(arch.first_name, ' ', arch.last_name) as archived_by_name,
                   ls.stage_label,
                   ls.stage_color,
                   ls.stage_icon,
                   l.converted_to_account_id,
                   l.converted_to_contact_id,
                   (SELECT COUNT(*) FROM sample_requests sr WHERE sr.lead_id = l.id) as sample_count,
                   CASE WHEN l.address IS NOT NULL AND l.address <> '' AND (l.address_line1 IS NULL OR l.address_line1 = '' OR l.city IS NULL OR l.city = '') THEN 1 ELSE 0 END as needs_address_sync
            FROM crm_leads l
            LEFT JOIN users a ON a.id = l.assigned_agent_id
            LEFT JOIN users arch ON arch.id = l.archived_by
            LEFT JOIN crm_lead_stages ls ON ls.stage_key = l.lead_status
            WHERE {$whereClause}
            ORDER BY 
                CASE WHEN l.lead_priority = 'urgent' THEN 1 
                     WHEN l.lead_priority = 'high' THEN 2 
                     WHEN l.lead_priority = 'medium' THEN 3 
                     ELSE 4 END,
                l.created_at DESC
        ");
    $stmt->execute($params);
    $leads = $stmt->fetchAll();

    // Get all sales agents
    $stmt = $pdo->prepare('
        SELECT u.id, CONCAT(u.first_name, " ", u.last_name) as name, u.email,
               COUNT(l.id) as lead_count,
               COUNT(CASE WHEN l.lead_status = "new" THEN 1 END) as new_leads,
               COUNT(CASE WHEN l.lead_status IN ("contacted", "qualified") THEN 1 END) as active_leads,
               COUNT(CASE WHEN l.lead_status = "won" THEN 1 END) as won_leads,
               SUM(l.estimated_deal_value) as pipeline_value
        FROM users u
        JOIN user_roles ur ON ur.user_id = u.id
        JOIN roles r ON r.id = ur.role_id
        LEFT JOIN crm_leads l ON l.assigned_agent_id = u.id
        WHERE r.name = "sales_agent"
        GROUP BY u.id
        ORDER BY u.first_name, u.last_name
    ');
    $stmt->execute();
    $agents = $stmt->fetchAll();

    // Overall stats (exclude archived) with enhanced metrics
    $stmt = $pdo->query('
        SELECT 
            COUNT(CASE WHEN is_archived = 0 THEN 1 END) as total_leads,
            COUNT(CASE WHEN is_archived = 1 THEN 1 END) as archived_leads,
            COUNT(CASE WHEN assigned_agent_id IS NULL AND is_archived = 0 THEN 1 END) as unassigned_leads,
            COUNT(CASE WHEN lead_status = "new" AND is_archived = 0 THEN 1 END) as new_leads,
            COUNT(CASE WHEN lead_status IN ("contacted", "qualified", "proposal_sent", "negotiating") AND is_archived = 0 THEN 1 END) as active_leads,
            COUNT(CASE WHEN lead_status = "won" AND is_archived = 0 THEN 1 END) as won_leads,
            COUNT(CASE WHEN lead_status = "lost" AND is_archived = 0 THEN 1 END) as lost_leads,
            COUNT(CASE WHEN lead_priority = "urgent" AND is_archived = 0 THEN 1 END) as urgent_leads,
            COUNT(CASE WHEN lead_priority = "high" AND is_archived = 0 THEN 1 END) as high_priority_leads,
            COUNT(CASE WHEN next_follow_up IS NOT NULL AND next_follow_up < NOW() AND is_archived = 0 THEN 1 END) as overdue_followups,
            COUNT(CASE WHEN DATEDIFF(NOW(), COALESCE(last_contact_date, created_at)) > 30 AND is_archived = 0 AND lead_status NOT IN ("won", "lost") THEN 1 END) as stale_leads,
            SUM(CASE WHEN is_archived = 0 THEN estimated_deal_value ELSE 0 END) as total_pipeline_value,
            AVG(CASE WHEN is_archived = 0 THEN interest_level END) as avg_interest_level,
            (COUNT(CASE WHEN lead_status = "won" AND is_archived = 0 THEN 1 END) / NULLIF(COUNT(CASE WHEN is_archived = 0 THEN 1 END), 0) * 100) as conversion_rate_all_time
        FROM crm_leads
    ');
    $stats = $stmt->fetch();
    
    // Sample request stats
    $stmt = $pdo->query('SELECT COUNT(*) FROM sample_requests WHERE status = "pending"');
    $stats['pending_samples'] = $stmt->fetchColumn();
    
    // This week stats
    $stmt = $pdo->query('SELECT COUNT(*) FROM crm_leads WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND is_archived = 0');
    $stats['new_this_week'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query('SELECT COUNT(*) FROM crm_leads WHERE lead_status = "won" AND converted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)');
    $stats['converted_this_week'] = $stmt->fetchColumn();
    
    // Average response time
    $stmt = $pdo->query('
        SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, last_contact_date)) 
        FROM crm_leads 
        WHERE last_contact_date IS NOT NULL AND is_archived = 0
    ');
    $stats['avg_response_hours'] = $stmt->fetchColumn() ?: 0;

    // Recent lead assignments
    $stmt = $pdo->prepare('
        SELECT la.*, 
               CONCAT(l.first_name, " ", l.last_name) as lead_name,
               CONCAT(fa.first_name, " ", fa.last_name) as from_agent_name,
               CONCAT(ta.first_name, " ", ta.last_name) as to_agent_name,
               CONCAT(admin.first_name, " ", admin.last_name) as admin_name
        FROM crm_lead_assignments la
        JOIN crm_leads l ON l.id = la.lead_id
        LEFT JOIN users fa ON fa.id = la.from_agent_id
        LEFT JOIN users ta ON ta.id = la.to_agent_id
        LEFT JOIN users admin ON admin.id = la.assigned_by_admin_id
        ORDER BY la.assigned_at DESC
        LIMIT 10
    ');
    $stmt->execute();
    $leadAssignments = $stmt->fetchAll();

} catch (Exception $e) {
    // CRM tables don't exist yet
    $crmNotSetup = true;
}

?>

<div class="asacp1021_crm_container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <div class="asacp1021_page_header">
        <div>
            <h1>CRM Overview <?php echo $viewArchived ? '- Archived' : ''; ?></h1>
            <p>
                <?php if ($viewArchived): ?>
                    Viewing archived leads (<?php echo count($leads); ?> total)
                <?php else: ?>
                    Manage all leads and sales agent assignments
                <?php endif; ?>
            </p>
        </div>
        <div class="asacp1021_header_actions">
            <?php if ($viewArchived): ?>
                <a href="crm-overview.php" class="btn secondary">Back to Active Leads</a>
            <?php else: ?>
                <a href="?view=archived" class="btn secondary">View Archived (<?php echo $stats['archived_leads']; ?>)</a>
            <?php endif; ?>
            <button onclick="syncLeadAddresses(false)" class="btn secondary" id="syncAddressesBtn" style="display:flex; align-items:center; gap:6px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                    <circle cx="12" cy="10" r="3"/>
                </svg>
                Parse Incomplete Addresses
            </button>
            <button onclick="syncLeadAddresses(true)" class="btn secondary" style="display:flex; align-items:center; gap:6px; background: #f59e0b; color: white; border-color: #f59e0b;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M23 4v6h-6M1 20v-6h6"/>
                    <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                </svg>
                Re-Parse All Addresses
            </button>
            <a href="../onboarding/sales-agent/" class="btn secondary">View as Sales Agent</a>
            <a href="crm-reports.php" class="btn">Reports</a>
        </div>
    </div>

    <?php if (isset($crmNotSetup)): ?>
    <!-- CRM Setup Notice -->
    <div class="card" style="background: linear-gradient(135deg, #fef3c7, #fbbf24); border: 2px solid #f59e0b; margin-bottom: 24px;">
        <div style="display: flex; align-items: center; gap: 16px;">
            <div style="font-size: 48px;">⚙️</div>
            <div>
                <h2 style="margin: 0 0 8px 0; color: #92400e;">CRM System Not Set Up</h2>
                <p style="margin: 0 0 16px 0; color: #a16207;">
                    The CRM database tables need to be created before you can manage leads and assignments.
                </p>
                <div style="background: rgba(0,0,0,0.1); padding: 12px; border-radius: 6px; font-family: monospace; font-size: 12px; color: #92400e; margin-bottom: 16px;">
                    mysql -u username -p database_name < /path/to/migrations/2025-09-11-crm-system-schema.sql
                </div>
                <a href="debug-panel.php" class="btn" style="background: #92400e;">Go to Debug Panel</a>
            </div>
        </div>
    </div>
    <?php else: ?>

    <!-- Statistics Overview -->
    <div class="asacp1021_stats_grid">
        <div class="asacp1021_stat_card">
            <div class="asacp1021_stat_icon">👥</div>
            <div class="asacp1021_stat_value"><?php echo number_format($stats['total_leads'] ?: 0); ?></div>
            <div class="asacp1021_stat_label">Total Leads</div>
            <div class="asacp1021_stat_meta"><?php echo number_format($stats['new_this_week'] ?: 0); ?> this week</div>
        </div>
        <div class="asacp1021_stat_card asacp1021_alert_card">
            <div class="asacp1021_stat_icon">⚠️</div>
            <div class="asacp1021_stat_value"><?php echo number_format($stats['unassigned_leads'] ?: 0); ?></div>
            <div class="asacp1021_stat_label">Unassigned</div>
            <div class="asacp1021_stat_meta"><?php echo number_format($stats['urgent_leads'] ?: 0); ?> urgent</div>
        </div>
        <div class="asacp1021_stat_card asacp1021_success_card">
            <div class="asacp1021_stat_icon">✨</div>
            <div class="asacp1021_stat_value"><?php echo number_format($stats['new_leads'] ?: 0); ?></div>
            <div class="asacp1021_stat_label">New Leads</div>
        </div>
        <div class="asacp1021_stat_card asacp1021_active_card">
            <div class="asacp1021_stat_icon">🔄</div>
            <div class="asacp1021_stat_value"><?php echo number_format($stats['active_leads'] ?: 0); ?></div>
            <div class="asacp1021_stat_label">Active Leads</div>
        </div>
        <div class="asacp1021_stat_card asacp1021_won_card">
            <div class="asacp1021_stat_icon">🎉</div>
            <div class="asacp1021_stat_value"><?php echo number_format($stats['won_leads'] ?: 0); ?></div>
            <div class="asacp1021_stat_label">Won Deals</div>
            <div class="asacp1021_stat_meta"><?php echo number_format($stats['converted_this_week'] ?: 0); ?> this week</div>
        </div>
        <div class="asacp1021_stat_card asacp1021_pipeline_card">
            <div class="asacp1021_stat_icon">💰</div>
            <div class="asacp1021_stat_value">$<?php echo number_format($stats['total_pipeline_value'] ?: 0, 0); ?></div>
            <div class="asacp1021_stat_label">Pipeline Value</div>
        </div>
        <div class="asacp1021_stat_card asacp1021_warning_card">
            <div class="asacp1021_stat_icon">📅</div>
            <div class="asacp1021_stat_value"><?php echo number_format($stats['overdue_followups'] ?: 0); ?></div>
            <div class="asacp1021_stat_label">Overdue Follow-ups</div>
        </div>
        <div class="asacp1021_stat_card">
            <div class="asacp1021_stat_icon">📦</div>
            <div class="asacp1021_stat_value"><?php echo number_format($stats['pending_samples'] ?: 0); ?></div>
            <div class="asacp1021_stat_label">Pending Samples</div>
        </div>
    </div>
    
    <!-- Filter Bar -->
    <div class="asacp1021_filter_bar">
        <form method="get">
            <?php if ($viewArchived): ?>
                <input type="hidden" name="view" value="archived">
            <?php endif; ?>
            
            <div class="asacp1021_filter_group">
                <label>Search</label>
                <input type="text" name="search" placeholder="Name, email, company..." value="<?php echo htmlspecialchars($searchQuery); ?>">
            </div>
            
            <div class="asacp1021_filter_group">
                <label>Priority</label>
                <select name="priority">
                    <option value="all">All Priorities</option>
                    <option value="urgent" <?php echo $filterPriority === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                    <option value="high" <?php echo $filterPriority === 'high' ? 'selected' : ''; ?>>High</option>
                    <option value="medium" <?php echo $filterPriority === 'medium' ? 'selected' : ''; ?>>Medium</option>
                    <option value="low" <?php echo $filterPriority === 'low' ? 'selected' : ''; ?>>Low</option>
                </select>
            </div>
            
            <div class="asacp1021_filter_group">
                <label>Source</label>
                <select name="source">
                    <option value="all">All Sources</option>
                    <option value="website" <?php echo $filterSource === 'website' ? 'selected' : ''; ?>>Website</option>
                    <option value="referral" <?php echo $filterSource === 'referral' ? 'selected' : ''; ?>>Referral</option>
                    <option value="cold_call" <?php echo $filterSource === 'cold_call' ? 'selected' : ''; ?>>Cold Call</option>
                    <option value="trade_show" <?php echo $filterSource === 'trade_show' ? 'selected' : ''; ?>>Trade Show</option>
                    <option value="social_media" <?php echo $filterSource === 'social_media' ? 'selected' : ''; ?>>Social Media</option>
                    <option value="email_campaign" <?php echo $filterSource === 'email_campaign' ? 'selected' : ''; ?>>Email Campaign</option>
                    <option value="other" <?php echo $filterSource === 'other' ? 'selected' : ''; ?>>Other</option>
                </select>
            </div>
            
            <div class="asacp1021_filter_group">
                <label>Stage</label>
                <select name="stage">
                    <option value="all">All Stages</option>
                    <option value="new" <?php echo $filterStage === 'new' ? 'selected' : ''; ?>>New</option>
                    <option value="contacted" <?php echo $filterStage === 'contacted' ? 'selected' : ''; ?>>Contacted</option>
                    <option value="qualified" <?php echo $filterStage === 'qualified' ? 'selected' : ''; ?>>Qualified</option>
                    <option value="proposal_sent" <?php echo $filterStage === 'proposal_sent' ? 'selected' : ''; ?>>Proposal Sent</option>
                    <option value="negotiating" <?php echo $filterStage === 'negotiating' ? 'selected' : ''; ?>>Negotiating</option>
                    <option value="won" <?php echo $filterStage === 'won' ? 'selected' : ''; ?>>Won</option>
                    <option value="lost" <?php echo $filterStage === 'lost' ? 'selected' : ''; ?>>Lost</option>
                </select>
            </div>
            
            <button type="submit" class="btn">Filter</button>
            <?php if ($filterPriority !== 'all' || $filterSource !== 'all' || $filterStage !== 'all' || !empty($searchQuery)): ?>
                <a href="crm-overview.php<?php echo $viewArchived ? '?view=archived' : ''; ?>" class="btn secondary">Clear Filters</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="asacp1021_grid_layout">
        <!-- All Leads -->
        <div class="asacp1021_card asacp1021_leads_card">
            <div class="asacp1021_card_header">
                <h3>All Leads</h3>
                <div class="asacp1021_card_actions">
                    <button onclick="toggleBulkActions()" class="btn secondary small">Bulk Actions</button>
                    <a href="../onboarding/sales-agent/leads.php?action=add" class="btn small">Add Lead</a>
                </div>
            </div>

            <!-- Bulk Actions (Hidden by default) -->
            <div id="bulk-actions" style="display: none; background: #f9fafb; padding: 16px; border-radius: 8px; margin-bottom: 16px;">
                <form method="post" id="bulk-assign-form" onsubmit="return submitBulkAssignAjax(event)" style="display: flex; gap: 12px; align-items: end;">
                    <input type="hidden" name="action" value="bulk_assign">
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Assign to Agent:</label>
                        <select name="bulk_agent_id" style="padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px;">
                            <option value="">Unassigned</option>
                            <?php foreach ($agents as $agent): ?>
                                <option value="<?php echo $agent['id']; ?>"><?php echo htmlspecialchars($agent['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Reason:</label>
                        <input type="text" name="bulk_reason" placeholder="Reason for reassignment" style="padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; width: 200px;">
                    </div>
                    <div style="display:flex; gap:12px; align-items:center;">
                        <label style="font-size:12px; display:flex; align-items:center; gap:6px;">
                            <input type="checkbox" name="bulk_grant_coc_view" value="1"> Grant COC View
                        </label>
                        <label style="font-size:12px; display:flex; align-items:center; gap:6px;">
                            <input type="checkbox" name="bulk_grant_coc_edit" value="1"> Grant COC Edit
                        </label>
                    </div>
                    <button type="submit" class="btn" style="background: #059669;">Assign Selected</button>
                    <button type="button" class="btn secondary" onclick="return bulkIncinerateLeads();">Incinerate Selected</button>
                </form>
            </div>

            <?php if (empty($leads)): ?>
                <div style="text-align: center; padding: 32px; color: #6b7280;">
                    <div style="font-size: 48px; margin-bottom: 16px;">🎯</div>
                    <p>No leads found.</p>
                    <a href="../onboarding/sales-agent/leads.php?action=add" class="btn">Add First Lead</a>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                        <thead>
                            <tr style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
                                <th style="padding: 12px 8px; text-align: left;">
                                    <input type="checkbox" id="select-all" onchange="toggleAllCheckboxes()">
                                </th>
                                <th style="padding: 12px 8px; text-align: left; font-weight: 600;">Lead</th>
                                <th style="padding: 12px 8px; text-align: left; font-weight: 600;">Company</th>
                                <th style="padding: 12px 8px; text-align: left; font-weight: 600;">Account/Contact</th>
                                <th style="padding: 12px 8px; text-align: left; font-weight: 600;">Status</th>
                                <?php if ($viewArchived): ?>
                                    <th style="padding: 12px 8px; text-align: left; font-weight: 600;">Archive Reason</th>
                                    <th style="padding: 12px 8px; text-align: left; font-weight: 600;">Archived By</th>
                                    <th style="padding: 12px 8px; text-align: left; font-weight: 600;">Archived Date</th>
                                <?php else: ?>
                                    <th style="padding: 12px 8px; text-align: left; font-weight: 600;">Assigned Agent</th>
                                    <th style="padding: 12px 8px; text-align: left; font-weight: 600;">Age</th>
                                    <th style="padding: 12px 8px; text-align: left; font-weight: 600;">Activity</th>
                                <?php endif; ?>
                                <th style="padding: 12px 8px; text-align: left; font-weight: 600;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($leads as $lead): ?>
                            <tr style="border-bottom: 1px solid #e5e7eb;">
                                <td style="padding: 12px 8px;">
                                    <input type="checkbox" name="lead_ids[]" value="<?php echo $lead['id']; ?>" class="lead-checkbox">
                                </td>
                                <td style="padding: 12px 8px;">
                                    <div class="asacp1021_lead_info">
                                        <div class="asacp1021_lead_name">
                                            <?php if ($lead['lead_priority'] === 'urgent'): ?>
                                                <span class="asacp1021_priority_indicator asacp1021_urgent">⚠️</span>
                                            <?php elseif ($lead['lead_priority'] === 'high'): ?>
                                                <span class="asacp1021_priority_indicator asacp1021_high">🔴</span>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($lead['full_name']); ?>
                                        </div>
                                        <div class="asacp1021_lead_meta"><?php echo htmlspecialchars($lead['email']); ?></div>
                                        <?php if ($lead['phone']): ?>
                                            <div class="asacp1021_lead_meta"><?php echo htmlspecialchars($lead['phone']); ?></div>
                                        <?php endif; ?>
                                        <?php if ($lead['days_since_contact'] > 30 && $lead['lead_status'] !== 'won' && $lead['lead_status'] !== 'lost'): ?>
                                            <div class="asacp1021_stale_indicator">⏰ <?php echo $lead['days_since_contact']; ?> days inactive</div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td style="padding: 12px 8px;">
                                    <div class="asacp1021_company_name"><?php echo htmlspecialchars($lead['company_name'] ?: 'N/A'); ?></div>
                                    <?php if ($lead['sample_count'] > 0): ?>
                                        <div class="asacp1021_sample_badge">📦 <?php echo $lead['sample_count']; ?> sample<?php echo $lead['sample_count'] > 1 ? 's' : ''; ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($lead['needs_address_sync'])): ?>
                                        <div style="font-size: 11px; color: #f59e0b; background: #fef3c7; padding: 2px 6px; border-radius: 4px; display: inline-block; margin-top: 4px;">
                                            📍 Address needs sync
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 8px; font-size: 13px; color:#374151;">
                                    <?php if (!empty($lead['converted_to_account_id'])): ?>
                                        <a class="link" href="wholesale-accounts.php?view=detail&id=<?php echo (int)$lead['converted_to_account_id']; ?>">Account</a>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                    <span class="dot"> </span>
                                    <?php if (!empty($lead['converted_to_contact_id'])): ?>
                                        <span>Contact #<?php echo (int)$lead['converted_to_contact_id']; ?></span>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 8px;">
                                    <?php if ($lead['stage_label']): ?>
                                        <span class="asacp1021_stage_badge" style="
                                            background: <?php echo htmlspecialchars($lead['stage_color'] ?? '#e5e7eb'); ?>22;
                                            color: <?php echo htmlspecialchars($lead['stage_color'] ?? '#5f6368'); ?>;
                                            border-left: 3px solid <?php echo htmlspecialchars($lead['stage_color'] ?? '#dadce0'); ?>;
                                        ">
                                            <span class="asacp1021_stage_icon"><?php echo $lead['stage_icon']; ?></span>
                                            <span><?php echo htmlspecialchars($lead['stage_label']); ?></span>
                                        </span>
                                    <?php else: ?>
                                        <span class="asacp1021_stage_badge">
                                            <?php echo ucwords(str_replace('_', ' ', $lead['lead_status'])); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($viewArchived): ?>
                                    <td style="padding: 12px 8px;">
                                        <span style="color: #dc2626; font-weight: 600;">
                                            <?php echo ucwords(str_replace('_', ' ', $lead['archive_reason'] ?? 'Unknown')); ?>
                                        </span>
                                        <?php if ($lead['archive_notes']): ?>
                                            <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">
                                                <?php echo htmlspecialchars($lead['archive_notes']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 12px 8px; font-size: 13px;">
                                        <?php echo htmlspecialchars($lead['archived_by_name'] ?? 'System'); ?>
                                    </td>
                                    <td style="padding: 12px 8px; color: #6b7280; font-size: 13px;">
                                        <?php echo $lead['archived_at'] ? date('M j, Y', strtotime($lead['archived_at'])) : 'N/A'; ?>
                                    </td>
                                <?php else: ?>
                                    <td style="padding: 12px 8px;">
                                        <form onsubmit="return quickAssignSubmit(event, <?php echo (int)$lead['id']; ?>)" style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                                            <select id="qa_<?php echo (int)$lead['id']; ?>" style="padding:4px 6px; border:1px solid #d1d5db; border-radius:6px;">
                                                <option value="">Unassigned</option>
                                                <?php foreach ($agents as $agent): ?>
                                                    <option value="<?php echo (int)$agent['id']; ?>" <?php echo ((int)$lead['assigned_agent_id'] === (int)$agent['id'] ? 'selected' : ''); ?>><?php echo htmlspecialchars($agent['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn secondary small" type="submit">Update</button>
                                            <?php if ($lead['agent_name']): ?>
                                                <span style="font-size: 12px; color: #6b7280;">Current: <?php echo htmlspecialchars($lead['agent_name']); ?></span>
                                            <?php endif; ?>
                                        </form>
                                    </td>
                                    <td style="padding: 12px 8px; color: #6b7280;">
                                        <?php echo $lead['days_old']; ?> days
                                    </td>
                                    <td style="padding: 12px 8px; font-size: 12px; color: #6b7280;">
                                        <?php echo $lead['call_count']; ?> calls
                                        <?php if ($lead['last_call_date']): ?>
                                            <br>Last: <?php echo date('M j', strtotime($lead['last_call_date'])); ?>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td style="padding: 12px 8px;">
                                    <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                        <?php if ($viewArchived): ?>
                                            <button onclick="showRestoreModal(<?php echo $lead['id']; ?>, '<?php echo htmlspecialchars($lead['full_name']); ?>')" class="btn small" style="background: #059669;">
                                                Restore
                                            </button>
                                            <button onclick="showDeleteModal(<?php echo $lead['id']; ?>, '<?php echo htmlspecialchars($lead['full_name']); ?>')" class="btn small" style="background: #dc2626;">
                                                Delete
                                            </button>
                                            <button onclick="incinerateLead(<?php echo $lead['id']; ?>, '<?php echo htmlspecialchars($lead['full_name']); ?>', <?php echo $lead['converted_to_account_id'] ? 'true' : 'false'; ?>, <?php echo $lead['converted_to_contact_id'] ? 'true' : 'false'; ?>)" class="btn small" style="background: #111827; color:#fff;">
                                                Incinerate
                                            </button>
                                        <?php else: ?>
                                            <button onclick="showAssignModal(<?php echo $lead['id']; ?>, '<?php echo htmlspecialchars($lead['full_name']); ?>', <?php echo $lead['assigned_agent_id'] ?: 'null'; ?>, <?php echo (int)($lead['converted_to_account_id'] ?: 0); ?>)" class="btn secondary small">
                                                Assign
                                            </button>
                                            <button onclick="showArchiveModal(<?php echo $lead['id']; ?>, '<?php echo htmlspecialchars($lead['full_name']); ?>')" class="btn small" style="background: #f59e0b;">
                                                Archive
                                            </button>
                                            <button onclick="incinerateLead(<?php echo $lead['id']; ?>, '<?php echo htmlspecialchars($lead['full_name']); ?>', <?php echo $lead['converted_to_account_id'] ? 'true' : 'false'; ?>, <?php echo $lead['converted_to_contact_id'] ? 'true' : 'false'; ?>)" class="btn small" style="background: #111827; color:#fff;">
                                                Incinerate
                                            </button>
                                        <?php endif; ?>
                                        <a href="../onboarding/sales-agent/leads.php?id=<?php echo $lead['id']; ?>" class="btn small">View</a>
                                        <?php if (!empty($lead['needs_address_sync'])): ?>
                                            <button onclick="syncSingleLead(<?php echo $lead['id']; ?>, '<?php echo htmlspecialchars($lead['address'], ENT_QUOTES); ?>')" class="btn small" style="background: #f59e0b;" title="Parse address into Street, City, State, ZIP">
                                                📍 Parse Address
                                            </button>
                                        <?php endif; ?>
                                        <?php if (!empty($lead['converted_to_account_id'])): ?>
                                            <button onclick="showCOCModal(<?php echo $lead['id']; ?>, '<?php echo htmlspecialchars($lead['full_name']); ?>', <?php echo (int)$lead['converted_to_account_id']; ?>, <?php echo (int)($lead['assigned_agent_id'] ?: 0); ?>)" class="btn small">COC Access</button>
                                            <a class="btn small secondary" href="wholesale-accounts.php?view=detail&id=<?php echo (int)$lead['converted_to_account_id']; ?>#payment">Payments</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sales Agents & Recent Assignments -->
        <div>
            <!-- Sales Agents -->
            <div class="card" style="margin-bottom: 24px;">
                <h3 style="margin: 0 0 16px 0;">👥 Sales Agents</h3>
                <?php if (empty($agents)): ?>
                    <div style="text-align: center; padding: 20px; color: #6b7280;">
                        <p>No sales agents found.</p>
                    </div>
                <?php else: ?>
                    <div style="max-height: 300px; overflow-y: auto;">
                        <?php foreach ($agents as $agent): ?>
                        <div style="padding: 12px; border-bottom: 1px solid #e5e7eb;">
                            <div style="font-weight: 600; color: #1f2937;"><?php echo htmlspecialchars($agent['name']); ?></div>
                            <div style="font-size: 12px; color: #6b7280; margin-bottom: 4px;">
                                <?php echo htmlspecialchars($agent['email']); ?>
                            </div>
                            <div style="display: flex; gap: 16px; font-size: 12px; color: #6b7280;">
                                <span><?php echo $agent['lead_count']; ?> leads</span>
                                <span><?php echo $agent['won_leads']; ?> won</span>
                                <span>$<?php echo number_format($agent['pipeline_value'] ?: 0, 0); ?> pipeline</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Recent Assignments -->
            <div class="card">
                <h3 style="margin: 0 0 16px 0;">📋 Recent Assignments</h3>
                <?php if (empty($leadAssignments)): ?>
                    <div style="text-align: center; padding: 20px; color: #6b7280; font-size: 14px;">
                        <p>No recent assignments.</p>
                    </div>
                <?php else: ?>
                    <div style="max-height: 250px; overflow-y: auto;">
                        <?php foreach ($leadAssignments as $assignment): ?>
                        <div style="padding: 8px; border-bottom: 1px solid #e5e7eb; font-size: 12px;">
                            <div style="font-weight: 600; color: #1f2937; margin-bottom: 2px;">
                                <?php echo htmlspecialchars($assignment['lead_name']); ?>
                            </div>
                            <div style="color: #6b7280; margin-bottom: 2px;">
                                <?php if ($assignment['from_agent_name'] && $assignment['to_agent_name']): ?>
                                    <?php echo htmlspecialchars($assignment['from_agent_name']); ?> → <?php echo htmlspecialchars($assignment['to_agent_name']); ?>
                                <?php elseif ($assignment['to_agent_name']): ?>
                                    Assigned to <?php echo htmlspecialchars($assignment['to_agent_name']); ?>
                                <?php elseif ($assignment['from_agent_name']): ?>
                                    Unassigned from <?php echo htmlspecialchars($assignment['from_agent_name']); ?>
                                <?php endif; ?>
                            </div>
                            <div style="color: #9ca3af;">
                                <?php echo date('M j, g:i A', strtotime($assignment['assigned_at'])); ?>
                                by <?php echo htmlspecialchars($assignment['admin_name']); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Lead Incinerator Modal -->
<div id="incinerateModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000;">
    <div style="position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); background:white; border-radius:12px; padding:24px; width:480px; max-width:90vw;">
        <h3 style="margin:0 0 16px 0; color:#111827;">Incinerate Lead</h3>
        <form id="incinerateForm" onsubmit="return submitIncinerateLead(event);">
            <input type="hidden" name="lead_id" id="incinerate_lead_id">
            <p style="color:#6b7280; font-size:14px;">This permanently deletes the lead, assignments, notes, tasks, call logs, and optionally the converted account/contact.</p>
            <div style="margin-bottom:12px; font-weight:600;">
                Lead: <span id="incinerate_lead_name"></span>
            </div>
            <label style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                <input type="checkbox" id="incinerate_account" name="incinerate_account">
                Also remove converted wholesale account
            </label>
            <label style="display:flex; align-items:center; gap:8px; margin-bottom:16px;">
                <input type="checkbox" id="incinerate_contact" name="incinerate_contact">
                Also remove converted contact record
            </label>
            <div style="padding:12px; background:#fef2f2; border:1px solid #fee2e2; border-radius:8px; margin-bottom:16px; color:#991b1b; font-size:13px;">
                <strong>Warning:</strong> This cannot be undone. Use only for test/demo data cleanup.
            </div>
            <div style="display:flex; gap:12px; justify-content:flex-end;">
                <button type="button" class="btn secondary" onclick="hideIncinerateModal()">Cancel</button>
                <button type="submit" class="btn" style="background:#111827;">Incinerate Lead</button>
            </div>
        </form>
    </div>
</div>

<!-- Lead Assignment Modal -->
<div id="assignModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; padding: 24px; width: 400px; max-width: 90vw;">
        <h3 style="margin: 0 0 16px 0;">Assign Lead</h3>
        <form method="post">
            <input type="hidden" name="action" value="assign_lead">
            <input type="hidden" name="lead_id" id="modal_lead_id">
            <input type="hidden" name="account_id" id="modal_account_id">
            
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 4px;">Lead:</label>
                <div id="modal_lead_name" style="font-size: 14px; color: #6b7280;"></div>
            </div>
            
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 4px;">Assign to Agent:</label>
                <select name="agent_id" id="modal_agent_id" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                    <option value="">Unassigned</option>
                    <?php foreach ($agents as $agent): ?>
                        <option value="<?php echo $agent['id']; ?>"><?php echo htmlspecialchars($agent['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 4px;">Reason for Assignment:</label>
                <input type="text" name="reason" placeholder="Optional reason..." style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
            </div>
            <div id="grant-coc-container" style="margin-bottom: 12px; display:none; padding:10px; background:#f9fafb; border-radius:8px;">
                <div style="font-weight:600; margin-bottom:6px;">COC Permissions for Assigned Agent</div>
                <label style="display:flex; align-items:center; gap:8px; font-size:13px; margin-bottom:4px;">
                    <input type="checkbox" name="grant_coc_view" value="1"> Grant COC View (see masked payment methods)
                </label>
                <label style="display:flex; align-items:center; gap:8px; font-size:13px;">
                    <input type="checkbox" name="grant_coc_edit" value="1"> Grant COC Edit (manage payment methods)
                </label>
                <div style="font-size:12px; color:#6b7280; margin-top:6px;">Shown only for converted accounts.</div>
            </div>
            
            <div style="display: flex; gap: 12px; justify-content: end;">
                <button type="button" onclick="hideAssignModal()" class="btn secondary">Cancel</button>
                <button type="submit" class="btn" style="background: #059669;">Assign Lead</button>
            </div>
        </form>
    </div>
</div>

<!-- Archive Lead Modal -->
<div id="archiveModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; padding: 24px; width: 500px; max-width: 90vw;">
        <h3 style="margin: 0 0 16px 0;">Archive Lead</h3>
        <form method="post">
            <input type="hidden" name="action" value="archive_lead">
            <input type="hidden" name="lead_id" id="archive_lead_id">
            
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 4px;">Lead:</label>
                <div id="archive_lead_name" style="font-size: 14px; color: #6b7280;"></div>
            </div>
            
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 4px;">Archive Reason *</label>
                <select name="archive_reason" required style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                    <option value="">Select reason...</option>
                    <option value="fake_store">Fake Store</option>
                    <option value="not_in_business">Not In Business</option>
                    <option value="lost_contact_admin_failed">Lost Contact - Admin Failed to Reconnect</option>
                    <option value="do_not_contact">Do Not Contact</option>
                    <option value="duplicate">Duplicate Lead</option>
                    <option value="test_lead">Test Lead</option>
                    <option value="other">Other</option>
                </select>
            </div>
            
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 4px;">Notes (optional)</label>
                <textarea name="archive_notes" rows="3" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;"></textarea>
            </div>
            
            <div style="display: flex; gap: 12px; justify-content: end;">
                <button type="button" onclick="hideArchiveModal()" class="btn secondary">Cancel</button>
                <button type="submit" class="btn" style="background: #f59e0b;">Archive Lead</button>
            </div>
        </form>
    </div>
</div>

<!-- Restore Lead Modal -->
<div id="restoreModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; padding: 24px; width: 400px; max-width: 90vw;">
        <h3 style="margin: 0 0 16px 0;">Restore Lead</h3>
        <form method="post">
            <input type="hidden" name="action" value="restore_lead">
            <input type="hidden" name="lead_id" id="restore_lead_id">
            
            <div style="margin-bottom: 16px;">
                <p>Are you sure you want to restore this lead?</p>
                <div style="font-weight: 600; margin-top: 8px;">
                    Lead: <span id="restore_lead_name"></span>
                </div>
            </div>
            
            <div style="display: flex; gap: 12px; justify-content: end;">
                <button type="button" onclick="hideRestoreModal()" class="btn secondary">Cancel</button>
                <button type="submit" class="btn" style="background: #059669;">Restore Lead</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Lead Modal -->
<div id="deleteModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; padding: 24px; width: 500px; max-width: 90vw;">
        <h3 style="margin: 0 0 16px 0; color: #dc2626;">⚠️ Permanently Delete Lead</h3>
        <form method="post">
            <input type="hidden" name="action" value="delete_lead_permanently">
            <input type="hidden" name="lead_id" id="delete_lead_id">
            
            <div style="background: #fee2e2; padding: 16px; border-radius: 8px; margin-bottom: 16px;">
                <div style="font-weight: 600; color: #dc2626; margin-bottom: 8px;">
                    WARNING: This action cannot be undone!
                </div>
                <div style="color: #7f1d1d; font-size: 14px;">
                    This will permanently delete the lead and all associated data including:
                    <ul style="margin: 8px 0 0 16px;">
                        <li>Call logs</li>
                        <li>Notes</li>
                        <li>Tasks</li>
                        <li>Sample requests</li>
                        <li>Assignment history</li>
                    </ul>
                </div>
            </div>
            
            <div style="margin-bottom: 16px;">
                <div style="font-weight: 600; margin-bottom: 8px;">
                    Lead: <span id="delete_lead_name"></span>
                </div>
                <label style="display: block; font-weight: 600; margin-bottom: 4px;">
                    Type <strong>DELETE_PERMANENTLY</strong> to confirm:
                </label>
                <input type="text" name="confirm_delete" required 
                       style="width: 100%; padding: 8px 12px; border: 2px solid #dc2626; border-radius: 6px;"
                       placeholder="Type DELETE_PERMANENTLY">
            </div>
            
            <div style="display: flex; gap: 12px; justify-content: end;">
                <button type="button" onclick="hideDeleteModal()" class="btn secondary">Cancel</button>
                <button type="submit" class="btn" style="background: #dc2626;">Delete Permanently</button>
            </div>
        </form>
    </div>
</div>

<!-- COC Access Modal -->
<div id="cocAccessModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; padding: 24px; width: 520px; max-width: 95vw;">
        <h3 style="margin: 0 0 16px 0;">COC Access</h3>
        <form method="post">
            <input type="hidden" name="action" value="update_coc_access">
            <input type="hidden" name="lead_id" id="coc_lead_id">
            <input type="hidden" name="account_id" id="coc_account_id">
            <input type="hidden" name="agent_id" id="coc_agent_id">

            <div style="margin-bottom: 12px; font-size: 14px; color:#6b7280;">
                Lead: <span id="coc_lead_name" style="font-weight:600; color:#111827;"></span>
            </div>

            <div style="display:flex; gap:12px; margin-bottom: 12px;">
                <label style="display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" name="can_view" value="1"> Allow Assigned Agent to View COC
                </label>
                <label style="display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" name="can_edit" value="1"> Allow Assigned Agent to Edit COC
                </label>
            </div>

            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Reason (optional)</label>
                <input type="text" name="reason" style="width:100%; padding:8px 12px; border:1px solid #d1d5db; border-radius:6px;" placeholder="Why granting/revoking access?">
            </div>

            <div style="display:flex; justify-content: space-between; align-items:center; margin-top: 8px;">
                <a id="coc_view_methods_link" href="#" target="_blank" class="btn secondary">View Payment Methods</a>
                <div style="display:flex; gap:12px;">
                    <button type="button" onclick="hideCOCModal()" class="btn secondary">Close</button>
                    <button type="submit" class="btn">Save Access</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="lead-incinerator-warning" style="display:none; position:fixed; inset:0; background:rgba(17,24,39,0.88); z-index:2200; align-items:center; justify-content:center;">
    <div style="background:#ffffff; color:#111827; padding:32px; max-width:520px; width:92%; border-radius:16px; box-shadow:0 25px 50px -12px rgba(30,41,59,0.45);">
        <div style="display:flex; align-items:center; gap:12px; margin-bottom:16px;">
            <span style="font-size:34px;">🔥</span>
            <div>
                <h2 style="margin:0; font-size:22px;">Lead Incinerator</h2>
                <p style="margin:4px 0 0; color:#6b7280; font-size:13px;">Permanent removal of lead records, activities, attachments, converted accounts, and contacts (if selected).</p>
            </div>
        </div>
        <ul style="margin:0 0 16px 20px; color:#374151; font-size:13px;">
            <li>Lead notes, tasks, call logs, opportunities, and assignments will be erased.</li>
            <li>If the lead converted to an account/contact and you select those options, they will also be purged with their payment methods and orders.</li>
            <li>No backups are created. This action is irreversible.</li>
        </ul>
        <p style="margin:0 0 12px; font-weight:600;">Type <code style="background:#f3f4f6; padding:2px 6px; border-radius:4px;">INCINERATE LEAD</code> to confirm.</p>
        <input id="lead-incinerate-confirm-input" type="text" placeholder="Type INCINERATE LEAD" style="width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; margin-bottom:16px;">
        <div style="display:flex; justify-content:flex-end; gap:12px;">
            <button type="button" class="btn secondary" onclick="hideLeadIncineratorWarning()">Cancel</button>
            <button type="button" class="btn" style="background:#111827;" onclick="confirmLeadIncineratorProceed()">Proceed</button>
        </div>
    </div>
</div>

<script>
function showAssignModal(leadId, leadName, currentAgentId) {
    document.getElementById('modal_lead_id').value = leadId;
    document.getElementById('modal_lead_name').textContent = leadName;
    document.getElementById('modal_agent_id').value = currentAgentId || '';
    document.getElementById('assignModal').style.display = 'block';
}

function hideAssignModal() {
    document.getElementById('assignModal').style.display = 'none';
}
// Enhanced: pass account id to show COCl grant options when account exists
function showAssignModal(leadId, leadName, currentAgentId, accountId) {
    document.getElementById('modal_lead_id').value = leadId;
    document.getElementById('modal_lead_name').textContent = leadName;
    document.getElementById('modal_agent_id').value = currentAgentId || '';
    document.getElementById('modal_account_id').value = accountId || '';
    const grantBox = document.getElementById('grant-coc-container');
    grantBox.style.display = accountId ? 'block' : 'none';
    document.getElementById('assignModal').style.display = 'block';
}

function showCOCModal(leadId, leadName, accountId, agentId) {
    document.getElementById('coc_lead_id').value = leadId;
    document.getElementById('coc_lead_name').textContent = leadName;
    document.getElementById('coc_account_id').value = accountId;
    document.getElementById('coc_agent_id').value = agentId || '';
    const link = document.getElementById('coc_view_methods_link');
    link.href = 'wholesale-accounts.php?id=' + accountId + '#payment';
    document.getElementById('cocAccessModal').style.display = 'block';
}

function hideCOCModal() { document.getElementById('cocAccessModal').style.display = 'none'; }


function toggleBulkActions() {
    const bulkActions = document.getElementById('bulk-actions');
    bulkActions.style.display = bulkActions.style.display === 'none' ? 'block' : 'none';
}

function toggleAllCheckboxes() {
    const selectAll = document.getElementById('select-all');
    const checkboxes = document.querySelectorAll('.lead-checkbox');
    checkboxes.forEach(cb => cb.checked = selectAll.checked);
}

// Archive modal functions
function showArchiveModal(leadId, leadName) {
    document.getElementById('archive_lead_id').value = leadId;
    document.getElementById('archive_lead_name').textContent = leadName;
    document.getElementById('archiveModal').style.display = 'block';
}

function hideArchiveModal() {
    document.getElementById('archiveModal').style.display = 'none';
}

// Restore modal functions
function showRestoreModal(leadId, leadName) {
    document.getElementById('restore_lead_id').value = leadId;
    document.getElementById('restore_lead_name').textContent = leadName;
    document.getElementById('restoreModal').style.display = 'block';
}

function hideRestoreModal() {
    document.getElementById('restoreModal').style.display = 'none';
}

// Delete modal functions
function showDeleteModal(leadId, leadName) {
    document.getElementById('delete_lead_id').value = leadId;
    document.getElementById('delete_lead_name').textContent = leadName;
    document.getElementById('deleteModal').style.display = 'block';
}

function hideDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

function showIncinerateModal(leadId, leadName, hasAccount, hasContact) {
    document.getElementById('incinerate_lead_id').value = leadId;
    document.getElementById('incinerate_lead_name').textContent = leadName;
    document.getElementById('incinerate_account').checked = !!hasAccount;
    document.getElementById('incinerate_contact').checked = !!hasContact;
    document.getElementById('incinerateModal').style.display = 'block';
}

function hideIncinerateModal() {
    document.getElementById('incinerateModal').style.display = 'none';
}

let leadIncineratorPendingAction = null;
let leadIncineratorPayload = null;

function showLeadIncineratorWarning(payload){
    leadIncineratorPendingAction = submitLeadIncineration;
    leadIncineratorPayload = payload;
    const overlay = document.getElementById('lead-incinerator-warning');
    if (overlay){
        overlay.style.display = 'flex';
        const input = document.getElementById('lead-incinerate-confirm-input');
        if (input) {
            input.value = '';
            setTimeout(() => input.focus(), 30);
        }
    }
}

function hideLeadIncineratorWarning(clearPending = false){
    if (clearPending) {
        leadIncineratorPendingAction = null;
        leadIncineratorPayload = null;
    }
    const overlay = document.getElementById('lead-incinerator-warning');
    if (overlay) overlay.style.display = 'none';
}

function confirmLeadIncineratorProceed(){
    const input = document.getElementById('lead-incinerate-confirm-input');
    if (!input || input.value.trim().toUpperCase() !== 'INCINERATE LEAD'){
        alert('Please type INCINERATE LEAD to confirm.');
        return;
    }
    const payload = leadIncineratorPayload;
    const pending = leadIncineratorPendingAction;
    leadIncineratorPendingAction = null;
    leadIncineratorPayload = null;
    hideLeadIncineratorWarning();
    if (typeof pending === 'function'){
        pending(payload);
    }
}

const leadIncineratorInput = document.getElementById('lead-incinerate-confirm-input');
if (leadIncineratorInput){
    leadIncineratorInput.addEventListener('keydown', function(e){
        if (e.key === 'Enter') {
            e.preventDefault();
            confirmLeadIncineratorProceed();
        }
    });
}

async function submitIncinerateLead(e) {
    e.preventDefault();
    const leadId = parseInt(document.getElementById('incinerate_lead_id').value, 10);
    if (!leadId) {
        alert('Missing lead id');
        return false;
    }
    const incAccount = document.getElementById('incinerate_account').checked;
    const incContact = document.getElementById('incinerate_contact').checked;
    showLeadIncineratorWarning({ leadIds: [leadId], incAccount, incContact });
    hideIncinerateModal();
    return false;
}

async function submitLeadIncineration(payload){
    if (!payload || !payload.leadIds || !payload.leadIds.length) {
        alert('No leads selected for incineration.');
        return;
    }
    try {
        const res = await fetch('<?=outbound_path('/api/admin-incinerator.php?action=incinerate_lead')?>', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({lead_ids: payload.leadIds, incinerate_account: payload.incAccount, incinerate_contact: payload.incContact})
        });
        const json = await res.json();
        if (!json.ok){
            throw new Error(json.error || 'Incineration failed');
        }
        alert(`Incinerated ${json.count || payload.leadIds.length} lead(s).`);
        window.location.reload();
    } catch (err) {
        alert(err.message || 'Failed to incinerate lead.');
    }
}

function incinerateLead(leadId, leadName, hasAccount, hasContact) {
    showIncinerateModal(leadId, leadName, hasAccount, hasContact);
}

function bulkIncinerateLeads(){
    const checkboxes = Array.from(document.querySelectorAll('.lead-checkbox:checked'));
    if (!checkboxes.length){
        alert('Select at least one lead to incinerate.');
        return false;
    }
    const leadIds = checkboxes.map(cb => parseInt(cb.value, 10)).filter(Boolean);
    const hasAccount = confirm('Also remove converted accounts for selected leads?');
    const hasContact = confirm('Also remove converted contacts for selected leads?');
    showLeadIncineratorWarning({ leadIds, incAccount: hasAccount, incContact: hasContact });
    return false;
}

// Sync single lead address
async function syncSingleLead(leadId, fullAddress){
    try {
        if (!confirm('Parse address into structured fields?\n\nFull Address:\n' + fullAddress + '\n\nThis will extract: Street, City, State, ZIP')) {
            return;
        }
        
        var btn = event.target.closest('button');
        var originalText = btn ? btn.innerHTML : null;
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span style="display:inline-block;animation:spin 1s linear infinite;">⚙️</span>';
        }
        
        var fd = new FormData();
        fd.append('action', 'sync_single_address');
        fd.append('lead_id', leadId);
        
        const res = await fetch('crm-overview.php', { method: 'POST', body: fd });
        const data = await res.json();
        
        if (data && data.ok) {
            alert('✓ Address Parsed Successfully!\n\n' + 
                  '📍 Street: ' + (data.parsed.address_line1 || 'N/A') + '\n' +
                  '🏙️ City: ' + (data.parsed.city || 'N/A') + '\n' +
                  '📌 State: ' + (data.parsed.state || 'N/A') + '\n' +
                  '📬 ZIP: ' + (data.parsed.zip_code || 'N/A') + '\n' +
                  '🌍 Country: ' + (data.parsed.country || 'N/A'));
            location.reload();
        } else {
            alert('Failed to parse address: ' + (data.error || 'Unknown error'));
            if (btn && originalText) {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        }
    } catch(err) {
        console.error(err);
        alert('Error: ' + err.message);
        if (btn && originalText) {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }
}

// Sync lead addresses
async function syncLeadAddresses(forceResync){
    try {
        var btn = event.target.closest('button');
        if (!btn) return;
        
        var confirmMsg = forceResync 
            ? 'Re-parse ALL lead addresses using Google Geocoding API?\n\nThis will OVERWRITE existing address fields for all leads with full addresses.\n\n⚠️ Use this if addresses were incorrectly parsed.'
            : 'Parse incomplete address fields from full addresses using Google Geocoding API?\n\nThis will ONLY fill empty fields for leads missing structured address data (city, state, zip).';
        
        if (!confirm(confirmMsg)) {
            return;
        }
        
        var originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span style="display:inline-block;animation:spin 1s linear infinite;">⚙️</span> ' + (forceResync ? 'Re-parsing...' : 'Parsing...');
        
        var fd = new FormData();
        fd.append('action', 'sync_addresses');
        if (forceResync) {
            fd.append('force_resync', '1');
        }
        
        const res = await fetch('crm-overview.php', { method: 'POST', body: fd });
        const data = await res.json();
        
        if (data && data.ok) {
            var modeLabel = data.mode === 're-sync' ? 'Re-parsed' : 'Parsed';
            alert(`Address ${modeLabel} Complete!\n\n✓ Successfully processed: ${data.synced} leads\n✗ Failed: ${data.failed} leads\n📊 Total found: ${data.total} leads${data.errors && data.errors.length ? '\n\nFirst errors:\n' + data.errors.join('\n') : ''}`);
            if (data.synced > 0) {
                location.reload();
            } else {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        } else {
            alert('Sync failed: ' + (data.error || 'Unknown error'));
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    } catch(err) {
        console.error(err);
        alert('Error syncing addresses: ' + err.message);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    }
}

// Inline quick-assign submit (AJAX)
async function quickAssignSubmit(e, leadId){
    try {
        e.preventDefault();
        var sel = document.getElementById('qa_' + leadId);
        if (!sel) return false;
        var agentId = sel.value;
        var fd = new FormData();
        fd.append('action', 'assign_lead');
        fd.append('lead_id', String(leadId));
        fd.append('agent_id', agentId);
        fd.append('reason', 'Quick inline assignment');
        fd.append('ajax', '1');
        const res = await fetch('crm-overview.php', { method: 'POST', body: fd });
        let data = null;
        try { data = await res.json(); } catch(e) {}
        if (data && data.ok) {
            // Optional toast
            sel.style.borderColor = '#059669';
            setTimeout(()=>{ sel.style.borderColor = '#d1d5db'; }, 1200);
        } else {
            alert((data && data.message) || 'Failed to assign lead.');
        }
    } catch (err) { console.error(err); alert('Failed to assign lead.'); }
    return false;
}

// Close modals when clicking outside
['assignModal', 'archiveModal', 'restoreModal', 'deleteModal', 'cocAccessModal', 'incinerateModal'].forEach(modalId => {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.style.display = 'none';
            }
        });
    }
});

// Prepare bulk assign: inject selected lead_ids[] into the form before submit
async function submitBulkAssignAjax(e) {
    try {
        e.preventDefault();
        var form = document.getElementById('bulk-assign-form');
        if (!form) return false;
        // Collect checked lead ids
        var checkboxes = document.querySelectorAll('.lead-checkbox:checked');
        if (!checkboxes.length) {
            alert('Select at least one lead.');
            return false;
        }
        var fd = new FormData(form);
        // Ensure fresh list
        fd.delete('lead_ids[]');
        checkboxes.forEach(function(cb){ fd.append('lead_ids[]', cb.value); });
        fd.append('ajax', '1');
        // Disable submit button to avoid double submits
        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Assigning…'; }
        const res = await fetch('crm-overview.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data || !data.ok) {
            throw new Error((data && data.message) || 'Bulk assignment failed');
        }
        // Visual feedback: outline selected rows and update current agent label in-line if present
        var agentId = data.agent_id;
        checkboxes.forEach(function(cb){
            var row = cb.closest('tr');
            if (row) {
                row.style.outline = '2px solid #059669';
                setTimeout(function(){ row.style.outline = ''; }, 1500);
                if (agentId) {
                    // Update quick-assign select if exists
                    var leadId = cb.value;
                    var sel = document.getElementById('qa_' + leadId);
                    if (sel) sel.value = String(agentId);
                }
            }
            // Uncheck after success
            cb.checked = false;
        });
        // Toast
        alert('Successfully reassigned ' + (data.assigned_count || checkboxes.length) + ' leads.');
    } catch (err) {
        console.error(err);
        alert(err.message || 'Bulk assignment failed');
    } finally {
        var form = document.getElementById('bulk-assign-form');
        if (form) {
            var submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Assign Selected'; }
        }
    }
    return false;
}
</script>

<style>
/* Google-style CRM Overview - asacp1021_ prefix */
body {
    background: #f8f9fa;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
}

.asacp1021_crm_container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 24px 48px 24px;
}

.asacp1021_page_header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}

.asacp1021_page_header h1 {
    margin: 0;
    color: #202124;
    font-weight: 400;
    font-size: 28px;
}

.asacp1021_page_header p {
    margin: 4px 0 0 0;
    color: #5f6368;
    font-size: 14px;
}

.asacp1021_header_actions {
    display: flex;
    gap: 12px;
}

.asacp1021_stats_grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.asacp1021_stat_card {
    background: white;
    padding: 20px;
    border-radius: 8px;
    border: 1px solid #dadce0;
    text-align: center;
    transition: all 0.2s;
}

.asacp1021_stat_card:hover {
    transform: translateY(-1px);
    box-shadow: 0 1px 3px 0 rgba(60,64,67,.3), 0 4px 8px 3px rgba(60,64,67,.15);
    border-color: transparent;
}

.asacp1021_stat_icon {
    font-size: 24px;
    margin-bottom: 8px;
}

.asacp1021_stat_value {
    font-size: 32px;
    font-weight: 400;
    color: #202124;
    line-height: 1;
    margin-bottom: 4px;
}

.asacp1021_stat_label {
    font-size: 12px;
    color: #5f6368;
    font-weight: 500;
    text-transform: none;
}

.asacp1021_stat_meta {
    font-size: 11px;
    color: #5f6368;
    margin-top: 4px;
}

.asacp1021_alert_card {
    background: #fef2f2;
    border-color: #fee2e2;
}

.asacp1021_success_card {
    background: #f0fdf4;
    border-color: #bbf7d0;
}

.asacp1021_active_card {
    background: #eff6ff;
    border-color: #bfdbfe;
}

.asacp1021_won_card {
    background: #ecfdf5;
    border-color: #a7f3d0;
}

.asacp1021_pipeline_card {
    background: #f5f3ff;
    border-color: #ddd6fe;
}

.asacp1021_warning_card {
    background: #fefce8;
    border-color: #fde047;
}

.asacp1021_filter_bar {
    background: white;
    padding: 16px 20px;
    border-radius: 8px;
    border: 1px solid #dadce0;
    margin-bottom: 16px;
}

.asacp1021_filter_bar form {
    display: flex;
    gap: 12px;
    align-items: end;
    flex-wrap: wrap;
}

.asacp1021_filter_group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.asacp1021_filter_group label {
    font-weight: 500;
    font-size: 12px;
    color: #5f6368;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.asacp1021_filter_group input[type="text"],
.asacp1021_filter_group select {
    padding: 8px 12px;
    border: 1px solid #dadce0;
    border-radius: 4px;
    font-size: 14px;
    color: #202124;
    background: white;
    transition: all 0.2s;
}

.asacp1021_filter_group input:focus,
.asacp1021_filter_group select:focus {
    outline: none;
    border-color: #1a73e8;
    box-shadow: 0 0 0 2px rgba(26, 115, 232, 0.1);
}

.asacp1021_grid_layout {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}

.asacp1021_card {
    background: white;
    border-radius: 8px;
    padding: 20px;
    border: 1px solid #dadce0;
}

.asacp1021_leads_card {
    grid-column: 1;
}

.asacp1021_card_header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.asacp1021_card_header h3 {
    margin: 0;
    color: #202124;
    font-weight: 500;
    font-size: 16px;
}

.asacp1021_card_actions {
    display: flex;
    gap: 8px;
}

.asacp1021_lead_info {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.asacp1021_lead_name {
    font-weight: 600;
    color: #202124;
    display: flex;
    align-items: center;
    gap: 6px;
}

.asacp1021_lead_meta {
    font-size: 12px;
    color: #5f6368;
}

.asacp1021_priority_indicator {
    display: inline-block;
    font-size: 14px;
}

.asacp1021_stale_indicator {
    font-size: 11px;
    color: #f29900;
    background: #fef7e0;
    padding: 2px 6px;
    border-radius: 4px;
    display: inline-block;
    margin-top: 4px;
}

.asacp1021_company_name {
    font-weight: 500;
    color: #202124;
}

.asacp1021_sample_badge {
    font-size: 11px;
    color: #1967d2;
    background: #e8f0fe;
    padding: 2px 6px;
    border-radius: 4px;
    display: inline-block;
    margin-top: 4px;
}

.asacp1021_stage_badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
}

.asacp1021_stage_icon {
    font-size: 14px;
}

/* Legacy styles kept for compatibility */
.card {
    background: white;
    border-radius: 8px;
    padding: 20px;
    border: 1px solid #dadce0;
}

.btn.secondary.small,
.btn.small {
    padding: 6px 12px;
    font-size: 12px;
}

.btn.secondary.small {
    background: white;
    color: #5f6368;
    border: 1px solid #dadce0;
}

.btn.secondary.small:hover {
    background: #f8f9fa;
    border-color: #5f6368;
}

/* Mobile Responsive */
@media (max-width: 1024px) {
    .asacp1021_grid_layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .asacp1021_crm_container {
        padding: 0 16px 32px 16px;
    }

    .asacp1021_page_header {
        flex-direction: column;
        align-items: flex-start;
        gap: 16px;
    }

    .asacp1021_header_actions {
        width: 100%;
        flex-wrap: wrap;
    }

    .asacp1021_stats_grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .asacp1021_filter_bar form {
        flex-direction: column;
    }

    .asacp1021_filter_group {
        width: 100%;
    }
}

@media (max-width: 480px) {
    .asacp1021_stats_grid {
        grid-template-columns: 1fr;
    }

    .asacp1021_stat_value {
        font-size: 24px;
    }

    .asacp1021_card_header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }

    .asacp1021_card_actions {
        width: 100%;
    }
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
