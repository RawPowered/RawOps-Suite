<?php
/**
 * ULTIMATE URL SCANNER V2 - Comprehensive URL Detection & Analysis
 * 
 * Enhanced version with expanded patterns as per URL Synchronization Mega Plan
 * Detects all remaining / references and legacy.example URLs
 */

$SEO = [
    'title' => 'Ultimate URL Scanner V2 — Comprehensive Detection',
    'description' => 'Most comprehensive URL detection system with enhanced patterns',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect('/admin/');

require_once __DIR__ . '/../required/domain-controller.php';

$domainController = new DomainController();
$pdo = db();

// ENHANCED URL SCANNER CLASS
class UltimateURLScannerV2 {
    private DomainController $domainController;
    private $patterns = [];
    
    private $priorityFiles = [
        // Core system files
        'login.php', 'logout.php', 'index.php', 'index-sales.php',
        
        // API files - CRITICAL PRIORITY
        'api/*.php', 'api/auth/*.php', 'api/user/*.php',
        'required/api/*.php',
        
        // Configuration files
        'required/config.php', 'required/auth.php', 'required/helpers.php',
        '.htaccess', '.htaccess-comprehensive',
        
        // Admin files
        'admin/*.php',
        
        // Customer files  
        'customer/*.php', 'required/customer/*.php',
        
        // Shop files
        'shop/*.php', 'required/shop/*.php',
        
        // Training files
        'onboarding/*.php', 'onboarding/**/*.php',
        'required/onboarding/**/*.php',
        
        // Operations files
        'operations/*.php', 'required/operations/*.php',
        
        // Asset files
        'assets/*.js', 'assets/*.css',
        'required/assets/*.js', 'required/assets/*.css',
        
        // Tools
        'tools/*.php', 'required/tools/*.php',
        
        // Labs and Promo
        'labs/*.php', 'promo/**/*.php',
    ];
    
    // Allowlist: Known-safe patterns and files
    private $allowlist = [
        // Test and diagnostic files (intentionally contain /product/ for testing)
        'test-' => 'Test/diagnostic file',
        'verify-urls.php' => 'URL verification tool',
        'database-url-cleanup.php' => 'Cleanup script',
        'final-url-cleanup.php' => 'Cleanup script',
        'scan-urls-cli.php' => 'Scanner tool',
        'ultimate-url-scanner' => 'Scanner tool',
        'automated-url-monitor' => 'Monitor tool',
        
        // Safe contexts (pattern + context combinations)
        'safe_contexts' => [
            // API index files with correct root-relative paths
            ['pattern' => 'api_auth_login', 'context' => "'login_direct' => '/api/auth/login.php'"],
            ['pattern' => 'api_auth_logout', 'context' => "'logout_direct' => '/api/auth/logout.php'"],
            ['pattern' => 'api_user_profile', 'context' => "'profile_direct' => '/api/user/profile.php'"],
            ['pattern' => 'api_auth_login', 'context' => "login: '/api/auth/login.php'"],
            ['pattern' => 'api_auth_logout', 'context' => "logout: '/api/auth/logout.php'"],
            ['pattern' => 'api_user_profile', 'context' => "profile: '/api/user/profile.php'"],
            
            // Defensive code in product-urls.js (removes /product/ if present)
            ['pattern' => 'escaped_slash_product', 'context' => ".replace(/\\/product\\//g, '/')"],
            ['pattern' => 'escaped_slash_product', 'context' => ".replace(/^\\/product\\/assets\\//, '')"],
            ['pattern' => 'escaped_slash_product', 'context' => ".replace(/^\\/product\\/api\\//, '')"],
            
            // Legacy dev-only code in config.php (dormant on production)
            ['pattern' => 'quoted_product_single', 'context' => "if (strpos(\$path, '/product/') === 0)"],
        ],
        
        // Safe file patterns (entire files that are harmless)
        'safe_files' => [
            // product-urls.js contains defensive regex patterns
            'assets/product-urls.js' => 'Defensive URL cleanup code',
            
            // config.php contains dormant dev-only code
            'required/config.php' => 'Legacy dev-environment code (dormant on production)',
            
            // API index files with correct paths
            'api/auth/index.php' => 'API directory index with correct root-relative paths',
            'api/user/index.php' => 'API directory index with correct root-relative paths',
        ]
    ];
    
    private $results = [];
    private $verifiedSafe = [];
    private $totalScanned = 0;
    private $issuesFound = 0;
    private $safeItemsFound = 0;
    private $patternCounts = [];
    private array $currentHosts;
    private array $legacyHosts;
    
    public function __construct(?DomainController $domainController = null) {
        $this->domainController = $domainController ?: new DomainController();
        $this->patterns = $this->buildPatterns();
        $this->currentHosts = $this->domainController->getCurrentHosts();
        $this->legacyHosts = $this->domainController->getLegacyHosts();
        
        // Initialize pattern counts
        foreach ($this->patterns as $key => $pattern) {
            $this->patternCounts[$key] = 0;
        }
    }

    private function buildPatterns(): array
    {
        $doublePathPattern = $this->domainController->buildDoublePathPattern('/product/');
        $legacyPattern = $this->domainController->legacyDomainPattern('[^\'"\\s]*', true, true);
        
        $patterns = [
            // Direct URL patterns
            'redirect_calls' => "/redirect\s*\(\s*['\"]([^'\"]*\/product\/[^'\"]*)['\"].*\)/i",
            'header_redirects' => "/header\s*\(\s*['\"]Location:\s*([^'\"]*\/product\/[^'\"]*)['\"].*\)/i",
            'window_location' => "/window\.location(?:\.href)?\s*=\s*['\"]([^'\"]*\/product\/[^'\"]*)['\"].*\)/i",
            'fetch_calls' => "/fetch\s*\(\s*['\"]([^'\"]*\/product\/[^'\"]*)['\"].*\)/i",
            'ajax_urls' => "/(?:ajax|get|post)\s*\(\s*.*url\s*:\s*['\"]([^'\"]*\/product\/[^'\"]*)['\"].*\)/i",
            
            // HTML attributes
            'href_links' => "/href\s*=\s*['\"]([^'\"]*\/product\/[^'\"]*)['\"].*>/i",
            'src_attributes' => "/src\s*=\s*['\"]([^'\"]*\/product\/[^'\"]*)['\"].*>/i", 
            'action_forms' => "/action\s*=\s*['\"]([^'\"]*\/product\/[^'\"]*)['\"].*>/i",
            
            // Function calls with URLs
            'require_redirects' => "/require_(?:login|admin)_or_redirect\s*\(\s*['\"]([^'\"]*\/product\/[^'\"]*)['\"].*\)/i",
            'path_functions' => "/(?:path_with_base|outbound_path)\s*\(\s*['\"]([^'\"]*\/product\/[^'\"]*)['\"].*\)/i",
            'window_open' => "/window\.open\s*\(\s*['\"]([^'\"]*\/product\/[^'\"]*)['\"].*\)/i",
            
            // NEW: Form callbacks and event handlers
            'form_callbacks' => "/(?:onsubmit|on(?:form)?submit)\s*=\s*['\"][^'\"]*\/product\/[^'\"]*['\"].*>/i",
            'onclick_handlers' => "/onclick\s*=\s*['\"][^'\"]*\/product\/[^'\"]*['\"].*>/i",
            
            // NEW: JSON-encoded URLs
            'json_encoded' => "/[\"'](?:url|href|src|endpoint|api_endpoint|returnUrl)[\"']\s*:\s*[\"']([^\"']*\/product\/[^\"']*)[\"']/i",
            
            // NEW: Template variables
            'template_vars' => "/\{\$[a-z0-9_]+_url\}.*?\/product\//i",
            'smarty_vars' => "/\{\{[a-z0-9_]+_url\}\}.*?\/product\//i",
            
            // NEW: Inline CSS patterns
            'inline_css' => "/url\s*\(\s*['\"]?([^'\"]*\/product\/[^'\"\)]*)['\"]?\s*\)/i",
            'css_import' => "/@import\s+url\(['\"]?\/product\/[^'\"\)]*['\"]?\)/i",
            'background_style' => "/background(?:-image)?\s*:\s*url\s*\(\s*['\"]?\/product\/[^'\"\)]*['\"]?\s*\)/i",
            
            // NEW: JavaScript specific patterns
            'axios_get' => "/axios\.get\s*\(\s*['\"]\/product\/[^'\"]*['\"]/i",
            'axios_post' => "/axios\.post\s*\(\s*['\"]\/product\/[^'\"]*['\"]/i",
            'axios_put' => "/axios\.put\s*\(\s*['\"]\/product\/[^'\"]*['\"]/i",
            'axios_delete' => "/axios\.delete\s*\(\s*['\"]\/product\/[^'\"]*['\"]/i",
            'jquery_ajax' => "/\$\s*\.(?:ajax|get|post)\s*\(\s*['\"]\/product\/[^'\"]*['\"]/i",
            'jquery_form_action' => "/\$\([^)]+\)\.attr\s*\(\s*['\"]action['\"]\s*,\s*['\"]\/product\/[^'\"]*['\"]\)/i",
            'const_baseurl' => "/const\s+baseUrl\s*=\s*['\"]\/product\//i",
            'let_apipath' => "/let\s+apiPath\s*=\s*['\"]\/product\//i",
            'var_declarations' => "/(?:const|let|var)\s+[a-zA-Z_$][a-zA-Z0-9_$]*\s*=\s*['\"]\/product\/[^'\"]*['\"]/i",
            
            // API-specific patterns
            'api_auth_login' => "/api\/auth\/login\.php/i",
            'api_auth_logout' => "/api\/auth\/logout\.php/i",
            'api_user_profile' => "/api\/user\/profile\.php/i",
            'api_training_progress' => "/api\/training-progress\.php/i",
            
            // Domain patterns
            'dev_domain_full' => "/https?:\/\/legacy\.example\/product\/[^'\"\\s]*/i",
            'dev_domain_partial' => "/legacy\.example\/i",
            'prod_double_product' => $doublePathPattern,
            'legacy_domain_full' => $legacyPattern,
            
            // Generic patterns (catch-all)
            'quoted_product_single' => "/'\/product\/[^']*'/",
            'quoted_product_double' => '/"\/product\/[^"]*"/',
            'url_segment_product' => "/\/product\/[a-zA-Z0-9\/_\-\.]+/",
            
            // Double-encoded patterns
            'url_encoded_product' => "/%2Fproduct%2F/i",
            'html_entity_product' => "/&#x2F;product&#x2F;/i",
            'escaped_slash_product' => "/\\\\\/product\\\\\//i",
        ];
        
        if ($legacyPattern === '/(?!)/') {
            unset($patterns['legacy_domain_full']);
        }
        if ($doublePathPattern === '/(?!)/') {
            unset($patterns['prod_double_product']);
        }
        
        return $patterns;
    }
    
    private function isFileAllowlisted($filePath) {
        $basename = basename($filePath);
        
        // Check if filename starts with allowlisted prefix
        foreach ($this->allowlist as $key => $reason) {
            if ($key === 'safe_contexts' || $key === 'safe_files') continue;
            if (strpos($basename, $key) === 0) {
                return ['reason' => $reason, 'type' => 'filename_prefix'];
            }
        }
        
        // Check if full file path matches safe files
        foreach ($this->allowlist['safe_files'] as $pattern => $reason) {
            if (strpos($filePath, $pattern) !== false) {
                return ['reason' => $reason, 'type' => 'safe_file'];
            }
        }
        
        return false;
    }
    
    private function isContextSafe($patternName, $context) {
        foreach ($this->allowlist['safe_contexts'] as $safeContext) {
            if ($safeContext['pattern'] === $patternName) {
                // Check if the context matches (contains the safe pattern)
                if (strpos($context, $safeContext['context']) !== false) {
                    return true;
                }
            }
        }
        return false;
    }
    
    public function scanFile($filePath) {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return null;
        }
        
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }
        
        $this->totalScanned++;
        $safeItems = [];
        $lineIssues = [];
        
        // Check if entire file is allowlisted
        $fileAllowlisted = $this->isFileAllowlisted($filePath);
        
        // Split content into lines for line-by-line analysis
        $lines = explode("\n", $content);
        
        foreach ($lines as $lineNumber => $line) {
            foreach ($this->patterns as $patternName => $pattern) {
                if (preg_match_all($pattern, $line, $matches)) {
                    $this->patternCounts[$patternName] += count($matches[0]);
                    foreach ($matches[0] as $match) {
                        $lineContext = trim($line);
                        
                        // Check if this is a safe context
                        if ($fileAllowlisted || $this->isContextSafe($patternName, $lineContext)) {
                            $safeItems[] = [
                                'line' => $lineNumber + 1,
                                'pattern' => $patternName,
                                'match' => $match,
                                'context' => $lineContext,
                                'reason' => $fileAllowlisted ? $fileAllowlisted['reason'] : 'Safe context pattern'
                            ];
                        } else {
                            $lineIssues[] = [
                                'line' => $lineNumber + 1,
                                'pattern' => $patternName,
                                'match' => $match,
                                'context' => $lineContext
                            ];
                        }
                    }
                }
            }
        }
        
        // Store verified safe items separately
        if (!empty($safeItems)) {
            $this->safeItemsFound++;
            $this->verifiedSafe[] = [
                'file' => $filePath,
                'safe_items' => $safeItems,
                'total_safe' => count($safeItems)
            ];
        }
        
        // Store actual issues
        if (!empty($lineIssues)) {
            $this->issuesFound++;
            $this->results[] = [
                'file' => $filePath,
                'issues' => $lineIssues,
                'total_issues' => count($lineIssues)
            ];
            return true;
        }
        
        return false;
    }
    
    public function scanDirectory($directory, $recursive = true) {
        if (!is_dir($directory)) {
            return false;
        }
        
        $files = scandir($directory);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $path = $directory . '/' . $file;
            
            // Skip excluded directories
            $excludedDirs = [
                'vendor', 'node_modules', '.git', 'cache', 'uploads', 
                'logs', 'backups', 'backup', 'migration-snapshots', 
                'temp', 'tmp', 'sessions'
            ];
            if (in_array($file, $excludedDirs)) {
                continue;
            }
            
            if (is_dir($path) && $recursive) {
                $this->scanDirectory($path, true);
            } elseif (is_file($path)) {
                // Skip scanner/tool files themselves
                $excludedFiles = [
                    'url-scanner', 'url-verification', 'url-migration', 
                    'automated-url-monitor', 'enhanced-url-scanner',
                    'ultimate-url-scanner', 'database-url-cleanup',
                    'final-url-cleanup', 'debug-panel', 'search-replace',
                    'fix-tracking', 'clear-old-session'
                ];
                
                $skipFile = false;
                foreach ($excludedFiles as $excluded) {
                    if (stripos($file, $excluded) !== false) {
                        $skipFile = true;
                        break;
                    }
                }
                
                // Skip backup files
                if (preg_match('/\.(backup|bak|old|orig|~)$/i', $file)) {
                    $skipFile = true;
                }
                
                if ($skipFile) {
                    continue;
                }
                
                // Only scan relevant file types
                if (preg_match('/\.(php|js|css|html|htm|json|xml|sql)$/i', $path)) {
                    $this->scanFile($path);
                }
            }
        }
        
        return true;
    }
    
    public function getResults() {
        return [
            'files_scanned' => $this->totalScanned,
            'files_with_issues' => $this->issuesFound,
            'files_verified_safe' => $this->safeItemsFound,
            'detailed_results' => $this->results,
            'verified_safe' => $this->verifiedSafe,
            'pattern_counts' => $this->patternCounts,
            'priority_summary' => $this->getPrioritySummary(),
            'current_domain_hosts' => $this->currentHosts,
            'legacy_domain_hosts' => $this->legacyHosts
        ];
    }
    
    private function getPrioritySummary() {
        $summary = [
            'critical' => [],
            'high' => [],
            'medium' => [],
            'low' => []
        ];
        
        foreach ($this->results as $result) {
            $file = $result['file'];
            
            // Categorize by priority
            if (strpos($file, '/api/') !== false) {
                $summary['critical'][] = $file;
            } elseif (strpos($file, '/required/') !== false || strpos($file, '/admin/') !== false) {
                $summary['high'][] = $file;
            } elseif (strpos($file, '/shop/') !== false || strpos($file, '/customer/') !== false) {
                $summary['medium'][] = $file;
            } else {
                $summary['low'][] = $file;
            }
        }
        
        return $summary;
    }
    
    public function generateReport() {
        $results = $this->getResults();
        $report = [];
        
        $report[] = "=== ULTIMATE URL SCANNER V2 REPORT ===\n";
        $report[] = "Scan completed: " . date('Y-m-d H:i:s') . "\n";
        $report[] = "Files scanned: " . $results['files_scanned'];
        $report[] = "Files with ACTUAL issues: " . $results['files_with_issues'];
        $report[] = "Files verified safe (allowlisted): " . $results['files_verified_safe'];
        $report[] = "\n⚠️  Note: Scanner now intelligently allowlists known-safe patterns\n";
        
        $report[] = "\n=== PATTERN DETECTION COUNTS ===\n";
        arsort($results['pattern_counts']);
        foreach ($results['pattern_counts'] as $pattern => $count) {
            if ($count > 0) {
                $report[] = sprintf("%-30s: %d matches", $pattern, $count);
            }
        }
        
        $report[] = "\n=== PRIORITY SUMMARY ===\n";
        $priority = $results['priority_summary'];
        $report[] = "Critical Priority (API): " . count($priority['critical']) . " files";
        $report[] = "High Priority (Required/Admin): " . count($priority['high']) . " files";
        $report[] = "Medium Priority (Shop/Customer): " . count($priority['medium']) . " files";
        $report[] = "Low Priority (Other): " . count($priority['low']) . " files";
        
        $report[] = "\n=== VERIFIED SAFE ITEMS (ALLOWLISTED) ===\n";
        if (empty($results['verified_safe'])) {
            $report[] = "No safe items found (all patterns were clean or actual issues)\n";
        } else {
            foreach ($results['verified_safe'] as $safeResult) {
                $report[] = "\n✅ File: " . $safeResult['file'];
                $report[] = "   Total safe patterns: " . $safeResult['total_safe'];
                $report[] = "   Reason: " . ($safeResult['safe_items'][0]['reason'] ?? 'Known safe context');
            }
        }
        
        $report[] = "\n=== ACTUAL ISSUES REQUIRING REVIEW ===\n";
        if (empty($results['detailed_results'])) {
            $report[] = "\n🎉 NO ISSUES FOUND! All detected patterns are either safe or allowlisted.\n";
        } else {
            foreach ($results['detailed_results'] as $fileResult) {
                $report[] = "\n⚠️  File: " . $fileResult['file'];
                $report[] = "   Total issues: " . $fileResult['total_issues'];
                
                // Group issues by pattern type
                $patternGroups = [];
                foreach ($fileResult['issues'] as $issue) {
                    if (!isset($patternGroups[$issue['pattern']])) {
                        $patternGroups[$issue['pattern']] = [];
                    }
                    $patternGroups[$issue['pattern']][] = $issue;
                }
                
                foreach ($patternGroups as $pattern => $issues) {
                    $report[] = "   Pattern: " . $pattern . " (" . count($issues) . " matches)";
                    foreach (array_slice($issues, 0, 3) as $issue) { // Show first 3 examples
                        $report[] = "     Line " . $issue['line'] . ": " . substr($issue['context'], 0, 100);
                    }
                    if (count($issues) > 3) {
                        $report[] = "     ... and " . (count($issues) - 3) . " more";
                    }
                }
            }
        }
        
        return implode("\n", $report);
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'scan') {
        $domainController = new DomainController();
        $scanner = new UltimateURLScannerV2($domainController);
        $rootDir = realpath(__DIR__ . '/..');
        
        // Scan priority directories
        $priorityDirs = [
            $rootDir . '/api',
            $rootDir . '/admin',
            $rootDir . '/required',
            $rootDir . '/assets',
            $rootDir . '/customer',
            $rootDir . '/shop',
            $rootDir . '/onboarding',
            $rootDir . '/operations',
            $rootDir . '/promo',
            $rootDir . '/labs',
            $rootDir . '/tools'
        ];
        
        foreach ($priorityDirs as $dir) {
            if (is_dir($dir)) {
                $scanner->scanDirectory($dir, true);
            }
        }
        
        // Also scan root level files
        $rootFiles = glob($rootDir . '/*.{php,js,css,html,htaccess}', GLOB_BRACE);
        foreach ($rootFiles as $file) {
            $scanner->scanFile($file);
        }
        
        $scanReport = $scanner->generateReport();
        
        // Save report to file
        $reportFile = $rootDir . '/admin/logs/url-scan-v2-' . date('Y-m-d-His') . '.txt';
        @mkdir(dirname($reportFile), 0755, true);
        file_put_contents($reportFile, $scanReport);
    }
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
        .scanner-container {
            max-width: 1400px;
            margin: 20px auto;
            padding: 20px;
        }
        .scan-button {
            background: linear-gradient(135deg, #059669, #047857);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .scan-button:hover {
            background: linear-gradient(135deg, #047857, #065f46);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
        }
        .results-panel {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }
        .results-header {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 15px;
            color: #111827;
        }
        .pattern-stats {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 10px;
            margin-bottom: 20px;
        }
        .pattern-stat {
            background: white;
            padding: 10px;
            border-radius: 4px;
            border: 1px solid #e5e7eb;
        }
        .pattern-stat .name {
            font-weight: 600;
            color: #374151;
            font-size: 14px;
        }
        .pattern-stat .count {
            color: #059669;
            font-size: 20px;
            font-weight: bold;
        }
        .priority-section {
            margin-top: 20px;
        }
        .priority-level {
            margin-bottom: 15px;
        }
        .priority-level h4 {
            margin-bottom: 5px;
        }
        .critical { color: #dc2626; }
        .high { color: #f59e0b; }
        .medium { color: #3b82f6; }
        .low { color: #6b7280; }
        .file-list {
            background: white;
            padding: 10px;
            border-radius: 4px;
            max-height: 200px;
            overflow-y: auto;
        }
        .file-item {
            padding: 5px;
            border-bottom: 1px solid #f3f4f6;
            font-family: monospace;
            font-size: 13px;
        }
        .report-textarea {
            width: 100%;
            height: 400px;
            font-family: monospace;
            font-size: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            padding: 10px;
        }
        .info-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .info-box h3 {
            margin-top: 0;
            color: #1e40af;
        }
    </style>
</head>
<body>
    <div class="scanner-container">
        <h1>🔍 Ultimate URL Scanner V2</h1>
        
        <div class="info-box">
            <h3>Enhanced Pattern Detection</h3>
            <p>This scanner includes comprehensive patterns to detect:</p>
            <ul>
                <li>✅ Form callbacks and event handlers</li>
                <li>✅ JSON-encoded URLs</li>
                <li>✅ Template variables</li>
                <li>✅ Inline CSS URL references</li>
                <li>✅ JavaScript-specific patterns (Axios, jQuery, etc.)</li>
                <li>✅ API endpoint patterns</li>
                <li>✅ Double-encoded URLs</li>
            </ul>
            <p><strong>Automatically excludes:</strong></p>
            <ul>
                <li>🚫 Backup directories and files</li>
                <li>🚫 Scanner and diagnostic tools</li>
                <li>🚫 Migration snapshots</li>
                <li>🚫 Log files</li>
            </ul>
            <p><strong>✨ NEW: Smart Allowlist System:</strong></p>
            <ul>
                <li>✅ Defensive code patterns (product-urls.js URL cleanup)</li>
                <li>✅ API index files with correct paths</li>
                <li>✅ Legacy dev-environment code (dormant on production)</li>
                <li>✅ Test files and cleanup scripts</li>
            </ul>
        </div>
        
        <form method="post">
            <input type="hidden" name="action" value="scan">
            <button type="submit" class="scan-button">
                🚀 Start Comprehensive Scan
            </button>
        </form>
        
        <?php if (isset($scanReport)): ?>
        <div class="results-panel">
            <h3 class="results-header">📊 Scan Results</h3>
            
            <?php
            $results = $scanner->getResults();
            ?>
            
            <div class="pattern-stats">
                <?php foreach ($results['pattern_counts'] as $pattern => $count): ?>
                    <?php if ($count > 0): ?>
                    <div class="pattern-stat">
                        <div class="name"><?= htmlspecialchars($pattern) ?></div>
                        <div class="count"><?= $count ?></div>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            
            <div class="priority-section">
                <h4>Priority Analysis</h4>
                
                <?php $priority = $results['priority_summary']; ?>
                
                <div class="priority-level">
                    <h4 class="critical">🔴 Critical Priority (<?= count($priority['critical']) ?> files)</h4>
                    <div class="file-list">
                        <?php foreach ($priority['critical'] as $file): ?>
                            <div class="file-item"><?= htmlspecialchars($file) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="priority-level">
                    <h4 class="high">🟠 High Priority (<?= count($priority['high']) ?> files)</h4>
                    <div class="file-list">
                        <?php foreach ($priority['high'] as $file): ?>
                            <div class="file-item"><?= htmlspecialchars($file) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($results['verified_safe'])): ?>
            <div class="priority-section">
                <h4>✅ Verified Safe Items (Allowlisted)</h4>
                <div class="file-list">
                    <?php foreach ($results['verified_safe'] as $safeItem): ?>
                        <div class="file-item" style="border-left: 3px solid #10b981;">
                            <strong><?= htmlspecialchars(basename($safeItem['file'])) ?></strong>
                            <span style="color: #6b7280; font-size: 11px; margin-left: 8px;">
                                (<?= $safeItem['total_safe'] ?> safe patterns - <?= htmlspecialchars($safeItem['safe_items'][0]['reason']) ?>)
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <h4>Full Report</h4>
            <textarea class="report-textarea" readonly><?= htmlspecialchars($scanReport) ?></textarea>
            
            <p><small>Report saved to: admin/logs/url-scan-v2-*.txt</small></p>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
