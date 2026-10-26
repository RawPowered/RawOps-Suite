<?php
/**
 * SendGrid PHP sender for Product‑It Product
 * - Uses SDK if available, falls back to direct HTTP (cURL) otherwise
 * - Accepts HTTP query/body params and CLI flags
 * - Can render the welcome email template with simple variable replacement
 * - Supports sandbox mode for non-delivery verification
 */

// Try to load Composer if present; OK if missing (we'll fall back to cURL)
$__autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($__autoload)) {
    require_once $__autoload;
}

function readEnv(string $key, ?string $default = null): ?string {
    $v = getenv($key);
    return $v !== false ? $v : $default;
}

function getRequestParam(string $key, ?string $default = null): ?string {
    if (php_sapi_name() === 'cli') {
        $long = [
            'to:', 'to_name::', 'from::', 'from_name::', 'subject::', 'html::', 'text::',
            'template::', 'first_name::', 'login_url::', 'sandbox::'
        ];
        $opts = getopt('', $long);
        if ($opts !== false && array_key_exists($key, $opts)) {
            $val = $opts[$key];
            if ($val === false) { return '1'; }
            return is_array($val) ? end($val) : $val;
        }
    }
    if (isset($_GET[$key])) { return (string)$_GET[$key]; }
    if (isset($_POST[$key])) { return (string)$_POST[$key]; }
    return $default;
}

if (!function_exists('sg_base_url')) {
    function sg_base_url(): string {
        static $base = null;
        if ($base !== null) {
            return $base;
        }
        if (function_exists('site_base_url')) {
            $candidate = site_base_url();
        } elseif (($env = getenv('SITE_BASE_URL')) !== false && $env !== null && $env !== '') {
            $candidate = $env;
        } elseif (($envFallback = getenv('PRODUCT_BASE_URL')) !== false && $envFallback !== null && $envFallback !== '') {
            $candidate = $envFallback;
        } else {
            $candidate = 'https://product.example';
        }
        $candidate = rtrim((string)$candidate, '/');
        if ($candidate === '') {
            $candidate = 'https://product.example';
        }
        $base = $candidate;
        return $base;
    }
}

if (!function_exists('sg_apply_base_placeholders')) {
    function sg_apply_base_placeholders(string $content): string {
        if ($content === '') {
            return $content;
        }
        $base = sg_base_url();
        $replacements = [
            '{{base_url}}' => $base,
            '{{assets_base_url}}' => $base . '/assets'
        ];
        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }
}

function renderWelcomeTemplate(string $html, ?string $firstName, ?string $loginUrl): string {
    // Replace greeting conditional block
    $greeting = $firstName ? 'Hi ' . htmlspecialchars($firstName) . ',' : 'Hi there,';
    $html = preg_replace(
        '/\{\{#if first_name\}\}(.|\n)*?\{\{\/if\}\}/',
        $greeting,
        $html
    );

    // Replace login_url conditional blocks
    $resolvedLoginUrl = $loginUrl ?: (sg_base_url() . '/login.php');
    $html = preg_replace(
        '/\{\{#if login_url\}\}(.|\n)*?\{\{\/if\}\}/',
        $resolvedLoginUrl,
        $html
    );

    // Also replace simple occurrences of {{first_name}} and {{login_url}}
    $html = str_replace(['{{first_name}}', '{{login_url}}'], [htmlspecialchars((string)$firstName), $resolvedLoginUrl], $html);
    return sg_apply_base_placeholders($html);
}

function loadTemplate(string $name, array $vars): string {
    $base = realpath(__DIR__ . '/../admin/emails');
    if (!$base) { throw new RuntimeException('Template base not found'); }
    $file = $base . DIRECTORY_SEPARATOR . $name . '.html';
    if (!is_file($file)) { throw new RuntimeException('Template not found: ' . $name); }
    $html = (string)file_get_contents($file);
    
    // Use universal renderer if available
    if (function_exists('render_email_template')) {
        return render_email_template($html, $vars);
    }
    
    // Universal template rendering for all templates
    $html = renderEmailTemplate($html, $vars);
    $html = sg_apply_base_placeholders($html);
    
    return $html;
}

/**
 * Universal email template renderer
 * Handles {{variable}} and {{#if variable}}...{{else}}...{{/if}} syntax
 */
function renderEmailTemplate(string $html, array $vars): string {
    // Handle conditional blocks: {{#if variable}}...{{else}}...{{/if}}
    $html = preg_replace_callback(
        '/\{\{#if\s+([a-zA-Z_][a-zA-Z0-9_]*)\}\}(.*?)\{\{\/if\}\}/s',
        function($matches) use ($vars) {
            $varName = $matches[1];
            $content = $matches[2];
            
            // Check if there's an {{else}} block
            if (strpos($content, '{{else}}') !== false) {
                list($ifBlock, $elseBlock) = explode('{{else}}', $content, 2);
                return !empty($vars[$varName]) ? $ifBlock : $elseBlock;
            }
            
            // No else block, just show if truthy
            return !empty($vars[$varName]) ? $content : '';
        },
        $html
    );
    
    // Replace simple variables: {{variable}}
    foreach ($vars as $key => $value) {
        $html = str_replace('{{' . $key . '}}', htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'), $html);
    }
    
    return $html;
}

/**
 * Inject tracking links into email HTML
 * Replaces all external links with tracking redirect
 */
function inject_tracking_links(string $html, string $token, string $baseUrl): string {
    if (!$token) {
        return $html;
    }
    
    // Replace href links with tracking redirects
    return preg_replace_callback(
        '/<a([^>]*?)href=["\']([^"\']+)["\']([^>]*?)>/i',
        function($matches) use ($token, $baseUrl) {
            $url = $matches[2];
            
            // Only track external http/https links
            if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
                $trackUrl = $baseUrl . '/api/email-track-conversion.php?action=track_click&token=' . urlencode($token) . '&url=' . urlencode($url);
                return '<a' . $matches[1] . 'href="' . htmlspecialchars($trackUrl, ENT_QUOTES) . '"' . $matches[3] . '>';
            }
            
            // Don't track internal anchors or mailto/tel links
            return $matches[0];
        },
        $html
    );
}

function generate_tracking_pixel_tag(string $token, string $baseUrl): string {
    if (!$token) {
        return '';
    }
    $pixelUrl = $baseUrl . '/api/email-track-conversion.php?action=track_open&token=' . urlencode($token) . '&t=' . time();
    return '<img src="' . htmlspecialchars($pixelUrl, ENT_QUOTES) . '" alt="" width="1" height="1" style="display:none!important;max-height:1px;max-width:1px;" />';
}

function append_tracking_pixel(string $html, string $token, string $baseUrl): string {
    if (!$token) {
        return $html;
    }
    $pixelTag = generate_tracking_pixel_tag($token, $baseUrl);
    if (strpos($html, '{{tracking_pixel}}') !== false) {
        return str_replace('{{tracking_pixel}}', $pixelTag, $html);
    }
    if (stripos($html, '</body>') !== false) {
        return preg_replace('/<\/body>/i', $pixelTag . '</body>', $html, 1);
    }
    return $html . $pixelTag;
}

function buildPayload(array $params): array {
    $to = $params['to'];
    $toName = $params['to_name'] ?? '';
    $from = $params['from'] ?? 'sample-006@example.com';
    $fromName = $params['from_name'] ?? 'Product';
    $subject = $params['subject'] ?? 'Welcome to Product';
    $text = $params['text'] ?? 'Welcome to Product! Your account is ready.';
    $html = $params['html'] ?? '';
    $sandbox = !empty($params['sandbox']);

    if (!$html && !empty($params['template'])) {
        $tplName = $params['template'];
        // Allow both short key "welcome" and full filename without extension
        if ($tplName === 'welcome') { $tplName = 'welcome-email-template-sendgrid'; }
        $html = loadTemplate($tplName, $params);
    }

    if (!$html) { $html = '<p>' . htmlspecialchars($text) . '</p>'; }

    $html = sg_apply_base_placeholders($html);
    $text = sg_apply_base_placeholders($text);
    
    $trackingToken = $params['tracking_token'] ?? null;
    if ($trackingToken) {
        $baseUrl = sg_base_url();
        $html = inject_tracking_links($html, $trackingToken, $baseUrl);
        $html = append_tracking_pixel($html, $trackingToken, $baseUrl);
    }

    $payload = [
        'personalizations' => [
            [
                'to' => [['email' => $to, 'name' => $toName]],
                'subject' => $subject
            ]
        ],
        'from' => ['email' => $from, 'name' => $fromName],
        'content' => [
            ['type' => 'text/plain', 'value' => $text],
            ['type' => 'text/html', 'value' => $html]
        ],
        'tracking_settings' => [
            'click_tracking' => ['enable' => true, 'enable_text' => false],
            'open_tracking' => ['enable' => true, 'substitution_tag' => '%open-track%'],
            'subscription_tracking' => ['enable' => false]
        ]
    ];

    if ($sandbox) {
        $payload['mail_settings'] = [ 'sandbox_mode' => ['enable' => true] ];
    }
    
    $customArgs = [];
    if (!empty($params['custom_args']) && is_array($params['custom_args'])) {
        foreach ($params['custom_args'] as $key => $value) {
            if ($value === null) {
                continue;
            }
            $customArgs[$key] = (string)$value;
        }
    }
    if ($trackingToken) {
        $customArgs['tracking_token'] = $trackingToken;
    }
    if (!empty($customArgs)) {
        $payload['custom_args'] = $customArgs;
    }

    return $payload;
}

function sendWithSdk(string $apiKey, array $payload): array {
    $email = new \SendGrid\Mail\Mail();
    $p = $payload;
    $email->setFrom($p['from']['email'], $p['from']['name'] ?? null);
    $to = $p['personalizations'][0]['to'][0];
    $email->addTo($to['email'], $to['name'] ?? null);
    $email->setSubject($p['personalizations'][0]['subject'] ?? '');
    foreach ($p['content'] as $c) {
        $email->addContent($c['type'], $c['value']);
    }
    if (isset($p['mail_settings']['sandbox_mode']['enable']) && $p['mail_settings']['sandbox_mode']['enable']) {
        $email->setMailSettings((new \SendGrid\Mail\MailSettings())->setSandboxMode((new \SendGrid\Mail\SandboxMode())->setEnable(true)));
    }
    if (!empty($p['custom_args'])) {
        foreach ($p['custom_args'] as $key => $value) {
            $email->addCustomArg($key, $value);
        }
    }

    $sg = new \SendGrid($apiKey);
    $response = $sg->send($email);
    return [
        'status' => $response->statusCode(),
        'headers' => $response->headers(),
        'body' => $response->body(),
    ];
}

function sendWithCurl(string $apiKey, array $payload, bool $insecure = false, ?string $cacertPath = null): array {
    if (!function_exists('curl_init')) {
        return sendWithHttp($apiKey, $payload);
    }
    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 30
    ]);
    if ($insecure) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    } else {
        // Prefer provided cacert path, else environment variable CURL_CA_BUNDLE
        if ($cacertPath && file_exists($cacertPath)) {
            curl_setopt($ch, CURLOPT_CAINFO, $cacertPath);
        } elseif (($envBundle = getenv('CURL_CA_BUNDLE')) && file_exists($envBundle)) {
            curl_setopt($ch, CURLOPT_CAINFO, $envBundle);
        }
    }
    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['status' => 0, 'error' => $err];
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headersRaw = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);
    return [
        'status' => $status,
        'headers' => $headersRaw,
        'body' => $body
    ];
}

function sendWithHttp(string $apiKey, array $payload): array {
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Authorization: Bearer $apiKey\r\n" .
                        "Content-Type: application/json\r\n",
            'content' => json_encode($payload),
            'ignore_errors' => true,
            'timeout' => 30
        ]
    ]);
    $url = 'https://api.sendgrid.com/v3/mail/send';
    $result = @file_get_contents($url, false, $context);
    $statusLine = isset($http_response_header[0]) ? $http_response_header[0] : '';
    $status = 0;
    if (preg_match('#HTTP/\S+\s+(\d{3})#', $statusLine, $m)) {
        $status = (int)$m[1];
    }
    $headersRaw = isset($http_response_header) ? implode("\n", $http_response_header) : null;
    if ($result === false && $status === 0) {
        $err = error_get_last();
        return ['status' => 0, 'headers' => $headersRaw, 'body' => null, 'error' => $err['message'] ?? 'HTTP request failed'];
    }
    return ['status' => $status, 'headers' => $headersRaw, 'body' => $result];
}

/**
 * Public helper that can be included and invoked by the app code.
 * Returns a structured array: ['ok'=>bool,'used'=>'sdk|curl','status'=>int,'headers'=>mixed,'body'=>mixed,'error'=>?string]
 */
function sendgrid_send(array $params): array {
    $apiKey = readEnv('SENDGRID_API_KEY');
    if (!$apiKey) {
        return ['ok' => false, 'used' => null, 'status' => 0, 'headers' => null, 'body' => null, 'error' => 'SENDGRID_API_KEY is not set in environment'];
    }
    if (empty($params['to']) || !filter_var($params['to'], FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'used' => null, 'status' => 400, 'headers' => null, 'body' => null, 'error' => 'Invalid recipient email address'];
    }
    $payload = buildPayload($params);
    $usedSdk = class_exists('SendGrid\\Mail\\Mail');
    $result = $usedSdk
        ? sendWithSdk($apiKey, $payload)
        : sendWithCurl($apiKey, $payload, !empty($params['insecure']), $params['cacert'] ?? null);
    $ok = isset($result['status']) && $result['status'] >= 200 && $result['status'] < 300;
    return [
        'ok' => $ok,
        'used' => $usedSdk ? 'sdk' : 'curl',
        'status' => $result['status'] ?? 0,
        'headers' => $result['headers'] ?? null,
        'body' => $result['body'] ?? null,
        'error' => $result['error'] ?? null
    ];
}

// If executed via CLI or web request, act as an endpoint
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $params = [
        'to' => getRequestParam('to', 'sample-018@example.com'),
        'to_name' => getRequestParam('to_name', ''),
        'from' => getRequestParam('from', 'sample-006@example.com'),
        'from_name' => getRequestParam('from_name', 'Product'),
        'subject' => getRequestParam('subject', 'Welcome to Product'),
        'text' => getRequestParam('text', 'Welcome to Product! Your account is ready.'),
        'html' => getRequestParam('html', ''),
        'template' => getRequestParam('template', ''),
        'first_name' => getRequestParam('first_name', ''),
        'login_url' => getRequestParam('login_url', ''),
        'sandbox' => getRequestParam('sandbox', ''),
        'insecure' => getRequestParam('insecure', ''),
        'cacert' => getRequestParam('cacert', '')
    ];

    try {
        $result = sendgrid_send($params);
        http_response_code($result['ok'] ? 200 : ($result['status'] ?: 500));
        echo json_encode($result);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}