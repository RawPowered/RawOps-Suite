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
$reason = trim($payload['reason'] ?? '');

if ($documentId <= 0 || !$reason) {
    echo json_encode(['ok' => false, 'message' => 'Document ID and reason required']);
    exit;
}

$validReasons = ['duplicate_entry', 'wrong_name', 'wrong_category', 'incorrect_file'];
if (!in_array($reason, $validReasons, true)) {
    echo json_encode(['ok' => false, 'message' => 'Invalid reason']);
    exit;
}

try {
    $pdo = db();
    
    // Fetch document info before deletion
    $stmt = $pdo->prepare('SELECT case_id, storage_path, display_name FROM legal_case_documents WHERE id = ? LIMIT 1');
    $stmt->execute([$documentId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$doc) {
        echo json_encode(['ok' => false, 'message' => 'Document not found']);
        exit;
    }
    
    $caseId = $doc['case_id'];
    
    // Log deletion before removing
    $pdo->prepare('INSERT INTO legal_case_activity (case_id, user_id, activity_type, activity_payload) VALUES (?, ?, "document_deleted", ?)')->execute([
        $caseId,
        $userId,
        json_encode([
            'document_id' => $documentId,
            'display_name' => $doc['display_name'],
            'storage_path' => $doc['storage_path'],
            'reason' => $reason,
            'reason_label' => match($reason) {
                'duplicate_entry' => 'Duplicate Entry',
                'wrong_name' => 'Filed Under Wrong Name',
                'wrong_category' => 'Filed Under Wrong Category',
                'incorrect_file' => 'Incorrect File Uploaded',
                default => 'Other'
            }
        ])
    ]);
    
    // Delete from database (cascades to legal_case_evidence)
    $pdo->prepare('DELETE FROM legal_case_documents WHERE id = ?')->execute([$documentId]);
    
    // Optionally delete physical file
    $filePath = __DIR__ . '/../../admin/legal/evidence-locker/' . basename($doc['storage_path']);
    if (is_file($filePath)) {
        @unlink($filePath);
    }
    
    echo json_encode(['ok' => true]);
    
} catch (Throwable $e) {
    error_log('[legal/delete-document] error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'Server error']);
}







