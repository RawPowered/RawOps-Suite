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

if ($documentId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Document ID required']);
    exit;
}

try {
    $pdo = db();
    
    // Fetch current document
    $stmt = $pdo->prepare('SELECT case_id, display_name FROM legal_case_documents WHERE id = ? LIMIT 1');
    $stmt->execute([$documentId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$doc) {
        echo json_encode(['ok' => false, 'message' => 'Document not found']);
        exit;
    }
    
    // Build update query
    $updates = [];
    $params = [];
    
    if (isset($payload['display_name']) && $payload['display_name'] !== '') {
        $updates[] = 'display_name = ?';
        $params[] = trim($payload['display_name']);
    }
    if (isset($payload['description'])) {
        $updates[] = 'description = ?';
        $params[] = trim($payload['description']);
    }
    if (isset($payload['document_type'])) {
        $updates[] = 'document_type = ?';
        $params[] = $payload['document_type'];
    }
    if (isset($payload['exhibit_number'])) {
        $updates[] = 'exhibit_number = ?';
        $params[] = trim($payload['exhibit_number']) ?: null;
    }
    if (isset($payload['evidence_date'])) {
        $updates[] = 'evidence_date = ?';
        $params[] = $payload['evidence_date'] ?: null;
    }
    if (isset($payload['timeline_date'])) {
        $updates[] = 'timeline_date = ?';
        $params[] = $payload['timeline_date'] ?: null;
    }
    if (isset($payload['is_key_evidence'])) {
        $updates[] = 'is_key_evidence = ?';
        $params[] = $payload['is_key_evidence'] ? 1 : 0;
    }
    if (isset($payload['notes'])) {
        $updates[] = 'notes = ?';
        $params[] = trim($payload['notes']);
    }
    if (isset($payload['tags'])) {
        $updates[] = 'tags = ?';
        $params[] = json_encode($payload['tags']);
    }
    if (isset($payload['sort_order'])) {
        $updates[] = 'sort_order = ?';
        $params[] = (int)$payload['sort_order'];
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
        $doc['case_id'],
        $userId,
        json_encode(['document_id' => $documentId, 'fields' => array_keys($payload)])
    ]);
    
    echo json_encode(['ok' => true]);
    
} catch (Throwable $e) {
    error_log('[legal/update-evidence-metadata] error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'Server error']);
}






