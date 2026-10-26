<?php
/**
 * API endpoints for inactive user management
 * Provides admin interface for managing inactive user automation
 */

require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/inactive-user-automation.php';

header('Content-Type: application/json');

// Require admin access
start_session();
if (!current_admin_id()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin access required']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$pdo = db();

try {
    switch ($action) {
        case 'get_inactive_stats':
            // Get inactive user statistics
            $stats_query = "
                SELECT 
                    COUNT(*) as total_tracked,
                    SUM(CASE WHEN days_inactive >= 3 AND days_inactive < 7 THEN 1 ELSE 0 END) as inactive_3_days,
                    SUM(CASE WHEN days_inactive >= 7 AND days_inactive < 14 THEN 1 ELSE 0 END) as inactive_7_days,
                    SUM(CASE WHEN days_inactive >= 14 AND days_inactive < 30 THEN 1 ELSE 0 END) as inactive_14_days,
                    SUM(CASE WHEN days_inactive >= 30 AND days_inactive < 35 THEN 1 ELSE 0 END) as inactive_30_days,
                    SUM(CASE WHEN days_inactive >= 35 THEN 1 ELSE 0 END) as suspended_35_days,
                    SUM(CASE WHEN pending_review = 1 THEN 1 ELSE 0 END) as pending_review,
                    SUM(CASE WHEN account_suspended = 1 THEN 1 ELSE 0 END) as total_suspended,
                    SUM(CASE WHEN email_3_day_sent = 1 THEN 1 ELSE 0 END) as emails_3_day_sent,
                    SUM(CASE WHEN email_7_day_sent = 1 THEN 1 ELSE 0 END) as emails_7_day_sent,
                    SUM(CASE WHEN email_14_day_sent = 1 THEN 1 ELSE 0 END) as emails_14_day_sent,
                    SUM(CASE WHEN email_30_day_sent = 1 THEN 1 ELSE 0 END) as emails_30_day_sent,
                    SUM(CASE WHEN email_35_day_sent = 1 THEN 1 ELSE 0 END) as emails_35_day_sent
                FROM user_inactive_tracking uit
                JOIN users u ON u.id = uit.user_id
                JOIN user_roles ur ON ur.user_id = u.id
                JOIN roles r ON r.id = ur.role_id
                WHERE r.name IN ('trainee', 'sales_agent')
            ";
            
            $stats = $pdo->query($stats_query)->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'ok' => true,
                'stats' => $stats
            ]);
            break;
            
        case 'get_inactive_users':
            $limit = intval($_GET['limit'] ?? 50);
            $offset = intval($_GET['offset'] ?? 0);
            $filter = $_GET['filter'] ?? 'all'; // all, pending_review, suspended, needs_email
            
            $where_conditions = ["r.name IN ('trainee', 'sales_agent')"];
            
            switch ($filter) {
                case 'pending_review':
                    $where_conditions[] = "uit.pending_review = 1";
                    break;
                case 'suspended':
                    $where_conditions[] = "uit.account_suspended = 1";
                    break;
                case 'needs_email':
                    $where_conditions[] = "(
                        (uit.days_inactive >= 3 AND uit.email_3_day_sent = 0) OR
                        (uit.days_inactive >= 7 AND uit.email_7_day_sent = 0) OR
                        (uit.days_inactive >= 14 AND uit.email_14_day_sent = 0) OR
                        (uit.days_inactive >= 30 AND uit.email_30_day_sent = 0) OR
                        (uit.days_inactive >= 35 AND uit.email_35_day_sent = 0)
                    )";
                    break;
                case 'inactive_7_days':
                    $where_conditions[] = "uit.days_inactive >= 7";
                    break;
            }
            
            $where_clause = implode(' AND ', $where_conditions);
            
            $users_query = "
                SELECT 
                    u.id,
                    u.first_name,
                    u.last_name,
                    u.email,
                    u.created_at,
                    u.last_login_at,
                    r.name as role_name,
                    uit.days_inactive,
                    uit.pending_review,
                    uit.pending_review_since,
                    uit.account_suspended,
                    uit.account_suspended_at,
                    uit.email_3_day_sent,
                    uit.email_3_day_sent_at,
                    uit.email_7_day_sent,
                    uit.email_7_day_sent_at,
                    uit.email_14_day_sent,
                    uit.email_14_day_sent_at,
                    uit.email_30_day_sent,
                    uit.email_30_day_sent_at,
                    uit.email_35_day_sent,
                    uit.email_35_day_sent_at,
                    CASE 
                        WHEN uit.days_inactive >= 35 THEN '35+ days'
                        WHEN uit.days_inactive >= 30 THEN '30+ days'
                        WHEN uit.days_inactive >= 14 THEN '14+ days'
                        WHEN uit.days_inactive >= 7 THEN '7+ days'
                        WHEN uit.days_inactive >= 3 THEN '3+ days'
                        ELSE 'Active'
                    END as inactive_status
                FROM user_inactive_tracking uit
                JOIN users u ON u.id = uit.user_id
                JOIN user_roles ur ON ur.user_id = u.id
                JOIN roles r ON r.id = ur.role_id
                WHERE {$where_clause}
                ORDER BY uit.days_inactive DESC, u.last_name, u.first_name
                LIMIT ? OFFSET ?
            ";
            
            $stmt = $pdo->prepare($users_query);
            $stmt->execute([$limit, $offset]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total count
            $count_query = "
                SELECT COUNT(*) 
                FROM user_inactive_tracking uit
                JOIN users u ON u.id = uit.user_id
                JOIN user_roles ur ON ur.user_id = u.id
                JOIN roles r ON r.id = ur.role_id
                WHERE {$where_clause}
            ";
            
            $total_count = $pdo->query($count_query)->fetchColumn();
            
            echo json_encode([
                'ok' => true,
                'users' => $users,
                'total_count' => intval($total_count),
                'limit' => $limit,
                'offset' => $offset
            ]);
            break;
            
        case 'reactivate_user':
            $user_id = intval($_POST['user_id'] ?? 0);
            if (!$user_id) {
                throw new Exception('User ID required');
            }
            
            // Reset user's inactive tracking
            $stmt = $pdo->prepare("
                UPDATE user_inactive_tracking 
                SET 
                    account_suspended = 0,
                    account_suspended_at = NULL,
                    pending_review = 0,
                    pending_review_since = NULL,
                    email_3_day_sent = 0,
                    email_3_day_sent_at = NULL,
                    email_7_day_sent = 0,
                    email_7_day_sent_at = NULL,
                    email_14_day_sent = 0,
                    email_14_day_sent_at = NULL,
                    email_30_day_sent = 0,
                    email_30_day_sent_at = NULL,
                    email_35_day_sent = 0,
                    email_35_day_sent_at = NULL,
                    updated_at = NOW()
                WHERE user_id = ?
            ");
            
            if ($stmt->execute([$user_id])) {
                // Update user's last login to now to reset their inactive status
                $login_stmt = $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
                $login_stmt->execute([$user_id]);
                
                echo json_encode([
                    'ok' => true,
                    'message' => 'User reactivated successfully'
                ]);
            } else {
                throw new Exception('Failed to reactivate user');
            }
            break;
            
        case 'send_manual_email':
            $user_id = intval($_POST['user_id'] ?? 0);
            $email_type = $_POST['email_type'] ?? '';
            
            if (!$user_id || !$email_type) {
                throw new Exception('User ID and email type required');
            }
            
            // Get user data
            $user_stmt = $pdo->prepare("
                SELECT u.*, uit.days_inactive, r.name as role_name
                FROM users u
                JOIN user_inactive_tracking uit ON uit.user_id = u.id
                JOIN user_roles ur ON ur.user_id = u.id
                JOIN roles r ON r.id = ur.role_id
                WHERE u.id = ?
            ");
            $user_stmt->execute([$user_id]);
            $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception('User not found');
            }
            
            // Map email types to event keys
            $email_events = [
                '3_day' => 'user_inactive_3_days',
                '7_day' => 'user_inactive_7_days',
                '14_day' => 'user_inactive_14_days',
                '30_day' => 'user_inactive_30_days',
                '35_day' => 'user_inactive_35_days'
            ];
            
            if (!isset($email_events[$email_type])) {
                throw new Exception('Invalid email type');
            }
            
            // Send the email using the automation function
            require_once __DIR__ . '/../required/email-triggers.php';
            
            $result = trigger_email_event($email_events[$email_type], $user['id'], [
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'email' => $user['email'],
                'days_inactive' => $user['days_inactive'],
                'last_login_at' => $user['last_login_at'],
                'role_name' => $user['role_name'],
                'login_url' => outbound_path('/login.php')
            ]);
            
            if ($result['success']) {
                echo json_encode([
                    'ok' => true,
                    'message' => 'Email sent successfully'
                ]);
            } else {
                throw new Exception('Failed to send email: ' . ($result['error'] ?? 'Unknown error'));
            }
            break;
            
        case 'run_automation':
            // Run the automation manually
            $result = run_inactive_user_automation();
            
            echo json_encode([
                'ok' => true,
                'result' => $result
            ]);
            break;
            
        case 'update_tracking':
            // Update tracking data manually
            if (update_inactive_tracking()) {
                echo json_encode([
                    'ok' => true,
                    'message' => 'Tracking data updated successfully'
                ]);
            } else {
                throw new Exception('Failed to update tracking data');
            }
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
