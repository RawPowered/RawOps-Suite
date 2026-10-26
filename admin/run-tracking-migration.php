<?php
/**
 * Run Realtime Tracking Migration
 * Creates site_active_sessions and related tables
 */

require_once __DIR__ . '/../required/config.php';
require_admin_or_redirect('/admin/');

$pdo = db();

// Read the migration file
$migrationFile = __DIR__ . '/../migrations/2025-09-23-realtime-analytics.sql';
$sql = file_get_contents($migrationFile);

if ($sql === false) {
    die('Error: Could not read migration file: ' . $migrationFile);
}

echo "<!DOCTYPE html>";
echo "<html><head><title>Tracking Migration</title>";
echo "<link rel='stylesheet' href='/style.css'></head><body>";
echo "<div class='container' style='max-width:800px; margin:40px auto; padding:20px;'>";
echo "<h1>🚀 Realtime Tracking Migration</h1>";

try {
    // Execute the migration
    $pdo->exec($sql);
    
    echo "<div style='background:#d1fae5; border:1px solid #10b981; padding:20px; border-radius:8px; margin:20px 0;'>";
    echo "<h2 style='color:#065f46; margin-top:0;'>✅ Migration Successful!</h2>";
    echo "<p>The following tables have been created/verified:</p>";
    echo "<ul style='color:#047857;'>";
    echo "<li>✅ site_active_sessions</li>";
    echo "<li>✅ auth_events</li>";
    echo "<li>✅ crawler_pulses</li>";
    echo "</ul>";
    echo "</div>";
    
    // Verify tables exist
    echo "<h2>📊 Table Verification</h2>";
    $tables = ['site_active_sessions', 'auth_events', 'crawler_pulses'];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        $exists = $stmt->fetch() ? '✅ EXISTS' : '❌ MISSING';
        $count = 0;
        if ($stmt->fetch() || true) {
            try {
                $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            } catch (Throwable $e) {}
        }
        echo "<p><strong>$table:</strong> $exists (Rows: $count)</p>";
    }
    
    echo "<div style='background:#fffbeb; border:1px solid #f59e0b; padding:20px; border-radius:8px; margin:20px 0;'>";
    echo "<h3 style='color:#92400e; margin-top:0;'>⚡ Next Steps:</h3>";
    echo "<ol style='color:#78350f; line-height:1.8;'>";
    echo "<li><strong>Refresh any open pages</strong> - The tracking script will now start sending data</li>";
    echo "<li><strong>Wait 1-2 minutes</strong> - For tracking data to populate</li>";
    echo "<li><strong>Check your admin dashboard</strong> - Realtime Activity should now show data</li>";
    echo "<li><strong>Verify tracking is working:</strong> <a href='/admin/check-tracking-tables.php' style='color:#059669;'>Check Tracking Tables</a></li>";
    echo "</ol>";
    echo "</div>";
    
    echo "<p style='margin-top:30px;'>";
    echo "<a href='/admin/' class='btn' style='background:#059669; color:white; padding:12px 24px; text-decoration:none; border-radius:6px; display:inline-block;'>→ Go to Admin Dashboard</a>";
    echo "</p>";
    
} catch (Throwable $e) {
    echo "<div style='background:#fee2e2; border:1px solid #ef4444; padding:20px; border-radius:8px; margin:20px 0;'>";
    echo "<h2 style='color:#991b1b; margin-top:0;'>❌ Migration Failed</h2>";
    echo "<p style='color:#7f1d1d;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre style='background:#fff; padding:12px; border-radius:4px; overflow:auto; color:#000;'>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
    
    echo "<p><a href='/admin/' class='btn secondary'>← Back to Admin</a></p>";
}

echo "</div></body></html>";
?>