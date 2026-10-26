<?php
/**
 * Update User Consent Preferences API
 * Updates SMS/Phone/Email consent with audit trail
 */

require_once __DIR__ . '/../../required/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

start_session();
$uid = current_user_id();

if (!$uid) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $pdo = db();
    
    // Get consent values from POST (checkboxes)
    $consentSMS = isset($_POST['consent_sms']) ? 1 : 0;
    $consentPhone = isset($_POST['consent_phone']) ? 1 : 0;
    $consentEmail = isset($_POST['consent_email']) ? 1 : 0;
    
    // Get current consent values
    $stmt = $pdo->prepare('SELECT consent_sms, consent_phone, consent_email FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $currentConsent = $stmt->fetch();
    
    if (!$currentConsent) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }
    
    // Begin transaction
    $pdo->beginTransaction();
    
    try {
        // Update consent fields
        $stmt = $pdo->prepare('
            UPDATE users 
            SET consent_sms = ?, 
                consent_phone = ?, 
                consent_email = ?,
                consent_updated_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([$consentSMS, $consentPhone, $consentEmail, $uid]);
        
        // Log each consent change
        $consentTypes = [
            'sms' => ['old' => intval($currentConsent['consent_sms']), 'new' => $consentSMS],
            'phone' => ['old' => intval($currentConsent['consent_phone']), 'new' => $consentPhone],
            'email' => ['old' => intval($currentConsent['consent_email']), 'new' => $consentEmail]
        ];
        
        $changesLogged = 0;
        foreach ($consentTypes as $type => $values) {
            // Only log if value changed
            if ($values['old'] !== $values['new']) {
                $stmt = $pdo->prepare('
                    INSERT INTO user_consent_history 
                    (user_id, consent_type, old_value, new_value, ip_address, user_agent)
                    VALUES (?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([
                    $uid,
                    $type,
                    $values['old'],
                    $values['new'],
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
                ]);
                $changesLogged++;
            }
        }
        
        $pdo->commit();
        
        $message = 'Contact preferences updated successfully';
        if ($changesLogged > 0) {
            $message .= ' (' . $changesLogged . ' change' . ($changesLogged > 1 ? 's' : '') . ' logged)';
        }
        
        echo json_encode([
            'success' => true,
            'message' => $message,
            'consent' => [
                'sms' => $consentSMS,
                'phone' => $consentPhone,
                'email' => $consentEmail
            ]
        ]);
        
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Throwable $e) {
    error_log('Consent update error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'An error occurred. Please try again.']);
}

