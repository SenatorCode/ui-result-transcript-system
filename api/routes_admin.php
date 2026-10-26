<?php
// Owner: Dev C. See docs/ADMIN_API_CONTRACT.md.
$todo = function () { throw new RuntimeException('Not implemented'); };

return [
    'GET admin/auth/csrf'                       => $todo,
    'POST admin/auth/login'                     => $todo,
    'GET admin/auth/me'                         => $todo,
    'POST admin/auth/logout'                    => $todo,
    'GET admin/dashboard'                       => $todo,
    'GET admin/transcripts/index'               => $todo,
    'GET admin/transcripts/show'                => $todo,
    'POST admin/transcripts/update-status'      => $todo,
    'GET admin/transcripts/document'            => $todo,
    'GET admin/payments/index'                  => $todo,
    'POST admin/payments/reverify'              => $todo, // optional
]; 



// Owner: Dev C. Aligned against classes/Admin.php and classes/Core.php parameters.
declare(strict_types=1);

return [
    // ---------- Auth ----------
    'GET admin/auth/csrf' => function () {
        Response::success(['csrfToken' => Csrf::token()]);
    },

    'POST admin/auth/login' => function () {
        Csrf::verify();
        $body = Request::json();

        $username = is_string($body['username'] ?? null) ? trim($body['username']) : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';

        $fields = [];
        if ($username === '') {
            $fields['username'] = 'Enter your Username.';
        }
        if ($password === '') {
            $fields['password'] = 'Enter your Password.';
        }
        if ($fields !== []) {
            throw new ApiException('validation_failed', 'Check the highlighted fields and try again.', 422, $fields);
        }

        $ip = Request::ip();
        $rateLimitIdentifier = $username . '|' . $ip;

        RateLimiter::assertAllowed('admin_login', $rateLimitIdentifier);
        try {
            $result = Admin::authenticate($username, $password);
        } catch (ApiException $e) {
            if ($e->httpStatus() === 401) {
                RateLimiter::recordFailure('admin_login', $rateLimitIdentifier);
            }
            throw $e;
        }
        RateLimiter::clear('admin_login', $rateLimitIdentifier);

        Session::regenerate();
        $_SESSION['admin_id'] = $result['id'];
        Response::success(['admin' => $result, 'csrfToken' => Csrf::token()]);
    },

    'GET admin/auth/me' => function () {
        $adminId = Auth::requireAdmin();
        $row = Admin::find((int) $adminId);
        if ($row === null) {
            throw new ApiException('not_found', 'We could not find that record.', 404);
        }
        Response::success([
            'admin' => [
                'id'       => IdCodec::encode('ad', (int) $row['id']),
                'fullName' => $row['full_name'],
                'username' => $row['username'],
            ],
            'csrfToken' => Csrf::token(),
        ]);
    },

    'POST admin/auth/logout' => function () {
        Auth::requireAdmin();
        Csrf::verify();
        Session::destroy();
        Response::success(['loggedOut' => true]);
    },

    // ---------- Dashboard ----------
    'GET admin/dashboard' => function () {
        Auth::requireAdmin();
        Response::success(AdminReport::dashboard());
    },

    // ---------- Transcript Requests ----------
    'GET admin/transcripts/index' => function () {
        Auth::requireAdmin();
        $filters = [
            'status'          => (string) (Request::query('status') ?? ''),
            'destinationType' => (string) (Request::query('destinationType') ?? ''),
            'q'               => (string) (Request::query('q') ?? ''),
            'sort'            => (string) (Request::query('sort') ?: 'newest'),
            'page'            => (int) (Request::query('page') ?: 1),
            'pageSize'        => (int) (Request::query('pageSize') ?: 20),
        ];
        Response::success(AdminReport::transcriptList($filters));
    },

    'GET admin/transcripts/show' => function () {
        Auth::requireAdmin();
        Response::success(AdminReport::transcriptDetail(Request::transcriptId()));
    },

    'POST admin/transcripts/update-status' => function () {
        $adminId = Auth::requireAdmin();
        Csrf::verify();
        $id   = Request::transcriptId();
        $body = Request::json();

        $action = (string) ($body['action'] ?? '');
        $reason = isset($body['reason']) ? trim((string) $body['reason']) : null;

        $allowed = ['start_processing', 'mark_ready', 'mark_sent', 'reject'];
        $fields = [];
        if (!in_array($action, $allowed, true)) {
            $fields['action'] = 'Choose a valid action.';
        }
        if ($action === 'reject' && ($reason === null || $reason === '')) {
            $fields['reason'] = 'Enter a reason for rejecting this request.';
        }
        if ($fields !== []) {
            throw new ApiException('validation_failed', 'Check the highlighted fields and try again.', 422, $fields);
        }

        Response::success(TranscriptIssuer::apply($id, (int) $adminId, $action, $reason));
    },

    'GET admin/transcripts/document' => function () {
        Auth::requireAdmin();
        $id = Request::transcriptId();
        $data = TranscriptData::build($id);
        $pdfBytes = TranscriptPdf::render($data);
        Response::pdf($pdfBytes, 'transcript_' . $id . '.pdf');
    },

    // ---------- Payments ----------
    'GET admin/payments/index' => function () {
        Auth::requireAdmin();
        $filters = [
            'status'   => (string) (Request::query('status') ?? ''),
            'gateway'  => (string) (Request::query('gateway') ?? ''),
            'from'     => (string) (Request::query('from') ?? ''),
            'to'       => (string) (Request::query('to') ?? ''),
            'page'     => (int) (Request::query('page') ?: 1),
            'pageSize' => (int) (Request::query('pageSize') ?: 20),
        ];
        Response::success(AdminReport::paymentList($filters));
    },

    'POST admin/payments/reverify' => function () {
        Auth::requireAdmin();
        Csrf::verify();
        throw new ApiException('not_implemented', 'Payment re-verification is not available yet.', 501);
    },
];
