<?php
// product/required/ai-knowledge-retriever.php
// Service to retrieve context from the database and knowledge files for the AI assistant.

class KnowledgeRetriever {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Main function to get all relevant context for a given user query.
     * @param string $query The user's message/question.
     * @return string A compiled string of context to be injected into the AI prompt.
     */
    public function getContext(string $query): string {
        $context = '';
        
        // Step 1: Search for relevant products in the database.
        $productContext = $this->searchProducts($query);
        if ($productContext) {
            $context .= "PRODUCT INFORMATION:\n" . $productContext . "\n\n";
        }
        
        // Step 2: Search file-based knowledge (knowledgebase.ts, etc.)
        $fileContext = $this->searchKnowledgeFiles($query);
        if ($fileContext) {
            $context .= "RELEVANT SALES & TRAINING INFO:\n" . $fileContext . "\n\n";
        }
        
        // TODO: Step 3: Get user-specific context (past orders, etc.) if logged in.
        
        // Step 4: Add high-quality examples from logs as few-shot prompts.
        $fewShotExamples = $this->getFewShotExamples($query);
        if ($fewShotExamples) {
            $context .= "LEARNING EXAMPLES (use these as a guide for tone and accuracy):\n" . $fewShotExamples . "\n\n";
        }

        return $context;
    }

    /**
     * Searches for products in the database that are relevant to the query.
     * @param string $query The user's query.
     * @return string|null Context string if relevant products are found, otherwise null.
     */
    private function searchProducts(string $query): ?string {
        // A simple keyword matching strategy to decide if we should inject product data.
        $keywords = preg_split('/\s+/', strtolower($query));
        $productKeywords = ['product', 'buy', 'price', 'cost', 'blend', 'product', 'formula', 'bottle', 'oz', 'mg', 'how much'];

        $isProductQuery = false;
        foreach ($keywords as $keyword) {
            if (in_array($keyword, $productKeywords)) {
                $isProductQuery = true;
                break;
            }
        }
        
        if (!$isProductQuery) {
            return null;
        }

        // Include the centralized product data file.
        $product_data_path = __DIR__ . '/product-data.php';
        if (!file_exists($product_data_path)) {
            return "Error: Product data file is missing.";
        }
        require $product_data_path; // This will make $products and $bulk_discounts available.

        if (empty($products) || !is_array($products)) {
            return "No product data available.";
        }

        $productContext = "Here are the currently available products with their specifications:\n";
        foreach ($products as $product) {
            $productContext .= "- Name: {$product['name']}, SKU: {$product['sku']}, Price: \${$product['sale_price']} (MSRP: \${$product['msrp']}). Specs: Total attribute {$product['total_mg']}mg, {$product['mg_per_oz']}mg/oz, {$product['bottle_size']}oz bottle, {$product['servings']} servings of {$product['serving_size_oz']}oz.\n";
        }
        
        if (!empty($bulk_discounts)) {
            $productContext .= "\nBULK DISCOUNTS:\n";
            foreach ($bulk_discounts as $discount) {
                $productContext .= "- {$discount['qty']} bottles: {$discount['discount']}% off\n";
            }
        }

        $productContext .= "\nPROFIT CALCULATION LOGIC:\n";
        $productContext .= "To calculate a customer's profit, use this formula: (Total Servings * Price Per Drink) - Wholesale Price = Net Profit. The 'Price Per Drink' is set by the customer (e.g., $10-$12). Use the detailed product specs above to find the 'Total Servings' and 'Wholesale Price'. Always explain the high return on investment.";

        return $productContext;
    }

    /**
     * Searches knowledge base files for relevant context.
     * @param string $query The user's query.
     * @return string|null Context string if relevant info is found, otherwise null.
     */
    private function searchKnowledgeFiles(string $query): ?string
    {
        $knowledgeFiles = [
            'knowledgebase.ts' => ['objection', 'competitor', 'quality', 'legal', 'price', 'cost'],
            'ten_step_close.ts' => ['close', 'sell', 'step', 'process', 'sale', 'sales'],
            'sales-learning.ts' => ['learn', 'improve', 'technique', 'ai', 'agent'],
        ];

        $relevantContent = '';
        $keywords = preg_split('/\s+/', strtolower($query));

        foreach ($knowledgeFiles as $file => $fileKeywords) {
            $isRelevant = false;
            foreach ($keywords as $keyword) {
                if (in_array($keyword, $fileKeywords)) {
                    $isRelevant = true;
                    break;
                }
            }

            if ($isRelevant) {
                $filePath = __DIR__ . '/../00-PLANNING/' . $file;
                if (file_exists($filePath)) {
                    // In a real app, parsing would be more sophisticated.
                    // For now, we return a snippet or a summary.
                    $content = file_get_contents($filePath);
                    // Simple approach: find the keyword and get some surrounding text.
                    // This is a basic implementation of RAG file chunking.
                    foreach ($keywords as $keyword) {
                        if (strpos($content, $keyword) !== false) {
                           $relevantContent .= "Found relevant content in '{$file}' related to '{$keyword}'.\n";
                           // A more advanced implementation would extract the relevant JSON/TS block.
                           // For now, just indicating the source is useful context for the LLM.
                        }
                    }
                }
            }
        }
        
        return $relevantContent !== '' ? $relevantContent : null;
    }

    /**
     * Retrieves high-quality question/answer pairs from logs to use as examples.
     * @param string $query The user's query.
     * @return string|null Examples string if relevant logs are found, otherwise null.
     */
    private function getFewShotExamples(string $query): ?string
    {
        // This is a simplified approach. A real implementation would use
        // semantic search (vector similarity) to find the most relevant examples.
        // For now, we'll just grab the highest-rated entries.
        try {
            $stmt = $this->pdo->prepare(
                "SELECT user_message, ai_response FROM ai_chat_logs WHERE feedback = 1 AND corrected_response IS NULL ORDER BY created_at DESC LIMIT 3"
            );
            $stmt->execute();
            $examples = $stmt->fetchAll();

            if (empty($examples)) {
                return null;
            }

            $exampleContext = "";
            foreach ($examples as $example) {
                $exampleContext .= "User Question: \"{$example['user_message']}\"\nGood AI Response: \"{$example['ai_response']}\"\n---\n";
            }
            return $exampleContext;

        } catch (Throwable $e) {
            error_log('AI Few-Shot Retriever Error: ' . $e->getMessage());
            return null;
        }
    }
}
