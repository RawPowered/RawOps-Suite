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
$noteId = isset($payload['note_id']) ? (int)$payload['note_id'] : 0;
$reason = trim($payload['reason'] ?? 'Note no longer relevant');

if ($noteId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Note ID required']);
    exit;
}

if (strlen($reason) > 500) {
    echo json_encode(['ok' => false, 'message' => 'Deletion reason too long (max 500 characters)']);
    exit;
}

try {
    $pdo = db();
    
    // Fetch the note with case access check
    $stmt = $pdo->prepare('SELECT n.*, c.id as case_id FROM legal_case_notes n JOIN legal_cases c ON c.id = n.case_id WHERE n.id = ? AND n.deleted_at IS NULL AND n.note_type = "note" LIMIT 1');
    $stmt->execute([$noteId]);
    $note = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$note) {
        echo json_encode(['ok' => false, 'message' => 'Note not found or already deleted']);
        exit;
    }
    
    // Check case access
    if (!legal_user_can_access_case($userId, $note['case_id'])) {
        echo json_encode(['ok' => false, 'message' => 'Access denied']);
        exit;
    }
    
    $isAdmin = user_has_role($userId, 'admin');
    $isAuthor = ($note['author_id'] == $userId);
    
    // Permission check: Admin can delete any note, users can delete their own notes
    if (!$isAdmin && !$isAuthor) {
        echo json_encode(['ok' => false, 'message' => 'You can only delete your own notes']);
        exit;
    }
    
    // Soft delete the note
    $stmt = $pdo->prepare('UPDATE legal_case_notes SET deleted_at = NOW(), deleted_by = ?, deletion_reason = ? WHERE id = ?');
    $stmt->execute([$userId, $reason, $noteId]);
    
    // Log the deletion activity
    $pdo->prepare('INSERT INTO legal_case_activity (case_id, user_id, activity_type, activity_payload) VALUES (?, ?, "note_deleted", ?)')->execute([
        $note['case_id'],
        $userId,
        json_encode([
            'note_id' => $noteId,
            'note_type' => 'note',
            'deleted_by_admin' => $isAdmin,
            'reason' => $reason,
            'original_author_id' => $note['author_id']
        ])
    ]);
    
    echo json_encode([
        'ok' => true,
        'message' => 'Note deleted successfully'
    ]);
    
} catch (Throwable $e) {
    error_log('[legal/delete-note] error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'Server error']);
}












