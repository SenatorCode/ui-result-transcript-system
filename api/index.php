<?php
// Owner: Dev A. Front controller: api/.htaccess sends every api/**.php URL here.
require_once __DIR__ . '/bootstrap.php';

Router::dispatch();
