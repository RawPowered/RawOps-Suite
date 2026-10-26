<?php
/**
 * Complete Tracking System Fix & Diagnostic
 * Repairs the realtime activity monitoring
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../required/config.php';
require_admin_or_redirect(path_with_base('/admin/'));

$issues = [];
$fixes = [];
$warnings = [];

try {
    $pdo = db();
    
    // STEP 1: Check helper functions
    $functionsOk = true;
    $requiredFunctions = ['site_domain', 'site_base_path', 'site_base_url', 'outbound_path', 'is_dev_base_forced'];
    foreach ($requiredFunctions as $func) {
        if (!function_exists($func)) {
            $issues[] = "❌ Function `$func()` is MISSING";
            $functionsOk = false;
        } else {
            // Test the function
            try {
                $result = call_user_func($func, $func === 'outbound_path' ? '/test' : null);
                
                // Check for bad values
                if (is_string($result) && (strpos($result, 'legacy.example') !== false || strpos($result, '/product/') !== false)) {
                    $warnings[] = "⚠️ Function `$func()` returns dev/product URL: " . htmlspecialchars($result);
                }
            } catch (Throwable $e) {
                $issues[] = "❌ Function `$func()` throws error: " . $e->getMessage();
                $functionsOk = false;
            }
        }
    }
    
    if ($functionsOk) {
        $fixes[] = "✅ All helper functions are defined and working";
    }
    
    // STEP 2: Check database tables
    $requiredTables = [
        'site_active_sessions' => 'Tracks live users',
        'site_page_sessions' => 'Page engagement metrics',
        'site_interactions' => 'Click/interaction events',
        'auth_events' => 'Login/logout events',
        'crawler_pulses' => 'Bot activity'
    ];
    
    $missingTables = [];
    foreach ($requiredTables as $table => $desc) {
        try {
            $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            $fixes[] = "✅ Table `$table` exists (rows: $count)";
        } catch (Throwable $e) {
            $issues[] = "❌ Table `$table` missing";
            $missingTables[] = $table;
        }
    }
    
    // STEP 3: Check site_config.json
    $configPath = __DIR__ . '/../required/site_config.json';
    if (file_exists($configPath)) {
        $config = json_decode(file_get_contents($configPath), true);
        if (isset($config['force_dev_base'])) {
            if ($config['force_dev_base'] == 0) {
                $fixes[] = "✅ site_config.json has force_dev_base = 0 (production mode)";
            } else {
                $issues[] = "❌ site_config.json has force_dev_base = {$config['force_dev_base']} (should be 0)";
            }
        } else {
            $warnings[] = "⚠️ site_config.json missing force_dev_base key";
        }
    } else {
        $issues[] = "❌ site_config.json not found";
    }
    
    // STEP 4: Check if site-tracker.js exists
    $trackerPath = __DIR__ . '/../assets/site-tracker.js';
    if (file_exists($trackerPath)) {
        $fixes[] = "✅ site-tracker.js exists (" . number_format(filesize($trackerPath)) . " bytes)";
    } else {
        $issues[] = "❌ site-tracker.js not found at: $trackerPath";
    }
    
    // STEP 5: Test API endpoints
    $apiTests = [
        '/api/site-activity.php' => 'Site activity tracker',
        '/api/site-heartbeat.php' => 'Site heartbeat',
        '/api/admin-realtime.php' => 'Admin realtime data'
    ];
    
    foreach ($apiTests as $endpoint => $desc) {
        $fullPath = __DIR__ . '/..' . $endpoint;
        if (file_exists($fullPath)) {
            $fixes[] = "✅ API endpoint exists: $endpoint";
        } else {
            $issues[] = "❌ API endpoint missing: $endpoint";
        }
    }
    
    // STEP 6: Check recent activity in tables
    if (empty($missingTables)) {
        try {
            $onlineNow = $pdo->query("SELECT COUNT(*) FROM site_active_sessions WHERE last_heartbeat >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetchColumn();
            $pagesRecent = $pdo->query("SELECT COUNT(*) FROM site_page_sessions WHERE last_heartbeat >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)")->fetchColumn();
            $interactionsRecent = $pdo->query("SELECT COUNT(*) FROM site_interactions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)")->fetchColumn();
            
            if ($onlineNow > 0) {
                $fixes[] = "✅ Found $onlineNow active sessions in last 5 minutes";
            } else {
                $warnings[] = "⚠️ No active sessions in last 5 minutes (tracker may not be running)";
            }
            
            if ($pagesRecent > 0 || $interactionsRecent > 0) {
                $fixes[] = "✅ Recent activity detected ($pagesRecent page views, $interactionsRecent interactions)";
            } else {
                $warnings[] = "⚠️ No recent page views or interactions (JavaScript tracker may not be loaded)";
            }
        } catch (Throwable $e) {
            $warnings[] = "⚠️ Could not check recent activity: " . $e->getMessage();
        }
    }
    
} catch (Throwable $e) {
    $issues[] = "❌ Fatal error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Fix Tracking System — Product Admin</title>
    <link rel="stylesheet" href="<?php echo outbound_path('/style.css'); ?>">
    <style>
        .diagnostic-section { background: white; padding: 24px; border-radius: 12px; margin: 20px 0; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .issue-list { list-style: none; padding: 0; margin: 16px 0; }
        .issue-list li { padding: 12px; margin: 8px 0; border-radius: 6px; }
        .success-item { background: #f0fdf4; color: #065f46; border-left: 4px solid #10b981; }
        .error-item { background: #fef2f2; color: #991b1b; border-left: 4px solid #dc2626; }
        .warning-item { background: #fffbeb; color: #92400e; border-left: 4px solid #f59e0b; }
        .action-btn { background: #059669; color: white; padding: 12px 24px; border-radius: 8px; text-decoration: none; display: inline-block; margin: 8px 8px 8px 0; }
        .action-btn:hover { background: #047857; }
        .secondary-btn { background: #6b7280; }
        .secondary-btn:hover { background: #4b5563; }
    </style>
</head>
<body>
<main class="section">
    <div class="container" style="max-width: 1100px;">
        <h1>🔧 Tracking System Diagnostic & Repair</h1>
        <p style="color: #6b7280; font-size: 18px;">Comprehensive check of the realtime activity monitoring system</p>

        <!-- Summary -->
        <div class="diagnostic-section">
            <h2>📊 Summary</h2>
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin: 20px 0;">
                <div style="text-align: center; padding: 20px; background: <?php echo empty($issues) ? '#f0fdf4' : '#fef2f2'; ?>; border-radius: 8px;">
                    <div style="font-size: 48px;"><?php echo empty($issues) ? '✅' : '❌'; ?></div>
                    <div style="font-weight: 600; margin-top: 8px;"><?php echo count($issues); ?> Critical Issues</div>
                </div>
                <div style="text-align: center; padding: 20px; background: #fffbeb; border-radius: 8px;">
                    <div style="font-size: 48px;">⚠️</div>
                    <div style="font-weight: 600; margin-top: 8px;"><?php echo count($warnings); ?> Warnings</div>
                </div>
                <div style="text-align: center; padding: 20px; background: #f0fdf4; border-radius: 8px;">
                    <div style="font-size: 48px;">✅</div>
                    <div style="font-weight: 600; margin-top: 8px;"><?php echo count($fixes); ?> Items OK</div>
                </div>
            </div>
        </div>

        <?php if (!empty($issues)): ?>
        <!-- Critical Issues -->
        <div class="diagnostic-section">
            <h2 style="color: #dc2626;">🚨 Critical Issues</h2>
            <ul class="issue-list">
                <?php foreach ($issues as $issue): ?>
                    <li class="error-item"><?php echo $issue; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if (!empty($warnings)): ?>
        <!-- Warnings -->
        <div class="diagnostic-section">
            <h2 style="color: #f59e0b;">⚠️ Warnings</h2>
            <ul class="issue-list">
                <?php foreach ($warnings as $warning): ?>
                    <li class="warning-item"><?php echo $warning; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Working Items -->
        <div class="diagnostic-section">
            <h2 style="color: #059669;">✅ Working Correctly</h2>
            <ul class="issue-list">
                <?php foreach ($fixes as $fix): ?>
                    <li class="success-item"><?php echo $fix; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Actions -->
        <div class="diagnostic-section">
            <h2>🛠️ Actions Required</h2>
            
            <?php if (!empty($issues) || !empty($warnings)): ?>
                <h3>Automated Fixes Available:</h3>
                
                <?php if (!empty($missingTables)): ?>
                    <div style="background: #fffbeb; padding: 16px; border-radius: 8px; margin: 16px 0;">
                        <strong>Missing Database Tables</strong>
                        <p>Run the tracking tables migration:</p>
                        <form method="post" action="<?php echo path_with_base('/admin/run-tracking-migration.php'); ?>" style="margin-top: 12px;">
                            <button type="submit" class="action-btn">🚀 Run Migration Now</button>
                        </form>
                        <p style="margin-top: 12px; color: #6b7280; font-size: 14px;">
                            Or manually run: <code>migrations/2025-09-23-realtime-analytics.sql</code>
                        </p>
                    </div>
                <?php endif; ?>
                
                <?php if (!$functionsOk): ?>
                    <div style="background: #fef2f2; padding: 16px; border-radius: 8px; margin: 16px 0;">
                        <strong>❌ Helper Functions Broken</strong>
                        <p>Upload the fixed <code>required/config.php</code> file to your server.</p>
                        <p style="margin-top: 8px; font-size: 14px; color: #6b7280;">
                            The functions have been wrapped in <code>if (!function_exists())</code> to prevent conflicts.
                        </p>
                    </div>
                <?php endif; ?>
                
                <div style="background: #f0f9ff; padding: 16px; border-radius: 8px; margin: 16px 0;">
                    <strong>Clear Cache</strong>
                    <p>After uploading fixed files, clear all caches:</p>
                    <ul>
                        <li>LiteSpeed Cache: Purge All</li>
                        <li>PHP OPcache: Restart PHP-FPM</li>
                        <li>Browser Cache: Hard refresh (Ctrl+F5)</li>
                    </ul>
                </div>
            <?php else: ?>
                <div style="background: #f0fdf4; padding: 24px; border-radius: 12px; text-align: center;">
                    <div style="font-size: 64px; margin-bottom: 16px;">🎉</div>
                    <h3 style="color: #065f46; margin: 0 0 12px 0;">Tracking System is Healthy!</h3>
                    <p style="color: #166534;">All components are operational. If the dashboard still shows 0 users, wait a few minutes for activity to accumulate.</p>
                </div>
            <?php endif; ?>
            
            <h3 style="margin-top: 32px;">Manual Verification:</h3>
            <a href="<?php echo path_with_base('/test-tracking-system.php'); ?>" class="action-btn">
                🔍 Run Full Tracking Test
            </a>
            <a href="<?php echo path_with_base('/admin/'); ?>" class="action-btn secondary-btn">
                📊 Go to Admin Dashboard
            </a>
            <a href="<?php echo path_with_base('/test-config.php'); ?>" class="action-btn secondary-btn">
                ⚙️ Test Config Functions
            </a>
        </div>

        <!-- Live Test -->
        <div class="diagnostic-section">
            <h2>🧪 Live JavaScript Test</h2>
            <p>Testing if site-tracker.js is loading and functioning:</p>
            <div id="tracker-test" style="background: #f9fafb; padding: 16px; border-radius: 8px; margin: 16px 0;">
                ⏳ Checking...
            </div>
            <div id="api-test" style="background: #f9fafb; padding: 16px; border-radius: 8px; margin: 16px 0;">
                ⏳ Testing API endpoints...
            </div>
        </div>
    </div>
</main>

<script src="<?php echo outbound_path('/assets/site-tracker.js'); ?>?v=<?php echo time(); ?>" defer></script>

<script>
// Wait for tracker to load
setTimeout(function() {
    const trackerDiv = document.getElementById('tracker-test');
    
    // Check if BASE_PREFIX is set
    const hasBasePrefix = typeof window.__BASE_PREFIX !== 'undefined';
    
    let html = '<table style="width: 100%; margin: 0;">';
    html += '<tr><th style="text-align: left;">Check</th><th style="text-align: left;">Result</th></tr>';
    html += '<tr><td>window.__BASE_PREFIX defined</td><td>' + (hasBasePrefix ? '✅ Yes: <code>' + window.__BASE_PREFIX + '</code>' : '❌ No') + '</td></tr>';
    
    // Check if script tag is in DOM
    const scripts = Array.from(document.querySelectorAll('script'));
    const trackerScript = scripts.find(s => s.src && s.src.includes('site-tracker.js'));
    html += '<tr><td>site-tracker.js script tag</td><td>' + (trackerScript ? '✅ Found: <code>' + trackerScript.src + '</code>' : '❌ Not found') + '</td></tr>';
    
    // Check if loaded
    const scriptLoaded = trackerScript && (trackerScript.readyState === 'loaded' || trackerScript.readyState === 'complete' || trackerScript.readyState === undefined);
    html += '<tr><td>Script load status</td><td>' + (scriptLoaded ? '✅ Loaded' : '⚠️ Loading or blocked') + '</td></tr>';
    
    html += '</table>';
    trackerDiv.innerHTML = html;
}, 2000);

// Test API endpoints
setTimeout(async function() {
    const apiDiv = document.getElementById('api-test');
    
    const tests = [
        { url: '/api/site-activity.php', method: 'POST', body: { type: 'page_start', path: window.location.pathname, client_page_id: 'test_' + Date.now() } },
        { url: '/api/site-heartbeat.php', method: 'POST', body: { path: window.location.pathname } },
        { url: '/api/admin-realtime.php', method: 'GET' }
    ];
    
    let html = '<table style="width: 100%; margin: 0;"><tr><th>Endpoint</th><th>Status</th><th>Response</th></tr>';
    
    for (const test of tests) {
        try {
            const opts = {
                method: test.method,
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin'
            };
            if (test.body) opts.body = JSON.stringify(test.body);
            
            const r = await fetch(test.url, opts);
            const data = await r.json();
            const ok = r.ok && (data.success !== false);
            
            html += '<tr>';
            html += '<td><code>' + test.url + '</code></td>';
            html += '<td>' + (ok ? '✅ OK' : '❌ Error') + '</td>';
            html += '<td style="font-size: 12px;"><code>' + JSON.stringify(data).slice(0, 100) + '</code></td>';
            html += '</tr>';
        } catch (err) {
            html += '<tr>';
            html += '<td><code>' + test.url + '</code></td>';
            html += '<td>❌ Failed</td>';
            html += '<td>' + err.message + '</td>';
            html += '</tr>';
        }
    }
    
    html += '</table>';
    apiDiv.innerHTML = html;
}, 1000);
</script>

</body>
</html>
