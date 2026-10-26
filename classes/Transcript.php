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

        /** Request row plus its latest payment. */
    private const WITH_PAYMENT = 'SELECT tr.*, p.reference AS pay_reference, p.status AS pay_status, p.paid_at AS pay_paid_at
          FROM transcript_requests tr
          LEFT JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.request_id = tr.id)';

    /** One request in the API shape (frontend list item). */
    public static function view(array $row): array
    {
        return [
            'id'              => IdCodec::encode('tr', (int) $row['id']),
            'requestNo'       => $row['request_no'],
            'destination'     => [
                'type'  => $row['destination_type'],
                'label' => $row['destination_type'] === 'personal' ? 'Personal copy' : $row['institution_name'],
            ],
            'deliveryMethod'  => $row['delivery_method'],
            'copies'          => (int) $row['copies'],
            'feeAmount'       => Response::naira($row['fee_amount']),
            'status'          => $row['status'],
            'createdAt'       => Response::iso($row['created_at']),
            'updatedAt'       => Response::iso($row['updated_at']),
            'expiresAt'       => Response::iso($row['expires_at']),
            'sentAt'          => Response::iso($row['sent_at']),
            'rejectionReason' => $row['rejection_reason'],
            'allowedActions'  => self::allowedActions($row),
            'payment'         => [
                'reference' => $row['pay_reference'] ?? null,
                'status'    => $row['pay_status'] ?? null,
                'paidAt'    => Response::iso($row['pay_paid_at'] ?? null),
            ],
        ];
    }

    /** The student's own requests, newest first. */
    public static function listForStudent(int $studentId, array $filters = []): array
    {
        self::expireOverdue();
        $rows = Database::fetchAll(
            self::WITH_PAYMENT . ' WHERE tr.student_id = ? ORDER BY tr.created_at DESC, tr.id DESC',
            [$studentId]
        );
        return array_map([self::class, 'view'], $rows);
    }

    /**
     * Creates a request. Input is validated, the quote must be the one stored in the session,
     * and the fee is recomputed here (never taken from the browser).
     */
    public static function create(int $studentId, array $body): array
    {
        self::expireOverdue();
        $o = FeeCalculator::options($body, true);

        $purpose = null;
        if (isset($body['purpose']) && is_string($body['purpose']) && trim($body['purpose']) !== '') {
            $purpose = trim($body['purpose']);
            if (strlen($purpose) > 255) {
                throw new ApiException('validation_failed', 'Check the highlighted fields and try again.', 422, ['purpose' => 'Use 255 characters or fewer.']);
            }
        }

        FeeCalculator::assertQuoteValid((string) ($body['quoteExpiresAt'] ?? ''), $o);
        $fee = FeeCalculator::fee($o['deliveryMethod'], $o['copies']);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + (int) Config::get('REQUEST_PAYMENT_HOURS') * 3600);
        $duplicate = new ApiException(
            'duplicate_open_request',
            'You already have an open transcript request. Pay for it, cancel it, or wait until it is finished.',
            409
        );

        try {
            $id = Database::transaction(function () use ($studentId, $o, $purpose, $fee, $expiresAt, $duplicate): int {
                $open = TranscriptStatus::OPEN;
                $marks = implode(', ', array_fill(0, count($open), '?'));
                $existing = Database::fetchOne(
                    "SELECT id FROM transcript_requests WHERE student_id = ? AND status IN ($marks) FOR UPDATE",
                    array_merge([$studentId], $open)
                );
                if ($existing !== null) {
                    throw $duplicate;
                }
                Database::execute(
                    'INSERT INTO transcript_requests
                        (request_no, student_id, fee_amount, purpose, destination_type, institution_name,
                         destination_address, destination_email, delivery_method, copies, expires_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        'NEW-' . bin2hex(random_bytes(8)), // temporary, replaced below (needs the id)
                        $studentId, $fee, $purpose, $o['destinationType'], $o['institutionName'],
                        $o['destinationAddress'], $o['destinationEmail'], $o['deliveryMethod'], $o['copies'], $expiresAt,
                    ]
                );
                $newId = Database::lastInsertId();
                Database::execute(
                    'UPDATE transcript_requests SET request_no = ? WHERE id = ?',
                    [sprintf('TR-%s-%06d', gmdate('Y'), $newId), $newId]
                );
                return $newId;
            });
        } catch (PDOException $e) {
            // Two requests racing past the check above: the unique key stops the second.
            if ((string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_one_open_request')) {
                throw $duplicate;
            }
            throw $e;
        }

        FeeCalculator::clearQuote();
        return self::view(Database::fetchOne(self::WITH_PAYMENT . ' WHERE tr.id = ?', [$id]));
    }

    /** Cancels an unpaid request (pending_payment or failed). Anything else is 409. */
    public static function cancel(string $publicId, int $studentId): array
    {
        $row = self::findOwned($publicId, $studentId);
        self::transition((int) $row['id'], TranscriptStatus::CANCELLED);
        return ['transcriptId' => $publicId, 'status' => TranscriptStatus::CANCELLED, 'allowedActions' => []];
    }

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
    private const QUOTE_MINUTES = 15;
    private const DELIVERY = ['personal' => ['download'], 'institution' => ['email', 'courier']];

    /**
     * Validates the request options. With $full = true it also checks the institution fields
     * (used by create). Throws 422 with a message per bad field.
     * @return array{destinationType:string,deliveryMethod:string,copies:int,institutionName:?string,destinationAddress:?string,destinationEmail:?string}
     */
    public static function options(array $in, bool $full = false): array
    {
        $fields = [];
        $type = self::text($in['destinationType'] ?? null);
        if (!isset(self::DELIVERY[$type])) {
            $fields['destinationType'] = 'Choose a personal copy or send to an institution.';
        }
        $method = self::text($in['deliveryMethod'] ?? null);
        if ($type === 'personal' && $method === '') {
            $method = 'download';
        }
        if (isset(self::DELIVERY[$type]) && !in_array($method, self::DELIVERY[$type], true)) {
            $fields['deliveryMethod'] = 'Choose a delivery method for this destination.';
        }
        $copies = self::copies($in['copies'] ?? null);
        if ($copies === null) {
            $fields['copies'] = 'Choose 1 to 3 copies.';
        }

        $name = $address = $email = null;
        if ($type === 'institution') {
            $name = self::text($in['institutionName'] ?? null) ?: null;
            $address = self::text($in['destinationAddress'] ?? null) ?: null;
            $email = self::text($in['destinationEmail'] ?? null) ?: null;
            if ($full) {
                if ($name === null || strlen($name) > 150) {
                    $fields['institutionName'] = 'Enter the institution name (up to 150 characters).';
                }
                if ($email !== null && (strlen($email) > 120 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
                    $fields['destinationEmail'] = 'Enter a valid email address.';
                }
                if ($address !== null && strlen($address) > 255) {
                    $fields['destinationAddress'] = 'Use 255 characters or fewer.';
                }
                if ($method === 'email' && $email === null) {
                    $fields['destinationEmail'] = 'Enter the institution email address.';
                }
                if ($method === 'courier' && $address === null) {
                    $fields['destinationAddress'] = 'Enter the postal address.';
                }
            }
        }

        if ($fields !== []) {
            throw new ApiException('validation_failed', 'Check the highlighted fields and try again.', 422, $fields);
        }
        return [
            'destinationType' => $type, 'deliveryMethod' => $method, 'copies' => $copies,
            'institutionName' => $name, 'destinationAddress' => $address, 'destinationEmail' => $email,
        ];
    }

    /** Whole naira: TRANSCRIPT_FEE x copies, plus COURIER_SURCHARGE for courier. */
    public static function fee(string $deliveryMethod, int $copies): int
    {
        $fee = (int) Config::get('TRANSCRIPT_FEE') * $copies;
        if ($deliveryMethod === 'courier') {
            $fee += (int) Config::get('COURIER_SURCHARGE');
        }
        return $fee;
    }

    /** Prices the options and remembers the quote in the session for create. */
    public static function quote(int $studentId, array $query): array
    {
        $o = self::options($query);
        $quote = [
            'feeAmount'       => self::fee($o['deliveryMethod'], $o['copies']),
            'currency'        => (string) Config::get('CURRENCY'),
            'expiresAt'       => gmdate('Y-m-d\TH:i:s\Z', time() + self::QUOTE_MINUTES * 60),
            'destinationType' => $o['destinationType'],
            'deliveryMethod'  => $o['deliveryMethod'],
            'copies'          => $o['copies'],
        ];
        $_SESSION['quote'] = $quote;
        return $quote;
    }

    /** $body is the validated options array. The quote must exist, match, and not be expired. */
    public static function assertQuoteValid(string $quoteExpiresAt, array $body): void
    {
        $fail = new ApiException(
            'validation_failed',
            'The fee quote expired or changed. Get a current quote and review again.',
            422,
            ['quoteExpiresAt' => 'Get a current fee quote.']
        );
        $q = $_SESSION['quote'] ?? null;
        if (!is_array($q) || ($q['expiresAt'] ?? null) !== $quoteExpiresAt || strtotime($q['expiresAt']) <= time()) {
            throw $fail;
        }
        foreach (['destinationType', 'deliveryMethod', 'copies'] as $key) {
            if (($q[$key] ?? null) !== ($body[$key] ?? null)) {
                throw $fail;
            }
        }
    }

    public static function clearQuote(): void
    {
        unset($_SESSION['quote']);
    }

    private static function text($value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function copies($value): ?int
    {
        if ($value === null || $value === '') {
            return 1;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int) $value;
        }
        return is_int($value) && $value >= 1 && $value <= 3 ? $value : null;
    }
}