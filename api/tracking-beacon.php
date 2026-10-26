<?php
require_once __DIR__ . '/../required/config.php';
start_session();

header('Content-Type: application/json');

try {
    $pdo = db();
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '[]', true);
    if (!is_array($data)) $data = [];

    $sourceId   = intval($data['source_id'] ?? 0);
    $sessionId  = substr((string)($data['session_id'] ?? ''), 0, 64);
    $eventType  = in_array(($data['event_type'] ?? ''), ['view','engage','submit','error'], true) ? $data['event_type'] : 'engage';
    $durationMs = max(0, intval($data['duration_ms'] ?? 0));
    $extra      = $data['extra'] ?? null;
    $demoLeadId = isset($extra['demo_lead_id']) ? intval($extra['demo_lead_id']) : null;

    if ($sourceId <= 0) throw new Exception('Invalid source');

    $stmt = $pdo->prepare('INSERT INTO promo_demo_events (source_id, demo_lead_id, event_type, session_id, ip_address, user_agent, referrer, duration_ms, extra) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $sourceId,
        $demoLeadId,
        $eventType,
        $sessionId ?: null,
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500),
        $durationMs,
        $extra ? json_encode($extra, JSON_UNESCAPED_SLASHES) : null
    ]);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['success' => false, 'error' => 'logged']);
}
?>


