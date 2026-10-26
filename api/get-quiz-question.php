<?php
/**
 * Get Quiz Question API
 * Returns quiz question data for editing
 */

require_once __DIR__ . '/../required/config.php';
header('Content-Type: application/json');

try {
    $quizId = intval($_GET['id'] ?? 0);
    
    if ($quizId <= 0) {
        throw new Exception('Invalid quiz ID');
    }
    
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT 
            tqq.*,
            tm.title as module_title,
            tm.slug as module_slug,
            p.name as product_name,
            p.sku as product_sku
        FROM training_quiz_questions tqq
        JOIN training_modules tm ON tm.id = tqq.module_id
        LEFT JOIN products p ON p.id = tqq.product_id
        WHERE tqq.id = ?
    ");
    $stmt->execute([$quizId]);
    $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$quiz) {
        throw new Exception('Quiz question not found');
    }
    
    echo json_encode([
        'ok' => true,
        'quiz' => $quiz
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}



