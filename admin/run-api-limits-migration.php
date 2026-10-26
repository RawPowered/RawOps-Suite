<?php
/**
 * One-time migration runner for API Limits & Walkthrough System
 * Run this once, then delete this file
 */

require_once __DIR__ . '/../required/config.php';
require_admin_or_redirect('/admin/');

$pdo = db();

echo "<!DOCTYPE html><html><head><title>Migration Runner</title></head><body style='font-family: monospace; padding: 40px; max-width: 1200px; margin: 0 auto;'>";
echo "<h1>🔧 Running Migration: API Limits & Walkthrough System</h1>";
echo "<div style='background: #f0f0f0; padding: 20px; border-radius: 8px; margin: 20px 0;'>";

try {
    $sqlFile = __DIR__ . '/../migrations/2025-10-20-api-limits-walkthrough-system.sql';
    
    if (!file_exists($sqlFile)) {
        throw new Exception("Migration file not found: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    echo "<p>📄 <strong>File:</strong> " . basename($sqlFile) . "</p>";
    echo "<p>📏 <strong>Size:</strong> " . number_format(strlen($sql)) . " bytes</p>";
    echo "<p>⏳ <strong>Status:</strong> Executing...</p>";
    
    // Split by semicolon and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    $executedCount = 0;
    $skippedCount = 0;
    
    foreach ($statements as $statement) {
        // Skip empty statements and comments
        if (empty($statement) || strpos($statement, '--') === 0) {
            $skippedCount++;
            continue;
        }
        
        try {
            $pdo->exec($statement);
            $executedCount++;
        } catch (PDOException $e) {
            // Log but continue (some ALTER TABLE might fail if column exists)
            if (strpos($e->getMessage(), 'Duplicate column') === false && 
                strpos($e->getMessage(), 'already exists') === false) {
                echo "<p style='color: #f59e0b;'>⚠️ Warning: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
        }
    }
    
    echo "<p>✅ <strong>Executed:</strong> $executedCount statements</p>";
    echo "<p>⏭️ <strong>Skipped:</strong> $skippedCount (empty/comments)</p>";
    echo "<p style='color: #10b981; font-weight: bold; margin-top: 20px;'>✅ Migration completed successfully!</p>";
    
    // Clear the performance cache to force refresh
    echo "<hr style='margin: 30px 0;'>";
    echo "<h2>🔄 Clearing Performance Cache</h2>";
    $pdo->exec('TRUNCATE TABLE crm_agent_performance_cache');
    echo "<p style='color: #10b981;'>✅ Cache cleared. Stats will regenerate on next page load.</p>";
    
    echo "<hr style='margin: 30px 0;'>";
    echo "<h2>📊 Verification</h2>";
    
    // Verify tables created
    $stmt = $pdo->query("
        SELECT TABLE_NAME 
        FROM information_schema.tables 
        WHERE table_schema = DATABASE() 
          AND table_name IN ('agent_api_restriction_settings', 'sales_walkthroughs', 'walkthrough_steps', 'user_walkthrough_progress', 'walkthrough_events')
    ");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<p><strong>New Tables Created:</strong></p><ul>";
    foreach ($tables as $table) {
        echo "<li style='color: #10b981;'>✅ $table</li>";
    }
    echo "</ul>";
    
    // Check for detail_completion_rate column
    $stmt = $pdo->query("SHOW COLUMNS FROM crm_agent_performance_cache LIKE 'detail_completion_rate'");
    $column = $stmt->fetch();
    
    if ($column) {
        echo "<p style='color: #10b981;'>✅ <strong>detail_completion_rate</strong> column added to crm_agent_performance_cache</p>";
    } else {
        echo "<p style='color: #ef4444;'>❌ <strong>detail_completion_rate</strong> column NOT found</p>";
    }
    
    // Check stored procedure
    $stmt = $pdo->query("SHOW PROCEDURE STATUS WHERE Db = DATABASE() AND Name = 'sp_update_agent_performance'");
    $proc = $stmt->fetch();
    
    if ($proc) {
        echo "<p style='color: #10b981;'>✅ Stored procedure <strong>sp_update_agent_performance</strong> updated</p>";
    }
    
    echo "</div>";
    
    $dashboardUrl = path_with_base('/onboarding/sales-agent/index.php');
    $cacheRebuildUrl = path_with_base('/admin/force-cache-rebuild.php');
    
    echo "<div style='background: #dbeafe; padding: 20px; border-radius: 8px; border-left: 4px solid #3b82f6; margin-top: 30px;'>";
    echo "<h2 style='margin-top: 0;'>🎉 Next Steps</h2>";
    echo "<ol>";
    echo "<li>Go to <a href='$cacheRebuildUrl'><strong>Force Cache Rebuild</strong></a> to regenerate stats</li>";
    echo "<li>Then go to <a href='$dashboardUrl'>Sales Agent Dashboard</a></li>";
    echo "<li>Do a <strong>hard refresh</strong> (Ctrl+Shift+R)</li>";
    echo "<li>The stats should now show with icons and accurate Detail Rate percentage</li>";
    echo "<li><strong>Delete this file</strong> (admin/run-api-limits-migration.php) after successful migration</li>";
    echo "</ol>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<p style='color: #ef4444; font-weight: bold;'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "</body></html>";
?>

