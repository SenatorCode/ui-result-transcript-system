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
        'GET transcripts/quote' => static function (): void {
        $studentId = Auth::requireStudent();
        $query = [];
        foreach (['destinationType', 'deliveryMethod', 'copies'] as $key) {
            $query[$key] = Request::query($key);
        }
        Response::success(FeeCalculator::quote($studentId, $query));
    },

    'POST transcripts/create' => static function (): void {
        Csrf::verify();
        $studentId = Auth::requireStudent();
        $body = Request::json();
        $result = Idempotency::run(
            'transcripts/create',
            Request::header('Idempotency-Key'),
            $body,
            static fn (): array => ['transcript' => Transcript::create($studentId, $body)]
        );
        Response::success($result, 201);
    },

    'GET transcripts/index' => static function (): void {
        $studentId = Auth::requireStudent();
        Response::success(['items' => Transcript::listForStudent($studentId)]);
    },

    'POST transcripts/cancel' => static function (): void {
        Csrf::verify();
        $studentId = Auth::requireStudent();
        Request::transcriptId(); // 422 when missing, 404 when malformed
        Response::success(Transcript::cancel((string) Request::query('id'), $studentId));
    },
        'POST transcripts/pay' => static function (): void {
        Csrf::verify();
        $studentId = Auth::requireStudent();
        $requestId = Request::transcriptId();                 // 422 missing, 404 malformed
        $publicId = (string) Request::query('id');
        Transcript::findOwned($publicId, $studentId);         // 404 for a foreign id
        $result = Idempotency::run(
            'transcripts/pay:' . $publicId,
            Request::header('Idempotency-Key'),
            Request::json(),
            static fn (): array => Payment::initiate($requestId, $studentId)
        );
        Response::success($result);
    },

    'POST payments/verify' => static function (): void {
        Csrf::verify();
        $studentId = Auth::requireStudent();
        Request::transcriptId();
        $request = Transcript::findOwned((string) Request::query('id'), $studentId);
        $body = Request::json();
        Request::requireFields($body, ['reference' => 'Enter the payment reference.']);
        $payment = is_string($body['reference']) ? Payment::findByReference(trim($body['reference'])) : null;
        if ($payment === null || (int) $payment['request_id'] !== (int) $request['id']) {
            throw new ApiException('validation_failed', 'The payment reference does not match this request.', 422,
                ['reference' => 'Check the payment reference and try again.']);
        }
        Response::success(Payment::settle($payment['reference']));
    },
        
        'GET payments/receipt' => static function (): void {
        $studentId = Auth::requireStudent();
        Request::transcriptId();                                   // 422 missing, 404 malformed
        $request = Transcript::findOwned((string) Request::query('id'), $studentId); // 404 foreign
        $receipt = Payment::receiptData((int) $request['id'], $studentId);
        if ($receipt === null) {
            throw new ApiException('not_found', 'A verified receipt is not available.', 404);
        }
        if (Request::acceptsPdf()) {
            Response::pdf(ReceiptPdf::render($receipt), 'receipt-' . $receipt['receiptNumber'] . '.pdf');
        }
        Response::success([
            'receiptNumber' => $receipt['receiptNumber'],
            'amount'        => $receipt['amount'],
            'paidAt'        => $receipt['paidAt'],
            'reference'     => $receipt['reference'],
        ]);
    },
];