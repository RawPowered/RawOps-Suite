<?php
$SEO = [
    'title' => 'Accounts — Sales Agent — Product',
    'description' => 'Manage accounts assigned to you.',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/header.php';
start_session();
$pdo = db();
$uid = current_user_id();
if (!$uid) redirect(path_with_base('/index.php'));

$isAdminSimulation = isset($_SESSION['admin_simulation']);
$adminMode = !empty($_SESSION['sales_acp_admin_mode']);
$q = trim($_GET['q'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per = 25; $off = ($page - 1) * $per;

$where = '1=1';
$params = [];
if (!$isAdminSimulation && !(current_admin_id() && $adminMode)) {
    $where .= ' AND wa.sales_agent_id = :uid';
    $params[':uid'] = $uid;
}
if ($q !== '') {
    $where .= ' AND (wa.business_name LIKE :q OR u.email LIKE :q)';
    $params[':q'] = "%$q%";
}

$accountId = intval($_GET['id'] ?? 0);

// Account address update/import actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_address' || $action === 'import_address_from_lead') {
        try {
            $aid = intval($_POST['account_id'] ?? 0);
            if ($aid > 0) {
                // Permission: admin/simulated admin/admin mode, or assigned sales agent
                $stp = $pdo->prepare('SELECT sales_agent_id FROM wholesale_accounts WHERE id = ?');
                $stp->execute([$aid]);
                $rowp = $stp->fetch(PDO::FETCH_ASSOC);
                $isAdmin = current_admin_id() ? true : false;
                $allowed = $isAdmin || $isAdminSimulation || ($adminMode && $isAdmin) || (intval($rowp['sales_agent_id'] ?? 0) === intval($uid));
                if ($allowed) {
                    $clean = function($v){
                        $s = trim((string)$v);
                        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s);
                    };
                    if ($action === 'update_address') {
                        $addr1 = $clean($_POST['address_line1'] ?? '');
                        $addr2 = $clean($_POST['address_line2'] ?? '');
                        $city = $clean($_POST['city'] ?? '');
                        $stateR = $clean($_POST['state_region'] ?? ($_POST['state'] ?? ''));
                        $postal = $clean($_POST['postal_code'] ?? ($_POST['zip_code'] ?? ''));
                        $addr1 = $addr1 !== '' ? $addr1 : null;
                        $addr2 = $addr2 !== '' ? $addr2 : null;
                        $city = $city !== '' ? $city : null;
                        $stateR = $stateR !== '' ? $stateR : null;
                        $postal = $postal !== '' ? $postal : null;
                        try {
                            $up = $pdo->prepare('UPDATE wholesale_accounts SET address_line1 = ?, address_line2 = ?, city = ?, state_region = ?, postal_code = ? WHERE id = ?');
                            $up->execute([$addr1, $addr2, $city, $stateR, $postal, $aid]);
                        } catch (Throwable $e) {
                            $up = $pdo->prepare('UPDATE wholesale_accounts SET address_line1 = ?, city = ?, state_region = ?, postal_code = ? WHERE id = ?');
                            $up->execute([$addr1, $city, $stateR, $postal, $aid]);
                        }
                        try { flash_set('success', 'Address updated.'); } catch (Throwable $e) {}
                    } else {
                        // import from latest converted lead
                        $ls = $pdo->prepare('SELECT address_line1, address_line2, city, state, zip_code, postal_code FROM crm_leads WHERE converted_to_account_id = ? ORDER BY updated_at DESC, id DESC LIMIT 1');
                        $ls->execute([$aid]);
                        if ($lead = $ls->fetch(PDO::FETCH_ASSOC)) {
                            $addr1 = $clean($lead['address_line1'] ?? '');
                            $addr2 = $clean($lead['address_line2'] ?? '');
                            $city = $clean($lead['city'] ?? '');
                            $stateR = $clean($lead['state'] ?? '');
                            $postal = $clean(($lead['zip_code'] ?? '') ?: ($lead['postal_code'] ?? ''));
                            $addr1 = $addr1 !== '' ? $addr1 : null;
                            $addr2 = $addr2 !== '' ? $addr2 : null;
                            $city = $city !== '' ? $city : null;
                            $stateR = $stateR !== '' ? $stateR : null;
                            $postal = $postal !== '' ? $postal : null;
                            try {
                                $up = $pdo->prepare('UPDATE wholesale_accounts SET address_line1 = ?, address_line2 = ?, city = ?, state_region = ?, postal_code = ? WHERE id = ?');
                                $up->execute([$addr1, $addr2, $city, $stateR, $postal, $aid]);
                            } catch (Throwable $e) {
                                $up = $pdo->prepare('UPDATE wholesale_accounts SET address_line1 = ?, city = ?, state_region = ?, postal_code = ? WHERE id = ?');
                                $up->execute([$addr1, $city, $stateR, $postal, $aid]);
                            }
                            try { flash_set('success', 'Address imported from lead.'); } catch (Throwable $e) {}
                        } else {
                            try { flash_set('warning', 'No converted lead address found.'); } catch (Throwable $e) {}
                        }
                    }
                }
            }
        } catch (Throwable $e) {}
        header('Location: accounts.php' . ($accountId ? ('?id=' . intval($accountId)) : ''));
        exit;
    }
}

// Chain Store API Endpoints
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Create or link to chain
    if ($action === 'create_chain' || $action === 'link_to_chain') {
        header('Content-Type: application/json');
        try {
            $accountId = intval($_POST['account_id'] ?? 0);
            if ($accountId <= 0) { echo json_encode(['ok' => false, 'error' => 'invalid_account']); exit; }
            
            // Permission check
            $isAdmin = current_admin_id() ? true : false;
            if (!$isAdmin && !$adminMode) { echo json_encode(['ok' => false, 'error' => 'admin_only']); exit; }
            
            if ($action === 'create_chain') {
                $chainName = trim($_POST['chain_name'] ?? '');
                $locationName = trim($_POST['location_name'] ?? '');
                
                if (!$chainName) { echo json_encode(['ok' => false, 'error' => 'missing_chain_name']); exit; }
                
                // Create new chain
                $stmt = $pdo->prepare('INSERT INTO wholesale_account_chains (chain_name, master_account_id, created_at) VALUES (?, ?, NOW())');
                $stmt->execute([$chainName, $accountId]);
                $chainId = $pdo->lastInsertId();
                
                // Update account
                $stmt = $pdo->prepare('UPDATE wholesale_accounts SET chain_id = ?, is_master_location = 1, location_name = ? WHERE id = ?');
                $stmt->execute([$chainId, $locationName ?: null, $accountId]);
                
                echo json_encode(['ok' => true, 'chain_id' => $chainId]);
            } else {
                // Link to existing chain
                $chainId = intval($_POST['chain_id'] ?? 0);
                $locationName = trim($_POST['location_name'] ?? '');
                
                if ($chainId <= 0) { echo json_encode(['ok' => false, 'error' => 'invalid_chain']); exit; }
                
                $stmt = $pdo->prepare('UPDATE wholesale_accounts SET chain_id = ?, location_name = ? WHERE id = ?');
                $stmt->execute([$chainId, $locationName ?: null, $accountId]);
                
                echo json_encode(['ok' => true, 'chain_id' => $chainId]);
            }
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'server', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // Unlink from chain
    if ($action === 'unlink_from_chain') {
        header('Content-Type: application/json');
        try {
            $accountId = intval($_POST['account_id'] ?? 0);
            if ($accountId <= 0) { echo json_encode(['ok' => false, 'error' => 'invalid_account']); exit; }
            
            $isAdmin = current_admin_id() ? true : false;
            if (!$isAdmin && !$adminMode) { echo json_encode(['ok' => false, 'error' => 'admin_only']); exit; }
            
            $stmt = $pdo->prepare('UPDATE wholesale_accounts SET chain_id = NULL, is_master_location = 0, location_name = NULL WHERE id = ?');
            $stmt->execute([$accountId]);
            
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'server', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // Close location and archive
    if ($action === 'close_location') {
        header('Content-Type: application/json');
        try {
            $accountId = intval($_POST['account_id'] ?? 0);
            $closedReason = trim($_POST['closed_reason'] ?? 'out_of_business');
            $closureNotes = trim($_POST['closure_notes'] ?? '');
            
            if ($accountId <= 0) { echo json_encode(['ok' => false, 'error' => 'invalid_account']); exit; }
            
            $isAdmin = current_admin_id() ? true : false;
            if (!$isAdmin && !$adminMode) { echo json_encode(['ok' => false, 'error' => 'admin_only']); exit; }
            
            // Get account data
            $stmt = $pdo->prepare('SELECT * FROM wholesale_accounts WHERE id = ?');
            $stmt->execute([$accountId]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$account) { echo json_encode(['ok' => false, 'error' => 'account_not_found']); exit; }
            
            // Calculate lifetime stats
            $stmt = $pdo->prepare('SELECT COUNT(*) as total_orders, COALESCE(SUM(total), 0) as total_revenue FROM orders WHERE wholesale_account_id = ? AND (status IS NULL OR status NOT IN ("cancelled"))');
            $stmt->execute([$accountId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Archive to closed_locations
            $stmt = $pdo->prepare('
                INSERT INTO crm_closed_locations 
                (original_account_id, chain_id, business_name, location_name, address_line1, address_line2, 
                 city, state_region, postal_code, phone, email, sales_agent_id, closed_date, closed_reason, 
                 closure_notes, closed_by_user_id, total_lifetime_revenue, total_orders, archived_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, NOW())
            ');
            $stmt->execute([
                $accountId,
                $account['chain_id'] ?: null,
                $account['business_name'],
                $account['location_name'] ?: null,
                $account['address_line1'] ?: null,
                $account['address_line2'] ?: null,
                $account['city'] ?: null,
                $account['state_region'] ?: null,
                $account['postal_code'] ?: null,
                $account['phone'] ?: null,
                $account['email'] ?: null,
                $account['sales_agent_id'] ?: null,
                $closedReason,
                $closureNotes ?: null,
                $uid,
                $stats['total_revenue'] ?: null,
                $stats['total_orders'] ?: null
            ]);
            
            // Mark account as inactive (set approved = 0)
            $pdo->prepare('UPDATE wholesale_accounts SET approved = 0, chain_id = NULL WHERE id = ?')->execute([$accountId]);
            
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'server', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // Grant invoice visibility
    if ($action === 'grant_visibility') {
        header('Content-Type: application/json');
        try {
            $accountId = intval($_POST['account_id'] ?? 0);
            $canViewAccountId = intval($_POST['can_view_account_id'] ?? 0);
            
            if ($accountId <= 0 || $canViewAccountId <= 0) { 
                echo json_encode(['ok' => false, 'error' => 'invalid_accounts']); 
                exit; 
            }
            
            $isAdmin = current_admin_id() ? true : false;
            if (!$isAdmin && !$adminMode) { echo json_encode(['ok' => false, 'error' => 'admin_only']); exit; }
            
            $stmt = $pdo->prepare('
                INSERT INTO account_visibility_groups (account_id, can_view_account_id, granted_by_user_id, granted_at) 
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE granted_at = NOW()
            ');
            $stmt->execute([$accountId, $canViewAccountId, $uid]);
            
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'server', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // Revoke invoice visibility
    if ($action === 'revoke_visibility') {
        header('Content-Type: application/json');
        try {
            $visibilityId = intval($_POST['visibility_id'] ?? 0);
            
            if ($visibilityId <= 0) { echo json_encode(['ok' => false, 'error' => 'invalid_id']); exit; }
            
            $isAdmin = current_admin_id() ? true : false;
            if (!$isAdmin && !$adminMode) { echo json_encode(['ok' => false, 'error' => 'admin_only']); exit; }
            
            $stmt = $pdo->prepare('DELETE FROM account_visibility_groups WHERE id = ?');
            $stmt->execute([$visibilityId]);
            
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'server', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // Create commission split
    if ($action === 'create_commission_split') {
        header('Content-Type: application/json');
        try {
            $accountId = intval($_POST['account_id'] ?? 0);
            $primaryAgentId = intval($_POST['primary_agent_id'] ?? 0);
            $splitAgentId = intval($_POST['split_agent_id'] ?? 0);
            $splitPercentage = floatval($_POST['split_percentage'] ?? 50.00);
            $notes = trim($_POST['arrangement_notes'] ?? '');
            $effectiveFrom = trim($_POST['effective_from'] ?? date('Y-m-d'));
            
            if ($accountId <= 0 || $primaryAgentId <= 0 || $splitAgentId <= 0) {
                echo json_encode(['ok' => false, 'error' => 'missing_required_fields']);
                exit;
            }
            
            if ($splitPercentage < 0 || $splitPercentage > 100) {
                echo json_encode(['ok' => false, 'error' => 'invalid_percentage']);
                exit;
            }
            
            $isAdmin = current_admin_id() ? true : false;
            if (!$isAdmin && !$adminMode) { echo json_encode(['ok' => false, 'error' => 'admin_only']); exit; }
            
            $stmt = $pdo->prepare('
                INSERT INTO commission_splits 
                (account_id, primary_agent_id, split_agent_id, split_percentage, arrangement_notes, effective_from, created_by_user_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            $stmt->execute([$accountId, $primaryAgentId, $splitAgentId, $splitPercentage, $notes ?: null, $effectiveFrom, $uid]);
            
            echo json_encode(['ok' => true, 'split_id' => $pdo->lastInsertId()]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'server', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // Delete commission split
    if ($action === 'delete_commission_split') {
        header('Content-Type: application/json');
        try {
            $splitId = intval($_POST['split_id'] ?? 0);
            
            if ($splitId <= 0) { echo json_encode(['ok' => false, 'error' => 'invalid_id']); exit; }
            
            $isAdmin = current_admin_id() ? true : false;
            if (!$isAdmin && !$adminMode) { echo json_encode(['ok' => false, 'error' => 'admin_only']); exit; }
            
            $stmt = $pdo->prepare('DELETE FROM commission_splits WHERE id = ?');
            $stmt->execute([$splitId]);
            
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'server', 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// Admin: revert to lead (keeps account/invoices; sets account pending; creates/updates lead)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revert_to_lead' && current_admin_id()) {
    try {
        $aid = intval($_POST['account_id'] ?? 0);
        if ($aid > 0) {
            // Fetch account + user
            $st = $pdo->prepare('SELECT wa.*, u.first_name, u.last_name, u.email, u.phone FROM wholesale_accounts wa JOIN users u ON u.id = wa.user_id WHERE wa.id = ?');
            $st->execute([$aid]);
            $acc = $st->fetch();
            if ($acc) {
                // Create lead (or update existing reverted one)
                $ins = $pdo->prepare('INSERT INTO crm_leads (assigned_agent_id, first_name, last_name, email, phone, company_name, lead_source, lead_status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
                $ins->execute([intval($acc['sales_agent_id'] ?: 0) ?: null, $acc['first_name'] ?: '', $acc['last_name'] ?: '', $acc['email'] ?: '', $acc['phone'] ?: null, $acc['business_name'] ?: null, 'reverted_from_account', 'new']);
                // Mark account as pending (archive-like without schema change)
                $pdo->prepare('UPDATE wholesale_accounts SET approved = 0 WHERE id = ?')->execute([$aid]);
                // Log operation
                try {
                    $ctx = json_encode(['account_id' => intval($aid), 'by_user_id' => intval($uid)]);
                    $pdo->prepare('INSERT INTO operations_log (user_id, action, details, created_at) VALUES (?, ?, ?, NOW())')
                        ->execute([$uid, 'revert_account_to_lead', $ctx]);
                } catch (Throwable $e) {}
            }
        }
        header('Location: accounts.php?id=' . intval($aid));
        exit;
    } catch (Throwable $e) {
        // Fail silently back to list
        header('Location: accounts.php');
        exit;
    }
}

// AJAX endpoints for chain data
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    // Get chain locations
    if ($_GET['ajax'] === 'chain_locations') {
        try {
            $chainId = intval($_GET['chain_id'] ?? 0);
            if ($chainId <= 0) { echo json_encode(['ok' => false]); exit; }
            
            $stmt = $pdo->prepare('
                SELECT wa.id, wa.business_name, wa.location_name, wa.is_master_location, wa.city, wa.state_region
                FROM wholesale_accounts wa
                WHERE wa.chain_id = ?
                ORDER BY wa.is_master_location DESC, wa.business_name ASC
            ');
            $stmt->execute([$chainId]);
            $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['ok' => true, 'locations' => $locations]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    // Get visibility groups for chain
    if ($_GET['ajax'] === 'visibility_groups') {
        try {
            $chainId = intval($_GET['chain_id'] ?? 0);
            if ($chainId <= 0) { echo json_encode(['ok' => false]); exit; }
            
            $stmt = $pdo->prepare('
                SELECT vg.*
                FROM account_visibility_groups vg
                JOIN wholesale_accounts wa ON wa.id = vg.account_id
                WHERE wa.chain_id = ?
            ');
            $stmt->execute([$chainId]);
            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['ok' => true, 'groups' => $groups]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

// AJAX live search endpoint (JSON)
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');
    try {
        $sqlAjax = "SELECT 
                        wa.id, wa.business_name, wa.approved, u.first_name, u.last_name, u.email, u.phone,
                        wa.address_line1, wa.city, wa.state_region, wa.postal_code,
                        (
                            SELECT COUNT(*) FROM orders o
                            WHERE o.wholesale_account_id = wa.id AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                              AND (o.status IS NULL OR o.status NOT IN ('cancelled'))
                        ) AS inv_30d,
                        (
                            SELECT COALESCE(SUM(o.total),0) FROM orders o
                            WHERE o.wholesale_account_id = wa.id AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                              AND (o.status IS NULL OR o.status NOT IN ('cancelled'))
                        ) AS sales_30d,
                        (
                            SELECT COALESCE(SUM(c.amount),0) FROM commissions c
                            JOIN orders o2 ON o2.id = c.order_id
                            WHERE o2.wholesale_account_id = wa.id
                              AND c.commission_type = 'sales_agent'
                              AND (wa.sales_agent_id IS NULL OR c.user_id = wa.sales_agent_id)
                              AND o2.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                        ) AS comm_30d
                    FROM wholesale_accounts wa
                    JOIN users u ON u.id = wa.user_id
                    WHERE $where
                    ORDER BY wa.business_name
                    LIMIT :limit OFFSET :offset";
        $stAjax = $pdo->prepare($sqlAjax);
        foreach ($params as $k => $v) $stAjax->bindValue($k, $v);
        $stAjax->bindValue(':limit', $per, PDO::PARAM_INT);
        $stAjax->bindValue(':offset', $off, PDO::PARAM_INT);
        $stAjax->execute();
        $data = $stAjax->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'rows' => $data]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['ok' => false]);
        exit;
    }
}

$sql = "SELECT 
            wa.*, 
            u.first_name, u.last_name, u.email, u.phone,
            (
                SELECT COUNT(*) FROM orders o
                WHERE o.wholesale_account_id = wa.id 
                  AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  AND (o.status IS NULL OR o.status NOT IN ('cancelled'))
            ) AS inv_30d,
            (
                SELECT COALESCE(SUM(o.total),0) FROM orders o
                WHERE o.wholesale_account_id = wa.id 
                  AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  AND (o.status IS NULL OR o.status NOT IN ('cancelled'))
            ) AS sales_30d,
            (
                SELECT COALESCE(SUM(c.amount),0)
                FROM commissions c
                JOIN orders o2 ON o2.id = c.order_id
                WHERE o2.wholesale_account_id = wa.id
                  AND c.commission_type = 'sales_agent'
                  AND (wa.sales_agent_id IS NULL OR c.user_id = wa.sales_agent_id)
                  AND o2.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ) AS comm_30d
        FROM wholesale_accounts wa
        JOIN users u ON u.id = wa.user_id
        WHERE $where
        ORDER BY wa.business_name
        LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $per, PDO::PARAM_INT);
$stmt->bindValue(':offset', $off, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

// Optional detail view
$detail = null; $contacts = []; $invoices = [];
if ($accountId > 0) {
    try {
        $w = $where . ' AND wa.id = :aid';
        $ps = $params; $ps[':aid'] = $accountId;
        $st = $pdo->prepare("SELECT wa.*, u.first_name, u.last_name, u.email, u.phone FROM wholesale_accounts wa JOIN users u ON u.id = wa.user_id WHERE $w");
        foreach ($ps as $k=>$v) $st->bindValue($k,$v);
        $st->execute();
        $detail = $st->fetch();
        if ($detail) {
            $canEditAccount = (current_admin_id() || $isAdminSimulation || ($adminMode && current_admin_id()) || (intval($detail['sales_agent_id'] ?? 0) === intval($uid)));
            $cst = $pdo->prepare('SELECT c.*, ac.is_primary FROM account_contacts ac JOIN crm_contacts c ON c.id=ac.contact_id WHERE ac.wholesale_account_id = ? ORDER BY ac.is_primary DESC, c.last_name');
            $cst->execute([$accountId]);
            $contacts = $cst->fetchAll();
            $ist = $pdo->prepare('SELECT id, order_number, total, status, created_at FROM orders WHERE wholesale_account_id = ? ORDER BY created_at DESC LIMIT 10');
            $ist->execute([$accountId]);
            $invoices = $ist->fetchAll();

            // Related leads (converted to this account)
            $leadIds = [];
            try {
                $ls = $pdo->prepare('SELECT id FROM crm_leads WHERE converted_to_account_id = ?');
                $ls->execute([$accountId]);
                $leadIds = array_map(fn($r)=>intval($r['id']), $ls->fetchAll());
            } catch (Throwable $e) { $leadIds = []; }

            $leadFilter = '';
            if (!empty($leadIds)) {
                $leadFilter = ' OR lead_id IN (' . implode(',', array_map('intval',$leadIds)) . ')';
            }

            // Calls
            $calls = [];
            try {
                $q = 'SELECT id, call_date, call_outcome, summary FROM crm_call_logs WHERE (account_id = ?' . $leadFilter . ') ORDER BY call_date DESC LIMIT 10';
                $stc = $pdo->prepare($q);
                $stc->execute([$accountId]);
                $calls = $stc->fetchAll();
            } catch (Throwable $e) { $calls = []; }

            // Notes
            $notes = [];
            try {
                $q = 'SELECT id, created_at, content FROM crm_notes WHERE (account_id = ?' . $leadFilter . ') ORDER BY created_at DESC LIMIT 10';
                $stn = $pdo->prepare($q);
                $stn->execute([$accountId]);
                $notes = $stn->fetchAll();
            } catch (Throwable $e) { $notes = []; }

            // Tasks
            $tasksL = [];
            try {
                $q = 'SELECT id, title, status, due_date, created_at FROM crm_tasks WHERE (account_id = ?' . $leadFilter . ') ORDER BY created_at DESC LIMIT 10';
                $stt = $pdo->prepare($q);
                $stt->execute([$accountId]);
                $tasksL = $stt->fetchAll();
            } catch (Throwable $e) { $tasksL = []; }

            // Demo drop-offs from related leads
            $demos = [];
            if (!empty($leadIds)) {
                try {
                    $q = 'SELECT d.*, t.product_name FROM crm_demo_dropoffs d JOIN demo_dropoff_types t ON t.id = d.demo_type_id WHERE d.lead_id IN (' . implode(',', array_map('intval',$leadIds)) . ') ORDER BY d.dropoff_date DESC LIMIT 10';
                    $demos = $pdo->query($q)->fetchAll();
                } catch (Throwable $e) { $demos = []; }
            }

            // Follow-up notifications and upcoming tasks
            $followups = [];
            $nextTask = null;
            try {
                if (!empty($leadIds)) {
                    $q = 'SELECT id, lead_id, follow_up_date, follow_up_status FROM crm_demo_dropoffs WHERE lead_id IN (' . implode(',', array_map('intval',$leadIds)) . ') ORDER BY follow_up_date ASC';
                    $followups = $pdo->query($q)->fetchAll();
                }
            } catch (Throwable $e) { $followups = []; }
            try {
                $q = 'SELECT id, title, due_date, status FROM crm_tasks WHERE (account_id = :aid' . (!empty($leadIds) ? (' OR lead_id IN (' . implode(',', array_map('intval',$leadIds)) . ')') : '') . ') AND status IN ("pending","in_progress") ORDER BY COALESCE(due_date, NOW()) ASC LIMIT 1';
                $stn = $pdo->prepare($q);
                $stn->execute([':aid' => $accountId]);
                $nextTask = $stn->fetch();
            } catch (Throwable $e) { $nextTask = null; }

            // Build unified activity list similar to leads.php
            $activities = [];
            try {
                foreach ($calls as $c) {
                    $activities[] = [
                        'type' => 'call',
                        'title' => 'Call',
                        'created_at' => $c['call_date'] ?? null,
                        'status' => $c['call_outcome'] ?? null,
                        'description' => $c['summary'] ?? null
                    ];
                }
            } catch (Throwable $e) {}
            try {
                foreach ($notes as $n) {
                    $activities[] = [
                        'type' => 'note',
                        'title' => 'Note',
                        'created_at' => $n['created_at'] ?? null,
                        'status' => 'completed',
                        'description' => $n['content'] ?? null
                    ];
                }
            } catch (Throwable $e) {}
            try {
                foreach ($tasksL as $t) {
                    $activities[] = [
                        'type' => 'task',
                        'task_id' => $t['id'] ?? null,
                        'title' => $t['title'] ?? 'Task',
                        'created_at' => $t['created_at'] ?? ($t['due_date'] ?? null),
                        'status' => $t['status'] ?? null,
                        'description' => null
                    ];
                }
            } catch (Throwable $e) {}
            try {
                foreach ($demos as $d) {
                    $activities[] = [
                        'type' => 'demo',
                        'title' => 'Demo: ' . ($d['product_name'] ?? 'Sample'),
                        'created_at' => $d['dropoff_date'] ?? null,
                        'status' => $d['demo_status'] ?? null,
                        'description' => $d['notes'] ?? null,
                        'dropoff_id' => $d['id'] ?? null,
                        'follow_up_status' => $d['follow_up_status'] ?? null,
                        'follow_up_task_id' => $d['follow_up_task_id'] ?? null
                    ];
                }
            } catch (Throwable $e) {}
            // Invoices as activities
            try {
                foreach ($invoices as $o) {
                    $activities[] = [
                        'type' => 'invoice',
                        'title' => 'Invoice ' . (($o['order_number'] ?? '') ?: ('#' . str_pad((int)($o['id'] ?? 0), 6, '0', STR_PAD_LEFT))),
                        'created_at' => $o['created_at'] ?? null,
                        'status' => $o['status'] ?? null,
                        'description' => '$' . number_format((float)($o['total'] ?? 0), 2),
                        'invoice_id' => (int)($o['id'] ?? 0)
                    ];
                }
            } catch (Throwable $e) {}
            try {
                usort($activities, function($a,$b){ return strtotime($b['created_at'] ?? '1970-01-01') <=> strtotime($a['created_at'] ?? '1970-01-01'); });
                $activities = array_slice($activities, 0, 15);
            } catch (Throwable $e) {}
            // Nudge logic for header + button: no activity in last 7 days AND no upcoming tasks in next 60 days
            $latestActivityTs = null;
            try {
                foreach (($activities ?? []) as $ac) {
                    $ts = strtotime($ac['created_at'] ?? '');
                    if ($ts && (!$latestActivityTs || $ts > $latestActivityTs)) $latestActivityTs = $ts;
                }
            } catch (Throwable $e) {}
            $staleWindowTs = strtotime('-7 days');
            $isStale = (!$latestActivityTs) || ($latestActivityTs < $staleWindowTs);
            $hasUpcoming60 = false;
            try {
                if (!empty($nextTask) && !empty($nextTask['due_date'])) {
                    $hasUpcoming60 = (strtotime($nextTask['due_date']) <= strtotime('+60 days'));
                }
            } catch (Throwable $e) { $hasUpcoming60 = false; }
            $shouldNudgeFab = $isStale && !$hasUpcoming60;
        }
    } catch (Throwable $e) {}
}

// Calculate stats
$totalAccounts = count($rows);
$approvedAccounts = count(array_filter($rows, fn($r) => $r['approved']));
$pendingAccounts = $totalAccounts - $approvedAccounts;
?>

<style>
/* Enhanced Paladin Theme Styles for Accounts */
.sacp_account-card {
    background: var(--acp-white, #ffffff);
    border: 1px solid var(--acp-platinum, #e5e7eb);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 16px;
    transition: all 250ms ease;
    position: relative;
    overflow: hidden;
}

.sacp_account-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    transform: translateY(-2px);
    border-color: var(--acp-gold, #FFD700);
}

.sacp_account-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 2px solid var(--acp-platinum, #e5e7eb);
}

.sacp_account-title {
    margin: 0;
    font-size: 24px;
    color: var(--acp-carbon, #1f2937);
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 12px;
}

.sacp_interaction-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.sacp_interaction-section {
    background: linear-gradient(135deg, #f9fafb, #f3f4f6);
    border-radius: 10px;
    padding: 16px;
    border: 1px solid var(--acp-platinum, #e5e7eb);
}

.sacp_interaction-title {
    font-weight: 600;
    margin-bottom: 12px;
    color: var(--acp-carbon, #1f2937);
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 15px;
}

.sacp_interaction-item {
    padding: 10px 0;
    border-bottom: 1px solid rgba(0, 0, 0, 0.05);
}

.sacp_interaction-item:last-child {
    border-bottom: none;
}

.sacp_interaction-meta {
    font-size: 12px;
    color: var(--acp-graphite, #6b7280);
    margin-bottom: 4px;
}

.sacp_contact-card {
    background: white;
    padding: 14px;
    border: 1px solid var(--acp-platinum, #e5e7eb);
    border-radius: 10px;
    margin-bottom: 10px;
    transition: all 250ms ease;
}

.sacp_contact-card:hover {
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
    border-color: var(--acp-gold, #FFD700);
}

.sacp_invoice-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px;
    background: white;
    border: 1px solid var(--acp-platinum, #e5e7eb);
    border-radius: 8px;
    margin-bottom: 8px;
}

.sacp_invoice-item:hover {
    background: linear-gradient(90deg, transparent, rgba(255, 215, 0, 0.05), transparent);
}

.sacp_detail-info {
    display: grid;
    grid-template-columns: 140px 1fr;
    gap: 12px 16px;
    font-size: 14px;
    margin-bottom: 16px;
}

.sacp_detail-label {
    font-weight: 600;
    color: var(--acp-graphite, #6b7280);
}

.sacp_detail-value {
    color: var(--acp-carbon, #1f2937);
}

.sacp_status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.sacp_status-badge.approved {
    background: linear-gradient(135deg, #dcfce7, #bbf7d0);
    color: #059669;
    border: 1px solid #86efac;
}

.sacp_status-badge.pending {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    color: #d97706;
    border: 1px solid #fcd34d;
}

.sacp_quick-actions { display:flex; gap:10px; flex-wrap:wrap; }

/* Gentle blue glow highlight for recommended action in menus */
.highlight-recommend {
    box-shadow: 0 0 0 0 rgba(5,150,105,.6);
    animation: greenGlow 1.8s ease-in-out infinite;
    border-left: 3px solid #10b981 !important;
}
@keyframes greenGlow {
    0% { box-shadow: 0 0 0 0 rgba(5,150,105,.45); }
    70% { box-shadow: 0 0 0 12px rgba(5,150,105,0); }
    100% { box-shadow: 0 0 0 0 rgba(5,150,105,0); }
}

/* Two-column layout for details + timeline */
.sacp_two-col { display:grid; grid-template-columns: 1fr 1fr; gap:16px; align-items:start; }
@media (max-width: 1024px) { .sacp_two-col { grid-template-columns: 1fr; } }

/* Activity Timeline + Tabs (match Leads premium styles) */
.activity-tabs {
    display: flex;
    gap: 8px;
    margin: 16px 0;
    padding-bottom: 12px;
    border-bottom: 1px solid #f3f4f6;
}

.activity-tab {
    padding: 6px 12px;
    background: #f3f4f6;
    border: none;
    border-radius: 20px;
    cursor: pointer;
    font-size: 13px;
    color: #6b7280;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}

.activity-tab:hover { background: #e5e7eb; color: #111827; }
.activity-tab.active { background: #0369a1; color: #ffffff; }

.tab-count {
    background: rgba(255,255,255,0.25);
    padding: 2px 6px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
}

.timeline {
    max-height: 400px;
    overflow-y: auto;
}

.timeline-empty {
    text-align: center;
    padding: 28px 16px;
    color: #6b7280;
}

.timeline-item {
    display: flex;
    gap: 12px;
    margin-bottom: 18px;
    position: relative;
}
.timeline-item:not(:last-child)::before {
    content: '';
    position: absolute;
    left: 20px;
    top: 40px;
    bottom: -14px;
    width: 2px;
    background: #f3f4f6;
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
.timeline-marker.call { background: #FEE2E2; }
.timeline-marker.note { background: #DCFCE7; }
.timeline-marker.task { background: #DBEAFE; }
.timeline-marker.demo { background: #FEF3C7; }
.timeline-marker.invoice { background: #E0E7FF; }

.timeline-content { flex: 1; }
.timeline-title { font-weight: 600; color: #111827; margin-bottom: 4px; }
.timeline-desc { font-size: 13px; color: #6b7280; margin-bottom: 4px; }
.timeline-meta { display: flex; gap: 8px; font-size: 12px; color: #6b7280; }

.badge-mini { padding: 2px 6px; background: #f3f4f6; border-radius: 4px; }

/* Header actions (mirror Leads detail styles) */
.header-actions-group{display:flex;align-items:center;gap:12px;position:relative}
.btn-add-activity{width:36px;height:36px;border-radius:50%;background:var(--primary,#0066FF);color:var(--white,#fff);border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .3s;position:relative}
.btn-add-activity.btn-nudge{position:relative}
.btn-add-activity.btn-nudge::after{content:'';position:absolute;left:0;top:0;width:100%;height:100%;border-radius:50%;box-shadow:0 0 0 0 rgba(0,102,255,.55);animation:fabPulse 1.2s ease-in-out infinite;pointer-events:none}
@keyframes fabPulse{0%{transform:scale(1);box-shadow:0 0 0 0 rgba(0,102,255,.55)}60%{transform:scale(1.06);box-shadow:0 0 0 14px rgba(0,102,255,0)}100%{transform:scale(1);box-shadow:0 0 0 0 rgba(0,102,255,.55)}}
.btn-add-activity:hover{background:#0052CC;transform:scale(1.1);box-shadow:0 4px 12px rgba(0,102,255,.4)}
.btn-view-all{position:relative;color:var(--primary,#0066FF)}
.btn-text{background:none;border:none;color:var(--primary,#0066FF);cursor:pointer;font-weight:600}
.menu-accent{font-weight:700;color:var(--dark,#111827)}
.activity-add-menu{position:absolute;top:100%;right:0;margin-top:8px;background:var(--white,#fff);border:1px solid var(--gray-400,#e5e7eb);border-radius:8px;box-shadow:0 10px 20px rgba(0,0,0,.12);min-width:220px;display:none;z-index:10000}
.activity-add-menu.active{display:block;animation:slideDown .2s ease}
.activity-add-menu button,.activity-add-menu a{width:100%;padding:12px 16px;background:none;border:none;cursor:pointer;display:flex;align-items:center;gap:10px;font-size:14px;line-height:1.5;color:var(--dark,#111827);text-align:left;transition:all .2s;font-weight:500;text-decoration:none;border-bottom:1px solid #f3f4f6}
.activity-add-menu button:last-child,.activity-add-menu a:last-child{border-bottom:none}
.activity-add-menu button:hover,.activity-add-menu a:hover{background:var(--gray-100,#f3f4f6);color:var(--primary,#0066FF)}
.view-all-menu{position:absolute;top:100%;right:0;margin-top:8px;background:var(--white,#fff);border:1px solid var(--gray-400,#e5e7eb);border-radius:8px;box-shadow:0 10px 20px rgba(0,0,0,.12);min-width:180px;display:none;z-index:10000}
.view-all-menu.active{display:block;animation:slideDown .2s ease}
.view-all-menu a{display:flex;align-items:center;gap:10px;padding:12px 16px;color:var(--dark,#111827);text-decoration:none;transition:all .2s;font-weight:500;border-bottom:1px solid #f3f4f6}
.view-all-menu a:last-child{border-bottom:none}
.view-all-menu a:hover{background:var(--gray-100,#f3f4f6);color:var(--primary,#0369a1)}
/* Menu-style buttons for header quick actions (blue) */
.sacp_menu-btn{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:10px 14px;
    background:linear-gradient(135deg, var(--acp-blue-deep, #1e40af), var(--acp-blue, #0369a1));
    color:#ffffff;
    border:1px solid rgba(3, 105, 161, 0.35);
    border-radius:12px;
    font-weight:600;
    text-decoration:none;
    cursor:pointer;
    transition:all .2s ease;
    box-shadow:0 2px 8px rgba(3,105,161,.25);
}
.sacp_menu-btn:hover{
    background:linear-gradient(135deg, #1b4cae, #0052cc);
    transform:translateY(-1px);
    box-shadow:0 6px 16px rgba(3,105,161,.35);
}
.sacp_menu-btn:focus-visible{ outline:2px solid #93c5fd; outline-offset:2px; }
</style>

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

    <!-- Enhanced Search Bar -->
    <div class="sacp_card" style="margin-bottom:20px; background: linear-gradient(135deg, #f9fafb, white);">
        <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
            <div style="flex: 1; min-width:280px; position:relative;">
                <input id="acctSearch" type="text" value="<?php echo htmlspecialchars($q); ?>" 
                       placeholder="🔍 Type to search accounts by business or email..." 
                       class="paladin-input" 
                       style="width:100%; padding:12px 40px 12px 12px; border:2px solid var(--acp-platinum, #e5e7eb); border-radius:12px; font-size:14px;">
                <div id="acctLoading" style="display:none; position:absolute; right:10px; top:10px;">⏳</div>
            </div>
            <a id="acctClear" href="accounts.php" class="btn secondary" style="padding:12px 20px; display:<?php echo $q ? 'inline-flex' : 'none'; ?>;">Clear</a>
        </div>
    </div>
    
    <!-- Stats Bar -->
    <?php if (!$detail): ?>
    <div class="sacp_stats-grid" style="margin-bottom:24px;">
        <div class="sacp_stat-card">
            <div class="sacp_stat-value"><?php echo $totalAccounts; ?></div>
            <div class="sacp_stat-label">Total Accounts</div>
        </div>
        <div class="sacp_stat-card" style="background: linear-gradient(135deg, #dcfce7, #f0fdf4);">
            <div class="sacp_stat-value" style="color: #059669;"><?php echo $approvedAccounts; ?></div>
            <div class="sacp_stat-label">Approved</div>
        </div>
        <div class="sacp_stat-card" style="background: linear-gradient(135deg, #fef3c7, #fffbeb);">
            <div class="sacp_stat-value" style="color: #d97706;"><?php echo $pendingAccounts; ?></div>
            <div class="sacp_stat-label">Pending</div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($detail): ?>
    <!-- Account Detail View -->
    <div class="sacp_account-card">
        <div class="sacp_account-header">
            <div>
                <h1 class="sacp_account-title">
                    <span style="font-size:32px;">🏢</span>
                    <?php echo htmlspecialchars($detail['business_name']); ?>
                </h1>
                <div style="margin-top:8px;">
                    <span class="sacp_status-badge <?php echo $detail['approved'] ? 'approved' : 'pending'; ?>">
                        <?php echo $detail['approved'] ? '✅ Approved' : '⏳ Pending'; ?>
                    </span>
                </div>
            </div>
            <div class="sacp_quick-actions">
                <a class="sacp_menu-btn" href="accounts.php">← Back to Accounts</a>
                <a class="sacp_menu-btn" href="invoice.php?account_id=<?php echo intval($accountId); ?>">
                    <span>📄</span> Create Invoice
                </a>
                <?php if (!empty($canEditAccount)): ?>
                <button type="button" class="sacp_menu-btn" onclick="document.getElementById('acctEditModal').style.display='block'">✏️ Edit Address</button>
                <form method="post" style="display:inline;" onsubmit="return confirm('Import address from the converted lead? This will overwrite current address.');">
                    <input type="hidden" name="action" value="import_address_from_lead">
                    <input type="hidden" name="account_id" value="<?php echo intval($accountId); ?>">
                    <button type="submit" class="sacp_menu-btn">↘️ Import from Lead</button>
                </form>
                <?php endif; ?>
                <?php if (current_admin_id()): ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('Revert this account to a lead? Invoices remain with the account (pending).');">
                    <input type="hidden" name="action" value="revert_to_lead">
                    <input type="hidden" name="account_id" value="<?php echo intval($accountId); ?>">
                    <button type="submit" class="btn" style="background: var(--acp-danger, #b91c1c);">
                        <span>↩️</span> Revert to Lead
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

    <!-- Details + Timeline two-column wrapper -->
    <div class="sacp_two-col" style="margin-top:12px;">
        <div>
        <!-- Follow-up Notification Banner -->
        <div style="margin-top:0;">
            <?php if (!empty($nextTask)): ?>
                <div class="data-warning" role="status" aria-live="polite" style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
                    <span>⏰ Upcoming Task: <strong><?php echo htmlspecialchars($nextTask['title']); ?></strong> <?php if(!empty($nextTask['due_date'])): ?>— due <?php echo date('M j, g:i A', strtotime($nextTask['due_date'])); ?><?php endif; ?></span>
                    <span style="display:flex; gap:8px; align-items:center;">
                        <a href="tasks.php?account_id=<?php echo intval($accountId); ?>" class="btn small">View Tasks</a>
                        <a href="invoices.php?account_id=<?php echo intval($accountId); ?>" class="btn small secondary">Invoices</a>
                    </span>
                </div>
            <?php else: ?>
                <div class="data-warning" style="background:#f3f4f6; color:#374151; border-color:#e5e7eb;">No current or upcoming tasks</div>
            <?php endif; ?>
        </div>

        <div class="sacp_detail-info">
            <span class="sacp_detail-label">Primary Contact</span>
            <span class="sacp_detail-value">
                <?php echo htmlspecialchars($detail['first_name'].' '.$detail['last_name']); ?>
            </span>
            
            <span class="sacp_detail-label">Email Address</span>
            <span class="sacp_detail-value">
                <a href="mailto:<?php echo htmlspecialchars($detail['email']); ?>" style="color: var(--acp-blue, #0369a1); text-decoration:none;">
                    <?php echo htmlspecialchars($detail['email']); ?>
                </a>
            </span>
            
            <?php if ($detail['phone']): ?>
            <span class="sacp_detail-label">Phone Number</span>
            <span class="sacp_detail-value">
                <a href="tel:<?php echo htmlspecialchars($detail['phone']); ?>" style="color: var(--acp-blue, #0369a1); text-decoration:none;">
                    <?php echo htmlspecialchars($detail['phone']); ?>
                </a>
            </span>
            <?php endif; ?>
            
            <span class="sacp_detail-label">Account Status</span>
            <span class="sacp_detail-value">
                <?php echo $detail['approved'] ? 'Approved' : 'Pending Approval'; ?>
            </span>
            <span class="sacp_detail-label">Address</span>
            <span class="sacp_detail-value">
                <?php 
                $addr = trim(($detail['address_line1'] ?? '') . ', ' . ($detail['city'] ?? '') . ', ' . ($detail['state_region'] ?? '') . ' ' . ($detail['postal_code'] ?? ''));
                $hasAddr = ($addr !== ', ,  ' && $addr !== '');
                echo $hasAddr ? htmlspecialchars($addr) : '<span class="muted">—</span>';
                if ($hasAddr) {
                    echo ' <a class="link-plain" style="color: var(--acp-blue, #0369a1);" target="_blank" href="https://www.google.com/maps/search/?api=1&query=' . urlencode($addr) . '">Map</a>';
                }
                ?>
            </span>
            
            <?php 
            // Get chain information if linked
            $chainInfo = null;
            $chainLocations = [];
            if (!empty($detail['chain_id'])) {
                try {
                    $stmt = $pdo->prepare('SELECT * FROM wholesale_account_chains WHERE id = ?');
                    $stmt->execute([$detail['chain_id']]);
                    $chainInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Get all locations in chain
                    $stmt = $pdo->prepare('
                        SELECT wa.id, wa.business_name, wa.location_name, wa.is_master_location, wa.city, wa.state_region,
                               u.first_name, u.last_name, u.email
                        FROM wholesale_accounts wa
                        JOIN users u ON u.id = wa.user_id
                        WHERE wa.chain_id = ?
                        ORDER BY wa.is_master_location DESC, wa.business_name ASC
                    ');
                    $stmt->execute([$detail['chain_id']]);
                    $chainLocations = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {}
            }
            ?>
            
            <span class="sacp_detail-label">Chain Status</span>
            <span class="sacp_detail-value">
                <?php if ($chainInfo): ?>
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <strong style="color:#059669;">🔗 <?php echo htmlspecialchars($chainInfo['chain_name']); ?></strong>
                        <?php if ($detail['is_master_location']): ?>
                            <span style="padding:2px 8px; background:#dbeafe; color:#1e40af; border-radius:4px; font-size:11px; font-weight:600;">MASTER</span>
                        <?php endif; ?>
                        <?php if ($detail['location_name']): ?>
                            <span style="color:#6b7280; font-size:13px;">(<?php echo htmlspecialchars($detail['location_name']); ?>)</span>
                        <?php endif; ?>
                        <?php if (current_admin_id() || $adminMode): ?>
                            <button onclick="showChainManager(<?php echo intval($accountId); ?>, <?php echo intval($detail['chain_id']); ?>)" class="btn small" style="background:#0369a1;">Manage Chain</button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <span class="muted">Not part of a chain</span>
                    <?php if (current_admin_id() || $adminMode): ?>
                        <button onclick="showCreateChainModal(<?php echo intval($accountId); ?>)" class="btn small" style="background:#059669; margin-left:8px;">+ Create Chain</button>
                    <?php endif; ?>
                <?php endif; ?>
            </span>
            
            <?php if (!empty($chainLocations) && count($chainLocations) > 1): ?>
            <span class="sacp_detail-label">Chain Locations (<?php echo count($chainLocations); ?>)</span>
            <span class="sacp_detail-value">
                <div style="display:grid; gap:8px; margin-top:8px;">
                    <?php foreach ($chainLocations as $loc): ?>
                    <div style="padding:8px; background:<?php echo $loc['id'] == $accountId ? '#f0fdf4' : '#f9fafb'; ?>; border:1px solid #e5e7eb; border-radius:6px; display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <div style="font-weight:600; color:#1f2937;">
                                <?php echo htmlspecialchars($loc['business_name']); ?>
                                <?php if ($loc['is_master_location']): ?>
                                    <span style="margin-left:6px; padding:2px 6px; background:#dbeafe; color:#1e40af; border-radius:4px; font-size:10px;">MASTER</span>
                                <?php endif; ?>
                                <?php if ($loc['id'] == $accountId): ?>
                                    <span style="margin-left:6px; padding:2px 6px; background:#d1fae5; color:#065f46; border-radius:4px; font-size:10px;">CURRENT</span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:13px; color:#6b7280;">
                                <?php if ($loc['location_name']): echo htmlspecialchars($loc['location_name']) . ' • '; endif; ?>
                                <?php echo htmlspecialchars(($loc['city'] ?? '') . ', ' . ($loc['state_region'] ?? '')); ?>
                            </div>
                        </div>
                        <?php if ($loc['id'] != $accountId): ?>
                            <a href="accounts.php?id=<?php echo $loc['id']; ?>" class="btn small secondary">View</a>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </span>
            <?php endif; ?>
            
            <?php 
            // Get commission splits for this account
            $commissionSplits = [];
            try {
                $stmt = $pdo->prepare('
                    SELECT cs.*, 
                           CONCAT(u1.first_name, " ", u1.last_name) as primary_agent_name,
                           CONCAT(u2.first_name, " ", u2.last_name) as split_agent_name
                    FROM commission_splits cs
                    JOIN users u1 ON u1.id = cs.primary_agent_id
                    JOIN users u2 ON u2.id = cs.split_agent_id
                    WHERE cs.account_id = ? AND (cs.effective_until IS NULL OR cs.effective_until >= CURDATE())
                    ORDER BY cs.created_at DESC
                ');
                $stmt->execute([$accountId]);
                $commissionSplits = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {}
            ?>
            
            <?php if (!empty($commissionSplits)): ?>
            <span class="sacp_detail-label">Commission Splits</span>
            <span class="sacp_detail-value">
                <div style="display:grid; gap:8px; margin-top:8px;">
                    <?php foreach ($commissionSplits as $split): ?>
                    <div style="padding:10px; background:#fef3c7; border:1px solid #fde047; border-radius:6px;">
                        <div style="font-weight:600; color:#92400e; margin-bottom:4px;">
                            💰 <?php echo htmlspecialchars($split['primary_agent_name']); ?> 
                            (<?php echo 100 - floatval($split['split_percentage']); ?>%) 
                            ↔️ 
                            <?php echo htmlspecialchars($split['split_agent_name']); ?> 
                            (<?php echo floatval($split['split_percentage']); ?>%)
                        </div>
                        <div style="font-size:12px; color:#6b7280;">
                            Effective from: <?php echo date('M j, Y', strtotime($split['effective_from'])); ?>
                            <?php if ($split['arrangement_notes']): ?>
                                <br><?php echo htmlspecialchars($split['arrangement_notes']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </span>
            <?php endif; ?>
        </div>
        
        <!-- Contacts (moved up; not collapsible) -->
        <div class="sacp_card" style="margin-top:16px;">
            <h3 style="margin:0 0 12px 0; display:flex; align-items:center; gap:8px;"><span>👥</span> Contacts</h3>
            <?php if (empty($contacts)): ?>
                <div style="text-align:center; padding:32px; color: var(--acp-silver, #9ca3af);">
                    <div style="font-size:48px; margin-bottom:12px;">🔗</div>
                    <p>No contacts linked to this account yet.</p>
                </div>
            <?php else: ?>
                <div style="display:grid; gap:12px;">
                    <?php foreach ($contacts as $c): ?>
                        <div class="sacp_contact-card">
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <div>
                                    <div style="font-weight:600; color: var(--acp-carbon, #1f2937); font-size:16px;">
                                        <a href="contacts.php?id=<?php echo intval($c['id']); ?>" style="color: var(--acp-carbon, #1f2937); text-decoration:none;">
                                            <?php echo htmlspecialchars($c['first_name'].' '.$c['last_name']); ?>
                                        </a>
                                    </div>
                                    <div style="font-size:13px; color: var(--acp-graphite, #6b7280); margin-top:4px;">
                                        📧 <?php echo htmlspecialchars($c['email'] ?: 'No email'); ?>
                                        <?php if ($c['phone']): ?>
                                            • 📞 <?php echo htmlspecialchars($c['phone']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div style="margin-top:8px; display:flex; gap:6px;">
                                        <button class="btn small secondary" data-rapid-action="quick-email" data-email="<?php echo htmlspecialchars($c['email']); ?>" title="Send Email">📧</button>
                                        <?php if ($c['phone']): ?>
                                        <button class="btn small secondary" data-rapid-action="quick-phone" data-phone="<?php echo htmlspecialchars($c['phone']); ?>" title="Call">📞</button>
                                        <?php endif; ?>
                                        <button class="btn small primary" data-rapid-action="quick-note" data-account-id="<?php echo $accountId; ?>" title="Add Note">📝</button>
                                    </div>
                                </div>
                                <?php if ($c['is_primary']): ?>
                                    <span class="chip chip-success">PRIMARY</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        </div>
        <!-- Right column: Activity Timeline (top) -->
        <div>
        <div class="sacp_card" style="margin-top:0;">
            <h3 style="margin:0 0 12px 0; display:flex; align-items:center; gap:8px; position:relative;">
                <span>📊</span> Activity Timeline
                <span class="header-actions-group" style="margin-left:auto; display:flex; align-items:center; gap:8px;">
                    <button class="btn-add-activity <?php echo !empty($shouldNudgeFab) ? 'btn-nudge' : ''; ?>" onclick="toggleAccountActivityMenu()" aria-label="Add Activity">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="16"/>
                            <line x1="8" y1="12" x2="16" y2="12"/>
                        </svg>
                    </button>
                    <div class="activity-add-menu" id="accountActivityMenu">
                        <button onclick="return acpOpenCallModal(null, '<?php echo htmlspecialchars(preg_replace('/[^\\d\+]/','', (string)($detail['phone'] ?? '')), ENT_QUOTES); ?>', '<?php echo htmlspecialchars($detail['business_name'] ?? 'Account', ENT_QUOTES); ?>', '<?php echo htmlspecialchars(trim(($detail['first_name']??'').' '.($detail['last_name']??'')), ENT_QUOTES); ?>', <?php echo intval($accountId); ?>)">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72"/>
                            </svg>
                            Log Call
                        </button>
                        <button onclick="acpOpenQuickModal('note', <?php echo intval($accountId); ?>)">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/>
                            </svg>
                            Add Note
                        </button>
                        <button onclick="acpOpenQuickModal('task', <?php echo intval($accountId); ?>)">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                <polyline points="22 4 12 14.01 9 11.01"/>
                            </svg>
                            Create Task
                        </button>
                        <a href="invoice.php?account_id=<?php echo intval($accountId); ?>" class="menu-accent" style="text-decoration:none; color:inherit; display:flex; align-items:center; gap:10px; padding:10px 16px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/>
                            </svg>
                            Create Invoice
                        </a>
                        <a href="request-sample.php?account_id=<?php echo intval($accountId); ?>" class="<?php echo !empty($shouldNudgeFab) ? 'highlight-recommend' : ''; ?>" style="text-decoration:none; color:inherit; display:flex; align-items:center; gap:10px; padding:10px 16px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M9 2v4l-5 9a5 5 0 0 0 5 5h6a5 5 0 0 0 5-5l-5-9V2"/>
                                <path d="M7 16h10"/>
                            </svg>
                            Request Sample
                        </a>
                    </div>
                    <button class="btn-text btn-view-all" onclick="toggleAccountViewAll()" style="background:none;border:none;color:#0369a1;cursor:pointer;display:inline-flex;align-items:center;gap:4px;">
                        View All
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                    </button>
                    <div class="view-all-menu" id="accountViewAllMenu">
                        <a href="calls.php?account_id=<?php echo intval($accountId); ?>">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07"/>
                            </svg>
                            All Calls
                        </a>
                        <a href="notes.php?account_id=<?php echo intval($accountId); ?>">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            </svg>
                            All Notes
                        </a>
                        <a href="tasks.php?account_id=<?php echo intval($accountId); ?>">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                            </svg>
                            All Tasks
                        </a>
                        <a href="invoices.php?account_id=<?php echo intval($accountId); ?>">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            </svg>
                            All Invoices
                        </a>
                    </div>
                </span>
            </h3>
            <div>
                <div style="display:flex; gap:8px; margin-bottom:10px; flex-wrap:wrap; align-items:center;">
                    <div style="margin-left:auto; display:none;"></div>
                    <button class="activity-tab active" onclick="acpActivityFilter('all', this)">All <span class="tab-count"><?php echo isset($activities)?count($activities):0; ?></span></button>
                    <button class="activity-tab" onclick="acpActivityFilter('call', this)">Calls <span class="tab-count"><?php echo isset($calls)?count($calls):0; ?></span></button>
                    <button class="activity-tab" onclick="acpActivityFilter('note', this)">Notes <span class="tab-count"><?php echo isset($notes)?count($notes):0; ?></span></button>
                    <button class="activity-tab" onclick="acpActivityFilter('task', this)">Tasks <span class="tab-count"><?php echo isset($tasksL)?count($tasksL):0; ?></span></button>
                    <button class="activity-tab" onclick="acpActivityFilter('demo', this)">Demos <span class="tab-count"><?php echo isset($demos)?count($demos):0; ?></span></button>
                    <button class="activity-tab" onclick="acpActivityFilter('invoice', this)">Invoices <span class="tab-count"><?php echo isset($invoices)?count($invoices):0; ?></span></button>
                </div>
                <div id="acpActivityTimeline" class="timeline">
                    <?php if (empty($activities)): ?>
                        <div class="timeline-empty"><p>No activities yet</p></div>
                    <?php else: foreach ($activities as $activity): ?>
                        <div class="timeline-item" data-type="<?php echo $activity['type']; ?>" <?php if(($activity['type']??'')==='demo' && !empty($activity['dropoff_id'])): ?>data-dropoff-id="<?php echo (int)($activity['dropoff_id'] ?? 0); ?>"<?php endif; ?> <?php if(($activity['type']??'')==='task' && !empty($activity['task_id'])): ?>data-task-id="<?php echo (int)($activity['task_id'] ?? 0); ?>"<?php endif; ?>>
                            <div class="timeline-marker <?php echo $activity['type']; ?>"><?php echo match($activity['type']){ 'call'=>'📞','note'=>'📝','task'=>'✓','demo'=>'🧪','invoice'=>'📄', default=>'•'}; ?></div>
                            <div class="timeline-content">
                                <div class="timeline-title"><?php echo htmlspecialchars($activity['title']); ?></div>
                                <?php if (!empty($activity['description'])): ?><div class="timeline-desc"><?php echo htmlspecialchars(substr($activity['description'],0,120)); ?></div><?php endif; ?>
                                <div class="timeline-meta">
                                    <span><?php echo $activity['created_at'] ? date('M j, g:i A', strtotime($activity['created_at'])) : ''; ?></span>
                                    <?php if (!empty($activity['status'])): ?><span class="badge-mini badge-<?php echo str_replace('_','-', $activity['status']); ?>"><?php echo htmlspecialchars($activity['status']); ?></span><?php endif; ?>
                                    <?php if (($activity['type'] ?? '') === 'task' && !in_array(($activity['status'] ?? ''), ['completed','cancelled']) && !empty($activity['task_id'])): ?>
                                        <button class="btn small" style="margin-left:auto;background:#059669;" onclick="acpCompleteTimelineTask(<?php echo (int)($activity['task_id']); ?>, this)">Mark Complete</button>
                                    <?php elseif (($activity['type'] ?? '') === 'demo' && ($activity['follow_up_status'] ?? '') !== 'completed' && !empty($activity['dropoff_id'])): ?>
                                        <button class="btn small" style="margin-left:auto;background:#059669;" onclick="acpOpenFollowUpModal(<?php echo (int)($activity['dropoff_id']); ?>, <?php echo (int)($activity['follow_up_task_id'] ?? 0); ?>)">Complete Follow-up</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
        </div>
    </div>

        <!-- Leads Near Here (Account) -->
        <div class="sacp_card" style="margin-top:20px;" data-collapsible="nearby">
            <h3 style="margin:0 0 12px 0; display:flex; align-items:center; gap:8px; position:relative;" data-collapsible-header>
                <span>📍</span> Leads Near Here
                <span data-collapsible-toggle style="margin-left:auto; font-size:14px;">▼</span>
            </h3>
            <div data-collapsible-content>
                <?php 
                $accAddrFull = trim(($detail['address_line1'] ?: '') . ', ' . ($detail['city'] ?: '') . ', ' . ($detail['state_region'] ?: '') . ' ' . ($detail['postal_code'] ?: '') . ', ' . (($detail['country'] ?? 'USA')));
                ?>
                <?php if ($accAddrFull): ?>
                <div style="margin-bottom:8px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <div class="muted" style="font-size:14px;">📬 <?php echo htmlspecialchars($accAddrFull); ?></div>
                    <a class="btn small" target="_blank" style="background:#0369a1;" href="https://www.google.com/maps/dir/?api=1&destination=<?php echo urlencode($accAddrFull); ?>">Open in Google Maps</a>
                    <button class="btn small" type="button" onclick="acpAccRefreshNearby()">Refresh Results</button>
                </div>
                <div style="display:flex; align-items:center; gap:12px; margin:8px 0; flex-wrap:wrap;">
                    <label style="display:flex; align-items:center; gap:8px;">
                        <input type="checkbox" id="acc_toggle_gas" onchange="acpAccRefreshNearby()"> Include gas stations / convenience
                    </label>
                    <label class="muted small">Radius</label>
                    <select id="acc_nearbyRadius" class="input small" onchange="acpAccRefreshNearby()">
                        <option value="10">10 miles</option>
                        <option value="20">20 miles</option>
                        <option value="30">30 miles</option>
                    </select>
                    <label class="muted small" style="margin-left:12px;">Sort by</label>
                    <select id="acc_nearbySort" class="input small" onchange="accRenderNearby()">
                        <option value="distance">Distance</option>
                        <option value="name">Name</option>
                    </select>
                    <label class="muted small" style="margin-left:12px;">Store type</label>
                    <select id="acc_nearbyType" class="input small" onchange="acpAccRefreshNearby()">
                        <option value="auto">Auto (Product-first)</option>
                        <option value="product_kava">Product/partner</option>
                        <option value="smoke_vape">Smoke/Vape</option>
                        <option value="herbal">Herbal</option>
                        <option value="convenience_gas">Gas/Convenience</option>
                        <option value="all">All</option>
                    </select>
                </div>
                <div class="section-label" id="accNearbyLabel">Suggested Nearby Stores (10 miles)</div>
                <div id="accNearbyWrap" style="max-height:280px; overflow:auto; border:1px solid #e5e7eb; border-radius:8px; padding:8px; background:#fff;">
                    <div id="accNearbyStatus" class="muted small" style="margin-bottom:8px;">Ready.</div>
                    <div id="accNearbyList" class="nearby-list" style="display:grid; gap:8px;"></div>
                </div>
                <?php else: ?>
                <div class="muted">No address on file for this account.</div>
                <?php endif; ?>
            </div>
        </div>
        <!-- Quick Actions removed per design -->
    </div>

    <!-- Inline Call Modal for Account Detail (reuses dashboard pattern) -->
    <div id="acpCallModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,.5); z-index:7000;">
        <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:#fff; border-radius:12px; width:520px; max-width:92vw; max-height:90vh; overflow:auto;">
            <div style="padding:16px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0">Log Call</h3>
                <button class="btn secondary" onclick="document.getElementById('acpCallModal').style.display='none'">Close</button>
            </div>
            <div id="acpCallContent" style="padding:16px;"></div>
        </div>
        <script>
        async function acpOpenCallModal(leadId, tel, storeName, contactName, accountId){
            try {
                const url = `calls.php?action=add${leadId?('&lead_id='+encodeURIComponent(leadId)) : ''}${accountId?('&account_id='+encodeURIComponent(accountId)) : ''}&modal=1&contact_type=phone_call&direction=outbound&subject=${encodeURIComponent('Call: ' + (storeName||'Store'))}&summary=${encodeURIComponent('Called ' + (contactName||'contact') + (tel?(' at ' + tel):''))}`;
                const r = await fetch(url, { headers:{'X-Requested-With':'fetch'} });
                const html = await r.text();
                const wrap = document.getElementById('acpCallContent');
                wrap.innerHTML = html;
                const controls = document.createElement('div');
                controls.style.cssText = 'display:flex; align-items:center; gap:8px; margin-bottom:12px;';
                const dial = document.createElement('a');
                dial.className = 'btn'; dial.style.background = '#dc2626'; dial.textContent = tel ? ('📞 Tap to Call ' + tel) : '📞 Tap to Call';
                dial.href = tel ? ('tel:' + tel) : '#';
                dial.onclick = function(){ startAcpTimer(); };
                const timer = document.createElement('span'); timer.id='acpCallTimer'; timer.style.cssText='font-weight:700; color:#111827;'; timer.textContent='00:00';
                const startBtn = document.createElement('button'); startBtn.id='acpStartBtn'; startBtn.className='btn small'; startBtn.textContent='Start'; startBtn.onclick=function(){ startAcpTimer(); };
                const stopBtn = document.createElement('button'); stopBtn.id='acpStopBtn'; stopBtn.className='btn small secondary'; stopBtn.textContent='Stop'; stopBtn.onclick=function(){ stopAcpTimer(); };
                controls.appendChild(dial); controls.appendChild(timer); controls.appendChild(startBtn); controls.appendChild(stopBtn);
                wrap.prepend(controls);
                const form = wrap.querySelector('form[data-ajax]');
                if (form){
                    const durInput = form.querySelector('input[name="duration_minutes"]');
                    if (!durInput){
                        const di = document.createElement('input'); di.type='hidden'; di.name='duration_minutes'; form.appendChild(di);
                    }
                    form.addEventListener('submit', async function(ev){
                        try {
                            ev.preventDefault();
                            const secs = window.__acpTimerSecs || 0; const mins = Math.max(0, Math.round(secs/60));
                            form.querySelector('input[name="duration_minutes"]').value = String(mins);
                            const fd = new FormData(form);
                            const actionUrl = form.getAttribute('action') || 'calls.php';
                            const resp = await fetch(actionUrl, { method:'POST', body: fd });
                            let json = null; try { json = await resp.json(); } catch(e) {}
                            if (json && json.ok){
                                document.getElementById('acpCallModal').style.display='none';
                                acpToast('Call logged');
                                setTimeout(function(){ location.reload(); }, 600);
                            } else {
                                acpToast('Failed to log call', 'error');
                            }
                        } catch (e) {
                            acpToast('Error submitting form', 'error');
                        }
                    });
                }
                document.getElementById('acpCallModal').style.display='block';
            } catch(e){ alert('Unable to open call dialog.'); }
            return false;
        }
        let __acpTimer, __acpTimerStart, __acpTimerTick; window.__acpTimerSecs = 0;
        function renderAcpTimer(){ const el = document.getElementById('acpCallTimer'); if (!el) return; const s = window.__acpTimerSecs|0; const m = Math.floor(s/60); const r = s%60; el.textContent = String(m).padStart(2,'0') + ':' + String(r).padStart(2,'0'); }
        function startAcpTimer(){ if (__acpTimer) return; __acpTimerStart = Date.now(); __acpTimerTick = function(){ window.__acpTimerSecs = Math.round((Date.now() - __acpTimerStart)/1000); renderAcpTimer(); }; __acpTimer = setInterval(__acpTimerTick, 1000); __acpTimerTick(); try { document.getElementById('acpStartBtn').disabled = true; document.getElementById('acpStopBtn').disabled = false; } catch(e) {} }
        function stopAcpTimer(){ if (__acpTimer){ clearInterval(__acpTimer); __acpTimer = null; } renderAcpTimer(); try { var secs = window.__acpTimerSecs || 0; var mins = secs > 0 ? Math.max(1, Math.round(secs/60)) : 0; var durInputs = document.querySelectorAll('#acpCallContent input[name="duration_minutes"]'); durInputs.forEach(function(inp){ inp.value = String(mins); }); } catch(e) {} try { document.getElementById('acpStartBtn').disabled = false; document.getElementById('acpStopBtn').disabled = true; } catch(e) {} }
        </script>
    </div>
    <?php if (!empty($canEditAccount)): ?>
    <!-- Edit Address Modal -->
    <div id="acctEditModal" style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 5000;">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; padding: 24px; width: 520px; max-width: 92vw; max-height: 85vh; overflow-y: auto;">
            <h3 style="margin: 0 0 16px 0;">Edit Account Address</h3>
            <form method="post" style="display:grid; gap:12px;" id="acctEditForm" data-ajax="1">
                <input type="hidden" name="action" value="update_address">
                <input type="hidden" name="account_id" value="<?php echo intval($accountId); ?>">

                <div style="margin-top:4px; padding: 12px; border: 1px solid #0369a1; background: #f0f9ff; border-radius: 8px;">
                    <div style="display:flex; justify-content: space-between; align-items:center; gap:8px; margin-bottom:8px;">
                        <label style="margin:0; color:#0369a1;">Smart Address</label>
                        <div style="display:flex; gap:8px;">
                            <button type="button" class="btn small" style="background:#0369a1;" onclick="sacpAcct_useMyLocation()">📍 Use My Location</button>
                            <button type="button" class="btn small secondary" onclick="sacpAcct_clearAddress()">Clear</button>
                        </div>
                    </div>
                    <input id="acct_autocomplete_address" type="text" placeholder="Start typing an address..." style="width: 100%; padding: 10px; border: 1px solid #93c5fd; border-radius: 8px;" value="<?php echo htmlspecialchars(trim(($detail['address_line1'] ?? '') . ', ' . ($detail['city'] ?? '') . ', ' . ($detail['state_region'] ?? '') . ' ' . ($detail['postal_code'] ?? ''))); ?>">
                </div>

                <div style="display:grid; grid-template-columns: 2fr 1fr; gap:8px;">
                    <div>
                        <label>Address line 1</label>
                        <input name="address_line1" id="acct_addr_line1" value="<?php echo htmlspecialchars($detail['address_line1'] ?? ''); ?>" class="paladin-input" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
                    </div>
                    <div>
                        <label>Address line 2</label>
                        <input name="address_line2" id="acct_addr_line2" value="<?php echo htmlspecialchars($detail['address_line2'] ?? ''); ?>" class="paladin-input" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
                    </div>
                    <div>
                        <label>City</label>
                        <input name="city" id="acct_addr_city" value="<?php echo htmlspecialchars($detail['city'] ?? ''); ?>" class="paladin-input" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
                    </div>
                    <div>
                        <label>State</label>
                        <input name="state_region" id="acct_addr_state" value="<?php echo htmlspecialchars($detail['state_region'] ?? ''); ?>" class="paladin-input" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
                    </div>
                    <div>
                        <label>Postal Code</label>
                        <input name="postal_code" id="acct_addr_postal" value="<?php echo htmlspecialchars($detail['postal_code'] ?? ''); ?>" class="paladin-input" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
                    </div>
                </div>

                <div style="display:flex; gap:12px; justify-content:flex-end; margin-top:8px;">
                    <button type="button" class="btn secondary" onclick="document.getElementById('acctEditModal').style.display='none'">Cancel</button>
                    <button type="submit" class="btn" style="background:#059669;">Save Address</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    <!-- Header contains quick actions; floating FAB removed for Accounts -->
    <!-- Contacts section moved to top; removed duplicate block -->

    <!-- Recent Invoices (Collapsible) -->
    <div class="sacp_card" style="margin-top:20px;">
        <h3 style="margin:0 0 20px 0; display:flex; align-items:center; gap:8px; position:relative;">
            <span>📄</span> Recent Invoices
            <span class="sacp_badge" style="background: var(--acp-success-bg, #dcfce7); color: var(--acp-success, #059669); padding: 2px 8px; border-radius: 10px; font-size: 12px; margin-left: 8px;">
                <?php echo count($invoices); ?>
            </span>
        </h3>
        <div>
        <?php if (empty($invoices)): ?>
            <div style="text-align:center; padding:32px; color: var(--acp-silver, #9ca3af);">
                <div style="font-size:48px; margin-bottom:12px;">📋</div>
                <p>No invoices created yet.</p>
                <a href="invoice.php?account_id=<?php echo intval($accountId); ?>" class="btn primary" style="margin-top:16px;">
                    Create First Invoice
                </a>
            </div>
        <?php else: ?>
            <div style="display:grid; gap:10px;">
                <?php foreach ($invoices as $o): ?>
                    <div class="sacp_invoice-item" onclick="acpOpenInvoiceModal(<?php echo (int)$o['id']; ?>)" style="cursor:pointer;">
                        <div>
                            <div style="font-weight:600; color: var(--acp-carbon, #1f2937);">
                                <?php echo htmlspecialchars($o['order_number'] ?: '#'.str_pad($o['id'],6,'0',STR_PAD_LEFT)); ?>
                            </div>
                            <div style="font-size:12px; color: var(--acp-graphite, #6b7280);">
                                <?php echo date('M j, Y', strtotime($o['created_at'])); ?> • 
                                <?php echo ucfirst($o['status']); ?>
                            </div>
                        </div>
                        <div style="font-weight:700; font-size:18px; color: var(--acp-success, #059669);">
                            $<?php echo number_format($o['total'],2); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        </div>
        
        <!-- Workflow Configuration Card -->
        <div class="sacp_card" style="margin-top:20px;">
            <h4 style="margin:0 0 12px;font-size:16px;font-weight:600;color:#1f2937;">🔄 Follow-up Automation</h4>
            <button type="button" class="btn" style="width:100%;background:linear-gradient(135deg,#14b8a6,#0ea5e9);color:#fff;padding:12px;border:none;border-radius:8px;cursor:pointer;font-weight:600;display:flex;align-items:center;justify-content:center;gap:8px;"
                    onclick="WorkflowEntityConfig.show('account', <?php echo $accountId; ?>, '<?php echo htmlspecialchars(addslashes($detail['business_name'] ?? '')); ?>')">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M12 6v6l4 2"/>
                </svg>
                Configure Workflows
            </button>
            <p style="margin:12px 0 0;font-size:12px;color:#64748b;text-align:center;">
                Set up automated follow-up emails for this account
            </p>
        </div>
    </div>

    <!-- Email Composer Tab -->
    <?php
    $emailComposerContext = [
        'type' => 'account',
        'id' => $accountId,
        'recipient_email' => $detail['email'] ?? '',
        'recipient_name' => trim(($detail['first_name'] ?? '') . ' ' . ($detail['last_name'] ?? '')),
        'company_name' => $detail['business_name'] ?? ''
    ];
    require_once __DIR__ . '/../../required/email-composer-tab.php';
    ?>

    <?php else: ?>
    <!-- Accounts List View -->
    <?php if (empty($rows)): ?>
        <div class="sacp_card" style="text-align:center; padding:48px;">
            <div style="font-size:64px; margin-bottom:16px;">🏢</div>
            <h2 style="color: var(--acp-carbon, #1f2937); margin-bottom:8px;">No Accounts Found</h2>
            <p style="color: var(--acp-graphite, #6b7280); margin-bottom:24px;">
                <?php if ($q): ?>
                    No accounts match your search criteria.
                <?php else: ?>
                    Convert leads to start building your account portfolio.
                <?php endif; ?>
            </p>
            <?php if ($q): ?>
                <a href="accounts.php" class="btn primary">Clear Search</a>
            <?php else: ?>
                <a href="leads.php" class="btn primary">View Leads</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="sacp_card">
            <div class="table-wrapper">
                <table class="paladin-table" style="width:100%;" id="acctTable">
                    <thead>
                        <tr>
                            <th>Store</th>
                            <th>Address</th>
                            <th>30d Sales / Inv</th>
                            <th>30d Commission</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $a): ?>
                        <?php 
                        $rowTpl = __DIR__ . '/partials/account-row.php';
                        if (is_file($rowTpl)) {
                            /** @noinspection PhpIncludeInspection */
                            include $rowTpl;
                        } else {
                            ?>
                            <tr>
                                <td style="font-weight:600; color: var(--acp-carbon, #1f2937);">
                                    <div style="display:flex; align-items:center; gap:10px;">
                                        <div style="width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg, var(--acp-blue-deep, #1e40af), var(--acp-blue, #0369a1)); display:flex; align-items:center; justify-content:center; font-weight:600; color:white; font-size:14px;">
                                            <?php echo strtoupper(substr((string)($a['business_name'] ?? '?'), 0, 1)); ?>
                                        </div>
                                        <a href="accounts.php?id=<?php echo intval($a['id']); ?>" class="link-plain" style="color: inherit; text-decoration: none;">
                                            <?php echo htmlspecialchars($a['business_name'] ?? ''); ?>
                                        </a>
                                    </div>
                                </td>
                                <td>
                                    <?php 
                                    $addr = trim(($a['address_line1'] ?? '') . ', ' . ($a['city'] ?? '') . ', ' . ($a['state_region'] ?? '') . ' ' . ($a['postal_code'] ?? ''));
                                    echo $addr !== ', ,  ' ? htmlspecialchars($addr) : '<span class="muted">—</span>';
                                    ?>
                                </td>
                                <td>
                                    $<?php echo number_format((float)($a['sales_30d'] ?? 0), 2); ?> 
                                    <span class="muted small">(<?php echo (int)($a['inv_30d'] ?? 0); ?> inv)</span>
                                </td>
                                <td>
                                    $<?php echo number_format((float)($a['comm_30d'] ?? 0), 2); ?>
                                </td>
                                <td>
                                    <span class="sacp_status-badge <?php echo !empty($a['approved']) ? 'approved' : 'pending'; ?>">
                                        <?php echo !empty($a['approved']) ? 'Approved' : 'Pending'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="display:flex; gap:6px;">
                                        <a class="btn small secondary" href="accounts.php?id=<?php echo intval($a['id']); ?>">View</a>
                                        <a class="btn small" style="background: var(--acp-blue, #0369a1);" href="invoices.php?account_id=<?php echo intval($a['id']); ?>">Invoices</a>
                                    </div>
                                </td>
                            </tr>
                            <?php
                        }
                        ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<script>
(function(){
    var input = document.getElementById('acctSearch');
    var loading = document.getElementById('acctLoading');
    var tableRoot = document.getElementById('acctTable');
    var table = tableRoot ? tableRoot.getElementsByTagName('tbody')[0] : null;
    if (!input || !table) return;
    var timer = null;
    input.addEventListener('input', function(){
        var v = input.value.trim();
        document.getElementById('acctClear').style.display = v ? 'inline-flex' : 'none';
        if (timer) clearTimeout(timer);
        timer = setTimeout(function(){
            loading.style.display = 'block';
            var url = 'accounts.php?ajax=1&q=' + encodeURIComponent(v);
            fetch(url, { headers: { 'X-Requested-With': 'fetch' } })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    loading.style.display = 'none';
                    if (!res || !res.ok) return;
                    var rows = res.rows || [];
                    table.innerHTML = '';
                    if (rows.length === 0) {
                        var tr = document.createElement('tr');
                        var td = document.createElement('td'); td.colSpan = 6; td.className='muted'; td.textContent='No accounts found.'; tr.appendChild(td); table.appendChild(tr);
                        return;
                    }
                    rows.forEach(function(a){
                        var tr = document.createElement('tr');
                        var addr = [a.address_line1||'', a.city||'', a.state_region||'', a.postal_code||''].join(', ').replace(/(,\s*){2,}/g, ', ').replace(/^,\s*|,\s*$/g,'');
                        tr.innerHTML =
                            '<td style="font-weight:600; color: var(--acp-carbon, #1f2937);">' +
                                '<div style="display:flex; align-items:center; gap:10px;">' +
                                    '<div style="width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg, var(--acp-blue-deep, #1e40af), var(--acp-blue, #0369a1)); display:flex; align-items:center; justify-content:center; font-weight:600; color:white; font-size:14px;">' +
                                        (a.business_name ? a.business_name.charAt(0).toUpperCase() : '?') +
                                    '</div>' +
                                    '<a href="accounts.php?id=' + encodeURIComponent(a.id) + '" class="link-plain" style="color: inherit; text-decoration: none;">' + (a.business_name ? escapeHtml(a.business_name) : '') + '</a>' +
                                '</div>' +
                            '</td>' +
                            '<td>' + (addr ? escapeHtml(addr) : '<span class="muted">—</span>') + '</td>' +
                            '<td>$' + Number(a.sales_30d||0).toFixed(2) + ' <span class="muted small">(' + parseInt(a.inv_30d||0) + ' inv)</span></td>' +
                            '<td>$' + Number(a.comm_30d||0).toFixed(2) + '</td>' +
                            '<td><span class="sacp_status-badge ' + (a.approved ? 'approved' : 'pending') + '">' + (a.approved ? 'Approved' : 'Pending') + '</span></td>' +
                            '<td><div style="display:flex; gap:6px;"><a class="btn small secondary" href="accounts.php?id=' + encodeURIComponent(a.id) + '">View</a><a class="btn small primary" href="invoices.php?account_id=' + encodeURIComponent(a.id) + '">Invoices</a></div></td>';
                        table.appendChild(tr);
                    });
                })
                .catch(function(){ loading.style.display = 'none'; });
        }, 250);
    });
    function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c]); }); }
    function escapeAttr(s){ return (s||'').replace(/["']/g, function(c){ return ({'"':'&quot;','\'':'&#39;'}[c]); }); }
})();
</script>

<script>
// Header activity menus for Activity Timeline
function toggleAccountActivityMenu(){
    try {
        var m = document.getElementById('accountActivityMenu'); if (!m) return;
        m.classList.toggle('active');
        var other = document.getElementById('accountViewAllMenu'); if (other) other.classList.remove('active');
    } catch(e) {}
}
function toggleAccountViewAll(){
    try {
        var m = document.getElementById('accountViewAllMenu'); if (!m) return;
        m.classList.toggle('active');
        var other = document.getElementById('accountActivityMenu'); if (other) other.classList.remove('active');
    } catch(e) {}
}
document.addEventListener('click', function(e){
    try {
        if (!e.target.closest('.header-actions-group')){
            var a = document.getElementById('accountActivityMenu'); if (a) a.classList.remove('active');
            var b = document.getElementById('accountViewAllMenu'); if (b) b.classList.remove('active');
        }
    } catch(_) {}
});
</script>

<!-- Invoice Detail Modal (Accounts) -->
<div id="acpInvoiceModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10000;">
    <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:#fff; border-radius:12px; width:820px; max-width:95vw; max-height:92vh; overflow:auto;">
        <div style="padding:12px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0">Invoice Detail</h3>
            <button class="btn secondary" onclick="document.getElementById('acpInvoiceModal').style.display='none'">Close</button>
        </div>
        <div id="acpInvoiceContent" style="padding:16px;"></div>
    </div>
</div>
<script>
async function acpOpenInvoiceModal(orderId){
    try {
        var m = document.getElementById('acpInvoiceModal');
        var c = document.getElementById('acpInvoiceContent');
        c.innerHTML = '<div class="muted">Loading…</div>';
        m.style.display = 'block';
        const r = await fetch('invoice-detail.php?partial=1&id=' + encodeURIComponent(orderId), { headers: { 'X-Requested-With':'fetch' } });
        const html = await r.text();
        c.innerHTML = html;
    } catch (e) {
        try { acpToast('Failed to load invoice','error'); } catch(_) {}
    }
}
</script>

<!-- Inline Follow-up Modal (Accounts) -->
<div id="acpInlineFuModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10000;">
    <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:#fff; border-radius:12px; padding:24px; width:520px; max-width:92vw; max-height:90vh; overflow:auto;">
        <h3 style="margin-top:0;">Complete Follow-up</h3>
        <form id="acpInlineFuForm" method="post" style="display:grid; gap:12px;">
            <input type="hidden" name="action" value="complete_followup">
            <input type="hidden" name="dropoff_id" id="acp_fu_dropoff_id">
            <input type="hidden" name="task_id" id="acp_fu_task_id">
            <div>
                <label>Follow-up Status</label>
                <select name="status" id="acp_fu_status">
                    <option value="completed">Completed - Successful Follow-up</option>
                    <option value="cancelled">Cancelled - Customer Not Interested</option>
                </select>
            </div>
            <div id="acp_fu_outcome" style="display:grid; gap:8px;">
                <label style="font-weight:600;">Customer Feedback:</label>
                <label style="display:flex; align-items:center; gap:8px; font-weight:500;"><input type="checkbox" name="interested_75mg" value="1"> Interested in trying 75mg blend</label>
                <label style="display:flex; align-items:center; gap:8px; font-weight:500;"><input type="checkbox" name="happy_current" value="1"> Happy with current blend (32/20)</label>
                <label style="display:flex; align-items:center; gap:8px; font-weight:500;"><input type="checkbox" name="ready_order" value="1"> Ready to place order</label>
            </div>
            <div>
                <label>Follow-up Notes</label>
                <textarea name="notes" rows="3" placeholder="Customer feedback, next steps, etc..."></textarea>
            </div>
            <div style="display:flex; gap:8px; justify-content:end;">
                <button type="button" class="btn secondary" onclick="document.getElementById('acpInlineFuModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn" style="background:#059669;">Complete Follow-up</button>
            </div>
        </form>
    </div>
    <script>
    (function(){
        var form = document.getElementById('acpInlineFuForm'); if (!form) return;
        form.addEventListener('submit', async function(e){
            e.preventDefault();
            try {
                const fd = new FormData(form);
                const res = await fetch('dropoffs.php', { method: 'POST', body: fd });
                document.getElementById('acpInlineFuModal').style.display='none';
                acpToast('Follow-up completed');
                setTimeout(function(){ location.reload(); }, 600);
            } catch (err) { acpToast('Failed to complete follow-up', 'error'); }
        });
    })();
    </script>
</div>

<script>
// Address helpers for account edit modal (reuse proxy approach from leads)
function sacpAcct_fillAddressFields(place){
    try {
        if (!place) return;
        var ac = place.address_components || [];
        var parts = { street_number:'', route:'', locality:'', admin_area_l1:'', postal_code:'' };
        (ac||[]).forEach(function(c){
            if ((c.types||[]).indexOf('street_number') !== -1) parts.street_number = c.long_name;
            if ((c.types||[]).indexOf('route') !== -1) parts.route = c.long_name;
            if ((c.types||[]).indexOf('locality') !== -1) parts.locality = c.long_name;
            if ((c.types||[]).indexOf('administrative_area_level_1') !== -1) parts.admin_area_l1 = c.short_name;
            if ((c.types||[]).indexOf('postal_code') !== -1) parts.postal_code = c.long_name;
        });
        var line1 = (parts.street_number + ' ' + parts.route).trim();
        var $ = function(id){ return document.getElementById(id); };
        if ($('acct_addr_line1')) $('acct_addr_line1').value = line1 || (place.name || '');
        if ($('acct_addr_line2')) $('acct_addr_line2').value = '';
        if ($('acct_addr_city')) $('acct_addr_city').value = parts.locality || '';
        if ($('acct_addr_state')) $('acct_addr_state').value = parts.admin_area_l1 || '';
        if ($('acct_addr_postal')) $('acct_addr_postal').value = parts.postal_code || '';
    } catch(e) {}
}

// Bind proxy autocomplete if Google Places JS is unavailable
function sacpAcct_bindProxyAutocomplete(){
    var input = document.getElementById('acct_autocomplete_address');
    if (!input || input.__proxyBound) return;
    input.__proxyBound = true;
    var listId = 'acct_autocomplete_list';
    var dl = document.createElement('div');
    dl.id = listId; dl.className = 'pac-container';
    dl.style.position='absolute'; dl.style.background='#fff'; dl.style.border='1px solid #e5e7eb'; dl.style.borderRadius='6px'; dl.style.boxShadow='0 6px 16px rgba(0,0,0,.12)'; dl.style.zIndex=99999;
    document.body.appendChild(dl);
    function positionList(){
        var rect = input.getBoundingClientRect();
        dl.style.left = rect.left + 'px';
        dl.style.top = (rect.bottom + window.scrollY) + 'px';
        dl.style.width = rect.width + 'px';
    }
    input.addEventListener('input', function(){
        positionList();
        var val = input.value.trim(); if (val.length < 3) { dl.innerHTML=''; return; }
        var session = (window.__ac_session || (window.__ac_session = (Math.random().toString(36).slice(2))));
        fetch(((window.__BASE_PREFIX||'')) + '/api/google-places-proxy.php?op=ac&q=' + encodeURIComponent(val) + '&session=' + encodeURIComponent(session))
            .then(function(r){ return r.json(); })
            .then(function(data){
                dl.innerHTML='';
                (data.predictions||[]).slice(0,5).forEach(function(p){
                    var item = document.createElement('div');
                    item.style.padding='10px 12px'; item.style.cursor='pointer'; item.style.borderBottom='1px solid #f3f4f6';
                    item.onmouseenter=function(){ this.style.background='#f9fafb'; };
                    item.onmouseleave=function(){ this.style.background='#fff'; };
                    item.textContent = p.description;
                    item.onclick = function(){
                        input.value = p.description; dl.innerHTML='';
                        fetch(((window.__BASE_PREFIX||'')) + '/api/google-places-proxy.php?op=details&place_id=' + encodeURIComponent(p.place_id))
                            .then(function(r){ return r.json(); })
                            .then(function(d){ sacpAcct_fillAddressFields(d.result || d); });
                    };
                    dl.appendChild(item);
                });
            });
    });
    input.addEventListener('blur', function(){ setTimeout(function(){ dl.innerHTML=''; }, 200); });
    window.addEventListener('resize', positionList);
}

function sacpAcct_useMyLocation(){
    if (!navigator.geolocation) { alert('Geolocation not supported on this device.'); return; }
    navigator.geolocation.getCurrentPosition(function(pos){
        var latitude = pos.coords.latitude;
        var longitude = pos.coords.longitude;
        fetch(((window.__BASE_PREFIX||'')) + '/api/google-places-proxy.php?op=geocode&lat=' + latitude + '&lng=' + longitude)
            .then(function(resp){ return resp.json(); })
            .then(function(data){
                var result = (data.results && data.results[0]) || null;
                var input = document.getElementById('acct_autocomplete_address'); if (input && result) input.value = result.formatted_address || '';
                sacpAcct_fillAddressFields(result);
            }).catch(function(){ alert('Failed to fetch address from location.'); });
    }, function(){ alert('Unable to access location.'); });
}

function sacpAcct_clearAddress(){
    ['acct_autocomplete_address','acct_addr_line1','acct_addr_line2','acct_addr_city','acct_addr_state','acct_addr_postal'].forEach(function(id){ var el = document.getElementById(id); if (el) el.value=''; });
}

// Initialize proxy autocomplete when modal opens
document.addEventListener('click', function(e){
    var m = document.getElementById('acctEditModal');
    if (m && m.style.display === 'block') {
        sacpAcct_bindProxyAutocomplete();
    }
});

// Activity filter for account timeline
function acpActivityFilter(type, tabBtn){
    try {
        var tl = document.getElementById('acpActivityTimeline'); if (!tl) return;
        var items = tl.querySelectorAll('.timeline-item');
        if (tabBtn){ Array.prototype.forEach.call(document.querySelectorAll('.activity-tab'), function(t){ t.classList.remove('active'); }); tabBtn.classList.add('active'); }
        var count=0; Array.prototype.forEach.call(items, function(it){ var show = (type==='all' || (it.getAttribute('data-type')===type)); it.style.display = show ? 'flex' : 'none'; if (show) count++; });
        if (count===0){ var es = tl.querySelector('.timeline-empty'); if (!es){ es = document.createElement('div'); es.className='timeline-empty'; es.textContent='No items'; tl.appendChild(es);} es.style.display='block'; } else { var es2 = tl.querySelector('.timeline-empty'); if (es2) es2.style.display='none'; }
    } catch(e) {}
}
function acpOpenFollowUpModal(dropoffId, taskId){
    try {
        document.getElementById('acpInlineFuModal').style.display='block';
        document.getElementById('acp_fu_dropoff_id').value = dropoffId || 0;
        document.getElementById('acp_fu_task_id').value = taskId || 0;
        document.getElementById('acp_fu_status').value = 'completed';
    } catch(e) {}
}

async function acpCompleteTimelineTask(taskId, btn){
    if (!taskId) return;
    try {
        btn.disabled = true;
        const fd = new FormData();
        fd.append('action','update_status');
        fd.append('task_id', String(taskId));
        fd.append('status','completed');
        const res = await fetch('tasks.php', { method:'POST', body: fd });
        let data = null; try { data = await res.json(); } catch(e) {}
        if (data && data.ok){
            btn.outerHTML = '<span class="badge-mini badge-completed">completed</span>';
            acpToast('Task completed');
        } else {
            btn.disabled = false; acpToast('Failed to complete task','error');
        }
    } catch(e){ btn.disabled = false; acpToast('Failed to complete task','error'); }
}

// Demo modal opener (Accounts) mirrors Leads openModal('demo', …)
async function acpOpenDemoModal(accountId){
    try {
        const container = document.getElementById('modalContainer') || (function(){
            var c = document.createElement('div'); c.id='modalContainer'; c.style.cssText='position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:none;z-index:9999;';
            c.innerHTML = '<div id="modalContent" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:white;border-radius:12px;max-width:90vw;max-height:90vh;overflow:auto;"></div>';
            document.body.appendChild(c); return c; })();
        const content = document.getElementById('modalContent');
        const url = 'dropoff.php?modal=1&account_id=' + encodeURIComponent(accountId||0);
        const r = await fetch(url, { headers: { 'X-Requested-With':'fetch' } });
        const html = await r.text();
        content.innerHTML = html; container.style.display = 'block';
        var form = content.querySelector('form[data-ajax]') || content.querySelector('form');
        if (form){
            form.addEventListener('submit', async function(e){
                try {
                    if (form.getAttribute('data-ajax') !== null) e.preventDefault();
                    const fd = new FormData(form);
                    const actionUrl = form.getAttribute('action') || 'dropoff.php';
                    const resp = await fetch(actionUrl, { method:'POST', body: fd });
                    let json = null; try { json = await resp.json(); } catch(_) {}
                    container.style.display = 'none';
                    acpToast('Demo recorded');
                    setTimeout(function(){ location.reload(); }, 600);
                } catch(err){ acpToast('Failed to record demo','error'); }
            });
        }
    } catch (e) { acpToast('Failed to open demo form','error'); }
}

// Generic modal for Notes/Tasks (Accounts)
;(function(){
    if (!document.getElementById('acpGenericModal')){
        var m = document.createElement('div');
        m.id = 'acpGenericModal';
        m.style.cssText = 'display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:7000;';
        m.innerHTML = '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:12px;max-width:92vw;max-height:90vh;overflow:auto;width:520px;"><div style="padding:16px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;"><h3 style="margin:0">Add Item</h3><button class="btn secondary" onclick="document.getElementById(\'acpGenericModal\').style.display=\'none\'">Close</button></div><div id="acpGenericContent" style="padding:16px;"></div></div>';
        document.body.appendChild(m);
        m.addEventListener('click', function(ev){ if (ev.target === m) m.style.display = 'none'; });
    }
})();

function acpToggleFAB(){
    try { var menu = document.getElementById('acpFabMenu'); if (!menu) return; menu.style.display = (menu.style.display==='flex') ? 'none' : 'flex'; menu.style.display = menu.style.display || 'flex'; menu.style.flexDirection = 'column-reverse'; } catch(e) {}
}

async function acpOpenQuickModal(type, accountId){
    try {
        var url = '';
        if (type === 'note') url = 'notes.php?action=add&account_id=' + encodeURIComponent(accountId||0) + '&modal=1';
        else if (type === 'task') url = 'tasks.php?action=add&account_id=' + encodeURIComponent(accountId||0) + '&modal=1';
        else return;
        const r = await fetch(url, { headers: { 'X-Requested-With':'fetch' } });
        const html = await r.text();
        var content = document.getElementById('acpGenericContent');
        content.innerHTML = html;
        var modal = document.getElementById('acpGenericModal');
        modal.style.display = 'block';
        var form = content.querySelector('form[data-ajax]');
        if (form){
            form.addEventListener('submit', async function(ev){
                ev.preventDefault();
                try {
                    const fd = new FormData(form);
                    const actionUrl = form.getAttribute('action') || url;
                    const resp = await fetch(actionUrl, { method:'POST', body: fd });
                    let json = null; try { json = await resp.json(); } catch(e) {}
                    if (json && json.ok){ modal.style.display='none'; acpToast((type==='note'?'Note':'Task') + ' added'); setTimeout(function(){ location.reload(); }, 600); }
                    else { acpToast('Failed to save','error'); }
                } catch(e){ acpToast('Error submitting form','error'); }
            });
        }
    } catch(e){ acpToast('Failed to load form','error'); }
}

// AJAX submit for Account Edit Address form
document.addEventListener('submit', async function(e){
    var f = e.target;
    if (f && f.id === 'acctEditForm' && f.hasAttribute('data-ajax')){
        e.preventDefault();
        try {
            const fd = new FormData(f);
            const resp = await fetch('accounts.php<?php echo $accountId?('?id='.intval($accountId)) : ''; ?>', { method:'POST', body: fd, headers: { 'X-Requested-With':'fetch' } });
            // Regardless of response body, close and refresh for consistency with server redirect
            document.getElementById('acctEditModal').style.display='none';
            acpToast('Address updated');
            setTimeout(function(){ location.reload(); }, 500);
        } catch (err) { acpToast('Failed to update address','error'); }
    }
});
// Lightweight toast for Accounts page
function acpToast(msg, type){
    try {
        if (type === 'error' && window.SACPInteractions && SACPInteractions.showErrorToast) { SACPInteractions.showErrorToast(msg); return; }
        if (window.SACPInteractions && SACPInteractions.showSuccessToast) { SACPInteractions.showSuccessToast(msg); return; }
    } catch(e) {}
    try {
        var t = document.createElement('div');
        t.style.cssText = 'position:fixed;bottom:22px;left:50%;transform:translateX(-50%);background:' + (type==='error'?'#b91c1c':'#059669') + ';color:#fff;padding:10px 16px;border-radius:8px;z-index:9999;box-shadow:0 4px 14px rgba(0,0,0,.2)';
        t.textContent = msg; document.body.appendChild(t); setTimeout(function(){ t.remove(); }, 2500);
    } catch(e) { try { alert(msg); } catch(e2) {} }
}

// Nearby suggestions for account
function accUpdateNearbyStatus(message, theme){
    try {
        var el = document.getElementById('accNearbyStatus');
        if (!el) return;
        el.textContent = message;
        el.style.color = theme === 'warn' ? '#b45309' : (theme === 'error' ? '#b91c1c' : '#4b5563');
    } catch (e) {}
}

function accGetSelectedRadiusMeters(){
    var sel = document.getElementById('acc_nearbyRadius');
    var miles = sel ? parseFloat(sel.value || '10') : 10;
    if (!(miles > 0)) { miles = 10; }
    return miles * 1609.344;
}

function accGetRadiusLabel(){
    return Math.round(accGetSelectedRadiusMeters() / 1609.344);
}

function accIncludeGas(){
    return !!(document.getElementById('acc_toggle_gas') && document.getElementById('acc_toggle_gas').checked);
}

function accGetSelectedType(){
    return (document.getElementById('acc_nearbyType') || {}).value || 'auto';
}

function acpAccRefreshNearby(){
    try {
        var addr = <?php echo json_encode(trim(($detail['address_line1'] ?? '') . ', ' . ($detail['city'] ?? '') . ', ' . ($detail['state_region'] ?? '') . ' ' . ($detail['postal_code'] ?? '') . ', ' . (($detail['country'] ?? 'USA')))); ?>;
        if (!addr) {
            accUpdateNearbyStatus('No address available for this account.', 'warn');
            return;
        }
        var wrap = document.getElementById('accNearbyList');
        if (wrap) { wrap.innerHTML = ''; }
        accUpdateNearbyStatus('Searching locations…', 'info');
        try { if (window.__accNearbyAbort) { window.__accNearbyAbort.abort(); } } catch (e) {}
        window.__accNearbyAbort = new AbortController();
        fetch(((window.__BASE_PREFIX||'')) + '/api/google-places-proxy.php?op=geocode_addr&address=' + encodeURIComponent(addr), { signal: window.__accNearbyAbort.signal })
            .then(function(resp){ return resp.json(); })
            .then(function(data){
                var res = (data.results && data.results[0]);
                if (!res || !res.geometry || !res.geometry.location) {
                    accUpdateNearbyStatus('Unable to locate address.', 'error');
                    return;
                }
                accLoadNearby({ lat: res.geometry.location.lat, lng: res.geometry.location.lng }, accGetSelectedRadiusMeters());
            })
            .catch(function(err){
                if (err && err.name === 'AbortError') {
                    accUpdateNearbyStatus('Nearby search cancelled.', 'warn');
                    return;
                }
                accUpdateNearbyStatus('Unable to load nearby suggestions.', 'error');
            });
    } catch (e) {
        accUpdateNearbyStatus('Unable to load nearby suggestions.', 'error');
    }
}

function accLoadNearby(origin, radiusMeters){
    try {
        var wrap = document.getElementById('accNearbyList');
        if (wrap) { wrap.innerHTML = ''; }
        var includeGas = accIncludeGas() ? 1 : 0;
        var typeSel = accGetSelectedType();
        var fetchToken = Date.now();
        window.__accNearbyFetchToken = fetchToken;
        var lagTimer = setTimeout(function(){
            if (window.__accNearbyFetchToken === fetchToken) {
                accUpdateNearbyStatus('Still collecting locations… please hold a moment.', 'warn');
            }
        }, 3000);
        var latVal = typeof origin.lat === 'number' ? origin.lat : (typeof origin.lat === 'function' ? origin.lat() : 0);
        var lngVal = typeof origin.lng === 'number' ? origin.lng : (typeof origin.lng === 'function' ? origin.lng() : 0);
        function runSearch(catList){
            var catParam = catList && catList.length ? ('&categories=' + encodeURIComponent(catList.join(','))) : '';
            var url = ((window.__BASE_PREFIX||'')) + '/api/google-places-proxy.php?op=v1_textsearch&lat=' + latVal + '&lng=' + lngVal + '&radius=' + radiusMeters + '&include_gas=' + includeGas + '&details=1' + catParam;
            return fetch(url, { signal: (window.__accNearbyAbort && window.__accNearbyAbort.signal) }).then(function(resp){ return resp.json(); });
        }
        var fetchQueues = [];
        if (typeSel === 'auto') {
            fetchQueues.push(['product_kava']);
            fetchQueues.push(['smoke_vape']);
            fetchQueues.push(['herbal']);
            if (includeGas) { fetchQueues.push(['convenience_gas']); }
            fetchQueues.push([]);
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
                    if (window.__accNearbyAbort && window.__accNearbyAbort.signal.aborted) { clearTimeout(lagTimer); return; }
                    var data = await runSearch(fetchQueues[i]);
                    (data.results || []).forEach(function(item){
                        if (!item || !item.place_id || resultsById[item.place_id]) { return; }
                        resultsById[item.place_id] = item;
                    });
                    var total = Object.keys(resultsById).length;
                    accUpdateNearbyStatus('Found ' + total + ' locations within ' + accGetRadiusLabel() + ' miles…', 'info');
                    if (total >= 40) { break; }
                }
                if (window.__accNearbyAbort && window.__accNearbyAbort.signal.aborted) { clearTimeout(lagTimer); return; }
                clearTimeout(lagTimer);
                window.__ACC_NEARBY_RESULTS = Object.keys(resultsById).map(function(key){ return resultsById[key]; });
                if (!window.__ACC_NEARBY_RESULTS.length) {
                    accUpdateNearbyStatus('No locations found for this area.', 'warn');
                    accRenderNearby();
                    return;
                }
                window.__ACC_NEARBY_RESULTS.forEach(function(place){
                    try {
                        var loc = place.geometry && place.geometry.location ? place.geometry.location : {};
                        var plat = (typeof loc.lat === 'function') ? loc.lat() : loc.lat;
                        var plng = (typeof loc.lng === 'function') ? loc.lng() : loc.lng;
                        if (typeof plat === 'number' && typeof plng === 'number') {
                            place.__distance = getDistanceMiles(latVal, lngVal, plat, plng);
                        } else if (typeof place.distance_m === 'number') {
                            place.__distance = place.distance_m / 1609.344;
                        } else {
                            place.__distance = 9999;
                        }
                        if (!place.phone && !place.intl_phone && !place.website && !place.websiteUri) {
                            place.__details_pending = true;
                        }
                    } catch (e) { place.__distance = 9999; }
                });
                var label = document.getElementById('accNearbyLabel');
                if (label) { label.textContent = 'Suggested Nearby Stores (' + accGetRadiusLabel() + ' miles)'; }
                accRenderNearby();
                accUpdateNearbyStatus('Collecting websites and phone numbers…', 'info');
                accPrimeNearbyDetails(window.__ACC_NEARBY_RESULTS, fetchToken)
                    .then(function(){
                        if (window.__accNearbyFetchToken === fetchToken) {
                            accRenderNearby();
                            accUpdateNearbyStatus('Nearby results updated.', 'info');
                        }
                    })
                    .catch(function(){
                        if (window.__accNearbyFetchToken === fetchToken) {
                            accUpdateNearbyStatus('Ready — showing top matches (details may still be missing).', 'warn');
                        }
                    });
            } catch (err) {
                clearTimeout(lagTimer);
                if (err && err.name === 'AbortError') {
                    accUpdateNearbyStatus('Nearby search cancelled.', 'warn');
                    return;
                }
                accUpdateNearbyStatus('Unable to load nearby suggestions.', 'error');
                window.__ACC_NEARBY_RESULTS = [];
                accRenderNearby();
            }
        })();
    } catch (e) {
        accUpdateNearbyStatus('Unable to load nearby suggestions.', 'error');
    }
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

function accPrimeNearbyDetails(results, fetchToken){
    return new Promise(function(resolve){
        try {
            if (!Array.isArray(results) || !results.length) { resolve(); return; }
            var detailLag = setTimeout(function(){
                if (window.__accNearbyFetchToken === fetchToken) {
                    accUpdateNearbyStatus('Finalizing contact details…', 'warn');
                }
            }, 2000);
            Promise.all(results.map(function(item){
                if (!item || !item.place_id) { return Promise.resolve(); }
                return fetch(((window.__BASE_PREFIX||'')) + '/api/google-places-proxy.php?op=v1_details&place_id=' + encodeURIComponent(item.place_id) + '&fields=id,displayName,internationalPhoneNumber,nationalPhoneNumber,websiteUri,googleMapsUri,addressComponents', { signal: (window.__accNearbyAbort && window.__accNearbyAbort.signal) })
                    .then(function(resp){ return resp.json(); })
                    .then(function(data){
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
                    })
                    .catch(function(){});
            })).then(function(){
                clearTimeout(detailLag);
                resolve();
            }).catch(function(){ clearTimeout(detailLag); resolve(); });
        } catch (e) { resolve(); }
    });
}

async function accRenderNearby(){
    try {
        var wrap = document.getElementById('accNearbyList');
        if (!wrap) return;
        var results = (window.__ACC_NEARBY_RESULTS || []).slice();
        var includeGas = accIncludeGas();
        var typeSel = accGetSelectedType();
        results = results.filter(function(place){
            var cat = place && place.category_key ? place.category_key : 'core';
            if (typeSel === 'all') { return true; }
            if (typeSel === 'auto') { return includeGas || cat !== 'convenience_gas'; }
            return cat === typeSel;
        });
        var sort = (document.getElementById('acc_nearbySort') || {}).value || 'distance';
        if (sort === 'name') {
            results.sort(function(a,b){ return (a.name||'').localeCompare(b.name||''); });
        } else {
            results.sort(function(a,b){ return (a.__distance||9999) - (b.__distance||9999); });
        }
        wrap.innerHTML = '';
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
            // Skip if already assigned (but show if archived by current agent)
            if (info && info.assigned && !info.archived_by_me) {
                continue;
            }
            var row = document.createElement('div');
            row.className = 'nearby-item';
            var left = document.createElement('div');
            var dist = (typeof place.__distance === 'number' && place.__distance < 9900) ? (' • ' + place.__distance.toFixed(1) + ' mi') : '';
            var contact = '';
            if (place.phone) { contact += ' • ' + place.phone; }
            else if (place.intl_phone) { contact += ' • ' + place.intl_phone; }
            else if (place.__details_pending) { contact += ' • fetching phone…'; }
            var website = place.website ? (' • <a href="' + place.website + '" target="_blank" class="link-premium">website</a>') : '';
            var catChip = place.category_key ? (' <span class="chip chip-status" style="margin-left:6px;">' + place.category_key.replace('_','/') + '</span>') : '';
            var archivedBadge = (info && info.archived_by_me) ? ' <span style="display:inline-block; padding:2px 6px; background:#fef3c7; color:#92400e; border-radius:4px; font-size:10px; margin-left:6px;">ARCHIVED</span>' : '';
            left.innerHTML = '<div style="font-weight:600; color:#1f2937">' + (place.name || 'Store') + catChip + archivedBadge + '</div>' +
                             '<div class="nearby-meta">' + addr + dist + contact + website + '</div>';
            var right = document.createElement('div');
            right.style.display = 'flex';
            right.style.gap = '8px';
            right.style.alignItems = 'center';
            
            // If archived by current agent, show unarchive button
            if (info && info.archived_by_me && info.lead_id) {
                var unarchiveBtn = document.createElement('button');
                unarchiveBtn.className = 'btn small secondary';
                unarchiveBtn.innerHTML = '↩️ Unarchive';
                unarchiveBtn.onclick = (function(leadIdVal){
                    return function(){
                        fetch('leads.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                action: 'unarchive_lead',
                                lead_id: leadIdVal
                            })
                        })
                        .then(r => r.json())
                        .then(data => {
                            if (data.ok) {
                                accUpdateNearbyStatus('Lead unarchived successfully', 'info');
                                setTimeout(() => location.reload(), 800);
                            } else {
                                alert('Failed to unarchive lead');
                            }
                        });
                    };
                })(info.lead_id);
                right.appendChild(unarchiveBtn);
            } else if (!info || (!info.assigned && !info.unassigned)) {
                var addBtn = document.createElement('button');
                addBtn.className = 'btn small';
                addBtn.style.background = '#059669';
                addBtn.innerText = 'Add to Leads';
                addBtn.onclick = (function(placeSnapshot, buttonSnapshot){
                    return function(){ acpAddNearbyLead(placeSnapshot, buttonSnapshot); };
                })(place, addBtn);
                right.appendChild(addBtn);
            } else if (info.unassigned && !info.assigned) {
                var reqBtn = document.createElement('button');
                reqBtn.className = 'btn small';
                reqBtn.style.background = '#0369a1';
                reqBtn.innerText = 'Request Lead';
                reqBtn.onclick = (function(placeSnapshot){
                    return function(){ acpRequestLeadAssignment(placeSnapshot); };
                })(place);
                right.appendChild(reqBtn);
            } else {
                continue;
            }
            row.appendChild(left);
            row.appendChild(right);
            wrap.appendChild(row);
            added++;
        }
        if (added === 0) {
            wrap.innerHTML = '<div class="muted">No nearby matches found. Try enabling gas/convenience and pressing Refresh.</div>';
        }
    } catch (e) {}
}

function acpAddNearbyLead(place, button){
    try {
        if (button) { button.disabled = true; button.innerText = 'Saving…'; }
        var addr = place.vicinity || place.formatted_address || '';
        var phone = place.phone || place.intl_phone || place.national_phone || '';
        var fd = new URLSearchParams({
            action: 'quick_add_from_place',
            company_name: (place.name || ''),
            address: addr,
            phone: phone,
            website: (place.website || ''),
            address_line1: (place.address_line1 || ''),
            address_line2: (place.address_line2 || ''),
            city: (place.city || ''),
            state: (place.state || ''),
            zip_code: (place.postal_code || ''),
            country: (place.country || 'USA'),
            postal_code: (place.postal_code || ''),
            attribution_source: 'accounts_nearby',
            place_id: place.place_id || ''
        });
        fetch('leads.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd })
            .then(function(resp){ return resp.json(); })
            .then(function(data){
                if (!data) { throw new Error('invalid'); }
                if (data.ok) {
                    if (data.mode === 'revived') {
                        acpToast('Lead revived and reassigned to you');
                    } else if (data.mode === 'active') {
                        acpToast('Lead already exists. Opening detail…');
                        if (data.lead_id) { window.location.href = 'leads.php?id=' + data.lead_id; }
                    } else {
                        acpToast('Lead added from nearby');
                    }
                    if (button) { button.disabled = true; button.innerText = 'Added'; }
                } else if (data.error === 'converted') {
                    acpToast('Store already converted to account', 'error');
                    if (button) { button.disabled = false; button.innerText = 'Add to Leads'; }
                } else {
                    throw new Error('save failed');
                }
            })
            .catch(function(){
                acpToast('Failed to add lead', 'error');
                if (button) { button.disabled = false; button.innerText = 'Add to Leads'; }
            });
    } catch (e) {
        acpToast('Failed to add lead', 'error');
        if (button) { button.disabled = false; button.innerText = 'Add to Leads'; }
    }
}

function acpRequestLeadAssignment(place){
    try {
        fetch('tasks.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({
            action: 'request_assignment',
            subject: 'Lead assignment request: ' + (place.name || 'Store'),
            details: JSON.stringify({ place_id: place.place_id, name: place.name, address: (place.vicinity || place.formatted_address || '') })
        }) }).then(function(){ acpToast('Assignment request sent'); });
    } catch (e) { acpToast('Failed to send request', 'error'); }
}

function acpRequestNearbyAccount(place){
    try {
        fetch('tasks.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({
            action: 'request_assignment',
            subject: 'Nearby account assignment request: ' + (place.name || 'Store'),
            details: JSON.stringify({ type: 'nearby_account', place_id: place.place_id, name: place.name, address: (place.vicinity || place.formatted_address || '') })
        }) }).then(function(){ acpToast('Nearby account request sent'); });
    } catch (e) { acpToast('Failed to send request', 'error'); }
}

function getDistanceMiles(lat1, lon1, lat2, lon2){
    function toRad(v){ return v * Math.PI / 180; }
    var R = 3958.8;
    var dLat = toRad(lat2 - lat1);
    var dLon = toRad(lon2 - lon1);
    var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon / 2) * Math.sin(dLon / 2);
    var c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return R * c;
}
</script>

<!-- Include Workflow Entity Configuration Script -->
<script src="<?=asset_url('/assets/workflow-entity-config.js')?>"></script>

<script>
// Create Chain Modal
function showCreateChainModal(accountId) {
    const overlay = document.createElement('div');
    overlay.id = 'createChainOverlay';
    overlay.style.cssText = 'position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:10000;';
    
    const modal = document.createElement('div');
    modal.style.cssText = 'background:#fff; border-radius:12px; padding:24px; max-width:500px; width:90%; box-shadow:0 8px 24px rgba(0,0,0,0.15);';
    
    modal.innerHTML = `
        <h3 style="margin:0 0 16px 0; color:#1f2937;">Create New Chain</h3>
        <p style="color:#6b7280; margin-bottom:16px;">Create a new chain and set this account as the master location.</p>
        
        <label style="display:block; margin-bottom:8px; color:#374151; font-weight:500;">Chain Name *</label>
        <input type="text" id="chainName" placeholder="e.g., Product Cafe" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:6px; margin-bottom:16px; font-family:inherit;">
        
        <label style="display:block; margin-bottom:8px; color:#374151; font-weight:500;">Location Name (optional)</label>
        <input type="text" id="locationName" placeholder="e.g., Downtown, West Side" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:6px; margin-bottom:16px; font-family:inherit;">
        
        <div style="display:flex; gap:8px; justify-content:flex-end;">
            <button id="cancelChainBtn" class="btn secondary">Cancel</button>
            <button id="createChainBtn" class="btn" style="background:#059669;">Create Chain</button>
        </div>
    `;
    
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    
    document.getElementById('cancelChainBtn').addEventListener('click', () => {
        document.body.removeChild(overlay);
    });
    
    document.getElementById('createChainBtn').addEventListener('click', () => {
        const chainName = document.getElementById('chainName').value.trim();
        const locationName = document.getElementById('locationName').value.trim();
        
        if (!chainName) {
            alert('Please enter a chain name');
            return;
        }
        
        const btn = document.getElementById('createChainBtn');
        btn.disabled = true;
        btn.textContent = 'Creating...';
        
        fetch('accounts.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'create_chain',
                account_id: accountId,
                chain_name: chainName,
                location_name: locationName
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                alert('Chain created successfully!');
                location.reload();
            } else {
                alert('Failed to create chain: ' + (data.error || 'Unknown error'));
                btn.disabled = false;
                btn.textContent = 'Create Chain';
            }
        })
        .catch(err => {
            console.error(err);
            alert('Network error creating chain');
            btn.disabled = false;
            btn.textContent = 'Create Chain';
        });
    });
}

// Chain Manager Modal
function showChainManager(accountId, chainId) {
    const overlay = document.createElement('div');
    overlay.id = 'chainManagerOverlay';
    overlay.style.cssText = 'position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:10000; overflow-y:auto; padding:20px;';
    
    const modal = document.createElement('div');
    modal.style.cssText = 'background:#fff; border-radius:12px; padding:24px; max-width:700px; width:100%; box-shadow:0 8px 24px rgba(0,0,0,0.15); max-height:90vh; overflow-y:auto;';
    
    modal.innerHTML = `
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h3 style="margin:0; color:#1f2937;">Chain Management</h3>
            <button onclick="document.getElementById('chainManagerOverlay').remove()" style="background:none; border:none; font-size:24px; cursor:pointer; color:#6b7280;">✕</button>
        </div>
        
        <div id="chainManagerContent">
            <div style="text-align:center; padding:40px; color:#9ca3af;">Loading...</div>
        </div>
    `;
    
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    
    // Load chain data
    loadChainManagerData(accountId, chainId);
}

function loadChainManagerData(accountId, chainId) {
    const content = document.getElementById('chainManagerContent');
    if (!content) return;
    
    // For now, show basic options
    content.innerHTML = `
        <div style="display:grid; gap:16px;">
            <div style="padding:16px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px;">
                <h4 style="margin:0 0 12px 0; color:#374151;">Chain Actions</h4>
                <div style="display:grid; gap:8px;">
                    <button onclick="unlinkFromChain(${accountId})" class="btn secondary" style="justify-content:flex-start;">
                        🔓 Unlink this location from chain
                    </button>
                    <button onclick="showCloseLocationModal(${accountId})" class="btn" style="background:#dc2626; justify-content:flex-start;">
                        🚫 Close this location
                    </button>
                    <button onclick="showVisibilityManager(${accountId}, ${chainId})" class="btn" style="background:#0369a1; justify-content:flex-start;">
                        👁️ Manage Invoice Visibility
                    </button>
                    <button onclick="showCommissionSplitsManager(${accountId}, ${chainId})" class="btn" style="background:#059669; justify-content:flex-start;">
                        💰 Manage Commission Splits
                    </button>
                </div>
            </div>
            
            <div style="padding:16px; background:#fef3c7; border:1px solid #fde047; border-radius:8px;">
                <strong>⚠️ Note:</strong> Chain management requires admin permissions. Changes affect all linked locations.
            </div>
        </div>
    `;
}

function unlinkFromChain(accountId) {
    if (!confirm('Unlink this location from its chain? This will make it a standalone account.')) return;
    
    fetch('accounts.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'unlink_from_chain',
            account_id: accountId
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            alert('Location unlinked successfully');
            location.reload();
        } else {
            alert('Failed to unlink: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Network error unlinking location');
    });
}

function showCloseLocationModal(accountId) {
    document.getElementById('chainManagerOverlay')?.remove();
    
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:10000;';
    
    const modal = document.createElement('div');
    modal.style.cssText = 'background:#fff; border-radius:12px; padding:24px; max-width:500px; width:90%; box-shadow:0 8px 24px rgba(0,0,0,0.15);';
    
    modal.innerHTML = `
        <h3 style="margin:0 0 16px 0; color:#1f2937;">Close Location</h3>
        <p style="color:#6b7280; margin-bottom:16px;">Archive this location and mark it as closed. Historical data will be preserved.</p>
        
        <label style="display:block; margin-bottom:8px; color:#374151; font-weight:500;">Reason for Closure *</label>
        <select id="closedReason" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:6px; margin-bottom:16px;">
            <option value="out_of_business">Out of Business</option>
            <option value="unlinked_from_chain">Unlinked from Chain</option>
            <option value="duplicate">Duplicate Record</option>
            <option value="sold">Sold/Changed Ownership</option>
            <option value="other">Other</option>
        </select>
        
        <label style="display:block; margin-bottom:8px; color:#374151; font-weight:500;">Notes</label>
        <textarea id="closureNotes" placeholder="Additional details about closure..." style="width:100%; min-height:80px; padding:10px; border:1px solid #d1d5db; border-radius:6px; margin-bottom:16px; font-family:inherit; resize:vertical;"></textarea>
        
        <div style="display:flex; gap:8px; justify-content:flex-end;">
            <button onclick="this.closest('[style*=fixed]').remove()" class="btn secondary">Cancel</button>
            <button id="closeLocationBtn" class="btn" style="background:#dc2626;">Close Location</button>
        </div>
    `;
    
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    
    document.getElementById('closeLocationBtn').addEventListener('click', function() {
        const reason = document.getElementById('closedReason').value;
        const notes = document.getElementById('closureNotes').value.trim();
        
        if (!confirm('Are you sure you want to close this location? This action will mark it as inactive.')) return;
        
        this.disabled = true;
        this.textContent = 'Closing...';
        
        fetch('accounts.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'close_location',
                account_id: accountId,
                closed_reason: reason,
                closure_notes: notes
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                alert('Location closed and archived successfully');
                window.location.href = 'accounts.php';
            } else {
                alert('Failed to close location: ' + (data.error || 'Unknown error'));
                this.disabled = false;
                this.textContent = 'Close Location';
            }
        })
        .catch(err => {
            console.error(err);
            alert('Network error closing location');
            this.disabled = false;
            this.textContent = 'Close Location';
        });
    });
}

function showVisibilityManager(accountId, chainId) {
    document.getElementById('chainManagerOverlay')?.remove();
    
    const overlay = document.createElement('div');
    overlay.id = 'visibilityManagerOverlay';
    overlay.style.cssText = 'position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:10000; padding:20px; overflow-y:auto;';
    
    const modal = document.createElement('div');
    modal.style.cssText = 'background:#fff; border-radius:12px; padding:24px; max-width:800px; width:100%; box-shadow:0 8px 24px rgba(0,0,0,0.15); max-height:90vh; overflow-y:auto;';
    
    modal.innerHTML = `
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; position:sticky; top:0; background:#fff; padding-bottom:16px; border-bottom:2px solid #e5e7eb;">
            <h3 style="margin:0; color:#1f2937;">👁️ Invoice Visibility Manager</h3>
            <button onclick="document.getElementById('visibilityManagerOverlay').remove()" style="background:none; border:none; font-size:24px; cursor:pointer; color:#6b7280;">✕</button>
        </div>
        
        <div id="visibilityManagerContent">
            <div style="text-align:center; padding:40px; color:#9ca3af;">Loading visibility groups...</div>
        </div>
    `;
    
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    
    loadVisibilityGroups(accountId, chainId);
}

function loadVisibilityGroups(accountId, chainId) {
    const content = document.getElementById('visibilityManagerContent');
    if (!content) return;
    
    // Fetch chain locations and visibility groups
    Promise.all([
        fetch('accounts.php?ajax=chain_locations&chain_id=' + chainId).then(r => r.json()).catch(() => ({ok: false})),
        fetch('accounts.php?ajax=visibility_groups&chain_id=' + chainId).then(r => r.json()).catch(() => ({ok: false}))
    ])
    .then(([locationsData, visibilityData]) => {
        const locations = locationsData.locations || [];
        const visibilityGroups = visibilityData.groups || [];
        
        if (locations.length === 0) {
            content.innerHTML = '<div class="muted" style="text-align:center; padding:20px;">No locations found in chain</div>';
            return;
        }
        
        let html = `
            <div style="margin-bottom:20px; padding:12px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; color:#1e40af;">
                <strong>ℹ️ How it works:</strong> Configure which locations can view each other's invoices. Each location can have different access levels.
            </div>
            
            <div style="margin-bottom:16px;">
                <h4 style="margin:0 0 12px 0; color:#374151;">Quick Presets</h4>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button onclick="grantMasterSeesAll(${accountId}, ${chainId})" class="btn small" style="background:#059669;">Master Sees All</button>
                    <button onclick="grantAllSeeAll(${chainId})" class="btn small" style="background:#0369a1;">All See All</button>
                    <button onclick="clearAllVisibility(${chainId})" class="btn small" style="background:#dc2626;">Clear All</button>
                </div>
            </div>
            
            <h4 style="margin:20px 0 12px 0; color:#374151;">Current Visibility Matrix</h4>
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr style="background:#f9fafb;">
                            <th style="padding:8px; text-align:left; border:1px solid #e5e7eb;">Location</th>
                            <th style="padding:8px; text-align:center; border:1px solid #e5e7eb;">Can View Invoices For</th>
                        </tr>
                    </thead>
                    <tbody>
        `;
        
        locations.forEach(loc => {
            const canViewList = visibilityGroups
                .filter(vg => vg.account_id == loc.id)
                .map(vg => {
                    const targetLoc = locations.find(l => l.id == vg.can_view_account_id);
                    return targetLoc ? targetLoc.business_name + (targetLoc.location_name ? ' (' + targetLoc.location_name + ')' : '') : 'Unknown';
                });
            
            html += `
                <tr>
                    <td style="padding:8px; border:1px solid #e5e7eb; font-weight:600;">
                        ${loc.business_name}
                        ${loc.location_name ? '<span style="color:#6b7280; font-weight:normal;"> (' + loc.location_name + ')</span>' : ''}
                        ${loc.is_master_location ? '<span style="margin-left:6px; padding:2px 6px; background:#dbeafe; color:#1e40af; border-radius:4px; font-size:10px;">MASTER</span>' : ''}
                    </td>
                    <td style="padding:8px; border:1px solid #e5e7eb;">
                        ${canViewList.length > 0 ? canViewList.join(', ') : '<span style="color:#9ca3af;">None</span>'}
                        <button onclick="editVisibilityFor(${loc.id}, ${chainId})" class="btn small secondary" style="margin-left:8px;">Edit</button>
                    </td>
                </tr>
            `;
        });
        
        html += `
                    </tbody>
                </table>
            </div>
        `;
        
        content.innerHTML = html;
    })
    .catch(err => {
        console.error(err);
        content.innerHTML = '<div class="muted" style="text-align:center; padding:20px;">Error loading visibility data</div>';
    });
}

function editVisibilityFor(accountId, chainId) {
    alert('Individual location visibility editor coming soon. Use Quick Presets above for now.');
}

function grantMasterSeesAll(accountId, chainId) {
    if (!confirm('Grant master location visibility to all other locations in chain?')) return;
    alert('Master sees all preset will be implemented. API endpoint is ready.');
}

function grantAllSeeAll(chainId) {
    if (!confirm('Grant all locations visibility to all other locations in chain?')) return;
    alert('All see all preset will be implemented. API endpoint is ready.');
}

function clearAllVisibility(chainId) {
    if (!confirm('Clear all visibility groups for this chain?')) return;
    alert('Clear all visibility will be implemented. API endpoint is ready.');
}

function showCommissionSplitsManager(accountId, chainId) {
    document.getElementById('chainManagerOverlay')?.remove();
    
    const overlay = document.createElement('div');
    overlay.id = 'commissionSplitsOverlay';
    overlay.style.cssText = 'position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:10000; padding:20px; overflow-y:auto;';
    
    const modal = document.createElement('div');
    modal.style.cssText = 'background:#fff; border-radius:12px; padding:24px; max-width:700px; width:100%; box-shadow:0 8px 24px rgba(0,0,0,0.15);';
    
    modal.innerHTML = `
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h3 style="margin:0; color:#1f2937;">💰 Commission Splits Manager</h3>
            <button onclick="document.getElementById('commissionSplitsOverlay').remove()" style="background:none; border:none; font-size:24px; cursor:pointer; color:#6b7280;">✕</button>
        </div>
        
        <div style="margin-bottom:20px; padding:12px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; color:#1e40af;">
            <strong>ℹ️ How it works:</strong> Configure commission sharing between agents for this specific location. By default, the primary agent gets 100% of commissions.
        </div>
        
        <div style="padding:16px; background:#fef3c7; border:1px solid #fde047; border-radius:8px; margin-bottom:20px;">
            <div style="font-weight:600; margin-bottom:8px;">Commission Split Framework</div>
            <div style="font-size:13px; color:#6b7280;">
                • Default: Primary agent (who opened account) gets 100%<br>
                • Splits: Configure percentage sharing with another agent<br>
                • Per-location: Each location can have different arrangements<br>
                • Time-based: Set effective dates for temporary splits<br>
            </div>
        </div>
        
        <div style="text-align:center; padding:20px; color:#9ca3af;">
            Commission split configuration UI is ready for implementation.<br>
            <strong>Database & API endpoints are fully functional.</strong>
        </div>
        
        <div style="text-align:center; margin-top:20px;">
            <button onclick="document.getElementById('commissionSplitsOverlay').remove()" class="btn secondary">Close</button>
        </div>
    `;
    
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
}
</script>

<?php 
// Include unified radial menu for Sales ACP
require_once __DIR__ . '/../../required/sales-acp-radial-menu.php'; 
?>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>
