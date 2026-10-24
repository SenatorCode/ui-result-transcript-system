# UI Result & Transcript Payment System

A web portal where University of Ibadan students log in to view results,
calculate GPA, request transcripts, pay the fee, and download/print them.
Includes an administrator dashboard.

IFT302 coursework, Group 12.

## Tech Stack
HTML, CSS, JavaScript (async fetch) · PHP 8 (OOP, PDO) · MySQL / MariaDB

## Local Setup
1. Install XAMPP (Apache, PHP 8, MySQL). Start Apache and MySQL in the XAMPP control panel.
2. Clone this repo into XAMPP's `htdocs` folder, so it lives at `htdocs/ui-result-transcript-system`.
3. In phpMyAdmin, create a database named `transcript_system` (utf8mb4) and import the SQL files
   **in this order**: `database/001_schema.sql`, `002_seed_data.sql`, `003_backend_alignment.sql`,
   `004_seed_update.sql`.
4. Copy `config.example.php` to `config.php` and change only what differs on your machine
   (usually `db_user` and `db_pass`). `config.php` is private: never commit it.
5. Check `mod_rewrite` is on and `.htaccess` is allowed (see Troubleshooting).
6. Open `http://localhost/ui-result-transcript-system/api/nothing.php`. You should see JSON
   `{"error":{"code":"not_found",...}}`. That means the API is working.
7. Open `http://localhost/ui-result-transcript-system/public/`.

Test logins: students use their matric number (e.g. `CSC/2023/001`) and `Test@1234`;
admins use `registry.admin` or `finance.admin` and `Admin@1234`. These are seed data only.

## Running the tests
```
php tests/run_all.php          # everything
php tests/run_all.php gpa      # only tests with "gpa" in the name
```
Needs the database imported. The HTTP tests need Apache running (set `API_BASE` in your
environment if your URL differs). Tests that cannot run yet are skipped, not failed.
Everyone edits only their own section of `tests/run_all.php`.

## Folders
- `api/` PHP endpoints that return JSON (`index.php` is the front controller; `routes_*.php` list the routes)
- `classes/` Core (Config, Database, Response, Request, Router), Academics (results and GPA),
  Documents (PDFs), Security, Student, Transcript, Payment, Admin
- `public/` HTML, CSS, JS (student pages)
- `admin/` administrator dashboard
- `database/` SQL files (change the database only by adding a new numbered file)
- `docs/` API contracts, build plan, database notes
- `tests/` the shared test runner
- `storage/logs/` error logs (not committed)

## Who owns what
One file, one owner. Do not edit another person's file; ask them or open an issue.
`.github/CODEOWNERS` lists the owners and GitHub asks them to review.

## Working rules
- Branch from `dev` as `feature/<area>-<name>`. Never push straight to `dev` or `main`.
- Open a pull request, get one review, squash merge.
- Never commit `config.php`, passwords or keys.
- Student ids come from the session, never from the browser. Use prepared statements only.
- Read `CONTRIBUTING.md` before you start.

## Troubleshooting
- **Every API URL gives an Apache 404 page (not JSON):** enable `mod_rewrite` and set
  `AllowOverride All` for `htdocs` in `httpd.conf`, then restart Apache.
- **`503 service_unavailable`:** check `db_*` values in `config.php` and that MySQL is running.
- **`500 server_error`:** see `storage/logs/error.log` and `storage/logs/php-errors.log`.
- **`501 not_implemented`:** that endpoint's owner has not merged it yet.
