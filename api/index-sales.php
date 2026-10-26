<?php
$SEO = [
  'title' => 'Product — catalog-ready attribute Formula | $15K+ Monthly Sales Opportunity',
  'description' => 'Join the $7.8B product revolution. Sell catalog-ready product with 400-800% markup potential. Earn $5,000-$15,000+ monthly as certified sales agent.',
  'type' => 'website',
  'image' => '/assets/og-default.png',
  'schema' => [
    '@context' => 'https://schema.org',
    '@type' => 'Organization',
    'name' => 'Product',
    'url' => (isset($_SERVER['HTTP_HOST']) ? 'https://' . $_SERVER['HTTP_HOST'] : ''),
    'logo' => '/assets/logo.svg'
  ]
];
require_once __DIR__ . '/required/header.php';
?>

<style>
.hero-sales {
    background: linear-gradient(135deg, #4a0b4a, #11406f);
    color: white;
    padding: 80px 20px;
    text-align: center;
    position: relative;
    overflow: hidden;
}
.hero-sales h1 {
    color: white;
    font-size: 48px;
    margin-bottom: 24px;
    font-weight: 800;
}
.market-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 20px;
    max-width: 800px;
    margin: 40px auto;
}
.stat-box {
    background: rgba(255,255,255,0.1);
    padding: 20px;
    border-radius: 12px;
    backdrop-filter: blur(10px);
}
.stat-box h3 {
    color: #fbbf24;
    font-size: 36px;
    margin: 0;
}
.stat-box p {
    color: #e0e0e0;
    margin: 8px 0 0;
}
.product-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin: 40px 0;
}
.product-card {
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 20px;
    transition: all 0.3s ease;
}
.product-card:hover {
    border-color: #f59e0b;
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.1);
}
.price-tag {
    font-size: 24px;
    font-weight: bold;
    color: #059669;
}
.roi-tag {
    background: #fef3c7;
    color: #92400e;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: bold;
}
.benefit-card {
    background: linear-gradient(135deg, var(--start-color), var(--end-color));
    border: 2px solid var(--border-color);
    border-radius: 12px;
    padding: 24px;
    height: 100%;
}
</style>

<section class="hero-sales">
    <div class="container">
        <?php if (!empty($_GET['err'])): ?>
            <div class="card" role="alert" style="background: rgba(255,255,255,0.95); color: #dc2626; margin-bottom: 24px;">
                <?php if ($_GET['err']==='dup'): ?>
                    That email already exists. Please log in or use a different email.
                <?php else: ?>
                    We couldn't process your request. Please try again.
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <h1>🚀 Capitalize on the $7.8 Billion Product Boom</h1>
        <p style="font-size: 24px; color: #fbbf24; margin-bottom: 8px;">
            catalog-ready attribute Formula • Zero Bitter Taste • 400-800% Markup
        </p>
        <p style="font-size: 18px; color: #e0e0e0;">
            Join the product revolution as a wholesale partner or certified sales agent
        </p>
        
        <div class="market-stats">
            <div class="stat-box">
                <h3>$7.8B</h3>
                <p>Market by 2032</p>
            </div>
            <div class="stat-box">
                <h3>172%</h3>
                <p>Annual Growth</p>
            </div>
            <div class="stat-box">
                <h3>15M+</h3>
                <p>US Users</p>
            </div>
            <div class="stat-box">
                <h3>400%</h3>
                <p>Higher Retention</p>
            </div>
        </div>
    </div>
</section>

<section class="section" style="background: #f9fafb;">
    <div class="container">
        <h2 style="text-align: center; font-size: 36px; margin-bottom: 16px;">
            💰 Our Premium Product Portfolio
        </h2>
        <p style="text-align: center; color: #6b7280; margin-bottom: 40px;">
            catalog-ready product that eliminates bitter product format taste
        </p>
        
        <div class="product-grid">
            <div class="product-card">
                <span class="roi-tag">BEST FOR STARTERS</span>
                <h3 style="color: #1e40af; margin-top: 12px;">Regular Formula 32oz</h3>
                <p class="price-tag">$59.99 wholesale</p>
                <p style="color: #6b7280;">Retail: $149.99</p>
                <ul style="margin: 16px 0;">
                    <li>32-64 drinks per bottle</li>
                    <li>Perfect dosing control</li>
                    <li>$0.94-$1.87 per serving</li>
                </ul>
                <p style="background: #dcfce7; padding: 8px; border-radius: 4px; text-align: center;">
                    <strong>Profit: $90 per bottle</strong>
                </p>
            </div>
            
            <div class="product-card">
                <span class="roi-tag">HIGH VOLUME</span>
                <h3 style="color: #1e40af; margin-top: 12px;">Regular Formula 128oz</h3>
                <p class="price-tag">$199.99 wholesale</p>
                <p style="color: #6b7280;">Retail: $499.99</p>
                <ul style="margin: 16px 0;">
                    <li>128-256 drinks per bottle</li>
                    <li>Best value per ounce</li>
                    <li>$0.78-$1.56 per serving</li>
                </ul>
                <p style="background: #dcfce7; padding: 8px; border-radius: 4px; text-align: center;">
                    <strong>Profit: $300 per bottle</strong>
                </p>
            </div>
            
            <div class="product-card">
                <span class="roi-tag">MAXIMUM STRENGTH</span>
                <h3 style="color: #1e40af; margin-top: 12px;">High-product specification 32oz</h3>
                <p class="price-tag">$89.99 wholesale</p>
                <p style="color: #6b7280;">Retail: $199.99</p>
                <ul style="margin: 16px 0;">
                    <li>Maximum attribute concentration</li>
                    <li>For experienced users</li>
                    <li>Premium positioning</li>
                </ul>
                <p style="background: #dcfce7; padding: 8px; border-radius: 4px; text-align: center;">
                    <strong>Profit: $110 per bottle</strong>
                </p>
            </div>
            
            <div class="product-card">
                <span class="roi-tag">COMMERCIAL GRADE</span>
                <h3 style="color: #1e40af; margin-top: 12px;">High-product specification 128oz</h3>
                <p class="price-tag">$299.99 wholesale</p>
                <p style="color: #6b7280;">Retail: $699.99</p>
                <ul style="margin: 16px 0;">
                    <li>For high-volume bars</li>
                    <li>Maximum profitability</li>
                    <li>Lowest cost per dose</li>
                </ul>
                <p style="background: #dcfce7; padding: 8px; border-radius: 4px; text-align: center;">
                    <strong>Profit: $400 per bottle</strong>
                </p>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <h2 style="text-align: center; font-size: 36px; margin-bottom: 40px;">
            Why Bars & Retailers Choose Our product Over product format
        </h2>
        
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px;">
            <div class="benefit-card" style="--start-color: #fee2e2; --end-color: #fecaca; --border-color: #dc2626;">
                <h3 style="color: #dc2626;">❌ Traditional product format Problems</h3>
                <ul style="color: #7f1d1d;">
                    <li>Extremely bitter, unpalatable taste</li>
                    <li>Batch-to-batch quality variations</li>
                    <li>Poor product specification - most product attribute wasted</li>
                    <li>Inconsistent product specification and quality</li>
                    <li>Customers avoid repeat purchases</li>
                    <li>5+ minutes preparation time</li>
                    <li>Measuring errors and waste</li>
                </ul>
            </div>
            
            <div class="benefit-card" style="--start-color: #dcfce7; --end-color: #bbf7d0; --border-color: #10b981;">
                <h3 style="color: #14532d;">✅ Our Clean product Solution</h3>
                <ul style="color: #14532d;">
                    <li>Completely tasteless with zero bitterness</li>
                    <li>Superior product specification - instant absorption</li>
                    <li>catalog-ready attribute concentration</li>
                    <li>Ready-to-use liquid - no product format mess</li>
                    <li>Customers become loyal repeat buyers</li>
                    <li>30-second serving time</li>
                    <li>Perfect dosing every time</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<section class="section" style="background: linear-gradient(135deg, #1e3a8a, #1e40af); color: white; padding: 80px 20px;">
    <div class="container">
        <h2 style="color: white; text-align: center; font-size: 36px; margin-bottom: 40px;">
            Choose Your Path to Success
        </h2>
        
        <div class="grid two" style="gap: 32px;">
            <div class="card" style="background: white; color: #111;">
                <h3 style="color: #f59e0b; font-size: 28px;">
                    🏪 Wholesale Partner
                </h3>
                <p style="font-size: 18px; margin: 16px 0;">
                    For product bars, cafes, and retailers
                </p>
                
                <div style="background: #fef3c7; padding: 16px; border-radius: 8px; margin: 20px 0;">
                    <h4 style="color: #92400e; margin-top: 0;">Your Profit Potential:</h4>
                    <ul style="color: #78350f;">
                        <li><strong>$1.87 cost</strong> → $10-20 retail price</li>
                        <li><strong>400-800% markup</strong> on every drink</li>
                        <li><strong>$800-1,200 daily</strong> profit increase</li>
                        <li><strong>Zero prep time</strong> = more sales</li>
                    </ul>
                </div>
                
                <form action="/required/registration.php" method="post" novalidate>
                    <input type="hidden" name="type" value="wholesale">
                    <h4>Quick Application</h4>
                    <div class="form-grid">
                        <label>
                            <span>Business Name</span>
                            <input name="business_name" required>
                        </label>
                        <label>
                            <span>Contact Name</span>
                            <input name="first_name" placeholder="First" required>
                        </label>
                        <label>
                            <span>&nbsp;</span>
                            <input name="last_name" placeholder="Last" required>
                        </label>
                        <label>
                            <span>Email</span>
                            <input type="email" name="email" required>
                        </label>
                        <label>
                            <span>Phone</span>
                            <input name="phone" required>
                        </label>
                        <label>
                            <span>Password</span>
                            <input type="password" name="password" required>
                        </label>
                    </div>
                    <button class="btn" type="submit" style="width: 100%; background: #f59e0b; color: white;">
                        Apply for Wholesale Account
                    </button>
                </form>
            </div>
            
            <div class="card" style="background: white; color: #111;">
                <h3 style="color: #10b981; font-size: 28px;">
                    🎓 Sales Agent Training
                </h3>
                <p style="font-size: 18px; margin: 16px 0;">
                    Become certified and earn big commissions
                </p>
                
                <div style="background: #dcfce7; padding: 16px; border-radius: 8px; margin: 20px 0;">
                    <h4 style="color: #14532d; margin-top: 0;">Commission Structure:</h4>
                    <ul style="color: #166534;">
                        <li><strong>Bronze:</strong> $1,000/mo (10% on $10K)</li>
                        <li><strong>Silver:</strong> $3,750/mo (15% on $25K)</li>
                        <li><strong>Gold:</strong> $10,000/mo (20% on $50K)</li>
                        <li><strong>Platinum:</strong> $15,000+/mo (25% on $60K+)</li>
                    </ul>
                    <p style="color: #dc2626; font-weight: bold; margin-top: 12px;">
                        🤖 AI-assisted selling doubles your volume!
                    </p>
                </div>
                
                <form action="/required/registration.php" method="post" novalidate>
                    <input type="hidden" name="type" value="trainee">
                    <h4>Start Your Training</h4>
                    <div class="form-grid">
                        <label>
                            <span>First Name</span>
                            <input name="first_name" required>
                        </label>
                        <label>
                            <span>Last Name</span>
                            <input name="last_name" required>
                        </label>
                        <label>
                            <span>Email</span>
                            <input type="email" name="email" required>
                        </label>
                        <label>
                            <span>Phone</span>
                            <input name="phone" required>
                        </label>
                        <label>
                            <span>Password</span>
                            <input type="password" name="password" required>
                        </label>
                    </div>
                    <button class="btn" type="submit" style="width: 100%; background: #10b981; color: white;">
                        Begin Sales Agent Training
                    </button>
                </form>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <h2 style="text-align: center; font-size: 36px; margin-bottom: 40px;">
            🏆 Real Success Stories from Product Bar Owners
        </h2>
        
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px;">
            <div class="card" style="border-left: 4px solid #f59e0b;">
                <h4 style="color: #1e40af;">Mike Johnson - Urban Leaf, Atlanta</h4>
                <p style="color: #6b7280; font-style: italic;">
                    "Switched to the catalog-ready product and saw immediate improvements. Revenue up 260% and complaints practically zero!"
                </p>
                <div style="color: #f59e0b;">⭐⭐⭐⭐⭐</div>
            </div>
            
            <div class="card" style="border-left: 4px solid #10b981;">
                <h4 style="color: #1e40af;">Sarah Rodriguez - Botanical Wellness, Miami</h4>
                <p style="color: #6b7280; font-style: italic;">
                    "The product specification difference is incredible. My product sales tripled in the first quarter."
                </p>
                <div style="color: #f59e0b;">⭐⭐⭐⭐⭐</div>
            </div>
            
            <div class="card" style="border-left: 4px solid #3b82f6;">
                <h4 style="color: #1e40af;">David Thompson - Natural Remedies, Nashville</h4>
                <p style="color: #6b7280; font-style: italic;">
                    "Finally a Product that customers request by name. Increased our overall customer retention 400%."
                </p>
                <div style="color: #f59e0b;">⭐⭐⭐⭐⭐</div>
            </div>
        </div>
    </div>
</section>

<section class="section" style="background: #f9fafb; text-align: center; padding: 60px 20px;">
    <div class="container">
        <h2 style="font-size: 36px; margin-bottom: 24px;">
            Ready to Transform Your Product Business?
        </h2>
        <p style="font-size: 20px; color: #6b7280; margin-bottom: 32px;">
            Join the product revolution - bars using our product report 400% higher customer retention<br>
            and double their product revenue
        </p>
        <div style="display: flex; gap: 20px; justify-content: center; flex-wrap: wrap;">
            <a href="#" class="btn" style="background: #f59e0b; color: white; padding: 16px 32px; font-size: 18px;">
                Apply for Wholesale
            </a>
            <a href="#" class="btn" style="background: #10b981; color: white; padding: 16px 32px; font-size: 18px;">
                Start Sales Training
            </a>
        </div>
        
        <p style="margin-top: 32px; color: #6b7280;">
            Free consultation • No setup fees • 30-day trial period
        </p>
    </div>
</section>

<?php require_once __DIR__ . '/required/footer.php'; ?>
