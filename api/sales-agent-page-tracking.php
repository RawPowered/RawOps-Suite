<?php
/**
 * Sales Agent Page Visit Tracking API
 * Tracks page visits and returns feedback prompt status
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../required/config.php';

// Ensure user is authenticated
start_session();
$uid = current_user_id();

if (!$uid) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get page URL from request
$pageUrl = $_POST['page_url'] ?? $_GET['page_url'] ?? '';

if (empty($pageUrl)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Page URL is required']);
    exit;
}

// Sanitize and normalize page URL (remove query strings for consistency)
$parsedUrl = parse_url($pageUrl);
$normalizedUrl = $parsedUrl['path'] ?? $pageUrl;
$normalizedUrl = substr($normalizedUrl, 0, 500); // Limit to VARCHAR size

try {
    $pdo = db();
    
    // Check if visit record exists
    $stmt = $pdo->prepare('
        SELECT id, visit_count, has_given_feedback 
        FROM sales_agent_page_visits 
        WHERE user_id = ? AND page_url = ?
        LIMIT 1
    ');
    $stmt->execute([$uid, $normalizedUrl]);
    $existingVisit = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingVisit) {
        // Update existing record
        $newVisitCount = intval($existingVisit['visit_count']) + 1;
        $hasFeedback = intval($existingVisit['has_given_feedback']) === 1;
        
        $stmt = $pdo->prepare('
            UPDATE sales_agent_page_visits 
            SET visit_count = ?, last_visited_at = NOW() 
            WHERE id = ?
        ');
        $stmt->execute([$newVisitCount, $existingVisit['id']]);
        
    } else {
        // Create new record
        $stmt = $pdo->prepare('
            INSERT INTO sales_agent_page_visits (user_id, page_url, visit_count, has_given_feedback)
            VALUES (?, ?, 1, 0)
        ');
        $stmt->execute([$uid, $normalizedUrl]);
        
        $newVisitCount = 1;
        $hasFeedback = false;
    }
    
    // Determine if we should show the prompt
    $shouldShowPrompt = ($newVisitCount >= 5) && !$hasFeedback;
    
    echo json_encode([
        'success' => true,
        'visit_count' => $newVisitCount,
        'has_given_feedback' => $hasFeedback,
        'should_show_prompt' => $shouldShowPrompt
    ]);
    
} catch (Exception $e) {
    error_log("Page tracking error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Failed to track page visit'
    ]);
}


