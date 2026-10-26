<?php
// API: Create or update legal case
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
    $caseId = $_POST['case_id'] ?? null;
    $isNew = empty($caseId);
    
    // Required fields
    $title = trim($_POST['title'] ?? '');
    $status = $_POST['status'] ?? 'draft';
    
    if (empty($title)) {
        echo json_encode(['success' => false, 'message' => 'Title is required']);
        exit;
    }
    
    // Optional fields
    $caseNumber = trim($_POST['case_number'] ?? '');
    $jurisdiction = trim($_POST['jurisdiction'] ?? '');
    $retentionPolicy = $_POST['retention_policy'] ?? 'never';
    $regulatoryHold = isset($_POST['regulatory_hold']) ? 1 : 0;
    $regulatoryNotes = trim($_POST['regulatory_notes'] ?? '');
    
    // Auto-generate case number if not provided and is new case
    if ($isNew && empty($caseNumber)) {
        $monthYear = date('mY');
        
        // Get or create sequence for this month
        $stmt = $pdo->prepare('
            INSERT INTO legal_case_number_sequence (month_year, last_sequence) 
            VALUES (?, 1)
            ON DUPLICATE KEY UPDATE last_sequence = last_sequence + 1
        ');
        $stmt->execute([$monthYear]);
        
        // Get the current sequence
        $stmt = $pdo->prepare('SELECT last_sequence FROM legal_case_number_sequence WHERE month_year = ?');
        $stmt->execute([$monthYear]);
        $sequence = $stmt->fetchColumn();
        
        $caseNumber = sprintf('RAWOPS-%s-%06d', $monthYear, $sequence);
    }
    
    if ($isNew) {
        // Generate case key
        $caseKey = 'case-' . strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $title));
        
        // Check if key exists, make unique
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM legal_cases WHERE case_key LIKE ?');
        $stmt->execute([$caseKey . '%']);
        $count = $stmt->fetchColumn();
        if ($count > 0) {
            $caseKey .= '-' . ($count + 1);
        }
        
        // Insert new case
        $stmt = $pdo->prepare('
            INSERT INTO legal_cases (
                case_key, case_number, title, status, jurisdiction, 
                retention_policy, regulatory_hold, regulatory_notes, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        
        $stmt->execute([
            $caseKey, $caseNumber, $title, $status, $jurisdiction,
            $retentionPolicy, $regulatoryHold, $regulatoryNotes, $currentUser
        ]);
        
        $newCaseId = $pdo->lastInsertId();
        
        // Log activity
        $stmt = $pdo->prepare('
            INSERT INTO legal_case_activity (case_id, user_id, activity_type) 
            VALUES (?, ?, "case_created")
        ');
        $stmt->execute([$newCaseId, $currentUser]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Case created successfully',
            'case_id' => $newCaseId,
            'case_number' => $caseNumber
        ]);
    } else {
        // Update existing case
        $stmt = $pdo->prepare('
            UPDATE legal_cases SET
                title = ?,
                status = ?,
                jurisdiction = ?,
                retention_policy = ?,
                regulatory_hold = ?,
                regulatory_notes = ?
            WHERE id = ?
        ');
        
        $stmt->execute([
            $title, $status, $jurisdiction,
            $retentionPolicy, $regulatoryHold, $regulatoryNotes, $caseId
        ]);
        
        // Update case number if provided
        if (!empty($caseNumber)) {
            $stmt = $pdo->prepare('UPDATE legal_cases SET case_number = ? WHERE id = ?');
            $stmt->execute([$caseNumber, $caseId]);
        }
        
        // Log activity
        $stmt = $pdo->prepare('
            INSERT INTO legal_case_activity (case_id, user_id, activity_type, activity_payload) 
            VALUES (?, ?, "case_updated", ?)
        ');
        $stmt->execute([
            $caseId, 
            $currentUser,
            json_encode(['fields_updated' => ['title', 'status', 'jurisdiction', 'retention_policy', 'regulatory_hold']])
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Case updated successfully'
        ]);
    }
    
} catch (Exception $e) {
    error_log('Legal case create/update error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ]);
}




