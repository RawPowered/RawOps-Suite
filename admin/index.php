<?php
$SEO = [
    'title' => 'Admin Portal — Product',
    'description' => 'Administrative dashboard for Product wholesale and sales management.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';

start_session();

$sanitizeAdminRedirect = static function (?string $candidate): ?string {
    if (!$candidate) {
        return null;
    }

    $path = parse_url($candidate, PHP_URL_PATH);
    if (!$path) {
        return null;
    }

    $query = parse_url($candidate, PHP_URL_QUERY);
    $target = $path;
    if ($query) {
        $target .= '?' . $query;
    }

    $adminBase = rtrim(path_with_base('/admin/'), '/');
    if (strpos($target, $adminBase) !== 0) {
        return path_with_base('/admin/');
    }

    return $target;
};

if (isset($_GET['redirect'])) {
    $_SESSION['admin_redirect_after_login'] = $sanitizeAdminRedirect($_GET['redirect']);
}

// Handle admin login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !current_admin_id()) {
    if (isset($_POST['redirect'])) {
        $_SESSION['admin_redirect_after_login'] = $sanitizeAdminRedirect($_POST['redirect']);
    }

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $adminId = login_admin($email, $password);
    if ($adminId) {
        $redirectTarget = $_SESSION['admin_redirect_after_login'] ?? path_with_base('/admin/');
        unset($_SESSION['admin_redirect_after_login']);
        flash_set('success', 'Welcome to the admin portal.');
        redirect($redirectTarget);
    }
    $error = 'Invalid admin credentials.';
}

$adminId = current_admin_id();
$pendingRedirect = $_SESSION['admin_redirect_after_login'] ?? null;
?>

<?php if (!$adminId): ?>
    <!-- Admin Login Form -->
    <section class="section">
        <div class="container">
            <div class="card" style="max-width: 500px; margin: 0 auto;">
                <h1>Admin Login</h1>
                <?php if (!empty($error)): ?>
                    <p style="color:#8a1f1f;background:#fff5f5;border:1px solid #ffb3b3;padding:12px;border-radius:8px;margin-bottom:16px"><?php echo sanitize($error); ?></p>
                <?php endif; ?>
                <form method="post" autocomplete="on">
                    <div class="form-grid">
                        <label style="grid-column: 1/-1;">
                            <span>Admin Email</span>
                            <input type="email" name="email" value="<?php echo isset($email) ? sanitize($email) : ''; ?>" autocomplete="email" required>
                        </label>
                        <label style="grid-column: 1/-1;">
                            <span>Password</span>
                            <input type="password" name="password" autocomplete="current-password" required>
                        </label>
                    </div>
                    <input type="hidden" name="redirect" value="<?php echo sanitize($pendingRedirect ?? ($_GET['redirect'] ?? '')); ?>">
                    <button class="btn" type="submit" style="width: 100%; margin-top: 16px;">Sign In as Admin</button>
                </form>
            </div>
        </div>
    </section>
<?php else: ?>
    <!-- Admin Dashboard -->
    <section class="section">
        <div class="container">
            <div class="adm-header">
                <h1 style="margin:0;">Admin Dashboard</h1>
                <div class="adm-sub">Operational overview • realtime insights</div>
            </div>
            <form method="get" style="margin-top:8px; display:flex; gap:12px; flex-wrap:wrap; align-items:end; background:#f8fafc; border:1px solid #e5e7eb; padding:12px; border-radius:8px;">
                <label>
                    <span style="display:block; font-size:12px; color:#6b7280;">Start</span>
                    <input type="date" name="start" value="<?php echo htmlspecialchars($_GET['start'] ?? date('Y-m-d', strtotime('-7 days'))); ?>">
                </label>
                <label>
                    <span style="display:block; font-size:12px; color:#6b7280;">End</span>
                    <input type="date" name="end" value="<?php echo htmlspecialchars($_GET['end'] ?? date('Y-m-d')); ?>">
                </label>
                <button class="btn" type="submit">Apply Filters</button>
            </form>
            <?php include __DIR__ . '/admin-menu.php'; ?>
            
            <!-- Enhanced Dashboard Grid -->
            <style>
            .dashboard-grid { display:grid; grid-template-columns: 2fr 1fr; gap:24px; margin-top:24px; }
            .kpi-row { display:grid; grid-template-columns: 1fr 1fr; gap:16px; margin-bottom:24px; }
            @media(max-width: 900px){
                .dashboard-grid { grid-template-columns: 1fr; }
                .kpi-row { grid-template-columns: 1fr; }
            }
            </style>
            <style>
            /* Admin premium theme (scoped) */
            .adm-header { background: linear-gradient(135deg, #eef2ff, #e8f5ff); border:1px solid #e5e7eb; padding:16px 20px; border-radius:14px; display:flex; align-items:center; gap:12px; justify-content:space-between; box-shadow: 0 6px 16px rgba(0,0,0,.06); }
            .adm-header .adm-sub{ color:#6b7280; font-size:12px; font-weight:600; }
            .adm-card{ border-radius:14px !important; border:1px solid #e5e7eb !important; box-shadow: 0 6px 16px rgba(2,6,23,.05) !important; }
            .adm-card h2{ margin-top:0; font-weight:800; letter-spacing:-0.01em; }
            .adm-chip{ display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:9999px; background:#f3f4f6; color:#374151; font-size:12px; font-weight:600; }
            .adm-pill{ padding:8px 12px; border-radius:10px; background:#f8fafc; border:1px solid #e5e7eb; }
            .adm-muted{ color:#6b7280; }
            /* KPI tiles & stat cards */
            .adm-stat-tiles>div, .adm-card .kpi-row>div{ border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 8px 20px rgba(2,6,23,.05); position:relative; overflow:hidden; }
            .adm-stat-tiles>div:hover, .adm-card .kpi-row>div:hover{ transform:translateY(-2px); box-shadow:0 12px 28px rgba(2,6,23,.08); }
            .adm-card .kpi-row h3{ font-weight:800; letter-spacing:-.02em; }
            .adm-micro{ font-size:12px; color:#6b7280; }
            /* Insights list polish */
            .adm-insight-row{ display:flex; align-items:center; gap:8px; margin:6px 0; }
            .adm-insight-bar{ flex:1; background:#e5e7eb; border-radius:9999px; height:8px; overflow:hidden; }
            .adm-insight-bar>div{ height:8px; background:#10b981; }
            /* Realtime widgets */
            #realtime-widgets h3{ font-weight:800; letter-spacing:-.01em; }
            #top-active>div{ background:rgba(0,0,0,0.02); padding:6px 8px; border-radius:8px; }
            </style>
            </style>
            <style>@media(max-width: 640px){ .adm-header{ padding:12px 14px; border-radius:12px; } }</style>
            <div class="dashboard-grid">
                <!-- Main Stats -->
                <div class="card adm-card">
                    <h2 style="color: #059669; margin-bottom: 24px;">📊 Business Overview</h2>
                    <?php
                    try {
                        $pdo = db();
                        $stats = [
                            'users' => $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
                            'wholesale' => $pdo->query('SELECT COUNT(*) FROM wholesale_accounts')->fetchColumn(),
                            'wholesale_approved' => $pdo->query('SELECT COUNT(*) FROM wholesale_accounts WHERE approved = 1')->fetchColumn(),
                            'wholesale_pending' => $pdo->query('SELECT COUNT(*) FROM wholesale_accounts WHERE approved = 0')->fetchColumn(),
                            'trainees' => $pdo->query('SELECT COUNT(*) FROM user_roles WHERE role_id = 1')->fetchColumn(),
                            'agents' => $pdo->query('SELECT COUNT(*) FROM user_roles WHERE role_id = 2')->fetchColumn(),
                            'products' => $pdo->query('SELECT COUNT(*) FROM products WHERE active = 1')->fetchColumn(),
                            'orders' => $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
                            'orders_today' => $pdo->query('SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()')->fetchColumn(),
                            'orders_week' => $pdo->query('SELECT COUNT(*) FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)')->fetchColumn(),
                            'revenue_month' => $pdo->query('SELECT COALESCE(SUM(total), 0) FROM orders WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW()) AND status != "cancelled"')->fetchColumn(),
                            'revenue_today' => $pdo->query('SELECT COALESCE(SUM(total), 0) FROM orders WHERE DATE(created_at) = CURDATE() AND status != "cancelled"')->fetchColumn(),
                            'commissions_pending' => $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM commissions WHERE status = "pending"')->fetchColumn(),
                            'active_training' => $pdo->query('SELECT COUNT(DISTINCT user_id) FROM training_active_sessions WHERE last_heartbeat >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)')->fetchColumn(),
                            'completed_training' => $pdo->query('SELECT COUNT(DISTINCT user_id) FROM training_progress WHERE status = "completed" AND completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)')->fetchColumn(),
                            'quiz_attempts_today' => $pdo->query('SELECT COUNT(*) FROM training_quiz_results WHERE DATE(completed_at) = CURDATE()')->fetchColumn(),
                            // Enhanced CRM/Lead metrics
                            'leads_total' => $pdo->query('SELECT COUNT(*) FROM crm_leads')->fetchColumn(),
                            'leads_new_today' => $pdo->query('SELECT COUNT(*) FROM crm_leads WHERE DATE(created_at) = CURDATE()')->fetchColumn(),
                            'leads_new_week' => $pdo->query('SELECT COUNT(*) FROM crm_leads WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)')->fetchColumn(),
                            'leads_new_month' => $pdo->query('SELECT COUNT(*) FROM crm_leads WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())')->fetchColumn(),
                            'leads_active' => $pdo->query('SELECT COUNT(*) FROM crm_leads WHERE lead_status IN ("new", "contacted", "qualified", "proposal_sent", "negotiating")')->fetchColumn(),
                            'conversions_today' => $pdo->query('SELECT COUNT(*) FROM crm_leads WHERE DATE(converted_at) = CURDATE() AND converted_to_account_id IS NOT NULL')->fetchColumn(),
                            'conversions_week' => $pdo->query('SELECT COUNT(*) FROM crm_leads WHERE converted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND converted_to_account_id IS NOT NULL')->fetchColumn(),
                            'conversions_month' => $pdo->query('SELECT COUNT(*) FROM crm_leads WHERE MONTH(converted_at) = MONTH(NOW()) AND YEAR(converted_at) = YEAR(NOW()) AND converted_to_account_id IS NOT NULL')->fetchColumn(),
                            'conversion_rate_week' => 0,
                            'conversion_rate_month' => 0,
                            // Lead Detail Quality Metrics (works with current database structure)
                            'leads_detailed' => $pdo->query('
                                SELECT COUNT(*) FROM crm_leads 
                                WHERE (
                                    email IS NOT NULL 
                                    AND email != ""
                                    AND email NOT LIKE "sample-009@example.com"
                                    AND email NOT LIKE "sample-010@example.com"
                                    AND email NOT LIKE "lead+%"
                                    AND email NOT LIKE "auto+%"
                                    AND email NOT LIKE "no-email-%"
                                )
                                AND (phone IS NOT NULL AND phone != "")
                            ')->fetchColumn(),
                            'leads_incomplete' => $pdo->query('
                                SELECT COUNT(*) FROM crm_leads 
                                WHERE NOT (
                                    (
                                        email IS NOT NULL 
                                        AND email != ""
                                        AND email NOT LIKE "sample-009@example.com"
                                        AND email NOT LIKE "sample-010@example.com"
                                        AND email NOT LIKE "lead+%"
                                        AND email NOT LIKE "auto+%"
                                        AND email NOT LIKE "no-email-%"
                                    )
                                    AND (phone IS NOT NULL AND phone != "")
                                )
                            ')->fetchColumn(),
                            'leads_missing_email' => $pdo->query('
                                SELECT COUNT(*) FROM crm_leads 
                                WHERE email IS NULL 
                                   OR email = ""
                                   OR email LIKE "sample-009@example.com"
                                   OR email LIKE "sample-010@example.com"
                                   OR email LIKE "lead+%"
                                   OR email LIKE "auto+%"
                                   OR email LIKE "no-email-%"
                            ')->fetchColumn(),
                            'leads_missing_phone' => $pdo->query('
                                SELECT COUNT(*) FROM crm_leads 
                                WHERE phone IS NULL OR phone = ""
                            ')->fetchColumn(),
                            'email_chains_count' => (function() use ($pdo) {
                                try {
                                    return $pdo->query('SELECT COUNT(*) FROM crm_lead_email_chains')->fetchColumn() ?: 0;
                                } catch (Exception $e) {
                                    return 0; // Table doesn't exist yet
                                }
                            })(),
                            'phone_chains_count' => (function() use ($pdo) {
                                try {
                                    return $pdo->query('SELECT COUNT(*) FROM crm_lead_phone_chains')->fetchColumn() ?: 0;
                                } catch (Exception $e) {
                                    return 0; // Table doesn't exist yet
                                }
                            })(),
                            'avg_days_to_email' => 0, // Will be populated after migration
                            'avg_days_to_phone' => 0, // Will be populated after migration
                            'avg_days_to_complete' => 0, // Will be populated after migration
                            'leads_detailed_today' => 0, // Will be populated after migration
                            'leads_detailed_week' => 0, // Will be populated after migration
                            'leads_detailed_month' => 0, // Will be populated after migration
                            // Inactive user stats
                            'inactive_users_7_days' => 0,
                            'inactive_users_pending_review' => 0,
                            'inactive_users_suspended' => 0,
                            'inactive_users_3_days' => 0,
                            'inactive_users_14_days' => 0,
                            'inactive_users_30_days' => 0
                        ];
                        
                        // Calculate conversion rates
                        $stats['conversion_rate_week'] = $stats['leads_new_week'] > 0 ? round(($stats['conversions_week'] / $stats['leads_new_week']) * 100, 1) : 0;
                        $stats['conversion_rate_month'] = $stats['leads_new_month'] > 0 ? round(($stats['conversions_month'] / $stats['leads_new_month']) * 100, 1) : 0;
                        
                        // Get inactive user statistics
                        try {
                            $inactive_stats = $pdo->query('
                                SELECT 
                                    SUM(CASE WHEN uit.days_inactive >= 7 THEN 1 ELSE 0 END) as inactive_7_days,
                                    SUM(CASE WHEN uit.pending_review = 1 THEN 1 ELSE 0 END) as pending_review,
                                    SUM(CASE WHEN uit.account_suspended = 1 THEN 1 ELSE 0 END) as suspended,
                                    SUM(CASE WHEN uit.days_inactive >= 3 AND uit.days_inactive < 7 THEN 1 ELSE 0 END) as inactive_3_days,
                                    SUM(CASE WHEN uit.days_inactive >= 14 AND uit.days_inactive < 30 THEN 1 ELSE 0 END) as inactive_14_days,
                                    SUM(CASE WHEN uit.days_inactive >= 30 AND uit.days_inactive < 35 THEN 1 ELSE 0 END) as inactive_30_days
                                FROM user_inactive_tracking uit
                                JOIN users u ON u.id = uit.user_id
                                JOIN user_roles ur ON ur.user_id = u.id
                                JOIN roles r ON r.id = ur.role_id
                                WHERE r.name IN ("trainee", "sales_agent")
                            ')->fetch(PDO::FETCH_ASSOC);
                            
                            if ($inactive_stats) {
                                $stats['inactive_users_7_days'] = intval($inactive_stats['inactive_7_days'] ?? 0);
                                $stats['inactive_users_pending_review'] = intval($inactive_stats['pending_review'] ?? 0);
                                $stats['inactive_users_suspended'] = intval($inactive_stats['suspended'] ?? 0);
                                $stats['inactive_users_3_days'] = intval($inactive_stats['inactive_3_days'] ?? 0);
                                $stats['inactive_users_14_days'] = intval($inactive_stats['inactive_14_days'] ?? 0);
                                $stats['inactive_users_30_days'] = intval($inactive_stats['inactive_30_days'] ?? 0);
                            }
                        } catch (Exception $e) {
                            // Inactive user tracking may not be set up yet
                        }
                        
                        // Get per-agent lead stats
                        $agentLeadStats = $pdo->query('
                            SELECT 
                                u.id,
                                CONCAT(u.first_name, " ", u.last_name) AS name,
                                COUNT(l.id) AS total_leads,
                                SUM(CASE WHEN DATE(l.created_at) = CURDATE() THEN 1 ELSE 0 END) AS leads_today,
                                SUM(CASE WHEN l.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS leads_week,
                                SUM(CASE WHEN l.lead_status IN ("new", "contacted", "qualified", "proposal_sent", "negotiating") THEN 1 ELSE 0 END) AS active_leads,
                                SUM(CASE WHEN l.converted_to_account_id IS NOT NULL THEN 1 ELSE 0 END) AS total_conversions,
                                SUM(CASE WHEN DATE(l.converted_at) = CURDATE() THEN 1 ELSE 0 END) AS conversions_today,
                                SUM(CASE WHEN l.converted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS conversions_week,
                                SUM(CASE WHEN l.converted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS conversions_month,
                                -- Detailed leads: has valid email AND has phone
                                SUM(CASE WHEN 
                                    (
                                        l.email IS NOT NULL 
                                        AND l.email != ""
                                        AND l.email NOT LIKE "sample-009@example.com"
                                        AND l.email NOT LIKE "sample-010@example.com"
                                        AND l.email NOT LIKE "lead+%"
                                        AND l.email NOT LIKE "auto+%"
                                        AND l.email NOT LIKE "no-email-%"
                                    )
                                    AND (l.phone IS NOT NULL AND l.phone != "")
                                THEN 1 ELSE 0 END) AS detailed_leads,
                                -- Incomplete leads: missing valid email OR missing phone
                                SUM(CASE WHEN NOT (
                                    (
                                        l.email IS NOT NULL 
                                        AND l.email != ""
                                        AND l.email NOT LIKE "sample-009@example.com"
                                        AND l.email NOT LIKE "sample-010@example.com"
                                        AND l.email NOT LIKE "lead+%"
                                        AND l.email NOT LIKE "auto+%"
                                        AND l.email NOT LIKE "no-email-%"
                                    )
                                    AND (l.phone IS NOT NULL AND l.phone != "")
                                ) THEN 1 ELSE 0 END) AS incomplete_leads,
                                -- Missing email: no valid email
                                SUM(CASE WHEN (
                                    l.email IS NULL 
                                    OR l.email = ""
                                    OR l.email LIKE "sample-009@example.com"
                                    OR l.email LIKE "sample-010@example.com"
                                    OR l.email LIKE "lead+%"
                                    OR l.email LIKE "auto+%"
                                    OR l.email LIKE "no-email-%"
                                ) THEN 1 ELSE 0 END) AS missing_email,
                                -- Missing phone: no phone
                                SUM(CASE WHEN (l.phone IS NULL OR l.phone = "") THEN 1 ELSE 0 END) AS missing_phone
                            FROM users u
                            JOIN user_roles ur ON ur.user_id = u.id
                            LEFT JOIN crm_leads l ON l.assigned_agent_id = u.id
                            WHERE ur.role_id = 2
                            GROUP BY u.id, name
                            ORDER BY total_leads DESC
                        ')->fetchAll(PDO::FETCH_ASSOC);
                        
                        // Get lead status distribution
                        $leadsByStatus = $pdo->query('
                            SELECT lead_status, COUNT(*) as count
                            FROM crm_leads
                            GROUP BY lead_status
                            ORDER BY 
                                CASE lead_status
                                    WHEN "new" THEN 1
                                    WHEN "contacted" THEN 2
                                    WHEN "qualified" THEN 3
                                    WHEN "proposal_sent" THEN 4
                                    WHEN "negotiating" THEN 5
                                    WHEN "won" THEN 6
                                    WHEN "lost" THEN 7
                                    WHEN "dormant" THEN 8
                                END
                        ')->fetchAll(PDO::FETCH_ASSOC);
                        
                        // Get conversion rate
                        $totalTrainees = $stats['trainees'] + $stats['agents'];
                        $conversionRate = $totalTrainees > 0 ? round(($stats['agents'] / $totalTrainees) * 100, 1) : 0;
                    ?>
                    <!-- Stats Grid -->
                    <div class="adm-stat-tiles" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 24px;">
                        <div style="background: linear-gradient(135deg, #f0fdf4, #dcfce7); padding: 16px; border-radius: 8px; text-align: center;">
                            <div style="font-size: 28px; font-weight: bold; color: #059669;"><?php echo intval($stats['users']); ?></div>
                            <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">Total Users</div>
                        </div>
                        <div style="background: linear-gradient(135deg, #fef3c7, #fed7aa); padding: 16px; border-radius: 8px; text-align: center;">
                            <div style="font-size: 28px; font-weight: bold; color: #d97706;"><?php echo intval($stats['wholesale_approved']); ?></div>
                            <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">Active Accounts</div>
                            <div style="color: #92400e; font-size: 11px;"><?php echo intval($stats['wholesale_pending']); ?> pending</div>
                        </div>
                        <div style="background: linear-gradient(135deg, #dbeafe, #bfdbfe); padding: 16px; border-radius: 8px; text-align: center;">
                            <div style="font-size: 28px; font-weight: bold; color: #2563eb;"><?php echo intval($stats['agents']); ?></div>
                            <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">Sales Agents</div>
                            <div style="color: #1e40af; font-size: 11px;"><?php echo $conversionRate; ?>% trainee conversion</div>
                        </div>
                        <div style="background: linear-gradient(135deg, #f3e8ff, #e9d5ff); padding: 16px; border-radius: 8px; text-align: center;">
                            <div style="font-size: 28px; font-weight: bold; color: #7c3aed;"><?php echo intval($stats['trainees']); ?></div>
                            <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">In Training</div>
                            <div style="color: #6b21a8; font-size: 11px;"><?php echo intval($stats['active_training']); ?> active now</div>
                        </div>
                        <div style="background: linear-gradient(135deg, #fce7f3, #fbcfe8); padding: 16px; border-radius: 8px; text-align: center;">
                            <div style="font-size: 28px; font-weight: bold; color: #be185d;"><?php echo intval($stats['leads_total']); ?></div>
                            <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">Total Leads</div>
                            <div style="color: #831843; font-size: 11px;"><?php echo intval($stats['leads_active']); ?> active</div>
                        </div>
                        <div style="background: linear-gradient(135deg, #dcfce7, #bbf7d0); padding: 16px; border-radius: 8px; text-align: center;">
                            <div style="font-size: 28px; font-weight: bold; color: #16a34a;"><?php echo intval($stats['leads_new_today']); ?></div>
                            <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">Leads Today</div>
                            <div style="color: #166534; font-size: 11px;"><?php echo intval($stats['leads_new_week']); ?> this week</div>
                        </div>
                        <div style="background: linear-gradient(135deg, #fef3c7, #fde68a); padding: 16px; border-radius: 8px; text-align: center;">
                            <div style="font-size: 28px; font-weight: bold; color: #d97706;"><?php echo intval($stats['inactive_users_7_days']); ?></div>
                            <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">Inactive 7+ Days</div>
                            <div style="color: #92400e; font-size: 11px;"><?php echo intval($stats['inactive_users_pending_review']); ?> pending review</div>
                        </div>
                        <div style="background: linear-gradient(135deg, #fee2e2, #fecaca); padding: 16px; border-radius: 8px; text-align: center;">
                            <div style="font-size: 28px; font-weight: bold; color: #dc2626;"><?php echo intval($stats['inactive_users_suspended']); ?></div>
                            <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">Suspended</div>
                            <div style="color: #991b1b; font-size: 11px;">35+ days inactive</div>
                        </div>
                    </div>
                    
                    <!-- Lead Conversions Stats -->
                    <div style="background: linear-gradient(135deg, #eff6ff, #dbeafe); padding: 20px; border-radius: 12px; border: 1px solid #bfdbfe; margin-bottom: 24px;">
                        <h3 style="margin: 0 0 16px 0; color: #1e40af; display: flex; align-items: center; gap: 8px;">🎯 Lead Conversions</h3>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px;">
                            <div>
                                <div style="font-size: 24px; font-weight: bold; color: #2563eb;"><?php echo intval($stats['conversions_today']); ?></div>
                                <div style="color: #6b7280; font-size: 12px;">Today</div>
                            </div>
                            <div>
                                <div style="font-size: 24px; font-weight: bold; color: #2563eb;"><?php echo intval($stats['conversions_week']); ?></div>
                                <div style="color: #6b7280; font-size: 12px;">This Week</div>
                                <div style="color: #1e40af; font-size: 11px; font-weight: 600; margin-top: 2px;"><?php echo $stats['conversion_rate_week']; ?>% rate</div>
                            </div>
                            <div>
                                <div style="font-size: 24px; font-weight: bold; color: #2563eb;"><?php echo intval($stats['conversions_month']); ?></div>
                                <div style="color: #6b7280; font-size: 12px;">This Month</div>
                                <div style="color: #1e40af; font-size: 11px; font-weight: 600; margin-top: 2px;"><?php echo $stats['conversion_rate_month']; ?>% rate</div>
                            </div>
                            <div>
                                <div style="font-size: 24px; font-weight: bold; color: #16a34a;"><?php echo intval($stats['leads_active']); ?></div>
                                <div style="color: #6b7280; font-size: 12px;">Active Pipeline</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Lead Detail Quality Metrics -->
                    <div style="background: linear-gradient(135deg, #fff7ed, #ffedd5); padding: 20px; border-radius: 12px; border: 1px solid #fdba74; margin-bottom: 24px;">
                        <h3 style="margin: 0 0 16px 0; color: #9a3412; display: flex; align-items: center; gap: 8px;">📊 Lead Detail Quality</h3>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 16px; margin-bottom: 16px;">
                            <div>
                                <div style="font-size: 24px; font-weight: bold; color: #16a34a;"><?php echo intval($stats['leads_detailed']); ?></div>
                                <div style="color: #6b7280; font-size: 12px;">Detailed Leads</div>
                                <div style="color: #166534; font-size: 11px; font-weight: 600; margin-top: 2px;"><?php echo intval($stats['leads_total']) > 0 ? round(($stats['leads_detailed'] / $stats['leads_total']) * 100, 1) : 0; ?>%</div>
                            </div>
                            <div>
                                <div style="font-size: 24px; font-weight: bold; color: #dc2626;"><?php echo intval($stats['leads_incomplete']); ?></div>
                                <div style="color: #6b7280; font-size: 12px;">Incomplete</div>
                                <div style="color: #991b1b; font-size: 11px;">Missing Info</div>
                            </div>
                            <div>
                                <div style="font-size: 20px; font-weight: bold; color: #ea580c;"><?php echo intval($stats['leads_missing_email']); ?></div>
                                <div style="color: #6b7280; font-size: 12px;">Missing Email</div>
                            </div>
                            <div>
                                <div style="font-size: 20px; font-weight: bold; color: #ea580c;"><?php echo intval($stats['leads_missing_phone']); ?></div>
                                <div style="color: #6b7280; font-size: 12px;">Missing Phone</div>
                            </div>
                            <div>
                                <div style="font-size: 20px; font-weight: bold; color: #2563eb;">⛓️ <?php echo intval($stats['email_chains_count']); ?></div>
                                <div style="color: #6b7280; font-size: 12px;">Email Chains</div>
                            </div>
                            <div>
                                <div style="font-size: 20px; font-weight: bold; color: #2563eb;">⛓️ <?php echo intval($stats['phone_chains_count']); ?></div>
                                <div style="color: #6b7280; font-size: 12px;">Phone Chains</div>
                            </div>
                        </div>
                        <div style="border-top: 1px solid #fdba74; padding-top: 12px; margin-top: 12px;">
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
                                <div>
                                    <div style="font-size: 16px; font-weight: bold; color: #9a3412;"><?php echo $stats['avg_days_to_email']; ?> days</div>
                                    <div style="color: #6b7280; font-size: 11px;">Avg Time to Email</div>
                                </div>
                                <div>
                                    <div style="font-size: 16px; font-weight: bold; color: #9a3412;"><?php echo $stats['avg_days_to_phone']; ?> days</div>
                                    <div style="color: #6b7280; font-size: 11px;">Avg Time to Phone</div>
                                </div>
                                <div>
                                    <div style="font-size: 16px; font-weight: bold; color: #9a3412;"><?php echo $stats['avg_days_to_complete']; ?> days</div>
                                    <div style="color: #6b7280; font-size: 11px;">Avg Time to Full Detail</div>
                                </div>
                                <div>
                                    <div style="font-size: 16px; font-weight: bold; color: #16a34a;"><?php echo intval($stats['leads_detailed_today']); ?> / <?php echo intval($stats['leads_detailed_week']); ?> / <?php echo intval($stats['leads_detailed_month']); ?></div>
                                    <div style="color: #6b7280; font-size: 11px;">Detailed Today / Week / Month</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Revenue & Orders -->
                    <div class="kpi-row">
                        <div style="background: linear-gradient(135deg, #ecfdf5, #d1fae5); padding: 20px; border-radius: 8px; border-left: 4px solid #10b981;">
                            <h3 style="margin: 0 0 8px 0; color: #065f46;">💰 Revenue</h3>
                            <?php
                            $start = $_GET['start'] ?? date('Y-m-d', strtotime('-7 days'));
                            $end   = $_GET['end'] ?? date('Y-m-d');
                            // Compute revenue current window
                            $stmt = $pdo->prepare('SELECT COALESCE(SUM(total),0) FROM orders WHERE status <> "cancelled" AND DATE(created_at) BETWEEN ? AND ?');
                            $stmt->execute([$start, $end]);
                            $rev = (float)$stmt->fetchColumn();
                            // Previous comparable window
                            $days = max(1, (int)round((strtotime($end) - strtotime($start)) / 86400) + 1);
                            $prevStart = date('Y-m-d', strtotime($start . " -{$days} days"));
                            $prevEnd   = date('Y-m-d', strtotime($start . ' -1 day'));
                            $stmt = $pdo->prepare('SELECT COALESCE(SUM(total),0) FROM orders WHERE status <> "cancelled" AND DATE(created_at) BETWEEN ? AND ?');
                            $stmt->execute([$prevStart, $prevEnd]);
                            $prevRev = (float)$stmt->fetchColumn();
                            $delta = $prevRev > 0 ? round((($rev - $prevRev) / $prevRev) * 100, 1) : 100.0;
                            // Series for simple sparkline
                            $seriesStmt = $pdo->prepare('SELECT DATE(created_at) d, COALESCE(SUM(total),0) v FROM orders WHERE status <> "cancelled" AND DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY d');
                            $seriesStmt->execute([$start, $end]);
                            $points = $seriesStmt->fetchAll(PDO::FETCH_ASSOC);
                            $values = array_map(function($r){ return (float)$r['v']; }, $points);
                            $labels = array_map(function($r){ return $r['d']; }, $points);
                            $maxV = max(1, (float)max($values ?: [0]));
                            $w = 220; $h = 40; $n = max(1, count($values));
                            $step = $n > 1 ? ($w / ($n - 1)) : $w; $path = '';
                            foreach ($values as $i => $val) {
                                $x = $i * $step;
                                $y = $h - (($val / $maxV) * $h);
                                $path .= ($i === 0 ? 'M' : ' L') . round($x,1) . ' ' . round($y,1);
                            }
                            ?>
                            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
                                <div style="font-size: 24px; font-weight: bold; color: #059669;">$<?php echo number_format($rev, 2); ?></div>
                                <div style="color: <?php echo $delta>=0 ? '#059669' : '#b91c1c'; ?>; font-size:12px; font-weight:600; background:rgba(0,0,0,0.04); padding:4px 6px; border-radius:6px;">
                                    <?php echo ($delta>=0?'+':''); echo $delta; ?>%
                                </div>
                            </div>
                            <svg width="100%" height="40" viewBox="0 0 <?php echo $w; ?> <?php echo $h; ?>" preserveAspectRatio="none" style="margin-top:6px;">
                                <path d="<?php echo $path; ?>" fill="none" stroke="#10b981" stroke-width="2" />
                            </svg>
                            <div style="color: #6b7280; font-size: 12px;">Filtered window (vs previous <?php echo $days; ?> days)</div>
                        </div>
                        <div style="background: linear-gradient(135deg, #fef2f2, #fecaca); padding: 20px; border-radius: 8px; border-left: 4px solid #ef4444;">
                            <h3 style="margin: 0 0 8px 0; color: #991b1b;">📦 Orders</h3>
                            <?php
                            $stmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE DATE(created_at) BETWEEN ? AND ?');
                            $stmt->execute([$start, $end]);
                            $ordersWindow = (int)$stmt->fetchColumn();
                            $stmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE DATE(created_at) BETWEEN ? AND ?');
                            $stmt->execute([$prevStart, $prevEnd]);
                            $ordersPrev = (int)$stmt->fetchColumn();
                            $ordersDelta = $ordersPrev > 0 ? round((($ordersWindow - $ordersPrev) / $ordersPrev) * 100, 1) : 100.0;
                            ?>
                            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
                                <div style="font-size: 24px; font-weight: bold; color: #dc2626;"><?php echo $ordersWindow; ?></div>
                                <div style="color: <?php echo $ordersDelta>=0 ? '#dc2626' : '#b91c1c'; ?>; font-size:12px; font-weight:600; background:rgba(0,0,0,0.04); padding:4px 6px; border-radius:6px;">
                                    <?php echo ($ordersDelta>=0?'+':''); echo $ordersDelta; ?>%
                                </div>
                            </div>
                            <div style="color: #6b7280; font-size: 12px;">Filtered window (vs previous <?php echo $days; ?> days)</div>
                        </div>
                    </div>
                    
                    <!-- Lead Status Distribution & Agent Performance -->
                    <div class="kpi-row" style="margin-bottom:20px;">
                        <div style="background: linear-gradient(135deg, #fdf4ff, #fae8ff); padding: 20px; border-radius: 8px; border-left: 4px solid #a855f7;">
                            <h3 style="margin: 0 0 12px 0; color: #6b21a8;">📊 Lead Pipeline Status</h3>
                            <?php if (!empty($leadsByStatus)): 
                                $maxCount = max(array_column($leadsByStatus, 'count'));
                                $statusColors = [
                                    'new' => '#3b82f6',
                                    'contacted' => '#8b5cf6',
                                    'qualified' => '#a855f7',
                                    'proposal_sent' => '#d946ef',
                                    'negotiating' => '#ec4899',
                                    'won' => '#10b981',
                                    'lost' => '#ef4444',
                                    'dormant' => '#6b7280'
                                ];
                                foreach ($leadsByStatus as $status): 
                                    $pct = $maxCount > 0 ? round(($status['count'] / $maxCount) * 100) : 0;
                                    $color = $statusColors[$status['lead_status']] ?? '#6b7280';
                            ?>
                                <div style="display:flex; align-items:center; gap:8px; margin:8px 0;">
                                    <div style="min-width:90px; font-weight:600; font-size:12px; color:#1f2937; text-transform:capitalize;">
                                        <?php echo str_replace('_', ' ', $status['lead_status']); ?>
                                    </div>
                                    <div style="flex:1; background:#e5e7eb; border-radius:9999px; height:10px; overflow:hidden;">
                                        <div style="width: <?php echo $pct; ?>%; height:10px; background:<?php echo $color; ?>;"></div>
                                    </div>
                                    <div style="min-width:40px; text-align:right; font-weight:700; font-size:13px; color:<?php echo $color; ?>;">
                                        <?php echo intval($status['count']); ?>
                                    </div>
                                </div>
                            <?php endforeach; else: ?>
                                <div style="color:#6b7280; text-align:center; padding:12px;">No leads yet</div>
                            <?php endif; ?>
                        </div>
                        <div style="background: linear-gradient(135deg, #fff7ed, #fed7aa); padding: 20px; border-radius: 8px; border-left: 4px solid #f59e0b;">
                            <h3 style="margin: 0 0 8px 0; color: #92400e;">💸 Commissions</h3>
                            <div style="font-size: 20px; font-weight: bold; color: #d97706; margin-bottom: 4px;">
                                $<?php echo number_format($stats['commissions_pending'], 2); ?>
                            </div>
                            <div style="color: #6b7280; font-size: 14px;">Pending payout</div>
                        </div>
                    </div>
                    
                    <!-- Agent Performance Table -->
                    <div style="background: white; padding: 20px; border-radius: 12px; border: 1px solid #e5e7eb; margin-bottom:20px;">
                        <h3 style="margin: 0 0 16px 0; color: #0f172a; display: flex; align-items: center; gap: 8px;">🏆 Agent Performance (Leads & Conversions)</h3>
                        <?php if (!empty($agentLeadStats)): ?>
                            <div style="overflow-x: auto;">
                                <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                                    <thead>
                                        <tr style="background: #f8fafc; border-bottom: 2px solid #e5e7eb;">
                                            <th style="padding: 10px; text-align: left; font-weight: 700; color: #374151;">Agent</th>
                                            <th style="padding: 10px; text-align: center; font-weight: 700; color: #374151;">Today</th>
                                            <th style="padding: 10px; text-align: center; font-weight: 700; color: #374151;">This Week</th>
                                            <th style="padding: 10px; text-align: center; font-weight: 700; color: #374151;">Active</th>
                                            <th style="padding: 10px; text-align: center; font-weight: 700; color: #374151;">Total</th>
                                            <th style="padding: 10px; text-align: center; font-weight: 700; color: #16a34a;" title="Leads with both email and phone">Detailed</th>
                                            <th style="padding: 10px; text-align: center; font-weight: 700; color: #dc2626;" title="Leads missing email or phone">Incomplete</th>
                                            <th style="padding: 10px; text-align: center; font-weight: 700; color: #ea580c;" title="Leads missing email">No Email</th>
                                            <th style="padding: 10px; text-align: center; font-weight: 700; color: #ea580c;" title="Leads missing phone">No Phone</th>
                                            <th style="padding: 10px; text-align: center; font-weight: 700; color: #16a34a;">Conv. Today</th>
                                            <th style="padding: 10px; text-align: center; font-weight: 700; color: #16a34a;">Conv. Week</th>
                                            <th style="padding: 10px; text-align: center; font-weight: 700; color: #16a34a;">Conv. Month</th>
                                            <th style="padding: 10px; text-align: center; font-weight: 700; color: #2563eb;">Rate</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($agentLeadStats as $agent): 
                                            $convRate = $agent['total_leads'] > 0 ? round(($agent['total_conversions'] / $agent['total_leads']) * 100, 1) : 0;
                                            $detailRate = $agent['total_leads'] > 0 ? round(($agent['detailed_leads'] / $agent['total_leads']) * 100, 1) : 0;
                                        ?>
                                            <tr style="border-bottom: 1px solid #f3f4f6; transition: background 0.15s;" onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='transparent'">
                                                <td style="padding: 12px; font-weight: 600; color: #1f2937;"><?php echo htmlspecialchars($agent['name']); ?></td>
                                                <td style="padding: 12px; text-align: center;">
                                                    <span style="display: inline-block; padding: 4px 10px; border-radius: 6px; background: <?php echo $agent['leads_today'] > 0 ? '#dcfce7' : '#f3f4f6'; ?>; color: <?php echo $agent['leads_today'] > 0 ? '#166534' : '#6b7280'; ?>; font-weight: 600;">
                                                        <?php echo intval($agent['leads_today']); ?>
                                                    </span>
                                                </td>
                                                <td style="padding: 12px; text-align: center; font-weight: 600; color: #374151;"><?php echo intval($agent['leads_week']); ?></td>
                                                <td style="padding: 12px; text-align: center; font-weight: 600; color: #7c3aed;"><?php echo intval($agent['active_leads']); ?></td>
                                                <td style="padding: 12px; text-align: center; font-weight: 600; color: #6b7280;"><?php echo intval($agent['total_leads']); ?></td>
                                                <td style="padding: 12px; text-align: center;">
                                                    <span style="display: inline-block; padding: 4px 10px; border-radius: 6px; background: <?php echo $detailRate >= 80 ? '#d1fae5' : ($detailRate >= 50 ? '#fef3c7' : '#fee2e2'); ?>; color: <?php echo $detailRate >= 80 ? '#065f46' : ($detailRate >= 50 ? '#92400e' : '#991b1b'); ?>; font-weight: 700;">
                                                        <?php echo intval($agent['detailed_leads']); ?>
                                                    </span>
                                                </td>
                                                <td style="padding: 12px; text-align: center; font-weight: 600; color: <?php echo $agent['incomplete_leads'] > 0 ? '#dc2626' : '#6b7280'; ?>;"><?php echo intval($agent['incomplete_leads']); ?></td>
                                                <td style="padding: 12px; text-align: center; font-weight: 600; color: <?php echo $agent['missing_email'] > 0 ? '#ea580c' : '#6b7280'; ?>;"><?php echo intval($agent['missing_email']); ?></td>
                                                <td style="padding: 12px; text-align: center; font-weight: 600; color: <?php echo $agent['missing_phone'] > 0 ? '#ea580c' : '#6b7280'; ?>;"><?php echo intval($agent['missing_phone']); ?></td>
                                                <td style="padding: 12px; text-align: center;">
                                                    <span style="display: inline-block; padding: 4px 10px; border-radius: 6px; background: <?php echo $agent['conversions_today'] > 0 ? '#d1fae5' : '#f3f4f6'; ?>; color: <?php echo $agent['conversions_today'] > 0 ? '#065f46' : '#6b7280'; ?>; font-weight: 700;">
                                                        <?php echo intval($agent['conversions_today']); ?>
                                                    </span>
                                                </td>
                                                <td style="padding: 12px; text-align: center; font-weight: 700; color: #059669;"><?php echo intval($agent['conversions_week']); ?></td>
                                                <td style="padding: 12px; text-align: center; font-weight: 700; color: #10b981;"><?php echo intval($agent['conversions_month']); ?></td>
                                                <td style="padding: 12px; text-align: center;">
                                                    <span style="display: inline-block; padding: 4px 10px; border-radius: 6px; background: <?php echo $convRate >= 20 ? '#dbeafe' : ($convRate >= 10 ? '#fef3c7' : '#fee2e2'); ?>; color: <?php echo $convRate >= 20 ? '#1e40af' : ($convRate >= 10 ? '#92400e' : '#991b1b'); ?>; font-weight: 700;">
                                                        <?php echo $convRate; ?>%
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div style="text-align: center; padding: 24px; color: #6b7280;">
                                No sales agents with leads yet. Leads will appear here once assigned to agents.
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Training & Other Stats -->
                    <div class="kpi-row" style="margin-bottom:0;">
                        <div style="background: linear-gradient(135deg, #f0f9ff, #e0f2fe); padding: 20px; border-radius: 8px; border-left: 4px solid #0ea5e9;">
                            <h3 style="margin: 0 0 8px 0; color: #0c4a6e;">📚 Training Activity</h3>
                            <div style="font-size: 20px; font-weight: bold; color: #0284c7; margin-bottom: 4px;">
                                <?php echo intval($stats['completed_training']); ?> completed
                            </div>
                            <div style="color: #6b7280; font-size: 14px;">This week</div>
                            <div style="color: #0284c7; font-size: 12px; margin-top: 4px;">
                                <?php echo intval($stats['quiz_attempts_today']); ?> quiz attempts today
                            </div>
                        </div>
                        <div style="background: linear-gradient(135deg, #f0fdf4, #dcfce7); padding: 20px; border-radius: 8px; border-left: 4px solid #10b981;">
                            <h3 style="margin: 0 0 8px 0; color: #065f46;">✅ System Health</h3>
                            <div style="font-size: 14px; color: #059669; margin-bottom: 8px;">
                                <div style="display: flex; justify-content: space-between; margin: 4px 0;">
                                    <span>Database:</span>
                                    <span style="font-weight: 700;">Connected</span>
                                </div>
                                <div style="display: flex; justify-content: space-between; margin: 4px 0;">
                                    <span>Active Agents:</span>
                                    <span style="font-weight: 700;"><?php echo intval($stats['agents']); ?></span>
                                </div>
                                <div style="display: flex; justify-content: space-between; margin: 4px 0;">
                                    <span>Products:</span>
                                    <span style="font-weight: 700;"><?php echo intval($stats['products']); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php } catch (Throwable $e) { ?>
                        <p style="color: #8a1f1f;">Unable to load statistics. Please check database connection.</p>
                    <?php } ?>
                </div>
                
                <!-- Insights -->
                <div class="card adm-card" style="margin-top: 16px;">
                    <h2 style="color:#0f172a; margin-bottom: 12px;">🔎 Insights</h2>
                    <?php try { 
                        $start = $_GET['start'] ?? date('Y-m-d', strtotime('-7 days'));
                        $end   = $_GET['end'] ?? date('Y-m-d');
                        // Top states by revenue
                        $stmt = $pdo->prepare('
                            SELECT COALESCE(wa.shipping_state, wa.state_region, "Unknown") AS state,
                                   COUNT(o.id) AS orders_count,
                                   COALESCE(SUM(CASE WHEN o.status <> "cancelled" THEN o.total ELSE 0 END),0) AS revenue
                            FROM orders o
                            LEFT JOIN wholesale_accounts wa ON wa.id = o.wholesale_account_id
                            WHERE DATE(o.created_at) BETWEEN ? AND ?
                            GROUP BY COALESCE(wa.shipping_state, wa.state_region)
                            ORDER BY revenue DESC
                            LIMIT 5');
                        $stmt->execute([$start, $end]);
                        $topStates = $stmt->fetchAll();

                        // Top sales agents by revenue
                        $stmt = $pdo->prepare('
                            SELECT u.id, CONCAT(u.first_name, " ", u.last_name) AS name,
                                   COUNT(o.id) AS orders_count,
                                   COALESCE(SUM(CASE WHEN o.status <> "cancelled" THEN o.total ELSE 0 END),0) AS revenue
                            FROM orders o
                            JOIN users u ON u.id = o.sales_agent_id
                            WHERE o.sales_agent_id IS NOT NULL AND DATE(o.created_at) BETWEEN ? AND ?
                            GROUP BY u.id, name
                            ORDER BY revenue DESC
                            LIMIT 5');
                        $stmt->execute([$start, $end]);
                        $topAgents = $stmt->fetchAll();

                        // Orders pending (broad definition to capture drafts/submitted)
                        $pendingStatuses = [ 'pending','processing','draft','submitted' ];
                        $in = implode(',', array_fill(0, count($pendingStatuses), '?'));
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status IN ($in)");
                        $stmt->execute($pendingStatuses);
                        $ordersPending = (int)$stmt->fetchColumn();

                        // Recent graduates (last 14 days)
                        $stmt = $pdo->prepare('
                            SELECT u.first_name, u.last_name, tp.completed_at
                            FROM training_progress tp
                            JOIN users u ON u.id = tp.user_id
                            WHERE tp.status = "completed" AND tp.completed_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                            ORDER BY tp.completed_at DESC
                            LIMIT 6');
                        $stmt->execute();
                        $recentGrads = $stmt->fetchAll();
                        
                        // Helpers for bars
                        $stateMax = 0; foreach ($topStates as $s) { $stateMax = max($stateMax, (float)$s['revenue']); }
                        $agentMax = 0; foreach ($topAgents as $a) { $agentMax = max($agentMax, (float)$a['revenue']); }
                    ?>
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:16px;">
                        <div>
                            <h3 style="margin:0 0 8px 0; color:#065f46;">🗺️ Sales by State</h3>
                            <?php if (!empty($topStates)): foreach ($topStates as $row): 
                                $pct = $stateMax > 0 ? round(((float)$row['revenue'] / $stateMax) * 100) : 0; ?>
                                <div style="display:flex; align-items:center; gap:8px; margin:6px 0;">
                                    <div style="min-width:36px; font-weight:600; color:#0f172a;"><?php echo htmlspecialchars($row['state'] ?: '—'); ?></div>
                                    <div style="flex:1; background:#e5e7eb; border-radius:9999px; height:8px; overflow:hidden;">
                                        <div style="width: <?php echo $pct; ?>%; height:8px; background:#10b981;"></div>
                                    </div>
                                    <div style="min-width:80px; text-align:right; color:#065f46; font-size:12px;">$<?php echo number_format($row['revenue'],0); ?></div>
                                </div>
                            <?php endforeach; else: ?>
                                <div style="color:#6b7280;">No orders in this window.</div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h3 style="margin:0 0 8px 0; color:#1f2937;">🏅 Top Sales Agents</h3>
                            <?php if (!empty($topAgents)): foreach ($topAgents as $row): 
                                $pct = $agentMax > 0 ? round(((float)$row['revenue'] / $agentMax) * 100) : 0; ?>
                                <div style="display:flex; align-items:center; gap:8px; margin:6px 0;">
                                    <div style="width:28px; height:28px; border-radius:9999px; background:#f3f4f6; color:#111827; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:600;">
                                        <?php $parts = explode(' ', $row['name']); echo strtoupper(substr($parts[0]??'',0,1) . substr($parts[1]??'',0,1)); ?>
                                    </div>
                                    <div style="flex:1;">
                                        <div style="font-weight:600; color:#111827;"><?php echo htmlspecialchars($row['name']); ?></div>
                                        <div style="background:#e5e7eb; border-radius:9999px; height:8px; overflow:hidden;">
                                            <div style="width: <?php echo $pct; ?>%; height:8px; background:#8b5cf6;"></div>
                                        </div>
                                    </div>
                                    <div style="min-width:90px; text-align:right; color:#111827; font-size:12px;">$<?php echo number_format($row['revenue'],0); ?></div>
                                </div>
                            <?php endforeach; else: ?>
                                <div style="color:#6b7280;">No agent sales in this window.</div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h3 style="margin:0 0 8px 0; color:#991b1b;">⏳ Orders Pending</h3>
                            <div style="font-size:28px; font-weight:700; color:#dc2626;"><?php echo $ordersPending; ?></div>
                            <div style="margin-top:6px;"><a href="<?php echo path_with_base('/admin/orders.php'); ?>" style="color:#991b1b; font-size:12px; text-decoration:none;">View orders →</a></div>
                        </div>
                        <div>
                            <h3 style="margin:0 0 8px 0; color:#0c4a6e;">🎓 Recent Graduates</h3>
                            <?php if (!empty($recentGrads)): foreach ($recentGrads as $g): ?>
                                <div style="display:flex; justify-content:space-between; gap:8px; padding:6px 0; border-bottom:1px solid #f3f4f6;">
                                    <div style="color:#0c4a6e; font-weight:600;">
                                        <?php echo htmlspecialchars($g['first_name'] . ' ' . $g['last_name']); ?>
                                    </div>
                                    <div style="color:#6b7280; font-size:12px;">
                                        <?php echo date('M j, g:i a', strtotime($g['completed_at'])); ?>
                                    </div>
                                </div>
                            <?php endforeach; else: ?>
                                <div style="color:#6b7280;">No completions in the last 14 days.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php } catch (Throwable $e) { ?>
                        <p style="color:#dc2626;">Unable to load insights.</p>
                    <?php } ?>
                </div>

                <!-- Realtime Activity -->
                <div class="card adm-card" style="margin-top: 16px;">
                    <h2 style="color:#0f172a; margin-bottom: 12px;">⚡ Realtime Activity</h2>
                    <div id="realtime-widgets" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:16px;">
                        <div>
                            <h3 style="margin:0 0 8px 0; color:#065f46;">🟢 Live Users</h3>
                            <div style="display:flex; gap:12px; align-items:center;">
                                <div style="font-size:28px; font-weight:800; color:#065f46;" id="live-users">0</div>
                                <div style="color:#6b7280;">users online</div>
                            </div>
                            <svg id="live-users-spark" width="100%" height="40" viewBox="0 0 240 40" preserveAspectRatio="none" style="margin-top:6px;"><path d="M0 40" fill="none" stroke="#10b981" stroke-width="2"/></svg>
                            <div style="color:#6b7280; font-size:12px; margin-top:4px;">Includes guests with activity in last 2 minutes</div>
                        </div>
                        <div>
                            <h3 style="margin:0 0 8px 0; color:#1f2937;">🏃 Top Active (5)</h3>
                            <div id="top-active" style="display:flex; flex-direction:column; gap:6px;"></div>
                        </div>
                        <div>
                            <h3 style="margin:0 0 8px 0; color:#0f172a;">📍 Top Pages (10m)</h3>
                            <div id="top-pages" style="display:flex; flex-direction:column; gap:6px;"></div>
                        </div>
                        <div>
                            <h3 style="margin:0 0 8px 0; color:#111827;">⏱️ Usage Snapshot (7d)</h3>
                            <div id="usage-snapshot" style="display:flex; flex-direction:column; gap:6px;"></div>
                        </div>
                        <div>
                            <h3 style="margin:0 0 8px 0; color:#0c4a6e;">🔐 Last Logins</h3>
                            <div id="last-logins" style="display:flex; flex-direction:column; gap:6px;"></div>
                            <h3 style="margin:12px 0 8px 0; color:#991b1b;">🚪 Last Logouts</h3>
                            <div id="last-logouts" style="display:flex; flex-direction:column; gap:6px;"></div>
                        </div>
                        <div>
                            <h3 style="margin:0 0 8px 0; color:#92400e;">❓ Forgot Password (5)</h3>
                            <div id="last-forgot" style="display:flex; flex-direction:column; gap:6px;"></div>
                            <h3 style="margin:12px 0 8px 0; color:#374151;">🕷️ Crawler Activity</h3>
                            <svg id="crawler-spark" width="100%" height="40" viewBox="0 0 240 40" preserveAspectRatio="none" style="margin-bottom:6px;"><path d="M0 40" fill="none" stroke="#6b7280" stroke-width="2"/></svg>
                            <div id="crawler-top" style="display:flex; flex-direction:column; gap:6px;"></div>
                        </div>
                    </div>
                    <script>
                    (function(){
                        const liveSeries = [];
                        const crawlerSeriesPts = [];
                        function drawSpark(elId, values, color){
                            const svg = document.getElementById(elId);
                            if(!svg) return;
                            const w = 240, h = 40; const n = Math.max(1, values.length);
                            const maxV = Math.max(1, Math.max.apply(null, values.concat([1])));
                            const step = n > 1 ? (w / (n-1)) : w; let d = '';
                            values.forEach((v,i)=>{ const x=i*step; const y=h - (v/maxV)*h; d += (i===0?'M':' L')+x+' '+y; });
                            svg.querySelector('path').setAttribute('d', d || 'M0 40');
                            svg.querySelector('path').setAttribute('stroke', color);
                        }
                        async function fetchRealtime(){
                            try{
                                const res = await fetch('/api/admin-realtime.php', {
                                    headers: {'Accept':'application/json'},
                                    credentials: 'same-origin'
                                });
                                if(!res.ok) {
                                    console.error('Realtime API error:', res.status, res.statusText);
                                    return;
                                }
                                const data = await res.json();
                                console.log('Realtime data received:', data);
                                const liveUsers = document.getElementById('live-users');
                                const topActive = document.getElementById('top-active');
                                const lastLogins = document.getElementById('last-logins');
                                const lastLogouts = document.getElementById('last-logouts');
                                const lastForgot = document.getElementById('last-forgot');
                                const crawlerTop = document.getElementById('crawler-top');
                                const topPages = document.getElementById('top-pages');

                                if (data.online) {
                                    const u = (data.online.users || 0);
                                    liveUsers.textContent = u.toString();
                                    liveSeries.push(u); if (liveSeries.length > 30) liveSeries.shift();
                                    drawSpark('live-users-spark', liveSeries, '#10b981');
                                }
                                function escapeHtml(s){
                                    const d=document.createElement('div'); d.textContent = s; return d.innerHTML;
                                }
                                function renderPeople(list){
                                    return (list||[]).map(x=>{
                                        const name = (x.first_name||'') + ' ' + (x.last_name||'');
                                        const ts = x.created_at ? new Date(x.created_at).toLocaleString() : '';
                                        return `<div style="display:flex; justify-content:space-between; border-bottom:1px solid #f3f4f6; padding:4px 0;">
                                            <div style="font-weight:600; color:#111827;">${escapeHtml(name.trim()||x.email||'User')}</div>
                                            <div style="font-size:12px; color:#6b7280;">${escapeHtml(ts)}</div>
                                        </div>`
                                    }).join('');
                                }
                                function renderTopActive(list){
                                    return (list||[]).map(x=>{
                                        const label = x.user_id ? ('User #' + x.user_id) : 'Guest';
                                        const pct = Math.min(100, Math.max(5, Math.round((x.score||1)/5*10)));
                                        return `<div>
                                            <div style="display:flex; justify-content:space-between; font-size:12px; color:#111827;"><span>${escapeHtml(label)}</span><span>${x.score||1}</span></div>
                                            <div style="background:#e5e7eb; border-radius:9999px; height:8px; overflow:hidden;"><div style="width:${pct}%; height:8px; background:#8b5cf6;"></div></div>
                                        </div>`
                                    }).join('');
                                }
                                function renderCrawlerTop(list){
                                    return (list||[]).map(x=>{
                                        return `<div style="display:flex; justify-content:space-between; font-size:12px; color:#374151; border-bottom:1px solid #f3f4f6; padding:4px 0;"><span>${escapeHtml(x.bot_name||'bot')}</span><span>${x.cnt||0}</span></div>`
                                    }).join('');
                                }
                                function renderTopPages(list){
                                    return (list||[]).map(x=>{
                                        const engaged = Math.round((x.engaged_ms||0)/1000);
                                        const interactions = x.interactions||0;
                                        return `<div style="display:flex; flex-direction:column; gap:2px; padding:4px 0; border-bottom:1px solid #f3f4f6;">
                                            <div style="display:flex; justify-content:space-between; font-size:12px; color:#111827;">
                                                <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:70%;" title="${escapeHtml(x.path||'')}">${escapeHtml(x.path||'')}</span>
                                                <span>${engaged}s • ${interactions} evt</span>
                                            </div>
                                            <div style="background:#e5e7eb; border-radius:9999px; height:6px; overflow:hidden;">
                                                <div style="width:${Math.min(100, Math.max(5, Math.round((x.total_ms? (x.engaged_ms/x.total_ms):0)*100)))}%; height:6px; background:#10b981;"></div>
                                            </div>
                                        </div>`
                                    }).join('');
                                }

                                // Update all sections with fallbacks for empty data
                                topActive.innerHTML = renderTopActive(data.top_active) || '<div style="color:#9ca3af;font-size:12px;padding:8px;">No active users in last 5 minutes</div>';
                                lastLogins.innerHTML = renderPeople(data.last_logins) || '<div style="color:#9ca3af;font-size:12px;padding:8px;">No recent logins</div>';
                                lastLogouts.innerHTML = renderPeople(data.last_logouts) || '<div style="color:#9ca3af;font-size:12px;padding:8px;">No recent logouts</div>';
                                lastForgot.innerHTML = renderPeople(data.last_forgot) || '<div style="color:#9ca3af;font-size:12px;padding:8px;">No password reset requests</div>';
                                crawlerTop.innerHTML = renderCrawlerTop((data.crawler && data.crawler.top) || []) || '<div style="color:#9ca3af;font-size:12px;padding:8px;">No crawler activity</div>';
                                topPages.innerHTML = renderTopPages(((data.pages||{}).top) || []) || '<div style="color:#9ca3af;font-size:12px;padding:8px;">No page activity</div>';
                                if (data.crawler && data.crawler.series) {
                                    const vals = data.crawler.series.map(p=>parseInt(p.v||0,10));
                                    crawlerSeriesPts.length = 0; Array.prototype.push.apply(crawlerSeriesPts, vals.slice(-30));
                                    drawSpark('crawler-spark', crawlerSeriesPts, '#6b7280');
                                }
                            }catch(e){
                                console.error('Realtime fetch error:', e);
                            }
                        }
                        fetchRealtime();
                        setInterval(fetchRealtime, 15000); // 15s

                        async function fetchUsageSnapshot(){
                            try {
                                const res = await fetch('/api/admin-usage-analytics.php?days=7', {
                                    headers: {'Accept':'application/json'},
                                    credentials: 'same-origin'
                                });
                                if(!res.ok){
                                    console.error('Usage analytics error', res.status);
                                    return;
                                }
                                const data = await res.json();
                                renderUsageSnapshot(data);
                            } catch (e) {
                                console.error('Usage snapshot fetch error', e);
                            }
                        }

                        function renderUsageSnapshot(data){
                            const container = document.getElementById('usage-snapshot');
                            if (!container) return;
                            const users = data.users || {};
                            const time = data.time || [];
                            const attribution = data.lead_attribution || {};

                            const sorted = time.slice().sort((a,b) => (b.engaged_ms||0) - (a.engaged_ms||0)).slice(0,5);

                            container.innerHTML = sorted.map(entry => {
                                const user = users[entry.user_id] || {first_name:'User', last_name:'#'+entry.user_id};
                                const name = `${user.first_name || ''} ${user.last_name || ''}`.trim() || `User #${entry.user_id}`;
                                const engagedHours = ((entry.engaged_ms||0)/3600000).toFixed(2);
                                const leadCounts = attribution[entry.user_id] || {};
                                const manual = (leadCounts['manual:created']||0);
                                const nearby = (leadCounts['nearby_leads:created']||0) + (leadCounts['nearby_accounts:created']||0);
                                return `<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:8px 10px;display:flex;flex-direction:column;gap:4px;">
                                    <div style="font-weight:600;color:#0f172a;">${name}</div>
                                    <div style="font-size:12px;color:#4b5563;">Engaged ${engagedHours}h • Sessions ${entry.sessions||0}</div>
                                    <div style="font-size:12px;color:#2563eb;">Leads Manual ${manual} • Nearby ${nearby}</div>
                                </div>`;
                            }).join('') || '<div style="color:#9ca3af;font-size:12px;padding:8px;">No usage data in range</div>';
                        }

                        fetchUsageSnapshot();
                        setInterval(fetchUsageSnapshot, 60000);
                    })();
                    </script>
                </div>

                <!-- Sidebar -->
                <div>
                    <!-- Recent Activity -->
                    <div class="card adm-card" style="margin-bottom: 20px;">
                        <h2 style="color: #059669; margin-bottom: 16px;">🕒 Recent Activity</h2>
                        <?php
                        try {
                            // Get recent registrations
                            $recentUsers = $pdo->query('
                                SELECT u.first_name, u.last_name, u.created_at, r.name as role_name
                                FROM users u 
                                JOIN user_roles ur ON ur.user_id = u.id 
                                JOIN roles r ON r.id = ur.role_id 
                                WHERE u.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                                ORDER BY u.created_at DESC LIMIT 5
                            ')->fetchAll();
                            
                            // Get recent training completions
                            $recentTraining = $pdo->query('
                                SELECT u.first_name, u.last_name, tm.title, tp.completed_at
                                FROM training_progress tp
                                JOIN users u ON u.id = tp.user_id
                                JOIN training_modules tm ON tm.id = tp.module_id
                                WHERE tp.status = "completed" AND tp.completed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                                ORDER BY tp.completed_at DESC LIMIT 3
                            ')->fetchAll();
                            
                            // Get recent orders
                            $recentOrders = $pdo->query('
                                SELECT o.order_number, o.total, o.created_at, wa.business_name
                                FROM orders o
                                JOIN wholesale_accounts wa ON wa.id = o.wholesale_account_id
                                WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                                ORDER BY o.created_at DESC LIMIT 3
                            ')->fetchAll();
                            
                            $hasActivity = !empty($recentUsers) || !empty($recentTraining) || !empty($recentOrders);
                            
                            if ($hasActivity): ?>
                                <div style="max-height: 300px; overflow-y: auto;">
                                    <?php if (!empty($recentUsers)): ?>
                                        <div style="margin-bottom: 16px;">
                                            <h4 style="margin: 0 0 8px 0; color: #374151; font-size: 14px;">👥 New Registrations</h4>
                                            <?php foreach ($recentUsers as $user): ?>
                                                <div style="padding: 8px 0; border-bottom: 1px solid #f3f4f6;">
                                                    <div style="font-weight: 600; color: #1f2937;">
                                                        <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                                    </div>
                                                    <div style="font-size: 12px; color: #6b7280;">
                                                        <?php echo ucfirst($user['role_name']); ?> • <?php echo date('M j, g:i a', strtotime($user['created_at'])); ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($recentTraining)): ?>
                                        <div style="margin-bottom: 16px;">
                                            <h4 style="margin: 0 0 8px 0; color: #374151; font-size: 14px;">📚 Training Completed</h4>
                                            <?php foreach ($recentTraining as $training): ?>
                                                <div style="padding: 8px 0; border-bottom: 1px solid #f3f4f6;">
                                                    <div style="font-weight: 600; color: #059669;">
                                                        <?php echo htmlspecialchars($training['first_name'] . ' ' . $training['last_name']); ?>
                                                    </div>
                                                    <div style="font-size: 12px; color: #6b7280;">
                                                        <?php echo htmlspecialchars($training['title']); ?> • <?php echo date('M j, g:i a', strtotime($training['completed_at'])); ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($recentOrders)): ?>
                                        <div>
                                            <h4 style="margin: 0 0 8px 0; color: #374151; font-size: 14px;">📦 New Orders</h4>
                                            <?php foreach ($recentOrders as $order): ?>
                                                <div style="padding: 8px 0; border-bottom: 1px solid #f3f4f6;">
                                                    <div style="font-weight: 600; color: #dc2626;">
                                                        <?php echo htmlspecialchars($order['order_number']); ?>
                                                    </div>
                                                    <div style="font-size: 12px; color: #6b7280;">
                                                        <?php echo htmlspecialchars($order['business_name']); ?> • $<?php echo number_format($order['total'], 2); ?>
                                                    </div>
                                                    <div style="font-size: 11px; color: #9ca3af;">
                                                        <?php echo date('M j, g:i a', strtotime($order['created_at'])); ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <p style="color: #6b7280; text-align: center; padding: 20px;">No recent activity in the last 24 hours.</p>
                            <?php endif; ?>
                        <?php } catch (Throwable $e) { ?>
                            <p style="color: #dc2626;">Unable to load recent activity.</p>
                        <?php } ?>
                    </div>
                    
                    <!-- Admin Alerts -->
                    <div class="card adm-card">
                        <h2 style="color: #dc2626; margin-bottom: 16px;">🚨 Admin Alerts</h2>
                        <?php
                        try {
                            // Get recent admin alerts
                            $alerts = $pdo->query('
                                SELECT taa.*, u.first_name, u.last_name, tm.title as module_title
                                FROM training_admin_alerts taa
                                JOIN users u ON u.id = taa.user_id
                                LEFT JOIN training_modules tm ON tm.id = taa.module_id
                                WHERE taa.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                                ORDER BY taa.created_at DESC
                                LIMIT 12
                            ')->fetchAll();
                            
                            if (!empty($alerts)): ?>
                                <div style="max-height: 250px; overflow-y: auto;">
                                    <?php foreach ($alerts as $alert): ?>
                                        <div style="padding: 12px; margin-bottom: 8px; border-radius: 6px; border-left: 4px solid <?php 
                                            echo $alert['severity'] === 'critical' ? '#dc2626' : 
                                                ($alert['severity'] === 'high' ? '#ea580c' : 
                                                ($alert['severity'] === 'medium' ? '#f59e0b' : '#10b981')); 
                                        ?>; background: <?php 
                                            echo $alert['severity'] === 'critical' ? '#fef2f2' : 
                                                ($alert['severity'] === 'high' ? '#fff7ed' : 
                                                ($alert['severity'] === 'medium' ? '#fffbeb' : '#f0fdf4')); 
                                        ?>;">
                                            <div style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: <?php 
                                                echo $alert['severity'] === 'critical' ? '#dc2626' : 
                                                    ($alert['severity'] === 'high' ? '#ea580c' : 
                                                    ($alert['severity'] === 'medium' ? '#f59e0b' : '#059669')); 
                                            ?>; margin-bottom: 4px;">
                                                <?php echo htmlspecialchars($alert['alert_type']); ?>
                                            </div>
                                            <div style="font-size: 14px; color: #374151; margin-bottom: 4px;">
                                                <?php echo htmlspecialchars($alert['alert_message']); ?>
                                            </div>
                                            <div style="font-size: 11px; color: #6b7280;">
                                                <?php echo htmlspecialchars($alert['first_name'] . ' ' . $alert['last_name']); ?>
                                                <?php if ($alert['module_title']): ?>
                                                    • <?php echo htmlspecialchars($alert['module_title']); ?>
                                                <?php endif; ?>
                                                • <?php echo date('M j, g:i a', strtotime($alert['created_at'])); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div style="text-align: center; margin-top: 12px;">
                                    <a href="<?php echo path_with_base('/admin/training-analytics.php'); ?>" style="color: #059669; font-size: 12px; text-decoration: none;">
                                        View All Alerts →
                                    </a>
                                </div>
                            <?php else: ?>
                                <div style="text-align: center; padding: 20px; color: #059669;">
                                    <div style="font-size: 24px; margin-bottom: 8px;">✅</div>
                                    <p style="margin: 0; font-size: 14px;">All clear! No alerts requiring attention.</p>
                                </div>
                            <?php endif; ?>
                        <?php } catch (Throwable $e) { ?>
                            <p style="color: #dc2626;">Unable to load alerts.</p>
                        <?php } ?>
                    </div>

                    <!-- Beta Sample Reviews -->
                    <div class="card adm-card" style="margin-top:24px;">
                        <h2 style="color:#059669; margin-bottom:16px; display:flex; align-items:center; gap:8px;">🧪 Beta Sample Reviews</h2>
                        <?php
                        try {
                            $betaStats = $pdo->query('
                                SELECT 
                                    COUNT(*) as total_reviews,
                                    SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending_reviews,
                                    SUM(CASE WHEN status = "approved" THEN 1 ELSE 0 END) as approved_reviews,
                                    AVG(rating) as avg_rating
                                FROM beta_sample_reviews
                            ')->fetch();
                            
                            $pendingCount = (int)($betaStats['pending_reviews'] ?? 0);
                            $approvedCount = (int)($betaStats['approved_reviews'] ?? 0);
                            $avgRating = $betaStats['avg_rating'] ? number_format($betaStats['avg_rating'], 1) : 'N/A';
                        ?>
                            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;">
                                <div style="background:#fef3c7;padding:12px;border-radius:8px;text-align:center;">
                                    <div style="font-size:24px;font-weight:700;color:#f59e0b;"><?php echo $pendingCount; ?></div>
                                    <div style="font-size:12px;color:#92400e;">Pending</div>
                                </div>
                                <div style="background:#dcfce7;padding:12px;border-radius:8px;text-align:center;">
                                    <div style="font-size:24px;font-weight:700;color:#059669;"><?php echo $approvedCount; ?></div>
                                    <div style="font-size:12px;color:#065f46;">Approved</div>
                                </div>
                                <div style="background:#dbeafe;padding:12px;border-radius:8px;text-align:center;">
                                    <div style="font-size:24px;font-weight:700;color:#3b82f6;"><?php echo $avgRating; ?> ★</div>
                                    <div style="font-size:12px;color:#1e40af;">Avg Rating</div>
                                </div>
                            </div>
                            <?php if ($pendingCount > 0): ?>
                                <div style="background:#fef3c7;border:1px solid #f59e0b;padding:12px;border-radius:8px;margin-bottom:12px;text-align:center;">
                                    <strong style="color:#92400e;">⚠️ <?php echo $pendingCount; ?> review<?php echo $pendingCount !== 1 ? 's' : ''; ?> waiting for approval</strong>
                                </div>
                            <?php endif; ?>
                            <a href="<?php echo path_with_base('/admin/beta-sample-reviews.php'); ?>" class="btn" style="background:#059669; color:#fff; width:100%;">
                                Manage Beta Reviews
                            </a>
                        <?php } catch (Throwable $e) { ?>
                            <p style="color:#64748b;">Beta review system available</p>
                            <a href="<?php echo path_with_base('/admin/beta-sample-reviews.php'); ?>" class="btn secondary">View Reviews</a>
                        <?php } ?>
                    </div>
                    
                    <div class="card adm-card" style="margin-top:24px;">
                        <h2 style="color:#1f2937; margin-bottom:16px; display:flex; align-items:center; gap:8px;">📈 Usage Analytics</h2>
                        <p style="margin:0 0 12px 0; color:#6b7280; font-size:14px;">Track agent engagement, top pages, and lead attribution trends.</p>
                        <a href="<?php echo path_with_base('/admin/usage-analytics.php'); ?>" class="btn" style="background:#2563eb; color:#fff;">Open Usage Analytics</a>
                    </div>
                    
                    <!-- Inactive User Management -->
                    <div class="card adm-card" style="margin-top:24px;">
                        <h2 style="color:#d97706; margin-bottom:16px; display:flex; align-items:center; gap:8px;">⚠️ Inactive User Management</h2>
                        
                        <!-- Inactive User Stats Summary -->
                        <div style="background:#fef3c7; border:1px solid #f59e0b; border-radius:8px; padding:16px; margin-bottom:16px;">
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; font-size:13px;">
                                <div>
                                    <div style="font-weight:600; color:#92400e;">3 Days: <?php echo intval($stats['inactive_users_3_days']); ?></div>
                                    <div style="font-weight:600; color:#92400e;">7 Days: <?php echo intval($stats['inactive_users_7_days'] - $stats['inactive_users_14_days'] - $stats['inactive_users_30_days'] - $stats['inactive_users_suspended']); ?></div>
                                </div>
                                <div>
                                    <div style="font-weight:600; color:#dc2626;">14 Days: <?php echo intval($stats['inactive_users_14_days']); ?></div>
                                    <div style="font-weight:600; color:#dc2626;">30+ Days: <?php echo intval($stats['inactive_users_30_days']); ?></div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($stats['inactive_users_pending_review'] > 0): ?>
                            <div style="background:#fee2e2; border:2px solid #dc2626; border-radius:8px; padding:16px; margin-bottom:16px;">
                                <h3 style="margin:0 0 8px 0; color:#dc2626; font-size:16px;">🚨 Pending Review</h3>
                                <p style="margin:0 0 12px 0; color:#7f1d1d; font-size:14px; font-weight:600;">
                                    <?php echo intval($stats['inactive_users_pending_review']); ?> users require administrative review
                                </p>
                                <button onclick="loadInactiveUsers('pending_review')" class="btn" style="background:#dc2626; color:#fff; font-size:12px; padding:8px 16px;">
                                    View Pending Users
                                </button>
                            </div>
                        <?php endif; ?>
                        
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <button onclick="loadInactiveUsers('inactive_7_days')" class="btn" style="background:#f59e0b; color:#fff; font-size:12px; padding:8px 12px;">
                                View 7+ Days
                            </button>
                            <button onclick="runInactiveAutomation()" class="btn" style="background:#6b7280; color:#fff; font-size:12px; padding:8px 12px;">
                                Run Automation
                            </button>
                        </div>
                    </div>
                    
                    <!-- Inactive Users Modal -->
                    <div id="inactiveUsersModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000;">
                        <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:#fff; border-radius:12px; padding:24px; max-width:90vw; max-height:90vh; overflow:auto; width:800px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                                <h3 style="margin:0; color:#1f2937;">Inactive Users Management</h3>
                                <button onclick="closeInactiveModal()" style="background:none; border:none; font-size:24px; cursor:pointer; color:#6b7280;">×</button>
                            </div>
                            <div id="inactiveUsersContent">Loading...</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Admin Actions moved into Quick Actions submenu in admin menu -->
        </div>
    </section>
    
    <!-- Auto-refresh for real-time updates -->
    <script>
    // Auto-refresh dashboard every 30 seconds
    let refreshInterval = setInterval(function() {
        // Only refresh if user is still on the page and it's been more than 30 seconds
        if (document.visibilityState === 'visible') {
            // Add a subtle loading indicator
            const refreshIndicator = document.createElement('div');
            refreshIndicator.innerHTML = '🔄 Updating...';
            refreshIndicator.style.position = 'fixed';
            refreshIndicator.style.top = '20px';
            refreshIndicator.style.right = '20px';
            refreshIndicator.style.background = '#059669';
            refreshIndicator.style.color = 'white';
            refreshIndicator.style.padding = '8px 16px';
            refreshIndicator.style.borderRadius = '6px';
            refreshIndicator.style.fontSize = '14px';
            refreshIndicator.style.zIndex = '1000';
            refreshIndicator.style.opacity = '0.9';
            document.body.appendChild(refreshIndicator);
            
            // Refresh the page after a short delay
            setTimeout(function() {
                window.location.reload();
            }, 1000);
        }
    }, 30000); // 30 seconds
    
    // Clear interval when page is hidden to save resources
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'hidden') {
            clearInterval(refreshInterval);
        } else if (document.visibilityState === 'visible') {
            // Restart the interval when page becomes visible again
            refreshInterval = setInterval(function() {
                if (document.visibilityState === 'visible') {
                    const refreshIndicator = document.createElement('div');
                    refreshIndicator.innerHTML = '🔄 Updating...';
                    refreshIndicator.style.position = 'fixed';
                    refreshIndicator.style.top = '20px';
                    refreshIndicator.style.right = '20px';
                    refreshIndicator.style.background = '#059669';
                    refreshIndicator.style.color = 'white';
                    refreshIndicator.style.padding = '8px 16px';
                    refreshIndicator.style.borderRadius = '6px';
                    refreshIndicator.style.fontSize = '14px';
                    refreshIndicator.style.zIndex = '1000';
                    refreshIndicator.style.opacity = '0.9';
                    document.body.appendChild(refreshIndicator);
                    
                    setTimeout(function() {
                        window.location.reload();
                    }, 1000);
                }
            }, 30000);
        }
    });
    
    // Add last updated timestamp
    const lastUpdated = document.createElement('div');
    lastUpdated.innerHTML = 'Last updated: ' + new Date().toLocaleTimeString();
    lastUpdated.style.position = 'fixed';
    lastUpdated.style.bottom = '20px';
    lastUpdated.style.right = '20px';
    lastUpdated.style.background = 'rgba(0,0,0,0.7)';
    lastUpdated.style.color = 'white';
    lastUpdated.style.padding = '4px 8px';
    lastUpdated.style.borderRadius = '4px';
    lastUpdated.style.fontSize = '11px';
    lastUpdated.style.zIndex = '999';
    document.body.appendChild(lastUpdated);
    
    // Inactive User Management Functions
    async function loadInactiveUsers(filter = 'all') {
        const modal = document.getElementById('inactiveUsersModal');
        const content = document.getElementById('inactiveUsersContent');
        
        modal.style.display = 'block';
        content.innerHTML = 'Loading inactive users...';
        
        try {
            const response = await fetch(`<?php echo outbound_path('/api/inactive-user-management.php'); ?>?action=get_inactive_users&filter=${filter}&limit=50`, {
                credentials: 'same-origin'
            });
            
            const data = await response.json();
            
            if (!data.ok) {
                content.innerHTML = `<div style="color:#dc2626;">Error: ${data.error}</div>`;
                return;
            }
            
            let html = `
                <div style="margin-bottom:16px; display:flex; gap:8px; flex-wrap:wrap;">
                    <button onclick="loadInactiveUsers('all')" class="btn" style="background:#6b7280; color:#fff; font-size:12px; padding:6px 12px;">All</button>
                    <button onclick="loadInactiveUsers('pending_review')" class="btn" style="background:#dc2626; color:#fff; font-size:12px; padding:6px 12px;">Pending Review</button>
                    <button onclick="loadInactiveUsers('suspended')" class="btn" style="background:#7f1d1d; color:#fff; font-size:12px; padding:6px 12px;">Suspended</button>
                    <button onclick="loadInactiveUsers('inactive_7_days')" class="btn" style="background:#f59e0b; color:#fff; font-size:12px; padding:6px 12px;">7+ Days</button>
                </div>
                <div style="margin-bottom:16px;">
                    <strong>Total: ${data.total_count} users</strong>
                </div>
            `;
            
            if (data.users.length === 0) {
                html += '<div style="text-align:center; color:#6b7280; padding:20px;">No users found for this filter.</div>';
            } else {
                html += `
                    <table style="width:100%; border-collapse:collapse; font-size:12px;">
                        <thead>
                            <tr style="background:#f8fafc; border-bottom:2px solid #e5e7eb;">
                                <th style="padding:8px; text-align:left;">User</th>
                                <th style="padding:8px; text-align:center;">Role</th>
                                <th style="padding:8px; text-align:center;">Days Inactive</th>
                                <th style="padding:8px; text-align:center;">Status</th>
                                <th style="padding:8px; text-align:center;">Last Login</th>
                                <th style="padding:8px; text-align:center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                `;
                
                data.users.forEach(user => {
                    const statusColor = user.account_suspended ? '#dc2626' : 
                                       user.pending_review ? '#f59e0b' : 
                                       user.days_inactive >= 30 ? '#dc2626' :
                                       user.days_inactive >= 14 ? '#ea580c' :
                                       user.days_inactive >= 7 ? '#f59e0b' : '#6b7280';
                    
                    const statusText = user.account_suspended ? 'Suspended' :
                                      user.pending_review ? 'Pending Review' :
                                      user.inactive_status;
                    
                    const lastLogin = user.last_login_at ? new Date(user.last_login_at).toLocaleDateString() : 'Never';
                    
                    html += `
                        <tr style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:8px;">
                                <div style="font-weight:600;">${user.first_name} ${user.last_name}</div>
                                <div style="font-size:11px; color:#6b7280;">${user.email}</div>
                            </td>
                            <td style="padding:8px; text-align:center;">
                                <span style="background:#f3f4f6; padding:2px 6px; border-radius:4px; font-size:10px;">${user.role_name}</span>
                            </td>
                            <td style="padding:8px; text-align:center; font-weight:600; color:${statusColor};">
                                ${user.days_inactive}
                            </td>
                            <td style="padding:8px; text-align:center;">
                                <span style="background:${statusColor}; color:white; padding:2px 6px; border-radius:4px; font-size:10px;">
                                    ${statusText}
                                </span>
                            </td>
                            <td style="padding:8px; text-align:center; font-size:11px;">
                                ${lastLogin}
                            </td>
                            <td style="padding:8px; text-align:center;">
                                ${user.account_suspended ? 
                                    `<button onclick="reactivateUser(${user.id})" style="background:#16a34a; color:white; border:none; padding:4px 8px; border-radius:4px; font-size:10px; cursor:pointer;">Reactivate</button>` :
                                    `<button onclick="sendManualEmail(${user.id})" style="background:#2563eb; color:white; border:none; padding:4px 8px; border-radius:4px; font-size:10px; cursor:pointer;">Send Email</button>`
                                }
                            </td>
                        </tr>
                    `;
                });
                
                html += '</tbody></table>';
            }
            
            content.innerHTML = html;
            
        } catch (error) {
            content.innerHTML = `<div style="color:#dc2626;">Error loading users: ${error.message}</div>`;
        }
    }
    
    function closeInactiveModal() {
        document.getElementById('inactiveUsersModal').style.display = 'none';
    }
    
    async function reactivateUser(userId) {
        if (!confirm('Are you sure you want to reactivate this user? This will reset their inactive status and email flags.')) {
            return;
        }
        
        try {
            const response = await fetch('<?php echo outbound_path('/api/inactive-user-management.php'); ?>', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action: 'reactivate_user', user_id: userId})
            });
            
            const data = await response.json();
            
            if (data.ok) {
                alert('User reactivated successfully');
                loadInactiveUsers(); // Reload the current view
            } else {
                alert('Error: ' + data.error);
            }
        } catch (error) {
            alert('Error reactivating user: ' + error.message);
        }
    }
    
    async function sendManualEmail(userId) {
        const emailType = prompt('Enter email type (3_day, 7_day, 14_day, 30_day, 35_day):');
        if (!emailType) return;
        
        try {
            const response = await fetch('<?php echo outbound_path('/api/inactive-user-management.php'); ?>', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action: 'send_manual_email', user_id: userId, email_type: emailType})
            });
            
            const data = await response.json();
            
            if (data.ok) {
                alert('Email sent successfully');
            } else {
                alert('Error: ' + data.error);
            }
        } catch (error) {
            alert('Error sending email: ' + error.message);
        }
    }
    
    async function runInactiveAutomation() {
        if (!confirm('Run the inactive user automation now? This will check all users and send appropriate emails.')) {
            return;
        }
        
        try {
            const response = await fetch('<?php echo outbound_path('/api/inactive-user-management.php'); ?>?action=run_automation', {
                credentials: 'same-origin'
            });
            
            const data = await response.json();
            
            if (data.ok) {
                const result = data.result;
                alert(`Automation completed:\\n\\nEmails sent: ${result.emails_sent}\\nErrors: ${result.errors}\\nUsers suspended: ${result.suspended}\\nExecution time: ${result.execution_time}s`);
                location.reload(); // Refresh to show updated stats
            } else {
                alert('Error: ' + data.error);
            }
        } catch (error) {
            alert('Error running automation: ' + error.message);
        }
    }
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../required/footer.php'; ?>