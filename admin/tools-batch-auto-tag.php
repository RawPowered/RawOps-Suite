<?php
/**
 * Batch Auto-Tag Leads
 * Recursively auto-tag all leads using the detection API
 * 
 * @version 1.0.0
 * @prefix sacp1015_
 */

$SEO = [
    'title' => 'Batch Auto-Tag Leads — Admin',
    'description' => 'Automatically tag all leads with business type detection',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
start_session();

$adminId = current_admin_id();
if (!$adminId) redirect(path_with_base('/admin/'));

$pdo = db();

/**
 * Direct tag detection function (no HTTP overhead)
 */
function sacp1015_detectTagsDirect($pdo, $companyName, $address = '', $city = '', $state = '') {
    $suggestions = [];
    
    // Get all active tags with keywords
    $stmt = $pdo->query('
        SELECT id, tag_name, tag_slug, tag_color, tag_icon, auto_detect_keywords 
        FROM crm_lead_tags 
        WHERE is_active = 1 
        ORDER BY sort_order ASC
    ');
    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Normalize company name for matching
    $normalizedName = strtolower(trim($companyName));
    $normalizedName = preg_replace('/[^a-z0-9\s]/', ' ', $normalizedName);
    $normalizedName = preg_replace('/\s+/', ' ', $normalizedName);
    
    // Match against each tag's keywords
    foreach ($tags as $tag) {
        if (empty($tag['auto_detect_keywords'])) {
            continue;
        }
        
        $keywords = json_decode($tag['auto_detect_keywords'], true);
        if (!is_array($keywords)) {
            continue;
        }
        
        $matchScore = 0;
        $matchedKeywords = [];
        
        foreach ($keywords as $keyword) {
            $normalizedKeyword = strtolower(trim($keyword));
            
            // Exact phrase match (higher score)
            if (strpos($normalizedName, $normalizedKeyword) !== false) {
                $matchScore += 50;
                $matchedKeywords[] = $keyword;
            }
            
            // Word match (lower score)
            $words = explode(' ', $normalizedName);
            foreach ($words as $word) {
                if ($word === $normalizedKeyword || 
                    (strlen($normalizedKeyword) > 4 && strpos($word, $normalizedKeyword) === 0)) {
                    $matchScore += 25;
                    if (!in_array($keyword, $matchedKeywords)) {
                        $matchedKeywords[] = $keyword;
                    }
                }
            }
        }
        
        if ($matchScore > 0) {
            // Calculate confidence (0-100)
            $confidence = min(100, $matchScore);
            
            $suggestions[] = [
                'tag_id' => $tag['id'],
                'tag_name' => $tag['tag_name'],
                'tag_slug' => $tag['tag_slug'],
                'tag_color' => $tag['tag_color'],
                'tag_icon' => $tag['tag_icon'],
                'confidence' => $confidence,
                'matched_keywords' => $matchedKeywords,
                'is_auto_assigned' => $confidence >= 70
            ];
        }
    }
    
    // Sort by confidence (highest first)
    usort($suggestions, function($a, $b) {
        return $b['confidence'] - $a['confidence'];
    });
    
    // If no strong matches, suggest "Other" as fallback
    if (empty($suggestions) || $suggestions[0]['confidence'] < 50) {
        $otherTag = $pdo->query("
            SELECT id, tag_name, tag_slug, tag_color, tag_icon 
            FROM crm_lead_tags 
            WHERE tag_slug = 'other' AND is_active = 1 
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
        
        if ($otherTag) {
            $suggestions[] = [
                'tag_id' => $otherTag['id'],
                'tag_name' => $otherTag['tag_name'],
                'tag_slug' => $otherTag['tag_slug'],
                'tag_color' => $otherTag['tag_color'],
                'tag_icon' => $otherTag['tag_icon'],
                'confidence' => 30,
                'matched_keywords' => [],
                'is_auto_assigned' => false
            ];
        }
    }
    
    return $suggestions;
}

// Handle batch processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'batch_tag') {
    $limit = (int)($_POST['limit'] ?? 50);
    $overwriteExisting = isset($_POST['overwrite_existing']);
    
    // Get leads to process
    if ($overwriteExisting) {
        $stmt = $pdo->prepare('
            SELECT id, company_name, address_line1, city, state 
            FROM crm_leads 
            WHERE company_name IS NOT NULL AND company_name != ""
            LIMIT ?
        ');
    } else {
        $stmt = $pdo->prepare('
            SELECT l.id, l.company_name, l.address_line1, l.city, l.state 
            FROM crm_leads l
            WHERE l.company_name IS NOT NULL AND l.company_name != ""
            AND NOT EXISTS (
                SELECT 1 FROM crm_lead_tag_assignments lta WHERE lta.lead_id = l.id
            )
            LIMIT ?
        ');
    }
    $stmt->execute([$limit]);
    $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $processed = 0;
    $tagged = 0;
    $skipped = 0;
    $errors = [];
    
    foreach ($leads as $lead) {
        $processed++;
        
        try {
            // Call auto-tag logic directly (no HTTP overhead)
            $suggestions = sacp1015_detectTagsDirect($pdo, $lead['company_name'], $lead['address_line1'] ?? '', $lead['city'] ?? '', $lead['state'] ?? '');
            
            if (!empty($suggestions)) {
                $topSuggestion = $suggestions[0];
                
                // Auto-assign if confidence >= 70%
                if ($topSuggestion['confidence'] >= 70) {
                    // Remove existing tags if overwrite
                    if ($overwriteExisting) {
                        $pdo->prepare('DELETE FROM crm_lead_tag_assignments WHERE lead_id = ?')->execute([$lead['id']]);
                    }
                    
                    // Insert new tag
                    $stmt = $pdo->prepare('
                        INSERT INTO crm_lead_tag_assignments (lead_id, tag_id, is_auto_assigned, confidence_score)
                        VALUES (?, ?, 1, ?)
                        ON DUPLICATE KEY UPDATE confidence_score = VALUES(confidence_score)
                    ');
                    $stmt->execute([$lead['id'], $topSuggestion['tag_id'], $topSuggestion['confidence']]);
                    $tagged++;
                } else {
                    $skipped++;
                }
            } else {
                $skipped++;
            }
        } catch (Exception $e) {
            $errors[] = "Lead ID {$lead['id']}: " . $e->getMessage();
        }
    }
    
    $message = "Processed $processed leads. Tagged: $tagged, Skipped (low confidence): $skipped";
    if (!empty($errors)) {
        $message .= ". Errors: " . count($errors);
    }
    $messageType = 'success';
}

// Get stats
$totalLeads = $pdo->query('SELECT COUNT(*) FROM crm_leads')->fetchColumn();
$taggedLeads = $pdo->query('SELECT COUNT(DISTINCT lead_id) FROM crm_lead_tag_assignments')->fetchColumn();
$untaggedLeads = $totalLeads - $taggedLeads;

?>

<div class="container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <div class="card" style="margin-top: 24px;">
        <h1>🤖 Batch Auto-Tag Leads</h1>
        <p style="color: #6b7280;">Automatically detect and assign business type tags to all leads using AI-powered keyword matching.</p>
        
        <?php if (isset($message)): ?>
        <div style="padding: 12px; border-radius: 8px; margin: 16px 0; background: <?php echo $messageType === 'success' ? '#d1fae5' : '#fee2e2'; ?>; border: 1px solid <?php echo $messageType === 'success' ? '#10b981' : '#ef4444'; ?>;">
            <?php echo htmlspecialchars($message); ?>
            <?php if (!empty($errors)): ?>
            <details style="margin-top: 8px;">
                <summary style="cursor: pointer; color: #dc2626; font-weight: 600;">View Errors (<?php echo count($errors); ?>)</summary>
                <div style="margin-top: 8px; max-height: 200px; overflow-y: auto; font-size: 12px;">
                    <?php foreach ($errors as $error): ?>
                    <div style="padding: 4px 0; color: #991b1b;"><?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>
                </div>
            </details>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Stats -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
            <div style="background: #eff6ff; border: 1px solid #3b82f6; border-radius: 8px; padding: 16px; text-align: center;">
                <div style="font-size: 32px; font-weight: 800; color: #1e40af;"><?php echo number_format($totalLeads); ?></div>
                <div style="font-size: 14px; color: #1e40af; font-weight: 600;">Total Leads</div>
            </div>
            <div style="background: #d1fae5; border: 1px solid #10b981; border-radius: 8px; padding: 16px; text-align: center;">
                <div style="font-size: 32px; font-weight: 800; color: #065f46;"><?php echo number_format($taggedLeads); ?></div>
                <div style="font-size: 14px; color: #065f46; font-weight: 600;">Tagged Leads</div>
            </div>
            <div style="background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px; padding: 16px; text-align: center;">
                <div style="font-size: 32px; font-weight: 800; color: #92400e;"><?php echo number_format($untaggedLeads); ?></div>
                <div style="font-size: 14px; color: #92400e; font-weight: 600;">Untagged Leads</div>
            </div>
        </div>
        
        <!-- Batch Processing Form -->
        <div style="background: #f9fafb; padding: 24px; border-radius: 8px; border: 1px solid #e5e7eb;">
            <h3>Batch Process Settings</h3>
            <form method="post">
                <input type="hidden" name="action" value="batch_tag">
                
                <div style="display: grid; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Batch Size</label>
                        <select name="limit" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px;">
                            <option value="10">10 leads (test run)</option>
                            <option value="50" selected>50 leads</option>
                            <option value="100">100 leads</option>
                            <option value="250">250 leads</option>
                            <option value="500">500 leads</option>
                            <option value="1000">1000 leads (large batch)</option>
                        </select>
                        <small style="color: #6b7280; display: block; margin-top: 4px;">
                            Process this many leads per batch. Larger batches take longer.
                        </small>
                    </div>
                    
                    <div>
                        <label style="display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" name="overwrite_existing">
                            <span style="font-weight: 600;">Overwrite Existing Tags</span>
                        </label>
                        <small style="color: #6b7280; display: block; margin-top: 4px; margin-left: 28px;">
                            If checked, re-tag leads that already have tags. Otherwise, only tag untagged leads.
                        </small>
                    </div>
                </div>
                
                <div style="background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px; padding: 12px; margin-bottom: 16px;">
                    <strong style="color: #92400e;">⚠️ Important Notes:</strong>
                    <ul style="margin: 8px 0 0 20px; color: #92400e;">
                        <li>Only leads with confidence ≥70% will be auto-tagged</li>
                        <li>Low-confidence matches will be skipped</li>
                        <li>Processing large batches may take 30-60 seconds</li>
                        <li>You can run multiple batches to process all leads</li>
                    </ul>
                </div>
                
                <button type="submit" class="btn" style="background: #10b981; padding: 12px 24px; font-weight: 600;">
                    🚀 Start Batch Processing
                </button>
            </form>
        </div>
        
        <!-- Tag Distribution -->
        <div style="margin-top: 32px;">
            <h3>Current Tag Distribution</h3>
            <div style="display: grid; gap: 8px;">
                <?php
                $tagStats = $pdo->query('
                    SELECT lt.tag_name, lt.tag_color, lt.tag_icon, COUNT(lta.id) as count
                    FROM crm_lead_tags lt
                    LEFT JOIN crm_lead_tag_assignments lta ON lt.id = lta.tag_id
                    WHERE lt.is_active = 1
                    GROUP BY lt.id
                    ORDER BY count DESC, lt.sort_order ASC
                ')->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($tagStats as $tag):
                    $percentage = $taggedLeads > 0 ? round(($tag['count'] / $taggedLeads) * 100, 1) : 0;
                ?>
                <div style="display: flex; align-items: center; gap: 12px; padding: 12px; background: white; border: 1px solid #e5e7eb; border-radius: 8px;">
                    <span style="font-size: 24px;"><?php echo $tag['tag_icon']; ?></span>
                    <div style="flex: 1;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                            <strong><?php echo htmlspecialchars($tag['tag_name']); ?></strong>
                            <span style="color: #6b7280;"><?php echo $tag['count']; ?> leads (<?php echo $percentage; ?>%)</span>
                        </div>
                        <div style="background: #e5e7eb; height: 8px; border-radius: 4px; overflow: hidden;">
                            <div style="width: <?php echo $percentage; ?>%; height: 8px; background: <?php echo $tag['tag_color']; ?>; transition: width 0.3s ease;"></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

