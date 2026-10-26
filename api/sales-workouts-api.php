<?php
/**
 * Sales Workouts API
 * Handles flash card deck and card management
 */

require_once __DIR__ . '/../required/config.php';
header('Content-Type: application/json');

$pdo = db();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    if ($action === 'get_cards') {
        $deckId = intval($_GET['deck_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT 
                swc.*,
                p.name as product_name,
                p.sku as product_sku
            FROM sales_workout_cards swc
            LEFT JOIN products p ON p.id = swc.product_id
            WHERE swc.deck_id = ?
            ORDER BY swc.sort_order ASC, swc.id ASC
        ");
        $stmt->execute([$deckId]);
        $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['ok' => true, 'cards' => $cards]);
    }
    elseif ($action === 'get_card') {
        $cardId = intval($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM sales_workout_cards WHERE id = ?");
        $stmt->execute([$cardId]);
        $card = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode(['ok' => true, 'card' => $card]);
    }
    elseif ($action === 'get_deck_for_workout') {
        $deckId = intval($_GET['deck_id'] ?? 0);
        $userId = current_user_id();
        
        // Get deck info
        $stmt = $pdo->prepare("SELECT * FROM sales_workout_decks WHERE id = ? AND active = 1");
        $stmt->execute([$deckId]);
        $deck = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$deck) {
            throw new Exception('Deck not found or inactive');
        }
        
        // Get cards with user progress
        $stmt = $pdo->prepare("
            SELECT 
                swc.*,
                p.name as product_name,
                swp.confidence_level,
                swp.review_count,
                swp.last_reviewed_at
            FROM sales_workout_cards swc
            LEFT JOIN products p ON p.id = swc.product_id
            LEFT JOIN sales_workout_progress swp ON swp.card_id = swc.id AND swp.user_id = ?
            WHERE swc.deck_id = ? AND swc.active = 1
            ORDER BY swc.sort_order ASC, swc.id ASC
        ");
        $stmt->execute([$userId, $deckId]);
        $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['ok' => true, 'deck' => $deck, 'cards' => $cards]);
    }
    elseif ($action === 'update_card_progress') {
        $userId = current_user_id();
        $cardId = intval($_POST['card_id'] ?? 0);
        $deckId = intval($_POST['deck_id'] ?? 0);
        $confidenceLevel = intval($_POST['confidence_level'] ?? 1);
        $wasCorrect = isset($_POST['correct']) ? intval($_POST['correct']) : null;
        
        // Calculate next review date based on confidence (spaced repetition)
        $intervals = [1, 3, 7, 14, 30]; // days
        $nextReviewDays = $intervals[min($confidenceLevel - 1, 4)];
        $nextReview = date('Y-m-d H:i:s', strtotime("+{$nextReviewDays} days"));
        
        $stmt = $pdo->prepare("
            INSERT INTO sales_workout_progress (user_id, deck_id, card_id, confidence_level, review_count, correct_count, last_reviewed_at, next_review_at, created_at)
            VALUES (?, ?, ?, ?, 1, ?, NOW(), ?, NOW())
            ON DUPLICATE KEY UPDATE
                confidence_level = VALUES(confidence_level),
                review_count = review_count + 1,
                correct_count = correct_count + ?,
                last_reviewed_at = NOW(),
                next_review_at = VALUES(next_review_at)
        ");
        $stmt->execute([$userId, $deckId, $cardId, $confidenceLevel, $wasCorrect ? 1 : 0, $nextReview, $wasCorrect ? 1 : 0]);
        
        echo json_encode(['ok' => true, 'next_review' => $nextReview]);
    }
    elseif ($action === 'start_session') {
        $userId = current_user_id();
        $deckId = intval($_POST['deck_id'] ?? 0);
        $sessionType = $_POST['session_type'] ?? 'full_deck';
        
        $stmt = $pdo->prepare("INSERT INTO sales_workout_sessions (user_id, deck_id, session_type, started_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$userId, $deckId, $sessionType]);
        $sessionId = $pdo->lastInsertId();
        
        echo json_encode(['ok' => true, 'session_id' => $sessionId]);
    }
    elseif ($action === 'end_session') {
        $sessionId = intval($_POST['session_id'] ?? 0);
        $cardsReviewed = intval($_POST['cards_reviewed'] ?? 0);
        $cardsCorrect = intval($_POST['cards_correct'] ?? 0);
        $avgConfidenceBefore = floatval($_POST['avg_confidence_before'] ?? 0);
        $avgConfidenceAfter = floatval($_POST['avg_confidence_after'] ?? 0);
        $totalTimeSeconds = intval($_POST['total_time_seconds'] ?? 0);
        
        $stmt = $pdo->prepare("
            UPDATE sales_workout_sessions SET
                completed_at = NOW(),
                cards_reviewed = ?,
                cards_correct = ?,
                avg_confidence_before = ?,
                avg_confidence_after = ?,
                total_time_seconds = ?
            WHERE id = ?
        ");
        $stmt->execute([$cardsReviewed, $cardsCorrect, $avgConfidenceBefore, $avgConfidenceAfter, $totalTimeSeconds, $sessionId]);
        
        echo json_encode(['ok' => true]);
    }
    else {
        throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}



