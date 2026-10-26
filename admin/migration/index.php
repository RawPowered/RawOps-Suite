<?php
/**
 * URL Migration Suite Dashboard
 * Central hub for all URL synchronization and migration tasks
 */

$SEO = [
    'title' => 'Migration Suite — Admin Dashboard',
    'description' => 'Comprehensive URL migration and synchronization management',
    'type' => 'website'
];

require_once __DIR__ . '/../../required/header.php';
require_admin_or_redirect('/admin/');

$pdo = db();

// Get migration statistics
function getMigrationStats($pdo) {
    $stats = [
        'total_issues' => 0,
        'files_scanned' => 0,
        'database_issues' => 0,
        'last_scan' => null,
        'migration_status' => 'pending',
        'success_rate' => 0
    ];
    
    // Check for recent scan results
    $logsDir = __DIR__ . '/../logs/';
    if (is_dir($logsDir)) {
        $scanFiles = glob($logsDir . 'url-scan-v2-*.txt');
        if (!empty($scanFiles)) {
            $lastScan = end($scanFiles);
            $stats['last_scan'] = date('Y-m-d H:i:s', filemtime($lastScan));
            
            // Parse scan file for stats
            $content = file_get_contents($lastScan);
            if (preg_match('/Files scanned: (\d+)/', $content, $matches)) {
                $stats['files_scanned'] = intval($matches[1]);
            }
            if (preg_match('/Files with issues: (\d+)/', $content, $matches)) {
                $stats['total_issues'] = intval($matches[1]);
            }
        }
    }
    
    // Check database for URL issues
    try {
        $tables = [
            'products' => ['name', 'description', 'image_url'],
            'training_modules' => ['title', 'content', 'resources'],
            'email_templates' => ['subject', 'body'],
            'users' => ['email', 'first_name', 'last_name']
        ];
        
        foreach ($tables as $table => $columns) {
            $conditions = [];
            foreach ($columns as $col) {
                $conditions[] = "$col LIKE '%/%' OR $col LIKE '%legacy.example%'";
            }
            $sql = "SELECT COUNT(*) FROM $table WHERE " . implode(' OR ', $conditions);
            $count = $pdo->query($sql)->fetchColumn();
            $stats['database_issues'] += $count;
        }
    } catch (Exception $e) {
        // Table might not exist
    }
    
    // Calculate success rate
    if ($stats['total_issues'] > 0 || $stats['database_issues'] > 0) {
        $totalPossible = 1000; // Estimate
        $remaining = $stats['total_issues'] + $stats['database_issues'];
        $stats['success_rate'] = max(0, round(((($totalPossible - $remaining) / $totalPossible) * 100), 1));
    } else {
        $stats['success_rate'] = 100;
    }
    
    return $stats;
}

$stats = getMigrationStats($pdo);

// Check which tools are available
$tools = [
    'scanner_v1' => file_exists(__DIR__ . '/../enhanced-url-scanner.php'),
    'scanner_v2' => file_exists(__DIR__ . '/../ultimate-url-scanner-v2.php'),
    'migration_v1' => file_exists(__DIR__ . '/../url-migration-tool.php'),
    'migration_v2' => file_exists(__DIR__ . '/../enhanced-url-migration-tool.php'),
    'verification' => file_exists(__DIR__ . '/../url-verification.php'),
    'diagnostics' => file_exists(__DIR__ . '/../migration-diagnostics.php'),
    'monitor' => file_exists(__DIR__ . '/../automated-url-monitor.php')
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $SEO['title'] ?></title>
    <link rel="stylesheet" href="/assets/style.css?v=<?= ASSET_VER ?>">
    <style>
        .migration-dashboard {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .dashboard-header {
            background: linear-gradient(135deg, #059669, #047857);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .dashboard-header h1 {
            margin: 0 0 10px 0;
            font-size: 32px;
        }
        
        .dashboard-header p {
            margin: 0;
            opacity: 0.9;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        
        .stat-value {
            font-size: 36px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #6b7280;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-card.success { border-left: 4px solid #059669; }
        .stat-card.success .stat-value { color: #059669; }
        
        .stat-card.warning { border-left: 4px solid #f59e0b; }
        .stat-card.warning .stat-value { color: #f59e0b; }
        
        .stat-card.danger { border-left: 4px solid #dc2626; }
        .stat-card.danger .stat-value { color: #dc2626; }
        
        .stat-card.info { border-left: 4px solid #3b82f6; }
        .stat-card.info .stat-value { color: #3b82f6; }
        
        .tools-section {
            margin-bottom: 30px;
        }
        
        .section-title {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 20px;
            color: #1f2937;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 10px;
        }
        
        .tools-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .tool-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .tool-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #059669, #047857);
        }
        
        .tool-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.15);
        }
        
        .tool-card.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .tool-card.disabled:hover {
            transform: none;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .tool-icon {
            font-size: 32px;
            margin-bottom: 15px;
        }
        
        .tool-title {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 10px;
            color: #1f2937;
        }
        
        .tool-description {
            color: #6b7280;
            margin-bottom: 15px;
            font-size: 14px;
            line-height: 1.5;
        }
        
        .tool-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
            display: inline-block;
            border: none;
            cursor: pointer;
        }
        
        .btn-primary {
            background: #059669;
            color: white;
        }
        
        .btn-primary:hover {
            background: #047857;
        }
        
        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }
        
        .btn-secondary:hover {
            background: #d1d5db;
        }
        
        .progress-bar {
            background: #e5e7eb;
            border-radius: 12px;
            height: 24px;
            overflow: hidden;
            margin-top: 20px;
            position: relative;
        }
        
        .progress-fill {
            background: linear-gradient(90deg, #059669, #047857);
            height: 100%;
            border-radius: 12px;
            transition: width 1s ease-out;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 12px;
        }
        
        .quick-actions {
            background: #f9fafb;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            margin-top: 15px;
        }
        
        .quick-action-btn {
            padding: 12px 20px;
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            text-align: center;
            text-decoration: none;
            color: #374151;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .quick-action-btn:hover {
            border-color: #059669;
            color: #059669;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(5, 150, 105, 0.1);
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-warning {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            color: #92400e;
        }
        
        .alert-info {
            background: #dbeafe;
            border-left: 4px solid #3b82f6;
            color: #1e40af;
        }
        
        .alert-success {
            background: #d1fae5;
            border-left: 4px solid #059669;
            color: #065f46;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .badge-new {
            background: #fbbf24;
            color: #78350f;
        }
        
        .badge-recommended {
            background: #059669;
            color: white;
        }
    </style>
</head>
<body>
    <div class="migration-dashboard">
        <div class="dashboard-header">
            <h1>🚀 URL Migration Suite</h1>
            <p>Comprehensive tools for URL synchronization from product.example to product.example</p>
        </div>
        
        <?php if ($stats['database_issues'] > 0 || $stats['total_issues'] > 0): ?>
        <div class="alert alert-warning">
            <strong>⚠️ Migration Required:</strong> 
            Found <?= $stats['total_issues'] ?> file issues and <?= $stats['database_issues'] ?> database issues that need attention.
        </div>
        <?php else: ?>
        <div class="alert alert-success">
            <strong>✅ All Clear:</strong> 
            No URL issues detected. Your migration appears to be complete!
        </div>
        <?php endif; ?>
        
        <div class="stats-grid">
            <div class="stat-card <?= $stats['success_rate'] >= 95 ? 'success' : ($stats['success_rate'] >= 80 ? 'warning' : 'danger') ?>">
                <div class="stat-value"><?= $stats['success_rate'] ?>%</div>
                <div class="stat-label">Success Rate</div>
            </div>
            
            <div class="stat-card <?= $stats['total_issues'] == 0 ? 'success' : 'warning' ?>">
                <div class="stat-value"><?= number_format($stats['total_issues']) ?></div>
                <div class="stat-label">File Issues</div>
            </div>
            
            <div class="stat-card <?= $stats['database_issues'] == 0 ? 'success' : 'danger' ?>">
                <div class="stat-value"><?= number_format($stats['database_issues']) ?></div>
                <div class="stat-label">Database Issues</div>
            </div>
            
            <div class="stat-card info">
                <div class="stat-value"><?= $stats['last_scan'] ? date('H:i', strtotime($stats['last_scan'])) : 'Never' ?></div>
                <div class="stat-label">Last Scan</div>
            </div>
        </div>
        
        <div class="progress-bar">
            <div class="progress-fill" style="width: <?= $stats['success_rate'] ?>%">
                <?= $stats['success_rate'] ?>% Complete
            </div>
        </div>
        
        <div class="quick-actions">
            <h3>🎯 Quick Actions</h3>
            <div class="quick-actions-grid">
                <a href="../ultimate-url-scanner-v2.php" class="quick-action-btn">
                    🔍 Run Full Scan
                </a>
                <a href="../enhanced-url-migration-tool.php?action=scan&dry_run=1" class="quick-action-btn">
                    📋 Preview Changes
                </a>
                <a href="changelog.php" class="quick-action-btn">
                    📜 View Changelog
                </a>
                <a href="../url-verification.php" class="quick-action-btn">
                    ✅ Verify URLs
                </a>
            </div>
        </div>
        
        <div class="tools-section">
            <h2 class="section-title">🔍 Detection & Analysis</h2>
            <div class="tools-grid">
                <?php if ($tools['scanner_v2']): ?>
                <div class="tool-card">
                    <div class="tool-icon">🔬</div>
                    <div class="tool-title">
                        URL Scanner V2
                        <span class="badge badge-recommended">Recommended</span>
                    </div>
                    <div class="tool-description">
                        Advanced scanner with 40+ detection patterns including forms, JSON, CSS, and JavaScript-specific patterns. Provides priority-based categorization.
                    </div>
                    <div class="tool-actions">
                        <a href="../ultimate-url-scanner-v2.php" class="btn btn-primary">Open Scanner</a>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($tools['scanner_v1']): ?>
                <div class="tool-card">
                    <div class="tool-icon">🔎</div>
                    <div class="tool-title">URL Scanner V1</div>
                    <div class="tool-description">
                        Original URL scanner with basic pattern detection. Use V2 for more comprehensive results.
                    </div>
                    <div class="tool-actions">
                        <a href="../enhanced-url-scanner.php" class="btn btn-secondary">Open Scanner</a>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($tools['diagnostics']): ?>
                <div class="tool-card">
                    <div class="tool-icon">🏥</div>
                    <div class="tool-title">Migration Diagnostics</div>
                    <div class="tool-description">
                        Comprehensive diagnostics tool to identify database issues, check configurations, and generate fix SQL.
                    </div>
                    <div class="tool-actions">
                        <a href="../migration-diagnostics.php" class="btn btn-primary">Run Diagnostics</a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="tools-section">
            <h2 class="section-title">🔧 Migration Tools</h2>
            <div class="tools-grid">
                <?php if ($tools['migration_v2']): ?>
                <div class="tool-card">
                    <div class="tool-icon">⚡</div>
                    <div class="tool-title">
                        Migration Tool V2
                        <span class="badge badge-new">New</span>
                    </div>
                    <div class="tool-description">
                        Enhanced migration with 90+ replacement patterns, risk assessment, and dry-run mode. Automatically creates backups.
                    </div>
                    <div class="tool-actions">
                        <a href="../enhanced-url-migration-tool.php" class="btn btn-primary">Open Tool</a>
                        <a href="../enhanced-url-migration-tool.php?action=scan&dry_run=1" class="btn btn-secondary">Dry Run</a>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($tools['migration_v1']): ?>
                <div class="tool-card">
                    <div class="tool-icon">🛠️</div>
                    <div class="tool-title">Migration Tool V1</div>
                    <div class="tool-description">
                        Original migration tool with snapshot support and rollback capability. Safe but less comprehensive than V2.
                    </div>
                    <div class="tool-actions">
                        <a href="../url-migration-tool.php" class="btn btn-secondary">Open Tool</a>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="tool-card">
                    <div class="tool-icon">💾</div>
                    <div class="tool-title">Database Migration</div>
                    <div class="tool-description">
                        Execute the comprehensive database cleanup script to fix URL issues in 30+ tables.
                    </div>
                    <div class="tool-actions">
                        <a href="database-migration.php" class="btn btn-primary">Manage DB</a>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="tools-section">
            <h2 class="section-title">✅ Verification & Monitoring</h2>
            <div class="tools-grid">
                <?php if ($tools['verification']): ?>
                <div class="tool-card">
                    <div class="tool-icon">🎯</div>
                    <div class="tool-title">URL Verification</div>
                    <div class="tool-description">
                        Test all site URLs to ensure they return proper status codes. Identifies 404 errors and response times.
                    </div>
                    <div class="tool-actions">
                        <a href="../url-verification.php" class="btn btn-primary">Verify URLs</a>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($tools['monitor']): ?>
                <div class="tool-card">
                    <div class="tool-icon">📊</div>
                    <div class="tool-title">Automated Monitor</div>
                    <div class="tool-description">
                        Set up automated monitoring to continuously check for URL issues and alert on problems.
                    </div>
                    <div class="tool-actions">
                        <a href="../automated-url-monitor.php" class="btn btn-primary">Configure</a>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="tool-card">
                    <div class="tool-icon">📜</div>
                    <div class="tool-title">Changelog Viewer</div>
                    <div class="tool-description">
                        View detailed changelog of all migration activities, updates, and system changes.
                    </div>
                    <div class="tool-actions">
                        <a href="changelog.php" class="btn btn-primary">View Changelog</a>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="tools-section">
            <h2 class="section-title">📚 Resources & Documentation</h2>
            <div class="tools-grid">
                <div class="tool-card">
                    <div class="tool-icon">📖</div>
                    <div class="tool-title">Implementation Guide</div>
                    <div class="tool-description">
                        Complete guide for URL synchronization implementation including step-by-step instructions.
                    </div>
                    <div class="tool-actions">
                        <a href="documentation.php" class="btn btn-primary">View Guide</a>
                    </div>
                </div>
                
                <div class="tool-card">
                    <div class="tool-icon">💻</div>
                    <div class="tool-title">Code Helpers</div>
                    <div class="tool-description">
                        Documentation for PHP and JavaScript URL helper functions to use in your code.
                    </div>
                    <div class="tool-actions">
                        <a href="helpers.php" class="btn btn-primary">View Helpers</a>
                    </div>
                </div>
                
                <div class="tool-card">
                    <div class="tool-icon">🔄</div>
                    <div class="tool-title">Backup & Recovery</div>
                    <div class="tool-description">
                        Tools for creating backups and managing recovery procedures if issues arise.
                    </div>
                    <div class="tool-actions">
                        <a href="backup.php" class="btn btn-primary">Manage Backups</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
