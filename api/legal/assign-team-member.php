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
$caseId = isset($payload['case_id']) ? (int)$payload['case_id'] : 0;
$targetUserId = isset($payload['user_id']) ? (int)$payload['user_id'] : 0;
$role = $payload['role'] ?? 'observer';

if ($caseId <= 0 || $targetUserId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Case ID and user ID required']);
    exit;
}

$validRoles = ['lead_counsel', 'co_counsel', 'paralegal', 'investigator', 'observer', 'admin'];
if (!in_array($role, $validRoles, true)) {
    echo json_encode(['ok' => false, 'message' => 'Invalid role']);
    exit;
}

try {
    $pdo = db();
    
    // Check if already assigned
    $stmt = $pdo->prepare('SELECT id FROM legal_case_team WHERE case_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$caseId, $targetUserId]);
    if ($stmt->fetchColumn()) {
        echo json_encode(['ok' => false, 'message' => 'User already assigned to this case']);
        exit;
    }
    
    // Insert team assignment
    $stmt = $pdo->prepare('INSERT INTO legal_case_team (case_id, user_id, role, added_by) VALUES (?, ?, ?, ?)');
    $stmt->execute([$caseId, $targetUserId, $role, $userId]);
    
    // Log activity
    $pdo->prepare('INSERT INTO legal_case_activity (case_id, user_id, activity_type, activity_payload) VALUES (?, ?, "team_assignment", ?)')->execute([
        $caseId,
        $userId,
        json_encode(['assigned_user_id' => $targetUserId, 'role' => $role])
    ]);
    
    echo json_encode(['ok' => true]);
    
} catch (Throwable $e) {
    error_log('[legal/assign-team-member] error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'Server error']);
}







