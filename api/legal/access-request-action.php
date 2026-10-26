<?php

require_once __DIR__ . '/../../required/config.php';
require_once __DIR__ . '/../../required/legal-permissions.php';
require_once __DIR__ . '/../../required/legal-email-service.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'Invalid request']);
    exit;
}

start_session();
$userId = current_user_id();
if (!$userId || !(user_has_role($userId, 'admin') || user_has_role($userId, 'legal'))) {
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$id = isset($payload['id']) ? (int)$payload['id'] : 0;
$action = $payload['action'] ?? '';
$reason = trim($payload['reason'] ?? '');

if ($id <= 0 || !in_array($action, ['approve', 'deny'], true)) {
    echo json_encode(['ok' => false, 'message' => 'Invalid parameters']);
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM legal_access_requests WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    echo json_encode(['ok' => false, 'message' => 'Request not found']);
    exit;
}

if ($request['status'] !== 'pending') {
    echo json_encode(['ok' => false, 'message' => 'Request already processed']);
    exit;
}

$status = $action === 'approve' ? 'approved' : 'denied';

$update = $pdo->prepare('UPDATE legal_access_requests SET status = ?, notes = CASE WHEN ? <> "" THEN CONCAT(COALESCE(notes, ""), "\nAdmin: ", ?) ELSE notes END, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?');
$update->execute([$status, $reason, $reason, $userId, $id]);

// Send email notification
$nameParts = explode(' ', $request['requestor_name'], 2);
$firstName = $nameParts[0];

if ($action === 'approve') {
    // Get username and create login URL
    $username = $request['desired_username'] ?: $request['email'];
    $loginUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . path_with_base('/login.php');
    
    $emailResult = send_legal_access_approved($request['email'], $firstName, $username, $loginUrl);
    
    // Assign legal role to user if they exist
    $userStmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $userStmt->execute([$request['email']]);
    $targetUserId = $userStmt->fetchColumn();
    
    if ($targetUserId) {
        // Assign legal role
        $roleStmt = $pdo->prepare('SELECT id FROM roles WHERE name = "legal" LIMIT 1');
        $roleStmt->execute();
        $legalRoleId = $roleStmt->fetchColumn();
        
        if ($legalRoleId) {
            $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$targetUserId, $legalRoleId]);
        }
        
        // Update user status to active and clear force_password_reset (they set password during signup)
        $pdo->prepare('UPDATE users SET status = "active", force_password_reset = 0 WHERE id = ?')->execute([$targetUserId]);
        
        // If case specified, auto-assign to case team
        if ($request['case_id']) {
            $pdo->prepare('INSERT IGNORE INTO legal_case_team (case_id, user_id, role, added_by) VALUES (?, ?, "observer", ?)')->execute([
                $request['case_id'],
                $targetUserId,
                $userId
            ]);
        }
    }
} else {
    $emailResult = send_legal_access_denied($request['email'], $firstName, $reason);
}

echo json_encode([
    'ok' => true,
    'email_sent' => $emailResult['ok'] ?? false
]);

