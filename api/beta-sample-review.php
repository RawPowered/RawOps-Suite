<?php
/**
 * Beta Sample Review API
 * 
 * Handles beta sample review submissions from customers
 */

require_once __DIR__ . '/../required/config.php';

header('Content-Type: application/json');

try {
    start_session();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Session initialization failed']);
    exit;
}

$pdo = db();
$userId = current_user_id();

// Require authentication
if (!$userId) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'You must be logged in to submit a review']);
    exit;
}

// Only handle POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    // Get input
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    
    // ============================================
    // 1. VALIDATE INPUT
    // ============================================
    
    $sampleRequestId = intval($input['sample_request_id'] ?? 0);
    $productId = intval($input['product_id'] ?? 0);
    $rating = intval($input['rating'] ?? 0);
    $title = trim($input['title'] ?? '');
    $reviewText = trim($input['review_text'] ?? '');
    $tasteRating = !empty($input['taste_rating']) ? intval($input['taste_rating']) : null;
    $clarityRating = !empty($input['clarity_rating']) ? intval($input['clarity_rating']) : null;
    $effectsRating = !empty($input['effects_rating']) ? intval($input['effects_rating']) : null;
    $wouldPurchase = !empty($input['would_purchase']);
    
    // Validate required fields
    if (!$sampleRequestId || !$productId) {
        throw new Exception('Invalid sample request or product');
    }
    
    if ($rating < 1 || $rating > 5) {
        throw new Exception('Overall rating must be between 1 and 5 stars');
    }
    
    if (empty($reviewText)) {
        throw new Exception('Review text is required');
    }
    
    if (strlen($reviewText) < 20) {
        throw new Exception('Review must be at least 20 characters');
    }
    
    // Validate detail ratings if provided
    if ($tasteRating !== null && ($tasteRating < 1 || $tasteRating > 5)) {
        throw new Exception('Taste rating must be between 1 and 5 stars');
    }
    
    if ($clarityRating !== null && ($clarityRating < 1 || $clarityRating > 5)) {
        throw new Exception('Clarity rating must be between 1 and 5 stars');
    }
    
    if ($effectsRating !== null && ($effectsRating < 1 || $effectsRating > 5)) {
        throw new Exception('Effects rating must be between 1 and 5 stars');
    }
    
    // ============================================
    // 2. VERIFY SAMPLE REQUEST BELONGS TO USER
    // ============================================
    
    $stmt = $pdo->prepare('
        SELECT id FROM sample_requests
        WHERE id = ? AND agent_id = ? AND is_beta_sample = 1 AND status IN ("shipped", "delivered")
        LIMIT 1
    ');
    $stmt->execute([$sampleRequestId, $userId]);
    $validRequest = $stmt->fetch();
    
    if (!$validRequest) {
        throw new Exception('Invalid sample request. You must have received a shipped beta sample to leave a review.');
    }
    
    // ============================================
    // 3. CHECK IF REVIEW ALREADY EXISTS
    // ============================================
    
    $stmt = $pdo->prepare('
        SELECT id FROM beta_sample_reviews
        WHERE sample_request_id = ? AND user_id = ?
    ');
    $stmt->execute([$sampleRequestId, $userId]);
    $existingReview = $stmt->fetch();
    
    if ($existingReview) {
        throw new Exception('You have already submitted a review for this sample');
    }
    
    // ============================================
    // 4. CREATE REVIEW
    // ============================================
    
    $pdo->beginTransaction();
    
    try {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        
        $stmt = $pdo->prepare('
            INSERT INTO beta_sample_reviews (
                sample_request_id,
                user_id,
                product_id,
                rating,
                title,
                review_text,
                taste_rating,
                clarity_rating,
                effects_rating,
                would_purchase,
                status,
                ip_address,
                user_agent,
                created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?, ?, NOW()
            )
        ');
        
        $stmt->execute([
            $sampleRequestId,
            $userId,
            $productId,
            $rating,
            $title ?: null,
            $reviewText,
            $tasteRating,
            $clarityRating,
            $effectsRating,
            $wouldPurchase ? 1 : 0,
            $ipAddress,
            $userAgent
        ]);
        
        $reviewId = (int)$pdo->lastInsertId();
        
        $pdo->commit();
        
        // ============================================
        // 5. RETURN SUCCESS
        // ============================================
        
        echo json_encode([
            'ok' => true,
            'message' => 'Review submitted successfully! Thank you for your feedback.',
            'review_id' => $reviewId
        ]);
        
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}



