<?php

require_once __DIR__ . '/config.php';

/**
 * Universal email template renderer with variable replacement
 * Handles Handlebars-style conditionals and simple variables
 */
function render_email_template(string $html, array $vars = []): string {
    // Handle {{#if variable}} content {{else}} alternate {{/if}}
    $html = preg_replace_callback(
        '/\{\{#if\s+(\w+)\}\}(.*?)(?:\{\{else\}\}(.*?))?\{\{\/if\}\}/s',
        function($matches) use ($vars) {
            $varName = $matches[1];
            $ifContent = $matches[2];
            $elseContent = $matches[3] ?? '';
            
            return !empty($vars[$varName]) ? $ifContent : $elseContent;
        },
        $html
    );
    
    // Replace simple {{variable}} placeholders
    foreach ($vars as $key => $value) {
        $html = str_replace('{{' . $key . '}}', htmlspecialchars((string)$value), $html);
    }
    
    // Apply base URL placeholders
    $baseUrl = site_base_url();
    $html = str_replace('{{base_url}}', $baseUrl, $html);
    $html = str_replace('{{assets_base_url}}', $baseUrl . '/assets', $html);
    
    // NEW: Process shortcodes for dynamic content
    if (strpos($html, '[') !== false && file_exists(__DIR__ . '/shortcode-engine.php')) {
        require_once __DIR__ . '/shortcode-engine.php';
        $html = process_shortcodes($html, $vars);
    }
    
    return $html;
}






