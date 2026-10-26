<?php
/**
 * ENHANCED URL SCANNER - Ultimate / Detection Tool
 * 
 * This script performs the most comprehensive scan possible for any remaining
 * / references across the entire Product platform
 */

$SEO = [
    'title' => 'Enhanced URL Scanner — Ultimate Detection',
    'description' => 'Most comprehensive / reference detection system',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect('/admin/');

$pdo = db();

// ULTIMATE URL PATTERN DETECTION SYSTEM
class UltimateURLScanner {
    private $patterns = [
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
        
        // Domain patterns
        'dev_domain_full' => "/https?:\/\/legacy\.example\/product\/[^'\"\s]*/i",
        'prod_double_product' => "/https?:\/\/productproduct\.com\/product\/[^'\"\s]*/i",
        
        // Generic patterns (catch-all)
        'quoted_product_single' => "/'\/product\/[^']*'/",
        'quoted_product_double' => '/"\/product\/[^"]*"/',
        'url_segment_product' => "/\/product\/[a-zA-Z0-9\/_\-\.]+/",
    ];
    
    private $criticalFiles = [
        // Core system files
        'login.php', 'logout.php', 'index.php', 'index-sales.php',
        
        // Authentication & redirects
        'required/registration.php', 'required/config.php', 'required/login.php',
        
        // Admin files
        'admin/*.php',
        
        // Customer files  
        'customer/*.php', 'required/customer/*.php',
        
        // Shop files
        'shop/*.php', 'required/shop/*.php',
        
        // Training files
        'onboarding/*.php', 'required/onboarding/**/*.php',
        
        // Operations files
        'operations/*.php', 'required/operations/*.php',
        
        // API files
        'api/*.php', 'required/api/*.php',
        
        // Asset files
        'assets/*.js', 'required/assets/*.js',
        
        // Tools
        'tools/*.php', 'required/tools/*.php'
    ];
    
    private $results = [];
    private $totalScanned = 0;
    private $issuesFound = 0;
    
    public function scanFile($filePath) {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return null;
        }
        
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }
        
        $this->totalScanned++;
        $issues = [];
        $lineNumber = 0;
        
        foreach (explode("\n", $content) as $lineNumber => $line) {
            foreach ($this->patterns as $patternName => $pattern) {
                if (preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as $match) {
                        $issues[] = [
                            'pattern' => $patternName,
                            'line' => $lineNumber + 1,
                            'content' => trim($line),
                            'match' => $match[0],
                            'position' => $match[1],
                            'risk_level' => $this->assessRiskLevel($patternName, $match[0])
                        ];
                        $this->issuesFound++;
                    }
                }
            }
        }
        
        if (!empty($issues)) {
            $this->results[] = [
                'file' => $filePath,
                'relative_path' => str_replace(realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR, '', $filePath),
                'issues' => $issues,
                'total_issues' => count($issues),
                'max_risk' => max(array_column($issues, 'risk_level')),
                'file_size' => filesize($filePath)
            ];
        }
        
        return $issues;
    }
    
    private function assessRiskLevel($patternName, $match) {
        $risk = 1; // Base risk
        
        // Critical patterns (break functionality)
        if (in_array($patternName, ['redirect_calls', 'header_redirects', 'window_location', 'fetch_calls'])) {
            $risk += 8; // Very high risk
        }
        
        // High risk patterns (user experience)
        if (in_array($patternName, ['href_links', 'action_forms', 'require_redirects'])) {
            $risk += 5; // High risk
        }
        
        // Medium risk patterns (functionality)
        if (in_array($patternName, ['src_attributes', 'ajax_urls', 'window_open'])) {
            $risk += 3; // Medium risk
        }
        
        // Domain-specific risks
        if (strpos($match, 'legacy.example') !== false) {
            $risk += 6; // Very high - dev domain in production
        }
        
        if (strpos($match, 'product.example/product') !== false) {
            $risk += 7; // Critical - double path issue
        }
        
        return min($risk, 10); // Cap at 10
    }
    
    public function scanDirectory($directory, $recursive = true) {
        $iterator = $recursive 
            ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory))
            : new DirectoryIterator($directory);
            
        foreach ($iterator as $file) {
            if ($file->isFile() && $this->shouldScanFile($file->getPathname())) {
                $this->scanFile($file->getPathname());
            }
        }
    }
    
    private function shouldScanFile($filePath) {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $allowedExts = ['php', 'js', 'css', 'html', 'htm', 'json', 'xml'];
        
        // Skip non-text files
        if (!in_array($ext, $allowedExts)) {
            return false;
        }
        
        // Skip backup and archive directories
        $skipPaths = ['vendor/', 'node_modules/', '.git/', 'backups/', '00-PLANNING/OLD', 'migration-snapshots/'];
        foreach ($skipPaths as $skipPath) {
            if (strpos($filePath, $skipPath) !== false) {
                return false;
            }
        }
        
        return true;
    }
    
    public function getResults() {
        return [
            'files_scanned' => $this->totalScanned,
            'files_with_issues' => count($this->results),
            'total_issues' => $this->issuesFound,
            'results' => $this->results,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    public function generateFixScript($outputPath = null) {
        $script = "#!/bin/bash\n";
        $script .= "# COMPREHENSIVE PRODUCT URL FIX SCRIPT\n";
        $script .= "# Generated: " . date('Y-m-d H:i:s') . "\n";
        $script .= "# Issues found: {$this->issuesFound} across " . count($this->results) . " files\n\n";
        
        foreach ($this->results as $fileResult) {
            $script .= "# File: {$fileResult['relative_path']}\n";
            $script .= "# Issues: {$fileResult['total_issues']}, Max Risk: {$fileResult['max_risk']}/10\n";
            
            foreach ($fileResult['issues'] as $issue) {
                $script .= "#   Line {$issue['line']}: {$issue['pattern']} - Risk {$issue['risk_level']}/10\n";
                $script .= "#   Content: " . substr($issue['content'], 0, 80) . "\n";
            }
            $script .= "\n";
        }
        
        $script .= "# Run the following commands to fix issues:\n";
        $script .= "cd " . realpath(__DIR__ . '/..') . "\n";
        $script .= "php admin/url-migration-tool.php --auto-fix\n";
        
        if ($outputPath) {
            file_put_contents($outputPath, $script);
        }
        
        return $script;
    }
}

// Enhanced verification runner
function runComprehensiveVerification() {
    $scanner = new UltimateURLScanner();
    $rootDir = realpath(__DIR__ . '/..');
    
    // Scan critical directories
    $criticalDirs = [
        $rootDir . '/admin',
        $rootDir . '/api', 
        $rootDir . '/assets',
        $rootDir . '/customer',
        $rootDir . '/onboarding',
        $rootDir . '/operations',
        $rootDir . '/promo',
        $rootDir . '/required',
        $rootDir . '/shop',
        $rootDir . '/tools'
    ];
    
    foreach ($criticalDirs as $dir) {
        if (is_dir($dir)) {
            $scanner->scanDirectory($dir, true);
        }
    }
    
    // Also scan root level files
    $rootFiles = glob($rootDir . '/*.{php,js,css,html}', GLOB_BRACE);
    foreach ($rootFiles as $file) {
        $scanner->scanFile($file);
    }
    
    return $scanner->getResults();
}

// Handle actions
$scanResults = null;
$fixResults = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch ($_POST['action'] ?? '') {
            case 'comprehensive_scan':
                $scanResults = runComprehensiveVerification();
                break;
                
            case 'generate_fix_script':
                $scanner = new UltimateURLScanner();
                $rootDir = realpath(__DIR__ . '/..');
                
                // Quick scan to generate script
                $scanner->scanDirectory($rootDir . '/admin', true);
                $scanner->scanDirectory($rootDir . '/required', true);
                $scanner->scanDirectory($rootDir . '/shop', true);
                
                $scriptPath = __DIR__ . '/generated-fix-script-' . date('Ymd-His') . '.sh';
                $script = $scanner->generateFixScript($scriptPath);
                
                header('Content-Type: text/plain');
                header('Content-Disposition: attachment; filename="product-url-fix-' . date('Ymd-His') . '.sh"');
                echo $script;
                exit;
                break;
                
            case 'verify_clean':
                // Quick verification that no critical patterns remain
                $criticalPatterns = [
                    "redirect('/" => 'PHP redirect calls',
                    'window.location.href = \'/' => 'JavaScript redirects', 
                    'fetch(\'/' => 'AJAX calls',
                    'href="/' => 'Navigation links',
                    'action="/' => 'Form actions'
                ];
                
                $findings = [];
                $rootDir = realpath(__DIR__ . '/..');
                
                foreach ($criticalPatterns as $pattern => $description) {
                    $command = "grep -r --include='*.php' --include='*.js' --include='*.html' " . 
                              escapeshellarg($pattern) . " " . escapeshellarg($rootDir) . " 2>/dev/null";
                    
                    exec($command, $output, $returnCode);
                    
                    if (!empty($output)) {
                        $findings[$description] = count($output);
                    }
                }
                
                $scanResults = [
                    'verification_type' => 'clean_check',
                    'timestamp' => date('Y-m-d H:i:s'),
                    'findings' => $findings,
                    'is_clean' => empty($findings),
                    'summary' => empty($findings) ? 'COMPLETELY CLEAN - No critical patterns found!' : 'Issues detected - review findings'
                ];
                break;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<style>
.scanner-container {
    max-width: 1600px;
    margin: 0 auto;
    padding: 20px;
}

.scanner-header {
    background: linear-gradient(135deg, #7c2d12, #dc2626);
    color: white;
    padding: 40px 0;
    margin-bottom: 32px;
    text-align: center;
}

.scanner-section {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    padding: 24px;
    margin-bottom: 24px;
}

.pattern-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 16px;
    margin: 20px 0;
}

.pattern-card {
    background: #f9fafb;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    padding: 16px;
    text-align: center;
}

.risk-indicator {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    margin: 4px;
}

.risk-critical { background: #fee2e2; color: #991b1b; }
.risk-high { background: #fed7aa; color: #92400e; }
.risk-medium { background: #fef3c7; color: #78350f; }
.risk-low { background: #d1fae5; color: #065f46; }

.issue-details {
    background: #fafafa;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 12px;
    margin: 8px 0;
    font-family: 'Courier New', monospace;
    font-size: 13px;
}

.scan-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin: 20px 0;
}

.stat-box {
    background: #f8fafc;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    padding: 20px;
    text-align: center;
}

.stat-number {
    font-size: 32px;
    font-weight: bold;
    margin: 8px 0;
}

.clean-badge {
    background: #dcfce7;
    color: #166534;
    padding: 8px 16px;
    border-radius: 20px;
    font-weight: 600;
    border: 2px solid #22c55e;
}

.issue-badge {
    background: #fee2e2;
    color: #991b1b;
    padding: 8px 16px;
    border-radius: 20px;
    font-weight: 600;
    border: 2px solid #ef4444;
}
</style>

<div class="scanner-header">
    <div class="container">
        <h1 style="font-size: 42px; margin-bottom: 12px;">🔍 ULTIMATE URL SCANNER</h1>
        <p style="font-size: 20px; opacity: 0.9;">Most Comprehensive / Detection System</p>
        <div style="margin-top: 16px;">
            <span style="background: rgba(255,255,255,0.2); padding: 8px 16px; border-radius: 20px;">
                🎯 15+ Pattern Types • 🔍 Deep File Analysis • 📊 Risk Assessment
            </span>
        </div>
    </div>
</div>

<div class="scanner-container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <!-- Control Panel -->
    <div class="scanner-section">
        <h2 style="color: #dc2626; margin-bottom: 20px;">🚀 ENHANCED SCANNING CONTROLS</h2>
        
        <div class="pattern-grid">
            <div class="pattern-card">
                <h4>🔍 Comprehensive Scan</h4>
                <p style="color: #6b7280; font-size: 14px;">Scan entire codebase with 15+ patterns</p>
                <form method="post" style="margin-top: 12px;">
                    <input type="hidden" name="action" value="comprehensive_scan">
                    <button type="submit" class="btn" style="background: #dc2626; color: white;">
                        Run Full Scan
                    </button>
                </form>
            </div>
            
            <div class="pattern-card">
                <h4>✅ Clean Verification</h4>
                <p style="color: #6b7280; font-size: 14px;">Quick check for critical patterns only</p>
                <form method="post" style="margin-top: 12px;">
                    <input type="hidden" name="action" value="verify_clean">
                    <button type="submit" class="btn" style="background: #059669; color: white;">
                        Verify Clean
                    </button>
                </form>
            </div>
            
            <div class="pattern-card">
                <h4>📜 Generate Fix Script</h4>
                <p style="color: #6b7280; font-size: 14px;">Create automated fix script</p>
                <form method="post" style="margin-top: 12px;">
                    <input type="hidden" name="action" value="generate_fix_script">
                    <button type="submit" class="btn" style="background: #3b82f6; color: white;">
                        Generate Script
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Pattern Detection Info -->
    <div class="scanner-section">
        <h2 style="color: #1f2937; margin-bottom: 20px;">🎯 Detection Patterns</h2>
        <p style="color: #6b7280; margin-bottom: 16px;">The enhanced scanner checks for these specific patterns:</p>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px;">
            <div>
                <h4 style="color: #dc2626;">🚨 Critical Patterns (Risk 8-10)</h4>
                <ul style="font-size: 14px; color: #374151;">
                    <li><code>redirect('/</code> - PHP redirects</li>
                    <li><code>header('Location: /</code> - Header redirects</li>
                    <li><code>window.location.href = '/</code> - JS redirects</li>
                    <li><code>fetch('/</code> - AJAX calls</li>
                </ul>
            </div>
            
            <div>
                <h4 style="color: #f59e0b;">⚠️ High Risk Patterns (Risk 5-7)</h4>
                <ul style="font-size: 14px; color: #374151;">
                    <li><code>href="/</code> - Navigation links</li>
                    <li><code>action="/</code> - Form actions</li>
                    <li><code>require_*_redirect('/</code> - Auth redirects</li>
                    <li><code>product.example</code> - Dev domains</li>
                </ul>
            </div>
            
            <div>
                <h4 style="color: #059669;">📋 Medium Risk Patterns (Risk 3-4)</h4>
                <ul style="font-size: 14px; color: #374151;">
                    <li><code>src="/</code> - Asset references</li>
                    <li><code>window.open('/</code> - Popup windows</li>
                    <li><code>path_with_base('/</code> - Path functions</li>
                    <li><code>'/</code> - Generic quoted paths</li>
                </ul>
            </div>
        </div>
    </div>
    
    <!-- Results Display -->
    <?php if ($scanResults): ?>
    <div class="scanner-section">
        <h2 style="color: #1f2937; margin-bottom: 20px;">📊 Scan Results</h2>
        
        <?php if ($scanResults['verification_type'] === 'clean_check'): ?>
            <!-- Clean verification results -->
            <div style="text-align: center; padding: 40px;">
                <?php if ($scanResults['is_clean']): ?>
                    <div class="clean-badge" style="font-size: 24px; padding: 20px 40px;">
                        ✅ COMPLETELY CLEAN!
                    </div>
                    <p style="color: #059669; font-size: 18px; margin-top: 16px;">
                        No critical / patterns detected in active codebase
                    </p>
                <?php else: ?>
                    <div class="issue-badge" style="font-size: 24px; padding: 20px 40px;">
                        🚨 ISSUES DETECTED
                    </div>
                    <div style="margin-top: 20px;">
                        <?php foreach ($scanResults['findings'] as $type => $count): ?>
                            <div style="background: #fef2f2; border: 1px solid #fecaca; padding: 12px; margin: 8px 0; border-radius: 6px;">
                                <strong><?php echo $type; ?>:</strong> <?php echo $count; ?> instances found
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
        <?php else: ?>
            <!-- Full scan results -->
            <div class="scan-stats">
                <div class="stat-box">
                    <div class="stat-number" style="color: #3b82f6;"><?php echo $scanResults['files_scanned']; ?></div>
                    <div>Files Scanned</div>
                </div>
                
                <div class="stat-box">
                    <div class="stat-number" style="color: <?php echo $scanResults['files_with_issues'] > 0 ? '#dc2626' : '#059669'; ?>">
                        <?php echo $scanResults['files_with_issues']; ?>
                    </div>
                    <div>Files with Issues</div>
                </div>
                
                <div class="stat-box">
                    <div class="stat-number" style="color: <?php echo $scanResults['total_issues'] > 0 ? '#dc2626' : '#059669'; ?>">
                        <?php echo $scanResults['total_issues']; ?>
                    </div>
                    <div>Total Issues</div>
                </div>
                
                <div class="stat-box">
                    <?php 
                    $successRate = $scanResults['files_scanned'] > 0 ? 
                        round((($scanResults['files_scanned'] - $scanResults['files_with_issues']) / $scanResults['files_scanned']) * 100) : 100;
                    ?>
                    <div class="stat-number" style="color: <?php echo $successRate >= 95 ? '#059669' : ($successRate >= 80 ? '#f59e0b' : '#dc2626'); ?>">
                        <?php echo $successRate; ?>%
                    </div>
                    <div>Clean Rate</div>
                </div>
            </div>
            
            <?php if ($scanResults['files_with_issues'] > 0): ?>
            <h3 style="color: #dc2626;">Files Requiring Attention</h3>
            
            <?php foreach ($scanResults['results'] as $fileResult): ?>
            <div style="border: 2px solid #fecaca; background: #fef2f2; border-radius: 8px; padding: 16px; margin: 12px 0;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <h4 style="margin: 0; color: #991b1b;">
                        📁 <?php echo htmlspecialchars($fileResult['relative_path']); ?>
                    </h4>
                    <div>
                        <span class="risk-indicator <?php 
                            echo $fileResult['max_risk'] >= 8 ? 'risk-critical' : (
                                $fileResult['max_risk'] >= 5 ? 'risk-high' : (
                                    $fileResult['max_risk'] >= 3 ? 'risk-medium' : 'risk-low'
                                )
                            );
                        ?>">
                            Risk: <?php echo $fileResult['max_risk']; ?>/10
                        </span>
                        <span style="background: #e5e7eb; color: #374151; padding: 4px 8px; border-radius: 4px; font-size: 12px;">
                            <?php echo $fileResult['total_issues']; ?> issues
                        </span>
                    </div>
                </div>
                
                <?php foreach (array_slice($fileResult['issues'], 0, 5) as $issue): ?>
                <div class="issue-details">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: #dc2626; font-weight: 600;">
                            Line <?php echo $issue['line']; ?>: <?php echo $issue['pattern']; ?>
                        </span>
                        <span class="risk-indicator <?php 
                            echo $issue['risk_level'] >= 8 ? 'risk-critical' : (
                                $issue['risk_level'] >= 5 ? 'risk-high' : (
                                    $issue['risk_level'] >= 3 ? 'risk-medium' : 'risk-low'
                                )
                            );
                        ?>">
                            <?php echo $issue['risk_level']; ?>/10
                        </span>
                    </div>
                    <div style="margin-top: 8px; color: #374151;">
                        <?php echo htmlspecialchars($issue['content']); ?>
                    </div>
                    <div style="margin-top: 4px; color: #dc2626; font-weight: 600;">
                        Match: <?php echo htmlspecialchars($issue['match']); ?>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <?php if ($fileResult['total_issues'] > 5): ?>
                <p style="color: #6b7280; font-style: italic; margin-top: 12px;">
                    ... and <?php echo $fileResult['total_issues'] - 5; ?> more issues in this file
                </p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            
            <?php else: ?>
            <div style="text-align: center; padding: 40px; background: #f0fdf4; border: 2px solid #22c55e; border-radius: 12px;">
                <div style="font-size: 48px; margin-bottom: 16px;">🎉</div>
                <h3 style="color: #059669; margin-bottom: 8px;">SCAN COMPLETE - NO ISSUES FOUND!</h3>
                <p style="color: #166534;">All <?php echo $scanResults['files_scanned']; ?> scanned files are clean of / references</p>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="scanner-section" style="background: #fef2f2; border: 2px solid #f87171;">
        <h3 style="color: #991b1b;">Error</h3>
        <p><?php echo htmlspecialchars($error); ?></p>
    </div>
    <?php endif; ?>
    
    <!-- Quick Actions -->
    <div class="scanner-section">
        <h2 style="color: #1f2937; margin-bottom: 20px;">⚡ Quick Actions</h2>
        <div style="display: flex; gap: 12px; flex-wrap: wrap;">
            <a href="<?php echo path_with_base('/admin/debug-panel.php'); ?>" class="btn">
                🔧 Debug Panel
            </a>
            <a href="<?php echo path_with_base('/admin/url-migration-tool.php'); ?>" class="btn">
                🚀 Migration Tool  
            </a>
            <a href="<?php echo path_with_base('/admin/url-verification.php'); ?>" class="btn">
                ✅ URL Verification
            </a>
            <a href="<?php echo path_with_base('/admin/search-replace.php'); ?>" class="btn secondary">
                🔍 Search & Replace
            </a>
        </div>
    </div>
</div>

<script>
// Enhanced real-time verification
function runLiveVerification() {
    const criticalPaths = [
        '/login.php',
        '/admin/',
        '/customer/',
        '/shop/',
        '/onboarding/trainee/',
        '/api/health'
    ];
    
    let results = [];
    let completed = 0;
    
    Promise.all(criticalPaths.map(async path => {
        try {
            const response = await fetch(path, { method: 'HEAD' });
            return {
                path: path,
                status: response.status,
                ok: response.ok,
                redirected: response.redirected,
                finalUrl: response.url
            };
        } catch (error) {
            return {
                path: path,
                status: 0,
                ok: false,
                error: error.message
            };
        }
    })).then(results => {
        console.log('Live verification results:', results);
        
        const issues = results.filter(r => !r.ok || r.finalUrl.includes('/'));
        
        if (issues.length === 0) {
            alert('✅ LIVE VERIFICATION PASSED\nAll critical paths are working correctly!');
        } else {
            alert(`🚨 LIVE VERIFICATION ISSUES\n${issues.length} paths have problems:\n\n` +
                  issues.map(i => `${i.path} - Status: ${i.status}`).join('\n'));
        }
    });
}

// Auto-run live verification on page load
document.addEventListener('DOMContentLoaded', function() {
    // Small delay to let page settle
    setTimeout(runLiveVerification, 2000);
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
