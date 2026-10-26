<?php
/**
 * Sales Agent Feedback Submission API
 * Routes feedback to admin_dev_tasks as "Feedback" category
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

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$category = trim($input['category'] ?? '');
$title = trim($input['title'] ?? '');
$description = trim($input['description'] ?? '');
$pageUrl = trim($input['page_url'] ?? '');
$browserInfo = trim($input['browser_info'] ?? '');
$deviceInfo = trim($input['device_info'] ?? '');

// Handle screenshot upload
$screenshotPath = null;
$screenshotUrl = null;
if (isset($_FILES['screenshot']) && $_FILES['screenshot']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = __DIR__ . '/../uploads/feedback/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $ext = strtolower(pathinfo($_FILES['screenshot']['name'], PATHINFO_EXTENSION));
    $allowedExts = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
    
    if (!in_array($ext, $allowedExts)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid file type. Only PNG, JPG, GIF, and WebP allowed.']);
        exit;
    }
    
    // Check file size (5MB max)
    if ($_FILES['screenshot']['size'] > 5 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'File too large. Maximum 5MB.']);
        exit;
    }
    
    $filename = 'feedback_' . uniqid() . '_' . time() . '.' . $ext;
    $screenshotPath = $uploadDir . $filename;
    $screenshotUrl = '/uploads/feedback/' . $filename;
    
    if (!move_uploaded_file($_FILES['screenshot']['tmp_name'], $screenshotPath)) {
        error_log("Failed to move uploaded file to: " . $screenshotPath);
        $screenshotPath = null;
        $screenshotUrl = null;
    }
}

// Validate required fields
if (empty($category) || empty($title) || empty($description)) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => 'Category, title, and description are required'
    ]);
    exit;
}

// Validate category
$validCategories = [
    'Bug Report',
    'Feature Request', 
    'General Feedback',
    'UX Improvement',
    'Performance Issue',
    'Documentation Request'
];

if (!in_array($category, $validCategories)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid category']);
    exit;
}

try {
    $pdo = db();
    
    // Get user info for context
    $stmt = $pdo->prepare('SELECT first_name, last_name, email FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$uid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $userName = ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '');
    $userEmail = $user['email'] ?? '';
    
    // Find default admin to assign feedback to (first admin user)
    $stmt = $pdo->query('
        SELECT u.id 
        FROM users u 
        JOIN user_roles ur ON ur.user_id = u.id 
        JOIN roles r ON r.id = ur.role_id 
        WHERE r.name = "admin" 
        ORDER BY u.id ASC 
        LIMIT 1
    ');
    $defaultAdmin = $stmt->fetch(PDO::FETCH_ASSOC);
    $assignedToAdminId = $defaultAdmin['id'] ?? 1; // Fallback to user ID 1
    
    // Set priority based on category
    $priority = match($category) {
        'Bug Report', 'Performance Issue' => 'high',
        'Feature Request', 'UX Improvement' => 'medium',
        default => 'medium'
    };
    
    // Build comprehensive description with context
    $enhancedDescription = "**User Feedback from Sales Agent**\n\n";
    $enhancedDescription .= "**Submitted by:** {$userName} ({$userEmail})\n";
    $enhancedDescription .= "**Category:** {$category}\n";
    if ($pageUrl) {
        $enhancedDescription .= "**Page URL:** {$pageUrl}\n";
    }
    if ($screenshotUrl) {
        $fullUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'product.example') . $screenshotUrl;
        $enhancedDescription .= "**Screenshot:** [{$fullUrl}]({$fullUrl})\n";
    }
    if ($browserInfo) {
        $enhancedDescription .= "**Browser:** {$browserInfo}\n";
    }
    if ($deviceInfo) {
        $enhancedDescription .= "**Device:** {$deviceInfo}\n";
    }
    $enhancedDescription .= "\n---\n\n";
    $enhancedDescription .= "**Feedback:**\n{$description}";
    
    // Insert into admin_dev_tasks
    $stmt = $pdo->prepare('
        INSERT INTO admin_dev_tasks 
        (title, description, priority, category, created_by_admin_id, assigned_to_admin_id, status)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    
    $feedbackTitle = "[{$category}] {$title}";
    
    $stmt->execute([
        $feedbackTitle,
        $enhancedDescription,
        $priority,
        'Feedback',
        $uid, // Created by the sales agent
        $assignedToAdminId,
        'pending'
    ]);
    
    $taskId = $pdo->lastInsertId();
    
    // Log to task history
    $stmt = $pdo->prepare('
        INSERT INTO admin_dev_task_history 
        (task_id, admin_id, action_type, action_description, new_values, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    
    $stmt->execute([
        $taskId,
        $uid,
        'create',
        'Feedback submitted via Sales ACP',
        json_encode(['category' => $category, 'page_url' => $pageUrl]),
        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 500)
    ]);
    
    // Mark page as having received feedback
    if ($pageUrl) {
        $parsedUrl = parse_url($pageUrl);
        $normalizedUrl = $parsedUrl['path'] ?? $pageUrl;
        $normalizedUrl = substr($normalizedUrl, 0, 500);
        
        $stmt = $pdo->prepare('
            UPDATE sales_agent_page_visits 
            SET has_given_feedback = 1 
            WHERE user_id = ? AND page_url = ?
        ');
        $stmt->execute([$uid, $normalizedUrl]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Thank you! Your feedback has been submitted to the development team.',
        'task_id' => $taskId
    ]);
    
} catch (Exception $e) {
    error_log("Feedback submission error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to submit feedback. Please try again.'
    ]);
}


