<?php
/**
 * CRM Reports Dashboard
 * Comprehensive reporting and analytics for the CRM system
 * @prefix asacp1021_
 */

$SEO = [
    'title' => 'CRM Reports — Admin',
    'description' => 'Comprehensive CRM reports and analytics dashboard.',
    'type' => 'website'
];

require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect('/admin/');

$pdo = db();

// Get selected report type and date range
$reportType = $_GET['report'] ?? 'overview';
$dateRange = $_GET['range'] ?? 'month';
$agentId = intval($_GET['agent_id'] ?? 0);

// Calculate date ranges
$dateRanges = [
    'week' => [
        'start' => date('Y-m-d', strtotime('-1 week')),
        'end' => date('Y-m-d'),
        'label' => 'Last 7 Days'
    ],
    'month' => [
        'start' => date('Y-m-01'),
        'end' => date('Y-m-d'),
        'label' => 'This Month'
    ],
    'quarter' => [
        'start' => date('Y-m-d', strtotime('-3 months')),
        'end' => date('Y-m-d'),
        'label' => 'Last 3 Months'
    ],
    'year' => [
        'start' => date('Y-01-01'),
        'end' => date('Y-m-d'),
        'label' => 'This Year'
    ]
];

$currentRange = $dateRanges[$dateRange];

// Initialize data arrays
$reportData = [];
$chartData = [];

try {
    
    if ($reportType === 'overview') {
        // Overall CRM statistics
        $reportData['stats'] = [
            'total_leads' => 0,
            'new_leads_period' => 0,
            'converted_leads' => 0,
            'total_calls' => 0,
            'total_tasks' => 0,
            'active_opportunities' => 0,
            'pipeline_value' => 0,
            'conversion_rate' => 0,
            'avg_response_hours' => 0,
            'sample_requests_total' => 0,
            'sample_requests_pending' => 0,
            'sample_requests_shipped' => 0
        ];
        
        // Get overall stats
        $stmt = $pdo->query('SELECT COUNT(*) FROM crm_leads WHERE is_archived = 0');
        $reportData['stats']['total_leads'] = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM crm_leads WHERE created_at >= ? AND created_at <= ? AND is_archived = 0');
        $stmt->execute([$currentRange['start'], $currentRange['end']]);
        $reportData['stats']['new_leads_period'] = $stmt->fetchColumn();
        
        $stmt = $pdo->query('SELECT COUNT(*) FROM crm_leads WHERE lead_status = "won" AND is_archived = 0');
        $reportData['stats']['converted_leads'] = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM crm_call_logs WHERE call_date >= ? AND call_date <= ?');
        $stmt->execute([$currentRange['start'], $currentRange['end']]);
        $reportData['stats']['total_calls'] = $stmt->fetchColumn();
        
        $stmt = $pdo->query('SELECT COUNT(*) FROM crm_tasks WHERE status IN ("pending", "in_progress")');
        $reportData['stats']['total_tasks'] = $stmt->fetchColumn();
        
        $stmt = $pdo->query('SELECT COUNT(*) FROM crm_opportunities WHERE stage NOT IN ("closed_won", "closed_lost")');
        $reportData['stats']['active_opportunities'] = $stmt->fetchColumn();
        
        $stmt = $pdo->query('SELECT SUM(estimated_value) FROM crm_opportunities WHERE stage NOT IN ("closed_won", "closed_lost")');
        $reportData['stats']['pipeline_value'] = $stmt->fetchColumn() ?: 0;
        
        // Sample request statistics
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM sample_requests WHERE requested_at >= ? AND requested_at <= ?');
        $stmt->execute([$currentRange['start'], $currentRange['end']]);
        $reportData['stats']['sample_requests_total'] = $stmt->fetchColumn();
        
        $stmt = $pdo->query('SELECT COUNT(*) FROM sample_requests WHERE status = "pending"');
        $reportData['stats']['sample_requests_pending'] = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM sample_requests WHERE status = "shipped" AND shipped_at >= ? AND shipped_at <= ?');
        $stmt->execute([$currentRange['start'], $currentRange['end']]);
        $reportData['stats']['sample_requests_shipped'] = $stmt->fetchColumn();
        
        // Average response time
        $stmt = $pdo->prepare('
            SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, last_contact_date)) as avg_hours
            FROM crm_leads 
            WHERE last_contact_date IS NOT NULL 
            AND created_at >= ? AND created_at <= ?
        ');
        $stmt->execute([$currentRange['start'], $currentRange['end']]);
        $reportData['stats']['avg_response_hours'] = $stmt->fetchColumn() ?: 0;
        
        if ($reportData['stats']['total_leads'] > 0) {
            $reportData['stats']['conversion_rate'] = ($reportData['stats']['converted_leads'] / $reportData['stats']['total_leads']) * 100;
        }
        
        // Lead priority distribution
        $stmt = $pdo->query('
            SELECT lead_priority, COUNT(*) as count 
            FROM crm_leads 
            WHERE is_archived = 0
            GROUP BY lead_priority 
            ORDER BY FIELD(lead_priority, "urgent", "high", "medium", "low")
        ');
        $reportData['lead_priorities'] = $stmt->fetchAll();
        
        // Lead stage distribution with colors/icons from crm_lead_stages
        $stmt = $pdo->query('
            SELECT ls.stage_label, ls.stage_color, ls.stage_icon, ls.stage_key, COUNT(l.id) as count
            FROM crm_lead_stages ls
            LEFT JOIN crm_leads l ON l.lead_status = ls.stage_key AND l.is_archived = 0
            WHERE ls.is_active = 1
            GROUP BY ls.id
            ORDER BY ls.sort_order
        ');
        $reportData['lead_stages'] = $stmt->fetchAll();
        
        // Lead sources breakdown
        $stmt = $pdo->prepare('
            SELECT lead_source, COUNT(*) as count 
            FROM crm_leads 
            WHERE created_at >= ? AND created_at <= ? AND is_archived = 0
            GROUP BY lead_source 
            ORDER BY count DESC
        ');
        $stmt->execute([$currentRange['start'], $currentRange['end']]);
        $reportData['lead_sources'] = $stmt->fetchAll();
        
        // Geographic distribution
        $stmt = $pdo->prepare('
            SELECT 
                state, 
                COUNT(*) as lead_count,
                AVG(estimated_deal_value) as avg_value,
                COUNT(CASE WHEN lead_status = "won" THEN 1 END) as conversions
            FROM crm_leads 
            WHERE state IS NOT NULL AND is_archived = 0
            AND created_at >= ? AND created_at <= ?
            GROUP BY state
            ORDER BY lead_count DESC
            LIMIT 10
        ');
        $stmt->execute([$currentRange['start'], $currentRange['end']]);
        $reportData['geographic'] = $stmt->fetchAll();
        
        // Top performing lead sources by conversion rate
        $stmt = $pdo->prepare('
            SELECT 
                lead_source,
                COUNT(*) as total_leads,
                COUNT(CASE WHEN lead_status = "won" THEN 1 END) as converted_leads,
                (COUNT(CASE WHEN lead_status = "won" THEN 1 END) / COUNT(*) * 100) as conversion_rate,
                AVG(estimated_deal_value) as avg_deal_size
            FROM crm_leads 
            WHERE created_at >= ? AND created_at <= ? AND is_archived = 0
            GROUP BY lead_source
            HAVING total_leads >= 3
            ORDER BY conversion_rate DESC, total_leads DESC
            LIMIT 5
        ');
        $stmt->execute([$currentRange['start'], $currentRange['end']]);
        $reportData['source_conversion'] = $stmt->fetchAll();
        
        // Daily lead creation trend
        $stmt = $pdo->prepare('
            SELECT DATE(created_at) as date, COUNT(*) as count 
            FROM crm_leads 
            WHERE created_at >= ? AND created_at <= ? AND is_archived = 0
            GROUP BY DATE(created_at) 
            ORDER BY date
        ');
        $stmt->execute([$currentRange['start'], $currentRange['end']]);
        $chartData['daily_leads'] = $stmt->fetchAll();
        
        // Lead quality by agent (detailed vs incomplete)
        $stmt = $pdo->query('
            SELECT 
                u.id,
                CONCAT(u.first_name, " ", u.last_name) as agent_name,
                COUNT(l.id) as total_leads,
                COUNT(CASE WHEN (l.phone IS NOT NULL AND l.company_name IS NOT NULL AND l.state IS NOT NULL) THEN 1 END) as detailed_leads,
                COUNT(CASE WHEN (l.phone IS NULL OR l.company_name IS NULL OR l.state IS NULL) THEN 1 END) as incomplete_leads,
                (COUNT(CASE WHEN (l.phone IS NOT NULL AND l.company_name IS NOT NULL AND l.state IS NOT NULL) THEN 1 END) / NULLIF(COUNT(l.id), 0) * 100) as quality_score
            FROM users u
            JOIN user_roles ur ON ur.user_id = u.id
            JOIN roles r ON r.id = ur.role_id
            LEFT JOIN crm_leads l ON l.assigned_agent_id = u.id AND l.is_archived = 0
            WHERE r.name = "sales_agent"
            GROUP BY u.id
            HAVING total_leads > 0
            ORDER BY quality_score ASC, incomplete_leads DESC
            LIMIT 10
        ');
        $reportData['agent_lead_quality'] = $stmt->fetchAll();
        
        // Top performing agents
        $stmt = $pdo->prepare('
            SELECT u.first_name, u.last_name, u.email,
                   COUNT(l.id) as total_leads,
                   COUNT(CASE WHEN l.lead_status = "won" THEN 1 END) as won_leads,
                   SUM(l.estimated_deal_value) as pipeline_value,
                   (COUNT(CASE WHEN l.lead_status = "won" THEN 1 END) / NULLIF(COUNT(l.id), 0) * 100) as conversion_rate
            FROM users u
            JOIN user_roles ur ON ur.user_id = u.id
            JOIN roles r ON r.id = ur.role_id
            LEFT JOIN crm_leads l ON l.assigned_agent_id = u.id AND l.created_at >= ? AND l.created_at <= ? AND l.is_archived = 0
            WHERE r.name = "sales_agent"
            GROUP BY u.id
            HAVING total_leads > 0
            ORDER BY conversion_rate DESC, total_leads DESC
            LIMIT 10
        ');
        $stmt->execute([$currentRange['start'], $currentRange['end']]);
        $reportData['top_agents'] = $stmt->fetchAll();
        
    } elseif ($reportType === 'leads') {
        // Lead-focused reports
        
        // Lead lifecycle analysis (average time in each stage)
        $stmt = $pdo->prepare('
            SELECT 
                lead_status,
                COUNT(*) as count,
                SUM(estimated_deal_value) as total_value,
                AVG(estimated_deal_value) as avg_value,
                AVG(DATEDIFF(NOW(), created_at)) as avg_age_days,
                AVG(interest_level) as avg_interest
            FROM crm_leads 
            WHERE created_at >= ? AND created_at <= ? AND is_archived = 0
            GROUP BY lead_status
            ORDER BY FIELD(lead_status, "new", "contacted", "qualified", "proposal_sent", "negotiating", "won", "lost", "dormant")
        ');
        $stmt->execute([$currentRange['start'], $currentRange['end']]);
        $reportData['pipeline'] = $stmt->fetchAll();
        
        // Interest level distribution
        $stmt = $pdo->query('
            SELECT 
                interest_level,
                COUNT(*) as count
            FROM crm_leads 
            WHERE interest_level IS NOT NULL AND is_archived = 0
            GROUP BY interest_level
            ORDER BY interest_level DESC
        ');
        $reportData['interest_distribution'] = $stmt->fetchAll();
        
        // Lead quality score (completeness)
        $stmt = $pdo->prepare('
            SELECT 
                CASE
                    WHEN (phone IS NOT NULL AND company_name IS NOT NULL AND state IS NOT NULL) THEN "Complete"
                    WHEN (phone IS NOT NULL OR company_name IS NOT NULL) THEN "Partial"
                    ELSE "Incomplete"
                END as quality,
                COUNT(*) as count
            FROM crm_leads 
            WHERE created_at >= ? AND created_at <= ? AND is_archived = 0
            GROUP BY quality
            ORDER BY FIELD(quality, "Complete", "Partial", "Incomplete")
        ');
        $stmt->execute([$currentRange['start'], $currentRange['end']]);
        $reportData['lead_quality'] = $stmt->fetchAll();
        
        // Stale leads (no activity in 30+ days)
        $stmt = $pdo->query('
            SELECT 
                CONCAT(first_name, " ", last_name) as name,
                company_name,
                email,
                DATEDIFF(NOW(), COALESCE(last_contact_date, created_at)) as days_inactive,
                assigned_agent_id
            FROM crm_leads 
            WHERE DATEDIFF(NOW(), COALESCE(last_contact_date, created_at)) > 30
            AND lead_status NOT IN ("won", "lost", "do_not_contact")
            AND is_archived = 0
            ORDER BY days_inactive DESC
            LIMIT 20
        ');
        $reportData['stale_leads'] = $stmt->fetchAll();
        
        // Lead source performance
        $stmt = $pdo->prepare('
            SELECT 
                lead_source,
                COUNT(*) as total_leads,
                COUNT(CASE WHEN lead_status = "won" THEN 1 END) as converted_leads,
                (COUNT(CASE WHEN lead_status = "won" THEN 1 END) / NULLIF(COUNT(*), 0) * 100) as conversion_rate,
                SUM(estimated_deal_value) as total_value,
                AVG(estimated_deal_value) as avg_deal_size
            FROM crm_leads 
            WHERE created_at >= ? AND created_at <= ? AND is_archived = 0
            GROUP BY lead_source
            ORDER BY conversion_rate DESC, total_leads DESC
        ');
        $stmt->execute([$currentRange['start'], $currentRange['end']]);
        $reportData['source_performance'] = $stmt->fetchAll();
        
        // Geographic heat map data
        $stmt = $pdo->prepare('
            SELECT 
                state,
                city,
                COUNT(*) as lead_count,
                AVG(estimated_deal_value) as avg_value,
                COUNT(CASE WHEN lead_status = "won" THEN 1 END) as conversions
            FROM crm_leads 
            WHERE state IS NOT NULL AND created_at >= ? AND created_at <= ? AND is_archived = 0
            GROUP BY state, city
            ORDER BY lead_count DESC
            LIMIT 20
        ');
        $stmt->execute([$currentRange['start'], $currentRange['end']]);
        $reportData['geographic_detailed'] = $stmt->fetchAll();
        
    } elseif ($reportType === 'agents') {
        // Sales agent performance reports
        
        $whereClause = '';
        $params = [$currentRange['start'], $currentRange['end']];
        
        if ($agentId > 0) {
            $whereClause = ' AND u.id = ?';
            $params[] = $agentId;
        }
        
        // Agent performance metrics with sample requests and lead quality
        $stmt = $pdo->prepare("
            SELECT 
                u.id,
                CONCAT(u.first_name, ' ', u.last_name) as name,
                u.email,
                COUNT(DISTINCT l.id) as total_leads,
                COUNT(DISTINCT CASE WHEN l.lead_status = 'new' THEN l.id END) as new_leads,
                COUNT(DISTINCT CASE WHEN l.lead_status IN ('contacted', 'qualified', 'proposal_sent', 'negotiating') THEN l.id END) as active_leads,
                COUNT(DISTINCT CASE WHEN l.lead_status = 'won' THEN l.id END) as won_leads,
                COUNT(DISTINCT CASE WHEN l.lead_status = 'lost' THEN l.id END) as lost_leads,
                COUNT(DISTINCT CASE WHEN (l.phone IS NOT NULL AND l.company_name IS NOT NULL AND l.state IS NOT NULL) THEN l.id END) as detailed_leads,
                COUNT(DISTINCT CASE WHEN (l.phone IS NULL OR l.company_name IS NULL OR l.state IS NULL) THEN l.id END) as incomplete_leads,
                (COUNT(DISTINCT CASE WHEN (l.phone IS NOT NULL AND l.company_name IS NOT NULL AND l.state IS NOT NULL) THEN l.id END) / NULLIF(COUNT(DISTINCT l.id), 0) * 100) as lead_quality_score,
                SUM(l.estimated_deal_value) as pipeline_value,
                AVG(l.interest_level) as avg_interest,
                (COUNT(DISTINCT CASE WHEN l.lead_status = 'won' THEN l.id END) / NULLIF(COUNT(DISTINCT l.id), 0) * 100) as conversion_rate,
                (SELECT COUNT(*) FROM crm_call_logs cl WHERE cl.agent_id = u.id AND cl.call_date >= ? AND cl.call_date <= ?) as calls_made,
                (SELECT COUNT(*) FROM crm_tasks t WHERE t.agent_id = u.id AND t.created_at >= ? AND t.created_at <= ?) as tasks_created,
                (SELECT COUNT(*) FROM crm_tasks t WHERE t.agent_id = u.id AND t.status = 'completed' AND t.completed_at >= ? AND t.completed_at <= ?) as tasks_completed,
                (SELECT COUNT(*) FROM sample_requests sr WHERE sr.agent_id = u.id AND sr.requested_at >= ? AND sr.requested_at <= ?) as samples_requested,
                (SELECT COUNT(*) FROM sample_requests sr WHERE sr.agent_id = u.id AND sr.status = 'delivered' AND sr.delivered_at >= ? AND sr.delivered_at <= ?) as samples_delivered,
                (SELECT AVG(TIMESTAMPDIFF(HOUR, l2.created_at, l2.last_contact_date)) FROM crm_leads l2 WHERE l2.assigned_agent_id = u.id AND l2.last_contact_date IS NOT NULL) as avg_response_hours
            FROM users u
            JOIN user_roles ur ON ur.user_id = u.id
            JOIN roles r ON r.id = ur.role_id
            LEFT JOIN crm_leads l ON l.assigned_agent_id = u.id AND l.created_at >= ? AND l.created_at <= ? AND l.is_archived = 0
            WHERE r.name = 'sales_agent' {$whereClause}
            GROUP BY u.id
            ORDER BY conversion_rate DESC, total_leads DESC
        ");
        
        $stmt->execute([
            $currentRange['start'], $currentRange['end'], // calls
            $currentRange['start'], $currentRange['end'], // tasks created
            $currentRange['start'], $currentRange['end'], // tasks completed
            $currentRange['start'], $currentRange['end'], // samples requested
            $currentRange['start'], $currentRange['end'], // samples delivered
            $currentRange['start'], $currentRange['end'], // leads date range
            ...($agentId > 0 ? [$agentId] : [])
        ]);
        $reportData['agent_performance'] = $stmt->fetchAll();
        
        // Agent activity timeline (if single agent selected)
        if ($agentId > 0) {
            $stmt = $pdo->prepare('
                SELECT DATE(call_date) as date, COUNT(*) as calls
                FROM crm_call_logs 
                WHERE agent_id = ? AND call_date >= ? AND call_date <= ?
                GROUP BY DATE(call_date)
                ORDER BY date
            ');
            $stmt->execute([$agentId, $currentRange['start'], $currentRange['end']]);
            $chartData['agent_activity'] = $stmt->fetchAll();
        }
        
    } elseif ($reportType === 'activities') {
        // Activity and communication reports
        
        // Call outcome analysis
        $stmt = $pdo->prepare('
            SELECT 
                call_outcome,
                COUNT(*) as count,
                AVG(duration_minutes) as avg_duration
            FROM crm_call_logs 
            WHERE call_date >= ? AND call_date <= ?
            AND call_outcome IS NOT NULL
            GROUP BY call_outcome
            ORDER BY count DESC
        ');
        $stmt->execute([$currentRange['start'], $currentRange['end']]);
        $reportData['call_outcomes'] = $stmt->fetchAll();
        
        // Best performing call times
        $stmt = $pdo->prepare('
            SELECT 
                HOUR(call_date) as hour,
                COUNT(*) as total_calls,
                COUNT(CASE WHEN call_outcome IN ("interested", "follow_up_scheduled", "meeting_set") THEN 1 END) as successful_calls,
                (COUNT(CASE WHEN call_outcome IN ("interested", "follow_up_scheduled", "meeting_set") THEN 1 END) / COUNT(*) * 100) as success_rate
            FROM crm_call_logs 
            WHERE call_date >= ? AND call_date <= ?
            GROUP BY HOUR(call_date)
            HAVING total_calls >= 5
            ORDER BY success_rate DESC
            LIMIT 10
        ');
        $stmt->execute([$currentRange['start'], $currentRange['end']]);
        $reportData['best_call_times'] = $stmt->fetchAll();
        
        // Communication type breakdown
        $stmt = $pdo->prepare('
            SELECT 
                contact_type,
                COUNT(*) as count,
                AVG(duration_minutes) as avg_duration
            FROM crm_call_logs 
            WHERE call_date >= ? AND call_date <= ?
            GROUP BY contact_type
            ORDER BY count DESC
        ');
        $stmt->execute([$currentRange['start'], $currentRange['end']]);
        $reportData['communication_types'] = $stmt->fetchAll();
        
        // Task completion analysis
        $stmt = $pdo->prepare('
            SELECT 
                task_type,
                COUNT(*) as total_tasks,
                COUNT(CASE WHEN status = "completed" THEN 1 END) as completed_tasks,
                (COUNT(CASE WHEN status = "completed" THEN 1 END) / NULLIF(COUNT(*), 0) * 100) as completion_rate,
                AVG(CASE WHEN status = "completed" THEN actual_duration_minutes END) as avg_completion_time,
                COUNT(CASE WHEN due_date < NOW() AND status != "completed" THEN 1 END) as overdue_tasks
            FROM crm_tasks 
            WHERE created_at >= ? AND created_at <= ?
            GROUP BY task_type
            ORDER BY total_tasks DESC
        ');
        $stmt->execute([$currentRange['start'], $currentRange['end']]);
        $reportData['task_analysis'] = $stmt->fetchAll();
        
        // Daily activity volume
        $stmt = $pdo->prepare('
            SELECT 
                DATE(call_date) as date,
                COUNT(*) as activities
            FROM crm_call_logs 
            WHERE call_date >= ? AND call_date <= ?
            GROUP BY DATE(call_date)
            ORDER BY date
        ');
        $stmt->execute([$currentRange['start'], $currentRange['end']]);
        $chartData['daily_activities'] = $stmt->fetchAll();
        
        // Follow-up effectiveness
        $stmt = $pdo->prepare('
            SELECT 
                follow_up_required,
                COUNT(*) as count,
                COUNT(CASE WHEN follow_up_date <= NOW() THEN 1 END) as overdue_count
            FROM crm_call_logs 
            WHERE call_date >= ? AND call_date <= ?
            GROUP BY follow_up_required
        ');
        $stmt->execute([$currentRange['start'], $currentRange['end']]);
        $reportData['follow_up_stats'] = $stmt->fetchAll();
    }
    
    // Get all sales agents for filter dropdown
    $stmt = $pdo->query('
        SELECT u.id, CONCAT(u.first_name, " ", u.last_name) as name
        FROM users u
        JOIN user_roles ur ON ur.user_id = u.id
        JOIN roles r ON r.id = ur.role_id
        WHERE r.name = "sales_agent"
        ORDER BY u.first_name, u.last_name
    ');
    $allAgents = $stmt->fetchAll();
    
} catch (Exception $e) {
    flash_set('error', 'Error generating reports: ' . $e->getMessage());
    $reportData = [];
}

?>

<div class="asacp1021_reports_container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <div class="asacp1021_report_header">
        <div>
            <h1>CRM Reports</h1>
            <p>Comprehensive analytics and reporting dashboard</p>
        </div>
        <div class="asacp1021_header_actions">
            <a href="crm-overview.php" class="btn secondary">CRM Overview</a>
            <button onclick="window.print()" class="btn">Print Report</button>
        </div>
    </div>

    <!-- Report Controls -->
    <div class="asacp1021_filter_bar">
        <form method="get">
            <div class="asacp1021_filter_group">
                <label>Report Type</label>
                <select name="report">
                    <option value="overview" <?php echo $reportType === 'overview' ? 'selected' : ''; ?>>Overview</option>
                    <option value="leads" <?php echo $reportType === 'leads' ? 'selected' : ''; ?>>Lead Analysis</option>
                    <option value="agents" <?php echo $reportType === 'agents' ? 'selected' : ''; ?>>Agent Performance</option>
                    <option value="activities" <?php echo $reportType === 'activities' ? 'selected' : ''; ?>>Activities</option>
                </select>
            </div>
            
            <div class="asacp1021_filter_group">
                <label>Date Range</label>
                <select name="range">
                    <?php foreach ($dateRanges as $key => $range): ?>
                        <option value="<?php echo $key; ?>" <?php echo $dateRange === $key ? 'selected' : ''; ?>>
                            <?php echo $range['label']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <?php if ($reportType === 'agents'): ?>
            <div class="asacp1021_filter_group">
                <label>Agent Filter</label>
                <select name="agent_id">
                    <option value="0">All Agents</option>
                    <?php foreach ($allAgents as $agent): ?>
                        <option value="<?php echo $agent['id']; ?>" <?php echo $agentId == $agent['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($agent['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            
            <button type="submit" class="btn">Generate Report</button>
        </form>
    </div>

    <!-- Report Period Badge -->
    <div class="asacp1021_period_badge">
        <strong><?php 
        echo match($reportType) {
            'overview' => 'CRM Overview Report',
            'leads' => 'Lead Analysis Report', 
            'agents' => 'Agent Performance Report',
            'activities' => 'Activities Report',
            default => 'CRM Report'
        };
        ?></strong>
        <span>
            <?php echo $currentRange['label']; ?> 
            (<?php echo date('M j, Y', strtotime($currentRange['start'])); ?> - <?php echo date('M j, Y', strtotime($currentRange['end'])); ?>)
            <?php if ($reportType === 'agents' && $agentId > 0): ?>
                • Agent: <?php 
                $selectedAgent = array_filter($allAgents, fn($a) => $a['id'] == $agentId);
                echo htmlspecialchars($selectedAgent ? reset($selectedAgent)['name'] : 'Unknown');
                ?>
            <?php endif; ?>
        </span>
    </div>

    <?php if ($reportType === 'overview'): ?>
        <!-- Overview Report -->
        <div class="asacp1021_stats_grid">
            <div class="asacp1021_stat_card">
                <div class="asacp1021_stat_icon">👥</div>
                <div class="asacp1021_stat_value"><?php echo number_format($reportData['stats']['total_leads']); ?></div>
                <div class="asacp1021_stat_label">Total Leads</div>
            </div>
            <div class="asacp1021_stat_card">
                <div class="asacp1021_stat_icon">✨</div>
                <div class="asacp1021_stat_value"><?php echo number_format($reportData['stats']['new_leads_period']); ?></div>
                <div class="asacp1021_stat_label">New This Period</div>
            </div>
            <div class="asacp1021_stat_card">
                <div class="asacp1021_stat_icon">🎉</div>
                <div class="asacp1021_stat_value"><?php echo number_format($reportData['stats']['converted_leads']); ?></div>
                <div class="asacp1021_stat_label">Converted</div>
            </div>
            <div class="asacp1021_stat_card">
                <div class="asacp1021_stat_icon">📈</div>
                <div class="asacp1021_stat_value"><?php echo number_format($reportData['stats']['conversion_rate'], 1); ?>%</div>
                <div class="asacp1021_stat_label">Conversion Rate</div>
            </div>
            <div class="asacp1021_stat_card">
                <div class="asacp1021_stat_icon">💰</div>
                <div class="asacp1021_stat_value">$<?php echo number_format($reportData['stats']['pipeline_value'], 0); ?></div>
                <div class="asacp1021_stat_label">Pipeline Value</div>
            </div>
            <div class="asacp1021_stat_card">
                <div class="asacp1021_stat_icon">📞</div>
                <div class="asacp1021_stat_value"><?php echo number_format($reportData['stats']['total_calls']); ?></div>
                <div class="asacp1021_stat_label">Calls Made</div>
            </div>
            <div class="asacp1021_stat_card">
                <div class="asacp1021_stat_icon">📦</div>
                <div class="asacp1021_stat_value"><?php echo number_format($reportData['stats']['sample_requests_total']); ?></div>
                <div class="asacp1021_stat_label">Sample Requests</div>
                <div class="asacp1021_stat_meta"><?php echo $reportData['stats']['sample_requests_pending']; ?> pending</div>
            </div>
            <div class="asacp1021_stat_card">
                <div class="asacp1021_stat_icon">⏱️</div>
                <div class="asacp1021_stat_value"><?php echo number_format($reportData['stats']['avg_response_hours'], 1); ?>h</div>
                <div class="asacp1021_stat_label">Avg Response Time</div>
            </div>
        </div>

        <div class="asacp1021_grid_2col">
            <!-- Lead Priority Distribution -->
            <div class="asacp1021_card">
                <h3>Lead Priority Distribution</h3>
                <?php if (empty($reportData['lead_priorities'])): ?>
                    <p class="asacp1021_empty_state">No priority data available</p>
                <?php else: ?>
                    <?php foreach ($reportData['lead_priorities'] as $priority): ?>
                        <div class="asacp1021_list_item">
                            <span class="asacp1021_metric_badge asacp1021_priority_<?php echo $priority['lead_priority']; ?>">
                                <?php echo ucfirst($priority['lead_priority']); ?>
                            </span>
                            <span class="asacp1021_metric_value"><?php echo number_format($priority['count']); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Lead Sources -->
            <div class="asacp1021_card">
                <h3>Lead Sources (<?php echo $currentRange['label']; ?>)</h3>
                <?php if (empty($reportData['lead_sources'])): ?>
                    <p class="asacp1021_empty_state">No data for this period</p>
                <?php else: ?>
                    <?php foreach ($reportData['lead_sources'] as $source): ?>
                        <div class="asacp1021_list_item">
                            <span><?php echo ucwords(str_replace('_', ' ', $source['lead_source'])); ?></span>
                            <span class="asacp1021_metric_value"><?php echo number_format($source['count']); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Lead Stage Distribution with Icons -->
        <div class="asacp1021_card">
            <h3>Lead Pipeline Stages</h3>
            <?php if (empty($reportData['lead_stages'])): ?>
                <p class="asacp1021_empty_state">No stage data available</p>
            <?php else: ?>
                <div class="asacp1021_stage_grid">
                    <?php foreach ($reportData['lead_stages'] as $stage): ?>
                        <div class="asacp1021_stage_item" style="border-left: 4px solid <?php echo htmlspecialchars($stage['stage_color']); ?>">
                            <div class="asacp1021_stage_icon"><?php echo $stage['stage_icon']; ?></div>
                            <div class="asacp1021_stage_info">
                                <div class="asacp1021_stage_label"><?php echo htmlspecialchars($stage['stage_label']); ?></div>
                                <div class="asacp1021_stage_count"><?php echo number_format($stage['count'] ?: 0); ?> leads</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="asacp1021_grid_2col">
            <!-- Geographic Distribution -->
            <div class="asacp1021_card">
                <h3>Top States by Lead Count</h3>
                <?php if (empty($reportData['geographic'])): ?>
                    <p class="asacp1021_empty_state">No location data available</p>
                <?php else: ?>
                    <?php foreach ($reportData['geographic'] as $geo): ?>
                        <div class="asacp1021_list_item">
                            <div>
                                <div class="asacp1021_geo_state"><?php echo htmlspecialchars($geo['state']); ?></div>
                                <div class="asacp1021_geo_meta">
                                    <?php echo $geo['conversions']; ?> conversions • Avg: $<?php echo number_format($geo['avg_value'] ?: 0, 0); ?>
                                </div>
                            </div>
                            <span class="asacp1021_metric_value"><?php echo number_format($geo['lead_count']); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Top Converting Sources -->
            <div class="asacp1021_card">
                <h3>Top Converting Sources</h3>
                <?php if (empty($reportData['source_conversion'])): ?>
                    <p class="asacp1021_empty_state">Insufficient data</p>
                <?php else: ?>
                    <?php foreach ($reportData['source_conversion'] as $source): ?>
                        <div class="asacp1021_list_item">
                            <div>
                                <div class="asacp1021_source_name"><?php echo ucwords(str_replace('_', ' ', $source['lead_source'])); ?></div>
                                <div class="asacp1021_source_meta">
                                    <?php echo $source['converted_leads']; ?>/<?php echo $source['total_leads']; ?> converted • 
                                    Avg deal: $<?php echo number_format($source['avg_deal_size'] ?: 0, 0); ?>
                                </div>
                            </div>
                            <span class="asacp1021_conversion_badge"><?php echo number_format($source['conversion_rate'], 1); ?>%</span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Agent Lead Quality Analysis -->
        <?php if (!empty($reportData['agent_lead_quality'])): ?>
        <div class="asacp1021_card">
            <h3>Agent Lead Quality - Who Has Detailed vs Incomplete Leads</h3>
            <p style="color: #5f6368; font-size: 12px; margin-bottom: 16px;">
                Detailed leads have phone, company, and state information. Lower quality scores indicate more incomplete data.
            </p>
            <div class="asacp1021_table_wrapper">
                <table class="asacp1021_report_table">
                    <thead>
                        <tr>
                            <th>Agent</th>
                            <th>Total Leads</th>
                            <th>Detailed Leads</th>
                            <th>Incomplete Leads</th>
                            <th>Quality Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportData['agent_lead_quality'] as $agent): ?>
                        <tr>
                            <td>
                                <div class="asacp1021_agent_name">
                                    <a href="?report=agents&range=<?php echo $dateRange; ?>&agent_id=<?php echo $agent['id']; ?>">
                                        <?php echo htmlspecialchars($agent['agent_name']); ?>
                                    </a>
                                </div>
                            </td>
                            <td><?php echo number_format($agent['total_leads']); ?></td>
                            <td>
                                <span class="asacp1021_quality_badge asacp1021_quality_complete">
                                    <?php echo number_format($agent['detailed_leads']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($agent['incomplete_leads'] > 0): ?>
                                    <span class="asacp1021_quality_badge asacp1021_quality_incomplete">
                                        <?php echo number_format($agent['incomplete_leads']); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #5f6368;">0</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span class="asacp1021_quality_score" style="
                                        color: <?php 
                                        echo $agent['quality_score'] >= 80 ? '#137333' : 
                                             ($agent['quality_score'] >= 50 ? '#f29900' : '#c5221f'); 
                                        ?>;
                                        font-weight: 600;
                                    ">
                                        <?php echo number_format($agent['quality_score'], 0); ?>%
                                    </span>
                                    <div class="asacp1021_quality_bar" style="flex: 1; height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden;">
                                        <div style="
                                            width: <?php echo $agent['quality_score']; ?>%;
                                            height: 100%;
                                            background: <?php 
                                            echo $agent['quality_score'] >= 80 ? '#137333' : 
                                                 ($agent['quality_score'] >= 50 ? '#f29900' : '#c5221f'); 
                                            ?>;
                                            transition: width 0.3s;
                                        "></div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Top Performing Agents -->
        <?php if (!empty($reportData['top_agents'])): ?>
        <div class="asacp1021_card">
            <h3>Top Performing Agents</h3>
            <div class="asacp1021_table_wrapper">
                <table class="asacp1021_report_table">
                    <thead>
                        <tr>
                            <th>Agent</th>
                            <th>Leads</th>
                            <th>Won</th>
                            <th>Conversion %</th>
                            <th>Pipeline Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportData['top_agents'] as $agent): ?>
                        <tr>
                            <td>
                                <div class="asacp1021_agent_name"><?php echo htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']); ?></div>
                                <div class="asacp1021_agent_email"><?php echo htmlspecialchars($agent['email']); ?></div>
                            </td>
                            <td><?php echo number_format($agent['total_leads']); ?></td>
                            <td><?php echo number_format($agent['won_leads']); ?></td>
                            <td>
                                <span class="asacp1021_conversion_badge">
                                    <?php echo number_format($agent['conversion_rate'] ?: 0, 1); ?>%
                                </span>
                            </td>
                            <td>$<?php echo number_format($agent['pipeline_value'] ?: 0, 0); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    <?php elseif ($reportType === 'leads'): ?>
        <!-- Lead Analysis Report -->
        <div class="asacp1021_card">
            <h3>Sales Pipeline Analysis</h3>
            <?php if (empty($reportData['pipeline'])): ?>
                <p class="asacp1021_empty_state">No pipeline data for this period</p>
            <?php else: ?>
                <div class="asacp1021_table_wrapper">
                    <table class="asacp1021_report_table">
                        <thead>
                            <tr>
                                <th>Stage</th>
                                <th>Count</th>
                                <th>Total Value</th>
                                <th>Avg Value</th>
                                <th>Avg Age (Days)</th>
                                <th>Avg Interest</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData['pipeline'] as $stage): ?>
                            <tr>
                                <td><?php echo ucwords(str_replace('_', ' ', $stage['lead_status'])); ?></td>
                                <td><?php echo number_format($stage['count']); ?></td>
                                <td>$<?php echo number_format($stage['total_value'] ?: 0, 0); ?></td>
                                <td>$<?php echo number_format($stage['avg_value'] ?: 0, 0); ?></td>
                                <td><?php echo round($stage['avg_age_days']); ?></td>
                                <td><?php echo number_format($stage['avg_interest'] ?: 0, 1); ?>/10</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="asacp1021_grid_3col">
            <!-- Lead Quality Distribution -->
            <div class="asacp1021_card">
                <h3>Lead Quality</h3>
                <?php if (empty($reportData['lead_quality'])): ?>
                    <p class="asacp1021_empty_state">No data available</p>
                <?php else: ?>
                    <?php foreach ($reportData['lead_quality'] as $quality): ?>
                        <div class="asacp1021_list_item">
                            <span class="asacp1021_quality_badge asacp1021_quality_<?php echo strtolower($quality['quality']); ?>">
                                <?php echo $quality['quality']; ?>
                            </span>
                            <span class="asacp1021_metric_value"><?php echo number_format($quality['count']); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Interest Level Distribution -->
            <div class="asacp1021_card">
                <h3>Interest Level Distribution</h3>
                <?php if (empty($reportData['interest_distribution'])): ?>
                    <p class="asacp1021_empty_state">No data available</p>
                <?php else: ?>
                    <?php foreach ($reportData['interest_distribution'] as $interest): ?>
                        <div class="asacp1021_list_item">
                            <span>Level <?php echo $interest['interest_level']; ?>/10</span>
                            <span class="asacp1021_metric_value"><?php echo number_format($interest['count']); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Source Performance -->
            <div class="asacp1021_card">
                <h3>Source Performance</h3>
                <?php if (empty($reportData['source_performance'])): ?>
                    <p class="asacp1021_empty_state">No source data available</p>
                <?php else: ?>
                    <?php foreach ($reportData['source_performance'] as $source): ?>
                        <div class="asacp1021_list_item">
                            <div>
                                <div><?php echo ucwords(str_replace('_', ' ', $source['lead_source'])); ?></div>
                                <div class="asacp1021_source_meta"><?php echo $source['converted_leads']; ?>/<?php echo $source['total_leads']; ?> converted</div>
                            </div>
                            <span class="asacp1021_conversion_badge"><?php echo number_format($source['conversion_rate'], 1); ?>%</span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stale Leads -->
        <?php if (!empty($reportData['stale_leads'])): ?>
        <div class="asacp1021_card">
            <h3>Stale Leads (30+ Days Inactive)</h3>
            <div class="asacp1021_table_wrapper">
                <table class="asacp1021_report_table">
                    <thead>
                        <tr>
                            <th>Lead Name</th>
                            <th>Company</th>
                            <th>Email</th>
                            <th>Days Inactive</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportData['stale_leads'] as $lead): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($lead['name']); ?></td>
                            <td><?php echo htmlspecialchars($lead['company_name'] ?: 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($lead['email']); ?></td>
                            <td><span class="asacp1021_days_badge"><?php echo $lead['days_inactive']; ?> days</span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Geographic Details -->
        <?php if (!empty($reportData['geographic_detailed'])): ?>
        <div class="asacp1021_card">
            <h3>Geographic Distribution (Top 20)</h3>
            <div class="asacp1021_table_wrapper">
                <table class="asacp1021_report_table">
                    <thead>
                        <tr>
                            <th>State</th>
                            <th>City</th>
                            <th>Leads</th>
                            <th>Conversions</th>
                            <th>Avg Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportData['geographic_detailed'] as $geo): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($geo['state']); ?></td>
                            <td><?php echo htmlspecialchars($geo['city'] ?: 'N/A'); ?></td>
                            <td><?php echo number_format($geo['lead_count']); ?></td>
                            <td><?php echo number_format($geo['conversions']); ?></td>
                            <td>$<?php echo number_format($geo['avg_value'] ?: 0, 0); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    <?php elseif ($reportType === 'agents'): ?>
        <!-- Agent Performance Report -->
        <div class="asacp1021_card">
            <h3>Agent Performance Metrics</h3>
            <?php if (empty($reportData['agent_performance'])): ?>
                <p class="asacp1021_empty_state">No agent data available</p>
            <?php else: ?>
                <div class="asacp1021_table_wrapper">
                    <table class="asacp1021_report_table">
                        <thead>
                            <tr>
                                <th>Agent</th>
                                <th>Leads</th>
                                <th>Detailed</th>
                                <th>Incomplete</th>
                                <th>Quality</th>
                                <th>Active</th>
                                <th>Won</th>
                                <th>Conv %</th>
                                <th>Calls</th>
                                <th>Tasks</th>
                                <th>Samples</th>
                                <th>Pipeline</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData['agent_performance'] as $agent): ?>
                            <tr>
                                <td>
                                    <div class="asacp1021_agent_name">
                                        <?php if ($agentId == 0): ?>
                                            <a href="?report=agents&range=<?php echo $dateRange; ?>&agent_id=<?php echo $agent['id']; ?>">
                                                <?php echo htmlspecialchars($agent['name']); ?>
                                            </a>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($agent['name']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="asacp1021_agent_email"><?php echo htmlspecialchars($agent['email']); ?></div>
                                </td>
                                <td><?php echo number_format($agent['total_leads']); ?></td>
                                <td>
                                    <span class="asacp1021_quality_badge asacp1021_quality_complete">
                                        <?php echo number_format($agent['detailed_leads'] ?: 0); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($agent['incomplete_leads'] > 0): ?>
                                        <span class="asacp1021_quality_badge asacp1021_quality_incomplete">
                                            <?php echo number_format($agent['incomplete_leads']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #5f6368;">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="asacp1021_quality_score" style="
                                        color: <?php 
                                        echo $agent['lead_quality_score'] >= 80 ? '#137333' : 
                                             ($agent['lead_quality_score'] >= 50 ? '#f29900' : '#c5221f'); 
                                        ?>;
                                    ">
                                        <?php echo number_format($agent['lead_quality_score'] ?: 0, 0); ?>%
                                    </span>
                                </td>
                                <td><?php echo number_format($agent['active_leads']); ?></td>
                                <td><?php echo number_format($agent['won_leads']); ?></td>
                                <td>
                                    <span class="asacp1021_conversion_badge">
                                        <?php echo number_format($agent['conversion_rate'] ?: 0, 1); ?>%
                                    </span>
                                </td>
                                <td><?php echo number_format($agent['calls_made']); ?></td>
                                <td><?php echo $agent['tasks_completed']; ?>/<?php echo $agent['tasks_created']; ?></td>
                                <td><?php echo $agent['samples_delivered']; ?>/<?php echo $agent['samples_requested']; ?></td>
                                <td>$<?php echo number_format($agent['pipeline_value'] ?: 0, 0); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($reportType === 'activities'): ?>
        <!-- Activities Report -->
        <div class="asacp1021_grid_2col">
            <!-- Call Outcomes -->
            <div class="asacp1021_card">
                <h3>Call Outcomes</h3>
                <?php if (empty($reportData['call_outcomes'])): ?>
                    <p class="asacp1021_empty_state">No call data available</p>
                <?php else: ?>
                    <?php foreach ($reportData['call_outcomes'] as $outcome): ?>
                        <div class="asacp1021_list_item">
                            <div>
                                <div><?php echo ucwords(str_replace('_', ' ', $outcome['call_outcome'])); ?></div>
                                <?php if ($outcome['avg_duration']): ?>
                                    <div class="asacp1021_call_meta">Avg: <?php echo round($outcome['avg_duration']); ?> min</div>
                                <?php endif; ?>
                            </div>
                            <span class="asacp1021_metric_value"><?php echo number_format($outcome['count']); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Communication Types -->
            <div class="asacp1021_card">
                <h3>Communication Types</h3>
                <?php if (empty($reportData['communication_types'])): ?>
                    <p class="asacp1021_empty_state">No communication data available</p>
                <?php else: ?>
                    <?php foreach ($reportData['communication_types'] as $type): ?>
                        <div class="asacp1021_list_item">
                            <div>
                                <div><?php echo ucwords(str_replace('_', ' ', $type['contact_type'])); ?></div>
                                <?php if ($type['avg_duration']): ?>
                                    <div class="asacp1021_call_meta">Avg: <?php echo round($type['avg_duration']); ?> min</div>
                                <?php endif; ?>
                            </div>
                            <span class="asacp1021_metric_value"><?php echo number_format($type['count']); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Best Call Times -->
        <?php if (!empty($reportData['best_call_times'])): ?>
        <div class="asacp1021_card">
            <h3>Best Call Times (Highest Success Rate)</h3>
            <div class="asacp1021_table_wrapper">
                <table class="asacp1021_report_table">
                    <thead>
                        <tr>
                            <th>Hour</th>
                            <th>Total Calls</th>
                            <th>Successful Calls</th>
                            <th>Success Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportData['best_call_times'] as $time): ?>
                        <tr>
                            <td><?php echo $time['hour']; ?>:00</td>
                            <td><?php echo number_format($time['total_calls']); ?></td>
                            <td><?php echo number_format($time['successful_calls']); ?></td>
                            <td>
                                <span class="asacp1021_conversion_badge">
                                    <?php echo number_format($time['success_rate'], 1); ?>%
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Task Analysis -->
        <div class="asacp1021_card">
            <h3>Task Completion Analysis</h3>
            <?php if (empty($reportData['task_analysis'])): ?>
                <p class="asacp1021_empty_state">No task data available</p>
            <?php else: ?>
                <div class="asacp1021_table_wrapper">
                    <table class="asacp1021_report_table">
                        <thead>
                            <tr>
                                <th>Task Type</th>
                                <th>Total</th>
                                <th>Completed</th>
                                <th>Overdue</th>
                                <th>Completion %</th>
                                <th>Avg Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData['task_analysis'] as $task): ?>
                            <tr>
                                <td><?php echo ucwords(str_replace('_', ' ', $task['task_type'])); ?></td>
                                <td><?php echo number_format($task['total_tasks']); ?></td>
                                <td><?php echo number_format($task['completed_tasks']); ?></td>
                                <td><?php echo number_format($task['overdue_tasks'] ?: 0); ?></td>
                                <td>
                                    <span class="asacp1021_conversion_badge">
                                        <?php echo number_format($task['completion_rate'], 1); ?>%
                                    </span>
                                </td>
                                <td><?php echo $task['avg_completion_time'] ? round($task['avg_completion_time']) . ' min' : '-'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Generated Report Footer -->
    <div class="asacp1021_report_footer">
        <p>Report generated on <?php echo date('F j, Y \a\t g:i A'); ?> • CRM System</p>
    </div>
</div>

<style>
/* Google-style CRM Reports - asacp1021_ prefix */
body {
    background: #f8f9fa;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
}

.asacp1021_reports_container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 24px 48px 24px;
}

.asacp1021_report_header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}

.asacp1021_report_header h1 {
    margin: 0;
    color: #202124;
    font-weight: 400;
    font-size: 28px;
}

.asacp1021_report_header p {
    margin: 4px 0 0 0;
    color: #5f6368;
    font-size: 14px;
}

.asacp1021_header_actions {
    display: flex;
    gap: 12px;
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
    gap: 16px;
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

.asacp1021_filter_group select {
    padding: 8px 12px;
    border: 1px solid #dadce0;
    border-radius: 4px;
    font-size: 14px;
    color: #202124;
    background: white;
    transition: all 0.2s;
}

.asacp1021_filter_group select:focus {
    outline: none;
    border-color: #1a73e8;
    box-shadow: 0 0 0 2px rgba(26, 115, 232, 0.1);
}

.asacp1021_period_badge {
    background: #e8f0fe;
    border: 1px solid #aecbfa;
    border-radius: 8px;
    padding: 16px 20px;
    margin-bottom: 24px;
}

.asacp1021_period_badge strong {
    display: block;
    color: #1967d2;
    font-weight: 500;
    margin-bottom: 4px;
}

.asacp1021_period_badge span {
    color: #1967d2;
    font-size: 14px;
}

.asacp1021_stats_grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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
}

.asacp1021_stat_meta {
    font-size: 11px;
    color: #5f6368;
    margin-top: 4px;
}

.asacp1021_card {
    background: white;
    border-radius: 8px;
    padding: 20px;
    border: 1px solid #dadce0;
    margin-bottom: 16px;
}

.asacp1021_card h3 {
    margin: 0 0 16px 0;
    color: #202124;
    font-weight: 500;
    font-size: 16px;
}

.asacp1021_grid_2col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}

.asacp1021_grid_3col {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 16px;
}

.asacp1021_list_item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px;
    background: #f8f9fa;
    border-radius: 4px;
    margin-bottom: 8px;
}

.asacp1021_metric_value {
    font-weight: 600;
    color: #202124;
}

.asacp1021_metric_badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
    text-transform: uppercase;
}

.asacp1021_priority_urgent {
    background: #fce8e6;
    color: #c5221f;
}

.asacp1021_priority_high {
    background: #fef7e0;
    color: #f29900;
}

.asacp1021_priority_medium {
    background: #e8f0fe;
    color: #1967d2;
}

.asacp1021_priority_low {
    background: #e6f4ea;
    color: #137333;
}

.asacp1021_stage_grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 12px;
}

.asacp1021_stage_item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: #f8f9fa;
    border-radius: 6px;
}

.asacp1021_stage_icon {
    font-size: 24px;
}

.asacp1021_stage_label {
    font-weight: 500;
    color: #202124;
    font-size: 13px;
}

.asacp1021_stage_count {
    font-size: 12px;
    color: #5f6368;
}

.asacp1021_geo_state {
    font-weight: 600;
    color: #202124;
}

.asacp1021_geo_meta {
    font-size: 12px;
    color: #5f6368;
    margin-top: 2px;
}

.asacp1021_source_name {
    font-weight: 600;
    color: #202124;
}

.asacp1021_source_meta {
    font-size: 12px;
    color: #5f6368;
    margin-top: 2px;
}

.asacp1021_conversion_badge {
    display: inline-block;
    padding: 4px 8px;
    background: #e6f4ea;
    color: #137333;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.asacp1021_quality_badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
}

.asacp1021_quality_complete {
    background: #e6f4ea;
    color: #137333;
}

.asacp1021_quality_partial {
    background: #fef7e0;
    color: #f29900;
}

.asacp1021_quality_incomplete {
    background: #fce8e6;
    color: #c5221f;
}

.asacp1021_quality_score {
    font-weight: 600;
    font-size: 14px;
    white-space: nowrap;
}

.asacp1021_quality_bar {
    position: relative;
    min-width: 80px;
}

.asacp1021_days_badge {
    display: inline-block;
    padding: 4px 8px;
    background: #fef7e0;
    color: #f29900;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
}

.asacp1021_call_meta {
    font-size: 12px;
    color: #5f6368;
    margin-top: 2px;
}

.asacp1021_table_wrapper {
    overflow-x: auto;
}

.asacp1021_report_table {
    width: 100%;
    border-collapse: collapse;
}

.asacp1021_report_table thead tr {
    background: #f8f9fa;
    border-bottom: 1px solid #dadce0;
}

.asacp1021_report_table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 500;
    color: #5f6368;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.asacp1021_report_table td {
    padding: 12px 16px;
    border-bottom: 1px solid #e8eaed;
    font-size: 14px;
    color: #202124;
}

.asacp1021_report_table tbody tr {
    transition: background-color 0.1s;
}

.asacp1021_report_table tbody tr:hover {
    background-color: #f8f9fa;
}

.asacp1021_agent_name {
    font-weight: 600;
    color: #202124;
    margin-bottom: 2px;
}

.asacp1021_agent_name a {
    color: #1a73e8;
    text-decoration: none;
}

.asacp1021_agent_name a:hover {
    text-decoration: underline;
}

.asacp1021_agent_email {
    font-size: 12px;
    color: #5f6368;
}

.asacp1021_empty_state {
    text-align: center;
    padding: 32px;
    color: #5f6368;
    font-size: 14px;
}

.asacp1021_report_footer {
    margin-top: 48px;
    padding-top: 24px;
    border-top: 1px solid #dadce0;
    text-align: center;
}

.asacp1021_report_footer p {
    margin: 0;
    color: #5f6368;
    font-size: 12px;
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .asacp1021_reports_container {
        padding: 0 16px 32px 16px;
    }

    .asacp1021_report_header {
        flex-direction: column;
        align-items: flex-start;
        gap: 16px;
    }

    .asacp1021_header_actions {
        width: 100%;
    }

    .asacp1021_filter_bar form {
        flex-direction: column;
    }

    .asacp1021_filter_group {
        width: 100%;
    }

    .asacp1021_stats_grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .asacp1021_grid_2col,
    .asacp1021_grid_3col {
        grid-template-columns: 1fr;
    }

    .asacp1021_stage_grid {
        grid-template-columns: 1fr;
    }

    .asacp1021_table_wrapper {
        position: relative;
    }

    .asacp1021_report_table {
        font-size: 12px;
    }

    .asacp1021_report_table th,
    .asacp1021_report_table td {
        padding: 8px;
    }
}

@media (max-width: 480px) {
    .asacp1021_stats_grid {
        grid-template-columns: 1fr;
    }

    .asacp1021_stat_value {
        font-size: 24px;
    }
}

/* Print Styles */
@media print {
    .asacp1021_reports_container {
        max-width: none !important;
    }

    .btn,
    .admin-menu,
    nav,
    .asacp1021_header_actions,
    .asacp1021_filter_bar {
        display: none !important;
    }

    .asacp1021_card {
        break-inside: avoid;
        box-shadow: none !important;
    }

    .asacp1021_report_table {
        page-break-inside: auto;
    }

    .asacp1021_report_table tr {
        page-break-inside: avoid;
        page-break-after: auto;
    }
}
</style>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
