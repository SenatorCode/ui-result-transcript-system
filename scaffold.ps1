# Run from the repo root:  powershell -ExecutionPolicy Bypass -File .\scaffold.ps1
# Creates the 19 working files + plumbing from the Backend Build Plan (section 11).
# Existing files are never overwritten.

$ErrorActionPreference = "Stop"
$utf8 = New-Object System.Text.UTF8Encoding($false)

function New-Stub($path, $content) {
    if (Test-Path $path) { Write-Host "skip   $path"; return }
    $dir = Split-Path $path -Parent
    if ($dir -and -not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    [System.IO.File]::WriteAllText((Join-Path (Get-Location) $path), ($content -replace "`r?`n", "`n"), $utf8)
    Write-Host "create $path"
}

function New-ClassFile($path, $owner, $classes) {
    $body = "<?php`n// Owner: Dev $owner`n"
    foreach ($c in $classes) {
        $body += "`nclass $c`n{`n    // TODO: implement per docs/BUILD_PLAN.md`n}`n"
    }
    New-Stub $path $body
}

function New-Routes($path, $owner) {
    New-Stub $path "<?php`n// Owner: Dev $owner`n// Returns ['METHOD path' => callable], e.g. 'POST auth/login' => function () { ... }`nreturn [`n];`n"
}

# ---- Plumbing (not counted) ----
New-Stub ".github/CODEOWNERS" @"
# Replace @devA, @devB, @devC with real GitHub usernames
/classes/Core.php        @devA
/classes/Academics.php   @devA
/classes/Documents.php   @devA
/classes/vendor/         @devA
/api/bootstrap.php       @devA
/api/index.php           @devA
/api/routes_student.php  @devA
/api/.htaccess           @devA
/README.md               @devA
/config.example.php      @devA
/classes/Security.php    @devB
/classes/Student.php     @devB
/classes/Transcript.php  @devB
/classes/Payment.php     @devB
/api/routes_account.php  @devB
/classes/Admin.php       @devC
/api/routes_admin.php    @devC
/admin/                  @devC
"@
New-Stub ".editorconfig" @"
root = true

[*]
indent_style = space
indent_size = 4
end_of_line = lf
charset = utf-8
insert_final_newline = true
trim_trailing_whitespace = true
"@
New-Stub ".gitignore" "config.php`nstorage/logs/*`n!storage/logs/.gitkeep`n.DS_Store`n.vscode/`n"
New-Stub "storage/logs/.gitkeep" ""
New-Stub "api/.htaccess" @"
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !index.php
RewriteRule ^(.+)\.php$ index.php [L,QSA]
"@

# ---- Dev A (8 files) ----
New-Stub "README.md" "# UI Digital Examination Result and Transcript Payment System`n`nSetup, import order, how to run tests, and working rules (see docs/BUILD_PLAN.md section 10).`n`nXAMPP: set AllowOverride All for htdocs so api/.htaccess works.`n"
New-Stub "config.example.php" @"
<?php
// Copy to config.php (never commit). Owner: Dev A
return [
    'DB_HOST' => '127.0.0.1',
    'DB_NAME' => '',
    'DB_USER' => '',
    'DB_PASS' => '',
    'TRANSCRIPT_FEE' => 0,
    'COURIER_SURCHARGE' => 0,
    'CURRENCY' => 'NGN',
    'CURRENT_SESSION' => '2025/2026',
    'REQUEST_PAYMENT_HOURS' => 24,
    'PAYMENT_MODE' => 'simulated',
    'PAYSTACK_SECRET_KEY' => '',
    'PAYSTACK_PUBLIC_KEY' => '',
    'RESET_LOG_PATH' => __DIR__ . '/storage/logs/reset-links.log',
];
"@
New-Stub "api/bootstrap.php" @"
<?php
// Owner: Dev A. Loads config, class files, headers, session, error handler.
`$root = dirname(__DIR__);
foreach (['Core', 'Security', 'Academics', 'Student', 'Transcript', 'Payment', 'Documents', 'Admin'] as `$f) {
    require_once `$root . "/classes/`$f.php";
}
"@
New-Stub "api/index.php" "<?php`n// Owner: Dev A. Front controller: bootstrap, then Router::dispatch().`nrequire_once __DIR__ . '/bootstrap.php';`n"
New-Routes "api/routes_student.php" "A"
New-ClassFile "classes/Core.php" "A" @("Config","Database","Response","Request","ApiException","IdCodec","Router")
New-ClassFile "classes/Academics.php" "A" @("Course","Result","GpaCalculator","StudentProfile")
New-ClassFile "classes/Documents.php" "A" @("TranscriptData","TranscriptPdf","ReceiptPdf")
New-Stub "classes/vendor/.gitkeep" ""   # drop fpdf.php here

# ---- Dev B (5 files) ----
New-Routes "api/routes_account.php" "B"
New-ClassFile "classes/Security.php" "B" @("Session","Csrf","RateLimiter","Idempotency","Auth")
New-ClassFile "classes/Student.php" "B" @("Student","PasswordReset")
New-ClassFile "classes/Transcript.php" "B" @("TranscriptStatus","Transcript","FeeCalculator")
New-ClassFile "classes/Payment.php" "B" @("Payment","PaymentGateway","SimulatedGateway","PaystackGateway")

# ---- Dev C (5 files) ----
New-Routes "api/routes_admin.php" "C"
New-ClassFile "classes/Admin.php" "C" @("Admin","AdminReport","TranscriptIssuer")
New-Stub "admin/index.html" @"
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Registry Admin</title>
  <link rel="stylesheet" href="admin.css">
</head>
<body>
  <!-- Views: login, #/dashboard, #/requests, #/request/<id>, #/payments -->
  <div id="app"></div>
  <script src="admin.js"></script>
</body>
</html>
"@
New-Stub "admin/admin.js" "// Owner: Dev C. Fetch wrapper (CSRF header, envelope unwrap, 401 to login) and hash view switcher.`n"
New-Stub "admin/admin.css" "/* Owner: Dev C. Shared admin styles. */`n"

# ---- Shared (1 file) ----
New-Stub "tests/run_all.php" @"
<?php
// Shared runner. Edit only your own section.
// ===== A: GPA / CGPA =====
// ===== B: state machine, payment verify, password reset =====
// ===== C: admin flow =====
"@

# ---- Docs folders (contents come from the team) ----
foreach ($d in @("docs/postman", "database", "public")) {
    if (-not (Test-Path $d)) { New-Item -ItemType Directory -Path $d -Force | Out-Null; Write-Host "create $d/" }
}

Write-Host "`nDone. Add classes/vendor/fpdf.php, then commit as the day-1 skeleton PR."