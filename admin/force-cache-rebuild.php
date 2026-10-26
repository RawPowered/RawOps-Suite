<?php
/**
 * Force rebuild performance cache
 * Run this once to regenerate all agent stats
 */

require_once __DIR__ . '/../required/config.php';
require_admin_or_redirect('/admin/');

$pdo = db();

echo "<!DOCTYPE html><html><head><title>Cache Rebuild</title></head><body style='font-family: monospace; padding: 40px; max-width: 1200px; margin: 0 auto;'>";
echo "<h1>🔄 Forcing Performance Cache Rebuild</h1>";

try {
    // Get your user ID
    $currentUserId = current_user_id();
    
    if (!$currentUserId) {
        throw new Exception("Not authenticated");
    }
    
    echo "<p>👤 <strong>Current User ID:</strong> $currentUserId</p>";
    
    // Check if detail_completion_rate column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM crm_agent_performance_cache LIKE 'detail_completion_rate'");
    $column = $stmt->fetch();
    
    if ($column) {
        echo "<p style='color: #10b981;'>✅ Column <strong>detail_completion_rate</strong> exists</p>";
    } else {
        echo "<p style='color: #ef4444;'>❌ Column <strong>detail_completion_rate</strong> NOT FOUND! Run the quick fix migration first.</p>";
        exit;
    }
    
    // Clear cache
    echo "<p>🗑️ Clearing performance cache...</p>";
    $pdo->exec('TRUNCATE TABLE crm_agent_performance_cache');
    echo "<p style='color: #10b981;'>✅ Cache cleared</p>";
    
    // Rebuild cache for current user
    echo "<p>⚙️ Rebuilding cache for user ID $currentUserId...</p>";
    
    try {
        $stmt = $pdo->prepare('CALL sp_update_agent_performance(?)');
        $stmt->execute([$currentUserId]);
        echo "<p style='color: #10b981;'>✅ Stored procedure executed successfully</p>";
    } catch (PDOException $e) {
        echo "<p style='color: #f59e0b;'>⚠️ Stored procedure failed, using fallback calculation</p>";
        echo "<p style='font-size: 12px; color: #6b7280;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
        
        // Fallback: Calculate inline
        require_once __DIR__ . '/../api/sacp1015-agent-performance.php';
        $stats = sacp1015_calculateAgentPerformance($pdo, $currentUserId);
        
        // Manually insert into cache
        $pdo->prepare('INSERT INTO crm_agent_performance_cache (
            agent_id, leads_today, leads_week, active_leads, total_leads,
            detailed_leads, incomplete_leads, detail_completion_rate, missing_email, missing_phone,
            conversions_today, conversions_week, conversions_month, conversion_rate,
            calls_today, calls_week, overdue_tasks
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            leads_today = VALUES(leads_today),
            leads_week = VALUES(leads_week),
            active_leads = VALUES(active_leads),
            total_leads = VALUES(total_leads),
            detailed_leads = VALUES(detailed_leads),
            incomplete_leads = VALUES(incomplete_leads),
            detail_completion_rate = VALUES(detail_completion_rate),
            missing_email = VALUES(missing_email),
            missing_phone = VALUES(missing_phone),
            conversions_today = VALUES(conversions_today),
            conversions_week = VALUES(conversions_week),
            conversions_month = VALUES(conversions_month),
            conversion_rate = VALUES(conversion_rate),
            calls_today = VALUES(calls_today),
            calls_week = VALUES(calls_week),
            overdue_tasks = VALUES(overdue_tasks),
            last_updated = CURRENT_TIMESTAMP
        ')->execute([
            $currentUserId,
            $stats['leads_today'],
            $stats['leads_week'],
            $stats['active_leads'],
            $stats['total_leads'],
            $stats['detailed_leads'],
            $stats['incomplete_leads'],
            $stats['detail_completion_rate'],
            $stats['missing_email'],
            $stats['missing_phone'],
            $stats['conversions_today'],
            $stats['conversions_week'],
            $stats['conversions_month'],
            $stats['conversion_rate'],
            $stats['calls_today'],
            $stats['calls_week'],
            $stats['overdue_tasks']
        ]);
        
        echo "<p style='color: #10b981;'>✅ Cache populated using fallback method</p>";
    }
    
    // Verify cache has data
    $stmt = $pdo->prepare('SELECT * FROM crm_agent_performance_cache WHERE agent_id = ?');
    $stmt->execute([$currentUserId]);
    $cached = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($cached) {
        echo "<hr style='margin: 30px 0;'>";
        echo "<h2>📊 Your Current Stats</h2>";
        echo "<table style='width: 100%; border-collapse: collapse;'>";
        echo "<tr style='background: #f9fafb;'><th style='padding: 12px; text-align: left; border: 1px solid #e5e7eb;'>Metric</th><th style='padding: 12px; text-align: left; border: 1px solid #e5e7eb;'>Value</th></tr>";
        
        foreach ($cached as $key => $value) {
            if ($key === 'agent_id') continue;
            
            $displayKey = ucwords(str_replace('_', ' ', $key));
            $displayValue = $value;
            
            if ($key === 'detail_completion_rate' || $key === 'conversion_rate') {
                $displayValue = number_format($value, 2) . '%';
                if ($key === 'detail_completion_rate') {
                    $color = $value >= 80 ? '#10b981' : ($value >= 70 ? '#eab308' : '#ef4444');
                    $displayValue = "<strong style='color: $color; font-size: 18px;'>$displayValue</strong>";
                }
            }
            
            echo "<tr><td style='padding: 12px; border: 1px solid #e5e7eb;'>$displayKey</td><td style='padding: 12px; border: 1px solid #e5e7eb;'>$displayValue</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: #ef4444;'>❌ No cache data found for user $currentUserId</p>";
    }
    
    $dashboardUrl = path_with_base('/onboarding/sales-agent/index.php');
    $dashboardUrlWithCache = $dashboardUrl . '?v=' . time();
    
    echo "<hr style='margin: 30px 0;'>";
    echo "<div style='background: #dbeafe; padding: 20px; border-radius: 8px; border-left: 4px solid #3b82f6;'>";
    echo "<h2 style='margin-top: 0;'>✅ Next Steps</h2>";
    echo "<ol>";
    echo "<li>Go to <a href='$dashboardUrlWithCache'><strong>Sales Agent Dashboard (cache-busted)</strong></a></li>";
    echo "<li><strong>Clear your browser cache completely:</strong>";
    echo "<ul style='margin-top: 8px;'>";
    echo "<li>Press <code>Ctrl + Shift + Delete</code></li>";
    echo "<li>Select <strong>Cached images and files</strong></li>";
    echo "<li>Click <strong>Clear data</strong></li>";
    echo "</ul></li>";
    echo "<li>Or use the cache-busted link above</li>";
    echo "<li>The stats should now show icons, colors, and accurate <strong>" . number_format($cached['detail_completion_rate'], 1) . "%</strong> Detail Rate</li>";
    echo "</ol>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<p style='color: #ef4444; font-weight: bold;'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre style='background: #fee2e2; padding: 12px; border-radius: 4px;'>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "</body></html>";
?>

