<?php
/**
 * Public Business Card Display
 * Shows sales agent business card with contact info, social links, and CTAs
 */

require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/business-card-functions.php';

start_session();

// Get slug from query string (via .htaccess rewrite)
$slug = $_GET['slug'] ?? '';

if (empty($slug)) {
    http_response_code(404);
    echo "Business card not found";
    exit;
}

// Get business card
$card = get_business_card_by_slug($slug);

if (!$card) {
    http_response_code(404);
    echo "Business card not found";
    exit;
}

// Track page view
track_card_view($card['id'], 'view');

// Get social links
$social_links = get_card_social_links($card['id']);

// Build page title and meta
$page_title = $card['first_name'] . ' ' . $card['last_name'] . ' - Product Sales Agent';
$theme_color = $card['theme_color'] ?? '#0369a1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <meta name="description" content="Connect with <?php echo htmlspecialchars($card['first_name'] . ' ' . $card['last_name']); ?>, your Product sales representative. Get premium Products and personalized service.">
    <meta name="robots" content="index, follow">
    
    <!-- Open Graph -->
    <meta property="og:title" content="<?php echo htmlspecialchars($page_title); ?>">
    <meta property="og:description" content="Your Product sales representative">
    <meta property="og:type" content="profile">
    <?php if ($card['profile_photo_path']): ?>
    <meta property="og:image" content="https://product.example<?php echo htmlspecialchars($card['profile_photo_path']); ?>">
    <?php endif; ?>
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="/assets/favicon.ico">
    
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --primary-color: <?php echo htmlspecialchars($theme_color); ?>;
            --primary-dark: #065f8c;
            --background: #f8fafc;
            --card-bg: #ffffff;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border: #e2e8f0;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--background);
            color: var(--text-primary);
            line-height: 1.6;
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 600px;
            margin: 0 auto;
        }
        
        .business-card {
            background: var(--card-bg);
            border-radius: 24px;
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            padding: 40px 30px 30px;
            text-align: center;
            color: white;
            position: relative;
        }
        
        .logo {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 20px;
            letter-spacing: 0.5px;
        }
        
        .avatar-container {
            width: 140px;
            height: 140px;
            margin: 0 auto 20px;
            border-radius: 50%;
            border: 5px solid rgba(255, 255, 255, 0.3);
            overflow: hidden;
            background: rgba(255, 255, 255, 0.1);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.2);
        }
        
        .avatar-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .avatar-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }
        
        .agent-name {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .agent-title {
            font-size: 16px;
            opacity: 0.9;
            font-weight: 500;
        }
        
        .card-body {
            padding: 30px;
        }
        
        .bio {
            text-align: center;
            color: var(--text-secondary);
            margin-bottom: 30px;
            line-height: 1.7;
            font-size: 15px;
        }
        
        .custom-message {
            background: #f1f5f9;
            border-left: 4px solid var(--primary-color);
            padding: 16px;
            margin-bottom: 30px;
            border-radius: 8px;
            font-size: 14px;
            color: var(--text-secondary);
        }
        
        .contact-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 30px;
        }
        
        .contact-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 14px 20px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            border: 2px solid var(--border);
            background: white;
            color: var(--text-primary);
        }
        
        .contact-btn:hover {
            border-color: var(--primary-color);
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }
        
        .contact-btn i {
            font-size: 18px;
        }
        
        .social-links {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
            gap: 12px;
            margin-bottom: 30px;
        }
        
        .social-link {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 16px;
            border-radius: 12px;
            text-decoration: none;
            color: var(--text-primary);
            background: var(--background);
            transition: all 0.3s ease;
            min-height: 90px;
        }
        
        .social-link:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }
        
        .social-link i {
            font-size: 24px;
        }
        
        .social-link span {
            font-size: 12px;
            font-weight: 500;
            text-align: center;
        }
        
        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .action-btn {
            display: block;
            padding: 16px 24px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            font-size: 15px;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .action-btn-primary {
            background: var(--primary-color);
            color: white;
            box-shadow: var(--shadow);
        }
        
        .action-btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        
        .action-btn-secondary {
            background: white;
            color: var(--primary-color);
            border: 2px solid var(--primary-color);
        }
        
        .action-btn-secondary:hover {
            background: var(--primary-color);
            color: white;
        }
        
        .vcard-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 20px;
        }
        
        .vcard-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 16px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            background: #f1f5f9;
            color: var(--text-primary);
            transition: all 0.3s ease;
        }
        
        .vcard-btn:hover {
            background: var(--text-primary);
            color: white;
        }
        
        .divider {
            height: 1px;
            background: var(--border);
            margin: 30px 0;
        }
        
        .section-title {
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
            margin-bottom: 16px;
            text-align: center;
        }
        
        .footer {
            text-align: center;
            padding: 20px;
            color: var(--text-secondary);
            font-size: 14px;
        }
        
        @media (max-width: 480px) {
            body {
                padding: 10px;
            }
            
            .card-header {
                padding: 30px 20px 20px;
            }
            
            .card-body {
                padding: 20px;
            }
            
            .agent-name {
                font-size: 24px;
            }
            
            .contact-buttons {
                grid-template-columns: 1fr;
            }
            
            .social-links {
                grid-template-columns: repeat(auto-fill, minmax(70px, 1fr));
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="business-card">
            <div class="card-header">
                <div class="logo">PRODUCT™</div>
                
                <div class="avatar-container">
                    <?php if (!empty($card['profile_photo_path'])): ?>
                        <img src="<?php echo htmlspecialchars($card['profile_photo_path']); ?>" alt="<?php echo htmlspecialchars($card['first_name'] . ' ' . $card['last_name']); ?>">
                    <?php else: ?>
                        <div class="avatar-placeholder">
                            <?php echo strtoupper(substr($card['first_name'], 0, 1) . substr($card['last_name'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="agent-name">
                    <?php echo htmlspecialchars($card['first_name'] . ' ' . $card['last_name']); ?>
                </div>
                <div class="agent-title">
                    <?php echo htmlspecialchars($card['job_title'] ?? 'Sales Agent'); ?>
                </div>
            </div>
            
            <div class="card-body">
                <?php if (!empty($card['bio'])): ?>
                <div class="bio">
                    <?php echo nl2br(htmlspecialchars($card['bio'])); ?>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($card['custom_message'])): ?>
                <div class="custom-message">
                    <?php echo nl2br(htmlspecialchars($card['custom_message'])); ?>
                </div>
                <?php endif; ?>
                
                <div class="section-title">Contact Me</div>
                <div class="contact-buttons">
                    <?php if ($card['show_phone'] && !empty($card['phone'])): ?>
                        <a href="tel:<?php echo htmlspecialchars($card['phone']); ?>" class="contact-btn">
                            <i class="fas fa-phone"></i>
                            <span>Call</span>
                        </a>
                        <a href="sms:<?php echo htmlspecialchars($card['phone']); ?>" class="contact-btn">
                            <i class="fas fa-sms"></i>
                            <span>Text</span>
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($card['show_email'] && !empty($card['email'])): ?>
                        <a href="mailto:<?php echo htmlspecialchars($card['email']); ?>" class="contact-btn">
                            <i class="fas fa-envelope"></i>
                            <span>Email</span>
                        </a>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($social_links)): ?>
                <div class="section-title">Connect With Me</div>
                <div class="social-links">
                    <?php foreach ($social_links as $link): ?>
                        <a href="<?php echo htmlspecialchars($link['url']); ?>" target="_blank" rel="noopener noreferrer" class="social-link">
                            <i class="<?php echo htmlspecialchars($link['icon_class'] ?: get_platform_icon($link['platform'])); ?>"></i>
                            <span><?php echo htmlspecialchars($link['display_label'] ?: get_platform_name($link['platform'])); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <div class="divider"></div>
                
                <div class="vcard-buttons">
                    <a href="/api/business-card-vcard.php?slug=<?php echo urlencode($card['slug']); ?>" class="vcard-btn">
                        <i class="fas fa-address-card"></i>
                        <span>Add to Contacts</span>
                    </a>
                    <a href="/api/business-card-vcard.php?slug=<?php echo urlencode($card['slug']); ?>" download class="vcard-btn">
                        <i class="fas fa-download"></i>
                        <span>Download vCard</span>
                    </a>
                </div>
                
                <div class="action-buttons">
                    <a href="/index.php?agent=<?php echo urlencode($card['slug']); ?>" 
                       class="action-btn action-btn-primary"
                       onclick="fetch('/api/business-card-api.php?action=track_signup&card_id=<?php echo $card['id']; ?>');">
                        <i class="fas fa-user-plus"></i> Become My Customer
                    </a>
                    <a href="/promo/product-sample/index.php?agent=<?php echo urlencode($card['slug']); ?>" 
                       class="action-btn action-btn-secondary"
                       onclick="fetch('/api/business-card-api.php?action=track_sample&card_id=<?php echo $card['id']; ?>');">
                        <i class="fas fa-gift"></i> Request Free Sample
                    </a>
                </div>
            </div>
        </div>
        
        <div class="footer">
            <strong>Product™ Product</strong><br>
            Premium Quality • Professional Service
        </div>
    </div>
    
    <script>
        // Track button clicks
        document.querySelectorAll('a[href*="index.php?agent"]').forEach(btn => {
            btn.addEventListener('click', function() {
                const cardId = <?php echo intval($card['id']); ?>;
                fetch('/api/business-card-api.php?action=track_action', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'card_id=' + cardId + '&action_type=signup_click'
                });
            });
        });
        
        document.querySelectorAll('a[href*="product-sample"]').forEach(btn => {
            btn.addEventListener('click', function() {
                const cardId = <?php echo intval($card['id']); ?>;
                fetch('/api/business-card-api.php?action=track_action', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'card_id=' + cardId + '&action_type=sample_click'
                });
            });
        });
    </script>
</body>
</html>

