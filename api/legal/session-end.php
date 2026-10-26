<?php

require_once __DIR__ . '/../../required/config.php';
require_once __DIR__ . '/../../required/legal-session.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    start_session();
} catch (Throwable $e) {
    // ignore
}

$userId = current_user_id();
if (!$userId || !user_has_role($userId, 'legal')) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!$payload || empty($payload['session_token'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid payload']);
    exit;
}

$sessionToken = $payload['session_token'];
$endedReason = isset($payload['reason']) ? substr((string)$payload['reason'], 0, 48) : 'manual_end';
$totalMs = isset($payload['total_ms']) ? max(0, (int)$payload['total_ms']) : null;
$activeMs = isset($payload['active_ms']) ? max(0, (int)$payload['active_ms']) : null;
$idleMs = isset($payload['idle_ms']) ? max(0, (int)$payload['idle_ms']) : null;
$isIdle = isset($payload['is_idle']) ? (int)!empty($payload['is_idle']) : null;

try {
    $pdo = db();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Database unavailable']);
    exit;
}

$stmt = $pdo->prepare('SELECT user_id, ended_at FROM legal_portal_sessions WHERE session_token = ? LIMIT 1');
$stmt->execute([$sessionToken]);
$sessionRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sessionRow || (int)$sessionRow['user_id'] !== (int)$userId) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Session not found']);
    exit;
}

if (!empty($sessionRow['ended_at'])) {
    echo json_encode(['ok' => true, 'message' => 'Session already closed']);
    exit;
}

$fields = ['ended_at = NOW()', 'ended_reason = ?'];
$params = [$endedReason];

if ($totalMs !== null) {
    $fields[] = 'total_session_seconds = ?';
    $params[] = (int)floor($totalMs / 1000);
}

if ($activeMs !== null) {
    $fields[] = 'total_active_seconds = ?';
    $params[] = (int)floor($activeMs / 1000);
}

if ($idleMs !== null) {
    $fields[] = 'total_idle_seconds = ?';
    $params[] = (int)floor($idleMs / 1000);
}

if ($isIdle !== null) {
    $fields[] = 'is_idle = ?';
    $params[] = $isIdle;
}

$fields[] = 'updated_at = NOW()';
$params[] = $sessionToken;

$sql = 'UPDATE legal_portal_sessions SET ' . implode(', ', $fields) . ' WHERE session_token = ? LIMIT 1';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

// Clear session tracking cache
unset($_SESSION['legal_portal_session']);

echo json_encode(['ok' => true]);







