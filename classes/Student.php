<?php
// Owner: Dev B.

final class Student
{
    /** Proposed. */
    public static function findByMatricNo(string $matricNo): ?array { throw new RuntimeException('Not implemented'); }
    /** Proposed. */
    public static function verifyPassword(array $student, string $password): bool { throw new RuntimeException('Not implemented'); }
    /** Proposed. Public shape for login and me. */
    public static function publicShape(array $student): array { throw new RuntimeException('Not implemented'); }
}

final class PasswordReset
{
    /** Proposed. Same response for every input. */
    public static function request(string $emailOrMatric): void { throw new RuntimeException('Not implemented'); }
    /** Proposed. Single-use token. */
    public static function consume(string $token, string $newPassword): void { throw new RuntimeException('Not implemented'); }
}