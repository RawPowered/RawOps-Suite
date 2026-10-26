<?php
/**
 * AI Email Composer API
 * Generates professional sales emails using Gemini AI
 * Tracks usage, applies AI commission fees, provides admin oversight
 */

// Suppress all output except JSON
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/email-functions.php';
require_once __DIR__ . '/sendgrid.php';
require_once __DIR__ . '/email-workflow-processor.php';

/**
 * Recursively strip duplicate header/footer content from AI-generated emails
 * Sometimes AI includes "Product" headers or copyright footers despite instructions
 */
function stripDuplicateHeaderFooter($html) {
    $original = $html;
    
    // Remove any "Product" standalone headings at the top
    $html = preg_replace('/^[\s]*<[^>]*>\s*Product[-\s]?It\s+Product\s*<\/[^>]*>[\s]*/i', '', $html);
    $html = preg_replace('/^[\s]*<h[1-6][^>]*>\s*Product[-\s]?It\s+Product\s*<\/h[1-6]>[\s]*/i', '', $html);
    
    // Remove table-based header structures (common in email templates)
    $html = preg_replace('/<table[^>]*>\s*<tr>\s*<td[^>]*>\s*<h[1-6][^>]*>\s*Product[-\s]?It\s+Product\s*<\/h[1-6]>\s*<\/td>\s*<\/tr>\s*<\/table>/i', '', $html);
    
    // Remove copyright footer text
    $html = preg_replace('/<[^>]*>\s*©\s*\d{4}\s*Product[-\s]?It.*?<\/[^>]*>/i', '', $html);
    $html = preg_replace('/<[^>]*>\s*Copyright.*?Product[-\s]?It.*?<\/[^>]*>/i', '', $html);
    
    // Remove logo/image references
    $html = preg_replace('/<img[^>]*alt=["\']*Product[-\s]?It[^>]*>/i', '', $html);
    
    // Remove any disclaimer/footer text
    $html = preg_replace('/<[^>]*>\s*This\s+email\s+was\s+sent.*?<\/[^>]*>/i', '', $html);
    
    // Recursively check if we removed anything - keep cleaning until no more changes
    if ($html !== $original) {
        return stripDuplicateHeaderFooter(trim($html));
    }
    
    return trim($html);
}

/**
 * Validate color contrast to prevent invisible text
 * Checks for problematic color combinations and fixes them
 */
function validateColorContrast($html) {
    // List of problematic same-color combinations
    $problematic = [
        // Dark text on dark backgrounds
        ['text' => '#000000', 'bg' => '#000000'],
        ['text' => '#1f2937', 'bg' => '#1e293b'],
        ['text' => '#111827', 'bg' => '#0f172a'],
        // White text on white backgrounds
        ['text' => '#ffffff', 'bg' => '#ffffff'],
        ['text' => '#f8fafc', 'bg' => '#ffffff'],
        ['text' => '#ffffff', 'bg' => '#f9fafb'],
    ];
    
    // Parse and validate inline styles
    $html = preg_replace_callback('/style="([^"]+)"/i', function($matches) {
        $style = $matches[1];
        
        // Extract color and background-color
        $textColor = null;
        $bgColor = null;
        
        if (preg_match('/color:\s*([#\w]+)/i', $style, $colorMatch)) {
            $textColor = strtolower($colorMatch[1]);
        }
        if (preg_match('/background(?:-color)?:\s*([#\w]+)/i', $style, $bgMatch)) {
            $bgColor = strtolower($bgMatch[1]);
        }
        
        // Check for problematic combinations
        if ($textColor && $bgColor) {
            // Normalize hex colors
            $textColor = normalizeColor($textColor);
            $bgColor = normalizeColor($bgColor);
            
            // If colors are too similar (potential invisible text)
            if (areColorsSimilar($textColor, $bgColor)) {
                // Fix: Make text color contrasting
                if (isLightColor($bgColor)) {
                    $style = preg_replace('/color:\s*[#\w]+/i', 'color:#1f2937', $style);
                } else {
                    $style = preg_replace('/color:\s*[#\w]+/i', 'color:#ffffff', $style);
                }
            }
        }
        
        return 'style="' . $style . '"';
    }, $html);
    
    return $html;
}

function normalize_workflow_selection_payload($raw) {
    $result = [
        'mode' => null,
        'selection' => null,
        'json' => null,
        'reason' => null,
        'should_clear' => false
    ];
    
    if ($raw === null) {
        $result['should_clear'] = true;
        return $result;
    }
    
    if (!is_array($raw)) {
        return $result;
    }
    
    $mode = $raw['mode'] ?? null;
    $reason = trim($raw['reason'] ?? '');
    if ($reason === '') {
        $reason = 'Agent attached workflow from composer';
    }
    
    if ($mode === 'single') {
        $workflowId = intval($raw['workflow_id'] ?? 0);
        if ($workflowId <= 0) {
            $result['should_clear'] = true;
            return $result;
        }
        $selection = [
            'workflow_id' => $workflowId,
            'source' => 'composer'
        ];
        $result['mode'] = 'single';
        $result['selection'] = $selection;
        $result['json'] = json_encode($selection, JSON_UNESCAPED_SLASHES);
        $result['reason'] = $reason;
        return $result;
    }
    
    if ($mode === 'conditional') {
        $openedId = intval($raw['opened_workflow_id'] ?? 0) ?: null;
        $unopenedId = intval($raw['unopened_workflow_id'] ?? 0) ?: null;
        if (!$openedId && !$unopenedId) {
            $result['should_clear'] = true;
            return $result;
        }
        $decisionWait = max(1, intval($raw['decision_wait_hours'] ?? 48));
        $selection = [
            'opened_workflow_id' => $openedId,
            'unopened_workflow_id' => $unopenedId,
            'decision_wait_hours' => $decisionWait,
            'source' => 'composer'
        ];
        $result['mode'] = 'conditional';
        $result['selection'] = $selection;
        $result['json'] = json_encode($selection, JSON_UNESCAPED_SLASHES);
        $result['reason'] = $reason;
        return $result;
    }
    
    $result['should_clear'] = true;
    return $result;
}

function apply_workflow_selection_payload(PDO $pdo, int $emailId, array $payload): void {
    if (!empty($payload['mode']) && $payload['json']) {
        $stmt = $pdo->prepare('UPDATE agent_emails SET workflow_selection_mode = ?, workflow_selection_json = ?, workflow_schedule_reason = ? WHERE id = ?');
        $stmt->execute([$payload['mode'], $payload['json'], $payload['reason'], $emailId]);
    } elseif (!empty($payload['should_clear'])) {
        $stmt = $pdo->prepare('UPDATE agent_emails SET workflow_selection_mode = NULL, workflow_selection_json = NULL, workflow_schedule_reason = NULL WHERE id = ?');
        $stmt->execute([$emailId]);
    }
}

function normalizeColor($color) {
    $color = strtolower(trim($color));
    // Convert 3-digit hex to 6-digit
    if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i', $color, $m)) {
        return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
    }
    return $color;
}

function areColorsSimilar($color1, $color2) {
    // Extract RGB values
    $rgb1 = hexToRgb($color1);
    $rgb2 = hexToRgb($color2);
    
    if (!$rgb1 || !$rgb2) return false;
    
    // Calculate color distance
    $distance = sqrt(
        pow($rgb1['r'] - $rgb2['r'], 2) +
        pow($rgb1['g'] - $rgb2['g'], 2) +
        pow($rgb1['b'] - $rgb2['b'], 2)
    );
    
    // If distance < 100 (out of 441 max), colors are too similar
    return $distance < 100;
}

function isLightColor($color) {
    $rgb = hexToRgb($color);
    if (!$rgb) return true;
    
    // Calculate perceived brightness
    $brightness = (($rgb['r'] * 299) + ($rgb['g'] * 587) + ($rgb['b'] * 114)) / 1000;
    
    return $brightness > 128;
}

function hexToRgb($hex) {
    $hex = ltrim($hex, '#');
    
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    
    if (strlen($hex) !== 6) return null;
    
    return [
        'r' => hexdec(substr($hex, 0, 2)),
        'g' => hexdec(substr($hex, 2, 2)),
        'b' => hexdec(substr($hex, 4, 2))
    ];
}

/**
 * Build context description for follow-up email based on recipient behavior
 */
function build_followup_behavior_context(string $behaviorType, array $parentEmail, array $conversions): string {
    $openCount = intval($parentEmail['open_count'] ?? 0);
    $clickCount = intval($parentEmail['click_count'] ?? 0);
    
    $context = '';
    
    switch ($behaviorType) {
        case 'unopened':
            $context = "The recipient has not opened your previous email yet (sent " . 
                       date('F j, Y', strtotime($parentEmail['sent_at'])) . "). " .
                       "This is a gentle reminder - they may have missed it or it ended up in spam.";
            break;
            
        case 'opened_not_clicked':
            $context = "The recipient opened your email {$openCount} time(s) but did not click any links. " .
                       "They showed interest by reading it but didn't take the next step.";
            break;
            
        case 'clicked_no_action':
            $context = "The recipient opened and clicked through from your email but didn't complete any actions on the website. " .
                       "They're interested but may need more information or have questions.";
            break;
            
        case 'clicked_no_sale':
            $conversionTypes = array_unique(array_column($conversions, 'conversion_type'));
            $context = "The recipient engaged with your email and visited the website. " .
                       "Actions taken: " . implode(', ', $conversionTypes) . ". " .
                       "They're exploring but haven't made a purchase yet.";
            break;
            
        case 'clicked_sale_with_agent':
            $context = "Great news! The recipient made a purchase after your email. " .
                       "This is a thank you and relationship-building follow-up.";
            break;
            
        case 'clicked_sale_no_agent':
            $context = "The recipient made a purchase after clicking your email but without agent follow-up. " .
                       "This is an opportunity to build rapport and ensure satisfaction.";
            break;
            
        case 'clicked_ai_interaction':
            $context = "The recipient engaged with your email, visited the site, AND used the AI chat assistant. " .
                       "They're actively seeking information. This is a warm lead.";
            break;
            
        default:
            $context = "Following up on previous email communication.";
    }
    
    return $context;
}

start_session();

// Authentication check
$uid = current_user_id();
if (!$uid) {
    ob_clean();
    http_response_code(401);
    die(json_encode(['ok' => false, 'error' => 'Not authenticated']));
}

$pdo = db();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Verify sales agent role (or admin simulation)
$isAdminSimulation = isset($_SESSION['admin_simulation']);
$isAdmin = current_admin_id() !== null;
if (!$isAdminSimulation && !$isAdmin) {
    $stmt = $pdo->prepare('SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.name = "sales_agent"');
    $stmt->execute([$uid]);
    if (!$stmt->fetch()) {
        ob_clean();
        http_response_code(403);
        die(json_encode(['ok' => false, 'error' => 'Access denied. Sales agent role required.']));
    }
}

try {
    switch ($action) {
        case 'generate':
            // Check API restriction before processing
            require_once __DIR__ . '/../required/api-restriction-checker.php';
            $check = check_api_access($uid, 'ai_email');
            if (!$check['allowed']) {
                echo json_encode([
                    'ok' => false,
                    'error' => 'api_restricted',
                    'message' => $check['reason'],
                    'stats' => $check['stats'],
                    'needs_details_count' => $check['needs_details_count'],
                    'threshold' => $check['threshold'] ?? 70,
                    'exemption_active' => $check['exemption_active']
                ]);
                exit;
            }
            
            // Generate AI email content
            $input = json_decode(file_get_contents('php://input'), true);
            $prompt = trim($input['prompt'] ?? '');
            $context = $input['context'] ?? [];
            
            if ($prompt === '') {
                throw new Exception('Prompt is required');
            }
            
            // Build context for AI
            $leadId = intval($context['lead_id'] ?? 0);
            $accountId = intval($context['account_id'] ?? 0);
            $orderId = intval($context['order_id'] ?? 0); // Track order context if available
            $recipientName = $context['recipient_name'] ?? '';
            $companyName = $context['company_name'] ?? '';
            
            // Get sales agent information
            $agentName = 'Sales Representative';
            $agentEmail = 'sample-006@example.com';
            $agentPhone = '';
            try {
                $stmt = $pdo->prepare('SELECT first_name, last_name, email, phone FROM users WHERE id = ?');
                $stmt->execute([$uid]);
                $agent = $stmt->fetch();
                if ($agent) {
                    $agentName = trim($agent['first_name'] . ' ' . $agent['last_name']);
                    $agentEmail = $agent['email'];
                    $agentPhone = $agent['phone'] ?? '';
                }
            } catch (Throwable $e) {}
            
            // Get current products from database
            $products = [];
            try {
                $stmt = $pdo->query("SELECT sku, name, wholesale_price, msrp, description FROM products WHERE active = 1 ORDER BY name LIMIT 20");
                $productRows = $stmt->fetchAll();
                foreach ($productRows as $p) {
                    $products[] = sprintf(
                        "%s (%s) - Wholesale: $%.2f, MSRP: $%.2f%s",
                        $p['name'],
                        $p['sku'],
                        (float)$p['wholesale_price'],
                        (float)$p['msrp'],
                        !empty($p['description']) ? " - " . substr($p['description'], 0, 100) : ""
                    );
                }
            } catch (Throwable $e) {
                $products = ['Product data unavailable'];
            }
            $productsContext = !empty($products) ? implode("\n", $products) : 'No products available';
            
            // Get existing activity context if available
            $activityContext = '';
            if ($leadId > 0) {
                $stmt = $pdo->prepare('SELECT company_name, first_name, last_name, lead_status, interest_level FROM crm_leads WHERE id = ?');
                $stmt->execute([$leadId]);
                $lead = $stmt->fetch();
                if ($lead) {
                    $activityContext = "Lead: {$lead['company_name']}, Status: {$lead['lead_status']}, Interest Level: {$lead['interest_level']}/10";
                    
                    // Get demo history for this lead
                    try {
                        $demoStmt = $pdo->prepare('SELECT dt.product_name, dd.dropoff_date FROM crm_demo_dropoffs dd JOIN demo_dropoff_types dt ON dt.id = dd.demo_type_id WHERE dd.lead_id = ? ORDER BY dd.dropoff_date DESC LIMIT 3');
                        $demoStmt->execute([$leadId]);
                        $demos = $demoStmt->fetchAll();
                        if (!empty($demos)) {
                            $demoList = [];
                            foreach ($demos as $d) {
                                $demoList[] = $d['product_name'] . ' (' . date('M j', strtotime($d['dropoff_date'])) . ')';
                            }
                            $activityContext .= "\nDemos dropped: " . implode(', ', $demoList);
                        }
                    } catch (Throwable $e) {}
                }
            } elseif ($accountId > 0) {
                $stmt = $pdo->prepare('SELECT wa.business_name, u.first_name, u.last_name, (SELECT COUNT(*) FROM orders WHERE wholesale_account_id = wa.id) as order_count FROM wholesale_accounts wa JOIN users u ON u.id = wa.user_id WHERE wa.id = ?');
                $stmt->execute([$accountId]);
                $acc = $stmt->fetch();
                if ($acc) {
                    $activityContext = "Account: {$acc['business_name']}, Total Orders: {$acc['order_count']}";
                    
                    // Get recent orders for this account
                    try {
                        $orderStmt = $pdo->prepare('SELECT order_number, total, created_at FROM orders WHERE wholesale_account_id = ? ORDER BY created_at DESC LIMIT 3');
                        $orderStmt->execute([$accountId]);
                        $orders = $orderStmt->fetchAll();
                        if (!empty($orders)) {
                            $orderList = [];
                            foreach ($orders as $o) {
                                $orderList[] = $o['order_number'] . ' ($' . number_format($o['total'], 2) . ' on ' . date('M j', strtotime($o['created_at'])) . ')';
                            }
                            $activityContext .= "\nRecent orders: " . implode(', ', $orderList);
                        }
                    } catch (Throwable $e) {}
                }
            }
            
            // Build AI prompt with sales best practices
            
            // Get active promo codes if any exist in database
            $activeCoupons = [];
            try {
                // Check if promo_codes table exists and get active codes
                $stmt = $pdo->query("SELECT code, description, discount_percent, discount_amount FROM promo_codes WHERE active = 1 AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 5");
                $couponRows = $stmt->fetchAll();
                foreach ($couponRows as $c) {
                    $discountText = $c['discount_percent'] > 0 ? "{$c['discount_percent']}% off" : "$" . number_format($c['discount_amount'], 2) . " off";
                    $activeCoupons[] = "Code: {$c['code']} - {$discountText}" . ($c['description'] ? " ({$c['description']})" : "");
                }
            } catch (Throwable $e) {
                // Table doesn't exist or query failed - no coupons available
                $activeCoupons = [];
            }
            
            $couponInstructions = '';
            if (!empty($activeCoupons)) {
                $couponInstructions = "\n**ACTIVE PROMO CODES (from database):**\n" . implode("\n", $activeCoupons) . "\n\n✅ You may mention these REAL promo codes if relevant to the email.";
            } else {
                $couponInstructions = "\n**PROMO CODES:**\n❌ NO active promo codes exist in database\n⚠️ DO NOT generate fake coupon codes\n⚠️ DO NOT promise specific discounts unless they exist in our system\n✅ If asked about promotions, say: \"Contact me to find out more about ongoing promotions!\"";
            }
            
            // Get dynamic base URL from config
            $baseUrl = site_base_url(); // Uses config.php for dynamic URL
            $shopUrl = absolute_url('/shop/');
            $loginUrl = absolute_url('/login.php');
            
            global $gemini_api_key;
            $systemPrompt = <<<PROMPT
You are a professional sales email writer for Product, a premium wholesale product supplier. 

Your task is to write a compelling, professional sales email based on the user's request.

**CRITICAL PRODUCT INFORMATION:**
⚠️ Product sells PRODUCT CONCENTRATES/EXTRACTS - NOT product format varieties!
⚠️ DO NOT mention: White Product B, Red Product A, Green Malay, or any color-based product format product categories
⚠️ We do NOT sell traditional Product format by product categories color
⚠️ ONLY reference products from the CURRENT PRODUCT CATALOG provided below
⚠️ Use EXACT product names and SKUs from the database - do NOT make up product names

**CRITICAL URL INFORMATION:**
⚠️ Our website is: {$baseUrl}
⚠️ Shop page: {$shopUrl}
⚠️ Login page: {$loginUrl}
⚠️ DO NOT use old domains like product.example or product.example
✅ ALWAYS use the dynamic base URL provided above
{$couponInstructions}

**Brand Voice Guidelines:**
- Professional but friendly and approachable
- Focus on quality, reliability, and partnership
- Emphasize our premium CONCENTRATE/EXTRACT product line
- Competitive wholesale pricing with volume discounts
- Use natural, conversational language (avoid overly formal corporate speak)

**Email Best Practices:**
- Keep it concise (2-3 paragraphs maximum)
- Clear call-to-action
- Personalize when possible
- Focus on customer benefits, not just features
- Professional subject lines (avoid spam triggers)
- Reference ONLY products from our database (see below)

**CURRENT PRODUCT CATALOG (from database):**
{$productsContext}

**Context for this email:**
{$activityContext}
Sales Agent: {$agentName} ({$agentEmail})
Recipient: {$recipientName} at {$companyName}

**User's Request:**
{$prompt}

**CRITICAL PRODUCT RULES:**
1. ⚠️ Product sells PRODUCT CONCENTRATES/EXTRACTS - NOT product format!
2. ⚠️ NEVER mention: White Product B, Red Product A, Green Malay, White Borneo, Red Thai, or ANY color-based product categories names
3. ⚠️ We do NOT sell traditional Product format varieties
4. ✅ ONLY reference products from the CURRENT PRODUCT CATALOG listed above
5. ✅ Use EXACT product names, SKUs, and prices from the database
6. ✅ Our products are proprietary blends and concentrates
7. ❌ Do NOT make up product names or invent SKUs
8. ❌ Do NOT use generic product terminology like "red category" or "white product categories"

**IF NO PRODUCTS ARE LISTED:** Simply mention "our premium product concentrate line" without specific product names.

**IMPORTANT OUTPUT FORMAT:**
Generate ONLY the email content (body paragraphs). Do NOT include header or footer - those are added automatically.

**CRITICAL HEADER/FOOTER RULES:**
⚠️ The Product header is AUTOMATICALLY added by the system
⚠️ The Product footer is AUTOMATICALLY added by the system
⚠️ DO NOT generate "Product" title/header
⚠️ DO NOT generate copyright footer text
⚠️ DO NOT include any <table> tags for header/footer structure
❌ NEVER write "Product" as a heading at the top
❌ NEVER include company logo references
❌ NEVER write copyright text or footer disclaimer
✅ Start DIRECTLY with the greeting paragraph
✅ End with your closing signature paragraph (agent name/title/contact)

**RECURSIVE HEADER/FOOTER CHECK:**
Before finalizing your output, scan your generated content:
- Does it contain "Product" as a standalone heading? ❌ REMOVE IT
- Does it contain copyright text or disclaimer footer? ❌ REMOVE IT
- Does it start with a table for header structure? ❌ REMOVE IT
- Does it reference company logo or branding images? ❌ REMOVE IT
✅ Your output should start IMMEDIATELY with: <p style="margin:0 0 20px...">Hi <strong>{$recipientName}</strong>,</p>

**STYLING CREATIVITY - YOU ARE ENCOURAGED TO BE CREATIVE:**
When the user requests styled, colorful, or visually appealing emails, you CAN and SHOULD:
- ✅ Use vibrant colors for backgrounds (gradients encouraged!)
- ✅ Create highlight boxes with colored backgrounds
- ✅ Adjust font sizes for emphasis (14px to 24px range)
- ✅ Add borders, spacing, padding for visual interest
- ✅ Use emoji strategically for attention
- ✅ Create call-out sections with background colors
- ✅ Add colored dividers or accent lines
- ✅ Use different text colors for emphasis (Product green #059669, blue #0369a1)

**IMPORTANT CONTRAST RULES:**
- ⚠️ NEVER use same/similar colors for text and background
- ⚠️ Light backgrounds (#f0fdf4, #f8fafc, etc) require DARK text (#1f2937, #334155)
- ⚠️ Dark backgrounds (#1e293b, #065f46, etc) require LIGHT text (#ffffff, #f8fafc)
- ⚠️ System will auto-fix dangerous contrast issues

**COLOR PALETTE (Product Brand):**
- Primary Green: #059669, #10b981, #065f46
- Primary Blue: #0369a1, #0ea5e9, #1e40af
- Accent Gold: #f59e0b, #fbbf24
- Neutrals: #1f2937 (dark), #64748b (medium), #f8fafc (light)

**Example Styled Email Elements:**
```html
<!-- Sales Special/Discount Box (use gold/yellow colors) -->
<div style="background:linear-gradient(135deg,#fef3c7,#fde68a);border:2px solid #fbbf24;padding:20px;margin:20px 0;border-radius:12px;text-align:center;">
    <p style="margin:0 0 8px;font-size:24px;font-weight:700;color:#92400e;">
        💰 Limited Time Special
    </p>
    <p style="margin:0;font-size:18px;color:#78350f;font-weight:600;">
        15% OFF Your First Order!
    </p>
</div>

<!-- Product Highlight Box (green) -->
<div style="background:#f0fdf4;border-left:4px solid #059669;padding:16px;margin:20px 0;border-radius:8px;">
    <p style="margin:0;font-size:15px;color:#166534;font-weight:600;">
        🌟 Featured Products
    </p>
</div>

<!-- Recipe Card (creative styling) -->
<div style="background:linear-gradient(135deg,#f0fdfa,#ccfbf1);border:2px solid #5eead4;padding:20px;margin:20px 0;border-radius:12px;">
    <p style="margin:0 0 12px;font-size:20px;font-weight:700;color:#0f766e;">
        🍹 Tropical Energy product
    </p>
    <div style="margin-bottom:12px;">
        <p style="margin:0 0 6px;font-weight:600;color:#115e59;">Ingredients:</p>
        <ul style="margin:0;padding-left:20px;color:#0f766e;line-height:1.8;">
            <li>1 packet Product 75mg Concentrate</li>
            <li>8 oz coconut water</li>
            <li>Fresh mango chunks</li>
            <li>Squeeze of lime</li>
        </ul>
    </div>
    <p style="margin:0;font-size:14px;color:#115e59;">
        <strong>Mix:</strong> Blend with ice for a refreshing boost!
    </p>
</div>
```

Start your response with:
SUBJECT: [your suggested subject line]

BODY:
<p style="margin:0 0 20px;font-size:16px;line-height:1.6;color:#334155;">
Hi <strong>{$recipientName}</strong>,
</p>

<!-- WRITE YOUR 2-3 EMAIL PARAGRAPHS HERE -->
<!-- Standard paragraph style (adjust colors/sizes if requested): -->
<p style="margin:0 0 20px;font-size:16px;line-height:1.6;color:#334155;">
[Your content here]
</p>

<!-- You can add styled boxes if requested: -->
<!-- <div style="background:#f0fdf4;border:1px solid #86efac;padding:16px;border-radius:8px;margin:20px 0;">
    <p style="margin:0;font-size:15px;color:#166534;">Highlighted content</p>
</div> -->

<!-- Final closing paragraph (ALWAYS include this with agent info): -->
<p style="margin:0;font-size:16px;line-height:1.6;color:#334155;">
Best regards,<br>
<strong>{$agentName}</strong><br>
<span style="color:#64748b;font-size:14px;">Sales Representative, Product</span><br>
<span style="color:#64748b;font-size:13px;">📧 {$agentEmail}</span>
</p>

CRITICAL RULES:
1. ❌ Do NOT include the Product header or footer - they're automatically added
2. ❌ Do NOT write "Product" as a heading
3. ❌ Do NOT write copyright or footer disclaimers
4. ✅ ONLY generate content paragraphs that go between header and footer
5. ✅ ALWAYS include the closing paragraph with actual agent name and email (see above)
6. ✅ Agent name: {$agentName}
7. ✅ Agent email: {$agentEmail}
8. ✅ Use inline styles for everything (email clients require inline CSS)
9. ✅ ENSURE COLOR CONTRAST - Never use similar colors for text and background
10. ✅ If user requests creative styling, use colors, gradients, boxes, emphasis
11. ✅ For links, use: color:#059669 (green) or #0369a1 (blue)
12. ✅ Test readability: light bg = dark text, dark bg = light text
13. ✅ Base URL: {$baseUrl}
14. ✅ Shop URL: {$shopUrl}
15. ❌ Do NOT generate fake coupon codes
16. ✅ For promotions: "Contact me to find out more about ongoing promotions!" (unless real codes exist above)
PROMPT;

            $geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent?key=" . $gemini_api_key;
            
            $startTime = microtime(true);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $geminiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $systemPrompt]]]
                ],
                'generationConfig' => [
                    'temperature' => 0.7,
                    'topP' => 0.9,
                    'maxOutputTokens' => 1024
                ]
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if (curl_errno($ch)) {
                throw new Exception('AI API request failed: ' . curl_error($ch));
            }
            curl_close($ch);
            
            $generationTime = round((microtime(true) - $startTime) * 1000);
            
            $data = json_decode($response, true);
            if ($httpCode !== 200 || !isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                throw new Exception('AI generation failed: ' . ($data['error']['message'] ?? 'Unknown error'));
            }
            
            $generatedText = $data['candidates'][0]['content']['parts'][0]['text'];
            
            // Track AI usage with enhanced tracking
            try {
                require_once __DIR__ . '/../required/expense-automation.php';
                track_api_usage_enhanced('gemini', 'generateContent', $uid, session_id(), 
                    ['endpoint' => 'ai-email-composer', 'action' => 'generate', 'prompt_length' => strlen($prompt)],
                    $orderId > 0 ? $orderId : null, $leadId > 0 ? $leadId : null);
            } catch (Exception $e) {
                error_log("AI usage tracking failed: " . $e->getMessage());
            }
            
            // Parse subject and body
            $subject = '';
            $body = $generatedText;
            
            if (preg_match('/^SUBJECT:\s*(.+?)[\r\n]+/i', $generatedText, $matches)) {
                $subject = trim($matches[1]);
                $body = trim(preg_replace('/^SUBJECT:\s*.+?[\r\n]+/i', '', $generatedText));
            }
            
            // Extract just the BODY content if present
            if (preg_match('/BODY:\s*(.+)/is', $body, $bodyMatch)) {
                $body = trim($bodyMatch[1]);
            }
            
            // Recursively clean up: Remove any duplicate header/footer if AI included them
            $body = stripDuplicateHeaderFooter($body);
            
            // Ensure we have paragraph tags with proper styling
            if (strpos($body, '<p') === false) {
                // AI didn't use paragraphs, wrap content
                $lines = explode("\n", trim($body));
                $styled = [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $styled[] = '<p style="margin:0 0 20px;font-size:16px;line-height:1.6;color:#334155;">' . $line . '</p>';
                    }
                }
                $body = implode("\n", $styled);
            }
            
            // Body now contains ONLY the content paragraphs (no header/footer)
            // The template wrapper is added by wrapInTemplate() on the frontend
            
            // Replace agent placeholders with actual information
            $body = str_replace('{{agent_name}}', htmlspecialchars($agentName), $body);
            $body = str_replace('{{agent_email}}', htmlspecialchars($agentEmail), $body);
            $body = str_replace('{{agent_phone}}', htmlspecialchars($agentPhone), $body);
            
            // Validate color contrast safety (prevent invisible text)
            $body = validateColorContrast($body);
            
            // Log AI generation
            $stmt = $pdo->prepare('INSERT INTO ai_email_generations (agent_id, prompt_text, generated_content, model_used, generation_time_ms, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            $stmt->execute([$uid, $prompt, $generatedText, 'gemini-2.0-flash-exp', $generationTime]);
            $generationId = $pdo->lastInsertId();
            
            ob_clean();
            echo json_encode([
                'ok' => true,
                'subject' => $subject,
                'body' => $body,
                'generation_id' => $generationId,
                'generation_time_ms' => $generationTime,
                'agent_info' => [
                    'name' => $agentName,
                    'email' => $agentEmail
                ]
            ]);
            break;
            
        case 'save_draft':
            // Save email as draft
            $input = json_decode(file_get_contents('php://input'), true);
            
            $leadId = intval($input['lead_id'] ?? 0) ?: null;
            $accountId = intval($input['account_id'] ?? 0) ?: null;
            $recipientEmail = trim($input['recipient_email'] ?? '');
            $recipientName = trim($input['recipient_name'] ?? '');
            $subject = trim($input['subject'] ?? '');
            $bodyHtml = $input['body_html'] ?? '';
            $isAiAssisted = !empty($input['is_ai_assisted']);
            $aiContribution = $input['ai_contribution_level'] ?? 'none';
            $generationId = intval($input['generation_id'] ?? 0) ?: null;
            $workflowSelectionInput = $input['workflow_selection'] ?? null;
            $workflowSelection = normalize_workflow_selection_payload($workflowSelectionInput);
            
            if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Valid recipient email is required');
            }
            if ($subject === '' || $bodyHtml === '') {
                throw new Exception('Subject and body are required');
            }
            
            // Generate plain text version
            $bodyText = strip_tags($bodyHtml);
            
            $stmt = $pdo->prepare('INSERT INTO agent_emails (agent_id, lead_id, account_id, recipient_email, recipient_name, subject, body_html, body_text, is_ai_assisted, ai_contribution_level, workflow_selection_mode, workflow_selection_json, workflow_schedule_reason, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", NOW())');
            $stmt->execute([
                $uid,
                $leadId,
                $accountId,
                $recipientEmail,
                $recipientName,
                $subject,
                $bodyHtml,
                $bodyText,
                $isAiAssisted ? 1 : 0,
                $aiContribution,
                $workflowSelection['mode'],
                $workflowSelection['json'],
                $workflowSelection['reason']
            ]);
            
            $emailId = $pdo->lastInsertId();
            
            // Link generation to email if provided
            if ($generationId && $isAiAssisted) {
                $pdo->prepare('UPDATE ai_email_generations SET agent_email_id = ?, was_used = 1 WHERE id = ?')->execute([$emailId, $generationId]);
            }
            
            ob_clean();
            echo json_encode(['ok' => true, 'email_id' => $emailId]);
            break;
            
        case 'send':
            // Send email immediately
            $input = json_decode(file_get_contents('php://input'), true);
            $emailId = intval($input['email_id'] ?? 0);
            
            if ($emailId <= 0) {
                throw new Exception('Email ID is required');
            }
            
            // Get email details
            $stmt = $pdo->prepare('SELECT * FROM agent_emails WHERE id = ? AND agent_id = ?');
            $stmt->execute([$emailId, $uid]);
            $email = $stmt->fetch();
            
            if (!$email) {
                throw new Exception('Email not found or access denied');
            }
            
            if ($email['status'] === 'sent') {
                throw new Exception('Email already sent');
            }
            
            if (array_key_exists('workflow_selection', $input)) {
                $selectionPayload = normalize_workflow_selection_payload($input['workflow_selection']);
                apply_workflow_selection_payload($pdo, $emailId, $selectionPayload);
                if ($selectionPayload['mode']) {
                    $email['workflow_selection_mode'] = $selectionPayload['mode'];
                    $email['workflow_selection_json'] = $selectionPayload['json'];
                    $email['workflow_schedule_reason'] = $selectionPayload['reason'];
                } elseif ($selectionPayload['should_clear']) {
                    $email['workflow_selection_mode'] = null;
                    $email['workflow_selection_json'] = null;
                    $email['workflow_schedule_reason'] = null;
                }
            }
            
            // Generate tracking token before sending
            $trackingToken = bin2hex(random_bytes(32));
            
            // Check if a workflow was attached to this email (transactional campaign)
            $attachedWorkflowId = intval($input['workflow_id'] ?? 0);
            
            // Send via SendGrid (no AI disclaimer - keeping clean)
            
            // Send via SendGrid
            $sendResult = sendgrid_send([
                'to' => $email['recipient_email'],
                'to_name' => $email['recipient_name'],
                'from' => 'sample-006@example.com',
                'from_name' => 'Product',
                'subject' => $email['subject'],
                'html' => $email['body_html'],
                'text' => $email['body_text'],
                'tracking_token' => $trackingToken,
                'custom_args' => [
                    'agent_email_id' => $emailId
                ]
            ]);
            
            if (!$sendResult['ok']) {
                throw new Exception('Email delivery failed: ' . ($sendResult['error'] ?? 'Unknown error'));
            }
            
            // Extract SendGrid message ID from headers (multiple possible formats)
            $messageId = null;
            if (isset($sendResult['headers'])) {
                // Try X-Message-Id first
                if (preg_match('/X-Message-Id:\s*([^\r\n]+)/i', $sendResult['headers'], $matches)) {
                    $messageId = trim($matches[1]);
                }
                // Try x-message-id (lowercase)
                if (!$messageId && preg_match('/x-message-id:\s*([^\r\n]+)/i', $sendResult['headers'], $matches)) {
                    $messageId = trim($matches[1]);
                }
            }
            
            // If no message ID from headers, generate a tracking ID from email ID
            if (!$messageId) {
                $messageId = 'product-' . $emailId . '-' . time();
            }
            
            // Update email status with message ID and tracking token (token was generated before send)
            $stmt = $pdo->prepare('UPDATE agent_emails SET status = "sent", sent_at = NOW(), sendgrid_message_id = ?, tracking_token = ?, recipient_email = ? WHERE id = ?');
            $stmt->execute([$messageId, $trackingToken, $email['recipient_email'], $emailId]);
            
            // If a workflow was attached, schedule it immediately as a transactional campaign
            $workflowScheduled = false;
            $sentEmail = null;
            if ($attachedWorkflowId > 0) {
                try {
                    // Get workflow details
                    $stmt = $pdo->prepare('SELECT * FROM email_followup_workflows WHERE id = ? AND (scope = "global" OR (scope = "agent" AND owner_agent_id = ?)) AND is_active = 1');
                    $stmt->execute([$attachedWorkflowId, $uid]);
                    $workflow = $stmt->fetch();
                    
                    if ($workflow) {
                        // Determine entity type and ID
                        $entityType = null;
                        $entityId = null;
                        if (!empty($email['lead_id'])) {
                            $entityType = 'lead';
                            $entityId = intval($email['lead_id']);
                        } elseif (!empty($email['account_id'])) {
                            $entityType = 'account';
                            $entityId = intval($email['account_id']);
                        }
                        
                        if ($entityType && $entityId) {
                            // Calculate scheduled time based on workflow delay
                            $delayHours = intval($workflow['trigger_delay_hours']);
                            $scheduledFor = date('Y-m-d H:i:s', strtotime("+{$delayHours} hours"));
                            
                            // Create the follow-up schedule entry
                            $stmt = $pdo->prepare("
                                INSERT INTO email_followup_schedule 
                                (workflow_id, parent_email_id, entity_type, entity_id, recipient_email, scheduled_for, status, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
                            ");
                            $stmt->execute([
                                $workflow['id'],
                                $emailId,
                                $entityType,
                                $entityId,
                                $email['recipient_email'],
                                $scheduledFor
                            ]);
                            
                            $workflowScheduled = true;
                        }
                    }
                } catch (Throwable $e) {
                    error_log("Failed to schedule attached workflow: " . $e->getMessage());
                    // Non-blocking - email was sent successfully
                }
            }
            
            // Evaluate automatic workflow triggers for this email (if no workflow was manually attached)
            $autoWorkflowScheduled = false;
            if (!$workflowScheduled) {
                try {
                    require_once __DIR__ . '/email-workflow-processor.php';
                    
                    // Reload email with updated sent_at timestamp
                    $stmt = $pdo->prepare('SELECT * FROM agent_emails WHERE id = ?');
                    $stmt->execute([$emailId]);
                    $sentEmail = $stmt->fetch();
                    
                    if ($sentEmail) {
                        $result = evaluate_workflow_triggers($pdo, $sentEmail);
                        if ($result['scheduled']) {
                            $autoWorkflowScheduled = true;
                            error_log("Auto-scheduled workflow for email #{$emailId}: workflow_id={$result['workflow_id']}, scheduled_for={$result['scheduled_for']}");
                        } elseif (!empty($result['reason'])) {
                            // Log why it wasn't scheduled (for debugging)
                            error_log("Workflow not scheduled for email #{$emailId}: {$result['reason']}");
                        }
                    }
                } catch (Throwable $e) {
                    error_log("Failed to evaluate workflow triggers for email #{$emailId}: " . $e->getMessage());
                    // Non-blocking - email was sent successfully
                }
            }
            
            // Handle manual single workflow selection immediately
            $latestEmailRow = $sentEmail ?? $email;
            $manualSelection = decode_email_workflow_selection($latestEmailRow);
            if ($manualSelection && $manualSelection['mode'] === 'single') {
                $manualWorkflowId = intval($manualSelection['data']['workflow_id'] ?? 0);
                if ($manualWorkflowId > 0) {
                    $manualResult = schedule_manual_workflow($pdo, $latestEmailRow, $manualWorkflowId, $manualSelection['reason'] ?: 'manual_single', 'manual_single');
                    if (!empty($manualResult['scheduled'])) {
                        clear_email_workflow_selection($pdo, $emailId);
                    }
                }
            }
            
            ob_clean();
            echo json_encode([
                'ok' => true,
                'email_id' => $emailId,
                'message_id' => $messageId,
                'tracking_token' => $trackingToken,
                'sent_at' => date('Y-m-d H:i:s'),
                'workflow_scheduled' => $workflowScheduled || $autoWorkflowScheduled
            ]);
            break;
            
        case 'schedule':
            // Schedule email for future delivery
            $input = json_decode(file_get_contents('php://input'), true);
            $emailId = intval($input['email_id'] ?? 0);
            $scheduledFor = $input['scheduled_for'] ?? '';
            
            if ($emailId <= 0 || $scheduledFor === '') {
                throw new Exception('Email ID and scheduled time are required');
            }
            
            // Validate email ownership
            $stmt = $pdo->prepare('SELECT * FROM agent_emails WHERE id = ? AND agent_id = ?');
            $stmt->execute([$emailId, $uid]);
            $email = $stmt->fetch();
            
            if (!$email) {
                throw new Exception('Email not found or access denied');
            }
            
            if ($email['status'] === 'sent' || $email['status'] === 'delivered') {
                throw new Exception('Email already sent - cannot schedule');
            }
            
            // Convert datetime format to MySQL datetime
            // Handle various input formats: "11/14/2025 03:00 PM", "2025-11-14T15:00", etc.
            $timestamp = strtotime($scheduledFor);
            if ($timestamp === false) {
                // Try alternative parsing for common formats
                // Handle "MM/DD/YYYY HH:MM AM/PM" format
                if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})\s+(\d{1,2}):(\d{2})\s*(AM|PM)/i', $scheduledFor, $matches)) {
                    $month = intval($matches[1]);
                    $day = intval($matches[2]);
                    $year = intval($matches[3]);
                    $hour = intval($matches[4]);
                    $minute = intval($matches[5]);
                    $ampm = strtoupper($matches[6]);
                    
                    if ($ampm === 'PM' && $hour < 12) {
                        $hour += 12;
                    } elseif ($ampm === 'AM' && $hour === 12) {
                        $hour = 0;
                    }
                    
                    $timestamp = mktime($hour, $minute, 0, $month, $day, $year);
                } else {
                    throw new Exception('Invalid date format. Please use MM/DD/YYYY HH:MM AM/PM format.');
                }
            }
            
            if ($timestamp === false || $timestamp < time()) {
                throw new Exception('Scheduled time must be in the future');
            }
            
            $scheduledDateTime = date('Y-m-d H:i:s', $timestamp);
            
            // Update email status and schedule time
            $stmt = $pdo->prepare('UPDATE agent_emails SET status = "scheduled", scheduled_for = ? WHERE id = ?');
            $stmt->execute([$scheduledDateTime, $emailId]);
            
            // Check if already in queue
            if (array_key_exists('workflow_selection', $input)) {
                $selectionPayload = normalize_workflow_selection_payload($input['workflow_selection']);
                apply_workflow_selection_payload($pdo, $emailId, $selectionPayload);
            }
            
            // Ensure tracking token exists before enqueue
            $trackingToken = $email['tracking_token'] ?? null;
            if (!$trackingToken) {
                $trackingToken = bin2hex(random_bytes(32));
                $pdo->prepare('UPDATE agent_emails SET tracking_token = ? WHERE id = ?')->execute([$trackingToken, $emailId]);
            }
            
            $stmt = $pdo->prepare('SELECT id FROM email_send_queue WHERE agent_email_id = ?');
            $stmt->execute([$emailId]);
            $queueItem = $stmt->fetch();
            
            if ($queueItem) {
                // Update existing queue item
                $stmt = $pdo->prepare('UPDATE email_send_queue SET scheduled_for = ?, status = "pending", attempts = 0, tracking_token = ? WHERE agent_email_id = ?');
                $stmt->execute([$scheduledDateTime, $trackingToken, $emailId]);
            } else {
                // Add to send queue
                $stmt = $pdo->prepare('INSERT INTO email_send_queue (agent_email_id, tracking_token, scheduled_for, priority, status, created_at) VALUES (?, ?, ?, 50, "pending", NOW())');
                $stmt->execute([$emailId, $trackingToken, $scheduledDateTime]);
            }
            
            ob_clean();
            echo json_encode(['ok' => true, 'email_id' => $emailId, 'scheduled_for' => $scheduledDateTime]);
            break;
            
        case 'get_drafts':
            // Get agent's draft emails with engagement stats
            $leadId = intval($_GET['lead_id'] ?? 0) ?: null;
            $accountId = intval($_GET['account_id'] ?? 0) ?: null;
            
            $where = 'agent_id = ?';
            $params = [$uid];
            
            if ($leadId) {
                $where .= ' AND lead_id = ?';
                $params[] = $leadId;
            } elseif ($accountId) {
                $where .= ' AND account_id = ?';
                $params[] = $accountId;
            }
            
            $stmt = $pdo->prepare("SELECT id, recipient_email, recipient_name, subject, status, is_ai_assisted, scheduled_for, created_at, sent_at, open_count, click_count, last_opened_at FROM agent_emails WHERE {$where} ORDER BY created_at DESC LIMIT 20");
            $stmt->execute($params);
            $drafts = $stmt->fetchAll();
            
            ob_clean();
            echo json_encode(['ok' => true, 'drafts' => $drafts]);
            break;
            
        case 'get_agent_stats':
            // Get email statistics for current agent
            $stmt = $pdo->prepare('
                SELECT 
                    COUNT(*) as total_sent,
                    SUM(CASE WHEN status = "delivered" THEN 1 ELSE 0 END) as delivered,
                    SUM(CASE WHEN status = "bounced" THEN 1 ELSE 0 END) as bounced,
                    SUM(CASE WHEN open_count > 0 THEN 1 ELSE 0 END) as opened,
                    SUM(open_count) as total_opens,
                    SUM(click_count) as total_clicks,
                    SUM(CASE WHEN is_ai_assisted = 1 THEN 1 ELSE 0 END) as ai_assisted_count,
                    SUM(CASE WHEN contributed_to_sale = 1 THEN 1 ELSE 0 END) as converted
                FROM agent_emails 
                WHERE agent_id = ? AND status IN ("sent", "delivered", "bounced")
            ');
            $stmt->execute([$uid]);
            $stats = $stmt->fetch();
            
            // Calculate rates
            $totalSent = intval($stats['total_sent'] ?? 0);
            $deliveryRate = $totalSent > 0 ? round((intval($stats['delivered']) / $totalSent) * 100, 1) : 0;
            $openRate = $totalSent > 0 ? round((intval($stats['opened']) / $totalSent) * 100, 1) : 0;
            $clickRate = $totalSent > 0 ? round((intval($stats['total_clicks']) / $totalSent) * 100, 1) : 0;
            $conversionRate = $totalSent > 0 ? round((intval($stats['converted']) / $totalSent) * 100, 1) : 0;
            
            ob_clean();
            echo json_encode([
                'ok' => true,
                'stats' => array_merge($stats, [
                    'delivery_rate' => $deliveryRate,
                    'open_rate' => $openRate,
                    'click_rate' => $clickRate,
                    'conversion_rate' => $conversionRate
                ])
            ]);
            break;
            
        case 'get_email':
            // Get single email details
            $emailId = intval($_GET['id'] ?? 0);
            
            $stmt = $pdo->prepare('SELECT * FROM agent_emails WHERE id = ? AND agent_id = ?');
            $stmt->execute([$emailId, $uid]);
            $email = $stmt->fetch();
            
            if (!$email) {
                throw new Exception('Email not found');
            }
            
            ob_clean();
            echo json_encode(['ok' => true, 'email' => $email]);
            break;
            
        case 'delete_draft':
            // Delete a draft email
            $input = json_decode(file_get_contents('php://input'), true);
            $emailId = intval($input['email_id'] ?? 0);
            
            $stmt = $pdo->prepare('DELETE FROM agent_emails WHERE id = ? AND agent_id = ? AND status = "draft"');
            $stmt->execute([$emailId, $uid]);
            
            $affected = $stmt->rowCount();
            
            ob_clean();
            echo json_encode(['ok' => $affected > 0, 'deleted' => $affected > 0]);
            break;
            
        case 'mark_contributed':
            // Admin action: Mark email as having contributed to a sale
            if (!current_admin_id()) {
                throw new Exception('Admin access required');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $emailId = intval($input['email_id'] ?? 0);
            $orderId = intval($input['order_id'] ?? 0);
            
            if ($emailId <= 0 || $orderId <= 0) {
                throw new Exception('Email ID and Order ID required');
            }
            
            $stmt = $pdo->prepare('UPDATE agent_emails SET contributed_to_sale = 1, related_order_id = ? WHERE id = ?');
            $stmt->execute([$orderId, $emailId]);
            
            ob_clean();
            echo json_encode(['ok' => true]);
            break;
            
        case 'get_templates':
            // Get available email templates for sales
            $stmt = $pdo->query("SELECT id, slug, name, subject, category FROM email_templates WHERE (scope = 'sales_agent' OR category = 'sales') AND is_active = 1 ORDER BY name");
            $templates = $stmt->fetchAll();
            
            ob_clean();
            echo json_encode(['ok' => true, 'templates' => $templates]);
            break;
            
        case 'load_template':
            // Load template content
            $templateId = intval($_GET['template_id'] ?? 0);
            
            $stmt = $pdo->prepare('SELECT * FROM email_templates WHERE id = ?');
            $stmt->execute([$templateId]);
            $template = $stmt->fetch();
            
            if (!$template) {
                throw new Exception('Template not found');
            }
            
            ob_clean();
            echo json_encode(['ok' => true, 'template' => $template]);
            break;
            
        case 'get_available_workflows':
            // Get workflows available to this agent for attaching to emails
            $stmt = $pdo->prepare("
                SELECT id, name, description, trigger_type, trigger_delay_hours, scope 
                FROM email_followup_workflows 
                WHERE (scope = 'global' OR (scope = 'agent' AND owner_agent_id = ?))
                AND is_active = 1
                ORDER BY scope DESC, name ASC
            ");
            $stmt->execute([$uid]);
            $workflows = $stmt->fetchAll();
            
            ob_clean();
            echo json_encode(['ok' => true, 'workflows' => $workflows]);
            break;
            
        case 'get_automation_settings':
            // Get automation settings for lead or account
            $entityType = $_GET['entity_type'] ?? ''; // 'lead' or 'account'
            $entityId = intval($_GET['entity_id'] ?? 0);
            
            if (!in_array($entityType, ['lead', 'account'])) {
                throw new Exception('Invalid entity type');
            }
            
            $stmt = $pdo->prepare('SELECT * FROM email_automation_settings WHERE entity_type = ? AND entity_id = ?');
            $stmt->execute([$entityType, $entityId]);
            $settings = $stmt->fetch();
            
            // If no settings exist, return global defaults
            if (!$settings) {
                $stmt = $pdo->prepare('SELECT * FROM email_automation_settings WHERE entity_type = "global" AND entity_id IS NULL');
                $stmt->execute();
                $settings = $stmt->fetch();
                
                // If still no settings, return safe defaults
                if (!$settings) {
                    $settings = [
                        'automation_enabled' => 0,
                        'allow_ai_emails' => 0,
                        'allow_scheduled_emails' => 1,
                        'max_emails_per_week' => 3
                    ];
                }
            }
            
            ob_clean();
            echo json_encode(['ok' => true, 'settings' => $settings]);
            break;
            
        case 'generate_followup':
            // Generate AI follow-up based on parent email and behavior
            $input = json_decode(file_get_contents('php://input'), true);
            $parentEmailId = intval($input['parent_email_id'] ?? 0);
            $behaviorType = $input['behavior_type'] ?? 'unopened';
            $followupLeadId = 0; // Will be populated from parent email
            
            if ($parentEmailId <= 0) {
                throw new Exception('Parent email ID is required');
            }
            
            // Get parent email context
            $stmt = $pdo->prepare('SELECT * FROM agent_emails WHERE id = ?');
            $stmt->execute([$parentEmailId]);
            $parentEmail = $stmt->fetch();
            
            if (!$parentEmail) {
                throw new Exception('Parent email not found');
            }
            
            // Populate lead ID for tracking
            $followupLeadId = intval($parentEmail['lead_id'] ?? 0);
            
            // Verify ownership
            if (intval($parentEmail['agent_id']) !== $uid && !current_admin_id()) {
                throw new Exception('Access denied');
            }
            
            // Get conversion data if available
            $conversions = [];
            $stmt = $pdo->prepare('SELECT * FROM email_conversions WHERE agent_email_id = ? ORDER BY created_at DESC');
            $stmt->execute([$parentEmailId]);
            $conversions = $stmt->fetchAll();
            
            // Build behavior context
            $behaviorContext = build_followup_behavior_context($behaviorType, $parentEmail, $conversions);
            
            // Get agent info
            $agentName = 'Sales Representative';
            $agentEmail = 'sample-006@example.com';
            try {
                $stmt = $pdo->prepare('SELECT first_name, last_name, email FROM users WHERE id = ?');
                $stmt->execute([$uid]);
                $agent = $stmt->fetch();
                if ($agent) {
                    $agentName = trim($agent['first_name'] . ' ' . $agent['last_name']);
                    $agentEmail = $agent['email'];
                }
            } catch (Throwable $e) {}
            
            // Build AI prompt for follow-up
            $followupPrompt = <<<PROMPT
You are writing a professional follow-up email for Product sales.

**Original Email Context:**
Subject: {$parentEmail['subject']}
Sent: {$parentEmail['sent_at']}
Recipient: {$parentEmail['recipient_name']}

**Recipient Behavior:**
{$behaviorContext}

**Follow-up Guidelines:**
- Reference the previous email naturally
- Address the specific behavior (e.g., "I wanted to follow up on my email about...")
- Keep it brief and respectful of their time
- Provide value or new information if possible
- Clear call-to-action
- Professional but friendly tone

**DO NOT:**
- Sound pushy or desperate
- Repeat the entire previous email
- Use aggressive sales tactics
- Make assumptions about why they didn't respond

**IMPORTANT OUTPUT FORMAT:**
Generate ONLY the email content (body paragraphs). Do NOT include header or footer.

Start your response with:
SUBJECT: [your suggested subject line]

BODY:
<p style="margin:0 0 20px;font-size:16px;line-height:1.6;color:#334155;">
Hi <strong>{$parentEmail['recipient_name']}</strong>,
</p>

<!-- WRITE YOUR 2-3 FOLLOW-UP PARAGRAPHS HERE -->

<!-- Final closing paragraph: -->
<p style="margin:0;font-size:16px;line-height:1.6;color:#334155;">
Best regards,<br>
<strong>{$agentName}</strong><br>
<span style="color:#64748b;font-size:14px;">Sales Representative, Product</span><br>
<span style="color:#64748b;font-size:13px;">📧 {$agentEmail}</span>
</p>
PROMPT;

            global $gemini_api_key;
            $geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent?key=" . $gemini_api_key;
            
            $startTime = microtime(true);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $geminiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $followupPrompt]]]
                ],
                'generationConfig' => [
                    'temperature' => 0.7,
                    'topP' => 0.9,
                    'maxOutputTokens' => 1024
                ]
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if (curl_errno($ch)) {
                throw new Exception('AI API request failed: ' . curl_error($ch));
            }
            curl_close($ch);
            
            $generationTime = round((microtime(true) - $startTime) * 1000);
            
            $data = json_decode($response, true);
            if ($httpCode !== 200 || !isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                throw new Exception('AI generation failed: ' . ($data['error']['message'] ?? 'Unknown error'));
            }
            
            $generatedText = $data['candidates'][0]['content']['parts'][0]['text'];
            
            // Track AI usage with enhanced tracking
            try {
                require_once __DIR__ . '/../required/expense-automation.php';
                track_api_usage_enhanced('gemini', 'generateContent', $uid, session_id(), 
                    ['endpoint' => 'ai-email-composer', 'action' => 'generate_followup', 'behavior_type' => $behaviorType],
                    null, $followupLeadId > 0 ? $followupLeadId : null);
            } catch (Exception $e) {
                error_log("AI usage tracking failed: " . $e->getMessage());
            }
            
            // Parse subject and body
            $subject = '';
            $body = $generatedText;
            
            if (preg_match('/^SUBJECT:\s*(.+?)[\r\n]+/i', $generatedText, $matches)) {
                $subject = trim($matches[1]);
                $body = trim(preg_replace('/^SUBJECT:\s*.+?[\r\n]+/i', '', $generatedText));
            }
            
            // Extract BODY content if present
            if (preg_match('/BODY:\s*(.+)/is', $body, $bodyMatch)) {
                $body = trim($bodyMatch[1]);
            }
            
            // Log AI generation
            $stmt = $pdo->prepare('INSERT INTO ai_email_generations (agent_id, prompt_text, generated_content, model_used, generation_time_ms, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            $stmt->execute([$uid, $followupPrompt, $generatedText, 'gemini-2.0-flash-exp', $generationTime]);
            $generationId = $pdo->lastInsertId();
            
            ob_clean();
            echo json_encode([
                'ok' => true,
                'subject' => $subject,
                'body' => $body,
                'generation_id' => $generationId,
                'generation_time_ms' => $generationTime,
                'parent_email_id' => $parentEmailId,
                'behavior_type' => $behaviorType
            ]);
            break;
            
        default:
            ob_clean();
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . $action]);
    }
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'trace' => PRODUCT_ENV !== 'prod' ? $e->getTraceAsString() : null
    ]);
}

ob_end_flush();

