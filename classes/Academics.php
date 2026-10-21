<?php
// Owner: Dev A.

final class Course
{
    /** Proposed. */
    public static function find(int $courseId): ?array { throw new RuntimeException('Not implemented'); }
}

final class Result
{
    /** Proposed. Session and semester pairs that have results for this student. */
    public static function optionsFor(int $studentId): array { throw new RuntimeException('Not implemented'); }
    /** Proposed. Results for one semester with attempt numbers and carry-over. */
    public static function forSemester(int $studentId, string $session, string $semester): array { throw new RuntimeException('Not implemented'); }
    /** Proposed. */
    public static function availability(int $studentId, string $session, string $semester): string { throw new RuntimeException('Not implemented'); }
}

final class GpaCalculator
{
    public static function semesterGpa(int $studentId, string $session, string $semester): float { throw new RuntimeException('Not implemented'); }
    /** Proposed. CGPA as of the selected semester. */
    public static function cgpaAsOf(int $studentId, string $session, string $semester): float { throw new RuntimeException('Not implemented'); }
    public static function overallCgpa(int $studentId): float { throw new RuntimeException('Not implemented'); }
    public static function totalUnitsCounted(int $studentId): int { throw new RuntimeException('Not implemented'); }
    /** Proposed. */
    public static function degreeClass(float $cgpa): string { throw new RuntimeException('Not implemented'); }
}

final class StudentProfile
{
    /** Proposed. Joins department and entry session. */
    public static function find(int $studentId): ?array { throw new RuntimeException('Not implemented'); }
}