<?php
// Owner: Dev A. Course, Result, GpaCalculator, StudentProfile.
//
// Rules used here (confirm with the department; they live in one place each):
//  - Grade points come from the grades table (A=5 ... F=0), through the v_result_details view.
//  - GPA = sum(units x grade point) / sum(units), rounded half-up to 2 decimals.
//    The sums use whole tenths of a grade point, so rounding never depends on floats.
//  - A course taken again is a new attempt. attempt > 1 means carryOver = true.
//  - unitsEarned is the course units when the grade is not F, otherwise 0.
//  - CGPA counts only the LATEST attempt of each course. "As of" a semester it looks only at
//    results up to and including that semester, so a later retake never rewrites the past.
//    (v_counted_results is not used for that: it looks at retakes from the future.)

declare(strict_types=1);

final class Course
{
    /** @return array{id:int,code:string,title:string,units:int,department:string}|null */
    public static function find(int $courseId): ?array
    {
        $row = Database::fetchOne(
            'SELECT c.id, c.code, c.title, c.unit, d.name AS department
               FROM courses c JOIN departments d ON d.id = c.department_id
              WHERE c.id = ?',
            [$courseId]
        );
        if ($row === null) {
            return null;
        }
        return [
            'id'         => (int) $row['id'],
            'code'       => $row['code'],
            'title'      => $row['title'],
            'units'      => (int) $row['unit'],
            'department' => $row['department'],
        ];
    }
}

final class Result
{
    /**
     * Session and semester pairs this student has results for, newest first.
     * v1 treats every stored result as released (see availability()).
     */
    public static function optionsFor(int $studentId): array
    {
        $rows = Database::fetchAll(
            'SELECT DISTINCT session, semester, sort_order
               FROM v_result_details
              WHERE student_id = ?
              ORDER BY session DESC, sort_order DESC',
            [$studentId]
        );
        return array_map(
            static fn (array $r): array => ['session' => $r['session'], 'semester' => $r['semester'], 'released' => true],
            $rows
        );
    }

    /**
     * Every result of one student, oldest first, with the attempt number of each course.
     * This is the single query the GPA and transcript code build on.
     *
     * @return list<array{session:string,semester:string,sortOrder:int,code:string,title:string,units:int,grade:string,gp10:int,attempt:int}>
     */
    public static function history(int $studentId): array
    {
        $rows = Database::fetchAll(
            'SELECT session, semester, sort_order, code, title, unit, grade, grade_point
               FROM v_result_details
              WHERE student_id = ?
              ORDER BY session ASC, sort_order ASC, code ASC',
            [$studentId]
        );
        $seen = [];
        $history = [];
        foreach ($rows as $r) {
            $seen[$r['code']] = ($seen[$r['code']] ?? 0) + 1;
            $history[] = [
                'session'   => $r['session'],
                'semester'  => $r['semester'],
                'sortOrder' => (int) $r['sort_order'],
                'code'      => $r['code'],
                'title'     => $r['title'],
                'units'     => (int) $r['unit'],
                'grade'     => $r['grade'],
                'gp10'      => (int) round((float) $r['grade_point'] * 10), // tenths of a grade point
                'attempt'   => $seen[$r['code']],
            ];
        }
        return $history;
    }

    /** The courses of one semester in the shape the API sends (see courseView()). */
    public static function forSemester(int $studentId, string $session, string $semester): array
    {
        $rows = self::pick(self::history($studentId), $session, $semester);
        return array_map([self::class, 'courseView'], $rows);
    }

    /**
     * 'released' when the student has results for the pair, otherwise 'empty'.
     * 'pending' and 'withheld' exist in the contract; adding them later only needs this
     * method to change (they need a table to store the release state).
     */
    public static function availability(int $studentId, string $session, string $semester): string
    {
        return self::pick(self::history($studentId), $session, $semester) === [] ? 'empty' : 'released';
    }

    /**
     * The full body of GET results/index: student, session, semester, courses and summary.
     * Throws 404 when the student has no results for the pair.
     */
    public static function report(int $studentId, string $session, string $semester): array
    {
        $history = self::history($studentId);
        $rows = self::pick($history, $session, $semester);
        if ($rows === []) {
            throw new ApiException('not_found', 'That session and semester are not available.', 404);
        }
        $student = Database::fetchOne('SELECT matric_no FROM students WHERE id = ?', [$studentId]);

        return [
            'student'      => $student['matric_no'] ?? null,
            'session'      => $rows[0]['session'],
            'semester'     => $rows[0]['semester'],
            'availability' => 'released',
            'courses'      => array_map([self::class, 'courseView'], $rows),
            'summary'      => GpaCalculator::summary($history, $rows[0]['session'], $rows[0]['semester']),
        ];
    }

    /** One history row as the API shows it. */
    public static function courseView(array $h): array
    {
        return [
            'code'        => $h['code'],
            'title'       => $h['title'],
            'units'       => $h['units'],
            'unitsEarned' => $h['gp10'] > 0 ? $h['units'] : 0,
            'grade'       => $h['grade'],
            'gradePoint'  => $h['gp10'] / 10,
            'attempt'     => $h['attempt'],
            'carryOver'   => $h['attempt'] > 1,
        ];
    }

    /** The history rows of one session and semester. */
    private static function pick(array $history, string $session, string $semester): array
    {
        return array_values(array_filter(
            $history,
            static fn (array $h): bool => $h['session'] === $session && $h['semester'] === $semester
        ));
    }
}

final class GpaCalculator
{
    /** GPA of one semester, 0.0 when there are no results. */
    public static function semesterGpa(int $studentId, string $session, string $semester): float
    {
        $rows = array_filter(
            Result::history($studentId),
            static fn (array $h): bool => $h['session'] === $session && $h['semester'] === $semester
        );
        return self::gpaOf($rows);
    }

    /** CGPA using only results up to and including the given semester. */
    public static function cgpaAsOf(int $studentId, string $session, string $semester): float
    {
        $history = Result::history($studentId);
        foreach ($history as $h) {
            if ($h['session'] === $session && $h['semester'] === $semester) {
                return self::cgpaUpTo($history, $session, $h['sortOrder']);
            }
        }
        return 0.0;
    }

    /** CGPA over everything the student has, latest attempt of each course. The issue snapshot. */
    public static function overallCgpa(int $studentId): float
    {
        return self::gpaOf(self::latestAttempts(Result::history($studentId)));
    }

    /**
     * Units of every result row that appears on the transcript, retaken attempts included
     * (the failed attempt stays on the record). This is the total_units issue snapshot,
     * matching the seed data: 55 for CSC/2023/001 while the CGPA is over 52 counted units.
     */
    public static function totalUnitsCounted(int $studentId): int
    {
        return array_sum(array_column(Result::history($studentId), 'units'));
    }

    public static function degreeClass(float $cgpa): string
    {
        $hundredths = (int) round($cgpa * 100);
        foreach ((array) Config::get('DEGREE_CLASS_BANDS') as [$minimum, $label]) {
            if ($hundredths >= (int) round($minimum * 100)) {
                return (string) $label;
            }
        }
        return (string) Config::get('DEGREE_CLASS_BELOW_PASS');
    }

    /**
     * GPA of any set of history rows. Whole tenths of grade points and a half-up integer
     * division keep 3.975 as 3.98 on every machine.
     */
    public static function gpaOf(iterable $rows): float
    {
        $units = 0;
        $tenths = 0;
        foreach ($rows as $r) {
            $units += $r['units'];
            $tenths += $r['units'] * $r['gp10'];
        }
        if ($units === 0) {
            return 0.0;
        }
        return intdiv($tenths * 20 + $units, 2 * $units) / 100;
    }

    /** The latest attempt of each course in these rows (rows must be oldest first). */
    public static function latestAttempts(array $rows): array
    {
        $latest = [];
        foreach ($rows as $r) {
            $latest[$r['code']] = $r;
        }
        return array_values($latest);
    }

    /** The summary block of GET results/index, from a student's whole history. */
    public static function summary(array $history, string $session, string $semester): array
    {
        $rows = [];
        $sortOrder = 0;
        foreach ($history as $h) {
            if ($h['session'] === $session && $h['semester'] === $semester) {
                $rows[] = $h;
                $sortOrder = $h['sortOrder'];
            }
        }
        $cgpa = self::cgpaUpTo($history, $session, $sortOrder);

        return [
            'gpa'             => self::gpaOf($rows),
            'cgpa'            => $cgpa,
            'unitsRegistered' => array_sum(array_column($rows, 'units')),
            'unitsEarned'     => array_sum(array_map(static fn (array $r): int => $r['gp10'] > 0 ? $r['units'] : 0, $rows)),
            'degreeClass'     => self::degreeClass($cgpa),
        ];
    }

    /** CGPA over the history up to and including (session, sortOrder). */
    public static function cgpaUpTo(array $history, string $session, int $sortOrder): float
    {
        $window = array_filter($history, static function (array $h) use ($session, $sortOrder): bool {
            $cmp = strcmp($h['session'], $session);
            return $cmp < 0 || ($cmp === 0 && $h['sortOrder'] <= $sortOrder);
        });
        return self::gpaOf(self::latestAttempts(array_values($window)));
    }
}

final class StudentProfile
{
    /** The student's own details in the API shape, or null when the id does not exist. */
    public static function find(int $studentId): ?array
    {
        $row = Database::fetchOne(
            'SELECT s.id, s.matric_no, s.full_name, s.email, s.phone, s.date_of_birth, s.level, s.status,
                    d.name AS department, d.faculty, es.name AS entry_session
               FROM students s
               JOIN departments d ON d.id = s.department_id
               LEFT JOIN academic_sessions es ON es.id = s.entry_session_id
              WHERE s.id = ?',
            [$studentId]
        );
        if ($row === null) {
            return null;
        }
        return [
            'id'           => IdCodec::encode('st', (int) $row['id']),
            'fullName'     => $row['full_name'],
            'matricNo'     => $row['matric_no'],
            'email'        => $row['email'],
            'phone'        => $row['phone'],
            'dateOfBirth'  => $row['date_of_birth'],
            'faculty'      => $row['faculty'],
            'department'   => $row['department'],
            'level'        => (int) $row['level'],
            'entrySession' => $row['entry_session'],
            'status'       => $row['status'],
        ];
    }
}
