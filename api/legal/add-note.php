<?php

require_once __DIR__ . '/../../required/config.php';
require_once __DIR__ . '/../../required/legal-permissions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'Invalid request']);
    exit;
}

start_session();
$userId = current_user_id();
if (!$userId) {
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$caseId = isset($payload['case_id']) ? (int)$payload['case_id'] : 0;
$noteBody = trim($payload['note_body'] ?? '');
$visibility = $payload['visibility'] ?? 'team';
$pinned = !empty($payload['pinned']);

if ($caseId <= 0 || !$noteBody) {
    echo json_encode(['ok' => false, 'message' => 'Case ID and note body required']);
    exit;
}

if (!legal_user_can_access_case($userId, $caseId)) {
    echo json_encode(['ok' => false, 'message' => 'Access denied']);
    exit;
}

try {
    $pdo = db();
    
    $stmt = $pdo->prepare('INSERT INTO legal_case_notes (case_id, author_id, visibility, note_body, pinned) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$caseId, $userId, $visibility, $noteBody, $pinned ? 1 : 0]);
    $noteId = (int)$pdo->lastInsertId();
    
    // Log activity
    $pdo->prepare('INSERT INTO legal_case_activity (case_id, user_id, activity_type, activity_payload) VALUES (?, ?, "note_added", ?)')->execute([
        $caseId,
        $userId,
        json_encode(['note_id' => $noteId, 'visibility' => $visibility])
    ]);
    
    echo json_encode(['ok' => true, 'note_id' => $noteId]);
    
} catch (Throwable $e) {
    error_log('[legal/add-note] error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'Server error']);
}








