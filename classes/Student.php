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
    /** Proposed. Same response for every input. */
    public static function request(string $emailOrMatric): void { throw new RuntimeException('Not implemented'); }
    /** Proposed. Single-use token. */
    public static function consume(string $token, string $newPassword): void { throw new RuntimeException('Not implemented'); }
}