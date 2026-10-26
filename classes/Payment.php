<?php
// Owner: Dev B.

declare(strict_types=1);

interface PaymentGateway
{
    /**
     * Asks the gateway what happened to a payment. The server never trusts the browser.
     * @return array{status:string,amount:int,txnId?:string,reason?:string,raw?:array}
     *         status is 'success', 'failed' or 'pending'; amount is whole naira.
     */
    public function verify(string $reference): array;
}

/** Demo gateway. Refuses to run unless PAYMENT_MODE is 'simulated'. */
final class SimulatedGateway implements PaymentGateway
{
    public function verify(string $reference): array
    {
        if (Config::get('PAYMENT_MODE', 'simulated') !== 'simulated') {
            throw new ApiException('forbidden', 'Simulated payments are not accepted.', 403);
        }
        $payment = Database::fetchOne('SELECT amount FROM payments WHERE reference = ?', [$reference]);
        if ($payment === null) {
            throw new ApiException('not_found', 'We could not find that record.', 404);
        }
        // Demo switch: set 'SIMULATE_PAYMENT_FAILURE' => true in your local config.php to see a decline.
        if (Config::get('SIMULATE_PAYMENT_FAILURE', false) === true) {
            return [
                'status' => 'failed', 'amount' => 0, 'reason' => 'Card declined (simulated)',
                'raw'    => ['status' => 'failed', 'mode' => 'simulated', 'gateway_response' => 'Card declined'],
            ];
        }
        return [
            'status' => 'success',
            'amount' => Response::naira($payment['amount']),
            'txnId'  => 'SIM-' . strtoupper(bin2hex(random_bytes(6))),
            'raw'    => ['status' => 'success', 'mode' => 'simulated'],
        ];
    }
}

/** Optional. */
final class PaystackGateway implements PaymentGateway
{
    public function verify(string $reference): array { throw new RuntimeException('Not implemented'); }
}

final class Payment
{
    /**
     * Starts a payment: locks the request, moves it to payment_processing (only from
     * pending_payment or failed, otherwise 409) and records an 'initiated' attempt.
     */
    public static function initiate(int $requestId, int $studentId): array
    {
        $mode = (string) Config::get('PAYMENT_MODE', 'simulated');
        if ($mode === 'paystack') {
            throw new RuntimeException('Not implemented');
        }
        if ($mode !== 'simulated') {
            throw new ApiException('service_unavailable', 'Payments are not available right now.', 503);
        }
        Transcript::expireOverdue();

        return Database::transaction(function () use ($requestId, $studentId): array {
            $row = Database::fetchOne(
                'SELECT * FROM transcript_requests WHERE id = ? AND student_id = ? FOR UPDATE',
                [$requestId, $studentId]
            );
            if ($row === null) {
                throw new ApiException('not_found', 'We could not find that record.', 404);
            }
            Transcript::transition($requestId, TranscriptStatus::PAYMENT_PROCESSING); // 409 if not allowed
            Database::execute("UPDATE payments SET status = 'cancelled' WHERE request_id = ? AND status = 'initiated'", [$requestId]);

            $reference = sprintf('PAY-%s-%s', gmdate('Y'), strtoupper(bin2hex(random_bytes(5))));
            Database::execute(
                "INSERT INTO payments (request_id, reference, gateway, amount, currency, status) VALUES (?, ?, 'simulated', ?, ?, 'initiated')",
                [$requestId, $reference, $row['fee_amount'], (string) Config::get('CURRENCY')]
            );
            return [
                'transcriptId' => IdCodec::encode('tr', $requestId),
                'mode'         => 'simulated',
                'paymentUrl'   => null,
                'reference'    => $reference,
                'status'       => TranscriptStatus::PAYMENT_PROCESSING,
            ];
        });
    }

    public static function findByReference(string $reference): ?array
    {
        return Database::fetchOne('SELECT * FROM payments WHERE reference = ?', [$reference]);
    }

    /**
     * Verifies a payment with its gateway and updates the payment and the request together.
     * Safe to repeat: a settled payment just reports its state. The caller checks ownership.
     */
    public static function settle(string $reference): array
    {
        return Database::transaction(function () use ($reference): array {
            $peek = self::findByReference($reference);
            if ($peek === null) {
                throw new ApiException('not_found', 'We could not find that record.', 404);
            }
            $requestId = (int) $peek['request_id'];
            // Lock order is always request first, then payment.
            $request = Database::fetchOne('SELECT * FROM transcript_requests WHERE id = ? FOR UPDATE', [$requestId]);
            $payment = Database::fetchOne('SELECT * FROM payments WHERE id = ? FOR UPDATE', [(int) $peek['id']]);

            if ($payment['status'] === 'initiated' && $request['status'] === TranscriptStatus::PAYMENT_PROCESSING) {
                $gateway = $payment['gateway'] === 'paystack' ? new PaystackGateway() : new SimulatedGateway();
                self::apply($payment, $request, $gateway->verify($reference));
                $request = Database::fetchOne('SELECT * FROM transcript_requests WHERE id = ?', [$requestId]);
                $payment = Database::fetchOne('SELECT * FROM payments WHERE id = ?', [(int) $payment['id']]);
            }
            return [
                'transcriptId'     => IdCodec::encode('tr', $requestId),
                'paymentStatus'    => $payment['status'],
                'transcriptStatus' => $request['status'],
                'allowedActions'   => Transcript::allowedActions($request),
                'verifiedAt'       => Response::iso($payment['verified_at']),
                'receiptAvailable' => $payment['status'] === 'success',
            ];
        });
    }

    private static function apply(array $payment, array $request, array $result): void
    {
        $status = (string) ($result['status'] ?? 'failed');
        if ($status === 'pending') {
            return; // not settled yet; the student can verify again later
        }
        $raw = json_encode($result['raw'] ?? $result, JSON_THROW_ON_ERROR);
        $paidInFull = $status === 'success'
            && (int) ($result['amount'] ?? 0) === Response::naira($request['fee_amount']);

        if ($paidInFull) {
            Database::execute(
                "UPDATE payments SET status = 'success', gateway_txn_id = ?, paid_at = UTC_TIMESTAMP(),
                        verified_at = UTC_TIMESTAMP(), receipt_no = ?, raw_response = ? WHERE id = ?",
                [$result['txnId'] ?? null, sprintf('RCPT-%s-%d', gmdate('Y'), 1000 + (int) $payment['id']), $raw, (int) $payment['id']]
            );
            Transcript::transition((int) $request['id'], TranscriptStatus::PAID);
            return;
        }
        $reason = $status === 'success'
            ? 'The amount paid does not match the fee.'
            : (string) ($result['reason'] ?? 'The payment was not successful.');
        Database::execute(
            "UPDATE payments SET status = 'failed', failure_reason = ?, raw_response = ? WHERE id = ?",
            [substr($reason, 0, 255), $raw, (int) $payment['id']]
        );
        Transcript::transition((int) $request['id'], TranscriptStatus::FAILED);
    }

    /** Feature 6. */
        /**
     * Receipt for the student's own request, or null when there is no verified payment
     * (or it was refunded). The array shape is what ReceiptPdf::render() expects.
     */
    public static function receiptData(int $requestId, int $studentId): ?array
    {
        $row = Database::fetchOne(
            "SELECT p.receipt_no, p.amount, p.currency, p.paid_at, p.reference,
                    tr.request_no, tr.destination_type, tr.institution_name, tr.copies,
                    s.full_name, s.matric_no
               FROM payments p
               JOIN transcript_requests tr ON tr.id = p.request_id
               JOIN students s ON s.id = tr.student_id
              WHERE p.request_id = ? AND tr.student_id = ?
                AND p.status = 'success' AND p.refunded_at IS NULL",
            [$requestId, $studentId]
        );
        if ($row === null) {
            return null;
        }
        return [
            'receiptNumber' => $row['receipt_no'],
            'amount'        => Response::naira($row['amount']),
            'currency'      => $row['currency'],
            'paidAt'        => Response::iso($row['paid_at']),
            'reference'     => $row['reference'],
            'requestNo'     => $row['request_no'],
            'studentName'   => $row['full_name'],
            'matricNo'      => $row['matric_no'],
            'destination'   => $row['destination_type'] === 'personal' ? 'Personal copy' : $row['institution_name'],
            'copies'        => (int) $row['copies'],
            'university'    => (string) Config::get('UNIVERSITY_NAME'),
        ];
    }

    /** Flags the verified payment refunded (C's reject flow). True when a payment was flagged. */
    public static function markRefunded(int $requestId): bool
    {
        return Database::execute(
            "UPDATE payments SET refunded_at = UTC_TIMESTAMP()
              WHERE request_id = ? AND status = 'success' AND refunded_at IS NULL",
            [$requestId]
        ) === 1;
    }
}