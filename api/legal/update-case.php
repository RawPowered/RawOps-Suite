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

if ($caseId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Case ID required']);
    exit;
}

// Only admins can edit case metadata
if (!user_has_role($userId, 'admin')) {
    echo json_encode(['ok' => false, 'message' => 'Admin access required']);
    exit;
}

try {
    $pdo = db();
    
    $updates = [];
    $params = [];
    
    if (isset($payload['title'])) {
        $updates[] = 'title = ?';
        $params[] = trim($payload['title']);
    }
    if (isset($payload['summary'])) {
        $updates[] = 'summary = ?';
        $params[] = trim($payload['summary']);
    }
    if (isset($payload['jurisdiction'])) {
        $updates[] = 'jurisdiction = ?';
        $params[] = trim($payload['jurisdiction']);
    }
    if (isset($payload['status'])) {
        $updates[] = 'status = ?';
        $params[] = $payload['status'];
    }
    if (isset($payload['retention_policy'])) {
        $updates[] = 'retention_policy = ?';
        $params[] = $payload['retention_policy'];
    }
    
    if (empty($updates)) {
        echo json_encode(['ok' => false, 'message' => 'No fields to update']);
        exit;
    }
    
    $params[] = $caseId;
    $sql = 'UPDATE legal_cases SET ' . implode(', ', $updates) . ' WHERE id = ?';
    $pdo->prepare($sql)->execute($params);
    
    // Log activity
    $pdo->prepare('INSERT INTO legal_case_activity (case_id, user_id, activity_type, activity_payload) VALUES (?, ?, "case_updated", ?)')->execute([
        $caseId,
        $userId,
        json_encode(['fields_updated' => array_keys($payload)])
    ]);
    
    echo json_encode(['ok' => true]);
    
} catch (Throwable $e) {
    error_log('[legal/update-case] error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'Server error']);
}
