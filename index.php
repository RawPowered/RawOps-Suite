<?php
// Load config FIRST so helpers like site_origin()/outbound_path() exist
require_once __DIR__ . '/required/config.php';

$SEO = [
  'title' => 'RawOps Suite | Commerce and Team Workflows',
  'description' => 'A connected operations system for product catalog management, sales workflows, customer requests, training, finance, and automation.',
  'type' => 'website',
  'image' => outbound_path('/assets/logo.svg'),
  'schema' => [
    '@context' => 'https://schema.org',
    '@type' => 'WebApplication',
    'name' => 'RawOps Suite',
    'applicationCategory' => 'BusinessApplication',
    'operatingSystem' => 'Web',
    'description' => 'A modular demonstration of connected product and team operations.'
  ]
];// If a user is logged in, keep them in their role home instead of the marketing page
if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($_GET['preview'])) {
    $uid = current_user_id();
    if ($uid) { redirect(user_dashboard_path($uid)); }
}

// If agent parameter is present, auto-redirect to wholesale signup with agent attribution
if (!empty($_GET['agent']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $agentSlug = $_GET['agent'];
    // Store agent slug in session for attribution
    start_session();
    $_SESSION['referral_agent_slug'] = $agentSlug;
    
    // Redirect to index with role=wholesale pre-selected
    if (empty($_GET['role'])) {
        header('Location: ' . outbound_path('/index.php?role=wholesale'));
        exit;
    }
}
require_once __DIR__ . '/required/header.php';

// Role simulation functionality (always available for admins)
start_session();

// Role simulation actions (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['simulate_role','simulate_user','end_simulation'], true)) {
    $allowed = current_admin_id();
    if ($allowed) {
        $pdo = db();
        $action = $_POST['action'];
        if ($action === 'end_simulation') {
            unset($_SESSION['uid'], $_SESSION['admin_uid'], $_SESSION['admin_impersonation'], $_SESSION['admin_simulation']);
            flash_set('success', 'Simulation ended. You are now a guest.');
        } elseif ($action === 'simulate_role') {
            $role = trim($_POST['role'] ?? '');
            $userId = null;
            try {
                if ($role === 'admin') {
                    $stmt = $pdo->query("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.name='admin' ORDER BY u.id LIMIT 1");
                    $userId = (int)($stmt->fetchColumn() ?: 0);
                } elseif ($role === 'sales_agent') {
                    $stmt = $pdo->query("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.name='sales_agent' ORDER BY u.id LIMIT 1");
                    $userId = (int)($stmt->fetchColumn() ?: 0);
                } elseif ($role === 'operations') {
                    $stmt = $pdo->query("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.name='operations' ORDER BY u.id LIMIT 1");
                    $userId = (int)($stmt->fetchColumn() ?: 0);
                } elseif ($role === 'customer') {
                    $stmt = $pdo->query("SELECT u.id FROM users u JOIN wholesale_accounts wa ON wa.user_id=u.id AND wa.approved=1 ORDER BY u.id LIMIT 1");
                    $userId = (int)($stmt->fetchColumn() ?: 0);
                } elseif ($role === 'guest') {
                    unset($_SESSION['uid']);
                    flash_set('success', 'Simulating Guest.');
                }
                if ($userId) {
                    $_SESSION['uid'] = $userId;
                    flash_set('success', ucfirst(str_replace('_',' ',$role)) . ' simulation active.');
                }
            } catch (Throwable $e) { flash_set('error', 'Simulation error'); }
        } elseif ($action === 'simulate_user') {
            $email = trim($_POST['email'] ?? '');
            if ($email !== '') {
                $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
                $stmt->execute([$email]);
                $uid = (int)($stmt->fetchColumn() ?: 0);
                if ($uid) { $_SESSION['uid'] = $uid; flash_set('success', 'Now simulating ' . htmlspecialchars($email)); }
                else { flash_set('error', 'User not found'); }
            }
        }
    } else {
        flash_set('error', 'Access denied');
    }
    header('Location: ' . path_with_base('/index.php'));
    exit;
}
?>

<section class="auth-hero parallax-container" aria-labelledby="hero-title" style="background:linear-gradient(135deg,#f8fafc,#ffffff);padding:80px 0;position:relative;overflow:hidden;">
    <!-- HD Image Backdrop with Parallax & Blur -->
    <div class="hero-image-backdrop" data-parallax-speed="0.25" data-blur-on-scroll="true" style="position:absolute;top:-10%;left:0;right:0;bottom:-10%;z-index:0;overflow:hidden;transform:translateZ(0);">
        <div style="position:absolute;inset:0;background:url('<?php echo outbound_path('/assets/img/product-placeholder.svg'); ?>') center center / cover no-repeat;filter:blur(0px);transition:filter 0.3s ease;will-change:filter;transform:scale(1.1) translateZ(0);"></div>
        <div style="position:absolute;inset:0;background:linear-gradient(135deg, rgba(248,250,252,0.92) 0%, rgba(255,255,255,0.88) 100%);backdrop-filter:blur(2px);"></div>
    </div>
    
    <!-- Multi-layer Parallax Backgrounds -->
    <div class="parallax-bg-layer-1" data-parallax-speed="0.15" style="position:absolute;top:0;left:0;right:0;bottom:0;background:radial-gradient(1200px 600px at 65% 20%, rgba(37,99,235,0.04) 0%, transparent 65%);pointer-events:none;z-index:1;transform:translateZ(0);"></div>
    <div class="parallax-bg-layer-2" data-parallax-speed="0.25" style="position:absolute;top:0;left:0;right:0;bottom:0;background:radial-gradient(900px 450px at 35% 80%, rgba(212,175,55,0.03) 0%, transparent 65%);pointer-events:none;z-index:1;transform:translateZ(0);"></div>
    <div class="parallax-particles" data-parallax-speed="0.08" style="position:absolute;top:0;left:0;right:0;bottom:0;pointer-events:none;z-index:1;opacity:0.2;background-image:radial-gradient(circle, rgba(37,99,235,0.1) 1px, transparent 1px);background-size:60px 60px;background-position:0 0;transform:translateZ(0);"></div>
    
    <div class="container" style="position:relative;z-index:1;">
        <?php if (!empty($_GET['err'])): ?>
            <div class="card" role="alert" aria-live="polite" style="border-color:#ffb3b3;color:#8a1f1f;background:#fff5f5;margin-bottom:16px">
                <?php if ($_GET['err']==='dup'): ?>
                    That email already exists. Please log in or use a different email.
                <?php else: ?>
                    We couldn't process your request. Please try again.
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- supplier documentation Badge -->
        <div class="fade-in-scroll" style="text-align:center;margin-bottom:32px;transform:translateY(20px);">
            <div style="display:inline-flex;align-items:center;gap:12px;padding:12px 28px;background:rgba(255,255,255,0.95);border:2px solid rgba(212,175,55,0.3);border-radius:50px;box-shadow:0 8px 24px rgba(0,0,0,0.08);backdrop-filter:blur(10px);">
                <i class="fas fa-certificate" style="color:#d4af37;font-size:20px;"></i>
                <span style="font-weight:700;font-size:13px;color:#0c4a6e;letter-spacing:0.5px;">product Manufacturer Licensed by <strong style="color:#d4af37;">supplier documentation, supplier region</strong></span>
            </div>
        </div>

        <div class="hero-grid" style="display:grid; grid-template-columns:1.2fr 0.8fr; gap:64px; align-items:center;">
            <!-- Left: Product Showcase -->
            <div class="hero-copy fade-in-scroll" style="transform:translateY(20px);">
                <div style="display:inline-block;padding:8px 20px;background:rgba(14,165,233,0.1);border:1px solid rgba(14,165,233,0.2);border-radius:50px;margin-bottom:16px;">
                    <span style="color:#0284c7;font-weight:700;font-size:13px;letter-spacing:1px;text-transform:uppercase;">catalog-ready</span>
                </div>
                <h1 id="hero-title" style="font-size:52px; line-height:1.05; margin:0 0 20px; color:#0c4a6e; font-weight:900;">
                    Product™ Premium Product
                </h1>
                <h2 style="font-size:28px; line-height:1.2; margin:0 0 28px; color:#0369a1; font-weight:700;">
                    catalog-defined product & Raw catalog-ready product format
                </h2>
                <p style="font-size:18px; line-height:1.7; margin:0 0 32px; max-width:600px; color:#1e293b;">
                    The world's cleanest Products. Crystal-clear liquid product with workflow-supported 4-pass filtration and ultra-refined raw product format from supplier region's supplier documentation licensed product manufacturer. product details configured by the operator, product details configured by the operator, 100% natural quality information.
                </p>
                
                <!-- Product Showcase with Containers -->
                <div class="fade-in-scroll" style="margin-bottom:32px;transform:translateY(20px);">
                    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:20px;">
                        <!-- Product A product -->
                        <a href="#blue-20-details" class="product-showcase-card" data-scroll-zoom="true" style="background:#fff;padding:24px;border-radius:20px;border:2px solid #bae6fd;box-shadow:0 8px 24px rgba(14,165,233,0.15);text-align:center;position:relative;overflow:hidden;transform:scale(1);opacity:1;transition:all 0.4s ease;text-decoration:none;cursor:pointer;display:block;">
                            <div style="position:absolute;top:-50%;left:-50%;width:200%;height:200%;background:radial-gradient(circle, rgba(14,165,233,0.08) 0%, transparent 70%);pointer-events:none;"></div>
                            <div style="position:relative;z-index:1;">
                                <div style="height:200px;display:flex;align-items:center;justify-content:center;margin-bottom:16px;">
                                    <img src="<?php echo outbound_path('/assets/img/product-placeholder.svg'); ?>" alt="Product A product" loading="eager" style="max-width:100%;max-height:100%;object-fit:contain;filter:drop-shadow(0 8px 16px rgba(14,165,233,0.3));">
                                </div>
                                <h3 style="margin:0 0 6px;font-size:18px;font-weight:800;color:#0284c7;">Product A</h3>
                                <p style="margin:0 0 4px;font-size:14px;font-weight:600;color:#334155;">20mg attribute/fl oz</p>
                                <p style="margin:0;font-size:12px;color:#64748b;">Precision product</p>
                            </div>
                        </a>
                        
                        <!-- Product B product -->
                        <a href="#gold-40-details" class="product-showcase-card" data-scroll-zoom="true" style="background:#fff;padding:24px;border-radius:20px;border:2px solid #fde68a;box-shadow:0 8px 24px rgba(245,158,11,0.15);text-align:center;position:relative;overflow:hidden;transform:scale(1);opacity:1;transition:all 0.4s ease;text-decoration:none;cursor:pointer;display:block;">
                            <div style="position:absolute;top:-50%;left:-50%;width:200%;height:200%;background:radial-gradient(circle, rgba(245,158,11,0.08) 0%, transparent 70%);pointer-events:none;"></div>
                            <div style="position:relative;z-index:1;">
                                <div style="height:200px;display:flex;align-items:center;justify-content:center;margin-bottom:16px;">
                                    <img src="<?php echo outbound_path('/assets/img/product-placeholder.svg'); ?>" alt="Product B product" loading="eager" style="max-width:100%;max-height:100%;object-fit:contain;filter:drop-shadow(0 8px 16px rgba(245,158,11,0.3));">
                                </div>
                                <h3 style="margin:0 0 6px;font-size:18px;font-weight:800;color:#d97706;">Product B</h3>
                                <p style="margin:0 0 4px;font-size:14px;font-weight:600;color:#334155;">40mg attribute/fl oz</p>
                                <p style="margin:0;font-size:12px;color:#64748b;">Maximum product specification</p>
                            </div>
                        </a>
                        
                        <!-- 1g product format Sample (Wholesale Only) -->
                        <a href="#product format-sample-details" class="product-showcase-card" data-scroll-zoom="true" style="background:#fff;padding:24px;border-radius:20px;border:2px solid #d4af37;box-shadow:0 8px 24px rgba(212,175,55,0.15);text-align:center;position:relative;overflow:hidden;transform:scale(1);opacity:1;transition:all 0.4s ease;text-decoration:none;cursor:pointer;display:block;">
                            <div style="position:absolute;top:-50%;left:-50%;width:200%;height:200%;background:radial-gradient(circle, rgba(212,175,55,0.08) 0%, transparent 70%);pointer-events:none;"></div>
                            <div style="position:relative;z-index:1;">
                                <div style="height:200px;display:flex;align-items:center;justify-content:center;margin-bottom:16px;">
                                    <img src="<?php echo outbound_path('/assets/img/product-placeholder.svg'); ?>" alt="1g Product format Sample" loading="eager" style="max-width:100%;max-height:100%;object-fit:contain;filter:drop-shadow(0 8px 16px rgba(212,175,55,0.3));">
                                </div>
                                <h3 style="margin:0 0 6px;font-size:18px;font-weight:800;color:#78350f;">product format 1g</h3>
                                <p style="margin:0 0 4px;font-size:14px;font-weight:600;color:#334155;">Ultra-Refined</p>
                                <p style="margin:0;font-size:12px;color:#64748b;">Wholesale Sample</p>
                            </div>
                        </a>
                        
                        <!-- 25g product format Jar -->
                        <a href="#product format-jar-details" class="product-showcase-card" data-scroll-zoom="true" style="background:#fff;padding:24px;border-radius:20px;border:2px solid #b8860b;box-shadow:0 8px 24px rgba(184,134,11,0.15);text-align:center;position:relative;overflow:hidden;transform:scale(1);opacity:1;transition:all 0.4s ease;text-decoration:none;cursor:pointer;display:block;">
                            <div style="position:absolute;top:-50%;left:-50%;width:200%;height:200%;background:radial-gradient(circle, rgba(184,134,11,0.08) 0%, transparent 70%);pointer-events:none;"></div>
                            <div style="position:relative;z-index:1;">
                                <div style="height:200px;display:flex;align-items:center;justify-content:center;margin-bottom:16px;">
                                    <img src="<?php echo outbound_path('/assets/img/product-placeholder.svg'); ?>" alt="25g Product format Jar" loading="eager" style="max-width:100%;max-height:100%;object-fit:contain;filter:drop-shadow(0 8px 16px rgba(184,134,11,0.3));">
                                </div>
                                <h3 style="margin:0 0 6px;font-size:18px;font-weight:800;color:#78350f;">product format 25g</h3>
                                <p style="margin:0 0 4px;font-size:14px;font-weight:600;color:#334155;">Ultra-Refined</p>
                                <p style="margin:0;font-size:12px;color:#64748b;">Standard Jar</p>
                            </div>
                        </a>
                    </div>
                </div>

                <!-- Product Options -->
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:32px;">
                    <div class="product-option-card" style="background:#fff;padding:20px;border-radius:16px;border:2px solid #bae6fd;box-shadow:0 4px 20px rgba(14,165,233,0.15);transition:all 0.3s ease;cursor:pointer;">
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                            <i class="fas fa-droplet" style="font-size:20px;color:#0284c7;"></i>
                            <h3 style="margin:0;font-size:18px;font-weight:800;color:#0c4a6e;">Product A</h3>
                        </div>
                        <p style="margin:0;font-size:14px;color:#334155;font-weight:600;">20mg attribute / fl oz</p>
                        <p style="margin:6px 0 0;font-size:13px;color:#64748b;">Precision-dosed product</p>
                    </div>
                    
                    <div class="product-option-card" style="background:#fff;padding:20px;border-radius:16px;border:2px solid #fde68a;box-shadow:0 4px 20px rgba(245,158,11,0.15);transition:all 0.3s ease;cursor:pointer;">
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                            <i class="fas fa-droplet" style="font-size:20px;color:#d97706;"></i>
                            <h3 style="margin:0;font-size:18px;font-weight:800;color:#78350f;">Product B</h3>
                        </div>
                        <p style="margin:0;font-size:14px;color:#334155;font-weight:600;">40mg attribute / fl oz</p>
                        <p style="margin:6px 0 0;font-size:13px;color:#64748b;">Maximum product specification product</p>
                    </div>
                    
                    <div class="product-option-card" style="background:#fff;padding:20px;border-radius:16px;border:2px solid #d4af37;box-shadow:0 4px 20px rgba(212,175,55,0.15);transition:all 0.3s ease;cursor:pointer;">
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                            <i class="fas fa-mortar-pestle" style="font-size:20px;color:#d4af37;"></i>
                            <h3 style="margin:0;font-size:18px;font-weight:800;color:#78350f;">Raw product format</h3>
                        </div>
                        <p style="margin:0;font-size:14px;color:#334155;font-weight:600;">Ultra-refined</p>
                        <p style="margin:6px 0 0;font-size:13px;color:#64748b;">catalog-ready</p>
                    </div>
                </div>

                <!-- compliance & Florida Compliance Badge -->
                <div style="background:linear-gradient(135deg,#ecfdf5,#ffffff);padding:20px;border-radius:12px;border:2px solid #a7f3d0;margin-bottom:20px;">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">
                        <i class="fas fa-certificate" style="color:#16a34a;font-size:24px;"></i>
                        <div>
                            <div style="font-size:14px;font-weight:800;color:#14532d;margin-bottom:2px;">manufacturer compliance details configured by the operator</div>
                            <div style="font-size:12px;color:#15803d;">Florida Statute 500.92 Compliant — Florida Product Consumer Protection Act</div>
                        </div>
                    </div>
                    <div style="font-size:13px;color:#166534;line-height:1.5;">
                        Manufactured in a fully registered and compliant food manufacturing facility meeting all compliance requirements and Florida state regulations.
                    </div>
                </div>

                <!-- quality information Guarantees -->
                <div style="background:linear-gradient(135deg,#ffffff,#f0f9ff);padding:28px;border-radius:16px;border:2px solid #e0f2fe;margin-bottom:24px;">
                    <h3 style="margin:0 0 20px;font-size:18px;font-weight:800;color:#0c4a6e;">
                        <i class="fas fa-shield-check" style="color:#0ea5e9;margin-right:8px;"></i>
                        Product™ catalog-defined Guarantees
                    </h3>
                    
                    <!-- What You GET (Benefits First) -->
                    <div class="quality information-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:14px;color:#334155;margin-bottom:20px;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <i class="fas fa-check-circle" style="color:#22c55e;font-size:16px;flex-shrink:0;"></i>
                            <span><strong>High product specification</strong> (Rapid Absorption)</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <i class="fas fa-check-circle" style="color:#22c55e;font-size:16px;flex-shrink:0;"></i>
                            <span><strong>100%</strong> operator-configured & Organic</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <i class="fas fa-check-circle" style="color:#22c55e;font-size:16px;flex-shrink:0;"></i>
                            <span>QR Code Authentication</span>
                        </div>
                    </div>
                    
                    <!-- Divider -->
                    <div style="border-top:1px solid #e0f2fe;margin:16px 0;"></div>
                    
                    <!-- What You DON'T Get (quality information Claims) -->
                    <div style="margin-bottom:8px;font-size:13px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">What's NOT in our product:</div>
                    <div class="quality information-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:14px;color:#334155;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <i class="fas fa-times-circle" style="color:#ef4444;font-size:16px;flex-shrink:0;"></i>
                            <span><strong>Zero</strong> Toxins</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <i class="fas fa-times-circle" style="color:#ef4444;font-size:16px;flex-shrink:0;"></i>
                            <span><strong>Zero</strong> product attributes</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <i class="fas fa-times-circle" style="color:#ef4444;font-size:16px;flex-shrink:0;"></i>
                            <span><strong>Zero</strong> Microbial Bacteria</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <i class="fas fa-times-circle" style="color:#ef4444;font-size:16px;flex-shrink:0;"></i>
                            <span><strong>Zero</strong> Contaminants</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <i class="fas fa-times-circle" style="color:#ef4444;font-size:16px;flex-shrink:0;"></i>
                            <span><strong>Zero</strong> Chemicals</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <i class="fas fa-times-circle" style="color:#ef4444;font-size:16px;flex-shrink:0;"></i>
                            <span><strong>Zero</strong> product attribute</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: Role Selector -->
            <div class="hero-auth">
                <div class="card" style="background:#fff; border:3px solid rgba(15,23,42,0.1); border-radius:20px; padding:36px; box-shadow:0 24px 80px rgba(15,23,42,0.2);">
                    <div role="tablist" aria-label="Account access" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:28px;">
                        <button id="main-tab-login" role="tab" aria-selected="true" aria-controls="main-panel-login" class="btn" style="margin:0; padding:14px 8px; border-radius:10px; font-size:14px;font-weight:700;">
                            <i class="fas fa-sign-in-alt" style="margin-right:6px;"></i>Login
                        </button>
                        <button id="main-tab-register" role="tab" aria-selected="false" aria-controls="main-panel-register" class="btn secondary" style="margin:0; padding:14px 8px; border-radius:10px; font-size:14px;font-weight:700;">
                            <i class="fas fa-user-plus" style="margin-right:6px;"></i>Sign Up
                        </button>
                    </div>

                    <!-- Login Panel -->
                    <div id="main-panel-login" role="tabpanel" aria-labelledby="main-tab-login">
                        <h2 style="margin:0 0 8px; font-size:26px; color:var(--blue); text-align:center;font-weight:800;">Welcome Back</h2>
                        <p style="margin:0 0 24px; color:#64748b; font-size:14px; text-align:center;">Access your Product account</p>
                        <form action="<?php echo outbound_path('/login.php'); ?>" method="post" novalidate>
                            <label style="display:block; margin-bottom:16px;">
                                <span style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a;">Email</span>
                                <input type="email" name="email" autocomplete="email" placeholder="sample-001@example.com" required style="width:100%; padding:12px; border:1px solid var(--border); border-radius:10px; font-size:14px;">
                            </label>
                            <label style="display:block; margin-bottom:24px;">
                                <span style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a;">Password</span>
                                <input type="password" name="password" autocomplete="current-password" placeholder="Enter your password" required style="width:100%; padding:12px; border:1px solid var(--border); border-radius:10px; font-size:14px;">
                            </label>
                            <button class="btn" type="submit" style="width:100%; padding:14px; font-size:16px; font-weight:700;">
                                <i class="fas fa-sign-in-alt" style="margin-right:8px;"></i>Sign In
                            </button>
                            <div style="text-align:center;margin-top:12px;">
                                <a href="<?php echo outbound_path('/forgot-password.php'); ?>" style="color:var(--blue-2);text-decoration:none;font-size:14px;">Forgot your password?</a>
                            </div>
                        </form>
                    </div>

                    <!-- Register Panel with Role Selector -->
                    <div id="main-panel-register" role="tabpanel" aria-labelledby="main-tab-register" hidden>
                        <h2 style="margin:0 0 8px; font-size:26px; color:var(--blue); text-align:center;font-weight:800;">Get Started</h2>
                        <p style="margin:0 0 24px; color:#64748b; font-size:14px; text-align:center;">Choose your account type</p>
                        
                        <!-- Role Selection -->
                        <div id="role-selector" style="display:grid;gap:12px;margin-bottom:0;">
                            <button type="button" onclick="selectRole('wholesale')" class="role-btn" style="text-align:left;padding:16px;border:2px solid #e5e7eb;border-radius:12px;background:#fff;cursor:pointer;transition:all 0.2s;display:flex;align-items:center;gap:14px;">
                                <div style="width:48px;height:48px;border-radius:10px;background:linear-gradient(135deg,#0ea5e9,#0284c7);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <i class="fas fa-store" style="font-size:22px;color:#fff;"></i>
                                </div>
                                <div style="flex:1;">
                                    <div style="font-size:16px;font-weight:700;color:#0f172a;margin-bottom:4px;">I'm a Business</div>
                                    <div style="font-size:13px;color:#64748b;">Wholesale pricing & bulk orders</div>
                                </div>
                                <i class="fas fa-chevron-right" style="color:#cbd5e1;"></i>
                            </button>
                            
                            <button type="button" onclick="selectRole('retail_customer')" class="role-btn" style="text-align:left;padding:16px;border:2px solid #e5e7eb;border-radius:12px;background:#fff;cursor:pointer;transition:all 0.2s;display:flex;align-items:center;gap:14px;">
                                <div style="width:48px;height:48px;border-radius:10px;background:linear-gradient(135deg,#22c55e,#16a34a);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <i class="fas fa-shopping-cart" style="font-size:22px;color:#fff;"></i>
                                </div>
                                <div style="flex:1;">
                                    <div style="font-size:16px;font-weight:700;color:#0f172a;margin-bottom:4px;">I'm a Customer</div>
                                    <div style="font-size:13px;color:#64748b;">Shop retail at MSRP pricing</div>
                                </div>
                                <i class="fas fa-chevron-right" style="color:#cbd5e1;"></i>
                            </button>
                            
                            <button type="button" onclick="selectRole('trainee')" class="role-btn" style="text-align:left;padding:16px;border:2px solid #e5e7eb;border-radius:12px;background:#fff;cursor:pointer;transition:all 0.2s;display:flex;align-items:center;gap:14px;">
                                <div style="width:48px;height:48px;border-radius:10px;background:linear-gradient(135deg,#a855f7,#9333ea);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <i class="fas fa-user-tie" style="font-size:22px;color:#fff;"></i>
                                </div>
                                <div style="flex:1;">
                                    <div style="font-size:16px;font-weight:700;color:#0f172a;margin-bottom:4px;">I'm a Sales Agent</div>
                                    <div style="font-size:13px;color:#64748b;">Join our sales team & earn commissions</div>
                                </div>
                                <i class="fas fa-chevron-right" style="color:#cbd5e1;"></i>
                            </button>
                            
                            <button type="button" onclick="selectRole('investor')" class="role-btn" style="text-align:left;padding:16px;border:2px solid #e5e7eb;border-radius:12px;background:#fff;cursor:pointer;transition:all 0.2s;display:flex;align-items:center;gap:14px;">
                                <div style="width:48px;height:48px;border-radius:10px;background:linear-gradient(135deg,#f59e0b,#d97706);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <i class="fas fa-chart-line" style="font-size:22px;color:#fff;"></i>
                                </div>
                                <div style="flex:1;">
                                    <div style="font-size:16px;font-weight:700;color:#0f172a;margin-bottom:4px;">I'm an Investor</div>
                                    <div style="font-size:13px;color:#64748b;">Explore platform & opportunities</div>
                                </div>
                                <i class="fas fa-chevron-right" style="color:#cbd5e1;"></i>
                            </button>
                        </div>

                        <!-- Dynamic Registration Forms (hidden by default) -->
                        <div id="registration-forms" style="display:none;margin-top:20px;">
                            <button onclick="backToRoleSelector()" style="background:none;border:none;color:var(--blue);font-size:14px;font-weight:600;cursor:pointer;margin-bottom:16px;padding:8px 0;display:flex;align-items:center;gap:6px;">
                                <i class="fas fa-arrow-left"></i> Back to role selection
                            </button>
                            <div id="form-container"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Raw product format Showcase Section -->
<section class="section parallax-container" aria-labelledby="product format-title" style="background:linear-gradient(135deg,#fefcf8,#ffffff);position:relative;overflow:hidden;padding:100px 0;">
    <!-- Parallax Background -->
    <div class="parallax-bg-product format" data-parallax-speed="0.18" style="position:absolute;top:0;left:0;right:0;bottom:0;background:radial-gradient(1000px 500px at 30% 50%, rgba(212,175,55,0.06) 0%, transparent 65%);pointer-events:none;z-index:0;transform:translateZ(0);"></div>
    
    <div class="container" style="position:relative;z-index:1;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:64px;align-items:center;">
            <!-- Left: Product Images with Parallax -->
            <div class="fade-in-scroll" style="position:relative;transform:translateY(20px);">
                <div class="product format-image-stack" style="position:relative;height:550px;margin:0 auto;max-width:500px;">
                    <!-- Background Image -->
                    <div data-parallax-speed="0.08" style="position:absolute;top:30px;left:0;width:85%;height:380px;border-radius:20px;overflow:hidden;box-shadow:0 16px 48px rgba(0,0,0,0.1);transform:rotate(-2deg) translateZ(0);">
                        <img src="<?php echo outbound_path('/assets/img/product-placeholder.svg'); ?>" alt="Traditional catalog-ready Product format" loading="lazy" style="width:100%;height:100%;object-fit:cover;transition:transform 0.6s ease;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                    </div>
                    
                    <!-- Foreground Image -->
                    <div data-parallax-speed="0.15" style="position:absolute;bottom:30px;right:0;width:80%;height:360px;border-radius:20px;overflow:hidden;box-shadow:0 20px 64px rgba(0,0,0,0.15);border:3px solid #fff;transform:rotate(1.5deg) translateZ(0);">
                        <img src="<?php echo outbound_path('/assets/img/product-placeholder.svg'); ?>" alt="Ultra-clean catalog-ready Product format" loading="lazy" style="width:100%;height:100%;object-fit:cover;transition:transform 0.6s ease;" onmouseover="this.style.transform='scale(1.08)'" onmouseout="this.style.transform='scale(1)'">
                    </div>
                    
                    <!-- Floating Badge -->
                    <div data-parallax-speed="0.22" class="fade-in-scroll" style="position:absolute;top:10px;right:10px;background:rgba(255,255,255,0.98);padding:14px 20px;border-radius:40px;box-shadow:0 10px 32px rgba(0,0,0,0.12);border:2px solid rgba(212,175,55,0.25);backdrop-filter:blur(8px);transform:translateY(20px) translateZ(0);">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <i class="fas fa-certificate" style="color:#d4af37;font-size:16px;"></i>
                            <span style="font-weight:800;font-size:11px;color:#0c4a6e;letter-spacing:0.3px;text-transform:uppercase;">Ministry Certified</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Right: Content -->
            <div class="fade-in-scroll" style="transform:translateY(20px);">
                <div style="display:inline-block;padding:8px 20px;background:rgba(212,175,55,0.1);border:1px solid rgba(212,175,55,0.3);border-radius:50px;margin-bottom:16px;">
                    <span style="color:#b8860b;font-weight:700;font-size:13px;letter-spacing:1px;text-transform:uppercase;">
                        <i class="fas fa-mortar-pestle" style="margin-right:6px;"></i>Raw catalog-ready
                    </span>
                </div>
                <h2 id="product format-title" style="margin:0 0 20px;font-size:42px;color:#0c4a6e;font-weight:900;line-height:1.1;">
                    Ultra-Refined Product format
                </h2>
                <p style="margin:0 0 24px;font-size:18px;color:#334155;line-height:1.7;">
                    Privately sourced, meticulously ultra-refined by the <strong>only product manufacturer backed by The supplier documentation in supplier region</strong>. Unparalleled excellence in every gram.
                </p>
                
                <!-- Quality Highlights -->
                <div style="display:grid;gap:16px;margin-bottom:28px;">
                    <div style="background:#fff;padding:20px;border-radius:12px;border:2px solid #f0f9ff;box-shadow:0 4px 16px rgba(0,0,0,0.06);">
                        <div style="display:flex;align-items:start;gap:14px;">
                            <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#0ea5e9,#0284c7);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="fas fa-flask-vial" style="font-size:22px;color:#fff;"></i>
                            </div>
                            <div>
                                <h3 style="margin:0 0 6px;font-size:16px;font-weight:800;color:#0c4a6e;">product Manufacturing</h3>
                                <p style="margin:0;font-size:14px;color:#64748b;line-height:1.6;">Produced in a supplier documentation licensed product facility using quality records standards. Not just clean—absolutely pristine.</p>
                            </div>
                        </div>
                    </div>
                    
                    <div style="background:#fff;padding:20px;border-radius:12px;border:2px solid #fef3c7;box-shadow:0 4px 16px rgba(0,0,0,0.06);">
                        <div style="display:flex;align-items:start;gap:14px;">
                            <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#f59e0b,#d97706);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="fas fa-shield-check" style="font-size:22px;color:#fff;"></i>
                            </div>
                            <div>
                                <h3 style="margin:0 0 6px;font-size:16px;font-weight:800;color:#78350f;">Private Sourcing Excellence</h3>
                                <p style="margin:0;font-size:14px;color:#64748b;line-height:1.6;">Exclusive access to premium Thai product from carefully vetted growers. Harvested at peak maturity for maximum product attribute content.</p>
                            </div>
                        </div>
                    </div>
                    
                    <div style="background:#fff;padding:20px;border-radius:12px;border:2px solid #ecfdf5;box-shadow:0 4px 16px rgba(0,0,0,0.06);">
                        <div style="display:flex;align-items:start;gap:14px;">
                            <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#22c55e,#16a34a);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="fas fa-sparkles" style="font-size:22px;color:#fff;"></i>
                            </div>
                            <div>
                                <h3 style="margin:0 0 6px;font-size:16px;font-weight:800;color:#14532d;">Ultra-Refinement Process</h3>
                                <p style="margin:0;font-size:14px;color:#64748b;line-height:1.6;">Advanced multi-stage refinement removes product attributes while preserving full-spectrum product attribute profile. The cleanest product format available.</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- quality information Stats Grid -->
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;background:linear-gradient(135deg,#0f172a,#1e293b);padding:24px;border-radius:16px;">
                    <div style="text-align:center;">
                        <div style="font-size:28px;font-weight:900;color:#22c55e;margin-bottom:4px;">100%</div>
                        <div style="font-size:12px;color:#cbd5e1;font-weight:600;">Natural</div>
                    </div>
                    <div style="text-align:center;border-left:1px solid rgba(255,255,255,0.1);border-right:1px solid rgba(255,255,255,0.1);">
                        <div style="font-size:28px;font-weight:900;color:#0ea5e9;margin-bottom:4px;">0</div>
                        <div style="font-size:12px;color:#cbd5e1;font-weight:600;">Contaminants</div>
                    </div>
                    <div style="text-align:center;">
                        <div style="font-size:28px;font-weight:900;color:#fbbf24;margin-bottom:4px;">Lab</div>
                        <div style="font-size:12px;color:#cbd5e1;font-weight:600;">Verified</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Source Material Journey Section -->
<section class="section parallax-container" aria-labelledby="source-title" style="background:linear-gradient(135deg,#ecfdf5,#f0f9ff);position:relative;overflow:hidden;padding:100px 0;">
    <!-- Parallax Background Elements -->
    <div data-parallax-speed="0.12" style="position:absolute;top:8%;right:6%;width:280px;height:280px;border-radius:50%;background:radial-gradient(circle, rgba(34,197,94,0.08) 0%, transparent 70%);pointer-events:none;z-index:0;transform:translateZ(0);"></div>
    <div data-parallax-speed="0.18" style="position:absolute;bottom:12%;left:7%;width:240px;height:240px;border-radius:50%;background:radial-gradient(circle, rgba(14,165,233,0.06) 0%, transparent 70%);pointer-events:none;z-index:0;transform:translateZ(0);"></div>
    
    <div class="container" style="position:relative;z-index:1;">
        <!-- Section Header -->
        <div class="fade-in-scroll" style="text-align:center;max-width:800px;margin:0 auto 64px;transform:translateY(20px);">
            <div style="display:inline-block;padding:8px 20px;background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.2);border-radius:50px;margin-bottom:16px;">
                <span style="color:#059669;font-weight:700;font-size:13px;letter-spacing:1px;text-transform:uppercase;">
                    <i class="fas fa-seedling" style="margin-right:6px;"></i>From Leaf to Laboratory
                </span>
            </div>
            <h2 id="source-title" style="margin:0 0 20px;font-size:44px;color:#0c4a6e;font-weight:900;line-height:1.1;">
                The Journey to product quality information
            </h2>
            <p style="margin:0;font-size:18px;color:#334155;line-height:1.7;">
                From the pristine forests of supplier region to our supplier documentation licensed product facility, every step ensures unmatched quality and quality information.
            </p>
        </div>
        
        <!-- Timeline Journey -->
        <div style="display:grid;gap:48px;">
            <!-- Step 1: Harvesting -->
            <div class="fade-in-scroll" style="display:grid;grid-template-columns:1fr 1fr;gap:48px;align-items:center;transform:translateY(20px);">
                <div data-parallax-speed="0.1" style="position:relative;transform:translateZ(0);">
                    <div style="position:absolute;top:-15px;left:-15px;width:70px;height:70px;background:linear-gradient(135deg,#22c55e,#16a34a);border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 10px 28px rgba(34,197,94,0.25);z-index:2;">
                        <span style="font-size:28px;font-weight:900;color:#fff;">1</span>
                    </div>
                    <div style="border-radius:20px;overflow:hidden;box-shadow:0 16px 48px rgba(0,0,0,0.12);border:3px solid #fff;">
                        <img src="<?php echo outbound_path('/assets/img/product-placeholder.svg'); ?>" alt="Unharvested product leaves in supplier region" loading="lazy" style="width:100%;height:380px;object-fit:cover;display:block;transition:transform 0.6s ease;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                    </div>
                </div>
                <div>
                    <h3 style="margin:0 0 16px;font-size:32px;font-weight:900;color:#0c4a6e;">Private Thai Sourcing</h3>
                    <p style="margin:0 0 16px;font-size:16px;color:#334155;line-height:1.7;">
                        Our product originates from exclusive, carefully vetted growers in supplier region. Only mature leaves from optimal growing conditions are selected, ensuring maximum product attribute content and product specification from the source.
                    </p>
                    <div style="display:flex;flex-wrap:wrap;gap:10px;">
                        <div style="display:inline-flex;align-items:center;gap:8px;padding:8px 16px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;">
                            <i class="fas fa-map-marker-alt" style="color:#16a34a;"></i>
                            <span style="font-size:14px;font-weight:600;color:#14532d;">supplier region Origin</span>
                        </div>
                        <div style="display:inline-flex;align-items:center;gap:8px;padding:8px 16px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;">
                            <i class="fas fa-check-circle" style="color:#0ea5e9;"></i>
                            <span style="font-size:14px;font-weight:600;color:#0c4a6e;">Vetted Growers</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Step 2: Processing -->
            <div class="fade-in-scroll" style="display:grid;grid-template-columns:1fr 1fr;gap:48px;align-items:center;transform:translateY(20px);">
                <div class="order-2">
                    <h3 style="margin:0 0 16px;font-size:32px;font-weight:900;color:#0c4a6e;">Immediate Processing</h3>
                    <p style="margin:0 0 16px;font-size:16px;color:#334155;line-height:1.7;">
                        Freshly harvested leaves are immediately processed to preserve product attribute integrity. Traditional drying methods combined with modern quality control ensure optimal preservation of natural compounds.
                    </p>
                    <div style="display:flex;flex-wrap:wrap;gap:10px;">
                        <div style="display:inline-flex;align-items:center;gap:8px;padding:8px 16px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;">
                            <i class="fas fa-clock" style="color:#f59e0b;"></i>
                            <span style="font-size:14px;font-weight:600;color:#78350f;">Rapid Processing</span>
                        </div>
                        <div style="display:inline-flex;align-items:center;gap:8px;padding:8px 16px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;">
                            <i class="fas fa-leaf" style="color:#16a34a;"></i>
                            <span style="font-size:14px;font-weight:600;color:#14532d;">product attribute Preserved</span>
                        </div>
                    </div>
                </div>
                <div data-parallax-speed="0.1" class="order-1" style="position:relative;transform:translateZ(0);">
                    <div style="position:absolute;top:-15px;right:-15px;width:70px;height:70px;background:linear-gradient(135deg,#f59e0b,#d97706);border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 10px 28px rgba(245,158,11,0.25);z-index:2;">
                        <span style="font-size:28px;font-weight:900;color:#fff;">2</span>
                    </div>
                    <div style="border-radius:20px;overflow:hidden;box-shadow:0 16px 48px rgba(0,0,0,0.12);border:3px solid #fff;">
                        <img src="<?php echo outbound_path('/assets/img/product-placeholder.svg'); ?>" alt="Harvested product leaves being processed" loading="lazy" style="width:100%;height:380px;object-fit:cover;display:block;transition:transform 0.6s ease;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                    </div>
                </div>
            </div>
            
            <!-- Step 3: product Refinement -->
            <div class="fade-in-scroll" style="display:grid;grid-template-columns:1fr 1fr;gap:48px;align-items:center;transform:translateY(20px);">
                <div data-parallax-speed="0.1" style="position:relative;transform:translateZ(0);">
                    <div style="position:absolute;top:-15px;left:-15px;width:70px;height:70px;background:linear-gradient(135deg,#0ea5e9,#0284c7);border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 10px 28px rgba(14,165,233,0.25);z-index:2;">
                        <span style="font-size:28px;font-weight:900;color:#fff;">3</span>
                    </div>
                    <div style="border-radius:20px;overflow:hidden;box-shadow:0 16px 48px rgba(0,0,0,0.12);border:3px solid #fff;">
                        <img src="<?php echo outbound_path('/assets/img/product-placeholder.svg'); ?>" alt="supplier documentation product facility" loading="lazy" style="width:100%;height:380px;object-fit:cover;display:block;transition:transform 0.6s ease;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                    </div>
                </div>
                <div>
                    <h3 style="margin:0 0 16px;font-size:32px;font-weight:900;color:#0c4a6e;">Ministry Certified product Manufacturing</h3>
                    <p style="margin:0 0 16px;font-size:16px;color:#334155;line-height:1.7;">
                        The final transformation happens in supplier region's only supplier documentation licensed product facility for product. Using quality records standards and advanced ultra-refinement processes, we achieve catalog-ready quality information that sets the industry standard.
                    </p>
                    <div style="display:flex;flex-wrap:wrap;gap:10px;">
                        <div style="display:inline-flex;align-items:center;gap:8px;padding:8px 16px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;">
                            <i class="fas fa-certificate" style="color:#d4af37;"></i>
                            <span style="font-size:14px;font-weight:600;color:#78350f;">Ministry Licensed</span>
                        </div>
                        <div style="display:inline-flex;align-items:center;gap:8px;padding:8px 16px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;">
                            <i class="fas fa-flask-vial" style="color:#0ea5e9;"></i>
                            <span style="font-size:14px;font-weight:600;color:#0c4a6e;">quality records Standards</span>
                        </div>
                        <div style="display:inline-flex;align-items:center;gap:8px;padding:8px 16px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;">
                            <i class="fas fa-microscope" style="color:#16a34a;"></i>
                            <span style="font-size:14px;font-weight:600;color:#14532d;">Lab Verified</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- supplier documentation Credential Block -->
        <div class="fade-in-scroll" style="margin-top:64px;background:linear-gradient(135deg,#fffbeb,#fef3c7);padding:48px;border-radius:24px;border:3px solid #fbbf24;box-shadow:0 20px 60px rgba(245,158,11,0.2);transform:translateY(20px);">
            <div style="text-align:center;max-width:900px;margin:0 auto;">
                <div style="width:80px;height:80px;background:linear-gradient(135deg,#d4af37,#b8860b);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 24px;box-shadow:0 12px 32px rgba(212,175,55,0.3);">
                    <i class="fas fa-award" style="font-size:40px;color:#fff;"></i>
                </div>
                <h3 style="margin:0 0 16px;font-size:28px;font-weight:900;color:#78350f;">
                    supplier region supplier documentation Licensed product Manufacturer
                </h3>
                <p style="margin:0 0 24px;font-size:16px;color:#92400e;line-height:1.7;">
                    We are proud to partner with the <strong>only product manufacturing facility in supplier region licensed by the supplier documentation</strong> for Production. This exclusive credential ensures that every batch meets the highest product standards for cleanliness, quality information, and safety—far exceeding typical dietary supplement requirements.
                </p>
                <div style="display:flex;justify-content:center;flex-wrap:wrap;gap:12px;">
                    <div style="display:inline-flex;align-items:center;gap:10px;padding:12px 20px;background:rgba(255,255,255,0.9);border:2px solid rgba(212,175,55,0.4);border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,0.08);">
                        <i class="fas fa-building" style="color:#d4af37;font-size:20px;"></i>
                        <span style="font-weight:700;color:#78350f;font-size:14px;">product Facility</span>
                    </div>
                    <div style="display:inline-flex;align-items:center;gap:10px;padding:12px 20px;background:rgba(255,255,255,0.9);border:2px solid rgba(212,175,55,0.4);border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,0.08);">
                        <i class="fas fa-shield-check" style="color:#d4af37;font-size:20px;"></i>
                        <span style="font-weight:700;color:#78350f;font-size:14px;">quality records Certified</span>
                    </div>
                    <div style="display:inline-flex;align-items:center;gap:10px;padding:12px 20px;background:rgba(255,255,255,0.9);border:2px solid rgba(212,175,55,0.4);border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,0.08);">
                        <i class="fas fa-certificate" style="color:#d4af37;font-size:20px;"></i>
                        <span style="font-weight:700;color:#78350f;font-size:14px;">Ministry Licensed</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section" aria-labelledby="ingredients-title" style="background:linear-gradient(135deg,#f8fafc,#eef2ff);">
    <div class="container" style="display:grid; gap:48px; grid-template-columns:1fr 1fr; align-items:center;">
        <div>
            <div style="display:inline-block;padding:6px 16px;background:rgba(14,165,233,0.1);border:1px solid rgba(14,165,233,0.2);border-radius:50px;margin-bottom:14px;">
                <span style="color:#0284c7;font-weight:700;font-size:12px;letter-spacing:0.5px;text-transform:uppercase;">
                    <i class="fas fa-microscope" style="margin-right:6px;"></i>product quality information
                </span>
            </div>
            <h2 id="ingredients-title" style="margin:0 0 20px; font-size:38px; color:#0c4a6e;font-weight:900;line-height:1.15;">
                Only the purest ingredients. Nothing else.
            </h2>
            <p style="margin:0 0 28px; font-size:17px; color:#334155; line-height:1.7;max-width:540px;">
                Our product uses catalog-ready extraction and 4-pass filtration. Our product format undergoes ultra-refinement at a supplier documentation licensed facility. Every batch is tested, tracked, and authenticated.
            </p>
            
            <div style="background:#fff;padding:24px;border-radius:16px;border:2px solid #e0f2fe;margin-bottom:24px;">
                <h3 style="margin:0 0 16px;font-size:16px;font-weight:800;color:#0c4a6e;">
                    <i class="fas fa-flask" style="color:#0ea5e9;margin-right:8px;"></i>
                    Simple Ingredients, Complex Science
                </h3>
                <ul style="list-style:none; padding:0; margin:0; display:grid; gap:10px; font-size:14px; color:#334155;">
                    <li style="display:flex; gap:12px; align-items:flex-start;">
                        <i class="fas fa-check-circle" style="color:#22c55e;font-size:16px;margin-top:2px;flex-shrink:0;"></i>
                        <span><strong>Distilled Water</strong> — catalog-defined base</span>
                    </li>
                    <li style="display:flex; gap:12px; align-items:flex-start;">
                        <i class="fas fa-check-circle" style="color:#22c55e;font-size:16px;margin-top:2px;flex-shrink:0;"></i>
                        <span><strong>catalog-ready product attribute</strong> — Lab-verified product attribute content</span>
                    </li>
                    <li style="display:flex; gap:12px; align-items:flex-start;">
                        <i class="fas fa-check-circle" style="color:#22c55e;font-size:16px;margin-top:2px;flex-shrink:0;"></i>
                        <span><strong>Malic Acid</strong> — pH optimization</span>
                    </li>
                    <li style="display:flex; gap:12px; align-items:flex-start;">
                        <i class="fas fa-check-circle" style="color:#22c55e;font-size:16px;margin-top:2px;flex-shrink:0;"></i>
                        <span><strong>Potassium Sorbate</strong> — Natural preservative</span>
                    </li>
                    <li style="display:flex; gap:12px; align-items:flex-start;">
                        <i class="fas fa-check-circle" style="color:#22c55e;font-size:16px;margin-top:2px;flex-shrink:0;"></i>
                        <span><strong>Sodium Benzoate</strong> — Food-grade stabilizer</span>
                    </li>
                </ul>
            </div>
            
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div style="background:#f0f9ff;padding:18px;border-radius:12px;border:1px solid #bae6fd;">
                    <div style="font-size:32px;font-weight:900;color:#0284c7;margin-bottom:4px;">
                        <i class="fas fa-qrcode"></i>
                    </div>
                    <div style="font-size:14px;font-weight:600;color:#0c4a6e;">QR Authentication</div>
                    <div style="font-size:12px;color:#64748b;">Scan to verify batch</div>
                </div>
                <div style="background:#ecfdf5;padding:18px;border-radius:12px;border:1px solid #a7f3d0;">
                    <div style="font-size:32px;font-weight:900;color:#16a34a;margin-bottom:4px;">
                        <i class="fas fa-flask"></i>
                    </div>
                    <div style="font-size:14px;font-weight:600;color:#14532d;">Lab-Verified</div>
                    <div style="font-size:12px;color:#64748b;">COA with every batch</div>
                </div>
            </div>
        </div>
        <div style="position:relative;">
            <div class="card" style="background:#fff; border:2px solid rgba(15,23,42,0.08); border-radius:20px; padding:32px; box-shadow:0 24px 60px rgba(15,23,42,0.15);">
                <h3 style="margin:0 0 8px; color:#0c4a6e; font-size:24px;font-weight:800;">
                    <i class="fas fa-certificate" style="color:#0ea5e9;margin-right:10px;"></i>
                    quality information Metrics
                </h3>
                <p style="margin:0 0 24px;font-size:14px;color:#64748b;">Every batch exceeds industry standards</p>
                <dl style="margin:0; display:grid; gap:16px; font-size:15px; color:#475569;">
                    <div style="padding:16px;background:#f8fafc;border-radius:12px;border:1px solid #e2e8f0;">
                        <dt style="font-weight:800; color:#0c4a6e;font-size:14px;margin-bottom:6px;">
                            <i class="fas fa-tint" style="color:#0ea5e9;margin-right:8px;"></i>Clarity
                        </dt>
                        <dd style="margin:0;font-size:14px;color:#334155;">Crystal clear, no particulates</dd>
                    </div>
                    <div style="padding:16px;background:#f8fafc;border-radius:12px;border:1px solid #e2e8f0;">
                        <dt style="font-weight:800; color:#0c4a6e;font-size:14px;margin-bottom:6px;">
                            <i class="fas fa-microscope" style="color:#0ea5e9;margin-right:8px;"></i>Microbial Count
                        </dt>
                        <dd style="margin:0;font-size:14px;color:#334155;">Zero detectable organisms</dd>
                    </div>
                    <div style="padding:16px;background:#f8fafc;border-radius:12px;border:1px solid #e2e8f0;">
                        <dt style="font-weight:800; color:#0c4a6e;font-size:14px;margin-bottom:6px;">
                            <i class="fas fa-atom" style="color:#0ea5e9;margin-right:8px;"></i>Heavy Metals
                        </dt>
                        <dd style="margin:0;font-size:14px;color:#334155;">Below detection limits (ppb)</dd>
                    </div>
                    <div style="padding:16px;background:#fff5f5;border-radius:12px;border:1px solid #fecaca;">
                        <dt style="font-weight:800; color:#991b1b;font-size:14px;margin-bottom:6px;">
                            <i class="fas fa-ban" style="color:#ef4444;margin-right:8px;"></i>product attribute Content
                        </dt>
                        <dd style="margin:0;font-size:14px;color:#334155;">Zero — Not present in our product</dd>
                    </div>
                    <div style="padding:16px;background:#f8fafc;border-radius:12px;border:1px solid #e2e8f0;">
                        <dt style="font-weight:800; color:#0c4a6e;font-size:14px;margin-bottom:6px;">
                            <i class="fas fa-leaf" style="color:#22c55e;margin-right:8px;"></i>Source
                        </dt>
                        <dd style="margin:0;font-size:14px;color:#334155;">100% natural, organic product attribute</dd>
                    </div>
                    <div style="padding:16px;background:#ecfdf5;border-radius:12px;border:1px solid #a7f3d0;">
                        <dt style="font-weight:800; color:#14532d;font-size:14px;margin-bottom:6px;">
                            <i class="fas fa-building" style="color:#16a34a;margin-right:8px;"></i>compliance Registration
                        </dt>
                        <dd style="margin:0;font-size:14px;color:#166534;">manufacturer compliance details configured by the operator — <a href="https://www.leg.state.fl.us/Statutes/index.cfm?App_mode=Display_Statute&URL=0500-0599/0500/Sections/0500.92.html" target="_blank" rel="noopener" style="color:#16a34a;text-decoration:underline;">FL Statute 500.92 compliant</a></dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
</section>

<!-- Product Comparison Grid -->
<section class="section parallax-container" aria-labelledby="comparison-title" style="background:linear-gradient(135deg,#ffffff,#f8fafc);position:relative;overflow:hidden;padding:100px 0;">
    <!-- Parallax Background -->
    <div data-parallax-speed="0.14" style="position:absolute;top:6%;left:8%;width:350px;height:350px;border-radius:50%;background:radial-gradient(circle, rgba(14,165,233,0.05) 0%, transparent 68%);pointer-events:none;z-index:0;transform:translateZ(0);"></div>
    <div data-parallax-speed="0.09" style="position:absolute;bottom:8%;right:7%;width:320px;height:320px;border-radius:50%;background:radial-gradient(circle, rgba(212,175,55,0.05) 0%, transparent 68%);pointer-events:none;z-index:0;transform:translateZ(0);"></div>
    
    <div class="container" style="position:relative;z-index:1;">
        <!-- Section Header -->
        <div class="fade-in-scroll" style="text-align:center;max-width:800px;margin:0 auto 64px;transform:translateY(20px);">
            <div style="display:inline-block;padding:8px 20px;background:rgba(14,165,233,0.1);border:1px solid rgba(14,165,233,0.2);border-radius:50px;margin-bottom:16px;">
                <span style="color:#0284c7;font-weight:700;font-size:13px;letter-spacing:1px;text-transform:uppercase;">
                    <i class="fas fa-balance-scale" style="margin-right:6px;"></i>Product Comparison
                </span>
            </div>
            <h2 id="comparison-title" style="margin:0 0 20px;font-size:44px;color:#0c4a6e;font-weight:900;line-height:1.1;">
                Choose Your Perfect Format
            </h2>
            <p style="margin:0;font-size:18px;color:#334155;line-height:1.7;">
                Whether you prefer our crystal-clear product or ultra-refined product format, both deliver unmatched catalog-ready quality information and product specification.
            </p>
        </div>
        
        <!-- Comparison Grid -->
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:24px;max-width:1100px;margin:0 auto;">
            <!-- Product A -->
            <div class="fade-in-scroll product-comparison-card" style="background:#fff;padding:32px;border-radius:20px;border:3px solid #bae6fd;box-shadow:0 12px 40px rgba(14,165,233,0.15);transition:all 0.4s ease;transform:translateY(20px);">
                <div style="text-align:center;margin-bottom:24px;">
                    <div style="width:80px;height:80px;margin:0 auto 16px;border-radius:50%;background:linear-gradient(135deg,#0ea5e9,#0284c7);display:flex;align-items:center;justify-content:center;box-shadow:0 8px 24px rgba(14,165,233,0.3);">
                        <i class="fas fa-droplet" style="font-size:32px;color:#fff;"></i>
                    </div>
                    <h3 style="margin:0 0 8px;font-size:24px;font-weight:900;color:#0c4a6e;">Product A product</h3>
                    <p style="margin:0;font-size:14px;color:#64748b;font-weight:600;">20mg attribute / fl oz</p>
                </div>
                <ul style="list-style:none;padding:0;margin:0 0 24px;display:grid;gap:12px;">
                    <li style="display:flex;gap:10px;align-items:start;">
                        <i class="fas fa-check-circle" style="color:#22c55e;font-size:16px;margin-top:2px;flex-shrink:0;"></i>
                        <span style="font-size:14px;color:#334155;">Crystal-clear liquid</span>
                    </li>
                    <li style="display:flex;gap:10px;align-items:start;">
                        <i class="fas fa-check-circle" style="color:#22c55e;font-size:16px;margin-top:2px;flex-shrink:0;"></i>
                        <span style="font-size:14px;color:#334155;">Precision dosing</span>
                    </li>
                    <li style="display:flex;gap:10px;align-items:start;">
                        <i class="fas fa-check-circle" style="color:#22c55e;font-size:16px;margin-top:2px;flex-shrink:0;"></i>
                        <span style="font-size:14px;color:#334155;">Rapid absorption</span>
                    </li>
                    <li style="display:flex;gap:10px;align-items:start;">
                        <i class="fas fa-check-circle" style="color:#22c55e;font-size:16px;margin-top:2px;flex-shrink:0;"></i>
                        <span style="font-size:14px;color:#334155;">4-pass filtration</span>
                    </li>
                    <li style="display:flex;gap:10px;align-items:start;">
                        <i class="fas fa-check-circle" style="color:#22c55e;font-size:16px;margin-top:2px;flex-shrink:0;"></i>
                        <span style="font-size:14px;color:#334155;">QR authentication</span>
                    </li>
                </ul>
            </div>
            
            <!-- Product B -->
            <div class="fade-in-scroll product-comparison-card" style="background:#fff;padding:32px;border-radius:20px;border:3px solid #fbbf24;box-shadow:0 12px 40px rgba(245,158,11,0.15);transition:all 0.4s ease;transform:translateY(20px);">
                <div style="text-align:center;margin-bottom:24px;">
                    <div style="width:80px;height:80px;margin:0 auto 16px;border-radius:50%;background:linear-gradient(135deg,#f59e0b,#d97706);display:flex;align-items:center;justify-content:center;box-shadow:0 8px 24px rgba(245,158,11,0.3);">
                        <i class="fas fa-droplet" style="font-size:32px;color:#fff;"></i>
                    </div>
                    <h3 style="margin:0 0 8px;font-size:24px;font-weight:900;color:#78350f;">Product B product</h3>
                    <p style="margin:0;font-size:14px;color:#64748b;font-weight:600;">40mg attribute / fl oz</p>
                </div>
                <ul style="list-style:none;padding:0;margin:0 0 24px;display:grid;gap:12px;">
                    <li style="display:flex;gap:10px;align-items:start;">
                        <i class="fas fa-check-circle" style="color:#22c55e;font-size:16px;margin-top:2px;flex-shrink:0;"></i>
                        <span style="font-size:14px;color:#334155;">Maximum product specification</span>
                    </li>
                    <li style="display:flex;gap:10px;align-items:start;">
                        <i class="fas fa-check-circle" style="color:#22c55e;font-size:16px;margin-top:2px;flex-shrink:0;"></i>
                        <span style="font-size:14px;color:#334155;">Crystal-clear liquid</span>
                    </li>
                    <li style="display:flex;gap:10px;align-items:start;">
                        <i class="fas fa-check-circle" style="color:#22c55e;font-size:16px;margin-top:2px;flex-shrink:0;"></i>
                        <span style="font-size:14px;color:#334155;">Rapid absorption</span>
                    </li>
                    <li style="display:flex;gap:10px;align-items:start;">
                        <i class="fas fa-check-circle" style="color:#22c55e;font-size:16px;margin-top:2px;flex-shrink:0;"></i>
                        <span style="font-size:14px;color:#334155;">4-pass filtration</span>
                    </li>
                    <li style="display:flex;gap:10px;align-items:start;">
                        <i class="fas fa-check-circle" style="color:#22c55e;font-size:16px;margin-top:2px;flex-shrink:0;"></i>
                        <span style="font-size:14px;color:#334155;">QR authentication</span>
                    </li>
                </ul>
            </div>
            
            <!-- Raw product format -->
            <div class="fade-in-scroll product-comparison-card" style="background:#fff;padding:32px;border-radius:20px;border:3px solid #d4af37;box-shadow:0 12px 40px rgba(212,175,55,0.15);transition:all 0.4s ease;transform:translateY(20px);">
                <div style="text-align:center;margin-bottom:24px;">
                    <div style="width:80px;height:80px;margin:0 auto 16px;border-radius:50%;background:linear-gradient(135deg,#d4af37,#b8860b);display:flex;align-items:center;justify-content:center;box-shadow:0 8px 24px rgba(212,175,55,0.3);">
                        <i class="fas fa-mortar-pestle" style="font-size:32px;color:#fff;"></i>
                    </div>
                    <h3 style="margin:0 0 8px;font-size:24px;font-weight:900;color:#78350f;">Raw product format</h3>
                    <p style="margin:0;font-size:14px;color:#64748b;font-weight:600;">25g per Jar</p>
                </div>
                <ul style="list-style:none;padding:0;margin:0 0 24px;display:grid;gap:12px;">
                    <li style="display:flex;gap:10px;align-items:start;">
                        <i class="fas fa-check-circle" style="color:#22c55e;font-size:16px;margin-top:2px;flex-shrink:0;"></i>
                        <span style="font-size:14px;color:#334155;">Ministry licensed</span>
                    </li>
                    <li style="display:flex;gap:10px;align-items:start;">
                        <i class="fas fa-check-circle" style="color:#22c55e;font-size:16px;margin-top:2px;flex-shrink:0;"></i>
                        <span style="font-size:14px;color:#334155;">catalog-ready</span>
                    </li>
                    <li style="display:flex;gap:10px;align-items:start;">
                        <i class="fas fa-check-circle" style="color:#22c55e;font-size:16px;margin-top:2px;flex-shrink:0;"></i>
                        <span style="font-size:14px;color:#334155;">Privately sourced</span>
                    </li>
                    <li style="display:flex;gap:10px;align-items:start;">
                        <i class="fas fa-check-circle" style="color:#22c55e;font-size:16px;margin-top:2px;flex-shrink:0;"></i>
                        <span style="font-size:14px;color:#334155;">Ultra-refined quality information</span>
                    </li>
                    <li style="display:flex;gap:10px;align-items:start;">
                        <i class="fas fa-check-circle" style="color:#22c55e;font-size:16px;margin-top:2px;flex-shrink:0;"></i>
                        <span style="font-size:14px;color:#334155;">1g sample (wholesale only)</span>
                    </li>
                </ul>
            </div>
        </div>
        
        <!-- Shared Quality Guarantee -->
        <div class="fade-in-scroll" style="max-width:900px;margin:64px auto 0;background:linear-gradient(135deg,#f0f9ff,#ecfdf5);padding:32px;border-radius:20px;border:2px solid #bae6fd;text-align:center;transform:translateY(20px);">
            <h3 style="margin:0 0 16px;font-size:22px;font-weight:900;color:#0c4a6e;">
                <i class="fas fa-shield-check" style="color:#0ea5e9;margin-right:10px;"></i>
                All Products Share Our Uncompromising Standards
            </h3>
            <div style="display:flex;justify-content:center;flex-wrap:wrap;gap:16px;">
                <div style="display:flex;align-items:center;gap:8px;padding:10px 18px;background:rgba(255,255,255,0.8);border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.06);">
                    <i class="fas fa-times-circle" style="color:#ef4444;"></i>
                    <span style="font-weight:600;color:#334155;font-size:14px;">product details configured by the operator</span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;padding:10px 18px;background:rgba(255,255,255,0.8);border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.06);">
                    <i class="fas fa-times-circle" style="color:#ef4444;"></i>
                    <span style="font-weight:600;color:#334155;font-size:14px;">product details configured by the operator</span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;padding:10px 18px;background:rgba(255,255,255,0.8);border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.06);">
                    <i class="fas fa-times-circle" style="color:#ef4444;"></i>
                    <span style="font-weight:600;color:#334155;font-size:14px;">Zero product attribute</span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;padding:10px 18px;background:rgba(255,255,255,0.8);border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.06);">
                    <i class="fas fa-check-circle" style="color:#22c55e;"></i>
                    <span style="font-weight:600;color:#334155;font-size:14px;">100% Natural</span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;padding:10px 18px;background:rgba(255,255,255,0.8);border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.06);">
                    <i class="fas fa-certificate" style="color:#d4af37;"></i>
                    <span style="font-weight:600;color:#334155;font-size:14px;">Lab-Verified</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Detailed Product Showcase Sections -->

<!-- Product A product Details -->
<section id="blue-20-details" class="section parallax-container" style="background:linear-gradient(135deg,#f0f9ff,#ffffff);position:relative;overflow:hidden;padding:100px 0;">
    <div class="container" style="position:relative;z-index:1;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:64px;align-items:center;">
            <!-- Large Product Image -->
            <div class="fade-in-scroll" style="position:relative;transform:translateY(20px);">
                <div style="background:radial-gradient(circle at center, rgba(14,165,233,0.08) 0%, transparent 70%);padding:60px;border-radius:30px;">
                    <img src="<?php echo outbound_path('/assets/img/product-placeholder.svg'); ?>" alt="Product A product - Large" loading="lazy" style="width:100%;height:auto;max-width:500px;margin:0 auto;display:block;filter:drop-shadow(0 20px 60px rgba(14,165,233,0.4));">
                </div>
            </div>
            
            <!-- Features & Description -->
            <div class="fade-in-scroll" style="transform:translateY(20px);">
                <div style="display:inline-block;padding:8px 20px;background:rgba(14,165,233,0.1);border:1px solid rgba(14,165,233,0.2);border-radius:50px;margin-bottom:16px;">
                    <span style="color:#0284c7;font-weight:700;font-size:13px;letter-spacing:1px;text-transform:uppercase;">
                        <i class="fas fa-droplet" style="margin-right:6px;"></i>Precision product
                    </span>
                </div>
                <h2 style="margin:0 0 20px;font-size:48px;color:#0c4a6e;font-weight:900;line-height:1.1;">Product A product</h2>
                <p style="margin:0 0 24px;font-size:20px;color:#0284c7;font-weight:700;">20mg attribute / fl oz</p>
                <p style="margin:0 0 32px;font-size:17px;color:#334155;line-height:1.7;">
                    Perfect for those seeking measured, consistent dosing. Our Product A offers precision control with catalog-ready quality information in every drop.
                </p>
                
                <!-- Feature Highlights -->
                <div style="display:grid;gap:16px;">
                    <div style="display:flex;align-items:start;gap:14px;background:#fff;padding:20px;border-radius:12px;border:2px solid #e0f2fe;box-shadow:0 4px 16px rgba(0,0,0,0.06);">
                        <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#0ea5e9,#0284c7);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="fas fa-droplet" style="font-size:20px;color:#fff;"></i>
                        </div>
                        <div>
                            <h3 style="margin:0 0 6px;font-size:16px;font-weight:800;color:#0c4a6e;">Crystal-Clear Liquid</h3>
                            <p style="margin:0;font-size:14px;color:#64748b;line-height:1.6;">Visibly pure. workflow-supported 4-pass filtration removes every trace of sediment and imquality.</p>
                        </div>
                    </div>
                    
                    <div style="display:flex;align-items:start;gap:14px;background:#fff;padding:20px;border-radius:12px;border:2px solid #e0f2fe;box-shadow:0 4px 16px rgba(0,0,0,0.06);">
                        <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#0ea5e9,#0284c7);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="fas fa-chart-line" style="font-size:20px;color:#fff;"></i>
                        </div>
                        <div>
                            <h3 style="margin:0 0 6px;font-size:16px;font-weight:800;color:#0c4a6e;">Precision Dosing</h3>
                            <p style="margin:0;font-size:14px;color:#64748b;line-height:1.6;">Exact measurements every time. Know exactly what you're getting with lab-verified attribute content.</p>
                        </div>
                    </div>
                    
                    <div style="display:flex;align-items:start;gap:14px;background:#fff;padding:20px;border-radius:12px;border:2px solid #e0f2fe;box-shadow:0 4px 16px rgba(0,0,0,0.06);">
                        <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#0ea5e9,#0284c7);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="fas fa-bolt" style="font-size:20px;color:#fff;"></i>
                        </div>
                        <div>
                            <h3 style="margin:0 0 6px;font-size:16px;font-weight:800;color:#0c4a6e;">Rapid Absorption</h3>
                            <p style="margin:0;font-size:14px;color:#64748b;line-height:1.6;">Liquid formulation ensures maximum product specification and faster onset compared to traditional product format.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Product B product Details -->
<section id="gold-40-details" class="section parallax-container" style="background:linear-gradient(135deg,#fffbeb,#fef3c7);position:relative;overflow:hidden;padding:100px 0;">
    <div class="container" style="position:relative;z-index:1;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:64px;align-items:center;">
            <!-- Features & Description -->
            <div class="fade-in-scroll" style="transform:translateY(20px);">
                <div style="display:inline-block;padding:8px 20px;background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.2);border-radius:50px;margin-bottom:16px;">
                    <span style="color:#d97706;font-weight:700;font-size:13px;letter-spacing:1px;text-transform:uppercase;">
                        <i class="fas fa-star" style="margin-right:6px;"></i>Maximum product specification
                    </span>
                </div>
                <h2 style="margin:0 0 20px;font-size:48px;color:#78350f;font-weight:900;line-height:1.1;">Product B product</h2>
                <p style="margin:0 0 24px;font-size:20px;color:#d97706;font-weight:700;">40mg attribute / fl oz</p>
                <p style="margin:0 0 32px;font-size:17px;color:#334155;line-height:1.7;">
                    Our highest-product specification product for experienced users. Double the attribute concentration of Product A, delivering maximum effectiveness in minimal volume.
                </p>
                
                <!-- Feature Highlights -->
                <div style="display:grid;gap:16px;">
                    <div style="display:flex;align-items:start;gap:14px;background:#fff;padding:20px;border-radius:12px;border:2px solid #fef3c7;box-shadow:0 4px 16px rgba(0,0,0,0.06);">
                        <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#f59e0b,#d97706);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="fas fa-fire" style="font-size:20px;color:#fff;"></i>
                        </div>
                        <div>
                            <h3 style="margin:0 0 6px;font-size:16px;font-weight:800;color:#78350f;">Maximum product specification</h3>
                            <p style="margin:0;font-size:14px;color:#64748b;line-height:1.6;">Double the concentration means half the volume for experienced users seeking powerful effects.</p>
                        </div>
                    </div>
                    
                    <div style="display:flex;align-items:start;gap:14px;background:#fff;padding:20px;border-radius:12px;border:2px solid #fef3c7;box-shadow:0 4px 16px rgba(0,0,0,0.06);">
                        <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#f59e0b,#d97706);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="fas fa-flask-vial" style="font-size:20px;color:#fff;"></i>
                        </div>
                        <div>
                            <h3 style="margin:0 0 6px;font-size:16px;font-weight:800;color:#78350f;">Same quality information Standards</h3>
                            <p style="margin:0;font-size:14px;color:#64748b;line-height:1.6;">All the catalog-ready cleanliness of Product A, with twice the product attribute concentration.</p>
                        </div>
                    </div>
                    
                    <div style="display:flex;align-items:start;gap:14px;background:#fff;padding:20px;border-radius:12px;border:2px solid #fef3c7;box-shadow:0 4px 16px rgba(0,0,0,0.06);">
                        <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#f59e0b,#d97706);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="fas fa-certificate" style="font-size:20px;color:#fff;"></i>
                        </div>
                        <div>
                            <h3 style="margin:0 0 6px;font-size:16px;font-weight:800;color:#78350f;">QR Authentication</h3>
                            <p style="margin:0;font-size:14px;color:#64748b;line-height:1.6;">Every bottle verified with blockchain-backed QR codes for complete transparency and authenticity.</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Large Product Image -->
            <div class="fade-in-scroll" style="position:relative;transform:translateY(20px);">
                <div style="background:radial-gradient(circle at center, rgba(245,158,11,0.08) 0%, transparent 70%);padding:60px;border-radius:30px;">
                    <img src="<?php echo outbound_path('/assets/img/product-placeholder.svg'); ?>" alt="Product B product - Large" loading="lazy" style="width:100%;height:auto;max-width:500px;margin:0 auto;display:block;filter:drop-shadow(0 20px 60px rgba(245,158,11,0.4));">
                </div>
            </div>
        </div>
    </div>
</section>

<!-- 1g product format Sample (Wholesale Only) -->
<section id="product format-sample-details" class="section parallax-container" style="background:linear-gradient(135deg,#fefcf8,#ffffff);position:relative;overflow:hidden;padding:100px 0;">
    <div class="container" style="position:relative;z-index:1;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:64px;align-items:center;">
            <!-- Large Product Image -->
            <div class="fade-in-scroll" style="position:relative;transform:translateY(20px);">
                <div style="background:radial-gradient(circle at center, rgba(212,175,55,0.08) 0%, transparent 70%);padding:60px;border-radius:30px;">
                    <img src="<?php echo outbound_path('/assets/img/product-placeholder.svg'); ?>" alt="1g Product format Sample - Large" loading="lazy" style="width:100%;height:auto;max-width:500px;margin:0 auto;display:block;filter:drop-shadow(0 20px 60px rgba(212,175,55,0.4));">
                </div>
            </div>
            
            <!-- Features & Description -->
            <div class="fade-in-scroll" style="transform:translateY(20px);">
                <div style="display:inline-block;padding:8px 20px;background:rgba(212,175,55,0.1);border:1px solid rgba(212,175,55,0.2);border-radius:50px;margin-bottom:16px;">
                    <span style="color:#b8860b;font-weight:700;font-size:13px;letter-spacing:1px;text-transform:uppercase;">
                        <i class="fas fa-store" style="margin-right:6px;"></i>Wholesale Only
                    </span>
                </div>
                <h2 style="margin:0 0 20px;font-size:48px;color:#78350f;font-weight:900;line-height:1.1;">product format 1g Sample</h2>
                <p style="margin:0 0 24px;font-size:20px;color:#d4af37;font-weight:700;">Ultra-Refined catalog-ready</p>
                <p style="margin:0 0 32px;font-size:17px;color:#334155;line-height:1.7;">
                    Exclusive sample size for wholesale accounts only. Experience supplier documentation catalog-ready quality before placing bulk orders. <strong>Minimum 25g purchase required for wholesale orders.</strong>
                </p>
                
                <!-- Feature Highlights -->
                <div style="display:grid;gap:16px;">
                    <div style="display:flex;align-items:start;gap:14px;background:#fff;padding:20px;border-radius:12px;border:2px solid #fef3c7;box-shadow:0 4px 16px rgba(0,0,0,0.06);">
                        <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#d4af37,#b8860b);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="fas fa-store" style="font-size:20px;color:#fff;"></i>
                        </div>
                        <div>
                            <h3 style="margin:0 0 6px;font-size:16px;font-weight:800;color:#78350f;">Wholesale Exclusive</h3>
                            <p style="margin:0;font-size:14px;color:#64748b;line-height:1.6;">Available only to approved wholesale accounts. Perfect for product testing and quality verification.</p>
                        </div>
                    </div>
                    
                    <div style="display:flex;align-items:start;gap:14px;background:#fff;padding:20px;border-radius:12px;border:2px solid #fef3c7;box-shadow:0 4px 16px rgba(0,0,0,0.06);">
                        <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#d4af37,#b8860b);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="fas fa-certificate" style="font-size:20px;color:#fff;"></i>
                        </div>
                        <div>
                            <h3 style="margin:0 0 6px;font-size:16px;font-weight:800;color:#78350f;">Ministry Licensed</h3>
                            <p style="margin:0;font-size:14px;color:#64748b;line-height:1.6;">Produced by supplier region's only supplier documentation licensed Product manufacturer.</p>
                        </div>
                    </div>
                    
                    <div style="display:flex;align-items:start;gap:14px;background:#fff;padding:20px;border-radius:12px;border:2px solid #fef3c7;box-shadow:0 4px 16px rgba(0,0,0,0.06);">
                        <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#d4af37,#b8860b);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="fas fa-sparkles" style="font-size:20px;color:#fff;"></i>
                        </div>
                        <div>
                            <h3 style="margin:0 0 6px;font-size:16px;font-weight:800;color:#78350f;">Ultra-Refined quality information</h3>
                            <p style="margin:0;font-size:14px;color:#64748b;line-height:1.6;">Advanced multi-stage refinement process removes product attributes while preserving full-spectrum product attribute.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- 25g product format Jar -->
<section id="product format-jar-details" class="section parallax-container" style="background:linear-gradient(135deg,#f5f3ef,#ffffff);position:relative;overflow:hidden;padding:100px 0;">
    <div class="container" style="position:relative;z-index:1;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:64px;align-items:center;">
            <!-- Features & Description -->
            <div class="fade-in-scroll" style="transform:translateY(20px);">
                <div style="display:inline-block;padding:8px 20px;background:rgba(184,134,11,0.1);border:1px solid rgba(184,134,11,0.2);border-radius:50px;margin-bottom:16px;">
                    <span style="color:#78350f;font-weight:700;font-size:13px;letter-spacing:1px;text-transform:uppercase;">
                        <i class="fas fa-mortar-pestle" style="margin-right:6px;"></i>Standard Jar
                    </span>
                </div>
                <h2 style="margin:0 0 20px;font-size:48px;color:#78350f;font-weight:900;line-height:1.1;">product format 25g Jar</h2>
                <p style="margin:0 0 24px;font-size:20px;color:#b8860b;font-weight:700;">Ultra-Refined catalog-ready</p>
                <p style="margin:0 0 32px;font-size:17px;color:#334155;line-height:1.7;">
                    Our standard catalog-ready product format jar containing 25 grams. Privately sourced, meticulously ultra-refined—the cleanest Product format available anywhere. <strong>Minimum purchase for wholesale accounts.</strong>
                </p>
                
                <!-- Feature Highlights -->
                <div style="display:grid;gap:16px;">
                    <div style="display:flex;align-items:start;gap:14px;background:#fff;padding:20px;border-radius:12px;border:2px solid #fef3c7;box-shadow:0 4px 16px rgba(0,0,0,0.06);">
                        <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#b8860b,#996515);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="fas fa-shield-check" style="font-size:20px;color:#fff;"></i>
                        </div>
                        <div>
                            <h3 style="margin:0 0 6px;font-size:16px;font-weight:800;color:#78350f;">Privately Sourced</h3>
                            <p style="margin:0;font-size:14px;color:#64748b;line-height:1.6;">Exclusive access to premium Thai product from vetted growers. Harvested at peak maturity.</p>
                        </div>
                    </div>
                    
                    <div style="display:flex;align-items:start;gap:14px;background:#fff;padding:20px;border-radius:12px;border:2px solid #fef3c7;box-shadow:0 4px 16px rgba(0,0,0,0.06);">
                        <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#b8860b,#996515);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="fas fa-microscope" style="font-size:20px;color:#fff;"></i>
                        </div>
                        <div>
                            <h3 style="margin:0 0 6px;font-size:16px;font-weight:800;color:#78350f;">Lab-Verified COAs</h3>
                            <p style="margin:0;font-size:14px;color:#64748b;line-height:1.6;">Complete lab testing with every batch. Full transparency on product attribute content and quality information.</p>
                        </div>
                    </div>
                    
                    <div style="display:flex;align-items:start;gap:14px;background:#fff;padding:20px;border-radius:12px;border:2px solid #fef3c7;box-shadow:0 4px 16px rgba(0,0,0,0.06);">
                        <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#b8860b,#996515);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="fas fa-gem" style="font-size:20px;color:#fff;"></i>
                        </div>
                        <div>
                            <h3 style="margin:0 0 6px;font-size:16px;font-weight:800;color:#78350f;">Unparalleled Excellence</h3>
                            <p style="margin:0;font-size:14px;color:#64748b;line-height:1.6;">The highest quality Product format available. catalog-ready manufacturing meets private Thai sourcing.</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Large Product Image -->
            <div class="fade-in-scroll" style="position:relative;transform:translateY(20px);">
                <div style="background:radial-gradient(circle at center, rgba(184,134,11,0.08) 0%, transparent 70%);padding:60px;border-radius:30px;">
                    <img src="<?php echo outbound_path('/assets/img/product-placeholder.svg'); ?>" alt="25g Product format Jar - Large" loading="lazy" style="width:100%;height:auto;max-width:500px;margin:0 auto;display:block;filter:drop-shadow(0 20px 60px rgba(184,134,11,0.4));">
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section" aria-labelledby="filtration-title" style="background:linear-gradient(135deg,#0f172a,#1e293b);">
    <div class="container">
        <div style="text-align:center;max-width:900px;margin:0 auto 48px;">
            <div style="display:inline-block;padding:8px 20px;background:rgba(14,165,233,0.2);border:1px solid rgba(14,165,233,0.3);border-radius:50px;margin-bottom:16px;">
                <span style="color:#38bdf8;font-weight:700;font-size:13px;letter-spacing:1px;text-transform:uppercase;">
                    <i class="fas fa-atom" style="margin-right:6px;"></i>workflow-supported Technology
                </span>
            </div>
            <h2 id="filtration-title" style="margin:0 0 20px;font-size:42px;color:#e2e8f0;font-weight:900;line-height:1.1;">
                4-Pass catalog-ready Filtration Process
            </h2>
            <p style="margin:0;font-size:18px;color:#cbd5e1;line-height:1.7;">
                Our proprietary, workflow-supported 4-pass filtration system was engineered specifically to maximize quality information, cleanliness, and product specification—delivering the cleanest Product on the market.
            </p>
        </div>

        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:20px;margin-bottom:40px;max-width:1000px;margin-left:auto;margin-right:auto;">
            <!-- Pass 1 -->
            <div class="filtration-card" style="background:rgba(255,255,255,0.05);border:2px solid rgba(56,189,248,0.3);border-radius:12px;padding:24px;backdrop-filter:blur(10px);">
                <div style="display:flex;align-items:center;gap:16px;margin-bottom:12px;">
                    <div style="width:48px;height:48px;border-radius:10px;background:linear-gradient(135deg,#0ea5e9,#0284c7);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <span style="font-size:24px;font-weight:900;color:#fff;">1</span>
                    </div>
                    <h3 style="margin:0;font-size:19px;font-weight:800;color:#38bdf8;">Coarse Filtration</h3>
                </div>
                <p style="margin:0;font-size:14px;color:#cbd5e1;line-height:1.5;">Removes large particulates, plant matter, and visible product attributes.</p>
            </div>

            <!-- Pass 2 -->
            <div class="filtration-card" style="background:rgba(255,255,255,0.05);border:2px solid rgba(34,197,94,0.3);border-radius:12px;padding:24px;backdrop-filter:blur(10px);">
                <div style="display:flex;align-items:center;gap:16px;margin-bottom:12px;">
                    <div style="width:48px;height:48px;border-radius:10px;background:linear-gradient(135deg,#22c55e,#16a34a);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <span style="font-size:24px;font-weight:900;color:#fff;">2</span>
                    </div>
                    <h3 style="margin:0;font-size:19px;font-weight:800;color:#4ade80;">Microbial Elimination</h3>
                </div>
                <p style="margin:0;font-size:14px;color:#cbd5e1;line-height:1.5;">Eliminates bacteria, mold, yeast—zero detectable organisms.</p>
            </div>

            <!-- Pass 3 -->
            <div class="filtration-card" style="background:rgba(255,255,255,0.05);border:2px solid rgba(168,85,247,0.3);border-radius:12px;padding:24px;backdrop-filter:blur(10px);">
                <div style="display:flex;align-items:center;gap:16px;margin-bottom:12px;">
                    <div style="width:48px;height:48px;border-radius:10px;background:linear-gradient(135deg,#a855f7,#9333ea);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <span style="font-size:24px;font-weight:900;color:#fff;">3</span>
                    </div>
                    <h3 style="margin:0;font-size:19px;font-weight:800;color:#c084fc;">Taste & Odor Purification</h3>
                </div>
                <p style="margin:0;font-size:14px;color:#cbd5e1;line-height:1.5;">Removes bitter taste, earthy smell, and unpleasant aftertaste.</p>
            </div>

            <!-- Pass 4 -->
            <div class="filtration-card" style="background:rgba(255,255,255,0.05);border:2px solid rgba(245,158,11,0.3);border-radius:12px;padding:24px;backdrop-filter:blur(10px);">
                <div style="display:flex;align-items:center;gap:16px;margin-bottom:12px;">
                    <div style="width:48px;height:48px;border-radius:10px;background:linear-gradient(135deg,#f59e0b,#d97706);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <span style="font-size:24px;font-weight:900;color:#fff;">4</span>
                    </div>
                    <h3 style="margin:0;font-size:19px;font-weight:800;color:#fbbf24;">Ultra-Fine Polishing</h3>
                </div>
                <p style="margin:0;font-size:14px;color:#cbd5e1;line-height:1.5;">Sub-micron filtration removes heavy metals, carcinogens, pesticides, toxins.</p>
            </div>
        </div>

        <!-- What Gets Eliminated -->
        <div class="card" style="background:rgba(255,255,255,0.08);border:1px solid rgba(148,163,184,0.3);border-radius:16px;padding:32px;backdrop-filter:blur(10px);max-width:1000px;margin:0 auto;box-shadow:0 4px 20px rgba(0,0,0,0.2);">
            <h3 style="margin:0 0 24px;font-size:20px;font-weight:700;color:#cbd5e1;text-align:center;letter-spacing:0.5px;">
                <i class="fas fa-filter" style="margin-right:8px;color:#94a3b8;"></i>Eliminated Through Filtration
            </h3>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px;">
                <div style="background:rgba(15,23,42,0.4);border:1px solid rgba(148,163,184,0.2);border-radius:10px;padding:16px;text-align:center;transition:all 0.2s;">
                    <i class="fas fa-radiation" style="font-size:28px;color:#94a3b8;margin-bottom:10px;"></i>
                    <div style="font-size:14px;font-weight:600;color:#e2e8f0;">Carcinogens</div>
                    <div style="font-size:11px;color:#94a3b8;margin-top:4px;">Cancer-causing compounds</div>
                </div>
                <div style="background:rgba(15,23,42,0.4);border:1px solid rgba(148,163,184,0.2);border-radius:10px;padding:16px;text-align:center;transition:all 0.2s;">
                    <i class="fas fa-bacterium" style="font-size:28px;color:#94a3b8;margin-bottom:10px;"></i>
                    <div style="font-size:14px;font-weight:600;color:#e2e8f0;">Mold & Bacteria</div>
                    <div style="font-size:11px;color:#94a3b8;margin-top:4px;">Microbial contaminants</div>
                </div>
                <div style="background:rgba(15,23,42,0.4);border:1px solid rgba(148,163,184,0.2);border-radius:10px;padding:16px;text-align:center;transition:all 0.2s;">
                    <i class="fas fa-vial" style="font-size:28px;color:#94a3b8;margin-bottom:10px;"></i>
                    <div style="font-size:14px;font-weight:600;color:#e2e8f0;">Toxins</div>
                    <div style="font-size:11px;color:#94a3b8;margin-top:4px;">Heavy metals & poisons</div>
                </div>
                <div style="background:rgba(15,23,42,0.4);border:1px solid rgba(148,163,184,0.2);border-radius:10px;padding:16px;text-align:center;transition:all 0.2s;">
                    <i class="fas fa-seedling" style="font-size:28px;color:#94a3b8;margin-bottom:10px;"></i>
                    <div style="font-size:14px;font-weight:600;color:#e2e8f0;">Pesticides</div>
                    <div style="font-size:11px;color:#94a3b8;margin-top:4px;">Chemical residues</div>
                </div>
                <div style="background:rgba(15,23,42,0.4);border:1px solid rgba(148,163,184,0.2);border-radius:10px;padding:16px;text-align:center;transition:all 0.2s;">
                    <i class="fas fa-smog" style="font-size:28px;color:#94a3b8;margin-bottom:10px;"></i>
                    <div style="font-size:14px;font-weight:600;color:#e2e8f0;">Volatile Compounds</div>
                    <div style="font-size:11px;color:#94a3b8;margin-top:4px;">Unpleasant odors</div>
                </div>
                <div style="background:rgba(15,23,42,0.4);border:1px solid rgba(148,163,184,0.2);border-radius:10px;padding:16px;text-align:center;transition:all 0.2s;">
                    <i class="fas fa-flask-vial" style="font-size:28px;color:#94a3b8;margin-bottom:10px;"></i>
                    <div style="font-size:14px;font-weight:600;color:#e2e8f0;">Bitter product attribute</div>
                    <div style="font-size:11px;color:#94a3b8;margin-top:4px;">Unpalatable compounds</div>
                </div>
            </div>
        </div>

        <!-- Result -->
        <div style="text-align:center;margin-top:40px;">
            <div style="display:inline-block;padding:20px 40px;background:rgba(34,197,94,0.1);border:2px solid rgba(34,197,94,0.3);border-radius:12px;max-width:800px;">
                <div style="font-size:14px;font-weight:700;color:#4ade80;margin-bottom:6px;text-transform:uppercase;letter-spacing:1px;">The Result</div>
                <div style="font-size:28px;font-weight:900;color:#e2e8f0;line-height:1.2;">Maximum quality information. Maximum product specification.</div>
                <div style="font-size:15px;color:#cbd5e1;margin-top:8px;">Crystal-clear liquid you can trust.</div>
            </div>
        </div>
    </div>
</section>

<section class="section" aria-labelledby="program-title">
    <div class="container">
        <div class="section-header" style="text-align:center; max-width:800px; margin:0 auto 48px;">
            <div style="display:inline-block;padding:6px 16px;background:rgba(14,165,233,0.1);border:1px solid rgba(14,165,233,0.2);border-radius:50px;margin-bottom:14px;">
                <span style="color:#0284c7;font-weight:700;font-size:12px;letter-spacing:0.5px;text-transform:uppercase;">
                    <i class="fas fa-rocket" style="margin-right:6px;"></i>Enterprise Infrastructure
                </span>
            </div>
            <h2 id="program-title" style="margin:0 0 16px; font-size:36px; color:#0c4a6e;font-weight:900;">Built on world-class technology.</h2>
            <p style="margin:0; color:#475569; font-size:17px;line-height:1.7;">Every order, every customer, every batch—managed through our proprietary All-In-One Industry Platform. Built to scale from startup to enterprise.</p>
        </div>
        <div class="features" role="list" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:24px;margin-bottom:48px;">
            <div class="feature" role="listitem" style="background:#fff;border:2px solid #e0f2fe; border-radius:16px; padding:28px; box-shadow:0 4px 20px rgba(14,165,233,0.1);">
                <div style="width:56px;height:56px;border-radius:12px;background:linear-gradient(135deg,#0ea5e9,#0284c7);display:flex;align-items:center;justify-content:center;margin-bottom:16px;">
                    <i class="fas fa-truck" style="font-size:28px;color:#fff;"></i>
                </div>
                <h3 style="margin:0 0 12px; font-size:20px; color:#0f172a;font-weight:800;">Rapid Fulfillment</h3>
                <p style="margin:0; color:#475569; font-size:15px;line-height:1.6;">Automated order processing with 48-hour shipping. Real-time inventory tracking keeps you stocked.</p>
            </div>
            <div class="feature" role="listitem" style="background:#fff;border:2px solid #dcfce7; border-radius:16px; padding:28px; box-shadow:0 4px 20px rgba(34,197,94,0.1);">
                <div style="width:56px;height:56px;border-radius:12px;background:linear-gradient(135deg,#22c55e,#16a34a);display:flex;align-items:center;justify-content:center;margin-bottom:16px;">
                    <i class="fas fa-flask-vial" style="font-size:28px;color:#fff;"></i>
                </div>
                <h3 style="margin:0 0 12px; font-size:20px; color:#0f172a;font-weight:800;">Batch Tracking & Testing</h3>
                <p style="margin:0; color:#475569; font-size:15px;line-height:1.6;">Every batch tracked from source to customer. Full COAs with lab-verified results. Complete transparency and accountability.</p>
            </div>
            <div class="feature" role="listitem" style="background:#fff;border:2px solid #fef3c7; border-radius:16px; padding:28px; box-shadow:0 4px 20px rgba(245,158,11,0.1);">
                <div style="width:56px;height:56px;border-radius:12px;background:linear-gradient(135deg,#f59e0b,#d97706);display:flex;align-items:center;justify-content:center;margin-bottom:16px;">
                    <i class="fas fa-shield-check" style="font-size:28px;color:#fff;"></i>
                </div>
                <h3 style="margin:0 0 12px; font-size:20px; color:#0f172a;font-weight:800;">compliance Registered & Compliant</h3>
                <p style="margin:0; color:#475569; font-size:15px;line-height:1.6;">manufacturer compliance details configured by the operator. Florida Statute 500.92 compliant. Full COAs, batch tracking, and audit trails with every order.</p>
            </div>
            <div class="feature" role="listitem" style="background:#fff;border:2px solid #e9d5ff; border-radius:16px; padding:28px; box-shadow:0 4px 20px rgba(168,85,247,0.1);">
                <div style="width:56px;height:56px;border-radius:12px;background:linear-gradient(135deg,#a855f7,#9333ea);display:flex;align-items:center;justify-content:center;margin-bottom:16px;">
                    <i class="fas fa-chart-line" style="font-size:28px;color:#fff;"></i>
                </div>
                <h3 style="margin:0 0 12px; font-size:20px; color:#0f172a;font-weight:800;">Real-Time Analytics</h3>
                <p style="margin:0; color:#475569; font-size:15px;line-height:1.6;">Live dashboards track every metric. Make data-driven decisions with instant insights.</p>
            </div>
        </div>
        
        <div class="card" style="background:linear-gradient(135deg,#0f172a,#1e293b);padding:48px;border-radius:20px;text-align:center;border:2px solid rgba(255,255,255,0.1);">
            <div style="max-width:700px;margin:0 auto;">
                <div style="display:inline-block;padding:8px 20px;background:rgba(14,165,233,0.2);border:1px solid rgba(14,165,233,0.3);border-radius:50px;margin-bottom:20px;">
                    <span style="color:#38bdf8;font-weight:700;font-size:13px;letter-spacing:1px;text-transform:uppercase;">
                        <i class="fas fa-industry" style="margin-right:6px;"></i>For Investors
                    </span>
                </div>
                <h3 style="margin:0 0 16px;font-size:32px;font-weight:900;color:#e2e8f0;line-height:1.2;">
                    Explore the Product™ All-In-One Platform
                </h3>
                <p style="margin:0 0 32px;font-size:17px;color:#cbd5e1;line-height:1.7;">
                    Manufacturing management, sales automation, AI-powered CRM, and enterprise-grade infrastructure—all in one proprietary system. Built to scale across food, beverage, product, and manufacturing industries.
                </p>
                <a href="<?php echo outbound_path('/platform.php'); ?>" class="btn" style="background:#38bdf8;color:#0f172a;padding:16px 40px;font-size:17px;font-weight:700;display:inline-flex;align-items:center;gap:10px;text-decoration:none;border-radius:12px;box-shadow:0 8px 24px rgba(56,189,248,0.3);">
                    <i class="fas fa-rocket"></i>
                    View Platform Overview
                </a>
            </div>
        </div>
    </div>
</section>

<section class="section" aria-labelledby="sales-title" style="background:#0f172a; color:#e2e8f0;">
    <div class="container" style="display:grid; gap:40px; grid-template-columns:1fr 1fr; align-items:start;">
        <div>
            <div class="kicker-cap" style="color:#38bdf8;">Interested in selling?</div>
            <h2 id="sales-title" style="margin:0 0 18px; font-size:30px; color:#e2e8f0;">Join the Product sales collective.</h2>
            <p style="margin:0 0 16px; color:#cbd5f5; font-size:16px;">We partner with a small group of representatives who understand beverage operations. Complete certification and gain access to playbooks, lead routes, and a dedicated support pod.</p>
            <ul style="list-style:none; padding:0; margin:0; display:grid; gap:10px; font-size:14px; color:#cbd5f5;">
                <li>• 4-hour certification with live Q&A</li>
                <li>• Menu-ready tasting kits shipped to you</li>
                <li>• Residual commissions on wholesale clients you activate</li>
            </ul>
        </div>
        <div class="card" style="background:#111c32; border:1px solid rgba(148,163,184,0.2); border-radius:20px; padding:28px;">
            <h3 style="margin:0 0 12px; color:#38bdf8; font-size:22px;">Sales certification signup</h3>
            <p style="margin:0 0 18px; color:#cbd5f5; font-size:14px;">We’ll reach out within one business day to align on territory and expectations.</p>
            <form action="<?php echo outbound_path('/required/registration.php'); ?>" method="post" novalidate>
                <input type="hidden" name="type" value="trainee">
                <div style="display:grid; gap:14px;">
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                        <label>
                            <span style="display:block; margin-bottom:6px; font-weight:600; color:#e2e8f0;">First name</span>
                            <input name="first_name" placeholder="First" required style="width:100%; padding:11px; border:1px solid rgba(148,163,184,0.3); border-radius:10px; font-size:14px; background:#0f172a; color:#e2e8f0;">
                        </label>
                        <label>
                            <span style="display:block; margin-bottom:6px; font-weight:600; color:#e2e8f0;">Last name</span>
                            <input name="last_name" placeholder="Last" required style="width:100%; padding:11px; border:1px solid rgba(148,163,184,0.3); border-radius:10px; font-size:14px; background:#0f172a; color:#e2e8f0;">
                        </label>
                    </div>
                    <label>
                        <span style="display:block; margin-bottom:6px; font-weight:600; color:#e2e8f0;">Email</span>
                        <input type="email" name="email" placeholder="sample-001@example.com" required style="width:100%; padding:11px; border:1px solid rgba(148,163,184,0.3); border-radius:10px; font-size:14px; background:#0f172a; color:#e2e8f0;">
                    </label>
                    <label>
                        <span style="display:block; margin-bottom:6px; font-weight:600; color:#e2e8f0;">Phone</span>
                        <input name="phone" placeholder="XXX-XXX-XXXX" required style="width:100%; padding:11px; border:1px solid rgba(148,163,184,0.3); border-radius:10px; font-size:14px; background:#0f172a; color:#e2e8f0;">
                    </label>
                    <label>
                        <span style="display:block; margin-bottom:6px; font-weight:600; color:#e2e8f0;">Password</span>
                        <input type="password" name="password" placeholder="Create a password" required style="width:100%; padding:11px; border:1px solid rgba(148,163,184,0.3); border-radius:10px; font-size:14px; background:#0f172a; color:#e2e8f0;">
                    </label>
                </div>
                
                <!-- Anti-Bot Warning -->
                <div style="margin-top:18px; padding:14px; background:#422006; border:1px solid #78350f; border-radius:8px;">
                    <div style="display:flex; align-items:start; gap:10px;">
                        <span style="font-size:20px;">⚠️</span>
                        <div style="font-size:13px; color:#fbbf24; line-height:1.5;">
                            <strong>Anti-Bot Notice:</strong> If you are signing up with a bot, AI, or another type of automated service, your IP address will be blacklisted by our security system.
                        </div>
                    </div>
                </div>
                
                <!-- Communications Consent Agreements -->
                <div style="margin-top:18px; padding:16px; background:#1e293b; border:1px solid rgba(148,163,184,0.3); border-radius:8px;">
                    <div style="font-size:14px; font-weight:600; color:#e2e8f0; margin-bottom:12px;">
                        📞 Communications Preferences
                    </div>
                    <div style="display:grid; gap:10px; font-size:13px;">
                        <label style="display:flex; align-items:start; gap:8px; cursor:pointer;">
                            <input type="checkbox" name="consent_email" value="1" checked required style="margin-top:2px;">
                            <span style="color:#cbd5f5;">
                                I consent to receive email communications from Product 
                                <a href="#" onclick="showAgreement(event, 'email_consent')" style="color:#38bdf8; text-decoration:underline;">
                                    (View Agreement)
                                </a>
                            </span>
                        </label>
                        <label style="display:flex; align-items:start; gap:8px; cursor:pointer;">
                            <input type="checkbox" name="consent_sms" value="1" style="margin-top:2px;">
                            <span style="color:#cbd5f5;">
                                I consent to receive SMS/text messages from Product 
                                <a href="#" onclick="showAgreement(event, 'sms_consent')" style="color:#38bdf8; text-decoration:underline;">
                                    (View Agreement)
                                </a>
                            </span>
                        </label>
                        <label style="display:flex; align-items:start; gap:8px; cursor:pointer;">
                            <input type="checkbox" name="consent_phone" value="1" style="margin-top:2px;">
                            <span style="color:#cbd5f5;">
                                I consent to receive phone calls from Product 
                                <a href="#" onclick="showAgreement(event, 'phone_consent')" style="color:#38bdf8; text-decoration:underline;">
                                    (View Agreement)
                                </a>
                            </span>
                        </label>
                    </div>
                    <div style="margin-top:12px; font-size:12px; color:#94a3b8; line-height:1.5;">
                        * Email consent is required for certification communications. SMS and phone consent are optional. 
                        You can update these preferences anytime in your account settings.
                    </div>
                </div>
                
                <!-- Sales Agent Agreement -->
                <div style="margin-top:18px; padding:16px; background:#1e293b; border:1px solid rgba(148,163,184,0.3); border-radius:8px;">
                    <div style="font-size:14px; font-weight:600; color:#e2e8f0; margin-bottom:12px;">
                        📋 Sales Agent Terms
                    </div>
                    <label style="display:flex; align-items:start; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="agree_terms" value="1" required style="margin-top:2px;">
                        <span style="color:#cbd5f5; font-size:13px;">
                            I agree to the 
                            <a href="#" onclick="showAgreement(event, 'sales_agent_terms')" style="color:#38bdf8; text-decoration:underline;">
                                Sales Agent Terms & Conditions
                            </a>
                            and understand that I will represent Products professionally and in compliance with all applicable laws.
                        </span>
                    </label>
                    <div style="margin-top:10px; font-size:12px; color:#94a3b8; line-height:1.5;">
                        * Agreement to terms is required to begin certification. This includes commission structure, non-compete clauses, and ethical sales practices.
                    </div>
                </div>
                
                <button class="btn" type="submit" style="width:100%; margin-top:18px; padding:13px; font-weight:600; background:#38bdf8; color:#0f172a;">Start certification</button>
            </form>
        </div>
    </div>
</section>


<style>
/* Parallax & Animation CSS */
.parallax-container {
    overflow: hidden;
    position: relative;
}

/* Hero Image Backdrop */
.hero-image-backdrop {
    will-change: transform;
}

.hero-image-backdrop > div:first-child {
    will-change: filter;
    backface-visibility: hidden;
    -webkit-backface-visibility: hidden;
}

.parallax-layer {
    transition: transform 0.5s cubic-bezier(0.25, 0.46, 0.45, 0.94);
}

.parallax-bg {
    will-change: transform;
}

[data-parallax-speed] {
    will-change: transform;
    transition: transform 0.05s linear;
    backface-visibility: hidden;
    -webkit-backface-visibility: hidden;
}

.fade-in-scroll {
    opacity: 0;
    transform: translateY(30px);
    transition: opacity 0.8s ease, transform 0.8s ease;
}

.fade-in-scroll.visible {
    opacity: 1;
    transform: translateY(0);
}

/* Product Option Cards Hover */
.product-option-card {
    transition: all 0.3s ease;
}

.product-option-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 36px rgba(0,0,0,0.12) !important;
}

/* product format Image Stack Hover Effects */
.product format-image-stack img {
    transition: transform 0.6s cubic-bezier(0.25, 0.46, 0.45, 0.94);
}

/* Product Comparison Cards Hover */
.product-comparison-card {
    transition: all 0.4s ease;
}

.product-comparison-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 20px 60px rgba(0,0,0,0.18) !important;
}

/* Product Bottles with Parallax */
.product-bottle {
    transition: all 0.4s ease;
}

.product-bottle img {
    transition: transform 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
}

/* Product Showcase Cards with Scroll Effects */
.product-showcase-card {
    will-change: transform, opacity;
    transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94) !important;
}

.product-showcase-card:hover {
    transform: scale(1.05) !important;
    box-shadow: 0 16px 48px rgba(0,0,0,0.18) !important;
}

.product-showcase-card img {
    transition: transform 0.4s ease;
}

.product-showcase-card:hover img {
    transform: scale(1.05);
}

/* Smooth Scroll */
html {
    scroll-behavior: smooth;
}

/* Performance Optimizations */
@media (prefers-reduced-motion: reduce) {
    .fade-in-scroll,
    .parallax-layer,
    [data-parallax-speed],
    .product-option-card,
    .product format-image-stack img {
        transition: none !important;
        animation: none !important;
    }
    
    html {
        scroll-behavior: auto;
    }
}
</style>

<style>
/* Mobile Optimization */
@media (max-width: 768px) {
    /* Hero Section */
    .auth-hero {
        padding: 40px 0 !important;
    }
    
    .hero-grid {
        grid-template-columns: 1fr !important;
        gap: 32px !important;
    }
    
    .hero-grid h1 {
        font-size: 36px !important;
    }
    
    .hero-grid h2 {
        font-size: 22px !important;
    }
    
    .hero-grid p {
        font-size: 16px !important;
    }
    
    /* Product Options */
    .hero-grid > div:first-child > div[style*="grid-template-columns:1fr 1fr"] {
        grid-template-columns: 1fr !important;
    }
    
    /* quality information Grid */
    .quality information-grid {
        grid-template-columns: 1fr !important;
    }
    
    /* Auth Card */
    .hero-auth .card {
        padding: 24px !important;
    }
    
    /* Ingredients Section */
    .section[aria-labelledby="ingredients-title"] > .container {
        grid-template-columns: 1fr !important;
        gap: 32px !important;
    }
    
    .section[aria-labelledby="ingredients-title"] h2 {
        font-size: 28px !important;
    }
    
    /* Features Grid */
    .features {
        grid-template-columns: 1fr !important;
    }
    
    /* Sales Section */
    .section[aria-labelledby="sales-title"] > .container {
        grid-template-columns: 1fr !important;
        gap: 28px !important;
    }
    
    .section[aria-labelledby="sales-title"] h2 {
        font-size: 24px !important;
    }
    
    /* Platform Card */
    .card[style*="gradient"] {
        padding: 32px 20px !important;
    }
    
    /* General Spacing */
    .section {
        padding: 40px 0 !important;
    }
    
    .container {
        padding: 0 20px !important;
    }
    
    /* Form Grids */
    form div[style*="grid-template-columns:1fr 1fr"] {
        grid-template-columns: 1fr !important;
    }
    
    /* Ingredients Section Bottom Grid (QR + Lab Verified) */
    .section[aria-labelledby="ingredients-title"] div[style*="grid-template-columns:1fr 1fr"]:not(form div) {
        grid-template-columns: 1fr !important;
    }
    
    /* Filtration Section */
    .section[aria-labelledby="filtration-title"] h2 {
        font-size: 32px !important;
    }
    
    .section[aria-labelledby="filtration-title"] > .container > div[style*="grid-template-columns:repeat(2,1fr)"] {
        grid-template-columns: 1fr !important;
    }
    
    .section[aria-labelledby="filtration-title"] div[style*="grid-template-columns:repeat(3,1fr)"] {
        grid-template-columns: repeat(2,1fr) !important;
    }
    
    .filtration-card {
        padding: 20px !important;
    }
}

@media (max-width: 480px) {
    /* Extra small devices */
    .hero-grid h1 {
        font-size: 28px !important;
        line-height: 1.15 !important;
    }
    
    .hero-grid h2 {
        font-size: 18px !important;
    }
    
    .hero-grid p {
        font-size: 15px !important;
    }
    
    .hero-auth .card {
        padding: 20px !important;
    }
    
    .section[aria-labelledby="ingredients-title"] h2,
    .section[aria-labelledby="program-title"] h2 {
        font-size: 24px !important;
    }
    
    .feature h3 {
        font-size: 18px !important;
    }
    
    .feature p {
        font-size: 14px !important;
    }
    
    /* Filtration Section - Extra Small */
    .section[aria-labelledby="filtration-title"] h2 {
        font-size: 26px !important;
    }
    
    .section[aria-labelledby="filtration-title"] div[style*="grid-template-columns:repeat(3,1fr)"] {
        grid-template-columns: 1fr !important;
    }
    
    .filtration-card h3 {
        font-size: 17px !important;
    }
    
    .filtration-card p {
        font-size: 13px !important;
    }
    
    /* New Sections - Mobile Responsive */
    .product format-image-stack {
        height: 450px !important;
    }
    
    .section[aria-labelledby="product format-title"] > .container > div {
        grid-template-columns: 1fr !important;
        gap: 32px !important;
    }
    
    .section[aria-labelledby="source-title"] > .container > div > div {
        grid-template-columns: 1fr !important;
    }
    
    .section[aria-labelledby="source-title"] .order-1,
    .section[aria-labelledby="source-title"] .order-2 {
        order: 0 !important;
    }
    
    /* Disable parallax transform on mobile for alignment */
    [data-parallax-speed] {
        transform: translate3d(0, 0, 0) !important;
    }
    
    /* Hide or reduce backdrop image on mobile for performance */
    .hero-image-backdrop > div:first-child {
        opacity: 0.3 !important;
        filter: blur(4px) !important;
    }
    
    /* Product option cards mobile */
    div[style*="grid-template-columns:repeat(3,1fr)"] {
        grid-template-columns: 1fr !important;
    }
    
    /* Product bottles mobile layout */
    .hero-copy > div[style*="display:flex"] {
        flex-direction: column !important;
        align-items: center !important;
    }
    
    .product-bottle {
        max-width: 200px !important;
    }
    
    /* Product showcase cards mobile */
    .hero-copy > div > div[style*="grid-template-columns:repeat(4,1fr)"] {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 16px !important;
    }
    
    /* Product comparison section mobile */
    .section[aria-labelledby="comparison-title"] > .container > div[style*="grid-template-columns:repeat(3,1fr)"] {
        grid-template-columns: 1fr !important;
    }
}
</style>

<script>
// Parallax Scroll Handler with Blur Effect & Product Zoom/Fade (Throttled for Performance)
(function() {
    let ticking = false;
    
    // Check if user prefers reduced motion
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    
    if (prefersReducedMotion) {
        // Skip parallax for accessibility
        return;
    }
    
    function updateParallax() {
        const scrollY = window.scrollY;
        
        // Update parallax elements
        document.querySelectorAll('[data-parallax-speed]').forEach(el => {
            const speed = parseFloat(el.getAttribute('data-parallax-speed'));
            const rect = el.getBoundingClientRect();
            const elementTop = rect.top + scrollY;
            const elementHeight = rect.height;
            
            // Only apply parallax when element is in viewport or near it
            if (scrollY + window.innerHeight > elementTop - 200 && scrollY < elementTop + elementHeight + 200) {
                const yPos = -(scrollY - elementTop) * speed;
                el.style.transform = `translate3d(0, ${yPos}px, 0)`;
            }
            
            // Apply blur effect to hero backdrop
            if (el.hasAttribute('data-blur-on-scroll')) {
                const imageDiv = el.querySelector('div[style*="background:url"]');
                if (imageDiv) {
                    // Calculate blur amount based on scroll (0px to 8px over first 600px of scroll)
                    const maxScroll = 600;
                    const maxBlur = 8;
                    const blurAmount = Math.min((scrollY / maxScroll) * maxBlur, maxBlur);
                    imageDiv.style.filter = `blur(${blurAmount}px)`;
                }
            }
        });
        
        // Handle product showcase cards zoom and fade on scroll
        document.querySelectorAll('[data-scroll-zoom]').forEach(card => {
            const rect = card.getBoundingClientRect();
            const cardTop = rect.top;
            const windowHeight = window.innerHeight;
            
            // Calculate progress (1 = fully visible, 0 = scrolled past)
            // Start effect when card reaches 60% of viewport
            const triggerPoint = windowHeight * 0.6;
            
            if (cardTop < triggerPoint) {
                // Card is in view and scrolling up
                // Increased from 400px to 1000px for slower fade
                const scrollProgress = Math.max(0, Math.min(1, (triggerPoint - cardTop) / 1000));
                
                // Zoom out: 1.0 to 0.92 (reduced intensity)
                const scale = 1 - (scrollProgress * 0.08);
                
                // Fade out: 1 to 0.5 (less aggressive fade)
                const opacity = 1 - (scrollProgress * 0.5);
                
                card.style.transform = `scale(${scale})`;
                card.style.opacity = opacity;
            } else {
                // Card hasn't reached trigger point yet - keep at full scale
                card.style.transform = 'scale(1)';
                card.style.opacity = '1';
            }
        });
        
        ticking = false;
    }
    
    function requestTick() {
        if (!ticking) {
            window.requestAnimationFrame(updateParallax);
            ticking = true;
        }
    }
    
    window.addEventListener('scroll', requestTick, { passive: true });
    
    // Initial parallax position
    updateParallax();
})();

// Intersection Observer for Fade-in Animations
(function() {
    const observerOptions = {
        root: null,
        rootMargin: '0px 0px -100px 0px',
        threshold: 0.1
    };
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                // Optionally unobserve after animation
                observer.unobserve(entry.target);
            }
        });
    }, observerOptions);
    
    // Observe all fade-in elements
    document.querySelectorAll('.fade-in-scroll').forEach(el => {
        observer.observe(el);
    });
})();

// Product Option Card Interactive Effects
(function() {
    document.querySelectorAll('.product-option-card').forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.borderColor = getComputedStyle(this).borderColor;
        });
    });
})();

// Smooth Scroll for Product Card Links
(function() {
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            const targetId = this.getAttribute('href');
            if (targetId && targetId !== '#') {
                const targetElement = document.querySelector(targetId);
                if (targetElement) {
                    e.preventDefault();
                    targetElement.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            }
        });
    });
})();

document.addEventListener('DOMContentLoaded', function() {
    const mainLoginTab = document.getElementById('main-tab-login');
    const mainRegisterTab = document.getElementById('main-tab-register');
    const mainLoginPanel = document.getElementById('main-panel-login');
    const mainRegisterPanel = document.getElementById('main-panel-register');

    function switchMainTab(activeTab, activePanel, inactiveTab, inactivePanel) {
        activeTab.setAttribute('aria-selected', 'true');
        activeTab.classList.remove('secondary');
        inactiveTab.setAttribute('aria-selected', 'false');
        inactiveTab.classList.add('secondary');

        activePanel.hidden = false;
        inactivePanel.hidden = true;
    }

    if (mainLoginTab && mainRegisterTab) {
        mainLoginTab.addEventListener('click', function() {
            switchMainTab(mainLoginTab, mainLoginPanel, mainRegisterTab, mainRegisterPanel);
        });

        mainRegisterTab.addEventListener('click', function() {
            switchMainTab(mainRegisterTab, mainRegisterPanel, mainLoginTab, mainLoginPanel);
        });
    }
    
    // Check for role parameter in URL to auto-select signup form
    const urlParams = new URLSearchParams(window.location.search);
    const roleParam = urlParams.get('role');
    
    if (roleParam && mainRegisterTab && mainRegisterPanel) {
        // Switch to signup tab
        switchMainTab(mainRegisterTab, mainRegisterPanel, mainLoginTab, mainLoginPanel);
        
        // Auto-select the specified role
        if (roleParam === 'investor') {
            selectRole('investor');
        } else if (roleParam === 'wholesale') {
            selectRole('wholesale');
        } else if (roleParam === 'retail_customer') {
            selectRole('retail_customer');
        } else if (roleParam === 'trainee') {
            selectRole('trainee');
        }
        
        // Auto-scroll to form on mobile
        setTimeout(function() {
            const isMobile = window.innerWidth <= 768;
            if (isMobile) {
                const authSection = document.querySelector('.hero-auth');
                if (authSection) {
                    authSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        }, 300);
    }
    
    // Add hover effects to role buttons
    document.querySelectorAll('.role-btn').forEach(btn => {
        btn.addEventListener('mouseenter', function() {
            this.style.borderColor = '#0ea5e9';
            this.style.boxShadow = '0 4px 20px rgba(14,165,233,0.15)';
        });
        btn.addEventListener('mouseleave', function() {
            this.style.borderColor = '#e5e7eb';
            this.style.boxShadow = 'none';
        });
    });
});

// Role selector functions
function selectRole(role) {
    const roleSelector = document.getElementById('role-selector');
    const registrationForms = document.getElementById('registration-forms');
    const formContainer = document.getElementById('form-container');
    
    roleSelector.style.display = 'none';
    registrationForms.style.display = 'block';
    
    const forms = {
        wholesale: getWholesaleForm(),
        retail_customer: getRetailForm(),
        trainee: getTraineeForm(),
        investor: getInvestorForm()
    };
    
    formContainer.innerHTML = forms[role] || '';
}

function backToRoleSelector() {
    document.getElementById('role-selector').style.display = 'grid';
    document.getElementById('registration-forms').style.display = 'none';
}

function getWholesaleForm() {
    // Check if agent attribution exists
    const agentSlug = '<?php echo isset($_SESSION["referral_agent_slug"]) ? addslashes($_SESSION["referral_agent_slug"]) : ""; ?>';
    
    let agentBanner = '';
    if (agentSlug) {
        agentBanner = `
            <div style="background:linear-gradient(135deg,#e0f2fe,#dbeafe);border:2px solid #0369a1;border-radius:12px;padding:16px;margin-bottom:20px;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="width:48px;height:48px;border-radius:50%;background:#0369a1;display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-user-tie" style="color:white;font-size:20px;"></i>
                    </div>
                    <div style="flex:1;">
                        <div style="font-weight:700;color:#0c4a6e;font-size:14px;margin-bottom:2px;">
                            <i class="fa-solid fa-check-circle" style="color:#059669;"></i> Sales Representative Assigned
                        </div>
                        <div style="font-size:12px;color:#075985;">
                            You'll be working with your agent after approval
                        </div>
                    </div>
                </div>
            </div>
        `;
    }
    
    return `
        <form action="<?php echo outbound_path('/required/registration.php'); ?>" method="post" novalidate>
            <input type="hidden" name="type" value="wholesale">
            ${agentSlug ? '<input type="hidden" name="referral_agent_slug" value="' + agentSlug + '">' : ''}
            <h3 style="margin:0 0 8px;font-size:20px;font-weight:800;color:var(--blue);">Wholesale Account Registration</h3>
            <p style="margin:0 0 20px;font-size:14px;color:#64748b;">Apply for wholesale pricing and bulk order access</p>
            ${agentBanner}
            <div style="display:grid; gap:14px;">
                <label>
                    <span style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a;">Business name</span>
                    <input name="business_name" autocomplete="organization" placeholder="Your business" required style="width:100%; padding:11px; border:1px solid var(--border); border-radius:10px; font-size:14px;">
                </label>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <label>
                        <span style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a;">First name</span>
                        <input name="first_name" autocomplete="given-name" placeholder="First" required style="width:100%; padding:11px; border:1px solid var(--border); border-radius:10px; font-size:14px;">
                    </label>
                    <label>
                        <span style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a;">Last name</span>
                        <input name="last_name" autocomplete="family-name" placeholder="Last" required style="width:100%; padding:11px; border:1px solid var(--border); border-radius:10px; font-size:14px;">
                    </label>
                </div>
                <label>
                    <span style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a;">Email</span>
                    <input type="email" name="email" autocomplete="email" placeholder="sample-002@example.com" required style="width:100%; padding:11px; border:1px solid var(--border); border-radius:10px; font-size:14px;">
                </label>
                <label>
                    <span style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a;">Phone</span>
                    <input name="phone" autocomplete="tel" placeholder="XXX-XXX-XXXX" required style="width:100%; padding:11px; border:1px solid var(--border); border-radius:10px; font-size:14px;">
                </label>
                <label>
                    <span style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a;">Password</span>
                    <input type="password" name="password" autocomplete="new-password" placeholder="Create a password" required style="width:100%; padding:11px; border:1px solid var(--border); border-radius:10px; font-size:14px;">
                </label>
            </div>
            <div style="margin-top:16px; padding:14px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px;">
                <label style="display:flex; align-items:start; gap:8px; cursor:pointer;">
                    <input type="checkbox" name="consent_email" value="1" checked required style="margin-top:2px;">
                    <span style="color:#0c4a6e;font-size:13px;">I consent to receive email communications from Product</span>
                </label>
            </div>
            <button class="btn" type="submit" style="width:100%; margin-top:18px; padding:13px; font-weight:700;">Request Wholesale Account</button>
        </form>
    `;
}

function getRetailForm() {
    return `
        <form action="<?php echo outbound_path('/required/registration.php'); ?>" method="post" novalidate>
            <input type="hidden" name="type" value="retail_customer">
            <h3 style="margin:0 0 8px;font-size:20px;font-weight:800;color:var(--blue);">Retail Customer Account</h3>
            <p style="margin:0 0 20px;font-size:14px;color:#64748b;">Shop premium Product at retail pricing</p>
            <div style="display:grid; gap:14px;">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <label>
                        <span style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a;">First name</span>
                        <input name="first_name" autocomplete="given-name" placeholder="First" required style="width:100%; padding:11px; border:1px solid var(--border); border-radius:10px; font-size:14px;">
                    </label>
                    <label>
                        <span style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a;">Last name</span>
                        <input name="last_name" autocomplete="family-name" placeholder="Last" required style="width:100%; padding:11px; border:1px solid var(--border); border-radius:10px; font-size:14px;">
                    </label>
                </div>
                <label>
                    <span style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a;">Email</span>
                    <input type="email" name="email" autocomplete="email" placeholder="sample-001@example.com" required style="width:100%; padding:11px; border:1px solid var(--border); border-radius:10px; font-size:14px;">
                </label>
                <label>
                    <span style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a;">Phone</span>
                    <input name="phone" autocomplete="tel" placeholder="XXX-XXX-XXXX" required style="width:100%; padding:11px; border:1px solid var(--border); border-radius:10px; font-size:14px;">
                </label>
                <label>
                    <span style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a;">Password</span>
                    <input type="password" name="password" autocomplete="new-password" placeholder="Create a password" required style="width:100%; padding:11px; border:1px solid var(--border); border-radius:10px; font-size:14px;">
                </label>
            </div>
            <div style="margin-top:16px; padding:14px; background:#ecfdf5; border:1px solid #a7f3d0; border-radius:8px;">
                <label style="display:flex; align-items:start; gap:8px; cursor:pointer;">
                    <input type="checkbox" name="consent_email" value="1" checked required style="margin-top:2px;">
                    <span style="color:#14532d;font-size:13px;">I consent to receive email communications from Product</span>
                </label>
            </div>
            <button class="btn" type="submit" style="width:100%; margin-top:18px; padding:13px; font-weight:700;background:#22c55e;">Create Retail Account</button>
        </form>
    `;
}

function getTraineeForm() {
    return `
        <form action="<?php echo outbound_path('/required/registration.php'); ?>" method="post" novalidate>
            <input type="hidden" name="type" value="trainee">
            <h3 style="margin:0 0 8px;font-size:20px;font-weight:800;color:var(--blue);">Sales Agent Certification</h3>
            <p style="margin:0 0 20px;font-size:14px;color:#64748b;">Join our sales team and earn commissions</p>
            <div style="display:grid; gap:14px;">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <label>
                        <span style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a;">First name</span>
                        <input name="first_name" autocomplete="given-name" placeholder="First" required style="width:100%; padding:11px; border:1px solid var(--border); border-radius:10px; font-size:14px;">
                    </label>
                    <label>
                        <span style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a;">Last name</span>
                        <input name="last_name" autocomplete="family-name" placeholder="Last" required style="width:100%; padding:11px; border:1px solid var(--border); border-radius:10px; font-size:14px;">
                    </label>
                </div>
                <label>
                    <span style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a;">Email</span>
                    <input type="email" name="email" autocomplete="email" placeholder="sample-001@example.com" required style="width:100%; padding:11px; border:1px solid var(--border); border-radius:10px; font-size:14px;">
                </label>
                <label>
                    <span style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a;">Phone</span>
                    <input name="phone" autocomplete="tel" placeholder="XXX-XXX-XXXX" required style="width:100%; padding:11px; border:1px solid var(--border); border-radius:10px; font-size:14px;">
                </label>
                <label>
                    <span style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a;">Password</span>
                    <input type="password" name="password" autocomplete="new-password" placeholder="Create a password" required style="width:100%; padding:11px; border:1px solid var(--border); border-radius:10px; font-size:14px;">
                </label>
            </div>
            <div style="margin-top:16px; padding:14px; background:#faf5ff; border:1px solid #e9d5ff; border-radius:8px;">
                <label style="display:flex; align-items:start; gap:8px; cursor:pointer;margin-bottom:10px;">
                    <input type="checkbox" name="consent_email" value="1" checked required style="margin-top:2px;">
                    <span style="color:#581c87;font-size:13px;">I consent to receive email communications from Product</span>
                </label>
                <label style="display:flex; align-items:start; gap:8px; cursor:pointer;">
                    <input type="checkbox" name="agree_terms" value="1" required style="margin-top:2px;">
                    <span style="color:#581c87;font-size:13px;">I agree to the Sales Agent Terms & Conditions</span>
                </label>
            </div>
            <button class="btn" type="submit" style="width:100%; margin-top:18px; padding:13px; font-weight:700;background:#a855f7;">Start Certification</button>
        </form>
    `;
}

function getInvestorForm() {
    return `
        <form action="<?php echo outbound_path('/required/registration.php'); ?>" method="post" novalidate>
            <input type="hidden" name="type" value="investor">
            <h3 style="margin:0 0 8px;font-size:20px;font-weight:800;color:var(--blue);">Investor Platform Access</h3>
            <p style="margin:0 0 20px;font-size:14px;color:#64748b;">Explore our proprietary All-In-One Industry Platform</p>
            <div style="display:grid; gap:14px;">
                <label>
                    <span style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a;">Company name (optional)</span>
                    <input name="company_name" autocomplete="organization" placeholder="Your firm or organization" style="width:100%; padding:11px; border:1px solid var(--border); border-radius:10px; font-size:14px;">
                </label>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <label>
                        <span style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a;">First name</span>
                        <input name="first_name" autocomplete="given-name" placeholder="First" required style="width:100%; padding:11px; border:1px solid var(--border); border-radius:10px; font-size:14px;">
                    </label>
                    <label>
                        <span style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a;">Last name</span>
                        <input name="last_name" autocomplete="family-name" placeholder="Last" required style="width:100%; padding:11px; border:1px solid var(--border); border-radius:10px; font-size:14px;">
                    </label>
                </div>
                <label>
                    <span style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a;">Email</span>
                    <input type="email" name="email" autocomplete="email" placeholder="sample-001@example.com" required style="width:100%; padding:11px; border:1px solid var(--border); border-radius:10px; font-size:14px;">
                </label>
                <label>
                    <span style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a;">Phone</span>
                    <input name="phone" autocomplete="tel" placeholder="XXX-XXX-XXXX" required style="width:100%; padding:11px; border:1px solid var(--border); border-radius:10px; font-size:14px;">
                </label>
                <label>
                    <span style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a;">Password</span>
                    <input type="password" name="password" autocomplete="new-password" placeholder="Create a password" required style="width:100%; padding:11px; border:1px solid var(--border); border-radius:10px; font-size:14px;">
                </label>
                <label>
                    <span style="display:block; margin-bottom:6px; font-weight:600; color:#0f172a;">Investment interest (optional)</span>
                    <textarea name="investment_interest" placeholder="What aspects interest you? (product, technology, market, etc.)" rows="3" style="width:100%; padding:11px; border:1px solid var(--border); border-radius:10px; font-size:14px;resize:vertical;"></textarea>
                </label>
            </div>
            <div style="margin-top:16px; padding:14px; background:#fffbeb; border:1px solid #fde68a; border-radius:8px;">
                <label style="display:flex; align-items:start; gap:8px; cursor:pointer;">
                    <input type="checkbox" name="consent_email" value="1" checked required style="margin-top:2px;">
                    <span style="color:#78350f;font-size:13px;">I consent to receive email communications. NDA required before platform access.</span>
                </label>
            </div>
            <button class="btn" type="submit" style="width:100%; margin-top:18px; padding:13px; font-weight:700;background:#f59e0b;">Request Investor Access</button>
        </form>
    `;
}

// Agreement Modal Viewer
function showAgreement(event, agreementType) {
    event.preventDefault();
    
    // Fetch agreement content from server
    fetch('<?php echo outbound_path('/api/get-agreement.php'); ?>?type=' + agreementType)
        .then(response => response.json())
        .then(data => {
            if (!data.ok) {
                alert('Could not load agreement. Please try again.');
                return;
            }
            
            // Create modal
            const modal = document.createElement('div');
            modal.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.75);z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px;overflow-y:auto;';
            
            const modalContent = document.createElement('div');
            modalContent.style.cssText = 'background:white;border-radius:12px;max-width:800px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);';
            
            const modalHeader = document.createElement('div');
            modalHeader.style.cssText = 'padding:24px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;';
            modalHeader.innerHTML = '<h3 style="margin:0;font-size:24px;font-weight:700;color:#1f2937;">' + data.title + '</h3><button onclick="this.closest(\'.agreement-modal\').remove()" style="background:none;border:none;font-size:28px;color:#6b7280;cursor:pointer;padding:0;width:32px;height:32px;">×</button>';
            
            const modalBody = document.createElement('div');
            modalBody.style.cssText = 'padding:32px;line-height:1.6;';
            modalBody.innerHTML = data.content;
            
            const modalFooter = document.createElement('div');
            modalFooter.style.cssText = 'padding:24px;border-top:1px solid #e5e7eb;text-align:right;';
            modalFooter.innerHTML = '<button onclick="this.closest(\'.agreement-modal\').remove()" style="padding:10px 20px;border-radius:8px;font-size:14px;font-weight:600;background:#f3f4f6;color:#374151;border:none;cursor:pointer;">Close</button>';
            
            modalContent.appendChild(modalHeader);
            modalContent.appendChild(modalBody);
            modalContent.appendChild(modalFooter);
            modal.appendChild(modalContent);
            modal.className = 'agreement-modal';
            
            document.body.appendChild(modal);
            
            // Close on outside click
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.remove();
                }
            });
        })
        .catch(error => {
            console.error('Error loading agreement:', error);
            alert('Could not load agreement. Please try again.');
        });
}
</script>

<?php require_once __DIR__ . '/required/footer.php'; ?>
