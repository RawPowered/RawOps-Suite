<?php
/**
 * Dynamic Quiz Rendering System
 * Pulls quiz questions from database with product integration and rotation support
 */

/**
 * Render a quiz question for a module at a specific position
 * 
 * @param int $moduleId Module ID
 * @param int $userId User ID (for tracking and rotation)
 * @param string $position Quiz position ('intro', 'early', 'mid', 'late', 'final')
 * @param array $options Rendering options
 * @return string HTML for quiz question or empty string if none found
 */
function render_module_quiz($moduleId, $userId, $position = 'mid', $options = []) {
    try {
        $pdo = db();
        
        // Get questions at this position
        $stmt = $pdo->prepare("
            SELECT 
                tqq.*,
                p.sku, p.name as product_name, p.wholesale_price, p.total_mg, p.servings_10mg,
                p.ml_per_10mg_serving, p.oz_per_10mg_serving, p.cost_per_10mg_serving
            FROM training_quiz_questions tqq
            LEFT JOIN products p ON p.id = tqq.product_id
            WHERE tqq.module_id = ?
              AND tqq.quiz_position = ?
              AND tqq.is_active = 1
            ORDER BY tqq.sort_order ASC, RAND()
        ");
        $stmt->execute([$moduleId, $position]);
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($questions)) {
            return ''; // No questions at this position
        }
        
        // If rotation enabled and multiple questions, select one randomly (but track it)
        $selectedQuestion = $questions[0];
        if (count($questions) > 1 && $selectedQuestion['rotation_enabled']) {
            // Weight by priority
            $totalWeight = array_sum(array_column($questions, 'rotation_priority'));
            $randomWeight = rand(1, $totalWeight);
            $cumulativeWeight = 0;
            
            foreach ($questions as $q) {
                $cumulativeWeight += $q['rotation_priority'];
                if ($randomWeight <= $cumulativeWeight) {
                    $selectedQuestion = $q;
                    break;
                }
            }
        }
        
        // Track which question was shown
        $stmt = $pdo->prepare("
            INSERT INTO quiz_question_usage (quiz_question_id, user_id, module_id, shown_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$selectedQuestion['id'], $userId, $moduleId]);
        
        // Update rotation analytics
        $pdo->prepare("
            INSERT INTO quiz_rotation_analytics (question_id, times_shown, last_shown_at)
            VALUES (?, 1, NOW())
            ON DUPLICATE KEY UPDATE
                times_shown = times_shown + 1,
                last_shown_at = NOW()
        ")->execute([$selectedQuestion['id']]);
        
        // Render the question
        return render_quiz_html($selectedQuestion, $userId, $options);
        
    } catch (Exception $e) {
        error_log("render_module_quiz error: " . $e->getMessage());
        return '<!-- Quiz render error -->';
    }
}

/**
 * Render quiz question HTML
 */
function render_quiz_html($question, $userId, $options = []) {
    $questionId = 'q_' . $question['id'] . '_' . time();
    $optionsData = json_decode($question['options'], true);
    
    // If requires calculation and has product data, inject live values
    if ($question['requires_calculation'] && $question['sku']) {
        $product = get_training_product($question['sku']);
        if ($product && !empty($question['calculation_template'])) {
            // Replace placeholders in options with live product data
            foreach ($optionsData as $key => $optionText) {
                $optionsData[$key] = render_product_placeholders($optionText, $product);
            }
            
            // Replace in question text
            $question['question_text'] = render_product_placeholders($question['question_text'], $product);
            $question['explanation'] = render_product_placeholders($question['explanation'], $product);
        }
    }
    
    $html = '<div class="quiz-question" data-question-id="' . $question['id'] . '" data-correct="' . htmlspecialchars($question['correct_answer']) . '">' . "\n";
    $html .= '    <p><strong>Question:</strong> ' . nl2br(htmlspecialchars($question['question_text'])) . '</p>' . "\n";
    
    // Show rotation indicator if applicable
    if ($question['rotation_enabled'] && !empty($options['show_rotation_indicator'])) {
        $html .= '    <div style="font-size: 11px; color: #9ca3af; margin-bottom: 8px;">🔄 This is a rotated question</div>' . "\n";
    }
    
    $html .= '    <div class="quiz-options">' . "\n";
    
    foreach ($optionsData as $key => $optionText) {
        $html .= '        <label><input type="radio" name="' . $questionId . '" value="' . htmlspecialchars($key) . '"> ' . htmlspecialchars($optionText) . '</label>' . "\n";
    }
    
    $html .= '    </div>' . "\n";
    
    $html .= '    <p style="margin-top: 16px; padding: 12px; background: #f0f9ff; border-radius: 8px; display: none;" class="answer" data-answer="' . htmlspecialchars($question['correct_answer']) . '">' . "\n";
    $html .= '        <strong>Correct Answer: ' . strtoupper($question['correct_answer']) . '</strong>';
    
    if (!empty($question['explanation'])) {
        $html .= ' - ' . htmlspecialchars($question['explanation']);
    }
    
    $html .= "\n" . '    </p>' . "\n";
    $html .= '</div>' . "\n\n";
    
    // Add interactivity script
    $html .= '<script>' . "\n";
    $html .= 'document.querySelectorAll(\'input[name="' . $questionId . '"]\').forEach(input => {' . "\n";
    $html .= '    input.addEventListener(\'change\', function() {' . "\n";
    $html .= '        this.closest(\'.quiz-question\').querySelector(\'.answer\').style.display = \'block\';' . "\n";
    $html .= '    });' . "\n";
    $html .= '});' . "\n";
    $html .= '</script>' . "\n";
    
    return $html;
}

/**
 * Replace product data placeholders in text
 */
function render_product_placeholders($text, $product) {
    $replacements = [
        '{{product_name}}' => $product['name'],
        '{{sku}}' => $product['sku'],
        '{{price}}' => format_currency($product['wholesale_price']),
        '{{total_mg}}' => number_format($product['total_mg']),
        '{{servings}}' => $product['servings_10mg'],
        '{{ml_per_serving}}' => number_format($product['ml_per_10mg_serving'], 1),
        '{{oz_per_serving}}' => number_format($product['oz_per_10mg_serving'], 3),
        '{{cost_per_serving}}' => format_currency($product['cost_per_10mg_serving'])
    ];
    
    return str_replace(array_keys($replacements), array_values($replacements), $text);
}

/**
 * Record quiz answer and update analytics
 */
function record_quiz_answer($questionId, $userId, $answerGiven, $isCorrect) {
    try {
        $pdo = db();
        
        // Update usage tracking
        $stmt = $pdo->prepare("
            UPDATE quiz_question_usage SET
                answered_correctly = ?,
                answer_given = ?
            WHERE quiz_question_id = ? AND user_id = ?
            ORDER BY shown_at DESC
            LIMIT 1
        ");
        $stmt->execute([$isCorrect ? 1 : 0, $answerGiven, $questionId, $userId]);
        
        // Update rotation analytics
        $field = $isCorrect ? 'times_correct' : 'times_incorrect';
        $pdo->prepare("
            UPDATE quiz_rotation_analytics SET
                {$field} = {$field} + 1
            WHERE question_id = ?
        ")->execute([$questionId]);
        
        return true;
    } catch (Exception $e) {
        error_log("record_quiz_answer error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get quiz questions for a module (for admin preview)
 */
function get_module_quiz_questions($moduleId) {
    try {
        $pdo = db();
        
        $stmt = $pdo->prepare("
            SELECT 
                tqq.*,
                p.name as product_name,
                p.sku
            FROM training_quiz_questions tqq
            LEFT JOIN products p ON p.id = tqq.product_id
            WHERE tqq.module_id = ?
            ORDER BY tqq.quiz_position, tqq.sort_order
        ");
        $stmt->execute([$moduleId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("get_module_quiz_questions error: " . $e->getMessage());
        return [];
    }
}



