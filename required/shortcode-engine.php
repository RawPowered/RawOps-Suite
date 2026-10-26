<?php
/**
 * Universal Dynamic Shortcode Engine
 * WordPress-style shortcode system for Product platform
 * 
 * Works everywhere: LMS modules, emails, product pages, anywhere
 * Auto-syncs with database - no more hardcoded values!
 * 
 * Date: 2025-10-20
 * @author Product Development Team
 */

if (!defined('PRODUCT_SHORTCODES_LOADED')) {
    define('PRODUCT_SHORTCODES_LOADED', true);
}

/**
 * Global shortcode registry
 */
global $product_shortcodes;
$product_shortcodes = [];

/**
 * Register a shortcode handler
 * 
 * @param string $tag Shortcode name (e.g., 'product_price')
 * @param callable $callback Function to process the shortcode
 */
function register_shortcode($tag, $callback) {
    global $product_shortcodes;
    $product_shortcodes[$tag] = $callback;
}

/**
 * Parse and execute all shortcodes in content
 * 
 * @param string $content Content with shortcodes
 * @param array $context Optional context data (product_id, user_id, etc.)
 * @return string Content with shortcodes replaced
 */
function process_shortcodes($content, $context = []) {
    global $product_shortcodes;
    
    if (empty($content) || strpos($content, '[') === false) {
        return $content;
    }
    
    // Find all shortcodes: [shortcode_name param="value"]
    $pattern = '/\[([a-zA-Z0-9_-]+)([^\]]*)\]/';
    
    $processed = preg_replace_callback($pattern, function($matches) use ($context, $product_shortcodes) {
        $tag = $matches[1];
        $attrString = $matches[2];
        
        // Parse attributes
        $attrs = shortcode_parse_atts($attrString);
        
        // Merge with context
        $attrs = array_merge($context, $attrs);
        
        // Execute shortcode handler
        if (isset($product_shortcodes[$tag]) && is_callable($product_shortcodes[$tag])) {
            try {
                $result = call_user_func($product_shortcodes[$tag], $attrs, $content);
                return $result !== null ? $result : $matches[0];
            } catch (Exception $e) {
                error_log("Shortcode Error [$tag]: " . $e->getMessage());
                return "<span style='color: #dc2626; background: #fee2e2; padding: 2px 6px; border-radius: 3px; font-size: 11px;'>Error: $tag</span>";
            }
        }
        
        // Unknown shortcode - return as-is
        return $matches[0];
    }, $content);
    
    return $processed;
}

/**
 * Parse shortcode attributes from string
 * Supports: key="value", key='value', key=value
 * 
 * @param string $text Attribute string
 * @return array Associative array of attributes
 */
function shortcode_parse_atts($text) {
    $attrs = [];
    $text = trim($text);
    
    if (empty($text)) {
        return $attrs;
    }
    
    // Match key="value" or key='value' or key=value
    $pattern = '/(\w+)\s*=\s*["\']([^"\']*)["\']|(\w+)\s*=\s*([^\s]+)/';
    
    if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            if (!empty($match[1])) {
                // key="value" or key='value'
                $attrs[$match[1]] = $match[2];
            } elseif (!empty($match[3])) {
                // key=value
                $attrs[$match[3]] = $match[4];
            }
        }
    }
    
    return $attrs;
}

/**
 * Get product by SKU or ID
 * 
 * @param array $attrs Attributes (sku or id)
 * @return array|null Product data
 */
function shortcode_get_product($attrs) {
    $pdo = db();
    
    if (!empty($attrs['sku'])) {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE sku = ? LIMIT 1");
        $stmt->execute([$attrs['sku']]);
    } elseif (!empty($attrs['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
        $stmt->execute([intval($attrs['id'])]);
    } else {
        return null;
    }
    
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ============================================================================
// PRODUCT DATA SHORTCODES
// ============================================================================

/**
 * [product_price sku="BLEND-10-32"]
 * [product_price id="11"]
 * [product_price sku="BLEND-10-32" type="msrp"]
 */
register_shortcode('product_price', function($attrs) {
    $product = shortcode_get_product($attrs);
    if (!$product) return '<span class="sc-error">Product not found</span>';
    
    $type = $attrs['type'] ?? 'wholesale';
    $price = $type === 'msrp' ? $product['msrp'] : $product['wholesale_price'];
    
    $format = $attrs['format'] ?? 'currency';
    if ($format === 'currency') {
        return '$' . number_format($price, 2);
    } else {
        return number_format($price, 2);
    }
});

/**
 * [product_name sku="BLEND-10-32"]
 */
register_shortcode('product_name', function($attrs) {
    $product = shortcode_get_product($attrs);
    return $product ? htmlspecialchars($product['name']) : '<span class="sc-error">Product not found</span>';
});

/**
 * [product_servings sku="BLEND-10-32"]
 */
register_shortcode('product_servings', function($attrs) {
    $product = shortcode_get_product($attrs);
    if (!$product) return '<span class="sc-error">Product not found</span>';
    
    return number_format($product['servings_10mg'] ?? 0);
});

/**
 * [product_attribute sku="BLEND-10-32" type="per_oz"]
 * Types: per_oz, total, per_serving
 */
register_shortcode('product_attribute', function($attrs) {
    $product = shortcode_get_product($attrs);
    if (!$product) return '<span class="sc-error">Product not found</span>';
    
    $type = $attrs['type'] ?? 'per_oz';
    
    switch ($type) {
        case 'per_oz':
            return number_format($product['attribute_per_oz'] ?? 0, 0) . 'mg';
        case 'total':
            return number_format($product['total_mg'] ?? 0, 0) . 'mg';
        case 'per_serving':
            return '10mg'; // Standardized
        default:
            return number_format($product['attribute_per_oz'] ?? 0, 0) . 'mg';
    }
});

/**
 * [product_size sku="BLEND-10-32"]
 */
register_shortcode('product_size', function($attrs) {
    $product = shortcode_get_product($attrs);
    if (!$product) return '<span class="sc-error">Product not found</span>';
    
    return number_format($product['size_oz'] ?? 0, 0) . 'oz';
});

/**
 * [product_cost_per_serving sku="BLEND-10-32"]
 */
register_shortcode('product_cost_per_serving', function($attrs) {
    $product = shortcode_get_product($attrs);
    if (!$product) return '<span class="sc-error">Product not found</span>';
    
    return '$' . number_format($product['cost_per_10mg_serving'] ?? 0, 2);
});

/**
 * [product_ml_per_serving sku="BLEND-10-32"]
 */
register_shortcode('product_ml_per_serving', function($attrs) {
    $product = shortcode_get_product($attrs);
    if (!$product) return '<span class="sc-error">Product not found</span>';
    
    return number_format($product['ml_per_10mg_serving'] ?? 0, 2) . 'ml';
});

// ============================================================================
// CALCULATION SHORTCODES
// ============================================================================

/**
 * [profit_calc sku="BLEND-10-32" drink_price="12" base_cost="3"]
 */
register_shortcode('profit_calc', function($attrs) {
    $product = shortcode_get_product($attrs);
    if (!$product) return '<span class="sc-error">Product not found</span>';
    
    $drinkPrice = floatval($attrs['drink_price'] ?? 12);
    $baseCost = floatval($attrs['base_cost'] ?? 3);
    $servings = intval($product['servings_10mg'] ?? 0);
    $bottleCost = floatval($product['wholesale_price'] ?? 0);
    
    $revenue = $servings * $drinkPrice;
    $grossProfit = $revenue - $bottleCost;
    $netProfit = $grossProfit - ($servings * $baseCost);
    
    $format = $attrs['format'] ?? 'net_profit';
    
    switch ($format) {
        case 'revenue':
            return '$' . number_format($revenue, 2);
        case 'gross_profit':
            return '$' . number_format($grossProfit, 2);
        case 'net_profit':
            return '$' . number_format($netProfit, 2);
        case 'all':
            return "Revenue: $" . number_format($revenue, 2) . " | Gross: $" . number_format($grossProfit, 2) . " | Net: $" . number_format($netProfit, 2);
        default:
            return '$' . number_format($netProfit, 2);
    }
});

/**
 * [serving_calc sku="BLEND-10-32" customer_dose="15"]
 * Calculate how much product needed for custom dose
 */
register_shortcode('serving_calc', function($attrs) {
    $product = shortcode_get_product($attrs);
    if (!$product) return '<span class="sc-error">Product not found</span>';
    
    $desiredMg = floatval($attrs['customer_dose'] ?? 10);
    $attributePerOz = floatval($product['attribute_per_oz'] ?? 0);
    
    if ($attributePerOz == 0) return '<span class="sc-error">Invalid attribute content</span>';
    
    $ozNeeded = $desiredMg / $attributePerOz;
    $mlNeeded = $ozNeeded * 29.5735;
    
    $format = $attrs['format'] ?? 'ml';
    
    if ($format === 'ml') {
        return number_format($mlNeeded, 2) . 'ml';
    } elseif ($format === 'oz') {
        return number_format($ozNeeded, 4) . 'oz';
    } else {
        return number_format($mlNeeded, 2) . 'ml (' . number_format($ozNeeded, 4) . 'oz)';
    }
});

// ============================================================================
// QUIZ SHORTCODES
// ============================================================================

/**
 * [quiz id="25"]
 * [quiz product="11" position="mid"]
 * [quiz sku="BLEND-10-32" position="intro"]
 */
register_shortcode('quiz', function($attrs) {
    $pdo = db();
    $quiz = null;
    
    // Method 1: Specific quiz ID
    if (!empty($attrs['id'])) {
        $stmt = $pdo->prepare("
            SELECT tqq.*, p.sku, p.name as product_name
            FROM training_quiz_questions tqq
            LEFT JOIN products p ON p.id = tqq.product_id
            WHERE tqq.id = ? AND tqq.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([intval($attrs['id'])]);
        $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    // Method 2: By product + position
    elseif (!empty($attrs['product']) || !empty($attrs['sku'])) {
        $position = $attrs['position'] ?? 'mid';
        
        if (!empty($attrs['sku'])) {
            $stmt = $pdo->prepare("
                SELECT tqq.*, p.sku, p.name as product_name
                FROM training_quiz_questions tqq
                JOIN products p ON p.id = tqq.product_id
                WHERE p.sku = ? AND tqq.quiz_position = ? AND tqq.is_active = 1
                ORDER BY tqq.sort_order, RAND()
                LIMIT 1
            ");
            $stmt->execute([$attrs['sku'], $position]);
        } else {
            $stmt = $pdo->prepare("
                SELECT tqq.*, p.sku, p.name as product_name
                FROM training_quiz_questions tqq
                LEFT JOIN products p ON p.id = tqq.product_id
                WHERE tqq.product_id = ? AND tqq.quiz_position = ? AND tqq.is_active = 1
                ORDER BY tqq.sort_order, RAND()
                LIMIT 1
            ");
            $stmt->execute([intval($attrs['product']), $position]);
        }
        $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$quiz) {
        return '<div class="sc-error" style="color: #991b1b; background: #fee2e2; padding: 8px; border-radius: 4px; font-size: 12px;">Quiz not found</div>';
    }
    
    // Render quiz
    return render_quiz_shortcode($quiz, $attrs);
});

/**
 * Render quiz HTML from quiz data
 */
function render_quiz_shortcode($quiz, $attrs = []) {
    $options = json_decode($quiz['options'], true) ?? [];
    $questionId = 'q_sc_' . $quiz['id'] . '_' . uniqid();
    $correctAnswer = $quiz['correct_answer'];
    $explanation = $quiz['explanation'] ?? '';
    
    $style = $attrs['style'] ?? 'default';
    
    $html = '<div class="quiz-question sc-quiz" data-quiz-id="' . $quiz['id'] . '" style="background: #f9fafb; padding: 20px; border-radius: 12px; margin: 24px 0;">' . "\n";
    $html .= '    <p style="font-weight: 600; color: #1f2937; margin-bottom: 16px;"><strong>Question:</strong> ' . htmlspecialchars($quiz['question_text']) . '</p>' . "\n";
    $html .= '    <div class="quiz-options" style="display: flex; flex-direction: column; gap: 8px;">' . "\n";
    
    foreach ($options as $key => $optionText) {
        if (!empty($optionText)) {
            $html .= '        <label style="padding: 12px; background: white; border: 2px solid #e5e7eb; border-radius: 6px; cursor: pointer; transition: all 0.2s;">' . "\n";
            $html .= '            <input type="radio" name="' . $questionId . '" value="' . $key . '" style="margin-right: 8px;"> ';
            $html .= '<strong>' . strtoupper($key) . ':</strong> ' . htmlspecialchars($optionText) . "\n";
            $html .= '        </label>' . "\n";
        }
    }
    
    $html .= '    </div>' . "\n";
    $html .= '    <div class="quiz-answer" style="margin-top: 16px; padding: 12px; background: #dbeafe; border-left: 4px solid #3b82f6; border-radius: 6px; display: none;">' . "\n";
    $html .= '        <strong style="color: #1e40af;">Correct Answer: ' . strtoupper($correctAnswer) . '</strong>';
    if ($explanation) {
        $html .= ' - ' . htmlspecialchars($explanation);
    }
    $html .= "\n    </div>\n";
    $html .= '</div>' . "\n";
    
    // Add interactivity script
    $html .= '<script>' . "\n";
    $html .= 'document.querySelectorAll(\'input[name="' . $questionId . '"]\').forEach(input => {' . "\n";
    $html .= '    input.addEventListener(\'change\', function() {' . "\n";
    $html .= '        const answer = this.closest(\'.sc-quiz\').querySelector(\'.quiz-answer\');' . "\n";
    $html .= '        answer.style.display = \'block\';' . "\n";
    $html .= '        if (this.value === \'' . $correctAnswer . '\') {' . "\n";
    $html .= '            answer.style.background = \'#dcfce7\';' . "\n";
    $html .= '            answer.style.borderColor = \'#16a34a\';' . "\n";
    $html .= '        } else {' . "\n";
    $html .= '            answer.style.background = \'#fee2e2\';' . "\n";
    $html .= '            answer.style.borderColor = \'#dc2626\';' . "\n";
    $html .= '        }' . "\n";
    $html .= '    });' . "\n";
    $html .= '});' . "\n";
    $html .= '</script>' . "\n";
    
    return $html;
}

/**
 * [product_sku id="11"]
 */
register_shortcode('product_sku', function($attrs) {
    $product = shortcode_get_product($attrs);
    return $product ? htmlspecialchars($product['sku']) : '<span class="sc-error">Product not found</span>';
});

/**
 * [product_drinks_per_oz sku="BLEND-10-32"]
 */
register_shortcode('product_drinks_per_oz', function($attrs) {
    $product = shortcode_get_product($attrs);
    if (!$product) return '<span class="sc-error">Product not found</span>';
    
    return number_format($product['drinks_per_oz'] ?? 0, 1);
});

/**
 * [product_total_revenue sku="BLEND-10-32" drink_price="12"]
 */
register_shortcode('product_total_revenue', function($attrs) {
    $product = shortcode_get_product($attrs);
    if (!$product) return '<span class="sc-error">Product not found</span>';
    
    $drinkPrice = floatval($attrs['drink_price'] ?? 12);
    $servings = intval($product['servings_10mg'] ?? 0);
    $revenue = $servings * $drinkPrice;
    
    return '$' . number_format($revenue, 2);
});

/**
 * [product_group_name sku="BLEND-10-32"]
 * Get the product group name for a product
 */
register_shortcode('product_group_name', function($attrs) {
    $product = shortcode_get_product($attrs);
    if (!$product || !$product['product_group_id']) {
        return '<span class="sc-error">No product group</span>';
    }
    
    $pdo = db();
    $stmt = $pdo->prepare("SELECT name FROM product_groups WHERE id = ?");
    $stmt->execute([$product['product_group_id']]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $group ? htmlspecialchars($group['name']) : '<span class="sc-error">Group not found</span>';
});

/**
 * [product_description sku="BLEND-10-32" length="100"]
 * Get product description, optionally truncated
 */
register_shortcode('product_description', function($attrs) {
    $product = shortcode_get_product($attrs);
    if (!$product) return '<span class="sc-error">Product not found</span>';
    
    $description = $product['description'] ?? '';
    $length = intval($attrs['length'] ?? 0);
    
    if ($length > 0 && strlen($description) > $length) {
        $description = substr($description, 0, $length) . '...';
    }
    
    return htmlspecialchars($description);
});

/**
 * [if_product_active sku="BLEND-10-32"]Content if active[/if_product_active]
 * Conditional shortcode
 */
register_shortcode('if_product_active', function($attrs, $content) {
    $product = shortcode_get_product($attrs);
    
    // Extract content between tags
    if (preg_match('/\[if_product_active[^\]]*\](.*?)\[\/if_product_active\]/s', $content, $matches)) {
        $innerContent = $matches[1];
    } else {
        return '';
    }
    
    return ($product && $product['active']) ? $innerContent : '';
});

/**
 * [product_link sku="BLEND-10-32" text="View Product"]
 * Create a link to product page
 */
register_shortcode('product_link', function($attrs) {
    $product = shortcode_get_product($attrs);
    if (!$product) return '<span class="sc-error">Product not found</span>';
    
    $text = $attrs['text'] ?? $product['name'];
    
    // Use function if available, otherwise build URL manually
    if (function_exists('path_with_base')) {
        $url = path_with_base('/shop/product.php?sku=' . urlencode($product['sku']));
    } else {
        $url = '/shop/product.php?sku=' . urlencode($product['sku']);
    }
    
    return '<a href="' . htmlspecialchars($url) . '" style="color: #3b82f6; text-decoration: underline;">' . htmlspecialchars($text) . '</a>';
});

// ============================================================================
// CONTENT BLOCK SHORTCODES
// ============================================================================

/**
 * [highlight]Important content here[/highlight]
 * [highlight type="success"]Success message[/highlight]
 * Note: For simple usage without closing tag, use content attribute:
 * [highlight type="success" content="Success message"]
 */
register_shortcode('highlight', function($attrs, $fullContent) {
    $type = $attrs['type'] ?? 'info';
    
    $colors = [
        'info' => ['bg' => '#dbeafe', 'border' => '#3b82f6', 'text' => '#1e40af'],
        'success' => ['bg' => '#dcfce7', 'border' => '#16a34a', 'text' => '#14532d'],
        'warning' => ['bg' => '#fef3c7', 'border' => '#f59e0b', 'text' => '#92400e'],
        'error' => ['bg' => '#fee2e2', 'border' => '#dc2626', 'text' => '#991b1b'],
    ];
    
    $color = $colors[$type] ?? $colors['info'];
    
    // Try to extract content between tags
    $innerContent = '';
    if (preg_match('/\[highlight[^\]]*\](.*?)\[\/highlight\]/s', $fullContent, $matches)) {
        $innerContent = $matches[1];
    } elseif (isset($attrs['content'])) {
        $innerContent = $attrs['content'];
    } else {
        $innerContent = 'Highlighted content';
    }
    
    return '<div style="background: ' . $color['bg'] . '; border-left: 4px solid ' . $color['border'] . '; padding: 16px; border-radius: 6px; margin: 16px 0; color: ' . $color['text'] . ';">' . htmlspecialchars($innerContent) . '</div>';
});

/**
 * [product_comparison sku1="BLEND-10-32" sku2="BLEND-40-32"]
 */
register_shortcode('product_comparison', function($attrs) {
    $pdo = db();
    
    $sku1 = $attrs['sku1'] ?? '';
    $sku2 = $attrs['sku2'] ?? '';
    
    if (empty($sku1) || empty($sku2)) {
        return '<span class="sc-error">Two SKUs required</span>';
    }
    
    $stmt = $pdo->prepare("SELECT * FROM products WHERE sku IN (?, ?) AND active = 1");
    $stmt->execute([$sku1, $sku2]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($products) < 2) {
        return '<span class="sc-error">Products not found</span>';
    }
    
    $p1 = $products[0];
    $p2 = $products[1];
    
    $html = '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin: 24px 0;">';
    
    foreach ([$p1, $p2] as $p) {
        $html .= '<div style="background: #f9fafb; padding: 20px; border-radius: 8px; border: 2px solid #e5e7eb;">';
        $html .= '<h4 style="margin: 0 0 12px 0; color: #1f2937;">' . htmlspecialchars($p['name']) . '</h4>';
        $html .= '<div style="font-size: 14px; color: #6b7280;">';
        $html .= '<div style="margin-bottom: 8px;"><strong>Price:</strong> $' . number_format($p['wholesale_price'], 2) . '</div>';
        $html .= '<div style="margin-bottom: 8px;"><strong>attribute per oz:</strong> ' . number_format($p['attribute_per_oz'], 0) . 'mg</div>';
        $html .= '<div style="margin-bottom: 8px;"><strong>Size:</strong> ' . number_format($p['size_oz'], 0) . 'oz</div>';
        $html .= '<div style="margin-bottom: 8px;"><strong>Servings:</strong> ' . number_format($p['servings_10mg'], 0) . '</div>';
        $html .= '<div><strong>Cost/serving:</strong> $' . number_format($p['cost_per_10mg_serving'], 2) . '</div>';
        $html .= '</div>';
        $html .= '</div>';
    }
    
    $html .= '</div>';
    
    return $html;
});

/**
 * [product_table skus="BLEND-10-32,BLEND-40-32,BLEND-80-32"]
 */
register_shortcode('product_table', function($attrs) {
    $pdo = db();
    
    $skus = explode(',', $attrs['skus'] ?? '');
    $skus = array_map('trim', $skus);
    
    if (empty($skus)) {
        return '<span class="sc-error">No SKUs provided</span>';
    }
    
    $placeholders = str_repeat('?,', count($skus) - 1) . '?';
    $stmt = $pdo->prepare("SELECT * FROM products WHERE sku IN ($placeholders) AND active = 1 ORDER BY attribute_per_oz ASC");
    $stmt->execute($skus);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($products)) {
        return '<span class="sc-error">No products found</span>';
    }
    
    $html = '<table style="width: 100%; border-collapse: collapse; margin: 24px 0; background: white; border-radius: 8px; overflow: hidden;">';
    $html .= '<thead><tr style="background: #f3f4f6;">';
    $html .= '<th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Product</th>';
    $html .= '<th style="padding: 12px; text-align: right; border-bottom: 2px solid #e5e7eb;">attribute/oz</th>';
    $html .= '<th style="padding: 12px; text-align: right; border-bottom: 2px solid #e5e7eb;">Size</th>';
    $html .= '<th style="padding: 12px; text-align: right; border-bottom: 2px solid #e5e7eb;">Servings</th>';
    $html .= '<th style="padding: 12px; text-align: right; border-bottom: 2px solid #e5e7eb;">Price</th>';
    $html .= '<th style="padding: 12px; text-align: right; border-bottom: 2px solid #e5e7eb;">Cost/Serving</th>';
    $html .= '</tr></thead><tbody>';
    
    foreach ($products as $p) {
        $html .= '<tr style="border-bottom: 1px solid #f3f4f6;">';
        $html .= '<td style="padding: 12px;"><strong>' . htmlspecialchars($p['name']) . '</strong><br><small style="color: #6b7280;">' . $p['sku'] . '</small></td>';
        $html .= '<td style="padding: 12px; text-align: right;">' . number_format($p['attribute_per_oz'], 0) . 'mg</td>';
        $html .= '<td style="padding: 12px; text-align: right;">' . number_format($p['size_oz'], 0) . 'oz</td>';
        $html .= '<td style="padding: 12px; text-align: right;">' . number_format($p['servings_10mg'], 0) . '</td>';
        $html .= '<td style="padding: 12px; text-align: right;">$' . number_format($p['wholesale_price'], 2) . '</td>';
        $html .= '<td style="padding: 12px; text-align: right;">$' . number_format($p['cost_per_10mg_serving'], 2) . '</td>';
        $html .= '</tr>';
    }
    
    $html .= '</tbody></table>';
    
    return $html;
});

/**
 * Get list of all registered shortcodes
 */
function get_registered_shortcodes() {
    global $product_shortcodes;
    return array_keys($product_shortcodes);
}

/**
 * Test if shortcode engine is loaded
 */
function shortcodes_enabled() {
    return defined('PRODUCT_SHORTCODES_LOADED');
}

// Auto-load shortcode engine in headers
if (!function_exists('auto_process_shortcodes')) {
    function auto_process_shortcodes($content) {
        return process_shortcodes($content);
    }
}

