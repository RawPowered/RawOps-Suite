<?php

require_once __DIR__ . '/../../required/config.php';
require_once __DIR__ . '/../../required/legal-permissions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['ok' => false, 'message' => 'Invalid request']);
    exit;
}

start_session();
$userId = current_user_id();
if (!$userId) {
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

$caseId = isset($_GET['case_id']) ? (int)$_GET['case_id'] : 0;
$since = isset($_GET['since']) ? (int)$_GET['since'] : 0;

if ($caseId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Case ID required']);
    exit;
}

if (!legal_user_can_access_case($userId, $caseId)) {
    echo json_encode(['ok' => false, 'message' => 'Access denied']);
    exit;
}

try {
    $pdo = db();
    
    // Fetch recent shoutbox messages (last 50, or since ID) - exclude deleted messages and only get shoutbox type
    if ($since > 0) {
        $stmt = $pdo->prepare('SELECT n.*, u.first_name, u.last_name, u.email FROM legal_case_notes n LEFT JOIN users u ON u.id = n.author_id WHERE n.case_id = ? AND n.id > ? AND n.deleted_at IS NULL AND n.note_type = "shoutbox" ORDER BY n.created_at DESC LIMIT 50');
        $stmt->execute([$caseId, $since]);
    } else {
        $stmt = $pdo->prepare('SELECT n.*, u.first_name, u.last_name, u.email FROM legal_case_notes n LEFT JOIN users u ON u.id = n.author_id WHERE n.case_id = ? AND n.deleted_at IS NULL AND n.note_type = "shoutbox" ORDER BY n.created_at DESC LIMIT 50');
        $stmt->execute([$caseId]);
    }
    
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'ok' => true,
        'notes' => array_reverse($notes), // Oldest first for chat display
        'count' => count($notes)
    ]);
    
} catch (Throwable $e) {
    error_log('[legal/shoutbox-fetch] error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'Server error']);
}

