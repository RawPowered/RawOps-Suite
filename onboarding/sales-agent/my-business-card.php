<?php
/**
 * Sales Agent Business Card Self-Service
 * Agents can view and edit their own business card
 */

require_once __DIR__ . '/../../required/config.php';
require_once __DIR__ . '/../../required/business-card-functions.php';

start_session();
$pdo = db();

// Auth check - must be sales agent
$uid = $_SESSION['uid'] ?? 0;
if (!$uid) {
    header('Location: ' . path_with_base('/login.php'));
    exit;
}

$role_stmt = $pdo->prepare('SELECT role_id FROM user_roles WHERE user_id = ?');
$role_stmt->execute([$uid]);
$roles = $role_stmt->fetchAll(PDO::FETCH_COLUMN);

if (!in_array(2, $roles)) { // Sales Agent role
    header('Location: ' . path_with_base('/'));
    exit;
}

// Get or create business card for this agent
$card = get_agent_business_card($uid);
$social_links = $card ? get_card_social_links($card['id']) : [];

// Get user info
$user_stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$user_stmt->execute([$uid]);
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

$page_title = 'My Business Card';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - Product Sales Agent</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo asset_url('/assets/sacp-base.css'); ?>">
    <style>
        .sacp_page-header {
            background: linear-gradient(135deg, #0369a1, #075985);
            color: white;
            padding: 30px;
            border-radius: 16px;
            margin-bottom: 30px;
        }
        
        .sacp_page-header h1 {
            font-size: 28px;
            margin-bottom: 8px;
        }
        
        .sacp_editor-grid {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 24px;
            margin-bottom: 30px;
        }
        
        .sacp_card-section {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 24px;
        }
        
        .sacp_card-section h3 {
            font-size: 18px;
            color: #0369a1;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .sacp_form-group {
            margin-bottom: 20px;
        }
        
        .sacp_form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .sacp_form-group input,
        .sacp_form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .sacp_form-group input:focus,
        .sacp_form-group textarea:focus {
            outline: none;
            border-color: #0369a1;
        }
        
        .sacp_form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .sacp_photo-upload {
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 24px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .sacp_photo-upload:hover {
            border-color: #0369a1;
            background: #f8fafc;
        }
        
        .sacp_photo-preview {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            overflow: hidden;
            margin: 0 auto 16px;
            background: #e5e7eb;
            border: 4px solid #f1f5f9;
        }
        
        .sacp_photo-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .sacp_social-links-list {
            margin-top: 16px;
        }
        
        .sacp_social-link-item {
            display: grid;
            grid-template-columns: 140px 1fr auto;
            gap: 12px;
            align-items: center;
            padding: 12px;
            background: #f8fafc;
            border-radius: 8px;
            margin-bottom: 12px;
        }
        
        .sacp_qr-display {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            text-align: center;
            margin-bottom: 24px;
        }
        
        .sacp_qr-display img {
            max-width: 300px;
            border: 4px solid #f1f5f9;
            border-radius: 8px;
        }
        
        .sacp_share-section {
            background: #f8fafc;
            padding: 20px;
            border-radius: 12px;
            margin-top: 20px;
        }
        
        .sacp_share-link {
            background: white;
            padding: 12px;
            border-radius: 8px;
            border: 2px solid #e5e7eb;
            font-family: monospace;
            font-size: 13px;
            word-break: break-all;
            margin-bottom: 12px;
        }
        
        @media (max-width: 968px) {
            .sacp_editor-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="sacp_body">
    <div class="sacp_container">
        <div class="sacp_page-header">
            <h1><i class="fas fa-address-card"></i> <?php echo htmlspecialchars($page_title); ?></h1>
            <p>Your digital business card for professional networking</p>
        </div>
        
        <?php if ($card): ?>
        <div class="sacp_share-section">
            <h3 style="margin-bottom: 12px;"><i class="fas fa-share-alt"></i> Share Your Card</h3>
            <div class="sacp_share-link" id="cardUrl">
                https://product.example/agent/<?php echo htmlspecialchars($card['slug']); ?>/card
            </div>
            <button class="sacp_btn sacp_btn-primary" onclick="copyCardUrl()">
                <i class="fas fa-copy"></i> Copy Link
            </button>
            <a href="/agent/<?php echo urlencode($card['slug']); ?>/card" target="_blank" class="sacp_btn sacp_btn-secondary">
                <i class="fas fa-eye"></i> View My Card
            </a>
        </div>
        <?php endif; ?>
        
        <div class="sacp_editor-grid">
            <div class="sacp_editor-form">
                <form id="cardForm" enctype="multipart/form-data">
                    <input type="hidden" name="agent_id" value="<?php echo $uid; ?>">
                    
                    <div class="sacp_card-section">
                        <h3><i class="fas fa-camera"></i> Profile Photo</h3>
                        <div class="sacp_photo-upload" onclick="document.getElementById('photoInput').click()">
                            <div class="sacp_photo-preview">
                                <?php if ($card && !empty($card['profile_photo_path'])): ?>
                                    <img src="<?php echo htmlspecialchars($card['profile_photo_path']); ?>" id="photoPreview">
                                <?php else: ?>
                                    <img src="" id="photoPreview" style="display:none;">
                                <?php endif; ?>
                            </div>
                            <p><strong>Click to upload or change photo</strong></p>
                            <p style="font-size: 13px; color: #64748b;">JPG, PNG, or WebP (max 5MB)</p>
                        </div>
                        <input type="file" id="photoInput" name="photo" accept="image/jpeg,image/png,image/webp" style="display:none;">
                    </div>
                    
                    <div class="sacp_card-section">
                        <h3><i class="fas fa-info-circle"></i> Card Information</h3>
                        <div class="sacp_form-group">
                            <label>Job Title</label>
                            <input type="text" name="job_title" value="<?php echo htmlspecialchars($card['job_title'] ?? 'Sales Agent'); ?>" required>
                        </div>
                        <div class="sacp_form-group">
                            <label>Bio / Tagline</label>
                            <textarea name="bio" placeholder="Tell customers about yourself and what makes you great at what you do..."><?php echo htmlspecialchars($card['bio'] ?? ''); ?></textarea>
                            <small style="color: #64748b;">Share your experience, approach, or what you love about product</small>
                        </div>
                        <div class="sacp_form-group">
                            <label>Custom Message (optional)</label>
                            <textarea name="custom_message" placeholder="A special message for your customers..."><?php echo htmlspecialchars($card['custom_message'] ?? ''); ?></textarea>
                            <small style="color: #64748b;">Optional personal touch for your customers</small>
                        </div>
                        <div class="sacp_form-group">
                            <label>Theme Color</label>
                            <input type="color" name="theme_color" value="<?php echo htmlspecialchars($card['theme_color'] ?? '#0369a1'); ?>">
                        </div>
                        <div class="sacp_form-group">
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" name="show_email" <?php echo empty($card) || $card['show_email'] ? 'checked' : ''; ?>>
                                Show my email address (<?php echo htmlspecialchars($user['email']); ?>)
                            </label>
                        </div>
                        <div class="sacp_form-group">
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" name="show_phone" <?php echo empty($card) || $card['show_phone'] ? 'checked' : ''; ?>>
                                Show my phone number (<?php echo htmlspecialchars($user['phone'] ?? 'Not set'); ?>)
                            </label>
                        </div>
                    </div>
                    
                    <div class="sacp_card-section">
                        <h3><i class="fas fa-share-nodes"></i> Social Links</h3>
                        <p style="color: #64748b; margin-bottom: 16px; font-size: 14px;">
                            Add your social media profiles so customers can connect with you
                        </p>
                        <div class="sacp_social-links-list" id="socialLinksContainer">
                            <?php foreach ($social_links as $link): ?>
                                <div class="sacp_social-link-item" data-id="<?php echo $link['id']; ?>">
                                    <select class="platform-select">
                                        <option value="facebook" <?php echo $link['platform'] === 'facebook' ? 'selected' : ''; ?>>Facebook</option>
                                        <option value="instagram" <?php echo $link['platform'] === 'instagram' ? 'selected' : ''; ?>>Instagram</option>
                                        <option value="linkedin" <?php echo $link['platform'] === 'linkedin' ? 'selected' : ''; ?>>LinkedIn</option>
                                        <option value="x" <?php echo $link['platform'] === 'x' ? 'selected' : ''; ?>>X (Twitter)</option>
                                        <option value="tiktok" <?php echo $link['platform'] === 'tiktok' ? 'selected' : ''; ?>>TikTok</option>
                                        <option value="youtube" <?php echo $link['platform'] === 'youtube' ? 'selected' : ''; ?>>YouTube</option>
                                        <option value="website" <?php echo $link['platform'] === 'website' ? 'selected' : ''; ?>>Website</option>
                                        <option value="custom" <?php echo $link['platform'] === 'custom' ? 'selected' : ''; ?>>Custom</option>
                                    </select>
                                    <input type="url" class="url-input" placeholder="https://..." value="<?php echo htmlspecialchars($link['url']); ?>">
                                    <button type="button" class="sacp_btn sacp_btn-danger" style="padding: 8px 12px;" onclick="removeSocialLink(this, <?php echo $link['id']; ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="sacp_btn sacp_btn-secondary" onclick="addSocialLink()">
                            <i class="fas fa-plus"></i> Add Social Link
                        </button>
                    </div>
                    
                    <div class="sacp_card-section">
                        <button type="submit" class="sacp_btn sacp_btn-primary" style="width: 100%; font-size: 16px;">
                            <i class="fas fa-save"></i> Save My Business Card
                        </button>
                    </div>
                </form>
            </div>
            
            <div class="sacp_sidebar">
                <?php if ($card): ?>
                <div class="sacp_qr-display">
                    <h3 style="margin-bottom: 16px;"><i class="fas fa-qrcode"></i> Your QR Code</h3>
                    <img src="/api/business-card-qr-generate.php?agent_id=<?php echo $uid; ?>&return=image" alt="QR Code">
                    <p style="margin-top: 16px; color: #64748b; font-size: 14px;">
                        Download and print this QR code to share your digital business card
                    </p>
                    <a href="/api/business-card-qr-generate.php?agent_id=<?php echo $uid; ?>&return=image" download class="sacp_btn sacp_btn-primary" style="margin-top: 12px;">
                        <i class="fas fa-download"></i> Download QR Code
                    </a>
                </div>
                
                <div class="sacp_card-section">
                    <h3><i class="fas fa-chart-line"></i> Card Statistics</h3>
                    <div style="display: grid; gap: 12px; margin-top: 16px;">
                        <div style="padding: 12px; background: #f8fafc; border-radius: 8px;">
                            <div style="font-size: 13px; color: #64748b;">Total Views</div>
                            <div style="font-size: 24px; font-weight: 700; color: #0369a1;">
                                <?php echo number_format($card['scan_count'] ?? 0); ?>
                            </div>
                        </div>
                        <div style="padding: 12px; background: #f8fafc; border-radius: 8px;">
                            <div style="font-size: 13px; color: #64748b;">vCard Downloads</div>
                            <div style="font-size: 24px; font-weight: 700; color: #059669;">
                                <?php echo number_format($card['vcard_download_count'] ?? 0); ?>
                            </div>
                        </div>
                        <div style="padding: 12px; background: #f8fafc; border-radius: 8px;">
                            <div style="font-size: 13px; color: #64748b;">Customer Signups</div>
                            <div style="font-size: 24px; font-weight: 700; color: #7c3aed;">
                                <?php echo number_format($card['signup_conversion_count'] ?? 0); ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        // Photo preview
        document.getElementById('photoInput').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('photoPreview').src = e.target.result;
                    document.getElementById('photoPreview').style.display = 'block';
                };
                reader.readAsDataURL(file);
            }
        });
        
        // Social links management
        function addSocialLink() {
            const container = document.getElementById('socialLinksContainer');
            const item = document.createElement('div');
            item.className = 'sacp_social-link-item';
            item.innerHTML = `
                <select class="platform-select">
                    <option value="facebook">Facebook</option>
                    <option value="instagram">Instagram</option>
                    <option value="linkedin">LinkedIn</option>
                    <option value="x">X (Twitter)</option>
                    <option value="tiktok">TikTok</option>
                    <option value="youtube">YouTube</option>
                    <option value="website">Website</option>
                    <option value="custom">Custom</option>
                </select>
                <input type="url" class="url-input" placeholder="https://...">
                <button type="button" class="sacp_btn sacp_btn-danger" style="padding: 8px 12px;" onclick="this.parentElement.remove()">
                    <i class="fas fa-trash"></i>
                </button>
            `;
            container.appendChild(item);
        }
        
        function removeSocialLink(btn, linkId) {
            if (linkId && !confirm('Delete this social link?')) return;
            
            if (linkId) {
                fetch('/api/business-card-api.php?action=delete_social_link', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'link_id=' + linkId
                });
            }
            
            btn.parentElement.remove();
        }
        
        // Copy card URL
        function copyCardUrl() {
            const url = document.getElementById('cardUrl').textContent;
            navigator.clipboard.writeText(url).then(() => {
                alert('Card URL copied to clipboard!');
            });
        }
        
        // Form submission
        document.getElementById('cardForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const agentId = formData.get('agent_id');
            
            // Upload photo first if changed
            const photoFile = formData.get('photo');
            if (photoFile && photoFile.size > 0) {
                const photoData = new FormData();
                photoData.append('photo', photoFile);
                photoData.append('agent_id', agentId);
                photoData.append('action', 'upload_photo');
                
                const photoResp = await fetch('/api/business-card-api.php', {
                    method: 'POST',
                    body: photoData
                });
                const photoResult = await photoResp.json();
                
                if (!photoResult.success) {
                    alert('Photo upload failed: ' + photoResult.error);
                    return;
                }
                
                formData.set('profile_photo_path', photoResult.path);
                formData.set('profile_photo_updated', '1');
            }
            
            // Save card data
            const cardData = new FormData();
            cardData.append('action', 'save');
            cardData.append('agent_id', agentId);
            cardData.append('job_title', formData.get('job_title'));
            cardData.append('bio', formData.get('bio'));
            cardData.append('custom_message', formData.get('custom_message'));
            cardData.append('theme_color', formData.get('theme_color'));
            if (formData.get('show_email')) cardData.append('show_email', '1');
            if (formData.get('show_phone')) cardData.append('show_phone', '1');
            if (formData.get('profile_photo_path')) cardData.append('profile_photo_path', formData.get('profile_photo_path'));
            if (formData.get('profile_photo_updated')) cardData.append('profile_photo_updated', '1');
            
            const cardResp = await fetch('/api/business-card-api.php', {
                method: 'POST',
                body: cardData
            });
            const cardResult = await cardResp.json();
            
            if (!cardResult.success) {
                alert('Error saving card: ' + cardResult.error);
                return;
            }
            
            const savedCardId = cardResult.card_id;
            
            // Save social links
            const socialItems = document.querySelectorAll('.sacp_social-link-item');
            for (const item of socialItems) {
                const platform = item.querySelector('.platform-select').value;
                const url = item.querySelector('.url-input').value;
                const linkId = item.dataset.id;
                
                if (!url) continue;
                
                const linkData = new FormData();
                linkData.append('card_id', savedCardId);
                linkData.append('platform', platform);
                linkData.append('url', url);
                linkData.append('icon_class', ''); // Will use default
                
                if (linkId) {
                    linkData.append('action', 'update_social_link');
                    linkData.append('link_id', linkId);
                } else {
                    linkData.append('action', 'add_social_link');
                }
                
                await fetch('/api/business-card-api.php', {
                    method: 'POST',
                    body: linkData
                });
            }
            
            alert('Business card saved successfully!');
            location.reload();
        });
    </script>
</body>
</html>

