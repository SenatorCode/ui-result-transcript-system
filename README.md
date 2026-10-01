# UI Result & Transcript Payment System

A web portal where University of Ibadan students log in to view results,
calculate GPA, request transcripts, pay the fee, and download/print them.
Includes an administrator dashboard.

IFT302 coursework, Group 12.

## Tech Stack
HTML, CSS, JavaScript (async fetch) · PHP (OOP, PDO) · MySQL

## Local Setup
1. Install XAMPP (Apache, PHP, MySQL).
2. Clone this repo into XAMPP's `htdocs` folder.
3. Start Apache and MySQL in the XAMPP control panel.
4. In phpMyAdmin, create a database named `transcript_system` and import
   the SQL files in `/database`.
5. Copy `config.example.php` to `config.php` and fill in your values.
6. Open `http://localhost/ui-result-transcript-system/public/`

## Folders
- `api/` PHP endpoints that return JSON
- `classes/` Database, Student, Course, Result, Transcript, Payment
- `public/` HTML, CSS, JS
- `admin/` administrator dashboard
- `database/` SQL files
- `docs/` notes and the final report

Please read CONTRIBUTING.md before you start.