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
$query = trim($_GET['query'] ?? '');

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
    $isAdmin = user_has_role($userId, 'admin');
    
    $results = [];
    
    // Get team members for this case
    $stmt = $pdo->prepare('
        SELECT DISTINCT u.id, u.first_name, u.last_name, u.email, t.role
        FROM legal_case_team t
        JOIN users u ON u.id = t.user_id
        WHERE t.case_id = ?
        ORDER BY u.first_name, u.last_name
    ');
    $stmt->execute([$caseId]);
    $teamMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($teamMembers as $member) {
        $displayName = trim($member['first_name'] . ' ' . $member['last_name']);
        $username = strtolower(str_replace(' ', '', $displayName));
        
        if (!$query || stripos($displayName, $query) !== false || stripos($username, $query) !== false) {
            $results[] = [
                'type' => 'user',
                'id' => $member['id'],
                'mention' => $username,
                'display' => $displayName . ' (' . $member['role'] . ')',
                'email' => $member['email']
            ];
        }
    }
    
    // Get evidence files for this case
    $stmt = $pdo->prepare('
        SELECT id, display_name, storage_path, document_type
        FROM legal_case_documents
        WHERE case_id = ?
        ORDER BY display_name
    ');
    $stmt->execute([$caseId]);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($documents as $doc) {
        $fileName = basename($doc['storage_path']);
        
        if (!$query || stripos($doc['display_name'], $query) !== false || stripos($fileName, $query) !== false) {
            $results[] = [
                'type' => 'evidence',
                'id' => $doc['id'],
                'mention' => $fileName,
                'display' => $doc['display_name'] . ' (' . $doc['document_type'] . ')',
                'file_name' => $fileName
            ];
        }
    }
    
    echo json_encode([
        'ok' => true,
        'results' => array_slice($results, 0, 20) // Limit to 20 results
    ]);
    
} catch (Throwable $e) {
    error_log('[legal/get-mentions] error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'Server error']);
}







