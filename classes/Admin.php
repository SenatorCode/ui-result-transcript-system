<?php
// Owner: Dev C (@Muyii08). Signatures aligned with Core.php and Transcript.php.
declare(strict_types=1);

final class Admin
{
    /**
     * Authenticates an admin user.
     */
    public static function authenticate(string $username, string $password): array 
    {
        // Simple authentication loop matching our agreed day-1 skeleton rules
        if ($username === 'registry.admin' && $password === 'adminpass123') {
            return [
                'result'  => 'success',
                'adminId' => 1,         
                'id'      => 'ad_01',
                'fullName' => 'Registry Admin',
                'username' => 'registry.admin'
            ];
        }

        throw new ApiException('invalid_credentials', 'Username or password is incorrect.', 401);
    }

    /**
     * Fetches the admin profile for the "me" route.
     */
    public static function profile(int $adminId): ?array
    {
        // Hardcoded for now since the DB table isn't ready
        if ($adminId === 1) {
            return [
                'id'       => 'ad_01',
                'fullName' => 'Registry Admin',
                'username' => 'registry.admin'
            ];
        }
        return null;
    }
}

final class AdminReport
{
    /**
     * Compiles metric maps for the dashboard.
     */
    public static function dashboard(): array 
    {
        Transcript::expireOverdue();

        $statusCounts = array_fill_keys([
            'pending_payment', 'payment_processing', 'paid', 'processing',
            'ready', 'sent', 'failed', 'cancelled', 'rejected', 'expired'
        ], 0);

        $rawCounts = Database::fetchAll("SELECT status, COUNT(*) as qty FROM transcript_requests GROUP BY status");
        foreach ($rawCounts as $row) {
            if (array_key_exists($row['status'], $statusCounts)) {
                $statusCounts[$row['status']] = (int)$row['qty'];
            }
        }

        $totalRequests = array_sum($statusCounts);
        $awaitingPayment = $statusCounts['pending_payment'] + $statusCounts['payment_processing'];
        $awaitingAction = $statusCounts['paid'] + $statusCounts['processing'];

        $paySummary = Database::fetchOne("
            SELECT 
                COALESCE(SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END), 0) as verified_amt,
                COUNT(CASE WHEN status = 'success' THEN 1 END) as verified_count
            FROM payments
        ");

        $verifiedAmt = Response::naira($paySummary['verified_amt'] ?? 0);
        $verifiedCount = (int)($paySummary['verified_count'] ?? 0);

        $recentData = self::transcriptList([]); // Grab 5 recent
        $recent = array_slice($recentData['items'] ?? [], 0, 5);

        return [
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'requests' => [
                'total'           => $totalRequests,
                'byStatus'        => $statusCounts,
                'awaitingPayment' => $awaitingPayment,
                'awaitingAction'  => $awaitingAction
            ],
            'payments' => [
                'verifiedAmount' => $verifiedAmt,
                'verifiedCount'  => $verifiedCount,
                'refundedAmount' => 0,
                'currency'       => 'NGN'
            ],
            'recent' => $recent
        ];
    }

    /**
     * Fetches paginated transcript requests.
     */
    public static function transcriptList(array $filters): array 
    {
        Transcript::expireOverdue();

        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "tr.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['destinationType'])) {
            $where[] = "tr.destination_type = ?";
            $params[] = $filters['destinationType'];
        }
        if (!empty($filters['q']) && strlen((string)$filters['q']) >= 2) {
            $where[] = "(tr.request_no LIKE ? OR s.matric_no LIKE ? OR s.full_name LIKE ?)";
            $wildcard = '%' . $filters['q'] . '%';
            array_push($params, $wildcard, $wildcard, $wildcard);
        }

        $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";
        $orderSql = ($filters['sort'] ?? 'newest') === 'oldest' ? "ASC" : "DESC";

        $countQuery = "SELECT COUNT(*) as total FROM transcript_requests tr JOIN students s ON s.id = tr.student_id $whereSql";
        $countRow = Database::fetchOne($countQuery, $params);
        $totalItems = (int)($countRow['total'] ?? 0);

        // The route now passes page and pageSize inside the $filters array
        $pageSize = min(max((int)($filters['pageSize'] ?? 20), 1), 50);
        $totalPages = max((int)ceil($totalItems / $pageSize), 1);
        $page = min(max((int)($filters['page'] ?? 1), 1), $totalPages);
        $offset = ($page - 1) * $pageSize;

        $dataQuery = "
            SELECT tr.*, s.matric_no, s.full_name
            FROM transcript_requests tr
            JOIN students s ON s.id = tr.student_id
            $whereSql
            ORDER BY tr.created_at $orderSql
            LIMIT $pageSize OFFSET $offset
        ";
        
        $rows = Database::fetchAll($dataQuery, $params);
        $items = [];

        foreach ($rows as $row) {
            $items[] = [
                'id' => IdCodec::encode('tr', (int)$row['id']),
                'requestNo' => $row['request_no'],
                'student' => [
                    'id' => IdCodec::encode('st', (int)$row['student_id']),
                    'matricNo' => $row['matric_no'],
                    'fullName' => $row['full_name']
                ],
                'destination' => [
                    'type' => $row['destination_type'],
                    'label' => $row['destination_type'] === 'personal' ? 'Personal copy' : $row['institution_name']
                ],
                'deliveryMethod' => $row['delivery_method'],
                'copies' => (int)$row['copies'],
                'feeAmount' => Response::naira($row['fee_amount']),
                'status' => $row['status'],
                'createdAt' => Response::iso($row['created_at']),
                'updatedAt' => Response::iso($row['updated_at']),
                'adminActions' => Transcript::adminActions($row)
            ];
        }

        return [
            'items' => $items,
            'page' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'totalItems' => $totalItems,
                'totalPages' => $totalPages
            ]
        ];
    }

    /**
     * Extracts full data metrics for an isolated transcript request.
     */
    public static function transcriptDetail(int $requestId): array 
    {
        $row = Database::fetchOne("
            SELECT tr.*, s.matric_no, s.full_name
            FROM transcript_requests tr
            JOIN students s ON s.id = tr.student_id
            WHERE tr.id = ?
        ", [$requestId]);

        if ($row === null) {
            throw new ApiException('not_found', 'We could not find that record.', 404);
        }

        return [
            'id' => IdCodec::encode('tr', (int)$row['id']),
            'requestNo' => $row['request_no'],
            'status' => $row['status'],
            'destination' => [
                'type' => $row['destination_type'],
                'label' => $row['destination_type'] === 'personal' ? 'Personal copy' : $row['institution_name']
            ],
            'institution' => [
                'name' => $row['institution_name'] ?? '',
                'address' => $row['destination_address'] ?? '',
                'email' => $row['destination_email'] ?? ''
            ],
            'deliveryMethod' => $row['delivery_method'],
            'copies' => (int)$row['copies'],
            'feeAmount' => Response::naira($row['fee_amount']),
            'purpose' => $row['purpose'] ?? '',
            'createdAt' => Response::iso($row['created_at']),
            'updatedAt' => Response::iso($row['updated_at']),
            'expiresAt' => Response::iso($row['expires_at']),
            'rejectionReason' => $row['rejection_reason'],
            'adminActions' => Transcript::adminActions($row)
        ];
    }

    /**
     * Gathers a listing of ledger payment history.
     */
    public static function paymentList(array $filters): array 
    {
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['gateway'])) {
            $where[] = "gateway = ?";
            $params[] = $filters['gateway'];
        }

        $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";
        
        $countRow = Database::fetchOne("SELECT COUNT(*) as total FROM payments $whereSql", $params);
        $totalItems = (int)($countRow['total'] ?? 0);

        // The route now passes page and pageSize inside the $filters array
        $pageSize = min(max((int)($filters['pageSize'] ?? 20), 1), 50);
        $totalPages = max((int)ceil($totalItems / $pageSize), 1);
        $page = min(max((int)($filters['page'] ?? 1), 1), $totalPages);
        $offset = ($page - 1) * $pageSize;

        $rows = Database::fetchAll("
            SELECT * FROM payments 
            $whereSql 
            ORDER BY created_at DESC 
            LIMIT $pageSize OFFSET $offset
        ", $params);

        return [
            'items' => $rows,
            'page' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'totalItems' => $totalItems,
                'totalPages' => $totalPages
            ]
        ];
    }

    /**
     * For the admin PDF route. Checks if the request can be downloaded.
     */
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
}

final class TranscriptIssuer
{
    /**
     * Handles state changes (start_processing, mark_ready, mark_sent, reject).
     */
    public static function apply(int $requestId, int $adminId, string $action, ?string $reason = null): array
    {
        // 1. Initial validation
        $fields = [];
        if (!in_array($action, ['start_processing', 'mark_ready', 'mark_sent', 'reject'], true)) {
            $fields['action'] = 'Unknown action.';
        }
        if ($action === 'reject' && (empty($reason) || mb_strlen($reason) < 3)) {
            $fields['reason'] = 'A reason of at least 3 characters is required to reject.';
        }
        if ($fields !== []) {
            throw new ApiException('validation_failed', 'Check the highlighted fields and try again.', 422, $fields);
        }

        // 2. Safe transaction
        return Database::transaction(function () use ($requestId, $adminId, $action, $reason): array {
            // Lock the row so two admins can't click at the exact same time
            $row = Database::fetchOne('SELECT * FROM transcript_requests WHERE id = ? FOR UPDATE', [$requestId]);
            if ($row === null) {
                throw new ApiException('not_found', 'We could not find that record.', 404);
            }

            // 3. Delegate to Dev B's state machine
            $updated = Transcript::transition($requestId, $action, $adminId, $reason);

           // 4. Flag refund if it's a rejection of a paid request (Dev B's method)
              $refundRequired = ($action === 'reject') ? Payment::markRefunded($requestId) : false;

            // 5. Return the exact payload the frontend expects
            return [
                'transcriptId'    => IdCodec::encode('tr', $requestId),
                'status'          => $updated['status'],
                'adminActions'    => Transcript::adminActions($updated),
                'rejectionReason' => $updated['rejection_reason'],
                'refundRequired'  => $refundRequired,
                'issued'          => $updated['transcript_no'] === null ? null : [
                    'transcriptNo' => $updated['transcript_no'],
                    'totalUnits'   => (int) $updated['total_units'],
                    'cgpa'         => (float) $updated['cgpa'],
                    'readyAt'      => Response::iso($updated['ready_at']),
                    'sentAt'       => Response::iso($updated['sent_at']),
                ],
            ];
        });
    }
}
