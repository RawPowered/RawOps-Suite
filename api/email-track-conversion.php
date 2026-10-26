<?php
/**
 * Email Conversion Tracking API
 * Tracks email click-throughs and website conversions
 * Integrates with SendGrid webhook for complete email lifecycle tracking
 */

require_once __DIR__ . '/../required/config.php';

start_session();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$pdo = db();

try {
    switch ($action) {
        case 'track_click':
            // Handle email link click and redirect
            $token = $_GET['token'] ?? '';
            $url = $_GET['url'] ?? site_base_url();
            
            if ($token) {
                try {
                    // Find email by tracking token
                    $stmt = $pdo->prepare('SELECT id, agent_id, recipient_email, lead_id, account_id FROM agent_emails WHERE tracking_token = ? LIMIT 1');
                    $stmt->execute([$token]);
                    $email = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($email) {
                        $emailId = intval($email['id']);
                        
                        // Store tracking data in session
                        $_SESSION['email_tracking_token'] = $token;
                        $_SESSION['email_tracking_id'] = $emailId;
                        $_SESSION['email_agent_id'] = intval($email['agent_id']);
                        $_SESSION['email_lead_id'] = intval($email['lead_id'] ?? 0);
                        $_SESSION['email_account_id'] = intval($email['account_id'] ?? 0);
                        $_SESSION['email_tracking_started'] = time();
                        
                        // Create initial page_view conversion
                        $stmt = $pdo->prepare("
                            INSERT INTO email_conversions 
                            (agent_email_id, session_id, tracking_token, conversion_type, created_at)
                            VALUES (?, ?, ?, 'page_view', NOW())
                        ");
                        $stmt->execute([$emailId, session_id(), $token]);
                        
                        // Increment click count in agent_emails (handled by SendGrid webhook, but backup)
                        $pdo->prepare("UPDATE agent_emails SET click_count = click_count + 1 WHERE id = ?")->execute([$emailId]);
                    }
                } catch (Throwable $e) {
                    error_log("Email tracking error: " . $e->getMessage());
                }
            }
            
            // Always redirect, even if tracking fails
            header('Location: ' . $url);
            exit;
            break;
            
        case 'track_open':
            $token = $_GET['token'] ?? '';
            if ($token) {
                try {
                    $stmt = $pdo->prepare('UPDATE agent_emails SET open_count = open_count + 1, last_opened_at = NOW() WHERE tracking_token = ?');
                    $stmt->execute([$token]);
                } catch (Throwable $e) {
                    error_log("Email open tracking error: " . $e->getMessage());
                }
            }
            header('Content-Type: image/gif');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            echo base64_decode('R0lGODlhAQABAIABAP///wAAACwAAAAAAQABAAACAkQBADs=');
            exit;
            break;
            
        case 'track_conversion':
            // Record conversion event via AJAX
            header('Content-Type: application/json');
            
            $input = json_decode(file_get_contents('php://input'), true);
            $type = $input['type'] ?? 'page_view';
            $value = floatval($input['value'] ?? 0);
            $metadata = $input['metadata'] ?? [];
            
            // Check if we have tracking session
            $token = $_SESSION['email_tracking_token'] ?? null;
            $emailId = $_SESSION['email_tracking_id'] ?? null;
            
            if (!$token || !$emailId) {
                echo json_encode(['ok' => false, 'error' => 'No tracking session']);
                exit;
            }
            
            // Validate conversion type
            $validTypes = ['page_view', 'product_view', 'cart_add', 'checkout_start', 'order_complete', 'ai_chat'];
            if (!in_array($type, $validTypes)) {
                echo json_encode(['ok' => false, 'error' => 'Invalid conversion type']);
                exit;
            }
            
            // Get user ID if logged in
            $userId = current_user_id();
            $orderId = intval($metadata['order_id'] ?? 0) ?: null;
            
            // Insert conversion event
            $stmt = $pdo->prepare("
                INSERT INTO email_conversions 
                (agent_email_id, session_id, tracking_token, user_id, conversion_type, conversion_value, order_id, metadata, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $emailId,
                session_id(),
                $token,
                $userId,
                $type,
                $value,
                $orderId,
                json_encode($metadata)
            ]);
            
            $conversionId = intval($pdo->lastInsertId());
            
            // Update agent_emails with conversion flags
            $pdo->prepare("
                UPDATE agent_emails 
                SET has_conversion = 1, conversion_type = ?
                WHERE id = ?
            ")->execute([$type, $emailId]);
            
            // If order_complete, mark as contributed to sale
            if ($type === 'order_complete' && $orderId) {
                $pdo->prepare("
                    UPDATE agent_emails 
                    SET contributed_to_sale = 1, related_order_id = ?
                    WHERE id = ?
                ")->execute([$orderId, $emailId]);
                
                // Update commission record if exists
                try {
                    $pdo->prepare("
                        UPDATE commissions 
                        SET source_email_id = ?
                        WHERE order_id = ? AND commission_type = 'sales_agent'
                    ")->execute([$emailId, $orderId]);
                } catch (Throwable $e) {
                    // Non-critical
                }
            }
            
            echo json_encode([
                'ok' => true,
                'conversion_id' => $conversionId,
                'type' => $type
            ]);
            break;
            
        case 'get_tracking_session':
            // Get current tracking session info (for debugging/admin)
            header('Content-Type: application/json');
            
            $hasSession = isset($_SESSION['email_tracking_token']);
            
            if ($hasSession) {
                echo json_encode([
                    'ok' => true,
                    'has_session' => true,
                    'email_id' => $_SESSION['email_tracking_id'] ?? null,
                    'agent_id' => $_SESSION['email_agent_id'] ?? null,
                    'started' => $_SESSION['email_tracking_started'] ?? null,
                    'age_seconds' => time() - ($_SESSION['email_tracking_started'] ?? time())
                ]);
            } else {
                echo json_encode([
                    'ok' => true,
                    'has_session' => false
                ]);
            }
            break;
            
        case 'clear_tracking':
            // Clear tracking session (admin/testing)
            unset(
                $_SESSION['email_tracking_token'],
                $_SESSION['email_tracking_id'],
                $_SESSION['email_agent_id'],
                $_SESSION['email_lead_id'],
                $_SESSION['email_account_id'],
                $_SESSION['email_tracking_started']
            );
            
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'cleared' => true]);
            break;
            
        default:
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    error_log("Conversion tracking error: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}





