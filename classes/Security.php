<?php
// Owner: Dev B. Session, Csrf, RateLimiter, Idempotency, Auth.
//
// Extras beyond the agreed signatures (all additive):
//   Csrf::rotate(), Auth::loginStudent($id), Auth::loginAdmin($id).
// RateLimiter scopes: 'login' (student), 'reset' (password reset), 'admin' (admin login, for Dev C).

declare(strict_types=1);

final class Session
{
    public const STUDENT_COOKIE = 'UISTUSESS';
    public const ADMIN_COOKIE   = 'UIADMINSESS';
    private const IDLE_SECONDS  = 1800; // 30 minutes without a request ends the session

    /** True when the URL is under /api/admin/. Decides which cookie is used. */
    public static function isAdminRequest(): bool
    {
        $path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
        $path = (string) preg_replace('#/+#', '/', $path);
        return str_contains($path, '/api/admin/');
    }

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name(self::isAdminRequest() ? self::ADMIN_COOKIE : self::STUDENT_COOKIE);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', '3600');
        session_cache_limiter(''); // Response sets Cache-Control itself
        $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (!session_start()) {
            throw new ApiException('service_unavailable', 'The service is temporarily unavailable. Try again shortly.', 503);
        }
        $now = time();
        $last = $_SESSION['_last_seen'] ?? null;
        if (is_int($last) && $now - $last > self::IDLE_SECONDS) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['_last_seen'] = $now;
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        if (!headers_sent() && PHP_SAPI !== 'cli') {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?: 'Lax',
            ]);
        }
        session_destroy();
    }
}

final class Csrf
{
    /** The token for this session (created on first use). */
    public static function token(): string
    {
        if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf']) || $_SESSION['csrf'] === '') {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    /** A fresh token. Call on login. */
    public static function rotate(): string
    {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf'];
    }

    /** Checks X-CSRF-Token; throws 403 csrf_invalid. */
    public static function verify(): void
    {
        $sent = Request::header('X-CSRF-Token');
        $expected = $_SESSION['csrf'] ?? null;
        if ($sent === null || !is_string($expected) || $expected === '' || !hash_equals($expected, $sent)) {
            throw new ApiException('csrf_invalid', 'Your session needs refreshing. Reload the page and try again.', 403);
        }
    }
}

final class RateLimiter
{
    private const MAX_FAILURES   = 5;
    private const WINDOW_MINUTES = 15;

    /** Throws 429 rate_limited (with retryAfterSeconds) after 5 failures in 15 minutes. */
    public static function assertAllowed(string $scope, string $identifier): void
    {
        [$kind, $id] = self::key($scope, $identifier);
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS failures,
                    TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), MIN(attempted_at) + INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE) AS wait_s
               FROM login_attempts
              WHERE kind = ? AND identifier = ? AND ip_address = ? AND success = 0
                AND attempted_at > UTC_TIMESTAMP() - INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE',
            [$kind, $id, Request::ip()]
        );
        if ((int) ($row['failures'] ?? 0) >= self::MAX_FAILURES) {
            $wait = max(1, (int) ($row['wait_s'] ?? 60));
            throw new ApiException('rate_limited', 'Too many attempts. Wait a few minutes and try again.', 429, ['retryAfterSeconds' => $wait]);
        }
    }

    public static function recordFailure(string $scope, string $identifier): void
    {
        [$kind, $id] = self::key($scope, $identifier);
        Database::execute(
            'INSERT INTO login_attempts (kind, identifier, ip_address, success) VALUES (?, ?, ?, 0)',
            [$kind, $id, Request::ip()]
        );
        Database::execute('DELETE FROM login_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
    }

    /** Call after a successful login or reset. */
    public static function clear(string $scope, string $identifier): void
    {
        [$kind, $id] = self::key($scope, $identifier);
        Database::execute(
            'DELETE FROM login_attempts WHERE kind = ? AND identifier = ? AND ip_address = ?',
            [$kind, $id, Request::ip()]
        );
    }

    /** @return array{0:string,1:string} [kind column, normalised identifier] */
    private static function key(string $scope, string $identifier): array
    {
        $kind = $scope === 'reset' ? 'reset' : 'login';
        $id = strtoupper(trim($identifier));
        if ($scope === 'admin') {
            $id = 'ADMIN:' . $id; // admin and student logins never share a counter
        }
        return [$kind, substr($id, 0, 120)];
    }
}

final class Idempotency
{
    /**
     * Same key + same body: returns the stored result without running $fn again.
     * Same key + different body: 409 idempotency_conflict.
     * $endpoint should include the resource, e.g. 'transcripts/pay:5'.
     * The student comes from the session. A route sets its own HTTP status (201 for create).
     * If $fn throws, the key is released so the student can retry.
     */
    public static function run(string $endpoint, ?string $key, array $body, callable $fn): array
    {
        $studentId = Auth::requireStudent();
        $key = $key === null ? '' : trim($key);
        if ($key === '' || strlen($key) > 100) {
            throw new ApiException('validation_failed', 'Send a unique Idempotency-Key header (up to 100 characters).', 422, ['Idempotency-Key' => 'This header is required.']);
        }
        $hash = hash('sha256', json_encode(self::normalise($body), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $endpoint = substr($endpoint, 0, 100);

        Database::execute('DELETE FROM idempotency_keys WHERE created_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');

        try {
            Database::execute(
                'INSERT INTO idempotency_keys (student_id, endpoint, idem_key, request_hash) VALUES (?, ?, ?, ?)',
                [$studentId, $endpoint, $key, $hash]
            );
            $claimId = Database::lastInsertId();
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $row = Database::fetchOne(
                'SELECT id, request_hash, response_body FROM idempotency_keys WHERE student_id = ? AND endpoint = ? AND idem_key = ?',
                [$studentId, $endpoint, $key]
            );
            if ($row === null) {
                throw $e;
            }
            if (!hash_equals((string) $row['request_hash'], $hash)) {
                throw new ApiException('idempotency_conflict', 'This request key was already used with different details. Start the action again.', 409);
            }
            if ($row['response_body'] !== null) {
                if (!headers_sent()) {
                    header('Idempotent-Replayed: true');
                }
                return json_decode((string) $row['response_body'], true, 512, JSON_THROW_ON_ERROR);
            }
            // Claimed but no result: still running, or the first request died. Take over after 60 seconds.
            $taken = Database::execute(
                'UPDATE idempotency_keys SET created_at = UTC_TIMESTAMP()
                  WHERE id = ? AND response_body IS NULL AND created_at < UTC_TIMESTAMP() - INTERVAL 60 SECOND',
                [(int) $row['id']]
            );
            if ($taken !== 1) {
                throw new ApiException('conflict', 'This request is still being processed. Try again in a moment.', 409);
            }
            $claimId = (int) $row['id'];
        }

        try {
            $result = $fn();
        } catch (Throwable $e) {
            Database::execute('DELETE FROM idempotency_keys WHERE id = ?', [$claimId]);
            throw $e;
        }
        Database::execute(
            'UPDATE idempotency_keys SET response_status = 200, response_body = ? WHERE id = ?',
            [json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $claimId]
        );
        return $result;
    }

    /** Sorts keys so the same body always hashes the same. */
    private static function normalise($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $k => $v) {
            $value[$k] = self::normalise($v);
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return $value;
    }
}

final class Auth
{
    /** Call after a successful student login: new session id, new CSRF token. */
    public static function loginStudent(int $studentId): void
    {
        Session::regenerate();
        $_SESSION['student_id'] = $studentId;
        Csrf::rotate();
    }

    /** Call after a successful admin login (admin cookie session). */
    public static function loginAdmin(int $adminId): void
    {
        Session::regenerate();
        $_SESSION['admin_id'] = $adminId;
        Csrf::rotate();
    }

    /** Returns the student id or throws 401. A suspended or deleted student is signed out. */
    public static function requireStudent(): int
    {
        $id = $_SESSION['student_id'] ?? null;
        if (!is_int($id)) {
            throw self::expired();
        }
        $row = Database::fetchOne('SELECT status FROM students WHERE id = ?', [$id]);
        if ($row === null || $row['status'] === 'suspended') {
            Session::destroy();
            throw self::expired();
        }
        return $id;
    }

    /** Returns the admin id or throws 401. A deactivated admin is signed out on the next request. */
    public static function requireAdmin(): int
    {
        $id = $_SESSION['admin_id'] ?? null;
        if (!is_int($id)) {
            throw self::expired();
        }
        $row = Database::fetchOne('SELECT is_active FROM admins WHERE id = ?', [$id]);
        if ($row === null || (int) $row['is_active'] !== 1) {
            Session::destroy();
            throw self::expired();
        }
        return $id;
    }

    private static function expired(): ApiException
    {
        return new ApiException('session_expired', 'Your session has expired. Sign in to continue.', 401);
    }
}