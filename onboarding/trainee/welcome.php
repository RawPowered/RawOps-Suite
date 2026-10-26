<?php 
$SEO = [
    'title' => 'Welcome to Your Sales Agent Journey — Product',
    'description' => 'Start your journey to becoming a certified Product sales agent with our comprehensive training program.',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/config.php'; 

// Auth BEFORE any output
require_login_or_redirect(path_with_base('/login.php'));
$uid = current_user_id();
$pdo = db();

// Check if this is a manual tour request
$isTour = isset($_GET['tour']) && $_GET['tour'] == '1';

// Get user info
try {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    
    // Track welcome walkthrough view (only for first-time, not tours)
    if (!$isTour) {
        $stmt = $pdo->prepare('INSERT IGNORE INTO welcome_walkthrough_tracking (user_id, viewed_at) VALUES (?, NOW())');
        $stmt->execute([$uid]);
    }
    
    // Check if user has already started training
    $stmt = $pdo->prepare('SELECT completed_at FROM training_progress WHERE user_id = ? AND module_id = (SELECT id FROM training_modules WHERE slug = "intro-product" LIMIT 1) LIMIT 1');
    $stmt->execute([$uid]);
    $hasStartedTraining = $stmt->fetch();
    
} catch (Throwable $e) {
    $user = ['first_name' => 'Agent'];
    $hasStartedTraining = false;
}

$firstName = $user['first_name'] ?? 'Agent';

// Build base-aware URLs
$returnToTraining = outbound_path('/onboarding/trainee/');
$fromWelcomeUrl = outbound_path('/onboarding/trainee/?from_welcome=1');
$skipWelcomeUrl = outbound_path('/onboarding/trainee/?skip_welcome=1');

// Now safe to include header (starts HTML output)
require_once __DIR__ . '/../../required/header.php';
?>

<style>
.walkthrough-container {
    min-height: 100vh;
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
    position: relative;
    overflow: hidden;
}

.walkthrough-content {
    position: relative;
    z-index: 2;
    padding: 40px 0;
}

.welcome-hero {
    text-align: center;
    color: white;
    margin-bottom: 60px;
    animation: fadeInUp 1s ease-out;
}

.welcome-hero h1 {
    font-size: 3.5rem;
    margin-bottom: 20px;
    text-shadow: 0 2px 10px rgba(0,0,0,0.3);
}

.welcome-hero p {
    font-size: 1.4rem;
    opacity: 0.9;
    max-width: 600px;
    margin: 0 auto;
}

.journey-steps {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 30px;
    margin: 60px 0;
}

.step-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 20px;
    padding: 40px;
    text-align: center;
    box-shadow: 0 8px 32px rgba(0,0,0,0.1);
    transform: translateY(20px);
    opacity: 0;
    animation: slideInUp 0.8s ease-out forwards;
}

.step-card:nth-child(2) { animation-delay: 0.2s; }
.step-card:nth-child(3) { animation-delay: 0.4s; }
.step-card:nth-child(4) { animation-delay: 0.6s; }

.step-icon {
    font-size: 4rem;
    margin-bottom: 20px;
    display: block;
}

.step-card h3 {
    color: #0b2a4a;
    margin-bottom: 15px;
    font-size: 1.5rem;
}

.step-card p {
    color: #6b7280;
    line-height: 1.6;
    margin-bottom: 20px;
}

.ai-assistant-intro {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 20px;
    padding: 50px;
    margin: 60px 0;
    text-align: center;
    box-shadow: 0 8px 32px rgba(0,0,0,0.1);
    animation: fadeIn 1s ease-out 0.8s both;
}

.ai-assistant-intro h2 {
    color: #0b2a4a;
    margin-bottom: 20px;
    font-size: 2.5rem;
}

.ai-features {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 30px;
    margin: 40px 0;
}

.ai-feature {
    background: #f0fdf4;
    border-radius: 15px;
    padding: 30px;
    border: 2px solid #bbf7d0;
}

.ai-feature-icon {
    font-size: 2.5rem;
    margin-bottom: 15px;
    display: block;
}

.ai-feature h4 {
    color: #14532d;
    margin-bottom: 10px;
    font-size: 1.2rem;
}

.ai-feature p {
    color: #166534;
    line-height: 1.5;
}

.earnings-highlight {
    background: linear-gradient(135deg, #fbbf24, #f59e0b);
    border-radius: 20px;
    padding: 50px;
    text-align: center;
    margin: 60px 0;
    color: white;
    animation: pulse 2s infinite;
}

.earnings-amount {
    font-size: 4rem;
    font-weight: 900;
    margin: 20px 0;
    text-shadow: 0 2px 10px rgba(0,0,0,0.3);
}

.cta-section {
    text-align: center;
    margin: 60px 0;
}

.btn-start-journey {
    background: linear-gradient(135deg, #059669, #047857);
    color: white;
    padding: 20px 50px;
    font-size: 1.3rem;
    border: none;
    border-radius: 50px;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    box-shadow: 0 8px 25px rgba(5, 150, 105, 0.3);
    transition: all 0.3s ease;
    animation: bounce 2s infinite;
}

.btn-start-journey:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 35px rgba(5, 150, 105, 0.4);
}

.chat-demo {
    position: relative;
    max-width: 400px;
    margin: 40px auto;
    background: white;
    border-radius: 20px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.1);
    overflow: hidden;
}

.chat-header {
    background: linear-gradient(135deg, #059669, #047857);
    color: white;
    padding: 15px 20px;
    font-weight: 600;
}

.chat-messages {
    padding: 20px;
    max-height: 200px;
    overflow-y: auto;
}

.chat-message {
    margin-bottom: 15px;
    display: flex;
}

.chat-message.ai {
    justify-content: flex-start;
}

.chat-message.user {
    justify-content: flex-end;
}

.chat-bubble {
    max-width: 80%;
    padding: 10px 15px;
    border-radius: 15px;
    font-size: 0.9rem;
    line-height: 1.4;
}

.chat-bubble.ai {
    background: #f0fdf4;
    color: #14532d;
    border-bottom-left-radius: 5px;
}

.chat-bubble.user {
    background: #dbeafe;
    color: #1e40af;
    border-bottom-right-radius: 5px;
}

.typing-indicator {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 10px 15px;
    background: #f0fdf4;
    border-radius: 15px;
    border-bottom-left-radius: 5px;
    max-width: 80px;
}

.typing-dot {
    width: 8px;
    height: 8px;
    background: #059669;
    border-radius: 50%;
    animation: typing 1.4s infinite;
}

.typing-dot:nth-child(2) { animation-delay: 0.2s; }
.typing-dot:nth-child(3) { animation-delay: 0.4s; }

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes slideInUp {
    from {
        opacity: 0;
        transform: translateY(50px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.02); }
}

@keyframes bounce {
    0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
    40% { transform: translateY(-10px); }
    60% { transform: translateY(-5px); }
}

@keyframes typing {
    0%, 60%, 100% { transform: translateY(0); }
    30% { transform: translateY(-10px); }
}

.background-shapes {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    overflow: hidden;
    z-index: 1;
}

.shape {
    position: absolute;
    opacity: 0.1;
    animation: float 6s ease-in-out infinite;
}

.shape:nth-child(1) {
    top: 10%;
    left: 10%;
    font-size: 4rem;
    animation-delay: 0s;
}

.shape:nth-child(2) {
    top: 20%;
    right: 10%;
    font-size: 3rem;
    animation-delay: 2s;
}

.shape:nth-child(3) {
    bottom: 20%;
    left: 15%;
    font-size: 5rem;
    animation-delay: 4s;
}

.shape:nth-child(4) {
    bottom: 10%;
    right: 20%;
    font-size: 3.5rem;
    animation-delay: 1s;
}

@keyframes float {
    0%, 100% { transform: translateY(0px) rotate(0deg); }
    50% { transform: translateY(-20px) rotate(10deg); }
}

@media (max-width: 768px) {
    .welcome-hero h1 {
        font-size: 2.5rem;
    }
    
    .welcome-hero p {
        font-size: 1.2rem;
    }
    
    .earnings-amount {
        font-size: 3rem;
    }
    
    .journey-steps {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .step-card {
        padding: 30px 20px;
    }
    
    .ai-assistant-intro {
        padding: 30px 20px;
    }
}
</style>

<div class="walkthrough-container">
    <div class="background-shapes">
        <div class="shape">🌿</div>
        <div class="shape">💼</div>
        <div class="shape">🎯</div>
        <div class="shape">💰</div>
    </div>
    
    <div class="walkthrough-content">
        <div class="container">
            <!-- Welcome Hero -->
            <div class="welcome-hero">
                <h1><?php echo $isTour ? 'Quick Tour' : 'Welcome'; ?>, <?php echo sanitize($firstName); ?>! 🎉</h1>
                <p><?php echo $isTour ? 'Here\'s a quick overview of your training program and AI assistant.' : 'You\'re about to start your journey to earning $5,000-$15,000+ monthly as a certified Product sales expert!'; ?></p>
            </div>

            <!-- Journey Steps - Condensed -->
            <div class="journey-steps">
                <div class="step-card">
                    <span class="step-icon">📚</span>
                    <h3>Learn Product Mastery</h3>
                    <p>Master botanical science, product categories, compliance, and sales techniques through 9 comprehensive modules.</p>
                </div>

                <div class="step-card">
                    <span class="step-icon">🤖</span>
                    <h3>AI Assistant Support</h3>
                    <p>Get instant help with products, prices, objections, and sales strategies from your AI assistant.</p>
                </div>

                <div class="step-card">
                    <span class="step-icon">💰</span>
                    <h3>Start Earning Big</h3>
                    <p>Once certified, earn $5,000-$15,000+ monthly with our proven commission structure.</p>
                </div>
            </div>

            <!-- AI Assistant Introduction -->
            <div class="ai-assistant-intro">
                <h2>🤖 Your AI Sales Assistant</h2>
                <p style="font-size: 1.1rem; color: #6b7280; margin-bottom: 30px;">
                    Get instant help with products, pricing, objections, and sales strategies. Your AI assistant is trained specifically on Product sales and compliance.
                </p>

                <!-- Chat Demo -->
                <div class="chat-demo">
                    <div class="chat-header">
                        AI Sales Assistant
                    </div>
                    <div class="chat-messages" id="demo-messages">
                        <div class="chat-message ai">
                            <div class="chat-bubble ai">
                                Hi <?php echo sanitize($firstName); ?>! I'm here to help you succeed. What would you like to know about Product sales?
                            </div>
                        </div>
                    </div>
                </div>

                <p style="margin-top: 20px; color: #6b7280;">
                    <strong>Look for the chat button (💬)</strong> in the bottom-right corner. Always available to help!
                </p>
            </div>

            <!-- Earnings Highlight -->
            <div class="earnings-highlight">
                <h2 style="margin: 0; font-size: 2rem;">Your Earning Potential</h2>
                <div class="earnings-amount">$5,000 - $15,000+</div>
                <p style="font-size: 1.3rem; margin: 0; opacity: 0.9;">Monthly Commission Potential</p>
                <p style="font-size: 1rem; margin-top: 15px; opacity: 0.8;">
                    Our top certified agents earn $180,000+ annually with our tiered commission structure
                </p>
            </div>

            <!-- Call to Action -->
            <div class="cta-section">
                <h2 style="color: white; margin-bottom: 20px; font-size: 2.2rem;">
                    <?php echo $isTour ? 'Ready to Continue?' : 'Ready to Begin?'; ?>
                </h2>
                <p style="color: rgba(255,255,255,0.9); font-size: 1.1rem; margin-bottom: 30px;">
                    <?php echo $isTour ? 'Return to your training portal to continue your journey.' : 'Start Module 1 and begin your path to earning $5,000-$15,000+ monthly.'; ?>
                </p>
                
                <?php if ($isTour): ?>
                    <a href="<?php echo $returnToTraining; ?>" class="btn-start-journey">
                        Return to Training →
                    </a>
                <?php elseif ($hasStartedTraining): ?>
                    <a href="<?php echo $fromWelcomeUrl; ?>" class="btn-start-journey">
                        Continue Your Training →
                    </a>
                <?php else: ?>
                    <a href="<?php echo $fromWelcomeUrl; ?>" class="btn-start-journey">
                        Start Your Training Journey →
                    </a>
                <?php endif; ?>
                
                <?php if (!$isTour): ?>
                <div style="margin-top: 30px;">
                    <a href="<?php echo $skipWelcomeUrl; ?>" style="color: rgba(255,255,255,0.7); text-decoration: underline; font-size: 1rem;">
                        Skip introduction and go directly to training
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Demo chat animation
document.addEventListener('DOMContentLoaded', function() {
    const demoMessages = document.getElementById('demo-messages');
    const demoConversation = [
        { type: 'user', text: 'What\'s the profit margin on Red Product A?' },
        { type: 'ai', text: 'Red Product A has excellent margins! Wholesale cost is $45/kg, retail at $120/kg gives you $75 profit - that\'s 167% markup. Perfect for new customers!' },
        { type: 'user', text: 'How do I handle price objections?' },
        { type: 'ai', text: 'Focus on value! Emphasize quality, lab testing, and customer satisfaction. Try: "This premium quality saves you money long-term because you need less product for the same results."' }
    ];
    
    let messageIndex = 1; // Start after initial message
    
    function addDemoMessage() {
        if (messageIndex >= demoConversation.length) {
            // Restart the demo
            setTimeout(() => {
                // Clear all messages except the first
                const firstMessage = demoMessages.children[0];
                demoMessages.innerHTML = '';
                demoMessages.appendChild(firstMessage);
                messageIndex = 0;
                setTimeout(addDemoMessage, 2000);
            }, 3000);
            return;
        }
        
        const message = demoConversation[messageIndex];
        
        // Show typing indicator for AI messages
        if (message.type === 'ai') {
            const typingDiv = document.createElement('div');
            typingDiv.className = 'chat-message ai';
            typingDiv.innerHTML = `
                <div class="typing-indicator">
                    <div class="typing-dot"></div>
                    <div class="typing-dot"></div>
                    <div class="typing-dot"></div>
                </div>
            `;
            demoMessages.appendChild(typingDiv);
            demoMessages.scrollTop = demoMessages.scrollHeight;
            
            // Replace typing indicator with actual message after delay
            setTimeout(() => {
                demoMessages.removeChild(typingDiv);
                showMessage(message);
                messageIndex++;
                setTimeout(addDemoMessage, 3000);
            }, 1500);
        } else {
            showMessage(message);
            messageIndex++;
            setTimeout(addDemoMessage, 2000);
        }
    }
    
    function showMessage(message) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `chat-message ${message.type}`;
        messageDiv.innerHTML = `<div class="chat-bubble ${message.type}">${message.text}</div>`;
        demoMessages.appendChild(messageDiv);
        demoMessages.scrollTop = demoMessages.scrollHeight;
    }
    
    // Start demo after 3 seconds
    setTimeout(addDemoMessage, 3000);
    
    // Highlight chat button when page loads
    setTimeout(() => {
        const chatButton = document.getElementById('ai-chat-button');
        if (chatButton) {
            chatButton.style.animation = 'pulse 1s infinite';
            chatButton.style.boxShadow = '0 4px 20px rgba(5, 150, 105, 0.6)';
            
            // Add a tooltip
            chatButton.title = 'Click here to chat with your AI assistant!';
            
            // Stop highlighting after 5 seconds
            setTimeout(() => {
                chatButton.style.animation = '';
                chatButton.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
            }, 5000);
        }
    }, 1000);
    
    // Customize AI chat for welcome page
    // Override the initial message when chat opens
    const originalToggleChat = window.toggleChat;
    if (typeof originalToggleChat === 'function') {
        window.toggleChat = function() {
            const wasOpen = document.getElementById('ai-chat-window').style.display === 'flex';
            originalToggleChat();
            
            // If chat was just opened, customize the welcome message
            if (!wasOpen) {
                setTimeout(() => {
                    const messagesContainer = document.getElementById('ai-messages');
                    if (messagesContainer && messagesContainer.children.length === 1) {
                        // Replace the default welcome message
                        const welcomeMessage = messagesContainer.children[0];
                        if (welcomeMessage) {
                            const bubble = welcomeMessage.querySelector('.chat-bubble, div[style*="background: white"]');
                            if (bubble) {
                                bubble.innerHTML = 'Welcome to your sales training journey, <?php echo sanitize($firstName); ?>! 🎉 I\'m your AI assistant, specially trained to help you master Product sales. I can answer questions about products, compliance, sales techniques, and help you succeed in your training. What would you like to know?';
                            }
                        }
                    }
                }, 100);
            }
        };
    }
});
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>
