<?php

require_once __DIR__ . '/config.php';

if (!function_exists('legal_session_bootstrap')) {
    function legal_session_bootstrap(array $context = []): ?array
    {
        try {
            start_session();
        } catch (Throwable $e) {
            // Session already active or unavailable
        }

        $userId = current_user_id();
        if (!$userId || !user_has_role($userId, 'legal')) {
            return null;
        }

        $idleGraceSeconds = max(0, (int)($context['idle_grace_seconds'] ?? 30));
        $idleTimeoutSeconds = max(60, (int)($context['idle_timeout_seconds'] ?? 300));
        $heartbeatIntervalSeconds = max(5, (int)($context['heartbeat_seconds'] ?? 20));

        $pdo = null;
        try {
            $pdo = db();
        } catch (Throwable $e) {
            error_log('[legal-session] database unavailable: ' . $e->getMessage());
            return null;
        }

        $sessionMeta = $_SESSION['legal_portal_session'] ?? [];
        $sessionToken = $sessionMeta['token'] ?? null;
        $sessionRow = null;

        if ($sessionToken) {
            try {
                $stmt = $pdo->prepare('SELECT * FROM legal_portal_sessions WHERE session_token = ? LIMIT 1');
                $stmt->execute([$sessionToken]);
                $sessionRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {
                error_log('[legal-session] lookup failed: ' . $e->getMessage());
                $sessionRow = null;
            }

            if (!$sessionRow || !empty($sessionRow['ended_at'])) {
                $sessionToken = null;
                $sessionRow = null;
                unset($_SESSION['legal_portal_session']);
            }
        }

        $pageUrl = ($context['page_url'] ?? ($_SERVER['REQUEST_URI'] ?? '/'));
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $ipAddress = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);

        if (!$sessionToken) {
            try {
                $sessionToken = bin2hex(random_bytes(24));
            } catch (Throwable $e) {
                $sessionToken = bin2hex(openssl_random_pseudo_bytes(24));
            }

            $now = date('Y-m-d H:i:s');

            try {
                $stmt = $pdo->prepare('INSERT INTO legal_portal_sessions (user_id, session_token, started_at, last_heartbeat_at, last_page_url, user_agent, ip_address, idle_grace_seconds, idle_timeout_seconds, is_idle) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)');
                $stmt->execute([
                    $userId,
                    $sessionToken,
                    $now,
                    $now,
                    $pageUrl,
                    $userAgent ?: null,
                    $ipAddress ?: null,
                    $idleGraceSeconds,
                    $idleTimeoutSeconds
                ]);

                $sessionId = (int)$pdo->lastInsertId();
                $_SESSION['legal_portal_session'] = [
                    'token' => $sessionToken,
                    'id' => $sessionId,
                    'started_at' => $now
                ];

                $sessionRow = [
                    'id' => $sessionId,
                    'session_token' => $sessionToken,
                    'started_at' => $now,
                    'last_heartbeat_at' => $now,
                    'total_active_seconds' => 0,
                    'total_idle_seconds' => 0,
                    'total_session_seconds' => 0,
                    'is_idle' => 0,
                    'ended_at' => null,
                ];
            } catch (Throwable $e) {
                error_log('[legal-session] insert failed: ' . $e->getMessage());
                return null;
            }
        } else {
            if ($pageUrl) {
                try {
                    $stmt = $pdo->prepare('UPDATE legal_portal_sessions SET last_page_url = ?, user_agent = ?, ip_address = ?, updated_at = NOW() WHERE session_token = ? LIMIT 1');
                    $stmt->execute([
                        $pageUrl,
                        $userAgent ?: null,
                        $ipAddress ?: null,
                        $sessionToken
                    ]);
                } catch (Throwable $e) {
                    error_log('[legal-session] update metadata failed: ' . $e->getMessage());
                }
            }
        }

        $startedAtMs = isset($sessionRow['started_at']) ? (int)(strtotime($sessionRow['started_at']) * 1000) : (int)(microtime(true) * 1000);
        $totalIdleMs = (int)(($sessionRow['total_idle_seconds'] ?? 0) * 1000);
        $totalActiveMs = (int)(($sessionRow['total_active_seconds'] ?? 0) * 1000);

        return [
            'sessionToken' => $sessionToken,
            'sessionId' => (int)($sessionRow['id'] ?? 0),
            'userId' => $userId,
            'startedAt' => $startedAtMs,
            'idleGraceMs' => $idleGraceSeconds * 1000,
            'idleTimeoutMs' => $idleTimeoutSeconds * 1000,
            'heartbeatIntervalMs' => $heartbeatIntervalSeconds * 1000,
            'totalIdleMs' => $totalIdleMs,
            'totalActiveMs' => $totalActiveMs,
            'heartbeatUrl' => path_with_base('/api/legal/session-heartbeat.php'),
            'endUrl' => path_with_base('/api/legal/session-end.php'),
            'logoutUrl' => path_with_base('/logout.php'),
            'pageUrl' => $pageUrl,
        ];
    }
}

if (!function_exists('legal_session_end')) {
    function legal_session_end(string $reason = 'logout', array $metrics = []): void
    {
        try {
            start_session();
        } catch (Throwable $e) {
            // ignore
        }

        $sessionMeta = $_SESSION['legal_portal_session'] ?? null;
        if (!$sessionMeta || empty($sessionMeta['token'])) {
            return;
        }

        $sessionToken = $sessionMeta['token'];

        try {
            $pdo = db();
        } catch (Throwable $e) {
            error_log('[legal-session] unable to end session: ' . $e->getMessage());
            unset($_SESSION['legal_portal_session']);
            return;
        }

        $totalSession = isset($metrics['total_session_seconds']) ? (int)$metrics['total_session_seconds'] : null;
        $totalActive = isset($metrics['total_active_seconds']) ? (int)$metrics['total_active_seconds'] : null;
        $totalIdle = isset($metrics['total_idle_seconds']) ? (int)$metrics['total_idle_seconds'] : null;
        $isIdle = isset($metrics['is_idle']) ? (int)!empty($metrics['is_idle']) : null;

        $fields = ['ended_at = NOW()', 'ended_reason = ?'];
        $params = [$reason];

        if ($totalSession !== null) {
            $fields[] = 'total_session_seconds = ?';
            $params[] = $totalSession;
        }
        if ($totalActive !== null) {
            $fields[] = 'total_active_seconds = ?';
            $params[] = $totalActive;
        }
        if ($totalIdle !== null) {
            $fields[] = 'total_idle_seconds = ?';
            $params[] = $totalIdle;
        }
        if ($isIdle !== null) {
            $fields[] = 'is_idle = ?';
            $params[] = $isIdle;
        }

        $params[] = $sessionToken;

        if (!empty($fields)) {
            $fields[] = 'updated_at = NOW()';
            $sql = 'UPDATE legal_portal_sessions SET ' . implode(', ', $fields) . ' WHERE session_token = ? LIMIT 1';

            try {
                $stmt = db()->prepare($sql);
                $stmt->execute($params);
            } catch (Throwable $e) {
                error_log('[legal-session] end update failed: ' . $e->getMessage());
            }
        }

        unset($_SESSION['legal_portal_session']);
    }
}


