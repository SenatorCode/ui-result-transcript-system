# Student portal frontend

The student portal is a zero-build HTML, CSS, and ES module app for the University of Ibadan Results & Transcript Portal. The API contract is documented in [the shared contract](../docs/api-contract.md); change that document and `js/mock/adapter.js` together whenever the frontend contract changes.

## Run on XAMPP

1. Put the repository inside XAMPP `htdocs`, start Apache, then open `http://localhost/ui-result-transcript-system/public/`.
2. The portal runs in mock mode by default. Use the fake coursework matric number `CSC/2023/001` and any non-empty password. The mock does not store that input. No database or API server is required for the UI walkthrough.
3. To use the PHP backend, edit `js/config.js` and set `USE_MOCKS: false`. Keep the portal and `api/` under the same Apache origin. The app then calls `../api/...` and sends the session cookie automatically. Implement every endpoint in [the contract](../docs/api-contract.md) before turning mocks off.
4. Mock conditions are selected with a query string: `?mock=slow`, `?mock=offline`, `?mock=expired`, `?mock=empty`, or `?mock=error`. Enter `LOCKED` as the matric number to preview a 429 login lockout. Remove the flag to restore normal mock behavior.

## How it was built

### Architecture

- `index.html` loads the global icon sprite, design tokens stylesheet, config, and one ES module entry point.
- `js/main.js` owns delegated interaction handling, session bootstrap, toasts, confirmation dialog, login/recovery, and request actions.
- `js/core/router.js` is a small hash router. Protected pages are lazy imported; route changes abort outstanding requests. The route and query survive a session expiry so sign-in can resume where the student was.
- `js/core/api.js` is the only API client. It uses a relative `../api/` base, same-origin credentials, timeout/abort handling, GET de-duplication, an in-memory stale-while-revalidate cache, CSRF and idempotency headers, and one error shape. Transcript/PDF downloads are authenticated blobs and bypass the GET cache.
- `js/config.js` controls mock mode. The mock adapter is dynamically imported only when `USE_MOCKS` is true. It returns the contract shapes, models request/payment state, and adds a short delay.
- `js/pages/` contains lazy-loaded login, home, result, and transcript pages. `js/components/ui.js` contains shared semantic UI markup and formatting helpers.
- `js/status.js` defines the complete transcript status vocabulary and A–F grading scale used by the UI. The server remains authoritative for GPA, CGPA, degree class, units, fees, status, and allowed actions.
- `css/app.css` uses CSS custom properties for the UI palette, typography, radius, and state colors. There is no framework, bundler, or runtime dependency.

### Key decisions

- The screen is a responsive student portal with a desktop side navigation and mobile bottom tabs. Content has a readable max width; controls reflow at phone widths.
- The attached prototype sets the colors, Neuton/Lato type, rounded card language, and spacing feel. Its phone frame, demo tab switcher, and mock sample content were not reused.
- The University of Ibadan seal SVG from the prototype is reused for the portal brand and home banner. Its ring text uses opaque high-contrast text; its orbit animation respects reduced-motion preferences.
- Session identity and profile fields come from `auth/me.php`. Mock sign-in only accepts the fake test account above. The quiet Admin login link points to the existing `../admin/` area.
- Fees are requested from `quote.php` before review. Payment always calls `payments/verify.php` before the UI reports success. Status-based controls are rendered only from each transcript's `allowedActions`.
- Offline and failed states keep a clear retry action. Loading regions reserve their dimensions with skeletons; reduced-motion preferences disable animation.

## HCI decisions

| Principle | Concrete choice |
|---|---|
| Visibility of system status | Page skeletons, fee quote progress, disabled payment button, transcript status text, live toasts, and explicit payment verification feedback. |
| Match to real world | Student language such as “Units registered”, “Personal copy”, and “Sent to”; ISO dates are formatted for Africa/Lagos. |
| User control and freedom | Clear back/edit steps, logout without a confirmation, and confirmation before payment or cancellation. |
| Consistency and standards | Familiar side/bottom navigation, links, form controls, and one status label/color treatment across pages. |
| Error prevention | Existing session/semester choices only, quote before request creation, duplicate destination feedback, and server supplied allowed actions. |
| Recognition over recall | Request destination and status are visible; the most recent result selection is remembered. |
| Flexibility and efficiency | Desktop and mobile navigation, keyboard-operable controls, and primary actions available at the current screen. |
| Minimalism | One main action per step, progressive institution fields, and a separate review screen before creating a request. |
| Error recovery | Plain-language messages include retry, sign-in, edit, or contact Registry next steps. |
| Fitts’s law | Main controls and mobile tabs are at least 44px high; primary actions are full-width on mobile. |
| Hick’s law | Destination has two choices; delivery details appear after institution selection; quote review is a separate step. |
| Miller’s law | Request review groups destination, delivery, copies, and fee into a short summary. |
| Doherty threshold | Immediate button feedback and mock responses under 400ms under normal conditions; long waits show progress. |
| Gestalt grouping | Related data shares cards and spacing; independent page sections are separated with whitespace and headings. |
| WCAG 2.2 AA | Semantic landmarks, labels, visible focus, keyboard actions, aria-live feedback, non-color status labels, contrast-safe text, and reduced motion. |

## Loopholes fixed

| Problem in the prototype/request scan | Fix in the frontend/contract |
|---|---|
| Phone-only frame and demo screen switcher | Real responsive shell, hash routes, desktop navigation, and mobile bottom tabs. |
| Prototype hardcoded grade chips only A–C | Single A–F grading constant and visible legend. |
| Degree class and course/GPA details embedded in sample UI | All academic values are returned by the API; frontend formats them only. |
| “Pending payment”, “Requested”, “Approved”, and “Ready” used inconsistently | One documented ten-value status vocabulary and status.js copy. |
| Transcript request had no form or fee source | Destination, delivery, copies, live quote, review, and creation flow. |
| Pay button could be double-clicked or imply success from a redirect | Button lock, idempotency key, server verification, and only then a paid message. |
| Every ready record showed download | Actions use `allowedActions`; institution-bound records show “Sent to …”; PDFs are authenticated blobs. |
| No cancel/rejection/failure/expiry recovery | Added statuses, next steps, cancellation confirmation, and rejection reason support. |
| Admin entry competed with the student task | Admin login is a quiet secondary link to the existing area. |
| Fixed/low-contrast opacity copy and small targets | High-contrast tokens, 16px body text, and 44px controls. |
| No session recovery, network handling, or expensive request protection | Session resume, timeout/offline feedback, request de-duplication, private in-memory cache, and no cached downloads. |
| Public password recovery conflicted with POST CSRF requirement | Added `auth/csrf.php` to bootstrap an anonymous session and token before public POSTs. |

## Endpoint summary

All requests are same-origin relative to `public/`. See [the full API contract](../docs/api-contract.md) for bodies, responses, errors, and backend requirements.

| Area | Endpoints |
|---|---|
| Session and recovery | `GET auth/csrf.php`, `POST auth/login.php`, `GET auth/me.php`, `POST auth/logout.php`, `POST auth/password-reset-request.php`, `POST auth/password-reset.php` |
| Results | `GET results/options.php`, `GET results/index.php?session=…&semester=…` |
| Transcript request | `GET transcripts/index.php`, `GET transcripts/quote.php`, `POST transcripts/create.php`, `POST transcripts/cancel.php`, `GET transcripts/download.php` |
| Payment | `POST transcripts/pay.php`, `POST payments/verify.php`, `GET payments/receipt.php` |

## Manual review checklist

- [ ] Check layout at 360px and 1280px; confirm no page-level horizontal overflow.
- [ ] Complete login, forgot-password request, and password reset with keyboard only; check focus visibility and field error announcements.
- [ ] Use a slow network; confirm skeletons/progress remain visible and actions cannot submit twice.
- [ ] Use `?mock=expired`; sign in and confirm the app resumes the page/route that was open.
- [ ] Use `?mock=offline` and `?mock=error`; confirm a clear recovery message and retry action.
- [ ] Use `?mock=empty`; confirm the results and transcript empty states.
- [ ] Create a personal request and an institution request; verify current quote, review, and success state.
- [ ] Double-click Pay; confirm only one payment intent starts and the UI waits for verification.
- [ ] Cancel an unpaid request and check that paid requests do not offer cancellation.
- [ ] Check A–F grades, carry-over, units registered vs earned, pending release, and empty semester.
- [ ] Confirm only allowed transcript actions appear; download uses an authenticated PDF blob.
- [ ] Check Windows high contrast/keyboard focus and `prefers-reduced-motion` behavior.
