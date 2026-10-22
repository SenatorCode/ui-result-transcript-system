<?php
// Owner: Dev A. Signatures agreed in docs/BUILD_PLAN.md section 10.

final class Config
{
    public static function get(string $key) { throw new RuntimeException('Not implemented'); }
}

final class Database
{
    public static function fetchOne(string $sql, array $params = []): ?array { throw new RuntimeException('Not implemented'); }
    public static function fetchAll(string $sql, array $params = []): array { throw new RuntimeException('Not implemented'); }
    public static function execute(string $sql, array $params = []): int { throw new RuntimeException('Not implemented'); }
    public static function lastInsertId(): int { throw new RuntimeException('Not implemented'); }
    /** Re-entrant: a nested call reuses the open transaction. */
    public static function transaction(callable $fn) { throw new RuntimeException('Not implemented'); }
}

final class Response
{
    public static function success($data, int $status = 200): void { throw new RuntimeException('Not implemented'); }
    public static function error(string $code, string $message, int $status, array $fields = []): void { throw new RuntimeException('Not implemented'); }
}

final class Request
{
    public static function json(): array { throw new RuntimeException('Not implemented'); }
    public static function query(string $key) { throw new RuntimeException('Not implemented'); }
    /** Decodes the tr_ id from ?id= */
    public static function transcriptId(): int { throw new RuntimeException('Not implemented'); }
}

final class ApiException extends Exception
{
    private string $errorCode;
    private int $httpStatus;
    private array $fields;

    public function __construct(string $code, string $message, int $status, array $fields = [])
    {
        parent::__construct($message);
        $this->errorCode = $code;
        $this->httpStatus = $status;
        $this->fields = $fields;
    }

    public function errorCode(): string { return $this->errorCode; }
    public function httpStatus(): int { return $this->httpStatus; }
    public function fields(): array { return $this->fields; }
}

final class IdCodec
{
    /** Prefixes: st, tr, ad */
    public static function encode(string $prefix, int $id): string { throw new RuntimeException('Not implemented'); }
    public static function decode(string $prefix, string $publicId): int { throw new RuntimeException('Not implemented'); }
}

final class Router
{
    /** Builds "METHOD path" from the request and runs the matching route. 404 not_found / 405 method_not_allowed. */
    public static function dispatch(): void { throw new RuntimeException('Not implemented'); }
}