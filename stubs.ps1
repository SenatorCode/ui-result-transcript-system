# Run from the repo root AFTER scaffold.ps1:
#   powershell -ExecutionPolicy Bypass -File .\stubs.ps1
# Writes the agreed signatures (BUILD_PLAN section 10) into the 8 class files and 3 routes files.
# A file is overwritten ONLY if it is still an empty scaffold stub (or missing).
# Any file with real content gets a side file "<name>.signatures.txt" instead; merge by hand.

$ErrorActionPreference = "Stop"
$utf8 = New-Object System.Text.UTF8Encoding($false)

function Write-Stub($path, $content) {
    $content = $content -replace "`r?`n", "`n"
    $dir = Split-Path $path -Parent
    if ($dir -and -not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $target = $path
    if (Test-Path $path) {
        $old = Get-Content $path -Raw
        if ($null -eq $old) { $old = '' }
        $isEmptyStub = [string]::IsNullOrWhiteSpace($old) -or
                       $old.Contains('TODO: implement per docs/BUILD_PLAN.md') -or
                       $old.Contains("Returns ['METHOD path'") -or
                       $old.Contains("throw new RuntimeException('Not implemented')")
        if (-not $isEmptyStub) {
            $target = "$path.signatures.txt"
            Write-Host "KEEP   $path has real content -> wrote $target" -ForegroundColor Yellow
        } else {
            Write-Host "write  $path"
        }
    } else {
        Write-Host "create $path"
    }
    [System.IO.File]::WriteAllText((Join-Path (Get-Location) $target), $content, $utf8)
}

# ======================= Dev A =======================

Write-Stub "classes/Core.php" @'
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
'@

Write-Stub "classes/Academics.php" @'
<?php
// Owner: Dev A.

final class Course
{
    /** Proposed. */
    public static function find(int $courseId): ?array { throw new RuntimeException('Not implemented'); }
}

final class Result
{
    /** Proposed. Session and semester pairs that have results for this student. */
    public static function optionsFor(int $studentId): array { throw new RuntimeException('Not implemented'); }
    /** Proposed. Results for one semester with attempt numbers and carry-over. */
    public static function forSemester(int $studentId, string $session, string $semester): array { throw new RuntimeException('Not implemented'); }
    /** Proposed. */
    public static function availability(int $studentId, string $session, string $semester): string { throw new RuntimeException('Not implemented'); }
}

final class GpaCalculator
{
    public static function semesterGpa(int $studentId, string $session, string $semester): float { throw new RuntimeException('Not implemented'); }
    /** Proposed. CGPA as of the selected semester. */
    public static function cgpaAsOf(int $studentId, string $session, string $semester): float { throw new RuntimeException('Not implemented'); }
    public static function overallCgpa(int $studentId): float { throw new RuntimeException('Not implemented'); }
    public static function totalUnitsCounted(int $studentId): int { throw new RuntimeException('Not implemented'); }
    /** Proposed. */
    public static function degreeClass(float $cgpa): string { throw new RuntimeException('Not implemented'); }
}

final class StudentProfile
{
    /** Proposed. Joins department and entry session. */
    public static function find(int $studentId): ?array { throw new RuntimeException('Not implemented'); }
}
'@

Write-Stub "classes/Documents.php" @'
<?php
// Owner: Dev A.

final class TranscriptData
{
    /** Before issue: live results, transcript number shown as pending. */
    public static function build(int $requestId): array { throw new RuntimeException('Not implemented'); }
}

final class TranscriptPdf
{
    /** Returns PDF bytes. */
    public static function render(array $data): string { throw new RuntimeException('Not implemented'); }
}

final class ReceiptPdf
{
    /** Renders the array returned by Payment::receiptData(). */
    public static function render(array $receipt): string { throw new RuntimeException('Not implemented'); }
}
'@

Write-Stub "api/routes_student.php" @'
<?php
// Owner: Dev A. Methods assumed; align with docs/API_CONTRACT.md.
$todo = function () { throw new RuntimeException('Not implemented'); };

return [
    'GET results/options'     => $todo,
    'GET results/index'       => $todo,
    'GET student/profile'     => $todo,
    'GET transcripts/download' => $todo,
];
'@

# ======================= Dev B =======================

Write-Stub "classes/Security.php" @'
<?php
// Owner: Dev B.

final class Session
{
    /** Student or admin cookie chosen from the /api/admin/ path prefix. */
    public static function start(): void { throw new RuntimeException('Not implemented'); }
    public static function regenerate(): void { throw new RuntimeException('Not implemented'); }
    public static function destroy(): void { throw new RuntimeException('Not implemented'); }
}

final class Csrf
{
    public static function token(): string { throw new RuntimeException('Not implemented'); }
    /** Checks X-CSRF-Token; throws 403 csrf_invalid. */
    public static function verify(): void { throw new RuntimeException('Not implemented'); }
}

final class RateLimiter
{
    /** Throws 429 rate_limited with Retry-After when blocked. */
    public static function assertAllowed(string $scope, string $identifier): void { throw new RuntimeException('Not implemented'); }
    public static function recordFailure(string $scope, string $identifier): void { throw new RuntimeException('Not implemented'); }
    public static function clear(string $scope, string $identifier): void { throw new RuntimeException('Not implemented'); }
}

final class Idempotency
{
    public static function run(string $endpoint, ?string $key, array $body, callable $fn): array { throw new RuntimeException('Not implemented'); }
}

final class Auth
{
    /** Returns the student id or throws 401. */
    public static function requireStudent(): int { throw new RuntimeException('Not implemented'); }
    /** Returns the admin id or throws 401; also rejects a deactivated admin. */
    public static function requireAdmin(): int { throw new RuntimeException('Not implemented'); }
}
'@

Write-Stub "classes/Student.php" @'
<?php
// Owner: Dev B.

final class Student
{
    /** Proposed. */
    public static function findByMatricNo(string $matricNo): ?array { throw new RuntimeException('Not implemented'); }
    /** Proposed. */
    public static function verifyPassword(array $student, string $password): bool { throw new RuntimeException('Not implemented'); }
    /** Proposed. Public shape for login and me. */
    public static function publicShape(array $student): array { throw new RuntimeException('Not implemented'); }
}

final class PasswordReset
{
    /** Proposed. Same response for every input. */
    public static function request(string $matricNo): void { throw new RuntimeException('Not implemented'); }
    /** Proposed. Single-use token. */
    public static function consume(string $token, string $newPassword): void { throw new RuntimeException('Not implemented'); }
}
'@

Write-Stub "classes/Transcript.php" @'
<?php
// Owner: Dev B.

final class TranscriptStatus
{
    public const PENDING_PAYMENT    = 'pending_payment';
    public const PAYMENT_PROCESSING = 'payment_processing';
    public const PAID               = 'paid';
    public const PROCESSING         = 'processing';
    public const READY              = 'ready';
    public const SENT               = 'sent';
    public const FAILED             = 'failed';
    public const CANCELLED          = 'cancelled';
    public const REJECTED           = 'rejected';
    public const EXPIRED            = 'expired';
}

final class Transcript
{
    public static function findOwned(string $publicId, int $studentId): array { throw new RuntimeException('Not implemented'); }
    /** Proposed. */
    public static function listForStudent(int $studentId, array $filters = []): array { throw new RuntimeException('Not implemented'); }
    /** Proposed. */
    public static function create(int $studentId, array $body): array { throw new RuntimeException('Not implemented'); }
    /** Proposed. */
    public static function cancel(string $publicId, int $studentId): array { throw new RuntimeException('Not implemented'); }
    /** Only way to change status. Locks the row; accepts an allow-list of $extra columns. */
    public static function transition(int $id, string $to, array $extra = []): array { throw new RuntimeException('Not implemented'); }
    public static function allowedActions(array $row): array { throw new RuntimeException('Not implemented'); }
    public static function canDownload(array $row): bool { throw new RuntimeException('Not implemented'); }
    public static function adminActions(array $row): array { throw new RuntimeException('Not implemented'); }
    /** Expires overdue unpaid requests; fails stale payment_processing. Returns rows changed. */
    public static function expireOverdue(): int { throw new RuntimeException('Not implemented'); }
}

final class FeeCalculator
{
    /** Proposed. Stores the quote in the session. */
    public static function quote(int $studentId, array $body): array { throw new RuntimeException('Not implemented'); }
    /** Proposed. */
    public static function assertQuoteValid(string $quoteId, int $studentId): array { throw new RuntimeException('Not implemented'); }
}
'@

Write-Stub "classes/Payment.php" @'
<?php
// Owner: Dev B.

interface PaymentGateway
{
    /** Proposed. Returns ['status' => ..., 'amount' => ...]; the server never trusts the browser. */
    public function verify(string $reference): array;
}

final class SimulatedGateway implements PaymentGateway
{
    public function verify(string $reference): array { throw new RuntimeException('Not implemented'); }
}

/** Optional. */
final class PaystackGateway implements PaymentGateway
{
    public function verify(string $reference): array { throw new RuntimeException('Not implemented'); }
}

final class Payment
{
    /** Proposed. */
    public static function initiate(int $requestId, int $studentId): array { throw new RuntimeException('Not implemented'); }
    /** Proposed. */
    public static function findByReference(string $reference): ?array { throw new RuntimeException('Not implemented'); }
    /** In a transaction; safe to repeat. */
    public static function settle(string $reference): array { throw new RuntimeException('Not implemented'); }
    public static function receiptData(int $requestId, int $studentId): ?array { throw new RuntimeException('Not implemented'); }
    /** Flags the verified payment refunded (used by C's reject flow). */
    public static function markRefunded(int $requestId): bool { throw new RuntimeException('Not implemented'); }
}
'@

Write-Stub "api/routes_account.php" @'
<?php
// Owner: Dev B. Methods assumed; align with docs/API_CONTRACT.md.
$todo = function () { throw new RuntimeException('Not implemented'); };

return [
    'GET auth/csrf'                    => $todo,
    'POST auth/login'                  => $todo,
    'GET auth/me'                      => $todo,
    'POST auth/logout'                 => $todo,
    'POST auth/password-reset-request' => $todo,
    'POST auth/password-reset'         => $todo,
    'POST transcripts/quote'           => $todo,
    'POST transcripts/create'          => $todo,
    'GET transcripts/index'            => $todo,
    'POST transcripts/cancel'          => $todo,
    'POST transcripts/pay'             => $todo,
    'POST payments/verify'             => $todo,
    'GET payments/receipt'             => $todo,
];
'@

# ======================= Dev C =======================

Write-Stub "classes/Admin.php" @'
<?php
// Owner: Dev C.

final class Admin
{
    /** Returns ['id' => 'ad_01', 'fullName' => ..., 'username' => ...]; throws invalid_credentials / account_deactivated. */
    public static function authenticate(string $username, string $password): array { throw new RuntimeException('Not implemented'); }
}

final class AdminReport
{
    /** Each method calls Transcript::expireOverdue() first. */
    public static function dashboard(): array { throw new RuntimeException('Not implemented'); }
    public static function transcriptList(array $filters): array { throw new RuntimeException('Not implemented'); }
    public static function transcriptDetail(int $requestId): array { throw new RuntimeException('Not implemented'); }
    public static function paymentList(array $filters): array { throw new RuntimeException('Not implemented'); }
}

final class TranscriptIssuer
{
    /** Actions: start_processing, mark_ready, mark_sent, reject. Runs in one transaction. */
    public static function apply(int $requestId, int $adminId, string $action, ?string $reason = null): array { throw new RuntimeException('Not implemented'); }
}
'@

Write-Stub "api/routes_admin.php" @'
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
'@

Write-Host "`nDone. Run 'php -l' on each file, then 'git diff' before committing."
Write-Host "Check: Get-ChildItem classes, api -Filter *.php -Recurse | ForEach-Object { php -l `$_.FullName }"