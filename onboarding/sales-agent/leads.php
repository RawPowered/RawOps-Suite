<?php

// Include lead chaining functions
require_once __DIR__ . '/../../required/lead-chaining-functions.php';

if (!function_exists('crm_sa_clean_input')) {
    function crm_sa_clean_input($value)
    {
        return is_string($value)
            ? trim(preg_replace("/\x{200B}|\x{FEFF}/u", '', $value))
            : $value;
    }
}

if (!function_exists('crm_sa_value_or_null')) {
    function crm_sa_value_or_null($value)
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $value = crm_sa_clean_input($value);
            return $value === '' ? null : $value;
        }
        return $value;
    }
}

if (!function_exists('crm_sa_find_lead_by_email')) {
    function crm_sa_find_lead_by_email(PDO $pdo, string $email): ?array
    {
        if ($email === '') {
            return null;
        }
        $stmt = $pdo->prepare('SELECT id, assigned_agent_id, is_archived, lead_status, converted_to_account_id FROM crm_leads WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('crm_sa_find_lead_by_company_address')) {
    function crm_sa_find_lead_by_company_address(PDO $pdo, ?string $company, ?string $address): ?array
    {
        if (!$company || !$address) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT id, assigned_agent_id, is_archived, lead_status, converted_to_account_id FROM crm_leads WHERE company_name = ? AND address = ? LIMIT 1');
        $stmt->execute([$company, $address]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('crm_sa_reactivate_lead')) {
    function crm_sa_reactivate_lead(PDO $pdo, array $lead, array $payload, int $agentId): void
    {
        $leadId = (int)$lead['id'];
        $leadStatus = $payload['lead_status'] ?? 'new';
        if ($leadStatus === '') {
            $leadStatus = 'new';
        }

        $stmt = $pdo->prepare('
            UPDATE crm_leads
            SET assigned_agent_id = ?,
                first_name = ?,
                last_name = ?,
                email = ?,
                phone = ?,
                company_name = ?,
                website = ?,
                address_line1 = ?,
                address_line2 = ?,
                city = ?,
                state = ?,
                zip_code = ?,
                country = ?,
                address = ?,
                postal_code = ?,
                lead_source = ?,
                lead_priority = ?,
                estimated_monthly_volume = ?,
                estimated_deal_value = ?,
                interest_level = ?,
                next_follow_up = ?,
                is_archived = 0,
                archived_at = NULL,
                archived_by = NULL,
                archive_reason = NULL,
                archive_notes = NULL,
                lead_status = ?,
                updated_at = NOW()
            WHERE id = ?
        ');

        $stmt->execute([
            $agentId ?: null,
            $payload['first_name'] ?? '',
            $payload['last_name'] ?? '',
            $payload['email'] ?? '',
            crm_sa_value_or_null($payload['phone'] ?? null),
            crm_sa_value_or_null($payload['company_name'] ?? null),
            crm_sa_value_or_null($payload['website'] ?? null),
            crm_sa_value_or_null($payload['address_line1'] ?? null),
            crm_sa_value_or_null($payload['address_line2'] ?? null),
            crm_sa_value_or_null($payload['city'] ?? null),
            crm_sa_value_or_null($payload['state'] ?? null),
            crm_sa_value_or_null($payload['zip_code'] ?? null),
            crm_sa_value_or_null($payload['country'] ?? null),
            crm_sa_value_or_null($payload['address'] ?? null),
            crm_sa_value_or_null($payload['postal_code'] ?? null),
            $payload['lead_source'] ?? 'website',
            $payload['lead_priority'] ?? 'medium',
            crm_sa_value_or_null($payload['estimated_monthly_volume'] ?? null),
            crm_sa_value_or_null($payload['estimated_deal_value'] ?? null),
            $payload['interest_level'] ?? 5,
            crm_sa_value_or_null($payload['next_follow_up'] ?? null),
            $leadStatus,
            $leadId
        ]);

        try {
            $noteStmt = $pdo->prepare('INSERT INTO crm_notes (lead_id, agent_id, note_type, title, content, created_at) VALUES (?, ?, "system", ?, ?, NOW())');
            $title = 'Lead Reactivated';
            $content = 'Lead reactivated from archive during new lead submission.';
            if ($agentId) {
                $content .= ' Initiated by user #' . $agentId . '.';
            }
            $noteStmt->execute([$leadId, $agentId ?: null, $title, $content]);
        } catch (Throwable $e) {
            // Non-blocking note failure
        }
    }
}

if (!function_exists('crm_sa_resolve_duplicate_lead')) {
    function crm_sa_resolve_duplicate_lead(PDO $pdo, array $lead, array $payload, int $agentId): array
    {
        $leadId = (int)$lead['id'];
        if (!empty($lead['converted_to_account_id'])) {
            return [
                'status' => 'converted',
                'lead_id' => $leadId,
                'account_id' => (int)$lead['converted_to_account_id']
            ];
        }

        $status = strtolower((string)($lead['lead_status'] ?? ''));
        $isArchived = !empty($lead['is_archived']);

        if ($isArchived || $status === 'deleted') {
            crm_sa_reactivate_lead($pdo, $lead, $payload, $agentId);
            return [
                'status' => 'revived',
                'lead_id' => $leadId
            ];
        }

        return [
            'status' => 'active',
            'lead_id' => $leadId,
            'assigned_agent_id' => isset($lead['assigned_agent_id']) ? (int)$lead['assigned_agent_id'] : null
        ];
    }
}

if (!function_exists('crm_sa_attempt_duplicate_resolution')) {
    function crm_sa_attempt_duplicate_resolution(PDO $pdo, array $locators, array $payload, int $agentId): array
    {
        if (!empty($locators['email'])) {
            $existing = crm_sa_find_lead_by_email($pdo, $locators['email']);
            if ($existing) {
                return crm_sa_resolve_duplicate_lead($pdo, $existing, $payload, $agentId);
            }
        }

        if (!empty($locators['company']) && !empty($locators['address'])) {
            $existing = crm_sa_find_lead_by_company_address($pdo, $locators['company'], $locators['address']);
            if ($existing) {
                return crm_sa_resolve_duplicate_lead($pdo, $existing, $payload, $agentId);
            }
        }

        return ['status' => 'none'];
    }
}

// Parse lead address endpoint
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'parse_lead_address') {
    require_once __DIR__ . '/../../required/config.php';
    header('Content-Type: application/json');
    try { start_session(); } catch (Throwable $e) {}
    $pdo = db();
    $uid = current_user_id();
    
    try {
        $leadId = intval($_POST['lead_id'] ?? 0);
        if (!$leadId) {
            throw new Exception('Missing lead_id');
        }
        
        // Get lead address
        $stmt = $pdo->prepare('SELECT id, address, assigned_agent_id FROM crm_leads WHERE id = ?');
        $stmt->execute([$leadId]);
        $lead = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$lead) {
            throw new Exception('Lead not found');
        }
        
        // Verify access (admin has full access, agents only their leads)
        $isAdmin = current_admin_id() !== null;
        if (!$isAdmin && intval($lead['assigned_agent_id']) !== intval($uid)) {
            throw new Exception('Access denied');
        }
        
        if (!$lead['address']) {
            throw new Exception('Lead has no address to parse');
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
        
        // Update lead (use COALESCE to preserve existing values if present)
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

// Early lightweight AJAX endpoints BEFORE any HTML output
if ((isset($_GET['action']) && $_GET['action'] === 'check_place') || (isset($_POST['action']) && $_POST['action'] === 'quick_add_from_place')) {
    require_once __DIR__ . '/../../required/config.php';
    header('Content-Type: application/json');
    try { start_session(); } catch (Throwable $e) {}
    $pdo = db();
    $act = $_GET['action'] ?? $_POST['action'];
    try {
        if ($act === 'check_place') {
            $uidForCheck = current_user_id();
            $name = trim($_GET['name'] ?? '');
            $addr = trim($_GET['addr'] ?? '');
            $existsAssigned = false; $existsUnassigned = false; $assignedToMe = false; $archivedByMe = false; $leadId = null;
            $likeName = '%' . $name . '%'; $likeAddr = '%' . $addr . '%';
            
            // Check if archived by current agent and get lead ID
            if ($uidForCheck) {
                $stmt = $pdo->prepare('
                    SELECT l.id FROM crm_leads l
                    INNER JOIN crm_lead_agent_archives aa ON aa.lead_id = l.id AND aa.agent_id = ?
                    WHERE (l.company_name LIKE ? OR l.address LIKE ?)
                    LIMIT 1
                ');
                $stmt->execute([$uidForCheck, $likeName, $likeAddr]);
                if ($archiveRow = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $archivedByMe = true;
                    $leadId = intval($archiveRow['id']);
                }
            }
            
            $stmt = $pdo->prepare('SELECT id, assigned_agent_id FROM crm_leads WHERE (company_name LIKE ? OR address LIKE ?) ORDER BY updated_at DESC LIMIT 1');
            $stmt->execute([$likeName, $likeAddr]);
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!$leadId) $leadId = intval($row['id']);
                $existsAssigned = !empty($row['assigned_agent_id']);
                $existsUnassigned = empty($row['assigned_agent_id']);
                if ($uidForCheck && !empty($row['assigned_agent_id']) && intval($row['assigned_agent_id']) === intval($uidForCheck)) {
                    $assignedToMe = true;
                }
            } else {
                $stmt = $pdo->prepare('SELECT sales_agent_id FROM wholesale_accounts WHERE (business_name LIKE ? OR address_line1 LIKE ?) ORDER BY id DESC LIMIT 1');
                $stmt->execute([$likeName, $likeAddr]);
                if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $existsAssigned = !empty($row['sales_agent_id']);
                    $existsUnassigned = empty($row['sales_agent_id']);
                    if ($uidForCheck && !empty($row['sales_agent_id']) && intval($row['sales_agent_id']) === intval($uidForCheck)) {
                        $assignedToMe = true;
                    }
                }
            }
            echo json_encode([
                'assigned' => $existsAssigned ? 1 : 0, 
                'unassigned' => $existsUnassigned ? 1 : 0, 
                'assigned_to_me' => $assignedToMe ? 1 : 0, 
                'archived_by_me' => $archivedByMe ? 1 : 0,
                'lead_id' => $leadId
            ]);
            exit;
        }
        if ($act === 'quick_add_from_place') {
            $uid = current_user_id();
            if (!$uid) { echo json_encode(['ok' => false, 'error' => 'auth']); exit; }

            $company = crm_sa_clean_input($_POST['company_name'] ?? '');
            $address = crm_sa_clean_input($_POST['address'] ?? '');
            $phone = crm_sa_clean_input($_POST['phone'] ?? '');
            $website = crm_sa_clean_input($_POST['website'] ?? '');
            $addr1 = crm_sa_clean_input($_POST['address_line1'] ?? '');
            $addr2 = crm_sa_clean_input($_POST['address_line2'] ?? '');
            $city = crm_sa_clean_input($_POST['city'] ?? '');
            $state = crm_sa_clean_input($_POST['state'] ?? '');
            $zip = crm_sa_clean_input($_POST['zip_code'] ?? ($_POST['postal_code'] ?? ''));
            $country = crm_sa_clean_input($_POST['country'] ?? 'USA');
            $postal = crm_sa_clean_input($_POST['postal_code'] ?? '');

            if ($company === '' && $address === '') { echo json_encode(['ok' => false, 'error' => 'missing']); exit; }

            $payload = [
                'first_name' => 'Store',
                'last_name' => 'Lead',
                'email' => null,
                'phone' => $phone ?: null,
                'company_name' => $company ?: null,
                'website' => $website ?: null,
                'address_line1' => $addr1 ?: null,
                'address_line2' => $addr2 ?: null,
                'city' => $city ?: null,
                'state' => $state ?: null,
                'zip_code' => $zip ?: null,
                'country' => $country ?: 'USA',
                'address' => $address ?: null,
                'postal_code' => $postal ?: null,
                'lead_source' => 'website',
                'lead_status' => 'new',
                'lead_priority' => 'medium',
                'estimated_monthly_volume' => null,
                'estimated_deal_value' => null,
                'interest_level' => 5,
                'next_follow_up' => null
            ];

            $dupResult = crm_sa_attempt_duplicate_resolution($pdo, [
                'company' => $company,
                'address' => $address
            ], $payload, $uid);

            if ($dupResult['status'] === 'converted') {
                echo json_encode([
                    'ok' => false,
                    'error' => 'converted',
                    'lead_id' => $dupResult['lead_id'],
                    'account_id' => $dupResult['account_id'],
                    'message' => 'This store is already a customer account.'
                ]);
                exit;
            }

            if ($dupResult['status'] === 'revived' || $dupResult['status'] === 'active') {
                try {
                    $sourcePage = (!empty($_POST['attribution_source']) && $_POST['attribution_source'] === 'accounts_nearby') ? 'accounts_nearby' : 'leads_nearby';
                    $method = ($sourcePage === 'accounts_nearby') ? 'nearby_accounts' : 'nearby_leads';
                    $attr = $pdo->prepare('INSERT INTO crm_lead_attribution (lead_id, agent_id, method, status, place_id, source_page, context_json) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $context = [
                        'request_path' => $_SERVER['REQUEST_URI'] ?? null,
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
                    ];
                    $attr->execute([
                        (int)$dupResult['lead_id'],
                        (int)$uid,
                        $method,
                        $dupResult['status'] === 'revived' ? 'revived' : 'active_existing',
                        $_POST['place_id'] ?? null,
                        $sourcePage,
                        json_encode($context, JSON_UNESCAPED_SLASHES)
                    ]);
                } catch (Throwable $e) {
                    // Ignore attribution errors
                }
                $response = [
                    'ok' => true,
                    'lead_id' => $dupResult['lead_id'],
                    'mode' => $dupResult['status']
                ];

                if (!empty($dupResult['assigned_agent_id']) && (int)$dupResult['assigned_agent_id'] === (int)$uid) {
                    $response['assigned_to_me'] = 1;
                }

                echo json_encode($response);
                exit;
            }

            // Insert a minimally valid lead; tolerate missing email by retrying with a placeholder if needed
            $first = 'Store';
            $last = 'Lead';
            $email = null;
            $leadId = 0;
            try {
                $stmt = $pdo->prepare('INSERT INTO crm_leads (assigned_agent_id, first_name, last_name, email, phone, company_name, website, address_line1, address_line2, city, state, zip_code, country, address, postal_code, lead_source, lead_status, lead_priority, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "website", "new", "medium", NOW())');
                $stmt->execute([$uid, $first, $last, $email, ($phone ?: null), ($company ?: null), ($website ?: null), ($addr1 ?: null), ($addr2 ?: null), ($city ?: null), ($state ?: null), ($zip ?: null), ($country ?: 'USA'), ($address ?: null), ($postal ?: null)]);
            } catch (Throwable $e) {
                $placeholderEmail = 'lead+' . substr(sha1(($company ?: '') . '|' . ($address ?: '') . '|' . microtime(true)), 0, 12) . '@noemail.invalid';
                $stmt = $pdo->prepare('INSERT INTO crm_leads (assigned_agent_id, first_name, last_name, email, phone, company_name, website, address_line1, address_line2, city, state, zip_code, country, address, postal_code, lead_source, lead_status, lead_priority, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "website", "new", "medium", NOW())');
                $stmt->execute([$uid, $first, $last, $placeholderEmail, ($phone ?: null), ($company ?: null), ($website ?: null), ($addr1 ?: null), ($addr2 ?: null), ($city ?: null), ($state ?: null), ($zip ?: null), ($country ?: 'USA'), ($address ?: null), ($postal ?: null)]);
            }
            $leadId = (int)$pdo->lastInsertId();

            // Handle tag assignments
            try {
                $tagIds = isset($_POST['tag_ids']) ? $_POST['tag_ids'] : '';
                if (!empty($tagIds)) {
                    $tagIdsArray = array_filter(array_map('intval', explode(',', $tagIds)));
                    $tagStmt = $pdo->prepare('
                        INSERT INTO crm_lead_tag_assignments (lead_id, tag_id, assigned_by_agent_id, is_auto_assigned)
                        VALUES (?, ?, ?, 0)
                        ON DUPLICATE KEY UPDATE assigned_by_agent_id = VALUES(assigned_by_agent_id)
                    ');
                    foreach ($tagIdsArray as $tagId) {
                        $tagStmt->execute([$leadId, $tagId, $uid]);
                    }
                }
            } catch (Throwable $e) {
                // Ignore tag errors, don't break lead creation
                error_log("Tag assignment error for lead $leadId: " . $e->getMessage());
            }

            try {
                $sourcePage = (!empty($_POST['attribution_source']) && $_POST['attribution_source'] === 'accounts_nearby') ? 'accounts_nearby' : 'leads_nearby';
                $method = ($sourcePage === 'accounts_nearby') ? 'nearby_accounts' : 'nearby_leads';
                $attr = $pdo->prepare('INSERT INTO crm_lead_attribution (lead_id, agent_id, method, status, place_id, source_page, context_json) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $context = [
                    'request_path' => $_SERVER['REQUEST_URI'] ?? null,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
                ];
                $attr->execute([
                    $leadId,
                    (int)$uid,
                    $method,
                    'created',
                    $_POST['place_id'] ?? null,
                    $sourcePage,
                    json_encode($context, JSON_UNESCAPED_SLASHES)
                ]);
            } catch (Throwable $e) {
                // Ignore attribution errors
            }

            echo json_encode(['ok' => true, 'lead_id' => $leadId, 'mode' => 'created']);
            exit;
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'server']);
    }
    exit;
}

// Archive Nearby Place Endpoint - Hide place from "Leads Near Here"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'archive_nearby_place') {
    require_once __DIR__ . '/../../required/config.php';
    header('Content-Type: application/json');
    try { start_session(); } catch (Throwable $e) {}
    $pdo = db();
    $uid = current_user_id();
    if (!$uid) { echo json_encode(['ok' => false, 'error' => 'auth']); exit; }
    
    try {
        $placeId = trim($_POST['place_id'] ?? '');
        $placeName = trim($_POST['place_name'] ?? '');
        $placeAddress = trim($_POST['place_address'] ?? '');
        $archiveNote = trim($_POST['archive_note'] ?? '');
        
        if (!$placeName || !$placeAddress) { 
            echo json_encode(['ok' => false, 'error' => 'missing_place_info']); 
            exit; 
        }
        
        // Insert archive record (or update if exists)
        $stmt = $pdo->prepare('
            INSERT INTO crm_nearby_place_archives (place_id, place_name, place_address, agent_id, archived_at, archive_note) 
            VALUES (?, ?, ?, ?, NOW(), ?) 
            ON DUPLICATE KEY UPDATE archived_at = NOW(), archive_note = VALUES(archive_note)
        ');
        $stmt->execute([$placeId ?: null, $placeName, $placeAddress, $uid, $archiveNote ?: null]);
        
        // Count total agents who have archived this place
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM crm_nearby_place_archives WHERE place_name = ? AND place_address = ?');
        $stmt->execute([$placeName, $placeAddress]);
        $archiveCount = intval($stmt->fetchColumn());
        
        echo json_encode(['ok' => true, 'archive_count' => $archiveCount, 'auto_archived' => $archiveCount >= 3]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'server', 'message' => $e->getMessage()]);
    }
    exit;
}

// Unarchive Nearby Place Endpoint - Show place again in "Leads Near Here"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unarchive_nearby_place') {
    require_once __DIR__ . '/../../required/config.php';
    header('Content-Type: application/json');
    try { start_session(); } catch (Throwable $e) {}
    $pdo = db();
    $uid = current_user_id();
    if (!$uid) { echo json_encode(['ok' => false, 'error' => 'auth']); exit; }
    
    try {
        $archiveId = intval($_POST['archive_id'] ?? 0);
        
        if ($archiveId <= 0) { echo json_encode(['ok' => false, 'error' => 'invalid_id']); exit; }
        
        // Delete archive record for this agent
        $stmt = $pdo->prepare('DELETE FROM crm_nearby_place_archives WHERE id = ? AND agent_id = ?');
        $stmt->execute([$archiveId, $uid]);
        
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'server', 'message' => $e->getMessage()]);
    }
    exit;
}

// Get Archived Nearby Places Endpoint
if (isset($_GET['action']) && $_GET['action'] === 'get_archived_places') {
    require_once __DIR__ . '/../../required/config.php';
    header('Content-Type: application/json');
    try { start_session(); } catch (Throwable $e) {}
    $pdo = db();
    $uid = current_user_id();
    if (!$uid) { echo json_encode(['ok' => false, 'error' => 'auth']); exit; }
    
    try {
        // Get places archived by this agent
        $stmt = $pdo->prepare('
            SELECT id, place_id, place_name, place_address, archived_at, archive_note,
                   (SELECT COUNT(*) FROM crm_nearby_place_archives WHERE place_name = pa.place_name AND place_address = pa.place_address) as total_archive_count
            FROM crm_nearby_place_archives pa
            WHERE agent_id = ?
            ORDER BY archived_at DESC
        ');
        $stmt->execute([$uid]);
        $archivedPlaces = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['ok' => true, 'places' => $archivedPlaces]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'server', 'message' => $e->getMessage()]);
    }
    exit;
}

// Early JSON handler for Add Lead (AJAX from dashboard modal)
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action']) && $_POST['action'] === 'add_lead'
    && (
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && stripos((string)$_SERVER['HTTP_X_REQUESTED_WITH'], 'fetch') !== false)
        || (isset($_SERVER['HTTP_ACCEPT']) && stripos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    )
) {
    require_once __DIR__ . '/../../required/config.php';
    header('Content-Type: application/json');
    try { start_session(); } catch (Throwable $e) {}
    try {
        $pdo = db();
        $uid = current_user_id();
        if (!$uid) { echo json_encode(['ok' => false, 'code' => 'auth', 'message' => 'Not authenticated']); exit; }

        // Basic role check unless in admin simulation
        $isAdminSimulation = isset($_SESSION['admin_simulation']);
        if (!$isAdminSimulation) {
            $stRole = $pdo->prepare('SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.name = "sales_agent"');
            try { $stRole->execute([$uid]); if (!$stRole->fetch()) { echo json_encode(['ok' => false, 'code' => 'forbidden', 'message' => 'Access restricted']); exit; } } catch (Throwable $e) {}
        }

        // Clean inputs (trim, basic cleansing per MDS/001 rules)
        $trim = static function($v){ return is_string($v) ? trim(preg_replace("/\x{200B}|\x{FEFF}/u", '', $v)) : $v; };
        $firstName = $trim($_POST['first_name'] ?? '');
        $lastName = $trim($_POST['last_name'] ?? '');
        $emailRaw = $trim($_POST['email'] ?? '');
        $phoneRaw = $trim($_POST['phone'] ?? '');
        $company = $trim($_POST['company_name'] ?? '') ?: null;
        $website = $trim($_POST['website'] ?? '') ?: null;
        $addr1 = $trim($_POST['address_line1'] ?? '') ?: null;
        $addr2 = $trim($_POST['address_line2'] ?? '') ?: null;
        $city = $trim($_POST['city'] ?? '') ?: null;
        $state = $trim($_POST['state'] ?? '') ?: null;
        $zip = $trim($_POST['zip_code'] ?? '') ?: null;
        $country = $trim($_POST['country'] ?? '') ?: 'USA';
        $addressFull = $trim($_POST['address_full'] ?? '') ?: null;
        $postal = $trim($_POST['postal_code'] ?? '') ?: null;
        $leadSource = $trim($_POST['lead_source'] ?? '') ?: 'website';
        $leadPriority = $trim($_POST['lead_priority'] ?? '') ?: 'medium';
        $estMonthly = $_POST['estimated_monthly_volume'] !== '' ? (float)$trim($_POST['estimated_monthly_volume']) : null;
        $dealValue = $_POST['estimated_deal_value'] !== '' ? (float)$trim($_POST['estimated_deal_value']) : null;
        $interestLevel = (int)($trim($_POST['interest_level'] ?? '5') ?: 5);
        $nextFollowUp = $trim($_POST['next_follow_up'] ?? '') ?: null;

        if ($firstName === '' || $lastName === '' || $emailRaw === '') {
            echo json_encode(['ok' => false, 'code' => 'validation', 'message' => 'Please fill in required fields.']);
            exit;
        }

        // Validate email
        $email = filter_var($emailRaw, FILTER_VALIDATE_EMAIL) ? $emailRaw : '';
        if ($email === '') {
            echo json_encode(['ok' => false, 'code' => 'validation', 'field' => 'email', 'message' => 'Please enter a valid email.']);
            exit;
        }

        // Normalize phone to digits/+
        $phone = $phoneRaw !== '' ? preg_replace('/[^\d\+]/', '', $phoneRaw) : null;
        if ($phone !== null && $phone === '') { $phone = null; }

        $leadPayload = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone,
            'company_name' => $company,
            'website' => $website,
            'address_line1' => $addr1,
            'address_line2' => $addr2,
            'city' => $city,
            'state' => $state,
            'zip_code' => $zip,
            'country' => $country,
            'address' => $addressFull,
            'postal_code' => $postal,
            'lead_source' => $leadSource,
            'lead_priority' => $leadPriority,
            'estimated_monthly_volume' => $estMonthly,
            'estimated_deal_value' => $dealValue,
            'interest_level' => $interestLevel,
            'next_follow_up' => $nextFollowUp,
            'lead_status' => 'new'
        ];

        $dupResolution = crm_sa_attempt_duplicate_resolution($pdo, [
            'email' => $email,
            'company' => $company,
            'address' => $addressFull
        ], $leadPayload, $uid);

        if ($dupResolution['status'] === 'converted') {
            echo json_encode([
                'ok' => false,
                'code' => 'converted',
                'lead_id' => $dupResolution['lead_id'],
                'account_id' => $dupResolution['account_id'],
                'message' => 'This lead has already been converted to a customer account.'
            ]);
            exit;
        }

        if ($dupResolution['status'] === 'revived') {
            echo json_encode([
                'ok' => true,
                'mode' => 'revived',
                'lead_id' => $dupResolution['lead_id'],
                'message' => 'Lead reactivated and reassigned to you.'
            ]);
            exit;
        }

        if ($dupResolution['status'] === 'active') {
            echo json_encode([
                'ok' => true,
                'mode' => 'active',
                'lead_id' => $dupResolution['lead_id'],
                'message' => 'Lead already exists.'
            ]);
            exit;
        }

        // Duplicate checks
        // 1) Email unique (per schema uk_leads_email)
        $stDupe = $pdo->prepare('SELECT id, assigned_agent_id FROM crm_leads WHERE email = ? LIMIT 1');
        $stDupe->execute([$email]);
        if ($row = $stDupe->fetch(PDO::FETCH_ASSOC)) {
            echo json_encode([
                'ok' => false,
                'code' => 'duplicate',
                'field' => 'email',
                'lead_id' => (int)$row['id'],
                'message' => 'A lead with this email already exists.'
            ]);
            exit;
        }

        // 2) Company + full address exact match
        if (!empty($company) && !empty($addressFull)) {
            $stDupe2 = $pdo->prepare('SELECT id, assigned_agent_id FROM crm_leads WHERE company_name = ? AND address = ? LIMIT 1');
            try { $stDupe2->execute([$company, $addressFull]); } catch (Throwable $e) { $stDupe2 = null; }
            if ($stDupe2 && ($row2 = $stDupe2->fetch(PDO::FETCH_ASSOC))) {
                echo json_encode([
                    'ok' => false,
                    'code' => 'duplicate',
                    'field' => 'company_name',
                    'lead_id' => (int)$row2['id'],
                    'message' => 'A lead with this company and address already exists.'
                ]);
                exit;
            }
        }

        // Insert lead
        $stIns = $pdo->prepare('
            INSERT INTO crm_leads (
                assigned_agent_id, first_name, last_name, email, phone, company_name,
                website,
                address_line1, address_line2, city, state, zip_code, country,
                address, postal_code,
                lead_source, lead_priority, estimated_monthly_volume,
                estimated_deal_value, interest_level, next_follow_up
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stIns->execute([
            $uid,
            $firstName,
            $lastName,
            $email,
            $phone,
            $company,
            $website,
            $addr1,
            $addr2,
            $city,
            $state,
            $zip,
            $country,
            $addressFull,
            $postal,
            $leadSource,
            $leadPriority,
            $estMonthly,
            $dealValue,
            $interestLevel,
            $nextFollowUp
        ]);

        $leadId = (int)$pdo->lastInsertId();

        // Optional: create demo drop-offs if provided (using new unified sample system)
        if (!empty($_POST['demo_dropoff']) && !empty($_POST['demo_types']) && is_array($_POST['demo_types'])) {
            $demoTypes = $_POST['demo_types'];
            $dropoffNotes = trim($_POST['dropoff_notes'] ?? '');
            foreach ($demoTypes as $demoTypeId) {
                $demoTypeId = (int)$demoTypeId;
                if ($demoTypeId <= 0) continue;
                try {
                    $result = create_dropoff_sample_request($pdo, $leadId, $uid, $demoTypeId, $dropoffNotes ?: null);
                    // Soft-warn only; lead creation succeeded even if dropoff failed
                } catch (Throwable $e) {
                    // ignore demo errors in JSON flow
                }
            }
        }

        echo json_encode(['ok' => true, 'lead_id' => $leadId, 'message' => 'Lead added successfully']);
        exit;
    } catch (Throwable $e) {
        // Detect duplicate key on email as a fallback
        $msg = (string)$e->getMessage();
        if (stripos($msg, 'duplicate') !== false && stripos($msg, 'email') !== false) {
            echo json_encode(['ok' => false, 'code' => 'duplicate', 'field' => 'email', 'message' => 'A lead with this email already exists.']);
            exit;
        }
        echo json_encode(['ok' => false, 'code' => 'server', 'message' => 'Unable to add lead.']);
        exit;
    }
}
$SEO = [
    'title' => 'My Leads — Sales Agent CRM — Product',
    'description' => 'Manage and track your assigned leads in the Product CRM system.',
    'type' => 'website'
];

require_once __DIR__ . '/../../required/header.php';
require_once __DIR__ . '/../../required/demo-functions.php';
require_once __DIR__ . '/../../required/social-links-helpers.php';
// Permission helpers for viewing/editing payment details (admin-safe)
if (is_file(__DIR__ . '/../../required/admin/wholesale-permissions.php')) {
    /** @noinspection PhpIncludeInspection */
    require_once __DIR__ . '/../../required/admin/wholesale-permissions.php';
} else {
    if (!function_exists('user_can_view_full_payment')) { function user_can_view_full_payment(int $userId, int $accountId): bool { return false; } }
    if (!function_exists('user_can_edit_full_payment')) { function user_can_edit_full_payment(int $userId, int $accountId): bool { return false; } }
}

start_session();
$uid = current_user_id();
if (!$uid) redirect(path_with_base('/index.php'));

// Check access permissions
$isAdminSimulation = isset($_SESSION['admin_simulation']);
$isRealAdmin = current_admin_id() !== null;
$adminMode = !empty($_SESSION['sales_acp_admin_mode']);
$hasFullAccess = $isAdminSimulation || ($isRealAdmin && $adminMode);

if (!$hasFullAccess) {
    // For real users, check role
    $pdo = db();
    $stmt = $pdo->prepare('SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.name = "sales_agent"');
    try { 
        $stmt->execute([$uid]); 
        $hasRole = (bool)$stmt->fetch(); 
    } catch (Throwable $e) { 
        $hasRole = false; 
    }
    if (!$hasRole && !is_training_complete($uid)) {
        redirect(path_with_base('/onboarding/trainee/index.php'));
    }
}

$pdo = db();

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'convert_to_account') {
            // Agent-led conversion creates a pending account; admins create approved
            $isAdmin = current_admin_id() ? true : false;
            $leadId = $leadId ?: intval($_GET['id'] ?? 0);
            if ($leadId <= 0) throw new Exception('Invalid lead.');
            // Fetch lead
            $stmt = $pdo->prepare('SELECT * FROM crm_leads WHERE id = ?');
            $stmt->execute([$leadId]);
            $lead = $stmt->fetch();
            if (!$lead) throw new Exception('Lead not found.');
            
            // Create user if not exists for account linkage
            $userId = null;
            $findU = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $findU->execute([$lead['email']]);
            $userId = intval($findU->fetchColumn());
            if (!$userId) {
                $insU = $pdo->prepare('INSERT INTO users (first_name,last_name,email,created_at) VALUES (?,?,?,NOW())');
                $insU->execute([$lead['first_name'], $lead['last_name'], $lead['email']]);
                $userId = intval($pdo->lastInsertId());
            }
            
            // Create wholesale account (now with address propagation from lead)
            $approvedFlag = $isAdmin ? 1 : 0; // pending for agent
            $businessName = ($lead['company_name'] ?: ($lead['first_name'].' '.$lead['last_name']));
            $phone = $lead['phone'] ?: null;
            // Map lead address fields to account address fields
            // Fallback: if address_line1 missing but full address exists, use it as line1
            $addr1 = $lead['address_line1'] ?: ((trim((string)($lead['address'] ?? '')) !== '') ? trim((string)$lead['address']) : null);
            $addr2 = $lead['address_line2'] ?: null;
            $city = $lead['city'] ?: null;
            $stateRegion = $lead['state'] ?: null;
            $postal = ($lead['zip_code'] ?: ($lead['postal_code'] ?: null));
            $salesAgentId = intval($lead['assigned_agent_id']) ?: null;
            try {
                $insA = $pdo->prepare('INSERT INTO wholesale_accounts (user_id, business_name, phone, address_line1, address_line2, city, state_region, postal_code, approved, created_at, sales_agent_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)');
                $insA->execute([$userId, $businessName, $phone, $addr1, $addr2, $city, $stateRegion, $postal, $approvedFlag, $salesAgentId]);
            } catch (Throwable $e) {
                // Fallback for environments missing address_line2 column
                try {
                    $insA = $pdo->prepare('INSERT INTO wholesale_accounts (user_id, business_name, phone, address_line1, city, state_region, postal_code, approved, created_at, sales_agent_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)');
                    $insA->execute([$userId, $businessName, $phone, $addr1, $city, $stateRegion, $postal, $approvedFlag, $salesAgentId]);
                } catch (Throwable $e2) {
                    // Last resort: original minimal insert
                    $insA = $pdo->prepare('INSERT INTO wholesale_accounts (user_id, business_name, phone, approved, created_at, sales_agent_id) VALUES (?, ?, ?, ?, NOW(), ?)');
                    $insA->execute([$userId, $businessName, $phone, $approvedFlag, $salesAgentId]);
                }
            }
            $accountId = intval($pdo->lastInsertId());
            
            // Create contact and link
            $insC = $pdo->prepare('INSERT INTO crm_contacts (first_name,last_name,email,phone,created_at) VALUES (?,?,?,?,NOW())');
            $insC->execute([$lead['first_name'], $lead['last_name'], $lead['email'], $lead['phone']]);
            $contactId = intval($pdo->lastInsertId());
            $pdo->prepare('INSERT INTO account_contacts (wholesale_account_id, contact_id, is_primary) VALUES (?, ?, 1)')->execute([$accountId, $contactId]);
            
            // Update lead with conversion fields
            $pdo->prepare('UPDATE crm_leads SET converted_to_account_id = ?, converted_to_contact_id = ?, lead_status = "won", updated_at = NOW() WHERE id = ?')->execute([$accountId, $contactId, $leadId]);
            
            // Log operation (best-effort)
            try {
                $ctx = json_encode(['lead_id' => intval($leadId), 'account_id' => intval($accountId), 'contact_id' => intval($contactId), 'approved' => $approvedFlag, 'by_user_id' => intval($uid)]);
                $pdo->prepare('INSERT INTO operations_log (user_id, action, details, created_at) VALUES (?, ?, ?, NOW())')
                    ->execute([$uid, 'convert_lead_to_account', $ctx]);
            } catch (Throwable $e) {}

            flash_set('success', $isAdmin ? 'Lead converted to approved account.' : 'Lead converted to pending account. Awaiting admin approval.');
            header('Location: leads.php?id=' . $leadId);
            exit;
        }
        if ($action === 'update_status') {
            // Quick status update
            $leadId = intval($_POST['lead_id']);
            $newStatus = $_POST['lead_status'];
            $notes = trim($_POST['status_notes'] ?? '');
            
            // Verify access
            if (!$hasFullAccess) {
                $stmt = $pdo->prepare('SELECT 1 FROM crm_leads WHERE id = ? AND assigned_agent_id = ?');
                $stmt->execute([$leadId, $uid]);
                if (!$stmt->fetch()) {
                    flash_set('error', 'Access denied to this lead.');
                    header('Location: leads.php');
                    exit;
                }
            }
            
            // Update status
            $stmt = $pdo->prepare('UPDATE crm_leads SET lead_status = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$newStatus, $leadId]);
            
            // Add note if provided
            if ($notes) {
                $stmt = $pdo->prepare('
                    INSERT INTO crm_notes (lead_id, agent_id, note_type, content, created_at)
                    VALUES (?, ?, "status_change", ?, NOW())
                ');
                $stmt->execute([$leadId, $uid, "Status changed to: $newStatus. $notes"]);
            }
            
            flash_set('success', 'Lead status updated successfully!');
            redirect('leads.php?id=' . $leadId);
            
        } elseif ($action === 'add_lead') {
            // Handle email/phone "not available" flags
            $emailNotAvailable = !empty($_POST['email_not_available']);
            $phoneNotAvailable = !empty($_POST['phone_not_available']);
            
            // Prepare email
            $email = null;
            if ($emailNotAvailable) {
                // Email will be set to placeholder after insert
                $email = null;
            } else {
                $email = $_POST['email'] ?: null;
            }
            
            // Prepare phone
            $phone = null;
            if ($phoneNotAvailable) {
                $phone = null;
            } else {
                $phone = $_POST['phone'] ?: null;
            }
            
            $stmt = $pdo->prepare('
                INSERT INTO crm_leads (
                    assigned_agent_id, first_name, last_name, email, phone, company_name,
                    website,
                    address_line1, address_line2, city, state, zip_code, country,
                    address, postal_code,
                    lead_source, lead_priority, estimated_monthly_volume,
                    estimated_deal_value, interest_level, next_follow_up
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            
            $nextFollowUp = !empty($_POST['next_follow_up']) ? $_POST['next_follow_up'] : null;
            
            $stmt->execute([
                $uid,
                $_POST['first_name'],
                $_POST['last_name'],
                $email,
                $phone,
                $_POST['company_name'] ?: null,
                $_POST['website'] ?: null,
                $_POST['address_line1'] ?: null,
                $_POST['address_line2'] ?: null,
                $_POST['city'] ?: null,
                $_POST['state'] ?: null,
                $_POST['zip_code'] ?: null,
                $_POST['country'] ?: 'USA',
                $_POST['address_full'] ?: null,
                $_POST['postal_code'] ?: null,
                $_POST['lead_source'] ?: 'website',
                $_POST['lead_priority'] ?: 'medium',
                floatval($_POST['estimated_monthly_volume'] ?: 0) ?: null,
                floatval($_POST['estimated_deal_value'] ?: 0) ?: null,
                intval($_POST['interest_level'] ?: 5),
                $nextFollowUp
            ]);
            
            $leadId = $pdo->lastInsertId();
            
            // Handle email chaining or "not available" flag
            if ($emailNotAvailable) {
                mark_email_not_available($pdo, $leadId);
            } elseif (!empty($email)) {
                update_lead_email_with_chaining($pdo, $leadId, $email, false);
            }
            
            // Handle phone chaining or "not available" flag
            if ($phoneNotAvailable) {
                mark_phone_not_available($pdo, $leadId);
            } elseif (!empty($phone)) {
                update_lead_phone_with_chaining($pdo, $leadId, $phone, false);
            }

            try {
                $attr = $pdo->prepare('INSERT INTO crm_lead_attribution (lead_id, agent_id, method, status, source_page, context_json) VALUES (?, ?, ?, ?, ?, ?)');
                $context = [
                    'request_path' => $_SERVER['REQUEST_URI'] ?? null,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
                ];
                $attr->execute([
                    (int)$leadId,
                    (int)$uid,
                    'manual',
                    'created',
                    'sales_leads_form',
                    json_encode($context, JSON_UNESCAPED_SLASHES)
                ]);
            } catch (Throwable $e) {
                // Non-blocking; attribution failure shouldn't affect lead creation
            }
            
            // Handle demo drop-off if checked (using new unified sample system)
            if (!empty($_POST['demo_dropoff']) && !empty($_POST['demo_types'])) {
                $demoTypes = is_array($_POST['demo_types']) ? $_POST['demo_types'] : [$_POST['demo_types']];
                $dropoffNotes = $_POST['dropoff_notes'] ?? null;
                
                foreach ($demoTypes as $demoTypeId) {
                    if ($demoTypeId) {
                        $result = create_dropoff_sample_request($pdo, $leadId, $uid, $demoTypeId, $dropoffNotes);
                        
                        if (!$result['success']) {
                            flash_set('warning', 'Lead created but demo drop-off failed: ' . $result['message']);
                        }
                    }
                }
            }
            
            flash_set('success', 'Lead added successfully!');
            if (!empty($_POST['return_detail'])) {
                redirect('leads.php?id=' . (int)$leadId);
            } else {
                redirect('leads.php');
            }
            
        } elseif ($action === 'update_lead') {
            $leadId = intval($_POST['lead_id']);
            
            // Verify access (admin has full access, agents only their leads)
            if (!$hasFullAccess) {
                $stmt = $pdo->prepare('SELECT 1 FROM crm_leads WHERE id = ? AND assigned_agent_id = ?');
                $stmt->execute([$leadId, $uid]);
                if (!$stmt->fetch()) {
                    flash_set('error', 'Access denied to this lead.');
                    redirect('leads.php');
                }
            }
            
            // Handle email/phone "not available" flags
            $emailNotAvailable = !empty($_POST['email_not_available']);
            $phoneNotAvailable = !empty($_POST['phone_not_available']);
            
            // First, update basic lead info
            $stmt = $pdo->prepare('
                UPDATE crm_leads SET 
                    first_name = ?, last_name = ?, company_name = ?,
                    website = ?,
                    address_line1 = ?, address_line2 = ?, city = ?, state = ?, zip_code = ?, country = ?,
                    address = ?, postal_code = ?,
                    lead_source = ?, lead_status = ?, lead_priority = ?, 
                    estimated_monthly_volume = ?, estimated_deal_value = ?, interest_level = ?, 
                    next_follow_up = ?, updated_at = NOW()
                WHERE id = ?
            ');
            
            $nextFollowUp = !empty($_POST['next_follow_up']) ? $_POST['next_follow_up'] : null;
            
            $stmt->execute([
                $_POST['first_name'],
                $_POST['last_name'],
                $_POST['company_name'] ?: null,
                $_POST['website'] ?: null,
                $_POST['address_line1'] ?: null,
                $_POST['address_line2'] ?: null,
                $_POST['city'] ?: null,
                $_POST['state'] ?: null,
                $_POST['zip_code'] ?: null,
                $_POST['country'] ?: 'USA',
                $_POST['address_full'] ?: null,
                $_POST['postal_code'] ?: null,
                $_POST['lead_source'] ?: 'website',
                $_POST['lead_status'] ?: 'new',
                $_POST['lead_priority'] ?: 'medium',
                floatval($_POST['estimated_monthly_volume'] ?: 0) ?: null,
                floatval($_POST['estimated_deal_value'] ?: 0) ?: null,
                intval($_POST['interest_level'] ?: 5),
                $nextFollowUp,
                $leadId
            ]);
            
            // Handle email updates with chaining
            $email = $_POST['email'] ?: null;
            update_lead_email_with_chaining($pdo, $leadId, $email, $emailNotAvailable);
            
            // Handle phone updates with chaining  
            $phone = $_POST['phone'] ?: null;
            update_lead_phone_with_chaining($pdo, $leadId, $phone, $phoneNotAvailable);
            
            flash_set('success', 'Lead updated successfully!');
            header('Location: leads.php?id=' . $leadId);
            exit;
        }
    } catch (Exception $e) {
        flash_set('error', 'Error: ' . $e->getMessage());
        redirect('leads.php' . ($leadId ? '?id=' . $leadId : ''));
    }
}

// Get leads data
$leads = [];
$leadDetail = null;
$accountDetail = null;
$recentInvoices = [];
$paymentPreview = [];
$canViewFull = false;
$canEditFull = false;
$leadId = intval($_GET['id'] ?? 0);

try {
        if ($leadId > 0) {
        // Get specific lead detail
        $query = '
            SELECT l.*, CONCAT(l.first_name, " ", l.last_name) as full_name,
                   DATEDIFF(NOW(), l.created_at) as days_old,
                   (SELECT COUNT(*) FROM crm_call_logs cl WHERE cl.lead_id = l.id) as call_count,
                   (SELECT COUNT(*) FROM crm_notes n WHERE n.lead_id = l.id) as note_count,
                   (SELECT COUNT(*) FROM crm_tasks t WHERE t.lead_id = l.id AND t.status = "pending") as pending_tasks,
                   (SELECT COUNT(*) FROM crm_tasks t2 WHERE t2.lead_id = l.id AND (t2.task_type IS NULL OR t2.task_type <> "data_cleanup")) as task_noncleanup_count,
                   (SELECT COUNT(*) FROM crm_demo_dropoffs dd WHERE dd.lead_id = l.id) as demo_count,
                   CONCAT(a.first_name, " ", a.last_name) as agent_name
            FROM crm_leads l 
            LEFT JOIN users a ON a.id = l.assigned_agent_id
            WHERE l.id = ?';
        
        if (!$hasFullAccess) {
            $query .= ' AND l.assigned_agent_id = ?';
            $stmt = $pdo->prepare($query);
            $stmt->execute([$leadId, $uid]);
        } else {
            $stmt = $pdo->prepare($query);
            $stmt->execute([$leadId]);
        }
        
        $leadDetail = $stmt->fetch();
        
        if (!$leadDetail) {
            flash_set('error', 'Lead not found or access denied.');
            header('Location: leads.php');
            exit;
        }
        // If this lead has been converted, redirect to the account detail. The lead is no longer active in ACP lists.
        if (!empty($leadDetail['converted_to_account_id'])) {
            header('Location: accounts.php?id=' . intval($leadDetail['converted_to_account_id']));
            exit;
        }
        // Collect known store names to filter nearby suggestions (CRM + Accounts)
        try {
            $knownStores = [];
            $ks = $pdo->query('SELECT company_name AS name FROM crm_leads WHERE company_name IS NOT NULL AND company_name <> "" UNION SELECT business_name AS name FROM wholesale_accounts WHERE business_name IS NOT NULL AND business_name <> ""');
            $knownStores = array_map(function($r){ return strtolower(trim($r['name'])); }, $ks->fetchAll());
        } catch (Throwable $e) { $knownStores = []; }

$sampleSuitePortalBase = 'https://product.example/promo/product-sample/';
$portalParams = array_filter([
    'utm_source' => 'sample-suite',
    'lead_id' => $leadDetail['id'] ?? null,
    'agent_id' => $uid ?? null,
    'company' => $leadDetail['company_name'] ?? null
]);
$sampleSuitePortalUrl = $sampleSuitePortalBase . ($portalParams ? '?' . http_build_query($portalParams) : '');
$sampleSuiteStepLabels = [
    'kickoff' => 'Initial Outreach',
    'offer_reminder_1' => 'Offer Reminder #1',
    'offer_reminder_2' => 'Offer Reminder #2',
    'offer_day5' => 'Day 5 Reminder',
    'rotation_a' => 'Rotation A',
    'rotation_b' => 'Rotation B',
    'rotation_c' => 'Rotation C',
    'click_probe' => 'Click Follow-up',
    'kickoff_resend_fast' => 'Kickoff Recheck (30 min)',
    'kickoff_resend_followup' => 'Kickoff Recheck (4 hr)',
    'feedback' => 'Sample Feedback',
    'human_escalation' => 'Human Escalation',
    'shipped' => 'Shipment Update',
    'delivered' => 'Delivery Check-in',
    'nurture' => 'Post Delivery Nurture',
    'last_call' => 'Final Nudge'
];
if (!function_exists('sample_suite_label_for_ui')) {
    function sample_suite_label_for_ui(string $key, array $labels): string
    {
        return $labels[$key] ?? ucwords(str_replace('_', ' ', $key));
    }
}
$sampleSuiteStartEligibleStatuses = ['pending','approved','shipped','delivered'];

$leadSampleSuite = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            sr.id,
            sr.status AS sample_status,
            sr.suite_status,
            sr.recipient_name,
            e.id AS enrollment_id,
            e.status AS enrollment_status,
            e.current_step_key,
            e.current_step_number,
            e.next_step_scheduled_for
        FROM sample_requests sr
        LEFT JOIN sample_suite_enrollments e ON e.sample_request_id = sr.id
        WHERE sr.lead_id = ?
        ORDER BY sr.requested_at DESC
        LIMIT 3
    ");
    $stmt->execute([$leadDetail['id']]);
    $leadSampleSuite = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $leadSampleSuite = [];
}
        // Load account, invoice info, and payment preview if available
        if (!empty($leadDetail['converted_to_account_id'])) {
            try {
                $accStmt = $pdo->prepare('SELECT wa.*, u.first_name, u.last_name, u.email FROM wholesale_accounts wa JOIN users u ON u.id = wa.user_id WHERE wa.id = ?');
                $accStmt->execute([intval($leadDetail['converted_to_account_id'])]);
                $accountDetail = $accStmt->fetch();

                $recentInvoices = [];
                $paymentPreview = [];
                $canViewFull = false;
                $canEditFull = false;

                if ($accountDetail) {
                    $invStmt = $pdo->prepare('SELECT id, order_number, total, status, created_at FROM orders WHERE wholesale_account_id = ? ORDER BY created_at DESC LIMIT 5');
                    $invStmt->execute([$accountDetail['id']]);
                    $recentInvoices = $invStmt->fetchAll();

                    // Payment preview (brand + last4, full only if permitted)
                    $pmStmt = $pdo->prepare('SELECT id, type, card_brand, last_four, bank_name, is_default, exp_month, exp_year, card_enc, card_holder_name FROM payment_methods WHERE wholesale_account_id = ? ORDER BY is_default DESC, created_at DESC');
                    $pmStmt->execute([$accountDetail['id']]);
                    $paymentPreview = $pmStmt->fetchAll();
                    $canViewFull = function_exists('user_can_view_full_payment') ? user_can_view_full_payment($uid, (int)$accountDetail['id']) : false;
                    $canEditFull = function_exists('user_can_edit_full_payment') ? user_can_edit_full_payment($uid, (int)$accountDetail['id']) : false;
                }
            } catch (Throwable $e) {
                $accountDetail = null;
                $recentInvoices = [];
                $paymentPreview = [];
                $canViewFull = false;
                $canEditFull = false;
            }
        }
        } else {
        // Get leads based on access level
        $query = '
            SELECT l.*, CONCAT(l.first_name, " ", l.last_name) as full_name,
                   DATEDIFF(NOW(), l.created_at) as days_old,
                   (SELECT COUNT(*) FROM crm_call_logs cl WHERE cl.lead_id = l.id) as call_count,
                   (SELECT COUNT(*) FROM crm_notes n WHERE n.lead_id = l.id) as note_count,
                   (SELECT COUNT(*) FROM crm_tasks t WHERE t.lead_id = l.id AND t.status IN ("pending","in_progress") AND t.due_date <= DATE_ADD(NOW(), INTERVAL 1 DAY)) as tasks_due_soon,
                   (SELECT MAX(cl.call_date) FROM crm_call_logs cl WHERE cl.lead_id = l.id) as last_call_date,
                   CONCAT(a.first_name, " ", a.last_name) as agent_name
            FROM crm_leads l
            LEFT JOIN users a ON a.id = l.assigned_agent_id
            LEFT JOIN crm_lead_agent_archives aa ON aa.lead_id = l.id AND aa.agent_id = ?';
        // Exclude converted leads from lists (they live under Accounts now)
        // Exclude leads archived by current agent (unless viewing archived)
        $query .= ' WHERE l.converted_to_account_id IS NULL AND aa.id IS NULL';
        if (!$hasFullAccess) {
            $query .= ' AND l.assigned_agent_id = ?';
            $stmt = $pdo->prepare($query . ' ORDER BY l.created_at DESC, l.updated_at DESC');
            $stmt->execute([$uid, $uid]);
        } else {
            $stmt = $pdo->prepare($query . ' ORDER BY l.created_at DESC, l.updated_at DESC');
            $stmt->execute([$uid]);
        }
        
        $leads = $stmt->fetchAll();
    }
} catch (Exception $e) {
    flash_set('error', 'Database error: ' . $e->getMessage());
}

?>

<div class="container">
    <?php 
    // Include the enhanced Paladin navigation
    $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
    require_once __DIR__ . '/../../required/sales-acp-nav.php';
    ?>
    
    <?php
    // Include simple breadcrumb navigation
    require_once __DIR__ . '/../../required/sales-acp-breadcrumb-simple.php';
    ?>
    
    <?php if ($leadDetail): ?>
        <!-- Lead Detail View - Premium UI -->
        <?php 
        $isHotLead = in_array($leadDetail['lead_status'], ['drop_successful', 'customer_review', 'customer_follow_up']);
        $isOnFire = $leadDetail['lead_status'] === 'customer_review';
        
        // Calculate lead score (0-100)
        $leadScore = 0;
        $leadScore += min(30, $leadDetail['call_count'] * 10); // Up to 30 points for calls
        $leadScore += min(20, $leadDetail['note_count'] * 5); // Up to 20 points for notes
        $leadScore += ($leadDetail['interest_level'] ?? 5) * 5; // Up to 50 points for interest
        if ($leadDetail['lead_status'] === 'qualified') $leadScore += 10;
        if ($leadDetail['lead_status'] === 'proposal_sent') $leadScore += 15;
        if ($leadDetail['lead_status'] === 'negotiating') $leadScore += 20;
        $leadScore = min(100, $leadScore);
        
        // Get activity data for timeline
        $activities = [];
        try {
            // Get calls
            $stmt = $pdo->prepare('SELECT "call" as type, subject as title, call_date as created_at, call_outcome as status, summary as description FROM crm_call_logs WHERE lead_id = ? ORDER BY call_date DESC LIMIT 10');
            $stmt->execute([$leadDetail['id']]);
            while ($row = $stmt->fetch()) { $activities[] = $row; }
            
            // Get notes  
            $stmt = $pdo->prepare('SELECT "note" as type, note_type as title, created_at, "completed" as status, content as description FROM crm_notes WHERE lead_id = ? ORDER BY created_at DESC LIMIT 10');
            $stmt->execute([$leadDetail['id']]);
            while ($row = $stmt->fetch()) { $activities[] = $row; }
            
            // Get tasks (include id for inline complete action)
            $stmt = $pdo->prepare('SELECT "task" as type, id AS task_id, title, created_at, status, description FROM crm_tasks WHERE lead_id = ? ORDER BY created_at DESC LIMIT 10');
            $stmt->execute([$leadDetail['id']]);
            while ($row = $stmt->fetch()) { $activities[] = $row; }

            // Get demo drop-offs as activities (join to get product_name)
            try {
                $stmt = $pdo->prepare('
                    SELECT 
                        "demo" AS type,
                        CONCAT("Demo: ", COALESCE(dt.product_name, "Sample")) AS title,
                        d.dropoff_date AS created_at,
                        d.demo_status AS status,
                        d.notes AS description,
                        d.id AS dropoff_id,
                        d.follow_up_status,
                        d.follow_up_date,
                        d.follow_up_task_id
                    FROM crm_demo_dropoffs d
                    JOIN demo_dropoff_types dt ON dt.id = d.demo_type_id
                    WHERE d.lead_id = ?
                    ORDER BY d.dropoff_date DESC, d.created_at DESC
                    LIMIT 10
                ');
                $stmt->execute([$leadDetail['id']]);
                while ($row = $stmt->fetch()) { $activities[] = $row; }
            } catch (Throwable $e) { /* ignore if table missing */ }
            
            // Get social media interactions
            try {
                $stmt = $pdo->prepare('
                    SELECT 
                        "social" AS type,
                        CONCAT(UPPER(LEFT(platform, 1)), SUBSTRING(platform, 2), " ", 
                            CASE interaction_type 
                                WHEN "dm" THEN "DM"
                                WHEN "comment" THEN "Comment"
                                WHEN "post" THEN "Post"
                                WHEN "story" THEN "Story"
                                ELSE "Interaction"
                            END,
                            " (", direction, ")"
                        ) AS title,
                        interaction_date AS created_at,
                        direction AS status,
                        content AS description,
                        id AS interaction_id,
                        platform,
                        interaction_type,
                        screenshot_data
                    FROM crm_social_interactions
                    WHERE lead_id = ?
                    ORDER BY interaction_date DESC
                    LIMIT 10
                ');
                $stmt->execute([$leadDetail['id']]);
                while ($row = $stmt->fetch()) { $activities[] = $row; }
            } catch (Throwable $e) { /* ignore if table missing */ }
            
            // Sort by date
            usort($activities, function($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });
            $activities = array_slice($activities, 0, 15);
        } catch (Throwable $e) { $activities = []; }
        // Lead data quality flags
        $emailVal = trim((string)($leadDetail['email'] ?? ''));
        $phoneVal = trim((string)($leadDetail['phone'] ?? ''));
        $emailNeedsUpdate = ($emailVal === '' || stripos($emailVal, '@noemail.invalid') !== false || !preg_match('/.+@.+\..+/i', $emailVal)) && empty($leadDetail['email_not_available']);
        $phoneNeedsUpdate = ($phoneVal === '' || strtolower($phoneVal) === 'not provided') && empty($leadDetail['phone_not_available']);
        
        // Check if this is a valid social media exclusive lead
        $isSocialMediaDetailed = false;
        if (!empty($leadDetail['is_social_media_lead'])) {
            $isSocialMediaDetailed = is_social_media_detailed_lead($pdo, $leadDetail);
        }

        // Create/auto-complete data quality task
        try {
            $taskTitle = 'Complete Lead Details';
            // Find most recent matching cleanup task by title, allowing for null/empty task_type from legacy rows
            $st = $pdo->prepare('SELECT id, status FROM crm_tasks WHERE lead_id = ? AND title = ? AND (task_type = "data_cleanup" OR task_type IS NULL OR task_type = "") ORDER BY id DESC LIMIT 1');
            $st->execute([intval($leadDetail['id']), $taskTitle]);
            $dqTask = $st->fetch();

            if ($emailNeedsUpdate || $phoneNeedsUpdate) {
                if ($dqTask) {
                    if ($dqTask['status'] === 'completed') {
                        // Reopen the existing task rather than creating a new one
                        $pdo->prepare('UPDATE crm_tasks SET status = "pending", completed_at = NULL, updated_at = NOW() WHERE id = ?')->execute([intval($dqTask['id'])]);
                    }
                    // If pending/in_progress, leave as-is
                } else {
                    // Create once if none exists (strong idempotence)
                    $ins = $pdo->prepare('INSERT INTO crm_tasks (agent_id, lead_id, account_id, task_type, title, description, priority, due_date, estimated_duration_minutes, created_at) VALUES (?, ?, NULL, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 2 DAY), 10, NOW())');
                    $desc = 'Lead details appear incomplete/incorrect. Please review and complete email and phone.';
                    $ins->execute([intval($leadDetail['assigned_agent_id'] ?: $uid), intval($leadDetail['id']), 'data_cleanup', $taskTitle, $desc, 'high']);
                }
            } else {
                if ($dqTask && $dqTask['status'] !== 'completed') {
                    $pdo->prepare('UPDATE crm_tasks SET status = "completed", completed_at = NOW(), updated_at = NOW() WHERE id = ?')->execute([intval($dqTask['id'])]);
                }
            }
        } catch (Throwable $e) { /* best-effort */ }

        // UI nudges
        // Encourage sample requests: pulse + button if no demo/sample has been recorded yet
        $needsDemoNudge = (intval($leadDetail['demo_count'] ?? 0) === 0);
        ?>
        
        <?php if (($emailNeedsUpdate || $phoneNeedsUpdate) && !$isSocialMediaDetailed): ?>
        <div class="data-warning" role="status" aria-live="polite" style="margin-bottom:12px; display:flex; align-items:center; gap:8px; justify-content:space-between;">
            <span>⚠️ Lead Details Appear Incomplete or Incorrect, Please Review/Complete.</span>
            <a href="edit-lead.php?id=<?php echo $leadDetail['id']; ?>" class="btn small" style="background:#f59e0b;color:#111827;border:1px solid #f59e0b;">Fix Details Now</a>
        </div>
        <?php endif; ?>
        
        <?php if ($isSocialMediaDetailed): ?>
        <div style="background:linear-gradient(135deg, #e0f2fe, #dbeafe); border:1px solid #0369a1; padding:12px; border-radius:8px; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
            <i class="fa-solid fa-hashtag" style="color:#0369a1; font-size:20px;"></i>
            <div>
                <strong style="color:#0369a1;">Social Media Exclusive Lead</strong>
                <div style="font-size:12px; color:#075985;">This lead is managed primarily through social media and counts as detailed.</div>
            </div>
        </div>
        <?php endif; ?>
        <!-- Premium Header -->
        <div class="lead-header-premium">
            <div class="lead-header-back">
                <a href="leads.php" class="back-button">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M19 12H5M12 19l-7-7 7-7"/>
                    </svg>
                    <span>Back to Leads</span>
                </a>
            </div>
            
            <div class="lead-header-main">
                <div class="lead-header-info">
                    <div class="lead-header-avatar <?php echo $isOnFire ? 'avatar-fire' : ($isHotLead ? 'avatar-hot' : ''); ?>">
                        <?php echo $isOnFire ? '🔥' : ($isHotLead ? '🎯' : '👤'); ?>
                    </div>
                    <div class="lead-header-details">
                        <h1 class="lead-header-title">
                            <?php echo htmlspecialchars($leadDetail['company_name'] ?: $leadDetail['full_name']); ?>
                            <?php if (!empty($leadDetail['is_social_media_lead'])): ?>
                                <span class="admin-badge" style="background:linear-gradient(135deg, #E4405F, #1877F2); color:white; border:none;">
                                    <i class="fa-solid fa-hashtag"></i> Social Media Lead
                                </span>
                            <?php endif; ?>
                            <?php if ($hasFullAccess && !$isAdminSimulation): ?>
                                <span class="admin-badge">Admin View</span>
                            <?php endif; ?>
                        </h1>
                        <div class="lead-header-meta">
                            <span class="meta-item">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 6v6l4 2"/>
                                </svg>
                                <?php echo $leadDetail['days_old']; ?> days in pipeline
                            </span>
                            <span class="meta-item">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                    <polyline points="22 4 12 14.01 9 11.01"/>
                                </svg>
                                <?php echo ucwords(str_replace('_', ' ', $leadDetail['lead_status'])); ?>
                            </span>
                            <?php if ($hasFullAccess && $leadDetail['agent_name']): ?>
                            <span class="meta-item">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                    <circle cx="12" cy="7" r="4"/>
                                </svg>
                                <?php echo htmlspecialchars($leadDetail['agent_name']); ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="lead-header-score">
                    <div class="score-ring">
                        <svg width="80" height="80">
                            <circle cx="40" cy="40" r="36" fill="none" stroke="#E5E7EB" stroke-width="8"/>
                            <circle cx="40" cy="40" r="36" fill="none" stroke="url(#scoreGradient)" stroke-width="8" 
                                stroke-dasharray="<?php echo 226 * ($leadScore/100); ?> 226"
                                stroke-linecap="round"
                                transform="rotate(-90 40 40)"/>
                            <defs>
                                <linearGradient id="scoreGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                                    <stop offset="0%" style="stop-color:#0066FF"/>
                                    <stop offset="100%" style="stop-color:#00C853"/>
                                </linearGradient>
                            </defs>
                        </svg>
                        <div class="score-value"><?php echo $leadScore; ?></div>
                    </div>
                    <div class="score-label">Lead Score</div>
                </div>
            </div>
        </div>
        
        <!-- Archive Lead Button -->
        <div style="position: absolute; top: 20px; right: 20px; z-index: 100;">
            <button class="btn secondary" onclick="showArchiveModal(<?php echo $leadDetail['id']; ?>, '<?php echo htmlspecialchars($leadDetail['company_name'] ?: $leadDetail['full_name'], ENT_QUOTES); ?>')" style="display:flex; align-items:center; gap:6px;">
                <span>🚫</span>
                <span>Archive Lead</span>
            </button>
        </div>
        
        <!-- Status Pipeline -->
        <div class="status-pipeline">
            <div class="pipeline-header">
                <h3>Lead Pipeline Progress</h3>
                <button class="btn-text" onclick="toggleStatusEdit()">Change Status</button>
            </div>
            <div class="pipeline-track">
                <?php
                $pipelineStages = ['new', 'contacted', 'qualified', 'proposal_sent', 'negotiating', 'won'];
                $currentStageIndex = array_search($leadDetail['lead_status'], $pipelineStages);
                if ($currentStageIndex === false) $currentStageIndex = -1;
                foreach ($pipelineStages as $index => $stage):
                    $isActive = $index <= $currentStageIndex;
                    $isCurrent = $stage === $leadDetail['lead_status'];
                ?>
                <div class="pipeline-stage <?php echo $isActive ? 'active' : ''; ?> <?php echo $isCurrent ? 'current' : ''; ?>" 
                     onclick="quickStatusChange('<?php echo $stage; ?>')">
                    <div class="stage-dot">
                        <?php if ($isActive): ?>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                        </svg>
                        <?php endif; ?>
                    </div>
                    <div class="stage-label"><?php echo ucwords(str_replace('_', ' ', $stage)); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="premium-layout">
            <!-- Main Content Column -->
            <div class="content-main">
                <!-- Lead Information Card -->
                <div class="card-premium">
                    <div class="card-header-premium">
                        <h3>Lead Information</h3>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <?php 
                            // Check if address needs parsing
                            $needsAddressParse = !empty($leadDetail['address']) && 
                                                 (!$leadDetail['address_line1'] || !$leadDetail['city'] || !$leadDetail['zip_code']);
                            ?>
                            <?php if ($needsAddressParse): ?>
                                <button onclick="parseLeadAddress(<?php echo $leadDetail['id']; ?>, '<?php echo htmlspecialchars($leadDetail['address'], ENT_QUOTES); ?>')" 
                                        class="btn-icon" 
                                        style="background: #fef3c7; color: #92400e; border: 1px solid #f59e0b; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; display: flex; align-items: center; gap: 4px;"
                                        title="Parse full address into structured fields"
                                        id="parseAddressBtn">
                                    📍 Parse Address
                                </button>
                            <?php endif; ?>
                            <a href="edit-lead.php?id=<?php echo $leadDetail['id']; ?>" class="btn-icon" aria-label="Edit Lead">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                </svg>
                            </a>
                        </div>
                    </div>
                    
                    <div class="info-tabs">
                        <button class="info-tab active" onclick="switchTab(this, 'contact')">Contact</button>
                        <button class="info-tab" onclick="switchTab(this, 'business')">Business</button>
                        <button class="info-tab" onclick="switchTab(this, 'details')">Details</button>
                    </div>
                    
                    <div class="tab-content" id="tab-contact">
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Email</div>
                                <div class="info-value <?php echo $emailNeedsUpdate ? 'needs-update' : ''; ?>"><?php echo htmlspecialchars($leadDetail['email']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Phone</div>
                                <div class="info-value <?php echo $phoneNeedsUpdate ? 'needs-update' : ''; ?>"><?php echo htmlspecialchars($leadDetail['phone'] ?: 'Not provided'); ?></div>
                            </div>
                            <?php if ($leadDetail['website']): ?>
                            <div class="info-item">
                                <div class="info-label">Website</div>
                                <div class="info-value">
                                    <a href="<?php echo htmlspecialchars($leadDetail['website']); ?>" target="_blank" class="link-premium">
                                        <?php echo htmlspecialchars($leadDetail['website']); ?>
                                    </a>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php 
                            // Get social links (helpers already loaded at top)
                            $leadSocialLinks = get_lead_social_links($pdo, $leadDetail['id']);
                            $socialLinkCount = count($leadSocialLinks);
                            ?>
                            <div class="info-item" style="grid-column: 1 / -1;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                    <div class="info-label">Social Media & Links</div>
                                    <?php if ($socialLinkCount < 12): ?>
                                    <button type="button" onclick="showAddSocialModal()" class="btn small" style="background:#059669; font-size:11px; padding:4px 8px;">
                                        <i class="fa-solid fa-plus"></i> Add Link
                                    </button>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if (empty($leadSocialLinks)): ?>
                                <div style="padding:12px; background:#f9fafb; border:1px dashed #d1d5db; border-radius:8px; text-align:center; color:#6b7280; font-size:13px;">
                                    <i class="fa-solid fa-link"></i> No social media links added yet. Click "Add Link" to get started.
                                </div>
                                <?php else: ?>
                                <div style="display:flex; flex-wrap:wrap; gap:8px;">
                                    <?php foreach ($leadSocialLinks as $socialLink): 
                                        $linkConfig = get_social_platform_config($socialLink['platform']);
                                    ?>
                                    <div style="position:relative; display:inline-flex;">
                                        <a href="<?php echo htmlspecialchars($socialLink['url']); ?>" 
                                           target="_blank" 
                                           rel="noopener"
                                           title="<?php echo htmlspecialchars($socialLink['display_label'] ?: $linkConfig['label']); ?>"
                                           style="display:flex; align-items:center; gap:6px; padding:8px 12px; background:white; border:1px solid #e5e7eb; border-radius:8px; color:<?php echo $linkConfig['color']; ?>; text-decoration:none; font-size:13px; transition:all 0.2s;">
                                            <i class="<?php echo $linkConfig['icon']; ?>" style="font-size:16px;"></i>
                                            <span style="color:#1f2937; font-weight:500;"><?php echo htmlspecialchars($socialLink['display_label'] ?: $linkConfig['label']); ?></span>
                                        </a>
                                        <button type="button" 
                                                onclick="quickDeleteSocialLink(<?php echo $socialLink['id']; ?>, event)" 
                                                style="position:absolute; top:-6px; right:-6px; width:20px; height:20px; border-radius:50%; background:#dc2626; color:white; border:1px solid white; font-size:10px; display:none; align-items:center; justify-content:center; cursor:pointer; box-shadow:0 2px 4px rgba(0,0,0,0.2);"
                                                class="social-delete-btn">
                                            <i class="fa-solid fa-times"></i>
                                        </button>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($socialLinkCount >= 12): ?>
                                <div style="font-size:11px; color:#92400e; margin-top:8px;">
                                    <i class="fa-solid fa-info-circle"></i> Maximum 12 links reached
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Address</div>
                                <div class="info-value">
                                    <?php 
                                    $addr = trim($leadDetail['address'] ?: ($leadDetail['address_line1'] . ', ' . $leadDetail['city'] . ', ' . $leadDetail['state'] . ' ' . $leadDetail['zip_code']));
                                    echo htmlspecialchars($addr ?: 'Not provided');
                                    ?>
                                </div>
                                <?php if ($needsAddressParse): ?>
                                <div style="font-size: 11px; color: #f59e0b; margin-top: 4px; display: flex; align-items: center; gap: 4px;">
                                    ⚠️ Address has full text but missing structured fields (street, city, state, zip)
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="tab-content" id="tab-business" style="display:none;">
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Company</div>
                                <div class="info-value"><?php echo htmlspecialchars($leadDetail['company_name'] ?: 'Individual'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Monthly Volume</div>
                                <div class="info-value info-highlight">$<?php echo number_format($leadDetail['estimated_monthly_volume'] ?: 0); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Deal Value</div>
                                <div class="info-value info-highlight">$<?php echo number_format($leadDetail['estimated_deal_value'] ?: 0); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Interest Level</div>
                                <div class="info-value">
                                    <div class="interest-meter">
                                        <div class="interest-bar" style="width: <?php echo ($leadDetail['interest_level'] ?? 5) * 10; ?>%"></div>
                                    </div>
                                    <span><?php echo $leadDetail['interest_level'] ?? 5; ?>/10</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="tab-content" id="tab-details" style="display:none;">
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Lead Source</div>
                                <div class="info-value"><?php echo ucwords(str_replace('_', ' ', $leadDetail['lead_source'])); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Priority</div>
                                <div class="info-value">
                                    <span class="badge-priority badge-<?php echo $leadDetail['lead_priority']; ?>">
                                        <?php echo ucfirst($leadDetail['lead_priority']); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Created</div>
                                <div class="info-value"><?php echo date('M j, Y', strtotime($leadDetail['created_at'])); ?></div>
                            </div>
                            <?php if ($leadDetail['next_follow_up']): ?>
                            <div class="info-item">
                                <div class="info-label">Next Follow-up</div>
                                <div class="info-value info-highlight"><?php echo date('M j, Y g:i A', strtotime($leadDetail['next_follow_up'])); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Leads Near Here (lazy-loaded) -->
                <div class="card subtle" style="margin-top: 16px;">
                    <h4 class="card-sub">📍 Leads Near Here</h4>
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom:8px; flex-wrap:wrap;">
                        <div class="muted small">Expand to find nearby stores and quickly add them to your pipeline.</div>
                        <button id="nearbyToggleBtn" class="btn small" type="button" onclick="toggleNearbySection()">Show map & leads</button>
                    </div>
                    <div id="nearbyContent" style="display:none;">
                        <?php 
                        $addrFull = trim(($leadDetail['address'] ?: ''));
                        if (!$addrFull) {
                            $addrFull = trim(($leadDetail['address_line1'] ?: '') . ', ' . ($leadDetail['city'] ?: '') . ', ' . ($leadDetail['state'] ?: '') . ' ' . ($leadDetail['zip_code'] ?: '') . ', ' . (($leadDetail['country'] ?: 'USA')));
                        }
                        ?>
                        <?php if ($addrFull): ?>
                        <div style="margin-bottom:8px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                            <div class="muted" style="font-size:14px;">📬 <?php echo htmlspecialchars($addrFull); ?></div>
                            <a class="btn small" target="_blank" style="background:#0369a1;" href="https://www.google.com/maps/dir/?api=1&destination=<?php echo urlencode($addrFull); ?>">Open in Google Maps</a>
                        </div>
                        <?php endif; ?>
                        <div style="display:flex; align-items:center; gap:12px; margin-bottom:8px;">
                            <label style="display:flex; align-items:center; gap:8px;">
                                <input type="checkbox" id="toggle_gas" onchange="refreshNearby()"> Include gas stations / convenience
                            </label>
                            <button class="btn small" type="button" onclick="refreshNearby()">Refresh Results</button>
                        </div>
                        <div class="section-label">Suggested Nearby Stores (10 miles)</div>
                        <div style="display:flex; align-items:center; gap:8px; margin:8px 0; flex-wrap:wrap;">
                            <label class="muted small">Radius</label>
                            <select id="nearbyRadius" class="input small" onchange="refreshNearby()">
                                <option value="10">10 miles</option>
                                <option value="20">20 miles</option>
                                <option value="30">30 miles</option>
                            </select>
                            <label class="muted small" style="margin-left:12px;">Sort by</label>
                            <select id="nearbySort" class="input small" onchange="renderNearby()">
                                <option value="distance">Distance</option>
                                <option value="name">Name</option>
                            </select>
                            <label class="muted small" style="margin-left:12px;">Store type</label>
                            <select id="nearbyType" class="input small" onchange="refreshNearby()">
                                <option value="auto">Auto (Product-first)</option>
                                <option value="product_kava">Product/partner</option>
                                <option value="smoke_vape">Smoke/Vape</option>
                                <option value="herbal">Herbal</option>
                                <option value="convenience_gas">Gas/Convenience</option>
                                <option value="all">All</option>
                            </select>
                        </div>
                        <div id="nearbyWrap" style="max-height:280px; overflow:auto; border:1px solid #e5e7eb; border-radius:8px; padding:8px; background:#fff;">
                            <div id="nearbyStatus" class="muted small" style="margin-bottom:8px;">Ready.</div>
                            <div id="nearbyList" class="nearby-list" style="display:grid; gap:8px;"></div>
                        </div>
                        <div class="muted small">Suggestions exclude known stores already in CRM or accounts.</div>
                    </div>
                </div>

                <?php if ($leadDetail['next_follow_up']): ?>
                <div style="margin-top: 12px; padding: 12px; background: #fef3c7; border-radius: 8px;">
                    <strong>⏰ Next Follow-up:</strong> <?php echo date('M j, Y g:i A', strtotime($leadDetail['next_follow_up'])); ?>
                </div>
                <?php endif; ?>
                
                <div class="card subtle" style="margin-top: 16px;" id="leadSampleSuiteCard">
                    <h4 class="card-sub">📬 Sample Suite Automation</h4>
                    <?php if (empty($leadSampleSuite)): ?>
                        <div>
                            <div class="muted small">
                                No sample automations yet. Share the
                                <a href="<?php echo htmlspecialchars($sampleSuitePortalUrl); ?>" target="_blank" rel="noopener">self-serve sample portal</a>
                                so the buyer can choose Blue, Gold, or the premium product format dash.
                            </div>
                            <div style="margin-top:8px;">
                                <button type="button" class="btn small" onclick="quickStartSampleSuite(<?php echo $leadDetail['id']; ?>, 'lead', this)">Send Kickoff Email</button>
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="display:flex;flex-direction:column;gap:10px;">
                            <?php foreach ($leadSampleSuite as $sampleRow):
                                $suiteState = $sampleRow['suite_status'] ?: 'not_started';
                                $enrollmentId = intval($sampleRow['enrollment_id']);
                                $currentStepName = $sampleRow['current_step_key'] ? sample_suite_label_for_ui($sampleRow['current_step_key'], $sampleSuiteStepLabels) : null;
                                $stepLabel = $sampleRow['current_step_number']
                                    ? 'Step ' . intval($sampleRow['current_step_number']) . ' — ' . $currentStepName
                                    : 'Not started';
                                $canStart = !$enrollmentId && in_array($sampleRow['sample_status'], $sampleSuiteStartEligibleStatuses, true);
                                $attemptCount = intval($sampleRow['attempt_count'] ?? 0);
                                $maxAttempts = intval($sampleRow['max_attempts'] ?? 10);
                                $behaviorState = json_decode($sampleRow['behavior_state'] ?? '[]', true);
                                $opens = intval($behaviorState['opens'] ?? 0);
                                $clicks = intval($behaviorState['clicks'] ?? 0);
                                $saleAt = $sampleRow['sale_detected_at'] ?? null;
                                $unsubscribedAt = $sampleRow['unsubscribe_at'] ?? null;
                                $canMarkSale = $enrollmentId && !$saleAt;
                            ?>
                            <div class="list-row" style="align-items:center;">
                                <div class="list-main">
                                    <div class="list-title">Request #<?php echo $sampleRow['id']; ?></div>
                                    <div class="list-sub muted">
                                        Sample: <?php echo ucfirst($sampleRow['sample_status']); ?>
                                        <span class="dot">•</span>
                                        Suite: <?php echo ucfirst(str_replace('_',' ', $suiteState)); ?>
                                        <?php if ($enrollmentId): ?>
                                            <span class="dot">•</span><?php echo htmlspecialchars($stepLabel); ?>
                                            <?php if (!empty($sampleRow['next_step_scheduled_for'])): ?>
                                                <span class="dot">•</span>Next send <?php echo date('M j, g:i A', strtotime($sampleRow['next_step_scheduled_for'])); ?>
                                            <?php endif; ?>
                                            <span class="dot">•</span>Attempts <?php echo $attemptCount; ?>/<?php echo $maxAttempts; ?>
                                            <?php if ($opens || $clicks): ?>
                                                <span class="dot">•</span><?php echo $opens; ?> opens / <?php echo $clicks; ?> clicks
                                            <?php endif; ?>
                                            <?php if ($saleAt): ?>
                                                <span class="dot">•</span><span class="chip chip-success">Sale logged</span>
                                            <?php elseif ($unsubscribedAt): ?>
                                                <span class="dot">•</span><span class="chip chip-warning">Unsubscribed</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="list-aside" style="display:flex;gap:8px;flex-wrap:wrap;">
                                    <a class="btn small tertiary" href="<?php echo htmlspecialchars($sampleSuitePortalUrl); ?>" target="_blank" rel="noopener">Portal Link</a>
                                    <?php if ($enrollmentId): ?>
                                        <button type="button" class="btn small tertiary" onclick="openSuiteTimeline(<?php echo $sampleRow['id']; ?>)">View Activity</button>
                                    <?php endif; ?>
                                    <?php if ($canStart): ?>
                                        <button type="button" class="btn small" onclick="startLeadSampleSuite(<?php echo $sampleRow['id']; ?>, this)">Start Suite</button>
                                    <?php elseif (!$enrollmentId): ?>
                                        <button type="button" class="btn small secondary" disabled>Waiting for approval</button>
                                    <?php else: ?>
                                        <button type="button" class="btn small secondary" onclick="cancelLeadSampleSuite(<?php echo $enrollmentId; ?>, this)">Pause Suite</button>
                                        <button type="button" class="btn small tertiary" onclick="resetSampleSuite(<?php echo $sampleRow['id']; ?>, this)">Reset Suite</button>
                                        <?php if ($canMarkSale): ?>
                                            <button type="button" class="btn small success" onclick="markSampleSuiteSale(<?php echo $sampleRow['id']; ?>, this)">Mark Sale</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <div class="muted tiny">Manage full automation in <a href="request-sample.php?lead_id=<?php echo $leadDetail['id']; ?>">Request Sample</a>.</div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php
                // Show demo drop-offs if any (defensive guard)
                $demos = [];
                try { $demos = get_lead_demos($pdo, $leadDetail['id']); } catch (Throwable $e) { $demos = []; }
                if (!empty($demos)):
                ?>
                <div class="card subtle" style="margin-top: 16px;">
                    <h4 class="card-sub">🧪 Demo Drop-offs</h4>
                    <?php foreach ($demos as $demo): ?>
                    <div class="list-row">
                        <div class="list-main">
                            <div class="list-title"><?php echo htmlspecialchars($demo['product_name']); ?></div>
                            <div class="list-sub muted">
                                Dropped: <?php echo date('M j, Y', strtotime($demo['dropoff_date'])); ?>
                                <?php if ($demo['follow_up_status'] === 'pending'): ?>
                                    <span class="dot">•</span> Follow-up: <?php echo date('M j', strtotime($demo['follow_up_date'])); ?>
                                    <?php if ($demo['follow_up_date'] < date('Y-m-d')): ?><span class="chip chip-danger">Overdue</span><?php endif; ?>
                                <?php elseif ($demo['follow_up_status'] === 'completed'): ?>
                                    <span class="dot">•</span> <span class="chip chip-success">Follow-up completed</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($demo['notes'])): ?>
                            <div class="list-notes"><?php echo htmlspecialchars($demo['notes']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="list-aside">
                            <?php if ($demo['demo_status'] === 'confirmed'): ?><span class="chip chip-success">✓ Confirmed</span><?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <div class="list-actions">
                        <?php if (count($demos) < 2): ?>
                            <a href="dropoff.php?lead_id=<?php echo $leadDetail['id']; ?>" class="btn small primary">Add Another Demo</a>
                        <?php else: ?>
                            <span class="muted small">Maximum demos reached (2 per store)</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php
                // Contacts panel
                $contacts = [];
                $contactOfLead = null;
                try {
                    if (!empty($leadDetail['converted_to_contact_id'])) {
                        $stmt = $pdo->prepare('SELECT * FROM crm_contacts WHERE id = ?');
                        $stmt->execute([intval($leadDetail['converted_to_contact_id'])]);
                        $contactOfLead = $stmt->fetch();
                    }
                    if (!empty($leadDetail['converted_to_account_id'])) {
                        $stmt = $pdo->prepare('SELECT c.*, ac.is_primary, ac.role FROM account_contacts ac JOIN crm_contacts c ON c.id = ac.contact_id WHERE ac.wholesale_account_id = ? ORDER BY ac.is_primary DESC, c.last_name, c.first_name');
                        $stmt->execute([intval($leadDetail['converted_to_account_id'])]);
                        $contacts = $stmt->fetchAll();
                    }
                } catch (Throwable $e) { $contacts = []; }
                ?>
                <?php if (!empty($leadDetail['converted_to_account_id'])): ?>
                <div class="card subtle" style="margin-top: 16px;">
                    <h4 class="card-sub">👤 Contacts</h4>
                    <?php if (empty($contacts) && !$contactOfLead): ?>
                        <div class="muted">No contacts linked yet.</div>
                    <?php else: ?>
                        <div style="display:grid; gap:8px;">
                            <?php if ($contactOfLead): ?>
                            <div class="list-row">
                                <div class="list-main">
                                    <div class="list-title"><?php echo htmlspecialchars($contactOfLead['first_name'].' '.$contactOfLead['last_name']); ?> <span class="chip chip-status">from lead</span></div>
                                    <div class="list-sub muted"><?php echo htmlspecialchars($contactOfLead['email'] ?: ''); ?> <?php echo $contactOfLead['phone'] ? '• '.htmlspecialchars($contactOfLead['phone']) : ''; ?></div>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php foreach ($contacts as $c): ?>
                            <div class="list-row">
                                <div class="list-main">
                                    <div class="list-title"><?php echo htmlspecialchars($c['first_name'].' '.$c['last_name']); ?> <?php if ($c['is_primary']): ?><span class="chip chip-success">Primary</span><?php endif; ?></div>
                                    <div class="list-sub muted"><?php echo htmlspecialchars($c['email'] ?: ''); ?> <?php echo $c['phone'] ? '• '.htmlspecialchars($c['phone']) : ''; ?><?php if ($c['role']): ?> • <?php echo htmlspecialchars($c['role']); ?><?php endif; ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
                        <?php if (current_admin_id()): ?>
                        <a class="btn small secondary" href="<?php echo outbound_path('/admin/wholesale-accounts.php'); ?>?view=detail&id=<?php echo (int)$leadDetail['converted_to_account_id']; ?>">Manage Account</a>
                        <?php endif; ?>
                        <?php if ($isRealAdmin || (!$isRealAdmin && $uid === intval($leadDetail['assigned_agent_id']))): ?>
                        <a class="btn small" style="background:#0369a1;" href="invoice.php?lead_id=<?php echo $leadDetail['id']; ?>">Create Invoice</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Email Composer Tab (Leads) - Only show if email available -->
                <?php if (empty($leadDetail['email_not_available'])): ?>
                <?php
                $emailComposerContext = [
                    'type' => 'lead',
                    'id' => $leadDetail['id'],
                    'recipient_email' => $leadDetail['email'] ?? '',
                    'recipient_name' => trim(($leadDetail['first_name'] ?? '') . ' ' . ($leadDetail['last_name'] ?? '')),
                    'company_name' => $leadDetail['company_name'] ?? ''
                ];
                require_once __DIR__ . '/../../required/email-composer-tab.php';
                ?>
                <?php else: ?>
                <!-- Email not available message -->
                <div style="padding:32px; text-align:center; background:#f9fafb; border-radius:8px; margin:20px 0;">
                    <i class="fa-solid fa-envelope-circle-check" style="font-size:48px; color:#d1d5db; margin-bottom:12px;"></i>
                    <div style="color:#6b7280; font-size:14px;">Email composer unavailable for this lead.</div>
                    <div style="color:#9ca3af; font-size:12px; margin-top:4px;">Lead marked as "Email Not Available"</div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Sidebar Column -->
            <div class="content-sidebar">
                <!-- Activity Timeline -->
                <div class="card-premium activity-card">
                    <div class="card-header-premium">
                        <h3>Activity Timeline</h3>
                        <div class="header-actions-group">
                            <button class="btn-add-activity <?php echo !empty($needsDemoNudge) ? 'btn-nudge' : ''; ?>" onclick="toggleActivityMenu()" aria-label="Add Activity">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <line x1="12" y1="8" x2="12" y2="16"/>
                                    <line x1="8" y1="12" x2="16" y2="12"/>
                                </svg>
                            </button>
                            <div class="activity-add-menu" id="activityAddMenu">
                                <?php if (empty($leadDetail['phone_not_available'])): ?>
                                <button onclick="openModal('call', <?php echo $leadDetail['id']; ?>); toggleActivityMenu();">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72"/>
                                    </svg>
                                    Log Call
                                </button>
                                <?php endif; ?>
                                <button onclick="openModal('note', <?php echo $leadDetail['id']; ?>); toggleActivityMenu();">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                        <polyline points="14 2 14 8 20 8"/>
                                    </svg>
                                    Add Note
                                </button>
                                <button onclick="openModal('task', <?php echo $leadDetail['id']; ?>); toggleActivityMenu();">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                        <polyline points="22 4 12 14.01 9 11.01"/>
                                    </svg>
                                    Create Task
                                </button>
                                <button class="menu-accent" onclick="window.location.href='invoice.php?lead_id=<?php echo $leadDetail['id']; ?>'; toggleActivityMenu();">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                        <polyline points="14 2 14 8 20 8"/>
                                    </svg>
                                    Create Invoice
                                </button>
                                <button onclick="window.location.href='request-sample.php?lead_id=<?php echo $leadDetail['id']; ?>'; toggleActivityMenu();" class="<?php echo intval($leadDetail['demo_count'] ?? 0) === 0 ? 'highlight-recommend' : ''; ?>">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M9 2v4l-5 9a5 5 0 0 0 5 5h6a5 5 0 0 0 5-5l-5-9V2"/>
                                        <path d="M7 16h10"/>
                                    </svg>
                                    Request Sample
                                </button>
                                <?php if (current_admin_id() || ($uid === intval($leadDetail['assigned_agent_id']))): ?>
                                <button onclick="acpConvertToAccount(<?php echo $leadDetail['id']; ?>); toggleActivityMenu();">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                        <circle cx="12" cy="7" r="4"/>
                                    </svg>
                                    Convert to Account
                                </button>
                                <?php endif; ?>
                            </div>
                            <button class="btn-text btn-view-all" onclick="toggleViewAllMenu()">
                                View All
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-left:4px;">
                                    <polyline points="6 9 12 15 18 9"/>
                                </svg>
                            </button>
                            <div class="view-all-menu" id="viewAllMenu">
                                <a href="calls.php?lead_id=<?php echo $leadDetail['id']; ?>">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07"/>
                                    </svg>
                                    All Calls
                                </a>
                                <a href="notes.php?lead_id=<?php echo $leadDetail['id']; ?>">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                    </svg>
                                    All Notes
                                </a>
                                <a href="tasks.php?lead_id=<?php echo $leadDetail['id']; ?>">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                    </svg>
                                    All Tasks
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Activity Tabs -->
                    <div class="activity-tabs">
                        <button class="activity-tab active" onclick="filterActivities('all', this)">
                            All <span class="tab-count"><?php echo count($activities); ?></span>
                        </button>
                        <button class="activity-tab" onclick="filterActivities('call', this)">
                            Calls <span class="tab-count"><?php echo $leadDetail['call_count']; ?></span>
                        </button>
                        <button class="activity-tab" onclick="filterActivities('note', this)">
                            Notes <span class="tab-count"><?php echo $leadDetail['note_count']; ?></span>
                        </button>
                        <button class="activity-tab" onclick="filterActivities('task', this)">
                            Tasks <span class="tab-count"><?php echo $leadDetail['pending_tasks'] ?? 0; ?></span>
                        </button>
                        <button class="activity-tab" onclick="filterActivities('demo', this)">
                            Demos <span class="tab-count"><?php echo intval($leadDetail['demo_count'] ?? 0); ?></span>
                        </button>
                    </div>
                    
                    <div class="timeline" id="activityTimeline">
                        <?php if (empty($activities)): ?>
                        <div class="timeline-empty">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#CBD5E0" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 6v6l4 2"/>
                            </svg>
                            <p>No activities yet</p>
                            <p class="text-muted">Click the + button above to get started</p>
                        </div>
                        <?php else: ?>
                        <?php foreach (array_slice($activities, 0, 10) as $activity): ?>
                        <div class="timeline-item" data-type="<?php echo $activity['type']; ?>" <?php if(($activity['type']??'')==='demo' && !empty($activity['dropoff_id'])): ?>data-dropoff-id="<?php echo (int)$activity['dropoff_id']; ?>"<?php endif; ?> <?php if(($activity['type']??'')==='task' && !empty($activity['task_id'])): ?>data-task-id="<?php echo (int)$activity['task_id']; ?>"<?php endif; ?>>
                            <div class="timeline-marker <?php echo $activity['type']; ?>">
                                <?php 
                                if ($activity['type'] === 'social') {
                                    $platformConfig = get_social_platform_config($activity['platform'] ?? 'custom');
                                    echo '<i class="' . $platformConfig['icon'] . '" style="color:' . $platformConfig['color'] . ';"></i>';
                                } else {
                                    echo match($activity['type']) {
                                        'call' => '📞',
                                        'note' => '📝',
                                        'task' => '✓',
                                        'demo' => '🧪',
                                        default => '•'
                                    };
                                }
                                ?>
                            </div>
                            <div class="timeline-content">
                                <div class="timeline-title"><?php echo htmlspecialchars($activity['title'] ?: ucfirst($activity['type'])); ?></div>
                                <?php if ($activity['description']): ?>
                                <div class="timeline-desc"><?php echo nl2br(htmlspecialchars(substr($activity['description'], 0, 100) . (strlen($activity['description']) > 100 ? '...' : ''))); ?></div>
                                <?php endif; ?>
                                <?php if ($activity['type'] === 'social' && !empty($activity['screenshot_data'])): ?>
                                <div style="margin-top:8px;">
                                    <img src="<?php echo htmlspecialchars($activity['screenshot_data']); ?>" 
                                         alt="Screenshot" 
                                         style="max-width:200px; max-height:150px; border-radius:8px; border:1px solid #e5e7eb; cursor:pointer;"
                                         onclick="showScreenshotModal(this.src)">
                                </div>
                                <?php endif; ?>
                                <div class="timeline-meta">
                                    <span><?php echo date('M j, g:i A', strtotime($activity['created_at'])); ?></span>
                                    <?php if ($activity['status']): ?>
                                    <span class="badge-mini badge-<?php echo str_replace('_', '-', $activity['status']); ?>"><?php echo htmlspecialchars($activity['status']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($activity['type'] === 'task' && ($activity['status'] ?? '') !== 'completed' && !empty($activity['task_id'])): ?>
                                    <button class="btn small" style="margin-left:auto;background:#059669;" onclick="completeTimelineTask(<?php echo (int)$activity['task_id']; ?>, this)">Mark Complete</button>
                                    <?php elseif ($activity['type'] === 'demo' && ($activity['follow_up_status'] ?? '') !== 'completed' && !empty($activity['dropoff_id'])): ?>
                                    <button class="btn small" style="margin-left:auto;background:#059669;" onclick="openFollowUpModal(<?php echo (int)$activity['dropoff_id']; ?>, <?php echo (int)($activity['follow_up_task_id'] ?? 0); ?>)">Complete Follow-up</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Activity Stats -->
                    <div class="activity-stats">
                        <div class="stat-item clickable" onclick="filterActivities('call', document.querySelector('.activity-tab'))">
                            <div class="stat-value"><?php echo $leadDetail['call_count']; ?></div>
                            <div class="stat-label">Calls</div>
                        </div>
                        <div class="stat-item clickable" onclick="filterActivities('note', document.querySelectorAll('.activity-tab')[2])">
                            <div class="stat-value"><?php echo $leadDetail['note_count']; ?></div>
                            <div class="stat-label">Notes</div>
                        </div>
                        <div class="stat-item clickable" onclick="filterActivities('task', document.querySelectorAll('.activity-tab')[3])">
                            <div class="stat-value"><?php echo $leadDetail['pending_tasks'] ?? 0; ?></div>
                            <div class="stat-label">Tasks</div>
                        </div>
                    </div>
                </div>
                
                <!-- Admin Actions -->
                <?php if (current_admin_id()): ?>
                <div class="card-premium">
                    <h4 class="card-title-premium">Admin Actions</h4>
                    <form method="post" action="leads.php?id=<?php echo $leadDetail['id']; ?>" 
                          onsubmit="return handleConversion(event)">
                        <input type="hidden" name="action" value="convert_to_account">
                        <button type="submit" class="btn-premium btn-success">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                <circle cx="12" cy="7" r="4"/>
                            </svg>
                            Convert to Account
                        </button>
                    </form>
                </div>
                <?php endif; ?>
                
                <!-- Workflow Configuration -->
                <div class="card-premium">
                    <h4 class="card-title-premium">🔄 Follow-up Automation</h4>
                    <button type="button" class="btn-premium" style="width:100%;" 
                            onclick="WorkflowEntityConfig.show('lead', <?php echo $leadDetail['id']; ?>, '<?php echo htmlspecialchars(addslashes($leadDetail['company_name'] ?? trim(($leadDetail['first_name'] ?? '') . ' ' . ($leadDetail['last_name'] ?? '')))); ?>')">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M12 6v6l4 2"/>
                        </svg>
                        Configure Workflows
                    </button>
                    <p style="margin:12px 0 0;font-size:12px;color:#64748b;text-align:center;">
                        Set up automated follow-up emails for this lead
                    </p>
                </div>
                
                <?php if (!empty($accountDetail)): ?>
                <div class="card">
                    <h4 class="card-title">🏦 Account & Billing</h4>
                    <div class="kv" style="margin-bottom:12px;">
                        <span class="k">Account</span><span class="v"><?php echo htmlspecialchars($accountDetail['business_name']); ?></span>
                        <span class="k">Email</span><span class="v"><?php echo htmlspecialchars($accountDetail['email']); ?></span>
                        <span class="k">Status</span><span class="v"><?php echo $accountDetail['approved'] ? 'Approved' : 'Pending'; ?></span>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px;">
                        <a class="btn secondary small" href="invoices.php?account_id=<?php echo $accountDetail['id']; ?>">View Invoices</a>
                        <a class="btn small primary" href="invoice.php?account_id=<?php echo $accountDetail['id']; ?>">Create Invoice</a>
                        <?php if (current_admin_id()): ?>
                        <a class="btn small" href="<?php echo outbound_path('/admin/wholesale-accounts.php'); ?>?view=detail&id=<?php echo $accountDetail['id']; ?>#payment">Admin: Payment Methods</a>
                        <a class="btn small" href="<?php echo outbound_path('/admin/wholesale-accounts.php'); ?>?view=detail&id=<?php echo $accountDetail['id']; ?>">Admin: Account</a>
                        <?php endif; ?>
                    </div>
                    <div style="border-top:1px solid #e5e7eb; padding-top:8px;">
                        <div class="section-label" style="margin-bottom:6px;">Payment Methods</div>
                        <?php if (empty($paymentPreview)): ?>
                            <div class="muted small">No payment methods on file.</div>
                        <?php else: ?>
                            <div style="display:grid; gap:8px; margin-bottom:12px;">
                                <?php foreach ($paymentPreview as $pm): ?>
                                <div style="display:flex; justify-content:space-between; align-items:center; padding:8px; border:1px solid #e5e7eb; border-radius:6px;">
                                    <div>
                                        <div style="font-weight:600;">
                                            <?php if ($pm['type'] === 'credit_card'): ?>
                                                <?php if ($canViewFull && !empty($pm['card_enc']) && function_exists('secure_decrypt')): ?>
                                                    <?php $full = secure_decrypt($pm['card_enc']); ?>
                                                    <?php echo htmlspecialchars($pm['card_brand'] ?: 'Card'); ?> — <?php echo htmlspecialchars($full ?: ('•••• ' . ($pm['last_four'] ?? ''))); ?>
                                                <?php else: ?>
                                                    <?php echo htmlspecialchars($pm['card_brand'] ?: 'Card'); ?> — •••• <?php echo htmlspecialchars($pm['last_four'] ?: ''); ?>
                                                <?php endif; ?>
                                                <?php if ($pm['exp_month'] && $pm['exp_year']): ?>
                                                    <span class="muted small">exp <?php echo htmlspecialchars($pm['exp_month'] . '/' . substr($pm['exp_year'], -2)); ?></span>
                                                <?php endif; ?>
                                            <?php elseif ($pm['type'] === 'ach'): ?>
                                                Bank (<?php echo htmlspecialchars($pm['bank_name'] ?: 'ACH'); ?>) •••• <?php echo htmlspecialchars($pm['last_four'] ?: ''); ?>
                                            <?php else: ?>
                                                <?php echo ucwords(str_replace('_',' ', $pm['type'])); ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($pm['is_default']): ?><div class="muted small">Default</div><?php endif; ?>
                                    </div>
                                    <div style="display:flex; gap:8px;">
                                        <?php if ($canEditFull): ?>
                                            <a class="btn small secondary" href="<?php echo outbound_path('/admin/wholesale-accounts.php'); ?>?view=detail&id=<?php echo (int)$accountDetail['id']; ?>#payment" target="_blank">Manage</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="section-label" style="margin-bottom:6px;">Recent Invoices</div>
                        <?php if (empty($recentInvoices)): ?>
                            <div class="muted small">No invoices yet.</div>
                        <?php else: ?>
                            <div style="display:grid; gap:8px;">
                                <?php foreach ($recentInvoices as $inv): ?>
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <div>
                                        <strong><?php echo htmlspecialchars($inv['order_number'] ?: ('#' . str_pad($inv['id'],6,'0',STR_PAD_LEFT))); ?></strong>
                                        <span class="muted small"> — <?php echo ucfirst($inv['status']); ?></span>
                                        <div class="muted small"><?php echo date('M j, Y', strtotime($inv['created_at'])); ?></div>
                                    </div>
                                    <div><strong>$<?php echo number_format($inv['total'], 2); ?></strong></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        </div> <!-- End premium-layout -->

    <?php else: ?>
        <!-- Leads List View -->
        <div class="page-header">
            <div>
                <?php if ($hasFullAccess && !$isAdminSimulation): ?><span class="badge-info">🔧 Admin Access</span><?php endif; ?>
                <h1 class="page-title">📋 <?php echo $hasFullAccess && !$isAdminSimulation ? 'All Leads' : 'My Leads'; ?></h1>
                <div class="page-subtitle muted"><?php echo count($leads); ?> leads <?php echo $hasFullAccess && !$isAdminSimulation ? 'total' : 'assigned'; ?></div>
            </div>
            <div class="header-actions">
                <a href="index.php" class="btn secondary">← Back to CRM</a>
                <button onclick="showAddModal()" class="btn success">+ Add New Lead</button>
            </div>
        </div>

        <?php if (empty($leads)): ?>
            <div class="card" style="text-align: center; padding: 48px;">
                <div style="font-size: 64px; margin-bottom: 16px;">🎯</div>
                <h2 style="color: #6b7280;">No Leads Assigned Yet</h2>
                <p style="color: #9ca3af; margin-bottom: 24px;">Start building your pipeline by adding your first lead.</p>
                <button onclick="showAddModal()" class="btn" style="background: #059669;">Add Your First Lead</button>
            </div>
        <?php else: ?>
            <div class="card">
                <div style="display:flex; gap:8px; align-items:center; margin-bottom:8px; flex-wrap:wrap;">
                    <input id="leadSearch" type="text" class="input" placeholder="Search by name, company, email or phone" style="flex:1; min-width:220px; padding:8px; border:1px solid #e5e7eb; border-radius:6px;">
                    <select id="leadFilterStatus" class="input small" style="padding:8px;">
                        <option value="">All Statuses</option>
                        <?php foreach (['new','contacted','qualified','proposal_sent','negotiating','won','lost','dormant'] as $st): ?>
                        <option value="<?php echo $st; ?>"><?php echo ucwords(str_replace('_',' ', $st)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="leadFilterPriority" class="input small" style="padding:8px;">
                        <option value="">All Priorities</option>
                        <?php foreach (['low','medium','high','urgent'] as $pr): ?>
                        <option value="<?php echo $pr; ?>"><?php echo ucfirst($pr); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label style="display:flex; align-items:center; gap:6px; padding:8px; cursor:pointer; white-space:nowrap;">
                        <input type="checkbox" id="showArchivedToggle" style="cursor:pointer;">
                        <span style="font-size:14px;">Show Archived</span>
                    </label>
                </div>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
                                <th style="padding: 12px 8px; text-align: left; font-weight: 600;">Store</th>
                                <th style="padding: 12px 8px; text-align: left; font-weight: 600;">Address</th>
                                <th style="padding: 12px 8px; text-align: left; font-weight: 600;">Contact</th>
                                <th style="padding: 12px 8px; text-align: left; font-weight: 600;">Demo</th>
                                <th style="padding: 12px 8px; text-align: left; font-weight: 600;">Status</th>
                                <th style="padding: 12px 8px; text-align: left; font-weight: 600;">Priority</th>
                                <?php if ($hasFullAccess && !$isAdminSimulation): ?>
                                    <th style="padding: 12px 8px; text-align: left; font-weight: 600;">Agent</th>
                                <?php endif; ?>
                                <th style="padding: 12px 8px; text-align: left; font-weight: 600;">Activity</th>
                                <th style="padding: 12px 8px; text-align: left; font-weight: 600;">Age</th>
                                <th style="padding: 12px 8px; text-align: center; font-weight: 600; width: 50px;" title="Archive">👁️</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($leads as $lead): ?>
                            <?php 
                            $isHotRow = in_array($lead['lead_status'], ['drop_successful', 'customer_review', 'customer_follow_up']);
                            $isFireRow = $lead['lead_status'] === 'customer_review';
                            ?>
                            <tr class="row-highlight <?php echo $isFireRow ? 'row-hot' : ($isHotRow ? 'row-warm' : ''); ?>" data-status="<?php echo htmlspecialchars($lead['lead_status']); ?>" data-priority="<?php echo htmlspecialchars($lead['lead_priority']); ?>" data-search="<?php echo htmlspecialchars(strtolower(($lead['full_name'].' '.$lead['company_name'].' '.$lead['email'].' '.$lead['phone'].' '.($lead['address'] ?? '').' '.($lead['address_line1'] ?? '').' '.($lead['city'] ?? '').' '.($lead['state'] ?? '').' '.($lead['zip_code'] ?? '')))); ?>" data-due-soon="<?php echo intval($lead['tasks_due_soon'] ?? 0) > 0 ? 1 : 0; ?>">
                                <td>
                                    <div class="lead-primary">
                                        <a href="leads.php?id=<?php echo $lead['id']; ?>" class="link-plain"><?php echo htmlspecialchars($lead['company_name'] ?: $lead['full_name']); ?></a>
                                        <?php if ($isFireRow): ?><span class="hot-indicator">🔥</span><?php elseif ($lead['lead_status'] === 'drop_successful'): ?><span>🎉</span><?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php 
                                    $addr = trim($lead['address'] ?: trim(($lead['address_line1'] ?: '').', '.($lead['city'] ?: '').', '.($lead['state'] ?: '').' '.($lead['zip_code'] ?: '')));
                                    echo $addr !== '' ? htmlspecialchars($addr) : '<span class="muted">—</span>';
                                    ?>
                                </td>
                                <td>
                                    <div style="font-weight:600; color:#1f2937;"><?php echo htmlspecialchars($lead['full_name']); ?></div>
                                    <div class="muted small"><?php echo htmlspecialchars($lead['email']); ?><?php if ($lead['phone']): ?> • <?php echo htmlspecialchars($lead['phone']); ?><?php endif; ?></div>
                                </td>
                                <td>
                                    <?php
                                    // Show a compact demo indicator using the follow-up view if available
                                    try {
                                        $stmt = $pdo->prepare('SELECT follow_up_status, follow_up_date FROM crm_demo_dropoffs WHERE lead_id = ? ORDER BY updated_at DESC LIMIT 1');
                                        $stmt->execute([intval($lead['id'])]);
                                        $demoRow = $stmt->fetch();
                                    } catch (Throwable $e) { $demoRow = null; }

                                    if ($demoRow) {
                                        $chip = ($demoRow['follow_up_status'] === 'completed')
                                            ? '<span class="chip chip-success">✓</span>'
                                            : '<span class="chip chip-status">⏰</span>';
                                        echo $chip;
                                        if (!empty($demoRow['follow_up_date'])) {
                                            echo ' <span class="muted small">'.date('M j', strtotime($demoRow['follow_up_date'])).'</span>';
                                        } else {
                                            echo '<span class="muted small">—</span>';
                                        }
                                    } else {
                                        echo '<span class="muted small">—</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <span class="chip chip-status"><?php echo ucwords($lead['lead_status']); ?></span>
                                </td>
                                <td>
                                    <span class="chip chip-priority-<?php echo $lead['lead_priority']; ?>"><?php echo ucfirst($lead['lead_priority']); ?></span>
                                </td>
                                <?php if ($hasFullAccess && !$isAdminSimulation): ?>
                                <td class="muted">
                                    <?php echo $lead['agent_name'] ? htmlspecialchars($lead['agent_name']) : '<span class="red">Unassigned</span>'; ?>
                                </td>
                                <?php endif; ?>
                                <td class="muted small"><?php echo $lead['call_count']; ?> calls<?php if ($lead['last_call_date']): ?> • Last: <?php echo date('M j', strtotime($lead['last_call_date'])); ?><?php endif; ?></td>
                                <td class="muted small"><?php echo $lead['days_old']; ?> days</td>
                                <td style="text-align: center;">
                                    <button class="archive-icon-btn" data-lead-id="<?php echo $lead['id']; ?>" data-lead-name="<?php echo htmlspecialchars($lead['company_name'] ?: $lead['full_name']); ?>" title="Archive this lead" style="background:none; border:none; cursor:pointer; font-size:18px; opacity:0.5; transition:opacity 0.2s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.5'">
                                        🚫
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <script>
            (function(){
                const searchInput = document.getElementById('leadSearch');
                const fStatus = document.getElementById('leadFilterStatus');
                const fPriority = document.getElementById('leadFilterPriority');
                const rows = Array.from(document.querySelectorAll('table tbody tr'));
                function applyFilters(){
                    const q = (searchInput.value||'').trim().toLowerCase();
                    const st = (fStatus.value||'').trim();
                    const pr = (fPriority.value||'').trim();
                    rows.forEach(tr => {
                        const hs = tr.getAttribute('data-status') || '';
                        const hp = tr.getAttribute('data-priority') || '';
                        const txt = tr.getAttribute('data-search') || '';
                        let show = true;
                        if (st && hs !== st) show = false;
                        if (pr && hp !== pr) show = false;
                        if (q && txt.indexOf(q) === -1) show = false;
                        tr.style.display = show ? '' : 'none';
                    });
                }
                [searchInput, fStatus, fPriority].forEach(el => el && el.addEventListener('input', applyFilters));
                [fStatus, fPriority].forEach(el => el && el.addEventListener('change', applyFilters));
                // Initial sort: bubble due-today/tomorrow tasks to top (if present in row dataset)
                try {
                    rows.sort((a,b) => {
                        const ad = parseInt(a.getAttribute('data-due-soon')||'0',10);
                        const bd = parseInt(b.getAttribute('data-due-soon')||'0',10);
                        return bd - ad; // show due-soon first
                    }).forEach(tr => tr.parentNode.appendChild(tr));
                } catch(e) {}
                
                // Archive lead functionality
                document.querySelectorAll('.archive-icon-btn').forEach(btn => {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        const leadId = this.dataset.leadId;
                        const leadName = this.dataset.leadName;
                        showArchiveModal(leadId, leadName);
                    });
                });
                
                // Show archived toggle
                const showArchivedToggle = document.getElementById('showArchivedToggle');
                if (showArchivedToggle) {
                    showArchivedToggle.addEventListener('change', function() {
                        if (this.checked) {
                            loadArchivedLeads();
                        } else {
                            clearArchivedLeads();
                        }
                    });
                }
            })();
            
            // Archive modal function
            function showArchiveModal(leadId, leadName) {
                const overlay = document.createElement('div');
                overlay.id = 'archiveModalOverlay';
                overlay.style.cssText = 'position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:9999;';
                
                const modal = document.createElement('div');
                modal.style.cssText = 'background:#fff; border-radius:8px; padding:24px; max-width:480px; width:90%; box-shadow:0 4px 12px rgba(0,0,0,0.15);';
                
                modal.innerHTML = `
                    <h3 style="margin:0 0 16px 0; color:#1f2937;">Archive Lead</h3>
                    <p style="color:#6b7280; margin-bottom:16px;">Archive <strong>${leadName}</strong> from your lead list?</p>
                    <label style="display:block; margin-bottom:8px; color:#374151; font-weight:500;">Optional Note:</label>
                    <textarea id="archiveNote" placeholder="Why are you archiving this lead?" style="width:100%; min-height:80px; padding:8px; border:1px solid #d1d5db; border-radius:6px; font-family:inherit; resize:vertical;" maxlength="500"></textarea>
                    <div style="display:flex; gap:8px; margin-top:16px; justify-content:flex-end;">
                        <button id="archiveCancelBtn" class="btn secondary">Cancel</button>
                        <button id="archiveConfirmBtn" class="btn" style="background:#dc2626;">Archive</button>
                    </div>
                `;
                
                overlay.appendChild(modal);
                document.body.appendChild(overlay);
                
                document.getElementById('archiveCancelBtn').addEventListener('click', () => {
                    document.body.removeChild(overlay);
                });
                
                overlay.addEventListener('click', (e) => {
                    if (e.target === overlay) {
                        document.body.removeChild(overlay);
                    }
                });
                
                document.getElementById('archiveConfirmBtn').addEventListener('click', () => {
                    const note = document.getElementById('archiveNote').value.trim();
                    archiveLead(leadId, note, overlay);
                });
            }
            
            function archiveLead(leadId, note, overlay) {
                const btn = document.getElementById('archiveConfirmBtn');
                if (!btn) return;
                
                btn.disabled = true;
                btn.textContent = 'Archiving...';
                
                fetch('leads.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'archive_lead',
                        lead_id: leadId,
                        archive_note: note
                    })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.ok) {
                        document.body.removeChild(overlay);
                        
                        // Check if we're on lead detail view (has lead-header-premium class)
                        const isDetailView = document.querySelector('.lead-header-premium');
                        
                        if (isDetailView) {
                            // Redirect to leads list with success message
                            showToast('Lead archived successfully' + (data.auto_archived ? ' (auto-archived for all agents)' : ''), 'success');
                            setTimeout(() => {
                                window.location.href = 'leads.php';
                            }, 800);
                        } else {
                            // Remove row with fade animation (list view)
                            const row = document.querySelector(`tr:has(.archive-icon-btn[data-lead-id="${leadId}"])`);
                            if (row) {
                                row.style.transition = 'opacity 0.3s';
                                row.style.opacity = '0';
                                setTimeout(() => row.remove(), 300);
                            }
                            
                            // Show toast notification
                            showToast('Lead archived successfully' + (data.auto_archived ? ' (auto-archived for all agents)' : ''), 'success');
                        }
                    } else {
                        alert('Failed to archive lead: ' + (data.error || 'Unknown error'));
                        btn.disabled = false;
                        btn.textContent = 'Archive';
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Network error archiving lead');
                    btn.disabled = false;
                    btn.textContent = 'Archive';
                });
            }
            
            function loadArchivedLeads() {
                fetch('leads.php?action=get_archived_leads')
                .then(r => r.json())
                .then(data => {
                    if (data.ok && data.leads) {
                        const tbody = document.querySelector('table tbody');
                        if (!tbody) return;
                        
                        data.leads.forEach(lead => {
                            const row = document.createElement('tr');
                            row.className = 'archived-lead-row';
                            row.style.background = '#f9fafb';
                            row.dataset.archived = 'true';
                            
                            const addr = lead.address || [lead.address_line1, lead.city, lead.state, lead.zip_code].filter(Boolean).join(', ') || '—';
                            const fullName = lead.full_name || 'Unknown';
                            const email = lead.email || '';
                            const phone = lead.phone || '';
                            
                            row.innerHTML = `
                                <td>
                                    <div style="color:#6b7280;">
                                        ${lead.company_name || fullName}
                                        <span style="display:inline-block; padding:2px 6px; background:#fef3c7; color:#92400e; border-radius:4px; font-size:10px; margin-left:6px;">ARCHIVED</span>
                                    </div>
                                </td>
                                <td style="color:#9ca3af;">${addr}</td>
                                <td>
                                    <div style="color:#6b7280;">${fullName}</div>
                                    <div style="font-size:13px; color:#9ca3af;">${email}${phone ? ' • ' + phone : ''}</div>
                                </td>
                                <td style="color:#9ca3af;">—</td>
                                <td><span style="padding:4px 8px; background:#e5e7eb; color:#6b7280; border-radius:4px; font-size:12px;">${lead.lead_status || 'unknown'}</span></td>
                                <td><span style="padding:4px 8px; background:#e5e7eb; color:#6b7280; border-radius:4px; font-size:12px;">${lead.lead_priority || 'medium'}</span></td>
                                <?php if ($hasFullAccess && !$isAdminSimulation): ?>
                                <td style="color:#9ca3af;">—</td>
                                <?php endif; ?>
                                <td style="color:#9ca3af; font-size:13px;">—</td>
                                <td style="color:#9ca3af; font-size:13px;">${lead.days_old || '—'} days</td>
                                <td style="text-align:center;">
                                    <button class="unarchive-icon-btn" data-lead-id="${lead.id}" title="Unarchive this lead" style="background:none; border:none; cursor:pointer; font-size:18px; opacity:0.7; transition:opacity 0.2s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.7'">
                                        ↩️
                                    </button>
                                </td>
                            `;
                            
                            tbody.appendChild(row);
                        });
                        
                        // Attach unarchive handlers
                        document.querySelectorAll('.unarchive-icon-btn').forEach(btn => {
                            btn.addEventListener('click', function() {
                                unarchiveLead(this.dataset.leadId);
                            });
                        });
                    }
                })
                .catch(err => {
                    console.error('Error loading archived leads:', err);
                });
            }
            
            function clearArchivedLeads() {
                document.querySelectorAll('.archived-lead-row').forEach(row => row.remove());
            }
            
            function unarchiveLead(leadId) {
                if (!confirm('Unarchive this lead and restore it to your active list?')) return;
                
                fetch('leads.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'unarchive_lead',
                        lead_id: leadId
                    })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.ok) {
                        showToast('Lead unarchived successfully', 'success');
                        setTimeout(() => location.reload(), 800);
                    } else {
                        alert('Failed to unarchive lead: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Network error unarchiving lead');
                });
            }
            
            function showToast(message, type) {
                const toast = document.createElement('div');
                toast.style.cssText = `position:fixed; top:20px; right:20px; padding:12px 20px; background:${type === 'success' ? '#10b981' : '#ef4444'}; color:#fff; border-radius:6px; box-shadow:0 4px 12px rgba(0,0,0,0.15); z-index:10000; animation:slideInRight 0.3s ease-out;`;
                toast.textContent = message;
                document.body.appendChild(toast);
                setTimeout(() => {
                    toast.style.opacity = '0';
                    toast.style.transition = 'opacity 0.3s';
                    setTimeout(() => document.body.removeChild(toast), 300);
                }, 3000);
            }
            </script>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($leadDetail): ?>
<!-- Inline Follow-up Drawer -->
<div id="followupDrawer" style="display:none; position: fixed; right: 0; top: 0; height: 100%; width: 420px; max-width: 90vw; background: #ffffff; border-left: 1px solid #e5e7eb; box-shadow: -2px 0 8px rgba(0,0,0,0.08); z-index: 2000;">
    <div style="display:flex; justify-content: space-between; align-items:center; padding: 12px 16px; border-bottom:1px solid #e5e7eb;">
        <div style="font-weight:600; color:#1f2937;">Log Follow‑up Call</div>
        <button class="btn secondary" onclick="closeFollowUpDrawer()">Close</button>
    </div>
    <div style="padding: 16px;">
        <form id="followupForm" method="post" action="calls.php" target="hidden_iframe">
            <input type="hidden" name="action" value="add_call">
            <input type="hidden" name="lead_id" id="fu_lead_id" value="<?php echo $leadDetail['id']; ?>">
            <input type="hidden" name="contact_type" value="phone_call">
            <input type="hidden" name="direction" value="outbound">
            <label>Subject</label>
            <input type="text" name="subject" value="Follow-up: Demo Review" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px; margin-bottom:8px;">
            <label>Call Outcome</label>
            <select name="call_outcome" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px; margin-bottom:8px;">
                <option value="">Select Outcome</option>
                <option value="answered">Answered</option>
                <option value="no_answer">No Answer</option>
                <option value="busy">Busy</option>
                <option value="voicemail">Voicemail</option>
                <option value="meeting_scheduled">Meeting Scheduled</option>
                <option value="sale_made">Sale Made</option>
                <option value="not_interested">Not Interested</option>
            </select>
            <label>Summary</label>
            <textarea name="summary" rows="3" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px; margin-bottom:8px;">Follow-up from demo review.</textarea>
            <label>Next Action</label>
            <textarea name="next_action" rows="2" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px; margin-bottom:8px;">Schedule reorder conversation.</textarea>
            <label style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                <input type="checkbox" name="follow_up_required" value="1" checked onchange="document.getElementById('fu_follow_date').disabled=!this.checked;"> Follow-up Required
            </label>
            <input type="datetime-local" id="fu_follow_date" name="follow_up_date" value="<?php echo date('Y-m-d\\TH:i', strtotime('+3 days')); ?>" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px; margin-bottom:12px;">
            <div style="display:flex; gap:8px; justify-content:flex-end;">
                <button type="button" class="btn secondary" onclick="closeFollowUpDrawer()">Cancel</button>
                <button type="submit" class="btn danger">Log Call</button>
            </div>
        </form>
        <iframe name="hidden_iframe" style="display:none;"></iframe>
    </div>
</div>
<?php endif; ?>

<!-- Premium Design System CSS -->
<style>
:root {
    --primary: #0066FF;
    --success: #00C853;
    --warning: #FF6D00;
    --danger: #FF1744;
    --dark: #1A1A2E;
    --gray-600: #64748B;
    --gray-400: #CBD5E0;
    --gray-100: #F8FAFC;
    --white: #FFFFFF;
    --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

* {
    transition: var(--transition);
}

/* Premium Header */
.lead-header-premium {
    background: linear-gradient(135deg, var(--white) 0%, var(--gray-100) 100%);
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: var(--shadow-md);
}

.lead-header-back {
    margin-bottom: 16px;
}

.back-button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--gray-600);
    text-decoration: none;
    font-weight: 500;
    padding: 8px 16px;
    border-radius: 8px;
    background: var(--white);
    border: 1px solid var(--gray-400);
}

.back-button:hover {
    background: var(--primary);
    color: var(--white);
    border-color: var(--primary);
}

.lead-header-main {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 24px;
}

.lead-header-info {
    display: flex;
    align-items: center;
    gap: 16px;
    flex: 1;
}

.lead-header-avatar {
    width: 64px;
    height: 64px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, var(--primary), #60A5FA);
    color: var(--white);
    font-size: 32px;
}

.lead-header-avatar.avatar-fire {
    background: linear-gradient(135deg, var(--danger), #FF9100);
    animation: pulse 2s infinite;
}

.lead-header-avatar.avatar-hot {
    background: linear-gradient(135deg, var(--warning), #FFC400);
}

@keyframes pulse {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.05); opacity: 0.9; }
}

.lead-header-title {
    margin: 0;
    font-size: 24px;
    font-weight: 700;
    color: var(--dark);
}

.admin-badge {
    display: inline-block;
    margin-left: 12px;
    padding: 4px 8px;
    background: var(--primary);
    color: var(--white);
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
}

.lead-header-meta {
    display: flex;
    gap: 16px;
    margin-top: 8px;
}

.meta-item {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--gray-600);
    font-size: 14px;
}

/* Lead Score Ring */
.lead-header-score {
    text-align: center;
}

.score-ring {
    position: relative;
    display: inline-block;
}

.score-value {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 24px;
    font-weight: 700;
    color: var(--dark);
}

.score-label {
    margin-top: 8px;
    color: var(--gray-600);
    font-size: 12px;
    font-weight: 600;
}

/* Radial Menu (styles loaded from radial-menu.css) */

/* Status Pipeline */
.status-pipeline {
    background: var(--white);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 24px;
    box-shadow: var(--shadow-sm);
}

.pipeline-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.pipeline-header h3 {
    margin: 0;
    color: var(--dark);
}

.btn-text {
    background: none;
    border: none;
    color: var(--primary);
    cursor: pointer;
    font-weight: 600;
}

.btn-text:hover {
    text-decoration: underline;
}

.pipeline-track {
    display: flex;
    justify-content: space-between;
    position: relative;
    padding: 0 20px;
}

.pipeline-track::before {
    content: '';
    position: absolute;
    top: 20px;
    left: 40px;
    right: 40px;
    height: 2px;
    background: var(--gray-400);
    z-index: 0;
}

.pipeline-stage {
    position: relative;
    text-align: center;
    cursor: pointer;
    z-index: 1;
}

.stage-dot {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--white);
    border: 2px solid var(--gray-400);
    margin: 0 auto 8px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.pipeline-stage.active .stage-dot {
    background: var(--success);
    border-color: var(--success);
    color: var(--white);
}

.pipeline-stage.current .stage-dot {
    background: var(--primary);
    border-color: var(--primary);
    color: var(--white);
    animation: pulse 2s infinite;
}

.stage-label {
    font-size: 12px;
    color: var(--gray-600);
}

.pipeline-stage.active .stage-label {
    color: var(--dark);
    font-weight: 600;
}

/* Premium Layout */
.premium-layout {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 24px;
    margin-bottom: 24px;
}

@media (max-width: 1024px) {
    .premium-layout {
        grid-template-columns: 1fr;
    }
}

/* Premium Cards */
.card-premium {
    background: var(--white);
    border-radius: 12px;
    padding: 20px;
    box-shadow: var(--shadow-sm);
    margin-bottom: 16px;
}

.card-header-premium {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.card-header-premium h3 {
    margin: 0;
    color: var(--dark);
    font-size: 18px;
}

.btn-icon {
    background: none;
    border: none;
    cursor: pointer;
    color: var(--gray-600);
    padding: 8px;
    border-radius: 8px;
}

.btn-icon:hover {
    background: var(--gray-100);
    color: var(--primary);
}

/* Gentle orange underline pulse for fields needing update */
.needs-update {
    position: relative;
}
.needs-update::after {
    content: '';
    position: absolute;
    left: 0; right: 0; bottom: -2px;
    height: 2px;
    background: #f59e0b;
    border-radius: 2px;
    animation: underlinePulse 1.5s ease-in-out infinite;
}
@keyframes underlinePulse {
    0% { opacity: .2; }
    50% { opacity: 1; }
    100% { opacity: .2; }
}

/* Gentle blue glow highlight for recommended action */
.highlight-recommend {
    box-shadow: 0 0 0 0 rgba(59,130,246,.6);
    animation: blueGlow 1.8s ease-in-out infinite;
}
@keyframes blueGlow {
    0% { box-shadow: 0 0 0 0 rgba(59,130,246,.45); }
    70% { box-shadow: 0 0 0 12px rgba(59,130,246,0); }
    100% { box-shadow: 0 0 0 0 rgba(59,130,246,0); }
}

.data-warning {
    background: #fff7ed;
    color: #9a3412;
    border: 1px solid #fdba74;
    padding: 10px 12px;
    border-radius: 8px;
    font-size: 14px;
}

/* Info Tabs */
.info-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
    border-bottom: 2px solid var(--gray-100);
}

.info-tab {
    padding: 12px 20px;
    background: none;
    border: none;
    color: var(--gray-600);
    cursor: pointer;
    font-weight: 500;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
}

.info-tab.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
}

.tab-content {
    animation: fadeIn 0.3s;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.info-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 16px;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.info-label {
    font-size: 12px;
    color: var(--gray-600);
    font-weight: 600;
}

.info-value {
    font-size: 14px;
    color: var(--dark);
}

.info-highlight {
    color: var(--primary);
    font-weight: 600;
}

.interest-meter {
    display: inline-block;
    width: 100px;
    height: 8px;
    background: var(--gray-100);
    border-radius: 4px;
    overflow: hidden;
}

.interest-bar {
    height: 100%;
    background: linear-gradient(90deg, var(--primary), var(--success));
}

/* Activity Timeline Enhanced */
.header-actions-group {
    display: flex;
    align-items: center;
    gap: 12px;
    position: relative;
}

.btn-add-activity {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--primary);
    color: var(--white);
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s;
    position: relative;
}

/* Pulse indicator on the header + button if no demo recorded */
.btn-add-activity.btn-nudge {
    position: relative;
}
.btn-add-activity.btn-nudge::after {
    content: '';
    position: absolute;
    left: 0; top: 0; width: 100%; height: 100%; border-radius: 50%;
    box-shadow: 0 0 0 0 rgba(0,102,255,0.55);
    animation: fabPulse 1.2s ease-in-out infinite;
    pointer-events: none;
}

.btn-add-activity:hover {
    background: #0052CC;
    transform: scale(1.1);
    box-shadow: 0 4px 12px rgba(0, 102, 255, 0.4);
}

.activity-add-menu {
    position: absolute;
    top: 100%;
    right: 0;
    margin-top: 8px;
    background: var(--white);
    border: 1px solid var(--gray-400);
    border-radius: 8px;
    box-shadow: var(--shadow-lg);
    min-width: 160px;
    display: none;
    z-index: 100;
}

.activity-add-menu.active {
    display: block;
    animation: slideDown 0.2s ease;
}

.activity-add-menu button {
    width: 100%;
    padding: 10px 16px;
    background: none;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
    line-height: 1.4;
    font-weight: 500;
    color: var(--dark);
    text-align: left;
    transition: all 0.2s;
}

.activity-add-menu button:hover {
    background: var(--gray-100);
    color: var(--primary);
}

.activity-add-menu button:first-child {
    border-radius: 8px 8px 0 0;
}

.activity-add-menu button:last-child {
    border-radius: 0 0 8px 8px;
}

.btn-view-all {
    position: relative;
}

/* Accent link for primary action inside dropdowns */
.menu-accent{font-weight:700;color:var(--dark)}

.view-all-menu {
    position: absolute;
    top: 100%;
    right: 0;
    margin-top: 8px;
    background: var(--white);
    border: 1px solid var(--gray-400);
    border-radius: 8px;
    box-shadow: var(--shadow-lg);
    min-width: 160px;
    display: none;
    z-index: 100;
}

.view-all-menu.active {
    display: block;
    animation: slideDown 0.2s ease;
}

.view-all-menu a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    color: var(--dark);
    text-decoration: none;
    transition: all 0.2s;
    font-weight: 500;
}

.view-all-menu a:hover {
    background: var(--gray-100);
    color: var(--primary);
}

/* Activity Tabs */
.activity-tabs {
    display: flex;
    gap: 8px;
    margin: 16px 0;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--gray-100);
}

.activity-tab {
    padding: 6px 12px;
    background: var(--gray-100);
    border: none;
    border-radius: 20px;
    cursor: pointer;
    font-size: 13px;
    color: var(--gray-600);
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}

.activity-tab:hover {
    background: var(--gray-400);
    color: var(--dark);
}

.activity-tab.active {
    background: var(--primary);
    color: var(--white);
}

.tab-count {
    background: rgba(255, 255, 255, 0.2);
    padding: 2px 6px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
}

.activity-tab.active .tab-count {
    background: rgba(255, 255, 255, 0.3);
}

.timeline {
    max-height: 400px;
    overflow-y: auto;
}

.timeline-empty {
    text-align: center;
    padding: 40px 20px;
    color: var(--gray-600);
}

/* Clickable stats */
.stat-item.clickable {
    cursor: pointer;
    transition: all 0.2s;
}

.stat-item.clickable:hover {
    transform: translateY(-2px);
    background: var(--gray-100);
    border-radius: 8px;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.timeline-item {
    display: flex;
    gap: 12px;
    margin-bottom: 20px;
    position: relative;
}

.timeline-item:not(:last-child)::before {
    content: '';
    position: absolute;
    left: 20px;
    top: 40px;
    bottom: -20px;
    width: 2px;
    background: var(--gray-100);
}

.timeline-marker {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.timeline-marker.call {
    background: #FEE2E2;
}

.timeline-marker.note {
    background: #DCFCE7;
}

.timeline-marker.task {
    background: #DBEAFE;
}
.timeline-marker.demo {
    background: #FEF3C7;
}

.timeline-content {
    flex: 1;
}

.timeline-title {
    font-weight: 600;
    color: var(--dark);
    margin-bottom: 4px;
}

.timeline-desc {
    font-size: 13px;
    color: var(--gray-600);
    margin-bottom: 4px;
}

.timeline-meta {
    display: flex;
    gap: 8px;
    font-size: 12px;
    color: var(--gray-600);
}

.badge-mini {
    padding: 2px 6px;
    background: var(--gray-100);
    border-radius: 4px;
}

/* Activity Stats */
.activity-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid var(--gray-100);
}

.stat-item {
    text-align: center;
}

.stat-value {
    font-size: 24px;
    font-weight: 700;
    color: var(--primary);
}

.stat-label {
    font-size: 12px;
    color: var(--gray-600);
}

/* Premium Buttons */
.btn-premium {
    padding: 12px 24px;
    border-radius: 8px;
    border: none;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    justify-content: center;
}

.btn-premium.btn-success {
    background: linear-gradient(135deg, var(--success), #00E676);
    color: var(--white);
}

.btn-premium:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

/* Keep existing utility classes */
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}
.page-title{margin:0;color:var(--dark)}
.card-title-premium{margin:0 0 16px 0;color:var(--dark)}
.text-muted{color:var(--gray-600)}
.link-premium{color:var(--primary);text-decoration:none}
.link-premium:hover{text-decoration:underline}
.badge-priority{padding:4px 8px;border-radius:6px;font-size:12px;font-weight:600}
.badge-urgent{background:#FEE2E2;color:var(--danger)}
.badge-high{background:#FEF3C7;color:var(--warning)}
.badge-medium{background:#DBEAFE;color:var(--primary)}
.badge-low{background:var(--gray-100);color:var(--gray-600)}

/* Keep existing needed styles */
.card.subtle{background:#f9fafb;border:1px solid #e5e7eb}
.nearby-item{display:flex;justify-content:space-between;align-items:center;padding:8px;border:1px solid #e5e7eb;border-radius:6px;background:#fff}
.btn.small{padding:4px 8px;font-size:12px}
.row-actions{display:flex;gap:4px}
.chip{display:inline-block;padding:2px 8px;border-radius:12px;font-size:12px;font-weight:600}
.chip-status{background:#f3f4f6;color:#6b7280}
.chip-success{background:#d1fae5;color:#065f46}
label{display:block;font-weight:600;margin-bottom:4px;color:#374151}

/* Celebration Animation */
@keyframes celebration {
    0% { transform: scale(0) rotate(0); opacity: 0; }
    50% { transform: scale(1.2) rotate(180deg); opacity: 1; }
    100% { transform: scale(1) rotate(360deg); opacity: 1; }
}

.celebration-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    pointer-events: none;
    z-index: 9999;
}

/* Demo callout (empty state) */
.demo-callout{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 14px;border-radius:12px;background:linear-gradient(180deg,#f8fafc, #eef6ff);border:1px solid #e5e7eb}
.demo-callout-title{font-weight:800;color:#111827;display:flex;align-items:center;gap:8px}
.demo-callout-sub{color:#64748b;font-size:13px}
@media(max-width:640px){.demo-callout{flex-direction:column;align-items:flex-start}}

/* Mobile polish: reduce gaps and ensure compact, readable layout */
@media (max-width: 640px) {
    .lead-header-premium { padding: 16px; margin-bottom: 16px; }
    .lead-header-main { flex-wrap: wrap; gap: 12px; align-items: flex-start; }
    .lead-header-title { font-size: 20px; }
    .lead-header-meta { gap: 12px; }
    .lead-header-avatar { width: 56px; height: 56px; border-radius: 12px; }
    .lead-header-score { margin-left: auto; }
    .score-ring svg { width: 64px !important; height: 64px !important; }
    .score-value { font-size: 20px; }

    .status-pipeline { padding: 14px; margin-bottom: 16px; }
    .pipeline-track { padding: 0 8px; }
    .pipeline-track::before { top: 16px; left: 28px; right: 28px; }
    .stage-dot { width: 32px; height: 32px; margin-bottom: 6px; }
    .stage-label { font-size: 11px; }

    .premium-layout { gap: 16px; }
    .card-premium { padding: 16px; margin-bottom: 12px; }
    .info-tab { padding: 10px 12px; }
    .timeline { max-height: 320px; }
    .stat-value { font-size: 20px; }

    /* Radial menu responsive positioning handled in radial-menu.css */
}
</style>

<!-- Add Lead Modal -->
<div id="addLeadModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 5000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; padding: 24px; width: 500px; max-width: 90vw; max-height: 80vh; overflow-y: auto;">
        <h3 style="margin: 0 0 16px 0;">Add New Lead</h3>
        <form method="post" style="display: grid; gap: 16px;">
            <input type="hidden" name="action" value="add_lead">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label>First Name *</label>
                    <input type="text" name="first_name" required style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
                <div>
                    <label>Last Name *</label>
                    <input type="text" name="last_name" required style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
            </div>
            
            <div>
                <label>Email *</label>
                <input type="email" name="email" id="add_email" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                <label style="display: flex; align-items: center; gap: 6px; margin-top: 6px; font-weight: normal;">
                    <input type="checkbox" name="email_not_available" id="add_email_not_available" style="width: auto;">
                    <span style="font-size: 13px; color: #6b7280;">Email not available</span>
                </label>
            </div>
            
            <div>
                <label>Phone</label>
                <input type="tel" name="phone" id="add_phone" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                <label style="display: flex; align-items: center; gap: 6px; margin-top: 6px; font-weight: normal;">
                    <input type="checkbox" name="phone_not_available" id="add_phone_not_available" style="width: auto;">
                    <span style="font-size: 13px; color: #6b7280;">Phone not available</span>
                </label>
            </div>
            
            <div>
                <label>Company Name</label>
                <input type="text" name="company_name" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
            </div>
            
            <div>
                <label>Website</label>
                <input type="url" name="website" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
            </div>

            <!-- Smart Address (Google Places) -->
            <div style="margin-top:8px; padding: 12px; border: 1px solid #0369a1; background: #f0f9ff; border-radius: 8px;">
                <div style="display:flex; justify-content: space-between; align-items:center; gap:8px; margin-bottom:8px;">
                    <label style="margin:0; color:#0369a1;">Smart Address</label>
                    <div style="display:flex; gap:8px;">
                        <button type="button" class="btn small" style="background:#0369a1;" onclick="useMyLocation()">📍 Use My Location</button>
                        <button type="button" class="btn small secondary" onclick="clearAddress()">Clear</button>
                    </div>
                </div>
                <input id="autocomplete_address" type="text" placeholder="Start typing an address..." style="width: 100%; padding: 10px; border: 1px solid #93c5fd; border-radius: 8px;">
                <div style="display:grid; grid-template-columns: 2fr 1fr; gap: 8px; margin-top: 8px;">
                    <input name="address_line1" id="addr_line1" placeholder="Address line 1" class="input" style="padding:8px;">
                    <input name="address_line2" id="addr_line2" placeholder="Address line 2 (optional)" class="input" style="padding:8px;">
                    <input name="city" id="addr_city" placeholder="City" class="input" style="padding:8px;">
                    <input name="state" id="addr_state" placeholder="State" class="input" style="padding:8px;">
                    <input name="zip_code" id="addr_zip" placeholder="Zip" class="input" style="padding:8px;">
                    <input name="country" id="addr_country" placeholder="Country" class="input" style="padding:8px;">
                </div>
                <input type="hidden" name="address_full" id="addr_full">
                <input type="hidden" name="postal_code" id="addr_postal">
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label>Lead Source</label>
                    <select name="lead_source" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="website">Website</option>
                        <option value="referral">Referral</option>
                        <option value="cold_call">Cold Call</option>
                        <option value="trade_show">Trade Show</option>
                        <option value="social_media">Social Media</option>
                        <option value="email_campaign">Email Campaign</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div>
                    <label>Priority</label>
                    <select name="lead_priority" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                        <option value="urgent">Urgent</option>
                    </select>
                </div>
            </div>
            
            <!-- Demo Drop-off Section -->
            <div style="background: #f0f9ff; padding: 16px; border-radius: 8px; border: 1px solid #0369a1;">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                    <input type="checkbox" id="demo_dropoff" name="demo_dropoff" value="1" onchange="toggleDemoOptions()" style="width: 16px; height: 16px;">
                    <label for="demo_dropoff" style="font-weight: 600; color: #0369a1; margin: 0; cursor: pointer;">🧪 Drop off demo during this visit?</label>
                </div>
                
                <div id="demo_options" style="display: none; margin-left: 28px;">
                    <label>Select Demo Type(s):</label>
                    <div style="display: grid; gap: 8px; margin-bottom: 12px;">
                        <?php 
                        $demoTypes = get_demo_types($pdo);
                        foreach ($demoTypes as $demo): 
                        ?>
                        <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer;">
                            <input type="checkbox" name="demo_types[]" value="<?php echo $demo['id']; ?>" style="width: 14px; height: 14px;">
                            <?php echo htmlspecialchars($demo['product_name']); ?> (<?php echo htmlspecialchars($demo['blend_type']); ?>)
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <label>Drop-off Notes (optional):</label>
                    <textarea name="dropoff_notes" rows="2" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;" placeholder="Any special instructions or notes about this demo..."></textarea>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                <div>
                    <label>Est. Monthly Volume ($)</label>
                    <input type="number" name="estimated_monthly_volume" min="0" step="0.01" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
                <div>
                    <label>Deal Value ($)</label>
                    <input type="number" name="estimated_deal_value" min="0" step="0.01" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
                <div>
                    <label>Interest Level (1-10)</label>
                    <input type="number" name="interest_level" min="1" max="10" value="5" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
            </div>
            
            <div>
                <label>Next Follow-up</label>
                <input type="datetime-local" name="next_follow_up" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
            </div>
            
            <div style="display: flex; gap: 12px; justify-content: end; margin-top: 16px;">
                <button type="button" onclick="hideAddModal()" class="btn secondary">Cancel</button>
                <button type="submit" class="btn" style="background: #059669;">Add Lead</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Lead Modal - Replaced with dedicated edit-lead.php page -->

<!-- Screenshot Viewer Modal -->
<div id="screenshotViewerModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.9); z-index:10001; align-items:center; justify-content:center; cursor:zoom-out;" onclick="this.style.display='none'">
    <img id="screenshotViewerImg" src="" alt="Screenshot" style="max-width:90%; max-height:90%; border-radius:8px; box-shadow:0 20px 60px rgba(0,0,0,0.5);">
</div>

<!-- Log DM/Social Interaction Modal -->
<div id="logDMModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:12px; padding:24px; max-width:600px; width:90%; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
        <h3 style="margin:0 0 20px 0; display:flex; align-items:center; gap:8px;">
            <i class="fa-solid fa-message"></i> Log Social Media Interaction
        </h3>
        
        <div style="display:grid; gap:16px;">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <div>
                    <label style="display:block; margin-bottom:6px; font-weight:600; font-size:13px;">Platform *</label>
                    <select id="dmPlatform" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:6px;">
                        <option value="instagram">Instagram</option>
                        <option value="facebook">Facebook</option>
                        <option value="linkedin">LinkedIn</option>
                        <option value="x">X (Twitter)</option>
                        <option value="tiktok">TikTok</option>
                        <option value="reddit">Reddit</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; margin-bottom:6px; font-weight:600; font-size:13px;">Type *</label>
                    <select id="dmType" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:6px;">
                        <option value="dm">Direct Message</option>
                        <option value="comment">Comment</option>
                        <option value="post">Post/Reply</option>
                        <option value="story">Story Response</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>
            
            <div>
                <label style="display:block; margin-bottom:6px; font-weight:600; font-size:13px;">Direction</label>
                <div style="display:flex; gap:12px;">
                    <label style="flex:1; display:flex; align-items:center; gap:8px; padding:10px; border:1px solid #d1d5db; border-radius:6px; cursor:pointer;">
                        <input type="radio" name="dmDirection" value="outbound" checked>
                        <span>Outbound (I sent)</span>
                    </label>
                    <label style="flex:1; display:flex; align-items:center; gap:8px; padding:10px; border:1px solid #d1d5db; border-radius:6px; cursor:pointer;">
                        <input type="radio" name="dmDirection" value="inbound">
                        <span>Inbound (They sent)</span>
                    </label>
                </div>
            </div>
            
            <div>
                <label style="display:block; margin-bottom:6px; font-weight:600; font-size:13px;">Message/Notes</label>
                <textarea id="dmContent" rows="4" placeholder="Paste the message content or add notes..." style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:6px; resize:vertical;"></textarea>
            </div>
            
            <div>
                <label style="display:block; margin-bottom:6px; font-weight:600; font-size:13px;">Screenshot (optional)</label>
                <div style="border:2px dashed #d1d5db; border-radius:8px; padding:20px; text-align:center;">
                    <input type="file" id="dmScreenshot" accept="image/*" style="display:none;" onchange="handleScreenshotUpload(event)">
                    <div id="screenshotPreview"></div>
                    <button type="button" onclick="document.getElementById('dmScreenshot').click()" class="btn small secondary">
                        <i class="fa-solid fa-image"></i> Upload or Paste Screenshot
                    </button>
                    <div style="font-size:11px; color:#6b7280; margin-top:8px;">
                        Or press Ctrl+V to paste from clipboard
                    </div>
                </div>
            </div>
        </div>
        
        <div style="display:flex; gap:12px; justify-content:end; margin-top:24px;">
            <button type="button" onclick="closeLogDMModal()" class="btn secondary">Cancel</button>
            <button type="button" onclick="saveLogDM()" class="btn" style="background:#059669;">
                <i class="fa-solid fa-save"></i> Log Interaction
            </button>
        </div>
    </div>
</div>

<style>
.card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.btn.small {
    padding: 4px 8px;
    font-size: 12px;
}

label {
    display: block;
    font-weight: 600;
    margin-bottom: 4px;
    color: #374151;
}

@keyframes pulse {
    0% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.9; transform: scale(1.02); }
    100% { opacity: 1; transform: scale(1); }
}

@keyframes fire {
    0% { transform: rotate(-2deg); }
    50% { transform: rotate(2deg); }
    100% { transform: rotate(-2deg); }
}

.hot-indicator {
    display: inline-block;
    animation: fire 0.5s ease-in-out infinite;
}
</style>

<script>
// Inline Follow-up modal (reused from dropoffs.php UX)
let __fuModal;
function ensureFollowUpModal(){
    if (document.getElementById('inlineFuModal')) return;
    const wrap = document.createElement('div');
    wrap.id = 'inlineFuModal';
    wrap.style.cssText = 'display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10000;';
    wrap.innerHTML = `
        <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:#fff; border-radius:12px; padding:24px; width:520px; max-width:92vw; max-height:90vh; overflow:auto;">
            <h3 style="margin-top:0;">Complete Follow-up</h3>
            <form id="inlineFuForm" method="post" style="display:grid; gap:12px;">
                <input type="hidden" name="action" value="complete_followup">
                <input type="hidden" name="dropoff_id" id="ifu_dropoff_id">
                <input type="hidden" name="task_id" id="ifu_task_id">
                <div>
                    <label>Follow-up Status</label>
                    <select name="status" id="ifu_status">
                        <option value="completed">Completed - Successful Follow-up</option>
                        <option value="cancelled">Cancelled - Customer Not Interested</option>
                    </select>
                </div>
                <div id="ifu_outcome" style="display:grid; gap:8px;">
                    <label style="font-weight:600;">Customer Feedback:</label>
                    <label style="display:flex; align-items:center; gap:8px; font-weight:500;">
                        <input type="checkbox" name="interested_75mg" value="1"> Interested in trying 75mg blend
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; font-weight:500;">
                        <input type="checkbox" name="happy_current" value="1"> Happy with current blend (32/20)
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; font-weight:500;">
                        <input type="checkbox" name="ready_order" value="1"> Ready to place order
                    </label>
                </div>
                <div>
                    <label>Follow-up Notes</label>
                    <textarea name="notes" rows="3" placeholder="Customer feedback, next steps, etc..."></textarea>
                </div>
                <div style="display:flex; gap:8px; justify-content:end;">
                    <button type="button" class="btn secondary" onclick="closeInlineFu()">Cancel</button>
                    <button type="submit" class="btn" style="background:#059669;">Complete Follow-up</button>
                </div>
            </form>
        </div>`;
    document.body.appendChild(wrap);
    __fuModal = wrap;
    document.getElementById('inlineFuForm').addEventListener('submit', async function(e){
        e.preventDefault();
        const fd = new FormData(this);
        try {
            const res = await fetch('dropoffs.php', { method: 'POST', body: fd });
            // dropoffs.php will redirect if not ajax; we expect OK HTML. Just close and reload.
            const did = fd.get('dropoff_id');
            closeInlineFu();
            markDemoCompletedInTimeline(did);
        } catch (err) {
            console.error(err); alert('Failed to complete follow-up.');
        }
    });
    document.addEventListener('click', function(ev){ if (ev.target === wrap) closeInlineFu(); });
}
function markDemoCompletedInTimeline(dropoffId){
    try {
        const el = document.querySelector('.timeline-item[data-dropoff-id="' + dropoffId + '"]');
        if (!el) { PremiumUI.showToast('Follow-up completed', 'success'); return; }
        const meta = el.querySelector('.timeline-meta');
        if (!meta) { PremiumUI.showToast('Follow-up completed', 'success'); return; }
        const btn = meta.querySelector('button'); if (btn) btn.remove();
        const span = document.createElement('span'); span.className = 'badge-mini badge-completed'; span.textContent = 'completed'; meta.appendChild(span);
        PremiumUI.showToast('Follow-up completed', 'success');
        // Update the Demos tab count and All count
        try {
            const demosTab = Array.from(document.querySelectorAll('.activity-tab')).find(el => el.textContent.includes('Demos'));
            if (demosTab) {
                const c = demosTab.querySelector('.tab-count');
                if (c) { const v = Math.max(0, parseInt(c.textContent||'0',10)); c.textContent = String(v || 1); }
            }
            const allTab = document.querySelector('.activity-tab');
            if (allTab) {
                const ac = allTab.querySelector('.tab-count'); if (ac) { const v = parseInt(ac.textContent||'0',10); ac.textContent = String(v); }
            }
        } catch(e){}
    } catch(e) { PremiumUI.showToast('Follow-up completed', 'success'); }
}
function openFollowUpModal(dropoffId, taskId){
    ensureFollowUpModal();
    document.getElementById('ifu_dropoff_id').value = dropoffId;
    document.getElementById('ifu_task_id').value = taskId || 0;
    document.getElementById('ifu_status').value = 'completed';
    document.getElementById('ifu_outcome').style.display = 'grid';
    __fuModal.style.display = 'block';
}
function closeInlineFu(){ if (__fuModal) __fuModal.style.display = 'none'; }
// Premium UI JavaScript Implementation
const PremiumUI = {
    // Initialize all components
    init() {
        this.initTabs();
        this.initPipeline();
        this.initModals();
        this.bindKeyboardShortcuts();
    },
    
    // Tab Switching
    initTabs() {
        window.switchTab = (tabBtn, contentId) => {
            // Update tab buttons
            document.querySelectorAll('.info-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            tabBtn.classList.add('active');
            
            // Update tab content
            document.querySelectorAll('.tab-content').forEach(content => {
                content.style.display = 'none';
            });
            document.getElementById('tab-' + contentId).style.display = 'block';
        };
    },
    
    // Pipeline Status Changes
    initPipeline() {
        window.quickStatusChange = async (status) => {
            if (confirm(`Change status to ${status.replace(/_/g, ' ')}?`)) {
                const formData = new FormData();
                formData.append('action', 'update_status');
                formData.append('lead_id', '<?php echo $leadDetail['id'] ?? 0; ?>');
                formData.append('lead_status', status);
                formData.append('status_notes', 'Status updated via pipeline');
                
                try {
                    const response = await fetch('leads.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    if (status === 'won') {
                        this.celebrate();
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        location.reload();
                    }
                } catch (error) {
                    console.error('Status update failed:', error);
                }
            }
        };
        
        window.toggleStatusEdit = () => {
            alert('Extended status options coming soon!');
        };
    },
    
    // Modal Management System
    initModals() {
        // Create modal container if it doesn't exist
        if (!document.getElementById('modalContainer')) {
            const container = document.createElement('div');
            container.id = 'modalContainer';
            container.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:none;z-index:9999;';
            container.innerHTML = '<div id="modalContent" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:white;border-radius:12px;max-width:90vw;max-height:90vh;overflow:auto;"></div>';
            document.body.appendChild(container);
        }
        
        // Open modal function
        window.openModal = async (type, leadId) => {
            const container = document.getElementById('modalContainer');
            const content = document.getElementById('modalContent');
            
            // Close radial menu (handled by radial-menu.js)
            
            let url = '';
            switch (type) {
                case 'call':
                    url = `calls.php?action=add&lead_id=${leadId}&modal=1`;
                    break;
                case 'note':
                    url = `notes.php?action=add&lead_id=${leadId}&modal=1`;
                    break;
                case 'task':
                    url = `tasks.php?action=add&lead_id=${leadId}&modal=1`;
                    break;
                case 'demo':
                    url = `dropoff.php?lead_id=${leadId}&modal=1`;
                    break;
                default:
                    return;
            }
            
            try {
                const response = await fetch(url);
                const html = await response.text();
                content.innerHTML = html;
                // Execute any inline scripts returned with the modal content (needed for timer forms, etc.)
                try {
                    const scripts = Array.from(content.querySelectorAll('script'));
                    scripts.forEach((script) => {
                        if (!script) return;
                        const clone = document.createElement('script');
                        clone.type = script.type || 'text/javascript';
                        if (script.src) {
                            clone.src = script.src;
                        } else {
                            clone.textContent = script.textContent;
                        }
                        document.head.appendChild(clone);
                        requestAnimationFrame(() => {
                            if (clone.parentNode) clone.parentNode.removeChild(clone);
                        });
                        if (script.parentNode) {
                            script.parentNode.removeChild(script);
                        }
                    });
                } catch (errScript) {
                    console.error('Modal script execution failed', errScript);
                }
                container.style.display = 'block';
                
                // Handle form submission
                const form = content.querySelector('form[data-ajax]');
                if (form) {
                    form.addEventListener('submit', async (e) => {
                        e.preventDefault();
                        const formData = new FormData(form);
                        
                        try {
                            // Avoid collision with <input name="action"> which can shadow form.action
                            const actionUrl = form.getAttribute('action') || url;
                            const submitResponse = await fetch(actionUrl, {
                                method: 'POST',
                                body: formData
                            });
                            let result;
                            try {
                                result = await submitResponse.json();
                            } catch (errJson) {
                                throw new Error('Invalid server JSON response');
                            }
                            
                            if (result.ok) {
                                container.style.display = 'none';
                                this.showToast(`${type.charAt(0).toUpperCase() + type.slice(1)} added successfully!`, 'success');
                                setTimeout(() => location.reload(), 1000);
                            } else {
                                alert(result.message || 'Error occurred');
                            }
                        } catch (err) {
                            console.error('Form submission error:', err);
                        }
                    });
                }
                
                // Setup close functionality
                window.SalesACPModals = {
                    close: () => {
                        container.style.display = 'none';
                    }
                };
                
            } catch (error) {
                console.error('Modal load error:', error);
                alert('Failed to load modal');
            }
        };
        
        // Close modal on background click
        document.getElementById('modalContainer')?.addEventListener('click', (e) => {
            if (e.target.id === 'modalContainer') {
                e.target.style.display = 'none';
            }
        });
    },
    
    // Celebration Animation
    celebrate() {
        const celebration = document.createElement('div');
        celebration.className = 'celebration-overlay';
        celebration.innerHTML = `
            <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);">
                <div style="font-size:120px;animation:celebration 1s;">🎉</div>
                <div style="font-size:32px;color:var(--success);font-weight:700;margin-top:20px;">Lead Converted!</div>
            </div>
        `;
        document.body.appendChild(celebration);
        
        // Confetti effect
        for (let i = 0; i < 50; i++) {
            const confetti = document.createElement('div');
            confetti.style.cssText = `
                position:absolute;
                width:10px;
                height:10px;
                background:${['#FF1744', '#00C853', '#0066FF', '#FF6D00'][Math.floor(Math.random() * 4)]};
                left:${Math.random() * 100}%;
                top:-10px;
                animation:fall ${2 + Math.random() * 2}s linear;
            `;
            celebration.appendChild(confetti);
        }
        
        setTimeout(() => celebration.remove(), 3000);
    },
    
    // Show Toast Notification
    showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.style.cssText = `
            position:fixed;
            bottom:24px;
            left:50%;
            transform:translateX(-50%);
            padding:12px 24px;
            background:${type === 'success' ? 'var(--success)' : 'var(--primary)'};
            color:white;
            border-radius:8px;
            box-shadow:var(--shadow-lg);
            z-index:10000;
            animation:slideUp 0.3s;
        `;
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
    },
    
    // Keyboard Shortcuts
    bindKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Ctrl/Cmd + K: Quick search (future feature)
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                // Future: Open quick search
            }
            
            // Alt + C: Log Call
            if (e.altKey && e.key === 'c') {
                e.preventDefault();
                openModal('call', <?php echo $leadDetail['id'] ?? 0; ?>);
            }
            
            // Alt + N: Add Note
            if (e.altKey && e.key === 'n') {
                e.preventDefault();
                openModal('note', <?php echo $leadDetail['id'] ?? 0; ?>);
            }
            
            // Alt + T: Create Task
            if (e.altKey && e.key === 't') {
                e.preventDefault();
                openModal('task', <?php echo $leadDetail['id'] ?? 0; ?>);
            }
        });
    }
};

// Handle conversion with celebration
window.handleConversion = (e) => {
    if (confirm('Convert this lead to an approved account?')) {
        // Form will submit normally
        return true;
    }
    e.preventDefault();
    return false;
};

// Quick conversion helper from Activity Timeline menu
function acpConvertToAccount(leadId){
    try {
        if (!leadId) return;
        if (!confirm('Convert this lead to an account?')) return;
        var f = document.createElement('form');
        f.method = 'POST';
        f.action = 'leads.php?id=' + encodeURIComponent(leadId);
        f.style.display = 'none';
        var a = document.createElement('input'); a.type = 'hidden'; a.name = 'action'; a.value = 'convert_to_account'; f.appendChild(a);
        document.body.appendChild(f);
        f.submit();
    } catch(e) { alert('Unable to start conversion.'); }
}

// Legacy functions for compatibility
function showAddModal() {
    document.getElementById('addLeadModal').style.display = 'block';
    // Initialize autocomplete after modal is visible
    attachProxyIfCSP();
}

function hideAddModal() {
    document.getElementById('addLeadModal').style.display = 'none';
}

// Handle "not available" checkboxes in add modal
document.addEventListener('DOMContentLoaded', function() {
    const addEmailNotAvail = document.getElementById('add_email_not_available');
    const addPhoneNotAvail = document.getElementById('add_phone_not_available');
    const addEmail = document.getElementById('add_email');
    const addPhone = document.getElementById('add_phone');
    
    if (addEmailNotAvail && addEmail) {
        addEmailNotAvail.addEventListener('change', function() {
            if (this.checked) {
                addEmail.value = '';
                addEmail.disabled = true;
                addEmail.removeAttribute('required');
            } else {
                addEmail.disabled = false;
                addEmail.setAttribute('required', 'required');
            }
        });
    }
    
    if (addPhoneNotAvail && addPhone) {
        addPhoneNotAvail.addEventListener('change', function() {
            if (this.checked) {
                addPhone.value = '';
                addPhone.disabled = true;
            } else {
                addPhone.disabled = false;
            }
        });
    }
    
    // Handle edit modal checkboxes
    const editEmailNotAvail = document.getElementById('edit_email_not_available');
    const editPhoneNotAvail = document.getElementById('edit_phone_not_available');
    const editEmail = document.getElementById('edit_email');
    const editPhone = document.getElementById('edit_phone');
    
    if (editEmailNotAvail && editEmail) {
        editEmailNotAvail.addEventListener('change', function() {
            if (this.checked) {
                editEmail.disabled = true;
            } else {
                editEmail.disabled = false;
            }
        });
        // Initialize on page load
        if (editEmailNotAvail.checked) {
            editEmail.disabled = true;
        }
    }
    
    if (editPhoneNotAvail && editPhone) {
        editPhoneNotAvail.addEventListener('change', function() {
            if (this.checked) {
                editPhone.disabled = true;
            } else {
                editPhone.disabled = false;
            }
        });
        // Initialize on page load
        if (editPhoneNotAvail.checked) {
            editPhone.disabled = true;
        }
    }
});

<!-- Edit modal functions removed - using dedicated edit-lead.php page -->

function toggleDemoOptions() {
    const checkbox = document.getElementById('demo_dropoff');
    const options = document.getElementById('demo_options');
    options.style.display = checkbox.checked ? 'block' : 'none';
}

function toggleAcc(btn) {
    const next = btn.nextElementSibling;
    if (!next) return;
    next.style.display = next.style.display === 'none' ? 'block' : 'none';
}

// Activity Timeline Functions
function toggleActivityMenu() {
    const menu = document.getElementById('activityAddMenu');
    menu.classList.toggle('active');
    
    // Close other menus
    document.getElementById('viewAllMenu')?.classList.remove('active');
}

function toggleViewAllMenu() {
    const menu = document.getElementById('viewAllMenu');
    menu.classList.toggle('active');
    
    // Close other menus
    document.getElementById('activityAddMenu')?.classList.remove('active');
}

function filterActivities(type, tabBtn) {
    const timeline = document.getElementById('activityTimeline');
    const items = timeline.querySelectorAll('.timeline-item');
    const tabs = document.querySelectorAll('.activity-tab');
    
    // Update active tab
    if (tabBtn) {
        tabs.forEach(tab => tab.classList.remove('active'));
        tabBtn.classList.add('active');
    } else {
        // Find and activate the corresponding tab
        tabs.forEach(tab => {
            if (type === 'all' && tab.textContent.includes('All')) {
                tab.classList.add('active');
            } else if (type === 'call' && tab.textContent.includes('Calls')) {
                tab.classList.add('active');
            } else if (type === 'note' && tab.textContent.includes('Notes')) {
                tab.classList.add('active');
            } else if (type === 'task' && tab.textContent.includes('Tasks')) {
                tab.classList.add('active');
            } else {
                tab.classList.remove('active');
            }
        });
    }
    
    // Filter items
    let visibleCount = 0;
    items.forEach(item => {
        if (type === 'all' || item.dataset.type === type) {
            item.style.display = 'flex';
            visibleCount++;
        } else {
            item.style.display = 'none';
        }
    });
    
    // Show/hide a dedicated empty state element without destroying items
    const typeLabel = type === 'all' ? 'activities' : (type + 's');
    let emptyState = document.getElementById('timelineEmptyState');
    if (!emptyState) {
        emptyState = document.createElement('div');
        emptyState.id = 'timelineEmptyState';
        emptyState.className = 'timeline-empty';
        emptyState.style.display = 'none';
        emptyState.innerHTML = `
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#CBD5E0" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <path d="M12 6v6l4 2"/>
            </svg>
            <p style="margin:4px 0 0 0;"></p>
            <p class="text-muted">Click the + button to add one</p>
        `;
        timeline.appendChild(emptyState);
    }

    // Update visibility and message
    const msg = emptyState.querySelector('p');
    if (msg) { msg.textContent = `No ${typeLabel} yet`; }
    emptyState.style.display = (visibleCount === 0) ? 'block' : 'none';
}

// Close menus when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.header-actions-group')) {
        document.getElementById('activityAddMenu')?.classList.remove('active');
        document.getElementById('viewAllMenu')?.classList.remove('active');
    }
});

function openFollowUpDrawer(leadId) {
    document.getElementById('fu_lead_id').value = leadId;
    document.getElementById('followupDrawer').style.display = 'block';
}

function closeFollowUpDrawer() {
    document.getElementById('followupDrawer').style.display = 'none';
    setTimeout(() => window.location.reload(), 500);
}

// Initialize Premium UI on load
document.addEventListener('DOMContentLoaded', () => {
    PremiumUI.init();
});

// Add fall animation for confetti
const style = document.createElement('style');
style.textContent = `
    @keyframes fall {
        to {
            transform: translateY(100vh) rotate(360deg);
        }
    }
    @keyframes slideUp {
        from {
            transform: translate(-50%, 100px);
            opacity: 0;
        }
        to {
            transform: translate(-50%, 0);
            opacity: 1;
        }
    }
`;
document.head.appendChild(style);

function updateNearbyStatus(message, theme){
    try {
        var el = document.getElementById('nearbyStatus');
        if (!el) return;
        el.textContent = message;
        el.style.color = theme === 'warn' ? '#b45309' : (theme === 'error' ? '#b91c1c' : '#4b5563');
    } catch (e) {}
}

function loadNearby(map, loc, radiusOverride){
    try {
        var list = document.getElementById('nearbyList'); if (!list) return;
        list.innerHTML = '';
        updateNearbyStatus('Searching locations…', 'info');
            var known = (window.__KNOWN_STORES || []);
        function num(v){ try { return typeof v === 'function' ? parseFloat(v()) : parseFloat(v); } catch(e){ return 0; } }
        var latVal = num(loc.lat);
        var lngVal = num(loc.lng);
        var includeGas = document.getElementById('toggle_gas') && document.getElementById('toggle_gas').checked ? 1 : 0;
        var radiusMeters = typeof radiusOverride === 'number' ? radiusOverride : getSelectedRadiusMeters();
        if (!(radiusMeters > 0)) { radiusMeters = 16093.4; }
        var typeSel = (document.getElementById('nearbyType') || {}).value || 'auto';
        var fetchToken = Date.now();
        window.__nearbyFetchToken = fetchToken;
        var desiredCount = 40;
        var lagTimer = setTimeout(function(){
            if (window.__nearbyFetchToken === fetchToken) {
                updateNearbyStatus('Still collecting locations… please hold a moment.', 'warn');
            }
        }, 3000);
        // Use Places API (New) text search proxy; no demo/cached data
        function runSearch(catList){
            var catParam = catList && catList.length ? ('&categories=' + encodeURIComponent(catList.join(','))) : '';
            var url = ((window.__BASE_PREFIX||'')) + '/api/google-places-proxy.php?op=v1_textsearch&lat=' + latVal + '&lng=' + lngVal + '&radius=' + radiusMeters + '&include_gas=' + includeGas + '&details=1' + catParam;
            return fetch(url, { signal: (window.__nearbyAbort && window.__nearbyAbort.signal) }).then(function(r){ return r.json(); });
        }

        var fetchQueues = [];
        if (typeSel === 'auto') {
            fetchQueues.push(['product_kava']);
            fetchQueues.push(['smoke_vape']);
            fetchQueues.push(['herbal']);
            if (includeGas) { fetchQueues.push(['convenience_gas']); }
            fetchQueues.push([]); // catch-all
        } else if (typeSel === 'all') {
            fetchQueues.push([]);
        } else {
            fetchQueues.push([typeSel]);
        }
        if (fetchQueues.length === 0) { fetchQueues.push([]); }
        var resultsById = {};
        (async function(){
            try {
                for (var i = 0; i < fetchQueues.length; i++) {
                    if (window.__nearbyAbort && window.__nearbyAbort.signal.aborted) { clearTimeout(lagTimer); return; }
                    var data = await runSearch(fetchQueues[i]);
                    (data.results || []).forEach(function(item){
                        if (!item || !item.place_id || resultsById[item.place_id]) { return; }
                        resultsById[item.place_id] = item;
                    });
                    var total = Object.keys(resultsById).length;
                    updateNearbyStatus('Found ' + total + ' locations within ' + Math.round(radiusMeters / 1609.344) + ' miles…', 'info');
                    if (total >= desiredCount) { break; }
                }
                if (window.__nearbyAbort && window.__nearbyAbort.signal.aborted) { clearTimeout(lagTimer); return; }
                clearTimeout(lagTimer);
                updateNearbyStatus('Collecting websites and phone numbers…', 'info');
                window.__NEARBY_RESULTS = Object.keys(resultsById).map(function(key){ return resultsById[key]; });
                if (window.__NEARBY_RESULTS.length === 0) {
                    updateNearbyStatus('No locations found for this area.', 'warn');
                    renderNearby();
                    return;
                }
                // compute distances if geometry present
                try {
                    var origin = { lat: latVal, lng: lngVal };
                    window.__NEARBY_RESULTS.forEach(function(p){
                        if (typeof p.distance_m === 'number' && p.distance_m >= 0) {
                            p.__distance = p.distance_m / 1609.344;
                        } else {
                            var locVal = p.geometry && p.geometry.location ? p.geometry.location : {};
                            var lat = (typeof locVal.lat === 'function') ? locVal.lat() : locVal.lat;
                            var lng = (typeof locVal.lng === 'function') ? locVal.lng() : locVal.lng;
                            if (typeof lat === 'number' && typeof lng === 'number') {
                                p.__distance = getDistanceMiles(origin.lat, origin.lng, lat, lng);
                            } else { p.__distance = 9999; }
                        }
                        if (!p.phone && !p.intl_phone && !p.website && !p.websiteUri) {
                            p.__details_pending = true;
                        }
                    });
                } catch(e) {}
                if (window.__nearbyFetchToken !== fetchToken) { return; }
                renderNearby();
                primeNearbyDetails(window.__NEARBY_RESULTS, fetchToken).then(function(){
                    if (window.__nearbyFetchToken === fetchToken) {
                        renderNearby();
                        updateNearbyStatus('Nearby results updated.', 'info');
                    }
                }).catch(function(){
                    if (window.__nearbyFetchToken === fetchToken) {
                        updateNearbyStatus('Ready — showing top matches (details may still be missing).', 'warn');
                    }
                });
            } catch (err) {
                clearTimeout(lagTimer);
                if (err && err.name === 'AbortError') { return; }
                updateNearbyStatus('Unable to load nearby suggestions.', 'error');
                list.innerHTML = '<div class="muted">Unable to load nearby suggestions.</div>';
            }
        })();
    } catch (e) { /* ignore */ }
}

// Parse Google Places API addressComponents into structured fields
function parseGoogleAddressComponents(addressComponents){
    try {
        if (!Array.isArray(addressComponents)) { return {}; }
        var parts = { street_number:'', route:'', locality:'', admin_area_l1:'', postal_code:'', country:'' };
        addressComponents.forEach(function(c){
            var types = c.types || [];
            if (types.indexOf('street_number') !== -1) parts.street_number = c.longText || c.long_name || '';
            if (types.indexOf('route') !== -1) parts.route = c.longText || c.long_name || '';
            if (types.indexOf('locality') !== -1) parts.locality = c.longText || c.long_name || '';
            if (types.indexOf('administrative_area_level_1') !== -1) parts.admin_area_l1 = c.shortText || c.short_name || '';
            if (types.indexOf('postal_code') !== -1) parts.postal_code = c.longText || c.long_name || '';
            if (types.indexOf('country') !== -1) parts.country = c.shortText || c.short_name || '';
        });
        return {
            address_line1: (parts.street_number + ' ' + parts.route).trim(),
            city: parts.locality,
            state: parts.admin_area_l1,
            zip_code: parts.postal_code,
            postal_code: parts.postal_code,
            country: parts.country || 'USA'
        };
    } catch(e) {
        return {};
    }
}

function primeNearbyDetails(results, fetchToken){
    return new Promise(function(resolve){
        try {
            if (!Array.isArray(results) || results.length === 0) { resolve(); return; }
            var lagTimer = setTimeout(function(){
                if (window.__nearbyFetchToken === fetchToken) {
                    updateNearbyStatus('Finalizing contact details…', 'warn');
                }
            }, 2000);
            Promise.all(results.map(function(item){
                if (!item || !item.place_id) { return Promise.resolve(); }
                var pid = item.place_id;
                var url = ((window.__BASE_PREFIX||'')) + '/api/google-places-proxy.php?op=v1_details&place_id=' + encodeURIComponent(pid) + '&fields=id,displayName,internationalPhoneNumber,nationalPhoneNumber,websiteUri,googleMapsUri,addressComponents';
                return fetch(url, { signal: (window.__nearbyAbort && window.__nearbyAbort.signal) }).then(function(r){ return r.json(); }).then(function(data){
                    if (!data || !data.id) { return; }
                    results.forEach(function(entry){
                        if (entry.place_id === data.id) {
                            entry.intl_phone = data.internationalPhoneNumber || entry.intl_phone;
                            entry.phone = data.nationalPhoneNumber || entry.phone;
                            entry.website = data.websiteUri || entry.website;
                            entry.maps_uri = data.googleMapsUri || entry.maps_uri;
                            // Parse and populate address fields from addressComponents
                            if (data.addressComponents) {
                                var parsed = parseGoogleAddressComponents(data.addressComponents);
                                entry.address_line1 = parsed.address_line1 || entry.address_line1;
                                entry.city = parsed.city || entry.city;
                                entry.state = parsed.state || entry.state;
                                entry.zip_code = parsed.zip_code || entry.zip_code;
                                entry.postal_code = parsed.postal_code || entry.postal_code;
                                entry.country = parsed.country || entry.country;
                            }
                        }
                    });
                }).catch(function(){ /* ignore */ });
            })).then(function(){
                clearTimeout(lagTimer);
                resolve();
            }).catch(function(){ clearTimeout(lagTimer); resolve(); });
        } catch (e) { resolve(); }
    });
}

function refreshNearby(){
    try { if (window.__nearbyAbort) { window.__nearbyAbort.abort(); } } catch(e) {}
    window.__nearbyAbort = new AbortController();
    // Re-run geocode so we have lat/lng then call loadNearby
    var addr = (document.getElementById('addr_full_edit') && document.getElementById('addr_full_edit').value) || (document.getElementById('addr_full') && document.getElementById('addr_full').value) || '';
    var addrFallback = (document.getElementById('autocomplete_address_edit') || {}).value || (document.getElementById('autocomplete_address') || {}).value || '';
    var useAddr = addr || addrFallback;
    if (!useAddr) return;
    fetch(((window.__BASE_PREFIX||'')) + '/api/google-places-proxy.php?op=geocode_addr&address=' + encodeURIComponent(useAddr))
        .then(function(r){ return r.json(); })
        .then(function(data){
            var res = (data.results && data.results[0]); if (!res) return;
            var radiusMeters = getSelectedRadiusMeters();
            loadNearby(null, { lat: res.geometry.location.lat, lng: res.geometry.location.lng }, radiusMeters);
        });
}

function requestLeadAssignment(place){
    try {
        fetch('tasks.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({
            action: 'request_assignment',
            subject: 'Lead assignment request: ' + (place.name || 'Store'),
            details: JSON.stringify({ place_id: place.place_id, name: place.name, address: place.vicinity })
        }) }).then(function(){ alert('Assignment request sent to admin.'); });
    } catch (e) { alert('Failed to send request.'); }
}

// Archive place from nearby section - hide from future searches
function archivePlaceFromNearby(placeId, placeName, placeAddress){
    if (!confirm('Hide "' + placeName + '" from future nearby searches?')) return;
    
    fetch('leads.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'archive_nearby_place',
            place_id: placeId,
            place_name: placeName,
            place_address: placeAddress,
            archive_note: 'Hidden from nearby searches'
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            if (typeof PremiumUI !== 'undefined' && PremiumUI.showToast) {
                PremiumUI.showToast('Place hidden from searches', 'success');
            }
            // Refresh nearby results
            setTimeout(function(){
                var refreshBtn = document.querySelector('button[onclick*="loadNearby"]');
                if (refreshBtn) refreshBtn.click();
            }, 500);
            // Update archived places panel
            loadArchivedPlacesPanel();
        } else {
            alert('Failed to hide place');
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error hiding place');
    });
}

// Toggle archived leads side panel
function toggleArchivedLeadsPanel(){
    var panel = document.getElementById('archivedLeadsPanel');
    var tab = document.getElementById('archivedLeadsTab');
    if (!panel || !tab) return;
    
    var isOpen = panel.style.right === '0px';
    if (isOpen) {
        panel.style.right = '-350px';
        tab.style.right = '0';
    } else {
        panel.style.right = '0';
        tab.style.right = '350px';
        loadArchivedLeadsPanel();
    }
}

// Load archived places into side panel
function loadArchivedPlacesPanel(){
    var content = document.getElementById('archivedLeadsContent');
    var countEl = document.getElementById('archivedCount');
    if (!content) return;
    
    content.innerHTML = '<div class="muted small" style="text-align:center; padding:20px;">Loading...</div>';
    
    fetch('leads.php?action=get_archived_places')
    .then(r => r.json())
    .then(data => {
        if (data.ok && data.places) {
            var places = data.places;
            window.__ARCHIVED_PLACES = places; // Store globally for filtering
            if (countEl) countEl.textContent = places.length;
            
            if (places.length === 0) {
                content.innerHTML = '<div class="muted small" style="text-align:center; padding:20px;">No hidden places</div>';
                return;
            }
            
            var html = '<div style="display:grid; gap:12px;">';
            places.forEach(function(place){
                var name = place.place_name || 'Unknown';
                var addr = place.place_address || '—';
                var archived = place.archived_at ? new Date(place.archived_at).toLocaleDateString() : '';
                var note = place.archive_note || '';
                var autoArchived = (place.total_archive_count >= 3);
                
                html += '<div style="padding:12px; border:1px solid #e5e7eb; border-radius:8px; background:#fafafa;">';
                html += '<div style="font-weight:600; margin-bottom:4px;">' + name + '</div>';
                html += '<div style="font-size:12px; color:#6b7280; margin-bottom:8px;">' + addr + '</div>';
                if (note) {
                    html += '<div style="font-size:11px; color:#9ca3af; font-style:italic; margin-bottom:8px;">"' + note + '"</div>';
                }
                html += '<div style="font-size:11px; color:#9ca3af; margin-bottom:8px;">Hidden: ' + archived + '</div>';
                if (autoArchived) {
                    html += '<div style="font-size:10px; background:#fef3c7; color:#92400e; padding:4px 6px; border-radius:4px; display:inline-block; margin-bottom:8px;">AUTO-HIDDEN (3+ agents)</div><br>';
                }
                html += '<button class="btn small secondary" onclick="unarchivePlaceFromPanel(' + place.id + ')" style="width:100%;">↩️ Show Again</button>';
                html += '</div>';
            });
            html += '</div>';
            
            content.innerHTML = html;
        } else {
            content.innerHTML = '<div class="muted small" style="text-align:center; padding:20px;">Error loading hidden places</div>';
        }
    })
    .catch(err => {
        console.error(err);
        content.innerHTML = '<div class="muted small" style="text-align:center; padding:20px;">Error loading hidden places</div>';
    });
}

// Alias for compatibility
function loadArchivedLeadsPanel() {
    loadArchivedPlacesPanel();
}

// Unarchive place from panel
function unarchivePlaceFromPanel(archiveId){
    fetch('leads.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'unarchive_nearby_place',
            archive_id: archiveId
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            if (typeof PremiumUI !== 'undefined' && PremiumUI.showToast) {
                PremiumUI.showToast('Place restored to searches', 'success');
            }
            // Reload panel
            loadArchivedPlacesPanel();
            // Refresh nearby if visible
            setTimeout(function(){
                var refreshBtn = document.querySelector('button[onclick*="loadNearby"]');
                if (refreshBtn) refreshBtn.click();
            }, 500);
        } else {
            alert('Failed to restore place');
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error restoring place');
    });
}

// Load archived count on page load
document.addEventListener('DOMContentLoaded', function(){
    setTimeout(loadArchivedPlacesPanel, 1000);
});

// Request nearby account reassignment (existing account, pending agent assignment)
function requestNearbyAccount(place){
    try {
        fetch('tasks.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({
            action: 'request_assignment',
            subject: 'Nearby account assignment request: ' + (place.name || 'Store'),
            details: JSON.stringify({ type: 'nearby_account', place_id: place.place_id, name: place.name, address: place.vicinity })
        }) }).then(function(){ alert('Nearby account request sent to admin.'); });
    } catch (e) { alert('Failed to send request.'); }
}

async function renderNearby(){
    try {
        var list = document.getElementById('nearbyList');
        if (!list) { return; }
        
        // Get archived places list
        var archivedPlaces = window.__ARCHIVED_PLACES || [];
        
        var results = (window.__NEARBY_RESULTS || []).slice();
        var includeGas = document.getElementById('toggle_gas') && document.getElementById('toggle_gas').checked ? 1 : 0;
        var typeSel = (document.getElementById('nearbyType') || {}).value || 'auto';
        results = results.filter(function(place){
            var cat = place && place.category_key ? place.category_key : 'core';
            if (typeSel === 'all') { return true; }
            if (typeSel === 'auto') { return includeGas || cat !== 'convenience_gas'; }
            return cat === typeSel;
        });
        
        // Filter out archived places
        results = results.filter(function(place){
            var isArchived = archivedPlaces.some(function(ap){
                return ap.place_name === place.name || ap.place_address === (place.vicinity || place.formatted_address);
            });
            return !isArchived;
        });
        
        var sort = (document.getElementById('nearbySort') || {}).value || 'distance';
        if (sort === 'name') {
            results.sort(function(a,b){ return (a.name||'').localeCompare(b.name||''); });
        } else {
            results.sort(function(a,b){ return (a.__distance||9999) - (b.__distance||9999); });
        }
        list.innerHTML = '';
        var added = 0;
        for (var idx = 0; idx < results.length && added < 12; idx++) {
            const place = results[idx];
            if (!place || !place.place_id) { continue; }
            const addr = place.vicinity || place.formatted_address || '';
            let info = null;
            try {
                const resp = await fetch('leads.php?action=check_place&name=' + encodeURIComponent(place.name || '') + '&addr=' + encodeURIComponent(addr));
                info = await resp.json();
            } catch (err) {
                continue;
            }
            // Skip if already assigned to someone else (not me)
            if (info && info.assigned && !info.assigned_to_me) {
                continue;
            }
            
            var row = document.createElement('div'); row.className = 'nearby-item';
            var left = document.createElement('div');
            var dist = (typeof place.__distance === 'number' && place.__distance < 9900) ? (' • ' + place.__distance.toFixed(1) + ' mi') : '';
            var contact = '';
            if (place.phone) contact += ' • ' + place.phone;
            else if (place.intl_phone) contact += ' • ' + place.intl_phone;
            else if (place.__details_pending) contact += ' • fetching phone…';
            var website = place.website ? (' • <a href="' + place.website + '" target="_blank" class="link-premium">website</a>') : '';
            var catChip = place.category_key ? (' <span class="chip chip-status" style="margin-left:6px;">' + place.category_key.replace('_','/') + '</span>') : '';
            var assignedToMeBadge = (info && info.assigned_to_me) ? ' <span style="display:inline-block; padding:2px 6px; background:#dbeafe; color:#1e40af; border-radius:4px; font-size:10px; margin-left:6px;">MY LEAD</span>' : '';
            left.innerHTML = '<div style="font-weight:600;color:#1f2937">' + (place.name || 'Store') + catChip + assignedToMeBadge + '</div>' +
                             '<div class="nearby-meta">' + addr + dist + contact + website + '</div>';
            var right = document.createElement('div');
            right.style.display = 'flex';
            right.style.gap = '8px';
            right.style.alignItems = 'center';
            
            if (!info || (!info.assigned && !info.unassigned)) {
                var addBtn = document.createElement('button');
                addBtn.className = 'btn small';
                addBtn.style.background = '#059669';
                addBtn.innerText = 'Add to Leads';
                addBtn.onclick = (function(placeSnapshot, addrSnapshot, buttonEl){
                    return function(){
                        if (buttonEl.dataset.loading === '1') { return; }
                        buttonEl.dataset.loading = '1';
                        buttonEl.disabled = true;
                        buttonEl.innerText = 'Adding…';
                        var phoneVal = placeSnapshot.phone || placeSnapshot.intl_phone || placeSnapshot.national_phone || '';
                        var fd = new URLSearchParams({
                            action: 'quick_add_from_place',
                            company_name: (placeSnapshot.name || ''),
                            address: addrSnapshot,
                            phone: phoneVal,
                            website: (placeSnapshot.website || ''),
                            address_line1: (placeSnapshot.address_line1 || ''),
                            address_line2: (placeSnapshot.address_line2 || ''),
                            city: (placeSnapshot.city || ''),
                            state: (placeSnapshot.state || ''),
                            zip_code: (placeSnapshot.postal_code || ''),
                            country: (placeSnapshot.country || 'USA'),
                            place_id: (placeSnapshot.place_id || ''),
                            attribution_source: 'leads_nearby'
                        });
                        fetch('leads.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd })
                            .then(function(r){ return r.json(); })
                            .then(function(res){
                                if (res && res.ok) {
                                    var toastMsg = 'Lead added successfully';
                                    if (res.mode === 'revived') {
                                        toastMsg = 'Lead reactivated and reassigned to you';
                                    } else if (res.mode === 'active') {
                                        toastMsg = 'Lead already exists and is now on your radar';
                                    }
                                    if (typeof PremiumUI !== 'undefined' && PremiumUI.showToast) {
                                        PremiumUI.showToast(toastMsg, 'success');
                                    }
                                    if (buttonEl) {
                                        buttonEl.innerText = 'Added';
                                        buttonEl.classList.add('btn-disabled');
                                        buttonEl.disabled = true;
                                    }
                                    updateNearbyStatus('Lead added. Use Refresh to update results.', 'info');
                                } else {
                                    var msg = (res && (res.message || res.error)) ? (res.message || res.error) : 'Unable to add lead.';
                                    if (typeof PremiumUI !== 'undefined' && PremiumUI.showToast) {
                                        PremiumUI.showToast(msg, 'error');
                                    } else {
                                        alert(msg);
                                    }
                                    buttonEl.disabled = false;
                                    buttonEl.innerText = 'Add to Leads';
                                    delete buttonEl.dataset.loading;
                                }
                            })
                            .catch(function(err){
                                console.error(err);
                                if (typeof PremiumUI !== 'undefined' && PremiumUI.showToast) {
                                    PremiumUI.showToast('Server error while adding lead.', 'error');
                                } else {
                                    alert('Server error while adding lead.');
                                }
                                buttonEl.disabled = false;
                                buttonEl.innerText = 'Add to Leads';
                                delete buttonEl.dataset.loading;
                            });
                    };
                })(place, addr, addBtn);
                right.appendChild(addBtn);
                
                // Add archive button next to "Add to Leads" for ALL places
                var archiveBtn = document.createElement('button');
                archiveBtn.className = 'btn small';
                archiveBtn.style.cssText = 'background:#dc2626; color:#fff; border:none; padding:6px 10px; font-size:18px; line-height:1;';
                archiveBtn.innerHTML = '🚫';
                archiveBtn.title = 'Hide this place from future searches';
                archiveBtn.onclick = (function(placeSnapshot, addrSnapshot){
                    return function(e){
                        e.stopPropagation();
                        archivePlaceFromNearby(placeSnapshot.place_id || '', placeSnapshot.name || 'Store', addrSnapshot);
                    };
                })(place, addr);
                right.appendChild(archiveBtn);
            } else if (info.unassigned && !info.assigned) {
                var reqBtn = document.createElement('button');
                reqBtn.className = 'btn small';
                reqBtn.style.background = '#0369a1';
                reqBtn.innerText = 'Request Lead';
                reqBtn.onclick = (function(placeSnapshot, addrSnapshot){
                    return function(){ requestLeadAssignment({ place_id: placeSnapshot.place_id, name: placeSnapshot.name, vicinity: addrSnapshot }); };
                })(place, addr);
                right.appendChild(reqBtn);
            } else {
                continue;
            }
            row.appendChild(left);
            row.appendChild(right);
            list.appendChild(row);
            added++;
        }
        if (added === 0) {
            list.innerHTML = '<div class="muted">No nearby matches found. Try enabling gas/convenience and pressing Refresh.</div>';
        }
    } catch (e) {}
}

function getDistanceMiles(lat1, lon1, lat2, lon2){
    function toRad(v){ return v * Math.PI / 180; }
    var R = 3958.8; // miles
    var dLat = toRad(lat2-lat1), dLon = toRad(lon2-lon1);
    var a = Math.sin(dLat/2)*Math.sin(dLat/2) + Math.cos(toRad(lat1))*Math.cos(toRad(lat2))*Math.sin(dLon/2)*Math.sin(dLon/2);
    var c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    return R * c;
}

// Skip loading Google JS to avoid CSP errors; rely on proxy-based autocomplete and static map
// API key is not exposed; backend proxy holds the key
// attachProxyIfCSP(); // Disabled - bindProxyAutocomplete not defined in this context
// expose known stores for filtering nearby list
window.__KNOWN_STORES = <?php echo json_encode($knownStores ?? []); ?>;
// Lazy init: only when section is expanded
 function toggleNearbySection(){
    var wrap = document.getElementById('nearbyContent');
    var btn = document.getElementById('nearbyToggleBtn');
    if (!wrap || !btn) return;
    var showing = wrap.style.display === 'block';
    wrap.style.display = showing ? 'none' : 'block';
    btn.textContent = showing ? 'Show map & leads' : 'Hide map & leads';
    if (!showing) { setTimeout(initLeadMiniMap, 10); }
}
// Lead preset address from server (detail view)
try { var presetAddr = <?php echo json_encode(isset($leadDetail) ? ($leadDetail['address'] ?: trim(($leadDetail['address_line1'] ?? '') . ', ' . ($leadDetail['city'] ?? '') . ', ' . ($leadDetail['state'] ?? '') . ' ' . ($leadDetail['zip_code'] ?? '') . ', ' . (($leadDetail['country'] ?? 'USA')))) : ''); ?>; if (presetAddr) window.__LEAD_ADDRESS = presetAddr; } catch(e) {}

// Quick Status: chip to hidden input
document.addEventListener('click', function(e){
    var btn = e.target.closest('.status-chip');
    if (!btn) return;
    var v = btn.getAttribute('data-status');
    var input = document.getElementById('qs_lead_status');
    if (input) { input.value = v; }
});

function getSelectedRadiusMeters(){
    var radiusSel = document.getElementById('nearbyRadius');
    var miles = radiusSel ? parseFloat(radiusSel.value || '10') : 10;
    if (!(miles > 0)) { miles = 10; }
    return miles * 1609.344;
}

function initLeadMiniMap() {
    try {
        var addr = (document.getElementById('addr_full_edit') && document.getElementById('addr_full_edit').value) || (document.getElementById('addr_full') && document.getElementById('addr_full').value) || '';
        var addrFallback = (document.getElementById('autocomplete_address_edit') || {}).value || (document.getElementById('autocomplete_address') || {}).value || '';
        var useAddr = addr || addrFallback;
        if (!useAddr) return;
        fetch(((window.__BASE_PREFIX||'')) + '/api/google-places-proxy.php?op=geocode_addr&address=' + encodeURIComponent(useAddr))
            .then(function(r){ return r.json(); })
            .then(function(data){
                var res = (data.results && data.results[0]); if (!res) return;
                loadNearby(null, { lat: res.geometry.location.lat, lng: res.geometry.location.lng }, getSelectedRadiusMeters());
            });
    } catch (e) { /* ignore */ }
}

// Address autofill helper for lead modals
function leadsFillAddressFields(place, mode){
    try {
        if (!place) return;
        var suffix = mode === 'edit' ? '_edit' : '';
        var ac = place.address_components || [];
        var parts = { street_number:'', route:'', locality:'', admin_area_l1:'', postal_code:'', country:'' };
        (ac||[]).forEach(function(c){
            if ((c.types||[]).indexOf('street_number') !== -1) parts.street_number = c.long_name;
            if ((c.types||[]).indexOf('route') !== -1) parts.route = c.long_name;
            if ((c.types||[]).indexOf('locality') !== -1) parts.locality = c.long_name;
            if ((c.types||[]).indexOf('administrative_area_level_1') !== -1) parts.admin_area_l1 = c.short_name;
            if ((c.types||[]).indexOf('postal_code') !== -1) parts.postal_code = c.long_name;
            if ((c.types||[]).indexOf('country') !== -1) parts.country = c.short_name;
        });
        var line1 = (parts.street_number + ' ' + parts.route).trim();
        var $ = function(id){ return document.getElementById(id); };
        if ($('addr_line1' + suffix)) $('addr_line1' + suffix).value = line1 || (place.name || '');
        if ($('addr_line2' + suffix)) $('addr_line2' + suffix).value = '';
        if ($('addr_city' + suffix)) $('addr_city' + suffix).value = parts.locality || '';
        if ($('addr_state' + suffix)) $('addr_state' + suffix).value = parts.admin_area_l1 || '';
        if ($('addr_zip' + suffix)) $('addr_zip' + suffix).value = parts.postal_code || '';
        if ($('addr_country' + suffix)) $('addr_country' + suffix).value = parts.country || 'USA';
        if ($('addr_full' + suffix)) $('addr_full' + suffix).value = place.formatted_address || '';
        if ($('addr_postal' + suffix)) $('addr_postal' + suffix).value = parts.postal_code || '';
    } catch(e) {
        console.error('Error filling address fields:', e);
    }
}

// Bind proxy-based autocomplete to address inputs
function bindProxyAutocomplete(input, mode){
    if (!input) return;
    
    var dl = document.createElement('div');
    dl.style.cssText = 'position:absolute; background:#fff; border:1px solid #d1d5db; border-radius:6px; box-shadow:0 4px 8px rgba(0,0,0,0.1); max-height:200px; overflow-y:auto; z-index:50000; display:none;';
    document.body.appendChild(dl);
    
    function positionList(){
        if (dl.children.length === 0) { dl.style.display = 'none'; return; }
        var rect = input.getBoundingClientRect();
        dl.style.left = rect.left + window.scrollX + 'px';
        dl.style.top = (rect.bottom + window.scrollY + 2) + 'px';
        dl.style.width = rect.width + 'px';
        dl.style.display = 'block';
    }
    
    var debounce = null;
    input.addEventListener('input', function(){
        clearTimeout(debounce);
        debounce = setTimeout(function(){
            positionList();
            var val = input.value;
            if (val.length < 3) { dl.innerHTML = ''; dl.style.display = 'none'; return; }
            
            var session = (window.__leads_ac_session || (window.__leads_ac_session = Math.random().toString(36).slice(2)));
            fetch(((window.__BASE_PREFIX||'')) + '/api/google-places-proxy.php?op=ac&q=' + encodeURIComponent(val) + '&session=' + encodeURIComponent(session))
                .then(function(r){ return r.json(); })
                .then(function(data){
                    dl.innerHTML='';
                    if (!data.predictions || data.predictions.length === 0) {
                        dl.style.display = 'none';
                        return;
                    }
                    (data.predictions||[]).slice(0,5).forEach(function(p){
                        var item = document.createElement('div');
                        item.style.cssText = 'padding:10px 12px; cursor:pointer; border-bottom:1px solid #f3f4f6;';
                        item.textContent = p.description;
                        item.onmouseenter=function(){ this.style.background='#f9fafb'; };
                        item.onmouseleave=function(){ this.style.background='#fff'; };
                        item.onclick = function(){
                            input.value = p.description; 
                            dl.innerHTML='';
                            dl.style.display = 'none';
                            // Fetch place details and fill address fields
                            fetch(((window.__BASE_PREFIX||'')) + '/api/google-places-proxy.php?op=details&place_id=' + encodeURIComponent(p.place_id))
                                .then(function(r){ return r.json(); })
                                .then(function(d){ 
                                    leadsFillAddressFields(d.result || d, mode); 
                                })
                                .catch(function(err){
                                    console.error('Error fetching place details:', err);
                                });
                        };
                        dl.appendChild(item);
                    });
                    positionList();
                })
                .catch(function(err){
                    console.error('Autocomplete error:', err);
                    dl.innerHTML = '';
                    dl.style.display = 'none';
                });
        }, 300);
    });
    
    input.addEventListener('blur', function(){ 
        setTimeout(function(){ 
            dl.innerHTML=''; 
            dl.style.display = 'none';
        }, 200); 
    });
    
    input.addEventListener('keydown', function(e){
        if (e.key === 'Escape') {
            dl.innerHTML = '';
            dl.style.display = 'none';
        }
    });
    
    input.addEventListener('focus', function(){
        positionList();
    });
    
    window.addEventListener('resize', positionList);
    window.addEventListener('scroll', positionList, true);
    
    // Find modal container and add scroll listener
    var modalContainer = input.closest('[style*="overflow"]');
    if (modalContainer) {
        modalContainer.addEventListener('scroll', positionList);
    }
}

function attachProxyIfCSP(){
    // Initialize autocomplete for both add and edit modals
    setTimeout(function(){
        var add = document.getElementById('autocomplete_address'); 
        if (add && !add.dataset.autocompleteInit) { 
            bindProxyAutocomplete(add, 'add');
            add.dataset.autocompleteInit = 'true';
        }
        var edit = document.getElementById('autocomplete_address_edit'); 
        if (edit && !edit.dataset.autocompleteInit) { 
            bindProxyAutocomplete(edit, 'edit');
            edit.dataset.autocompleteInit = 'true';
        }
    }, 100);
}

// Use My Location feature
function useMyLocation(mode){
    if (!navigator.geolocation) { 
        alert('Geolocation not supported on this device.'); 
        return; 
    }
    
    var suffix = mode === 'edit' ? '_edit' : '';
    var input = document.getElementById('autocomplete_address' + suffix);
    
    if (input) {
        input.value = 'Detecting location...';
        input.disabled = true;
    }
    
    navigator.geolocation.getCurrentPosition(
        function(position){
            var lat = position.coords.latitude;
            var lng = position.coords.longitude;
            
            fetch(((window.__BASE_PREFIX||'')) + '/api/google-places-proxy.php?op=geocode&lat=' + lat + '&lng=' + lng)
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if (data.results && data.results[0]) {
                        var place = data.results[0];
                        if (input) {
                            input.value = place.formatted_address || '';
                            input.disabled = false;
                        }
                        leadsFillAddressFields(place, mode);
                    } else {
                        if (input) {
                            input.value = '';
                            input.disabled = false;
                        }
                        alert('Could not determine address from location.');
                    }
                })
                .catch(function(err){
                    console.error('Geocoding error:', err);
                    if (input) {
                        input.value = '';
                        input.disabled = false;
                    }
                    alert('Error getting address from location.');
                });
        },
        function(error){
            console.error('Geolocation error:', error);
            if (input) {
                input.value = '';
                input.disabled = false;
            }
            alert('Unable to get your location: ' + error.message);
        }
    );
}

// Clear address fields
function clearAddress(mode){
    var suffix = mode === 'edit' ? '_edit' : '';
    var $ = function(id){ return document.getElementById(id); };
    
    if ($('autocomplete_address' + suffix)) $('autocomplete_address' + suffix).value = '';
    if ($('addr_line1' + suffix)) $('addr_line1' + suffix).value = '';
    if ($('addr_line2' + suffix)) $('addr_line2' + suffix).value = '';
    if ($('addr_city' + suffix)) $('addr_city' + suffix).value = '';
    if ($('addr_state' + suffix)) $('addr_state' + suffix).value = '';
    if ($('addr_zip' + suffix)) $('addr_zip' + suffix).value = '';
    if ($('addr_country' + suffix)) $('addr_country' + suffix).value = 'USA';
    if ($('addr_full' + suffix)) $('addr_full' + suffix).value = '';
    if ($('addr_postal' + suffix)) $('addr_postal' + suffix).value = '';
}

async function startLeadSampleSuite(requestId, btn) {
    if (!requestId) return;
    const originalText = btn ? btn.textContent : null;
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Starting...';
    }
    try {
        const response = await fetch('<?php echo api_url('/api/sample-suite.php?action=start'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({sample_request_id: requestId})
        });
        const data = await response.json();
        if (!data.ok) {
            throw new Error(data.error || 'Unable to start automation');
        }
        alert('Sample Suite launched successfully.');
        window.location.reload();
    } catch (err) {
        if (btn) {
            btn.disabled = false;
            btn.textContent = originalText;
        }
        alert('Failed to start Sample Suite: ' + err.message);
    }
}

async function cancelLeadSampleSuite(enrollmentId, btn) {
    if (!enrollmentId) return;
    if (!confirm('Pause Sample Suite for this request?')) {
        return;
    }
    const originalText = btn ? btn.textContent : null;
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Pausing...';
    }
    try {
        const response = await fetch('<?php echo api_url('/api/sample-suite.php?action=cancel'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({enrollment_id: enrollmentId})
        });
        const data = await response.json();
        if (!data.ok) {
            throw new Error(data.error || 'Unable to pause automation');
        }
        alert('Sample Suite paused.');
        window.location.reload();
    } catch (err) {
        if (btn) {
            btn.disabled = false;
            btn.textContent = originalText;
        }
        alert('Failed to pause Sample Suite: ' + err.message);
    }
}

async function resetSampleSuite(requestId, btn) {
    if (!requestId) return;
    if (!confirm('Stop and reset the Sample Suite for this request?')) {
        return;
    }
    const originalText = btn ? btn.textContent : null;
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Resetting...';
    }
    try {
        const response = await fetch('<?php echo api_url('/api/sample-suite.php?action=reset'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({sample_request_id: requestId})
        });
        const data = await response.json();
        if (!data.ok) {
            throw new Error(data.error || 'Unable to reset automation');
        }
        alert('Sample Suite reset. You can restart it whenever you’re ready.');
        window.location.reload();
    } catch (err) {
        if (btn) {
            btn.disabled = false;
            btn.textContent = originalText;
        }
        alert('Failed to reset Sample Suite: ' + err.message);
    }
}

async function markSampleSuiteSale(requestId, btn) {
    if (!requestId) return;
    if (!confirm('Mark this sample request as converted to a sale?')) {
        return;
    }
    const originalText = btn ? btn.textContent : null;
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Saving...';
    }
    try {
        const response = await fetch('<?php echo api_url('/api/sample-suite.php?action=mark_sale'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({sample_request_id: requestId})
        });
        const data = await response.json();
        if (!data.ok) {
            throw new Error(data.error || (data.result && data.result.error) || 'Unable to mark sale');
        }
        alert('Sale recorded and automation completed.');
        window.location.reload();
    } catch (err) {
        if (btn) {
            btn.disabled = false;
            btn.textContent = originalText;
        }
        alert('Failed to mark sale: ' + err.message);
    }
}

async function quickStartSampleSuite(entityId, entityType, btn) {
    if (!entityId) return;
    const originalText = btn ? btn.textContent : null;
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Sending...';
    }
    try {
        const response = await fetch('<?php echo api_url('/api/sample-suite.php?action=quick_start'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({entity_type: entityType, entity_id: entityId})
        });
        const data = await response.json();
        if (!data.ok) {
            throw new Error(data.error || 'Unable to send kickoff');
        }
        alert('Kickoff email queued successfully.');
        window.location.reload();
    } catch (err) {
        if (btn) {
            btn.disabled = false;
            btn.textContent = originalText;
        }
        alert('Failed to send kickoff: ' + err.message);
    }
}

const suiteStatusEndpoint = '<?php echo api_url('/api/sample-suite.php'); ?>';
const suiteStepLabelMap = <?php echo json_encode($sampleSuiteStepLabels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

async function openSuiteTimeline(sampleRequestId) {
    if (!sampleRequestId) return;
    const modal = document.getElementById('suiteTimelineModal');
    const body = document.getElementById('suiteTimelineBody');
    modal.classList.add('open');
    body.innerHTML = '<div class="muted small">Loading automation history…</div>';
    try {
        const response = await fetch(`${suiteStatusEndpoint}?action=status&sample_request_id=${sampleRequestId}`);
        const data = await response.json();
        if (!data.ok || !Array.isArray(data.items) || !data.items.length) {
            throw new Error(data.error || 'No automation data available yet.');
        }
        body.innerHTML = buildSuiteTimelineHtml(data.items[0]);
    } catch (err) {
        body.innerHTML = `<div class="alert alert-danger">${err.message}</div>`;
    }
}

function closeSuiteTimeline() {
    const modal = document.getElementById('suiteTimelineModal');
    if (modal) {
        modal.classList.remove('open');
    }
}

function buildSuiteTimelineHtml(record) {
    const engagement = record.behavior_state || {};
    const attempts = `${record.attempt_count || 0} / ${record.max_attempts || 10}`;
    const opens = engagement.opens || 0;
    const clicks = engagement.clicks || 0;
    const badge = buildSuiteStatusBadge(record);

    return `
        <div class="suite-timeline-grid">
            <div class="suite-metric">
                <div class="suite-metric-label">Status</div>
                <div class="suite-metric-value">${badge}</div>
            </div>
            <div class="suite-metric">
                <div class="suite-metric-label">Attempts</div>
                <div class="suite-metric-value">${attempts}</div>
            </div>
            <div class="suite-metric">
                <div class="suite-metric-label">Opens</div>
                <div class="suite-metric-value">${opens}</div>
            </div>
            <div class="suite-metric">
                <div class="suite-metric-label">Clicks</div>
                <div class="suite-metric-value">${clicks}</div>
            </div>
        </div>
        <div class="suite-next-action">${computeSuiteNextAction(record)}</div>
        <div class="suite-step-list">
            ${renderSuiteSteps(record.steps)}
        </div>
    `;
}

function buildSuiteStatusBadge(record) {
    if (record.sale_detected_at) {
        return `<span class="suite-badge success">Sale Logged (${formatSuiteDate(record.sale_detected_at)})</span>`;
    }
    if (record.unsubscribe_at) {
        return `<span class="suite-badge warn">Unsubscribed (${formatSuiteDate(record.unsubscribe_at)})</span>`;
    }
    const status = record.enrollment_status || record.suite_status || 'pending';
    return `<span class="suite-badge info">${status.replace(/_/g, ' ')}</span>`;
}

function computeSuiteNextAction(record) {
    if (record.sale_detected_at) {
        return `Cycle completed. A sale was recorded on ${formatSuiteDate(record.sale_detected_at)}.`;
    }
    if (record.unsubscribe_at) {
        return `Automation paused because the contact unsubscribed on ${formatSuiteDate(record.unsubscribe_at)}. Follow up manually if appropriate.`;
    }
    const futureSteps = (record.steps || []).filter(step => step.status === 'scheduled');
    if (futureSteps.length) {
        futureSteps.sort((a, b) => new Date(a.scheduled_for) - new Date(b.scheduled_for));
        const next = futureSteps[0];
        const label = suiteStepLabelMap[next.step_key] || next.step_key;
        return `Next automated step <strong>${label}</strong> is scheduled for ${formatSuiteDate(next.scheduled_for)}.`;
    }
    if ((record.attempt_count || 0) >= (record.max_attempts || 10)) {
        return 'Automation has reached its attempt limit. A human follow-up is recommended.';
    }
    return 'No upcoming sends are scheduled. Share the portal link or follow up manually to keep the conversation moving.';
}

function renderSuiteSteps(steps) {
    if (!Array.isArray(steps) || !steps.length) {
        return '<div class="muted small" style="padding:16px 0;">No workflow events have been recorded yet.</div>';
    }
    return steps.map(step => {
        const label = suiteStepLabelMap[step.step_key] || step.step_key;
        const status = step.status ? step.status.replace('_', ' ') : 'scheduled';
        const timing = step.sent_at
            ? `Sent ${formatSuiteDate(step.sent_at)}`
            : step.scheduled_for
                ? `Scheduled ${formatSuiteDate(step.scheduled_for)}`
                : '';
        return `
            <div class="suite-step-row">
                <div class="suite-step-title">${label}</div>
                <div class="suite-step-meta">${status}${timing ? ` · ${timing}` : ''}</div>
            </div>
        `;
    }).join('');
}

function formatSuiteDate(value) {
    if (!value) return '—';
    const normalized = value.replace(' ', 'T');
    const date = new Date(normalized);
    if (Number.isNaN(date.getTime())) {
        return value;
    }
    return date.toLocaleString();
}

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        closeSuiteTimeline();
    }
});
</script>

<!-- Include Workflow Entity Configuration Script -->
<script src="<?=asset_url('/assets/workflow-entity-config.js')?>"></script>

<div id="suiteTimelineModal" class="suite-timeline-modal" onclick="if(event.target === this) closeSuiteTimeline();">
    <div class="suite-timeline-panel">
        <div class="suite-timeline-panel-header">
            <strong>Sample Suite Activity</strong>
            <button type="button" class="btn tiny tertiary" onclick="closeSuiteTimeline()">×</button>
        </div>
        <div id="suiteTimelineBody" class="suite-timeline-body">
            <div class="muted small">Select a sample request to view its automation timeline.</div>
        </div>
    </div>
</div>

<style>
.suite-timeline-modal {
    position: fixed;
    inset: 0;
    background: rgba(15,23,42,0.45);
    display: none;
    align-items: flex-start;
    justify-content: center;
    padding: 40px 16px;
    z-index: 12000;
}
.suite-timeline-modal.open { display: flex; }
.suite-timeline-panel {
    background: #fff;
    width: min(720px, 100%);
    border-radius: 16px;
    box-shadow: 0 30px 80px rgba(15,23,42,0.25);
    max-height: 90vh;
    overflow-y: auto;
}
.suite-timeline-panel-header {
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:18px 24px;
    border-bottom:1px solid #e2e8f0;
    font-size:18px;
}
.suite-timeline-body {
    padding:24px;
    font-size:14px;
    color:#0f172a;
}
.suite-timeline-grid {
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
    gap:16px;
}
.suite-metric {
    background:#f8fafc;
    border-radius:12px;
    padding:12px 16px;
}
.suite-metric-label {
    font-size:12px;
    text-transform:uppercase;
    color:#64748b;
    letter-spacing:0.05em;
}
.suite-metric-value {
    font-size:18px;
    font-weight:600;
    color:#0f172a;
}
.suite-next-action {
    margin:20px 0;
    padding:16px 18px;
    border-radius:12px;
    background:#eef2ff;
    color:#312e81;
    font-weight:500;
}
.suite-step-list {
    margin-top:12px;
    border-top:1px solid #e2e8f0;
}
.suite-step-row {
    padding:14px 0;
    border-bottom:1px solid #e2e8f0;
    display:flex;
    flex-direction:column;
    gap:4px;
}
.suite-step-title {
    font-weight:600;
}
.suite-step-meta {
    font-size:13px;
    color:#475569;
}
.suite-badge {
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:4px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:600;
}
.suite-badge.success { background:#dcfce7; color:#166534; }
.suite-badge.warn { background:#fee2e2; color:#b91c1c; }
.suite-badge.info { background:#e0e7ff; color:#3730a3; }
</style>

<?php if ($leadDetail): ?>
<!-- Archived Leads Side Panel (only on lead detail page) -->
<div id="archivedLeadsPanel" style="position:fixed; right:-350px; top:0; bottom:0; width:350px; background:#fff; box-shadow:-2px 0 8px rgba(0,0,0,0.1); z-index:10000; transition:right 0.3s ease; overflow-y:auto;">
    <div style="padding:16px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; background:#f9fafb; position:sticky; top:0; z-index:10;">
        <h4 style="margin:0;">📂 Archived Leads</h4>
        <button onclick="toggleArchivedLeadsPanel()" style="background:none; border:none; cursor:pointer; font-size:20px; padding:4px;">✕</button>
    </div>
    <div id="archivedLeadsContent" style="padding:16px;">
        <div class="muted small" style="text-align:center; padding:20px;">Loading archived leads...</div>
    </div>
</div>

<!-- Archived Leads Tab Toggle Button -->
<button id="archivedLeadsTab" onclick="toggleArchivedLeadsPanel()" style="position:fixed; right:0; top:50%; transform:translateY(-50%); background:#dc2626; color:#fff; border:none; padding:12px 8px; writing-mode:vertical-rl; cursor:pointer; border-radius:8px 0 0 8px; box-shadow:-2px 0 8px rgba(0,0,0,0.1); z-index:9999; transition:right 0.3s ease; font-weight:600; font-size:13px;">
    ARCHIVED (<span id="archivedCount">0</span>)
</button>
<?php endif; ?>

<?php 
// Include unified radial menu for Sales ACP
require_once __DIR__ . '/../../required/sales-acp-radial-menu.php'; 
?>

<?php if ($leadDetail): ?>
<!-- Quick Add Social Link Modal -->
<div id="quickAddSocialModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:12px; padding:24px; max-width:500px; width:90%; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
        <h3 style="margin:0 0 20px 0; display:flex; align-items:center; gap:8px;">
            <i class="fa-solid fa-link"></i> Add Social Media Link
        </h3>
        
        <div style="display:grid; gap:16px;">
            <div>
                <label style="display:block; margin-bottom:6px; font-weight:600; font-size:13px;">Platform</label>
                <select id="quickSocialPlatform" onchange="updateQuickPlatformPreview()" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:6px;">
                    <?php foreach (get_social_platform_options() as $value => $label): ?>
                    <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label style="display:block; margin-bottom:6px; font-weight:600; font-size:13px;">URL *</label>
                <input type="url" id="quickSocialUrl" placeholder="https://..." style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:6px;" required>
                <div style="font-size:11px; color:#6b7280; margin-top:4px;">
                    <i class="fa-solid fa-magic"></i> Platform auto-detected from URL
                </div>
            </div>
            
            <div>
                <label style="display:block; margin-bottom:6px; font-weight:600; font-size:13px;">Custom Label (optional)</label>
                <input type="text" id="quickSocialLabel" placeholder="Leave blank for default" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:6px;">
            </div>
            
            <div id="quickPlatformPreview" style="display:flex; gap:12px; align-items:center; padding:12px; background:#f9fafb; border-radius:8px; border:1px solid #e5e7eb;">
                <div id="quickPreviewIcon" style="width:40px; height:40px; display:flex; align-items:center; justify-content:center; background:white; border-radius:8px; font-size:20px;">
                    <i class="fa-brands fa-facebook" style="color:#1877F2;"></i>
                </div>
                <div>
                    <div id="quickPreviewLabel" style="font-weight:600; font-size:13px; color:#1f2937;">Facebook</div>
                    <div style="font-size:12px; color:#6b7280;">Preview</div>
                </div>
            </div>
        </div>
        
        <div style="display:flex; gap:12px; justify-content:end; margin-top:24px;">
            <button type="button" onclick="closeQuickSocialModal()" class="btn secondary">Cancel</button>
            <button type="button" onclick="saveQuickSocialLink()" class="btn" style="background:#059669;">
                <i class="fa-solid fa-save"></i> Save Link
            </button>
        </div>
    </div>
</div>

<style>
/* Show delete button on hover */
.info-item [style*="position:relative"]:hover .social-delete-btn {
    display: flex !important;
}

.info-item a[href*="http"]:hover {
    border-color: #d1d5db;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
</style>

<script>
const quickSocialApiUrl = '<?php echo api_url('/api/lead-social-links-api.php'); ?>';
const currentLeadId = <?php echo $leadDetail['id']; ?>;

const quickPlatformConfigs = {
    facebook: {icon: 'fa-brands fa-facebook', color: '#1877F2', label: 'Facebook'},
    instagram: {icon: 'fa-brands fa-instagram', color: '#E4405F', label: 'Instagram'},
    linkedin: {icon: 'fa-brands fa-linkedin', color: '#0A66C2', label: 'LinkedIn'},
    x: {icon: 'fa-brands fa-x-twitter', color: '#000000', label: 'X (Twitter)'},
    reddit: {icon: 'fa-brands fa-reddit', color: '#FF4500', label: 'Reddit'},
    youtube: {icon: 'fa-brands fa-youtube', color: '#FF0000', label: 'YouTube'},
    linktree: {icon: 'fa-solid fa-tree', color: '#43E55E', label: 'LinkTree'},
    beacons: {icon: 'fa-solid fa-link', color: '#7C3AED', label: 'Beacons'},
    twitch: {icon: 'fa-brands fa-twitch', color: '#9146FF', label: 'Twitch'},
    kick: {icon: 'fa-solid fa-k', color: '#53FC18', label: 'Kick'},
    tiktok: {icon: 'fa-brands fa-tiktok', color: '#000000', label: 'TikTok'},
    custom: {icon: 'fa-solid fa-link', color: '#6B7280', label: 'Custom Link'}
};

function showAddSocialModal() {
    document.getElementById('quickAddSocialModal').style.display = 'flex';
    document.getElementById('quickSocialUrl').value = '';
    document.getElementById('quickSocialLabel').value = '';
    document.getElementById('quickSocialPlatform').value = 'facebook';
    updateQuickPlatformPreview();
}

function closeQuickSocialModal() {
    document.getElementById('quickAddSocialModal').style.display = 'none';
}

function updateQuickPlatformPreview() {
    const platform = document.getElementById('quickSocialPlatform').value;
    const config = quickPlatformConfigs[platform] || quickPlatformConfigs.custom;
    
    document.getElementById('quickPreviewIcon').innerHTML = `<i class="${config.icon}" style="color:${config.color};"></i>`;
    document.getElementById('quickPreviewLabel').textContent = config.label;
}

async function saveQuickSocialLink() {
    const platform = document.getElementById('quickSocialPlatform').value;
    const url = document.getElementById('quickSocialUrl').value.trim();
    const label = document.getElementById('quickSocialLabel').value.trim();
    
    if (!url) {
        alert('URL is required');
        return;
    }
    
    try {
        const response = await fetch(quickSocialApiUrl + '?action=add', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                lead_id: currentLeadId,
                platform: platform,
                url: url,
                display_label: label
            })
        });
        
        const result = await response.json();
        
        if (!result.ok) {
            throw new Error(result.error || 'Failed to add link');
        }
        
        // Reload page to show new link
        window.location.reload();
        
    } catch (error) {
        console.error('Error:', error);
        alert('Error adding social link: ' + error.message);
    }
}

async function quickDeleteSocialLink(linkId, event) {
    event.preventDefault();
    event.stopPropagation();
    
    if (!confirm('Delete this social media link?')) {
        return;
    }
    
    try {
        const response = await fetch(quickSocialApiUrl + '?action=delete', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                link_id: linkId
            })
        });
        
        const result = await response.json();
        
        if (!result.ok) {
            throw new Error(result.error || 'Failed to delete link');
        }
        
        // Reload page
        window.location.reload();
        
    } catch (error) {
        console.error('Error:', error);
        alert('Error deleting social link: ' + error.message);
    }
}

// Close modal on backdrop click
document.getElementById('quickAddSocialModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeQuickSocialModal();
    }
});

// Auto-detect platform from URL
document.getElementById('quickSocialUrl')?.addEventListener('blur', function() {
    const url = this.value.trim().toLowerCase();
    if (!url) return;
    
    let detectedPlatform = 'custom';
    
    if (url.includes('facebook.com') || url.includes('fb.com')) detectedPlatform = 'facebook';
    else if (url.includes('instagram.com')) detectedPlatform = 'instagram';
    else if (url.includes('linkedin.com')) detectedPlatform = 'linkedin';
    else if (url.includes('twitter.com') || url.includes('x.com')) detectedPlatform = 'x';
    else if (url.includes('reddit.com')) detectedPlatform = 'reddit';
    else if (url.includes('youtube.com') || url.includes('youtu.be')) detectedPlatform = 'youtube';
    else if (url.includes('linktree') || url.includes('linktr.ee')) detectedPlatform = 'linktree';
    else if (url.includes('beacons')) detectedPlatform = 'beacons';
    else if (url.includes('twitch.tv')) detectedPlatform = 'twitch';
    else if (url.includes('kick.com')) detectedPlatform = 'kick';
    else if (url.includes('tiktok.com')) detectedPlatform = 'tiktok';
    
    document.getElementById('quickSocialPlatform').value = detectedPlatform;
    updateQuickPlatformPreview();
});

// Screenshot viewer
function showScreenshotModal(src) {
    document.getElementById('screenshotViewerImg').src = src;
    document.getElementById('screenshotViewerModal').style.display = 'flex';
}

// Quick menu for Note or Social interaction (used by radial menu)
window.showNoteOrSocialMenu = function(leadId) {
    // Remove any existing menu first
    const existingBackdrop = document.getElementById('noteOrSocialBackdrop');
    const existingMenu = document.getElementById('noteOrSocialQuickMenu');
    if (existingBackdrop) existingBackdrop.remove();
    if (existingMenu) existingMenu.remove();
    
    const menu = document.createElement('div');
    menu.id = 'noteOrSocialQuickMenu';
    menu.style.cssText = 'position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); background:white; border-radius:12px; padding:20px; box-shadow:0 20px 60px rgba(0,0,0,0.3); z-index:10002; min-width:280px;';
    
    const backdrop = document.createElement('div');
    backdrop.id = 'noteOrSocialBackdrop';
    backdrop.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.4); z-index:10001;';
    
    // Function to close menu completely
    const closeMenu = function() {
        // Remove menu elements
        const bd = document.getElementById('noteOrSocialBackdrop');
        const mn = document.getElementById('noteOrSocialQuickMenu');
        if (bd) bd.remove();
        if (mn) mn.remove();
    };
    
    menu.innerHTML = `
        <h4 style="margin:0 0 16px 0; color:#1f2937;">Choose Action</h4>
        <div style="display:grid; gap:12px;">
            <button id="btnAddNote" class="btn" style="background:#0369a1; display:flex; align-items:center; gap:8px; justify-content:center;">
                <i class="fa-solid fa-note-sticky"></i> Add Note
            </button>
            <button id="btnLogSocial" class="btn" style="background:#059669; display:flex; align-items:center; gap:8px; justify-content:center;">
                <i class="fa-solid fa-message"></i> Log Social Interaction
            </button>
            <button id="btnCancelMenu" class="btn secondary">Cancel</button>
        </div>
    `;
    
    backdrop.onclick = closeMenu;
    
    document.body.appendChild(backdrop);
    document.body.appendChild(menu);
    
    // Attach event listeners after elements are in DOM
    requestAnimationFrame(() => {
        const btnNote = document.getElementById('btnAddNote');
        const btnSocial = document.getElementById('btnLogSocial');
        const btnCancel = document.getElementById('btnCancelMenu');
        
        if (btnNote) {
            btnNote.onclick = function(e) {
                e.preventDefault();
                e.stopPropagation();
                // Immediately remove from DOM
                backdrop.remove();
                menu.remove();
                // Then open modal
                requestAnimationFrame(() => {
                    if (window.openModal) {
                        window.openModal('note', leadId);
                    }
                });
            };
        }
        
        if (btnSocial) {
            btnSocial.onclick = function(e) {
                e.preventDefault();
                e.stopPropagation();
                // Immediately remove from DOM
                backdrop.remove();
                menu.remove();
                // Then open modal
                requestAnimationFrame(() => {
                    if (window.showLogDMModal) {
                        window.showLogDMModal();
                    }
                });
            };
        }
        
        if (btnCancel) {
            btnCancel.onclick = function(e) {
                e.preventDefault();
                e.stopPropagation();
                closeMenu();
            };
        }
    });
};

// Log DM/Social Interaction functions
let dmScreenshotData = null;

function showLogDMModal() {
    document.getElementById('logDMModal').style.display = 'flex';
    document.getElementById('dmContent').value = '';
    dmScreenshotData = null;
    document.getElementById('screenshotPreview').innerHTML = '';
    
    // Set up clipboard paste listener
    document.addEventListener('paste', handlePasteScreenshot);
}

function closeLogDMModal() {
    document.getElementById('logDMModal').style.display = 'none';
    document.removeEventListener('paste', handlePasteScreenshot);
}

function handleScreenshotUpload(event) {
    const file = event.target.files[0];
    if (!file) return;
    
    const reader = new FileReader();
    reader.onload = function(e) {
        dmScreenshotData = e.target.result;
        showScreenshotPreview(dmScreenshotData);
    };
    reader.readAsDataURL(file);
}

function handlePasteScreenshot(e) {
    const items = e.clipboardData?.items;
    if (!items) return;
    
    for (let i = 0; i < items.length; i++) {
        if (items[i].type.indexOf('image') !== -1) {
            e.preventDefault();
            const blob = items[i].getAsFile();
            const reader = new FileReader();
            reader.onload = function(event) {
                dmScreenshotData = event.target.result;
                showScreenshotPreview(dmScreenshotData);
            };
            reader.readAsDataURL(blob);
            break;
        }
    }
}

function showScreenshotPreview(dataUrl) {
    const preview = document.getElementById('screenshotPreview');
    preview.innerHTML = `
        <div style="position:relative; display:inline-block; margin-bottom:12px;">
            <img src="${dataUrl}" alt="Preview" style="max-width:200px; max-height:150px; border-radius:8px; border:1px solid #e5e7eb;">
            <button type="button" onclick="clearScreenshot()" style="position:absolute; top:-6px; right:-6px; width:24px; height:24px; border-radius:50%; background:#dc2626; color:white; border:1px solid white; cursor:pointer; display:flex; align-items:center; justify-content:center;">
                <i class="fa-solid fa-times"></i>
            </button>
        </div>
    `;
}

function clearScreenshot() {
    dmScreenshotData = null;
    document.getElementById('screenshotPreview').innerHTML = '';
}

async function saveLogDM() {
    const platform = document.getElementById('dmPlatform').value;
    const type = document.getElementById('dmType').value;
    const direction = document.querySelector('input[name="dmDirection"]:checked').value;
    const content = document.getElementById('dmContent').value.trim();
    
    if (!content && !dmScreenshotData) {
        alert('Please add message content or screenshot');
        return;
    }
    
    try {
        const response = await fetch('<?php echo api_url('/api/social-interactions-api.php'); ?>?action=add', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                lead_id: currentLeadId,
                platform: platform,
                interaction_type: type,
                direction: direction,
                content: content,
                screenshot_data: dmScreenshotData
            })
        });
        
        const result = await response.json();
        
        if (!result.ok) {
            throw new Error(result.error || 'Failed to log interaction');
        }
        
        // Reload page to show new interaction
        window.location.reload();
        
    } catch (error) {
        console.error('Error:', error);
        alert('Error logging interaction: ' + error.message);
    }
}

// Close modal on backdrop click
document.getElementById('logDMModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeLogDMModal();
    }
});

// Parse lead address function
async function parseLeadAddress(leadId, fullAddress) {
    try {
        var btn = document.getElementById('parseAddressBtn');
        if (!btn) return;
        
        if (!confirm('Parse this address into structured fields?\n\n' + fullAddress + '\n\nThis will populate: Street, City, State, ZIP')) {
            return;
        }
        
        btn.disabled = true;
        btn.innerHTML = '<span style="display:inline-block;animation:spin 1s linear infinite;">⚙️</span> Parsing...';
        
        var fd = new FormData();
        fd.append('action', 'parse_lead_address');
        fd.append('lead_id', leadId);
        
        const res = await fetch('leads.php', { method: 'POST', body: fd });
        const data = await res.json();
        
        if (data && data.ok) {
            PremiumUI.showToast('Address parsed successfully!', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            alert('Failed to parse address: ' + (data.error || 'Unknown error'));
            btn.disabled = false;
            btn.innerHTML = '📍 Parse Address';
        }
    } catch(err) {
        console.error(err);
        alert('Error: ' + err.message);
        var btn = document.getElementById('parseAddressBtn');
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '📍 Parse Address';
        }
    }
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>
