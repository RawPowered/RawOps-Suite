<?php
// required/google_mailer.php
// Shared helpers for sending mail through the Google Gmail API using stored OAuth tokens.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/google_oauth.php';

/**
 * Send an email using the Gmail API on behalf of the connected admin user.
 *
 * @param int   $senderAdminId The admin user ID that authorized Gmail access.
 * @param array $message       Associative array with required keys: to, subject, html.
 *                             Optional keys: text, from_name, from_email, reply_to.
 * @param array $extraHeaders  Additional headers as key => value.
 *
 * @return bool True on success, false on failure.
 */
function google_send_mail(int $senderAdminId, array $message, array $extraHeaders = []): bool
{
    $to = trim($message['to'] ?? '');
    $subject = trim($message['subject'] ?? '');
    $htmlBody = (string)($message['html'] ?? '');
    $textBody = (string)($message['text'] ?? strip_tags($htmlBody));

    if ($to === '' || $subject === '' || $htmlBody === '') {
        return false;
    }

    $accessToken = google_get_access_token_for_user($senderAdminId);
    if (!$accessToken) {
        return false;
    }

    // Determine headers
    $fromEmail = trim($message['from_email'] ?? 'sample-006@example.com');
    $fromName  = trim($message['from_name'] ?? 'Product Team');
    $replyTo   = trim($message['reply_to'] ?? 'sample-006@example.com');

    $boundary = '----=_Part_' . bin2hex(random_bytes(8));

    $headers = [
        'To' => $to,
        'Subject' => '=?utf-8?B?' . base64_encode($subject) . '?=',
        'From' => sprintf('"%s" <%s>', addslashes($fromName), $fromEmail),
        'Reply-To' => $replyTo,
        'MIME-Version' => '1.0',
        'Content-Type' => 'multipart/alternative; boundary="' . $boundary . '"'
    ];

    foreach ($extraHeaders as $key => $value) {
        if (!isset($headers[$key])) {
            $headers[$key] = $value;
        }
    }

    $lines = [];
    foreach ($headers as $key => $value) {
        $lines[] = $key . ': ' . $value;
    }
    $lines[] = '';
    $lines[] = '--' . $boundary;
    $lines[] = 'Content-Type: text/plain; charset=UTF-8';
    $lines[] = 'Content-Transfer-Encoding: quoted-printable';
    $lines[] = '';
    $lines[] = quoted_printable_encode($textBody);
    $lines[] = '';
    $lines[] = '--' . $boundary;
    $lines[] = 'Content-Type: text/html; charset=UTF-8';
    $lines[] = 'Content-Transfer-Encoding: quoted-printable';
    $lines[] = '';
    $lines[] = quoted_printable_encode($htmlBody);
    $lines[] = '';
    $lines[] = '--' . $boundary . '--';

    $rawMessage = implode("\r\n", $lines);
    $encodedMessage = rtrim(strtr(base64_encode($rawMessage), '+/', '-_'), '=');

    $payload = ['raw' => $encodedMessage];

    $ch = curl_init('https://gmail.googleapis.com/gmail/v1/users/me/messages/send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => 1,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 15
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($response !== false) && $httpCode >= 200 && $httpCode < 300;
}


