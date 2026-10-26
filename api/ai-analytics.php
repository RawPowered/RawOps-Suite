<?php
// ai-analytics.php
// Real-time analytics and performance tracking for AI Sales Assistant

header('Content-Type: application/json');
require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/ai-advanced-context.php';

start_session();

// Get analytics type
$input = json_decode(file_get_contents('php://input'), true);
$type = $input['type'] ?? 'dashboard';

$advancedContext = new AdvancedAIContext(db());
$analytics = [];

try {
    switch ($type) {
        case 'dashboard':
            // Get overall AI performance metrics
            $stmt = db()->prepare("
                SELECT 
                    COUNT(*) as total_conversations,
                    COUNT(DISTINCT session_id) as unique_sessions,
                    COUNT(DISTINCT user_id) as unique_users,
                    AVG(CASE WHEN feedback = 1 THEN 100 ELSE 0 END) as satisfaction_rate,
                    COUNT(CASE WHEN action_taken = 'create_account' THEN 1 END) as accounts_created,
                    COUNT(CASE WHEN action_taken = 'place_order' THEN 1 END) as orders_placed,
                    COUNT(CASE WHEN action_taken = 'add_to_cart' THEN 1 END) as items_added_to_cart
                FROM ai_chat_logs
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt->execute();
            $analytics['performance'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get conversion funnel
            $stmt = db()->prepare("
                SELECT 
                    COUNT(DISTINCT session_id) as sessions_with_conversation,
                    COUNT(DISTINCT CASE WHEN action_taken IS NOT NULL THEN session_id END) as sessions_with_action,
                    COUNT(DISTINCT CASE WHEN action_taken IN ('create_account', 'place_order') THEN session_id END) as sessions_with_conversion
                FROM ai_chat_logs
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $stmt->execute();
            $analytics['funnel'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Calculate conversion rates
            $total = $analytics['funnel']['sessions_with_conversation'] ?: 1;
            $analytics['conversion_rates'] = [
                'engagement_rate' => round(($analytics['funnel']['sessions_with_action'] / $total) * 100, 2),
                'conversion_rate' => round(($analytics['funnel']['sessions_with_conversion'] / $total) * 100, 2)
            ];
            break;
            
        case 'learning':
            // Get AI learning progress
            $stmt = db()->prepare("
                SELECT 
                    learning_category,
                    COUNT(*) as technique_count,
                    AVG(success_rate_improvement) as avg_improvement
                FROM ai_learning_insights
                WHERE is_active = 1
                GROUP BY learning_category
            ");
            $stmt->execute();
            $analytics['learning_progress'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get most successful techniques
            $stmt = db()->prepare("
                SELECT *
                FROM ai_learning_insights
                WHERE is_active = 1
                ORDER BY success_rate_improvement DESC
                LIMIT 5
            ");
            $stmt->execute();
            $analytics['top_techniques'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'customer_insights':
            // Get customer behavior patterns
            $stmt = db()->prepare("
                SELECT 
                    DATE(created_at) as date,
                    COUNT(DISTINCT user_id) as active_users,
                    COUNT(*) as total_messages,
                    AVG(LENGTH(user_message)) as avg_message_length
                FROM ai_chat_logs
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY DATE(created_at)
                ORDER BY date DESC
            ");
            $stmt->execute();
            $analytics['daily_activity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get common questions/topics
            $stmt = db()->prepare("
                SELECT 
                    user_message,
                    COUNT(*) as frequency
                FROM ai_chat_logs
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY user_message
                ORDER BY frequency DESC
                LIMIT 10
            ");
            $stmt->execute();
            $analytics['common_questions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'sales_impact':
            // Calculate AI's impact on sales
            $stmt = db()->prepare("
                SELECT 
                    DATE_FORMAT(o.created_at, '%Y-%m') as month,
                    COUNT(o.id) as ai_assisted_orders,
                    SUM(o.total) as ai_assisted_revenue,
                    AVG(o.total) as avg_order_value
                FROM orders o
                WHERE o.notes LIKE '%AI%' OR o.order_number LIKE 'AI-%'
                GROUP BY DATE_FORMAT(o.created_at, '%Y-%m')
                ORDER BY month DESC
                LIMIT 6
            ");
            $stmt->execute();
            $analytics['sales_impact'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get product recommendations success rate
            $stmt = db()->prepare("
                SELECT 
                    p.name,
                    COUNT(DISTINCT acl.session_id) as times_recommended,
                    COUNT(DISTINCT oi.order_id) as times_purchased
                FROM ai_chat_logs acl
                JOIN products p ON acl.ai_response LIKE CONCAT('%', p.name, '%')
                LEFT JOIN order_items oi ON oi.product_sku = p.sku
                LEFT JOIN orders o ON o.id = oi.order_id AND o.created_at > acl.created_at
                WHERE acl.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY p.id
                HAVING times_recommended > 0
            ");
            $stmt->execute();
            $recommendations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate recommendation success rates
            foreach ($recommendations as &$rec) {
                $rec['success_rate'] = $rec['times_recommended'] > 0 
                    ? round(($rec['times_purchased'] / $rec['times_recommended']) * 100, 2) 
                    : 0;
            }
            $analytics['recommendation_success'] = $recommendations;
            break;
            
        case 'optimization':
            // Get areas for improvement
            $stmt = db()->prepare("
                SELECT 
                    action_taken,
                    COUNT(*) as attempts,
                    COUNT(CASE WHEN feedback = -1 THEN 1 END) as negative_feedback,
                    COUNT(CASE WHEN corrected_response IS NOT NULL THEN 1 END) as corrections_needed
                FROM ai_chat_logs
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY action_taken
                HAVING negative_feedback > 0 OR corrections_needed > 0
            ");
            $stmt->execute();
            $analytics['areas_for_improvement'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get failed conversation patterns
            $stmt = db()->prepare("
                SELECT 
                    user_message,
                    ai_response,
                    feedback,
                    corrected_response
                FROM ai_chat_logs
                WHERE feedback = -1 OR corrected_response IS NOT NULL
                ORDER BY created_at DESC
                LIMIT 10
            ");
            $stmt->execute();
            $analytics['failed_interactions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
    }
    
    // Add timestamp
    $analytics['generated_at'] = date('Y-m-d H:i:s');
    $analytics['success'] = true;
    
} catch (Throwable $e) {
    error_log("AI Analytics Error: " . $e->getMessage());
    $analytics = [
        'success' => false,
        'error' => 'Failed to generate analytics'
    ];
}

echo json_encode($analytics);
?>
