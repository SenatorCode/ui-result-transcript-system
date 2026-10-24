<?php
// Owner: Dev A. Every setting the backend reads is listed here, with its default.
//
// How it works: Config::get() loads THIS file first, then overlays your local
// config.php on top. So you only put the values you want to change in config.php.
// Copy this file to config.php for your own machine. NEVER commit config.php.
//
// Adding a setting? Add one line here (with a comment) in the same PR as the code
// that reads it. Do not edit the other lines.
return [
    // ---- Database (XAMPP defaults) -------------------------------------------
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_name' => 'transcript_system',
    'db_user' => 'root',
    'db_pass' => '',

    // ---- App -------------------------------------------------------------------
    'UNIVERSITY_NAME' => 'University of Ibadan',
    'CURRENT_SESSION' => '2025/2026',   // must exist in academic_sessions
    'CURRENCY'        => 'NGN',

    // 'production' or 'local'. Only 'local' honours DEV_STUDENT_ID below.
    'APP_ENV'         => 'production',
    // TEMPORARY, for testing logged-in routes before Dev B's login is merged.
    // Put the DB id of a seed student here (e.g. 1) AND set APP_ENV to 'local' in your
    // own config.php. Leave null here. It is removed in phase R8.
    'DEV_STUDENT_ID'  => null,

    // ---- Fees (fee = TRANSCRIPT_FEE x copies + COURIER_SURCHARGE for courier) -----
    // Whole naira. Group decision pending: the seed uses 5000 per copy.
    'TRANSCRIPT_FEE'    => 5000,
    'COURIER_SURCHARGE' => 2000,

    // ---- Payments --------------------------------------------------------------
    'REQUEST_PAYMENT_HOURS' => 24,          // payment deadline for an unpaid request
    'PAYMENT_MODE'          => 'simulated', // 'simulated' or 'paystack'
    'PAYSTACK_SECRET_KEY'   => '',          // test key only, and only in config.php
    'PAYSTACK_PUBLIC_KEY'   => '',
    'PAYSTACK_CALLBACK_URL' => '',

    // ---- Password reset (no mail server on XAMPP: links go to a log file) ---------
    'RESET_LOG_PATH' => 'storage/logs/reset-links.log', // relative to the project root

    // ---- Results ----------------------------------------------------------------
    // Degree class by CGPA on the 5-point scale. [minimum CGPA, label], highest first.
    // Standard Nigerian bands; confirm with the department.
    'DEGREE_CLASS_BANDS' => [
        [4.50, 'First Class Honours'],
        [3.50, 'Second Class Honours (Upper Division)'],
        [2.40, 'Second Class Honours (Lower Division)'],
        [1.50, 'Third Class Honours'],
        [1.00, 'Pass'],
    ],
    'DEGREE_CLASS_BELOW_PASS' => 'Not classified',
];
