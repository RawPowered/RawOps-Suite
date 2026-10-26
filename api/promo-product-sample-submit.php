<?php
header('Content-Type: application/json');

// Robust config loader
$loaded = false;
$candidates = [
    __DIR__ . '/../required/config.php',
    __DIR__ . '/../../required/config.php',
];
foreach ($candidates as $p) { 
    if (is_file($p)) { 
        require_once $p; 
        require_once dirname($p) . '/demo-functions.php';
        require_once dirname($p) . '/sample-email-triggers.php';
        $loaded = true; 
        break; 
    } 
}

if (!$loaded) {
    echo json_encode(['success' => false, 'error' => 'Configuration not found']);
    exit;
}

try {
    $pdo = db();
    
    // Handle sample request action
    if (isset($_POST['action']) && $_POST['action'] === 'request_sample') {
        $productType = trim($_POST['product_type'] ?? '');
        $agentId = intval($_POST['sales_agent_id'] ?? 0);
        $email = trim($_POST['email'] ?? $_SESSION['email'] ?? '');
        $firstName = trim($_POST['first_name'] ?? $_SESSION['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? $_SESSION['last_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $storeName = trim($_POST['store_name'] ?? '');
        
        $validProductTypes = ['gold_40', 'blue_20', 'product format_dash'];
        if (!$agentId || !in_array($productType, $validProductTypes) || !$email) {
            echo json_encode(['success' => false, 'error' => 'Missing required fields']);
            exit;
        }
        
        // Get product ID based on product_type
        $productSkuMap = [
            'gold_40' => ['%product-pack%gold%40%', '%product-pack%40%'],
            'blue_20' => ['%product-pack%blue%20%', '%product-pack%20%'],
            'product format_dash' => ['%SAMPLE-product format-1G%', '%SAMPLE-product format-1G%']
        ];
        
        if ($productType === 'product format_dash') {
            $stmt = $pdo->prepare('SELECT id, name, sku FROM products WHERE is_sample = 1 AND sku = ? LIMIT 1');
            $stmt->execute(['SAMPLE-product format-1G']);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $pdo->prepare('
                SELECT id, name, sku FROM products 
                WHERE is_sample = 1 AND size_oz = 3 
                AND (sku LIKE ? OR sku LIKE ? OR name LIKE ? OR name LIKE ?)
                LIMIT 1
            ');
            $searchTerms = $productSkuMap[$productType];
            $stmt->execute([$searchTerms[0], $searchTerms[1], $searchTerms[0], $searchTerms[1]]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        if (!$product) {
            echo json_encode(['success' => false, 'error' => 'Product not found in inventory. Please contact your sales agent.']);
            exit;
        }
        
        $pdo->beginTransaction();
        
        // Get or create CRM lead
        $stmt = $pdo->prepare('SELECT id FROM crm_leads WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $leadId = $stmt->fetchColumn();
        
        if (!$leadId) {
            // Create CRM lead
            $stmt = $pdo->prepare('
                INSERT INTO crm_leads (
                    first_name, last_name, email, phone, company_name,
                    lead_source, lead_status, assigned_agent_id, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            $stmt->execute([
                $firstName, $lastName, $email, $phone, $storeName,
                'product-pack-trial-request', 'sample_requested', $agentId
            ]);
            $leadId = $pdo->lastInsertId();
        }
        
        // Create sample_request
        $stmt = $pdo->prepare('
            INSERT INTO sample_requests (
                agent_id, lead_id, sample_product_id, recipient_name, 
                recipient_email, recipient_phone, status, notes, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, "pending", ?, NOW())
        ');
        $stmt->execute([
            $agentId, $leadId, $product['id'],
            $storeName ?: ($firstName . ' ' . $lastName), $email, $phone,
            "Requested via product-pack trial landing page - Product: {$productType} ({$product['name']})"
        ]);
        $sampleRequestId = $pdo->lastInsertId();
        
        // Create or update promo_demo_lead
        $sourceId = intval($_POST['source_id'] ?? 0);
        if (!$sourceId) {
            $stmt = $pdo->prepare('SELECT id FROM promo_demo_sources WHERE slug = "product-pack-trial" LIMIT 1');
            $stmt->execute();
            $sourceId = intval($stmt->fetchColumn());
        }
        
        if ($sourceId) {
            $stmt = $pdo->prepare('SELECT id FROM promo_demo_leads WHERE contact_email = ? AND source_id = ? LIMIT 1');
            $stmt->execute([$email, $sourceId]);
            $demoLeadId = $stmt->fetchColumn();
            
            if ($demoLeadId) {
                // Update existing
                $stmt = $pdo->prepare('
                    UPDATE promo_demo_leads 
                    SET sample_request_id = ?, product_type = ?, sales_agent_id = COALESCE(sales_agent_id, ?), updated_at = NOW()
                    WHERE id = ?
                ');
                $stmt->execute([$sampleRequestId, $productType, $agentId, $demoLeadId]);
            } else {
                // Create new
                $stmt = $pdo->prepare('
                    INSERT INTO promo_demo_leads (
                        source_id, store_name, contact_first_name, contact_last_name,
                        contact_email, contact_phone, sales_agent_id, product_type, 
                        sample_request_id, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ');
                $stmt->execute([
                    $sourceId, $storeName, $firstName, $lastName,
                    $email, $phone, $agentId, $productType, $sampleRequestId
                ]);
            }
        }
        
        // Log CRM activity
        log_sample_crm_activity(
            $pdo,
            $leadId,
            'sample_requested',
            "Customer requested product-pack sample - Product: {$productType}",
            [
                'sample_request_id' => $sampleRequestId,
                'product_type' => $productType,
                'product_name' => $product['name']
            ]
        );
        
        // Send notifications
        send_sample_email_notification($pdo, 'sample_requested', $sampleRequestId, [
            'recipient_email' => $email,
            'recipient_name' => $storeName ?: ($firstName . ' ' . $lastName),
            'product_type' => $productType,
            'product_name' => $product['name']
        ]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Sample request submitted! Your sales agent will contact you shortly.',
            'sample_request_id' => $sampleRequestId
        ]);
        exit;
    }
    
    // Validate required fields for review submission
    $required = ['source_id', 'email', 'rating'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            echo json_encode(['success' => false, 'error' => "Missing required field: $field"]);
            exit;
        }
    }
    
    // Prepare data
    $sourceId = intval($_POST['source_id']);
    $sessionId = $_POST['session_id'] ?? '';
    $email = trim($_POST['email']);
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $storeName = trim($_POST['store_name'] ?? '');
    $agentId = !empty($_POST['sales_agent_id']) ? intval($_POST['sales_agent_id']) : null;
    $rating = intval($_POST['rating']);
    $reviewText = trim($_POST['review_text'] ?? '');
    $productType = trim($_POST['product_type'] ?? '');
    
    if (!in_array($productType, ['gold_40', 'blue_20', 'product format_dash', ''])) {
        echo json_encode(['success' => false, 'error' => 'Invalid product type']);
        exit;
    }
    
    // Check if demo lead already exists
    $stmt = $pdo->prepare('SELECT id, sales_agent_id FROM promo_demo_leads WHERE contact_email = ?');
    $stmt->execute([$email]);
    $existingLead = $stmt->fetch();
    
    $pdo->beginTransaction();
    
    if ($existingLead) {
        // Update existing demo lead
        $demoLeadId = $existingLead['id'];
        
        // Update agent if provided
        if ($agentId && !$existingLead['sales_agent_id']) {
            $stmt = $pdo->prepare('UPDATE promo_demo_leads SET sales_agent_id = ? WHERE id = ?');
            $stmt->execute([$agentId, $demoLeadId]);
        }
        
        // Check if CRM lead exists
        $stmt = $pdo->prepare('SELECT id, assigned_agent_id FROM crm_leads WHERE email = ?');
        $stmt->execute([$email]);
        $crmLead = $stmt->fetch();
        
        if ($crmLead) {
            // Update CRM lead status to customer_review
            $stmt = $pdo->prepare('
                UPDATE crm_leads 
                SET lead_status = "customer_review", updated_at = NOW()
                WHERE id = ? AND lead_status NOT IN ("won", "lost", "do_not_contact")
            ');
            $stmt->execute([$crmLead['id']]);
            
            // Update agent if needed
            if ($agentId && !$crmLead['assigned_agent_id']) {
                $stmt = $pdo->prepare('UPDATE crm_leads SET assigned_agent_id = ? WHERE id = ?');
                $stmt->execute([$agentId, $crmLead['id']]);
            }
        }
    } else {
        // Create new demo lead (no session_id column exists)
        $stmt = $pdo->prepare('
            INSERT INTO promo_demo_leads (
                source_id, store_name, contact_first_name, contact_last_name,
                contact_email, contact_phone, sales_agent_id, product_type, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        
        $stmt->execute([
            $sourceId, $storeName, $firstName, $lastName,
            $email, $phone, $agentId, $productType ?: NULL
        ]);
        
        $demoLeadId = $pdo->lastInsertId();
        
        // Link to existing CRM lead if one already exists, otherwise optionally create one
        $stmt = $pdo->prepare('SELECT id, assigned_agent_id, lead_status FROM crm_leads WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $crmLead = $stmt->fetch();
        
        if ($crmLead) {
            // Update status to customer_review (unless terminal) and assign agent if not set
            $stmt = $pdo->prepare('
                UPDATE crm_leads 
                SET 
                    lead_status = CASE WHEN lead_status IN ("won","lost","do_not_contact") THEN lead_status ELSE "customer_review" END,
                    assigned_agent_id = COALESCE(assigned_agent_id, ?),
                    updated_at = NOW()
                WHERE id = ?
            ');
            $stmt->execute([$agentId, $crmLead['id']]);
            
            // Link demo lead to existing CRM lead
            $stmt = $pdo->prepare('UPDATE promo_demo_leads SET crm_lead_id = ? WHERE id = ?');
            $stmt->execute([$crmLead['id'], $demoLeadId]);
        } elseif ($agentId) {
            // Create CRM lead only when we have an agent and none exists yet
            $stmt = $pdo->prepare('
                INSERT INTO crm_leads (
                    first_name, last_name, email, phone, company_name,
                    lead_source, lead_status, assigned_agent_id, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            $stmt->execute([
                $firstName, $lastName, $email, $phone, $storeName,
                'product-pack-trial-review', 'customer_review', $agentId
            ]);
            $crmLeadId = $pdo->lastInsertId();
            // Link demo lead to new CRM lead
            $stmt = $pdo->prepare('UPDATE promo_demo_leads SET crm_lead_id = ? WHERE id = ?');
            $stmt->execute([$crmLeadId, $demoLeadId]);
            // Expose for later drop-off update
            $crmLead = ['id' => $crmLeadId];
        }
    }
    
    // Check for existing review
    $stmt = $pdo->prepare('
        SELECT id, created_at, DATEDIFF(NOW(), created_at) as days_old 
        FROM promo_demo_reviews 
        WHERE demo_lead_id = ?
    ');
    $stmt->execute([$demoLeadId]);
    $existingReview = $stmt->fetch();
    
    if ($existingReview) {
        // Check if within 3 days for updates
        if ($existingReview['days_old'] <= 3) {
            // Update existing review
            $stmt = $pdo->prepare('
                UPDATE promo_demo_reviews 
                SET rating = ?, review_text = ?, updated_at = NOW()
                WHERE id = ?
            ');
            $stmt->execute([$rating, $reviewText, $existingReview['id']]);
            $reviewAction = 'updated';
        } else {
            // Review too old to update
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'error' => 'Review period has expired. Reviews can only be updated within 3 days.'
            ]);
            exit;
        }
    } else {
        // Create new review  
        $stmt = $pdo->prepare('
            INSERT INTO promo_demo_reviews (
                demo_lead_id, rating, review_text, product_type, created_at
            ) VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                rating = VALUES(rating),
                review_text = VALUES(review_text),
                product_type = VALUES(product_type),
                updated_at = NOW()
        ');
        $stmt->execute([$demoLeadId, $rating, $reviewText, $productType ?: NULL]);
        $reviewAction = 'created';
    }
    
    // Get or create sample request for this demo lead
    $sampleRequestId = null;
    $stmt = $pdo->prepare('SELECT sample_request_id FROM promo_demo_leads WHERE id = ?');
    $stmt->execute([$demoLeadId]);
    $sampleRequestId = $stmt->fetchColumn();
    
    // If no sample request exists, create one
    if (!$sampleRequestId && isset($crmLead) && $crmLead) {
        // Get customer details for sample request
        $stmt = $pdo->prepare('
            SELECT l.*, wa.address_line1, wa.city, wa.state_region, wa.postal_code
            FROM crm_leads l
            LEFT JOIN wholesale_accounts wa ON wa.user_id = (SELECT user_id FROM users WHERE email = l.email LIMIT 1)
            WHERE l.id = ?
        ');
        $stmt->execute([$crmLead['id']]);
        $customerInfo = $stmt->fetch();
        
        if ($customerInfo) {
            $recipientData = [
                'name' => $storeName ?: ($firstName . ' ' . $lastName),
                'email' => $email,
                'phone' => $phone,
                'address_line1' => $customerInfo['address_line1'] ?? '',
                'address_line2' => '',
                'city' => $customerInfo['city'] ?? '',
                'state' => $customerInfo['state_region'] ?? '',
                'zip' => $customerInfo['postal_code'] ?? '',
                'country' => 'USA'
            ];
            
            $sampleRequestId = create_product_pack_trial_sample_request(
                $pdo,
                $agentId ?: 0,
                $crmLead['id'],
                null,
                $recipientData
            );
            
            // Link sample request to demo lead
            if ($sampleRequestId) {
                $stmt = $pdo->prepare('UPDATE promo_demo_leads SET sample_request_id = ? WHERE id = ?');
                $stmt->execute([$sampleRequestId, $demoLeadId]);
                
                // Update review with sample_request_id
                $stmt = $pdo->prepare('UPDATE promo_demo_reviews SET sample_request_id = ? WHERE demo_lead_id = ?');
                $stmt->execute([$sampleRequestId, $demoLeadId]);
            }
        }
    }
    
    // Update review with sample_request_id if it exists
    if ($sampleRequestId) {
        $stmt = $pdo->prepare('UPDATE promo_demo_reviews SET sample_request_id = ? WHERE demo_lead_id = ?');
        $stmt->execute([$sampleRequestId, $demoLeadId]);
    }
    
    // Update demo drop-off records if CRM lead exists
    if (isset($crmLead) && $crmLead) {
        // Update all demo dropoffs for this lead with review status
        $reviewNote = "Customer Review Submitted - Rating: $rating/5";
        if ($reviewText) {
            $reviewNote .= " | Review: " . substr($reviewText, 0, 100) . (strlen($reviewText) > 100 ? '...' : '');
        }
        
        $stmt = $pdo->prepare('
            UPDATE crm_demo_dropoffs 
            SET demo_status = "confirmed",
                notes = CONCAT(IFNULL(notes, ""), IF(notes != "", "\n", ""), ?),
                updated_at = NOW()
            WHERE lead_id = ? AND demo_status = "dropped"
        ');
        $stmt->execute([$reviewNote, $crmLead['id']]);
    }
    
    // Create follow-up entry (simplified schema)
    $followAgentId = $agentId;
    if (!$followAgentId && isset($crmLead['assigned_agent_id'])) {
        $followAgentId = (int)$crmLead['assigned_agent_id'];
    }
    if (!$followAgentId) { $followAgentId = 0; }
    
    $stmt = $pdo->prepare('
        INSERT INTO promo_demo_followups (
            demo_lead_id, agent_id, follow_up_at, interested_75mg, disposition, notes
        ) VALUES (?, ?, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 1, "pending", ?)
    ');
    
    $stmt->execute([
        $demoLeadId,
        $followAgentId,
        'Customer submitted review with rating ' . $rating . '/5'
    ]);
    
    // Log CRM activity for review submission
    if (isset($crmLead) && $crmLead && $crmLead['id']) {
        log_sample_crm_activity(
            $pdo,
            $crmLead['id'],
            'review_submitted',
            "Customer submitted review - Rating: {$rating}/5",
            [
                'rating' => $rating,
                'review_text' => $reviewText,
                'sample_request_id' => $sampleRequestId,
                'demo_lead_id' => $demoLeadId
            ]
        );
    }
    
    // Send email notification to agent about review
    if ($sampleRequestId && $agentId) {
        send_sample_email_notification($pdo, 'review_submitted', $sampleRequestId, [
            'rating' => $rating,
            'review_text' => $reviewText ?: 'No written review provided'
        ]);
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'demo_lead_id' => $demoLeadId,
        'message' => 'Thank you for your review! Your sales agent will follow up about your free 75mg sample.'
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>