<?php
$SEO = [
    'title' => 'Beta Sample Feedback - Product™',
    'description' => 'Share your feedback about your Product beta sample.',
    'type' => 'website'
];

require_once __DIR__ . '/../required/header.php';

// Require login
start_session();
$userId = current_user_id();

if (!$userId) {
    redirect('/login.php?return=' . urlencode('/customer/beta-feedback.php'));
}

$pdo = db();

// Get user's beta sample request
$stmt = $pdo->prepare('
    SELECT sr.*, p.id as product_id, p.name as product_name, p.sku
    FROM sample_requests sr
    JOIN products p ON p.id = sr.sample_product_id
    WHERE sr.agent_id = ? AND sr.is_beta_sample = 1 AND sr.status IN ("shipped", "delivered")
    ORDER BY sr.requested_at DESC
    LIMIT 1
');
$stmt->execute([$userId]);
$sampleRequest = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sampleRequest) {
    flash_set('error', 'No eligible beta sample found. You must have received a shipped beta sample to leave a review.');
    redirect('/customer/');
}

// Check if user already submitted a review
$stmt = $pdo->prepare('SELECT id FROM beta_sample_reviews WHERE sample_request_id = ? AND user_id = ?');
$stmt->execute([$sampleRequest['id'], $userId]);
$existingReview = $stmt->fetch();

require_once __DIR__ . '/../required/header.php';
?>

<section class="section" style="background:linear-gradient(135deg,#f8fafc,#ffffff);min-height:calc(100vh - 200px);">
    <div class="container" style="max-width:800px;">
        
        <?php if ($existingReview): ?>
            <!-- Already submitted -->
            <div class="card" style="border:2px solid #10b981;background:#ecfdf5;padding:32px;text-align:center;">
                <div style="font-size:64px;margin-bottom:16px;">✅</div>
                <h1 style="margin:0 0 16px;color:#065f46;font-size:32px;font-weight:900;">Thank You for Your Feedback!</h1>
                <p style="margin:0 0 24px;color:#047857;font-size:18px;">
                    Your review has been submitted and is pending approval. We truly appreciate you taking the time to help us improve our product!
                </p>
                <a href="<?php echo outbound_path('/customer/'); ?>" class="btn" style="display:inline-block;padding:14px 32px;font-size:16px;">
                    Return to Dashboard
                </a>
            </div>
            
        <?php else: ?>
            <!-- Review form -->
            <div class="card" style="background:#fff;border:3px solid #e2e8f0;border-radius:20px;padding:40px;box-shadow:0 24px 80px rgba(15,23,42,0.15);">
                
                <div style="text-align:center;margin-bottom:32px;">
                    <div style="display:inline-block;padding:8px 20px;background:rgba(14,165,233,0.1);border:1px solid rgba(14,165,233,0.3);border-radius:50px;margin-bottom:16px;">
                        <span style="color:#0284c7;font-weight:700;font-size:13px;letter-spacing:1px;text-transform:uppercase;">
                            🧪 Beta Testing Program
                        </span>
                    </div>
                    <h1 style="margin:0 0 12px;color:#0c4a6e;font-size:36px;font-weight:900;">How Was Your Sample?</h1>
                    <p style="margin:0 0 8px;color:#475569;font-size:17px;">
                        Product: <strong><?php echo htmlspecialchars($sampleRequest['product_name']); ?></strong>
                    </p>
                    <p style="margin:0;color:#64748b;font-size:15px;">
                        Your honest feedback helps us perfect this product before launch
                    </p>
                </div>
                
                <form id="reviewForm">
                    <input type="hidden" name="sample_request_id" value="<?php echo $sampleRequest['id']; ?>">
                    <input type="hidden" name="product_id" value="<?php echo $sampleRequest['product_id']; ?>">
                    
                    <!-- Overall Rating -->
                    <div style="margin-bottom:32px;">
                        <label style="display:block;margin-bottom:12px;font-weight:700;color:#0f172a;font-size:18px;">
                            Overall Rating *
                        </label>
                        <div class="star-rating" style="font-size:40px;color:#e5e7eb;cursor:pointer;">
                            <span class="star" data-rating="1">★</span>
                            <span class="star" data-rating="2">★</span>
                            <span class="star" data-rating="3">★</span>
                            <span class="star" data-rating="4">★</span>
                            <span class="star" data-rating="5">★</span>
                        </div>
                        <input type="hidden" name="rating" id="rating" required>
                    </div>
                    
                    <!-- Review Title -->
                    <div style="margin-bottom:24px;">
                        <label style="display:block;margin-bottom:8px;font-weight:600;color:#0f172a;">
                            Review Title (Optional)
                        </label>
                        <input type="text" name="title" maxlength="255" placeholder="e.g., Clean and effective!"
                            style="width:100%;padding:12px;border:1px solid var(--border);border-radius:10px;font-size:15px;">
                    </div>
                    
                    <!-- Review Text -->
                    <div style="margin-bottom:32px;">
                        <label style="display:block;margin-bottom:8px;font-weight:600;color:#0f172a;">
                            Your Review *
                        </label>
                        <textarea name="review_text" rows="6" required placeholder="Tell us about your experience with taste, clarity, effects, and anything else..."
                            style="width:100%;padding:12px;border:1px solid var(--border);border-radius:10px;font-size:15px;resize:vertical;"></textarea>
                    </div>
                    
                    <!-- Detailed Ratings -->
                    <div style="background:#f8fafc;border-radius:12px;padding:24px;margin-bottom:32px;">
                        <h3 style="margin:0 0 20px;color:#0f172a;font-size:18px;font-weight:700;">Detailed Ratings</h3>
                        
                        <!-- Taste Rating -->
                        <div style="margin-bottom:20px;">
                            <label style="display:block;margin-bottom:8px;font-weight:600;color:#0f172a;font-size:15px;">
                                Taste (Optional)
                            </label>
                            <div class="detail-stars" data-field="taste_rating" style="font-size:24px;color:#e5e7eb;cursor:pointer;">
                                <span class="detail-star" data-rating="1">★</span>
                                <span class="detail-star" data-rating="2">★</span>
                                <span class="detail-star" data-rating="3">★</span>
                                <span class="detail-star" data-rating="4">★</span>
                                <span class="detail-star" data-rating="5">★</span>
                            </div>
                            <input type="hidden" name="taste_rating" id="taste_rating">
                        </div>
                        
                        <!-- Clarity Rating -->
                        <div style="margin-bottom:20px;">
                            <label style="display:block;margin-bottom:8px;font-weight:600;color:#0f172a;font-size:15px;">
                                Clarity (Optional)
                            </label>
                            <div class="detail-stars" data-field="clarity_rating" style="font-size:24px;color:#e5e7eb;cursor:pointer;">
                                <span class="detail-star" data-rating="1">★</span>
                                <span class="detail-star" data-rating="2">★</span>
                                <span class="detail-star" data-rating="3">★</span>
                                <span class="detail-star" data-rating="4">★</span>
                                <span class="detail-star" data-rating="5">★</span>
                            </div>
                            <input type="hidden" name="clarity_rating" id="clarity_rating">
                        </div>
                        
                        <!-- Effects Rating -->
                        <div style="margin-bottom:0;">
                            <label style="display:block;margin-bottom:8px;font-weight:600;color:#0f172a;font-size:15px;">
                                Effects (Optional)
                            </label>
                            <div class="detail-stars" data-field="effects_rating" style="font-size:24px;color:#e5e7eb;cursor:pointer;">
                                <span class="detail-star" data-rating="1">★</span>
                                <span class="detail-star" data-rating="2">★</span>
                                <span class="detail-star" data-rating="3">★</span>
                                <span class="detail-star" data-rating="4">★</span>
                                <span class="detail-star" data-rating="5">★</span>
                            </div>
                            <input type="hidden" name="effects_rating" id="effects_rating">
                        </div>
                    </div>
                    
                    <!-- Would Purchase -->
                    <div style="margin-bottom:32px;background:#fffbeb;border:2px solid #fde68a;border-radius:12px;padding:20px;">
                        <label style="display:flex;align-items:start;gap:12px;cursor:pointer;">
                            <input type="checkbox" name="would_purchase" value="1" style="margin-top:4px;width:20px;height:20px;">
                            <span style="color:#78350f;font-size:15px;font-weight:600;">
                                I would purchase this product when it's released
                            </span>
                        </label>
                    </div>
                    
                    <!-- Submit Button -->
                    <div id="submitError" style="display:none;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 16px;border-radius:8px;margin-bottom:16px;"></div>
                    
                    <button type="submit" class="btn" style="width:100%;padding:16px;font-size:18px;font-weight:700;">
                        <i class="fas fa-paper-plane" style="margin-right:8px;"></i>
                        Submit Review
                    </button>
                    
                    <p style="margin:16px 0 0;text-align:center;color:#64748b;font-size:13px;">
                        Your review will be reviewed by our team before being published
                    </p>
                </form>
            </div>
        <?php endif; ?>
    </div>
</section>

<style>
.star, .detail-star {
    transition: color 0.2s;
}

.star:hover, .detail-star:hover {
    transform: scale(1.1);
}

.star.active, .detail-star.active {
    color: #fbbf24 !important;
}

@media (max-width: 768px) {
    .card {
        padding: 24px !important;
    }
    
    h1 {
        font-size: 28px !important;
    }
    
    .star-rating {
        font-size: 32px !important;
    }
    
    .detail-stars {
        font-size: 20px !important;
    }
}
</style>

<script>
// Overall rating stars
const starRating = document.querySelector('.star-rating');
const stars = document.querySelectorAll('.star');
const ratingInput = document.getElementById('rating');

stars.forEach(star => {
    star.addEventListener('click', function() {
        const rating = this.dataset.rating;
        ratingInput.value = rating;
        
        stars.forEach(s => {
            if (s.dataset.rating <= rating) {
                s.classList.add('active');
            } else {
                s.classList.remove('active');
            }
        });
    });
    
    star.addEventListener('mouseenter', function() {
        const rating = this.dataset.rating;
        stars.forEach(s => {
            if (s.dataset.rating <= rating) {
                s.style.color = '#fbbf24';
            } else {
                s.style.color = '#e5e7eb';
            }
        });
    });
});

starRating?.addEventListener('mouseleave', function() {
    const currentRating = ratingInput.value;
    stars.forEach(s => {
        if (s.dataset.rating <= currentRating) {
            s.style.color = '#fbbf24';
        } else {
            s.style.color = '#e5e7eb';
        }
    });
});

// Detail rating stars
document.querySelectorAll('.detail-stars').forEach(container => {
    const field = container.dataset.field;
    const detailStars = container.querySelectorAll('.detail-star');
    const input = document.getElementById(field);
    
    detailStars.forEach(star => {
        star.addEventListener('click', function() {
            const rating = this.dataset.rating;
            input.value = rating;
            
            detailStars.forEach(s => {
                if (s.dataset.rating <= rating) {
                    s.classList.add('active');
                } else {
                    s.classList.remove('active');
                }
            });
        });
        
        star.addEventListener('mouseenter', function() {
            const rating = this.dataset.rating;
            detailStars.forEach(s => {
                if (s.dataset.rating <= rating) {
                    s.style.color = '#fbbf24';
                } else {
                    s.style.color = '#e5e7eb';
                }
            });
        });
    });
    
    container.addEventListener('mouseleave', function() {
        const currentRating = input.value;
        detailStars.forEach(s => {
            if (s.dataset.rating <= currentRating) {
                s.style.color = '#fbbf24';
            } else {
                s.style.color = '#e5e7eb';
            }
        });
    });
});

// Form submission
document.getElementById('reviewForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const submitBtn = this.querySelector('button[type="submit"]');
    const errorDiv = document.getElementById('submitError');
    const originalBtnText = submitBtn.innerHTML;
    
    // Validate overall rating
    if (!document.getElementById('rating').value) {
        errorDiv.textContent = 'Please select an overall rating';
        errorDiv.style.display = 'block';
        return;
    }
    
    // Disable button
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:8px;"></i>Submitting...';
    errorDiv.style.display = 'none';
    
    // Collect form data
    const formData = new FormData(this);
    const data = Object.fromEntries(formData.entries());
    
    try {
        const response = await fetch('<?php echo outbound_path('/api/beta-sample-review.php'); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.ok) {
            // Success - reload page to show thank you message
            window.location.reload();
        } else {
            // Show error
            errorDiv.textContent = result.error || 'An error occurred. Please try again.';
            errorDiv.style.display = 'block';
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnText;
        }
    } catch (error) {
        errorDiv.textContent = 'Network error. Please check your connection and try again.';
        errorDiv.style.display = 'block';
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalBtnText;
    }
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>



