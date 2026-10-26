<?php
// API: Remove team member from case
header('Content-Type: application/json');
require_once __DIR__ . '/../../required/config.php';
require_once __DIR__ . '/../../required/legal-permissions.php';

try {
    require_login_or_die();
    $currentUser = current_user_id();
    
    if (!user_has_role($currentUser, 'admin') && !user_has_role($currentUser, 'legal')) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    
    $pdo = db();
    $memberId = (int)($_POST['member_id'] ?? 0);
    
    if ($memberId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Member ID is required']);
        exit;
    }
    
    // Get member info for logging
    $stmt = $pdo->prepare('
        SELECT t.case_id, t.user_id, t.role, u.first_name, u.last_name
        FROM legal_case_team t
        JOIN users u ON u.id = t.user_id
        WHERE t.id = ?
    ');
    $stmt->execute([$memberId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$member) {
        echo json_encode(['success' => false, 'message' => 'Team member not found']);
        exit;
    }
    
    // Delete team member
    $stmt = $pdo->prepare('DELETE FROM legal_case_team WHERE id = ?');
    $stmt->execute([$memberId]);
    
    // Log activity
    $stmt = $pdo->prepare('
        INSERT INTO legal_case_activity (case_id, user_id, activity_type, activity_payload)
        VALUES (?, ?, "team_removed", ?)
    ');
    $stmt->execute([
        $member['case_id'],
        $currentUser,
        json_encode([
            'removed_user_id' => $member['user_id'],
            'removed_user_name' => $member['first_name'] . ' ' . $member['last_name'],
            'role' => $member['role']
        ])
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Team member removed successfully'
    ]);
    
} catch (Exception $e) {
    error_log('Legal team remove error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ]);
}




