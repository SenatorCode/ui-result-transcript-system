<?php
// Owner: Dev B.

final class TranscriptStatus
{
    public const PENDING_PAYMENT    = 'pending_payment';
    public const PAYMENT_PROCESSING = 'payment_processing';
    public const PAID               = 'paid';
    public const PROCESSING         = 'processing';
    public const READY              = 'ready';
    public const SENT               = 'sent';
    public const FAILED             = 'failed';
    public const CANCELLED          = 'cancelled';
    public const REJECTED           = 'rejected';
    public const EXPIRED            = 'expired';
}

final class Transcript
{
    public static function findOwned(string $publicId, int $studentId): array { throw new RuntimeException('Not implemented'); }
    /** Proposed. */
    public static function listForStudent(int $studentId, array $filters = []): array { throw new RuntimeException('Not implemented'); }
    /** Proposed. */
    public static function create(int $studentId, array $body): array { throw new RuntimeException('Not implemented'); }
    /** Proposed. */
    public static function cancel(string $publicId, int $studentId): array { throw new RuntimeException('Not implemented'); }
    /** Only way to change status. Locks the row; accepts an allow-list of $extra columns. */
    public static function transition(int $id, string $to, array $extra = []): array { throw new RuntimeException('Not implemented'); }
    public static function allowedActions(array $row): array { throw new RuntimeException('Not implemented'); }
    public static function canDownload(array $row): bool { throw new RuntimeException('Not implemented'); }
    public static function adminActions(array $row): array { throw new RuntimeException('Not implemented'); }
    /** Expires overdue unpaid requests; fails stale payment_processing. Returns rows changed. */
    public static function expireOverdue(): int { throw new RuntimeException('Not implemented'); }
}

final class FeeCalculator
{
    /** Proposed. Stores the quote in the session. */
    public static function quote(int $studentId, array $query): array { throw new RuntimeException('Not implemented'); }
    /** Proposed. */
    public static function assertQuoteValid(string $quoteExpiresAt, array $body): void { throw new RuntimeException('Not implemented'); }
}