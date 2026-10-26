<?php
/**
 * Database Migration Manager
 * Execute and manage database URL cleanup operations
 */

$SEO = [
    'title' => 'Database Migration Manager — Admin',
    'description' => 'Manage database URL cleanup and migration',
    'type' => 'website'
];

require_once __DIR__ . '/../../required/header.php';
require_admin_or_redirect('/admin/');

$pdo = db();

// Check for migration SQL files
function getMigrationScripts() {
    $scripts = [];
    $migrationDir = __DIR__ . '/../../migrations/';
    
    if (is_dir($migrationDir)) {
        $files = glob($migrationDir . '*url*.sql');
        foreach ($files as $file) {
            $scripts[] = [
                'path' => $file,
                'name' => basename($file),
                'date' => date('Y-m-d H:i:s', filemtime($file)),
                'size' => filesize($file),
                'lines' => count(file($file))
            ];
        }
    }
    
    // Sort by date descending
    usort($scripts, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });
    
    return $scripts;
}

// Analyze database for URL issues
function analyzeDatabase($pdo) {
    $analysis = [
        'total_issues' => 0,
        'tables' => []
    ];
    
    $tables = [
        'users' => ['email', 'first_name', 'last_name'],
        'products' => ['name', 'description', 'image_url'],
        'training_modules' => ['title', 'content', 'resources'],
        'email_templates' => ['subject', 'body'],
        'crm_contacts' => ['first_name', 'last_name', 'email', 'company', 'website', 'notes'],
        'crm_notes' => ['content'],
        'crm_demo_requests' => ['business_name', 'website', 'notes'],
        'crm_leads' => ['business_name', 'website', 'notes'],
        'admin_dev_tasks' => ['title', 'description', 'notes'],
        'admin_history' => ['action', 'details', 'metadata'],
        'operations_log' => ['action', 'details'],
        'orders' => ['notes', 'shipping_address', 'billing_address'],
        'order_items' => ['product_name', 'notes'],
        'ai_chat_logs' => ['message', 'response', 'metadata'],
        'ai_learning_insights' => ['content', 'metadata'],
        'promo_demo_leads' => ['business_name', 'website', 'notes'],
        'wholesale_accounts' => ['business_name', 'business_address', 'website', 'notes'],
        'objection_library' => ['objection', 'response'],
        'suggestion_box' => ['suggestion', 'response']
    ];
    
    foreach ($tables as $table => $columns) {
        try {
            // Check if table exists
            $tableExists = $pdo->query("SHOW TABLES LIKE '$table'")->fetch();
            if (!$tableExists) continue;
            
            $tableIssues = 0;
            $columnIssues = [];
            
            foreach ($columns as $column) {
                // Check if column exists
                $columnExists = $pdo->query("SHOW COLUMNS FROM $table LIKE '$column'")->fetch();
                if (!$columnExists) continue;
                
                // Count issues
                $sql = "SELECT COUNT(*) FROM $table WHERE $column LIKE '%/%' OR $column LIKE '%legacy.example%'";
                $count = $pdo->query($sql)->fetchColumn();
                
                if ($count > 0) {
                    $tableIssues += $count;
                    $columnIssues[$column] = $count;
                }
            }
            
            if ($tableIssues > 0) {
                $analysis['tables'][$table] = [
                    'total' => $tableIssues,
                    'columns' => $columnIssues
                ];
                $analysis['total_issues'] += $tableIssues;
            }
        } catch (Exception $e) {
            // Skip table if error
        }
    }
    
    return $analysis;
}

// Handle actions
$action = $_POST['action'] ?? '';
$message = '';
$messageType = '';
$analysis = null;

if ($action === 'analyze') {
    $analysis = analyzeDatabase($pdo);
    $message = "Analysis complete. Found {$analysis['total_issues']} URL issues across " . count($analysis['tables']) . " tables.";
    $messageType = $analysis['total_issues'] > 0 ? 'warning' : 'success';
} elseif ($action === 'backup') {
    // Generate backup command
    $timestamp = date('YmdHis');
    $backupFile = "product_backup_{$timestamp}.sql";
    $message = "Backup command generated. Execute this in your terminal:";
    $messageType = 'info';
} elseif ($action === 'preview') {
    // Show preview of what will be changed
    $scriptPath = __DIR__ . '/../../migrations/2025-09-29-comprehensive-url-cleanup.sql';
    if (file_exists($scriptPath)) {
        $scriptContent = file_get_contents($scriptPath);
        // Extract first few operations for preview
        preg_match_all('/UPDATE\s+(\w+)\s+SET/i', $scriptContent, $matches);
        $tables = array_unique($matches[1]);
        $message = "Migration will update the following tables: " . implode(', ', array_slice($tables, 0, 10));
        if (count($tables) > 10) {
            $message .= " and " . (count($tables) - 10) . " more...";
        }
        $messageType = 'info';
    }
}

// Get migration scripts
$scripts = getMigrationScripts();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $SEO['title'] ?></title>
    <link rel="stylesheet" href="/assets/style.css?v=<?= ASSET_VER ?>">
    <style>
        .migration-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .page-header {
            background: linear-gradient(135deg, #059669, #047857);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .page-header h1 {
            margin: 0;
            font-size: 28px;
        }
        
        .back-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            transition: background 0.2s;
        }
        
        .back-btn:hover {
            background: rgba(255, 255, 255, 0.3);
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
        
        .alert-danger {
            background: #fee2e2;
            border-left: 4px solid #dc2626;
            color: #991b1b;
        }
        
        .section {
            background: white;
            border-radius: 8px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .section-title {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 20px;
            color: #1f2937;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 10px;
        }
        
        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .action-card {
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s;
        }
        
        .action-card:hover {
            border-color: #059669;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.15);
        }
        
        .action-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        .action-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 10px;
            color: #1f2937;
        }
        
        .action-description {
            color: #6b7280;
            margin-bottom: 15px;
            font-size: 14px;
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 6px;
            border: none;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-block;
            text-decoration: none;
        }
        
        .btn-primary {
            background: #059669;
            color: white;
        }
        
        .btn-primary:hover {
            background: #047857;
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #4b5563;
        }
        
        .btn-danger {
            background: #dc2626;
            color: white;
        }
        
        .btn-danger:hover {
            background: #b91c1c;
        }
        
        .scripts-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .scripts-table th {
            background: #f9fafb;
            text-align: left;
            padding: 12px;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .scripts-table td {
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .scripts-table tr:hover {
            background: #f9fafb;
        }
        
        .script-name {
            font-family: monospace;
            font-weight: 600;
            color: #059669;
        }
        
        .analysis-results {
            margin-top: 20px;
        }
        
        .analysis-table {
            width: 100%;
            margin-top: 15px;
        }
        
        .analysis-table th {
            background: #f3f4f6;
            padding: 10px;
            text-align: left;
            font-weight: 600;
        }
        
        .analysis-table td {
            padding: 10px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .issue-count {
            display: inline-block;
            background: #fef3c7;
            color: #92400e;
            padding: 2px 8px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 12px;
        }
        
        .column-list {
            font-size: 12px;
            color: #6b7280;
        }
        
        .code-block {
            background: #1f2937;
            color: #f9fafb;
            padding: 15px;
            border-radius: 6px;
            font-family: monospace;
            margin: 15px 0;
            overflow-x: auto;
        }
        
        .step-list {
            counter-reset: step-counter;
            list-style: none;
            padding: 0;
        }
        
        .step-list li {
            counter-increment: step-counter;
            margin-bottom: 15px;
            padding-left: 40px;
            position: relative;
        }
        
        .step-list li::before {
            content: counter(step-counter);
            position: absolute;
            left: 0;
            top: 0;
            background: #059669;
            color: white;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="migration-container">
        <div class="page-header">
            <h1>💾 Database Migration Manager</h1>
            <a href="index.php" class="back-btn">← Back to Dashboard</a>
        </div>
        
        <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>">
            <?= htmlspecialchars($message) ?>
            <?php if ($action === 'backup'): ?>
            <div class="code-block">
                mysqldump -u [username] -p [database_name] > <?= $backupFile ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <div class="alert alert-warning">
            <strong>⚠️ Important:</strong> Always create a database backup before running migration scripts. Database changes cannot be easily undone without a backup.
        </div>
        
        <div class="section">
            <h2 class="section-title">🎬 Quick Actions</h2>
            <div class="action-grid">
                <div class="action-card">
                    <div class="action-icon">🔍</div>
                    <div class="action-title">Analyze Database</div>
                    <div class="action-description">Scan all tables for URL issues that need fixing</div>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="action" value="analyze">
                        <button type="submit" class="btn btn-primary">Run Analysis</button>
                    </form>
                </div>
                
                <div class="action-card">
                    <div class="action-icon">💾</div>
                    <div class="action-title">Create Backup</div>
                    <div class="action-description">Generate backup command before migration</div>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="action" value="backup">
                        <button type="submit" class="btn btn-secondary">Get Command</button>
                    </form>
                </div>
                
                <div class="action-card">
                    <div class="action-icon">👁️</div>
                    <div class="action-title">Preview Changes</div>
                    <div class="action-description">See what tables will be affected</div>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="action" value="preview">
                        <button type="submit" class="btn btn-secondary">Preview</button>
                    </form>
                </div>
            </div>
        </div>
        
        <?php if ($analysis): ?>
        <div class="section">
            <h2 class="section-title">📊 Analysis Results</h2>
            <div class="analysis-results">
                <p><strong>Total Issues Found:</strong> <span class="issue-count"><?= $analysis['total_issues'] ?></span></p>
                
                <?php if ($analysis['total_issues'] > 0): ?>
                <table class="analysis-table">
                    <thead>
                        <tr>
                            <th>Table</th>
                            <th>Issues</th>
                            <th>Affected Columns</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($analysis['tables'] as $table => $data): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($table) ?></strong></td>
                            <td><span class="issue-count"><?= $data['total'] ?></span></td>
                            <td class="column-list">
                                <?php foreach ($data['columns'] as $col => $count): ?>
                                    <?= htmlspecialchars($col) ?> (<?= $count ?>)
                                    <?php if ($col !== array_key_last($data['columns'])): ?>, <?php endif; ?>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="alert alert-success">
                    ✅ No URL issues found in the database! Migration appears to be complete.
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="section">
            <h2 class="section-title">📜 Available Migration Scripts</h2>
            <?php if (empty($scripts)): ?>
                <p>No migration scripts found in the /migrations/ directory.</p>
            <?php else: ?>
            <table class="scripts-table">
                <thead>
                    <tr>
                        <th>Script Name</th>
                        <th>Modified</th>
                        <th>Size</th>
                        <th>Lines</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($scripts as $script): ?>
                    <tr>
                        <td class="script-name"><?= htmlspecialchars($script['name']) ?></td>
                        <td><?= date('M j, Y g:i A', strtotime($script['date'])) ?></td>
                        <td><?= number_format($script['size'] / 1024, 1) ?> KB</td>
                        <td><?= number_format($script['lines']) ?></td>
                        <td>
                            <a href="view-script.php?file=<?= urlencode($script['name']) ?>" class="btn btn-secondary" style="padding: 5px 10px; font-size: 12px;">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        
        <div class="section">
            <h2 class="section-title">🚀 Execute Migration</h2>
            <p>Follow these steps to safely execute the database migration:</p>
            
            <ol class="step-list">
                <li>
                    <strong>Create a full database backup</strong><br>
                    <code>mysqldump -u [username] -p [database] > backup_<?= date('Ymd_His') ?>.sql</code>
                </li>
                
                <li>
                    <strong>Run the analysis</strong> (click "Run Analysis" above) to see current issues
                </li>
                
                <li>
                    <strong>Execute the migration script</strong><br>
                    <code>mysql -u [username] -p [database] < migrations/2025-09-29-comprehensive-url-cleanup.sql</code>
                </li>
                
                <li>
                    <strong>Run the analysis again</strong> to verify all issues are resolved
                </li>
                
                <li>
                    <strong>Test critical functionality</strong>:
                    <ul style="margin-top: 10px;">
                        <li>Login/logout flow</li>
                        <li>Admin dashboard access</li>
                        <li>API endpoints</li>
                        <li>Shop functionality</li>
                        <li>Training modules</li>
                    </ul>
                </li>
            </ol>
            
            <div class="alert alert-info" style="margin-top: 20px;">
                <strong>💡 Tip:</strong> The migration script is transaction-safe. If any error occurs during execution, all changes will be rolled back automatically.
            </div>
        </div>
        
        <div class="section">
            <h2 class="section-title">🔄 Rollback Procedure</h2>
            <p>If issues arise after migration, restore from your backup:</p>
            <div class="code-block">
                # Drop existing database (BE CAREFUL!)<br>
                mysql -u [username] -p -e "DROP DATABASE [database_name];"<br><br>
                # Recreate database<br>
                mysql -u [username] -p -e "CREATE DATABASE [database_name];"<br><br>
                # Restore from backup<br>
                mysql -u [username] -p [database_name] < backup_[timestamp].sql
            </div>
        </div>
    </div>
</body>
</html>
