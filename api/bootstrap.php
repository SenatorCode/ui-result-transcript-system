<?php
// Owner: Dev A. Loads the classes, sets safe defaults, and turns every error into the JSON
// error envelope. Included by api/index.php and by tests/run_all.php.

declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';
$root = dirname(__DIR__);

if (!$isCli) {
    ob_start(); // anything echoed by accident is discarded before a response is sent
}

// Core first: the handlers below need Response and ApiException.
require_once $root . '/classes/Core.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', $root . '/storage/logs/php-errors.log');
ini_set('serialize_precision', '-1'); // 3.98 stays 3.98 in JSON
date_default_timezone_set('UTC');

/** Writes to storage/logs/error.log (not tracked by git). Keeps personal data out of the log. */
function api_log_error(Throwable $e): void
{
    $message = $e->getMessage();
    if ($e instanceof PDOException) {
        // Database messages can quote real values (e.g. a duplicate matric number). Keep the key name only.
        $message = (string) preg_replace_callback(
            "/(key )?'[^']*'/",
            static fn (array $m): string => ($m[1] ?? '') !== '' ? $m[0] : "'...'",
            $message
        );
    }
    $line = sprintf(
        "[%s] %s: %s in %s:%d\n%s\n\n",
        gmdate('Y-m-d H:i:s') . ' UTC',
        get_class($e),
        $message,
        basename($e->getFile()),
        $e->getLine(),
        $e->getTraceAsString()
    );
    @file_put_contents(dirname(__DIR__) . '/storage/logs/error.log', $line, FILE_APPEND | LOCK_EX);
}

/** The one place that answers for an uncaught exception. */
function api_handle_exception(Throwable $e): void
{
    if ($e instanceof ApiException) {
        if ($e->getPrevious() !== null) {
            api_log_error($e->getPrevious());
        }
        Response::error($e->errorCode(), $e->getMessage(), $e->httpStatus(), $e->fields());
    }
    // The skeleton's placeholder: a route or method nobody has built yet.
    if ($e instanceof RuntimeException && $e->getMessage() === 'Not implemented') {
        Response::error('not_implemented', 'This endpoint has not been built yet.', 501);
    }
    api_log_error($e);
    Response::error('server_error', 'Something went wrong on our side. Try again in a moment.', 500);
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    if ($severity & (E_DEPRECATED | E_USER_DEPRECATED)) {
        return false; // logged by PHP, not fatal
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

if (!$isCli) {
    set_exception_handler('api_handle_exception');

    // Fatal errors (out of memory, etc.) skip the exception handler; still answer with JSON.
    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            api_log_error(new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
            if (!headers_sent()) {
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo '{"error":{"code":"server_error","message":"Something went wrong on our side. Try again in a moment.","fields":{}}}';
            }
        }
    });

    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
}

foreach (['Security', 'Academics', 'Student', 'Transcript', 'Payment', 'Documents', 'Admin'] as $file) {
    require_once $root . "/classes/$file.php";
}

if (!$isCli) {
    // Student or admin cookie, chosen from the /api/admin/ path (Dev B).
    try {
        Session::start();
    } catch (RuntimeException $e) {
        // TEMPORARY: until Dev B merges Session, the skeleton throws "Not implemented".
        // Remove this try/catch once Session::start() exists.
        if ($e->getMessage() !== 'Not implemented') {
            throw $e;
        }
    }
}
