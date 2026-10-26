<?php
// Site-wide heartbeat endpoint (non-LMS)
// Records active sessions and returns live counts for dashboard widgets

require_once __DIR__ . '/../required/config.php';
start_session();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

try {
    $pdo = db();
    $raw = file_get_contents('php://input');
    $input = json_decode($raw ?: '{}', true);
    if (!is_array($input)) { $input = []; }

    // Basic cleansing per JSON-CLEANSING-RULES.md
    $clean = function($v, $max = 500) {
        if (!is_string($v)) return null;
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
        $v = trim($v);
        if ($v === '') return null;
        return substr($v, 0, $max);
    };

    $sessionId = substr(session_id() ?: ($input['session_id'] ?? ''), 0, 64);
    if ($sessionId === '') {
        // Generate a lightweight session id for guests without PHP session
        $sessionId = bin2hex(random_bytes(16));
        session_id($sessionId);
    }

    $uid = current_user_id();
    $roleList = $uid ? (get_user_roles($uid) ?: []) : [];
    $role = $roleList[0] ?? null;
    $isGuest = $uid ? 0 : 1;

    $currentPath = $clean($input['path'] ?? ($_SERVER['REQUEST_URI'] ?? '/'));
    $referrer = $clean($input['referrer'] ?? ($_SERVER['HTTP_REFERER'] ?? null));
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $isIdle = !empty($input['is_idle']) ? 1 : 0;
    $activityScore = max(0, intval($input['activity_score'] ?? 1));

    // Detect bots (basic pattern)
    $uaLower = strtolower($ua ?: '');
    $isBot = (int) (preg_match('/bot|crawl|spider|slurp|mediapartners-google|bingpreview|semrush|ahrefs|yandex|baiduspider|duckduckbot|facebookexternalhit|embedly/i', $uaLower) ? 1 : 0);

    // Upsert active session
    $stmt = $pdo->prepare('
        INSERT INTO site_active_sessions
            (session_id, user_id, role, is_guest, is_bot, current_path, referrer, first_seen, last_heartbeat, is_idle, activity_score, ip_address, user_agent)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            role = VALUES(role),
            is_guest = VALUES(is_guest),
            is_bot = VALUES(is_bot),
            current_path = VALUES(current_path),
            referrer = VALUES(referrer),
            last_heartbeat = NOW(),
            is_idle = VALUES(is_idle),
            activity_score = LEAST(999999, activity_score + VALUES(activity_score)),
            ip_address = VALUES(ip_address),
            user_agent = VALUES(user_agent)
    ');
    $stmt->execute([
        $sessionId,
        $uid,
        $role,
        $isGuest,
        $isBot,
        $currentPath ?? '/',
        $referrer,
        $isIdle,
        $activityScore,
        $ip,
        $ua
    ]);

    // Cleanup old sessions (older than 10 minutes)
    $pdo->exec("DELETE FROM site_active_sessions WHERE last_heartbeat < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");

    // If this looks like a crawler, record a pulse bucket
    if ($isBot) {
        $botName = 'bot';
        if (preg_match('/(googlebot|bingbot|semrush|ahrefs|yandex|baiduspider|duckduckbot)/i', $ua, $m)) {
            $botName = strtolower($m[1]);
        }
        $bucket = date('Y-m-d H:i:00');
        $pulse = $pdo->prepare('INSERT INTO crawler_pulses (bot_name, user_agent, ip_address, path, referer, bucket_minute) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE bot_name = VALUES(bot_name)');
        $pulse->execute([$botName, $ua, $ip, $currentPath, $referrer, $bucket]);
    }

    // Live metrics snapshot
    $onlineUsers = (int)$pdo->query("SELECT COUNT(*) FROM site_active_sessions WHERE is_bot = 0 AND last_heartbeat >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)")->fetchColumn();
    $onlineGuests = (int)$pdo->query("SELECT COUNT(*) FROM site_active_sessions WHERE is_bot = 0 AND is_guest = 1 AND last_heartbeat >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)")->fetchColumn();
    $topActive = $pdo->query("SELECT COALESCE(user_id, 0) user_id, MAX(role) role, MAX(is_guest) is_guest, SUM(activity_score) score FROM site_active_sessions WHERE is_bot = 0 AND last_heartbeat >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) GROUP BY user_id ORDER BY score DESC LIMIT 5")->fetchAll();
    $recentBots = $pdo->query("SELECT bot_name, COUNT(*) cnt FROM crawler_pulses WHERE bucket_minute >= DATE_SUB(NOW(), INTERVAL 60 MINUTE) GROUP BY bot_name ORDER BY cnt DESC LIMIT 5")->fetchAll();

    echo json_encode([
        'success' => true,
        'session_id' => $sessionId,
        'online' => [
            'users' => $onlineUsers,
            'guests' => $onlineGuests
        ],
        'top_active' => $topActive,
        'crawler_activity' => $recentBots
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['success' => false, 'error' => 'logged']);
}


