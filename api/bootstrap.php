<?php
// Owner: Dev A. Loads config, class files, headers, session, error handler.
$root = dirname(__DIR__);
foreach (['Core', 'Security', 'Academics', 'Student', 'Transcript', 'Payment', 'Documents', 'Admin'] as $f) {
    require_once $root . "/classes/$f.php";
}