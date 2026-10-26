<?php
/**
 * Demo Drop-off Helper Functions
 * Shared functions for demo workflow management
 */

/**
 * Check if a lead can receive a demo drop-off
 */
function can_receive_demo($pdo, $lead_id, &$error_message = '') {
    try {
        // Check if lead exists
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM crm_leads WHERE id = ?');
        $stmt->execute([$lead_id]);
        if (!$stmt->fetchColumn()) {
            $error_message = 'Lead not found';
            return false;
        }
        
        // Check demo count limit (max 2)
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM crm_demo_dropoffs WHERE lead_id = ?');
        $stmt->execute([$lead_id]);
        $count = intval($stmt->fetchColumn());
        
        if ($count >= 2) {
            $error_message = 'Demo limit reached (maximum 2 per store)';
            return false;
        }
        
        return true;
    } catch (Exception $e) {
        $error_message = 'Database error';
        return false;
    }
}

/**
 * Check if a specific demo type can be dropped for a lead
 */
function can_drop_demo_type($pdo, $lead_id, $demo_type_id, &$error_message = '') {
    if (!can_receive_demo($pdo, $lead_id, $error_message)) {
        return false;
    }
    
    try {
        // Check if this specific type already dropped
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM crm_demo_dropoffs WHERE lead_id = ? AND demo_type_id = ?');
        $stmt->execute([$lead_id, $demo_type_id]);
        
        if ($stmt->fetchColumn() > 0) {
            $error_message = 'This demo type already dropped for this store';
            return false;
        }
        
        return true;
    } catch (Exception $e) {
        $error_message = 'Database error';
        return false;
    }
}

/**
 * Get available demo types
 */
function get_demo_types($pdo, $active_only = true) {
    $sql = 'SELECT * FROM demo_dropoff_types';
    if ($active_only) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY product_code';
    
    try {
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Create drop-off sample request (new unified system)
 * Replaces sp_create_demo_dropoff stored procedure
 * 
 * @param PDO $pdo Database connection
 * @param int $leadId Lead ID
 * @param int $agentId Agent ID
 * @param int $demoTypeId Demo type ID
 * @param string $notes Optional notes
 * @return array ['success' => bool, 'message' => string, 'request_id' => int|null]
 */
function create_dropoff_sample_request($pdo, $leadId, $agentId, $demoTypeId, $notes = null) {
    try {
        // Get demo type details
        $stmt = $pdo->prepare('SELECT * FROM demo_dropoff_types WHERE id = ? LIMIT 1');
        $stmt->execute([$demoTypeId]);
        $demoType = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$demoType) {
            return ['success' => false, 'message' => 'Invalid demo type', 'request_id' => null];
        }
        
        // Get lead details
        $stmt = $pdo->prepare('SELECT * FROM crm_leads WHERE id = ? LIMIT 1');
        $stmt->execute([$leadId]);
        $lead = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$lead) {
            return ['success' => false, 'message' => 'Lead not found', 'request_id' => null];
        }
        
        // Find matching sample product for this demo type
        $stmt = $pdo->prepare('
            SELECT id FROM products 
            WHERE is_sample = 1 
            AND (demo_type_id = ? OR sku = ?)
            LIMIT 1
        ');
        $stmt->execute([$demoTypeId, $demoType['product_code']]);
        $sampleProductId = $stmt->fetchColumn();
        
        if (!$sampleProductId) {
            return ['success' => false, 'message' => 'No sample product found for this demo type', 'request_id' => null];
        }
        
        // Check demo limit (max 2 per lead via new system)
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM sample_requests 
            WHERE lead_id = ? AND request_type = "drop_off"
        ');
        $stmt->execute([$leadId]);
        $dropoffCount = (int)$stmt->fetchColumn();
        
        if ($dropoffCount >= 2) {
            return ['success' => false, 'message' => 'Demo limit reached (max 2 per store)', 'request_id' => null];
        }
        
        // Check if this specific demo type already dropped for this lead
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM sample_requests sr
            JOIN products p ON p.id = sr.sample_product_id
            WHERE sr.lead_id = ? 
            AND sr.request_type = "drop_off"
            AND p.demo_type_id = ?
        ');
        $stmt->execute([$leadId, $demoTypeId]);
        if ($stmt->fetchColumn() > 0) {
            return ['success' => false, 'message' => 'This demo type already dropped for this lead', 'request_id' => null];
        }
        
        // Create the sample request (auto-approved for drop-offs)
        $recipientName = trim($lead['company_name'] ?: ($lead['first_name'] . ' ' . $lead['last_name']));
        $followUpDate = date('Y-m-d', strtotime('+3 days'));
        
        $pdo->beginTransaction();
        
        // Insert sample request
        $stmt = $pdo->prepare('
            INSERT INTO sample_requests (
                agent_id, lead_id, sample_product_id, request_type,
                quantity, recipient_name, agent_notes,
                status, requested_at, approved_at, approved_by
            ) VALUES (?, ?, ?, "drop_off", 1, ?, ?, "approved", NOW(), NOW(), ?)
        ');
        $stmt->execute([$agentId, $leadId, $sampleProductId, $recipientName, $notes, $agentId]);
        
        $requestId = (int)$pdo->lastInsertId();
        
        // Reserve inventory
        require_once __DIR__ . '/sample-inventory-helpers.php';
        reserve_sample_inventory($pdo, $sampleProductId, 1, $requestId, $agentId);
        
        // Create follow-up task
        $stmt = $pdo->prepare('
            INSERT INTO crm_tasks (
                agent_id, lead_id, sample_request_id,
                task_type, title, description,
                priority, due_date, status
            ) VALUES (?, ?, ?, "follow_up", ?, ?, "high", ?, "pending")
        ');
        $stmt->execute([
            $agentId,
            $leadId,
            $requestId,
            'Follow up on sample drop-off',
            'Check in with customer about the ' . $demoType['product_name'] . ' sample dropped off on ' . date('M j, Y'),
            $followUpDate
        ]);
        
        $pdo->commit();
        
        return [
            'success' => true, 
            'message' => 'Drop-off recorded successfully',
            'request_id' => $requestId
        ];
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'success' => false,
            'message' => 'Error: ' . $e->getMessage(),
            'request_id' => null
        ];
    }
}

/**
 * Get demo drop-offs for a lead
 */
function get_lead_demos($pdo, $lead_id) {
    try {
        $stmt = $pdo->prepare('
            SELECT d.*, dt.product_name, dt.blend_type, dt.size_oz, dt.total_mg
            FROM crm_demo_dropoffs d
            JOIN demo_dropoff_types dt ON dt.id = d.demo_type_id
            WHERE d.lead_id = ?
            ORDER BY d.dropoff_date DESC
        ');
        $stmt->execute([$lead_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Search for stores/leads by name, email, or phone
 */
function search_stores($pdo, $query, $limit = 20) {
    if (strlen($query) < 2) {
        return [];
    }
    
    $results = [];
    $search = '%' . $query . '%';
    
    try {
        // Search in CRM leads
        $stmt = $pdo->prepare('
            SELECT 
                l.id,
                l.first_name,
                l.last_name,
                l.email,
                l.phone,
                l.company_name as store_name,
                l.address,
                l.city,
                l.state,
                l.postal_code,
                "crm_lead" as source_type,
                CASE 
                    WHEN wa.id IS NOT NULL THEN wa.id
                    ELSE NULL
                END as wholesale_account_id
            FROM crm_leads l
            LEFT JOIN wholesale_accounts wa ON wa.user_id = (
                SELECT id FROM users WHERE email = l.email LIMIT 1
            )
            WHERE l.company_name LIKE ? 
               OR l.email LIKE ?
               OR l.phone LIKE ?
               OR CONCAT(l.first_name, " ", l.last_name) LIKE ?
            LIMIT ?
        ');
        $stmt->execute([$search, $search, $search, $search, $limit]);
        $crmResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Search in wholesale accounts
        $stmt = $pdo->prepare('
            SELECT 
                wa.id as wholesale_account_id,
                wa.business_name as store_name,
                wa.address_line1 as address,
                wa.city,
                wa.state_region as state,
                wa.postal_code,
                u.email,
                u.phone,
                u.first_name,
                u.last_name,
                "wholesale_account" as source_type,
                (SELECT id FROM crm_leads WHERE email = u.email LIMIT 1) as id
            FROM wholesale_accounts wa
            JOIN users u ON u.id = wa.user_id
            WHERE wa.business_name LIKE ?
               OR u.email LIKE ?
               OR u.phone LIKE ?
            LIMIT ?
        ');
        $stmt->execute([$search, $search, $search, $limit]);
        $wholesaleResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Merge and deduplicate results
        $seen = [];
        foreach (array_merge($crmResults, $wholesaleResults) as $row) {
            $key = strtolower($row['email'] ?? '');
            if ($key && !isset($seen[$key])) {
                $seen[$key] = true;
                $results[] = $row;
            }
        }
        
        return array_slice($results, 0, $limit);
        
    } catch (Exception $e) {
        error_log('Store search error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Create CRM lead from demo submission
 */
function create_lead_from_demo($pdo, $data) {
    try {
        $pdo->beginTransaction();
        
        // Check if lead already exists
        $stmt = $pdo->prepare('SELECT id FROM crm_leads WHERE email = ? LIMIT 1');
        $stmt->execute([$data['email']]);
        $lead_id = $stmt->fetchColumn();
        
        if (!$lead_id) {
            // Create new lead
            $stmt = $pdo->prepare('
                INSERT INTO crm_leads (
                    first_name, last_name, email, phone, company_name,
                    lead_source, lead_status, lead_priority, interest_level,
                    assigned_agent_id, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            
            $stmt->execute([
                $data['first_name'],
                $data['last_name'],
                $data['email'],
                $data['phone'] ?? null,
                $data['store_name'] ?? null,
                'demo_request',
                'info_obtained_demo_pending',
                'high',
                8, // High interest
                $data['agent_id'] ?? null
            ]);
            
            $lead_id = $pdo->lastInsertId();
        }
        
        $pdo->commit();
        return $lead_id;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Create lead error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get overdue demo follow-ups for an agent
 */
function get_overdue_demo_followups($pdo, $agent_id = null) {
    try {
        $sql = '
            SELECT * FROM crm_demo_followup_view
            WHERE followup_urgency IN ("overdue", "due_today")
        ';
        
        $params = [];
        if ($agent_id) {
            $sql .= ' AND assigned_agent_id = ?';
            $params[] = $agent_id;
        }
        
        $sql .= ' ORDER BY follow_up_date ASC, lead_id';
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get demo statistics for admin dashboard
 */
function get_demo_statistics($pdo) {
    try {
        $stats = [];
        
        // Total demos dropped
        $stats['total_demos'] = $pdo->query('SELECT COUNT(*) FROM crm_demo_dropoffs')->fetchColumn();
        
        // Demos by status
        $stmt = $pdo->query('
            SELECT demo_status, COUNT(*) as count
            FROM crm_demo_dropoffs
            GROUP BY demo_status
        ');
        $stats['by_status'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Follow-up stats
        $stmt = $pdo->query('
            SELECT follow_up_status, COUNT(*) as count
            FROM crm_demo_dropoffs
            GROUP BY follow_up_status
        ');
        $stats['followup_status'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Overdue follow-ups
        $stats['overdue_followups'] = $pdo->query('
            SELECT COUNT(*) FROM crm_demo_dropoffs
            WHERE follow_up_status = "pending" 
            AND follow_up_date < CURDATE()
        ')->fetchColumn();
        
        // Unassigned demos
        $stats['unassigned_demos'] = $pdo->query('
            SELECT COUNT(*) FROM admin_unassigned_demos_view
        ')->fetchColumn();
        
        // Conversion rate (demos with reviews)
        $stats['demos_with_reviews'] = $pdo->query('
            SELECT COUNT(DISTINCT d.lead_id)
            FROM crm_demo_dropoffs d
            WHERE EXISTS (
                SELECT 1 FROM promo_demo_reviews r
                JOIN promo_demo_leads pdl ON pdl.id = r.demo_lead_id
                JOIN crm_leads cl ON cl.email = pdl.contact_email
                WHERE cl.id = d.lead_id
            )
        ')->fetchColumn();
        
        return $stats;
        
    } catch (Exception $e) {
        error_log('Demo statistics error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Validate demo drop-off limits
 */
function validate_demo_limits($pdo, $lead_id, $demo_types) {
    if (!is_array($demo_types)) {
        $demo_types = [$demo_types];
    }
    
    $errors = [];
    
    // Get existing demos
    $existing = get_lead_demos($pdo, $lead_id);
    $existing_types = array_column($existing, 'demo_type_id');
    
    // Check total limit
    if (count($existing) + count($demo_types) > 2) {
        $errors[] = 'Maximum 2 demos per store';
    }
    
    // Check for duplicates
    foreach ($demo_types as $type_id) {
        if (in_array($type_id, $existing_types)) {
            $errors[] = 'Demo type already dropped for this store';
        }
    }
    
    return $errors;
}

/**
 * Unified fetch for demo drop-offs.
 * - If $agent_id is null, returns all drop-offs (admin/global views)
 * - If promo tables are missing, gracefully falls back to CRM-only
 */
function get_demo_dropoffs_unified($pdo, $agent_id = null, $include_promo = true) {
    $results = [];

    // New Sample System segment (priority - most recent data)
    try {
        $sampleSql = '
            SELECT 
                sr.id,
                sr.lead_id,
                sr.agent_id,
                p.demo_type_id,
                DATE(sr.requested_at) as dropoff_date,
                DATE_ADD(DATE(sr.requested_at), INTERVAL 3 DAY) as follow_up_date,
                CASE
                    WHEN sr.status = "delivered" THEN "completed"
                    WHEN sr.status = "cancelled" THEN "cancelled"
                    ELSE "pending"
                END as follow_up_status,
                (SELECT id FROM crm_tasks WHERE sample_request_id = sr.id LIMIT 1) as follow_up_task_id,
                CASE
                    WHEN sr.status = "delivered" THEN "used"
                    WHEN sr.status = "cancelled" THEN "unused"
                    ELSE "dropped"
                END as demo_status,
                sr.agent_notes as notes,
                sr.created_at,
                sr.updated_at,
                l.first_name, l.last_name, l.email, l.phone, l.company_name,
                l.lead_status,
                p.name as product_name,
                dt.blend_type,
                p.size_oz,
                p.total_mg,
                DATEDIFF(DATE_ADD(DATE(sr.requested_at), INTERVAL 3 DAY), CURDATE()) as days_until_followup,
                u.first_name as agent_first_name, u.last_name as agent_last_name,
                (SELECT COUNT(*) FROM users WHERE email = l.email) as has_user,
                (
                    SELECT COUNT(*) 
                    FROM wholesale_accounts wa 
                    JOIN users u2 ON u2.id = wa.user_id 
                    WHERE u2.email = l.email AND wa.approved = 1
                ) as has_wholesale_account,
                NULL as demo_lead_id,
                NULL as rating, NULL as review_text, NULL as preferred_strength, NULL as review_date,
                "sample_system" AS source
            FROM sample_requests sr
            JOIN products p ON p.id = sr.sample_product_id
            JOIN crm_leads l ON l.id = sr.lead_id
            LEFT JOIN demo_dropoff_types dt ON dt.id = p.demo_type_id
            LEFT JOIN users u ON u.id = sr.agent_id
            WHERE sr.request_type = "drop_off"
        ';
        $sampleParams = [];
        if (!empty($agent_id)) {
            $sampleSql .= ' AND sr.agent_id = ?';
            $sampleParams[] = $agent_id;
        }
        $sampleSql .= ' ORDER BY sr.requested_at DESC';
        $stmt = $pdo->prepare($sampleSql);
        $stmt->execute($sampleParams);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // If sample system query fails, fall back to legacy
        $results = [];
    }

    // Legacy CRM segment (for historical data not yet in sample_requests)
    try {
        $crmSql = '
            SELECT 
                d.id,
                d.lead_id,
                d.agent_id,
                d.demo_type_id,
                d.dropoff_date,
                d.follow_up_date,
                d.follow_up_status,
                d.follow_up_task_id,
                d.demo_status,
                d.notes,
                d.created_at,
                d.updated_at,
                l.first_name, l.last_name, l.email, l.phone, l.company_name,
                l.lead_status,
                dt.product_name, dt.blend_type, dt.size_oz, dt.total_mg,
                DATEDIFF(d.follow_up_date, CURDATE()) as days_until_followup,
                u.first_name as agent_first_name, u.last_name as agent_last_name,
                (SELECT COUNT(*) FROM users WHERE email = l.email) as has_user,
                (
                    SELECT COUNT(*) 
                    FROM wholesale_accounts wa 
                    JOIN users u2 ON u2.id = wa.user_id 
                    WHERE u2.email = l.email AND wa.approved = 1
                ) as has_wholesale_account,
                NULL as demo_lead_id,
                NULL as rating, NULL as review_text, NULL as preferred_strength, NULL as review_date,
                "crm_legacy" AS source
            FROM crm_demo_dropoffs d
            JOIN crm_leads l ON l.id = d.lead_id
            JOIN demo_dropoff_types dt ON dt.id = d.demo_type_id
            LEFT JOIN users u ON u.id = d.agent_id
            WHERE d.sample_request_id IS NULL
        ';
        $crmParams = [];
        if (!empty($agent_id)) {
            $crmSql .= ' AND d.agent_id = ?';
            $crmParams[] = $agent_id;
        }
        $crmSql .= ' ORDER BY d.dropoff_date DESC, d.created_at DESC';
        $stmt = $pdo->prepare($crmSql);
        $stmt->execute($crmParams);
        $legacyResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Merge with sample system results
        $results = array_merge($results, $legacyResults);
    } catch (Throwable $e) {
        // If even CRM fails, continue with what we have
    }

    // Promo segment (optional)
    if ($include_promo) {
        try {
            // Quick existence check to avoid exceptions on fresh DBs
            $hasPromo = $pdo->query("SHOW TABLES LIKE 'promo_demo_dropoffs'")->fetchColumn();
            if ($hasPromo) {
                $promoSql = '
                    SELECT
                        pdd.id,
                        COALESCE(pdl.crm_lead_id, 0) AS lead_id,
                        pdd.agent_id,
                        NULL AS demo_type_id,
                        pdd.dropoff_date,
                        pdd.follow_up_date,
                        pdd.follow_up_status,
                        NULL AS follow_up_task_id,
                        CASE 
                            WHEN pdr.id IS NOT NULL THEN "confirmed"
                            ELSE "dropped"
                        END AS demo_status,
                        pdd.notes,
                        pdd.created_at,
                        pdd.updated_at,
                        COALESCE(l.first_name, pdl.contact_first_name) AS first_name,
                        COALESCE(l.last_name, pdl.contact_last_name) AS last_name,
                        pdl.contact_email AS email,
                        pdl.contact_phone AS phone,
                        COALESCE(l.company_name, pdl.store_name) AS company_name,
                        l.lead_status,
                        pdd.product_name AS product_name,
                        NULL AS blend_type,
                        4.00 AS size_oz,
                        80 AS total_mg,
                        DATEDIFF(pdd.follow_up_date, CURDATE()) AS days_until_followup,
                        u.first_name AS agent_first_name,
                        u.last_name AS agent_last_name,
                        (SELECT COUNT(*) FROM users WHERE email = pdl.contact_email) AS has_user,
                        (
                            SELECT COUNT(*) 
                            FROM wholesale_accounts wa 
                            JOIN users u2 ON u2.id = wa.user_id 
                            WHERE u2.email = pdl.contact_email AND wa.approved = 1
                        ) as has_wholesale_account,
                        pdl.id AS demo_lead_id,
                        pdr.rating, pdr.review_text, NULL AS preferred_strength, pdr.created_at AS review_date,
                        "promo" AS source
                    FROM promo_demo_dropoffs pdd
                    LEFT JOIN promo_demo_leads pdl ON pdl.id = pdd.demo_lead_id
                    LEFT JOIN crm_leads l ON l.id = pdd.crm_lead_id
                    LEFT JOIN promo_demo_reviews pdr ON pdr.demo_lead_id = pdd.demo_lead_id
                    LEFT JOIN users u ON u.id = pdd.agent_id
                ';
                $promoParams = [];
                if (!empty($agent_id)) {
                    $promoSql .= ' WHERE pdd.agent_id = ?';
                    $promoParams[] = $agent_id;
                }
                $promoSql .= ' ORDER BY pdd.dropoff_date DESC, pdd.created_at DESC';
                $stmt = $pdo->prepare($promoSql);
                $stmt->execute($promoParams);
                $promoRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                // Merge
                $results = array_merge($results, $promoRows);
            }
        } catch (Throwable $e) {
            // Ignore promo failures; CRM data already captured
        }
    }

    // Sort combined results consistently by dropoff_date DESC, created_at DESC
    usort($results, function($a, $b) {
        $da = strtotime($a['dropoff_date'] ?? '1970-01-01');
        $db = strtotime($b['dropoff_date'] ?? '1970-01-01');
        if ($da === $db) {
            $ca = strtotime($a['created_at'] ?? '1970-01-01 00:00:00');
            $cb = strtotime($b['created_at'] ?? '1970-01-01 00:00:00');
            return $cb <=> $ca;
        }
        return $db <=> $da;
    });

    return $results;
}