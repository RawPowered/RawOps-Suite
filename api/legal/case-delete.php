<?php
// API: Delete/archive legal case
header('Content-Type: application/json');
require_once __DIR__ . '/../../required/config.php';
require_once __DIR__ . '/../../required/legal-permissions.php';

try {
    require_login_or_die();
    $currentUser = current_user_id();
    
    // Only admins can delete cases
    if (!user_has_role($currentUser, 'admin')) {
        echo json_encode(['success' => false, 'message' => 'Only administrators can delete cases']);
        exit;
    }
    
    $pdo = db();
    $caseId = (int)($_POST['case_id'] ?? 0);
    $action = $_POST['action'] ?? 'archive'; // 'archive' or 'delete'
    
    if ($caseId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Case ID is required']);
        exit;
    }
    
    // Verify case exists
    $stmt = $pdo->prepare('SELECT title FROM legal_cases WHERE id = ?');
    $stmt->execute([$caseId]);
    $case = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$case) {
        echo json_encode(['success' => false, 'message' => 'Case not found']);
        exit;
    }
    
    if ($action === 'archive') {
        // Archive case (set status to archived)
        $stmt = $pdo->prepare('UPDATE legal_cases SET status = "archived" WHERE id = ?');
        $stmt->execute([$caseId]);
        
        // Log activity
        $stmt = $pdo->prepare('
            INSERT INTO legal_case_activity (case_id, user_id, activity_type, activity_payload)
            VALUES (?, ?, "case_updated", ?)
        ');
        $stmt->execute([
            $caseId,
            $currentUser,
            json_encode(['action' => 'archived', 'archived_by' => $currentUser])
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Case archived successfully'
        ]);
        
    } elseif ($action === 'delete') {
        // Hard delete (this will cascade to all related records)
        // Note: Documents in evidence locker will need separate cleanup
        $stmt = $pdo->prepare('DELETE FROM legal_cases WHERE id = ?');
        $stmt->execute([$caseId]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Case deleted successfully'
        ]);
        
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    error_log('Legal case delete error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ]);
}




