<?php
// Generic site activity endpoint for page sessions and interactions
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

    $type = $input['type'] ?? '';
    if (!in_array($type, ['page_start','page_heartbeat','page_end','interaction'], true)) {
        throw new Exception('Invalid type');
    }

    $uid = current_user_id();
    $sessionId = substr(session_id() ?: ($input['session_id'] ?? ''), 0, 64);
    if ($sessionId === '') {
        $sessionId = bin2hex(random_bytes(16));
        session_id($sessionId);
    }

    $clientPageId = substr((string)($input['client_page_id'] ?? ''), 0, 64);
    $path = substr((string)($input['path'] ?? ($_SERVER['REQUEST_URI'] ?? '/')), 0, 500);
    $pageTitle = substr((string)($input['page_title'] ?? ''), 0, 255) ?: null;
    $referrer = substr((string)($input['referrer'] ?? ($_SERVER['HTTP_REFERER'] ?? '')), 0, 500) ?: null;

    if ($type === 'page_start') {
        if ($clientPageId === '') { throw new Exception('client_page_id required'); }
        $stmt = $pdo->prepare('INSERT INTO site_page_sessions (client_page_id, session_id, user_id, path, page_title, referrer, started_at, last_heartbeat, total_ms, engaged_ms, idle_ms, max_scroll, interactions_count, ended) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), 0, 0, 0, 0, 0, 0) ON DUPLICATE KEY UPDATE path = VALUES(path), page_title = VALUES(page_title), referrer = VALUES(referrer), last_heartbeat = NOW()');
        $stmt->execute([$clientPageId, $sessionId, $uid, $path, $pageTitle, $referrer]);
        echo json_encode(['success' => true, 'session_id' => $sessionId]);
        exit;
    }

    if ($type === 'page_heartbeat' || $type === 'page_end') {
        if ($clientPageId === '') { throw new Exception('client_page_id required'); }
        $totalMs = max(0, intval($input['total_ms'] ?? 0));
        $engagedMs = max(0, intval($input['engaged_ms'] ?? 0));
        $idleMs = max(0, intval($input['idle_ms'] ?? 0));
        $maxScroll = min(100, max(0, intval($input['max_scroll'] ?? 0)));
        $ended = ($type === 'page_end') ? 1 : 0;
        $stmt = $pdo->prepare('UPDATE site_page_sessions SET last_heartbeat = NOW(), total_ms = GREATEST(total_ms, ?), engaged_ms = GREATEST(engaged_ms, ?), idle_ms = GREATEST(idle_ms, ?), max_scroll = GREATEST(max_scroll, ?), ended = GREATEST(ended, ?) WHERE client_page_id = ?');
        $stmt->execute([$totalMs, $engagedMs, $idleMs, $maxScroll, $ended, $clientPageId]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($type === 'interaction') {
        // Basic interaction payload
        $eventType = substr((string)($input['event_type'] ?? 'click'), 0, 40);
        $target = $input['target'] ?? [];
        $targetTag = substr((string)($target['tag'] ?? ''), 0, 32) ?: null;
        $targetId = substr((string)($target['id'] ?? ''), 0, 80) ?: null;
        $targetClass = substr((string)($target['class'] ?? ''), 0, 120) ?: null;
        $targetText = substr((string)($target['text'] ?? ''), 0, 160) ?: null;
        $targetHref = substr((string)($target['href'] ?? ''), 0, 255) ?: null;
        $meta = $input['meta'] ?? null;

        // Optional: link to existing page_session row
        $pageSessionId = null;
        if ($clientPageId) {
            $lookup = $pdo->prepare('SELECT id FROM site_page_sessions WHERE client_page_id = ?');
            $lookup->execute([$clientPageId]);
            $pageSessionId = ($row = $lookup->fetchColumn()) ? intval($row) : null;
            if ($pageSessionId) {
                $pdo->prepare('UPDATE site_page_sessions SET interactions_count = LEAST(999999, interactions_count + 1) WHERE id = ?')->execute([$pageSessionId]);
            }
        }

        $stmt = $pdo->prepare('INSERT INTO site_interactions (client_page_id, page_session_id, session_id, user_id, path, event_type, target_tag, target_id, target_class, target_text, target_href, meta) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $clientPageId ?: null,
            $pageSessionId,
            $sessionId,
            $uid,
            $path,
            $eventType,
            $targetTag,
            $targetId,
            $targetClass,
            $targetText,
            $targetHref,
            $meta ? json_encode($meta, JSON_UNESCAPED_SLASHES) : null
        ]);

        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'unhandled']);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['success' => false, 'error' => 'logged']);
}


