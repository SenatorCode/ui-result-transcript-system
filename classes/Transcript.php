<?php
// Owner: Dev B.

declare(strict_types=1);

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

    /** Statuses that count as an open request (one per student). */
    public const OPEN = [
        self::PENDING_PAYMENT, self::PAYMENT_PROCESSING, self::FAILED, self::PAID, self::PROCESSING,
    ];

    /** The only allowed moves. Anything else is 409 invalid_transition. */
    public const TRANSITIONS = [
        self::PENDING_PAYMENT    => [self::PAYMENT_PROCESSING, self::CANCELLED, self::EXPIRED],
        self::PAYMENT_PROCESSING => [self::PAID, self::FAILED],
        self::FAILED             => [self::PAYMENT_PROCESSING, self::CANCELLED, self::EXPIRED],
        self::PAID               => [self::PROCESSING, self::REJECTED],
        self::PROCESSING         => [self::READY, self::SENT, self::REJECTED],
        self::READY              => [],
        self::SENT               => [],
        self::CANCELLED          => [],
        self::REJECTED           => [],
        self::EXPIRED            => [],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    public static function isOpen(string $status): bool
    {
        return in_array($status, self::OPEN, true);
    }

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }
}

final class Transcript
{
    /** Columns transition() lets a caller set in the same UPDATE as the status. */
    private const EXTRA_COLUMNS = [
        'transcript_no', 'total_units', 'gpa', 'cgpa', 'ready_at', 'sent_at',
        'rejection_reason', 'expires_at',
        'handled_by_admin_id', 'handled_at', // only exist once migration 005 is applied
    ];

    /** Minutes a payment may stay unconfirmed before the request becomes 'failed'. */
    private const STALE_PAYMENT_MINUTES = 30;

    /**
     * The student's own request as a raw database row. A malformed id, a missing id and
     * another student's id all give the same 404. Expires overdue requests first (lazy expiry).
     */
    public static function findOwned(string $publicId, int $studentId): array
    {
        $id = IdCodec::decode('tr', $publicId);
        self::expireOverdue();
        $row = Database::fetchOne(
            'SELECT * FROM transcript_requests WHERE id = ? AND student_id = ?',
            [$id, $studentId]
        );
        if ($row === null) {
            throw new ApiException('not_found', 'We could not find that record.', 404);
        }
        return $row;
    }

    /** Proposed. Feature 4. */
    public static function listForStudent(int $studentId, array $filters = []): array { throw new RuntimeException('Not implemented'); }
    /** Proposed. Feature 4. */
    public static function create(int $studentId, array $body): array { throw new RuntimeException('Not implemented'); }
    /** Proposed. Feature 4. */
    public static function cancel(string $publicId, int $studentId): array { throw new RuntimeException('Not implemented'); }

    /**
     * The only way to change a status. Locks the row, checks the move against the table,
     * and writes the status plus any allow-listed $extra columns in ONE update (the database
     * CHECK constraints need the issue columns together with the status).
     * Safe inside an outer transaction (Database::transaction is re-entrant).
     * Returns the updated row.
     */
    public static function transition(int $id, string $to, array $extra = []): array
    {
        return Database::transaction(function () use ($id, $to, $extra): array {
            $row = Database::fetchOne('SELECT * FROM transcript_requests WHERE id = ? FOR UPDATE', [$id]);
            if ($row === null) {
                throw new ApiException('not_found', 'We could not find that record.', 404);
            }
            $invalid = new ApiException('invalid_transition', 'That action is not allowed for this request right now.', 409);
            if (!TranscriptStatus::canMove((string) $row['status'], $to)) {
                throw $invalid;
            }
            // ready is for personal copies, sent for institution copies
            if ($to === TranscriptStatus::READY && $row['destination_type'] !== 'personal') {
                throw $invalid;
            }
            if ($to === TranscriptStatus::SENT && $row['destination_type'] !== 'institution') {
                throw $invalid;
            }

            $sets = ['status = ?'];
            $params = [$to];
            foreach ($extra as $column => $value) {
                if (!in_array($column, self::EXTRA_COLUMNS, true)) {
                    throw new LogicException("transition() may not set column '$column'.");
                }
                $sets[] = "$column = ?";
                $params[] = $value;
            }
            $params[] = $id;
            Database::execute('UPDATE transcript_requests SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

            return Database::fetchOne('SELECT * FROM transcript_requests WHERE id = ?', [$id]);
        });
    }

    /** What the student may do now. The frontend shows buttons from this list only. */
    public static function allowedActions(array $row): array
    {
        return match ($row['status']) {
            TranscriptStatus::PENDING_PAYMENT => ['pay', 'cancel'],
            TranscriptStatus::FAILED          => ['retry', 'cancel'],
            TranscriptStatus::PAID,
            TranscriptStatus::PROCESSING,
            TranscriptStatus::SENT            => ['receipt'],
            TranscriptStatus::READY           => self::canDownload($row) ? ['download', 'receipt'] : ['receipt'],
            default                           => [],
        };
    }

    /** What Registry may do now. Single source for the admin pages and update-status. */
    public static function adminActions(array $row): array
    {
        return match ($row['status']) {
            TranscriptStatus::PAID       => ['start_processing', 'reject'],
            TranscriptStatus::PROCESSING => [$row['destination_type'] === 'personal' ? 'mark_ready' : 'mark_sent', 'reject'],
            default                      => [],
        };
    }

    /** Download only for a ready personal copy. Institution copies are never downloadable. */
    public static function canDownload(array $row): bool
    {
        return $row['status'] === TranscriptStatus::READY && $row['destination_type'] === 'personal';
    }

    /**
     * Bulk housekeeping. Returns the number of rows changed.
     *  - unpaid (pending_payment, failed) requests past expires_at become 'expired'
     *  - payment_processing untouched for 30 minutes becomes 'failed' (its initiated payment too)
     * This is the one place besides transition() that writes the status column.
     */
    public static function expireOverdue(): int
    {
        return Database::transaction(function (): int {
            $changed = Database::execute(
                "UPDATE transcript_requests SET status = 'expired'
                  WHERE status IN ('pending_payment', 'failed')
                    AND expires_at IS NOT NULL AND expires_at < UTC_TIMESTAMP()"
            );
            $stale = Database::fetchAll(
                "SELECT id FROM transcript_requests
                  WHERE status = 'payment_processing'
                    AND updated_at < UTC_TIMESTAMP() - INTERVAL " . self::STALE_PAYMENT_MINUTES . " MINUTE
                    FOR UPDATE"
            );
            foreach ($stale as $s) {
                $id = (int) $s['id'];
                Database::execute(
                    "UPDATE payments SET status = 'failed', failure_reason = 'Payment was not confirmed in time'
                      WHERE request_id = ? AND status = 'initiated'",
                    [$id]
                );
                $changed += Database::execute(
                    "UPDATE transcript_requests SET status = 'failed' WHERE id = ? AND status = 'payment_processing'",
                    [$id]
                );
            }
            return $changed;
        });
    }
}

final class FeeCalculator
{
    /** Proposed. Stores the quote in the session. */
    public static function quote(int $studentId, array $query): array { throw new RuntimeException('Not implemented'); }
    /** Proposed. */
    public static function assertQuoteValid(string $quoteExpiresAt, array $body): void { throw new RuntimeException('Not implemented'); }
}