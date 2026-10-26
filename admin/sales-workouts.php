<?php
$SEO = [
    'title' => 'Sales Workouts Manager — Admin LMS',
    'description' => 'Create and manage flash card decks for sales training reinforcement.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();
$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'save_deck') {
            $deckId = intval($_POST['deck_id'] ?? 0);
            $name = trim($_POST['name']);
            $description = trim($_POST['description'] ?? '');
            $category = $_POST['category'];
            $difficulty = $_POST['difficulty'];
            $icon = trim($_POST['icon'] ?? '📚');
            $sortOrder = intval($_POST['sort_order'] ?? 0);
            $active = isset($_POST['active']) ? 1 : 0;
            
            if ($deckId > 0) {
                $stmt = $pdo->prepare("UPDATE sales_workout_decks SET name = ?, description = ?, category = ?, difficulty = ?, icon = ?, sort_order = ?, active = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$name, $description, $category, $difficulty, $icon, $sortOrder, $active, $deckId]);
                $message = 'Deck updated successfully!';
            } else {
                $stmt = $pdo->prepare("INSERT INTO sales_workout_decks (name, description, category, difficulty, icon, sort_order, active, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$name, $description, $category, $difficulty, $icon, $sortOrder, $active, current_user_id()]);
                $deckId = $pdo->lastInsertId();
                $message = 'Deck created successfully!';
            }
        }
        elseif ($action === 'delete_deck') {
            $deckId = intval($_POST['deck_id']);
            $pdo->prepare("DELETE FROM sales_workout_progress WHERE deck_id = ?")->execute([$deckId]);
            $pdo->prepare("DELETE FROM sales_workout_cards WHERE deck_id = ?")->execute([$deckId]);
            $pdo->prepare("DELETE FROM sales_workout_decks WHERE id = ?")->execute([$deckId]);
            $message = 'Deck deleted successfully!';
        }
        elseif ($action === 'save_card') {
            $cardId = intval($_POST['card_id'] ?? 0);
            $deckId = intval($_POST['deck_id']);
            $frontText = trim($_POST['front_text']);
            $backText = trim($_POST['back_text']);
            $hints = trim($_POST['hints'] ?? '');
            $productId = !empty($_POST['product_id']) ? intval($_POST['product_id']) : null;
            $sortOrder = intval($_POST['sort_order'] ?? 0);
            $difficultyLevel = intval($_POST['difficulty_level'] ?? 1);
            $active = isset($_POST['active']) ? 1 : 0;
            
            if ($cardId > 0) {
                $stmt = $pdo->prepare("UPDATE sales_workout_cards SET deck_id = ?, front_text = ?, back_text = ?, hints = ?, product_id = ?, sort_order = ?, difficulty_level = ?, active = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$deckId, $frontText, $backText, $hints, $productId, $sortOrder, $difficultyLevel, $active, $cardId]);
                $message = 'Card updated!';
            } else {
                $stmt = $pdo->prepare("INSERT INTO sales_workout_cards (deck_id, front_text, back_text, hints, product_id, sort_order, difficulty_level, active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$deckId, $frontText, $backText, $hints, $productId, $sortOrder, $difficultyLevel, $active]);
                $message = 'Card created!';
            }
        }
        elseif ($action === 'delete_card') {
            $cardId = intval($_POST['card_id']);
            $pdo->prepare("DELETE FROM sales_workout_progress WHERE card_id = ?")->execute([$cardId]);
            $pdo->prepare("DELETE FROM sales_workout_cards WHERE id = ?")->execute([$cardId]);
            $message = 'Card deleted!';
        }
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Load all decks
$decks = $pdo->query("
    SELECT 
        swd.*,
        COUNT(DISTINCT swc.id) as actual_card_count,
        COUNT(DISTINCT swp.user_id) as user_count,
        AVG(swp.confidence_level) as avg_confidence
    FROM sales_workout_decks swd
    LEFT JOIN sales_workout_cards swc ON swc.deck_id = swd.id AND swc.active = 1
    LEFT JOIN sales_workout_progress swp ON swp.deck_id = swd.id
    GROUP BY swd.id
    ORDER BY swd.sort_order ASC, swd.name ASC
")->fetchAll();

// Load products for linking
$products = $pdo->query("SELECT id, sku, name FROM products WHERE active = 1 ORDER BY name")->fetchAll();
?>

<style>
.workouts-container { max-width: 1400px; margin: 0 auto; padding: 20px; }
.workouts-header { background: linear-gradient(135deg, #7c3aed, #a855f7); color: white; padding: 40px 0; margin-bottom: 32px; text-align: center; }
.deck-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; margin-bottom: 32px; }
.deck-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border: 2px solid #e5e7eb; }
.deck-card:hover { border-color: #7c3aed; }
.card-list { max-height: 400px; overflow-y: auto; }
.card-item { background: #f9fafb; padding: 12px; margin-bottom: 8px; border-radius: 6px; border-left: 4px solid #3b82f6; }
.modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
.modal.active { display: flex; }
.modal-content { background: white; border-radius: 12px; max-width: 700px; width: 90%; max-height: 90vh; overflow-y: auto; }
.modal-header { padding: 20px 24px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; }
.modal-body { padding: 24px; }
</style>

<div class="workouts-header">
    <div class="container">
        <h1 style="font-size: 42px; margin-bottom: 12px;">🏋️ Sales Workouts Manager</h1>
        <p style="font-size: 20px; opacity: 0.9;">Create flash card decks to reinforce learning and build sales mastery</p>
    </div>
</div>

<div class="workouts-container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <?php if ($message): ?>
        <div class="card" style="background: #dcfce7; color: #14532d; margin-bottom: 24px;">
            ✅ <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="card" style="background: #fee2e2; color: #991b1b; margin-bottom: 24px;">
            ❌ <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <div style="margin-bottom: 24px;">
        <button onclick="openDeckModal()" class="btn" style="background: var(--green); color: white;">
            ➕ Create New Deck
        </button>
    </div>
    
    <div class="deck-grid">
        <?php foreach ($decks as $deck): ?>
        <div class="deck-card">
            <div style="display: flex; justify-content: between; align-items: start; margin-bottom: 12px;">
                <div style="flex: 1;">
                    <div style="font-size: 32px; margin-bottom: 8px;"><?php echo htmlspecialchars($deck['icon'] ?? '📚'); ?></div>
                    <h3 style="margin: 0 0 8px 0; color: #1f2937;"><?php echo htmlspecialchars($deck['name']); ?></h3>
                    <div style="font-size: 13px; color: #6b7280;">
                        <?php echo ucfirst(str_replace('_', ' ', $deck['category'])); ?> • 
                        <?php echo ucfirst($deck['difficulty']); ?>
                    </div>
                </div>
                <div>
                    <?php if ($deck['active']): ?>
                        <span style="background: #dcfce7; color: #14532d; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;">Active</span>
                    <?php else: ?>
                        <span style="background: #f3f4f6; color: #6b7280; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;">Inactive</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($deck['description']): ?>
                <p style="color: #6b7280; font-size: 14px; margin: 0 0 16px 0;">
                    <?php echo htmlspecialchars($deck['description']); ?>
                </p>
            <?php endif; ?>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                <div style="background: #f9fafb; padding: 12px; border-radius: 6px; text-align: center;">
                    <div style="font-size: 24px; font-weight: bold; color: #3b82f6;"><?php echo $deck['actual_card_count']; ?></div>
                    <div style="font-size: 12px; color: #6b7280;">Cards</div>
                </div>
                <div style="background: #f9fafb; padding: 12px; border-radius: 6px; text-align: center;">
                    <div style="font-size: 24px; font-weight: bold; color: #059669;"><?php echo $deck['user_count']; ?></div>
                    <div style="font-size: 12px; color: #6b7280;">Users Practicing</div>
                </div>
            </div>
            
            <div style="display: flex; gap: 8px;">
                <button onclick="manageDeckCards(<?php echo $deck['id']; ?>)" class="btn small secondary" style="flex: 1;">
                    Manage Cards
                </button>
                <button onclick="openEditDeck(<?php echo $deck['id']; ?>)" class="btn small secondary">
                    Edit
                </button>
                <form method="post" style="display: inline;" onsubmit="return confirm('Delete this deck and all its cards?');">
                    <input type="hidden" name="action" value="delete_deck">
                    <input type="hidden" name="deck_id" value="<?php echo $deck['id']; ?>">
                    <button type="submit" class="btn small" style="background: #dc2626; color: white;">Delete</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if (empty($decks)): ?>
        <div class="card" style="grid-column: 1 / -1; text-align: center; padding: 60px 20px;">
            <div style="font-size: 48px; margin-bottom: 16px;">🏋️</div>
            <h3>No Sales Workouts Yet</h3>
            <p style="color: #6b7280;">Create your first flash card deck to help agents reinforce their training.</p>
            <button onclick="openDeckModal()" class="btn" style="background: var(--green); color: white; margin-top: 16px;">
                Create First Deck
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Create/Edit Deck Modal -->
<div id="deckModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="deckModalTitle" style="margin: 0;">Create Workout Deck</h3>
            <button onclick="closeDeckModal()" style="background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
        </div>
        <div class="modal-body">
            <form method="post" id="deckForm">
                <input type="hidden" name="action" value="save_deck">
                <input type="hidden" name="deck_id" id="deck_id">
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 4px; font-weight: 600;">Deck Name *</label>
                    <input type="text" name="name" id="deck_name" required style="width: 100%; padding: 10px; border: 1px solid #e5e7eb; border-radius: 6px;">
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 4px; font-weight: 600;">Description</label>
                    <textarea name="description" id="deck_description" rows="3" style="width: 100%; padding: 10px; border: 1px solid #e5e7eb; border-radius: 6px;"></textarea>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; margin-bottom: 4px; font-weight: 600;">Category</label>
                        <select name="category" id="deck_category" style="width: 100%; padding: 10px; border: 1px solid #e5e7eb; border-radius: 6px;">
                            <option value="product_knowledge">Product Knowledge</option>
                            <option value="objection_handling">Objection Handling</option>
                            <option value="profit_calculations">Profit Calculations</option>
                            <option value="compliance">Compliance</option>
                            <option value="general">General</option>
                        </select>
                    </div>
                    
                    <div>
                        <label style="display: block; margin-bottom: 4px; font-weight: 600;">Difficulty</label>
                        <select name="difficulty" id="deck_difficulty" style="width: 100%; padding: 10px; border: 1px solid #e5e7eb; border-radius: 6px;">
                            <option value="beginner">Beginner</option>
                            <option value="intermediate">Intermediate</option>
                            <option value="advanced">Advanced</option>
                        </select>
                    </div>
                    
                    <div>
                        <label style="display: block; margin-bottom: 4px; font-weight: 600;">Icon (Emoji)</label>
                        <input type="text" name="icon" id="deck_icon" maxlength="4" placeholder="📚" style="width: 100%; padding: 10px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; margin-bottom: 4px; font-weight: 600;">Sort Order</label>
                        <input type="number" name="sort_order" id="deck_sort_order" value="0" style="width: 100%; padding: 10px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                    
                    <div style="display: flex; align-items: end;">
                        <label style="display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" name="active" id="deck_active" checked>
                            <span>Active</span>
                        </label>
                    </div>
                </div>
                
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" class="btn secondary" onclick="closeDeckModal()">Cancel</button>
                    <button type="submit" class="btn" style="background: var(--green); color: white;">Save Deck</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Manage Cards Modal -->
<div id="cardsModal" class="modal">
    <div class="modal-content" style="max-width: 900px;">
        <div class="modal-header">
            <h3 id="cardsModalTitle" style="margin: 0;">Manage Cards</h3>
            <button onclick="closeCardsModal()" style="background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="current_deck_id">
            
            <button onclick="openCardModal()" class="btn" style="background: var(--green); color: white; margin-bottom: 16px;">
                ➕ Add Card
            </button>
            
            <div id="cardsList" class="card-list">
                <!-- Cards will be loaded here -->
            </div>
        </div>
    </div>
</div>

<!-- Create/Edit Card Modal -->
<div id="cardModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="cardModalTitle" style="margin: 0;">Create Flash Card</h3>
            <button onclick="closeCardModal()" style="background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
        </div>
        <div class="modal-body">
            <form method="post" id="cardForm">
                <input type="hidden" name="action" value="save_card">
                <input type="hidden" name="card_id" id="card_id">
                <input type="hidden" name="deck_id" id="card_deck_id">
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 4px; font-weight: 600;">Front (Question/Prompt) *</label>
                    <textarea name="front_text" id="card_front" rows="3" required style="width: 100%; padding: 10px; border: 1px solid #e5e7eb; border-radius: 6px;" placeholder="What is the attribute content of Blue 10 per 0.5oz?"></textarea>
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 4px; font-weight: 600;">Back (Answer/Explanation) *</label>
                    <textarea name="back_text" id="card_back" rows="3" required style="width: 100%; padding: 10px; border: 1px solid #e5e7eb; border-radius: 6px;" placeholder="10mg attribute per 0.5oz serving"></textarea>
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 4px; font-weight: 600;">Hints (Optional)</label>
                    <textarea name="hints" id="card_hints" rows="2" style="width: 100%; padding: 10px; border: 1px solid #e5e7eb; border-radius: 6px;" placeholder="Think about the standard serving size..."></textarea>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; margin-bottom: 4px; font-weight: 600;">Link to Product</label>
                        <select name="product_id" id="card_product" style="width: 100%; padding: 10px; border: 1px solid #e5e7eb; border-radius: 6px;">
                            <option value="">None</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?php echo $product['id']; ?>"><?php echo htmlspecialchars($product['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label style="display: block; margin-bottom: 4px; font-weight: 600;">Difficulty (1-5)</label>
                        <input type="number" name="difficulty_level" id="card_difficulty" min="1" max="5" value="1" style="width: 100%; padding: 10px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                    
                    <div>
                        <label style="display: block; margin-bottom: 4px; font-weight: 600;">Sort Order</label>
                        <input type="number" name="sort_order" id="card_sort_order" value="0" style="width: 100%; padding: 10px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="active" id="card_active" checked>
                        <span>Active</span>
                    </label>
                </div>
                
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" class="btn secondary" onclick="closeCardModal()">Cancel</button>
                    <button type="submit" class="btn" style="background: var(--green); color: white;">Save Card</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openDeckModal() {
    document.getElementById('deckModalTitle').textContent = 'Create Workout Deck';
    document.getElementById('deckForm').reset();
    document.getElementById('deck_id').value = '';
    document.getElementById('deck_active').checked = true;
    document.getElementById('deckModal').classList.add('active');
}

function openEditDeck(deckId) {
    const deck = <?php echo json_encode($decks); ?>.find(d => d.id == deckId);
    if (!deck) return;
    
    document.getElementById('deckModalTitle').textContent = 'Edit Workout Deck';
    document.getElementById('deck_id').value = deck.id;
    document.getElementById('deck_name').value = deck.name;
    document.getElementById('deck_description').value = deck.description || '';
    document.getElementById('deck_category').value = deck.category;
    document.getElementById('deck_difficulty').value = deck.difficulty;
    document.getElementById('deck_icon').value = deck.icon || '';
    document.getElementById('deck_sort_order').value = deck.sort_order;
    document.getElementById('deck_active').checked = deck.active == 1;
    document.getElementById('deckModal').classList.add('active');
}

function closeDeckModal() {
    document.getElementById('deckModal').classList.remove('active');
}

async function manageDeckCards(deckId) {
    document.getElementById('current_deck_id').value = deckId;
    const deck = <?php echo json_encode($decks); ?>.find(d => d.id == deckId);
    document.getElementById('cardsModalTitle').textContent = 'Manage Cards: ' + (deck ? deck.name : 'Deck');
    
    // Load cards
    const response = await fetch('<?php echo path_with_base('/api/sales-workouts-api.php'); ?>?action=get_cards&deck_id=' + deckId);
    const data = await response.json();
    
    const cardsList = document.getElementById('cardsList');
    if (data.ok && data.cards && data.cards.length > 0) {
        cardsList.innerHTML = data.cards.map(card => `
            <div class="card-item">
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">
                    <strong style="color: #1f2937;">${escapeHtml(card.front_text.substring(0, 80))}${card.front_text.length > 80 ? '...' : ''}</strong>
                    <div style="display: flex; gap: 4px;">
                        <button onclick="editCard(${card.id})" class="btn small secondary" style="font-size: 11px; padding: 4px 8px;">Edit</button>
                        <form method="post" style="display: inline;" onsubmit="return confirm('Delete this card?');">
                            <input type="hidden" name="action" value="delete_card">
                            <input type="hidden" name="card_id" value="${card.id}">
                            <button type="submit" class="btn small" style="background: #dc2626; color: white; font-size: 11px; padding: 4px 8px;">×</button>
                        </form>
                    </div>
                </div>
                <div style="font-size: 13px; color: #6b7280; margin-bottom: 4px;">
                    <strong>Answer:</strong> ${escapeHtml(card.back_text.substring(0, 100))}${card.back_text.length > 100 ? '...' : ''}
                </div>
                ${card.product_name ? '<div style="font-size: 12px; color: #059669;">🔗 ' + escapeHtml(card.product_name) + '</div>' : ''}
            </div>
        `).join('');
    } else {
        cardsList.innerHTML = '<p style="text-align: center; color: #6b7280; padding: 40px;">No cards yet. Add your first card!</p>';
    }
    
    document.getElementById('cardsModal').classList.add('active');
}

function closeCardsModal() {
    document.getElementById('cardsModal').classList.remove('active');
}

function openCardModal() {
    const deckId = document.getElementById('current_deck_id').value;
    document.getElementById('cardModalTitle').textContent = 'Create Flash Card';
    document.getElementById('cardForm').reset();
    document.getElementById('card_id').value = '';
    document.getElementById('card_deck_id').value = deckId;
    document.getElementById('card_active').checked = true;
    document.getElementById('cardModal').classList.add('active');
}

async function editCard(cardId) {
    const response = await fetch('<?php echo path_with_base('/api/sales-workouts-api.php'); ?>?action=get_card&id=' + cardId);
    const data = await response.json();
    
    if (data.ok && data.card) {
        const card = data.card;
        document.getElementById('cardModalTitle').textContent = 'Edit Flash Card';
        document.getElementById('card_id').value = card.id;
        document.getElementById('card_deck_id').value = card.deck_id;
        document.getElementById('card_front').value = card.front_text;
        document.getElementById('card_back').value = card.back_text;
        document.getElementById('card_hints').value = card.hints || '';
        document.getElementById('card_product').value = card.product_id || '';
        document.getElementById('card_difficulty').value = card.difficulty_level;
        document.getElementById('card_sort_order').value = card.sort_order;
        document.getElementById('card_active').checked = card.active == 1;
        document.getElementById('cardModal').classList.add('active');
    }
}

function closeCardModal() {
    document.getElementById('cardModal').classList.remove('active');
}

function escapeHtml(text) {
    const map = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
    return String(text).replace(/[&<>"']/g, m => map[m]);
}
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>



