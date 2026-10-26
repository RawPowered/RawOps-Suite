<?php
/**
 * AUTOMATED URL MONITORING SYSTEM
 * 
 * Continuous monitoring script that can be run via cron job
 * to detect any new / references that might be introduced
 */

require_once __DIR__ . '/../required/config.php';

class AutomatedURLMonitor {
    private $alertThreshold = 1; // Alert if any issues found
    private $scanPaths = [];
    private $issues = [];
    private $lastScan = null;
    
    public function __construct() {
        $rootDir = realpath(__DIR__ . '/..');
        $this->scanPaths = [
            $rootDir . '/admin',
            $rootDir . '/api',
            $rootDir . '/assets',
            $rootDir . '/customer', 
            $rootDir . '/onboarding',
            $rootDir . '/operations',
            $rootDir . '/required',
            $rootDir . '/shop',
            $rootDir . '/tools',
            $rootDir . '/login.php',
            $rootDir . '/logout.php',
            $rootDir . '/index.php',
            $rootDir . '/index-sales.php'
        ];
    }
    
    public function runScan() {
        $this->issues = [];
        $totalFiles = 0;
        $totalIssues = 0;
        
        $criticalPatterns = [
            'redirect_calls' => [
                'pattern' => "/redirect\s*\(\s*['\"][^'\"]*\/product\/[^'\"]*['\"]/i",
                'risk' => 10,
                'description' => 'PHP redirect function calls'
            ],
            'window_location' => [
                'pattern' => "/window\.location(?:\.href)?\s*=\s*['\"][^'\"]*\/product\/[^'\"]*['\"]/i",
                'risk' => 9,
                'description' => 'JavaScript location redirects'
            ],
            'fetch_calls' => [
                'pattern' => "/fetch\s*\(\s*['\"][^'\"]*\/product\/[^'\"]*['\"]/i",
                'risk' => 8,
                'description' => 'AJAX fetch calls'
            ],
            'form_actions' => [
                'pattern' => "/action\s*=\s*['\"][^'\"]*\/product\/[^'\"]*['\"]/i",
                'risk' => 7,
                'description' => 'Form action attributes'
            ],
            'href_links' => [
                'pattern' => "/href\s*=\s*['\"][^'\"]*\/product\/[^'\"]*['\"]/i",
                'risk' => 6,
                'description' => 'Navigation href links'
            ],
            'header_redirects' => [
                'pattern' => "/header\s*\(\s*['\"]Location:[^'\"]*\/product\/[^'\"]*['\"]/i",
                'risk' => 10,
                'description' => 'PHP header location redirects'
            ]
        ];
        
        foreach ($this->scanPaths as $path) {
            if (is_file($path)) {
                $totalFiles++;
                $fileIssues = $this->scanFile($path, $criticalPatterns);
                if (!empty($fileIssues)) {
                    $this->issues[] = [
                        'file' => $path,
                        'relative_path' => str_replace(realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR, '', $path),
                        'issues' => $fileIssues,
                        'count' => count($fileIssues)
                    ];
                    $totalIssues += count($fileIssues);
                }
            } elseif (is_dir($path)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
                );
                
                foreach ($iterator as $file) {
                    if ($file->isFile() && $this->shouldScanFile($file->getPathname())) {
                        $totalFiles++;
                        $fileIssues = $this->scanFile($file->getPathname(), $criticalPatterns);
                        if (!empty($fileIssues)) {
                            $this->issues[] = [
                                'file' => $file->getPathname(),
                                'relative_path' => str_replace(realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR, '', $file->getPathname()),
                                'issues' => $fileIssues,
                                'count' => count($fileIssues)
                            ];
                            $totalIssues += count($fileIssues);
                        }
                    }
                }
            }
        }
        
        $this->lastScan = [
            'timestamp' => date('Y-m-d H:i:s'),
            'files_scanned' => $totalFiles,
            'files_with_issues' => count($this->issues),
            'total_issues' => $totalIssues,
            'is_clean' => $totalIssues === 0,
            'risk_level' => $this->calculateOverallRisk()
        ];
        
        return $this->lastScan;
    }
    
    private function scanFile($filePath, $patterns) {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return [];
        }
        
        $issues = [];
        $lines = explode("\n", $content);
        
        foreach ($lines as $lineNum => $line) {
            foreach ($patterns as $patternName => $patternData) {
                if (preg_match_all($patternData['pattern'], $line, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as $match) {
                        $issues[] = [
                            'pattern' => $patternName,
                            'line' => $lineNum + 1,
                            'content' => trim($line),
                            'match' => $match[0],
                            'risk' => $patternData['risk'],
                            'description' => $patternData['description']
                        ];
                    }
                }
            }
        }
        
        return $issues;
    }
    
    private function shouldScanFile($filePath) {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['php', 'js', 'css', 'html', 'htm', 'sql'])) {
            return false;
        }
        
        // Skip directories
        $skipPaths = [
            '00-PLANNING', 'migrations', 'vendor', 'node_modules', 
            '.git', 'backups', 'backup', 'logs', 'migration-snapshots',
            'temp', 'tmp', 'sessions'
        ];
        foreach ($skipPaths as $skipPath) {
            if (strpos($filePath, $skipPath) !== false) {
                return false;
            }
        }
        
        // Skip scanner/diagnostic tool files themselves
        $filename = basename($filePath);
        $excludedFiles = [
            'url-scanner', 'url-verification', 'url-migration',
            'automated-url-monitor', 'enhanced-url-scanner',
            'ultimate-url-scanner', 'database-url-cleanup',
            'final-url-cleanup', 'debug-panel', 'search-replace',
            'fix-tracking', 'clear-old-session', 'critical-url-fixes',
            'test-htaccess', 'test-session', 'test-realtime',
            'check-tracking', 'run-tracking-migration'
        ];
        
        foreach ($excludedFiles as $excluded) {
            if (stripos($filename, $excluded) !== false) {
                return false;
            }
        }
        
        // Skip backup files
        if (preg_match('/\.(backup|bak|old|orig|~)$/i', $filename)) {
            return false;
        }
        
        return true;
    }
    
    private function calculateOverallRisk() {
        if (empty($this->issues)) {
            return 0;
        }
        
        $totalRisk = 0;
        $totalIssues = 0;
        
        foreach ($this->issues as $fileData) {
            foreach ($fileData['issues'] as $issue) {
                $totalRisk += $issue['risk'];
                $totalIssues++;
            }
        }
        
        return $totalIssues > 0 ? round($totalRisk / $totalIssues, 1) : 0;
    }
    
    public function getIssues() {
        return $this->issues;
    }
    
    public function getLastScan() {
        return $this->lastScan;
    }
    
    public function sendAlert($issues) {
        // Log to admin_history if available
        try {
            $pdo = db();
            $stmt = $pdo->prepare("
                INSERT INTO admin_history 
                (admin_id, action_type, description, metadata, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            
            $description = "URL Monitor Alert: " . count($issues) . " files with / references detected";
            $metadata = json_encode([
                'files_affected' => count($issues),
                'total_issues' => array_sum(array_column($issues, 'count')),
                'scan_timestamp' => date('Y-m-d H:i:s')
            ]);
            
            $stmt->execute([1, 'url_monitor_alert', $description, $metadata]);
        } catch (Exception $e) {
            // Silent fail - monitoring shouldn't break system
        }
    }
    
    public function generateReport() {
        $report = "# URL MONITORING REPORT\n";
        $report .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
        
        if ($this->lastScan) {
            $report .= "## SCAN SUMMARY\n";
            $report .= "- Files Scanned: {$this->lastScan['files_scanned']}\n";
            $report .= "- Files with Issues: {$this->lastScan['files_with_issues']}\n";
            $report .= "- Total Issues: {$this->lastScan['total_issues']}\n";
            $report .= "- Overall Risk: {$this->lastScan['risk_level']}/10\n";
            $report .= "- Status: " . ($this->lastScan['is_clean'] ? "✅ CLEAN" : "🚨 ISSUES DETECTED") . "\n\n";
        }
        
        if (!empty($this->issues)) {
            $report .= "## ISSUES DETECTED\n\n";
            foreach ($this->issues as $fileData) {
                $report .= "### {$fileData['relative_path']}\n";
                $report .= "Issues: {$fileData['count']}\n\n";
                
                foreach ($fileData['issues'] as $issue) {
                    $report .= "- **Line {$issue['line']}**: {$issue['description']} (Risk: {$issue['risk']}/10)\n";
                    $report .= "  - Pattern: {$issue['pattern']}\n";
                    $report .= "  - Match: `{$issue['match']}`\n";
                    $report .= "  - Content: `{$issue['content']}`\n\n";
                }
            }
        }
        
        return $report;
    }
}

// Command line support
if (php_sapi_name() === 'cli') {
    $monitor = new AutomatedURLMonitor();
    $results = $monitor->runScan();
    
    echo "URL Monitor Scan Results:\n";
    echo "Files Scanned: {$results['files_scanned']}\n";
    echo "Issues Found: {$results['total_issues']}\n";
    echo "Status: " . ($results['is_clean'] ? "CLEAN" : "ISSUES DETECTED") . "\n";
    
    if (!$results['is_clean']) {
        echo "\nReport:\n";
        echo $monitor->generateReport();
        exit(1); // Exit with error code
    }
    
    exit(0); // Success
}

// Web interface
$monitor = new AutomatedURLMonitor();
$scanResults = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_scan'])) {
    $scanResults = $monitor->runScan();
    
    if (!$scanResults['is_clean']) {
        $monitor->sendAlert($monitor->getIssues());
    }
}
?>

<style>
.monitor-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.monitor-header {
    background: linear-gradient(135deg, #059669, #10b981);
    color: white;
    padding: 40px 0;
    margin-bottom: 32px;
    text-align: center;
}

.status-dashboard {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin: 24px 0;
}

.status-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    padding: 24px;
    text-align: center;
}

.status-value {
    font-size: 36px;
    font-weight: bold;
    margin: 12px 0;
}

.monitor-clean { color: #059669; border-left: 4px solid #059669; }
.monitor-warning { color: #f59e0b; border-left: 4px solid #f59e0b; }
.monitor-error { color: #dc2626; border-left: 4px solid #dc2626; }
</style>

<div class="monitor-header">
    <div class="container">
        <h1 style="font-size: 36px; margin-bottom: 8px;">🔍 Automated URL Monitor</h1>
        <p style="font-size: 18px; opacity: 0.9;">Continuous monitoring for / references</p>
    </div>
</div>

<div class="monitor-container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <!-- Control Panel -->
    <div style="background: white; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.1); padding: 24px; margin-bottom: 24px;">
        <h2 style="color: #1f2937; margin-bottom: 20px;">🚀 Monitoring Controls</h2>
        
        <form method="post" style="display: flex; gap: 12px; align-items: center;">
            <input type="hidden" name="run_scan" value="1">
            <button type="submit" class="btn" style="background: #059669; color: white;">
                🔍 Run Comprehensive Scan
            </button>
            <a href="enhanced-url-scanner.php" class="btn secondary">
                ⚡ Enhanced Scanner
            </a>
            <a href="debug-panel.php" class="btn secondary">
                🔧 Debug Panel
            </a>
        </form>
    </div>
    
    <?php if ($scanResults): ?>
    <!-- Results Dashboard -->
    <div class="status-dashboard">
        <div class="status-card <?php echo $scanResults['is_clean'] ? 'monitor-clean' : 'monitor-error'; ?>">
            <h3>Overall Status</h3>
            <div class="status-value">
                <?php echo $scanResults['is_clean'] ? '✅' : '🚨'; ?>
            </div>
            <p><?php echo $scanResults['is_clean'] ? 'CLEAN' : 'ISSUES FOUND'; ?></p>
        </div>
        
        <div class="status-card">
            <h3>Files Scanned</h3>
            <div class="status-value" style="color: #3b82f6;">
                <?php echo $scanResults['files_scanned']; ?>
            </div>
            <p>Total files checked</p>
        </div>
        
        <div class="status-card">
            <h3>Issues Detected</h3>
            <div class="status-value" style="color: <?php echo $scanResults['total_issues'] > 0 ? '#dc2626' : '#059669'; ?>;">
                <?php echo $scanResults['total_issues']; ?>
            </div>
            <p>URL references found</p>
        </div>
        
        <div class="status-card">
            <h3>Risk Level</h3>
            <div class="status-value" style="color: <?php 
                echo $scanResults['risk_level'] >= 8 ? '#dc2626' : (
                    $scanResults['risk_level'] >= 5 ? '#f59e0b' : '#059669'
                ); 
            ?>;">
                <?php echo $scanResults['risk_level']; ?>/10
            </div>
            <p>Average risk score</p>
        </div>
    </div>
    
    <?php if (!$scanResults['is_clean']): ?>
    <!-- Issues Detail -->
    <div style="background: white; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.1); padding: 24px; margin-bottom: 24px;">
        <h2 style="color: #dc2626; margin-bottom: 20px;">🚨 Issues Requiring Attention</h2>
        
        <?php foreach ($monitor->getIssues() as $fileData): ?>
        <div style="background: #fef2f2; border: 2px solid #fecaca; border-radius: 8px; padding: 16px; margin: 12px 0;">
            <h4 style="color: #991b1b; margin-bottom: 8px;">
                📁 <?php echo htmlspecialchars($fileData['relative_path']); ?>
                <span style="background: #dc2626; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px;">
                    <?php echo $fileData['count']; ?> issues
                </span>
            </h4>
            
            <?php foreach ($fileData['issues'] as $issue): ?>
            <div style="background: white; border: 1px solid #e5e7eb; border-radius: 4px; padding: 12px; margin: 8px 0;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <span style="font-weight: 600; color: #374151;">
                        Line <?php echo $issue['line']; ?>: <?php echo $issue['description']; ?>
                    </span>
                    <span style="background: <?php 
                        echo $issue['risk'] >= 8 ? '#dc2626' : (
                            $issue['risk'] >= 5 ? '#f59e0b' : '#059669'
                        ); 
                    ?>; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px;">
                        Risk: <?php echo $issue['risk']; ?>/10
                    </span>
                </div>
                <div style="font-family: 'Courier New', monospace; font-size: 13px; color: #4b5563; background: #f9fafb; padding: 8px; border-radius: 4px;">
                    <?php echo htmlspecialchars($issue['content']); ?>
                </div>
                <div style="font-size: 12px; color: #dc2626; margin-top: 4px;">
                    <strong>Match:</strong> <?php echo htmlspecialchars($issue['match']); ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        
        <!-- Action Buttons -->
        <div style="margin-top: 24px; display: flex; gap: 12px;">
            <a href="enhanced-url-scanner.php" class="btn" style="background: #dc2626; color: white;">
                🛠️ Fix Issues with Enhanced Scanner
            </a>
            <a href="url-migration-tool.php" class="btn secondary">
                🚀 Use Migration Tool
            </a>
            <a href="debug-panel.php" class="btn secondary">
                🔧 Debug Panel
            </a>
        </div>
    </div>
    
    <?php else: ?>
    <!-- Clean Status -->
    <div style="background: #f0fdf4; border: 2px solid #22c55e; border-radius: 12px; padding: 40px; text-align: center; margin-bottom: 24px;">
        <div style="font-size: 64px; margin-bottom: 20px;">🎉</div>
        <h2 style="color: #059669; margin-bottom: 12px;">SYSTEM IS COMPLETELY CLEAN!</h2>
        <p style="color: #166534; font-size: 18px;">
            No / references detected in <?php echo $scanResults['files_scanned']; ?> scanned files
        </p>
        <div style="margin-top: 20px;">
            <span style="background: #dcfce7; color: #166534; padding: 8px 16px; border-radius: 20px; font-weight: 600;">
                ✅ Production Ready
            </span>
        </div>
    </div>
    <?php endif; ?>
    
    <?php endif; ?>
    
    <!-- Cron Job Setup -->
    <div style="background: white; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.1); padding: 24px;">
        <h2 style="color: #1f2937; margin-bottom: 20px;">⏰ Automated Monitoring Setup</h2>
        <p style="color: #6b7280; margin-bottom: 16px;">
            Set up automated monitoring to detect any new / references that might be introduced:
        </p>
        
        <div style="background: #1f2937; color: #f9fafb; padding: 16px; border-radius: 8px; font-family: 'Courier New', monospace; font-size: 13px;">
            # Add this to your crontab to run every hour:<br>
            0 * * * * /usr/bin/php <?php echo __DIR__; ?>/automated-url-monitor.php<br><br>
            
            # Or run manually:<br>
            php <?php echo __DIR__; ?>/automated-url-monitor.php
        </div>
        
        <div style="margin-top: 16px;">
            <strong>Exit Codes:</strong>
            <ul style="margin: 8px 0 0 20px; color: #6b7280;">
                <li><strong>0</strong> - No issues found (clean)</li>
                <li><strong>1</strong> - Issues detected (needs attention)</li>
            </ul>
        </div>
    </div>
</div>

<script>
// Auto-refresh every 5 minutes if issues are detected
<?php if ($scanResults && !$scanResults['is_clean']): ?>
setTimeout(() => {
    if (confirm('Issues were detected. Would you like to refresh and check again?')) {
        location.reload();
    }
}, 300000); // 5 minutes
<?php endif; ?>

// Live URL testing
function testCriticalPaths() {
    const paths = ['/login.php', '/admin/', '/customer/', '/shop/', '/api/health'];
    const results = [];
    
    Promise.all(paths.map(async path => {
        try {
            const response = await fetch(path, { method: 'HEAD' });
            return {
                path,
                status: response.status,
                ok: response.ok,
                finalUrl: response.url,
                hasProduct: response.url.includes('/')
            };
        } catch (error) {
            return { path, error: error.message, ok: false };
        }
    })).then(results => {
        const issues = results.filter(r => !r.ok || r.hasProduct);
        
        if (issues.length === 0) {
            console.log('✅ Live verification passed - all critical paths working');
        } else {
            console.warn('🚨 Live verification issues:', issues);
        }
    });
}

// Run live test after page loads
document.addEventListener('DOMContentLoaded', () => {
    setTimeout(testCriticalPaths, 1000);
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
