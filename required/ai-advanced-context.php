<?php
// ai-advanced-context.php
// Advanced context retrieval for world-class AI sales assistant

class AdvancedAIContext {
    private $pdo;
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Get comprehensive customer context for personalized interactions
     */
    public function getCustomerContext($userId): array {
        $context = [
            'profile' => null,
            'purchase_history' => [],
            'preferences' => [],
            'lifetime_value' => 0,
            'engagement_score' => 0,
            'recommended_products' => []
        ];
        
        if (!$userId) return $context;
        
        try {
            // Get customer profile with wholesale account details
            $stmt = $this->pdo->prepare("
                SELECT u.*, wa.business_name, wa.approved, wa.sales_agent_id,
                       sa.first_name as agent_first_name, sa.last_name as agent_last_name
                FROM users u
                LEFT JOIN wholesale_accounts wa ON wa.user_id = u.id
                LEFT JOIN users sa ON sa.id = wa.sales_agent_id
                WHERE u.id = ?
            ");
            $stmt->execute([$userId]);
            $context['profile'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get purchase history with product preferences
            $stmt = $this->pdo->prepare("
                SELECT o.*, 
                       GROUP_CONCAT(CONCAT(oi.product_sku, ':', oi.quantity) SEPARATOR ',') as items,
                       SUM(oi.line_total) as order_value
                FROM orders o
                LEFT JOIN order_items oi ON oi.order_id = o.id
                WHERE o.user_id = ?
                GROUP BY o.id
                ORDER BY o.created_at DESC
                LIMIT 10
            ");
            $stmt->execute([$userId]);
            $context['purchase_history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate lifetime value and preferences
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(DISTINCT o.id) as total_orders,
                    SUM(o.total) as lifetime_value,
                    AVG(o.total) as avg_order_value,
                    MAX(o.created_at) as last_order_date,
                    GROUP_CONCAT(DISTINCT oi.product_sku) as purchased_products
                FROM orders o
                LEFT JOIN order_items oi ON oi.order_id = o.id
                WHERE o.user_id = ? AND o.status != 'cancelled'
            ");
            $stmt->execute([$userId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $context['lifetime_value'] = $stats['lifetime_value'] ?? 0;
            $context['preferences'] = [
                'total_orders' => $stats['total_orders'] ?? 0,
                'avg_order_value' => $stats['avg_order_value'] ?? 0,
                'last_order_date' => $stats['last_order_date'],
                'favorite_products' => $this->getFavoriteProducts($userId)
            ];
            
            // Calculate engagement score
            $context['engagement_score'] = $this->calculateEngagementScore($userId);
            
            // Get personalized product recommendations
            $context['recommended_products'] = $this->getProductRecommendations($userId);
            
        } catch (Throwable $e) {
            error_log("AI Customer Context Error: " . $e->getMessage());
        }
        
        return $context;
    }
    
    /**
     * Get sales agent performance context for motivation and guidance
     */
    public function getAgentContext($agentId): array {
        if (!$agentId) return [];
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    u.first_name, u.last_name,
                    COUNT(DISTINCT o.id) as total_sales,
                    SUM(o.total) as total_revenue,
                    AVG(o.total) as avg_sale_value,
                    ct.name as commission_tier,
                    ct.commission_rate
                FROM users u
                LEFT JOIN orders o ON o.sales_agent_id = u.id
                LEFT JOIN agent_commissions ac ON ac.sales_agent_id = u.id
                LEFT JOIN commission_tiers ct ON ct.id = ac.tier_id
                WHERE u.id = ? AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY u.id
            ");
            $stmt->execute([$agentId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            
        } catch (Throwable $e) {
            error_log("AI Agent Context Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get relevant objection handling strategies
     */
    public function getObjectionStrategies($message): array {
        $keywords = ['price', 'cost', 'expensive', 'cheaper', 'competitor', 'legal', 'health', 'quality'];
        $relevantObjections = [];
        
        foreach ($keywords as $keyword) {
            if (stripos($message, $keyword) !== false) {
                try {
                    $stmt = $this->pdo->prepare("
                        SELECT objection_text, rebuttal_text, follow_up_text, success_rate
                        FROM objection_library
                        WHERE category LIKE ? OR objection_text LIKE ?
                        ORDER BY success_rate DESC
                        LIMIT 3
                    ");
                    $searchTerm = '%' . $keyword . '%';
                    $stmt->execute([$searchTerm, $searchTerm]);
                    $objections = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if ($objections) {
                        $relevantObjections = array_merge($relevantObjections, $objections);
                    }
                } catch (Throwable $e) {
                    error_log("AI Objection Retrieval Error: " . $e->getMessage());
                }
            }
        }
        
        return $relevantObjections;
    }
    
    /**
     * Get AI learning insights for improved responses
     */
    public function getAIInsights($category = null): array {
        try {
            $query = "SELECT * FROM ai_learning_insights WHERE is_active = 1";
            $params = [];
            
            if ($category) {
                $query .= " AND learning_category = ?";
                $params[] = $category;
            }
            
            $query .= " ORDER BY success_rate_improvement DESC LIMIT 5";
            
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Throwable $e) {
            error_log("AI Insights Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get training module recommendations based on user role
     */
    public function getTrainingRecommendations($userId): array {
        try {
            // Check user's training progress
            $stmt = $this->pdo->prepare("
                SELECT 
                    tm.slug, tm.title,
                    tp.status, tp.score,
                    tm.estimated_duration
                FROM training_modules tm
                LEFT JOIN training_progress tp ON tp.module_id = tm.id AND tp.user_id = ?
                WHERE tm.is_active = 1
                ORDER BY tm.order_index
            ");
            $stmt->execute([$userId]);
            $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $recommendations = [];
            foreach ($modules as $module) {
                if (!$module['status'] || $module['status'] === 'in_progress') {
                    $recommendations[] = [
                        'module' => $module['title'],
                        'slug' => $module['slug'],
                        'duration' => $module['estimated_duration'],
                        'status' => $module['status'] ?? 'not_started'
                    ];
                }
            }
            
            return $recommendations;
            
        } catch (Throwable $e) {
            error_log("AI Training Recommendations Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Track and analyze conversation patterns for continuous improvement
     */
    public function analyzeConversationPatterns($sessionId): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as message_count,
                    AVG(LENGTH(user_message)) as avg_message_length,
                    COUNT(DISTINCT action_taken) as unique_actions,
                    SUM(CASE WHEN feedback = 1 THEN 1 ELSE 0 END) as positive_feedback,
                    SUM(CASE WHEN feedback = -1 THEN 1 ELSE 0 END) as negative_feedback
                FROM ai_chat_logs
                WHERE session_id = ?
            ");
            $stmt->execute([$sessionId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            
        } catch (Throwable $e) {
            error_log("AI Pattern Analysis Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get real-time inventory and pricing updates
     */
    public function getInventoryStatus(): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    p.sku, p.name, p.wholesale_price, p.msrp,
                    COUNT(oi.id) as recent_sales,
                    AVG(oi.quantity) as avg_quantity_sold
                FROM products p
                LEFT JOIN order_items oi ON oi.product_sku = p.sku
                LEFT JOIN orders o ON o.id = oi.order_id 
                    AND o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                WHERE p.active = 1
                GROUP BY p.id
                ORDER BY recent_sales DESC
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Throwable $e) {
            error_log("AI Inventory Status Error: " . $e->getMessage());
            return [];
        }
    }
    
    private function getFavoriteProducts($userId): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    oi.product_sku,
                    oi.product_name,
                    COUNT(*) as purchase_count,
                    SUM(oi.quantity) as total_quantity
                FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                WHERE o.user_id = ? AND o.status != 'cancelled'
                GROUP BY oi.product_sku
                ORDER BY purchase_count DESC
                LIMIT 3
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Throwable $e) {
            return [];
        }
    }
    
    private function calculateEngagementScore($userId): int {
        // Simple engagement scoring based on activity
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM orders WHERE user_id = ?) * 10 +
                    (SELECT COUNT(*) FROM ai_chat_logs WHERE user_id = ?) * 2 +
                    (SELECT COUNT(*) FROM training_progress WHERE user_id = ? AND status = 'completed') * 5
                    as score
            ");
            $stmt->execute([$userId, $userId, $userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return min(100, $result['score'] ?? 0);
            
        } catch (Throwable $e) {
            return 0;
        }
    }
    
    private function getProductRecommendations($userId): array {
        try {
            // Get products the user hasn't purchased yet
            $stmt = $this->pdo->prepare("
                SELECT p.sku, p.name, p.wholesale_price
                FROM products p
                WHERE p.active = 1 
                AND p.sku NOT IN (
                    SELECT DISTINCT oi.product_sku 
                    FROM order_items oi
                    JOIN orders o ON o.id = oi.order_id
                    WHERE o.user_id = ?
                )
                ORDER BY p.wholesale_price ASC
                LIMIT 3
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Throwable $e) {
            return [];
        }
    }
    
    /**
     * Log successful sales techniques for AI learning
     */
    public function logSuccessfulTechnique($technique, $category, $sessionId): void {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO ai_learning_insights 
                (agent_name, learning_category, original_technique, ai_adapted_technique, created_at)
                VALUES ('ai_assistant', ?, ?, ?, NOW())
            ");
            $stmt->execute([$category, $technique, "Session: $sessionId"]);
        } catch (Throwable $e) {
            error_log("AI Learning Log Error: " . $e->getMessage());
        }
    }
}
?>
