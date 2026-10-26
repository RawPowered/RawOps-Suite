<?php

require_once __DIR__ . '/../../required/config.php';
require_once __DIR__ . '/../../required/legal-email-service.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'Invalid request']);
    exit;
}

start_session();

$payload = json_decode(file_get_contents('php://input'), true) ?: [];

$requestorName = trim($payload['requestor_name'] ?? '');
$lawFirm = trim($payload['law_firm'] ?? '');
$email = trim($payload['email'] ?? '');
$phone = trim($payload['phone'] ?? '');
$desiredUsername = trim($payload['desired_username'] ?? '');
$caseKey = trim($payload['case_key'] ?? '');
$additionalMembers = $payload['additional_members'] ?? [];

if (!$requestorName || !$email) {
    echo json_encode(['ok' => false, 'message' => 'Name and email are required']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'message' => 'Invalid email address']);
    exit;
}

try {
    $pdo = db();
    
    // Resolve case ID if key provided
    $caseId = null;
    if ($caseKey) {
        $stmt = $pdo->prepare('SELECT id FROM legal_cases WHERE case_key = ? LIMIT 1');
        $stmt->execute([$caseKey]);
        $caseId = $stmt->fetchColumn();
    }
    
    // Check for duplicate request
    $stmt = $pdo->prepare('SELECT id FROM legal_access_requests WHERE email = ? AND status = "pending" LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) {
        echo json_encode(['ok' => false, 'message' => 'You already have a pending access request']);
        exit;
    }
    
    // Insert main request
    $stmt = $pdo->prepare('INSERT INTO legal_access_requests (case_id, requestor_name, law_firm, email, phone, desired_username, status) VALUES (?, ?, ?, ?, ?, ?, "pending")');
    $stmt->execute([$caseId, $requestorName, $lawFirm, $email, $phone, $desiredUsername]);
    $requestId = (int)$pdo->lastInsertId();
    
    // Insert additional team members (up to 3)
    $membersInserted = 0;
    foreach (array_slice($additionalMembers, 0, 3) as $member) {
        $memberName = trim($member['name'] ?? '');
        $memberEmail = trim($member['email'] ?? '');
        $memberPhone = trim($member['phone'] ?? '');
        
        if ($memberName && $memberEmail && filter_var($memberEmail, FILTER_VALIDATE_EMAIL)) {
            $stmt = $pdo->prepare('INSERT INTO legal_access_request_members (access_request_id, full_name, email, phone) VALUES (?, ?, ?, ?)');
            $stmt->execute([$requestId, $memberName, $memberEmail, $memberPhone]);
            $membersInserted++;
        }
    }
    
    // Log activity if case exists
    if ($caseId) {
        $pdo->prepare('INSERT INTO legal_case_activity (case_id, user_id, activity_type, activity_payload) VALUES (?, NULL, "guest_request", ?)')->execute([
            $caseId,
            json_encode(['requestor_name' => $requestorName, 'email' => $email, 'request_id' => $requestId])
        ]);
    }
    
    // Send admin notification via SendGrid
    $caseNumber = null;
    if ($caseId) {
        $caseStmt = $pdo->prepare('SELECT case_number FROM legal_cases WHERE id = ? LIMIT 1');
        $caseStmt->execute([$caseId]);
        $caseNumber = $caseStmt->fetchColumn();
    }
    
    $notificationResult = send_legal_access_request_notification($requestId, $requestorName, $email, $caseNumber);
    
    // Send confirmation email to requestor
    $confirmationResult = send_legal_access_request_confirmation($email, $requestorName, $requestId, $caseNumber);
    
    echo json_encode([
        'ok' => true,
        'message' => 'Access request submitted successfully. An admin will review your request shortly.',
        'request_id' => $requestId,
        'members_added' => $membersInserted,
        'notification_sent' => $notificationResult['ok'] ?? false,
        'confirmation_sent' => $confirmationResult['ok'] ?? false
    ]);
    
} catch (Throwable $e) {
    error_log('[legal/request-access] error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'Server error. Please try again later.']);
}


