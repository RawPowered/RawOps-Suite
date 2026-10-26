<?php

require_once __DIR__ . '/config.php';

class LegalCaseService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: db();
    }

    public function getCasesForUser(int $userId, bool $includeClosed = false): array
    {
        $sql = 'SELECT c.* FROM legal_cases c
                JOIN legal_case_team t ON t.case_id = c.id
                WHERE t.user_id = :userId';
        if (!$includeClosed) {
            $sql .= " AND c.status IN ('draft','active','on_hold')";
        }
        $sql .= ' ORDER BY c.updated_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['userId' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCaseByKey(string $caseKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM legal_cases WHERE case_key = ? LIMIT 1');
        $stmt->execute([$caseKey]);
        $case = $stmt->fetch(PDO::FETCH_ASSOC);
        return $case ?: null;
    }

    public function listDocuments(int $caseId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM legal_case_documents WHERE case_id = ? ORDER BY uploaded_at DESC');
        $stmt->execute([$caseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function trackDownload(int $documentId, int $caseId, ?int $userId): void
    {
        $this->pdo->prepare('UPDATE legal_case_documents SET download_count = download_count + 1, last_accessed_at = NOW() WHERE id = ?')
            ->execute([$documentId]);
        $this->logActivity($caseId, $userId, 'document_downloaded', ['document_id' => $documentId]);
    }

    public function logActivity(int $caseId, ?int $userId, string $activityType, array $payload = []): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO legal_case_activity (case_id, user_id, activity_type, activity_payload, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $caseId,
            $userId,
            $activityType,
            $payload ? json_encode($payload) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
        ]);
    }

    public function retentionOptions(): array
    {
        return [
            'never' => 'Never remove (default)',
            '1_year' => 'Retain 1 year',
            '2_year' => 'Retain 2 years',
            '3_year' => 'Retain 3 years',
            '5_year' => 'Retain 5 years',
            '10_year' => 'Retain 10 years',
            '20_year' => 'Retain 20 years',
        ];
    }

    public function computeRetentionExpiry(string $policy, ?DateTimeImmutable $from = null): ?DateTimeImmutable
    {
        $from = $from ?: new DateTimeImmutable();
        return match ($policy) {
            '1_year' => $from->modify('+1 year'),
            '2_year' => $from->modify('+2 years'),
            '3_year' => $from->modify('+3 years'),
            '5_year' => $from->modify('+5 years'),
            '10_year' => $from->modify('+10 years'),
            '20_year' => $from->modify('+20 years'),
            default => null,
        };
    }
}









