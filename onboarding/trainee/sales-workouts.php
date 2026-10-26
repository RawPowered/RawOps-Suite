<?php
$SEO = [
    'title' => 'Sales Workouts — Training',
    'description' => 'Practice and reinforce your sales knowledge with interactive flash cards.',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/header.php';
require_login_or_redirect(path_with_base('/login.php'));

$pdo = db();
$uid = current_user_id();

// Load available decks
$decks = $pdo->query("
    SELECT 
        swd.*,
        COUNT(DISTINCT swc.id) as total_cards,
        COUNT(DISTINCT swp.card_id) as cards_practiced,
        AVG(swp.confidence_level) as avg_confidence,
        MAX(swp.last_reviewed_at) as last_practice
    FROM sales_workout_decks swd
    LEFT JOIN sales_workout_cards swc ON swc.deck_id = swd.id AND swc.active = 1
    LEFT JOIN sales_workout_progress swp ON swp.deck_id = swd.id AND swp.user_id = {$uid}
    WHERE swd.active = 1
    GROUP BY swd.id
    ORDER BY swd.sort_order ASC, swd.name ASC
")->fetchAll();
?>

<style>
.workouts-hero { background: linear-gradient(135deg, #1e3a8a, #2563eb); color: white; padding: 40px 0; }
.deck-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; margin: 32px 0; }
.deck-card { background: white; border-radius: 12px; padding: 24px; box-shadow: 0 4px 16px rgba(0,0,0,0.1); transition: transform 0.2s; cursor: pointer; }
.deck-card:hover { transform: translateY(-4px); box-shadow: 0 6px 20px rgba(0,0,0,0.15); }
.flash-card { width: 100%; max-width: 600px; height: 400px; margin: 0 auto; perspective: 1000px; }
.flash-card-inner { position: relative; width: 100%; height: 100%; transition: transform 0.6s; transform-style: preserve-3d; }
.flash-card.flipped .flash-card-inner { transform: rotateY(180deg); }
.flash-card-face { position: absolute; width: 100%; height: 100%; backface-visibility: hidden; border-radius: 12px; padding: 40px; display: flex; align-items: center; justify-content: center; text-align: center; font-size: 20px; box-shadow: 0 4px 16px rgba(0,0,0,0.2); }
.flash-card-front { background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; }
.flash-card-back { background: linear-gradient(135deg, #059669, #10b981); color: white; transform: rotateY(180deg); }
</style>

<div class="workouts-hero">
    <div class="container">
        <h1 style="font-size: 42px; margin-bottom: 12px;">🏋️ Sales Workouts</h1>
        <p style="font-size: 20px; opacity: 0.9;">Practice and reinforce your knowledge with interactive flash cards</p>
    </div>
</div>

<div class="container" style="padding: 40px 20px;">
    <div class="deck-grid">
        <?php foreach ($decks as $deck): ?>
            <?php
            $progressPercent = $deck['total_cards'] > 0 ? round(($deck['cards_practiced'] / $deck['total_cards']) * 100) : 0;
            $confidenceStars = round($deck['avg_confidence'] ?? 0);
            ?>
            <div class="deck-card" onclick="window.location.href='sales-workouts.php?deck=<?php echo $deck['id']; ?>'">
                <div style="font-size: 48px; text-align: center; margin-bottom: 16px;">
                    <?php echo htmlspecialchars($deck['icon'] ?? '📚'); ?>
                </div>
                
                <h3 style="margin: 0 0 8px 0; color: #1f2937; text-align: center;">
                    <?php echo htmlspecialchars($deck['name']); ?>
                </h3>
                
                <div style="text-align: center; font-size: 13px; color: #6b7280; margin-bottom: 16px;">
                    <?php echo ucfirst(str_replace('_', ' ', $deck['category'])); ?> • 
                    <?php echo ucfirst($deck['difficulty']); ?>
                </div>
                
                <?php if ($deck['description']): ?>
                    <p style="color: #6b7280; font-size: 14px; text-align: center; margin-bottom: 16px;">
                        <?php echo htmlspecialchars($deck['description']); ?>
                    </p>
                <?php endif; ?>
                
                <div style="background: #f9fafb; padding: 12px; border-radius: 6px; margin-bottom: 12px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span style="font-size: 13px; color: #6b7280;">Progress</span>
                        <span style="font-size: 13px; font-weight: 600; color: #059669;"><?php echo $progressPercent; ?>%</span>
                    </div>
                    <div style="width: 100%; height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden;">
                        <div style="width: <?php echo $progressPercent; ?>%; height: 100%; background: linear-gradient(to right, #3b82f6, #059669);"></div>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 13px; color: #6b7280;">
                    <div>
                        <strong style="color: #1f2937;"><?php echo $deck['total_cards']; ?></strong> cards
                    </div>
                    <div style="text-align: right;">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <span style="color: <?php echo $i <= $confidenceStars ? '#fbbf24' : '#e5e7eb'; ?>;">★</span>
                        <?php endfor; ?>
                    </div>
                </div>
                
                <?php if ($deck['last_practice']): ?>
                    <div style="font-size: 12px; color: #9ca3af; text-align: center; margin-top: 8px;">
                        Last practiced: <?php echo date('M d, Y', strtotime($deck['last_practice'])); ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        
        <?php if (empty($decks)): ?>
        <div class="card" style="grid-column: 1 / -1; text-align: center; padding: 60px 20px;">
            <div style="font-size: 48px; margin-bottom: 16px;">🏋️</div>
            <h3>No Workouts Available Yet</h3>
            <p style="color: #6b7280;">Check back soon for flash card practice decks!</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Workout Session Modal -->
<?php if (isset($_GET['deck'])): ?>
    <?php
    $deckId = intval($_GET['deck']);
    $stmt = $pdo->prepare("SELECT * FROM sales_workout_decks WHERE id = ? AND active = 1");
    $stmt->execute([$deckId]);
    $currentDeck = $stmt->fetch(PDO::FETCH_ASSOC);
    ?>
    
    <?php if ($currentDeck): ?>
    <div id="workoutModal" class="modal active" style="display: flex;">
        <div style="background: white; border-radius: 16px; max-width: 800px; width: 95%; padding: 32px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                <h2 style="margin: 0;"><?php echo htmlspecialchars($currentDeck['icon']); ?> <?php echo htmlspecialchars($currentDeck['name']); ?></h2>
                <a href="sales-workouts.php" style="font-size: 24px; color: #6b7280; text-decoration: none;">&times;</a>
            </div>
            
            <div id="workout-container">
                <div style="text-align: center; padding: 40px; color: #6b7280;">
                    Loading cards...
                </div>
            </div>
        </div>
    </div>
    
    <script>
    let currentDeckId = <?php echo $deckId; ?>;
    let cards = [];
    let currentCardIndex = 0;
    let sessionId = null;
    let sessionStartTime = Date.now();
    let cardConfidences = [];
    
    async function loadWorkout() {
        const response = await fetch('<?php echo path_with_base('/api/sales-workouts-api.php'); ?>?action=get_deck_for_workout&deck_id=' + currentDeckId);
        const data = await response.json();
        
        if (data.ok && data.cards) {
            cards = data.cards;
            
            // Start session
            const sessionResp = await fetch('<?php echo path_with_base('/api/sales-workouts-api.php'); ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action: 'start_session', deck_id: currentDeckId, session_type: 'full_deck'})
            });
            const sessionData = await sessionResp.json();
            if (sessionData.ok) {
                sessionId = sessionData.session_id;
            }
            
            showCard(0);
        }
    }
    
    function showCard(index) {
        if (index >= cards.length) {
            showResults();
            return;
        }
        
        currentCardIndex = index;
        const card = cards[index];
        const container = document.getElementById('workout-container');
        
        container.innerHTML = `
            <div style="margin-bottom: 24px; text-align: center; color: #6b7280;">
                Card ${index + 1} of ${cards.length}
            </div>
            
            <div class="flash-card" id="flash-card-${index}">
                <div class="flash-card-inner">
                    <div class="flash-card-face flash-card-front">
                        <div>
                            <div style="font-size: 24px; line-height: 1.4;">${escapeHtml(card.front_text)}</div>
                            ${card.hints ? '<div style="margin-top: 20px; font-size: 14px; opacity: 0.8;">💡 ' + escapeHtml(card.hints) + '</div>' : ''}
                        </div>
                    </div>
                    <div class="flash-card-face flash-card-back">
                        <div style="font-size: 20px; line-height: 1.5;">${escapeHtml(card.back_text)}</div>
                    </div>
                </div>
            </div>
            
            <div style="text-align: center; margin-top: 24px;">
                <button onclick="flipCard()" class="btn" id="flip-btn" style="background: #3b82f6; color: white; padding: 12px 32px; font-size: 16px; border: none; border-radius: 8px; cursor: pointer;">
                    Show Answer
                </button>
            </div>
            
            <div id="confidence-section" style="display: none; margin-top: 32px;">
                <div style="text-align: center; margin-bottom: 16px; color: #6b7280; font-weight: 600;">
                    How confident are you with this card?
                </div>
                <div style="display: flex; justify-content: center; gap: 12px;">
                    <button onclick="rateCard(1)" class="btn secondary">😰 1</button>
                    <button onclick="rateCard(2)" class="btn secondary">😕 2</button>
                    <button onclick="rateCard(3)" class="btn secondary">😐 3</button>
                    <button onclick="rateCard(4)" class="btn secondary">😊 4</button>
                    <button onclick="rateCard(5)" class="btn" style="background: #059669; color: white;">🎯 5</button>
                </div>
            </div>
        `;
    }
    
    function flipCard() {
        const card = document.querySelector('.flash-card');
        card.classList.add('flipped');
        document.getElementById('flip-btn').style.display = 'none';
        document.getElementById('confidence-section').style.display = 'block';
    }
    
    async function rateCard(confidence) {
        cardConfidences.push(confidence);
        
        // Save progress
        await fetch('<?php echo path_with_base('/api/sales-workouts-api.php'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: 'update_card_progress',
                deck_id: currentDeckId,
                card_id: cards[currentCardIndex].id,
                confidence_level: confidence,
                correct: confidence >= 3 ? 1 : 0
            })
        });
        
        // Next card
        showCard(currentCardIndex + 1);
    }
    
    function showResults() {
        const avgConfidence = cardConfidences.reduce((a, b) => a + b, 0) / cardConfidences.length;
        const cardsCorrect = cardConfidences.filter(c => c >= 3).length;
        const totalTime = Math.round((Date.now() - sessionStartTime) / 1000);
        
        // End session
        fetch('<?php echo path_with_base('/api/sales-workouts-api.php'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: 'end_session',
                session_id: sessionId,
                cards_reviewed: cards.length,
                cards_correct: cardsCorrect,
                avg_confidence_before: 0,
                avg_confidence_after: avgConfidence,
                total_time_seconds: totalTime
            })
        });
        
        const container = document.getElementById('workout-container');
        container.innerHTML = `
            <div style="background: linear-gradient(135deg, #dcfce7, #bbf7d0); padding: 40px; border-radius: 12px; text-align: center;">
                <div style="font-size: 72px; margin-bottom: 16px;">🎉</div>
                <h2 style="color: #166534; margin: 0 0 24px 0;">Workout Complete!</h2>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 24px;">
                    <div style="background: white; padding: 16px; border-radius: 8px;">
                        <div style="font-size: 32px; font-weight: bold; color: #3b82f6;">${cards.length}</div>
                        <div style="font-size: 13px; color: #6b7280;">Cards Reviewed</div>
                    </div>
                    <div style="background: white; padding: 16px; border-radius: 8px;">
                        <div style="font-size: 32px; font-weight: bold; color: #059669;">${avgConfidence.toFixed(1)}/5</div>
                        <div style="font-size: 13px; color: #6b7280;">Avg Confidence</div>
                    </div>
                    <div style="background: white; padding: 16px; border-radius: 8px;">
                        <div style="font-size: 32px; font-weight: bold; color: #f59e0b;">${Math.floor(totalTime / 60)}</div>
                        <div style="font-size: 13px; color: #6b7280;">Minutes</div>
                    </div>
                </div>
                
                <a href="sales-workouts.php" class="btn" style="background: #059669; color: white; text-decoration: none; display: inline-block; padding: 12px 32px; border-radius: 8px;">
                    Back to Workouts
                </a>
            </div>
        `;
    }
    
    function escapeHtml(text) {
        const map = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }
    
    // Start workout
    loadWorkout();
    </script>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>



