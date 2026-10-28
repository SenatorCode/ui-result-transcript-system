<?php
// Owner: Dev C. Admin, AdminReport, TranscriptIssuer.
declare(strict_types=1);

final class Admin
{
    /** Returns the admin in API shape plus 'adminId' (for the session). Throws invalid_credentials / account_deactivated. */
    public static function authenticate(string $username, string $password): array
    {
        $row = Database::fetchOne(
            'SELECT id, username, full_name, password_hash, is_active FROM admins WHERE username = ?',
            [$username]
        );
        // Same error for an unknown user and a wrong password, so usernames cannot be probed.
        if ($row === null || !password_verify($password, $row['password_hash'])) {
            throw new ApiException('invalid_credentials', 'Username or password is incorrect.', 401);
        }
        if ((int) $row['is_active'] !== 1) {
            throw new ApiException('account_deactivated', 'This account has been deactivated.', 403);
        }
        Database::execute('UPDATE admins SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [(int) $row['id']]);

        return [
            'adminId'  => (int) $row['id'],
            'id'       => IdCodec::encode('ad', (int) $row['id']),
            'fullName' => $row['full_name'],
            'username' => $row['username'],
        ];
    }

    /** The admin for the "me" route, or null when missing or deactivated. */
    public static function profile(int $adminId): ?array
    {
        $row = Database::fetchOne(
            'SELECT id, username, full_name FROM admins WHERE id = ? AND is_active = 1',
            [$adminId]
        );
        if ($row === null) {
            return null;
        }
        return [
            'id'       => IdCodec::encode('ad', (int) $row['id']),
            'fullName' => $row['full_name'],
            'username' => $row['username'],
        ];
    }
}

final class AdminReport
{
    private const STATUSES = [
        'pending_payment', 'payment_processing', 'paid', 'processing',
        'ready', 'sent', 'failed', 'cancelled', 'rejected', 'expired',
    ];

    public static function dashboard(): array
    {
        Transcript::expireOverdue();

        $byStatus = array_fill_keys(self::STATUSES, 0);
        foreach (Database::fetchAll('SELECT status, COUNT(*) AS qty FROM transcript_requests GROUP BY status') as $row) {
            if (array_key_exists($row['status'], $byStatus)) {
                $byStatus[$row['status']] = (int) $row['qty'];
            }
        }

        // Verified revenue = successful and not refunded (DATABASE_SCHEMA.md section 4.10).
        $pay = Database::fetchOne(
            "SELECT
                COALESCE(SUM(CASE WHEN status = 'success' AND refunded_at IS NULL THEN amount END), 0) AS verified_amt,
                COUNT(CASE WHEN status = 'success' AND refunded_at IS NULL THEN 1 END)                 AS verified_count,
                COALESCE(SUM(CASE WHEN status = 'success' AND refunded_at IS NOT NULL THEN amount END), 0) AS refunded_amt
               FROM payments"
        );

        $recent = self::transcriptList(['pageSize' => 5, 'sort' => 'newest'])['items'];

        return [
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'requests'    => [
                'total'           => array_sum($byStatus),
                'byStatus'        => $byStatus,
                'awaitingPayment' => $byStatus['pending_payment'] + $byStatus['payment_processing'],
                'awaitingAction'  => $byStatus['paid'] + $byStatus['processing'],
            ],
            'payments'    => [
                'verifiedAmount' => Response::naira($pay['verified_amt'] ?? 0),
                'verifiedCount'  => (int) ($pay['verified_count'] ?? 0),
                'refundedAmount' => Response::naira($pay['refunded_amt'] ?? 0),
                'currency'       => (string) Config::get('CURRENCY'),
            ],
            'recent'      => $recent,
        ];
    }

    public static function transcriptList(array $filters): array
    {
        Transcript::expireOverdue();

        $where = [];
        $params = [];
        $fields = [];

        $statusFilter = trim((string) ($filters['status'] ?? ''));
        if ($statusFilter !== '') {
            $wanted = array_values(array_filter(array_map('trim', explode(',', $statusFilter)), 'strlen'));
            foreach ($wanted as $s) {
                if (!in_array($s, self::STATUSES, true)) {
                    $fields['status'] = 'Choose a valid status.';
                }
            }
            if ($wanted !== [] && !isset($fields['status'])) {
                $where[] = 'tr.status IN (' . implode(',', array_fill(0, count($wanted), '?')) . ')';
                array_push($params, ...$wanted);
            }
        }

        $destination = (string) ($filters['destinationType'] ?? '');
        if ($destination !== '') {
            if (!in_array($destination, ['personal', 'institution'], true)) {
                $fields['destinationType'] = 'Choose personal or institution.';
            } else {
                $where[] = 'tr.destination_type = ?';
                $params[] = $destination;
            }
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            if (mb_strlen($q) < 2) {
                $fields['q'] = 'Search needs at least 2 characters.';
            } else {
                $like = '%' . $q . '%';
                $where[] = '(tr.request_no LIKE ? OR s.matric_no LIKE ? OR s.full_name LIKE ?)';
                array_push($params, $like, $like, $like);
            }
        }

        $sort = (string) ($filters['sort'] ?? 'newest');
        if (!in_array($sort, ['newest', 'oldest'], true)) {
            $fields['sort'] = 'Sort must be newest or oldest.';
        }
        $pageSize = self::pageSize($filters, $fields);
        if ($fields !== []) {
            throw new ApiException('validation_failed', 'Check the highlighted fields and try again.', 422, $fields);
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $dir = $sort === 'oldest' ? 'ASC' : 'DESC';

        $total = (int) (Database::fetchOne(
            "SELECT COUNT(*) AS total FROM transcript_requests tr JOIN students s ON s.id = tr.student_id $whereSql",
            $params
        )['total'] ?? 0);
        [$page, $totalPages, $offset] = self::paging($filters, $total, $pageSize);

        $rows = Database::fetchAll(
            "SELECT tr.*, s.matric_no, s.full_name,
                    lp.reference AS pay_reference, lp.payment_status AS pay_status, lp.paid_at AS pay_paid_at
               FROM transcript_requests tr
               JOIN students s ON s.id = tr.student_id
               LEFT JOIN v_request_latest_payment lp ON lp.request_id = tr.id
               $whereSql
              ORDER BY tr.created_at $dir, tr.id $dir
              LIMIT $pageSize OFFSET $offset",
            $params
        );

        $items = array_map(static fn (array $r): array => [
            'id'             => IdCodec::encode('tr', (int) $r['id']),
            'requestNo'      => $r['request_no'],
            'student'        => [
                'id'       => IdCodec::encode('st', (int) $r['student_id']),
                'matricNo' => $r['matric_no'],
                'fullName' => $r['full_name'],
            ],
            'destination'    => self::destination($r),
            'deliveryMethod' => $r['delivery_method'],
            'copies'         => (int) $r['copies'],
            'feeAmount'      => Response::naira($r['fee_amount']),
            'status'         => $r['status'],
            'createdAt'      => Response::iso($r['created_at']),
            'updatedAt'      => Response::iso($r['updated_at']),
            'payment'        => [
                'reference' => $r['pay_reference'],
                'status'    => $r['pay_status'],
                'paidAt'    => Response::iso($r['pay_paid_at']),
            ],
            'adminActions'   => Transcript::adminActions($r),
        ], $rows);

        return ['items' => $items, 'page' => self::pageBlock($page, $pageSize, $total, $totalPages)];
    }

    /** Body of GET admin/transcripts/show: transcript, student and every payment attempt. */
    public static function transcriptDetail(int $requestId): array
    {
        Transcript::expireOverdue();

        $r = Database::fetchOne(
            'SELECT tr.*, s.matric_no, s.full_name, s.email, s.phone, s.level, s.status AS student_status,
                    d.name AS department
               FROM transcript_requests tr
               JOIN students s ON s.id = tr.student_id
               JOIN departments d ON d.id = s.department_id
              WHERE tr.id = ?',
            [$requestId]
        );
        if ($r === null) {
            throw new ApiException('not_found', 'We could not find that record.', 404);
        }

        $issued = in_array($r['status'], ['ready', 'sent'], true) && $r['transcript_no'] !== null
            ? [
                'transcriptNo' => $r['transcript_no'],
                'totalUnits'   => (int) $r['total_units'],
                'cgpa'         => (float) $r['cgpa'],
                'readyAt'      => Response::iso($r['ready_at']),
                'sentAt'       => Response::iso($r['sent_at']),
            ]
            : null;

        $payments = array_map(static fn (array $p): array => self::paymentView($p), Database::fetchAll(
            'SELECT * FROM payments WHERE request_id = ? ORDER BY id DESC',
            [$requestId]
        ));

        return [
            'transcript' => [
                'id'              => IdCodec::encode('tr', (int) $r['id']),
                'requestNo'       => $r['request_no'],
                'status'          => $r['status'],
                'destination'     => self::destination($r),
                'institution'     => $r['destination_type'] === 'institution'
                    ? ['name' => $r['institution_name'], 'address' => $r['destination_address'], 'email' => $r['destination_email']]
                    : null,
                'deliveryMethod'  => $r['delivery_method'],
                'copies'          => (int) $r['copies'],
                'feeAmount'       => Response::naira($r['fee_amount']),
                'purpose'         => $r['purpose'],
                'createdAt'       => Response::iso($r['created_at']),
                'updatedAt'       => Response::iso($r['updated_at']),
                'expiresAt'       => Response::iso($r['expires_at']),
                'rejectionReason' => $r['rejection_reason'],
                'issued'          => $issued,
                'download'        => ['count' => (int) $r['download_count'], 'lastDownloadedAt' => Response::iso($r['last_downloaded_at'])],
                'handledBy'       => null, // needs migration 005
                'handledAt'       => null,
                'adminActions'    => Transcript::adminActions($r),
            ],
            'student'    => [
                'id'         => IdCodec::encode('st', (int) $r['student_id']),
                'matricNo'   => $r['matric_no'],
                'fullName'   => $r['full_name'],
                'email'      => $r['email'],
                'phone'      => $r['phone'],
                'department' => $r['department'],
                'level'      => (int) $r['level'],
                'status'     => $r['student_status'],
            ],
            'payments'   => $payments,
        ];
    }

    public static function paymentList(array $filters): array
    {
        $where = [];
        $params = [];
        $fields = [];

        $status = (string) ($filters['status'] ?? '');
        if ($status !== '') {
            if (!in_array($status, ['initiated', 'success', 'failed', 'cancelled'], true)) {
                $fields['status'] = 'Choose a valid payment status.';
            } else {
                $where[] = 'p.status = ?';
                $params[] = $status;
            }
        }

        $gateway = (string) ($filters['gateway'] ?? '');
        if ($gateway !== '') {
            if (!in_array($gateway, ['simulated', 'gateway'], true)) {
                $fields['gateway'] = 'Choose simulated or gateway.';
            } else {
                $where[] = 'p.gateway = ?';
                $params[] = $gateway === 'gateway' ? 'paystack' : 'simulated';
            }
        }

        foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
            $value = (string) ($filters[$key] ?? '');
            if ($value === '') {
                continue;
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
            if ($date === false || $date->format('Y-m-d') !== $value) {
                $fields[$key] = 'Use the format YYYY-MM-DD.';
                continue;
            }
            // "to" is inclusive of that whole day.
            $bound = $key === 'to' ? $date->modify('+1 day') : $date;
            $where[] = "p.initiated_at $op ?";
            $params[] = $bound->format('Y-m-d H:i:s');
        }

        $pageSize = self::pageSize($filters, $fields);
        if ($fields !== []) {
            throw new ApiException('validation_failed', 'Check the highlighted fields and try again.', 422, $fields);
        }

        $from = 'FROM payments p
                 JOIN transcript_requests tr ON tr.id = p.request_id
                 JOIN students s ON s.id = tr.student_id';
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Summary covers the whole filtered set, not just this page.
        $sum = Database::fetchOne(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN p.status = 'success' AND p.refunded_at IS NULL THEN p.amount END), 0) AS verified
               $from $whereSql",
            $params
        );
        $total = (int) ($sum['total'] ?? 0);
        [$page, $totalPages, $offset] = self::paging($filters, $total, $pageSize);

        $rows = Database::fetchAll(
            "SELECT p.*, tr.id AS tr_id, tr.request_no, s.matric_no, s.full_name
               $from $whereSql
              ORDER BY p.initiated_at DESC, p.id DESC
              LIMIT $pageSize OFFSET $offset",
            $params
        );

        $items = array_map(static fn (array $p): array => [
            'transcriptId' => IdCodec::encode('tr', (int) $p['tr_id']),
            'requestNo'    => $p['request_no'],
            'student'      => ['matricNo' => $p['matric_no'], 'fullName' => $p['full_name']],
        ] + self::paymentView($p), $rows);

        return [
            'items'   => $items,
            'summary' => ['count' => $total, 'verifiedAmount' => Response::naira($sum['verified'] ?? 0)],
            'page'    => self::pageBlock($page, $pageSize, $total, $totalPages),
        ];
    }

    /** For the admin PDF route. Returns the request number when the PDF may be produced. */
    public static function requireDocumentable(int $requestId): string
    {
        $r = Database::fetchOne('SELECT request_no, status FROM transcript_requests WHERE id = ?', [$requestId]);
        if ($r === null) {
            throw new ApiException('not_found', 'We could not find that record.', 404);
        }
        if (!in_array($r['status'], ['processing', 'ready', 'sent'], true)) {
            throw new ApiException('invalid_transition', 'The transcript PDF is only available once processing has started.', 409);
        }
        return (string) $r['request_no'];
    }

    // ---- helpers -------------------------------------------------------------

    private static function destination(array $r): array
    {
        return [
            'type'  => $r['destination_type'],
            'label' => $r['destination_type'] === 'personal' ? 'Personal copy' : $r['institution_name'],
        ];
    }

    /** One payments row in API shape (shared by the list and the detail). */
    private static function paymentView(array $p): array
    {
        return [
            'reference'     => $p['reference'],
            'gateway'       => $p['gateway'] === 'paystack' ? 'gateway' : 'simulated',
            'status'        => $p['status'],
            'amount'        => Response::naira($p['amount']),
            'currency'      => $p['currency'],
            'initiatedAt'   => Response::iso($p['initiated_at']),
            'paidAt'        => Response::iso($p['paid_at']),
            'verifiedAt'    => Response::iso($p['verified_at']),
            'receiptNumber' => $p['receipt_no'],
            'refundedAt'    => Response::iso($p['refunded_at']),
            'failureReason' => $p['failure_reason'],
        ];
    }

    private static function pageSize(array $filters, array &$fields): int
    {
        $size = (int) ($filters['pageSize'] ?? 20);
        if ($size < 1 || $size > 50) {
            $fields['pageSize'] = 'Page size must be between 1 and 50.';
            return 20;
        }
        return $size;
    }

    /** @return array{0:int,1:int,2:int} page, totalPages, offset */
    private static function paging(array $filters, int $total, int $pageSize): array
    {
        $totalPages = max((int) ceil($total / $pageSize), 1);
        $page = min(max((int) ($filters['page'] ?? 1), 1), $totalPages);
        return [$page, $totalPages, ($page - 1) * $pageSize];
    }

    private static function pageBlock(int $page, int $pageSize, int $total, int $totalPages): array
    {
        return ['page' => $page, 'pageSize' => $pageSize, 'totalItems' => $total, 'totalPages' => $totalPages];
    }
}

final class TranscriptIssuer
{
    /** Admin action => the status it moves the request to. */
    private const TARGET = [
        'start_processing' => 'processing',
        'mark_ready'       => 'ready',
        'mark_sent'        => 'sent',
        'reject'           => 'rejected',
    ];

    /**
     * Actions: start_processing, mark_ready, mark_sent, reject. One transaction.
     * $adminId is kept for the audit columns (migration 005); nothing stores it yet.
     */
    public static function apply(int $requestId, int $adminId, string $action, ?string $reason = null): array
    {
        $fields = [];
        if (!isset(self::TARGET[$action])) {
            $fields['action'] = 'Choose a valid action.';
        }
        $reason = $reason === null ? null : trim($reason);
        if ($action === 'reject' && ($reason === null || mb_strlen($reason) < 3 || mb_strlen($reason) > 255)) {
            $fields['reason'] = 'Enter a reason of 3 to 255 characters.';
        }
        if ($fields !== []) {
            throw new ApiException('validation_failed', 'Check the highlighted fields and try again.', 422, $fields);
        }

        return Database::transaction(function () use ($requestId, $action, $reason): array {
            $row = Database::fetchOne('SELECT * FROM transcript_requests WHERE id = ? FOR UPDATE', [$requestId]);
            if ($row === null) {
                throw new ApiException('not_found', 'We could not find that record.', 404);
            }
            // adminActions() is the single source of truth for what is allowed right now.
            if (!in_array($action, Transcript::adminActions($row), true)) {
                throw new ApiException('invalid_transition', 'That action is not available for this request.', 409);
            }
            if (($action === 'mark_ready' && $row['destination_type'] !== 'personal')
                || ($action === 'mark_sent' && $row['destination_type'] !== 'institution')) {
                throw new ApiException('invalid_transition', 'That action does not apply to this delivery type.', 409);
            }

            $extra = [];
            if ($action === 'mark_ready' || $action === 'mark_sent') {
                // Snapshot taken by the server, in the same UPDATE as the status (CHECK chk_req_issued).
                $studentId = (int) $row['student_id'];
                $now = gmdate('Y-m-d H:i:s');
                $extra = [
                    'transcript_no' => sprintf('UITR-%s-%06d', gmdate('Y'), $requestId),
                    'total_units'   => GpaCalculator::totalUnitsCounted($studentId),
                    'cgpa'          => GpaCalculator::overallCgpa($studentId),
                    'ready_at'      => $now,
                ];
                if ($action === 'mark_sent') {
                    $extra['sent_at'] = $now;
                }
            } elseif ($action === 'reject') {
                $extra = ['rejection_reason' => $reason];
            }

            Transcript::transition($requestId, self::TARGET[$action], $extra);

            $refundRequired = $action === 'reject' ? Payment::markRefunded($requestId) : false;

            // Re-read so the response never depends on what transition() returns.
            $updated = Database::fetchOne('SELECT * FROM transcript_requests WHERE id = ?', [$requestId]);
            $issued = in_array($updated['status'], ['ready', 'sent'], true) && $updated['transcript_no'] !== null
                ? [
                    'transcriptNo' => $updated['transcript_no'],
                    'totalUnits'   => (int) $updated['total_units'],
                    'cgpa'         => (float) $updated['cgpa'],
                    'readyAt'      => Response::iso($updated['ready_at']),
                    'sentAt'       => Response::iso($updated['sent_at']),
                ]
                : null;

            return [
                'transcriptId'    => IdCodec::encode('tr', $requestId),
                'status'          => $updated['status'],
                'adminActions'    => Transcript::adminActions($updated),
                'rejectionReason' => $updated['rejection_reason'],
                'refundRequired'  => (bool) $refundRequired,
                'issued'          => $issued,
            ];
        });
    }
}