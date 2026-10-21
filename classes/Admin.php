<?php
// Owner: Dev C.

final class Admin
{
    /** Returns ['id' => 'ad_01', 'fullName' => ..., 'username' => ...]; throws invalid_credentials / account_deactivated. */
    public static function authenticate(string $username, string $password): array { throw new RuntimeException('Not implemented'); }
}

final class AdminReport
{
    /** Each method calls Transcript::expireOverdue() first. */
    public static function dashboard(): array { throw new RuntimeException('Not implemented'); }
    public static function transcriptList(array $filters): array { throw new RuntimeException('Not implemented'); }
    public static function transcriptDetail(int $requestId): array { throw new RuntimeException('Not implemented'); }
    public static function paymentList(array $filters): array { throw new RuntimeException('Not implemented'); }
}

final class TranscriptIssuer
{
    /** Actions: start_processing, mark_ready, mark_sent, reject. Runs in one transaction. */
    public static function apply(int $requestId, int $adminId, string $action, ?string $reason = null): array { throw new RuntimeException('Not implemented'); }
}