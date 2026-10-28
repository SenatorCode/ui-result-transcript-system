# Admin portal API contract

This is the contract between the pages in `admin/` and the PHP endpoints in `api/admin/`. It is an addendum to the student portal contract (`docs/API_CONTRACT.md`): everything there about the response envelope, error shape, ISO 8601 UTC dates, integer-naira money, status vocabulary and `Cache-Control: no-store` applies here unless this document says otherwise. The backend owns all rules. The admin pages call `../api/admin/...` relative to `admin/`, on the same Apache origin.

## Conventions

- **Separate session.** Admin accounts use their own PHP session cookie (for example `UIADMINSESS`), chosen by the backend from the request path `/api/admin/`. A student and an admin can be signed in at the same time in one browser without affecting each other. Cookie attributes: `HttpOnly; SameSite=Lax; Secure on HTTPS`. Never return or store bearer tokens in JavaScript.
- **CSRF.** `GET admin/auth/csrf.php` starts the anonymous admin session and returns a token before login. Login and `me` return a fresh token. Every POST requires `X-CSRF-Token`. Rotate the token on login.
- **Ids.** Admin `ad_<n>`, student `st_<n>`, transcript request `tr_<n>`. Request numbers such as `TR-2026-000001` are display values only; use `id` in URLs.
- **Wrong session type.** A student session on an admin endpoint, or an admin session on a student endpoint, gets `401 session_expired`. Do not reveal that the other account type exists.
- **Pagination.** `page` is 1-based (default 1). `pageSize` defaults to 20, maximum 50. Lists return a `page` object: `{ "page": 1, "pageSize": 20, "totalItems": 11, "totalPages": 1 }`.
- **Fresh data.** Before building any list or the dashboard, the server expires overdue unpaid requests and moves stale `payment_processing` requests to `failed`, so counts and statuses are always current.
- **Rate limiting.** Login allows 5 failures per 15 minutes per username and IP, then `429 rate_limited` with `Retry-After` and `fields.retryAfterSeconds`.
- **Errors.** `401 unauthenticated` or `session_expired`; `403 csrf_invalid` or `account_deactivated`; `404 not_found`; `409 invalid_transition`; `422 validation_failed`; `429 rate_limited`. All use the shared error envelope, and `fields` is always an object.

## Admin action vocabulary

`adminActions` is authoritative and may contain `start_processing`, `mark_ready`, `mark_sent`, `reject`. The pages never infer actions from status.

| Action | From status | To status | Applies to | Notes |
|---|---|---|---|---|
| `start_processing` | `paid` | `processing` | Any request | Registry begins preparing it. |
| `mark_ready` | `processing` | `ready` | Personal copies only (`deliveryMethod: download`) | Server issues the transcript: sets the transcript number, total units, CGPA snapshot and ready time. |
| `mark_sent` | `processing` | `sent` | Institution copies only (`email` or `courier`) | Server sets `sentAt` and issues the same snapshot. |
| `reject` | `paid`, `processing` | `rejected` | Any request | `reason` required. If a verified payment exists it is flagged refunded and the response says `refundRequired: true`. |

Any action not in the request's current `adminActions` returns `409 invalid_transition`. Repeating an action that already took effect also returns `409`; the page should reload the request.

## Endpoints

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `api/admin/auth/csrf.php` | Public | Start anonymous admin session and get a pre-login CSRF token |
| POST | `api/admin/auth/login.php` | Public + CSRF | Admin login |
| GET | `api/admin/auth/me.php` | Admin | Restore session and get CSRF token |
| POST | `api/admin/auth/logout.php` | Admin + CSRF | End session |
| GET | `api/admin/dashboard.php` | Admin | Counts, revenue and recent requests |
| GET | `api/admin/transcripts/index.php` | Admin | List and filter transcript requests |
| GET | `api/admin/transcripts/show.php?id=tr_1001` | Admin | One request with student and payment history |
| POST | `api/admin/transcripts/update-status.php?id=tr_1001` | Admin + CSRF | Apply an admin action |
| GET | `api/admin/transcripts/document.php?id=tr_1001` | Admin | Stream the transcript PDF for preparing or dispatching |
| GET | `api/admin/payments/index.php` | Admin | List and filter payments |
| POST | `api/admin/payments/reverify.php?id=tr_1001` | Admin + CSRF | Optional: re-check a payment stuck in `payment_processing` |

### `GET admin/auth/csrf.php`

No auth. `200`: `{ "csrfToken": "..." }`. `503 service_unavailable` if PHP cannot start a session.

### `POST admin/auth/login.php`

Request: `{ "username": "registry.admin", "password": "..." }`.

`200`: `{ "admin": { "id": "ad_01", "fullName": "Registry Admin", "username": "registry.admin" }, "csrfToken": "..." }`; the admin session cookie is set and the session id is regenerated. `401 invalid_credentials` (generic, never says whether the username exists); `403 account_deactivated`; `422 validation_failed`; `429 rate_limited`.

### `GET admin/auth/me.php`

`200`: same body as login. `401 session_expired` when there is no valid admin session. This is the source of the CSRF token after a page reload.

### `POST admin/auth/logout.php`

No body. `200`: `{ "loggedOut": true }`; destroys the admin session only. `401 session_expired`; `403 csrf_invalid`.

### `GET admin/dashboard.php`

`200` (values illustrative):

```json
{
  "generatedAt": "2026-10-21T09:00:00Z",
  "requests": {
    "total": 11,
    "byStatus": {
      "pending_payment": 1, "payment_processing": 1, "paid": 1, "processing": 1,
      "ready": 2, "sent": 1, "failed": 1, "cancelled": 1, "rejected": 1, "expired": 1
    },
    "awaitingPayment": 2,
    "awaitingAction": 2
  },
  "payments": {
    "verifiedAmount": 40000,
    "verifiedCount": 5,
    "refundedAmount": 0,
    "currency": "NGN"
  },
  "recent": [ { "...": "up to 5 request summaries, same shape as list items" } ]
}
```

`byStatus` always contains all ten statuses, with zero where none. `awaitingPayment` counts `pending_payment` and `payment_processing`. `awaitingAction` counts `paid` and `processing`, the requests Registry must act on. `verifiedAmount` is the sum of successful, not-refunded payments. `401 session_expired`.

### `GET admin/transcripts/index.php`

Query (all optional): `status` (one canonical status, or several separated by commas), `destinationType` (`personal` or `institution`), `q` (at least 2 characters; matches request number, matric number or student name), `sort` (`newest` default, or `oldest`), `page`, `pageSize`.

`200`:

```json
{
  "items": [
    {
      "id": "tr_1001",
      "requestNo": "TR-2026-000001",
      "student": { "id": "st_01", "matricNo": "CSC/2023/001", "fullName": "Ada Okafor" },
      "destination": { "type": "personal", "label": "Personal copy" },
      "deliveryMethod": "download",
      "copies": 1,
      "feeAmount": 15000,
      "status": "paid",
      "createdAt": "2026-10-10T08:30:00Z",
      "updatedAt": "2026-10-10T08:45:00Z",
      "payment": { "reference": "pay_abc123", "status": "success", "paidAt": "2026-10-10T08:45:00Z" },
      "adminActions": ["start_processing", "reject"]
    }
  ],
  "page": { "page": 1, "pageSize": 20, "totalItems": 11, "totalPages": 1 }
}
```

`422 validation_failed` for an unknown status or destination type, a `q` shorter than 2 characters, or `pageSize` above 50. An empty `items` array is a successful response.

### `GET admin/transcripts/show.php?id=tr_1001`

`200`:

```json
{
  "transcript": {
    "id": "tr_1001",
    "requestNo": "TR-2026-000001",
    "status": "processing",
    "destination": { "type": "institution", "label": "Example University" },
    "institution": { "name": "Example University", "address": "Registry, Example Road", "email": "records@example.edu" },
    "deliveryMethod": "courier",
    "copies": 1,
    "feeAmount": 15000,
    "purpose": "Postgraduate admission",
    "createdAt": "2026-10-10T08:30:00Z",
    "updatedAt": "2026-10-11T10:00:00Z",
    "expiresAt": "2026-10-11T08:30:00Z",
    "rejectionReason": null,
    "issued": null,
    "download": { "count": 0, "lastDownloadedAt": null },
    "handledBy": null,
    "handledAt": null,
    "adminActions": ["mark_sent", "reject"]
  },
  "student": {
    "id": "st_01", "matricNo": "CSC/2023/001", "fullName": "Ada Okafor",
    "email": "ada@example.com", "phone": "08000000000",
    "department": "Computer Science", "level": 300, "status": "active"
  },
  "payments": [
    {
      "reference": "pay_abc123", "gateway": "simulated", "status": "success",
      "amount": 15000, "currency": "NGN",
      "initiatedAt": "2026-10-10T08:40:00Z", "paidAt": "2026-10-10T08:45:00Z",
      "verifiedAt": "2026-10-10T08:45:00Z", "receiptNumber": "RCPT-2026-1001",
      "refundedAt": null, "failureReason": null
    }
  ]
}
```

`institution` is `null` for personal copies. `issued` is `null` until the request is `ready` or `sent`; afterwards it is `{ "transcriptNo": "TRN-2026-000001", "totalUnits": 96, "cgpa": 4.30, "readyAt": "...", "sentAt": null }`. `gateway` is `simulated` or `gateway`. `payments` lists every attempt, newest first. `handledBy` is `{ "id": "ad_01", "fullName": "Registry Admin" }` once audit columns exist (optional migration 005) and `null` before; pages must tolerate `null`. `404 not_found` for a missing id.

### `POST admin/transcripts/update-status.php?id=tr_1001`

Request: `{ "action": "reject", "reason": "Results are under review." }`. `reason` is required for `reject` (3 to 255 characters) and ignored otherwise.

`200`:

```json
{
  "transcriptId": "tr_1001",
  "status": "rejected",
  "adminActions": [],
  "rejectionReason": "Results are under review.",
  "refundRequired": true,
  "issued": null
}
```

For `mark_ready` and `mark_sent`, `issued` carries the transcript number, total units, CGPA and timestamps. `refundRequired` is `true` only when a verified payment was flagged refunded by this call. Errors: `404 not_found`; `409 invalid_transition` (action not allowed for the current status, `mark_ready` on an institution copy, `mark_sent` on a personal copy); `422 validation_failed` for an unknown action or a missing or too-short reason, with `fields.reason`; `403 csrf_invalid`.

### `GET admin/transcripts/document.php?id=tr_1001`

`200 application/pdf` with `Content-Disposition: attachment`, so Registry can print or send the transcript. Allowed when the status is `processing`, `ready` or `sent`. Before issue, the PDF uses live results and shows the transcript number as pending. This does not change the student's download count. `409 invalid_transition` for any other status; `404 not_found`. Never return a public or storage URL.

### `GET admin/payments/index.php`

Query (all optional): `status` (`initiated`, `success`, `failed`, `cancelled`), `gateway` (`simulated` or `gateway`), `from` and `to` (dates, `YYYY-MM-DD`, filter on initiation time), `page`, `pageSize`.

`200`:

```json
{
  "items": [
    {
      "reference": "pay_abc123",
      "transcriptId": "tr_1001",
      "requestNo": "TR-2026-000001",
      "student": { "matricNo": "CSC/2023/001", "fullName": "Ada Okafor" },
      "gateway": "simulated",
      "amount": 15000,
      "currency": "NGN",
      "status": "success",
      "initiatedAt": "2026-10-10T08:40:00Z",
      "paidAt": "2026-10-10T08:45:00Z",
      "verifiedAt": "2026-10-10T08:45:00Z",
      "receiptNumber": "RCPT-2026-1001",
      "refundedAt": null,
      "failureReason": null
    }
  ],
  "summary": { "count": 8, "verifiedAmount": 40000 },
  "page": { "page": 1, "pageSize": 20, "totalItems": 8, "totalPages": 1 }
}
```

`summary` covers the whole filtered set, not just the current page. `422 validation_failed` for invalid filters.

### `POST admin/payments/reverify.php?id=tr_1001` (optional)

Body `{}`. Only for a request in `payment_processing`. Behaves like the student `payments/verify.php`: safe to repeat, never trusts the browser. `200`: `{ "transcriptId": "tr_1001", "paymentStatus": "success", "transcriptStatus": "paid", "adminActions": ["start_processing", "reject"] }`. `409 invalid_transition` for any other status. If this endpoint is not built, it is simply left out and the pages hide the button.

## Backend requirements

- Every admin endpoint calls the admin guard first. A deactivated admin gets `403 account_deactivated` at login, and an existing session is rejected with `401 session_expired` on its next request.
- `update-status` runs in one transaction: lock the request row, check the action against the current status, then write. Issue columns (`transcript_no`, `total_units`, `cgpa`, `ready_at` or `sent_at`) are set in the same UPDATE as the status, because the database CHECK constraints reject partial issues.
- Status changes go through the single `Transcript::transition()` method. No endpoint writes the status column directly.
- Never accept amounts, statuses or snapshot values from the browser. Fee, CGPA and units are computed by the server.
- Validate every filter and body field server-side, use PDO prepared statements, and return the shared error envelope with correct HTTP codes.
- Do not log student personal data or passwords.
- Audit fields (`handled_by_admin_id`, `handled_at`) need a new numbered migration and are optional. Until it is applied, `handledBy` and `handledAt` are `null`.

## Pages and the endpoints they call

The admin interface is a Single Page Application (SPA). There is only one physical HTML file (`index.html`). The five screens are rendered dynamically via JavaScript hash routing.

| View / Route | Purpose | Calls |
|---|---|---|
| `admin/index.html#/login` | Login view; redirects to dashboard if already signed in | `auth/csrf`, `auth/login`, `auth/me` |
| `admin/index.html#/dashboard` | Status counts, revenue, recent requests | `dashboard` |
| `admin/index.html#/requests` | Filterable, paged request list | `transcripts/index` |
| `admin/index.html#/request/<id>` | Request detail, admin action buttons, PDF link | `transcripts/show`, `transcripts/update-status`, `transcripts/document`, optionally `payments/reverify` |
| `admin/index.html#/payments` | Filterable, paged payment list | `payments/index` |
| `admin/admin.js`, `admin/admin.css` | SPA router, shared fetch wrapper (adds the CSRF header, unwraps the envelope, sends a 401 back to login), and styles | All |

The SPA renders action buttons only from `adminActions`, asks for confirmation before `reject`, and reloads the request after every action.

## Endpoint status

| Endpoint | Status |
|---|---|
| `admin/auth/csrf`, `login`, `me`, `logout` | Draft |
| `admin/dashboard` | Draft |
| `admin/transcripts/index`, `show` | Draft |
| `admin/transcripts/update-status` | Draft |
| `admin/transcripts/document` | Draft |
| `admin/payments/index` | Draft |
| `admin/payments/reverify` | Optional |
