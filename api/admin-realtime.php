<?php
// Admin realtime data snapshot for dashboard polling

// CRITICAL: Set session name BEFORE requiring config to prevent auto-start with wrong name
if (session_status() === PHP_SESSION_NONE) {
    session_name('PRODUCT_SESSION');
}

require_once __DIR__ . '/../required/config.php';

// Start session FIRST
start_session();

// Set CORS and JSON headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // Debug: Log session info
    $adminId = current_admin_id();
    
    if (!$adminId) {
        // More detailed error for debugging
        error_log("admin-realtime: Authentication failed. Session ID: " . session_id() . ", Has admin_uid: " . (isset($_SESSION['admin_uid']) ? 'yes' : 'no') . ", Has uid: " . (isset($_SESSION['uid']) ? 'yes' : 'no'));
        
        http_response_code(403);
        echo json_encode([
            'error' => 'forbidden',
            'debug' => [
                'session_active' => session_status() === PHP_SESSION_ACTIVE,
                'session_id' => session_id() ? 'present' : 'missing',
                'has_admin' => isset($_SESSION['admin_uid']),
                'has_user' => isset($_SESSION['uid'])
            ]
        ]);
        exit;
    }

    $pdo = db();

    // Initialize all variables with empty defaults
    $onlineUsers = 0;
    $onlineGuests = 0;
    $topActive = [];
    $topPages = [];
    $recentInteractions = [];
    $lastLogins = [];
    $lastLogouts = [];
    $lastForgot = [];
    $crawlerSeries = [];
    $crawlerTop = [];

    // Try to get online users (table might not exist)
    try {
        $onlineUsers = (int)$pdo->query("SELECT COUNT(*) FROM site_active_sessions WHERE is_bot = 0 AND last_heartbeat >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)")->fetchColumn();
        $onlineGuests = (int)$pdo->query("SELECT COUNT(*) FROM site_active_sessions WHERE is_bot = 0 AND is_guest = 1 AND last_heartbeat >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)")->fetchColumn();
        $topActive = $pdo->query("SELECT s.user_id, MAX(s.role) role, MAX(s.is_guest) is_guest, SUM(s.activity_score) score, MAX(u.first_name) first_name, MAX(u.last_name) last_name FROM site_active_sessions s LEFT JOIN users u ON u.id = s.user_id WHERE s.is_bot = 0 AND s.last_heartbeat >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) GROUP BY s.user_id ORDER BY score DESC LIMIT 5")->fetchAll();
    } catch (Throwable $e) {
        error_log("admin-realtime: site_active_sessions error - " . $e->getMessage());
    }

    // Try to get top pages (table might not exist)
    try {
        $topPages = $pdo->query("SELECT path, COUNT(*) pages, SUM(engaged_ms) engaged_ms, SUM(total_ms) total_ms, SUM(interactions_count) interactions FROM site_page_sessions WHERE last_heartbeat >= DATE_SUB(NOW(), INTERVAL 10 MINUTE) GROUP BY path ORDER BY engaged_ms DESC LIMIT 5")->fetchAll();
    } catch (Throwable $e) {
        error_log("admin-realtime: site_page_sessions error - " . $e->getMessage());
    }

    // Try to get recent interactions (table might not exist)
    try {
        $recentInteractions = $pdo->query("SELECT event_type, path, target_tag, target_id, target_class, target_text, target_href, created_at FROM site_interactions ORDER BY created_at DESC LIMIT 5")->fetchAll();
    } catch (Throwable $e) {
        error_log("admin-realtime: site_interactions error - " . $e->getMessage());
    }

    // Get auth events (this table should exist)
    try {
        $lastLogins = $pdo->query("SELECT ae.*, u.first_name, u.last_name FROM auth_events ae LEFT JOIN users u ON u.id = ae.user_id WHERE ae.event_type = 'login' ORDER BY ae.created_at DESC LIMIT 5")->fetchAll();
        $lastLogouts = $pdo->query("SELECT ae.*, u.first_name, u.last_name FROM auth_events ae LEFT JOIN users u ON u.id = ae.user_id WHERE ae.event_type = 'logout' ORDER BY ae.created_at DESC LIMIT 5")->fetchAll();
        $lastForgot = $pdo->query("SELECT ae.*, u.first_name, u.last_name FROM auth_events ae LEFT JOIN users u ON u.id = ae.user_id WHERE ae.event_type = 'forgot_password' ORDER BY ae.created_at DESC LIMIT 5")->fetchAll();
    } catch (Throwable $e) {
        error_log("admin-realtime: auth_events error - " . $e->getMessage());
    }

    // Try to get crawler data (table might not exist)
    try {
        $crawlerSeries = $pdo->query("SELECT DATE_FORMAT(bucket_minute, '%H:%i') t, COUNT(*) v FROM crawler_pulses WHERE bucket_minute >= DATE_SUB(NOW(), INTERVAL 60 MINUTE) GROUP BY t ORDER BY t")->fetchAll();
        $crawlerTop = $pdo->query("SELECT bot_name, COUNT(*) cnt FROM crawler_pulses WHERE bucket_minute >= DATE_SUB(NOW(), INTERVAL 60 MINUTE) GROUP BY bot_name ORDER BY cnt DESC LIMIT 5")->fetchAll();
    } catch (Throwable $e) {
        error_log("admin-realtime: crawler_pulses error - " . $e->getMessage());
    }

    echo json_encode([
        'online' => ['users' => $onlineUsers, 'guests' => $onlineGuests],
        'top_active' => $topActive,
        'last_logins' => $lastLogins,
        'last_logouts' => $lastLogouts,
        'last_forgot' => $lastForgot,
        'crawler' => ['series' => $crawlerSeries, 'top' => $crawlerTop],
        'pages' => [ 'top' => $topPages ],
        'interactions' => [ 'recent' => $recentInteractions ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log("admin-realtime: Fatal error - " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(200);
    echo json_encode([
        'error' => 'logged',
        'message' => 'Check error_app.log for details',
        'online' => ['users' => 0, 'guests' => 0],
        'top_active' => [],
        'last_logins' => [],
        'last_logouts' => [],
        'last_forgot' => [],
        'crawler' => ['series' => [], 'top' => []],
        'pages' => ['top' => []],
        'interactions' => ['recent' => []]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}


