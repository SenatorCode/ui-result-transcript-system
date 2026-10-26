<?php
// Owner: Dev B.

declare(strict_types=1);

final class Student
{
    // bcrypt of a throwaway password: unknown matric numbers still cost one password_verify,
    // so response time does not reveal whether an account exists.
    private const DUMMY_HASH = '$2y$10$oU3c6KxgKk84BoMlOCExHOsmwHMH7SQjrKzMK97IogKKH5wJ/kTJ6';

    private const BASE = 'SELECT s.id, s.matric_no, s.full_name, s.email, s.level, s.status, s.password_hash,
                                 d.name AS department
                            FROM students s JOIN departments d ON d.id = s.department_id';

    /** Row with password_hash and status, or null. Matric numbers are stored uppercase. */
    public static function findByMatricNo(string $matricNo): ?array
    {
        return Database::fetchOne(self::BASE . ' WHERE s.matric_no = ?', [strtoupper(trim($matricNo))]);
    }

    public static function findById(int $id): ?array
    {
        return Database::fetchOne(self::BASE . ' WHERE s.id = ?', [$id]);
    }

    /** Accepts null (unknown account) and still does the same work. */
    public static function verifyPassword(?array $student, string $password): bool
    {
        $ok = password_verify($password, $student['password_hash'] ?? self::DUMMY_HASH);
        return $student !== null && $ok;
    }

    /** The shape login and auth/me return. Never includes the hash. */
    public static function publicShape(array $student): array
    {
        return [
            'id'         => IdCodec::encode('st', (int) $student['id']),
            'fullName'   => $student['full_name'],
            'matricNo'   => $student['matric_no'],
            'department' => $student['department'],
            'level'      => (int) $student['level'],
        ];
    }
}

final class PasswordReset
{
    private const TOKEN_HOURS = 1;

    /** At least 8 characters (72 max, the bcrypt limit), one letter and one number. */
    public static function assertPasswordPolicy(string $password): void
    {
        $problem = null;
        if (strlen($password) < 8) {
            $problem = 'Use at least 8 characters.';
        } elseif (strlen($password) > 72) {
            $problem = 'Use 72 characters or fewer.';
        } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $problem = 'Use at least one letter and one number.';
        }
        if ($problem !== null) {
            throw new ApiException('validation_failed', 'Check the highlighted fields and try again.', 422, ['newPassword' => $problem]);
        }
    }

    /**
     * Looks the student up by matric number or email and writes a reset link to the log file.
     * Returns nothing and behaves the same for unknown or suspended accounts, so callers
     * cannot tell whether an account exists.
     */
    public static function request(string $emailOrMatric): void
    {
        $identity = trim($emailOrMatric);
        if ($identity === '') {
            return;
        }
        $student = Database::fetchOne(
            'SELECT id, matric_no, status FROM students WHERE matric_no = ? OR email = ? LIMIT 1',
            [strtoupper($identity), $identity]
        );
        if ($student === null || $student['status'] === 'suspended') {
            return;
        }
        self::writeLink((string) $student['matric_no'], self::issue((int) $student['id']));
    }

    /**
     * Creates a one-hour token for the student (earlier unused tokens stop working) and
     * returns the raw token. Only its SHA-256 hash is stored.
     */
    public static function issue(int $studentId): string
    {
        $token = bin2hex(random_bytes(32));
        $expires = gmdate('Y-m-d H:i:s', time() + self::TOKEN_HOURS * 3600);
        Database::transaction(function () use ($studentId, $token, $expires): void {
            Database::execute(
                'UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP() WHERE student_id = ? AND used_at IS NULL',
                [$studentId]
            );
            Database::execute(
                'INSERT INTO password_reset_tokens (student_id, token_hash, expires_at, ip_address) VALUES (?, ?, ?, ?)',
                [$studentId, hash('sha256', $token), $expires, Request::ip()]
            );
        });
        return $token;
    }

    /**
     * Sets a new password with a valid, unused token. The password policy is checked first,
     * so a weak password does not burn the token. The token is claimed with one conditional
     * UPDATE, so it can only ever be used once.
     */
    public static function consume(string $token, string $newPassword): void
    {
        self::assertPasswordPolicy($newPassword);
        $invalid = new ApiException(
            'validation_failed',
            'This reset link is invalid or has expired. Request a new one.',
            422,
            ['token' => 'Request a new reset link.']
        );
        $token = strtolower(trim($token));
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            throw $invalid;
        }
        $hash = hash('sha256', $token);

        Database::transaction(function () use ($hash, $newPassword, $invalid): void {
            $claimed = Database::execute(
                'UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP()
                  WHERE token_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()',
                [$hash]
            );
            if ($claimed !== 1) {
                throw $invalid;
            }
            $row = Database::fetchOne(
                'SELECT t.student_id, s.matric_no FROM password_reset_tokens t
                   JOIN students s ON s.id = t.student_id WHERE t.token_hash = ?',
                [$hash]
            );
            Database::execute(
                'UPDATE students SET password_hash = ? WHERE id = ?',
                [password_hash($newPassword, PASSWORD_DEFAULT), (int) $row['student_id']]
            );
            Database::execute(
                'UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP() WHERE student_id = ? AND used_at IS NULL',
                [(int) $row['student_id']]
            );
            // A student locked out by failed logins can sign in straight away with the new password.
            Database::execute(
                "DELETE FROM login_attempts WHERE kind = 'login' AND identifier = ?",
                [strtoupper((string) $row['matric_no'])]
            );
        });
    }

    /** Dev stand-in for email: one line per link in the reset log. */
    private static function writeLink(string $matricNo, string $token): void
    {
        $path = (string) Config::get('RESET_LOG_PATH', 'storage/logs/reset-links.log');
        if (!preg_match('#^([A-Za-z]:)?[\\\\/]#', $path)) {
            $path = Config::root() . '/' . $path;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf(
            "[%s UTC] %s  %s/public/#/reset-password?token=%s  (valid %d hour)\n",
            gmdate('Y-m-d H:i:s'), $matricNo, self::baseUrl(), $token, self::TOKEN_HOURS
        );
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    /** Project URL without a trailing slash, e.g. http://localhost/ui-result-transcript-system. */
    private static function baseUrl(): string
    {
        $configured = Config::get('APP_URL', null);
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }
        $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        if (preg_match('/^[A-Za-z0-9.\-:]+$/', $host) !== 1) {
            $host = 'localhost';
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $prefix = preg_match('#^(.*?)/api/#', $uri, $m) === 1 ? $m[1] : '';
        return ($https ? 'https' : 'http') . '://' . $host . $prefix;
    }
}