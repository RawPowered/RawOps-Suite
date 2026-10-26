<?php
/**
 * Training Quiz Submission API
 * Handles quiz answer submission, scoring, and module completion
 */

require_once __DIR__ . '/../required/config.php';
header('Content-Type: application/json');

try {
    $userId = current_user_id();
    if (!$userId) {
        throw new Exception('Not authenticated');
    }
    
    $moduleId = intval($_POST['module_id'] ?? 0);
    
    if ($moduleId <= 0) {
        throw new Exception('Invalid module ID');
    }
    
    $pdo = db();
    
    // Check if this is a simple score submission (from client-side scoring)
    if (isset($_POST['score_percent'])) {
        // Client already calculated score
        $scorePercent = intval($_POST['score_percent']);
        $correctAnswers = intval($_POST['correct_answers']);
        $totalQuestions = intval($_POST['total_questions']);
        $passed = (intval($_POST['passed']) === 1);
    } else {
        // Server-side scoring (for database quizzes)
        $answers = $_POST['answers'] ?? [];
        
        if (empty($answers)) {
            throw new Exception('No answers provided');
        }
        
        // Get all questions answered
        $questionIds = array_keys($answers);
        $placeholders = str_repeat('?,', count($questionIds) - 1) . '?';
        
        $stmt = $pdo->prepare("
            SELECT id, correct_answer, question_text
            FROM training_quiz_questions
            WHERE id IN ($placeholders)
        ");
        $stmt->execute($questionIds);
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate score
        $totalQuestions = count($questions);
        $correctAnswers = 0;
        
        foreach ($questions as $question) {
            $questionId = $question['id'];
            $userAnswer = $answers[$questionId] ?? '';
            $correctAnswer = $question['correct_answer'];
            $isCorrect = (strtolower($userAnswer) === strtolower($correctAnswer));
            
            if ($isCorrect) {
                $correctAnswers++;
            }
            
            // Record answer in quiz_question_usage
            require_once __DIR__ . '/../required/quiz-renderer.php';
            record_quiz_answer($questionId, $userId, $userAnswer, $isCorrect);
        }
        
        $scorePercent = $totalQuestions > 0 ? round(($correctAnswers / $totalQuestions) * 100) : 0;
        $passed = $scorePercent >= 80;
    }
    
    // Get current attempt number
    $stmt = $pdo->prepare("
        SELECT COALESCE(MAX(attempt_number), 0) as max_attempt
        FROM training_quiz_results
        WHERE user_id = ? AND module_id = ?
    ");
    $stmt->execute([$userId, $moduleId]);
    $attemptNumber = intval($stmt->fetchColumn()) + 1;
    
    // Record quiz result
    $stmt = $pdo->prepare("
        INSERT INTO training_quiz_results 
        (user_id, module_id, score, total_questions, attempt_number, passed, completed_at, questions_shown)
        VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
    ");
    $stmt->execute([
        $userId,
        $moduleId,
        $correctAnswers,
        $totalQuestions,
        $attemptNumber,
        $passed ? 1 : 0,
        json_encode($questionIds)
    ]);
    
    // If passed, update module completion
    if ($passed) {
        $stmt = $pdo->prepare("
            UPDATE training_progress SET
                status = 'completed',
                completed_at = NOW(),
                quiz_score = ?
            WHERE user_id = ? AND module_id = ?
        ");
        $stmt->execute([$scorePercent, $userId, $moduleId]);
    }
    
    echo json_encode([
        'ok' => true,
        'score_percent' => $scorePercent,
        'correct_answers' => $correctAnswers,
        'total_questions' => $totalQuestions,
        'passed' => $passed,
        'attempt_number' => $attemptNumber,
        'results' => $results
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}

