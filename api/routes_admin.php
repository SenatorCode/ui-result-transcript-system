<?php
// Owner: Dev C. See docs/admin-api-contract.md.
declare(strict_types=1);

$todo = function () { throw new RuntimeException('Not implemented'); };

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
        if ($username === '') $fields['username'] = 'Enter your username.';
        if ($password === '') $fields['password'] = 'Enter your password.';
        if ($fields) {
            throw new ApiException('validation_failed', 'Check the highlighted fields and try again.', 422, $fields);
        }

        $identifier = strtolower($username) . '|' . Request::ip();
        RateLimiter::assertAllowed('admin_login', $identifier);

        try {
            $result = Admin::authenticate($username, $password);
        } catch (ApiException $e) {
            if ($e->httpStatus() === 401) {
                RateLimiter::recordFailure('admin_login', $identifier);
            }
            throw $e;
        }

        RateLimiter::clear('admin_login', $identifier);
        Session::regenerate();
        $_SESSION['admin_id'] = $result['adminId'];
        unset($result['adminId']); // internal id stays out of the response

        Response::success(['admin' => $result, 'csrfToken' => Csrf::token()]);
    },

    'GET admin/auth/me' => function () {
        $adminId = Auth::requireAdmin();
        $profile = Admin::profile((int) $adminId);
        if ($profile === null) {
            throw new ApiException('session_expired', 'Please sign in again.', 401);
        }
        Response::success(['admin' => $profile, 'csrfToken' => Csrf::token()]);
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

    // ---------- Transcript requests ----------
    'GET admin/transcripts/index' => function () {
        Auth::requireAdmin();
        Response::success(AdminReport::transcriptList([
            'status'          => (string) (Request::query('status') ?? ''),
            'destinationType' => (string) (Request::query('destinationType') ?? ''),
            'q'               => (string) (Request::query('q') ?? ''),
            'sort'            => (string) (Request::query('sort') ?: 'newest'),
            'page'            => (int) (Request::query('page') ?: 1),
            'pageSize'        => (int) (Request::query('pageSize') ?: 20),
        ]));
    },

    'GET admin/transcripts/show' => function () {
        Auth::requireAdmin();
        Response::success(AdminReport::transcriptDetail(Request::transcriptId()));
    },

    'POST admin/transcripts/update-status' => function () {
        $adminId = Auth::requireAdmin();
        Csrf::verify();
        $id = Request::transcriptId();
        $body = Request::json();

        $action = is_string($body['action'] ?? null) ? $body['action'] : '';
        $reason = isset($body['reason']) && is_string($body['reason']) ? $body['reason'] : null;

        // TranscriptIssuer::apply() does the validation and the transaction.
        Response::success(TranscriptIssuer::apply($id, (int) $adminId, $action, $reason));
    },

    'GET admin/transcripts/document' => function () {
        Auth::requireAdmin();
        $id = Request::transcriptId();

        $requestNo = AdminReport::requireDocumentable($id);
        // Same renderer as the student download; does not touch download_count.
        $pdf = TranscriptPdf::render(TranscriptData::build($id));

        Response::pdf($pdf, 'transcript-' . $requestNo . '.pdf');
    },

    // ---------- Payments ----------
    'GET admin/payments/index' => function () {
        Auth::requireAdmin();
        Response::success(AdminReport::paymentList([
            'status'   => (string) (Request::query('status') ?? ''),
            'gateway'  => (string) (Request::query('gateway') ?? ''),
            'from'     => (string) (Request::query('from') ?? ''),
            'to'       => (string) (Request::query('to') ?? ''),
            'page'     => (int) (Request::query('page') ?: 1),
            'pageSize' => (int) (Request::query('pageSize') ?: 20),
        ]));
    },

    'POST admin/payments/reverify' => $todo, // optional: the page does not show the button
];