<?php
// Owner: Dev B.

interface PaymentGateway
{
    /** Proposed. Returns ['status' => ..., 'amount' => ...]; the server never trusts the browser. */
    public function verify(string $reference): array;
}

final class SimulatedGateway implements PaymentGateway
{
    public function verify(string $reference): array { throw new RuntimeException('Not implemented'); }
}

/** Optional. */
final class PaystackGateway implements PaymentGateway
{
    public function verify(string $reference): array { throw new RuntimeException('Not implemented'); }
}

final class Payment
{
    /** Proposed. */
    public static function initiate(int $requestId, int $studentId): array { throw new RuntimeException('Not implemented'); }
    /** Proposed. */
    public static function findByReference(string $reference): ?array { throw new RuntimeException('Not implemented'); }
    /** In a transaction; safe to repeat. */
    public static function settle(string $reference): array { throw new RuntimeException('Not implemented'); }
    public static function receiptData(int $requestId, int $studentId): ?array { throw new RuntimeException('Not implemented'); }
    /** Flags the verified payment refunded (used by C's reject flow). */
    public static function markRefunded(int $requestId): bool { throw new RuntimeException('Not implemented'); }
}