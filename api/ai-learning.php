<?php
// ai-learning.php
// Automated learning system for continuous AI improvement

header('Content-Type: application/json');
require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/ai-knowledge.php';

// This script analyzes successful conversations and extracts patterns
class AILearningEngine {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Verify store numbers (products/pricing/specs) against canonical knowledge
     */
    public function verifyStoreNumbers(): array {
        $report = [
            'checked' => 0,
            'mismatches' => [],
        ];
        try {
            $canonical = ai_get_canonical_products();
            $bySku = [];
            foreach ($canonical as $p) { $bySku[$p['sku']] = $p; }

            $stmt = $this->pdo->query('SELECT sku, name, wholesale_price, msrp FROM products WHERE active = 1');
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $sku = $row['sku'];
                $report['checked']++;
                $canon = $bySku[$sku] ?? null;
                if (!$canon) continue; // If not in canonical list, skip as informational
                $diffs = [];
                if (round((float)$row['wholesale_price'], 2) !== round((float)$canon['wholesale_price'], 2)) {
                    $diffs['wholesale_price'] = [
                        'db' => (float)$row['wholesale_price'],
                        'canon' => (float)$canon['wholesale_price']
                    ];
                }
                if (round((float)$row['msrp'], 2) !== round((float)$canon['msrp'], 2)) {
                    $diffs['msrp'] = [
                        'db' => (float)$row['msrp'],
                        'canon' => (float)$canon['msrp']
                    ];
                }
                if (!empty($diffs)) {
                    $report['mismatches'][] = [
                        'sku' => $sku,
                        'name' => $row['name'],
                        'diffs' => $diffs,
                    ];
                }
            }
        } catch (Throwable $e) {
            $report['error'] = $e->getMessage();
        }
        return $report;
    }
    
    /**
     * Analyze successful conversations and extract patterns
     */
    public function analyzeSuccessPatterns() {
        $patterns = [];
        
        try {
            // Find successful conversation patterns
            $stmt = $this->pdo->prepare("
                SELECT 
                    acl1.user_message as initial_message,
                    acl1.ai_response as initial_response,
                    acl2.action_taken as outcome,
                    acl2.feedback
                FROM ai_chat_logs acl1
                JOIN ai_chat_logs acl2 ON acl1.session_id = acl2.session_id
                WHERE acl2.action_taken IN ('create_account', 'place_order', 'add_to_cart')
                AND acl2.created_at > acl1.created_at
                AND acl1.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                ORDER BY acl2.created_at DESC
                LIMIT 100
            ");
            $stmt->execute();
            $successfulConversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Extract common phrases and techniques
            $successPhrases = [];
            foreach ($successfulConversations as $conv) {
                // Extract key phrases that led to success
                if (stripos($conv['initial_response'], 'profit') !== false) {
                    $successPhrases['profit_focus']++;
                }
                if (stripos($conv['initial_response'], 'recommend') !== false) {
                    $successPhrases['recommendations']++;
                }
                if (stripos($conv['initial_response'], 'save') !== false || 
                    stripos($conv['initial_response'], 'discount') !== false) {
                    $successPhrases['savings_emphasis']++;
                }
                if (preg_match('/\$[\d,]+/', $conv['initial_response'])) {
                    $successPhrases['specific_pricing']++;
                }
            }
            
            $patterns['success_phrases'] = $successPhrases;
            
            // Analyze objection handling success
            $stmt = $this->pdo->prepare("
                SELECT 
                    ol.objection_text,
                    ol.rebuttal_text,
                    COUNT(acl.id) as times_used,
                    SUM(CASE WHEN acl.feedback = 1 THEN 1 ELSE 0 END) as positive_outcomes
                FROM objection_library ol
                JOIN ai_chat_logs acl ON acl.ai_response LIKE CONCAT('%', ol.rebuttal_text, '%')
                WHERE acl.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY ol.id
                HAVING times_used > 0
            ");
            $stmt->execute();
            $objectionSuccess = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($objectionSuccess as &$obj) {
                $obj['success_rate'] = $obj['times_used'] > 0 
                    ? round(($obj['positive_outcomes'] / $obj['times_used']) * 100, 2)
                    : 0;
            }
            
            $patterns['objection_handling'] = $objectionSuccess;
            
            // Analyze timing patterns
            $stmt = $this->pdo->prepare("
                SELECT 
                    HOUR(created_at) as hour_of_day,
                    COUNT(*) as total_conversations,
                    COUNT(CASE WHEN action_taken IS NOT NULL THEN 1 END) as successful_actions,
                    AVG(CASE WHEN feedback = 1 THEN 100 ELSE 0 END) as satisfaction_rate
                FROM ai_chat_logs
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY HOUR(created_at)
                ORDER BY successful_actions DESC
            ");
            $stmt->execute();
            $patterns['timing_patterns'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Identify conversation length patterns
            $stmt = $this->pdo->prepare("
                SELECT 
                    session_id,
                    COUNT(*) as message_count,
                    MAX(CASE WHEN action_taken IN ('create_account', 'place_order') THEN 1 ELSE 0 END) as converted
                FROM ai_chat_logs
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY session_id
            ");
            $stmt->execute();
            $lengthData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $conversionByLength = [];
            foreach ($lengthData as $session) {
                $bucket = $this->getLengthBucket($session['message_count']);
                $conversionByLength[$bucket]['total']++;
                if ($session['converted']) {
                    $conversionByLength[$bucket]['converted']++;
                }
            }
            
            foreach ($conversionByLength as $bucket => &$data) {
                $data['conversion_rate'] = round(($data['converted'] / $data['total']) * 100, 2);
            }
            
            $patterns['conversation_length'] = $conversionByLength;
            
        } catch (Throwable $e) {
            error_log("AI Learning Analysis Error: " . $e->getMessage());
        }
        
        return $patterns;
    }
    
    /**
     * Update AI learning insights based on analysis
     */
    public function updateLearningInsights($patterns) {
        if (empty($patterns)) return false;
        
        try {
            // Store new insights based on success patterns
            if (!empty($patterns['success_phrases'])) {
                arsort($patterns['success_phrases']);
                $topPhrase = array_key_first($patterns['success_phrases']);
                $improvement = round(($patterns['success_phrases'][$topPhrase] / 100) * 100, 2);
                
                $stmt = $this->pdo->prepare("
                    INSERT INTO ai_learning_insights 
                    (agent_name, learning_category, original_technique, ai_adapted_technique, success_rate_improvement, created_at)
                    VALUES ('ai_auto_learn', 'conversation_technique', ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                    success_rate_improvement = VALUES(success_rate_improvement),
                    created_at = NOW()
                ");
                
                $technique = $this->phraseToTechnique($topPhrase);
                $stmt->execute([
                    "Standard response",
                    $technique,
                    $improvement
                ]);
            }
            
            // Update objection library success rates
            if (!empty($patterns['objection_handling'])) {
                foreach ($patterns['objection_handling'] as $objection) {
                    $stmt = $this->pdo->prepare("
                        UPDATE objection_library 
                        SET success_rate = ?, times_used = times_used + 1
                        WHERE objection_text = ?
                    ");
                    $stmt->execute([
                        $objection['success_rate'],
                        $objection['objection_text']
                    ]);
                }
            }
            
            return true;
            
        } catch (Throwable $e) {
            error_log("AI Learning Update Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generate recommendations for AI improvement
     */
    public function generateRecommendations($patterns) {
        $recommendations = [];
        
        // Analyze success phrases
        if (!empty($patterns['success_phrases'])) {
            $topPhrases = array_slice($patterns['success_phrases'], 0, 3, true);
            foreach ($topPhrases as $phrase => $count) {
                $recommendations[] = [
                    'type' => 'technique',
                    'priority' => 'high',
                    'recommendation' => "Focus on " . str_replace('_', ' ', $phrase) . " - used in $count successful conversations",
                    'impact' => 'High conversion impact'
                ];
            }
        }
        
        // Analyze timing patterns
        if (!empty($patterns['timing_patterns'])) {
            $bestHours = array_slice($patterns['timing_patterns'], 0, 3);
            $peakHour = $bestHours[0]['hour_of_day'];
            $recommendations[] = [
                'type' => 'timing',
                'priority' => 'medium',
                'recommendation' => "Peak performance at {$peakHour}:00 - consider focusing proactive outreach during this time",
                'impact' => 'Optimal engagement timing'
            ];
        }
        
        // Analyze conversation length
        if (!empty($patterns['conversation_length'])) {
            $optimalLength = '';
            $maxConversion = 0;
            foreach ($patterns['conversation_length'] as $bucket => $data) {
                if ($data['conversion_rate'] > $maxConversion) {
                    $maxConversion = $data['conversion_rate'];
                    $optimalLength = $bucket;
                }
            }
            
            $recommendations[] = [
                'type' => 'conversation_flow',
                'priority' => 'high',
                'recommendation' => "Optimal conversation length is $optimalLength messages - {$maxConversion}% conversion rate",
                'impact' => 'Conversation efficiency'
            ];
        }
        
        // Analyze failed patterns
        $stmt = $this->pdo->prepare("
            SELECT user_message, COUNT(*) as failure_count
            FROM ai_chat_logs
            WHERE feedback = -1 OR corrected_response IS NOT NULL
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY user_message
            ORDER BY failure_count DESC
            LIMIT 5
        ");
        $stmt->execute();
        $failurePatterns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($failurePatterns)) {
            foreach ($failurePatterns as $failure) {
                $recommendations[] = [
                    'type' => 'improvement_area',
                    'priority' => 'high',
                    'recommendation' => "Improve response to: \"{$failure['user_message']}\" - failed {$failure['failure_count']} times",
                    'impact' => 'Error reduction'
                ];
            }
        }
        
        return $recommendations;
    }
    
    private function getLengthBucket($messageCount) {
        if ($messageCount <= 3) return '1-3 messages';
        if ($messageCount <= 6) return '4-6 messages';
        if ($messageCount <= 10) return '7-10 messages';
        return '10+ messages';
    }
    
    private function phraseToTechnique($phrase) {
        $techniques = [
            'profit_focus' => 'Emphasize profit calculations and ROI in responses',
            'recommendations' => 'Provide personalized product recommendations',
            'savings_emphasis' => 'Highlight bulk discounts and savings opportunities',
            'specific_pricing' => 'Include specific dollar amounts and calculations'
        ];
        
        return $techniques[$phrase] ?? 'Use proven sales techniques';
    }
}

// Execute learning analysis
$engine = new AILearningEngine(db());
$consistency = $engine->verifyStoreNumbers();
$patterns = $engine->analyzeSuccessPatterns();
$success = $engine->updateLearningInsights($patterns);
$recommendations = $engine->generateRecommendations($patterns);

// Return results
echo json_encode([
    'success' => $success,
    'patterns_found' => count($patterns),
    'recommendations' => $recommendations,
    'store_numbers_check' => $consistency,
    'last_analysis' => date('Y-m-d H:i:s')
]);
?>
