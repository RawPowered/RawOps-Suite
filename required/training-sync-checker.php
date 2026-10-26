<?php
/**
 * Training Module Sync Checker
 * 
 * Validates data integrity between products and training modules
 * Detects conflicts, overlaps, and missing assignments
 */

// Ensure training-helpers.php is loaded (dependency)
if (!function_exists('get_training_product')) {
    require_once __DIR__ . '/training-helpers.php';
}

/**
 * Find all instances where a product is used in module files
 * 
 * @param string $sku Product SKU
 * @return array Usage details with file excerpts
 */
function find_product_usage_in_modules($sku) {
    $modules_dir = __DIR__ . '/../onboarding/trainee/modules/';
    $modules = [
        'intro-product.php' => 'Module 1: Introduction to Product',
        'products-product categories-new.php' => 'Module 2: Product Mastery',
        'compliance-sales.php' => 'Module 3: Compliance Sales',
        'crm-mastery.php' => 'Module 4: CRM Mastery',
        'final-assessment.php' => 'Module 5: Final Assessment',
        'ten-step-close.php' => 'Module 6: Ten-Step Close',
        'ai-assisted-sales.php' => 'Module 7: AI-Assisted Sales',
        'objection-mastery.php' => 'Module 8: Objection Mastery',
        'profit-calculator-training.php' => 'Module 9: Profit Calculator',
        'final-exam.php' => 'Module 10: Final Exam'
    ];
    
    $usage = [];
    
    foreach ($modules as $file => $module_name) {
        $filepath = $modules_dir . $file;
        if (!file_exists($filepath)) continue;
        
        $content = file_get_contents($filepath);
        $lines = explode("\n", $content);
        
        // Search for SKU usage
        $matches = [];
        foreach ($lines as $line_num => $line) {
            if (stripos($line, $sku) !== false || 
                stripos($line, str_replace('BLEND-', '', $sku)) !== false) {
                
                // Get context (5 lines before and after)
                $start = max(0, $line_num - 5);
                $end = min(count($lines) - 1, $line_num + 5);
                $context_lines = array_slice($lines, $start, $end - $start + 1);
                
                $matches[] = [
                    'line_number' => $line_num + 1,
                    'line_content' => $line,
                    'context' => implode("\n", $context_lines),
                    'context_start' => $start + 1,
                    'context_end' => $end + 1
                ];
            }
        }
        
        if (!empty($matches)) {
            $usage[] = [
                'file' => $file,
                'module_name' => $module_name,
                'filepath' => $filepath,
                'matches' => $matches,
                'match_count' => count($matches)
            ];
        }
    }
    
    return $usage;
}

/**
 * Detect quiz question conflicts (multiple products assigned to same question)
 * 
 * @return array Conflicts found
 */
function detect_quiz_conflicts() {
    try {
        $pdo = db();
        
        // Check if table exists
        $tables = $pdo->query("SHOW TABLES LIKE 'training_module_examples'")->fetchAll();
        if (empty($tables)) {
            return [];
        }
        
        // Find quiz questions with multiple products assigned
        $stmt = $pdo->query("
            SELECT 
                module_name,
                quiz_question_num,
                GROUP_CONCAT(product_sku SEPARATOR ', ') as products,
                COUNT(*) as product_count
            FROM training_module_examples
            WHERE example_type = 'quiz'
                AND quiz_question_num IS NOT NULL
                AND enabled = 1
            GROUP BY module_name, quiz_question_num
            HAVING COUNT(*) > 1
        ");
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("detect_quiz_conflicts error: " . $e->getMessage());
        return [];
    }
}

/**
 * Check for products with no training assignment
 * 
 * @return array Unassigned products
 */
function find_unassigned_training_products() {
    try {
        $pdo = db();
        
        $stmt = $pdo->query("
            SELECT sku, name, active
            FROM products
            WHERE active = 1
                AND sku LIKE 'BLEND-%'
                AND (use_in_training = 0 OR use_in_training IS NULL)
        ");
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Check for over-assigned products (used in too many contexts)
 * 
 * @param string $sku Product SKU
 * @return array Warning messages
 */
function check_product_overuse($sku) {
    try {
        $pdo = db();
        
        // Check if table exists
        $tables = $pdo->query("SHOW TABLES LIKE 'training_module_examples'")->fetchAll();
        if (empty($tables)) {
            return [];
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                module_name,
                example_type,
                COUNT(*) as usage_count
            FROM training_module_examples
            WHERE product_sku = ?
                AND enabled = 1
            GROUP BY module_name, example_type
        ");
        $stmt->execute([$sku]);
        $usage = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $warnings = [];
        $total_usage = 0;
        
        foreach ($usage as $u) {
            $total_usage += $u['usage_count'];
        }
        
        // Warning if product used in more than 8 modules
        if ($total_usage > 15) {
            $warnings[] = "Product used in {$total_usage} locations - may be overexposed";
        }
        
        // Check for quiz overuse (should be max 3-4 quiz questions per product)
        $quiz_count = 0;
        foreach ($usage as $u) {
            if ($u['example_type'] === 'quiz') {
                $quiz_count += $u['usage_count'];
            }
        }
        
        if ($quiz_count > 5) {
            $warnings[] = "Product used in {$quiz_count} quiz questions - consider distributing across products";
        }
        
        return $warnings;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Verify data sync between database and module files
 * 
 * @param string $sku Product SKU
 * @return array Sync status
 */
function verify_product_sync($sku) {
    $product = get_training_product($sku, false);
    if (!$product) {
        return [
            'status' => 'error',
            'message' => 'Product not found in database'
        ];
    }
    
    $usage = find_product_usage_in_modules($sku);
    $issues = [];
    $checks_passed = 0;
    $total_checks = 0;
    
    // Check 1: Product has use_in_training enabled
    $total_checks++;
    if ($product['use_in_training']) {
        $checks_passed++;
    } else {
        $issues[] = 'Product not enabled for training (use_in_training = 0)';
    }
    
    // Check 2: Product has required calculated fields
    $total_checks++;
    $required_fields = ['total_mg', 'servings_10mg', 'ml_per_10mg_serving', 'drinks_per_oz'];
    $missing_fields = [];
    foreach ($required_fields as $field) {
        if (empty($product[$field]) || $product[$field] <= 0) {
            $missing_fields[] = $field;
        }
    }
    
    if (empty($missing_fields)) {
        $checks_passed++;
    } else {
        $issues[] = 'Missing calculated fields: ' . implode(', ', $missing_fields);
    }
    
    // Check 3: Product referenced in module files
    $total_checks++;
    if (!empty($usage)) {
        $checks_passed++;
    } else {
        $issues[] = 'Product not found in any module files';
    }
    
    // Check 4: Bulk pricing exists
    $total_checks++;
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM bulk_pricing WHERE sku = ? AND active = 1");
        $stmt->execute([$sku]);
        $bulk_count = $stmt->fetchColumn();
        
        if ($bulk_count >= 4) {
            $checks_passed++;
        } else {
            $issues[] = "Only {$bulk_count} bulk pricing tiers configured (should be 5)";
        }
    } catch (Exception $e) {
        $issues[] = 'Unable to check bulk pricing';
    }
    
    // Check 5: Training examples tracked in database
    $total_checks++;
    try {
        $pdo = db();
        
        // Check if table exists first
        $tables = $pdo->query("SHOW TABLES LIKE 'training_module_examples'")->fetchAll();
        if (empty($tables)) {
            $issues[] = 'training_module_examples table not created yet (run migration)';
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM training_module_examples WHERE product_sku = ? AND enabled = 1");
            $stmt->execute([$sku]);
            $example_count = $stmt->fetchColumn();
            
            if ($example_count > 0) {
                $checks_passed++;
            } else {
                $issues[] = 'No training examples tracked in database';
            }
        }
    } catch (Exception $e) {
        $issues[] = 'Unable to check training examples: ' . $e->getMessage();
    }
    
    $sync_percent = $total_checks > 0 ? round(($checks_passed / $total_checks) * 100) : 0;
    
    return [
        'status' => $sync_percent >= 80 ? 'good' : ($sync_percent >= 60 ? 'warning' : 'error'),
        'sync_percent' => $sync_percent,
        'checks_passed' => $checks_passed,
        'total_checks' => $total_checks,
        'issues' => $issues,
        'usage_count' => count($usage),
        'module_count' => count($usage)
    ];
}

/**
 * Get quiz questions that use this product
 * 
 * @param string $sku Product SKU
 * @return array Quiz questions
 */
function get_product_quiz_questions($sku) {
    try {
        $pdo = db();
        
        // Check if table exists
        $tables = $pdo->query("SHOW TABLES LIKE 'training_module_examples'")->fetchAll();
        if (empty($tables)) {
            return [];
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                module_name,
                section_name,
                quiz_question_num,
                correct_answer_key,
                notes
            FROM training_module_examples
            WHERE product_sku = ?
                AND example_type = 'quiz'
                AND enabled = 1
            ORDER BY module_name, quiz_question_num
        ");
        $stmt->execute([$sku]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Analyze product assignment balance across training contexts
 * 
 * @return array Balance analysis
 */
function analyze_training_balance() {
    try {
        $pdo = db();
        
        $stmt = $pdo->query("
            SELECT 
                training_context,
                COUNT(*) as product_count,
                GROUP_CONCAT(sku SEPARATOR ', ') as products
            FROM products
            WHERE use_in_training = 1 AND active = 1
            GROUP BY training_context
        ");
        
        $balance = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Recommendations
        $recommendations = [];
        foreach ($balance as $b) {
            if ($b['product_count'] == 0) {
                $recommendations[] = "No products assigned to '{$b['training_context']}' context";
            } elseif ($b['product_count'] > 3) {
                $recommendations[] = "'{$b['training_context']}' context has {$b['product_count']} products - may be over-saturated";
            }
        }
        
        return [
            'balance' => $balance,
            'recommendations' => $recommendations
        ];
    } catch (Exception $e) {
        return ['balance' => [], 'recommendations' => []];
    }
}

/**
 * Extract code snippet showing how product is used
 * 
 * @param string $filepath Full path to module file
 * @param int $line_num Line number
 * @param int $context_lines Lines before/after to include
 * @return array Snippet details
 */
function extract_usage_snippet($filepath, $line_num, $context_lines = 3) {
    if (!file_exists($filepath)) {
        return null;
    }
    
    $lines = file($filepath);
    $start = max(0, $line_num - $context_lines - 1);
    $end = min(count($lines), $line_num + $context_lines);
    
    $snippet = '';
    for ($i = $start; $i < $end; $i++) {
        $marker = ($i == $line_num - 1) ? '>>> ' : '    ';
        $snippet .= $marker . ($i + 1) . '| ' . $lines[$i];
    }
    
    return [
        'snippet' => $snippet,
        'start_line' => $start + 1,
        'end_line' => $end,
        'target_line' => $line_num
    ];
}

/**
 * Check if product data matches what's in module files
 * 
 * @param string $sku Product SKU
 * @return array Comparison results
 */
function verify_module_file_sync($sku) {
    $product = get_training_product($sku, false);
    if (!$product) {
        return ['synced' => false, 'issues' => ['Product not found']];
    }
    
    $usage = find_product_usage_in_modules($sku);
    $issues = [];
    
    // Check if files reference old prices that don't match database
    foreach ($usage as $module) {
        foreach ($module['matches'] as $match) {
            $line = $match['line_content'];
            
            // Look for price references
            if (preg_match('/\$(\d+\.?\d*)/', $line, $price_match)) {
                $found_price = floatval($price_match[1]);
                
                // Check if this price matches any of our product prices
                $expected_prices = [
                    $product['wholesale_price'],
                    $product['msrp'],
                    $product['cost_per_10mg_serving']
                ];
                
                $is_valid_price = false;
                foreach ($expected_prices as $ep) {
                    if (abs($found_price - $ep) < 0.02) {
                        $is_valid_price = true;
                        break;
                    }
                }
                
                // Only flag if it's a suspiciously close but wrong price
                if (!$is_valid_price && 
                    $found_price > 10 && $found_price < 1000 &&
                    !stripos($line, '<?php')) {
                    
                    $issues[] = "{$module['module_name']}, line {$match['line_number']}: Found \${$found_price} (expected \${$product['wholesale_price']})";
                }
            }
        }
    }
    
    return [
        'synced' => empty($issues),
        'issues' => $issues,
        'scanned_modules' => count($usage),
        'scanned_lines' => array_sum(array_column($usage, 'match_count'))
    ];
}

