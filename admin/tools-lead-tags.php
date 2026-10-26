<?php
/**
 * Lead Tags Management
 * Admin interface for managing predefined business type tags
 * 
 * @version 1.0.0
 * @prefix sacp1015_
 */

$SEO = [
    'title' => 'Lead Tags Management — Admin',
    'description' => 'Manage lead business type tags and auto-detection keywords',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
start_session();

$adminId = current_admin_id();
if (!$adminId) redirect(path_with_base('/admin/'));

$pdo = db();
$message = '';
$messageType = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'create') {
            $tagName = trim($_POST['tag_name'] ?? '');
            $tagSlug = strtolower(trim($_POST['tag_slug'] ?? ''));
            $tagSlug = preg_replace('/[^a-z0-9-]/', '-', $tagSlug);
            $tagColor = trim($_POST['tag_color'] ?? '#6b7280');
            $tagIcon = trim($_POST['tag_icon'] ?? '🏪');
            $sortOrder = (int)($_POST['sort_order'] ?? 0);
            $keywords = trim($_POST['auto_detect_keywords'] ?? '');
            
            // Parse keywords (comma-separated)
            $keywordArray = array_filter(array_map('trim', explode(',', $keywords)));
            $keywordsJson = json_encode($keywordArray);
            
            $stmt = $pdo->prepare('
                INSERT INTO crm_lead_tags (tag_name, tag_slug, tag_color, tag_icon, sort_order, auto_detect_keywords)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$tagName, $tagSlug, $tagColor, $tagIcon, $sortOrder, $keywordsJson]);
            
            $message = "Tag '$tagName' created successfully!";
            $messageType = 'success';
            
        } elseif ($action === 'update') {
            $tagId = (int)$_POST['tag_id'];
            $tagName = trim($_POST['tag_name'] ?? '');
            $tagColor = trim($_POST['tag_color'] ?? '#6b7280');
            $tagIcon = trim($_POST['tag_icon'] ?? '🏪');
            $sortOrder = (int)($_POST['sort_order'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $keywords = trim($_POST['auto_detect_keywords'] ?? '');
            
            // Parse keywords
            $keywordArray = array_filter(array_map('trim', explode(',', $keywords)));
            $keywordsJson = json_encode($keywordArray);
            
            $stmt = $pdo->prepare('
                UPDATE crm_lead_tags 
                SET tag_name = ?, tag_color = ?, tag_icon = ?, sort_order = ?, is_active = ?, auto_detect_keywords = ?
                WHERE id = ?
            ');
            $stmt->execute([$tagName, $tagColor, $tagIcon, $sortOrder, $isActive, $keywordsJson, $tagId]);
            
            $message = "Tag updated successfully!";
            $messageType = 'success';
            
        } elseif ($action === 'delete') {
            $tagId = (int)$_POST['tag_id'];
            
            // Check if tag is in use
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM crm_lead_tag_assignments WHERE tag_id = ?');
            $stmt->execute([$tagId]);
            $useCount = (int)$stmt->fetchColumn();
            
            if ($useCount > 0) {
                $message = "Cannot delete tag: it is assigned to $useCount lead(s). Please reassign leads first.";
                $messageType = 'error';
            } else {
                $stmt = $pdo->prepare('DELETE FROM crm_lead_tags WHERE id = ?');
                $stmt->execute([$tagId]);
                $message = "Tag deleted successfully!";
                $messageType = 'success';
            }
            
        } elseif ($action === 'approve_suggestion') {
            $suggestionId = (int)$_POST['suggestion_id'];
            $tagName = trim($_POST['tag_name'] ?? '');
            $tagSlug = strtolower(preg_replace('/[^a-z0-9-]/', '-', $tagName));
            
            // Create tag from suggestion
            $stmt = $pdo->prepare('
                INSERT INTO crm_lead_tags (tag_name, tag_slug, tag_color, tag_icon, sort_order, auto_detect_keywords)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $keywords = json_encode([strtolower($tagName)]);
            $stmt->execute([$tagName, $tagSlug, '#9ca3af', '🏷️', 50, $keywords]);
            
            // Mark suggestion as approved
            $stmt = $pdo->prepare('UPDATE crm_tag_suggestions SET status = ?, reviewed_by_admin_id = ?, reviewed_at = NOW() WHERE id = ?');
            $stmt->execute(['approved', $adminId, $suggestionId]);
            
            $message = "Suggestion approved and tag created!";
            $messageType = 'success';
            
        } elseif ($action === 'reject_suggestion') {
            $suggestionId = (int)$_POST['suggestion_id'];
            
            $stmt = $pdo->prepare('UPDATE crm_tag_suggestions SET status = ?, reviewed_by_admin_id = ?, reviewed_at = NOW() WHERE id = ?');
            $stmt->execute(['rejected', $adminId, $suggestionId]);
            
            $message = "Suggestion rejected.";
            $messageType = 'success';
        }
        
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $messageType = 'error';
    }
}

// Fetch tags
$tags = $pdo->query('
    SELECT t.*, 
           (SELECT COUNT(*) FROM crm_lead_tag_assignments WHERE tag_id = t.id) as usage_count
    FROM crm_lead_tags t
    ORDER BY t.sort_order ASC, t.tag_name ASC
')->fetchAll(PDO::FETCH_ASSOC);

// Fetch pending suggestions
$suggestions = $pdo->query('
    SELECT * FROM crm_tag_suggestions 
    WHERE status = "pending" 
    ORDER BY agent_count DESC, usage_count DESC
')->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <div class="card" style="margin-top: 24px;">
        <h1>🏷️ Lead Tags Management</h1>
        <p style="color: #6b7280;">Manage business type tags and auto-detection keywords for lead categorization.</p>
        
        <?php if ($message): ?>
        <div style="padding: 12px; border-radius: 8px; margin: 16px 0; background: <?php echo $messageType === 'success' ? '#d1fae5' : '#fee2e2'; ?>; border: 1px solid <?php echo $messageType === 'success' ? '#10b981' : '#ef4444'; ?>;">
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>
        
        <!-- Create New Tag -->
        <div style="background: #f9fafb; padding: 20px; border-radius: 8px; margin-bottom: 24px;">
            <h3>Create New Tag</h3>
            <form method="post">
                <input type="hidden" name="action" value="create">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 4px;">Tag Name *</label>
                        <input type="text" name="tag_name" required style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                    </div>
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 4px;">Slug *</label>
                        <input type="text" name="tag_slug" required placeholder="smoke-shop" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <small style="color: #6b7280;">Lowercase, hyphens only</small>
                    </div>
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 4px;">Color</label>
                        <input type="color" name="tag_color" value="#6b7280" style="width: 100%; height: 40px; border: 1px solid #d1d5db; border-radius: 6px;">
                    </div>
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 4px;">Icon</label>
                        <input type="text" name="tag_icon" value="🏪" maxlength="10" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                    </div>
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 4px;">Sort Order</label>
                        <input type="number" name="sort_order" value="0" min="0" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                    </div>
                </div>
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 4px;">Auto-Detect Keywords (comma-separated)</label>
                    <textarea name="auto_detect_keywords" rows="2" placeholder="smoke, vape, tobacco, cigar" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;"></textarea>
                    <small style="color: #6b7280;">Keywords used for automatic tag detection from company names</small>
                </div>
                <button type="submit" class="btn" style="background: #10b981;">Create Tag</button>
            </form>
        </div>
        
        <!-- Existing Tags -->
        <h3>Existing Tags</h3>
        <div style="display: grid; gap: 16px;">
            <?php foreach ($tags as $tag): 
                $keywords = json_decode($tag['auto_detect_keywords'], true) ?: [];
                $keywordsStr = implode(', ', $keywords);
            ?>
            <div style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; background: white;">
                <form method="post" style="display: flex; gap: 16px; align-items: start; flex-wrap: wrap;">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="tag_id" value="<?php echo $tag['id']; ?>">
                    
                    <div style="flex: 1; min-width: 200px;">
                        <label style="display: block; font-size: 12px; color: #6b7280; margin-bottom: 4px;">Tag Name</label>
                        <input type="text" name="tag_name" value="<?php echo htmlspecialchars($tag['tag_name']); ?>" required style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                    </div>
                    
                    <div style="width: 80px;">
                        <label style="display: block; font-size: 12px; color: #6b7280; margin-bottom: 4px;">Color</label>
                        <input type="color" name="tag_color" value="<?php echo htmlspecialchars($tag['tag_color']); ?>" style="width: 100%; height: 40px; border: 1px solid #d1d5db; border-radius: 6px;">
                    </div>
                    
                    <div style="width: 80px;">
                        <label style="display: block; font-size: 12px; color: #6b7280; margin-bottom: 4px;">Icon</label>
                        <input type="text" name="tag_icon" value="<?php echo htmlspecialchars($tag['tag_icon']); ?>" maxlength="10" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; text-align: center; font-size: 20px;">
                    </div>
                    
                    <div style="width: 100px;">
                        <label style="display: block; font-size: 12px; color: #6b7280; margin-bottom: 4px;">Sort Order</label>
                        <input type="number" name="sort_order" value="<?php echo $tag['sort_order']; ?>" min="0" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                    </div>
                    
                    <div style="flex-basis: 100%; margin-top: 8px;">
                        <label style="display: block; font-size: 12px; color: #6b7280; margin-bottom: 4px;">Auto-Detect Keywords</label>
                        <textarea name="auto_detect_keywords" rows="2" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;"><?php echo htmlspecialchars($keywordsStr); ?></textarea>
                    </div>
                    
                    <div style="flex-basis: 100%; display: flex; justify-content: space-between; align-items: center; margin-top: 8px;">
                        <div>
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" name="is_active" <?php echo $tag['is_active'] ? 'checked' : ''; ?>>
                                <span>Active</span>
                            </label>
                            <small style="color: #6b7280;">Used by <?php echo $tag['usage_count']; ?> leads</small>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <button type="submit" class="btn secondary" style="padding: 8px 16px;">Update</button>
                            <?php if ($tag['usage_count'] == 0): ?>
                            <button type="submit" name="action" value="delete" onclick="return confirm('Delete this tag?')" class="btn" style="background: #ef4444; padding: 8px 16px;">Delete</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Tag Suggestions from Agents -->
        <?php if (!empty($suggestions)): ?>
        <div style="margin-top: 32px;">
            <h3>Agent Tag Suggestions</h3>
            <p style="color: #6b7280; margin-bottom: 16px;">Custom tags used by multiple agents that may be worth adding as predefined tags.</p>
            <div style="display: grid; gap: 12px;">
                <?php foreach ($suggestions as $suggestion): ?>
                <div style="border: 1px solid #fbbf24; background: #fef3c7; border-radius: 8px; padding: 16px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong style="font-size: 16px;"><?php echo htmlspecialchars($suggestion['tag_name']); ?></strong>
                        <div style="font-size: 13px; color: #92400e; margin-top: 4px;">
                            Used by <strong><?php echo $suggestion['agent_count']; ?></strong> agents • 
                            <strong><?php echo $suggestion['usage_count']; ?></strong> total uses
                            <?php if ($suggestion['agent_count'] >= 20): ?>
                                <span style="background: #ef4444; color: white; padding: 2px 8px; border-radius: 4px; margin-left: 8px;">POPULAR</span>
                            <?php elseif ($suggestion['agent_count'] >= 5): ?>
                                <span style="background: #f59e0b; color: white; padding: 2px 8px; border-radius: 4px; margin-left: 8px;">HIGHLY SUGGESTED</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="action" value="approve_suggestion">
                            <input type="hidden" name="suggestion_id" value="<?php echo $suggestion['id']; ?>">
                            <input type="hidden" name="tag_name" value="<?php echo htmlspecialchars($suggestion['tag_name']); ?>">
                            <button type="submit" class="btn" style="background: #10b981; padding: 8px 16px;">Approve & Create</button>
                        </form>
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="action" value="reject_suggestion">
                            <input type="hidden" name="suggestion_id" value="<?php echo $suggestion['id']; ?>">
                            <button type="submit" class="btn secondary" style="padding: 8px 16px;" onclick="return confirm('Reject this suggestion?')">Reject</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>




