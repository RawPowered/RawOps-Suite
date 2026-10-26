<?php 
$SEO = [
    'title' => 'Sales Agent CRM — Product',
    'description' => 'Manage your leads, accounts, and sales activities with the Product CRM.',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/header.php';
require_once __DIR__ . '/../../required/demo-functions.php';
?>
<!-- SACP Enhanced Dashboard CSS & JS -->
<link rel="stylesheet" href="<?php echo asset_url('/assets/sacp1015-dashboard.css'); ?>">
<script>
// Expose Google Maps API key to JavaScript
window.GOOGLE_MAPS_API_KEY = <?php 
    global $google_api_key, $gemini_api_key; 
    $apiKey = $google_api_key ?: $gemini_api_key;
    echo json_encode($apiKey ?: ''); 
?>;
</script>
<script src="<?php echo asset_url('/assets/sacp1015-dashboard.js'); ?>" defer></script>
<?php
start_session();
$uid = current_user_id();
if (!$uid) redirect(path_with_base('/index.php'));
$pdo = db();

// Admin Mode toggle (affects scoping across Sales ACP)
if (isset($_GET['toggle_admin_mode']) && current_admin_id()) {
    $_SESSION['sales_acp_admin_mode'] = $_GET['toggle_admin_mode'] === '1' ? 1 : 0;
    header('Location: index.php');
    exit;
}

// Check for admin simulation - if active, bypass training requirements
$isAdminSimulation = isset($_SESSION['admin_simulation']);
$isRealAdmin = current_admin_id() ? true : false;

// Check for active retest requirements (skip if admin simulation)
if (!$isAdminSimulation) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM training_retest_requirements WHERE user_id = ? AND is_active = 1');
    try { $stmt->execute([$uid]); $activeRetests = intval($stmt->fetchColumn()); } catch (Throwable $e) { $activeRetests = 0; }

    if ($activeRetests > 0) {
        echo '<div class="card"><h2>Training Retest Required</h2><p>You have ' . $activeRetests . ' training module' . ($activeRetests > 1 ? 's' : '') . ' that require retesting before you can access the Sales Agent Portal.</p><p><a class="btn" href="'. outbound_path('/onboarding/trainee/index.php') .'">Complete Required Retests</a></p></div>';
        require_once __DIR__ . '/../../required/footer.php';
        exit;
    }
}

// Check sales_agent role (skip role check if admin simulation)
if (!$isAdminSimulation) {
    $stmt = $pdo->prepare('SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.name = "sales_agent"');
    try { $stmt->execute([$uid]); $hasRole = (bool)$stmt->fetch(); } catch (Throwable $e) { $hasRole = false; }
    if (!$hasRole) {
        if (is_training_complete($uid)) {
            // Promote automatically if training complete but role not yet set
            $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, (SELECT id FROM roles WHERE name = "sales_agent")) ON DUPLICATE KEY UPDATE role_id = role_id')->execute([$uid]);
            header('Location: ' . path_with_base('/onboarding/sales-agent/index.php'));
            exit;
        }
        echo '<div class="card"><h2>Access Restricted</h2><p>Please complete training to access the ACP.</p><p><a class="btn" href="'. outbound_path('/onboarding/trainee/index.php') .'">Go to Training</a></p></div>';
        require_once __DIR__ . '/../../required/footer.php';
        exit;
    }
}

// Get current user info
$stmt = $pdo->prepare('SELECT first_name, last_name, email FROM users WHERE id = ?');
$stmt->execute([$uid]);
$user = $stmt->fetch();
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');

// Fetch CRM data (use try-catch for tables that might not exist yet)
$myLeads = [];
$recentCalls = [];
$upcomingTasks = [];
$pipelineStats = [];
$agentPerf = null;
$crmSetupPending = false;

try {
    // My leads (exclude converted and archived by current agent) with sorting
    $leadSort = $_GET['lead_sort'] ?? 'newest';
    $allowedSorts = ['newest','updated','calls','priority'];
    if (!in_array($leadSort, $allowedSorts, true)) { $leadSort = 'newest'; }
    $orderSql = 'ORDER BY l.created_at DESC';
    if ($leadSort === 'updated') {
        $orderSql = 'ORDER BY COALESCE(l.updated_at, l.created_at) DESC';
    } elseif ($leadSort === 'calls') {
        // Sort by computed alias, then fallback to created_at
        $orderSql = 'ORDER BY call_count DESC, l.created_at DESC';
    } elseif ($leadSort === 'priority') {
        // Urgent → High → Medium → Low
        $orderSql = "ORDER BY FIELD(l.lead_priority, 'urgent','high','medium','low'), l.created_at DESC";
    }
    $sql = '
        SELECT l.*, CONCAT(l.first_name, " ", l.last_name) as full_name,
               DATEDIFF(NOW(), l.created_at) as days_old,
               (SELECT COUNT(*) FROM crm_call_logs cl WHERE cl.lead_id = l.id) as call_count,
               (SELECT GROUP_CONCAT(CONCAT(lt.tag_icon, " ", lt.tag_name) SEPARATOR ", ") 
                FROM crm_lead_tag_assignments lta 
                JOIN crm_lead_tags lt ON lta.tag_id = lt.id 
                WHERE lta.lead_id = l.id LIMIT 3) as tags_display
        FROM crm_leads l 
        LEFT JOIN crm_lead_agent_archives aa ON aa.lead_id = l.id AND aa.agent_id = ?
        WHERE l.assigned_agent_id = ? AND l.converted_to_account_id IS NULL AND aa.id IS NULL
        ' . $orderSql . ' 
        LIMIT 10';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$uid, $uid]);
    $myLeads = $stmt->fetchAll();

    // My accounts summary for dashboard (with address and 30-day stats)
    $myAccounts = [];
    $st = $pdo->prepare('SELECT 
            wa.id, wa.business_name, wa.approved, wa.phone,
            wa.address_line1, wa.city, wa.state_region, wa.postal_code,
            u.first_name, u.last_name, u.email,
            (
                SELECT COUNT(*) FROM orders o
                WHERE o.wholesale_account_id = wa.id
                  AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  AND (o.status IS NULL OR o.status NOT IN ("cancelled"))
            ) AS inv_30d,
            (
                SELECT COALESCE(SUM(o.total),0) FROM orders o
                WHERE o.wholesale_account_id = wa.id
                  AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  AND (o.status IS NULL OR o.status NOT IN ("cancelled"))
            ) AS sales_30d,
            (
                SELECT COALESCE(SUM(c.amount),0) FROM commissions c
                JOIN orders o2 ON o2.id = c.order_id
                WHERE o2.wholesale_account_id = wa.id
                  AND c.commission_type = "sales_agent"
                  AND (wa.sales_agent_id IS NULL OR c.user_id = wa.sales_agent_id)
                  AND o2.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ) AS comm_30d,
            (
                SELECT COUNT(*) FROM crm_tasks t
                WHERE t.account_id = wa.id AND t.status IN ("pending","in_progress")
                  AND t.due_date <= DATE_ADD(NOW(), INTERVAL 1 DAY)
            ) AS tasks_due_soon
        FROM wholesale_accounts wa
        JOIN users u ON u.id = wa.user_id
        WHERE wa.sales_agent_id = ?
        ORDER BY wa.updated_at DESC, wa.created_at DESC
        LIMIT 10');
    $st->execute([$uid]);
    $myAccounts = $st->fetchAll();

    // Recent activity: calls + notes + completed tasks + demos (latest first)
    $recentActivity = [];
    // Calls
    $stmt = $pdo->prepare('SELECT "call" AS type, cl.call_date AS created_at, cl.call_outcome AS status, cl.contact_type, cl.duration_minutes, cl.summary, l.company_name, wa.business_name AS account_name, l.first_name, l.last_name FROM crm_call_logs cl LEFT JOIN crm_leads l ON l.id = cl.lead_id LEFT JOIN wholesale_accounts wa ON wa.id = cl.account_id WHERE cl.agent_id = ? ORDER BY cl.call_date DESC LIMIT 8');
    $stmt->execute([$uid]);
    $recentActivity = array_merge($recentActivity, $stmt->fetchAll());
    // Notes
    try {
        $stn = $pdo->prepare('SELECT "note" AS type, n.created_at, NULL AS status, NULL AS contact_type, NULL AS duration_minutes, n.content AS summary, l.company_name, wa.business_name AS account_name, l.first_name, l.last_name FROM crm_notes n LEFT JOIN crm_leads l ON l.id = n.lead_id LEFT JOIN wholesale_accounts wa ON wa.id = n.account_id WHERE n.agent_id = ? ORDER BY n.created_at DESC LIMIT 8');
        $stn->execute([$uid]);
        $recentActivity = array_merge($recentActivity, $stn->fetchAll());
    } catch (Throwable $e) {}
    // Completed tasks
    try {
        $stt = $pdo->prepare('SELECT "task" AS type, COALESCE(t.completed_at, t.updated_at, t.created_at) AS created_at, t.status, NULL AS contact_type, NULL AS duration_minutes, t.title AS summary, l.company_name, wa.business_name AS account_name, l.first_name, l.last_name FROM crm_tasks t LEFT JOIN crm_leads l ON l.id = t.lead_id LEFT JOIN wholesale_accounts wa ON wa.id = t.account_id WHERE t.agent_id = ? AND t.status = "completed" ORDER BY COALESCE(t.completed_at, t.updated_at, t.created_at) DESC LIMIT 8');
        $stt->execute([$uid]);
        $recentActivity = array_merge($recentActivity, $stt->fetchAll());
    } catch (Throwable $e) {}
    // Demos
    try {
        $std = $pdo->prepare('SELECT "demo" AS type, d.created_at, d.follow_up_status AS status, NULL AS contact_type, NULL AS duration_minutes, CONCAT("Demo: ", dt.product_name) AS summary, l.company_name, NULL AS account_name, l.first_name, l.last_name FROM crm_demo_dropoffs d JOIN demo_dropoff_types dt ON dt.id = d.demo_type_id LEFT JOIN crm_leads l ON l.id = d.lead_id WHERE d.agent_id = ? ORDER BY d.created_at DESC LIMIT 8');
        $std->execute([$uid]);
        $recentActivity = array_merge($recentActivity, $std->fetchAll());
    } catch (Throwable $e) {}
    // Sort and clip
    usort($recentActivity, function($a,$b){ return strtotime($b['created_at']) <=> strtotime($a['created_at']); });
    $recentActivity = array_slice($recentActivity, 0, 8);

    // Upcoming tasks
    $stmt = $pdo->prepare('
        SELECT t.*, l.first_name, l.last_name, l.company_name,
               wa.business_name as account_name,
               DATEDIFF(t.due_date, NOW()) as days_until_due
        FROM crm_tasks t 
        LEFT JOIN crm_leads l ON l.id = t.lead_id
        LEFT JOIN wholesale_accounts wa ON wa.id = t.account_id
        WHERE t.agent_id = ? AND t.status = "pending"
        ORDER BY t.due_date ASC 
        LIMIT 8
    ');
    $stmt->execute([$uid]);
    $upcomingTasks = $stmt->fetchAll();

    // Prefer the planning view crm_agent_performance when present for accuracy
    $hasPerfView = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW' AND Tables_in_" . DB_NAME . " = 'crm_agent_performance'")->fetch();
    if ($hasPerfView) {
        $stmt = $pdo->prepare('SELECT * FROM crm_agent_performance WHERE id = ? LIMIT 1');
        $stmt->execute([$uid]);
        $agentPerf = $stmt->fetch();
        if ($agentPerf) {
            $pipelineStats = [
                'total_leads' => intval($agentPerf['total_leads'] ?? 0),
                'new_leads' => null, // computed below for UX, not in view
                'active_leads' => null, // computed below
                'won_leads' => intval($agentPerf['won_leads'] ?? 0),
                'pipeline_value' => floatval($agentPerf['pipeline_value'] ?? 0)
            ];
        }
    }

    // Fallback or complement: compute granular buckets from crm_leads
    $stmt = $pdo->prepare('
        SELECT 
            COUNT(*) as total_leads,
            COUNT(CASE WHEN lead_status = "new" THEN 1 END) as new_leads,
            COUNT(CASE WHEN lead_status IN ("contacted", "qualified", "proposal_sent", "negotiating", "customer_review", "customer_follow_up", "info_obtained_demo_pending") THEN 1 END) as active_leads,
            COUNT(CASE WHEN lead_status IN ("won","converted") THEN 1 END) as won_leads,
            SUM(estimated_deal_value) as pipeline_value
        FROM crm_leads 
        WHERE assigned_agent_id = ?
    ');
    $stmt->execute([$uid]);
    $calc = $stmt->fetch();
    if (!$pipelineStats) { $pipelineStats = $calc ?: []; }
    else {
        // Fill missing granular buckets if view used
        $pipelineStats['new_leads'] = $calc['new_leads'] ?? 0;
        $pipelineStats['active_leads'] = $calc['active_leads'] ?? 0;
        if (!isset($agentPerf['won_leads'])) {
            $pipelineStats['won_leads'] = $calc['won_leads'] ?? 0;
        }
        if (empty($pipelineStats['pipeline_value'])) {
            $pipelineStats['pipeline_value'] = $calc['pipeline_value'] ?? 0;
        }
    }

} catch (Throwable $e) {
    // CRM tables don't exist yet - mark pending, but never show to real agents
    $crmSetupPending = true;
}

// Get demo follow-up notifications
$overdueFollowups = [];
try {
    $overdueFollowups = get_overdue_demo_followups($pdo, $uid);
} catch (Throwable $e) {
    // Demo tables might not exist yet
}

// Collect known store names for nearby search filtering (CRM + Accounts)
$knownStores = [];
try {
    $ks = $pdo->query('SELECT company_name AS name FROM crm_leads WHERE company_name IS NOT NULL AND company_name <> "" UNION SELECT business_name AS name FROM wholesale_accounts WHERE business_name IS NOT NULL AND business_name <> ""');
    $knownStores = array_map(function($r){ return strtolower(trim($r['name'])); }, $ks->fetchAll());
} catch (Throwable $e) {
    $knownStores = [];
}

?>

<div class="container">
    <?php 
    // Include the enhanced Paladin navigation
    require_once __DIR__ . '/../../required/sales-acp-nav.php';
    ?>
    
    <?php
    // Include simple breadcrumb navigation
    require_once __DIR__ . '/../../required/sales-acp-breadcrumb-simple.php';
    ?>

    <?php if (!empty($overdueFollowups)): ?>
    <!-- Demo Follow-up Notifications with Pulse -->
    <div class="sacp_card urgent-followup" style="background: linear-gradient(135deg, #fee2e2, #fecaca); border: 2px solid var(--acp-danger); margin-bottom: 24px; animation: pulse-urgent 2s infinite;">
        <div style="display: flex; align-items: center; gap: 16px;">
            <div style="font-size: 48px; animation: pulse-badge 2s infinite;">⚠️</div>
            <div style="flex: 1;">
                <h2 style="margin: 0 0 8px 0; color: #dc2626;">Demo Follow-ups Required!</h2>
                <p style="margin: 0; color: #991b1b;">
                    You have <strong><?php echo count($overdueFollowups); ?></strong> overdue or due today demo follow-up<?php echo count($overdueFollowups) > 1 ? 's' : ''; ?>.
                </p>
                <div style="margin-top: 12px;">
                    <?php foreach (array_slice($overdueFollowups, 0, 3) as $followup): ?>
                    <div style="padding: 8px 12px; background: white; border-radius: 6px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <strong><?php echo htmlspecialchars($followup['company_name'] ?: $followup['first_name'] . ' ' . $followup['last_name']); ?></strong>
                            - <?php echo htmlspecialchars($followup['product_name']); ?>
                            <div style="font-size: 12px; color: #6b7280;">
                                Dropped: <?php echo date('M j', strtotime($followup['dropoff_date'])); ?>
                                <?php if ($followup['followup_urgency'] === 'overdue'): ?>
                                    <span style="color: #dc2626; font-weight: 600;">• Overdue by <?php echo abs($followup['days_until_followup']); ?> days!</span>
                                <?php else: ?>
                                    <span style="color: #f59e0b; font-weight: 600;">• Due today!</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <a href="dropoffs.php" class="btn small" style="background: #dc2626;">Follow Up Now</a>
                    </div>
                    <?php endforeach; ?>
                    <?php if (count($overdueFollowups) > 3): ?>
                    <div style="text-align: center; margin-top: 8px;">
                        <a href="dropoffs.php" style="color: #dc2626; text-decoration: underline; font-weight: 600;">
                            View all <?php echo count($overdueFollowups); ?> pending follow-ups →
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php /* Removed CRM setup notice for all users per request */ ?>

    <!-- SACP Enhanced: Agent Performance Stats -->
    <div id="sacp1015_perf-stats-container" style="margin-bottom: 32px;">
        <div class="sacp1015_loading-spinner" style="margin:0 auto;"></div>
    </div>

    <!-- Enhanced Pipeline Overview with Paladin Theme -->
    <?php if (isset($pipelineStats['total_leads'])): ?>
    <div class="sacp_stats-grid">
        <div class="sacp_stat-card">
            <div class="sacp_stat-value"><?php echo $pipelineStats['total_leads'] ?: 0; ?></div>
            <div class="sacp_stat-label">Total Leads</div>
            <span class="stat-trend">📊</span>
        </div>
        <div class="sacp_stat-card" style="border-color: var(--acp-green); background: linear-gradient(135deg, #ffffff, #f0fdf4);">
            <div class="sacp_stat-value" style="color: var(--acp-green);"><?php echo $pipelineStats['new_leads'] ?: 0; ?></div>
            <div class="sacp_stat-label">New Leads</div>
            <span class="stat-trend" style="background: linear-gradient(135deg, #dcfce7, #bbf7d0); color: #14532d;">✨</span>
        </div>
        <div class="sacp_stat-card" style="border-color: var(--acp-warning);">
            <div class="sacp_stat-value" style="color: var(--acp-warning);"><?php echo $pipelineStats['active_leads'] ?: 0; ?></div>
            <div class="sacp_stat-label">Active Leads</div>
            <?php if (($pipelineStats['active_leads'] ?? 0) > 5): ?>
                <span class="stat-trend" style="background: linear-gradient(135deg, #fef3c7, #fde68a); color: #92400e;">🔥</span>
            <?php endif; ?>
        </div>
        <div class="sacp_stat-card" style="border-color: var(--acp-gold);">
            <div class="sacp_stat-value" style="color: var(--acp-gold-dark);"><?php echo $pipelineStats['won_leads'] ?: 0; ?></div>
            <div class="sacp_stat-label">Won Deals</div>
            <span class="stat-trend" style="background: var(--acp-gradient-gold); color: white;">🏆</span>
        </div>
        <div class="sacp_stat-card" style="border-color: var(--acp-blue);">
            <div class="sacp_stat-value" style="background: linear-gradient(135deg, var(--acp-blue-deep), var(--acp-blue)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">
                $<?php echo number_format($pipelineStats['pipeline_value'] ?: 0, 0); ?>
            </div>
            <div class="sacp_stat-label">Pipeline Value</div>
        </div>
        <?php if ($agentPerf): ?>
        <div class="sacp_stat-card" style="border-color: var(--acp-info); background: linear-gradient(135deg, #eff6ff, #dbeafe);">
            <div class="sacp_stat-value" style="color: var(--acp-info);"><?php echo intval($agentPerf['calls_today'] ?? 0); ?></div>
            <div class="sacp_stat-label">Calls Today</div>
        </div>
        <div class="sacp_stat-card" style="border-color: var(--acp-danger); background: linear-gradient(135deg, #fee2e2, #fecaca);">
            <div class="sacp_stat-value" style="color: var(--acp-danger);"><?php echo intval($agentPerf['overdue_tasks'] ?? 0); ?></div>
            <div class="sacp_stat-label">Overdue Tasks</div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- SACP Enhanced: Tabbed Interface -->
    <div class="sacp1015_tab-container">
        <!-- Mobile tab select -->
        <select class="sacp1015_tab-select">
            <option value="leads">My Leads</option>
            <option value="accounts">My Accounts</option>
            <option value="activity">Recent Activity</option>
            <option value="status-kanban">Status Kanban</option>
            <option value="kanban">Tagged Kanban</option>
            <option value="geographic">Geographic View</option>
        </select>
        
        <!-- Desktop tab navigation -->
        <div class="sacp1015_tab-nav">
            <button class="sacp1015_tab-btn active" data-tab="leads">🎯 My Leads</button>
            <button class="sacp1015_tab-btn" data-tab="accounts">🏦 My Accounts</button>
            <button class="sacp1015_tab-btn" data-tab="activity">🕓 Recent Activity</button>
            <button class="sacp1015_tab-btn" data-tab="status-kanban">📋 Status Kanban</button>
            <button class="sacp1015_tab-btn" data-tab="kanban">📊 Tagged Kanban</button>
            <button class="sacp1015_tab-btn" data-tab="geographic">🗺️ Geographic View</button>
        </div>
        
        <div class="sacp1015_tab-content">
            <!-- Tab 1: My Leads -->
            <div class="sacp1015_tab-pane active" id="sacp1015_tab-leads">
                <div class="sacp_card" style="margin-bottom: 0;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid var(--acp-platinum); gap: 12px;">
                <h3 style="margin: 0; font-size: 18px; font-weight: 700; color: var(--acp-carbon); display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 24px;">🎯</span> My Leads
                </h3>
                <div style="display:flex; align-items:center; gap:8px;">
                    <form method="get" action="index.php" style="display:flex; align-items:center; gap:8px;">
                        <label for="lead_sort" class="hide-mobile" style="font-size:12px; color:#6b7280;">Sort</label>
                        <select id="lead_sort" name="lead_sort" onchange="this.form.submit()" style="padding:6px 8px; border:1px solid #d1d5db; border-radius:6px; font-size:12px;">
                            <option value="newest"<?php echo ($leadSort==='newest'?' selected':''); ?>>Newest</option>
                            <option value="updated"<?php echo ($leadSort==='updated'?' selected':''); ?>>Last Updated</option>
                            <option value="calls"<?php echo ($leadSort==='calls'?' selected':''); ?>>Most Calls</option>
                            <option value="priority"<?php echo ($leadSort==='priority'?' selected':''); ?>>Priority</option>
                        </select>
                    </form>
                    <a href="leads.php?action=add" class="btn gold small" data-open-add-lead="1">+ Add Lead</a>
                </div>
            </div>
            
            <?php if (empty($myLeads)): ?>
                <div style="text-align: center; padding: 32px; color: #6b7280;">
                    <div style="font-size: 48px; margin-bottom: 16px;">🎯</div>
                    <p>No leads assigned yet.</p>
                    <a href="leads.php?action=add" class="btn" data-open-add-lead="1">Add Your First Lead</a>
                </div>
            <?php else: ?>
                <div style="max-height: 400px; overflow-y: auto;">
                    <?php foreach ($myLeads as $lead): 
                        $isHot = ($lead['days_old'] <= 3 && $lead['lead_status'] === 'new');
                        $needsFollowup = ($lead['days_old'] > 7 && $lead['call_count'] == 0);
                    ?>
                    <div class="sacp_lead-card<?php echo $isHot ? ' hot-lead' : ($needsFollowup ? ' needs-attention' : ''); ?>" 
                         style="padding: 14px; margin-bottom: 12px; border-radius: 12px; transition: all 250ms ease;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <div style="font-weight: 600; color: var(--acp-carbon); font-size: 15px;">
                                    <?php echo htmlspecialchars($lead['company_name'] ?: 'Store'); ?>
                                    <?php if ($isHot): ?>
                                        <span class="chip chip-danger" style="margin-left: 8px; font-size: 10px;">HOT</span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size: 14px; color: var(--acp-graphite); margin-top: 4px;">
                                    <?php 
                                    $addrLine = trim($lead['address'] ?: trim(($lead['address_line1'] ?? '') . ', ' . ($lead['city'] ?? '') . ', ' . ($lead['state'] ?? '') . ' ' . ($lead['zip_code'] ?? '')));
                                    echo $addrLine !== '' ? htmlspecialchars($addrLine) : '<span class="muted">No address</span>';
                                    ?>
                                </div>
                                <div style="font-size: 13px; color: var(--acp-silver); margin-top: 4px;">
                                    <?php echo htmlspecialchars($lead['full_name']); ?>
                                </div>
                                <div style="font-size: 12px; color: var(--acp-silver); margin-top: 6px; display: flex; gap: 12px; flex-wrap: wrap;">
                                    <span class="chip chip-<?php echo $lead['lead_status'] === 'new' ? 'info' : ($lead['lead_status'] === 'won' ? 'success' : 'status'); ?>" style="font-size: 10px;">
                                        <?php echo strtoupper($lead['lead_status']); ?>
                                    </span>
                                    <?php if (!empty($lead['tags_display'])): ?>
                                        <span style="font-size: 10px; background: #dbeafe; color: #1e40af; padding: 2px 8px; border-radius: 4px;">
                                            <?php echo htmlspecialchars($lead['tags_display']); ?>
                                        </span>
                                    <?php else: ?>
                                        <button onclick="sacpQuickTag(<?php echo $lead['id']; ?>, '<?php echo htmlspecialchars($lead['company_name'], ENT_QUOTES); ?>'); return false;" 
                                                class="chip" style="font-size: 10px; background: #fef3c7; color: #92400e; cursor: pointer; border: 1px dashed #f59e0b;">
                                            🏷️ Quick Tag
                                        </button>
                                    <?php endif; ?>
                                    <span>⏱️ <?php echo $lead['days_old']; ?> days</span>
                                    <span>📞 <?php echo $lead['call_count']; ?> calls</span>
                                </div>
                            </div>
                            <div style="display: flex; gap: 8px;">
                                <a href="leads.php?id=<?php echo $lead['id']; ?>" class="btn secondary small">View</a>
                                <?php 
                                    $tel = preg_replace('/[^\\\\d\\+]/','', (string)($lead['phone'] ?? ''));
                                    $addr = trim($lead['address'] ?: trim(($lead['address_line1'] ?? '') . ', ' . ($lead['city'] ?? '') . ', ' . ($lead['state'] ?? '') . ' ' . ($lead['zip_code'] ?? '')));
                                    $maps = $addr ? 'https://www.google.com/maps/search/?api=1&query=' . urlencode($addr) : '';
                                    $pref = 'calls.php?action=add&lead_id=' . intval($lead['id']) . '&contact_type=phone_call&direction=outbound&subject=' . urlencode('Call: ' . ($lead['company_name'] ?: 'Store')) . '&summary=' . urlencode('Called ' . ($lead['full_name'] ?: 'contact') . ' at ' . ($tel ?: 'unknown') . '.') . '&follow_up_required=1';
                                ?>
                                <a href="#" class="btn danger small" title="Call & Prefill Log" onclick="return acpOpenCallModal(<?php echo intval($lead['id']); ?>, '<?php echo htmlspecialchars($tel, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($lead['company_name'] ?: 'Store', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($lead['full_name'] ?: 'Contact', ENT_QUOTES); ?>');">Call</a>
                                <?php if ($maps): ?>
                                <a href="<?php echo htmlspecialchars($maps); ?>" target="_blank" class="btn small secondary" title="Open in Maps">Map</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="text-align: center; margin-top: 20px;">
                    <a href="leads.php" class="btn secondary">View All Leads →</a>
                </div>
            <?php endif; ?>
                </div>
            </div>
            <!-- End Tab 1: My Leads -->
            
            <!-- Tab 2: My Accounts -->
            <div class="sacp1015_tab-pane" id="sacp1015_tab-accounts">
                <div class="sacp_card" style="margin-bottom: 0;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h3 style="margin: 0;">🏦 My Accounts</h3>
                <a href="accounts.php" class="btn secondary small">View Accounts</a>
            </div>
            <?php if (empty($myAccounts)): ?>
                <div style="text-align:center; color:#6b7280; padding:24px;">No accounts yet.</div>
            <?php else: ?>
                <div style="max-height: 300px; overflow-y: auto; display: flex; flex-direction: column;">
                    <?php foreach ($myAccounts as $a): ?>
                    <div style="display:flex; justify-content:space-between; align-items:center; padding:12px; border-bottom:1px solid #e5e7eb;">
                        <div>
                            <div style="font-weight:600; color:#1f2937; display:flex; align-items:center; gap:8px;">
                                <a href="accounts.php?id=<?php echo intval($a['id']); ?>" class="link-plain"><?php echo htmlspecialchars($a['business_name']); ?></a>
                                <?php 
                                    $addrA = trim(($a['address_line1'] ?? '') . ', ' . ($a['city'] ?? '') . ', ' . ($a['state_region'] ?? '') . ' ' . ($a['postal_code'] ?? ''));
                                    $mapsA = $addrA ? 'https://www.google.com/maps/search/?api=1&query=' . urlencode($addrA) : '';
                                    $telA = preg_replace('/[^\\\\d\\+]/','', (string)($a['phone'] ?? ''));
                                ?>
                                <?php if ($mapsA): ?><a href="<?php echo htmlspecialchars($mapsA); ?>" target="_blank" class="btn small secondary" title="Open in Maps">Map</a><?php endif; ?>
                            </div>
                            <?php if ($addrA): ?>
                            <div style="font-size:12px; color:#6b7280; margin-top:4px; display:flex; align-items:center; gap:6px;">
                                <span>📍</span>
                                <span><?php echo htmlspecialchars($addrA); ?></span>
                            </div>
                            <?php endif; ?>
                            <div style="font-size:12px; color:#6b7280; margin-top:4px;">
                                Contact: <?php echo htmlspecialchars($a['first_name'].' '.$a['last_name']); ?> — <?php echo htmlspecialchars($a['email']); ?>
                            </div>
                            <div style="font-size:12px; margin-top:6px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                                <span style="color:#059669; font-weight:700;">$<?php echo number_format((float)($a['sales_30d'] ?? 0),2); ?></span>
                                <span class="muted small">(<?php echo intval($a['inv_30d'] ?? 0); ?> inv / 30d)</span>
                                <span style="color:#2563eb; font-weight:700;">Comm $<?php echo number_format((float)($a['comm_30d'] ?? 0),2); ?></span>
                                <?php if (!empty($a['tasks_due_soon'])): ?>
                                <span class="muted small" style="color:#b45309;">⏰ Tasks due soon: <?php echo intval($a['tasks_due_soon']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <?php echo $a['approved'] ? '<span class="chip chip-success">Approved</span>' : '<span class="chip chip-status">Pending</span>'; ?>
                            <a href="#" class="btn small" style="background:#dc2626;" onclick="return acpOpenCallModal(null, '<?php echo htmlspecialchars($telA, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($a['business_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars(trim(($a['first_name']??'').' '.($a['last_name']??'')), ENT_QUOTES); ?>', <?php echo intval($a['id']); ?>);">Call</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
                </div>
            </div>
            <!-- End Tab 2: My Accounts -->
            
            <!-- Tab 3: Recent Activity -->
            <div class="sacp1015_tab-pane" id="sacp1015_tab-activity">
                <div class="sacp_card" style="margin-bottom: 0;">
            <h3 style="margin: 0 0 16px 0;">🕓 Recent Activity</h3>
            <?php if (empty($recentActivity)): ?>
                <div style="text-align: center; padding: 32px; color: #6b7280;">
                    <div style="font-size: 48px; margin-bottom: 16px;">🗂️</div>
                    <p>No recent activity.</p>
                    <a href="calls.php?action=add" class="btn">Add First Activity</a>
                </div>
            <?php else: ?>
                <div style="max-height: 320px; overflow-y: auto;">
                    <?php foreach ($recentActivity as $act): ?>
                    <div style="padding: 12px; border-bottom: 1px solid #e5e7eb; display:flex; gap:12px;">
                        <div style="width:24px; text-align:center;"> 
                            <?php echo $act['type'] === 'call' ? '📞' : ($act['type'] === 'note' ? '📝' : ($act['type'] === 'task' ? '✓' : '🧪')); ?>
                        </div>
                        <div style="flex:1;">
                            <div style="display:flex; justify-content:space-between;">
                                <strong style="color:#1f2937;">
                                    <?php echo htmlspecialchars($act['company_name'] ?: ($act['account_name'] ?: trim(($act['first_name'] ?? '').' '.($act['last_name'] ?? '')))); ?>
                                </strong>
                                <span style="font-size:12px; color:#6b7280;"><?php echo date('M j, g:i A', strtotime($act['created_at'])); ?></span>
                            </div>
                            <div style="font-size: 13px; color: #4b5563; margin-top:2px;">
                                <?php 
                                $meta = [];
                                if (!empty($act['contact_type'])) $meta[] = ucwords(str_replace('_',' ',$act['contact_type']));
                                if (!empty($act['status'])) $meta[] = ucwords(str_replace('_',' ', $act['status']));
                                if (!empty($act['duration_minutes'])) $meta[] = intval($act['duration_minutes']).' min';
                                echo $meta ? implode(' • ', $meta) : '';
                                ?>
                            </div>
                            <?php if (!empty($act['summary'])): ?>
                            <div style="font-size: 13px; color: #4b5563; margin-top:4px;">
                                <?php echo htmlspecialchars(substr($act['summary'], 0, 140) . (strlen($act['summary']) > 140 ? '...' : '')); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="text-align: center; margin-top: 16px; display:flex; gap:8px; justify-content:center; flex-wrap:wrap;">
                    <a href="calls.php" class="btn secondary">All Calls</a>
                    <a href="notes.php" class="btn secondary">All Notes</a>
                    <a href="tasks.php" class="btn secondary">All Tasks</a>
                    <a href="dropoffs.php" class="btn secondary">All Demos</a>
                </div>
            <?php endif; ?>
                </div>
            </div>
            <!-- End Tab 3: Recent Activity -->
            
            <!-- Tab 4: Status Kanban View -->
            <div class="sacp1015_tab-pane" id="sacp1015_tab-status-kanban" data-loaded="false">
                <div class="sacp1015_loading-spinner" style="margin:48px auto;"></div>
                <p style="text-align:center;color:#6b7280;">Loading Status Kanban view...</p>
            </div>
            
            <!-- Tab 5: Tagged Kanban View -->
            <div class="sacp1015_tab-pane" id="sacp1015_tab-kanban" data-loaded="false">
                <div class="sacp1015_loading-spinner" style="margin:48px auto;"></div>
                <p style="text-align:center;color:#6b7280;">Loading Tagged Kanban view...</p>
            </div>
            
            <!-- Tab 6: Geographic View -->
            <div class="sacp1015_tab-pane" id="sacp1015_tab-geographic" data-loaded="false">
                <div class="sacp1015_loading-spinner" style="margin:48px auto;"></div>
                <p style="text-align:center;color:#6b7280;">Loading geographic view...</p>
            </div>
        </div>
    </div>
    <!-- End Tabbed Interface -->

    <!-- Tasks & Follow-ups -->
    <?php if (!empty($upcomingTasks)): ?>
    <div class="sacp_card" style="margin-top: 24px;">
        <h3 style="margin: 0 0 16px 0;">✓ Upcoming Tasks & Follow-ups</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px;">
            <?php foreach ($upcomingTasks as $task): ?>
            <div style="padding: 16px; background: #f9fafb; border-radius: 8px; border-left: 4px solid <?php echo $task['priority'] === 'urgent' ? '#dc2626' : ($task['priority'] === 'high' ? '#f59e0b' : '#6b7280'); ?>;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                    <span style="font-weight: 600; color: #1f2937;"><?php echo htmlspecialchars($task['title']); ?></span>
                    <span style="font-size: 12px; color: <?php echo $task['days_until_due'] < 0 ? '#dc2626' : ($task['days_until_due'] <= 1 ? '#f59e0b' : '#6b7280'); ?>;">
                        <?php 
                        if ($task['days_until_due'] < 0) {
                            echo abs($task['days_until_due']) . ' days overdue';
                        } elseif ($task['days_until_due'] == 0) {
                            echo 'Due today';
                        } elseif ($task['days_until_due'] == 1) {
                            echo 'Due tomorrow';
                        } else {
                            echo $task['days_until_due'] . ' days';
                        }
                        ?>
                    </span>
                </div>
                <div style="font-size: 14px; color: #6b7280; margin-bottom: 8px;">
                    <?php if ($task['first_name']): ?>
                        <?php echo htmlspecialchars($task['first_name'] . ' ' . $task['last_name']); ?>
                        <?php if ($task['company_name']): ?>
                            (<?php echo htmlspecialchars($task['company_name']); ?>)
                        <?php endif; ?>
                    <?php elseif ($task['account_name']): ?>
                        <?php echo htmlspecialchars($task['account_name']); ?>
                    <?php endif; ?>
                </div>
                <div style="display: flex; gap: 8px;">
                    <a href="tasks.php?id=<?php echo $task['id']; ?>" class="btn secondary small">View</a>
                    <form method="post" action="tasks.php" style="display: inline;">
                        <input type="hidden" name="action" value="complete">
                        <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                        <button type="submit" class="btn small" style="background: #059669;">Complete</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="text-align: center; margin-top: 16px;">
            <a href="tasks.php" class="btn secondary">View All Tasks →</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Leads Near Address (Custom Search) -->
    <div class="sacp_card sacp1015_collapsible" id="sacp1015_nearby-section" style="margin-top: 24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h3 style="margin: 0; color: #1f2937; display: flex; align-items: center; gap: 8px;">
                <span>📍</span> Find Leads Near Any Address
            </h3>
            <span class="sacp1015_collapsible-toggle">
                <span class="sacp1015_collapsible-icon">▼</span> Toggle
            </span>
        </div>
        <div class="sacp1015_collapsible-content">
        <div style="background: #f0f9ff; padding: 16px; border-radius: 8px; border: 1px solid #0369a1; margin-bottom: 16px;">
            <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 12px; flex-wrap: wrap;">
                <input id="dashboard_search_address" type="text" placeholder="Enter any address to search nearby leads..." style="flex: 1; min-width: 250px; padding: 10px; border: 1px solid #93c5fd; border-radius: 8px; font-size: 14px;">
                <button type="button" class="btn" style="background: #0369a1;" onclick="dashboardSearchNearby()">
                    🔍 Search
                </button>
                <button type="button" class="btn secondary" onclick="dashboardClearSearch()">
                    Clear
                </button>
            </div>
            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; color: #4b5563;">
                    <input type="checkbox" id="dashboard_toggle_gas" onchange="if(window.__dashboard_search_active) dashboardSearchNearby()"> Include gas/convenience
                </label>
                <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; color: #4b5563;">
                    Radius:
                    <select id="dashboard_nearby_radius" class="input small" onchange="if(window.__dashboard_search_active) dashboardSearchNearby()" style="padding: 4px 8px; border: 1px solid #d1d5db; border-radius: 4px;">
                        <option value="10" selected>10 miles</option>
                        <option value="20">20 miles</option>
                        <option value="30">30 miles</option>
                    </select>
                </label>
                <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; color: #4b5563;">
                    Sort:
                    <select id="dashboard_nearby_sort" class="input small" onchange="dashboardRenderNearby()" style="padding: 4px 8px; border: 1px solid #d1d5db; border-radius: 4px;">
                        <option value="distance" selected>Distance</option>
                        <option value="name">Name</option>
                    </select>
                </label>
                <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; color: #4b5563;">
                    Type:
                    <select id="dashboard_nearby_type" class="input small" onchange="if(window.__dashboard_search_active) dashboardSearchNearby()" style="padding: 4px 8px; border: 1px solid #d1d5db; border-radius: 4px;">
                        <option value="auto" selected>Auto (Product-first)</option>
                        <option value="product_kava">Product/partner</option>
                        <option value="smoke_vape">Smoke/Vape</option>
                        <option value="herbal">Herbal</option>
                        <option value="convenience_gas">Gas/Convenience</option>
                        <option value="all">All</option>
                    </select>
                </label>
            </div>
        </div>
        
        <div id="dashboard_nearby_status" style="font-size: 13px; color: #6b7280; margin-bottom: 12px; padding: 8px; background: #f9fafb; border-radius: 6px;">
            Enter an address above to search for nearby leads
        </div>
        
        <div id="dashboard_nearby_results" style="max-height: 400px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; background: #fff; display: none;">
            <div id="dashboard_nearby_list" style="display: grid; gap: 12px;"></div>
        </div>
        
        <div style="font-size: 12px; color: #9ca3af; margin-top: 8px; text-align: center;">
            💡 Tip: Search any address to discover new wholesale opportunities in that area
        </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <!-- Quick Actions Desktop - Replaced by Unified Radial Menu -->

</div>

<style>
/* Dashboard Nearby Search Styles */
#dashboard_search_address {
    transition: all 0.2s ease;
}
#dashboard_search_address:focus {
    border-color: #0369a1 !important;
    box-shadow: 0 0 0 3px rgba(3, 105, 161, 0.1);
    outline: none;
}
.nearby-item {
    transition: all 0.2s ease;
}
.nearby-item:hover {
    transform: translateY(-2px);
}
#dashboard_nearby_status {
    animation: fadeIn 0.3s ease;
}
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
/* Autocomplete dropdown styling */
#dashboard_address_list > div {
    transition: background-color 0.15s ease;
}
/* Loading pulse animation */
@keyframes pulse-load {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.6; }
}
.loading-pulse {
    animation: pulse-load 1.5s ease-in-out infinite;
}
</style>

<!-- Mobile Quick Actions Bar - Replaced by Unified Radial Menu -->

<!-- Inline Call Modal for Dashboard -->
<div id="acpCallModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,.5); z-index:7000;">
    <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:#fff; border-radius:12px; width:520px; max-width:92vw; max-height:90vh; overflow:auto;">
        <div style="padding:16px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0">Log Call</h3>
            <button class="btn secondary" onclick="document.getElementById('acpCallModal').style.display='none'">Close</button>
        </div>
        <div id="acpCallContent" style="padding:16px;">
            <!-- fetched form will render here -->
        </div>
    </div>
    <script>
    // Dashboard inline call modal
    async function acpOpenCallModal(leadId, tel, storeName, contactName, accountId){
        try {
            const url = `calls.php?action=add${leadId?('&lead_id='+encodeURIComponent(leadId)) : ''}${accountId?('&account_id='+encodeURIComponent(accountId)) : ''}&modal=1&contact_type=phone_call&direction=outbound&subject=${encodeURIComponent('Call: ' + (storeName||'Store'))}&summary=${encodeURIComponent('Called ' + (contactName||'contact') + (tel?(' at ' + tel):''))}`;
            const r = await fetch(url, { headers:{'X-Requested-With':'fetch'} });
            const html = await r.text();
            const wrap = document.getElementById('acpCallContent');
            wrap.innerHTML = html;
            // Inject dial + timer controls at top of the modal
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

            // Hook the form to write duration when submitted
            const form = wrap.querySelector('form[data-ajax]');
            if (form){
                const durInput = form.querySelector('input[name="duration_minutes"]');
                if (!durInput){
                    const di = document.createElement('input'); di.type='hidden'; di.name='duration_minutes'; form.appendChild(di);
                }
                form.addEventListener('submit', function(){
                    const secs = window.__acpTimerSecs || 0; const mins = Math.max(0, Math.round(secs/60));
                    (form.querySelector('input[name="duration_minutes"]')||{}).value = String(mins);
                });
            }
            document.getElementById('acpCallModal').style.display='block';
        } catch(e){ alert('Unable to open call dialog.'); }
        return false;
    }

    // Simple call timer
    let __acpTimer, __acpTimerStart, __acpTimerTick;
    window.__acpTimerSecs = 0;
    function renderAcpTimer(){
        const el = document.getElementById('acpCallTimer');
        if (!el) return; const s = window.__acpTimerSecs|0; const m = Math.floor(s/60); const r = s%60;
        el.textContent = String(m).padStart(2,'0') + ':' + String(r).padStart(2,'0');
    }
    function startAcpTimer(){
        if (__acpTimer) return;
        __acpTimerStart = Date.now();
        __acpTimerTick = function(){ window.__acpTimerSecs = Math.round((Date.now() - __acpTimerStart)/1000); renderAcpTimer(); };
        __acpTimer = setInterval(__acpTimerTick, 1000); __acpTimerTick();
        try { document.getElementById('acpStartBtn').disabled = true; document.getElementById('acpStopBtn').disabled = false; } catch(e) {}
    }
    function stopAcpTimer(){
        if (__acpTimer){ clearInterval(__acpTimer); __acpTimer = null; }
        renderAcpTimer();
        // Populate visible duration field in minutes when timer stops
        try {
            var secs = window.__acpTimerSecs || 0;
            var mins = secs > 0 ? Math.max(1, Math.round(secs/60)) : 0;
            var durInputs = document.querySelectorAll('#acpCallContent input[name="duration_minutes"]');
            durInputs.forEach(function(inp){ inp.value = String(mins); });
        } catch(e) {}
        try { document.getElementById('acpStartBtn').disabled = false; document.getElementById('acpStopBtn').disabled = true; } catch(e) {}
    }
    </script>
</div>
<!-- Add Lead Modal (Dashboard) -->
<div id="acpAL_addLeadModal" style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 6000;">
    <div class="acpAL_modalWrap" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; padding: 24px; width: 500px; max-width: 90vw; max-height: 80vh; overflow-y: auto;">
        <h3 style="margin: 0 0 16px 0;">Add New Lead</h3>
        <form method="post" action="leads.php" style="display: grid; gap: 16px;">
            <input type="hidden" name="action" value="add_lead">
            <input type="hidden" name="return_detail" value="1">
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
                <input type="email" name="email" required style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
            </div>
            <div>
                <label>Phone</label>
                <input type="tel" name="phone" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
            </div>
            <div>
                <label>Company Name</label>
                <input type="text" name="company_name" id="acpAL_company_name" onblur="acpAL_detectTags()" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
            </div>
            
            <!-- Auto-Detected Tags Section -->
            <div id="acpAL_tags_section" style="display:none;background:#f0fdf4;border:1px solid #10b981;border-radius:8px;padding:16px;">
                <label style="font-weight:600;color:#065f46;margin-bottom:8px;display:block;">🏷️ Business Type Tags</label>
                <div id="acpAL_suggested_tags" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px;"></div>
                <div style="font-size:12px;color:#6b7280;">Select all tags that apply to this business</div>
                <input type="hidden" name="tag_ids" id="acpAL_selected_tag_ids">
            </div>
            
            <div>
                <label>Website</label>
                <input type="url" name="website" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
            </div>
            <div style="margin-top:8px; padding: 12px; border: 1px solid #0369a1; background: #f0f9ff; border-radius: 8px;">
                <div style="display:flex; justify-content: space-between; align-items:center; gap:8px; margin-bottom:8px;">
                    <label style="margin:0; color:#0369a1;">Smart Address</label>
                    <div style="display:flex; gap:8px;">
                        <button type="button" class="btn small" style="background:#0369a1;" onclick="acpAL_useMyLocation()">📍 Use My Location</button>
                        <button type="button" class="btn small secondary" onclick="acpAL_clearAddress()">Clear</button>
                    </div>
                </div>
                <input id="acpAL_autocomplete_address" type="text" placeholder="Start typing an address..." style="width: 100%; padding: 10px; border: 1px solid #93c5fd; border-radius: 8px;">
                <div style="display:grid; grid-template-columns: 2fr 1fr; gap: 8px; margin-top: 8px;">
                    <input name="address_line1" id="acpAL_addr_line1" placeholder="Address line 1" class="input" style="padding:8px;">
                    <input name="address_line2" id="acpAL_addr_line2" placeholder="Address line 2 (optional)" class="input" style="padding:8px;">
                    <input name="city" id="acpAL_addr_city" placeholder="City" class="input" style="padding:8px;">
                    <input name="state" id="acpAL_addr_state" placeholder="State" class="input" style="padding:8px;">
                    <input name="zip_code" id="acpAL_addr_zip" placeholder="Zip" class="input" style="padding:8px;">
                    <input name="country" id="acpAL_addr_country" placeholder="Country" class="input" style="padding:8px;">
                </div>
                <input type="hidden" name="address_full" id="acpAL_addr_full">
                <input type="hidden" name="postal_code" id="acpAL_addr_postal">
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
            <div style="background: #f0f9ff; padding: 16px; border-radius: 8px; border: 1px solid #0369a1;">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                    <input type="checkbox" id="acpAL_demo_dropoff" name="demo_dropoff" value="1" onchange="acpAL_toggleDemoOptions()" style="width: 16px; height: 16px;">
                    <label for="acpAL_demo_dropoff" style="font-weight: 600; color: #0369a1; margin: 0; cursor: pointer;">🧪 Drop off demo during this visit?</label>
                </div>
                <div id="acpAL_demo_options" style="display: none; margin-left: 28px;">
                    <label>Select Demo Type(s):</label>
                    <div style="display: grid; gap: 8px; margin-bottom: 12px;">
                        <?php 
                        $acpAL_demoTypes = [];
                        try { $acpAL_demoTypes = get_demo_types($pdo); } catch (Throwable $e) { $acpAL_demoTypes = []; }
                        foreach ($acpAL_demoTypes as $demo): 
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
                <button type="button" onclick="(function(){try{document.getElementById('acpAL_addLeadModal').style.display='none';}catch(e){}})()" class="btn secondary">Cancel</button>
                <button type="submit" class="btn" style="background: #059669;">Add Lead</button>
            </div>
        </form>
    </div>
    <style>
    @keyframes acpAL_shake { 10%,90%{ transform: translate(-50%, -50%) translateX(-1px);} 20%,80%{ transform: translate(-50%, -50%) translateX(2px);} 30%,50%,70%{ transform: translate(-50%, -50%) translateX(-4px);} 40%,60%{ transform: translate(-50%, -50%) translateX(4px);} }
    #acpAL_addLeadModal .acpAL_modalWrap.acpAL_shake { animation: acpAL_shake 0.5s; }
    #acpAL_addLeadModal .acpAL_error { color:#dc2626; font-size:12px; margin-top:4px; display:block; }
    #acpAL_addLeadModal input.error, #acpAL_addLeadModal select.error { border-color:#dc2626; }
    </style>
    
    <!-- Backdrop click to close -->
    <script>
    (function(){
        try {
            var modal = document.getElementById('acpAL_addLeadModal');
            if (modal && !modal.__acpAL_bound) {
                modal.addEventListener('click', function(ev){ if (ev.target === modal) { modal.style.display = 'none'; } });
                modal.__acpAL_bound = true;
            }
        } catch(e){}
    })();
    </script>
</div>

<script>
// Dashboard Add Lead Modal JS (acpAL_*) — unique, non-colliding helpers
document.addEventListener('DOMContentLoaded', function(){
    try {
        document.addEventListener('click', function(e){
            var trg = e.target.closest('[data-open-add-lead]');
            if (!trg) return;
            e.preventDefault();
            var m = document.getElementById('acpAL_addLeadModal');
            if (m) m.style.display = 'block';
        });
    } catch(e) {}
    // Bind proxy autocomplete
    try {
        var ac = document.getElementById('acpAL_autocomplete_address');
        if (ac) acpAL_bindProxyAutocomplete(ac);
    } catch(e) {}
    // Bind AJAX submit for Add Lead modal
    try { acpAL_bindAddLeadAjax(); } catch(e) {}
});

function acpAL_toggleDemoOptions(){
    try {
        var cb = document.getElementById('acpAL_demo_dropoff');
        var opt = document.getElementById('acpAL_demo_options');
        if (opt && cb) opt.style.display = cb.checked ? 'block' : 'none';
    } catch(e) {}
}

function acpAL_fillAddressFields(place){
    if (!place) return;
    try {
        var comps = place.address_components || [];
        var parts = { street_number:'', route:'', locality:'', admin_area_l1:'', postal_code:'', country:'' };
        comps.forEach(function(c){
            var t = c.types || [];
            if (t.indexOf('street_number') !== -1) parts.street_number = c.long_name;
            if (t.indexOf('route') !== -1) parts.route = c.long_name;
            if (t.indexOf('locality') !== -1) parts.locality = c.long_name;
            if (t.indexOf('administrative_area_level_1') !== -1) parts.admin_area_l1 = c.short_name;
            if (t.indexOf('postal_code') !== -1) parts.postal_code = c.long_name;
            if (t.indexOf('country') !== -1) parts.country = c.long_name;
        });
        var line1 = (parts.street_number + ' ' + parts.route).trim();
        var byId = function(id){ return document.getElementById('acpAL_' + id); };
        if (byId('addr_line1')) byId('addr_line1').value = line1;
        if (byId('addr_line2')) byId('addr_line2').value = '';
        if (byId('addr_city')) byId('addr_city').value = parts.locality;
        if (byId('addr_state')) byId('addr_state').value = parts.admin_area_l1;
        if (byId('addr_zip')) byId('addr_zip').value = parts.postal_code;
        if (byId('addr_country')) byId('addr_country').value = parts.country || 'USA';
        if (byId('addr_full')) byId('addr_full').value = place.formatted_address || '';
        if (byId('addr_postal')) byId('addr_postal').value = parts.postal_code || '';
    } catch(e) {}
}

function acpAL_bindProxyAutocomplete(input){
    if (!input || input.__acpAL_bound) return;
    input.__acpAL_bound = true;
    var listId = input.id + '_list';
    var dl = document.getElementById(listId);
    if (!dl) {
        dl = document.createElement('div');
        dl.id = listId; dl.style.position='absolute'; dl.style.background='#fff'; dl.style.border='1px solid #e5e7eb'; dl.style.borderRadius='6px'; dl.style.boxShadow='0 6px 16px rgba(0,0,0,.12)'; dl.style.zIndex='99999';
        document.body.appendChild(dl);
    }
    function positionList(){
        var rect = input.getBoundingClientRect();
        dl.style.left = rect.left + 'px';
        dl.style.top = (rect.bottom + window.scrollY) + 'px';
        dl.style.width = rect.width + 'px';
    }
    input.addEventListener('input', function(){
        positionList();
        var val = input.value; if (val.length < 3) { dl.innerHTML=''; return; }
        var session = (window.__acpAL_ac_session || (window.__acpAL_ac_session = (Math.random().toString(36).slice(2))));
        fetch(((window.__BASE_PREFIX||'')) + '/api/google-places-proxy.php?op=ac&q=' + encodeURIComponent(val) + '&session=' + encodeURIComponent(session))
            .then(function(r){ return r.json(); })
            .then(function(data){
                dl.innerHTML = '';
                (data.predictions || []).slice(0,5).forEach(function(p){
                    var item = document.createElement('div');
                    item.style.padding='10px 12px'; item.style.cursor='pointer'; item.style.borderBottom='1px solid #f3f4f6';
                    item.onmouseenter=function(){ this.style.background='#f9fafb'; };
                    item.onmouseleave=function(){ this.style.background='#fff'; };
                    item.textContent = p.description;
                    item.onclick = function(){
                        input.value = p.description; dl.innerHTML = '';
                        fetch(((window.__BASE_PREFIX||'')) + '/api/google-places-proxy.php?op=details&place_id=' + encodeURIComponent(p.place_id))
                            .then(function(r){ return r.json(); })
                            .then(function(d){ acpAL_fillAddressFields((d.result || d)); });
                    };
                    dl.appendChild(item);
                });
            });
    });
    input.addEventListener('blur', function(){ setTimeout(function(){ dl.innerHTML=''; }, 200); });
    window.addEventListener('resize', positionList);
}

function acpAL_useMyLocation(){
    try {
        // Prefer browser GPS with high accuracy; suppress user alerts and silently fall back
        if (!navigator.geolocation) {
            // Silent fallback when API unsupported
            fetch(((window.__BASE_PREFIX||'')) + '/api/ip-geolocate.php').then(function(r){return r.json();}).then(function(ip){
                if (!ip || !ip.ok) return;
                return fetch(((window.__BASE_PREFIX||'')) + '/api/google-places-proxy.php?op=geocode&lat=' + ip.lat + '&lng=' + ip.lng)
                    .then(function(r){ return r.json(); })
                    .then(function(data){ var res=(data.results&&data.results[0])||null; if(!res) return; var el=document.getElementById('acpAL_autocomplete_address'); if(el) el.value=(res.formatted_address||''); acpAL_fillAddressFields(res); });
            }).catch(function(){});
            return;
        }

        var geoOpts = { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 };

        var didFinish = false;
        var onSuccess = function(pos){
            if (didFinish) return; didFinish = true;
            var lat = pos.coords.latitude; var lng = pos.coords.longitude;
            fetch(((window.__BASE_PREFIX||'')) + '/api/google-places-proxy.php?op=geocode&lat=' + lat + '&lng=' + lng)
                .then(function(r){ return r.json(); })
                .then(function(data){
                    var res = (data.results && data.results[0]) || null;
                    if (!res) return;
                    var el = document.getElementById('acpAL_autocomplete_address');
                    if (el) el.value = (res.formatted_address||'');
                    acpAL_fillAddressFields(res);
                }).catch(function(){});
        };
        var onError = function(){
            if (didFinish) return; didFinish = true;
            // Silent Google-IP fallback
            fetch(((window.__BASE_PREFIX||'')) + '/api/ip-geolocate.php')
                .then(function(r){ return r.json(); })
                .then(function(ip){
                    if (!ip || !ip.ok || ip.lat == null || ip.lng == null) { return; }
                    return fetch(((window.__BASE_PREFIX||'')) + '/api/google-places-proxy.php?op=geocode&lat=' + ip.lat + '&lng=' + ip.lng)
                        .then(function(r){ return r.json(); })
                        .then(function(data){
                            var res = (data.results && data.results[0]) || null; if (!res) return;
                            var el = document.getElementById('acpAL_autocomplete_address');
                            if (el) el.value = (res.formatted_address||'');
                            acpAL_fillAddressFields(res);
                        });
                }).catch(function(){});
        };

        navigator.geolocation.getCurrentPosition(onSuccess, onError, geoOpts);
    } catch(e) { /* silent */ }
}

function acpAL_clearAddress(){
    ['autocomplete_address','addr_line1','addr_line2','addr_city','addr_state','addr_zip','addr_country','addr_full','addr_postal'].forEach(function(id){ var el = document.getElementById('acpAL_' + id); if (el) el.value = ''; });
}
</script>

<script>
// Enhanced animations and interactions for Paladin theme
document.addEventListener('DOMContentLoaded', function() {
    // Animate stats on page load
    const statValues = document.querySelectorAll('.sacp_stat-value');
    statValues.forEach((stat, index) => {
        setTimeout(() => {
            stat.style.animation = 'success-check 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275)';
        }, index * 100);
    });
    
    // Add hover effects to lead cards
    const leadCards = document.querySelectorAll('.sacp_lead-card');
    leadCards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateX(4px)';
        });
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateX(0)';
        });
    });
    
    // Pulse effect for urgent items
    const urgentItems = document.querySelectorAll('.urgent-followup, .needs-attention');
    urgentItems.forEach(item => {
        // Already animated via CSS
        item.style.cursor = 'pointer';
        item.addEventListener('click', function() {
            this.style.animation = 'none';
            setTimeout(() => {
                this.style.animation = '';
            }, 100);
        });
    });
    
    // Success animation for completed tasks
    const completeBtns = document.querySelectorAll('button[type="submit"]');
    completeBtns.forEach(btn => {
        if (btn.textContent.includes('Complete')) {
            btn.addEventListener('click', function(e) {
                const originalText = this.innerHTML;
                this.innerHTML = '<span class="success-animation">✓</span> Done!';
                this.classList.add('success');
                setTimeout(() => {
                    this.innerHTML = originalText;
                    this.classList.remove('success');
                }, 2000);
            });
        }
    });
});

// Initialize Paladin theme features
(function() {
    // Add theme class to body
    document.body.classList.add('paladin-theme');
    
    // Check for dark mode preference (future enhancement)
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    if (prefersDark) {
        // Future: Add dark mode support
        // document.body.classList.add('paladin-dark');
    }
})();
</script>

<script>
// Add Lead Modal - Auto-Tag Detection
let acpAL_selectedTags = [];

async function acpAL_detectTags() {
    const companyName = document.getElementById('acpAL_company_name').value.trim();
    if (!companyName || companyName.length < 3) {
        document.getElementById('acpAL_tags_section').style.display = 'none';
        return;
    }
    
    const city = document.getElementById('acpAL_addr_city').value.trim();
    const state = document.getElementById('acpAL_addr_state').value.trim();
    const address = document.getElementById('acpAL_addr_line1').value.trim();
    
    try {
        const response = await fetch('/api/sacp1015-auto-tag.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                company_name: companyName,
                address: address,
                city: city,
                state: state
            })
        });
        
        const data = await response.json();
        
        if (data.success && data.suggestions && data.suggestions.length > 0) {
            // Show tags section
            document.getElementById('acpAL_tags_section').style.display = 'block';
            
            // Render suggested tags
            const tagsContainer = document.getElementById('acpAL_suggested_tags');
            tagsContainer.innerHTML = '';
            
            // Auto-select high-confidence tag
            acpAL_selectedTags = [];
            
            data.suggestions.forEach(tag => {
                const isAutoSelect = tag.confidence >= 70;
                if (isAutoSelect) {
                    acpAL_selectedTags.push(tag.tag_id);
                }
                
                const tagBtn = document.createElement('button');
                tagBtn.type = 'button';
                tagBtn.className = isAutoSelect ? 'selected' : '';
                tagBtn.style.cssText = `
                    display:flex;
                    align-items:center;
                    gap:6px;
                    padding:8px 12px;
                    border:2px solid ${isAutoSelect ? tag.tag_color : '#e5e7eb'};
                    background:${isAutoSelect ? tag.tag_color + '20' : 'white'};
                    border-radius:8px;
                    cursor:pointer;
                    transition:all 0.2s;
                    font-size:13px;
                    font-weight:600;
                    color:${isAutoSelect ? tag.tag_color : '#6b7280'};
                `;
                tagBtn.innerHTML = `
                    <span style="font-size:18px;">${tag.tag_icon}</span>
                    <span>${tag.tag_name}</span>
                    <span style="font-size:11px;opacity:0.7;">${tag.confidence}%</span>
                `;
                tagBtn.onclick = () => acpAL_toggleTag(tag.tag_id, tag.tag_color, tagBtn);
                tagsContainer.appendChild(tagBtn);
            });
            
            // Update hidden input
            acpAL_updateTagsInput();
        }
    } catch (error) {
        console.error('Tag detection error:', error);
    }
}

function acpAL_toggleTag(tagId, tagColor, button) {
    const index = acpAL_selectedTags.indexOf(tagId);
    
    if (index > -1) {
        // Remove tag
        acpAL_selectedTags.splice(index, 1);
        button.style.border = '2px solid #e5e7eb';
        button.style.background = 'white';
        button.style.color = '#6b7280';
        button.classList.remove('selected');
    } else {
        // Add tag
        acpAL_selectedTags.push(tagId);
        button.style.border = `2px solid ${tagColor}`;
        button.style.background = `${tagColor}20`;
        button.style.color = tagColor;
        button.classList.add('selected');
    }
    
    acpAL_updateTagsInput();
}

function acpAL_updateTagsInput() {
    document.getElementById('acpAL_selected_tag_ids').value = acpAL_selectedTags.join(',');
}

// Add Lead modal AJAX submission and feedback helpers
function acpAL_bindAddLeadAjax(){
    var modal = document.getElementById('acpAL_addLeadModal');
    if (!modal) return;
    var form = modal.querySelector('form');
    if (!form || form.__acpAL_bound) return;
    form.__acpAL_bound = true;

    function clearErrors(){
        try {
            form.querySelectorAll('.acpAL_error').forEach(function(n){ n.remove(); });
            form.querySelectorAll('.error').forEach(function(n){ n.classList.remove('error'); });
        } catch(e) {}
    }
    function setFieldError(name, message){
        try {
            var field = form.querySelector('[name="'+name+'"]');
            if (!field) return;
            field.classList.add('error');
            var err = document.createElement('span');
            err.className = 'acpAL_error';
            err.textContent = message || 'Invalid value';
            field.parentNode.appendChild(err);
            try { field.scrollIntoView({behavior:'smooth', block:'center'}); field.focus(); } catch(e) {}
        } catch(e) {}
    }
    function shakeModal(){
        try {
            var wrap = modal.querySelector('.acpAL_modalWrap');
            if (!wrap) return;
            wrap.classList.remove('acpAL_shake');
            // Force reflow
            void wrap.offsetWidth;
            wrap.classList.add('acpAL_shake');
        } catch(e) {}
    }

    form.addEventListener('submit', function(e){
        try { e.preventDefault(); } catch(_) {}
        clearErrors();
        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Saving...'; }
        var fd = new FormData(form);
        // Ensure we target JSON endpoint behavior
        fd.set('action', 'add_lead');
        fetch('leads.php', {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
        }).then(function(r){ return r.json(); }).then(function(res){
            if (res && res.ok) {
                try { modal.style.display = 'none'; } catch(e) {}
                setTimeout(function(){ location.reload(); }, 400);
                return;
            }
            // Error flows
            if (res && res.code === 'duplicate') {
                setFieldError(res.field || 'email', res.message || 'Duplicate record');
                shakeModal();
            } else if (res && res.code === 'validation') {
                if (res.field) setFieldError(res.field, res.message || 'Please check this field.');
                else setFieldError('email', res.message || 'Please complete required fields.');
            } else {
                setFieldError('email', (res && res.message) ? res.message : 'Unable to add lead.');
            }
        }).catch(function(){
            setFieldError('email', 'Network error. Please try again.');
        }).finally(function(){
            if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Add Lead'; }
        });
    });
}
</script>

<script>
// Dashboard Nearby Leads Search System
(function(){
    // Bind address autocomplete on page load
    document.addEventListener('DOMContentLoaded', function(){
        try {
            var input = document.getElementById('dashboard_search_address');
            if (input) dashboardBindAddressAutocomplete(input);
        } catch(e) { console.error('Dashboard nearby init error:', e); }
    });
})();

// Known stores for filtering (from PHP)
window.__DASHBOARD_KNOWN_STORES = <?php echo json_encode($knownStores ?? []); ?>;
window.__dashboard_search_active = false;
window.__dashboard_nearby_results = [];
window.__dashboard_abort = null;

function dashboardBindAddressAutocomplete(input){
    if (!input || input.__dashboard_bound) return;
    input.__dashboard_bound = true;
    
    var listId = 'dashboard_address_list';
    var dl = document.getElementById(listId);
    if (!dl) {
        dl = document.createElement('div');
        dl.id = listId;
        dl.style.cssText = 'position:absolute; background:#fff; border:1px solid #e5e7eb; border-radius:6px; box-shadow:0 6px 16px rgba(0,0,0,.12); z-index:99999; max-height:300px; overflow-y:auto;';
        document.body.appendChild(dl);
    }
    
    function positionList(){
        var rect = input.getBoundingClientRect();
        dl.style.left = rect.left + 'px';
        dl.style.top = (rect.bottom + window.scrollY) + 'px';
        dl.style.width = rect.width + 'px';
    }
    
    var debounce = null;
    input.addEventListener('input', function(){
        clearTimeout(debounce);
        debounce = setTimeout(function(){
            positionList();
            var val = input.value;
            if (val.length < 3) { dl.innerHTML = ''; return; }
            
            var session = (window.__dashboard_ac_session || (window.__dashboard_ac_session = Math.random().toString(36).slice(2)));
            fetch(((window.__BASE_PREFIX||'')) + '/api/google-places-proxy.php?op=ac&q=' + encodeURIComponent(val) + '&session=' + encodeURIComponent(session))
                .then(function(r){ return r.json(); })
                .then(function(data){
                    dl.innerHTML = '';
                    (data.predictions || []).slice(0,5).forEach(function(p){
                        var item = document.createElement('div');
                        item.style.cssText = 'padding:10px 12px; cursor:pointer; border-bottom:1px solid #f3f4f6;';
                        item.textContent = p.description;
                        item.onmouseenter = function(){ this.style.background='#f9fafb'; };
                        item.onmouseleave = function(){ this.style.background='#fff'; };
                        item.onclick = function(){
                            input.value = p.description;
                            dl.innerHTML = '';
                            input.dataset.placeId = p.place_id;
                            // Auto-search on selection
                            dashboardSearchNearby();
                        };
                        dl.appendChild(item);
                    });
                });
        }, 300);
    });
    
    input.addEventListener('blur', function(){ setTimeout(function(){ dl.innerHTML=''; }, 200); });
    input.addEventListener('keydown', function(e){
        if (e.key === 'Enter') {
            e.preventDefault();
            dl.innerHTML = '';
            dashboardSearchNearby();
        }
    });
    window.addEventListener('resize', positionList);
}

function dashboardUpdateStatus(message, theme){
    try {
        var el = document.getElementById('dashboard_nearby_status');
        if (!el) return;
        el.textContent = message;
        el.style.color = theme === 'error' ? '#dc2626' : (theme === 'warn' ? '#f59e0b' : '#6b7280');
    } catch(e) {}
}

function dashboardSearchNearby(){
    try {
        if (window.__dashboard_abort) { window.__dashboard_abort.abort(); }
        window.__dashboard_abort = new AbortController();
        
        var input = document.getElementById('dashboard_search_address');
        if (!input || !input.value.trim()) {
            dashboardUpdateStatus('Please enter an address to search', 'warn');
            return;
        }
        
        var address = input.value.trim();
        dashboardUpdateStatus('Geocoding address...', 'info');
        
        // First geocode the address
        fetch(((window.__BASE_PREFIX||'')) + '/api/google-places-proxy.php?op=geocode_addr&address=' + encodeURIComponent(address))
            .then(function(r){ return r.json(); })
            .then(function(data){
                var res = (data.results && data.results[0]);
                if (!res) {
                    dashboardUpdateStatus('Address not found. Please try a different address.', 'error');
                    return;
                }
                
                var lat = res.geometry.location.lat;
                var lng = res.geometry.location.lng;
                
                // Now search nearby
                dashboardLoadNearby(lat, lng);
            })
            .catch(function(err){
                if (err.name === 'AbortError') return;
                dashboardUpdateStatus('Unable to geocode address. Please try again.', 'error');
            });
    } catch(e) {
        console.error('Dashboard search error:', e);
        dashboardUpdateStatus('Search error. Please try again.', 'error');
    }
}

function dashboardLoadNearby(lat, lng){
    try {
        window.__dashboard_search_active = true;
        var resultsDiv = document.getElementById('dashboard_nearby_results');
        var listDiv = document.getElementById('dashboard_nearby_list');
        if (resultsDiv) resultsDiv.style.display = 'block';
        if (listDiv) listDiv.innerHTML = '';
        
        dashboardUpdateStatus('Searching for nearby stores...', 'info');
        
        var radiusSel = document.getElementById('dashboard_nearby_radius');
        var miles = radiusSel ? parseFloat(radiusSel.value || '10') : 10;
        var radiusMeters = miles * 1609.344;
        
        var includeGas = document.getElementById('dashboard_toggle_gas') && document.getElementById('dashboard_toggle_gas').checked ? 1 : 0;
        var typeSel = (document.getElementById('dashboard_nearby_type') || {}).value || 'auto';
        
        var fetchToken = Date.now();
        window.__dashboard_fetch_token = fetchToken;
        
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
        
        var resultsById = {};
        
        function runSearch(catList){
            var catParam = catList && catList.length ? ('&categories=' + encodeURIComponent(catList.join(','))) : '';
            var url = ((window.__BASE_PREFIX||'')) + '/api/google-places-proxy.php?op=v1_textsearch&lat=' + lat + '&lng=' + lng + '&radius=' + radiusMeters + '&include_gas=' + includeGas + '&details=1' + catParam;
            return fetch(url, { signal: window.__dashboard_abort.signal }).then(function(r){ return r.json(); });
        }
        
        (async function(){
            try {
                for (var i = 0; i < fetchQueues.length; i++) {
                    if (window.__dashboard_abort && window.__dashboard_abort.signal.aborted) return;
                    
                    var data = await runSearch(fetchQueues[i]);
                    (data.results || []).forEach(function(item){
                        if (!item || !item.place_id || resultsById[item.place_id]) return;
                        resultsById[item.place_id] = item;
                    });
                    
                    var total = Object.keys(resultsById).length;
                    dashboardUpdateStatus('Found ' + total + ' locations within ' + Math.round(radiusMeters / 1609.344) + ' miles...', 'info');
                    
                    if (total >= 40) break;
                }
                
                if (window.__dashboard_abort && window.__dashboard_abort.signal.aborted) return;
                
                window.__dashboard_nearby_results = Object.keys(resultsById).map(function(key){ return resultsById[key]; });
                
                if (window.__dashboard_nearby_results.length === 0) {
                    dashboardUpdateStatus('No stores found in this area. Try a different address or larger radius.', 'warn');
                    if (listDiv) listDiv.innerHTML = '<div style="text-align:center; padding:24px; color:#6b7280;">No stores found</div>';
                    return;
                }
                
                // Compute distances
                window.__dashboard_nearby_results.forEach(function(p){
                    if (typeof p.distance_m === 'number' && p.distance_m >= 0) {
                        p.__distance = p.distance_m / 1609.344;
                    } else {
                        var locVal = p.geometry && p.geometry.location ? p.geometry.location : {};
                        var placeLat = (typeof locVal.lat === 'function') ? locVal.lat() : locVal.lat;
                        var placeLng = (typeof locVal.lng === 'function') ? locVal.lng() : locVal.lng;
                        if (typeof placeLat === 'number' && typeof placeLng === 'number') {
                            p.__distance = dashboardGetDistanceMiles(lat, lng, placeLat, placeLng);
                        } else {
                            p.__distance = 9999;
                        }
                    }
                });
                
                dashboardUpdateStatus('Loading contact details...', 'info');
                
                // Fetch details for top results
                await dashboardPrimeDetails(window.__dashboard_nearby_results.slice(0, 20), fetchToken);
                
                if (window.__dashboard_fetch_token === fetchToken) {
                    dashboardRenderNearby();
                    dashboardUpdateStatus('Found ' + window.__dashboard_nearby_results.length + ' potential leads nearby', 'info');
                }
            } catch(err) {
                if (err && err.name === 'AbortError') return;
                console.error('Dashboard nearby search error:', err);
                dashboardUpdateStatus('Search failed. Please try again.', 'error');
            }
        })();
    } catch(e) {
        console.error('Dashboard load nearby error:', e);
        dashboardUpdateStatus('Error loading results', 'error');
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

async function dashboardPrimeDetails(results, fetchToken){
    try {
        if (!Array.isArray(results) || results.length === 0) return;
        
        await Promise.all(results.map(function(item){
            if (!item || !item.place_id) return Promise.resolve();
            
            var url = ((window.__BASE_PREFIX||'')) + '/api/google-places-proxy.php?op=v1_details&place_id=' + encodeURIComponent(item.place_id) + '&fields=id,displayName,internationalPhoneNumber,nationalPhoneNumber,websiteUri,googleMapsUri,addressComponents';
            return fetch(url, { signal: window.__dashboard_abort.signal })
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if (!data || !data.id) return;
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
                .catch(function(){ /* ignore */ });
        }));
    } catch(e) {}
}

async function dashboardRenderNearby(){
    try {
        var listDiv = document.getElementById('dashboard_nearby_list');
        if (!listDiv) return;
        
        var results = (window.__dashboard_nearby_results || []).slice();
        
        var includeGas = document.getElementById('dashboard_toggle_gas') && document.getElementById('dashboard_toggle_gas').checked ? 1 : 0;
        var typeSel = (document.getElementById('dashboard_nearby_type') || {}).value || 'auto';
        
        results = results.filter(function(place){
            var cat = place && place.category_key ? place.category_key : 'core';
            if (typeSel === 'all') return true;
            if (typeSel === 'auto') return includeGas || cat !== 'convenience_gas';
            return cat === typeSel;
        });
        
        var sort = (document.getElementById('dashboard_nearby_sort') || {}).value || 'distance';
        if (sort === 'name') {
            results.sort(function(a,b){ return (a.name||'').localeCompare(b.name||''); });
        } else {
            results.sort(function(a,b){ return (a.__distance||9999) - (b.__distance||9999); });
        }
        
        listDiv.innerHTML = '';
        
        var added = 0;
        for (var idx = 0; idx < results.length && added < 12; idx++) {
            var place = results[idx];
            if (!place || !place.place_id) continue;
            
            var addr = place.vicinity || place.formatted_address || '';
            
            // Check if already exists
            var info = null;
            try {
                var resp = await fetch('leads.php?action=check_place&name=' + encodeURIComponent(place.name || '') + '&addr=' + encodeURIComponent(addr));
                info = await resp.json();
            } catch(err) {
                continue;
            }
            
            // Skip if already assigned (but show if archived by current agent)
            if (info && info.assigned && !info.archived_by_me) continue;
            
            var row = document.createElement('div');
            row.className = 'nearby-item';
            row.style.cssText = 'display:flex; justify-content:space-between; align-items:center; padding:12px; border:1px solid #e5e7eb; border-radius:8px; background:#fff; transition:all 0.2s;';
            row.onmouseenter = function(){ this.style.boxShadow = '0 4px 12px rgba(0,0,0,0.08)'; };
            row.onmouseleave = function(){ this.style.boxShadow = 'none'; };
            
            var left = document.createElement('div');
            left.style.flex = '1';
            
            var dist = (typeof place.__distance === 'number' && place.__distance < 9900) ? (' • ' + place.__distance.toFixed(1) + ' mi') : '';
            var contact = '';
            if (place.phone) contact += ' • ' + place.phone;
            else if (place.intl_phone) contact += ' • ' + place.intl_phone;
            
            var website = place.website ? (' • <a href="' + place.website + '" target="_blank" style="color:#0369a1;">website</a>') : '';
            var catChip = place.category_key ? (' <span style="display:inline-block; padding:2px 6px; background:#e0f2fe; color:#0369a1; border-radius:4px; font-size:10px; margin-left:6px;">' + place.category_key.replace('_','/') + '</span>') : '';
            var archivedBadge = (info && info.archived_by_me) ? ' <span style="display:inline-block; padding:2px 6px; background:#fef3c7; color:#92400e; border-radius:4px; font-size:10px; margin-left:6px;">ARCHIVED</span>' : '';
            
            left.innerHTML = '<div style="font-weight:600; color:#1f2937; margin-bottom:4px;">' + (place.name || 'Store') + catChip + archivedBadge + '</div>' +
                           '<div style="font-size:13px; color:#6b7280;">' + addr + dist + contact + website + '</div>';
            
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
                                dashboardUpdateStatus('Lead unarchived successfully', 'info');
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
                addBtn.style.cssText = 'background:#059669; color:#fff; white-space:nowrap;';
                addBtn.innerHTML = '🏷️ Add & Tag';
                
                addBtn.onclick = (function(placeSnapshot, addrSnapshot, buttonEl){
                    return async function(){
                        if (buttonEl.dataset.loading === '1') return;
                        buttonEl.dataset.loading = '1';
                        buttonEl.disabled = true;
                        buttonEl.innerHTML = 'Detecting tags...';
                        
                        // Auto-detect tags first
                        let tagIds = '';
                        try {
                            const tagResponse = await fetch('/api/sacp1015-auto-tag.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ 
                                    company_name: placeSnapshot.name || '',
                                    address: placeSnapshot.address_line1 || '',
                                    city: placeSnapshot.city || '',
                                    state: placeSnapshot.state || ''
                                })
                            });
                            const tagData = await tagResponse.json();
                            if (tagData.success && tagData.suggestions && tagData.suggestions.length > 0) {
                                // Auto-assign high confidence tags
                                const highConfTags = tagData.suggestions.filter(t => t.confidence >= 70);
                                if (highConfTags.length > 0) {
                                    tagIds = highConfTags.map(t => t.tag_id).join(',');
                                }
                            }
                        } catch (error) {
                            console.error('Tag detection error:', error);
                        }
                        
                        buttonEl.innerHTML = 'Adding...';
                        
                        var phoneVal = placeSnapshot.phone || placeSnapshot.intl_phone || '';
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
                            attribution_source: 'dashboard_nearby',
                            tag_ids: tagIds
                        });
                        
                        fetch('leads.php', { 
                            method: 'POST', 
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, 
                            body: fd 
                        })
                        .then(function(r){ return r.json(); })
                        .then(function(res){
                            if (res && res.ok) {
                                buttonEl.innerHTML = '✓ Tagged & Added';
                                buttonEl.style.background = '#10b981';
                                buttonEl.disabled = true;
                                
                                // Show success message with tag info
                                var msg = 'Lead added successfully!';
                                if (res.mode === 'revived') msg = 'Lead reactivated!';
                                else if (res.mode === 'active') msg = 'Lead already exists!';
                                if (tagIds) msg += ' Auto-tagged.';
                                
                                dashboardUpdateStatus(msg + ' Refresh results to update.', 'info');
                            } else {
                                var errMsg = (res && res.message) || 'Unable to add lead';
                                alert(errMsg);
                                buttonEl.disabled = false;
                                buttonEl.textContent = 'Add Lead';
                                delete buttonEl.dataset.loading;
                            }
                        })
                        .catch(function(err){
                            console.error(err);
                            alert('Network error adding lead');
                            buttonEl.disabled = false;
                            buttonEl.textContent = 'Add Lead';
                            delete buttonEl.dataset.loading;
                        });
                    };
                })(place, addr, addBtn);
                
                right.appendChild(addBtn);
            } else if (info.unassigned && !info.assigned) {
                var reqBtn = document.createElement('button');
                reqBtn.className = 'btn small';
                reqBtn.style.cssText = 'background:#0369a1; color:#fff; white-space:nowrap;';
                reqBtn.textContent = 'Request Lead';
                reqBtn.onclick = function(){
                    alert('Lead assignment request will be sent to admin (feature coming soon)');
                };
                right.appendChild(reqBtn);
            } else {
                continue;
            }
            
            row.appendChild(left);
            row.appendChild(right);
            listDiv.appendChild(row);
            added++;
        }
        
        if (added === 0) {
            listDiv.innerHTML = '<div style="text-align:center; padding:24px; color:#6b7280;">No new leads found. All nearby stores are already in the system.</div>';
        }
    } catch(e) {
        console.error('Dashboard render error:', e);
    }
}

function dashboardGetDistanceMiles(lat1, lon1, lat2, lon2){
    function toRad(v){ return v * Math.PI / 180; }
    var R = 3958.8; // miles
    var dLat = toRad(lat2-lat1), dLon = toRad(lon2-lon1);
    var a = Math.sin(dLat/2)*Math.sin(dLat/2) + Math.cos(toRad(lat1))*Math.cos(toRad(lat2))*Math.sin(dLon/2)*Math.sin(dLon/2);
    var c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    return R * c;
}

function dashboardClearSearch(){
    try {
        document.getElementById('dashboard_search_address').value = '';
        document.getElementById('dashboard_nearby_results').style.display = 'none';
        document.getElementById('dashboard_nearby_list').innerHTML = '';
        dashboardUpdateStatus('Enter an address above to search for nearby leads', 'info');
        window.__dashboard_search_active = false;
        window.__dashboard_nearby_results = [];
    } catch(e) {}
}

// Lightweight modals for Compose Email and Schedule Meeting
function ensureModalRoot(){
    if (!document.getElementById('modalRoot')){
        var d=document.createElement('div'); d.id='modalRoot'; d.style.position='fixed'; d.style.inset='0'; d.style.background='rgba(0,0,0,0.5)'; d.style.display='none'; d.style.zIndex='5000';
        d.innerHTML='<div id="modalCard" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:12px;max-width:90vw;max-height:90vh;overflow:auto;width:640px;padding:16px;"></div>';
        document.body.appendChild(d);
        d.addEventListener('click',function(e){ if(e.target.id==='modalRoot'){ d.style.display='none'; }});
    }
}
function openComposeEmailModal(){
    ensureModalRoot();
    var root=document.getElementById('modalRoot'), card=document.getElementById('modalCard');
    card.innerHTML='<h3>Compose Email</h3>'+
        '<label>To<br><input id="em_to" style="width:100%"></label>'+
        '<label>Subject<br><input id="em_sub" style="width:100%"></label>'+
        '<label>HTML Body<br><textarea id="em_html" style="width:100%;height:180px"></textarea></label>'+
        '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px">'+
        '<button class="btn secondary" onclick="document.getElementById(\'modalRoot\').style.display=\'none\'">Cancel</button>'+
        '<button class="btn" style="background:#2563eb;color:#fff" onclick="sendEmailAjax()">Send</button></div>';
    root.style.display='block';
}
async function sendEmailAjax(){
    const res = await fetch('<?php echo path_with_base('/required/api/gmail-send.php'); ?>',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({to:document.getElementById('em_to').value,subject:document.getElementById('em_sub').value,html:document.getElementById('em_html').value})});
    const j = await res.json();
    if(j.ok){ alert('Email sent'); document.getElementById('modalRoot').style.display='none'; } else { alert('Error: '+j.error); }
}
function openScheduleMeetingModal(){
    ensureModalRoot();
    var root=document.getElementById('modalRoot'), card=document.getElementById('modalCard');
    card.innerHTML='<h3>Schedule Meeting</h3>'+
        '<label>Title<br><input id="mt_title" style="width:100%" value="Intro Meeting"></label>'+
        '<label>Start (ISO)<br><input id="mt_start" style="width:100%" placeholder="2025-09-26T10:00:00-05:00"></label>'+
        '<label>End (ISO)<br><input id="mt_end" style="width:100%" placeholder="2025-09-26T10:30:00-05:00"></label>'+
        '<label>Attendees (comma emails)<br><input id="mt_att" style="width:100%"></label>'+
        '<label><input type="checkbox" id="mt_meet" checked> Create Google Meet link</label>'+
        '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px">'+
        '<button class="btn secondary" onclick="document.getElementById(\'modalRoot\').style.display=\'none\'">Cancel</button>'+
        '<button class="btn" style="background:#059669;color:#fff" onclick="createMeetingAjax()">Schedule</button></div>';
    root.style.display='block';
}
async function createMeetingAjax(){
    const attendees = document.getElementById('mt_att').value.split(',').map(s=>s.trim()).filter(Boolean);
    const res = await fetch('<?php echo path_with_base('/required/api/calendar-event.php'); ?>',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({summary:document.getElementById('mt_title').value,start:document.getElementById('mt_start').value,end:document.getElementById('mt_end').value,attendees:attendees,createMeet:document.getElementById('mt_meet').checked})});
    const j = await res.json();
    if(j.ok){ alert('Event created'+(j.event.hangoutLink?"\nMeet: "+j.event.hangoutLink:'')); document.getElementById('modalRoot').style.display='none'; } else { alert('Error: '+j.error); }
}

// Quick Tag Functionality
async function sacpQuickTag(leadId, companyName) {
    try {
        // Fetch available tags
        const response = await fetch('/api/sacp1015-auto-tag.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ company_name: companyName })
        });
        
        const data = await response.json();
        
        if (data.success && data.suggestions.length > 0) {
            // Show tag selection modal
            const modal = document.createElement('div');
            modal.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;';
            
            const suggestions = data.suggestions.slice(0, 5).map(tag => `
                <button onclick="assignTag(${leadId}, ${tag.tag_id}, '${tag.tag_name.replace(/'/g, "\\'")}'); closeQuickTagModal();" 
                        style="display:flex;align-items:center;gap:12px;padding:12px;border:1px solid #e5e7eb;border-radius:8px;background:white;cursor:pointer;transition:all 0.2s;width:100%;"
                        onmouseover="this.style.background='#f9fafb';this.style.borderColor='${tag.tag_color}';"
                        onmouseout="this.style.background='white';this.style.borderColor='#e5e7eb';">
                    <span style="font-size:24px;">${tag.tag_icon}</span>
                    <div style="flex:1;text-align:left;">
                        <div style="font-weight:600;color:#111827;">${tag.tag_name}</div>
                        <div style="font-size:12px;color:#6b7280;">Confidence: ${tag.confidence}%</div>
                    </div>
                    <span style="background:${tag.tag_color};color:white;padding:4px 12px;border-radius:6px;font-size:12px;font-weight:600;">Select</span>
                </button>
            `).join('');
            
            modal.innerHTML = `
                <div style="background:white;border-radius:12px;max-width:500px;width:100%;max-height:80vh;overflow:hidden;display:flex;flex-direction:column;">
                    <div style="padding:20px;border-bottom:1px solid #e5e7eb;">
                        <h3 style="margin:0;">🏷️ Quick Tag: ${companyName}</h3>
                        <p style="margin:8px 0 0 0;color:#6b7280;font-size:14px;">Select a business type tag</p>
                    </div>
                    <div style="padding:20px;overflow-y:auto;display:flex;flex-direction:column;gap:12px;">
                        ${suggestions}
                    </div>
                    <div style="padding:16px;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end;">
                        <button onclick="closeQuickTagModal()" class="btn secondary">Cancel</button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            modal.id = 'quick-tag-modal';
        } else {
            alert('No tag suggestions found for this business.');
        }
    } catch (error) {
        console.error('Quick Tag Error:', error);
        alert('Error loading tags. Please try again.');
    }
}

function closeQuickTagModal() {
    const m = document.getElementById('quick-tag-modal');
    if (m) m.remove();
}

async function assignTag(leadId, tagId, tagName) {
    try {
        const assignResp = await fetch('/api/sacp1015-assign-tag.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lead_id: leadId, tag_id: tagId })
        });
        
        if (assignResp.ok) {
            alert('✓ Tagged as: ' + tagName);
            location.reload();
        } else {
            alert('Error assigning tag. Please try again.');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error assigning tag. Please try again.');
    }
}
</script>

<?php 
// Include unified radial menu for Sales ACP
require_once __DIR__ . '/../../required/sales-acp-radial-menu.php'; 
?>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>