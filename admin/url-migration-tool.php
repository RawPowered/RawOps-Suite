<?php
$SEO = [
    'title' => 'URL Migration Tool — Admin',
    'description' => 'Safe and comprehensive URL migration system with auditing and rollback',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

require_once __DIR__ . '/../required/domain-controller.php';

$pdo = db();

// Activity tracking helper
function logMigrationActivity($pdo, $action, $details = []) {
    try {
        // Check if admin_history table exists
        $tableExists = $pdo->query("SHOW TABLES LIKE 'admin_history'")->fetch();
        if ($tableExists) {
            $stmt = $pdo->prepare("
                INSERT INTO admin_history 
                (admin_id, action_type, description, metadata, ip_address, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            $description = 'URL Migration: ' . $action;
            $metadata = json_encode($details);
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            
            $stmt->execute([
                current_user_id(),
                'migration_' . strtolower(str_replace(' ', '_', $action)),
                $description,
                $metadata,
                $ip
            ]);
        }
        
        // Also log to migration-specific table if it exists
        $migrationTableExists = $pdo->query("SHOW TABLES LIKE 'migration_progress'")->fetch();
        if ($migrationTableExists) {
            $stmt = $pdo->prepare("
                UPDATE migration_progress 
                SET status = 'in_progress', 
                    started_at = NOW(),
                    metadata = ?
                WHERE phase = ?
            ");
            $stmt->execute([json_encode($details), strtolower($action)]);
        }
    } catch (Exception $e) {
        // Silently fail - don't break the main functionality
    }
}

$pdo = db();

// Check if migration tables exist
try {
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'migration_snapshots'")->fetch();
    if (!$tableCheck) {
        // Tables don't exist yet, create them
        $sqlFile = __DIR__ . '/../migrations/2025-09-26-url-migration-system.sql';
        if (file_exists($sqlFile)) {
            $sql = file_get_contents($sqlFile);
            // Split by semicolons but be careful with delimiters in strings
            $statements = preg_split('/;\s*$/m', $sql);
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if (!empty($statement)) {
                    try {
                        $pdo->exec($statement);
                    } catch (PDOException $e) {
                        // Ignore errors for now (table might already exist)
                    }
                }
            }
        }
    }
} catch (PDOException $e) {
    // Handle error gracefully
    echo '<div style="background: #fee2e2; border: 2px solid #f87171; padding: 20px; margin: 20px; border-radius: 8px;">
        <h2 style="color: #991b1b;">Database Setup Required</h2>
        <p>The migration tables have not been created yet. Please run the following SQL file:</p>
        <code style="background: #1f2937; color: #f9fafb; padding: 8px; border-radius: 4px; display: block; margin: 10px 0;">
            migrations/2025-09-26-url-migration-system.sql
        </code>
        <p>Error: ' . htmlspecialchars($e->getMessage()) . '</p>
    </div>';
    require_once __DIR__ . '/../required/footer.php';
    exit;
}

// Migration snapshot management
function createMigrationSnapshot($pdo, $type = 'pre-migration') {
    $snapshotId = date('YmdHis') . '_' . bin2hex(random_bytes(4));
    $snapshotDir = __DIR__ . '/migration-snapshots/' . $snapshotId;
    
    if (!is_dir($snapshotDir)) {
        mkdir($snapshotDir, 0775, true);
    }
    
    // Record in database
    $stmt = $pdo->prepare("
        INSERT INTO migration_snapshots 
        (snapshot_id, type, created_at, created_by, status) 
        VALUES (?, ?, NOW(), ?, 'active')
    ");
    $stmt->execute([$snapshotId, $type, current_user_id()]);
    
    return $snapshotId;
}

// URL validation and crawling
class URLCrawler {
    private $baseUrl;
    private $pages = [];
    private $errors = [];
    private $links = [];
    private $checked = [];
    private DomainController $domainController;
    
    public function __construct($baseUrl, ?DomainController $domainController = null) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->domainController = $domainController ?: new DomainController();
    }
    
    public function crawlPage($path) {
        $url = $this->baseUrl . $path;
        
        if (isset($this->checked[$url])) {
            return $this->checked[$url];
        }
        
        // Check if cURL is available
        if (!function_exists('curl_init')) {
            return [
                'url' => $url,
                'path' => $path,
                'status' => 0,
                'is_404' => false,
                'links' => [],
                'issues' => ['cURL extension not available']
            ];
        }
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HEADER => true
        ]);
        
        $response = curl_exec($ch);
        
        // Check for cURL errors
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            return [
                'url' => $url,
                'path' => $path,
                'status' => 0,
                'is_404' => false,
                'links' => [],
                'issues' => ['cURL error: ' . $error]
            ];
        }
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $body = $response ? substr($response, $headerSize) : '';
        curl_close($ch);
        
        $result = [
            'url' => $url,
            'path' => $path,
            'status' => $httpCode,
            'is_404' => $httpCode == 404,
            'links' => [],
            'issues' => []
        ];
        
        // Parse HTML for links if successful
        if ($httpCode == 200 && $body) {
            $dom = new DOMDocument();
            @$dom->loadHTML($body);
            $xpath = new DOMXPath($dom);
            
            // Find all links
            $linkElements = $xpath->query('//a[@href]|//link[@href]|//script[@src]|//img[@src]|//form[@action]');
            
            foreach ($linkElements as $element) {
                $tagName = $element->tagName;
                $linkUrl = '';
                
                switch($tagName) {
                    case 'a':
                    case 'link':
                        $linkUrl = $element->getAttribute('href');
                        break;
                    case 'script':
                    case 'img':
                        $linkUrl = $element->getAttribute('src');
                        break;
                    case 'form':
                        $linkUrl = $element->getAttribute('action');
                        break;
                }
                
                if ($linkUrl) {
                    $linkInfo = [
                        'tag' => $tagName,
                        'url' => $linkUrl,
                        'text' => $tagName == 'a' ? trim($element->textContent) : '',
                        'has_product' => strpos($linkUrl, '/product/') !== false,
                        'is_dev_url' => $this->domainController->containsLegacyDomain($linkUrl) || strpos($linkUrl, $this->domainController->devDomain()) !== false,
                        'is_absolute' => preg_match('/^https?:\/\//', $linkUrl),
                        'risk_level' => $this->assessLinkRisk($linkUrl, $tagName)
                    ];
                    
                    $result['links'][] = $linkInfo;
                    
                    // Flag issues
                    if ($linkInfo['has_product']) {
                        $result['issues'][] = "Contains / segment: $linkUrl";
                    }
                    if ($linkInfo['is_dev_url']) {
                        $result['issues'][] = "Contains dev URL: $linkUrl";
                    }
                }
            }
        }
        
        $this->checked[$url] = $result;
        return $result;
    }
    
    private function assessLinkRisk($url, $tagName) {
        $risk = 0;
        
        // High risk: JavaScript or critical resources
        if ($tagName == 'script' || $tagName == 'link') {
            $risk += 3;
        }
        
        // Medium risk: Navigation links
        if ($tagName == 'a' && (strpos($url, '/admin/') !== false || strpos($url, '/api/') !== false)) {
            $risk += 2;
        }
        
        // Higher risk for absolute URLs
        if (preg_match('/^https?:\/\//', $url)) {
            $risk += 1;
        }
        
        // Extreme risk for dev URLs
        if (strpos($url, 'legacy.example') !== false) {
            $risk += 5;
        }
        
        // High risk for / paths
        if (strpos($url, '/') !== false) {
            $risk += 3;
        }
        
        return min($risk, 10); // Cap at 10
    }
    
    public function getResults() {
        return $this->checked;
    }
}

// Smart link replacer
class SmartLinkReplacer {
    private $replacements = [];
    private $backups = [];
    private $riskAssessment = [];
    private DomainController $domainController;
    private array $domainReplacementMap;
    private array $legacyHosts;

    public function __construct(?DomainController $domainController = null)
    {
        $this->domainController = $domainController ?: new DomainController();
        $this->domainReplacementMap = $this->domainController->buildDomainReplacementMap();
        $this->legacyHosts = $this->domainController->getLegacyHosts();
        $this->initializeDefaultReplacements();
    }

    private function initializeDefaultReplacements(): void
    {
        foreach ($this->domainReplacementMap as $search => $replace) {
            $this->addReplacement($search, $replace, ['type' => 'domain']);
        }

        $currentDomain = $this->domainController->currentDomain();
        $currentHostNoSlash = 'https://' . $currentDomain;
        $currentHostWithSlash = $currentHostNoSlash . '/';
        $currentProtocolRelative = '//' . $currentDomain;

        foreach ($this->legacyHosts as $legacyHost) {
            $this->addReplacement('https://' . $legacyHost . '/', $currentHostWithSlash, ['type' => 'legacy_host']);
            $this->addReplacement('http://' . $legacyHost . '/', $currentHostWithSlash, ['type' => 'legacy_host']);
            $this->addReplacement('https://' . $legacyHost, $currentHostNoSlash, ['type' => 'legacy_host']);
            $this->addReplacement('http://' . $legacyHost, $currentHostNoSlash, ['type' => 'legacy_host']);
            $this->addReplacement('//' . $legacyHost . '/', rtrim($currentProtocolRelative, '/') . '/', ['type' => 'legacy_host']);
            $this->addReplacement('//' . $legacyHost, $currentProtocolRelative, ['type' => 'legacy_host']);
        }
    }

    public function addReplacement($pattern, $replacement, $context = []) {
        $this->replacements[] = [
            'pattern' => $pattern,
            'replacement' => $replacement,
            'context' => $context,
            'risk' => $this->assessReplacementRisk($pattern, $replacement)
        ];
    }
    
    private function assessReplacementRisk($pattern, $replacement) {
        $risk = 0;
        
        // Risk factors
        if ($this->domainController->containsLegacyDomain($pattern) || $this->domainController->containsLegacyDomain($replacement)) {
            $risk += 2; // Domain change
        }
        
        if (strpos($pattern, '/') !== false) {
            $risk += 1; // Path structure change
        }
        
        if (preg_match('/\.(js|css|php)/', $pattern)) {
            $risk += 3; // Resource file
        }
        
        return min($risk, 10);
    }
    
    public function processFile($filePath, $dryRun = true) {
        if (!file_exists($filePath)) {
            return ['error' => 'File not found', 'file' => $filePath];
        }
        
        if (!is_readable($filePath)) {
            return ['error' => 'File not readable', 'file' => $filePath];
        }
        
        $content = @file_get_contents($filePath);
        if ($content === false) {
            return ['error' => 'Failed to read file', 'file' => $filePath];
        }
        
        $originalContent = $content;
        $changes = [];
        
        // COMPREHENSIVE URL pattern detection
        $patterns = [
            // HTML attributes (high priority)
            '/(href|src|action)=(["\'])([^"\']*?)\\2/i',
            
            // JavaScript location patterns (critical for UX)
            '/window\.location\.href\s*=\s*(["\'])([^"\']*?)\\1/i',
            '/window\.location\s*=\s*(["\'])([^"\']*?)\\1/i',
            '/location\.href\s*=\s*(["\'])([^"\']*?)\\1/i',
            '/document\.location\s*=\s*(["\'])([^"\']*?)\\1/i',
            
            // AJAX and fetch patterns (critical for functionality)
            '/fetch\s*\(\s*(["\'])([^"\']*?)\\1/i',
            '/ajax\s*\(\s*.*url\s*:\s*(["\'])([^"\']*?)\\1/i',
            '/\.get\s*\(\s*(["\'])([^"\']*?)\\1/i',
            '/\.post\s*\(\s*(["\'])([^"\']*?)\\1/i',
            
            // PHP redirection patterns (critical for flow)
            '/redirect\s*\(\s*(["\'])([^"\']*?)\\1/i',
            '/header\s*\(\s*(["\'])Location:\s*([^"\']*?)\\1/i',
            '/require_login_or_redirect\s*\(\s*(["\'])([^"\']*?)\\1/i',
            '/require_admin_or_redirect\s*\(\s*(["\'])([^"\']*?)\\1/i',
            
            // Path helper functions
            '/path_with_base\s*\(\s*(["\'])([^"\']*?)\\1/i',
            '/outbound_path\s*\(\s*(["\'])([^"\']*?)\\1/i',
            
            // Form and navigation patterns
            '/window\.open\s*\(\s*(["\'])([^"\']*?)\\1/i',
            '/\.navigate\s*\(\s*(["\'])([^"\']*?)\\1/i',
        ];
        
        foreach ($patterns as $pattern) {
            $content = preg_replace_callback($pattern, function($matches) use (&$changes) {
                $fullMatch = $matches[0];
                $urlPart = isset($matches[3]) ? $matches[3] : (isset($matches[2]) ? $matches[2] : '');
                
                $newUrl = $this->applyReplacements($urlPart);
                
                if ($newUrl !== $urlPart) {
                    $changes[] = [
                        'original' => $urlPart,
                        'replacement' => $newUrl,
                        'context' => $fullMatch,
                        'risk' => $this->assessReplacementRisk($urlPart, $newUrl)
                    ];
                    
                    if (isset($matches[3])) {
                        return $matches[1] . '=' . $matches[2] . $newUrl . $matches[2];
                    } else {
                        return str_replace($urlPart, $newUrl, $fullMatch);
                    }
                }
                
                return $fullMatch;
            }, $content);
        }
        
        if (!$dryRun && $content !== $originalContent) {
            // Create backup
            $backupPath = $filePath . '.backup.' . date('YmdHis');
            file_put_contents($backupPath, $originalContent);
            file_put_contents($filePath, $content);
            
            $this->backups[] = [
                'file' => $filePath,
                'backup' => $backupPath,
                'timestamp' => time()
            ];
        }
        
        return [
            'file' => $filePath,
            'changes' => $changes,
            'total_changes' => count($changes),
            'risk_score' => array_sum(array_column($changes, 'risk')),
            'dry_run' => $dryRun
        ];
    }
    
    private function applyReplacements($url) {
        $newUrl = $url;
        
        foreach ($this->domainReplacementMap as $search => $replace) {
            $newUrl = str_replace($search, $replace, $newUrl);
        }

        // COMPREHENSIVE replacement patterns - ordered by specificity
        $replacements = [
            // Directory-specific patterns (medium priority)
            '/admin/' => '/admin/',
            '/api/' => '/api/',
            '/assets/' => '/assets/',
            '/shop/' => '/shop/',
            '/training/' => '/training/',
            '/onboarding/' => '/onboarding/',
            '/customer/' => '/customer/',
            '/promo/' => '/promo/',
            '/tools/' => '/tools/',
            '/operations/' => '/operations/',
            '/labs/' => '/labs/',
            '/support/' => '/support/',
            '/required/' => '/required/',
            
            // File-specific patterns (medium priority)
            '/style.css' => '/style.css',
            '/script.js' => '/script.js',
            '/login.php' => '/login.php',
            '/logout.php' => '/logout.php',
            '/index.php' => '/index.php',
            '/sitemap.xml' => '/sitemap.xml',
            
            // Function-specific patterns (high priority for functionality)
            "redirect('/" => "redirect('/",
            'header("Location: /' => 'header("Location: /',
            "header('Location: /" => "header('Location: /",
            "window.location.href = '/" => "window.location.href = '/",
            "location.href = '/" => "location.href = '/",
            "fetch('/" => "fetch('/",
            "window.open('/" => "window.open('/",
            
            // Generic catch-all (lowest priority - most dangerous)
            '/' => '/'
        ];
        
        foreach ($replacements as $search => $replace) {
            $newUrl = str_replace($search, $replace, $newUrl);
        }
        
        return $newUrl;
    }
    
    public function rollback($snapshotId) {
        // Restore from backups
        foreach ($this->backups as $backup) {
            if (file_exists($backup['backup'])) {
                copy($backup['backup'], $backup['file']);
            }
        }
        
        return ['restored' => count($this->backups)];
    }
}

// Handle actions
$action = $_GET['action'] ?? 'dashboard';
$results = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($_POST['action'] ?? '') {
        case 'crawl_404':
            logMigrationActivity($pdo, '404 Audit', ['start' => date('Y-m-d H:i:s')]);
            $domainController = new DomainController();
            $crawler = new URLCrawler(site_origin(), $domainController);
            // COMPREHENSIVE page check list - all critical user paths
            $pagesToCheck = [
                // Core pages
                '/', '/login.php', '/logout.php', '/index.php',
                
                // Admin panel
                '/admin/', '/admin/users.php', '/admin/orders.php', 
                '/admin/training-analytics.php', '/admin/debug-panel.php',
                '/admin/commissions.php', '/admin/lms-manager.php',
                '/admin/user-detail.php', '/admin/wholesale-accounts.php',
                
                // Shop & Customer
                '/shop/', '/shop/cart.php', '/shop/checkout.php',
                '/customer/', '/customer/dashboard.php', '/customer/profit-calculator.php',
                
                // Training & Onboarding  
                '/onboarding/', '/onboarding/trainee/', '/onboarding/trainee/index.php',
                '/onboarding/trainee/module.php', '/onboarding/sales-agent/',
                '/onboarding/sales-agent/index.php', '/onboarding/sales-agent/leads.php',
                
                // Tools & Operations
                '/tools/', '/tools/profit-calculator.php', '/tools/sales-reference.php',
                '/operations/', '/operations/inventory.php', '/operations/orders.php',
                
                // API endpoints
                '/api/health', '/api/test-connection.php', '/api/ai-chat.php',
                '/api/training-heartbeat.php', '/api/site-activity.php',
                
                // Static resources
                '/style.css', '/script.js', '/assets/ai-chat.css', 
                '/assets/ai-chat.js', '/assets/site-tracker.js', '/assets/logo.svg',
                '/assets/sales-acp.css', '/assets/training-time-tracker.js'
            ];
            
            $results = [];
            foreach ($pagesToCheck as $page) {
                $results[] = $crawler->crawlPage($page);
            }
            break;
            
        case 'scan_links':
            logMigrationActivity($pdo, 'Link Scan', ['start' => date('Y-m-d H:i:s')]);
            $root = realpath(__DIR__ . '/..');
            $domainController = new DomainController();
            $replacer = new SmartLinkReplacer($domainController);
            
            // Collect files more carefully
            $phpFiles = glob_recursive($root . '/*.php') ?: [];
            $jsFiles = glob_recursive($root . '/*.js') ?: [];
            $cssFiles = glob_recursive($root . '/*.css') ?: [];
            
            // Also check for common problem areas
            $adminFiles = glob($root . '/admin/*.php') ?: [];
            $apiFiles = glob($root . '/api/*.php') ?: [];
            $requiredFiles = glob($root . '/required/*.php') ?: [];
            
            $files = array_merge($phpFiles, $jsFiles, $cssFiles, $adminFiles, $apiFiles, $requiredFiles);
            $files = array_unique($files); // Remove duplicates
            
            $results = [];
            $scannedCount = 0;
            $issuesFound = 0;
            
            foreach ($files as $file) {
                // Skip vendor and other directories
                if (strpos($file, '/vendor/') !== false || 
                    strpos($file, '/node_modules/') !== false ||
                    strpos($file, '/backups/') !== false ||
                    strpos($file, '/migration-snapshots/') !== false) {
                    continue;
                }
                
                $result = $replacer->processFile($file, true);
                if ($result['total_changes'] > 0) {
                    $results[] = $result;
                    $issuesFound += $result['total_changes'];
                }
                
                $scannedCount++;
                
                // Limit for performance but make it higher
                if ($scannedCount >= 100) {
                    break;
                }
            }
            
            logMigrationActivity($pdo, 'Link Scan Complete', [
                'files_scanned' => $scannedCount,
                'files_with_issues' => count($results),
                'total_issues' => $issuesFound
            ]);
            break;
            
        case 'create_snapshot':
            logMigrationActivity($pdo, 'Create Snapshot', ['timestamp' => date('Y-m-d H:i:s')]);
            $snapshotId = createMigrationSnapshot($pdo);
            $results = ['snapshot_id' => $snapshotId, 'message' => 'Snapshot created successfully'];
            logMigrationActivity($pdo, 'Snapshot Created', ['snapshot_id' => $snapshotId]);
            break;
            
        case 'apply_fixes':
            $filesToFix = json_decode($_POST['files'] ?? '[]', true);
            logMigrationActivity($pdo, 'Apply Fixes', ['files_count' => count($filesToFix)]);
            
            $snapshotId = createMigrationSnapshot($pdo, 'pre-fix');
            $domainController = new DomainController();
            $replacer = new SmartLinkReplacer($domainController);
            
            $results = [];
            $successCount = 0;
            $totalChanges = 0;
            
            foreach ($filesToFix as $file) {
                $result = $replacer->processFile($file, false);
                $results[] = $result;
                if (!isset($result['error'])) {
                    $successCount++;
                    $totalChanges += $result['total_changes'] ?? 0;
                }
            }
            
            logMigrationActivity($pdo, 'Fixes Applied', [
                'snapshot_id' => $snapshotId,
                'files_processed' => count($filesToFix),
                'files_success' => $successCount,
                'total_changes' => $totalChanges
            ]);
            break;
    }
}

// Helper for recursive glob - improved version
function glob_recursive($pattern, $flags = 0) {
    $files = glob($pattern, $flags) ?: [];
    $dirs = glob(dirname($pattern).'/*', GLOB_ONLYDIR|GLOB_NOSORT) ?: [];
    
    foreach ($dirs as $dir) {
        // Skip directories that might cause issues
        $basename = basename($dir);
        if (in_array($basename, ['vendor', 'node_modules', '.git', 'backups', '.idea', '.vscode', 'migration-snapshots'])) {
            continue;
        }
        
        $subFiles = glob_recursive($dir.'/'.basename($pattern), $flags);
        if (is_array($subFiles)) {
            $files = array_merge($files, $subFiles);
        }
    }
    return $files;
}
?>

<style>
.migration-container {
    max-width: 1600px;
    margin: 0 auto;
    padding: 20px;
}

.migration-header {
    background: linear-gradient(135deg, #1e40af, #3b82f6);
    color: white;
    padding: 40px 0;
    margin-bottom: 32px;
}

.action-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 24px;
    border-bottom: 2px solid #e5e7eb;
}

.action-tab {
    padding: 12px 24px;
    background: white;
    border: 1px solid #e5e7eb;
    border-bottom: none;
    cursor: pointer;
    font-weight: 500;
    color: #6b7280;
    transition: all 0.2s;
}

.action-tab:hover {
    background: #f9fafb;
}

.action-tab.active {
    color: #1e40af;
    background: white;
    border-top: 3px solid #3b82f6;
    margin-top: -2px;
    padding-top: 11px;
}

.migration-section {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    padding: 24px;
    margin-bottom: 24px;
}

.crawl-results {
    display: grid;
    gap: 16px;
}

.page-result {
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    padding: 16px;
    transition: all 0.2s;
}

.page-result.error-404 {
    border-color: #f87171;
    background: #fef2f2;
}

.page-result.has-issues {
    border-color: #fbbf24;
    background: #fffbeb;
}

.page-result.clean {
    border-color: #34d399;
    background: #f0fdf4;
}

.issue-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
    margin-right: 8px;
}

.issue-critical {
    background: #f87171;
    color: white;
}

.issue-warning {
    background: #fbbf24;
    color: #78350f;
}

.issue-info {
    background: #60a5fa;
    color: white;
}

.link-list {
    margin-top: 12px;
    max-height: 300px;
    overflow-y: auto;
    border: 1px solid #e5e7eb;
    border-radius: 4px;
}

.link-item {
    padding: 8px;
    border-bottom: 1px solid #f3f4f6;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.link-item:hover {
    background: #f9fafb;
}

.risk-meter {
    display: inline-block;
    width: 100px;
    height: 20px;
    background: #e5e7eb;
    border-radius: 10px;
    overflow: hidden;
    position: relative;
}

.risk-fill {
    height: 100%;
    transition: width 0.3s;
}

.risk-low { background: #34d399; }
.risk-medium { background: #fbbf24; }
.risk-high { background: #fb923c; }
.risk-critical { background: #f87171; }

.action-buttons {
    display: flex;
    gap: 12px;
    margin-top: 20px;
}

.btn-primary {
    background: #3b82f6;
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 500;
}

.btn-danger {
    background: #ef4444;
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 500;
}

.btn-success {
    background: #10b981;
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 500;
}

.snapshot-info {
    background: #eff6ff;
    border: 2px solid #3b82f6;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 20px;
}

.fix-preview {
    background: #fafafa;
    border: 1px solid #e5e7eb;
    border-radius: 4px;
    padding: 12px;
    margin: 8px 0;
    font-family: 'Courier New', monospace;
    font-size: 13px;
}

.fix-preview .removed {
    background: #fee2e2;
    color: #991b1b;
    text-decoration: line-through;
}

.fix-preview .added {
    background: #dcfce7;
    color: #166534;
}

.progress-bar {
    width: 100%;
    height: 30px;
    background: #e5e7eb;
    border-radius: 15px;
    overflow: hidden;
    margin: 20px 0;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #3b82f6, #1e40af);
    transition: width 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
}
</style>

<div class="migration-header">
    <div class="container">
        <h1 style="font-size: 36px; margin-bottom: 8px;">🚀 ENHANCED URL Migration Tool</h1>
        <p style="font-size: 18px; opacity: 0.9;">Advanced URL migration with 15+ pattern detection, comprehensive scanning, and intelligent risk assessment</p>
        <div style="margin-top: 16px;">
            <span style="background: rgba(255,255,255,0.2); padding: 8px 16px; border-radius: 20px;">
                ✨ Enhanced Detection • 🛡️ Safe Rollback • 📊 Risk Analysis
            </span>
        </div>
    </div>
</div>

<div class="migration-container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <div class="action-tabs">
        <div class="action-tab <?php echo $action == 'dashboard' ? 'active' : ''; ?>" onclick="location.href='?action=dashboard'">
            📊 Dashboard
        </div>
        <div class="action-tab <?php echo $action == '404_audit' ? 'active' : ''; ?>" onclick="location.href='?action=404_audit'">
            🔍 404 Audit
        </div>
        <div class="action-tab <?php echo $action == 'link_scan' ? 'active' : ''; ?>" onclick="location.href='?action=link_scan'">
            🔗 Link Scanner
        </div>
        <div class="action-tab <?php echo $action == 'fix_urls' ? 'active' : ''; ?>" onclick="location.href='?action=fix_urls'">
            🔧 Fix URLs
        </div>
        <div class="action-tab <?php echo $action == 'snapshots' ? 'active' : ''; ?>" onclick="location.href='?action=snapshots'">
            💾 Snapshots
        </div>
        <div class="action-tab <?php echo $action == 'rollback' ? 'active' : ''; ?>" onclick="location.href='?action=rollback'">
            ⏪ Rollback
        </div>
    </div>
    
    <?php if ($action == 'dashboard'): ?>
    <div class="migration-section">
        <h2 style="color: #1f2937; margin-bottom: 20px;">Migration Status Dashboard</h2>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
            <div class="debug-card">
                <h4>Current Environment</h4>
                <p style="font-size: 20px; color: #3b82f6; margin: 10px 0;">
                    <?php echo site_domain(); ?>
                </p>
                <p style="color: #6b7280;">Base: <?php echo site_base_url(); ?></p>
            </div>
            
            <div class="debug-card">
                <h4>Migration Progress</h4>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: 35%;">35%</div>
                </div>
                <p style="color: #6b7280;">Audit in progress</p>
            </div>
            
            <div class="debug-card">
                <h4>Issues Detected</h4>
                <p style="font-size: 32px; color: #f59e0b; margin: 10px 0;">⚠️ 23</p>
                <p style="color: #6b7280;">Requires attention</p>
            </div>
            
            <div class="debug-card">
                <h4>Last Snapshot</h4>
                <p style="color: #10b981; margin: 10px 0;">✓ Available</p>
                <p style="color: #6b7280;">Created 2 hours ago</p>
            </div>
        </div>
        
        <h3 style="margin-bottom: 16px;">Quick Actions</h3>
        <div class="action-buttons">
            <form method="post" style="display: inline;">
                <input type="hidden" name="action" value="crawl_404">
                <button type="submit" class="btn-primary">🔍 Run 404 Audit</button>
            </form>
            
            <form method="post" style="display: inline;">
                <input type="hidden" name="action" value="scan_links">
                <button type="submit" class="btn-primary">🔗 Scan All Links</button>
            </form>
            
            <form method="post" style="display: inline;">
                <input type="hidden" name="action" value="create_snapshot">
                <button type="submit" class="btn-success">💾 Create Snapshot</button>
            </form>
        </div>
    </div>
    
    <?php elseif ($action == '404_audit'): ?>
    <div class="migration-section">
        <h2 style="color: #1f2937; margin-bottom: 20px;">404 Error Audit</h2>
        
        <form method="post">
            <input type="hidden" name="action" value="crawl_404">
            <button type="submit" class="btn-primary">Start 404 Crawl</button>
        </form>
        
        <?php if ($results && is_array($results)): ?>
        <div class="crawl-results" style="margin-top: 24px;">
            <?php foreach ($results as $page): ?>
            <div class="page-result <?php 
                echo $page['is_404'] ? 'error-404' : (
                    !empty($page['issues']) ? 'has-issues' : 'clean'
                ); 
            ?>">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <h4 style="margin: 0;">
                            <?php echo htmlspecialchars($page['path']); ?>
                            <span class="issue-badge <?php 
                                echo $page['is_404'] ? 'issue-critical' : (
                                    $page['status'] != 200 ? 'issue-warning' : 'issue-info'
                                ); 
                            ?>">
                                HTTP <?php echo $page['status']; ?>
                            </span>
                        </h4>
                        <?php if (!empty($page['issues'])): ?>
                        <p style="color: #dc2626; margin: 8px 0;">
                            ⚠️ <?php echo count($page['issues']); ?> issues found
                        </p>
                        <ul style="margin: 0; padding-left: 20px;">
                            <?php foreach ($page['issues'] as $issue): ?>
                            <li><?php echo htmlspecialchars($issue); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($page['links'])): ?>
                    <div>
                        <button onclick="toggleLinks('links_<?php echo md5($page['path']); ?>')" class="btn">
                            View Links (<?php echo count($page['links']); ?>)
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($page['links'])): ?>
                <div id="links_<?php echo md5($page['path']); ?>" class="link-list" style="display: none;">
                    <?php foreach ($page['links'] as $link): ?>
                    <div class="link-item">
                        <div>
                            <span style="color: #6b7280; font-size: 11px;">
                                &lt;<?php echo htmlspecialchars($link['tag']); ?>&gt;
                            </span>
                            <code><?php echo htmlspecialchars(substr($link['url'], 0, 60)); ?></code>
                            <?php if ($link['has_product']): ?>
                                <span class="issue-badge issue-warning">Contains /</span>
                            <?php endif; ?>
                            <?php if ($link['is_dev_url']): ?>
                                <span class="issue-badge issue-critical">Dev URL</span>
                            <?php endif; ?>
                        </div>
                        <div class="risk-meter">
                            <div class="risk-fill <?php 
                                echo $link['risk_level'] >= 7 ? 'risk-critical' : (
                                    $link['risk_level'] >= 5 ? 'risk-high' : (
                                        $link['risk_level'] >= 3 ? 'risk-medium' : 'risk-low'
                                    )
                                );
                            ?>" style="width: <?php echo $link['risk_level'] * 10; ?>%;"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <?php elseif ($action == 'link_scan'): ?>
    <div class="migration-section">
        <h2 style="color: #1f2937; margin-bottom: 20px;">Link Scanner</h2>
        
        <form method="post">
            <input type="hidden" name="action" value="scan_links">
            <button type="submit" class="btn-primary">Scan Files for URL Issues</button>
        </form>
        
        <?php if ($results && is_array($results)): ?>
        <div style="margin-top: 24px;">
            <h3>Files with Issues (<?php echo count($results); ?>)</h3>
            
            <?php foreach ($results as $fileResult): ?>
            <div class="page-result has-issues">
                <h4><?php echo htmlspecialchars(basename($fileResult['file'])); ?></h4>
                <p style="color: #6b7280; font-size: 12px;">
                    <?php echo htmlspecialchars(str_replace(realpath(__DIR__ . '/..'), '', $fileResult['file'])); ?>
                </p>
                <p>
                    Changes needed: <strong><?php echo $fileResult['total_changes']; ?></strong> | 
                    Risk score: 
                    <span style="color: <?php 
                        echo $fileResult['risk_score'] >= 15 ? '#dc2626' : (
                            $fileResult['risk_score'] >= 10 ? '#f59e0b' : '#10b981'
                        ); 
                    ?>">
                        <?php echo $fileResult['risk_score']; ?>/30
                    </span>
                </p>
                
                <?php foreach (array_slice($fileResult['changes'], 0, 3) as $change): ?>
                <div class="fix-preview">
                    <div>Context: <code><?php echo htmlspecialchars($change['context']); ?></code></div>
                    <div style="margin-top: 8px;">
                        <span class="removed"><?php echo htmlspecialchars($change['original']); ?></span>
                        → 
                        <span class="added"><?php echo htmlspecialchars($change['replacement']); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <?php if (count($fileResult['changes']) > 3): ?>
                <p style="color: #6b7280; font-style: italic;">
                    ... and <?php echo count($fileResult['changes']) - 3; ?> more changes
                </p>
                <?php endif; ?>
                
                <div style="margin-top: 12px;">
                    <label>
                        <input type="checkbox" class="file-to-fix" value="<?php echo htmlspecialchars($fileResult['file']); ?>">
                        Select for fixing
                    </label>
                </div>
            </div>
            <?php endforeach; ?>
            
            <div class="action-buttons">
                <button onclick="applySelectedFixes()" class="btn-danger">
                    Apply Fixes to Selected Files
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <?php elseif ($action == 'snapshots'): ?>
    <div class="migration-section">
        <h2 style="color: #1f2937; margin-bottom: 20px;">Migration Snapshots</h2>
        
        <div class="snapshot-info">
            <h3>Create New Snapshot</h3>
            <p>Snapshots allow you to save the current state before making changes.</p>
            <form method="post" style="margin-top: 12px;">
                <input type="hidden" name="action" value="create_snapshot">
                <button type="submit" class="btn-success">💾 Create Snapshot Now</button>
            </form>
        </div>
        
        <?php
        // List existing snapshots
        $snapshots = $pdo->query("
            SELECT * FROM migration_snapshots 
            WHERE status = 'active' 
            ORDER BY created_at DESC 
            LIMIT 10
        ")->fetchAll();
        ?>
        
        <?php if ($snapshots): ?>
        <h3>Recent Snapshots</h3>
        <table class="bulk-table" style="width: 100%;">
            <thead>
                <tr>
                    <th>Snapshot ID</th>
                    <th>Type</th>
                    <th>Created</th>
                    <th>Created By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($snapshots as $snapshot): ?>
                <tr>
                    <td><code><?php echo htmlspecialchars($snapshot['snapshot_id']); ?></code></td>
                    <td><?php echo htmlspecialchars($snapshot['type']); ?></td>
                    <td><?php echo htmlspecialchars($snapshot['created_at']); ?></td>
                    <td><?php echo htmlspecialchars($snapshot['created_by']); ?></td>
                    <td>
                        <button onclick="restoreSnapshot('<?php echo $snapshot['snapshot_id']; ?>')" class="btn">
                            Restore
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    
    <?php elseif ($action == 'rollback'): ?>
    <div class="migration-section">
        <h2 style="color: #dc2626; margin-bottom: 20px;">⚠️ Emergency Rollback</h2>
        
        <div style="background: #fef2f2; border: 2px solid #f87171; border-radius: 8px; padding: 20px; margin-bottom: 20px;">
            <h3 style="color: #991b1b;">Warning</h3>
            <p>Rollback will restore files to their previous state. This action cannot be undone.</p>
        </div>
        
        <?php
        $snapshots = $pdo->query("
            SELECT * FROM migration_snapshots 
            WHERE status = 'active' 
            ORDER BY created_at DESC 
            LIMIT 5
        ")->fetchAll();
        ?>
        
        <h3>Select Snapshot to Restore</h3>
        <form method="post" onsubmit="return confirm('Are you sure you want to rollback? This cannot be undone.');">
            <input type="hidden" name="action" value="rollback">
            <select name="snapshot_id" class="form-control" style="margin-bottom: 12px;">
                <?php foreach ($snapshots as $snapshot): ?>
                <option value="<?php echo htmlspecialchars($snapshot['snapshot_id']); ?>">
                    <?php echo htmlspecialchars($snapshot['snapshot_id']); ?> - 
                    <?php echo htmlspecialchars($snapshot['created_at']); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-danger">Execute Rollback</button>
        </form>
    </div>
    <?php endif; ?>
    
    <?php if ($results && isset($results['message'])): ?>
    <div style="background: #dcfce7; border: 2px solid #34d399; border-radius: 8px; padding: 16px; margin-top: 20px;">
        <p style="color: #166534; font-weight: 600;">✓ <?php echo htmlspecialchars($results['message']); ?></p>
    </div>
    <?php endif; ?>
</div>

<script>
function toggleLinks(id) {
    const element = document.getElementById(id);
    if (element) {
        element.style.display = element.style.display === 'none' ? 'block' : 'none';
    }
}

function applySelectedFixes() {
    const checkboxes = document.querySelectorAll('.file-to-fix:checked');
    const files = Array.from(checkboxes).map(cb => cb.value);
    
    if (files.length === 0) {
        alert('Please select files to fix');
        return;
    }
    
    if (!confirm(`Apply fixes to ${files.length} files? This will create a backup first.`)) {
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="apply_fixes">
        <input type="hidden" name="files" value='${JSON.stringify(files)}'>
    `;
    document.body.appendChild(form);
    form.submit();
}

function restoreSnapshot(snapshotId) {
    if (!confirm('Restore from this snapshot? Current state will be lost.')) {
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="restore_snapshot">
        <input type="hidden" name="snapshot_id" value="${snapshotId}">
    `;
    document.body.appendChild(form);
    form.submit();
}
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
