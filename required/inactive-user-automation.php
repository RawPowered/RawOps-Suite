<?php
/**
 * Inactive User Email Automation System
 * Handles automated email notifications for users who haven't logged in
 * 
 * Schedule: 3, 7, 14, 30, and 35 days of inactivity
 * 
 * Usage: Run this script via cron job daily
 * 0 9 * * * /usr/bin/php /path/to/product.example/required/inactive-user-automation.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/email-triggers.php';

// Logging function
function log_automation($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] [$level] $message" . PHP_EOL;
    
    $log_file = __DIR__ . '/logs/inactive-user-automation.log';
    if (!is_dir(dirname($log_file))) {
        mkdir(dirname($log_file), 0755, true);
    }
    
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    
    // Also output to console if running from command line
    if (php_sapi_name() === 'cli') {
        echo $log_entry;
    }
}

/**
 * Update inactive user tracking data
 */
function update_inactive_tracking() {
    try {
        $pdo = db();
        
        // Call the stored procedure to update tracking
        $pdo->exec('CALL UpdateInactiveUserTracking()');
        
        log_automation('Updated inactive user tracking data');
        return true;
    } catch (Exception $e) {
        log_automation('Error updating inactive tracking: ' . $e->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Get users who need inactive emails sent
 */
function get_users_needing_emails($days, $email_sent_column) {
    try {
        $pdo = db();
        
        $sql = "
            SELECT 
                u.id,
                u.first_name,
                u.last_name,
                u.email,
                uit.days_inactive,
                uit.last_login_at,
                r.name as role_name
            FROM user_inactive_tracking uit
            JOIN users u ON u.id = uit.user_id
            JOIN user_roles ur ON ur.user_id = u.id
            JOIN roles r ON r.id = ur.role_id
            WHERE uit.days_inactive >= ?
                AND uit.{$email_sent_column} = 0
                AND uit.account_suspended = 0
                AND r.name IN ('trainee', 'sales_agent')
                AND u.email IS NOT NULL
                AND u.email != ''
            ORDER BY uit.days_inactive DESC, u.last_name, u.first_name
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$days]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        log_automation("Error getting users for {$days}-day emails: " . $e->getMessage(), 'ERROR');
        return [];
    }
}

/**
 * Mark email as sent for a user
 */
function mark_email_sent($user_id, $email_sent_column) {
    try {
        $pdo = db();
        
        $sql = "UPDATE user_inactive_tracking 
                SET {$email_sent_column} = 1, 
                    {$email_sent_column}_at = NOW(),
                    updated_at = NOW()
                WHERE user_id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id]);
        
        return true;
    } catch (Exception $e) {
        log_automation("Error marking email sent for user {$user_id}: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Send inactive user email
 */
function send_inactive_email($user, $event_key, $email_sent_column) {
    try {
        // Trigger the email event
        $result = trigger_email_event($event_key, $user['id'], [
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'email' => $user['email'],
            'days_inactive' => $user['days_inactive'],
            'last_login_at' => $user['last_login_at'],
            'role_name' => $user['role_name'],
            'login_url' => outbound_path('/login.php')
        ]);
        
        if ($result['success']) {
            // Mark email as sent
            if (mark_email_sent($user['id'], $email_sent_column)) {
                log_automation("Sent {$event_key} email to {$user['email']} (User ID: {$user['id']})");
                return true;
            }
        } else {
            log_automation("Failed to send {$event_key} email to {$user['email']}: " . ($result['error'] ?? 'Unknown error'), 'ERROR');
        }
        
        return false;
    } catch (Exception $e) {
        log_automation("Error sending {$event_key} email to {$user['email']}: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Process inactive users for a specific day threshold
 */
function process_inactive_users($days, $event_key, $email_sent_column) {
    log_automation("Processing {$days}-day inactive users...");
    
    $users = get_users_needing_emails($days, $email_sent_column);
    $sent_count = 0;
    $error_count = 0;
    
    foreach ($users as $user) {
        // Additional check: make sure user hasn't logged in recently
        // (in case tracking update hasn't run yet)
        $actual_days_inactive = calculate_actual_days_inactive($user['id']);
        
        if ($actual_days_inactive < $days) {
            log_automation("Skipping user {$user['id']} - actual days inactive ({$actual_days_inactive}) less than threshold ({$days})");
            continue;
        }
        
        if (send_inactive_email($user, $event_key, $email_sent_column)) {
            $sent_count++;
        } else {
            $error_count++;
        }
        
        // Small delay to avoid overwhelming the email system
        usleep(100000); // 0.1 second delay
    }
    
    log_automation("Completed {$days}-day processing: {$sent_count} emails sent, {$error_count} errors");
    return ['sent' => $sent_count, 'errors' => $error_count];
}

/**
 * Calculate actual days inactive for a user
 */
function calculate_actual_days_inactive($user_id) {
    try {
        $pdo = db();
        
        $stmt = $pdo->prepare("
            SELECT 
                CASE 
                    WHEN last_login_at IS NULL THEN DATEDIFF(NOW(), created_at)
                    ELSE DATEDIFF(NOW(), last_login_at)
                END as days_inactive
            FROM users 
            WHERE id = ?
        ");
        
        $stmt->execute([$user_id]);
        $result = $stmt->fetchColumn();
        
        return intval($result);
    } catch (Exception $e) {
        log_automation("Error calculating days inactive for user {$user_id}: " . $e->getMessage(), 'ERROR');
        return 0;
    }
}

/**
 * Suspend users who have been inactive for 35+ days
 */
function suspend_inactive_users() {
    try {
        $pdo = db();
        
        // Get users who need to be suspended
        $stmt = $pdo->prepare("
            SELECT u.id, u.first_name, u.last_name, u.email, uit.days_inactive
            FROM user_inactive_tracking uit
            JOIN users u ON u.id = uit.user_id
            JOIN user_roles ur ON ur.user_id = u.id
            JOIN roles r ON r.id = ur.role_id
            WHERE uit.days_inactive >= 35
                AND uit.account_suspended = 0
                AND r.name IN ('trainee', 'sales_agent')
        ");
        
        $stmt->execute();
        $users_to_suspend = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $suspended_count = 0;
        
        foreach ($users_to_suspend as $user) {
            // Send suspension email first
            $email_sent = send_inactive_email($user, 'user_inactive_35_days', 'email_35_day_sent');
            
            // Mark as suspended in tracking table
            $suspend_stmt = $pdo->prepare("
                UPDATE user_inactive_tracking 
                SET account_suspended = 1,
                    account_suspended_at = NOW(),
                    pending_review = 0,
                    updated_at = NOW()
                WHERE user_id = ?
            ");
            
            if ($suspend_stmt->execute([$user['id']])) {
                log_automation("Suspended user {$user['id']} ({$user['email']}) for {$user['days_inactive']} days inactivity");
                $suspended_count++;
            } else {
                log_automation("Failed to suspend user {$user['id']} ({$user['email']})", 'ERROR');
            }
        }
        
        log_automation("Suspended {$suspended_count} users for 35+ days inactivity");
        return $suspended_count;
        
    } catch (Exception $e) {
        log_automation("Error suspending inactive users: " . $e->getMessage(), 'ERROR');
        return 0;
    }
}

/**
 * Main automation function
 */
function run_inactive_user_automation() {
    log_automation("=== Starting Inactive User Email Automation ===");
    
    $start_time = microtime(true);
    $total_sent = 0;
    $total_errors = 0;
    
    // Update tracking data first
    if (!update_inactive_tracking()) {
        log_automation("Failed to update tracking data, aborting automation", 'ERROR');
        return false;
    }
    
    // Process each email threshold
    $email_configs = [
        ['days' => 3, 'event' => 'user_inactive_3_days', 'column' => 'email_3_day_sent'],
        ['days' => 7, 'event' => 'user_inactive_7_days', 'column' => 'email_7_day_sent'],
        ['days' => 14, 'event' => 'user_inactive_14_days', 'column' => 'email_14_day_sent'],
        ['days' => 30, 'event' => 'user_inactive_30_days', 'column' => 'email_30_day_sent']
    ];
    
    foreach ($email_configs as $config) {
        $result = process_inactive_users($config['days'], $config['event'], $config['column']);
        $total_sent += $result['sent'];
        $total_errors += $result['errors'];
    }
    
    // Handle suspensions (35+ days)
    $suspended_count = suspend_inactive_users();
    
    $end_time = microtime(true);
    $execution_time = round($end_time - $start_time, 2);
    
    log_automation("=== Automation Complete ===");
    log_automation("Total emails sent: {$total_sent}");
    log_automation("Total errors: {$total_errors}");
    log_automation("Users suspended: {$suspended_count}");
    log_automation("Execution time: {$execution_time} seconds");
    
    return [
        'success' => true,
        'emails_sent' => $total_sent,
        'errors' => $total_errors,
        'suspended' => $suspended_count,
        'execution_time' => $execution_time
    ];
}

// Run automation if called directly (from command line or cron)
if (php_sapi_name() === 'cli' || (isset($_GET['run_automation']) && $_GET['run_automation'] === 'true')) {
    // Verify admin access if called via web
    if (php_sapi_name() !== 'cli') {
        start_session();
        if (!current_admin_id()) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            exit;
        }
    }
    
    $result = run_inactive_user_automation();
    
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json');
        echo json_encode($result);
    }
}
