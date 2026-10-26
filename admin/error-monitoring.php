<?php
// Comprehensive Error Monitoring Dashboard
// Real-time error tracking and analysis
require_once __DIR__ . '/../required/config.php';
start_session();
require_admin_or_redirect();

$pdo = db();
$pageTitle = 'Error Monitoring Dashboard';

// Get filter parameters
$filterType = $_GET['type'] ?? 'all';
$filterSeverity = $_GET['severity'] ?? 'all';
$filterResolved = $_GET['resolved'] ?? 'unresolved';
$filterDays = intval($_GET['days'] ?? 7);
$searchQuery = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Build WHERE clause
$where = ['1=1'];
$params = [];

if ($filterType !== 'all') {
    $where[] = 'e.error_type = ?';
    $params[] = $filterType;
}

if ($filterSeverity !== 'all') {
    $where[] = 'e.error_severity = ?';
    $params[] = $filterSeverity;
}

if ($filterResolved === 'resolved') {
    $where[] = 'e.is_resolved = 1';
} elseif ($filterResolved === 'unresolved') {
    $where[] = 'e.is_resolved = 0';
}

$where[] = 'e.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
$params[] = $filterDays;

if ($searchQuery !== '') {
    $where[] = '(e.error_message LIKE ? OR e.path LIKE ? OR e.url LIKE ? OR e.error_code LIKE ?)';
    $searchParam = '%' . $searchQuery . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$whereClause = implode(' AND ', $where);

// Get total count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM error_log_detailed e WHERE $whereClause");
$countStmt->execute($params);
$totalErrors = (int)$countStmt->fetchColumn();
$totalPages = ceil($totalErrors / $perPage);

// Get errors
$sql = "
    SELECT 
        e.*,
        u.email as user_email,
        CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as user_name
    FROM error_log_detailed e
    LEFT JOIN users u ON e.user_id = u.id
    WHERE $whereClause
    ORDER BY e.last_occurrence DESC, e.occurrence_count DESC
    LIMIT $perPage OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$errors = $stmt->fetchAll();

// Get statistics
$stats = $pdo->query("
    SELECT 
        COUNT(*) as total_errors,
        COUNT(DISTINCT session_id) as affected_sessions,
        COUNT(DISTINCT user_id) as affected_users,
        COUNT(DISTINCT path) as affected_paths,
        SUM(occurrence_count) as total_occurrences,
        SUM(CASE WHEN error_severity = 'critical' THEN 1 ELSE 0 END) as critical_count,
        SUM(CASE WHEN error_severity = 'error' THEN 1 ELSE 0 END) as error_count,
        SUM(CASE WHEN error_severity = 'warning' THEN 1 ELSE 0 END) as warning_count,
        SUM(CASE WHEN is_resolved = 0 THEN 1 ELSE 0 END) as unresolved_count
    FROM error_log_detailed
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL $filterDays DAY)
")->fetch();

// Get error type breakdown
$typeBreakdown = $pdo->query("
    SELECT 
        error_type,
        COUNT(*) as count,
        SUM(occurrence_count) as occurrences
    FROM error_log_detailed
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL $filterDays DAY)
    GROUP BY error_type
    ORDER BY count DESC
")->fetchAll();

// Get top error patterns
$topPatterns = $pdo->query("
    SELECT 
        p.*,
        COUNT(e.id) as recent_errors
    FROM error_patterns p
    LEFT JOIN error_log_detailed e ON e.error_id LIKE CONCAT(LEFT(p.pattern_hash, 8), '%')
        AND e.created_at >= DATE_SUB(NOW(), INTERVAL $filterDays DAY)
    WHERE p.last_seen >= DATE_SUB(NOW(), INTERVAL $filterDays DAY)
    GROUP BY p.id
    ORDER BY p.severity_score DESC, p.occurrence_count DESC
    LIMIT 10
")->fetchAll();

// Get recent alerts
$recentAlerts = $pdo->query("
    SELECT 
        a.*,
        e.error_message,
        e.error_code,
        e.path
    FROM error_alerts a
    LEFT JOIN error_log_detailed e ON a.error_log_id = e.id
    WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL $filterDays DAY)
    ORDER BY a.is_acknowledged ASC, a.severity DESC, a.created_at DESC
    LIMIT 20
")->fetchAll();

require_once __DIR__ . '/../required/header.php';
?>

<style>
.error-dashboard {
    padding: 20px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    text-align: center;
}

.stat-card h3 {
    margin: 0 0 10px 0;
    font-size: 32px;
    font-weight: bold;
}

.stat-card p {
    margin: 0;
    color: #666;
    font-size: 14px;
}

.stat-card.critical { border-left: 4px solid #dc3545; }
.stat-card.error { border-left: 4px solid #fd7e14; }
.stat-card.warning { border-left: 4px solid #ffc107; }
.stat-card.info { border-left: 4px solid #17a2b8; }

.filters {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

.filters-row {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
    align-items: end;
}

.filter-group {
    flex: 1;
    min-width: 150px;
}

.filter-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    font-size: 14px;
}

.filter-group select,
.filter-group input {
    width: 100%;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.btn {
    padding: 8px 16px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
}

.btn-primary {
    background: #007bff;
    color: white;
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.error-table {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    overflow: hidden;
    margin-bottom: 20px;
}

.error-table table {
    width: 100%;
    border-collapse: collapse;
}

.error-table th,
.error-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}

.error-table th {
    background: #f8f9fa;
    font-weight: 600;
    font-size: 14px;
}

.error-table tr:hover {
    background: #f8f9fa;
}

.error-row {
    cursor: pointer;
}

.severity-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
}

.severity-critical { background: #dc3545; color: white; }
.severity-error { background: #fd7e14; color: white; }
.severity-warning { background: #ffc107; color: black; }
.severity-info { background: #17a2b8; color: white; }
.severity-debug { background: #6c757d; color: white; }

.type-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    background: #e9ecef;
    color: #495057;
}

.occurrence-count {
    background: #ffc107;
    color: black;
    padding: 2px 6px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: bold;
}

.error-details {
    display: none;
    background: #f8f9fa;
    padding: 15px;
    border-top: 2px solid #dee2e6;
}

.error-details.active {
    display: block;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 15px;
    margin-bottom: 15px;
}

.detail-item {
    background: white;
    padding: 10px;
    border-radius: 4px;
    border: 1px solid #dee2e6;
}

.detail-item strong {
    display: block;
    font-size: 12px;
    color: #666;
    margin-bottom: 5px;
}

.stack-trace {
    background: #2d2d2d;
    color: #f8f8f2;
    padding: 15px;
    border-radius: 4px;
    font-family: 'Courier New', monospace;
    font-size: 12px;
    overflow-x: auto;
    white-space: pre-wrap;
}

.pagination {
    display: flex;
    gap: 5px;
    justify-content: center;
    margin: 20px 0;
}

.pagination a,
.pagination span {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    text-decoration: none;
    color: #007bff;
}

.pagination span {
    background: #007bff;
    color: white;
}

.alert-item {
    background: white;
    border-left: 4px solid #dc3545;
    padding: 15px;
    margin-bottom: 10px;
    border-radius: 4px;
}

.alert-item.acknowledged {
    opacity: 0.6;
    border-left-color: #6c757d;
}

.patterns-list {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

.pattern-item {
    border-bottom: 1px solid #e9ecef;
    padding: 15px 0;
}

.pattern-item:last-child {
    border-bottom: none;
}

.section-title {
    margin: 30px 0 15px 0;
    font-size: 24px;
    font-weight: 600;
}
</style>

<div class="error-dashboard">
    <h1>Error Monitoring Dashboard</h1>
    <p>Real-time error tracking and analysis for the past <?= $filterDays ?> days</p>

    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card critical">
            <h3><?= number_format($stats['critical_count']) ?></h3>
            <p>Critical Errors</p>
        </div>
        <div class="stat-card error">
            <h3><?= number_format($stats['error_count']) ?></h3>
            <p>Errors</p>
        </div>
        <div class="stat-card warning">
            <h3><?= number_format($stats['warning_count']) ?></h3>
            <p>Warnings</p>
        </div>
        <div class="stat-card info">
            <h3><?= number_format($stats['unresolved_count']) ?></h3>
            <p>Unresolved</p>
        </div>
        <div class="stat-card">
            <h3><?= number_format($stats['total_occurrences']) ?></h3>
            <p>Total Occurrences</p>
        </div>
        <div class="stat-card">
            <h3><?= number_format($stats['affected_sessions']) ?></h3>
            <p>Affected Sessions</p>
        </div>
        <div class="stat-card">
            <h3><?= number_format($stats['affected_users']) ?></h3>
            <p>Affected Users</p>
        </div>
        <div class="stat-card">
            <h3><?= number_format($stats['affected_paths']) ?></h3>
            <p>Affected Paths</p>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters">
        <form method="get" action="">
            <div class="filters-row">
                <div class="filter-group">
                    <label>Error Type</label>
                    <select name="type">
                        <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>All Types</option>
                        <option value="http" <?= $filterType === 'http' ? 'selected' : '' ?>>HTTP</option>
                        <option value="javascript" <?= $filterType === 'javascript' ? 'selected' : '' ?>>JavaScript</option>
                        <option value="php" <?= $filterType === 'php' ? 'selected' : '' ?>>PHP</option>
                        <option value="api" <?= $filterType === 'api' ? 'selected' : '' ?>>API</option>
                        <option value="redirect" <?= $filterType === 'redirect' ? 'selected' : '' ?>>Redirect</option>
                        <option value="resource" <?= $filterType === 'resource' ? 'selected' : '' ?>>Resource</option>
                        <option value="performance" <?= $filterType === 'performance' ? 'selected' : '' ?>>Performance</option>
                        <option value="security" <?= $filterType === 'security' ? 'selected' : '' ?>>Security</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Severity</label>
                    <select name="severity">
                        <option value="all" <?= $filterSeverity === 'all' ? 'selected' : '' ?>>All Severities</option>
                        <option value="critical" <?= $filterSeverity === 'critical' ? 'selected' : '' ?>>Critical</option>
                        <option value="error" <?= $filterSeverity === 'error' ? 'selected' : '' ?>>Error</option>
                        <option value="warning" <?= $filterSeverity === 'warning' ? 'selected' : '' ?>>Warning</option>
                        <option value="info" <?= $filterSeverity === 'info' ? 'selected' : '' ?>>Info</option>
                        <option value="debug" <?= $filterSeverity === 'debug' ? 'selected' : '' ?>>Debug</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Status</label>
                    <select name="resolved">
                        <option value="all" <?= $filterResolved === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="unresolved" <?= $filterResolved === 'unresolved' ? 'selected' : '' ?>>Unresolved</option>
                        <option value="resolved" <?= $filterResolved === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Time Period</label>
                    <select name="days">
                        <option value="1" <?= $filterDays === 1 ? 'selected' : '' ?>>Last 24 Hours</option>
                        <option value="7" <?= $filterDays === 7 ? 'selected' : '' ?>>Last 7 Days</option>
                        <option value="30" <?= $filterDays === 30 ? 'selected' : '' ?>>Last 30 Days</option>
                        <option value="90" <?= $filterDays === 90 ? 'selected' : '' ?>>Last 90 Days</option>
                    </select>
                </div>

                <div class="filter-group" style="flex: 2;">
                    <label>Search</label>
                    <input type="text" name="search" placeholder="Search errors..." value="<?= htmlspecialchars($searchQuery) ?>">
                </div>

                <div class="filter-group" style="flex: 0;">
                    <button type="submit" class="btn btn-primary">Filter</button>
                </div>

                <div class="filter-group" style="flex: 0;">
                    <a href="?" class="btn btn-secondary">Reset</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Error Breakdown by Type -->
    <?php if (!empty($typeBreakdown)): ?>
    <h2 class="section-title">Error Types</h2>
    <div class="patterns-list">
        <?php foreach ($typeBreakdown as $type): ?>
        <div class="pattern-item">
            <strong><?= htmlspecialchars($type['error_type']) ?></strong>:
            <?= number_format($type['count']) ?> unique errors,
            <?= number_format($type['occurrences']) ?> total occurrences
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Top Error Patterns -->
    <?php if (!empty($topPatterns)): ?>
    <h2 class="section-title">Top Error Patterns</h2>
    <div class="patterns-list">
        <?php foreach ($topPatterns as $pattern): ?>
        <div class="pattern-item">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <span class="type-badge"><?= htmlspecialchars($pattern['error_type']) ?></span>
                    <span class="type-badge"><?= htmlspecialchars($pattern['error_code'] ?? 'N/A') ?></span>
                    <strong><?= htmlspecialchars($pattern['pattern_template']) ?></strong>
                </div>
                <div>
                    <span class="occurrence-count"><?= number_format($pattern['occurrence_count']) ?> occurrences</span>
                    Severity: <?= number_format($pattern['severity_score'], 1) ?>
                </div>
            </div>
            <?php if (!empty($pattern['notes'])): ?>
            <p style="margin: 10px 0 0 0; color: #666; font-size: 14px;">
                <?= htmlspecialchars($pattern['notes']) ?>
            </p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Recent Alerts -->
    <?php if (!empty($recentAlerts)): ?>
    <h2 class="section-title">Recent Alerts</h2>
    <div style="margin-bottom: 20px;">
        <?php foreach ($recentAlerts as $alert): ?>
        <div class="alert-item <?= $alert['is_acknowledged'] ? 'acknowledged' : '' ?>">
            <div style="display: flex; justify-content: space-between; align-items: start;">
                <div>
                    <strong><?= htmlspecialchars($alert['alert_message']) ?></strong>
                    <p style="margin: 5px 0 0 0; color: #666; font-size: 14px;">
                        <?= htmlspecialchars($alert['error_message'] ?? '') ?>
                        <?php if ($alert['path']): ?>
                        <br><code><?= htmlspecialchars($alert['path']) ?></code>
                        <?php endif; ?>
                    </p>
                </div>
                <div style="text-align: right;">
                    <span class="severity-badge severity-<?= $alert['severity'] ?>">
                        <?= htmlspecialchars($alert['severity']) ?>
                    </span>
                    <p style="margin: 5px 0 0 0; font-size: 12px; color: #999;">
                        <?= date('Y-m-d H:i:s', strtotime($alert['created_at'])) ?>
                    </p>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Error List -->
    <h2 class="section-title">Errors (<?= number_format($totalErrors) ?> total)</h2>
    
    <?php if (empty($errors)): ?>
        <div class="error-table">
            <p style="padding: 40px; text-align: center; color: #999;">
                No errors found matching your criteria. 🎉
            </p>
        </div>
    <?php else: ?>
        <div class="error-table">
            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Severity</th>
                        <th>Message</th>
                        <th>Path</th>
                        <th>Occurrences</th>
                        <th>Last Seen</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($errors as $error): ?>
                    <tr class="error-row" onclick="toggleErrorDetails('error-<?= $error['id'] ?>')">
                        <td>
                            <span class="type-badge"><?= htmlspecialchars($error['error_type']) ?></span>
                            <?php if ($error['error_code']): ?>
                            <br><small><?= htmlspecialchars($error['error_code']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="severity-badge severity-<?= $error['error_severity'] ?>">
                                <?= htmlspecialchars($error['error_severity']) ?>
                            </span>
                        </td>
                        <td>
                            <?= htmlspecialchars(substr($error['error_message'], 0, 100)) ?>
                            <?= strlen($error['error_message']) > 100 ? '...' : '' ?>
                        </td>
                        <td>
                            <code><?= htmlspecialchars($error['path'] ?? 'N/A') ?></code>
                        </td>
                        <td>
                            <span class="occurrence-count"><?= number_format($error['occurrence_count']) ?></span>
                        </td>
                        <td>
                            <?= date('Y-m-d H:i:s', strtotime($error['last_occurrence'])) ?>
                        </td>
                        <td>
                            <?= $error['is_resolved'] ? '✓ Resolved' : '⚠ Unresolved' ?>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="7" style="padding: 0;">
                            <div class="error-details" id="error-<?= $error['id'] ?>">
                                <h3>Error Details</h3>
                                
                                <div class="detail-grid">
                                    <div class="detail-item">
                                        <strong>Error ID</strong>
                                        <?= htmlspecialchars($error['error_id']) ?>
                                    </div>
                                    <div class="detail-item">
                                        <strong>First Occurred</strong>
                                        <?= date('Y-m-d H:i:s', strtotime($error['first_occurrence'])) ?>
                                    </div>
                                    <div class="detail-item">
                                        <strong>Last Occurred</strong>
                                        <?= date('Y-m-d H:i:s', strtotime($error['last_occurrence'])) ?>
                                    </div>
                                    <div class="detail-item">
                                        <strong>Session ID</strong>
                                        <?= htmlspecialchars($error['session_id'] ?? 'N/A') ?>
                                    </div>
                                    <?php if ($error['user_id']): ?>
                                    <div class="detail-item">
                                        <strong>User</strong>
                                        <?= htmlspecialchars($error['user_name'] ?? $error['user_email'] ?? 'Unknown') ?>
                                        (ID: <?= $error['user_id'] ?>)
                                    </div>
                                    <?php endif; ?>
                                    <div class="detail-item">
                                        <strong>IP Address</strong>
                                        <?= htmlspecialchars($error['ip_address'] ?? 'N/A') ?>
                                    </div>
                                    <?php if ($error['file_path']): ?>
                                    <div class="detail-item">
                                        <strong>File</strong>
                                        <?= htmlspecialchars($error['file_path']) ?>
                                        <?php if ($error['line_number']): ?>
                                        : Line <?= $error['line_number'] ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($error['viewport_width']): ?>
                                    <div class="detail-item">
                                        <strong>Viewport</strong>
                                        <?= $error['viewport_width'] ?> x <?= $error['viewport_height'] ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($error['page_load_time_ms']): ?>
                                    <div class="detail-item">
                                        <strong>Page Load Time</strong>
                                        <?= number_format($error['page_load_time_ms']) ?> ms
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <div style="margin-top: 15px;">
                                    <strong>Full Error Message:</strong>
                                    <div style="background: white; padding: 10px; border-radius: 4px; margin-top: 5px; border: 1px solid #dee2e6;">
                                        <?= nl2br(htmlspecialchars($error['error_message'])) ?>
                                    </div>
                                </div>

                                <?php if ($error['stack_trace']): ?>
                                <div style="margin-top: 15px;">
                                    <strong>Stack Trace:</strong>
                                    <div class="stack-trace">
<?= htmlspecialchars($error['stack_trace']) ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if ($error['user_agent']): ?>
                                <div style="margin-top: 15px;">
                                    <strong>User Agent:</strong>
                                    <div style="background: white; padding: 10px; border-radius: 4px; margin-top: 5px; border: 1px solid #dee2e6; font-size: 12px;">
                                        <?= htmlspecialchars($error['user_agent']) ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <div style="margin-top: 15px;">
                                    <button class="btn btn-primary" onclick="resolveError(<?= $error['id'] ?>)">
                                        <?= $error['is_resolved'] ? 'Mark as Unresolved' : 'Mark as Resolved' ?>
                                    </button>
                                    <button class="btn btn-secondary" onclick="window.open('<?= htmlspecialchars($error['url'] ?? $error['path']) ?>', '_blank')">
                                        View Page
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">« Previous</a>
            <?php endif; ?>
            
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <?php if ($i == $page): ?>
                    <span><?= $i ?></span>
                <?php else: ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next »</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
function toggleErrorDetails(id) {
    var details = document.getElementById(id);
    details.classList.toggle('active');
}

function resolveError(errorId) {
    if (!confirm('Are you sure you want to change the resolution status of this error?')) {
        return;
    }

    fetch('/api/error-tracker.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'toggle_resolve',
            error_id: errorId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Failed to update error status');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to update error status');
    });
}

// Auto-refresh statistics every 30 seconds
setInterval(function() {
    location.reload();
}, 30000);
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
