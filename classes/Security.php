<?php
// Owner: Dev B.

final class Session
{
    /** Student or admin cookie chosen from the /api/admin/ path prefix. */
    public static function start(): void { throw new RuntimeException('Not implemented'); }
    public static function regenerate(): void { throw new RuntimeException('Not implemented'); }
    public static function destroy(): void { throw new RuntimeException('Not implemented'); }
}

final class Csrf
{
    public static function token(): string { throw new RuntimeException('Not implemented'); }
    /** Checks X-CSRF-Token; throws 403 csrf_invalid. */
    public static function verify(): void { throw new RuntimeException('Not implemented'); }
}

final class RateLimiter
{
    /** Throws 429 rate_limited with Retry-After when blocked. */
    public static function assertAllowed(string $scope, string $identifier): void { throw new RuntimeException('Not implemented'); }
    public static function recordFailure(string $scope, string $identifier): void { throw new RuntimeException('Not implemented'); }
    public static function clear(string $scope, string $identifier): void { throw new RuntimeException('Not implemented'); }
}

final class Idempotency
{
    public static function run(string $endpoint, ?string $key, array $body, callable $fn): array { throw new RuntimeException('Not implemented'); }
}

final class Auth
{
    /** Returns the student id or throws 401. */
    public static function requireStudent(): int { throw new RuntimeException('Not implemented'); }
    /** Returns the admin id or throws 401; also rejects a deactivated admin. */
    public static function requireAdmin(): int { throw new RuntimeException('Not implemented'); }
}