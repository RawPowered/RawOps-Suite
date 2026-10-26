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
if (!$userId || !user_has_role($userId, 'admin')) {
    echo json_encode(['ok' => false, 'message' => 'Admin access required']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$documentId = isset($payload['document_id']) ? (int)$payload['document_id'] : 0;
$displayName = trim($payload['display_name'] ?? '');
$description = trim($payload['description'] ?? '');
$documentType = $payload['document_type'] ?? null;

if ($documentId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Document ID required']);
    exit;
}

try {
    $pdo = db();
    
    // Fetch current document to verify case access
    $stmt = $pdo->prepare('SELECT case_id FROM legal_case_documents WHERE id = ? LIMIT 1');
    $stmt->execute([$documentId]);
    $caseId = $stmt->fetchColumn();
    
    if (!$caseId) {
        echo json_encode(['ok' => false, 'message' => 'Document not found']);
        exit;
    }
    
    // Build update query
    $updates = [];
    $params = [];
    
    if ($displayName) {
        $updates[] = 'display_name = ?';
        $params[] = $displayName;
    }
    if ($description !== '') {
        $updates[] = 'description = ?';
        $params[] = $description;
    }
    if ($documentType && in_array($documentType, ['exhibit','analysis','correspondence','financial','media','other'], true)) {
        $updates[] = 'document_type = ?';
        $params[] = $documentType;
    }
    
    if (empty($updates)) {
        echo json_encode(['ok' => false, 'message' => 'No fields to update']);
        exit;
    }
    
    $params[] = $documentId;
    $sql = 'UPDATE legal_case_documents SET ' . implode(', ', $updates) . ' WHERE id = ?';
    $pdo->prepare($sql)->execute($params);
    
    // Log activity
    $pdo->prepare('INSERT INTO legal_case_activity (case_id, user_id, activity_type, activity_payload) VALUES (?, ?, "document_updated", ?)')->execute([
        $caseId,
        $userId,
        json_encode(['document_id' => $documentId, 'display_name' => $displayName])
    ]);
    
    echo json_encode(['ok' => true]);
    
} catch (Throwable $e) {
    error_log('[legal/update-document] error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'Server error']);
}








