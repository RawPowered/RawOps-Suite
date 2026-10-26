<?php
$SEO = [
    'title' => 'Module Reviews — Product Admin',
    'description' => 'Monitor and manage training module reviews and feedback.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect();

$pdo = db();

// Handle admin actions (flagging, responding, etc.)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $reviewId = intval($_POST['review_id'] ?? 0);
    $adminId = current_admin_id();
    
    try {
        if ($action === 'flag' && $reviewId) {
            $stmt = $pdo->prepare('UPDATE training_module_reviews SET admin_flagged = 1 WHERE id = ?');
            $stmt->execute([$reviewId]);
            
            // Log the action
            $stmt = $pdo->prepare('INSERT INTO training_review_admin_log (review_id, admin_id, action_type, action_details) VALUES (?, ?, "flag", "Review flagged for admin attention")');
            $stmt->execute([$reviewId, $adminId]);
            
            flash_set('success', 'Review has been flagged for attention.');
        } elseif ($action === 'unflag' && $reviewId) {
            $stmt = $pdo->prepare('UPDATE training_module_reviews SET admin_flagged = 0 WHERE id = ?');
            $stmt->execute([$reviewId]);
            
            $stmt = $pdo->prepare('INSERT INTO training_review_admin_log (review_id, admin_id, action_type, action_details) VALUES (?, ?, "unflag", "Review flag removed")');
            $stmt->execute([$reviewId, $adminId]);
            
            flash_set('success', 'Review flag has been removed.');
        } elseif ($action === 'respond' && $reviewId) {
            $response = trim($_POST['admin_response'] ?? '');
            if ($response) {
                $stmt = $pdo->prepare('UPDATE training_module_reviews SET admin_response = ?, admin_responder_id = ?, admin_response_date = NOW() WHERE id = ?');
                $stmt->execute([$response, $adminId, $reviewId]);
                
                $stmt = $pdo->prepare('INSERT INTO training_review_admin_log (review_id, admin_id, action_type, action_details) VALUES (?, ?, "respond", "Admin response added")');
                $stmt->execute([$reviewId, $adminId]);
                
                flash_set('success', 'Your response has been added to the review.');
            }
        }
    } catch (Exception $e) {
        flash_set('error', 'Action could not be completed: ' . $e->getMessage());
    }
    
    redirect($_SERVER['REQUEST_URI']);
}

// Get filter parameters
$moduleFilter = $_GET['module'] ?? '';
$ratingFilter = $_GET['rating'] ?? '';
$flaggedOnly = isset($_GET['flagged']);
$sortBy = $_GET['sort'] ?? 'recent';

// Build the query
$whereConditions = [];
$params = [];

if ($moduleFilter) {
    $whereConditions[] = 'tm.slug = ?';
    $params[] = $moduleFilter;
}

if ($ratingFilter) {
    $whereConditions[] = 'tmr.rating = ?';
    $params[] = intval($ratingFilter);
}

if ($flaggedOnly) {
    $whereConditions[] = 'tmr.admin_flagged = 1';
}

$whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

$orderClause = match($sortBy) {
    'rating_desc' => 'ORDER BY tmr.rating DESC, tmr.created_at DESC',
    'rating_asc' => 'ORDER BY tmr.rating ASC, tmr.created_at DESC',
    'module' => 'ORDER BY tm.sort_order ASC, tmr.created_at DESC',
    default => 'ORDER BY tmr.created_at DESC'
};

try {
    // Get reviews
    $stmt = $pdo->prepare("
        SELECT 
            tmr.*,
            tm.title as module_title,
            tm.slug as module_slug,
            tm.sort_order,
            CASE 
                WHEN tmr.anonymous = 1 THEN 'Anonymous User'
                ELSE CONCAT(u.first_name, ' ', u.last_name)
            END as reviewer_name,
            CASE 
                WHEN tmr.anonymous = 1 THEN 'sample-011@example.com'
                ELSE u.email
            END as reviewer_email,
            CASE 
                WHEN tmr.admin_responder_id IS NOT NULL THEN 
                    CONCAT(ar.first_name, ' ', ar.last_name)
                ELSE NULL
            END as admin_responder
        FROM training_module_reviews tmr
        JOIN training_modules tm ON tmr.module_id = tm.id
        JOIN users u ON tmr.user_id = u.id
        LEFT JOIN users ar ON tmr.admin_responder_id = ar.id
        {$whereClause}
        {$orderClause}
        LIMIT 100
    ");
    $stmt->execute($params);
    $reviews = $stmt->fetchAll();
    
    // Get summary statistics
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_reviews,
            AVG(rating) as avg_rating,
            COUNT(CASE WHEN admin_flagged = 1 THEN 1 END) as flagged_count,
            COUNT(CASE WHEN admin_response IS NOT NULL THEN 1 END) as responded_count
        FROM training_module_reviews
    ");
    $summary = $stmt->fetch();
    
    // Get module statistics (no dependency on view)
    $stmt = $pdo->query("
        SELECT 
            tm.slug,
            tm.title,
            tm.sort_order,
            COUNT(tmr.id) AS total_reviews,
            AVG(tmr.rating) AS average_rating,
            AVG(tmr.difficulty_rating) AS average_difficulty,
            AVG(tmr.content_quality_rating) AS average_content_quality,
            AVG(tmr.usefulness_rating) AS average_usefulness,
            AVG(CASE WHEN tmr.would_recommend = 1 THEN 100 ELSE NULL END) AS recommendation_percentage,
            SUM(CASE WHEN tmr.admin_flagged = 1 THEN 1 ELSE 0 END) AS flagged_reviews_count
        FROM training_modules tm
        LEFT JOIN training_module_reviews tmr ON tmr.module_id = tm.id
        GROUP BY tm.id, tm.slug, tm.title, tm.sort_order
        ORDER BY tm.sort_order ASC
    ");
    $moduleStats = $stmt->fetchAll();
    
    // Get available modules for filter
    $stmt = $pdo->query("SELECT slug, title FROM training_modules ORDER BY sort_order ASC");
    $modules = $stmt->fetchAll();
    
} catch (Exception $e) {
    $reviews = [];
    $summary = ['total_reviews' => 0, 'avg_rating' => 0, 'flagged_count' => 0, 'responded_count' => 0];
    $moduleStats = [];
    $modules = [];
    $error = "Review data not available: " . $e->getMessage();
}
?>

<section class="section">
    <div class="container">
        <div class="admin-header">
            <h1>Module Reviews Management</h1>
            <div class="admin-nav">
                <a href="<?php echo path_with_base('/admin/'); ?>" class="btn secondary">← Admin Dashboard</a>
                <a href="<?php echo path_with_base('/admin/training-analytics.php'); ?>" class="btn secondary">Training Analytics</a>
            </div>
        </div>

        <?php if (isset($error)): ?>
        <div class="card" style="border-color: #fca5a5; background: #fef2f2; color: #991b1b;">
            <p>⚠️ <?php echo sanitize($error); ?></p>
            <p style="font-size: 14px; margin-top: 8px;">
                The review system may not be installed yet. Run the LMS patch: 
                <code>php /lms-patch-system.php</code>
            </p>
        </div>
        <?php endif; ?>

        <!-- Summary Statistics -->
        <div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 32px;">
            <div class="card" style="text-align: center; padding: 24px;">
                <div style="font-size: 32px; font-weight: 800; color: var(--blue);"><?php echo number_format($summary['total_reviews']); ?></div>
                <div style="color: #6b7280; font-size: 14px;">Total Reviews</div>
            </div>
            <div class="card" style="text-align: center; padding: 24px;">
                <div style="font-size: 32px; font-weight: 800; color: var(--gold);"><?php echo number_format($summary['avg_rating'], 1); ?> ★</div>
                <div style="color: #6b7280; font-size: 14px;">Average Rating</div>
            </div>
            <div class="card" style="text-align: center; padding: 24px;">
                <div style="font-size: 32px; font-weight: 800; color: var(--red);"><?php echo number_format($summary['flagged_count']); ?></div>
                <div style="color: #6b7280; font-size: 14px;">Flagged Reviews</div>
            </div>
            <div class="card" style="text-align: center; padding: 24px;">
                <div style="font-size: 32px; font-weight: 800; color: var(--green);"><?php echo number_format($summary['responded_count']); ?></div>
                <div style="color: #6b7280; font-size: 14px;">Admin Responses</div>
            </div>
        </div>

        <!-- Module Performance Overview -->
        <?php if ($moduleStats): ?>
        <div class="card" style="margin-bottom: 32px;">
            <h3>Module Performance Overview</h3>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; margin-top: 16px;">
                    <thead>
                        <tr style="border-bottom: 2px solid #e5e7eb;">
                            <th style="text-align: left; padding: 8px;">Module</th>
                            <th style="text-align: center; padding: 8px;">Reviews</th>
                            <th style="text-align: center; padding: 8px;">Rating</th>
                            <th style="text-align: center; padding: 8px;">Difficulty</th>
                            <th style="text-align: center; padding: 8px;">Quality</th>
                            <th style="text-align: center; padding: 8px;">Usefulness</th>
                            <th style="text-align: center; padding: 8px;">Recommend</th>
                            <th style="text-align: center; padding: 8px;">Flagged</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($moduleStats as $module): ?>
                        <tr style="border-bottom: 1px solid #f3f4f6;">
                            <td style="padding: 8px; font-weight: 600;">
                                <a href="?module=<?php echo urlencode($module['slug']); ?>" style="color: var(--blue); text-decoration: none;">
                                    <?php echo sanitize($module['title']); ?>
                                </a>
                            </td>
                            <td style="text-align: center; padding: 8px;"><?php echo intval($module['total_reviews']); ?></td>
                            <td style="text-align: center; padding: 8px;">
                                <span style="color: var(--gold);">
                                    <?php echo $module['average_rating'] > 0 ? number_format($module['average_rating'], 1) . ' ★' : '—'; ?>
                                </span>
                            </td>
                            <td style="text-align: center; padding: 8px;">
                                <?php echo $module['average_difficulty'] > 0 ? number_format($module['average_difficulty'], 1) : '—'; ?>
                            </td>
                            <td style="text-align: center; padding: 8px;">
                                <?php echo $module['average_content_quality'] > 0 ? number_format($module['average_content_quality'], 1) : '—'; ?>
                            </td>
                            <td style="text-align: center; padding: 8px;">
                                <?php echo $module['average_usefulness'] > 0 ? number_format($module['average_usefulness'], 1) : '—'; ?>
                            </td>
                            <td style="text-align: center; padding: 8px;">
                                <?php if ($module['recommendation_percentage'] > 0): ?>
                                    <span style="color: var(--green);"><?php echo number_format($module['recommendation_percentage'], 0); ?>%</span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center; padding: 8px;">
                                <?php if ($module['flagged_reviews_count'] > 0): ?>
                                    <span style="color: var(--red); font-weight: 600;"><?php echo $module['flagged_reviews_count']; ?></span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="card" style="margin-bottom: 24px;">
            <h3>Filter Reviews</h3>
            <form method="get" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; align-items: end;">
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Module</label>
                    <select name="module" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="">All Modules</option>
                        <?php foreach ($modules as $module): ?>
                        <option value="<?php echo sanitize($module['slug']); ?>" <?php echo $moduleFilter === $module['slug'] ? 'selected' : ''; ?>>
                            <?php echo sanitize($module['title']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Rating</label>
                    <select name="rating" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="">All Ratings</option>
                        <?php for ($i = 5; $i >= 1; $i--): ?>
                        <option value="<?php echo $i; ?>" <?php echo $ratingFilter == $i ? 'selected' : ''; ?>>
                            <?php echo $i; ?> Star<?php echo $i > 1 ? 's' : ''; ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Sort By</label>
                    <select name="sort" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="recent" <?php echo $sortBy === 'recent' ? 'selected' : ''; ?>>Most Recent</option>
                        <option value="rating_desc" <?php echo $sortBy === 'rating_desc' ? 'selected' : ''; ?>>Highest Rating</option>
                        <option value="rating_asc" <?php echo $sortBy === 'rating_asc' ? 'selected' : ''; ?>>Lowest Rating</option>
                        <option value="module" <?php echo $sortBy === 'module' ? 'selected' : ''; ?>>By Module</option>
                    </select>
                </div>
                
                <div>
                    <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                        <input type="checkbox" name="flagged" value="1" <?php echo $flaggedOnly ? 'checked' : ''; ?>>
                        Flagged Only
                    </label>
                    <button type="submit" class="btn" style="width: 100%; padding: 8px;">Apply Filters</button>
                </div>
            </form>
        </div>

        <!-- Reviews List -->
        <div class="reviews-container">
            <?php if (empty($reviews)): ?>
            <div class="card" style="text-align: center; padding: 48px; color: #6b7280;">
                <div style="font-size: 48px; margin-bottom: 16px;">📝</div>
                <h3>No Reviews Found</h3>
                <p>No reviews match your current filters, or the review system hasn't been used yet.</p>
            </div>
            <?php else: ?>
                <?php foreach ($reviews as $review): ?>
                <div class="card review-card" style="margin-bottom: 24px; <?php echo $review['admin_flagged'] ? 'border-color: #f59e0b; background: #fffbeb;' : ''; ?>">
                    <!-- Review Header -->
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px;">
                        <div>
                            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
                                <h4 style="margin: 0; color: var(--blue);">
                                    <?php echo sanitize($review['module_title']); ?>
                                </h4>
                                <div style="display: flex; gap: 2px;">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <span style="color: <?php echo $i <= $review['rating'] ? '#fbbf24' : '#d1d5db'; ?>; font-size: 16px;">★</span>
                                    <?php endfor; ?>
                                </div>
                                <?php if ($review['admin_flagged']): ?>
                                <span style="background: #f59e0b; color: white; padding: 2px 8px; border-radius: 4px; font-size: 12px;">FLAGGED</span>
                                <?php endif; ?>
                            </div>
                            <div style="color: #6b7280; font-size: 14px;">
                                By: <?php echo sanitize($review['reviewer_name']); ?> 
                                (<?php echo sanitize($review['reviewer_email']); ?>) • 
                                <?php echo date('M j, Y g:i A', strtotime($review['created_at'])); ?>
                                <?php if ($review['completion_time_minutes']): ?>
                                • Completed in <?php echo $review['completion_time_minutes']; ?> minutes
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div style="display: flex; gap: 8px; flex-shrink: 0;">
                            <?php if (!$review['admin_flagged']): ?>
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="action" value="flag">
                                <input type="hidden" name="review_id" value="<?php echo $review['id']; ?>">
                                <button type="submit" class="btn secondary" style="font-size: 12px; padding: 4px 8px;" onclick="return confirm('Flag this review for attention?')">
                                    🚩 Flag
                                </button>
                            </form>
                            <?php else: ?>
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="action" value="unflag">
                                <input type="hidden" name="review_id" value="<?php echo $review['id']; ?>">
                                <button type="submit" class="btn secondary" style="font-size: 12px; padding: 4px 8px;">
                                    ✅ Unflag
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Review Content -->
                    <?php if ($review['review_title']): ?>
                    <h5 style="margin: 0 0 8px; color: #1f2937;"><?php echo sanitize($review['review_title']); ?></h5>
                    <?php endif; ?>
                    
                    <?php if ($review['review_comments']): ?>
                    <p style="margin: 0 0 16px; line-height: 1.6;"><?php echo nl2br(sanitize($review['review_comments'])); ?></p>
                    <?php endif; ?>
                    
                    <!-- Detailed Ratings -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin: 16px 0; font-size: 14px;">
                        <div>
                            <strong>Difficulty:</strong> 
                            <span style="color: #fbbf24;"><?php echo str_repeat('★', $review['difficulty_rating']); ?></span>
                            <span style="color: #d1d5db;"><?php echo str_repeat('★', 5 - $review['difficulty_rating']); ?></span>
                        </div>
                        <div>
                            <strong>Quality:</strong> 
                            <span style="color: #fbbf24;"><?php echo str_repeat('★', $review['content_quality_rating']); ?></span>
                            <span style="color: #d1d5db;"><?php echo str_repeat('★', 5 - $review['content_quality_rating']); ?></span>
                        </div>
                        <div>
                            <strong>Usefulness:</strong> 
                            <span style="color: #fbbf24;"><?php echo str_repeat('★', $review['usefulness_rating']); ?></span>
                            <span style="color: #d1d5db;"><?php echo str_repeat('★', 5 - $review['usefulness_rating']); ?></span>
                        </div>
                    </div>
                    
                    <!-- Suggestions and Questions -->
                    <?php if ($review['suggestions'] || $review['questions']): ?>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 16px 0; padding: 16px; background: #f9fafb; border-radius: 8px;">
                        <?php if ($review['suggestions']): ?>
                        <div>
                            <strong style="color: var(--blue);">Suggestions:</strong>
                            <p style="margin: 4px 0 0; font-size: 14px; line-height: 1.5;"><?php echo nl2br(sanitize($review['suggestions'])); ?></p>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($review['questions']): ?>
                        <div>
                            <strong style="color: var(--blue);">Questions:</strong>
                            <p style="margin: 4px 0 0; font-size: 14px; line-height: 1.5;"><?php echo nl2br(sanitize($review['questions'])); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Recommendation -->
                    <div style="margin: 16px 0;">
                        <strong>Would recommend:</strong> 
                        <span style="color: <?php echo $review['would_recommend'] ? 'var(--green)' : 'var(--red)'; ?>;">
                            <?php echo $review['would_recommend'] ? '✅ Yes' : '❌ No'; ?>
                        </span>
                    </div>
                    
                    <!-- Admin Response Section -->
                    <?php if ($review['admin_response']): ?>
                    <div style="background: #f0fdf4; border: 1px solid #16a34a; border-radius: 8px; padding: 16px; margin-top: 16px;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                            <span style="color: #166534; font-weight: 600;">Admin Response</span>
                            <span style="color: #6b7280; font-size: 12px;">
                                by <?php echo sanitize($review['admin_responder']); ?> on 
                                <?php echo date('M j, Y', strtotime($review['admin_response_date'])); ?>
                            </span>
                        </div>
                        <p style="margin: 0; color: #166534; line-height: 1.5;"><?php echo nl2br(sanitize($review['admin_response'])); ?></p>
                    </div>
                    <?php else: ?>
                    <!-- Add Response Form -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-top: 16px;">
                        <form method="post">
                            <input type="hidden" name="action" value="respond">
                            <input type="hidden" name="review_id" value="<?php echo $review['id']; ?>">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Add Admin Response:</label>
                            <textarea name="admin_response" rows="3" placeholder="Respond to this review..." required
                                      style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px; resize: vertical; margin-bottom: 12px;"></textarea>
                            <button type="submit" class="btn" style="font-size: 14px; padding: 8px 16px;">
                                Post Response
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<style>
.review-card {
    transition: all 0.2s ease;
}

.review-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.1);
}

.stats-grid .card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.1);
}
</style>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
