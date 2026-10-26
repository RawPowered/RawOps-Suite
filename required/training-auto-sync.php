<?php
/**
 * Training Auto-Sync System
 * 
 * Automatically synchronizes product changes with training modules
 * Ensures all training materials stay current with product updates
 */

// Ensure training-helpers.php is loaded (dependency)
if (!function_exists('get_training_product')) {
    require_once __DIR__ . '/training-helpers.php';
}

/**
 * Auto-sync a product with training modules
 * 
 * @param string $sku Product SKU
 * @param array $product_data Product data array
 * @return array Sync results
 */
function auto_sync_product_training($sku, $product_data = null) {
    $results = [
        'success' => false,
        'actions_taken' => [],
        'errors' => [],
        'warnings' => []
    ];
    
    try {
        // Get product data if not provided
        if (!$product_data) {
            $product_data = get_training_product($sku, false);
            if (!$product_data) {
                $results['errors'][] = "Product {$sku} not found in database";
                return $results;
            }
        }
        
        // Check if product is enabled for training
        if (!$product_data['use_in_training']) {
            $results['warnings'][] = "Product {$sku} is not enabled for training";
            return $results;
        }
        
        // 1. Update training_module_examples table
        $example_result = sync_training_examples($sku, $product_data);
        if ($example_result['success']) {
            $results['actions_taken'][] = "Updated training examples: {$example_result['updated']} records";
        } else {
            $results['errors'] = array_merge($results['errors'], $example_result['errors']);
        }
        
        // 2. Verify module file references
        $module_result = verify_module_references($sku);
        if ($module_result['found']) {
            $results['actions_taken'][] = "Verified module references: {$module_result['module_count']} modules";
        } else {
            $results['warnings'][] = "Product not found in any module files - may need manual integration";
        }
        
        // 3. Update bulk pricing if needed
        $pricing_result = sync_bulk_pricing($sku, $product_data);
        if ($pricing_result['success']) {
            $results['actions_taken'][] = "Synced bulk pricing: {$pricing_result['tiers']} tiers";
        }
        
        // 4. Validate calculated fields
        $calc_result = validate_calculated_fields($sku, $product_data);
        if (!$calc_result['valid']) {
            $results['errors'] = array_merge($results['errors'], $calc_result['errors']);
        } else {
            $results['actions_taken'][] = "Validated calculated fields";
        }
        
        $results['success'] = empty($results['errors']);
        
    } catch (Exception $e) {
        $results['errors'][] = "Auto-sync failed: " . $e->getMessage();
    }
    
    return $results;
}

/**
 * Sync training examples for a product
 * 
 * @param string $sku Product SKU
 * @param array $product_data Product data
 * @return array Sync results
 */
function sync_training_examples($sku, $product_data) {
    $results = ['success' => false, 'updated' => 0, 'errors' => []];
    
    try {
        $pdo = db();
        
        // Check if table exists
        $tables = $pdo->query("SHOW TABLES LIKE 'training_module_examples'")->fetchAll();
        if (empty($tables)) {
            $results['errors'][] = "training_module_examples table does not exist";
            return $results;
        }
        
        // Get training context and features
        $training_context = $product_data['training_context'] ?? 'all';
        $training_features = $product_data['training_features'] ?? [];
        
        // Determine appropriate modules based on training context
        $module_assignments = get_module_assignments_by_context($training_context);
        
        // Update or insert training examples
        foreach ($module_assignments as $module) {
            $stmt = $pdo->prepare("
                INSERT INTO training_module_examples 
                (module_name, section_name, product_sku, example_type, usage_context, enabled, created_at)
                VALUES (?, ?, ?, ?, ?, 1, NOW())
                ON DUPLICATE KEY UPDATE
                    usage_context = VALUES(usage_context),
                    enabled = VALUES(enabled),
                    updated_at = NOW()
            ");
            
            $stmt->execute([
                $module['module_name'],
                $module['section_name'],
                $sku,
                $module['example_type'],
                $module['usage_context']
            ]);
            
            $results['updated']++;
        }
        
        // Add quiz questions if appropriate
        $quiz_assignments = get_quiz_assignments_by_context($training_context);
        foreach ($quiz_assignments as $quiz) {
            $stmt = $pdo->prepare("
                INSERT INTO training_module_examples 
                (module_name, section_name, product_sku, example_type, quiz_question_num, correct_answer_key, usage_context, enabled, created_at)
                VALUES (?, ?, ?, 'quiz', ?, ?, ?, 1, NOW())
                ON DUPLICATE KEY UPDATE
                    correct_answer_key = VALUES(correct_answer_key),
                    usage_context = VALUES(usage_context),
                    enabled = VALUES(enabled),
                    updated_at = NOW()
            ");
            
            $stmt->execute([
                $quiz['module_name'],
                $quiz['section_name'],
                $sku,
                $quiz['question_num'],
                $quiz['correct_answer'],
                $quiz['usage_context']
            ]);
            
            $results['updated']++;
        }
        
        $results['success'] = true;
        
    } catch (Exception $e) {
        $results['errors'][] = "Failed to sync training examples: " . $e->getMessage();
    }
    
    return $results;
}

/**
 * Get module assignments based on training context
 * 
 * @param string $context Training context
 * @return array Module assignments
 */
function get_module_assignments_by_context($context) {
    $assignments = [
        'beginner' => [
            ['module_name' => 'intro-product.php', 'section_name' => 'Product Overview', 'example_type' => 'example', 'usage_context' => 'Basic product introduction for beginners'],
            ['module_name' => 'products-product categories-new.php', 'section_name' => 'Product Lineup', 'example_type' => 'example', 'usage_context' => 'Primary product for beginner training'],
            ['module_name' => 'profit-calculator-training.php', 'section_name' => 'Basic Calculations', 'example_type' => 'calculation', 'usage_context' => 'Basic profit calculations for new users'],
            ['module_name' => 'crm-mastery.php', 'section_name' => 'Pricing Examples', 'example_type' => 'example', 'usage_context' => 'Basic pricing examples in CRM training']
        ],
        'intermediate' => [
            ['module_name' => 'products-product categories-new.php', 'section_name' => 'Product Lineup', 'example_type' => 'example', 'usage_context' => 'Intermediate product for stronger doses'],
            ['module_name' => 'profit-calculator-training.php', 'section_name' => 'Advanced Calculations', 'example_type' => 'calculation', 'usage_context' => 'Intermediate profit calculations'],
            ['module_name' => 'ten-step-close.php', 'section_name' => 'Value Proposition', 'example_type' => 'example', 'usage_context' => 'Value proposition examples for intermediate users'],
            ['module_name' => 'objection-mastery.php', 'section_name' => 'Pricing Objections', 'example_type' => 'example', 'usage_context' => 'Handling pricing objections with intermediate product']
        ],
        'advanced' => [
            ['module_name' => 'products-product categories-new.php', 'section_name' => 'Product Lineup', 'example_type' => 'example', 'usage_context' => 'Advanced product for high-volume operations'],
            ['module_name' => 'profit-calculator-training.php', 'section_name' => 'Advanced Calculations', 'example_type' => 'calculation', 'usage_context' => 'Advanced profit calculations for high-volume'],
            ['module_name' => 'ten-step-close.php', 'section_name' => 'High-Value Sales', 'example_type' => 'example', 'usage_context' => 'High-value sales scenarios'],
            ['module_name' => 'ai-assisted-sales.php', 'section_name' => 'AI Examples', 'example_type' => 'example', 'usage_context' => 'AI-assisted sales examples for advanced users'],
            ['module_name' => 'final-assessment.php', 'section_name' => 'Comprehensive Test', 'example_type' => 'example', 'usage_context' => 'Final assessment scenarios'],
            ['module_name' => 'final-exam.php', 'section_name' => 'Final Exam', 'example_type' => 'example', 'usage_context' => 'Final exam comprehensive testing']
        ],
        'all' => [
            ['module_name' => 'intro-product.php', 'section_name' => 'Product Overview', 'example_type' => 'example', 'usage_context' => 'General product introduction'],
            ['module_name' => 'products-product categories-new.php', 'section_name' => 'Product Lineup', 'example_type' => 'example', 'usage_context' => 'Product lineup reference'],
            ['module_name' => 'profit-calculator-training.php', 'section_name' => 'Basic Calculations', 'example_type' => 'calculation', 'usage_context' => 'General profit calculations'],
            ['module_name' => 'crm-mastery.php', 'section_name' => 'Pricing Examples', 'example_type' => 'example', 'usage_context' => 'General pricing examples']
        ]
    ];
    
    return $assignments[$context] ?? $assignments['all'];
}

/**
 * Get quiz assignments based on training context
 * 
 * @param string $context Training context
 * @return array Quiz assignments
 */
function get_quiz_assignments_by_context($context) {
    $assignments = [
        'beginner' => [
            ['module_name' => 'crm-mastery.php', 'section_name' => 'Pricing Mastery', 'question_num' => 1, 'correct_answer' => 'A', 'usage_context' => 'Basic pricing calculation quiz'],
            ['module_name' => 'profit-calculator-training.php', 'section_name' => 'Basic Calculations', 'question_num' => 1, 'correct_answer' => 'B', 'usage_context' => 'Basic profit calculation quiz']
        ],
        'intermediate' => [
            ['module_name' => 'crm-mastery.php', 'section_name' => 'Pricing Mastery', 'question_num' => 2, 'correct_answer' => 'C', 'usage_context' => 'Intermediate pricing calculation quiz'],
            ['module_name' => 'ten-step-close.php', 'section_name' => 'Value Proposition', 'question_num' => 1, 'correct_answer' => 'A', 'usage_context' => 'Value proposition understanding quiz']
        ],
        'advanced' => [
            ['module_name' => 'profit-calculator-training.php', 'section_name' => 'Advanced Calculations', 'question_num' => 2, 'correct_answer' => 'D', 'usage_context' => 'Advanced profit calculation quiz'],
            ['module_name' => 'final-assessment.php', 'section_name' => 'Comprehensive Test', 'question_num' => 1, 'correct_answer' => 'B', 'usage_context' => 'Final assessment comprehensive quiz'],
            ['module_name' => 'final-exam.php', 'section_name' => 'Final Exam', 'question_num' => 1, 'correct_answer' => 'C', 'usage_context' => 'Final exam comprehensive quiz']
        ],
        'all' => [
            ['module_name' => 'crm-mastery.php', 'section_name' => 'Pricing Mastery', 'question_num' => 1, 'correct_answer' => 'A', 'usage_context' => 'General pricing calculation quiz']
        ]
    ];
    
    return $assignments[$context] ?? $assignments['all'];
}

/**
 * Verify module file references
 * 
 * @param string $sku Product SKU
 * @return array Verification results
 */
function verify_module_references($sku) {
    $results = ['found' => false, 'module_count' => 0, 'files' => []];
    
    try {
        // Use the existing function from training-sync-checker.php
        if (function_exists('find_product_usage_in_modules')) {
            $usage = find_product_usage_in_modules($sku);
            $results['found'] = !empty($usage);
            $results['module_count'] = count($usage);
            $results['files'] = array_column($usage, 'file');
        }
    } catch (Exception $e) {
        error_log("verify_module_references error: " . $e->getMessage());
    }
    
    return $results;
}

/**
 * Sync bulk pricing for a product
 * 
 * @param string $sku Product SKU
 * @param array $product_data Product data
 * @return array Sync results
 */
function sync_bulk_pricing($sku, $product_data) {
    $results = ['success' => false, 'tiers' => 0, 'errors' => []];
    
    try {
        $pdo = db();
        
        // Check if bulk_pricing table exists
        $tables = $pdo->query("SHOW TABLES LIKE 'bulk_pricing'")->fetchAll();
        if (empty($tables)) {
            $results['errors'][] = "bulk_pricing table does not exist";
            return $results;
        }
        
        // Check if pricing already exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM bulk_pricing WHERE sku = ? AND active = 1");
        $stmt->execute([$sku]);
        $existing_count = $stmt->fetchColumn();
        
        if ($existing_count >= 4) {
            $results['success'] = true;
            $results['tiers'] = $existing_count;
            return $results;
        }
        
        // Add default pricing tiers if missing
        $default_tiers = [
            ['quantity_tier_name' => 'X1', 'quantity_min' => 1, 'discount_percent' => 0.00, 'unit_price' => $product_data['wholesale_price']],
            ['quantity_tier_name' => 'X5', 'quantity_min' => 5, 'discount_percent' => 0.00, 'unit_price' => $product_data['wholesale_price']],
            ['quantity_tier_name' => 'X10', 'quantity_min' => 10, 'discount_percent' => 8.00, 'unit_price' => $product_data['wholesale_price'] * 0.92],
            ['quantity_tier_name' => 'X15', 'quantity_min' => 15, 'discount_percent' => 12.00, 'unit_price' => $product_data['wholesale_price'] * 0.88],
            ['quantity_tier_name' => 'X20', 'quantity_min' => 20, 'discount_percent' => 12.00, 'unit_price' => $product_data['wholesale_price'] * 0.88]
        ];
        
        foreach ($default_tiers as $tier) {
            $stmt = $pdo->prepare("
                INSERT INTO bulk_pricing 
                (sku, quantity_tier_name, quantity_min, discount_percent, unit_price, active, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    discount_percent = VALUES(discount_percent),
                    unit_price = VALUES(unit_price),
                    active = VALUES(active),
                    updated_at = NOW()
            ");
            
            $stmt->execute([
                $sku,
                $tier['quantity_tier_name'],
                $tier['quantity_min'],
                $tier['discount_percent'],
                $tier['unit_price']
            ]);
            
            $results['tiers']++;
        }
        
        $results['success'] = true;
        
    } catch (Exception $e) {
        $results['errors'][] = "Failed to sync bulk pricing: " . $e->getMessage();
    }
    
    return $results;
}

/**
 * Validate calculated fields for a product
 * 
 * @param string $sku Product SKU
 * @param array $product_data Product data
 * @return array Validation results
 */
function validate_calculated_fields($sku, $product_data) {
    $results = ['valid' => true, 'errors' => []];
    
    $required_fields = ['total_mg', 'servings_10mg', 'ml_per_10mg_serving', 'drinks_per_oz'];
    
    foreach ($required_fields as $field) {
        if (empty($product_data[$field]) || $product_data[$field] <= 0) {
            $results['valid'] = false;
            $results['errors'][] = "Missing or invalid calculated field: {$field}";
        }
    }
    
    // Validate calculations make sense
    if ($product_data['attribute_per_oz'] && $product_data['size_oz']) {
        $expected_total_mg = $product_data['attribute_per_oz'] * $product_data['size_oz'];
        if (abs($product_data['total_mg'] - $expected_total_mg) > 1) {
            $results['valid'] = false;
            $results['errors'][] = "Total attribute calculation mismatch: expected {$expected_total_mg}, got {$product_data['total_mg']}";
        }
    }
    
    return $results;
}

/**
 * Auto-sync all training-enabled products
 * 
 * @return array Sync results for all products
 */
function auto_sync_all_training_products() {
    $results = [
        'success' => false,
        'products_processed' => 0,
        'products_successful' => 0,
        'products_failed' => 0,
        'errors' => [],
        'warnings' => []
    ];
    
    try {
        $pdo = db();
        
        // Get all training-enabled products
        $stmt = $pdo->query("
            SELECT sku, name, use_in_training, training_context
            FROM products 
            WHERE use_in_training = 1 AND active = 1
        ");
        
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($products as $product) {
            $results['products_processed']++;
            
            $sync_result = auto_sync_product_training($product['sku']);
            
            if ($sync_result['success']) {
                $results['products_successful']++;
            } else {
                $results['products_failed']++;
                $results['errors'] = array_merge($results['errors'], $sync_result['errors']);
            }
            
            $results['warnings'] = array_merge($results['warnings'], $sync_result['warnings']);
        }
        
        $results['success'] = $results['products_failed'] === 0;
        
    } catch (Exception $e) {
        $results['errors'][] = "Auto-sync all products failed: " . $e->getMessage();
    }
    
    return $results;
}

/**
 * Get sync status for all products
 * 
 * @return array Sync status summary
 */
function get_training_sync_status() {
    $results = [
        'total_products' => 0,
        'training_enabled' => 0,
        'sync_issues' => 0,
        'products_with_issues' => [],
        'overall_status' => 'unknown'
    ];
    
    try {
        $pdo = db();
        
        // Get all active products
        $stmt = $pdo->query("
            SELECT sku, name, use_in_training, training_context
            FROM products 
            WHERE active = 1
        ");
        
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $results['total_products'] = count($products);
        
        foreach ($products as $product) {
            if ($product['use_in_training']) {
                $results['training_enabled']++;
                
                // Check sync status
                if (function_exists('verify_product_sync')) {
                    $sync_check = verify_product_sync($product['sku']);
                    if ($sync_check['status'] !== 'good') {
                        $results['sync_issues']++;
                        $results['products_with_issues'][] = [
                            'sku' => $product['sku'],
                            'name' => $product['name'],
                            'status' => $sync_check['status'],
                            'issues' => $sync_check['issues'] ?? []
                        ];
                    }
                }
            }
        }
        
        // Determine overall status
        if ($results['sync_issues'] === 0) {
            $results['overall_status'] = 'good';
        } elseif ($results['sync_issues'] <= $results['training_enabled'] * 0.3) {
            $results['overall_status'] = 'warning';
        } else {
            $results['overall_status'] = 'error';
        }
        
    } catch (Exception $e) {
        $results['overall_status'] = 'error';
        $results['errors'][] = "Failed to get sync status: " . $e->getMessage();
    }
    
    return $results;
}


