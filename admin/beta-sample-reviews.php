<?php
$SEO = [
    'title' => 'Beta Sample Reviews — Admin',
    'description' => 'Manage beta sample reviews from customers.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';

// Ensure user is admin
$userId = current_user_id();
if (!$userId || !user_has_role($userId, 'admin')) {
    flash_set('error', 'Access denied. Admin role required.');
    redirect('/login.php');
}

$pdo = db();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'approve_review') {
            $reviewId = intval($_POST['review_id'] ?? 0);
            
            if ($reviewId <= 0) {
                throw new Exception('Invalid review ID');
            }
            
            $stmt = $pdo->prepare('
                UPDATE beta_sample_reviews 
                SET status = "approved", approved_by = ?, approved_at = NOW() 
                WHERE id = ?
            ');
            $stmt->execute([$userId, $reviewId]);
            
            flash_set('success', 'Review approved successfully.');
            
        } elseif ($action === 'reject_review') {
            $reviewId = intval($_POST['review_id'] ?? 0);
            $adminNotes = trim($_POST['admin_notes'] ?? '');
            
            if ($reviewId <= 0) {
                throw new Exception('Invalid review ID');
            }
            
            $stmt = $pdo->prepare('
                UPDATE beta_sample_reviews 
                SET status = "rejected", admin_notes = ?, approved_by = ?, approved_at = NOW() 
                WHERE id = ?
            ');
            $stmt->execute([$adminNotes, $userId, $reviewId]);
            
            flash_set('success', 'Review rejected.');
            
        } elseif ($action === 'update_notes') {
            $reviewId = intval($_POST['review_id'] ?? 0);
            $adminNotes = trim($_POST['admin_notes'] ?? '');
            
            if ($reviewId <= 0) {
                throw new Exception('Invalid review ID');
            }
            
            $stmt = $pdo->prepare('UPDATE beta_sample_reviews SET admin_notes = ? WHERE id = ?');
            $stmt->execute([$adminNotes, $reviewId]);
            
            flash_set('success', 'Notes updated.');
        }
        
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    
    redirect($_SERVER['REQUEST_URI']);
}

// Get filter
$statusFilter = $_GET['status'] ?? 'pending';

// Build query
$whereConditions = ['1=1'];
$params = [];

if ($statusFilter !== 'all') {
    $whereConditions[] = 'r.status = ?';
    $params[] = $statusFilter;
}

$whereClause = implode(' AND ', $whereConditions);

// Get reviews
$stmt = $pdo->prepare("
    SELECT 
        r.*,
        u.first_name, u.last_name, u.email,
        p.name as product_name, p.sku as product_sku,
        sr.tracking_number,
        approver.first_name as approver_first_name,
        approver.last_name as approver_last_name
    FROM beta_sample_reviews r
    JOIN users u ON u.id = r.user_id
    JOIN products p ON p.id = r.product_id
    JOIN sample_requests sr ON sr.id = r.sample_request_id
    LEFT JOIN users approver ON approver.id = r.approved_by
    WHERE {$whereClause}
    ORDER BY r.created_at DESC
    LIMIT 100
");
$stmt->execute($params);
$reviews = $stmt->fetchAll();

// Get counts
$statusCounts = $pdo->query('
    SELECT status, COUNT(*) as count 
    FROM beta_sample_reviews 
    GROUP BY status
')->fetchAll(PDO::FETCH_KEY_PAIR);

// Get average ratings
$avgRatings = $pdo->query('
    SELECT 
        AVG(rating) as avg_overall,
        AVG(taste_rating) as avg_taste,
        AVG(clarity_rating) as avg_clarity,
        AVG(effects_rating) as avg_effects,
        SUM(CASE WHEN would_purchase = 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(*) as purchase_intent
    FROM beta_sample_reviews
    WHERE status = "approved"
')->fetch();
?>

<section class="section">
    <div class="container">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
            <div>
                <h1>🧪 Beta Sample Reviews</h1>
                <p class="muted">Review and approve customer feedback from beta testing program</p>
            </div>
            <a href="<?php echo outbound_path('/admin/'); ?>" class="btn secondary">← Back to Admin</a>
        </div>

        <!-- Analytics Summary -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px;">
            <div class="card" style="padding:20px;border-left:4px solid #f59e0b;">
                <div style="font-size:12px;color:#64748b;text-transform:uppercase;font-weight:600;margin-bottom:8px;">Pending Review</div>
                <div style="font-size:32px;font-weight:700;color:#f59e0b;"><?php echo $statusCounts['pending'] ?? 0; ?></div>
            </div>
            
            <div class="card" style="padding:20px;border-left:4px solid #10b981;">
                <div style="font-size:12px;color:#64748b;text-transform:uppercase;font-weight:600;margin-bottom:8px;">Approved</div>
                <div style="font-size:32px;font-weight:700;color:#10b981;"><?php echo $statusCounts['approved'] ?? 0; ?></div>
            </div>
            
            <div class="card" style="padding:20px;border-left:4px solid #ef4444;">
                <div style="font-size:12px;color:#64748b;text-transform:uppercase;font-weight:600;margin-bottom:8px;">Rejected</div>
                <div style="font-size:32px;font-weight:700;color:#ef4444;"><?php echo $statusCounts['rejected'] ?? 0; ?></div>
            </div>
            
            <div class="card" style="padding:20px;border-left:4px solid #3b82f6;">
                <div style="font-size:12px;color:#64748b;text-transform:uppercase;font-weight:600;margin-bottom:8px;">Avg Rating</div>
                <div style="font-size:32px;font-weight:700;color:#3b82f6;">
                    <?php echo $avgRatings && $avgRatings['avg_overall'] ? number_format($avgRatings['avg_overall'], 1) : 'N/A'; ?>
                    <?php if ($avgRatings && $avgRatings['avg_overall']): ?>
                        <span style="font-size:20px;">★</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card" style="padding:20px;border-left:4px solid #8b5cf6;">
                <div style="font-size:12px;color:#64748b;text-transform:uppercase;font-weight:600;margin-bottom:8px;">Purchase Intent</div>
                <div style="font-size:32px;font-weight:700;color:#8b5cf6;">
                    <?php echo $avgRatings && $avgRatings['purchase_intent'] ? number_format($avgRatings['purchase_intent'], 0) : '0'; ?>%
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card" style="margin-bottom:20px;">
            <form method="get" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
                <div>
                    <label style="font-size:12px;color:#64748b;display:block;margin-bottom:4px;">Status</label>
                    <select name="status" class="input" style="padding:8px 12px;">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending (<?php echo $statusCounts['pending'] ?? 0; ?>)</option>
                        <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>Approved (<?php echo $statusCounts['approved'] ?? 0; ?>)</option>
                        <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected (<?php echo $statusCounts['rejected'] ?? 0; ?>)</option>
                    </select>
                </div>
                
                <button type="submit" class="btn primary" style="margin-top:20px;">Filter</button>
            </form>
        </div>

        <!-- Reviews List -->
        <?php if (empty($reviews)): ?>
            <div class="card" style="padding:40px;text-align:center;color:#64748b;">
                <p style="font-size:18px;margin:0;">No reviews found.</p>
            </div>
        <?php else: ?>
            <?php foreach ($reviews as $review): ?>
                <div class="card" style="margin-bottom:20px;padding:24px;">
                    <!-- Header -->
                    <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:16px;">
                        <div>
                            <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
                                <div style="font-size:24px;color:#fbbf24;">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <?php echo $i <= $review['rating'] ? '★' : '☆'; ?>
                                    <?php endfor; ?>
                                </div>
                                <span class="status-badge" style="padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;
                                    <?php if ($review['status'] === 'approved'): ?>
                                        background:#dcfce7;color:#14532d;
                                    <?php elseif ($review['status'] === 'rejected'): ?>
                                        background:#fee2e2;color:#991b1b;
                                    <?php else: ?>
                                        background:#fef3c7;color:#92400e;
                                    <?php endif; ?>">
                                    <?php echo ucfirst($review['status']); ?>
                                </span>
                            </div>
                            
                            <?php if ($review['title']): ?>
                                <h3 style="margin:0 0 8px;font-size:18px;font-weight:700;color:#0f172a;">
                                    <?php echo htmlspecialchars($review['title']); ?>
                                </h3>
                            <?php endif; ?>
                            
                            <div style="font-size:14px;color:#64748b;">
                                <strong><?php echo htmlspecialchars($review['first_name'] . ' ' . $review['last_name']); ?></strong>
                                (<?php echo htmlspecialchars($review['email']); ?>)
                                • <?php echo htmlspecialchars($review['product_name']); ?>
                                • <?php echo date('M j, Y', strtotime($review['created_at'])); ?>
                            </div>
                        </div>
                    </div>

                    <!-- Review Text -->
                    <div style="background:#f8fafc;padding:16px;border-radius:8px;margin-bottom:16px;">
                        <p style="margin:0;color:#334155;line-height:1.6;">
                            <?php echo nl2br(htmlspecialchars($review['review_text'])); ?>
                        </p>
                    </div>

                    <!-- Detailed Ratings -->
                    <div style="display:flex;gap:24px;margin-bottom:16px;flex-wrap:wrap;">
                        <?php if ($review['taste_rating']): ?>
                            <div>
                                <div style="font-size:12px;color:#64748b;margin-bottom:4px;">Taste</div>
                                <div style="color:#fbbf24;font-size:16px;">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <?php echo $i <= $review['taste_rating'] ? '★' : '☆'; ?>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($review['clarity_rating']): ?>
                            <div>
                                <div style="font-size:12px;color:#64748b;margin-bottom:4px;">Clarity</div>
                                <div style="color:#fbbf24;font-size:16px;">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <?php echo $i <= $review['clarity_rating'] ? '★' : '☆'; ?>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($review['effects_rating']): ?>
                            <div>
                                <div style="font-size:12px;color:#64748b;margin-bottom:4px;">Effects</div>
                                <div style="color:#fbbf24;font-size:16px;">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <?php echo $i <= $review['effects_rating'] ? '★' : '☆'; ?>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($review['would_purchase']): ?>
                            <div>
                                <div style="font-size:12px;color:#64748b;margin-bottom:4px;">Purchase Intent</div>
                                <div style="color:#10b981;font-size:16px;font-weight:700;">✓ Yes</div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Admin Notes -->
                    <?php if ($review['admin_notes']): ?>
                        <div style="background:#fef3c7;border:1px solid #fde68a;padding:12px;border-radius:8px;margin-bottom:16px;">
                            <div style="font-size:12px;font-weight:600;color:#78350f;margin-bottom:4px;">Admin Notes:</div>
                            <div style="font-size:14px;color:#92400e;">
                                <?php echo nl2br(htmlspecialchars($review['admin_notes'])); ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Actions -->
                    <div style="display:flex;gap:12px;border-top:1px solid #e5e7eb;padding-top:16px;">
                        <?php if ($review['status'] === 'pending'): ?>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="approve_review">
                                <input type="hidden" name="review_id" value="<?php echo $review['id']; ?>">
                                <button type="submit" class="btn" style="padding:8px 16px;font-size:14px;background:#10b981;">
                                    ✓ Approve
                                </button>
                            </form>
                            
                            <button onclick="showRejectModal(<?php echo $review['id']; ?>)" class="btn" style="padding:8px 16px;font-size:14px;background:#ef4444;">
                                ✗ Reject
                            </button>
                        <?php endif; ?>
                        
                        <button onclick="showNotesModal(<?php echo $review['id']; ?>, <?php echo htmlspecialchars(json_encode($review['admin_notes'] ?? ''), ENT_QUOTES); ?>)" 
                            class="btn secondary" style="padding:8px 16px;font-size:14px;">
                            📝 <?php echo $review['admin_notes'] ? 'Edit Notes' : 'Add Notes'; ?>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<!-- Reject Modal -->
<div id="rejectModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.75);z-index:99999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;max-width:500px;width:90%;padding:24px;">
        <h3 style="margin:0 0 16px;font-size:20px;">Reject Review</h3>
        <form method="post">
            <input type="hidden" name="action" value="reject_review">
            <input type="hidden" name="review_id" id="reject_review_id">
            
            <label style="display:block;margin-bottom:16px;">
                <span style="display:block;margin-bottom:8px;font-weight:600;">Rejection Reason:</span>
                <textarea name="admin_notes" rows="4" required style="width:100%;padding:12px;border:1px solid #e5e7eb;border-radius:8px;"></textarea>
            </label>
            
            <div style="display:flex;gap:12px;">
                <button type="submit" class="btn" style="background:#ef4444;">Reject Review</button>
                <button type="button" onclick="document.getElementById('rejectModal').style.display='none'" class="btn secondary">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Notes Modal -->
<div id="notesModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.75);z-index:99999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;max-width:500px;width:90%;padding:24px;">
        <h3 style="margin:0 0 16px;font-size:20px;">Admin Notes</h3>
        <form method="post">
            <input type="hidden" name="action" value="update_notes">
            <input type="hidden" name="review_id" id="notes_review_id">
            
            <label style="display:block;margin-bottom:16px;">
                <span style="display:block;margin-bottom:8px;font-weight:600;">Notes (Internal Only):</span>
                <textarea name="admin_notes" id="notes_textarea" rows="4" style="width:100%;padding:12px;border:1px solid #e5e7eb;border-radius:8px;"></textarea>
            </label>
            
            <div style="display:flex;gap:12px;">
                <button type="submit" class="btn">Save Notes</button>
                <button type="button" onclick="document.getElementById('notesModal').style.display='none'" class="btn secondary">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function showRejectModal(reviewId) {
    document.getElementById('reject_review_id').value = reviewId;
    document.getElementById('rejectModal').style.display = 'flex';
}

function showNotesModal(reviewId, currentNotes) {
    document.getElementById('notes_review_id').value = reviewId;
    document.getElementById('notes_textarea').value = currentNotes || '';
    document.getElementById('notesModal').style.display = 'flex';
}

// Close modals on outside click
document.getElementById('rejectModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});

document.getElementById('notesModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>



