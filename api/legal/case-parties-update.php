<?php
// API: Manage case parties (add/remove)
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
    $action = $_POST['action'] ?? 'add';
    
    if ($action === 'add') {
        $caseId = (int)($_POST['case_id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $partyType = $_POST['party_type'] ?? 'other';
        $entityType = $_POST['entity_type'] ?? 'individual';
        $email = trim($_POST['email'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if ($caseId <= 0 || empty($fullName)) {
            echo json_encode(['success' => false, 'message' => 'Case ID and full name are required']);
            exit;
        }
        
        // Build contact info JSON
        $contactInfo = null;
        if (!empty($email)) {
            $contactInfo = json_encode(['email' => $email]);
        }
        
        // Insert party
        $stmt = $pdo->prepare('
            INSERT INTO legal_case_parties (case_id, party_type, full_name, entity_type, contact_info, notes)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        
        $stmt->execute([$caseId, $partyType, $fullName, $entityType, $contactInfo, $notes]);
        
        // Log activity
        $stmt = $pdo->prepare('
            INSERT INTO legal_case_activity (case_id, user_id, activity_type, activity_payload)
            VALUES (?, ?, "other", ?)
        ');
        $stmt->execute([
            $caseId,
            $currentUser,
            json_encode(['action' => 'party_added', 'party_name' => $fullName, 'party_type' => $partyType])
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Party added successfully'
        ]);
        
    } elseif ($action === 'remove') {
        $partyId = (int)($_POST['party_id'] ?? 0);
        
        if ($partyId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Party ID is required']);
            exit;
        }
        
        // Get party info for logging
        $stmt = $pdo->prepare('SELECT case_id, full_name FROM legal_case_parties WHERE id = ?');
        $stmt->execute([$partyId]);
        $party = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$party) {
            echo json_encode(['success' => false, 'message' => 'Party not found']);
            exit;
        }
        
        // Delete party
        $stmt = $pdo->prepare('DELETE FROM legal_case_parties WHERE id = ?');
        $stmt->execute([$partyId]);
        
        // Log activity
        $stmt = $pdo->prepare('
            INSERT INTO legal_case_activity (case_id, user_id, activity_type, activity_payload)
            VALUES (?, ?, "other", ?)
        ');
        $stmt->execute([
            $party['case_id'],
            $currentUser,
            json_encode(['action' => 'party_removed', 'party_name' => $party['full_name']])
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Party removed successfully'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    error_log('Legal case parties error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ]);
}




