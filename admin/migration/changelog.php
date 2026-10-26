<?php
/**
 * Changelog Viewer
 * Displays migration changelog and system updates
 */

$SEO = [
    'title' => 'Migration Changelog — Admin',
    'description' => 'View detailed changelog of URL migration activities',
    'type' => 'website'
];

require_once __DIR__ . '/../../required/header.php';
require_admin_or_redirect('/admin/');

// Get available changelog files
function getChangelogs() {
    $changelogs = [];
    
    // Check main changelog directory
    $changelogDir = __DIR__ . '/../../00-PLANNING/MDS/CHANGELOGS/';
    if (is_dir($changelogDir)) {
        $files = glob($changelogDir . '*.md');
        foreach ($files as $file) {
            $changelogs[] = [
                'path' => $file,
                'name' => basename($file),
                'date' => date('Y-m-d H:i:s', filemtime($file)),
                'size' => filesize($file)
            ];
        }
    }
    
    // Sort by date descending
    usort($changelogs, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });
    
    return $changelogs;
}

// Parse markdown to HTML
function parseMarkdown($text) {
    // Basic markdown parsing
    $html = htmlspecialchars($text);
    
    // Headers
    $html = preg_replace('/^### (.*?)$/m', '<h3>$1</h3>', $html);
    $html = preg_replace('/^## (.*?)$/m', '<h2>$1</h2>', $html);
    $html = preg_replace('/^# (.*?)$/m', '<h1>$1</h1>', $html);
    
    // Bold
    $html = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $html);
    
    // Italic
    $html = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $html);
    
    // Code blocks
    $html = preg_replace('/```([^`]+)```/', '<pre><code>$1</code></pre>', $html);
    
    // Inline code
    $html = preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);
    
    // Lists
    $html = preg_replace('/^- (.*?)$/m', '<li>$1</li>', $html);
    $html = preg_replace('/(<li>.*?<\/li>\s*)+/s', '<ul>$0</ul>', $html);
    
    // Checkmarks
    $html = str_replace('✅', '<span class="checkmark">✅</span>', $html);
    $html = str_replace('- [ ]', '<span class="checkbox-empty">☐</span>', $html);
    $html = str_replace('- [x]', '<span class="checkbox-checked">☑</span>', $html);
    
    // Line breaks
    $html = nl2br($html);
    
    return $html;
}

// Get selected changelog
$selectedFile = $_GET['file'] ?? '';
$changelogContent = '';
$changelogs = getChangelogs();

if ($selectedFile) {
    // Validate file path to prevent directory traversal
    $selectedFile = basename($selectedFile);
    foreach ($changelogs as $changelog) {
        if (basename($changelog['path']) === $selectedFile) {
            $changelogContent = file_get_contents($changelog['path']);
            break;
        }
    }
} elseif (!empty($changelogs)) {
    // Load most recent by default
    $changelogContent = file_get_contents($changelogs[0]['path']);
    $selectedFile = $changelogs[0]['name'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $SEO['title'] ?></title>
    <link rel="stylesheet" href="/assets/style.css?v=<?= ASSET_VER ?>">
    <style>
        .changelog-container {
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
        
        .changelog-layout {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 30px;
        }
        
        .changelog-sidebar {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            max-height: calc(100vh - 200px);
            overflow-y: auto;
            position: sticky;
            top: 20px;
        }
        
        .sidebar-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 20px;
            color: #1f2937;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 10px;
        }
        
        .changelog-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .changelog-item {
            margin-bottom: 10px;
        }
        
        .changelog-link {
            display: block;
            padding: 12px;
            border-radius: 6px;
            text-decoration: none;
            color: #374151;
            transition: all 0.2s;
            border: 1px solid #e5e7eb;
        }
        
        .changelog-link:hover {
            background: #f9fafb;
            border-color: #059669;
            transform: translateX(4px);
        }
        
        .changelog-link.active {
            background: linear-gradient(135deg, #059669, #047857);
            color: white;
            border-color: transparent;
        }
        
        .changelog-name {
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .changelog-meta {
            font-size: 12px;
            opacity: 0.8;
        }
        
        .changelog-content {
            background: white;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            min-height: 500px;
        }
        
        .changelog-content h1 {
            color: #059669;
            border-bottom: 3px solid #059669;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        
        .changelog-content h2 {
            color: #047857;
            margin-top: 30px;
            margin-bottom: 15px;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 8px;
        }
        
        .changelog-content h3 {
            color: #374151;
            margin-top: 20px;
            margin-bottom: 10px;
        }
        
        .changelog-content code {
            background: #f3f4f6;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            color: #dc2626;
        }
        
        .changelog-content pre {
            background: #1f2937;
            color: #f9fafb;
            padding: 15px;
            border-radius: 6px;
            overflow-x: auto;
            margin: 15px 0;
        }
        
        .changelog-content pre code {
            background: transparent;
            color: #f9fafb;
            padding: 0;
        }
        
        .changelog-content ul {
            margin: 10px 0;
            padding-left: 20px;
        }
        
        .changelog-content li {
            margin: 5px 0;
            list-style: none;
            position: relative;
            padding-left: 20px;
        }
        
        .changelog-content li::before {
            content: '•';
            position: absolute;
            left: 0;
            color: #059669;
            font-weight: bold;
        }
        
        .checkmark {
            color: #059669;
            font-weight: bold;
        }
        
        .checkbox-empty,
        .checkbox-checked {
            margin-right: 5px;
        }
        
        .checkbox-checked {
            color: #059669;
        }
        
        .stats-summary {
            background: #f9fafb;
            border-left: 4px solid #059669;
            padding: 15px 20px;
            margin: 20px 0;
            border-radius: 4px;
        }
        
        .stats-summary h4 {
            margin-top: 0;
            color: #059669;
        }
        
        .no-changelog {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }
        
        .no-changelog-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        
        .create-btn {
            display: inline-block;
            background: #059669;
            color: white;
            padding: 12px 24px;
            border-radius: 6px;
            text-decoration: none;
            margin-top: 20px;
            transition: background 0.2s;
        }
        
        .create-btn:hover {
            background: #047857;
        }
        
        @media (max-width: 768px) {
            .changelog-layout {
                grid-template-columns: 1fr;
            }
            
            .changelog-sidebar {
                position: static;
                max-height: none;
            }
        }
    </style>
</head>
<body>
    <div class="changelog-container">
        <div class="page-header">
            <h1>📜 Migration Changelog</h1>
            <a href="index.php" class="back-btn">← Back to Dashboard</a>
        </div>
        
        <?php if (empty($changelogs)): ?>
        <div class="changelog-content">
            <div class="no-changelog">
                <div class="no-changelog-icon">📭</div>
                <h2>No Changelogs Found</h2>
                <p>No changelog files have been created yet.</p>
                <a href="index.php" class="create-btn">Return to Dashboard</a>
            </div>
        </div>
        <?php else: ?>
        
        <div class="changelog-layout">
            <div class="changelog-sidebar">
                <h3 class="sidebar-title">Available Changelogs</h3>
                <ul class="changelog-list">
                    <?php foreach ($changelogs as $changelog): ?>
                    <li class="changelog-item">
                        <a href="?file=<?= urlencode($changelog['name']) ?>" 
                           class="changelog-link <?= $changelog['name'] === $selectedFile ? 'active' : '' ?>">
                            <div class="changelog-name">
                                <?= htmlspecialchars($changelog['name']) ?>
                            </div>
                            <div class="changelog-meta">
                                <?= date('M j, Y g:i A', strtotime($changelog['date'])) ?>
                                <br>
                                <?= number_format($changelog['size'] / 1024, 1) ?> KB
                            </div>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            
            <div class="changelog-content">
                <?php if ($changelogContent): ?>
                    <?php
                    // Add some statistics if this is the main changelog
                    if (strpos($selectedFile, '9-29-2025') !== false): ?>
                    <div class="stats-summary">
                        <h4>📊 Implementation Summary</h4>
                        <p><strong>Detection Patterns:</strong> 40+ unique patterns for finding URL issues</p>
                        <p><strong>Replacement Patterns:</strong> 90+ patterns for automatic fixing</p>
                        <p><strong>Database Tables:</strong> 30+ tables covered in cleanup script</p>
                        <p><strong>Success Target:</strong> 100% URL synchronization</p>
                    </div>
                    <?php endif; ?>
                    
                    <?= parseMarkdown($changelogContent) ?>
                <?php else: ?>
                    <div class="no-changelog">
                        <div class="no-changelog-icon">📄</div>
                        <h2>Select a Changelog</h2>
                        <p>Choose a changelog from the sidebar to view its contents.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php endif; ?>
    </div>
</body>
</html>
