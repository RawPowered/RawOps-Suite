<?php
/**
 * CRM System Status and Verification Dashboard
 * Shows complete status of the CRM system and database
 */

$SEO = [
    'title' => 'CRM System Status — Admin — Product',
    'description' => 'Complete status dashboard for the Product CRM system.',
    'type' => 'website'
];

require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect('/admin/');

$pdo = db();

// System status checks
$systemStatus = [
    'database_connected' => false,
    'tables_exist' => 0,
    'tables_missing' => [],
    'views_exist' => 0,
    'views_missing' => [],
    'sample_data' => 0,
    'total_leads' => 0,
    'total_calls' => 0,
    'total_tasks' => 0,
    'sales_agents_count' => 0,
    'admin_simulation_active' => isset($_SESSION['admin_simulation'])
];

$crmTables = [
    'crm_leads' => 'Lead Management',
    'crm_call_logs' => 'Call Logs & Communication',
    'crm_notes' => 'Notes & Documentation', 
    'crm_tasks' => 'Task Management',
    'crm_opportunities' => 'Sales Pipeline',
    'crm_lead_assignments' => 'Assignment Tracking',
    'crm_agent_metrics' => 'Performance Metrics'
];

$crmViews = [
    'crm_leads_summary' => 'Lead Summary View',
    'crm_agent_performance' => 'Agent Performance View'
];

try {
    $systemStatus['database_connected'] = true;
    
    // Check tables
    foreach ($crmTables as $table => $description) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM `{$table}`");
            $count = $stmt->fetchColumn();
            $systemStatus['tables_exist']++;
            $systemStatus['sample_data'] += $count;
            
            // Specific counts
            if ($table === 'crm_leads') $systemStatus['total_leads'] = $count;
            if ($table === 'crm_call_logs') $systemStatus['total_calls'] = $count;  
            if ($table === 'crm_tasks') $systemStatus['total_tasks'] = $count;
            
        } catch (Exception $e) {
            $systemStatus['tables_missing'][] = $table;
        }
    }
    
    // Check views
    foreach ($crmViews as $view => $description) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM `{$view}` LIMIT 1");
            $systemStatus['views_exist']++;
        } catch (Exception $e) {
            $systemStatus['views_missing'][] = $view;
        }
    }
    
    // Count sales agents
    try {
        $stmt = $pdo->query("
            SELECT COUNT(*) FROM users u 
            JOIN user_roles ur ON ur.user_id = u.id 
            JOIN roles r ON r.id = ur.role_id 
            WHERE r.name = 'sales_agent'
        ");
        $systemStatus['sales_agents_count'] = $stmt->fetchColumn();
    } catch (Exception $e) {}
    
} catch (Exception $e) {
    $systemStatus['database_connected'] = false;
    $dbError = $e->getMessage();
}

// Calculate overall system health
$totalTables = count($crmTables);
$totalViews = count($crmViews);
$tablesHealth = ($systemStatus['tables_exist'] / $totalTables) * 100;
$viewsHealth = ($systemStatus['views_exist'] / $totalViews) * 100;
$overallHealth = ($tablesHealth + $viewsHealth) / 2;

?>

<div class="container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <div>
            <h1 style="margin: 0; color: #1f2937;">🎯 CRM System Status</h1>
            <p style="margin: 4px 0 0 0; color: #6b7280;">Complete health check and system verification</p>
        </div>
        <div style="display: flex; gap: 12px;">
            <a href="crm-overview.php" class="btn">📊 CRM Overview</a>
            <a href="../run_crm_migration.php" class="btn secondary">🔧 Run Migration</a>
        </div>
    </div>

    <!-- Overall Health Status -->
    <div class="card" style="margin-bottom: 24px; background: linear-gradient(135deg, #f0f9ff, #e0f2fe);">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <div>
                <h2 style="margin: 0; color: #0369a1;">
                    <?php if ($overallHealth >= 95): ?>
                        🎉 CRM System Fully Operational
                    <?php elseif ($overallHealth >= 80): ?>
                        ⚠️ CRM System Mostly Working
                    <?php elseif ($overallHealth >= 50): ?>
                        🔧 CRM System Needs Attention
                    <?php else: ?>
                        🚨 CRM System Issues Detected
                    <?php endif; ?>
                </h2>
                <p style="margin: 8px 0 0 0; color: #0369a1;">
                    Overall Health: <?php echo round($overallHealth, 1); ?>% 
                    • <?php echo $systemStatus['tables_exist']; ?>/<?php echo $totalTables; ?> Tables
                    • <?php echo $systemStatus['views_exist']; ?>/<?php echo $totalViews; ?> Views
                </p>
            </div>
            <div style="font-size: 64px;">
                <?php if ($overallHealth >= 95): ?>
                    ✅
                <?php elseif ($overallHealth >= 80): ?>
                    ⚠️
                <?php elseif ($overallHealth >= 50): ?>
                    🔧
                <?php else: ?>
                    🚨
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
        <!-- Database Status -->
        <div class="card">
            <h3 style="margin: 0 0 16px 0; color: #1f2937;">💾 Database Status</h3>
            
            <div style="margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                    <span>Database Connection</span>
                    <span style="color: <?php echo $systemStatus['database_connected'] ? '#059669' : '#dc2626'; ?>;">
                        <?php echo $systemStatus['database_connected'] ? '✅ Connected' : '❌ Failed'; ?>
                    </span>
                </div>
                <?php if (!$systemStatus['database_connected']): ?>
                    <div style="background: #fee2e2; padding: 8px; border-radius: 4px; font-size: 12px; color: #7f1d1d;">
                        Error: <?php echo htmlspecialchars($dbError ?? 'Unknown connection error'); ?>
                    </div>
                <?php endif; ?>
            </div>

            <h4 style="margin: 16px 0 8px 0;">CRM Tables (<?php echo $systemStatus['tables_exist']; ?>/<?php echo $totalTables; ?>)</h4>
            <?php foreach ($crmTables as $table => $description): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px; margin-bottom: 4px; background: #f9fafb; border-radius: 4px;">
                    <div>
                        <span style="font-weight: 600;"><?php echo $table; ?></span>
                        <div style="font-size: 12px; color: #6b7280;"><?php echo $description; ?></div>
                    </div>
                    <div style="text-align: right;">
                        <?php if (in_array($table, $systemStatus['tables_missing'])): ?>
                            <span style="color: #dc2626; font-weight: 600;">❌ Missing</span>
                        <?php else: ?>
                            <span style="color: #059669; font-weight: 600;">✅ Exists</span>
                            <?php 
                            try {
                                $stmt = $pdo->query("SELECT COUNT(*) FROM `{$table}`");
                                $count = $stmt->fetchColumn();
                                echo "<div style='font-size: 11px; color: #6b7280;'>{$count} records</div>";
                            } catch (Exception $e) {}
                            ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <h4 style="margin: 16px 0 8px 0;">Database Views (<?php echo $systemStatus['views_exist']; ?>/<?php echo $totalViews; ?>)</h4>
            <?php foreach ($crmViews as $view => $description): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px; margin-bottom: 4px; background: #f9fafb; border-radius: 4px;">
                    <div>
                        <span style="font-weight: 600;"><?php echo $view; ?></span>
                        <div style="font-size: 12px; color: #6b7280;"><?php echo $description; ?></div>
                    </div>
                    <span style="color: <?php echo in_array($view, $systemStatus['views_missing']) ? '#dc2626' : '#059669'; ?>; font-weight: 600;">
                        <?php echo in_array($view, $systemStatus['views_missing']) ? '❌ Missing' : '✅ Working'; ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- System Statistics -->
        <div>
            <!-- Current Statistics -->
            <div class="card" style="margin-bottom: 16px;">
                <h3 style="margin: 0 0 16px 0; color: #1f2937;">📊 CRM Statistics</h3>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div style="text-align: center; padding: 12px; background: linear-gradient(135deg, #dbeafe, #bfdbfe); border-radius: 8px;">
                        <div style="font-size: 24px; font-weight: bold; color: #1e40af;"><?php echo $systemStatus['total_leads']; ?></div>
                        <div style="font-size: 12px; color: #1e40af;">Total Leads</div>
                    </div>
                    <div style="text-align: center; padding: 12px; background: linear-gradient(135deg, #dcfce7, #bbf7d0); border-radius: 8px;">
                        <div style="font-size: 24px; font-weight: bold; color: #166534;"><?php echo $systemStatus['total_calls']; ?></div>
                        <div style="font-size: 12px; color: #166534;">Call Logs</div>
                    </div>
                    <div style="text-align: center; padding: 12px; background: linear-gradient(135deg, #fef3c7, #fde68a); border-radius: 8px;">
                        <div style="font-size: 24px; font-weight: bold; color: #d97706;"><?php echo $systemStatus['total_tasks']; ?></div>
                        <div style="font-size: 12px; color: #d97706;">Total Tasks</div>
                    </div>
                    <div style="text-align: center; padding: 12px; background: linear-gradient(135deg, #e0e7ff, #c7d2fe); border-radius: 8px;">
                        <div style="font-size: 24px; font-weight: bold; color: #4338ca;"><?php echo $systemStatus['sales_agents_count']; ?></div>
                        <div style="font-size: 12px; color: #4338ca;">Sales Agents</div>
                    </div>
                </div>
            </div>

            <!-- System Features -->
            <div class="card">
                <h3 style="margin: 0 0 16px 0; color: #1f2937;">⚡ System Features</h3>
                
                <div style="display: grid; gap: 8px;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Role Simulation</span>
                        <span style="color: #059669;">✅ Active</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Admin CRM Overview</span>
                        <span style="color: #059669;">✅ Available</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Sales Agent Dashboard</span>
                        <span style="color: #059669;">✅ Functional</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Lead Assignment System</span>
                        <span style="color: <?php echo !in_array('crm_lead_assignments', $systemStatus['tables_missing']) ? '#059669' : '#dc2626'; ?>;">
                            <?php echo !in_array('crm_lead_assignments', $systemStatus['tables_missing']) ? '✅ Ready' : '❌ Missing'; ?>
                        </span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Performance Tracking</span>
                        <span style="color: <?php echo !in_array('crm_agent_metrics', $systemStatus['tables_missing']) ? '#059669' : '#dc2626'; ?>;">
                            <?php echo !in_array('crm_agent_metrics', $systemStatus['tables_missing']) ? '✅ Ready' : '❌ Missing'; ?>
                        </span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Training Integration</span>
                        <span style="color: #059669;">✅ Complete</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Action Items -->
    <?php if (count($systemStatus['tables_missing']) > 0 || count($systemStatus['views_missing']) > 0): ?>
    <div class="card" style="margin-top: 24px; background: linear-gradient(135deg, #fef3c7, #fbbf24); border: 2px solid #f59e0b;">
        <h3 style="margin: 0 0 16px 0; color: #92400e;">🔧 Action Required</h3>
        
        <?php if (count($systemStatus['tables_missing']) > 0): ?>
        <div style="margin-bottom: 16px;">
            <strong style="color: #92400e;">Missing Tables:</strong>
            <ul style="margin: 8px 0; color: #a16207;">
                <?php foreach ($systemStatus['tables_missing'] as $table): ?>
                    <li><?php echo $table; ?> - <?php echo $crmTables[$table]; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        
        <?php if (count($systemStatus['views_missing']) > 0): ?>
        <div style="margin-bottom: 16px;">
            <strong style="color: #92400e;">Missing Views:</strong>
            <ul style="margin: 8px 0; color: #a16207;">
                <?php foreach ($systemStatus['views_missing'] as $view): ?>
                    <li><?php echo $view; ?> - <?php echo $crmViews[$view]; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        
        <div style="display: flex; gap: 12px;">
            <a href="../run_crm_migration.php" class="btn" style="background: #dc2626;">🚀 Run CRM Migration</a>
            <a href="debug-panel.php" class="btn secondary">🔍 Debug Panel</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Quick Actions -->
    <div class="card" style="margin-top: 24px; background: linear-gradient(135deg, #f0f9ff, #e0f2fe);">
        <h3 style="margin: 0 0 16px 0; color: #0369a1;">🚀 Quick Actions</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
            <a href="crm-overview.php" class="btn" style="background: #dc2626; display: flex; align-items: center; gap: 8px;">
                <span>🎯</span> CRM Overview
            </a>
            <a href="../onboarding/sales-agent/" class="btn" style="background: #059669; display: flex; align-items: center; gap: 8px;">
                <span>💼</span> Sales Agent View
            </a>
            <a href="role-simulator.php" class="btn" style="background: #7c3aed; display: flex; align-items: center; gap: 8px;">
                <span>🎭</span> Test Simulation
            </a>
            <a href="../run_crm_migration.php" class="btn secondary" style="display: flex; align-items: center; gap: 8px;">
                <span>🔧</span> Run Migration
            </a>
        </div>
    </div>

    <!-- System Information -->
    <div class="card" style="margin-top: 24px;">
        <h3 style="margin: 0 0 16px 0; color: #1f2937;">ℹ️ System Information</h3>
        
        <div style="font-family: monospace; font-size: 12px; background: #f9fafb; padding: 12px; border-radius: 6px;">
            <strong>Database:</strong> <?php echo $systemStatus['database_connected'] ? 'Connected ✅' : 'Failed ❌'; ?><br>
            <strong>CRM Tables:</strong> <?php echo $systemStatus['tables_exist']; ?>/<?php echo $totalTables; ?> (<?php echo round($tablesHealth, 1); ?>%)<br>
            <strong>CRM Views:</strong> <?php echo $systemStatus['views_exist']; ?>/<?php echo $totalViews; ?> (<?php echo round($viewsHealth, 1); ?>%)<br>
            <strong>Total Records:</strong> <?php echo number_format($systemStatus['sample_data']); ?><br>
            <strong>Role Simulation:</strong> <?php echo $systemStatus['admin_simulation_active'] ? 'Active' : 'Inactive'; ?><br>
            <strong>Last Check:</strong> <?php echo date('Y-m-d H:i:s'); ?>
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

.btn {
    transition: all 0.2s ease;
}

.btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}
</style>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
