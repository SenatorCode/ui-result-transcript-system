<?php
// Owner: Dev B. Methods assumed; align with docs/API_CONTRACT.md.
$todo = function () { throw new RuntimeException('Not implemented'); };

return [
        'GET auth/csrf'                    => static function (): void {
        Response::success(['csrfToken' => Csrf::token()]);
    },
        'POST auth/login' => static function (): void {
        Csrf::verify();
        $body = Request::json();
        Request::requireFields($body, [
            'matricNo' => 'Enter your matric number.',
            'password' => 'Enter your password.',
        ]);
        if (!is_string($body['matricNo']) || !is_string($body['password'])) {
            throw new ApiException('validation_failed', 'Check the highlighted fields and try again.', 422);
        }
        $matric = strtoupper(trim($body['matricNo']));

        RateLimiter::assertAllowed('login', $matric);
        $student = Student::findByMatricNo($matric);
        if (!Student::verifyPassword($student, $body['password'])) {
            RateLimiter::recordFailure('login', $matric);
            throw new ApiException('invalid_credentials', 'Matric number or password is incorrect.', 401);
        }
        // Only someone who knows the password learns the account is suspended.
        if ($student['status'] === 'suspended') {
            throw new ApiException('account_suspended', 'This account is suspended. Contact Registry.', 403);
        }

        RateLimiter::clear('login', $matric);
        Auth::loginStudent((int) $student['id']);
        Database::execute('UPDATE students SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [(int) $student['id']]);
        Response::success(['student' => Student::publicShape($student), 'csrfToken' => Csrf::token()]);
    },

    'GET auth/me' => static function (): void {
        $studentId = Auth::requireStudent();
        $student = Student::findById($studentId);
        if ($student === null) {
            throw new ApiException('session_expired', 'Your session has expired. Sign in to continue.', 401);
        }
        Response::success(['student' => Student::publicShape($student), 'csrfToken' => Csrf::token()]);
    },

    'POST auth/logout' => static function (): void {
        Auth::requireStudent();
        Csrf::verify();
        Session::destroy();
        Response::success(['loggedOut' => true]);
    },
    'POST auth/password-reset-request' => $todo,
    'POST auth/password-reset'         => $todo,
    'GET transcripts/quote'           => $todo,
    'POST transcripts/create'          => $todo,
    'GET transcripts/index'            => $todo,
    'POST transcripts/cancel'          => $todo,
    'POST transcripts/pay'             => $todo,
    'POST payments/verify'             => $todo,
    'GET payments/receipt'             => $todo,
];