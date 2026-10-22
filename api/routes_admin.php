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