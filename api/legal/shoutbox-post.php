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
$message = trim($payload['message'] ?? '');
$addToNotes = !empty($payload['add_to_notes']);

if ($caseId <= 0 || !$message) {
    echo json_encode(['ok' => false, 'message' => 'Case ID and message required']);
    exit;
}

if (!legal_user_can_access_case($userId, $caseId)) {
    echo json_encode(['ok' => false, 'message' => 'Access denied']);
    exit;
}

try {
    $pdo = db();
    
    // Parse mentions (@username, @filename)
    $mentions = [];
    preg_match_all('/@([a-zA-Z0-9_\-\.]+)/', $message, $matches);
    if (!empty($matches[1])) {
        $mentions = array_unique($matches[1]);
    }
    
    // Store as shoutbox message
    $stmt = $pdo->prepare('INSERT INTO legal_case_notes (case_id, author_id, visibility, note_type, note_body, pinned) VALUES (?, ?, "team", "shoutbox", ?, 0)');
    $stmt->execute([$caseId, $userId, $message]);
    $noteId = (int)$pdo->lastInsertId();
    
    // If "Add To Notes" is checked, also create a formal note
    if ($addToNotes) {
        $stmt = $pdo->prepare('INSERT INTO legal_case_notes (case_id, author_id, visibility, note_type, note_body, pinned) VALUES (?, ?, "team", "note", ?, 0)');
        $stmt->execute([$caseId, $userId, $message]);
    }
    
    // Log activity with mentions
    $pdo->prepare('INSERT INTO legal_case_activity (case_id, user_id, activity_type, activity_payload) VALUES (?, ?, "note_added", ?)')->execute([
        $caseId,
        $userId,
        json_encode(['note_id' => $noteId, 'type' => 'shoutbox', 'mentions' => $mentions, 'added_to_notes' => $addToNotes])
    ]);
    
    // Fetch the created note with author info
    $stmt = $pdo->prepare('SELECT n.*, u.first_name, u.last_name, u.email FROM legal_case_notes n LEFT JOIN users u ON u.id = n.author_id WHERE n.id = ? LIMIT 1');
    $stmt->execute([$noteId]);
    $note = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'ok' => true,
        'note' => $note,
        'mentions' => $mentions
    ]);
    
} catch (Throwable $e) {
    error_log('[legal/shoutbox-post] error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'Server error']);
}

