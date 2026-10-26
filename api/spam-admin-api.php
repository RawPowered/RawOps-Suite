<?php
/**
 * Spam Management Admin API
 * Handles bulk spam actions and blacklist management
 */

require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/spam-detector.php';
require_once __DIR__ . '/../required/spam_prevention_utils.php';
require_once __DIR__ . '/../required/admin_user_utils.php';

header('Content-Type: application/json');

// Require admin authentication
start_session();
$adminId = current_admin_id();
if (!$adminId) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin access required']);
    exit;
}

$action = $_GET['action'] ?? '';
$pdo = db();

try {
    // Mark users as spam and add to blacklist
    if ($action === 'mark_users_as_spam') {
        $input = json_decode(file_get_contents('php://input'), true);
        $userIds = $input['user_ids'] ?? [];
        $deleteUsers = $input['delete_users'] ?? true; // Default to true per user preference
        
        if (empty($userIds) || !is_array($userIds)) {
            throw new Exception('No user IDs provided');
        }
        
        $markedCount = 0;
        $totalBlacklistedEntries = 0;
        $deletedCount = 0;
        $emailsBlacklisted = 0;
        $phonesBlacklisted = 0;
        $namesBlacklisted = 0;
        $ipsBlacklisted = 0;
        $errors = [];
        
        foreach ($userIds as $userId) {
            $userId = intval($userId);
            if ($userId <= 0) continue;
            
            try {
                // Add comprehensive blacklist entries
                $blacklistResult = add_to_comprehensive_blacklist(
                    $pdo, 
                    $userId, 
                    'Marked as spam by admin (ID: ' . $adminId . ')', 
                    $adminId
                );
                
                if ($blacklistResult['success']) {
                    $markedCount++;
                    $totalBlacklistedEntries += $blacklistResult['entries_added'];
                    
                    // Count by type
                    foreach ($blacklistResult['details'] as $detail) {
                        if (strpos($detail, 'Email:') === 0) $emailsBlacklisted++;
                        if (strpos($detail, 'Phone:') === 0) $phonesBlacklisted++;
                        if (strpos($detail, 'Name:') === 0) $namesBlacklisted++;
                        if (strpos($detail, 'IP:') === 0) $ipsBlacklisted++;
                    }
                    
                    // Delete the user if requested
                    if ($deleteUsers) {
                        $deleteResult = admin_delete_user($pdo, $userId);
                        if ($deleteResult['ok']) {
                            $deletedCount++;
                        }
                    }
                } else {
                    $errors[] = "User ID $userId: " . ($blacklistResult['error'] ?? 'Unknown error');
                }
                
            } catch (Exception $e) {
                $errors[] = "User ID $userId: " . $e->getMessage();
            }
        }
        
        echo json_encode([
            'ok' => true,
            'count' => $markedCount,
            'blacklisted_entries' => $totalBlacklistedEntries,
            'emails_blacklisted' => $emailsBlacklisted,
            'phones_blacklisted' => $phonesBlacklisted,
            'names_blacklisted' => $namesBlacklisted,
            'ips_blacklisted' => $ipsBlacklisted,
            'users_deleted' => $deletedCount,
            'errors' => $errors,
            'message' => "Marked {$markedCount} user(s) as spam with {$totalBlacklistedEntries} blacklist entries" . 
                         ($deletedCount > 0 ? " and deleted {$deletedCount} accounts" : "")
        ]);
        exit;
    }
    
    // Add single blacklist entry
    elseif ($action === 'add_blacklist_entry') {
        $input = json_decode(file_get_contents('php://input'), true);
        $entryType = $input['entry_type'] ?? '';
        $entryValue = trim($input['entry_value'] ?? '');
        $reason = trim($input['reason'] ?? '');
        
        if (empty($entryType) || empty($entryValue)) {
            throw new Exception('Entry type and value are required');
        }
        
        $validTypes = ['email', 'domain', 'name_pattern', 'phone', 'ip_address'];
        if (!in_array($entryType, $validTypes)) {
            throw new Exception('Invalid entry type');
        }
        
        $stmt = $pdo->prepare('
            INSERT INTO spam_blacklist (entry_type, entry_value, reason, added_by_admin_id, is_active)
            VALUES (?, ?, ?, ?, 1)
        ');
        $stmt->execute([$entryType, $entryValue, $reason, $adminId]);
        
        echo json_encode([
            'ok' => true,
            'id' => $pdo->lastInsertId(),
            'message' => 'Blacklist entry added successfully'
        ]);
        exit;
    }
    
    // Remove blacklist entry
    elseif ($action === 'remove_blacklist_entry') {
        $input = json_decode(file_get_contents('php://input'), true);
        $entryId = intval($input['entry_id'] ?? 0);
        
        if ($entryId <= 0) {
            throw new Exception('Invalid entry ID');
        }
        
        $stmt = $pdo->prepare('UPDATE spam_blacklist SET is_active = 0 WHERE id = ?');
        $stmt->execute([$entryId]);
        
        echo json_encode([
            'ok' => true,
            'message' => 'Blacklist entry removed'
        ]);
        exit;
    }
    
    // Get spam attempts with pagination
    elseif ($action === 'get_spam_attempts') {
        $page = intval($_GET['page'] ?? 1);
        $limit = intval($_GET['limit'] ?? 50);
        $offset = ($page - 1) * $limit;
        
        $stmt = $pdo->prepare('
            SELECT * FROM spam_registration_attempts 
            ORDER BY blocked_at DESC 
            LIMIT ? OFFSET ?
        ');
        $stmt->execute([$limit, $offset]);
        $attempts = $stmt->fetchAll();
        
        $totalStmt = $pdo->query('SELECT COUNT(*) FROM spam_registration_attempts');
        $total = $totalStmt->fetchColumn();
        
        echo json_encode([
            'ok' => true,
            'attempts' => $attempts,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / $limit)
        ]);
        exit;
    }
    
    else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}

