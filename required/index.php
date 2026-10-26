<?php
// Load config FIRST so helpers like site_origin()/outbound_path() exist
require_once __DIR__ . '/required/config.php';

$SEO = [
  'title' => 'Product — Wholesale Product Supply | Enterprise Fulfillment',
  'description' => 'Wholesale product done right: vetted sourcing, compliance-first operations, and a high-performance sales ACP. Apply for wholesale or join as a sales agent.',
  'type' => 'website',
  'image' => outbound_path('/assets/og-default.png'),
  'schema' => [
    '@context' => 'https://schema.org',
    '@type' => 'Organization',
    'name' => 'Product',
    'url' => site_origin(),
    'logo' => outbound_path('/assets/logo.svg')
  ]
];
// If a user is logged in, keep them in their role home instead of the marketing page
if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($_GET['preview'])) {
    $uid = current_user_id();
    if ($uid) { redirect(user_dashboard_path($uid)); }
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

<section class="hero" aria-labelledby="hero-title">
    <div class="hero-bg" data-parallax data-speed="0.15" aria-hidden="true"></div>
    <div class="container">
        <?php if (!empty($_GET['err'])): ?>
            <div class="card" role="alert" aria-live="polite" style="border-color:#ffb3b3;color:#8a1f1f;background:#fff5f5;margin-bottom:12px">
                <?php if ($_GET['err']==='dup'): ?>
                    That email already exists. Please log in or use a different email.
                <?php else: ?>
                    We couldn't process your request. Please try again.
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <!-- Brand Header -->
        <div style="text-align:center; margin-bottom:48px;">
            <div style="display:flex; justify-content:center; gap:10px; align-items:center; color:var(--blue-2); font-weight:600; margin-bottom:16px;">
                <span style="width:8px;height:8px;background:#10b981;border-radius:50%;display:inline-block;"></span>
                <span>Third-Party Tested</span>
                <span>•</span>
                <span>AKA GMP Standards</span>
                <span>•</span>
                <span>99.9% quality information Guaranteed</span>
                <span style="width:8px;height:8px;background:#10b981;border-radius:50%;display:inline-block;"></span>
            </div>
            <div class="kicker-cap" style="margin-bottom:12px;">AI‑Powered Wholesale Engine</div>
            <h1 id="hero-title" style="font-size:48px; line-height:1.1; margin:0 0 16px; color:var(--blue); font-weight:800;">Product‑It Sales Platform</h1>
            <p style="color:#334155; max-width:700px; margin:0 auto; font-size:20px; line-height:1.5;">The world's most advanced lab‑grade, compliance‑first platform with AI‑assisted sales tools and precision‑dosed Product SKUs.</p>
        </div>

        <!-- World-Class Hero Content Grid -->
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:48px; align-items:start; margin-bottom:40px;" class="hero-grid">
            
            <!-- Left Side: Key Points & Value Props -->
            <div class="hero-content">
                <div style="margin-bottom:32px;">
                    <h2 style="color:var(--blue); font-size:28px; margin:0 0 16px; font-weight:700;">Why Industry Leaders Choose Product</h2>
                    
                    <div class="value-props" style="display:grid; gap:20px;">
                        <div class="value-prop" style="display:flex; gap:16px; align-items:flex-start;">
                            <div style="width:48px; height:48px; background:linear-gradient(135deg, var(--blue-2), var(--blue)); border-radius:12px; display:flex; align-items:center; justify-content:center; color:#fff; font-size:20px; flex-shrink:0;">🧬</div>
                            <div>
                                <h3 style="margin:0 0 8px; color:var(--blue); font-size:18px; font-weight:600;">Superior product specification</h3>
                                <p style="margin:0; color:#475569; line-height:1.6;">Instant absorption and maximum effectiveness with a smooth, consistent experience every pour. Our liquid formulation delivers 3x faster onset than traditional product format.</p>
                            </div>
                        </div>
                        
                        <div class="value-prop" style="display:flex; gap:16px; align-items:flex-start;">
                            <div style="width:48px; height:48px; background:linear-gradient(135deg, var(--gold), #f59e0b); border-radius:12px; display:flex; align-items:center; justify-content:center; color:#000; font-size:20px; flex-shrink:0;">💰</div>
                            <div>
                                <h3 style="margin:0 0 8px; color:var(--blue); font-size:18px; font-weight:600;">Exceptional Profit Margins</h3>
                                <p style="margin:0; color:#475569; line-height:1.6;">High-margin products with clear dosing and quick service—built for busy bars and cafés. Average 400% markup with premium positioning.</p>
                            </div>
                        </div>
                        
                        <div class="value-prop" style="display:flex; gap:16px; align-items:flex-start;">
                            <div style="width:48px; height:48px; background:linear-gradient(135deg, var(--green), #059669); border-radius:12px; display:flex; align-items:center; justify-content:center; color:#fff; font-size:20px; flex-shrink:0;">🏆</div>
                            <div>
                                <h3 style="margin:0 0 8px; color:var(--blue); font-size:18px; font-weight:600;">Industry-Leading Standards</h3>
                                <p style="margin:0; color:#475569; line-height:1.6;">Third‑party tested, AKA GMP practices, and documented chain of custody from source to shelf. Zero compromise on quality or compliance.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Market Stats -->
                <div class="market-stats" style="background:linear-gradient(135deg, #f0f9ff, #e0f2fe); border:1px solid #bae6fd; border-radius:16px; padding:24px; margin-bottom:24px;">
                    <h4 style="margin:0 0 16px; color:var(--blue); font-size:16px; font-weight:600; text-align:center;">🚀 Explosive Market Growth</h4>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; text-align:center;">
                        <div>
                            <div style="font-size:24px; font-weight:800; color:var(--blue-2);">$2.19B</div>
                            <div style="font-size:12px; color:var(--muted);">2024 Market Size</div>
                        </div>
                        <div>
                            <div style="font-size:24px; font-weight:800; color:var(--green);">$7.80B</div>
                            <div style="font-size:12px; color:var(--muted);">2032 Projected</div>
                        </div>
                    </div>
                    <div style="text-align:center; margin-top:12px;">
                        <span style="background:var(--gold); color:#000; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600;">17.2% CAGR</span>
                    </div>
                </div>

            </div>

            <!-- Right Side: Login/Register -->
            <div class="hero-auth">
                <div class="card" style="background:#ffffff; box-shadow:0 25px 70px rgba(2,6,23,.15); border:2px solid var(--border);">
                    <!-- Tab Navigation -->
                    <div role="tablist" aria-label="Access Tabs" style="display:flex; gap:4px; background:#f8fafc; padding:6px; border-radius:12px; margin-bottom:24px;">
                        <button id="main-tab-login" role="tab" aria-selected="true" aria-controls="main-panel-login" class="btn" style="flex:1; border-radius:8px; font-size:14px; padding:12px; margin:0;">Login</button>
                        <button id="main-tab-register" role="tab" aria-selected="false" aria-controls="main-panel-register" class="btn secondary" style="flex:1; border-radius:8px; font-size:14px; padding:12px; margin:0;">Register</button>
                    </div>

                    <!-- Login Panel -->
                    <div id="main-panel-login" role="tabpanel" aria-labelledby="main-tab-login">
                        <h3 style="margin:0 0 8px; color:var(--blue); text-align:center; font-size:22px;">Welcome Back</h3>
                        <p style="text-align:center; color:var(--muted); margin:0 0 24px; font-size:14px;">Login to access your account</p>
                        
                        <form action="<?php echo outbound_path('/login.php'); ?>" method="post" novalidate>
                            <label style="margin-bottom:16px; display:block;">
                                <span style="font-weight:600; color:var(--text); display:block; margin-bottom:6px;">Email</span>
                                <input type="email" name="email" autocomplete="email" placeholder="Enter your email" required style="width:100%; padding:12px; border:1px solid var(--border); border-radius:8px; font-size:14px;">
                            </label>
                            <label style="margin-bottom:24px; display:block;">
                                <span style="font-weight:600; color:var(--text); display:block; margin-bottom:6px;">Password</span>
                                <input type="password" name="password" autocomplete="current-password" placeholder="Enter your password" required style="width:100%; padding:12px; border:1px solid var(--border); border-radius:8px; font-size:14px;">
                            </label>
                            <button class="btn" type="submit" style="width:100%; margin:0; padding:14px; font-size:16px; font-weight:600;">Login to Platform</button>
                        </form>
                    </div>

                    <!-- Register Panel -->
                    <div id="main-panel-register" role="tabpanel" aria-labelledby="main-tab-register" hidden>
                        <h3 style="margin:0 0 8px; color:var(--blue); text-align:center; font-size:22px;">Join Product</h3>
                        <p style="text-align:center; color:var(--muted); margin:0 0 20px; font-size:14px;">Choose your path to success</p>
                        
                        <!-- Registration Type Selection -->
                        <div style="display:flex; gap:8px; margin-bottom:20px;">
                            <button id="reg-wholesale-btn" class="btn" style="flex:1; font-size:12px; padding:8px; margin:0;">Apply For Wholesale</button>
                            <button id="reg-agent-btn" class="btn secondary" style="flex:1; font-size:12px; padding:8px; margin:0;">Sell Product!</button>
                        </div>

                        <!-- Wholesale Registration Form -->
                        <div id="wholesale-form" class="registration-form">
                            <form action="<?php echo outbound_path('/required/registration.php'); ?>" method="post" novalidate>
                                <input type="hidden" name="type" value="wholesale">
                                <div style="display:grid; gap:12px;">
                                    <label>
                                        <span style="font-weight:600; color:var(--text); display:block; margin-bottom:6px;">Business Name</span>
                                        <input name="business_name" autocomplete="organization" placeholder="Your Business Name" required style="width:100%; padding:10px; border:1px solid var(--border); border-radius:6px; font-size:13px;">
                                    </label>
                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                                        <label>
                                            <span style="font-weight:600; color:var(--text); display:block; margin-bottom:6px;">First Name</span>
                                            <input name="first_name" autocomplete="given-name" placeholder="First Name" required style="width:100%; padding:10px; border:1px solid var(--border); border-radius:6px; font-size:13px;">
                                        </label>
                                        <label>
                                            <span style="font-weight:600; color:var(--text); display:block; margin-bottom:6px;">Last Name</span>
                                            <input name="last_name" autocomplete="family-name" placeholder="Last Name" required style="width:100%; padding:10px; border:1px solid var(--border); border-radius:6px; font-size:13px;">
                                        </label>
                                    </div>
                                    <label>
                                        <span style="font-weight:600; color:var(--text); display:block; margin-bottom:6px;">Email</span>
                                        <input type="email" name="email" autocomplete="email" placeholder="sample-002@example.com" required style="width:100%; padding:10px; border:1px solid var(--border); border-radius:6px; font-size:13px;">
                                    </label>
                                    <label>
                                        <span style="font-weight:600; color:var(--text); display:block; margin-bottom:6px;">Phone</span>
                                        <input name="phone" autocomplete="tel" placeholder="XXX-XXX-XXXX" required style="width:100%; padding:10px; border:1px solid var(--border); border-radius:6px; font-size:13px;">
                                    </label>
                                    <label>
                                        <span style="font-weight:600; color:var(--text); display:block; margin-bottom:6px;">Password</span>
                                        <input type="password" name="password" autocomplete="new-password" placeholder="Create a password" required style="width:100%; padding:10px; border:1px solid var(--border); border-radius:6px; font-size:13px;">
                                    </label>
                                </div>
                                <button class="btn" type="submit" style="width:100%; margin-top:16px; padding:12px; font-weight:600;">Apply for Wholesale</button>
                            </form>
                        </div>

                        <!-- Sales Agent Registration Form -->
                        <div id="agent-form" class="registration-form" hidden>
                            <p style="margin:0 0 16px; font-size:12px; color:var(--muted); text-align:center; background:#fef3c7; padding:8px; border-radius:6px;">Complete 4-hour certification and unlock the Sales ACP</p>
                            <form action="<?php echo outbound_path('/required/registration.php'); ?>" method="post" novalidate>
                                <input type="hidden" name="type" value="trainee">
                                <div style="display:grid; gap:12px;">
                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                                        <label>
                                            <span style="font-weight:600; color:var(--text); display:block; margin-bottom:6px;">First Name</span>
                                            <input name="first_name" placeholder="First Name" required style="width:100%; padding:10px; border:1px solid var(--border); border-radius:6px; font-size:13px;">
                                        </label>
                                        <label>
                                            <span style="font-weight:600; color:var(--text); display:block; margin-bottom:6px;">Last Name</span>
                                            <input name="last_name" placeholder="Last Name" required style="width:100%; padding:10px; border:1px solid var(--border); border-radius:6px; font-size:13px;">
                                        </label>
                                    </div>
                                    <label>
                                        <span style="font-weight:600; color:var(--text); display:block; margin-bottom:6px;">Email</span>
                                        <input type="email" name="email" placeholder="sample-016@example.com" required style="width:100%; padding:10px; border:1px solid var(--border); border-radius:6px; font-size:13px;">
                                    </label>
                                    <label>
                                        <span style="font-weight:600; color:var(--text); display:block; margin-bottom:6px;">Phone</span>
                                        <input name="phone" placeholder="XXX-XXX-XXXX" required style="width:100%; padding:10px; border:1px solid var(--border); border-radius:6px; font-size:13px;">
                                    </label>
                                    <label>
                                        <span style="font-weight:600; color:var(--text); display:block; margin-bottom:6px;">Password</span>
                                        <input type="password" name="password" placeholder="Create a password" required style="width:100%; padding:10px; border:1px solid var(--border); border-radius:6px; font-size:13px;">
                                    </label>
                                </div>
                                <button class="btn" type="submit" style="width:100%; margin-top:16px; padding:12px; background:var(--gold); color:#000; font-weight:600;">Start Training Program</button>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
    <div class="hero-fg" data-parallax data-speed="0.05" aria-hidden="true"></div>
</section>

<section class="section" aria-labelledby="features-title">
    <div class="container">
        <div class="section-header">
            <div class="kicker-cap">Outcomes</div>
            <h2 id="features-title">🤖 AI‑Powered Sales, Real‑World Results</h2>
            <p>Automate research, objections, and follow‑ups so your team closes more in a market scaling from $2.2B to $7.8B.</p>
        </div>
        <div class="features" role="list">
            <div class="feature" role="listitem">
                <div class="kicker">product specification</div>
                <h3>Superior product specification</h3>
                <p>Instant absorption and maximum effectiveness with a smooth, consistent experience every pour.</p>
            </div>
            <div class="feature" role="listitem">
                <div class="kicker">Opportunity</div>
                <h3>Exceptional Sales Opportunity</h3>
                <p>High margins with clear dosing and quick service—built for busy bars and cafés.</p>
            </div>
            <div class="feature" role="listitem">
                <div class="kicker">Standards</div>
                <h3>Cleanest Processing Standards</h3>
                <p>Third‑party tested, AKA GMP practices, and documented chain of custody from source to shelf.</p>
            </div>
        </div>
    </div>
</section>

<section class="section" aria-labelledby="how-title">
    <div class="container">
        <div class="section-header">
            <div class="kicker-cap">How it works</div>
            <h2 id="how-title">From Apply → Train → Launch</h2>
            <p>We compress onboarding into hours, not weeks, with automated account setup and AI workflows.</p>
        </div>
        <div class="steps">
            <div class="step"><div class="num">1</div><h3>Apply</h3><p>Submit your wholesale profile or register as a sales agent.</p></div>
            <div class="step"><div class="num">2</div><h3>Certify</h3><p>Complete a focused 4‑hour program to unlock the ACP toolkit.</p></div>
            <div class="step"><div class="num">3</div><h3>Launch</h3><p>Access precision‑dosed SKUs, compliant materials, and AI follow‑ups out of the box.</p></div>
        </div>
    </div>
</section>

<section class="section" aria-labelledby="cta-title">
    <div class="container">
        <div class="cta-band">
            <h3 id="cta-title">Ready to get started?</h3>
            <p>Open a wholesale account or join the sales team. We’ll handle the heavy lifting.</p>
            <div class="cta-actions">
                <a class="btn" href="#hero-title">Apply for Wholesale</a>
                <a class="btn secondary" href="#hero-title">Become a Sales Agent</a>
            </div>
        </div>
    </div>
</section>



<script>
// World-Class Hero Functionality
document.addEventListener('DOMContentLoaded', function() {
    // Main tabs (Login/Register)
    const mainLoginTab = document.getElementById('main-tab-login');
    const mainRegisterTab = document.getElementById('main-tab-register');
    const mainLoginPanel = document.getElementById('main-panel-login');
    const mainRegisterPanel = document.getElementById('main-panel-register');

    function switchMainTab(activeTab, activePanel, inactiveTab, inactivePanel) {
        // Update tab states
        activeTab.setAttribute('aria-selected', 'true');
        activeTab.classList.remove('secondary');
        inactiveTab.setAttribute('aria-selected', 'false');
        inactiveTab.classList.add('secondary');
        
        // Update panel visibility
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

    // Registration type selection (Wholesale vs Sales Agent)
    const regWholesaleBtn = document.getElementById('reg-wholesale-btn');
    const regAgentBtn = document.getElementById('reg-agent-btn');
    const wholesaleForm = document.getElementById('wholesale-form');
    const agentForm = document.getElementById('agent-form');

    function switchRegType(activeBtn, activeForm, inactiveBtn, inactiveForm) {
        // Update button states
        activeBtn.classList.remove('secondary');
        inactiveBtn.classList.add('secondary');
        
        // Update form visibility
        activeForm.hidden = false;
        inactiveForm.hidden = true;
    }

    if (regWholesaleBtn && regAgentBtn) {
        regWholesaleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            switchRegType(regWholesaleBtn, wholesaleForm, regAgentBtn, agentForm);
        });

        regAgentBtn.addEventListener('click', function(e) {
            e.preventDefault();
            switchRegType(regAgentBtn, agentForm, regWholesaleBtn, wholesaleForm);
        });
    }

    // Protected Access Accordion Functionality
    const accordionTrigger = document.getElementById('accordion-trigger');
    const accordionContent = document.getElementById('accordion-content');
    const accordionIcon = document.getElementById('accordion-icon');
    let isAccordionOpen = false;

    // Check if accordion should be open on load (when unlocked)
    const isUnlocked = accordionContent && accordionContent.style.maxHeight !== '0px' && accordionContent.style.maxHeight !== '';
    if (isUnlocked) {
        isAccordionOpen = true;
        if (accordionIcon) accordionIcon.style.transform = 'rotate(180deg)';
        if (accordionTrigger) accordionTrigger.style.borderRadius = '12px 12px 0 0';
    }

    function toggleAccordion() {
        isAccordionOpen = !isAccordionOpen;
        
        if (isAccordionOpen) {
            accordionContent.style.maxHeight = accordionContent.scrollHeight + 'px';
            accordionIcon.style.transform = 'rotate(180deg)';
            accordionTrigger.style.borderRadius = '12px 12px 0 0';
        } else {
            accordionContent.style.maxHeight = '0';
            accordionIcon.style.transform = 'rotate(0deg)';
            accordionTrigger.style.borderRadius = '12px';
        }
    }

    if (accordionTrigger) {
        accordionTrigger.addEventListener('click', toggleAccordion);
    }

    // Add hover effects to role buttons in accordion
    const roleBtns = document.querySelectorAll('.role-btn');
    roleBtns.forEach(btn => {
        btn.addEventListener('mouseenter', function() {
            this.style.background = 'var(--blue-2)';
            this.style.color = '#ffffff';
            this.style.transform = 'translateY(-1px)';
            this.style.boxShadow = '0 4px 12px rgba(37, 99, 235, 0.2)';
        });
        
        btn.addEventListener('mouseleave', function() {
            this.style.background = '#f8fafc';
            this.style.color = 'var(--blue)';
            this.style.transform = 'translateY(0)';
            this.style.boxShadow = 'none';
        });
    });

    // Add subtle animation to value props
    const valueProps = document.querySelectorAll('.value-prop');
    valueProps.forEach((prop, index) => {
        prop.style.opacity = '0';
        prop.style.transform = 'translateY(20px)';
        prop.style.transition = 'all 0.6s ease';
        
        setTimeout(() => {
            prop.style.opacity = '1';
            prop.style.transform = 'translateY(0)';
        }, 200 + (index * 150));
    });

    // Responsive grid handling
    function handleResize() {
        const heroGrid = document.querySelector('.hero-grid');
        if (window.innerWidth < 768) {
            heroGrid.style.gridTemplateColumns = '1fr';
            heroGrid.style.gap = '32px';
        } else {
            heroGrid.style.gridTemplateColumns = '1fr 1fr';
            heroGrid.style.gap = '48px';
        }
    }

    window.addEventListener('resize', handleResize);
    handleResize(); // Initial call
});
</script>

<?php require_once __DIR__ . '/required/footer.php'; ?>
