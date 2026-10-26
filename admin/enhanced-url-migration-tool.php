<?php
/**
 * ENHANCED URL MIGRATION TOOL V2
 * Complete URL migration with expanded patterns per Phase 2 of URL Synchronization Mega Plan
 */

$SEO = [
    'title' => 'Enhanced URL Migration Tool V2 — Admin',
    'description' => 'Advanced URL migration with comprehensive patterns and risk assessment',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect('/admin/');

$pdo = db();

// Enhanced Smart Link Replacer with Risk Assessment
class EnhancedSmartLinkReplacer {
    private $replacements = [
        // JavaScript API calls
        'axios.get("/' => 'axios.get("/',
        'axios.post("/' => 'axios.post("/',
        'axios.put("/' => 'axios.put("/',
        'axios.delete("/' => 'axios.delete("/',
        'axios.patch("/' => 'axios.patch("/',
        
        // jQuery form actions
        "$('#form').attr('action', '/" => "$('#form').attr('action', '/",
        '$(this).attr("action", "/' => '$(this).attr("action", "/',
        '$("form").attr("action", "/' => '$("form").attr("action", "/',
        
        // Form HTML attributes
        'form action="/' => 'form action="/',
        'action="/' => 'action="/',
        '<form method="post" action="/' => '<form method="post" action="/',
        '<form method="get" action="/' => '<form method="get" action="/',
        
        // CSS URL references
        "background-image: url('/" => "background-image: url('/",
        'background-image: url("/' => 'background-image: url("/',
        "background: url('/" => "background: url('/",
        '@import url("/' => '@import url("/',
        "@import '/" => "@import '/",
        
        // JSON configuration
        '"api_endpoint": "/' => '"api_endpoint": "/',
        '"endpoint": "/' => '"endpoint": "/',
        '"url": "/' => '"url": "/',
        '"href": "/' => '"href": "/',
        '"src": "/' => '"src": "/',
        "'api_endpoint': '/" => "'api_endpoint': '/",
        "'endpoint': '/" => "'endpoint': '/",
        "'returnUrl': '/" => "'returnUrl': '/",
        
        // JavaScript const/let/var declarations
        "const baseUrl = '/" => "const baseUrl = '/",
        "let apiPath = '/" => "let apiPath = '/",
        "var rootPath = '/" => "var rootPath = '/",
        "const API_BASE = '/" => "const API_BASE = '/",
        "const BASE_URL = '/" => "const BASE_URL = '/",
        
        // API-specific patterns (CRITICAL)
        '/api/auth/login.php' => '/api/auth/login.php',
        '/api/auth/logout.php' => '/api/auth/logout.php',
        '/api/user/profile.php' => '/api/user/profile.php',
        '/api/training-activity.php' => '/api/training-activity.php',
        '/api/training-progress.php' => '/api/training-activity.php',
        '/api/health.php' => '/api/health.php',
        
        // PHP redirects and includes
        "redirect('/" => "redirect('/",
        "header('Location: /" => "header('Location: /",
        'header("Location: /' => 'header("Location: /',
        "require_once '/" => "require_once '/",
        "include '/" => "include '/",
        "require '/" => "require '/",
        
        // Window location
        "window.location = '/" => "window.location = '/",
        "window.location.href = '/" => "window.location.href = '/",
        "location.href = '/" => "location.href = '/",
        "location.replace('/" => "location.replace('/",
        
        // Event handlers
        'onclick="location.href=\'/' => 'onclick="location.href=\'/',
        'onsubmit="return submitTo(\'/' => 'onsubmit="return submitTo(\'/',
        
        // Template variables
        '{$base_url}/' => '{$base_url}/',
        '{{base_url}}/' => '{{base_url}}/',
        
        // Domain replacements
        'https://product.example/' => 'https://product.example/',
        'https://product.example/' => 'https://product.example/',
        'product.example/' => 'product.example/',
        
        // Generic href/src attributes
        'href="/' => 'href="/',
        'src="/' => 'src="/',
        "href='/" => "href='/",
        "src='/" => "src='/",
        
        // Function calls
        "path_with_base('/" => "path_with_base('/",
        "outbound_path('/" => "outbound_path('/",
        "site_url('/" => "site_url('/",
        "base_url('/" => "base_url('/",
        
        // AJAX URLs
        "url: '/" => "url: '/",
        'url: "/' => 'url: "/',
        
        // Fetch API
        "fetch('/" => "fetch('/",
        'fetch("/' => 'fetch("/',
        
        // XMLHttpRequest
        'open("GET", "/' => 'open("GET", "/',
        'open("POST", "/' => 'open("POST", "/',
        "open('GET', '/" => "open('GET', '/",
        "open('POST', '/" => "open('POST', '/",
    ];
    
    private $filePatterns = [
        '*.php', '*.js', '*.css', '*.html', '*.htm', 
        '*.json', '*.xml', '.htaccess', '*.md', '*.txt'
    ];
    
    private $skipDirs = [
        'vendor', 'node_modules', '.git', 'cache', 
        'uploads', 'logs', 'migration-snapshots'
    ];
    
    private $riskFactors = [];
    private $replacedFiles = [];
    private $errors = [];
    
    public function __construct() {
        // Initialize risk assessment factors
        $this->initializeRiskFactors();
    }
    
    private function initializeRiskFactors() {
        $this->riskFactors = [
            'domain_change' => 3,        // Changing domain names
            'api_endpoint' => 5,          // API endpoints are critical
            'auth_path' => 5,             // Authentication paths are critical
            'javascript_var' => 2,        // JS variable changes
            'css_url' => 1,               // CSS URL changes
            'form_action' => 3,           // Form actions
            'redirect' => 4,              // Redirects are important
            'include_require' => 3,       // PHP includes/requires
            'ajax_request' => 4,          // AJAX requests
            'window_location' => 4,       // JS navigation
        ];
    }
    
    public function assessReplacementRisk($pattern, $replacement, $file = '') {
        $risk = 0;
        
        // Check for domain change
        if (strpos($pattern, 'legacy.example') !== false) {
            $risk += $this->riskFactors['domain_change'];
        }
        
        // Check for API paths
        if (strpos($pattern, '/api/') !== false || strpos($replacement, '/api/') !== false) {
            $risk += $this->riskFactors['api_endpoint'];
        }
        
        // Check for auth paths
        if (strpos($pattern, '/auth/') !== false || strpos($pattern, 'login') !== false || strpos($pattern, 'logout') !== false) {
            $risk += $this->riskFactors['auth_path'];
        }
        
        // Check for JavaScript variables
        if (preg_match('/(?:const|let|var)\s+/', $pattern)) {
            $risk += $this->riskFactors['javascript_var'];
        }
        
        // Check for CSS URLs
        if (strpos($pattern, 'url(') !== false || strpos($pattern, '@import') !== false) {
            $risk += $this->riskFactors['css_url'];
        }
        
        // Check for form actions
        if (strpos($pattern, 'action=') !== false || strpos($pattern, '.attr("action"') !== false) {
            $risk += $this->riskFactors['form_action'];
        }
        
        // Check for redirects
        if (strpos($pattern, 'redirect') !== false || strpos($pattern, 'Location:') !== false) {
            $risk += $this->riskFactors['redirect'];
        }
        
        // Check for includes/requires
        if (preg_match('/(?:include|require)(?:_once)?\s*/', $pattern)) {
            $risk += $this->riskFactors['include_require'];
        }
        
        // Check for AJAX
        if (strpos($pattern, 'axios') !== false || strpos($pattern, '$.ajax') !== false || strpos($pattern, 'fetch(') !== false) {
            $risk += $this->riskFactors['ajax_request'];
        }
        
        // Check for window.location
        if (strpos($pattern, 'window.location') !== false || strpos($pattern, 'location.') !== false) {
            $risk += $this->riskFactors['window_location'];
        }
        
        // File type risk adjustment
        if ($file) {
            if (strpos($file, '/api/') !== false) {
                $risk += 2; // API files are more critical
            }
            if (strpos($file, '/auth/') !== false || strpos($file, 'login') !== false) {
                $risk += 2; // Auth files are critical
            }
            if (strpos($file, '/admin/') !== false) {
                $risk += 1; // Admin files need care
            }
        }
        
        return min($risk, 10); // Cap at 10
    }
    
    public function processFile($filePath, $dryRun = false) {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            $this->errors[] = "Cannot read file: $filePath";
            return false;
        }
        
        $content = file_get_contents($filePath);
        $originalContent = $content;
        $replacements = [];
        
        foreach ($this->replacements as $search => $replace) {
            if (strpos($content, $search) !== false) {
                $risk = $this->assessReplacementRisk($search, $replace, $filePath);
                $count = substr_count($content, $search);
                
                $replacements[] = [
                    'pattern' => $search,
                    'replacement' => $replace,
                    'count' => $count,
                    'risk' => $risk
                ];
                
                if (!$dryRun) {
                    $content = str_replace($search, $replace, $content);
                }
            }
        }
        
        if (!empty($replacements)) {
            $this->replacedFiles[$filePath] = [
                'replacements' => $replacements,
                'total_risk' => array_sum(array_column($replacements, 'risk')),
                'total_replacements' => array_sum(array_column($replacements, 'count'))
            ];
            
            if (!$dryRun && $content !== $originalContent) {
                // Backup original file
                $backupPath = $filePath . '.pre-url-migration.' . date('YmdHis');
                file_put_contents($backupPath, $originalContent);
                
                // Write updated content
                file_put_contents($filePath, $content);
            }
        }
        
        return true;
    }
    
    public function scanDirectory($directory, $recursive = true, $dryRun = false) {
        if (!is_dir($directory)) {
            $this->errors[] = "Not a directory: $directory";
            return false;
        }
        
        $files = scandir($directory);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $path = $directory . '/' . $file;
            
            // Skip problematic directories
            if (in_array($file, $this->skipDirs)) {
                continue;
            }
            
            if (is_dir($path) && $recursive) {
                $this->scanDirectory($path, true, $dryRun);
            } elseif (is_file($path)) {
                // Check if file matches our patterns
                $shouldProcess = false;
                foreach ($this->filePatterns as $pattern) {
                    if (fnmatch($pattern, $file)) {
                        $shouldProcess = true;
                        break;
                    }
                }
                
                if ($shouldProcess) {
                    $this->processFile($path, $dryRun);
                }
            }
        }
        
        return true;
    }
    
    public function generateReport() {
        $report = [
            'summary' => [
                'files_processed' => count($this->replacedFiles),
                'total_replacements' => 0,
                'high_risk_files' => 0,
                'medium_risk_files' => 0,
                'low_risk_files' => 0
            ],
            'files' => $this->replacedFiles,
            'errors' => $this->errors
        ];
        
        foreach ($this->replacedFiles as $file => $data) {
            $report['summary']['total_replacements'] += $data['total_replacements'];
            
            if ($data['total_risk'] >= 7) {
                $report['summary']['high_risk_files']++;
            } elseif ($data['total_risk'] >= 4) {
                $report['summary']['medium_risk_files']++;
            } else {
                $report['summary']['low_risk_files']++;
            }
        }
        
        return $report;
    }
    
    public function getReplacementPatterns() {
        return $this->replacements;
    }
}

// Handle form submission
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$report = null;
$dryRun = isset($_POST['dry_run']) || isset($_GET['dry_run']);

if ($action === 'scan') {
    $replacer = new EnhancedSmartLinkReplacer();
    $rootDir = realpath(__DIR__ . '/..');
    
    // Priority directories to scan
    $priorityDirs = [
        $rootDir . '/api',
        $rootDir . '/required',
        $rootDir . '/admin',
        $rootDir . '/assets',
        $rootDir . '/shop',
        $rootDir . '/customer',
        $rootDir . '/onboarding',
        $rootDir . '/operations',
        $rootDir . '/promo',
        $rootDir . '/labs',
        $rootDir . '/tools'
    ];
    
    foreach ($priorityDirs as $dir) {
        if (is_dir($dir)) {
            $replacer->scanDirectory($dir, true, $dryRun);
        }
    }
    
    // Also process root level files
    $rootFiles = glob($rootDir . '/*.{php,js,css,html,htaccess}', GLOB_BRACE);
    foreach ($rootFiles as $file) {
        $replacer->processFile($file, $dryRun);
    }
    
    $report = $replacer->generateReport();
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
        .migration-container {
            max-width: 1400px;
            margin: 20px auto;
            padding: 20px;
        }
        .action-buttons {
            display: flex;
            gap: 10px;
            margin: 20px 0;
        }
        .btn-primary {
            background: linear-gradient(135deg, #059669, #047857);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #047857, #065f46);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
        }
        .btn-secondary {
            background: #6b7280;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            cursor: pointer;
        }
        .patterns-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 10px;
            margin: 20px 0;
            background: #f9fafb;
            padding: 15px;
            border-radius: 8px;
            max-height: 400px;
            overflow-y: auto;
        }
        .pattern-item {
            background: white;
            padding: 8px;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            font-family: monospace;
            font-size: 12px;
        }
        .pattern-search {
            color: #dc2626;
        }
        .pattern-replace {
            color: #059669;
        }
        .report-section {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        .risk-high {
            color: #dc2626;
            font-weight: bold;
        }
        .risk-medium {
            color: #f59e0b;
            font-weight: bold;
        }
        .risk-low {
            color: #059669;
        }
        .file-report {
            background: white;
            padding: 15px;
            margin: 10px 0;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
        }
        .file-path {
            font-family: monospace;
            font-weight: bold;
            color: #1f2937;
            margin-bottom: 10px;
        }
        .replacement-detail {
            font-family: monospace;
            font-size: 12px;
            padding: 5px;
            background: #f9fafb;
            margin: 5px 0;
            border-left: 3px solid #059669;
        }
        .summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        .stat-card {
            background: white;
            padding: 15px;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
        }
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #059669;
        }
        .stat-label {
            color: #6b7280;
            font-size: 14px;
        }
        .warning-box {
            background: #fef3c7;
            border: 1px solid #fbbf24;
            border-radius: 6px;
            padding: 15px;
            margin: 20px 0;
        }
        .warning-box h3 {
            color: #92400e;
            margin-top: 0;
        }
    </style>
</head>
<body>
    <div class="migration-container">
        <h1>🔧 Enhanced URL Migration Tool V2</h1>
        
        <div class="warning-box">
            <h3>⚠️ Important</h3>
            <p>This tool will replace URL patterns across your codebase. Always:</p>
            <ul>
                <li>Run in "Dry Run" mode first to preview changes</li>
                <li>Create backups before applying changes</li>
                <li>Test critical functionality after migration</li>
            </ul>
        </div>
        
        <form method="post">
            <div class="action-buttons">
                <input type="hidden" name="action" value="scan">
                <button type="submit" name="dry_run" value="1" class="btn-secondary">
                    🔍 Dry Run (Preview Only)
                </button>
                <button type="submit" class="btn-primary" onclick="return confirm('This will modify files. Are you sure you have backups?')">
                    🚀 Apply Replacements
                </button>
            </div>
        </form>
        
        <?php if ($report): ?>
        <div class="report-section">
            <h2>📊 Migration Report <?= $dryRun ? '(Dry Run)' : '(Applied)' ?></h2>
            
            <div class="summary-stats">
                <div class="stat-card">
                    <div class="stat-value"><?= $report['summary']['files_processed'] ?></div>
                    <div class="stat-label">Files Processed</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $report['summary']['total_replacements'] ?></div>
                    <div class="stat-label">Total Replacements</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value risk-high"><?= $report['summary']['high_risk_files'] ?></div>
                    <div class="stat-label">High Risk Files</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value risk-medium"><?= $report['summary']['medium_risk_files'] ?></div>
                    <div class="stat-label">Medium Risk Files</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value risk-low"><?= $report['summary']['low_risk_files'] ?></div>
                    <div class="stat-label">Low Risk Files</div>
                </div>
            </div>
            
            <h3>Detailed File Changes</h3>
            <?php foreach ($report['files'] as $file => $data): ?>
                <div class="file-report">
                    <div class="file-path">
                        <?= htmlspecialchars(str_replace(realpath(__DIR__ . '/..'), '', $file)) ?>
                        <span class="<?= $data['total_risk'] >= 7 ? 'risk-high' : ($data['total_risk'] >= 4 ? 'risk-medium' : 'risk-low') ?>">
                            (Risk: <?= $data['total_risk'] ?>)
                        </span>
                    </div>
                    <div>Total Replacements: <?= $data['total_replacements'] ?></div>
                    <?php foreach (array_slice($data['replacements'], 0, 3) as $r): ?>
                        <div class="replacement-detail">
                            <?= htmlspecialchars(substr($r['pattern'], 0, 50)) ?> → 
                            <?= htmlspecialchars(substr($r['replacement'], 0, 50)) ?>
                            (<?= $r['count'] ?> occurrences, risk: <?= $r['risk'] ?>)
                        </div>
                    <?php endforeach; ?>
                    <?php if (count($data['replacements']) > 3): ?>
                        <div style="color: #6b7280; font-size: 12px;">
                            ... and <?= count($data['replacements']) - 3 ?> more patterns
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            
            <?php if (!empty($report['errors'])): ?>
                <h3>⚠️ Errors</h3>
                <div style="background: #fee2e2; padding: 10px; border-radius: 6px;">
                    <?php foreach ($report['errors'] as $error): ?>
                        <div><?= htmlspecialchars($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <h2>📋 Replacement Patterns (<?= count((new EnhancedSmartLinkReplacer())->getReplacementPatterns()) ?> total)</h2>
        <div class="patterns-grid">
            <?php
            $replacer = new EnhancedSmartLinkReplacer();
            foreach ($replacer->getReplacementPatterns() as $search => $replace):
            ?>
                <div class="pattern-item">
                    <span class="pattern-search"><?= htmlspecialchars($search) ?></span>
                    <br>→
                    <span class="pattern-replace"><?= htmlspecialchars($replace) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>
