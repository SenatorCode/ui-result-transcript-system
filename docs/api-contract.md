# Student portal API contract

This document is the contract between `public/` and the PHP endpoints in `api/`. The backend owns all academic, fee, payment, and transcript rules. All URLs are plain PHP files; enforce HTTP methods in each file and pass resource ids in the query string. The frontend calls `../api/...` relative to `public/`, on the same Apache origin.

## Conventions

- JSON success: `{ "data": ... }`. JSON error: `{ "error": { "code": "...", "message": "...", "fields": {} } }`; `fields` is always an object, even when empty.
- Dates are ISO 8601 UTC strings. Money is an integer number of naira. GPA/CGPA are decimal numbers as returned by the academic system.
- Student authentication uses a PHP session cookie (`HttpOnly; SameSite=Lax; Secure on HTTPS`). Never return or store bearer tokens in JavaScript. Every POST requires `X-CSRF-Token`, obtained from `auth/csrf.php` before login and from login or `auth/me` after authentication. `auth/csrf.php` creates the anonymous PHP session needed for safe login and public password-reset POSTs; rotate the token on login.
- `POST transcripts/create.php` and `POST transcripts/pay.php` also require a unique `Idempotency-Key` per user intent. A replay with the same key and same body returns the original result; same key with a different body returns `409 idempotency_conflict`.
- Errors: `401 unauthenticated` or `session_expired`; `403 forbidden` or `csrf_invalid`; `404 not_found`; `409 conflict`, `duplicate_open_request`, `invalid_transition`, `idempotency_conflict`; `422 validation_failed`; `429 rate_limited` (include `Retry-After`). Include actionable plain-language messages; do not disclose whether a matric number exists during login or reset requests.
- Example error body (all endpoints use this same shape): `{ "error": { "code": "validation_failed", "message": "Check the highlighted fields and try again.", "fields": { "institutionName": "Enter the institution name." } } }`. `fields` is `{}` when there are no field-specific errors.
- Every endpoint accepts/returns UTF-8 JSON except authenticated PDF download. Set `Cache-Control: no-store` for student-specific data and downloads.

## Status vocabulary

The canonical transcript status enum is: `pending_payment`, `payment_processing`, `paid`, `processing`, `ready`, `sent`, `failed`, `cancelled`, `rejected`, `expired`. Use only these values in storage/API/UI. `payment_processing` means payment has been initiated but not verified. `paid` means verified. `processing` means Registry is preparing it. `ready` means a personal copy is available. `sent` means an institution-bound copy has been dispatched. `failed` means an operational or payment attempt failed and needs a next step. `rejected` includes a required `rejectionReason`. `expired` means the unpaid quote/request expired. `cancelled` means the student cancelled before payment.

`allowedActions` is authoritative and may contain `pay`, `cancel`, `download`, `receipt`, or `retry`; never infer actions from status. Institution-bound transcripts must not offer `download`. Every transition is validated by the server.

## Endpoints

All examples below show the body inside the success envelope unless noted. All errors use the common envelope above, with the HTTP status shown.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `api/auth/login.php` | Public | Login |
| GET | `api/auth/csrf.php` | Public | Start anonymous session and obtain pre-login CSRF token |
| GET | `api/auth/me.php` | Student | Restore session and get CSRF token |
| POST | `api/auth/logout.php` | Student | End session |
| POST | `api/auth/password-reset-request.php` | Public | Request reset link |
| POST | `api/auth/password-reset.php` | Public, one-time token | Set new password |
| GET | `api/results/options.php` | Student | Existing session/semester pairs |
| GET | `api/results/index.php?session=2025%2F2026&semester=First%20Semester` | Student | Results and server calculations |
| GET | `api/transcripts/index.php` | Student | List transcript requests |
| GET | `api/transcripts/quote.php?destinationType=personal` | Student | Get current fee quote |
| POST | `api/transcripts/create.php` | Student + CSRF + idempotency | Create request |
| POST | `api/transcripts/pay.php?id=tr_1001` | Student + CSRF + idempotency | Start payment |
| POST | `api/payments/verify.php?id=tr_1001` | Student + CSRF | Verify payment with server/gateway |
| POST | `api/transcripts/cancel.php?id=tr_1001` | Student + CSRF | Cancel eligible unpaid request |
| GET | `api/transcripts/download.php?id=tr_1001` | Student | Stream owner-matched PDF if ready/sent and allowed |
| GET | `api/payments/receipt.php?id=tr_1001` | Student | Receipt metadata or PDF stream (content negotiation via `Accept`) |

### `POST auth/login.php`

Request: `{ "matricNo": "CSC/2023/001", "password": "..." }`.

`200`: `{ "student": { "id": "st_01", "fullName": "Ada Okafor", "matricNo": "CSC/2023/001", "department": "Computer Science", "level": 300 }, "csrfToken": "..." }`; session cookie is set. Generic `401 invalid_credentials` for wrong matric/password. `403 account_suspended` for a suspended account. `429 rate_limited` includes `retryAfterSeconds` and `Retry-After`.

### `GET auth/csrf.php`

No auth. Starts an anonymous PHP session and returns `{ "csrfToken": "..." }` in the success envelope. The frontend calls this before any POST when no authenticated CSRF token is available.

### `GET auth/me.php`

`200`: `{ "student": { "id": "st_01", "fullName": "Ada Okafor", "matricNo": "CSC/2023/001", "department": "Computer Science", "level": 300 }, "csrfToken": "..." }`. `401 session_expired` when no valid session. This is the source of CSRF token after reload.

### `POST auth/logout.php`

No body. Requires CSRF. `200`: `{ "loggedOut": true }`; expire session cookie and destroy session.

### Password reset

`POST auth/password-reset-request.php`: `{ "emailOrMatric": "..." }`. `200`: `{ "message": "If the account can be found, reset instructions will be sent." }` for all inputs; `429` on throttling. `POST auth/password-reset.php`: `{ "token": "one-time-token", "newPassword": "..." }`. `200`: `{ "reset": true }`; `422` for password policy or invalid/expired token. Tokens are single-use, short-lived, and delivered out of band; do not reveal account existence.

### Results

`GET results/options.php` returns only pairs that exist and are visible to the student: `{ "pairs": [{ "session": "2025/2026", "semester": "First Semester", "released": true }, { "session": "2024/2025", "semester": "Second Semester", "released": false, "availability": "withheld" }] }`. Empty list is valid. `released` false may include `availability: "pending" | "withheld"` and a student-safe message.

`GET results/index.php?...` returns `{ "session": "2025/2026", "semester": "First Semester", "availability": "released", "courses": [{ "code": "CSC 301", "title": "Data Structures", "units": 3, "unitsEarned": 3, "grade": "A", "gradePoint": 5, "attempt": 1, "carryOver": false }], "summary": { "gpa": 4.25, "cgpa": 3.91, "unitsRegistered": 18, "unitsEarned": 15, "degreeClass": "Second Class Honours (Upper Division)" } }`. Availability is `released`, `pending`, `withheld`, or `empty`; non-released states may omit courses/summary. Course and summary `unitsEarned` values follow university pass/retake rules; UI does not compute them. Include failed/carry-over attempts where appropriate. `404` when the requested pair does not exist for the student.

Grading scale (one source for UI display; backend remains authoritative for GPA):

| Grade | Points | Meaning |
|---|---:|---|
| A | 5 | Excellent |
| B | 4 | Very good |
| C | 3 | Good |
| D | 2 | Pass |
| E | 1 | Pass |
| F | 0 | Fail |

### Transcripts

`GET transcripts/index.php` returns `{ "items": [ { "id": "tr_1001", "destination": { "type": "personal", "label": "Personal copy" }, "deliveryMethod": "download", "copies": 1, "feeAmount": 15000, "status": "pending_payment", "createdAt": "2026-10-10T08:30:00Z", "updatedAt": "2026-10-10T08:30:00Z", "rejectionReason": null, "allowedActions": ["pay", "cancel"], "payment": { "reference": null, "status": null, "paidAt": null } } ] }`. Never return another student's records. `sent` items include a human-readable destination and `sentAt`; `ready` personal items may expose download and receipt actions.

`GET transcripts/quote.php?destinationType=personal|institution&deliveryMethod=download|courier|email&copies=1` returns `{ "feeAmount": 15000, "currency": "NGN", "expiresAt": "2026-10-10T09:00:00Z", "destinationType": "personal", "deliveryMethod": "download", "copies": 1 }`. Quote is required before create and is not a client-calculated fee. Current UI offers 1–3 copies; backend validates 1–3 for this contract. Personal copies use `download`; institution deliveries use `email` or `courier`. `422` for unsupported combinations.

`POST transcripts/create.php` request: `{ "destinationType": "institution", "institutionName": "Example University", "destinationAddress": "Registry, Example Road", "destinationEmail": "records@example.edu", "deliveryMethod": "courier", "copies": 1, "quoteExpiresAt": "2026-10-10T09:00:00Z" }` (institution name and email for `email`, institution name and postal address for `courier`; personal copy omits these). `201`: `{ "transcript": { "id": "tr_1002", "status": "pending_payment", "feeAmount": 15000, "allowedActions": ["pay", "cancel"] } }`. `409 duplicate_open_request` for same student and destination while an unpaid request remains open; `422` field errors include `institutionName`, destination, quote expiry, or copy count.

`POST transcripts/pay.php?id=tr_1001` body `{}`. `200`: `{ "transcriptId": "tr_1001", "mode": "simulated", "paymentUrl": null, "reference": "pay_abc123", "status": "payment_processing" }` or mode `gateway` with optional `paymentUrl`. When redirecting to a gateway, its configured return URL points to the same portal with `?payment=return&id=tr_1001&reference=...`. The UI never trusts query status and always calls verify on return. `404` for a non-owned/missing id; `409` for ineligible/duplicate payment; `422` for invalid request.

`POST payments/verify.php?id=tr_1001` body `{ "reference": "pay_abc123" }`. `200`: `{ "transcriptId": "tr_1001", "paymentStatus": "success", "transcriptStatus": "paid", "allowedActions": ["receipt"], "verifiedAt": "2026-10-10T08:45:00Z", "receiptAvailable": true }`. Verification is safe to repeat. Failed/not-yet-settled verification returns the current status (`failed` or `payment_processing`) and current `allowedActions` (for example `retry` after a confirmed failure); never mark paid from a browser redirect alone.

`POST transcripts/cancel.php?id=tr_1001` body `{}`. `200`: `{ "transcriptId": "tr_1001", "status": "cancelled", "allowedActions": [] }`; `409 invalid_transition` if payment already verified or work has started. UI confirms before sending.

`GET transcripts/download.php?id=tr_1001` returns `200 application/pdf` with safe `Content-Disposition: attachment`. Only stream when owner matches, status is `ready` or `sent`, and server policy allows it. Otherwise JSON `403 forbidden` or `409 invalid_transition`. Never return a public/storage URL.

`GET payments/receipt.php?id=tr_1001`: with `Accept: application/json`, `200` `{ "receiptNumber": "RCPT-2026-1001", "amount": 15000, "paidAt": "2026-10-10T08:45:00Z", "reference": "pay_abc123" }`; with `Accept: application/pdf`, stream an authenticated receipt PDF. `404` if no verified payment.

## Backend requirements

- Use PDO prepared statements for all SQL and validate inputs server-side. Hash passwords with `password_hash()` and verify with `password_verify()`.
- Regenerate the session id on login; use secure cookie attributes, expire/destroy on logout, and protect login with rate limiting and generic credential errors.
- Validate `X-CSRF-Token` on every POST, including payment verification and reset where session-bound; use one-time reset tokens for public reset flow.
- Check ownership on every student id/resource id lookup before returning data or performing an action. A student must never read another student's results, transcript, payment, receipt, or download.
- Enforce allowed state transitions and idempotency on the server. Recompute fee quotes/amounts and all academic calculations on the server; never trust values sent by the browser.
- Download streams a PDF only if the authenticated owner matches and status is `ready`/`sent` with action permitted. Do not expose filesystem paths or public links.
- Return correct HTTP status codes, safe cache headers, and structured errors consistently.

## Endpoint error responses

Every error below uses the shared error envelope example above. In addition to validation failures, each protected endpoint can return `401 session_expired`, `403 csrf_invalid` on POST or `403 forbidden` for a valid resource/action permission denial, and `429 rate_limited` where rate limiting applies. Return `404 not_found` for foreign ids to avoid confirming another student's records exist.

| Endpoint | Additional error responses |
|---|---|
| `POST auth/login.php` | `401 invalid_credentials`; `403 account_suspended`; `422 validation_failed`; `429 rate_limited` with `Retry-After` and `fields.retryAfterSeconds`. |
| `GET auth/csrf.php` | `503 service_unavailable` if PHP cannot start a session. |
| `GET auth/me.php` | `401 session_expired`. |
| `POST auth/logout.php` | `401 session_expired`; `403 csrf_invalid`. |
| `POST auth/password-reset-request.php` | `422 validation_failed`; `429 rate_limited`. A successful response stays generic. |
| `POST auth/password-reset.php` | `422 validation_failed` for invalid/expired token or password policy; `429 rate_limited`. |
| `GET results/options.php` | `401 session_expired`. An empty pair array is a successful response. |
| `GET results/index.php?...` | `404 not_found` for a nonexistent/unavailable pair; `401 session_expired`. |
| `GET transcripts/index.php` | `401 session_expired`. An empty items array is a successful response. |
| `GET transcripts/quote.php?...` | `422 validation_failed` for unsupported delivery/copy combinations; `401 session_expired`. |
| `POST transcripts/create.php` | `409 duplicate_open_request` or `idempotency_conflict`; `422 validation_failed` for destination, copy, or expired quote; `401`/`403` auth/CSRF failures. |
| `POST transcripts/pay.php?id=...` | `404 not_found`; `409 invalid_transition` or `idempotency_conflict`; `422 validation_failed`; `401`/`403` auth/CSRF failures. |
| `POST payments/verify.php?id=...` | `404 not_found`; `409 invalid_transition`; `422 validation_failed`; `401`/`403` auth/CSRF failures. |
| `POST transcripts/cancel.php?id=...` | `404 not_found`; `409 invalid_transition`; `401`/`403` auth/CSRF failures. |
| `GET transcripts/download.php?id=...` | `404 not_found` for non-owner or missing id; `403 forbidden` for an owned but policy-denied download; `409 invalid_transition` when not ready/sent. |
| `GET payments/receipt.php?id=...` | `404 not_found` if no verified payment or record not owned; `401 session_expired`. |

## Implied data needs for team lead review

The existing schema/docs cover students, result attempts, grades, GPA/CGPA views, transcript requests, and payments. Before backend implementation, confirm whether these fields/tables are needed (do not alter SQL as part of this frontend work):

- Transcript destination type, institution name, postal address, email, delivery method, copy count, dispatch timestamp/tracking, and destination normalization for duplicate-open-request checks.
- Quote expiry and fee configuration by destination/delivery/copies; integer-naira API mapping from current decimal schema values.
- `payment_processing`, `failed`, `cancelled`, `expired`, and `sent` transcript states; status history, payment attempt idempotency keys, and durable idempotency response storage.
- Result release/withheld state and per-session/semester visibility pairs, plus student level if not already derivable/reliably stored.
- Reset token hash, expiry, used timestamp, and delivery workflow; session/CSRF support and login attempt throttling storage.
- Secure PDF storage metadata, receipts, receipt number, and institution dispatch records.
- Confirm institutional grading/pass rules, units-earned definition (failed units count as registered, not earned), carry-over display, degree-class rules, and whether failed attempts remain visible in transcript output.
