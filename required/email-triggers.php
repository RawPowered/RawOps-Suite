<?php
// required/email-triggers.php
// Centralized handler for dispatching automated emails based on system events.

require_once __DIR__ . '/config.php';
// SendGrid integration for transactional emails
require_once __DIR__ . '/../api/sendgrid.php';
require_once __DIR__ . '/email-template-renderer.php';
require_once __DIR__ . '/json_sanitizer.php';

if (!function_exists('log_notification_event')) {
    /**
     * Persist notification event diagnostics into database table email_notification_logs
     *
     * @param PDO    $pdo
     * @param string $eventKey
     * @param int    $userId
     * @param array  $payload
     * @param array  $response
     * @param bool   $success
     *
     * @return void
     */
    function log_notification_event(PDO $pdo, string $eventKey, int $userId, array $payload, array $response, bool $success, array $context = []): void
    {
        try {
            $encodedPayload = ['send_payload' => $payload];
            if (!empty($context)) {
                $encodedPayload['context'] = $context;
            }
            $payloadSafe = json_encode(json_sanitize_for_encoding($encodedPayload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $responseSafe = json_encode(json_sanitize_for_encoding($response), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $pdo->prepare('INSERT INTO email_notification_logs (event_key,user_id,payload_json,response_json,delivered,created_at) VALUES (?,?,?,?,?,NOW())')
                ->execute([
                    $eventKey,
                    $userId,
                    $payloadSafe,
                    $responseSafe,
                    $success ? 1 : 0
                ]);
        } catch (Throwable $e) {
            // silently ignore logging failures
        }
    }
}

if (!function_exists('probe_notification_event')) {
    /**
     * Dry-run probe for an email event without sending.
     * Returns matched rules and rendered payload data.
     */
    function probe_notification_event(string $eventKey, int $userId, PDO $pdo, array $context = []): array
    {
        $details = [
            'event' => $eventKey,
            'user_id' => $userId,
            'rules' => [],
            'user' => null
        ];

        $userStmt = $pdo->prepare('SELECT id, email, first_name, last_name FROM users WHERE id = ?');
        $userStmt->execute([$userId]);
        $details['user'] = $userStmt->fetch() ?: null;
        if (!$details['user']) {
            return $details + ['error' => 'User not found'];
        }

        $fullName = trim(($details['user']['first_name'] ?? '') . ' ' . ($details['user']['last_name'] ?? ''));
        $basePlaceholders = [
            'first_name' => $details['user']['first_name'] ?? '',
            'last_name' => $details['user']['last_name'] ?? '',
            'firstName' => $details['user']['first_name'] ?? '',
            'lastName' => $details['user']['last_name'] ?? '',
            'fullName' => $fullName,
            'email' => $details['user']['email'] ?? '',
        ];
        $placeholders = normalize_email_placeholders($basePlaceholders, $context);

        $ruleStmt = $pdo->prepare(
            "SELECT r.id, r.is_active, t.subject, t.html_body, t.text_body, t.slug, t.scope
             FROM email_notification_rules r
             JOIN email_templates t ON r.template_id = t.id
             WHERE r.event_key = ?"
        );
        $ruleStmt->execute([$eventKey]);
        $rules = $ruleStmt->fetchAll();

        foreach ($rules as $rule) {
            $details['rules'][] = [
                'rule_id' => $rule['id'],
                'active' => (bool)$rule['is_active'],
                'template_slug' => $rule['slug'],
                'scope' => $rule['scope'],
                'render_preview' => [
                    'subject' => str_replace(array_keys($placeholders), array_values($placeholders), $rule['subject'] ?? ''),
                    'html' => str_replace(array_keys($placeholders), array_values($placeholders), $rule['html_body'] ?? ''),
                    'text' => str_replace(array_keys($placeholders), array_values($placeholders), $rule['text_body'] ?? ''),
                ]
            ];
        }

        $details['context'] = $context;
        return $details;
    }
}

if (!function_exists('normalize_email_placeholders')) {
    function normalize_email_placeholders(array $base, array $context): array
    {
        foreach ($context as $key => $value) {
            if (!is_string($key)) { continue; }
            $base[$key] = $value;
            if (strpos($key, '{{') === false) {
                $base['{{' . $key . '}}'] = $value;
            }
        }
        return $base;
    }
}

if (!function_exists('extract_plain_placeholders')) {
    function extract_plain_placeholders(array $placeholders): array
    {
        $plain = [];
        foreach ($placeholders as $key => $value) {
            if (strpos($key, '{{') === false) {
                $plain[$key] = $value;
            }
        }
        return $plain;
    }
}

if (!function_exists('json_clean_string')) {
    function json_clean_string(string $string): string
    {
        // ... existing code ...
    }
}

if (!function_exists('email_debug_dump')) {
    function email_debug_dump(string $eventKey, array $payload, array $result, array $extra = []): void
    {
        try {
            if (!(!empty($_ENV['EMAIL_DEBUG_VERBOSE']) || getenv('EMAIL_DEBUG_VERBOSE') === '1')) {
                return;
            }
            $dir = __DIR__ . '/logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $stamp = gmdate('Ymd_His');
            $id = bin2hex(random_bytes(4));
            $file = sprintf('%s/email-debug-%s-%s.json', $dir, $stamp, $id);
            $blob = [
                'event' => $eventKey,
                'timestamp' => gmdate('c'),
                'payload' => json_sanitize_for_encoding($payload),
                'result' => json_sanitize_for_encoding($result),
                'extra' => json_sanitize_for_encoding($extra)
            ];
            @file_put_contents($file, json_encode($blob, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (Throwable $e) {
            // ignore
        }
    }
}

/**
 * Triggers automated email notifications for all active rules matching an event key.
 *
 * @param string $eventKey The key for the event (e.g., 'first_signup', 'first_purchase').
 * @param int    $userId   The user associated with the event.
 * @param array  $context  Additional key/value pairs for placeholder replacement.
 *
 * @return int Number of emails successfully queued/sent.
 */
function trigger_email_notification(string $eventKey, int $userId, array $context = []): int
{
    $sentCount = 0;

    try {
        $pdo = db();

        // Load user basics for personalization
        $userStmt = $pdo->prepare("SELECT email, first_name, last_name FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();
        if (!$user || empty($user['email'])) { return 0; }

        $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        $basePlaceholders = [
            'first_name' => $user['first_name'] ?? '',
            'last_name' => $user['last_name'] ?? '',
            'firstName' => $user['first_name'] ?? '',
            'lastName' => $user['last_name'] ?? '',
            'fullName' => $fullName,
            'email' => $user['email'],
            'login_url' => path_with_base('/login.php')
        ];
        $placeholders = normalize_email_placeholders($basePlaceholders, $context);
        $plainPlaceholderContext = extract_plain_placeholders($placeholders);

        // Default behavior for first signup: always send a transactional welcome via SendGrid
        if ($eventKey === 'first_signup') {
            $signupType = $context['signup_type'] ?? '';
            $fileTemplate = 'welcome-email-template-sendgrid';
            $subject = 'Welcome to Product‑It Product';
            if ($signupType === 'wholesale') { $fileTemplate = 'welcome-wholesale-sendgrid'; $subject = 'Welcome to Product‑It Wholesale'; }
            if ($signupType === 'trainee') { $fileTemplate = 'welcome-agent-sendgrid'; $subject = 'Welcome to the Product‑It Sales Team'; }

            // Prefer DB-managed template if present (so admin edits are respected)
            $slugMap = [
                'welcome-email-template-sendgrid' => 'welcome-general-sendgrid', // allow optional alias
                'welcome-wholesale-sendgrid' => 'welcome-wholesale-sendgrid',
                'welcome-agent-sendgrid' => 'welcome-agent-sendgrid'
            ];
            $slug = $slugMap[$fileTemplate] ?? $fileTemplate;
            $tplStmt = $pdo->prepare('SELECT subject, html_body, text_body FROM email_templates WHERE slug = ? AND is_active = 1 ORDER BY updated_at DESC LIMIT 1');
            $tplStmt->execute([$slug]);
            $dbTpl = $tplStmt->fetch();

            $success = false;
            $responseMeta = [];

            if ($dbTpl) {
                // Use universal renderer for proper conditional handling
                $subjectToSend = render_email_template($dbTpl['subject'] ?: $subject, $plainPlaceholderContext);
                $htmlBody = render_email_template((string)$dbTpl['html_body'], $plainPlaceholderContext);
                $textBody = $dbTpl['text_body'] ? render_email_template((string)$dbTpl['text_body'], $plainPlaceholderContext) : strip_tags($htmlBody);

                $payload = [
                    'to' => $user['email'],
                    'to_name' => $placeholders['fullName'] ?? $fullName,
                    'from' => 'sample-006@example.com',
                    'from_name' => 'Product',
                    'subject' => $subjectToSend,
                    'html' => $htmlBody,
                    'text' => $textBody
                ];
                $res = sendgrid_send($payload);
                $success = (bool)($res['ok'] ?? false);
                $responseMeta = $res;
            } else {
                // Fallback to file-based template
                $subjectToSend = str_replace(array_keys($placeholders), array_values($placeholders), $subject);
                $payload = array_merge($plainPlaceholderContext, [
                    'to' => $user['email'],
                    'to_name' => $plainPlaceholderContext['fullName'] ?? $fullName,
                    'from' => 'sample-006@example.com',
                    'from_name' => 'Product',
                    'subject' => $subjectToSend,
                    'template' => $fileTemplate
                ]);
                $res = sendgrid_send($payload);
                $success = (bool)($res['ok'] ?? false);
                $responseMeta = $res;
            }
            if ($success) {
                log_notification_event($pdo, $eventKey, $userId, $payload, $responseMeta, true, $context);
            } else {
                log_notification_event($pdo, $eventKey, $userId, $payload ?? [], $responseMeta, false, $context);
            }
            return $success ? 1 : 0;
        }

        // Rules-based sends for other events, now delivered via SendGrid (no Gmail dependency)
        $stmt = $pdo->prepare(
            "SELECT r.id, t.subject, t.html_body, t.text_body
             FROM email_notification_rules r
             JOIN email_templates t ON r.template_id = t.id
             WHERE r.event_key = ?
               AND r.is_active = 1
               AND t.is_active = 1"
        );
        $stmt->execute([$eventKey]);
        $rules = $stmt->fetchAll();

        if (!$rules) { return 0; }

        $placeholders = normalize_email_placeholders([
            'first_name' => $user['first_name'] ?? '',
            'last_name' => $user['last_name'] ?? '',
            'firstName' => $user['first_name'] ?? '',
            'lastName' => $user['last_name'] ?? '',
            'fullName' => $fullName,
            'email' => $user['email']
        ], $context);
        $plainPlaceholderContext = extract_plain_placeholders($placeholders);

        foreach ($rules as $rule) {
            // Prepare placeholders for [VARIABLE] syntax (used in email templates)
            $templateVars = [
                '[FIRST_NAME]' => $user['first_name'] ?? '',
                '[LAST_NAME]' => $user['last_name'] ?? '',
                '[FULL_NAME]' => $fullName,
                '[EMAIL]' => $user['email']
            ];
            
            // Add context variables with [UPPERCASE] syntax
            foreach ($context as $key => $value) {
                $upperKey = '[' . strtoupper($key) . ']';
                $templateVars[$upperKey] = $value;
            }
            
            // Also add {{variable}} syntax for backwards compatibility
            foreach ($placeholders as $key => $value) {
                $templateVars[$key] = $value;
            }
            
            $subject = str_replace(array_keys($templateVars), array_values($templateVars), $rule['subject']);
            $htmlBody = str_replace(array_keys($templateVars), array_values($templateVars), $rule['html_body']);
            $textBody = $rule['text_body'] ? str_replace(array_keys($templateVars), array_values($templateVars), $rule['text_body']) : strip_tags($htmlBody);
            
            // Replace [BASE_URL] with actual base URL
            $baseUrl = site_base_url();
            $subject = str_replace('[BASE_URL]', $baseUrl, $subject);
            $htmlBody = str_replace('[BASE_URL]', $baseUrl, $htmlBody);
            $textBody = str_replace('[BASE_URL]', $baseUrl, $textBody);

            $payload = array_merge($plainPlaceholderContext, [
                'to' => $user['email'],
                'from' => 'sample-006@example.com',
                'from_name' => 'Product',
                'subject' => $subject,
                'html' => $htmlBody,
                'text' => $textBody
            ]);
            $res = sendgrid_send($payload);
            $success = (bool)($res['ok'] ?? false);
            if ($success) { $sentCount++; }
            log_notification_event($pdo, $eventKey, $userId, $payload, $res, $success, $context);
        }
    } catch (Exception $e) {
        // Optional: log error using error tracker if available
    }

    return $sentCount;
}
