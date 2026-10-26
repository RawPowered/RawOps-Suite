<h2>💎 Module 3: Product Mastery - Blue 10, Product B, Black 80</h2>

<div class="key-highlight">
    <h3>Learning Objectives</h3>
    <ul>
        <li>Master all three Product® core products: Blue 10, Product B, Black 80</li>
        <li>Match the right product to the right customer use case</li>
        <li>Explain pricing, servings, and profit potential for each product</li>
        <li>Understand when to recommend each product specification level</li>
    </ul>
</div>

<?php
// Load dynamic product data
require_once __DIR__ . '/../../../required/training-helpers.php';
$p_blue_10 = get_training_product('BLUE-10-32');
$p_gold_40 = get_training_product('GOLD-40-32');
$p_black_80 = get_training_product('BLACK-80-32');
?>

<div style="background: linear-gradient(135deg, #1f2937, #374151); color: white; padding: 32px; border-radius: 12px; margin: 32px 0;">
    <h3 style="color: white; margin-top: 0; text-align: center;">🎯 The Three Core Products You Need to Know</h3>
    <p style="text-align: center; font-size: 18px; opacity: 0.9; margin: 0;">
        Every sale starts with matching the customer to the right product. Master these three and you'll close 95% of deals.
    </p>
</div>

<h3>Product #1: Blue 10 - The Market Standard</h3>

<div style="background: linear-gradient(135deg, #dbeafe, #bfdbfe); padding: 24px; border-radius: 12px; margin: 24px 0; border: 2px solid #3b82f6;">
    <?php if ($p_blue_10): ?>
    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 24px;">
        <div style="text-align: center;">
            <div style="font-size: 72px; margin-bottom: 12px;">🔵</div>
            <h4 style="color: #1e40af; margin: 0;">Blue 10</h4>
            <div style="font-size: 14px; color: #1e40af; margin-top: 4px;">10mg attribute per 0.5oz</div>
        </div>
        
        <div style="background: white; padding: 20px; border-radius: 8px;">
            <h4 style="margin: 0 0 12px 0; color: #1f2937;"><?php echo htmlspecialchars($p_blue_10['name']); ?></h4>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <div style="font-size: 12px; color: #6b7280;">Your Cost</div>
                    <div style="font-size: 20px; font-weight: bold; color: #dc2626;"><?php echo format_currency($p_blue_10['wholesale_price']); ?></div>
                </div>
                <div>
                    <div style="font-size: 12px; color: #6b7280;">Total attribute</div>
                    <div style="font-size: 20px; font-weight: bold; color: #3b82f6;"><?php echo number_format($p_blue_10['total_mg']); ?>mg</div>
                </div>
                <div>
                    <div style="font-size: 12px; color: #6b7280;">Servings (10mg)</div>
                    <div style="font-size: 20px; font-weight: bold; color: #059669;"><?php echo $p_blue_10['servings_10mg']; ?> drinks</div>
                </div>
                <div>
                    <div style="font-size: 12px; color: #6b7280;">Cost per Serving</div>
                    <div style="font-size: 20px; font-weight: bold; color: #6b7280;"><?php echo format_currency($p_blue_10['cost_per_10mg_serving']); ?></div>
                </div>
            </div>
            
            <div style="background: #eff6ff; padding: 12px; border-radius: 6px; border-left: 4px solid #3b82f6;">
                <strong style="color: #1e40af;">Serving Size:</strong> <?php echo number_format($p_blue_10['oz_per_10mg_serving'], 2); ?>oz or <?php echo number_format($p_blue_10['ml_per_10mg_serving'], 1); ?>ml = 10mg attribute
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <div style="background: white; padding: 20px; border-radius: 8px; margin-top: 16px;">
        <h5 style="margin: 0 0 12px 0; color: #1e40af;">🎯 Best For:</h5>
        <ul style="margin: 0; color: #1e3a8a;">
            <li><strong>New Product Bars:</strong> Testing the market without big investment</li>
            <li><strong>Standard Drink Service:</strong> Most common customer requests (10mg doses)</li>
            <li><strong>Beginner-Friendly:</strong> Safe, reliable starting point</li>
            <li><strong>Consistent Daily Business:</strong> Repeat customers who want the same experience</li>
        </ul>
        
        <div style="background: #1e40af; color: white; padding: 12px; border-radius: 6px; margin-top: 16px; text-align: center;">
            <strong>Sales Pitch:</strong> "Perfect for building your customer base with reliable, consistent 10mg doses."
        </div>
    </div>
</div>

<h3>Product #2: Product B - The Power Upgrade</h3>

<div style="background: linear-gradient(135deg, #fef3c7, #fed7aa); padding: 24px; border-radius: 12px; margin: 24px 0; border: 2px solid #f59e0b;">
    <?php if ($p_gold_40): ?>
    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 24px;">
        <div style="text-align: center;">
            <div style="font-size: 72px; margin-bottom: 12px;">🟡</div>
            <h4 style="color: #92400e; margin: 0;">Product B</h4>
            <div style="font-size: 14px; color: #92400e; margin-top: 4px;">40mg attribute per 0.5oz</div>
        </div>
        
        <div style="background: white; padding: 20px; border-radius: 8px;">
            <h4 style="margin: 0 0 12px 0; color: #1f2937;"><?php echo htmlspecialchars($p_gold_40['name']); ?></h4>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <div style="font-size: 12px; color: #6b7280;">Your Cost</div>
                    <div style="font-size: 20px; font-weight: bold; color: #dc2626;"><?php echo format_currency($p_gold_40['wholesale_price']); ?></div>
                </div>
                <div>
                    <div style="font-size: 12px; color: #6b7280;">Total attribute</div>
                    <div style="font-size: 20px; font-weight: bold; color: #f59e0b;"><?php echo number_format($p_gold_40['total_mg']); ?>mg</div>
                </div>
                <div>
                    <div style="font-size: 12px; color: #6b7280;">Servings (10mg)</div>
                    <div style="font-size: 20px; font-weight: bold; color: #059669;"><?php echo $p_gold_40['servings_10mg']; ?> drinks</div>
                </div>
                <div>
                    <div style="font-size: 12px; color: #6b7280;">Cost per Serving</div>
                    <div style="font-size: 20px; font-weight: bold; color: #6b7280;"><?php echo format_currency($p_gold_40['cost_per_10mg_serving']); ?></div>
                </div>
            </div>
            
            <div style="background: #fffbeb; padding: 12px; border-radius: 6px; border-left: 4px solid #f59e0b;">
                <strong style="color: #92400e;">Serving Size:</strong> <?php echo number_format($p_gold_40['oz_per_10mg_serving'], 3); ?>oz or <?php echo number_format($p_gold_40['ml_per_10mg_serving'], 1); ?>ml = 10mg attribute
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <div style="background: white; padding: 20px; border-radius: 8px; margin-top: 16px;">
        <h5 style="margin: 0 0 12px 0; color: #92400e;">🎯 Best For:</h5>
        <ul style="margin: 0; color: #78350f;">
            <li><strong>Established Bars:</strong> Serving stronger drinks (15-20mg+ doses)</li>
            <li><strong>Experienced Customer Base:</strong> Regular product users who want more product specification</li>
            <li><strong>Higher Volume Operations:</strong> More servings per bottle = better efficiency</li>
            <li><strong>Premium Positioning:</strong> "Stronger drinks" command higher menu prices</li>
        </ul>
        
        <div style="background: #92400e; color: white; padding: 12px; border-radius: 6px; margin-top: 16px; text-align: center;">
            <strong>Sales Pitch:</strong> "4x stronger than Blue 10, perfect for customers who want elevated experiences."
        </div>
    </div>
</div>

<h3>Product #3: Black 80 - Maximum product specification Concentrate</h3>

<div style="background: linear-gradient(135deg, #fee2e2, #fecaca); padding: 24px; border-radius: 12px; margin: 24px 0; border: 3px solid #dc2626;">
    <?php if ($p_black_80): ?>
    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 24px;">
        <div style="text-align: center;">
            <div style="font-size: 72px; margin-bottom: 12px;">⚫</div>
            <h4 style="color: #dc2626; margin: 0;">Black 80</h4>
            <div style="font-size: 14px; color: #dc2626; margin-top: 4px; font-weight: 600;">80mg attribute per 0.5oz</div>
        </div>
        
        <div style="background: white; padding: 20px; border-radius: 8px;">
            <h4 style="margin: 0 0 12px 0; color: #1f2937;"><?php echo htmlspecialchars($p_black_80['name']); ?></h4>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <div style="font-size: 12px; color: #6b7280;">Your Cost</div>
                    <div style="font-size: 20px; font-weight: bold; color: #dc2626;"><?php echo format_currency($p_black_80['wholesale_price']); ?></div>
                </div>
                <div>
                    <div style="font-size: 12px; color: #6b7280;">Total attribute</div>
                    <div style="font-size: 20px; font-weight: bold; color: #dc2626;"><?php echo number_format($p_black_80['total_mg']); ?>mg</div>
                </div>
                <div>
                    <div style="font-size: 12px; color: #6b7280;">Servings (10mg)</div>
                    <div style="font-size: 20px; font-weight: bold; color: #059669;"><?php echo $p_black_80['servings_10mg']; ?> drinks</div>
                </div>
                <div>
                    <div style="font-size: 12px; color: #6b7280;">Cost per Serving</div>
                    <div style="font-size: 20px; font-weight: bold; color: #6b7280;"><?php echo format_currency($p_black_80['cost_per_10mg_serving']); ?></div>
                </div>
            </div>
            
            <div style="background: #fee2e2; padding: 12px; border-radius: 6px; border-left: 4px solid #dc2626;">
                <strong style="color: #dc2626;">Serving Size:</strong> <?php echo number_format($p_black_80['oz_per_10mg_serving'], 3); ?>oz or <?php echo number_format($p_black_80['ml_per_10mg_serving'], 1); ?>ml = 10mg attribute
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <div style="background: white; padding: 20px; border-radius: 8px; margin-top: 16px;">
        <h5 style="margin: 0 0 12px 0; color: #dc2626;">🎯 Best For:</h5>
        <ul style="margin: 0; color: #991b1b;">
            <li><strong>High-Volume Operations:</strong> Maximum attribute in minimal volume = better efficiency</li>
            <li><strong>Bulk Product Formulation:</strong> Creating pre-mixed drinks or retail products</li>
            <li><strong>Advanced Users:</strong> Customers who want 20mg+ doses</li>
            <li><strong>Maximum Profit Per Bottle:</strong> Most servings = highest revenue potential</li>
        </ul>
        
        <div style="background: #dc2626; color: white; padding: 12px; border-radius: 6px; margin-top: 16px; text-align: center;">
            <strong>Sales Pitch:</strong> "8x stronger than Blue 10—maximum attribute in minimum volume for serious operations."
        </div>
    </div>
    
    <div style="background: #991b1b; color: white; padding: 16px; border-radius: 8px; margin-top: 16px;">
        <strong>⚠️ Important:</strong> Black 80 is ultra-concentrated. Educate customers on precise measurement and starting with small test batches.
    </div>
</div>

<h3>Quick Comparison Chart</h3>

<table style="width: 100%; border-collapse: collapse; margin: 32px 0;">
    <thead>
        <tr style="background: #f9fafb;">
            <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Product</th>
            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">attribute/0.5oz</th>
            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Price</th>
            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Servings</th>
            <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Ideal Customer</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td style="padding: 12px; border-bottom: 1px solid #f3f4f6;"><strong style="color: #3b82f6;">🔵 Blue 10</strong></td>
            <td style="padding: 12px; text-align: center; border-bottom: 1px solid #f3f4f6;">10mg</td>
            <td style="padding: 12px; text-align: center; border-bottom: 1px solid #f3f4f6;"><?php echo $p_blue_10 ? format_currency($p_blue_10['wholesale_price']) : '$55.05'; ?></td>
            <td style="padding: 12px; text-align: center; border-bottom: 1px solid #f3f4f6;"><?php echo $p_blue_10 ? $p_blue_10['servings_10mg'] : '64'; ?></td>
            <td style="padding: 12px; border-bottom: 1px solid #f3f4f6;">New bars, standard drinks, beginners</td>
        </tr>
        <tr>
            <td style="padding: 12px; border-bottom: 1px solid #f3f4f6;"><strong style="color: #f59e0b;">🟡 Product B</strong></td>
            <td style="padding: 12px; text-align: center; border-bottom: 1px solid #f3f4f6;">40mg</td>
            <td style="padding: 12px; text-align: center; border-bottom: 1px solid #f3f4f6;"><?php echo $p_gold_40 ? format_currency($p_gold_40['wholesale_price']) : '$90.15'; ?></td>
            <td style="padding: 12px; text-align: center; border-bottom: 1px solid #f3f4f6;"><?php echo $p_gold_40 ? $p_gold_40['servings_10mg'] : '256'; ?></td>
            <td style="padding: 12px; border-bottom: 1px solid #f3f4f6;">Established bars, stronger drinks, experienced users</td>
        </tr>
        <tr>
            <td style="padding: 12px;"><strong style="color: #dc2626;">⚫ Black 80</strong></td>
            <td style="padding: 12px; text-align: center;">80mg</td>
            <td style="padding: 12px; text-align: center;"><?php echo $p_black_80 ? format_currency($p_black_80['wholesale_price']) : '$142.79'; ?></td>
            <td style="padding: 12px; text-align: center;"><?php echo $p_black_80 ? $p_black_80['servings_10mg'] : '512'; ?></td>
            <td style="padding: 12px;">High-volume operations, bulk formulation, maximum efficiency</td>
        </tr>
    </tbody>
</table>

<h3>Matching Customers to Products</h3>

<div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin: 32px 0;">
    <div style="background: #f0fdf4; padding: 20px; border-radius: 8px; border-left: 4px solid #16a34a;">
        <h5 style="color: #166534; margin: 0 0 12px 0;">Scenario A: New Bar</h5>
        <p style="font-size: 14px; color: #166534; margin: 0 0 12px 0;">
            "We're opening our first product bar next month."
        </p>
        <div style="background: #dcfce7; padding: 12px; border-radius: 6px;">
            <strong style="color: #166534;">Recommend:</strong> Blue 10<br>
            <strong>Why:</strong> Low risk, beginner-friendly, test market
        </div>
    </div>
    
    <div style="background: #fffbeb; padding: 20px; border-radius: 8px; border-left: 4px solid #f59e0b;">
        <h5 style="color: #92400e; margin: 0 0 12px 0;">Scenario B: Growing Bar</h5>
        <p style="font-size: 14px; color: #92400e; margin: 0 0 12px 0;">
            "We serve 100+ product drinks per week."
        </p>
        <div style="background: #fef3c7; padding: 12px; border-radius: 6px;">
            <strong style="color: #92400e;">Recommend:</strong> Product B<br>
            <strong>Why:</strong> More servings, stronger drinks, better margins
        </div>
    </div>
    
    <div style="background: #fef2f2; padding: 20px; border-radius: 8px; border-left: 4px solid #dc2626;">
        <h5 style="color: #dc2626; margin: 0 0 12px 0;">Scenario C: High-Volume</h5>
        <p style="font-size: 14px; color: #dc2626; margin: 0 0 12px 0;">
            "We need to pre-mix 20oz bottles or serve 200+ drinks weekly."
        </p>
        <div style="background: #fee2e2; padding: 12px; border-radius: 6px;">
            <strong style="color: #dc2626;">Recommend:</strong> Black 80<br>
            <strong>Why:</strong> Maximum efficiency, bulk formulation, lowest cost per mg
        </div>
    </div>
</div>

<h3>Simple Profit Math (Customer Perspective)</h3>

<div style="background: #f9fafb; padding: 24px; border-radius: 12px; margin: 32px 0; border: 2px solid #e5e7eb;">
    <h4 style="margin: 0 0 20px 0; text-align: center;">💰 Revenue Potential at $12/Drink (10mg Serving)</h4>
    
    <?php if ($p_blue_10 && $p_gold_40 && $p_black_80): ?>
    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
        <div style="background: white; padding: 16px; border-radius: 8px; border: 1px solid #e5e7eb;">
            <div style="text-align: center; margin-bottom: 12px;">
                <strong style="color: #3b82f6;">Blue 10</strong>
            </div>
            <div style="font-size: 13px; color: #6b7280;">
                <?php 
                $blue_revenue = $p_blue_10['servings_10mg'] * 12;
                $blue_cost = $p_blue_10['wholesale_price'];
                $blue_profit = $blue_revenue - $blue_cost - ($p_blue_10['servings_10mg'] * 3);
                ?>
                <div style="margin: 4px 0;">Revenue: <strong><?php echo format_currency($blue_revenue); ?></strong></div>
                <div style="margin: 4px 0;">Cost: <?php echo format_currency($blue_cost); ?></div>
                <div style="margin: 4px 0;">Base costs: <?php echo format_currency($p_blue_10['servings_10mg'] * 3); ?></div>
                <div style="margin: 12px 0 4px 0; padding-top: 8px; border-top: 1px solid #e5e7eb;">
                    <strong style="color: #059669;">Net Profit: <?php echo format_currency($blue_profit); ?></strong>
                </div>
            </div>
        </div>
        
        <div style="background: white; padding: 16px; border-radius: 8px; border: 1px solid #e5e7eb;">
            <div style="text-align: center; margin-bottom: 12px;">
                <strong style="color: #f59e0b;">Product B</strong>
            </div>
            <div style="font-size: 13px; color: #6b7280;">
                <?php 
                $gold_revenue = $p_gold_40['servings_10mg'] * 12;
                $gold_cost = $p_gold_40['wholesale_price'];
                $gold_profit = $gold_revenue - $gold_cost - ($p_gold_40['servings_10mg'] * 3);
                ?>
                <div style="margin: 4px 0;">Revenue: <strong><?php echo format_currency($gold_revenue); ?></strong></div>
                <div style="margin: 4px 0;">Cost: <?php echo format_currency($gold_cost); ?></div>
                <div style="margin: 4px 0;">Base costs: <?php echo format_currency($p_gold_40['servings_10mg'] * 3); ?></div>
                <div style="margin: 12px 0 4px 0; padding-top: 8px; border-top: 1px solid #e5e7eb;">
                    <strong style="color: #059669;">Net Profit: <?php echo format_currency($gold_profit); ?></strong>
                </div>
            </div>
        </div>
        
        <div style="background: white; padding: 16px; border-radius: 8px; border: 1px solid #e5e7eb;">
            <div style="text-align: center; margin-bottom: 12px;">
                <strong style="color: #dc2626;">Black 80</strong>
            </div>
            <div style="font-size: 13px; color: #6b7280;">
                <?php 
                $black_revenue = $p_black_80['servings_10mg'] * 12;
                $black_cost = $p_black_80['wholesale_price'];
                $black_profit = $black_revenue - $black_cost - ($p_black_80['servings_10mg'] * 3);
                ?>
                <div style="margin: 4px 0;">Revenue: <strong><?php echo format_currency($black_revenue); ?></strong></div>
                <div style="margin: 4px 0;">Cost: <?php echo format_currency($black_cost); ?></div>
                <div style="margin: 4px 0;">Base costs: <?php echo format_currency($p_black_80['servings_10mg'] * 3); ?></div>
                <div style="margin: 12px 0 4px 0; padding-top: 8px; border-top: 1px solid #e5e7eb;">
                    <strong style="color: #059669;">Net Profit: <?php echo format_currency($black_profit); ?></strong>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <div style="background: #059669; color: white; padding: 16px; border-radius: 8px; margin-top: 16px; text-align: center;">
        <em>Base cost = $3 per drink (water, flavors, ice, cup). These are conservative estimates—actual customer profits may be higher.</em>
    </div>
</div>

<h3>Knowledge Check</h3>

<div class="quiz-question">
    <p><strong>Question 1:</strong> A new product bar owner asks which product to start with. What do you recommend?</p>
    <div class="quiz-options">
        <label><input type="radio" name="q1" value="a"> Black 80 - they'll need the strongest eventually</label>
        <label><input type="radio" name="q1" value="b"> Blue 10 - perfect for testing the market with beginner-friendly 10mg doses</label>
        <label><input type="radio" name="q1" value="c"> Product B - it's the middle option</label>
    </div>
    <p style="margin-top: 16px; padding: 12px; background: #f0f9ff; border-radius: 8px; display: none;" class="answer" data-answer="b">
        <strong>Correct Answer: B</strong> - Blue 10 is ideal for new operations. Standard 10mg doses are beginner-friendly, and the lower investment helps them test their market without risk. You can always upsell to Product B or Black 80 once they're established.
    </p>
</div>

<div class="quiz-question">
    <p><strong>Question 2:</strong> A customer serves 200 drinks per week and wants maximum efficiency. Which product and why?</p>
    <div class="quiz-options">
        <label><input type="radio" name="q2" value="a"> Blue 10 - it's the most affordable</label>
        <label><input type="radio" name="q2" value="b"> Product B - good middle ground</label>
        <label><input type="radio" name="q2" value="c"> Black 80 - maximum attribute in minimum volume, lowest cost per serving, highest efficiency for bulk operations</label>
    </div>
    <p style="margin-top: 16px; padding: 12px; background: #f0f9ff; border-radius: 8px; display: none;" class="answer" data-answer="c">
        <strong>Correct Answer: C</strong> - Black 80 gives maximum servings per bottle, making it most efficient for high-volume operations. The concentrated format means less product handling and better cost per mg.
    </p>
</div>

<div class="quiz-question">
    <p><strong>Question 3:</strong> What is the attribute content per 0.5oz serving for each product?</p>
    <div class="quiz-options">
        <label><input type="radio" name="q3" value="a"> Blue 10 = 5mg, Product B = 20mg, Black 80 = 40mg</label>
        <label><input type="radio" name="q3" value="b"> Blue 10 = 10mg, Product B = 40mg, Black 80 = 80mg</label>
        <label><input type="radio" name="q3" value="c"> Blue 10 = 20mg, Product B = 60mg, Black 80 = 120mg</label>
    </div>
    <p style="margin-top: 16px; padding: 12px; background: #f0f9ff; border-radius: 8px; display: none;" class="answer" data-answer="b">
        <strong>Correct Answer: B</strong> - Blue 10 = 10mg per 0.5oz, Product B = 40mg per 0.5oz, Black 80 = 80mg per 0.5oz. These are standardized attribute contents you MUST memorize. (Note: per full ounce, these are 20mg, 80mg, and 160mg respectively.)
    </p>
</div>

<!-- Quiz Submission Section -->
<div id="quiz-submit-section" style="background: #f0f9ff; padding: 24px; border-radius: 12px; margin: 32px 0; text-align: center; border: 2px solid #3b82f6;">
    <h3 style="margin: 0 0 16px 0; color: #1e40af;">Ready to Check Your Answers?</h3>
    <p style="color: #6b7280; margin-bottom: 20px;">You must score 80% or higher to pass this module.</p>
    <button onclick="submitModuleQuiz(<?php echo $GLOBALS['current_module_id'] ?? 3; ?>)" class="btn" style="background: #059669; color: white; padding: 12px 32px; font-size: 16px; border: none; border-radius: 8px; cursor: pointer;">
        Submit Quiz Answers
    </button>
</div>

<div id="quiz-results" style="display: none;"></div>

<h3>💡 Key Takeaways</h3>

<div style="background: #f0f9ff; padding: 24px; border-radius: 12px; margin-top: 32px;">
    <ul style="margin: 0;">
        <li><strong>Blue 10:</strong> 10mg per 0.5oz, best for new bars and standard drinks, 64 servings/bottle</li>
        <li><strong>Product B:</strong> 40mg per 0.5oz, best for stronger drinks and established operations, 256 servings/bottle</li>
        <li><strong>Black 80:</strong> 80mg per 0.5oz, best for high-volume and bulk formulation, 512 servings/bottle</li>
        <li>Match customer volume and experience level to the right product</li>
        <li>All three products are competitively priced and catalog-ready pure</li>
        <li>Simple profit math: Revenue from drinks minus product cost minus base costs = net profit</li>
        <li>You can upsell from Blue → Gold → Black as customers grow</li>
    </ul>
</div>

<script src="<?php echo path_with_base('/required/module-quiz-handler.js'); ?>"></script>
<!-- Quiz answers hidden until submission - handled by global module.php logic -->

