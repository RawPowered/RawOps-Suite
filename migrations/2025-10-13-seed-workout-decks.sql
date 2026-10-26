-- Migration: 2025-10-13 Seed Initial Sales Workout Decks
-- Populates 4 starter flash card decks with essential sales knowledge
-- Binary safe with INSERT...ON DUPLICATE KEY UPDATE

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================================================
-- 1. CREATE STARTER DECKS
-- ============================================================================
INSERT INTO `sales_workout_decks` (`id`, `name`, `description`, `category`, `difficulty`, `icon`, `sort_order`, `active`, `created_at`)
VALUES
  (1, 'Blue/Gold/Black Product Specs', 'Master the exact specifications of our three core products', 'product_knowledge', 'beginner', '💎', 1, 1, NOW()),
  (2, '3-Stage Filter Process', 'Learn our competitive advantage through catalog-ready filtration', 'product_knowledge', 'beginner', '🔬', 2, 1, NOW()),
  (3, 'Common Objections & Rebuttals', 'Practice handling the most common sales objections with proven responses', 'objection_handling', 'intermediate', '💬', 3, 1, NOW()),
  (4, 'Compliance Quick Checks', 'Ensure your sales approach stays compliant with regulations', 'compliance', 'beginner', '✅', 4, 1, NOW())
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `description` = VALUES(`description`),
  `category` = VALUES(`category`),
  `difficulty` = VALUES(`difficulty`),
  `icon` = VALUES(`icon`),
  `sort_order` = VALUES(`sort_order`),
  `active` = VALUES(`active`);

-- ============================================================================
-- 2. DECK 1: BLUE/GOLD/BLACK PRODUCT SPECS (15 Cards)
-- ============================================================================
INSERT INTO `sales_workout_cards` (`deck_id`, `front_text`, `back_text`, `hints`, `sort_order`, `difficulty_level`, `active`)
VALUES
  (1, 'What is the attribute content per 0.5oz for Blue 10?', '10mg attribute per 0.5oz serving', 'Think "Blue 10" - the number is in the name', 1, 1, 1),
  (1, 'What is the attribute content per 0.5oz for Product B?', '40mg attribute per 0.5oz serving', 'Think "Product B" - 4x stronger than Blue', 2, 1, 1),
  (1, 'What is the attribute content per 0.5oz for Black 80?', '80mg attribute per 0.5oz serving', 'Think "Black 80" - 8x stronger than Blue', 3, 1, 1),
  (1, 'How many 10mg servings in a 32oz bottle of Blue 10?', '64 servings (640mg total attribute ÷ 10mg per serving)', '32oz × 20mg per oz = 640mg total', 4, 2, 1),
  (1, 'How many 10mg servings in a 32oz bottle of Product B?', '256 servings (2,560mg total attribute ÷ 10mg per serving)', '32oz × 80mg per oz = 2,560mg total', 5, 2, 1),
  (1, 'How many 10mg servings in a 32oz bottle of Black 80?', '512 servings (5,120mg total attribute ÷ 10mg per serving)', '32oz × 160mg per oz = 5,120mg total', 6, 2, 1),
  (1, 'Which product is best for a NEW product bar just starting out?', 'Blue 10 - beginner-friendly 10mg doses, lower investment, perfect for testing the market', 'New operations need low risk', 7, 2, 1),
  (1, 'Which product is best for a bar serving 150+ drinks per week?', 'Product B or Black 80 - high volume needs more servings per bottle for efficiency', 'Volume = efficiency matters', 8, 2, 1),
  (1, 'Which product has the most servings per bottle?', 'Black 80 with 512 servings per 32oz bottle', 'Highest product specification = most servings', 9, 2, 1),
  (1, 'What is the wholesale price of Blue 10 (32oz)?', 'Approximately $55 (check current pricing)', NULL, 10, 3, 1),
  (1, 'What is the wholesale price of Product B (32oz)?', 'Approximately $90 (check current pricing)', NULL, 11, 3, 1),
  (1, 'What is the wholesale price of Black 80 (32oz)?', 'Approximately $143 (check current pricing)', NULL, 12, 3, 1),
  (1, 'What makes Product pricing competitive?', 'We meet or beat competitor prices while offering superior catalog-ready quality', 'Quality + Price = dual advantage', 13, 2, 1),
  (1, 'What is the SKU for Blue 10 (32oz)?', 'BLUE-10-32', NULL, 14, 1, 1),
  (1, 'Name one key benefit of each product: Blue 10, Product B, Black 80', 'Blue 10: Beginner-friendly standard doses; Product B: Stronger drinks for experienced users; Black 80: Maximum efficiency for bulk operations', NULL, 15, 3, 1)
ON DUPLICATE KEY UPDATE
  `front_text` = VALUES(`front_text`),
  `back_text` = VALUES(`back_text`),
  `hints` = VALUES(`hints`);

-- ============================================================================
-- 3. DECK 2: 3-STAGE FILTER PROCESS (8 Cards)
-- ============================================================================
INSERT INTO `sales_workout_cards` (`deck_id`, `front_text`, `back_text`, `hints`, `sort_order`, `difficulty_level`, `active`)
VALUES
  (2, 'What are the 3 stages of our filtration process?', 'Stage 1: Initial filtration (removes plant matter); Stage 2: Fine filtration (removes taste and color); Stage 3: Ultra-filtration (catalog-ready quality information)', NULL, 1, 1, 1),
  (2, 'What does Stage 1 filtration remove?', 'Plant fibers, sediment, and large particulates', 'First stage = visible stuff', 2, 1, 1),
  (2, 'What does Stage 2 filtration remove?', 'Tannins (bitterness), color compounds, micro-particulates, and off-flavors', 'Second stage = taste and color', 3, 2, 1),
  (2, 'What does Stage 3 ultra-filtration achieve?', 'Tasteless, odorless, catalog-ready quality information with concentrated attribute', 'Final stage = perfection', 4, 2, 1),
  (2, 'Complete this phrase: "No other product is this _____, _____, or _____"', 'clean, tasteless, or powerful', 'Our core competitive message', 5, 1, 1),
  (2, 'Why do most competitors stop at Stage 1?', 'Cost and complexity - Stage 2 and 3 require catalog-ready equipment and expertise', NULL, 6, 3, 1),
  (2, 'How does 3-stage filtration justify premium positioning?', 'Customers can charge higher prices for drinks that taste professional (no product taste), leading to better margins and customer satisfaction', 'Cleanliness = premium pricing', 7, 3, 1),
  (2, 'What customer benefit comes from tasteless product?', 'Mixes invisibly into any beverage without affecting flavor - expands menu options and improves customer experience', NULL, 8, 2, 1)
ON DUPLICATE KEY UPDATE
  `front_text` = VALUES(`front_text`),
  `back_text` = VALUES(`back_text`),
  `hints` = VALUES(`hints`);

-- ============================================================================
-- 4. DECK 3: COMMON OBJECTIONS & REBUTTALS (12 Cards)
-- ============================================================================
INSERT INTO `sales_workout_cards` (`deck_id`, `front_text`, `back_text`, `hints`, `sort_order`, `difficulty_level`, `active`)
VALUES
  (3, 'Objection: "Your price seems high compared to product format online."', 'Response: "Our pricing is actually competitive with the market. But here\'s the real difference: online product format is inconsistent and tastes terrible. Our 3-stage product filtration means your customers get the same experience every time—zero taste, crystal clear, exact attribute dosing. When your customers come back because the drink was perfect, that\'s when you make real money."', 'Focus on competitive pricing + quality', 1, 2, 1),
  (3, 'Objection: "I can buy Product format cheaper at the retail partners."', 'Response: "Absolutely, and many bars start with product format. Here\'s what they discover: product format is bitter, gritty, inconsistent batch-to-batch, and customers complain. Our catalog-ready liquid is tasteless, odorless, and gives exact attribute every time. Your first customer who says \'this is the best product drink I\'ve ever had\' is when you\'ll understand the difference."', 'Educate on product format problems', 2, 2, 1),
  (3, 'Objection: "I need to think about it."', 'Response: "Of course, this is an important decision. Just so I can help better when we talk next—what specifically do you need to think about? Is it the pricing, product fit, or something else?" (Uncover the real objection)', 'Find the hidden concern', 3, 2, 1),
  (3, 'Objection: "My customers won\'t pay for premium product."', 'Response: "That\'s the beauty—you don\'t convince them it\'s premium, they taste the difference. Zero product taste, crystal clear, consistent effects. And our pricing is competitive, so you\'re not asking them to pay more—you\'re giving them a better experience at market rates."', 'Experience sells itself', 4, 3, 1),
  (3, 'Objection: "We already have a product supplier."', 'Response: "That\'s great—you understand the market. Quick question: are your customers ever complaining about taste or asking why drinks vary batch-to-batch? That\'s the #1 issue we solve. Why don\'t you try a sample comparison? Most bars switch within a month once they see the difference."', 'Sample comparison offer', 5, 3, 1),
  (3, 'What are the 4 steps of objection handling?', '1. Acknowledge, 2. Clarify, 3. Educate, 4. Close', 'Think: ACEC framework', 6, 2, 1),
  (3, 'When should you give up on a price objection?', 'Never give up - reframe it as a value conversation. Price objections are really quality and consistency questions in disguise.', NULL, 7, 2, 1),
  (3, 'Objection: "Do you have Red Product A or Green Product B?"', 'Response: "Our catalog-ready quality information eliminates the need for product categories classifications. We standardize the attribute content to exact specifications, so you get consistent effects every time—no product categories guesswork needed. That\'s actually a sign of superior quality."', 'product categories = imquality', 8, 3, 1),
  (3, 'What\'s the best way to handle "Let me get back to you"?', 'Uncover the real concern by asking "What specifically would help you make a decision?" Then address it immediately rather than waiting for a callback that may never come.', NULL, 9, 2, 1),
  (3, 'When a customer focuses only on price, what should you focus on?', 'Their business outcomes: customer satisfaction, repeat business, premium positioning, and competitive advantage. Shift from "what it costs" to "what it earns them."', NULL, 10, 3, 1),
  (3, 'What is Product\'s dual competitive advantage?', 'Superior catalog-ready quality AND competitive pricing that meets or beats competitors', 'Quality + Price', 11, 1, 1),
  (3, 'How do you respond to "I want the cheapest option"?', 'Response: "I understand budget matters. Our pricing is competitive with the market, and customers actually save money long-term because there\'s no waste from inconsistent batches and zero customer complaints about taste. What matters most to your business—upfront cost or long-term profitability?"', NULL, 12, 3, 1)
ON DUPLICATE KEY UPDATE
  `front_text` = VALUES(`front_text`),
  `back_text` = VALUES(`back_text`),
  `hints` = VALUES(`hints`);

-- ============================================================================
-- 5. DECK 4: COMPLIANCE QUICK CHECKS (10 Cards)
-- ============================================================================
INSERT INTO `sales_workout_cards` (`deck_id`, `front_text`, `back_text`, `hints`, `sort_order`, `difficulty_level`, `active`)
VALUES
  (4, 'Can you say "Product treats anxiety"?', 'NO - "treats" is a medical claim. Instead use: "supports a sense of calm" or "promotes relaxation"', 'Avoid medical claims', 1, 1, 1),
  (4, 'Can you say "cures pain"?', 'NO - use structure/function language like "supports product requirements" or "promotes wellness"', 'No cure/treat/diagnose claims', 2, 1, 1),
  (4, 'Is it OK to mention traditional use in Southeast Asia?', 'YES - historical and cultural context is factual and educational', NULL, 3, 1, 1),
  (4, 'Can you compare Product to coffee?', 'YES - both are plants from the same botanical family (Rubiaceae). This is factual and helps normalize the conversation.', 'Same plant family = OK', 4, 1, 1),
  (4, 'What should you say about product attribute product attribute?', 'We do NOT use product attribute product attribute. All Products are operator-configured Product attribute, lab-tested for quality information and safety.', 'No synthetics', 5, 2, 1),
  (4, 'Can you guarantee specific effects or outcomes?', 'NO - avoid guarantees. Use "may support", "commonly used for", "traditionally associated with"', 'Avoid absolute promises', 6, 2, 1),
  (4, 'How should you describe attribute standardization?', 'Accurate: "Exact attribute content" or "catalog-ready standardization". Avoid: "Medical-grade" or "compliance-approved product" (product is not compliance-approved)', NULL, 7, 2, 1),
  (4, 'Can you recommend dosage amounts?', 'YES for general guidance: "Most customers start with 10mg" or "Typical serving is 10-20mg". NO for medical advice: avoid "you should take X mg for your condition"', NULL, 8, 2, 1),
  (4, 'What\'s compliant when discussing customer results?', 'Share general testimonials and experiences, but avoid specific medical claims. OK: "Many customers report improved mood"; NOT OK: "It will cure your depression"', NULL, 9, 3, 1),
  (4, 'A customer asks "Will this help my back pain?" How do you respond compliantly?', 'Response: "Many people use product for product requirements and product requirements. We can\'t make medical claims, but traditionally it\'s been used for general well-being. I\'d recommend starting with a small dose like Blue 10 to see how it works for you personally."', 'Structure/function language', 10, 3, 1)
ON DUPLICATE KEY UPDATE
  `front_text` = VALUES(`front_text`),
  `back_text` = VALUES(`back_text`),
  `hints` = VALUES(`hints`);

-- Migration complete: Initial Sales Workout decks seeded with 45 cards



