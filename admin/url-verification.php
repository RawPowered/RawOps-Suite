<?php
$SEO = [
    'title' => 'URL Verification System — Admin',
    'description' => 'Comprehensive URL verification and testing system',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

require_once __DIR__ . '/../required/domain-controller.php';

$domainController = new DomainController();
$pdo = db();

// Comprehensive URL patterns that should exist
$urlPatterns = [
    // Admin pages
    'admin' => [
        '/admin/' => 'Admin Dashboard',
        '/admin/users.php' => 'User Management',
        '/admin/orders.php' => 'Orders',
        '/admin/training-analytics.php' => 'Training Analytics',
        '/admin/debug-panel.php' => 'Debug Panel',
        '/admin/url-migration-tool.php' => 'URL Migration Tool',
        '/admin/url-verification.php' => 'URL Verification',
        '/admin/commissions.php' => 'Commissions',
        '/admin/lms-manager.php' => 'LMS Manager',
        '/admin/course-manager.php' => 'Course Manager',
        '/admin/trainee-monitor.php' => 'Trainee Monitor',
        '/admin/crm-overview.php' => 'CRM Overview',
        '/admin/role-simulator.php' => 'Role Simulator',
        '/admin/products.php' => 'Products',
        '/admin/reports.php' => 'Reports',
        '/admin/alerts.php' => 'Alerts',
        '/admin/audit-log.php' => 'Audit Log',
        '/admin/site-config.php' => 'Site Configuration'
    ],
    
    // Shop pages
    'shop' => [
        '/shop/' => 'Shop Home',
        '/shop/cart.php' => 'Shopping Cart',
        '/shop/checkout.php' => 'Checkout',
        '/shop/order-confirmation.php' => 'Order Confirmation'
    ],
    
    // Training pages
    'training' => [
        '/training/' => 'Training Home',
        '/training/module/introduction' => 'Introduction Module',
        '/training/module/product-knowledge' => 'Product Knowledge',
        '/training/module/sales-techniques' => 'Sales Techniques',
        '/training/module/compliance' => 'Compliance',
        '/training/module/advanced-strategies' => 'Advanced Strategies'
    ],
    
    // Customer pages
    'customer' => [
        '/customer/' => 'Customer Dashboard',
        '/customer/dashboard.php' => 'Customer Dashboard',
        '/customer/order-details.php' => 'Order Details',
        '/customer/profit-calculator.php' => 'Profit Calculator'
    ],
    
    // API endpoints
    'api' => [
        '/api/health' => 'API Health Check',
        '/api/auth/login' => 'Login API',
        '/api/auth/logout' => 'Logout API',
        '/api/user/profile' => 'User Profile API',
        '/api/training/progress' => 'Training Progress API'
    ],
    
    // Static resources
    'assets' => [
        '/style.css' => 'Main Stylesheet',
        '/script.js' => 'Main Script',
        '/assets/ai-chat.css' => 'AI Chat CSS',
        '/assets/ai-chat.js' => 'AI Chat JS',
        '/assets/site-tracker.js' => 'Site Tracker',
        '/assets/logo.svg' => 'Logo SVG',
        '/assets/sales-acp.css' => 'Sales ACP CSS'
    ],
    
    // Root pages
    'root' => [
        '/' => 'Homepage',
        '/login.php' => 'Login Page',
        '/logout.php' => 'Logout',
        '/index.php' => 'Index',
        '/sitemap.xml' => 'Sitemap'
    ],
    
    // Onboarding & Training
    'onboarding' => [
        '/onboarding/' => 'Onboarding Home',
        '/onboarding/trainee/' => 'Trainee Portal',
        '/onboarding/trainee/index.php' => 'Training Dashboard',
        '/onboarding/trainee/module.php' => 'Training Module',
        '/onboarding/trainee/progress.php' => 'Progress Tracker',
        '/onboarding/trainee/complete.php' => 'Completion Page',
        '/onboarding/sales-agent/' => 'Sales Agent Portal',
        '/onboarding/sales-agent/index.php' => 'CRM Dashboard',
        '/onboarding/sales-agent/leads.php' => 'Lead Management',
        '/onboarding/sales-agent/calls.php' => 'Call Log',
        '/onboarding/sales-agent/tasks.php' => 'Task Management'
    ],
    
    // Tools & Utilities  
    'tools' => [
        '/tools/' => 'Tools Home',
        '/tools/profit-calculator.php' => 'Profit Calculator',
        '/tools/sales-reference.php' => 'Sales Reference Guide'
    ],
    
    // Operations
    'operations' => [
        '/operations/' => 'Operations Dashboard',
        '/operations/inventory.php' => 'Inventory Management',
        '/operations/orders.php' => 'Order Processing', 
        '/operations/shipping.php' => 'Shipping Management',
        '/operations/reports.php' => 'Operations Reports'
    ]
];

// URL testing functions
function testUrl($url, $checkContent = true, ?DomainController $domainController = null) {
    $domainController = $domainController ?: new DomainController();
    // Check if cURL is available
    if (!function_exists('curl_init')) {
        return [
            'url' => $url,
            'status' => 0,
            'effective_url' => $url,
            'redirects' => 0,
            'time' => 0,
            'issues' => ['cURL extension not available'],
            'success' => false
        ];
    }
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false, // Add this for SSL issues
        CURLOPT_HEADER => true,
        CURLOPT_NOBODY => !$checkContent
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $redirectCount = curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
    $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    
    // Check for cURL errors
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return [
            'url' => $url,
            'status' => 0,
            'effective_url' => $url,
            'redirects' => 0,
            'time' => 0,
            'issues' => ['cURL error: ' . $error],
            'success' => false
        ];
    }
    
    curl_close($ch);
    
    $issues = [];
    
    // Check for bad patterns in effective URL
    if ($domainController->containsLegacyDomain($effectiveUrl)) {
        $issues[] = 'Contains legacy domain';
    }

    if ($domainController->countCurrentDoublePathMatches($effectiveUrl) > 0) {
        $issues[] = 'Contains double path on current domain';
    }

    if (strpos($effectiveUrl, $domainController->devDomain()) !== false) {
        $issues[] = 'Contains dev domain';
    }
    
    // ENHANCED content scanning for bad patterns
    if ($checkContent && $response) {
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $body = substr($response, $headerSize);
        
        // Check for legacy domain references
        if ($domainController->containsLegacyDomain($body)) {
            $issues[] = 'Page content contains legacy domains';
        }
        
        // Check for various / patterns in HTML
        $urlPatterns = [
            '/href=["\']([^"\']*\/product\/[^"\']*)["\']/i' => 'href links',
            '/src=["\']([^"\']*\/product\/[^"\']*)["\']/i' => 'src attributes', 
            '/action=["\']([^"\']*\/product\/[^"\']*)["\']/i' => 'form actions',
            '/window\.location[^=]*=[^"\']*["\']([^"\']*\/product\/[^"\']*)["\']/i' => 'JavaScript redirects',
            '/fetch\(["\']([^"\']*\/product\/[^"\']*)["\']/i' => 'AJAX fetch calls',
            '/redirect\(["\']([^"\']*\/product\/[^"\']*)["\']/i' => 'PHP redirects',
            '/header\(["\']Location:[^"\']*\/product\/[^"\']*["\']/i' => 'PHP header redirects'
        ];
        
        foreach ($urlPatterns as $pattern => $type) {
            if (preg_match_all($pattern, $body, $matches)) {
                $issues[] = "Contains " . count($matches[1]) . " $type with /";
            }
        }
        
        // Check for hardcoded domain references
        if ($domainController->countCurrentDoublePathMatches($body) > 0) {
            $issues[] = 'Contains double / paths on current domain';
        }
        
        // Check for API endpoint issues
        if (preg_match_all('/api\/[^"\']*product/i', $body, $matches)) {
            $issues[] = 'Contains ' . count($matches[0]) . ' API endpoints with product references';
        }
    }
    
    return [
        'url' => $url,
        'status' => $httpCode,
        'effective_url' => $effectiveUrl,
        'redirects' => $redirectCount,
        'time' => round($totalTime * 1000, 2),
        'issues' => $issues,
        'success' => $httpCode >= 200 && $httpCode < 400 && empty($issues)
    ];
}

// Run comprehensive test
$testResults = [];
$runTest = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_test'])) {
    $runTest = true;
    $baseUrl = site_origin();
    $categories = isset($_POST['categories']) ? $_POST['categories'] : array_keys($urlPatterns);
    
    foreach ($categories as $category) {
        if (!isset($urlPatterns[$category])) continue;
        
        $testResults[$category] = [];
        foreach ($urlPatterns[$category] as $path => $name) {
            $fullUrl = $baseUrl . $path;
            $testResults[$category][] = array_merge(
                testUrl($fullUrl, in_array($category, ['admin', 'shop', 'training', 'customer', 'root', 'onboarding']), $domainController),
                ['path' => $path, 'name' => $name]
            );
        }
    }
}

// Calculate statistics
$stats = [
    'total' => 0,
    'success' => 0,
    'errors' => 0,
    'warnings' => 0,
    'avg_time' => 0
];

if ($testResults) {
    foreach ($testResults as $category => $results) {
        foreach ($results as $result) {
            $stats['total']++;
            if ($result['success']) {
                $stats['success']++;
            } else if ($result['status'] >= 400) {
                $stats['errors']++;
            } else if (!empty($result['issues'])) {
                $stats['warnings']++;
            }
            $stats['avg_time'] += $result['time'];
        }
    }
    if ($stats['total'] > 0) {
        $stats['avg_time'] = round($stats['avg_time'] / $stats['total'], 2);
    }
}

// Export function
if (isset($_GET['export']) && $testResults) {
    $export = [
        'timestamp' => date('Y-m-d H:i:s'),
        'domain' => site_domain(),
        'base_url' => site_base_url(),
        'statistics' => $stats,
        'results' => $testResults
    ];
    
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="url-verification-' . date('Y-m-d-His') . '.json"');
    echo json_encode($export, JSON_PRETTY_PRINT);
    exit;
}
?>

<style>
.verification-container {
    max-width: 1600px;
    margin: 0 auto;
    padding: 20px;
}

.verification-header {
    background: linear-gradient(135deg, #10b981, #34d399);
    color: white;
    padding: 40px 0;
    margin-bottom: 32px;
}

.test-controls {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    padding: 24px;
    margin-bottom: 24px;
}

.category-selector {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 12px;
    margin: 20px 0;
}

.category-checkbox {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: #f9fafb;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
}

.category-checkbox:hover {
    background: #f3f4f6;
    border-color: #9ca3af;
}

.category-checkbox input:checked + span {
    color: #10b981;
    font-weight: 600;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}

.stat-card {
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    padding: 20px;
    text-align: center;
}

.stat-value {
    font-size: 36px;
    font-weight: bold;
    margin: 8px 0;
}

.stat-label {
    color: #6b7280;
    font-size: 14px;
    text-transform: uppercase;
}

.results-section {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    padding: 24px;
    margin-bottom: 24px;
}

.result-category {
    margin-bottom: 32px;
}

.result-category h3 {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 16px;
    padding-bottom: 8px;
    border-bottom: 2px solid #e5e7eb;
}

.results-table {
    width: 100%;
    border-collapse: collapse;
}

.results-table th {
    background: #f9fafb;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
}

.results-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #f3f4f6;
}

.results-table tr:hover {
    background: #fafafa;
}

.status-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
    text-align: center;
    min-width: 50px;
}

.status-success { background: #d1fae5; color: #065f46; }
.status-redirect { background: #fed7aa; color: #92400e; }
.status-error { background: #fee2e2; color: #991b1b; }
.status-warning { background: #fef3c7; color: #92400e; }

.issue-pill {
    display: inline-block;
    padding: 2px 6px;
    background: #fef2f2;
    color: #dc2626;
    border-radius: 3px;
    font-size: 11px;
    margin: 2px;
}

.time-badge {
    font-family: 'Courier New', monospace;
    font-size: 12px;
    color: #6b7280;
}

.time-fast { color: #10b981; }
.time-medium { color: #f59e0b; }
.time-slow { color: #ef4444; }

.progress-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}

.progress-box {
    background: white;
    border-radius: 12px;
    padding: 32px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.2);
    text-align: center;
}

.spinner {
    width: 50px;
    height: 50px;
    border: 4px solid #e5e7eb;
    border-top-color: #10b981;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin: 0 auto 20px;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.success-rate {
    display: inline-block;
    width: 100px;
    height: 100px;
    border-radius: 50%;
    background: conic-gradient(#10b981 var(--progress), #e5e7eb var(--progress));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    font-weight: bold;
    color: #1f2937;
    margin: 0 auto;
}
</style>

<div class="verification-header">
    <div class="container">
        <h1 style="font-size: 36px; margin-bottom: 8px;">✅ URL Verification System</h1>
        <p style="font-size: 18px; opacity: 0.9;">Comprehensive testing of all site URLs and resources</p>
    </div>
</div>

<div class="verification-container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <?php if ($runTest && $stats['total'] > 0): ?>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total URLs</div>
            <div class="stat-value"><?php echo $stats['total']; ?></div>
        </div>
        
        <div class="stat-card">
            <div class="stat-label">Success</div>
            <div class="stat-value" style="color: #10b981;"><?php echo $stats['success']; ?></div>
        </div>
        
        <div class="stat-card">
            <div class="stat-label">Errors</div>
            <div class="stat-value" style="color: #ef4444;"><?php echo $stats['errors']; ?></div>
        </div>
        
        <div class="stat-card">
            <div class="stat-label">Warnings</div>
            <div class="stat-value" style="color: #f59e0b;"><?php echo $stats['warnings']; ?></div>
        </div>
        
        <div class="stat-card">
            <div class="stat-label">Success Rate</div>
            <div class="success-rate" style="--progress: <?php echo $stats['total'] > 0 ? round(($stats['success'] / $stats['total']) * 100) : 0; ?>%;">
                <?php echo $stats['total'] > 0 ? round(($stats['success'] / $stats['total']) * 100) : 0; ?>%
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-label">Avg Response</div>
            <div class="stat-value"><?php echo $stats['avg_time']; ?>ms</div>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="test-controls">
        <h2 style="margin-bottom: 20px;">🔍 URL Testing Configuration</h2>
        
        <form method="post" id="testForm">
            <input type="hidden" name="run_test" value="1">
            
            <div class="category-selector">
                <?php foreach ($urlPatterns as $category => $urls): ?>
                <label class="category-checkbox">
                    <input type="checkbox" name="categories[]" value="<?php echo $category; ?>" checked>
                    <span><?php echo ucfirst($category); ?> (<?php echo count($urls); ?>)</span>
                </label>
                <?php endforeach; ?>
            </div>
            
            <div style="margin-top: 24px; display: flex; gap: 12px;">
                <button type="submit" class="btn-primary" onclick="showProgress()">
                    🚀 Run Verification Test
                </button>
                
                <?php if ($testResults): ?>
                <a href="?export=1" class="btn secondary">
                    📥 Export Results
                </a>
                <?php endif; ?>
                
                <button type="button" class="btn secondary" onclick="selectAll()">
                    Select All
                </button>
                
                <button type="button" class="btn secondary" onclick="selectNone()">
                    Select None
                </button>
            </div>
        </form>
    </div>
    
    <?php if ($testResults): ?>
    <div class="results-section">
        <h2 style="margin-bottom: 24px;">📊 Test Results</h2>
        
        <?php foreach ($testResults as $category => $results): ?>
        <div class="result-category">
            <h3>
                <?php 
                $categorySuccess = array_filter($results, function($r) { return $r['success']; });
                $successRate = count($results) > 0 ? round((count($categorySuccess) / count($results)) * 100) : 0;
                ?>
                <span><?php echo ucfirst($category); ?></span>
                <span style="font-size: 14px; color: <?php echo $successRate >= 80 ? '#10b981' : ($successRate >= 50 ? '#f59e0b' : '#ef4444'); ?>;">
                    (<?php echo count($categorySuccess); ?>/<?php echo count($results); ?> - <?php echo $successRate; ?>%)
                </span>
            </h3>
            
            <table class="results-table">
                <thead>
                    <tr>
                        <th>URL Path</th>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Time</th>
                        <th>Issues</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $result): ?>
                    <tr>
                        <td>
                            <code style="font-size: 12px;"><?php echo htmlspecialchars($result['path']); ?></code>
                        </td>
                        <td><?php echo htmlspecialchars($result['name']); ?></td>
                        <td>
                            <span class="status-badge <?php 
                                echo $result['status'] == 200 ? 'status-success' : (
                                    $result['status'] >= 300 && $result['status'] < 400 ? 'status-redirect' : (
                                        $result['status'] >= 400 ? 'status-error' : 'status-warning'
                                    )
                                );
                            ?>">
                                <?php echo $result['status']; ?>
                            </span>
                        </td>
                        <td>
                            <span class="time-badge <?php 
                                echo $result['time'] < 500 ? 'time-fast' : (
                                    $result['time'] < 1000 ? 'time-medium' : 'time-slow'
                                );
                            ?>">
                                <?php echo $result['time']; ?>ms
                            </span>
                        </td>
                        <td>
                            <?php if (empty($result['issues'])): ?>
                                <span style="color: #10b981;">✓ Clean</span>
                            <?php else: ?>
                                <?php foreach ($result['issues'] as $issue): ?>
                                    <span class="issue-pill"><?php echo htmlspecialchars($issue); ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo htmlspecialchars($result['url']); ?>" target="_blank" style="color: #3b82f6; font-size: 12px;">
                                View →
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <!-- Summary Actions -->
    <?php if ($testResults && $stats['errors'] + $stats['warnings'] > 0): ?>
    <div class="results-section" style="background: #fef2f2; border: 2px solid #f87171;">
        <h2 style="color: #991b1b; margin-bottom: 20px;">⚠️ Action Required</h2>
        
        <p style="margin-bottom: 16px;">
            Found <strong><?php echo $stats['errors']; ?> errors</strong> and 
            <strong><?php echo $stats['warnings']; ?> warnings</strong> that need attention.
        </p>
        
        <div style="display: flex; gap: 12px;">
            <a href="<?php echo path_with_base('/admin/url-migration-tool.php'); ?>" class="btn-primary">
                Open Migration Tool
            </a>
            
            <a href="<?php echo path_with_base('/admin/debug-panel.php'); ?>" class="btn secondary">
                Open Debug Panel
            </a>
            
            <button onclick="generateFixScript()" class="btn secondary">
                Generate Fix Script
            </button>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="progress-overlay" id="progressOverlay">
    <div class="progress-box">
        <div class="spinner"></div>
        <h3>Running URL Verification</h3>
        <p style="color: #6b7280;">Testing all configured URLs...</p>
        <p style="color: #9ca3af; font-size: 12px; margin-top: 8px;">This may take a few moments</p>
    </div>
</div>

<script>
function showProgress() {
    document.getElementById('progressOverlay').style.display = 'flex';
}

function selectAll() {
    document.querySelectorAll('input[name="categories[]"]').forEach(cb => cb.checked = true);
}

function selectNone() {
    document.querySelectorAll('input[name="categories[]"]').forEach(cb => cb.checked = false);
}

function generateFixScript() {
    const issues = <?php echo json_encode($testResults ?? []); ?>;
    
    let script = '#!/bin/bash\n';
    script += '# URL Fix Script Generated: ' + new Date().toISOString() + '\n\n';
    script += '# Files with issues:\n';
    
    // Generate fix commands
    for (const category in issues) {
        for (const result of issues[category]) {
            if (result.issues && result.issues.length > 0) {
                script += `# ${result.path} - ${result.issues.join(', ')}\n`;
            }
        }
    }
    
    script += '\n# Run the migration tool to fix these issues\n';
    script += 'php admin/url-migration-tool.php --auto-fix\n';
    
    // Download script
    const blob = new Blob([script], {type: 'text/plain'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'url-fix-script-' + Date.now() + '.sh';
    a.click();
    URL.revokeObjectURL(url);
}

// Auto-refresh status
<?php if ($runTest): ?>
setTimeout(() => {
    document.getElementById('progressOverlay').style.display = 'none';
}, 500);
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
