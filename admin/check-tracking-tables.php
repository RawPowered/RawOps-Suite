<?php
/**
 * Check Tracking Tables Status
 * Verify if realtime tracking tables exist and have data
 */

require_once __DIR__ . '/../required/config.php';
require_admin_or_redirect('/admin/');

$pdo = db();

// Check what tables exist
$tables = [
    'site_active_sessions',
    'auth_events',
    'site_activity',
    'training_activity',
];

$results = [];

foreach ($tables as $table) {
    $tableInfo = [
        'name' => $table,
        'exists' => false,
        'row_count' => 0,
        'structure' => [],
        'sample_data' => []
    ];
    
    try {
        // Check if table exists
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($stmt->fetch()) {
            $tableInfo['exists'] = true;
            
            // Get row count
            $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            $tableInfo['row_count'] = $count;
            
            // Get structure
            $columns = $pdo->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_ASSOC);
            $tableInfo['structure'] = $columns;
            
            // Get sample data (last 5 rows)
            try {
                // Try with id column first
                $sample = $pdo->query("SELECT * FROM `$table` ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
                $tableInfo['sample_data'] = $sample;
            } catch (Throwable $e) {
                // If no id column, just get any 5 rows
                try {
                    $sample = $pdo->query("SELECT * FROM `$table` LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
                    $tableInfo['sample_data'] = $sample;
                } catch (Throwable $e2) {
                    $tableInfo['sample_data_error'] = $e2->getMessage();
                }
            }
        }
    } catch (Throwable $e) {
        $tableInfo['error'] = $e->getMessage();
    }
    
    $results[$table] = $tableInfo;
}

// Also check for any tracking beacons being received
$trackingStatus = [
    'site_tracker_loaded' => false,
    'heartbeat_working' => false,
    'last_activity' => null
];

if ($results['site_active_sessions']['exists']) {
    try {
        $recent = $pdo->query("SELECT MAX(last_heartbeat) as last_beat FROM site_active_sessions")->fetch();
        $trackingStatus['last_activity'] = $recent['last_beat'];
        
        if ($recent['last_beat']) {
            $lastBeat = strtotime($recent['last_beat']);
            $now = time();
            $trackingStatus['heartbeat_working'] = ($now - $lastBeat) < 300; // Within last 5 minutes
        }
    } catch (Throwable $e) {
        $trackingStatus['error'] = $e->getMessage();
    }
}

header('Content-Type: application/json');
echo json_encode([
    'tables' => $results,
    'tracking_status' => $trackingStatus,
    'recommendations' => [
        'site_active_sessions_missing' => !$results['site_active_sessions']['exists'],
        'no_tracking_data' => $results['site_active_sessions']['exists'] && $results['site_active_sessions']['row_count'] === 0,
        'tracking_stale' => !$trackingStatus['heartbeat_working'],
        'site_tracker_needed' => !$trackingStatus['site_tracker_loaded']
    ]
], JSON_PRETTY_PRINT);
?>
