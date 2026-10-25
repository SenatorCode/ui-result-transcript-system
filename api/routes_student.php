<?php
// Owner: Dev A. Student routes for results, profile and the transcript download.
// Contract: docs/api-contract.md. Routes stay short; the rules live in the classes.

// TEMPORARY (remove in phase R8): lets you test these routes before Dev B's login is merged.
// It only works when your own config.php sets APP_ENV to 'local' AND DEV_STUDENT_ID to a
// student's database id. The committed defaults (production, null) never take this branch.
$currentStudent = static function (): int {
    $devId = Config::get('DEV_STUDENT_ID');
    if ($devId !== null && Config::get('APP_ENV') === 'local') {
        return (int) $devId;
    }
    return Auth::requireStudent();
};

return [
    // Session and semester pairs this student has results for.
    'GET results/options' => static function () use ($currentStudent): void {
        $studentId = $currentStudent();
        Response::success(['pairs' => Result::optionsFor($studentId)]);
    },

    // One semester: courses, GPA, CGPA as of that semester, units, degree class.
    'GET results/index' => static function () use ($currentStudent): void {
        $studentId = $currentStudent();
        $query = ['session' => Request::query('session'), 'semester' => Request::query('semester')];
        Request::requireFields($query, [
            'session'  => 'Choose an academic session.',
            'semester' => 'Choose a semester.',
        ]);
        Response::success(Result::report($studentId, $query['session'], $query['semester']));
    },

    // The student's own profile (auth/me only carries the five fields the shell needs).
    'GET student/profile' => static function () use ($currentStudent): void {
        $studentId = $currentStudent();
        $profile = StudentProfile::find($studentId);
        if ($profile === null) {
            throw new ApiException('not_found', 'We could not find that record.', 404);
        }
        Response::success(['student' => $profile]);
    },

    // Streams the transcript PDF. Order of checks: owner (404), status (409), policy (403).
    'GET transcripts/download' => static function () use ($currentStudent): void {
        $studentId = $currentStudent();
        $requestId = Request::transcriptId();                       // 404 for a malformed id
        $owned = Transcript::findOwned((string) Request::query('id'), $studentId); // 404 for a foreign id

        $request = Database::fetchOne('SELECT status, request_no FROM transcript_requests WHERE id = ?', [$requestId]);
        if ($request === null || !in_array($request['status'], ['ready', 'sent'], true)) {
            throw new ApiException('invalid_transition', 'This transcript is not available to download yet.', 409);
        }
        if (!Transcript::canDownload($owned)) {
            throw new ApiException('forbidden', 'This transcript cannot be downloaded. Registry sends institution copies directly.', 403);
        }

        $pdf = TranscriptPdf::render(TranscriptData::build($requestId));
        Database::execute(
            'UPDATE transcript_requests SET download_count = download_count + 1, last_downloaded_at = UTC_TIMESTAMP() WHERE id = ?',
            [$requestId]
        );
        Response::pdf($pdf, 'transcript-' . $request['request_no'] . '.pdf');
    },
];
