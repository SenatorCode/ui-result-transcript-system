<?php
// Owner: Dev B. Methods assumed; align with docs/API_CONTRACT.md.
$todo = function () { throw new RuntimeException('Not implemented'); };

return [
        'GET auth/csrf'                    => static function (): void {
        Response::success(['csrfToken' => Csrf::token()]);
    },
    'POST auth/login'                  => $todo,
    'GET auth/me'                      => $todo,
    'POST auth/logout'                 => $todo,
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