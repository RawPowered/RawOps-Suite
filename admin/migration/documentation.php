<?php
/**
 * Migration Documentation Viewer
 * View implementation guides and documentation
 */

$SEO = [
    'title' => 'Migration Documentation — Admin',
    'description' => 'View URL migration implementation guides and documentation',
    'type' => 'website'
];

require_once __DIR__ . '/../../required/header.php';
require_admin_or_redirect('/admin/');

// Get available documentation files
function getDocumentationFiles() {
    $docs = [];
    
    // Check planning/MDS directory
    $docsDir = __DIR__ . '/../../00-PLANNING/MDS/';
    if (is_dir($docsDir)) {
        // Implementation guides
        if (file_exists($docsDir . 'URL-SYNCHRONIZATION-IMPLEMENTATION-GUIDE.md')) {
            $docs['implementation'] = [
                'path' => $docsDir . 'URL-SYNCHRONIZATION-IMPLEMENTATION-GUIDE.md',
                'title' => 'Implementation Guide',
                'description' => 'Complete guide for URL synchronization implementation'
            ];
        }
        
        // URL plan
        if (file_exists($docsDir . 'PLANS/URL Synchronization Mega Plan.md')) {
            $docs['plan'] = [
                'path' => $docsDir . 'PLANS/URL Synchronization Mega Plan.md',
                'title' => 'URL Synchronization Mega Plan',
                'description' => 'Comprehensive plan for achieving 100% URL synchronization'
            ];
        }
        
        // Rules and guidelines
        if (file_exists($docsDir . '00-RULES-OF-ENGAGEMENT.MD')) {
            $docs['rules'] = [
                'path' => $docsDir . '00-RULES-OF-ENGAGEMENT.MD',
                'title' => 'Rules of Engagement',
                'description' => 'Development rules and guidelines'
            ];
        }
        
        // SQL formatting rules
        if (file_exists($docsDir . '002-SQL-FORMATTING-RULES.md')) {
            $docs['sql'] = [
                'path' => $docsDir . '002-SQL-FORMATTING-RULES.md',
                'title' => 'SQL Formatting Rules',
                'description' => 'Binary-safe SQL formatting guidelines'
            ];
        }
    }
    
    return $docs;
}

// Parse markdown to HTML
function parseMarkdown($text) {
    $html = htmlspecialchars($text);
    
    // Headers
    $html = preg_replace('/^#### (.*?)$/m', '<h4>$1</h4>', $html);
    $html = preg_replace('/^### (.*?)$/m', '<h3>$1</h3>', $html);
    $html = preg_replace('/^## (.*?)$/m', '<h2>$1</h2>', $html);
    $html = preg_replace('/^# (.*?)$/m', '<h1>$1</h1>', $html);
    
    // Bold and italic
    $html = preg_replace('/\*\*\*(.*?)\*\*\*/', '<strong><em>$1</em></strong>', $html);
    $html = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $html);
    $html = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $html);
    
    // Code blocks with language
    $html = preg_replace_callback('/```(\w+)?\n(.*?)\n```/s', function($matches) {
        $lang = $matches[1] ?: 'code';
        $code = htmlspecialchars($matches[2]);
        return '<pre class="code-' . $lang . '"><code>' . $code . '</code></pre>';
    }, $html);
    
    // Inline code
    $html = preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);
    
    // Links
    $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $html);
    
    // Unordered lists
    $html = preg_replace('/^- (.*?)$/m', '<li>$1</li>', $html);
    $html = preg_replace('/(<li>.*?<\/li>\s*)+/s', '<ul>$0</ul>', $html);
    
    // Ordered lists (numbered)
    $html = preg_replace('/^\d+\. (.*?)$/m', '<li>$1</li>', $html);
    
    // Checkboxes
    $html = str_replace('- [ ]', '<input type="checkbox" disabled> ', $html);
    $html = str_replace('- [x]', '<input type="checkbox" checked disabled> ', $html);
    
    // Emojis
    $html = str_replace('✅', '<span class="emoji">✅</span>', $html);
    $html = str_replace('📊', '<span class="emoji">📊</span>', $html);
    $html = str_replace('🔧', '<span class="emoji">🔧</span>', $html);
    $html = str_replace('🚀', '<span class="emoji">🚀</span>', $html);
    
    // Line breaks
    $html = nl2br($html);
    
    return $html;
}

// Get selected document
$selectedDoc = $_GET['doc'] ?? 'implementation';
$docs = getDocumentationFiles();
$docContent = '';

if (isset($docs[$selectedDoc])) {
    $docContent = file_get_contents($docs[$selectedDoc]['path']);
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
        .docs-container {
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
        
        .docs-nav {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .docs-nav-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 15px;
            color: #1f2937;
        }
        
        .docs-tabs {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .doc-tab {
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            color: #374151;
            background: #f3f4f6;
            transition: all 0.2s;
            position: relative;
        }
        
        .doc-tab:hover {
            background: #e5e7eb;
            transform: translateY(-1px);
        }
        
        .doc-tab.active {
            background: linear-gradient(135deg, #059669, #047857);
            color: white;
        }
        
        .doc-content {
            background: white;
            border-radius: 8px;
            padding: 40px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            min-height: 500px;
        }
        
        .doc-content h1 {
            color: #059669;
            font-size: 32px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 3px solid #059669;
        }
        
        .doc-content h2 {
            color: #047857;
            font-size: 24px;
            margin-top: 35px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .doc-content h3 {
            color: #374151;
            font-size: 20px;
            margin-top: 25px;
            margin-bottom: 15px;
        }
        
        .doc-content h4 {
            color: #6b7280;
            font-size: 18px;
            margin-top: 20px;
            margin-bottom: 10px;
        }
        
        .doc-content code {
            background: #f3f4f6;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            color: #dc2626;
        }
        
        .doc-content pre {
            background: #1f2937;
            color: #f9fafb;
            padding: 20px;
            border-radius: 8px;
            overflow-x: auto;
            margin: 20px 0;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .doc-content pre code {
            background: transparent;
            color: #f9fafb;
            padding: 0;
        }
        
        .doc-content ul,
        .doc-content ol {
            margin: 15px 0;
            padding-left: 30px;
        }
        
        .doc-content li {
            margin: 8px 0;
            line-height: 1.6;
        }
        
        .doc-content a {
            color: #059669;
            text-decoration: underline;
        }
        
        .doc-content a:hover {
            color: #047857;
        }
        
        .doc-content strong {
            color: #1f2937;
            font-weight: 600;
        }
        
        .doc-content em {
            font-style: italic;
            color: #4b5563;
        }
        
        .doc-content blockquote {
            border-left: 4px solid #059669;
            padding-left: 20px;
            margin: 20px 0;
            color: #4b5563;
            font-style: italic;
        }
        
        .doc-content table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        
        .doc-content th {
            background: #f3f4f6;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            border: 1px solid #e5e7eb;
        }
        
        .doc-content td {
            padding: 12px;
            border: 1px solid #e5e7eb;
        }
        
        .doc-content input[type="checkbox"] {
            margin-right: 8px;
        }
        
        .code-php { border-left: 3px solid #7c3aed; }
        .code-javascript, .code-js { border-left: 3px solid #f59e0b; }
        .code-sql { border-left: 3px solid #3b82f6; }
        .code-bash { border-left: 3px solid #10b981; }
        .code-html { border-left: 3px solid #ef4444; }
        .code-css { border-left: 3px solid #06b6d4; }
        
        .toc {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .toc-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 15px;
            color: #1f2937;
        }
        
        .toc ul {
            list-style: none;
            padding-left: 0;
        }
        
        .toc li {
            margin: 5px 0;
            padding-left: 20px;
            position: relative;
        }
        
        .toc li::before {
            content: '→';
            position: absolute;
            left: 0;
            color: #059669;
        }
        
        .emoji {
            font-size: 1.2em;
        }
        
        @media (max-width: 768px) {
            .doc-content {
                padding: 20px;
            }
            
            .docs-tabs {
                flex-direction: column;
            }
            
            .doc-tab {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="docs-container">
        <div class="page-header">
            <h1>📚 Migration Documentation</h1>
            <a href="index.php" class="back-btn">← Back to Dashboard</a>
        </div>
        
        <?php if (!empty($docs)): ?>
        <div class="docs-nav">
            <h3 class="docs-nav-title">Available Documentation</h3>
            <div class="docs-tabs">
                <?php foreach ($docs as $key => $doc): ?>
                <a href="?doc=<?= $key ?>" 
                   class="doc-tab <?= $selectedDoc === $key ? 'active' : '' ?>">
                    <?= htmlspecialchars($doc['title']) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="doc-content">
            <?php if ($docContent): ?>
                <?= parseMarkdown($docContent) ?>
            <?php else: ?>
                <h2>Documentation Not Found</h2>
                <p>The requested documentation file could not be found.</p>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="doc-content">
            <h2>No Documentation Available</h2>
            <p>Documentation files have not been created yet.</p>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
