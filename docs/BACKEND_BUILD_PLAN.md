# Group 12 Backend Build Plan: Responsibilities and Links

Digital Examination Result and Transcript Payment System | PHP + MySQL + JavaScript | Backend team: Dev A, Dev B, Dev C | 21 October 2026

## 1. How to use this document

- **Section 2** shows each person's full responsibilities on one screen.
- **Section 3** gives each person's tasks in detail, plus what they depend on and who depends on them.
- **Section 4** is the phase-by-phase roadmap with owners and a completion test for every phase.
- **Section 5** shows every link (handoff) between people.
- **Section 6** lists decisions to settle before building. **Sections 7 to 9** hold shared rules, test data and the cut list.
- **Section 10** explains how we avoid stepping on each other. **Section 11** gives the 19 working files and who owns each. Endpoint names in this plan are contract URLs; each one is a route in a routes file, not a separate PHP file.

Sources: DATABASE\_SCHEMA.md (v1.1, migrations 001 to 004), the student portal API contract and the admin API contract. If this document and the SQL disagree, the SQL wins.

## 2. Responsibilities at a glance

|  | Dev A | Dev B | Dev C |
| --- | --- | --- | --- |
| **Focus** | Project setup, core plumbing, results and GPA, student profile, all PDFs, student download | Security, student auth and password reset, transcript requests, payments | The whole admin panel: admin API, issuing, admin pages |
| **Classes** | Config, Database, Response, Request, ApiException, IdCodec, Course, Result, GpaCalculator, StudentProfile, TranscriptData, TranscriptPdf, ReceiptPdf | Session, Csrf, RateLimiter, Idempotency, Auth, Student, PasswordReset, TranscriptStatus, Transcript, FeeCalculator, Payment, payment gateways | Admin, AdminReport, TranscriptIssuer |
| **Endpoints** | `results/options`, `results/index`, `student/profile`, `transcripts/download` | `auth/csrf`, `auth/login`, `auth/me`, `auth/logout`, `auth/password-reset-request`, `auth/password-reset`, `transcripts/quote`, `create`, `index`, `cancel`, `pay`, `payments/verify`, `payments/receipt` | `admin/auth/csrf`, `login`, `me`, `logout`, `admin/dashboard`, `admin/transcripts/index`, `show`, `update-status`, `document`, `admin/payments/index`, `reverify` (optional) |
| **Pages** | None (student pages are in `public/`) | None | `admin/index.html` with login, dashboard, requests, request detail and payments views |
| **Phases** | R0, R1, R2, R3, R7, R8 | R1, R2, R4, R5, R7, R8 | R1, R2, R6, R7, R8 |
| **Files (19 in all)** | `README.md`, `config.example.php`, `api/bootstrap.php`, `api/index.php`, `api/routes_student.php`, `classes/Core.php`, `Academics.php`, `Documents.php` (8) | `api/routes_account.php`, `classes/Security.php`, `Student.php`, `Transcript.php`, `Payment.php` (5) | `api/routes_admin.php`, `classes/Admin.php`, `admin/index.html`, `admin.js`, `admin.css` (5); `tests/run_all.php` is shared (1) |

## 3. Responsibilities in detail

### 3.1 Dev A: Setup, core, academic data and documents

**Tasks**

- **R0 Setup kit:** `.github/CODEOWNERS`, `.editorconfig`, `.gitignore`, `api/.htaccess` (URL rewrite), `config.example.php`, one `README.md` holding the setup steps and working rules, `storage/logs/.gitkeep`; import migrations 001 to 004 on a clean database and confirm the 4 views exist; record the decisions in section 6; open the day-1 skeleton PR (section 10).
- **R1 Foundation:** `Config`, `Database` (PDO with exceptions, utf8mb4, re-entrant `transaction()`), bootstrap with a global error handler (JSON error envelope, no SQL or file paths leaked), `Response`, `Request` and validation helpers, `ApiException`, ID codec (`st_`, `tr_` and `ad_` prefixes), `Router` and the front controller `api/index.php`, so every contract URL keeps working from one entry point.
- **R2 Profile:** `StudentProfile` and `student/profile.php` (full name, matric number, email, phone, date of birth, faculty, department, level, entry session, status).
- **R3 Results and GPA:** `results/options.php` and `results/index.php`; `Result` and `Course`; attempt numbers, carry-over, semester GPA, units registered and earned, degree class; CGPA as of the selected semester from `v_result_details` (never from `v_counted_results`); `GpaCalculator::overallCgpa()` and `totalUnitsCounted()` for the issue snapshot; a GPA section in `tests/run_all.php` with hand-checked answers.
- **R7 Documents:** `TranscriptData` (student header, results grouped by session and semester, semester GPAs, CGPA snapshot; for a request not yet issued it uses live results and shows the transcript number as pending), `TranscriptPdf` with FPDF, `transcripts/download.php` (streams as an attachment, updates `download_count`), and `ReceiptPdf` for the receipt PDF.
- **R8:** cross-check GPA output against the SQL views; `tests/run_all.php` runner and helpers; keep `docs/api-contract.md` statuses and this plan current; final database dump.

**Depends on:** B's sessions and student guard for any logged-in test (until then use a temporary test student id and remove it before merging to main); B's `canDownload()` for the download endpoint.

**Others depend on you:** every endpoint uses `Database`, `Response` and `Request`, so the R1 core must merge before B and C build on it. C's issuer needs `GpaCalculator`, C's admin document endpoint needs `TranscriptData` and `TranscriptPdf`, and B's receipt endpoint needs `ReceiptPdf`.

### 3.2 Dev B: Security, requests, payments and account recovery

**Tasks**

- **R1 Foundation:** `Session` (cookie flags, id regeneration, separate student and admin cookies chosen by the `/api/admin/` path), `Csrf` (student and admin namespaces), `RateLimiter` on `login_attempts`, `Idempotency` on `idempotency_keys`, `Auth` guards (`requireStudent()` and `requireAdmin()`; the admin guard also rejects a deactivated admin with `401 session_expired` on the next request).
- **R2 Student auth:** `auth/csrf.php`, `login.php`, `me.php`, `logout.php` with generic `401 invalid_credentials`, `403 account_suspended`, and `429` with `Retry-After` after 5 failures in 15 minutes. Password reset: `PasswordReset` (hashed single-use token, link written to `storage/logs/reset-links.log` because XAMPP has no mail server, password policy), `password-reset-request.php` (same answer for every input) and `password-reset.php`.
- **R4 Transcript requests:** `quote`, `create`, `index`, `cancel`; `TranscriptStatus`, `Transcript` with the state machine, `transition()`, `allowedActions()`, `adminActions()`, `canDownload()`, `expireOverdue()` (bulk: expires overdue unpaid requests and moves `payment_processing` older than about 30 minutes to `failed`), lazy expiry, `request_no` generation; `FeeCalculator` with the quote stored in the session; ownership checks (foreign ids return 404); idempotency on create; duplicate key mapped to `409 duplicate_open_request`.
- **R5 Payments:** `transcripts/pay.php` (from `pending_payment` or `failed` only; insert an `initiated` payment with a random reference and move the request to `payment_processing` in one transaction), `payments/verify.php` and `Payment::settle()` (lock the payment row, safe to repeat, check amount equals `fee_amount`, set `verified_at` and `receipt_no`, move the request to `paid`; stale `payment_processing` after about 30 minutes becomes `failed`), `payments/receipt.php` (JSON, or PDF through A's `ReceiptPdf`), `PAYMENT_MODE` guard, simulated gateway (Paystack optional); `Payment::markRefunded()` for C's reject flow.
- **R8:** security pass across auth, ownership, CSRF and payments; tests and Postman collection for student auth and payments.

**Depends on:** A's `Database`, `Response` and `Request` (start on stubs until A merges); A's decisions on fee formula and the open-request rule; A's `ReceiptPdf` for the receipt PDF.

**Others depend on you:** guards and sessions are needed by A (results, profile) and C (all admin endpoints); the state machine is needed by A (download) and C (issuing, dashboard, lists); `Payment::settle()` is needed by C's optional `reverify`; `Payment::markRefunded()` and `RateLimiter` by C's reject flow and admin login.

### 3.3 Dev C: The admin panel

**Tasks**

- **R1 Page shell:** the single admin page `admin/index.html` (a view switcher for login, dashboard, requests, request detail and payments), `admin/admin.css` and `admin/admin.js` (fetch wrapper that adds the CSRF header, unwraps the envelope, sends a 401 back to login), built against the mock JSON in the admin contract so no endpoint is needed yet.
- **R2 Admin auth:** `Admin` class, `admin/auth/csrf.php`, `login.php`, `me.php`, `logout.php`, and the login view.
- **R6 Admin features:** `AdminReport` (dashboard counts by status, verified revenue, filtered and paged lists, request detail); `admin/dashboard.php`, `admin/transcripts/index.php`, `show.php`, `admin/payments/index.php`; `TranscriptIssuer` and `admin/transcripts/update-status.php` (`start_processing`, `mark_ready`, `mark_sent`, `reject`, with transcript number, units and CGPA snapshot set in one UPDATE, and the verified payment flagged refunded through `Payment::markRefunded()` when a paid request is rejected; when migration 005 exists, `handled_by_admin_id` and `handled_at` are set in the same call); the dashboard, requests, request-detail and payments views; optional `admin/payments/reverify.php`.
- **R7:** `admin/transcripts/document.php`, which streams the PDF for statuses `processing`, `ready` and `sent` using A's `TranscriptData` and `TranscriptPdf`.
- **R8:** the admin section of `tests/run_all.php`, admin Postman collection, keep `docs/admin-api-contract.md` statuses current, end-to-end admin walkthrough through the admin views.

**Depends on:** A's core, `GpaCalculator`, `TranscriptData` and `TranscriptPdf`; B's admin session and CSRF, `Auth::requireAdmin()`, `Transcript::transition()`, `adminActions()`, `expireOverdue()`, `Payment::markRefunded()` and `Payment::settle()`; `RateLimiter` for admin login.

**Others depend on you:** the admin views and the end-to-end admin walkthrough; the `ready` and `sent` requests that `update-status` produces for testing downloads and receipts.

## 4. Roadmap by phase

| Phase | What gets built | Owner | Needs | Done when |
| --- | --- | --- | --- | --- |
| **R0** Setup and sign-off (G0 to G1) | Repo kit, config template, DB import, views check, decisions settled, schema signed off | A, with B and C signing off | None | Clean import works and the decisions are agreed |
| **R1** Foundation (G3) | Core, bootstrap, ID codec (A); sessions, CSRF, rate limiter, idempotency, guards (B); admin page shell on mock data (C) | A, B, C | R0 | A test endpoint returns correct 200, 405, 422 and 500 envelopes with no SQL leak; the admin shell renders mock data |
| **R2** Auth and profile (G4) | Student auth and password reset (B); admin auth and login view (C); student profile (A) | A, B, C | R1 | Seed student logs in; suspended account blocked; 6th wrong attempt returns 429; admin logs in on its own cookie; profile returns |
| **R3** Results and GPA | options, index, attempts, carry-over, GPA, CGPA as-of, degree class, GPA test script | A | R1, and R2 for logged-in testing | JSON matches the contract; seed test cases pass (section 8) |
| **R4** Transcript requests | quote, create, index, cancel, state machine, allowedActions, adminActions, lazy expiry | B | R1, R2 | Every allowed transition passes and every other returns `409 invalid_transition` |
| **R5** Payments (G6) | pay, verify, settle, receipt JSON, simulated gateway | B | R4 | Double-verify, wrong-amount and wrong-owner tests pass |
| **R6** Admin features | AdminReport, dashboard, lists, detail, issuer, update-status, four admin views | C | R3, R4, R5, R2 | Admin can move a paid request to ready or sent, or reject it with a reason, from the pages |
| **R7** Documents | Data builder, transcript PDF, student download, receipt PDF (A); download eligibility (B); admin document endpoint (C) | A, B, C | R3, R4, R5, R6 | A ready personal transcript downloads as PDF; Registry can fetch the PDF for processing, ready and sent requests; institution copies never download for students |
| **R8** Hardening and integration (G7) | Security pass (B); GPA cross-check, runner, README, statuses, dump (A); admin regression and walkthrough (C); end-to-end run with Frontend (all) | A, B, C | All phases | Full flow works: login, results, request, payment, admin ready, download |

Order: R0, then R1, then R2 alongside R3, then R4 and R5 with R6 starting as R4 lands, then R7, then R8. Password reset can slot in anywhere after R1.

## 5. Where the links are

| # | From | To | What is handed over | Needed before |
| --- | --- | --- | --- | --- |
| 1 | A (R1 core) | B, C | `Database`, `Response`, `Request`, `ApiException`, ID codec | B and C finish any endpoint |
| 2 | B (R1 sessions, CSRF, guards) | A, C | Student and admin guards, CSRF check, admin session cookie | Profile and results (A), all admin endpoints (C) |
| 3 | B (R2 login) | A | A real session for testing | Removing A's temporary test student id |
| 4 | A (R0 decisions) | B, C | Fee formula, degree-class bands, open-request rule, quote handling | R3 summary fields (A), R4 fee and create (B) |
| 5 | B (R4 state machine) | C | `transition()`, `adminActions()`, `expireOverdue()` | Issuer, dashboard, lists, `show` (C) |
| 6 | B (R4 `canDownload()`) | A | Download eligibility | `transcripts/download.php` (A) |
| 7 | B (R5 payments) | C | `Payment::settle()`, payment rows | Admin payments list, `reverify`, dashboard revenue (C) |
| 8 | A (R3 `GpaCalculator`) | C | `overallCgpa()`, `totalUnitsCounted()` | `TranscriptIssuer` (C) |
| 9 | A (R7 `TranscriptData`, `TranscriptPdf`) | C | Data and PDF renderer | `admin/transcripts/document.php` (C) |
| 10 | A (R7 `ReceiptPdf`) | B | Receipt PDF renderer | `payments/receipt.php` PDF branch (B) |
| 11 | C (R6 `update-status`) | A, B | A `ready` or `sent` request to test against | Download testing (A), receipt checks (B) |
| 12 | C (mock JSON in the admin contract) | C | Sample responses for every admin endpoint | Building views before the endpoints are Ready |
| 13 | B (R1 `RateLimiter`, R5 `Payment::markRefunded()`) | C | Login throttling and the refund flag | Admin login (R2) and the reject action (R6) |

## 6. Decisions to settle first

Recommended defaults are shown so nobody is blocked.

| # | Mismatch between contract and database | Default |
| --- | --- | --- |
| 1 | **Open-request rule.** The contract says duplicates apply per student and destination; the DB allows one open request per student (`uq_one_open_request`). | Follow the DB. Fix the contract text. Catch SQLSTATE 23000 on that key and return `409 duplicate_open_request`. |
| 2 | **Result release states** (released, pending, withheld) have no column or table. | v1 treats everything as released behind one `Result::availability()` method. Add a small migration 005 only if the group wants the withheld demo. |
| 3 | **Admin endpoints** were missing from the student contract. | The admin contract is written (`docs/admin-api-contract.md`) and is the source for the admin endpoints and pages. |
| 4 | **Quote verification.** `create.php` receives only `quoteExpiresAt`, no signature. | Store the quote in the session at `quote.php`; `create.php` checks it matches and has not expired. Fee is always recomputed server-side. Drop `QUOTE_SECRET`. |
| 5 | **Fee numbers.** Contract examples show 15,000; the seed uses 5,000 per copy; courier surcharge undecided. | Config formula: `TRANSCRIPT_FEE x copies + COURIER_SURCHARGE`. Group agrees the values. |
| 6 | **Result rules** for `degreeClass`, `unitsEarned`, `carryOver`. | Config bands on the 5-point scale. `unitsEarned` counts units where grade is not F. `carryOver` is true when attempt number is above 1. Confirm with the department. |
| 7 | **Public ids and enums.** `st_01` and `tr_1001` vs integer ids; integer naira vs decimals; `paystack` vs `gateway`. | One mapping layer handles all three. |
| 8 | **Brief's JSON shape** has `student` and `unit`; the contract has no `student` and uses `units`. | Add `student` (matric number) to the results response. Decide `unit` vs `units` once. |
| 9 | **Student profile.** `auth/me` returns only 5 fields. | Add `GET student/profile.php`. |
| 10 | **PDF.** The contract needs PDFs for download and receipt. | Generate on demand with FPDF (single file, no Composer). Do not store files. |
| 11 | **Admin audit trail.** The schema stores no admin id on a request. | Optional migration 005 (`handled_by_admin_id`, `handled_at`). The admin contract returns `null` until it exists. |
| 12 | **Nested transactions.** `update-status` runs in one transaction, while `transition()` also needs to lock the request row. | `Database::transaction()` is re-entrant; `transition()` locks the row with `SELECT ... FOR UPDATE` itself; the issuer calls it inside the outer transaction so the issue columns and the refund flag commit together. |
| 13 | **File count.** The first plan had about 80 files; the group wants 15 to 20. | 19 working files (section 11): related classes share one file per owner, URLs become routes in three routes files, tests live in one runner, and the admin pages are views of one page. No class, endpoint, page or test case is dropped. |
| 14 | **Admin pages vs the admin contract.** The contract lists five HTML pages. | Same five screens as views of `admin/index.html` (`#/dashboard`, `#/requests`, `#/request/tr_1001`, `#/payments`). C updates the page table in the admin contract; endpoint URLs do not change. |

## 7. Shared rules

**Response shape.** Success: `{ data: ... }`. Error: `{ error: { code, message, fields } }`, with `fields` always an object. Set `Cache-Control: no-store` on student-specific and admin data.

**Statuses.** `pending_payment`, `payment_processing`, `paid`, `processing`, `ready`, `sent`, `failed`, `cancelled`, `rejected`, `expired`. Use only these values everywhere.

**Allowed transitions.**

| From | To | Triggered by |
| --- | --- | --- |
| pending\_payment | payment\_processing | student starts payment |
| pending\_payment | cancelled | student cancels |
| pending\_payment | expired | deadline passed (lazy) |
| payment\_processing | paid | verification succeeds |
| payment\_processing | failed | verification fails, or unsettled after about 30 minutes |
| failed | payment\_processing | student retries payment |
| failed | cancelled | student cancels |
| failed | expired | deadline passed (lazy) |
| paid | processing | admin (`start_processing`) |
| paid, processing | rejected | admin (`reject`, reason required) |
| processing | ready | admin (`mark_ready`, personal copy) |
| processing | sent | admin (`mark_sent`, institution copy) |

Any other transition returns `409 invalid_transition`. `Transcript::adminActions()` is the single source for which admin actions apply to a request; the admin pages and `update-status` both use it.

**Security checklist (everyone).**

- Prepared statements for all SQL; validate all input server-side.
- The student id comes from the session, never from the browser.
- Every resource lookup checks ownership; foreign ids return 404.
- CSRF token on every POST, student and admin; idempotency key on `create` and `pay`.
- `password_hash()` and `password_verify()` only; regenerate the session id on login.
- Never log passwords or student personal data, including in error logs; admin login is rate limited like student login (5 failures in 15 minutes).
- Student and admin sessions are separate; each type gets `401` on the other's endpoints.
- Amounts, statuses and issue snapshots are decided on the server; never mark paid from a browser redirect alone.
- Download streams only for the owner, status `ready`, personal copy; Registry fetches PDFs only through the admin endpoint; no public links or file paths.

## 8. Test data (from the seed)

| Who | Login | Password |
| --- | --- | --- |
| Students | Matric number, e.g. `CSC/2023/001` | `Test@1234` |
| Admins | `registry.admin` or `finance.admin` | `Admin@1234` |

- `CSC/2023/001`, 2025/2026 Second Semester returns CSC 204 with grade A.
- `CSC/2023/001` CGPA at 2024/2025 Second Semester is 3.98, while the overall CGPA is 4.30.
- `CSC/2023/002` has no 2025/2026 Second Semester results, so that request returns 404.
- `CSC/2025/002` has no results at all, so `results/options` returns an empty list.
- `CSC/2024/003` is suspended and cannot log in.
- The seed holds 11 transcript requests covering every status, and 8 payments (5 success, 2 failed, 1 initiated).

## 9. If time runs short

Cut in this order: admin `reverify`, password reset, receipt PDF (keep the JSON), Paystack mode (keep simulated), admin paging, the withheld and pending result states, the admin audit trail.

## 10. Working without stepping on each other

**The rules**

1. **One file, one owner.** Section 11 lists the owner of every file. Only the owner edits it. Related classes share a file only when the same person owns all of them, so a grouped file still has exactly one owner. If you need a change in someone else's file, ask the owner in the PR or issue; they make it, or approve your small PR, the same day. A `.github/CODEOWNERS` file makes GitHub request the owner's review automatically.
2. **Skeleton PR on day 1.** Dev A opens one PR that creates all 19 working files in section 11 as stubs: each class with its docblock, the method signatures from the table below, and bodies that throw `RuntimeException('Not implemented')`; each routes file with its route keys pointing at those stubs. B and C review the signatures. After that nobody creates the same file twice, and everyone codes against agreed signatures from the first hour.
3. **Thin routes, logic in classes.** Every URL in the contracts is one route, a short closure in a routes file with the same shape (below), and the rules live in classes. Each owner has exactly one routes file, so two people never edit the same routes.
4. **Overlapping features stay in separate files.** `Student` (B, in `Student.php`) and `StudentProfile` (A, in `Academics.php`); `Transcript` (B) and `TranscriptIssuer` (C, in `Admin.php`); `Payment` (B) and `ReceiptPdf` (A, in `Documents.php`); `Payment` (B) and the refund flag set by `TranscriptIssuer` (C). Each pair talks only through the signatures below.
5. **Git habits.** Branch off `dev` as `feature/<area>-<short-name>`, rebase on `dev` daily, keep PRs under about 300 lines, squash merge, never push straight to `dev` or `main`. The owner of every file a PR touches reviews it. Do not reformat files you do not own; the `.editorconfig` (4 spaces, LF, UTF-8) keeps everyone's editor consistent.
6. **Append-only shared files.** `config.example.php`, the status columns in `docs/api-contract.md` and `docs/admin-api-contract.md`, the setup steps in `README.md` and `tests/run_all.php` may be edited by anyone, but only by adding or changing your own line or your own marked section, in the same PR as the code that needs it.
7. **Database and contract changes.** The database changes only through a new numbered file in `database/` and is announced to everyone; never edit a merged SQL file. Contract changes go through a PR reviewed by Frontend (student contract) or by the admin page owner (admin contract), plus the endpoint's owner.
8. **Daily 10-minute check-in.** What merged, what is blocked, and any signature that changed.

**How requests reach the code**

`api/.htaccess` rewrites every `api/**/*.php` URL to `api/index.php`, so the contract URLs (`api/transcripts/create.php`, `api/admin/auth/login.php`) stay exactly as written. `index.php` calls `bootstrap.php`, strips the `/api/` prefix and the `.php` ending, builds a key such as `POST transcripts/create`, and looks it up in the three routes files (`routes_student.php`, `routes_account.php`, `routes_admin.php`), each of which returns an array of `'METHOD path' => callable`. An unknown path returns `404 not_found`; a known path with the wrong method returns `405 method_not_allowed` with an `Allow` header. `Session::start()` picks the student or admin cookie from the `/api/admin/` path prefix.

**Route shape (every route follows this)**

```
<?php
// api/routes_account.php  (Dev B)
return [
    'POST transcripts/create' => function () {
        Csrf::verify();
        $studentId = Auth::requireStudent();
        $body = Request::json();

        $result = Transcript::create($studentId, $body);
        Response::success(['transcript' => $result], 201);
    },
    // ... one entry per URL, same shape
];
```

Admin routes in `routes_admin.php` call `Auth::requireAdmin()` instead. Errors are thrown as `ApiException` and turned into the error envelope in one place, so no route builds its own error response.

**Where the overlap really is, and the rule for each**

| Hotspot | Who touches it | Rule |
| --- | --- | --- |
| Request status changes | B (pay, verify, cancel), C (`update-status` through the issuer) | Every change goes through `Transcript::transition($id, $to, $extra)`. Nobody writes an UPDATE on the status column anywhere else. |
| Issue columns (`transcript_no`, `total_units`, `cgpa`, `ready_at`, `sent_at`, `rejection_reason`, and the audit columns once migration 005 exists) | C (issuer), B (transition) | C passes them in `$extra`; B's `transition()` accepts only an allow-list of columns, so the CHECK constraints are satisfied in one UPDATE. |
| Student table queries | B (login, me), A (profile) | B uses `Student`, A uses `StudentProfile`. Separate queries, no shared edits. |
| Receipt | B (data and route), A (PDF) | B's `Payment::receiptData()` returns a fixed array shape; A's `ReceiptPdf` only renders it. |
| Transcript PDF | A (renderer and student download), C (admin document) | Both call `TranscriptData::build()` and `TranscriptPdf::render()`. Nobody makes a second renderer. |
| Admin actions | B (rules), C (routes and page) | Only `Transcript::adminActions()` decides which actions apply. |
| Refund flag on reject | C (issuer), B (payments table) | C calls `Payment::markRefunded($requestId)`; nobody else writes `refunded_at` and C writes no payment SQL. |
| Admin access | B (guard and session), C (admin routes) | `Auth::requireAdmin()` is B's. C never reads `$_SESSION` directly. |
| Bootstrap, router and shared helpers | Everyone | A owns them. Ask for a hook; do not edit. |
| Status names | Everyone | Use `TranscriptStatus::PAID` and friends (B), never raw strings. |
| Settings | Everyone | Read through `Config::get('KEY')`. Every key is defined in `config.example.php` on day 1. |
| GPA and CGPA | A | Only `GpaCalculator` computes them. Nobody else writes GPA SQL; C's issuer calls it. |
| Tests | Everyone | `tests/run_all.php` has one marked section per owner (A: GPA; B: state machine, payment verify, password reset; C: admin flow). Edit only your section. |

**Signatures to agree before coding (commit these in the skeleton PR)**

| Signature | Owner | Used by |
| --- | --- | --- |
| `Database::fetchOne($sql, $params): ?array`, `fetchAll(...): array`, `execute(...): int`, `transaction(callable)` (re-entrant) | A | Everyone |
| `Response::success($data, $status = 200)`, `Response::error($code, $message, $status, $fields = [])` | A | Everyone |
| `Router::dispatch()`; each routes file returns `['METHOD path' => callable]` | A | A, B, C (routes files) |
| `Request::json(): array`, `Request::query($key)`, `Request::transcriptId(): int` | A | Everyone |
| `new ApiException($code, $message, $status, $fields = [])` | A | Everyone |
| `IdCodec::encode($prefix, $id): string`, `IdCodec::decode($prefix, $publicId): int` (prefixes `st`, `tr`, `ad`) | A | B, C |
| `Config::get($key)` | A | Everyone |
| `Auth::requireStudent(): int`, `Auth::requireAdmin(): int` | B | A, C |
| `Csrf::token(): string`, `Csrf::verify()` | B | Every POST route |
| `RateLimiter::assertAllowed($scope, $identifier)`, `recordFailure(...)`, `clear(...)` | B | B (student login), C (admin login) |
| `Idempotency::run($endpoint, $key, $body, callable): array` | B | B (create, pay) |
| `Transcript::findOwned($publicId, $studentId): array` | B | A (download), B |
| `Transcript::transition($id, $to, $extra = []): array` | B | C (issuer), B (pay, verify, cancel) |
| `Transcript::allowedActions($row): array`, `Transcript::canDownload($row): bool` | B | A (download), B (lists) |
| `Transcript::adminActions($row): array`, `Transcript::expireOverdue(): int` | B | C |
| `Payment::receiptData($requestId, $studentId): ?array` | B | B (receipt route) |
| `Payment::settle($reference): array` | B | B (verify), C (reverify) |
| `Payment::markRefunded($requestId): bool` | B | C (issuer) |
| `GpaCalculator::overallCgpa($studentId): float`, `GpaCalculator::totalUnitsCounted($studentId): int` | A | C (issuer) |
| `TranscriptData::build($requestId): array`, `TranscriptPdf::render($data): string` | A | A (download), C (admin document) |
| `ReceiptPdf::render($receipt): string` | A | B (receipt route) |
| `Admin::authenticate($username, $password): array` | C | C (admin login route) |
| `AdminReport::dashboard(): array`, `transcriptList($filters): array`, `transcriptDetail($requestId): array`, `paymentList($filters): array` | C | C (admin routes) |
| `TranscriptIssuer::apply($requestId, $adminId, $action, $reason = null): array` | C | C (`update-status` route) |

## 11. File structure and ownership

The team writes **19 working files**, marked with a number below. Repo plumbing (`.github/CODEOWNERS`, `.editorconfig`, `.gitignore`, `api/.htaccess`, `storage/logs/.gitkeep`), the FPDF library, the SQL in `database/`, the documents in `docs/` and the Frontend's `public/` are not counted. Top-level folders `admin/`, `api/`, `classes/`, `database/`, `docs/`, `public/` and the root files already exist in the repo; `admin/` is empty and belongs to Dev C. The letter beside each file is its owner. Nothing from the earlier file-per-class layout was dropped: every class, endpoint, page and test case still exists, grouped into fewer files.

```
ui-result-transcript-system/
  .github/CODEOWNERS                      A  plumbing
  .editorconfig                           A  plumbing
  .gitignore                              A  plumbing (add storage/logs/)
  README.md                               A  (1) setup, import order, working rules
  config.example.php                      A  (2)
  config.php                              local only, never committed
  public/                                 Frontend
  database/                               Database team
  admin/
    index.html                            C  (3) login, dashboard, requests, request detail, payments views
    admin.js                              C  (4)
    admin.css                             C  (5)
  api/
    .htaccess                             A  plumbing (rewrites api/**/*.php to index.php)
    bootstrap.php                         A  (6)
    index.php                             A  (7) front controller
    routes_student.php                    A  (8)
    routes_account.php                    B  (9)
    routes_admin.php                      C  (10)
  classes/
    Core.php                              A  (11) Config, Database, Response, Request, ApiException, IdCodec, Router
    Academics.php                         A  (12) Course, Result, GpaCalculator, StudentProfile
    Documents.php                         A  (13) TranscriptData, TranscriptPdf, ReceiptPdf
    vendor/fpdf.php                       A  library, not counted
    Security.php                          B  (14) Session, Csrf, RateLimiter, Idempotency, Auth
    Student.php                           B  (15) Student, PasswordReset
    Transcript.php                        B  (16) TranscriptStatus, Transcript, FeeCalculator
    Payment.php                           B  (17) Payment, PaymentGateway, SimulatedGateway, PaystackGateway (optional)
    Admin.php                             C  (18) Admin, AdminReport, TranscriptIssuer
  docs/
    DATABASE_SCHEMA.md                    Database team
    api-contract.md                       A (status column: anyone, own line)
    admin-api-contract.md                 C (status column: anyone, own line)
    BUILD_PLAN.md                         A
    postman/student-results-documents.postman_collection.json   A
    postman/student-auth-payments.postman_collection.json       B
    postman/admin.postman_collection.json                       C
  tests/
    run_all.php                           shared (19) one runner and helpers; marked sections per owner
  storage/logs/.gitkeep                   A  plumbing (reset-links.log and error logs stay untracked)
```

`bootstrap.php` loads the eight class files once with `require_once`. The file list is fixed by the day-1 skeleton PR, so there is no list to fight over, and classes call each other at run time so load order does not matter.

### 11.1 Dev A: files and what they contain (8 files)

| File | Contains |
| --- | --- |
| Plumbing: `.github/CODEOWNERS`, `.editorconfig`, `.gitignore`, `api/.htaccess`, `storage/logs/.gitkeep` | Review routing by owner, shared editor settings, ignores for `config.php` and `storage/logs/`, the rewrite that sends every `api/**/*.php` URL to `index.php`, and the kept log folder. |
| `README.md` | XAMPP setup (including `AllowOverride All` for the rewrite), import order, how to run the tests, and the working rules from section 10. It replaces a separate CONTRIBUTING file. |
| `config.example.php` | Every setting key with a comment: database credentials, `TRANSCRIPT_FEE`, `COURIER_SURCHARGE`, `CURRENCY`, `CURRENT_SESSION`, `REQUEST_PAYMENT_HOURS`, `PAYMENT_MODE`, Paystack key placeholders, reset-log path, degree-class bands. |
| `api/bootstrap.php` | Loads `config.php`, requires the eight class files, sets JSON and `no-store` headers, starts the session through `Session::start()`, and holds the global exception handler that turns `ApiException` and any other error into the error envelope with no SQL or paths leaked. |
| `api/index.php` | Front controller: runs bootstrap, then `Router::dispatch()` over the three routes files, with `404 not_found` and `405 method_not_allowed`. |
| `api/routes_student.php` | Routes `results/options`, `results/index`, `student/profile` and `transcripts/download`. Download checks the owner and status through `Transcript::canDownload()`, streams the PDF as an attachment and updates `download_count`. |
| `classes/Core.php` | `Config::get()` for fee, currency, current session, payment mode, payment deadline hours, degree-class bands and database settings. `Database`: PDO connection (exceptions on, utf8mb4), `fetchOne`, `fetchAll`, `execute` and a re-entrant `transaction` (a nested call reuses the open transaction). `Response`: `success()` and `error()` in the agreed envelope, status codes and cache headers. `Request`: JSON body parsing, query values, `transcriptId()` (decodes `tr_` ids), required-field validation that fills `fields`. `ApiException`: typed error carrying code, HTTP status, message and fields. `IdCodec`: encode and decode `st_`, `tr_` and `ad_` public ids. `Router`: the route lookup. |
| `classes/Academics.php` | `Course`: course lookups. `Result`: session and semester pairs for a student, results for one semester with attempt numbers and carry-over, `availability()`. `GpaCalculator`: grade points, semester GPA, CGPA as of a semester (from `v_result_details`), overall CGPA, units registered and earned, `totalUnitsCounted()`, degree class. `StudentProfile`: profile query joining department and entry session. |
| `classes/Documents.php` | `TranscriptData`: student header, results grouped by session and semester, GPAs and the CGPA snapshot (live results with the transcript number shown as pending before issue). `TranscriptPdf`: PDF render with FPDF. `ReceiptPdf`: renders the receipt PDF from the array returned by `Payment::receiptData()`. |
| `classes/vendor/fpdf.php` | The FPDF library file, unmodified. Not counted. |
| `tests/run_all.php` (A section) | One command that runs every test; login and cURL helpers; hand-checked GPA and CGPA cases from the seed. |
| `docs/api-contract.md` status, `docs/BUILD_PLAN.md`, `docs/postman/student-results-documents.postman_collection.json` | Contract statuses kept current; this plan kept current; requests for results, profile and download. |

### 11.2 Dev B: files and what they contain (5 files)

| File | Contains |
| --- | --- |
| `api/routes_account.php` | Student auth routes (`auth/csrf`, `login`, `me`, `logout`, `password-reset-request`, `password-reset`), transcript routes (`transcripts/quote`, `create`, `index`, `cancel`, `pay`) and payment routes (`payments/verify`, `receipt`), each with the method in the student contract. `create` and `pay` use `Idempotency`; the reset-request route answers the same for every input; verify calls `Payment::settle()`; receipt returns JSON, or hands the data to `ReceiptPdf` when `Accept` asks for PDF. |
| `classes/Security.php` | `Session`: start with cookie flags, id regeneration, destroy; separate student and admin cookies chosen by the `/api/admin/` path. `Csrf`: token creation, rotation and `X-CSRF-Token` verification for both namespaces. `RateLimiter`: counts rows in `login_attempts` (5 failures in 15 minutes) and computes `Retry-After`. `Idempotency`: claims a key, replays the stored response, detects a conflicting body, stores the response. `Auth`: `requireStudent()` and `requireAdmin()` guards that return the id or throw 401; the admin guard re-checks that the admin is still active on every request. |
| `classes/Student.php` | `Student`: find by matric number, password check, suspended check, the public student shape for login and `me`. `PasswordReset`: random token stored as a hash, single-use consume, password policy, writes the reset link to `storage/logs/reset-links.log`. |
| `classes/Transcript.php` | `TranscriptStatus`: status constants, the allowed-transition table, open and closed status lists. `Transcript`: find with ownership check, list, create, `transition()`, `allowedActions()`, `adminActions()`, `canDownload()`, `expireOverdue()` (bulk: expires overdue unpaid requests and moves `payment_processing` older than about 30 minutes to `failed`), lazy expiry, `request_no` generation. `FeeCalculator`: fee formula, quote creation stored in the session, quote expiry check. |
| `classes/Payment.php` | `Payment`: create a payment attempt, find by reference, `settle()` in a transaction (safe to repeat), `receiptData()`, `markRefunded()`. `PaymentGateway`: the gateway interface. `SimulatedGateway`: the simulated gateway. `PaystackGateway`: the optional Paystack verify call. |
| `tests/run_all.php` (B sections), `docs/postman/student-auth-payments.postman_collection.json` | Every allowed and illegal transition; double-verify, wrong-amount and wrong-owner cases; single-use and expiry of reset tokens. Requests for student auth, password reset, transcripts and payments with the seed logins. |

### 11.3 Dev C: files and what they contain (5 files)

| File | Contains |
| --- | --- |
| `api/routes_admin.php` | Routes `admin/auth/csrf`, `login`, `me`, `logout`; `admin/dashboard`; `admin/transcripts/index`, `show`, `update-status`, `document`; `admin/payments/index` and `reverify` (optional). Document streams the transcript PDF for statuses `processing`, `ready` and `sent` using `TranscriptData` and `TranscriptPdf`, never changes the student's download count and never returns a storage URL. Update-status runs locked in one transaction through `TranscriptIssuer`. |
| `classes/Admin.php` | `Admin`: find admin by username, password check, active check. `AdminReport`: calls `Transcript::expireOverdue()` first on every list and the dashboard, then builds dashboard counts by status, verified revenue, filtered and paged transcript and payment lists, and the request detail. `TranscriptIssuer`: applies each admin action, builds `transcript_no`, takes the units and CGPA snapshot from `GpaCalculator`, calls `Payment::markRefunded()` when a paid request is rejected, passes the issue columns (and the audit columns once migration 005 exists) in `$extra` to `Transcript::transition()`, and returns `refundRequired`. |
| `admin/index.html` | One page with five views switched by the URL hash: login (calls `auth/csrf`, `auth/login`, `auth/me`; goes to the dashboard when already signed in), `#/dashboard` (status count cards, verified revenue, awaiting-action count, recent requests), `#/requests` (filter bar for status, destination type and search, paged table), `#/request/tr_1001` (student and payment history, action buttons drawn from `adminActions`, reject dialog with confirmation, PDF link) and `#/payments` (filter bar, paged table, summary strip). |
| `admin/admin.js` | Fetch wrapper that adds the CSRF header, unwraps the envelope and sends a 401 back to login; the view switcher; the page logic for each view. |
| `admin/admin.css` | Shared styles. |
| `tests/run_all.php` (C section), `docs/admin-api-contract.md` status, `docs/postman/admin.postman_collection.json` | Every admin action against seed requests, illegal actions, student session refused, CSRF missing; admin contract statuses kept current; one request per admin endpoint with the seed admin logins. |

### 11.4 Other folders

| Folder or file | Owner |
| --- | --- |
| `public/` | Frontend |
| `database/`, `docs/DATABASE_SCHEMA.md` | Database team |
