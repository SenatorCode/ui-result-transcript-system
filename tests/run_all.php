<?php
// Shared runner. Edit only your own section (between your two marker lines).
//
//   php tests/run_all.php            run everything
//   php tests/run_all.php gpa        run only tests whose name contains "gpa"
//
// Needs the database imported (database/001 to 004) and, for the HTTP tests, Apache running.
// Set API_BASE if your URL differs from the default below. Exit code is 1 when a test fails.

declare(strict_types=1);

require_once __DIR__ . '/../api/bootstrap.php';

// ===================================================================================
// Shared helpers (Dev A). Use these in your section; do not change them.
// ===================================================================================

final class SkipTest extends Exception
{
}

/** Registers and runs one test. $fn throws (AssertionError, Exception) to fail, or calls skip(). */
function test(string $name, callable $fn): void
{
    global $TEST_FILTER, $TEST_RESULTS;
    if ($TEST_FILTER !== '' && stripos($name, $TEST_FILTER) === false) {
        return;
    }
    try {
        $fn();
        $TEST_RESULTS['pass']++;
        echo "  PASS  $name\n";
    } catch (SkipTest $e) {
        $TEST_RESULTS['skip']++;
        echo "  SKIP  $name  ({$e->getMessage()})\n";
    } catch (Throwable $e) {
        $TEST_RESULTS['fail']++;
        $TEST_RESULTS['failures'][] = $name;
        echo "  FAIL  $name\n        " . str_replace("\n", "\n        ", $e->getMessage()) . "\n";
    }
}

function skip(string $reason): void
{
    throw new SkipTest($reason);
}

function section(string $title): void
{
    echo "\n$title\n";
}

function assert_same($expected, $actual, string $what = 'value'): void
{
    if ($expected !== $actual) {
        throw new AssertionError("$what: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assert_true($condition, string $what = 'condition'): void
{
    if ($condition !== true) {
        throw new AssertionError("$what: expected true, got " . var_export($condition, true));
    }
}

/** Passes when $fn throws an ApiException with this error code and HTTP status. */
function assert_api_error(string $code, int $status, callable $fn): void
{
    try {
        $fn();
    } catch (ApiException $e) {
        assert_same($code, $e->errorCode(), 'error code');
        assert_same($status, $e->httpStatus(), 'http status');
        return;
    }
    throw new AssertionError("expected ApiException $code ($status), but nothing was thrown");
}

/** A tiny cURL client with a cookie jar, so it behaves like a browser session. */
final class Http
{
    public ?string $csrf = null;
    private string $jar;

    public function __construct(private string $base)
    {
        $this->base = rtrim($base, '/') . '/';
        $this->jar = tempnam(sys_get_temp_dir(), 'jar');
    }

    /** @return array{status:int,headers:array<string,string>,body:string,json:?array} */
    public function request(string $method, string $path, ?array $json = null, array $headers = []): array
    {
        $ch = curl_init($this->base . ltrim($path, '/'));
        $responseHeaders = [];
        $send = ['Accept: application/json'];
        foreach ($headers as $name => $value) {
            $send[] = "$name: $value";
        }
        if ($this->csrf !== null && $method === 'POST') {
            $send[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        if ($json !== null) {
            $send[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $send,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new SkipTest("server not reachable at {$this->base} ($error)");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $decoded = json_decode($body, true);
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $body, 'json' => is_array($decoded) ? $decoded : null];
    }

    /** Signs in through the real endpoints (Dev B). Skips the calling test while they are not built. */
    public function login(string $matricNo, string $password = 'Test@1234'): void
    {
        $csrf = $this->request('GET', 'auth/csrf.php');
        if ($csrf['status'] !== 200) {
            skip("login not available yet (auth/csrf.php answered {$csrf['status']}; needs Dev B)");
        }
        $this->csrf = $csrf['json']['data']['csrfToken'];
        $login = $this->request('POST', 'auth/login.php', ['matricNo' => $matricNo, 'password' => $password]);
        if ($login['status'] !== 200) {
            skip("login not available yet (auth/login.php answered {$login['status']}; needs Dev B)");
        }
        $this->csrf = $login['json']['data']['csrfToken'] ?? $this->csrf;
    }
}

function api_base(): string
{
    return getenv('API_BASE') ?: 'http://localhost/ui-result-transcript-system/api/';
}

$TEST_FILTER = $argv[1] ?? '';
$TEST_RESULTS = ['pass' => 0, 'fail' => 0, 'skip' => 0, 'failures' => []];

// ===== A: GPA / CGPA =====
// Dev A. Hand-checked against the seed data (database/002 + 004).
//
// Student 1 (CSC/2023/001), by hand. Points = units x grade point.
//   2023/2024 First   CSC101 3A 15, GST111 2A 10, MTH101 3B 12, STA111 3A 15   = 52 / 11 units = 4.73
//   2023/2024 Second  CSC102 3A 15, GST112 2B 8,  MTH102 3C 9                  = 32 / 8        = 4.00
//   2024/2025 First   CSC201 3A 15, CSC203 3B 12, MTH201 3B 12                 = 39 / 9        = 4.33
//   2024/2025 Second  CSC202 3B 12, CSC204 3F 0,  CSC206 3A 15, STA202 3C 9    = 36 / 12       = 3.00
//   2025/2026 First   CSC301 3A 15, CSC303 3A 15                               = 30 / 6        = 5.00
//   2025/2026 Second  CSC204 3A 15 (retake), CSC302 3A 15, CSC304 3A 15        = 45 / 9        = 5.00
// CGPA as of 2024/2025 Second = (52+32+39+36) / 40 = 159 / 40 = 3.975 -> 3.98 (rounds half up).
// Overall: the F attempt of CSC 204 stops counting once it is retaken: 234 / 52 = 4.50,
// while the 55 units on the record still include that attempt.
section('GPA and CGPA (Dev A)');

test('gpa: id codec round trip and canonical form only', function () {
    assert_same('tr_1001', IdCodec::encode('tr', 1));
    assert_same('st_01', IdCodec::encode('st', 1));
    assert_same('ad_12', IdCodec::encode('ad', 12));
    assert_same(1, IdCodec::decode('tr', 'tr_1001'));
    assert_same(11, IdCodec::decode('tr', 'tr_1011'));
    foreach (['tr_01001', 'tr_1000', 'tr_0001', 'tr_', 'st_01', 'tr_1001x', '', "tr_1001\n1"] as $bad) {
        assert_api_error('not_found', 404, fn () => IdCodec::decode('tr', $bad));
    }
});

test('gpa: unknown config key throws, local override works, defaults are present', function () {
    assert_same('simulated', Config::get('PAYMENT_MODE'));
    assert_same('fallback', Config::get('NOT_A_REAL_KEY', 'fallback'));
    $thrown = false;
    try {
        Config::get('NOT_A_REAL_KEY');
    } catch (LogicException $e) {
        $thrown = true;
    }
    assert_true($thrown, 'unknown key throws');
});

test('gpa: degree class bands and their edges', function () {
    $cases = [
        5.00 => 'First Class Honours', 4.50 => 'First Class Honours',
        4.49 => 'Second Class Honours (Upper Division)', 3.50 => 'Second Class Honours (Upper Division)',
        3.49 => 'Second Class Honours (Lower Division)', 2.40 => 'Second Class Honours (Lower Division)',
        2.39 => 'Third Class Honours', 1.50 => 'Third Class Honours',
        1.49 => 'Pass', 1.00 => 'Pass',
        0.99 => 'Not classified', 0.00 => 'Not classified',
    ];
    foreach ($cases as $cgpa => $expected) {
        assert_same($expected, GpaCalculator::degreeClass((float) $cgpa), "degree class for $cgpa");
    }
});

test('gpa: gpaOf rounds half up from whole tenths and handles no results', function () {
    assert_same(0.0, GpaCalculator::gpaOf([]), 'empty');
    // 3 units of A and 5 units of C = (15 + 15) / 8 = 3.75
    assert_same(3.75, GpaCalculator::gpaOf([['units' => 3, 'gp10' => 50], ['units' => 5, 'gp10' => 30]]));
    // 159 / 40 = 3.975 must become 3.98, never 3.97
    assert_same(3.98, GpaCalculator::gpaOf([['units' => 30, 'gp10' => 40], ['units' => 10, 'gp10' => 39]]));
});

test('gpa: semester GPA for student 1 matches the hand calculation', function () {
    $expected = [
        ['2023/2024', 'First Semester', 4.73], ['2023/2024', 'Second Semester', 4.0],
        ['2024/2025', 'First Semester', 4.33], ['2024/2025', 'Second Semester', 3.0],
        ['2025/2026', 'First Semester', 5.0], ['2025/2026', 'Second Semester', 5.0],
    ];
    foreach ($expected as [$session, $semester, $gpa]) {
        assert_same($gpa, GpaCalculator::semesterGpa(1, $session, $semester), "GPA $session $semester");
    }
    assert_same(0.0, GpaCalculator::semesterGpa(2, '2025/2026', 'Second Semester'), 'no results');
});

test('gpa: CGPA as of each semester for student 1 (a later retake never rewrites the past)', function () {
    $expected = [
        ['2023/2024', 'First Semester', 4.73],   // 52 / 11
        ['2023/2024', 'Second Semester', 4.42],  // 84 / 19
        ['2024/2025', 'First Semester', 4.39],   // 123 / 28
        ['2024/2025', 'Second Semester', 3.98],  // 159 / 40, the build plan's checked value
        ['2025/2026', 'First Semester', 4.11],   // 189 / 46, the F is still the latest attempt
        ['2025/2026', 'Second Semester', 4.5],   // 234 / 52, the retake replaces the F
    ];
    foreach ($expected as [$session, $semester, $cgpa]) {
        assert_same($cgpa, GpaCalculator::cgpaAsOf(1, $session, $semester), "CGPA as of $session $semester");
    }
    assert_same(0.0, GpaCalculator::cgpaAsOf(1, '2030/2031', 'First Semester'), 'unknown semester');
});

test('gpa: overall CGPA and total units for the issue snapshot', function () {
    assert_same(4.5, GpaCalculator::overallCgpa(1), 'overall CGPA (latest attempts only)');
    assert_same(55, GpaCalculator::totalUnitsCounted(1), 'units on the record, retake included');
    assert_same(0.0, GpaCalculator::overallCgpa(7), 'student with no results');
    assert_same(0, GpaCalculator::totalUnitsCounted(7), 'student with no results');
});

test('gpa: PHP results agree with the SQL views for every seed student', function () {
    $students = Database::fetchAll('SELECT id FROM students ORDER BY id');
    $checked = 0;
    foreach ($students as $s) {
        $id = (int) $s['id'];
        foreach (Database::fetchAll('SELECT session, semester, gpa FROM v_semester_gpa WHERE student_id = ?', [$id]) as $row) {
            assert_same((float) $row['gpa'], GpaCalculator::semesterGpa($id, $row['session'], $row['semester']), "student $id {$row['session']} {$row['semester']}");
            $checked++;
        }
        $counted = Database::fetchOne('SELECT ROUND(SUM(quality_points) / SUM(unit), 2) AS cgpa FROM v_counted_results WHERE student_id = ?', [$id]);
        assert_same((float) ($counted['cgpa'] ?? 0), GpaCalculator::overallCgpa($id), "student $id overall CGPA");
        $units = Database::fetchOne('SELECT COALESCE(SUM(unit), 0) AS u FROM v_result_details WHERE student_id = ?', [$id]);
        assert_same((int) $units['u'], GpaCalculator::totalUnitsCounted($id), "student $id units");
    }
    assert_true($checked >= 10, 'checked at least 10 student semesters');
});

test('results: options are newest first and only list the student\'s own pairs', function () {
    $pairs = Result::optionsFor(1);
    assert_same(6, count($pairs), 'six semesters');
    assert_same(['session' => '2025/2026', 'semester' => 'Second Semester', 'released' => true], $pairs[0]);
    assert_same(['session' => '2023/2024', 'semester' => 'First Semester', 'released' => true], $pairs[5]);
    assert_same([], Result::optionsFor(7), 'student with no results gets an empty list');
});

test('results: 2025/2026 Second Semester for CSC/2023/001 (the brief\'s example)', function () {
    $report = Result::report(1, '2025/2026', 'Second Semester');
    assert_same('CSC/2023/001', $report['student']);
    assert_same('released', $report['availability']);
    $codes = array_column($report['courses'], 'code');
    assert_same(['CSC 204', 'CSC 302', 'CSC 304'], $codes);
    $csc204 = $report['courses'][0];
    assert_same('A', $csc204['grade']);
    assert_same(2, $csc204['attempt'], 'CSC 204 is a retake');
    assert_same(true, $csc204['carryOver']);
    assert_same(3, $csc204['unitsEarned']);
    assert_same(5, (int) $csc204['gradePoint']);
    assert_same(['gpa' => 5.0, 'cgpa' => 4.5, 'unitsRegistered' => 9, 'unitsEarned' => 9, 'degreeClass' => 'First Class Honours'], $report['summary']);
});

test('results: the failed first attempt shows earned 0 and the CGPA of that time', function () {
    $report = Result::report(1, '2024/2025', 'Second Semester');
    $csc204 = $report['courses'][1];
    assert_same('CSC 204', $csc204['code']);
    assert_same('F', $csc204['grade']);
    assert_same(1, $csc204['attempt']);
    assert_same(false, $csc204['carryOver']);
    assert_same(0, $csc204['unitsEarned']);
    assert_same(['gpa' => 3.0, 'cgpa' => 3.98, 'unitsRegistered' => 12, 'unitsEarned' => 9, 'degreeClass' => 'Second Class Honours (Upper Division)'], $report['summary']);
});

test('results: a pair the student does not have is a 404, not an empty page', function () {
    assert_api_error('not_found', 404, fn () => Result::report(2, '2025/2026', 'Second Semester')); // CSC/2023/002
    assert_api_error('not_found', 404, fn () => Result::report(7, '2025/2026', 'First Semester'));  // no results at all
    assert_api_error('not_found', 404, fn () => Result::report(1, '2030/2031', 'First Semester'));
    assert_same('empty', Result::availability(2, '2025/2026', 'Second Semester'));
    assert_same('released', Result::availability(1, '2025/2026', 'Second Semester'));
});

test('results: the response is JSON-safe (numbers stay numbers)', function () {
    $json = json_encode(Result::report(1, '2024/2025', 'First Semester'), JSON_THROW_ON_ERROR);
    $decoded = json_decode($json, true);
    assert_same(4.33, $decoded['summary']['gpa']);
    assert_true(is_int($decoded['courses'][0]['units']), 'units is an integer');
    assert_true(is_bool($decoded['courses'][0]['carryOver']), 'carryOver is a boolean');
});

test('profile: student 1 profile has department, faculty, level and entry session', function () {
    $profile = StudentProfile::find(1);
    assert_same('st_01', $profile['id']);
    assert_same('CSC/2023/001', $profile['matricNo']);
    assert_same('2023/2024', $profile['entrySession']);
    assert_true(is_int($profile['level']), 'level is an integer');
    foreach (['fullName', 'email', 'faculty', 'department', 'status', 'phone', 'dateOfBirth'] as $key) {
        assert_true(array_key_exists($key, $profile), "has $key");
    }
    assert_same(null, StudentProfile::find(9999), 'unknown id');
});

test('profile: course lookup', function () {
    $course = Course::find(1);
    assert_true($course !== null && is_int($course['units']), 'course 1 exists with integer units');
    assert_same(null, Course::find(99999));
});

// ===== A: documents (transcript data, transcript PDF, receipt PDF) =====
section('Documents (Dev A)');

test('documents: issued transcript uses the stored snapshot', function () {
    $data = TranscriptData::build(1); // TR-2026-000001, ready, student 1
    assert_same(true, $data['issued']);
    assert_same('UITR-2026-000001', $data['transcriptNo']);
    assert_same(55, $data['summary']['totalUnits']);
    assert_same(4.5, $data['summary']['cgpa']);
    assert_same('First Class Honours', $data['summary']['degreeClass']);
    assert_same(3, count($data['sessions']));
    assert_same('CSC/2023/001', $data['student']['matricNo']);
    assert_same('Personal copy', $data['destination']['label']);
});

test('documents: a request not yet issued shows the transcript number as pending', function () {
    $data = TranscriptData::build(5); // TR-2026-000005, processing
    assert_same(false, $data['issued']);
    assert_same(null, $data['transcriptNo']);
    assert_true(count($data['sessions']) > 0, 'live results are shown');
});

test('documents: a session-scoped request lists only that session', function () {
    $data = TranscriptData::build(7); // TR-2026-000007, ready, scope = session
    assert_same('session', $data['scope']);
    assert_same(1, count($data['sessions']));
    assert_same(null, $data['summary']['degreeClass'], 'no degree class for one session');
});

test('documents: institution copy names the institution', function () {
    $data = TranscriptData::build(8); // TR-2026-000008, sent by email
    assert_same('Example University', $data['destination']['label']);
    assert_same('email', $data['destination']['deliveryMethod']);
    assert_same(2, $data['destination']['copies']);
});

test('documents: an unknown request is a 404', function () {
    assert_api_error('not_found', 404, fn () => TranscriptData::build(99999));
});

test('documents: transcript PDF is a real multi-section PDF', function () {
    $pdf = TranscriptPdf::render(TranscriptData::build(1));
    assert_same('%PDF-', substr($pdf, 0, 5));
    assert_true(str_contains(substr($pdf, -16), '%%EOF'), 'ends with %%EOF');
    assert_true(strlen($pdf) > 2500, 'has content');
    $pending = TranscriptPdf::render(TranscriptData::build(5));
    assert_same('%PDF-', substr($pending, 0, 5));
});

test('documents: receipt PDF renders with full and with missing data', function () {
    $full = ReceiptPdf::render([
        'receiptNumber' => 'RCPT-2026-1001', 'amount' => 5000, 'currency' => 'NGN', 'paidAt' => '2026-10-10T08:45:00Z',
        'reference' => 'PAY-2026-000001', 'requestNo' => 'TR-2026-000001', 'studentName' => 'Ada Okafor',
        'matricNo' => 'CSC/2023/001', 'destination' => 'Personal copy', 'copies' => 1,
    ]);
    assert_same('%PDF-', substr($full, 0, 5));
    assert_same('%PDF-', substr(ReceiptPdf::render([]), 0, 5));
});

test('documents: text with accents and symbols does not break the PDF', function () {
    assert_same("Caf\xE9", PortalPdf::latin1('Café'));
    assert_same('A?B', PortalPdf::latin1("A\u{2603}B") === 'A?B' ? 'A?B' : PortalPdf::latin1("A\u{2603}B"));
});

// ===== A: HTTP (needs Apache running; logged-in checks wait for Dev B's login) =====
section('HTTP (Dev A)');

test('http: unknown endpoint answers a JSON 404 (proves the rewrite and front controller work)', function () {
    $r = (new Http(api_base()))->request('GET', 'does/not-exist.php');
    assert_same(404, $r['status']);
    assert_same('not_found', $r['json']['error']['code'] ?? null);
    assert_true(is_array($r['json']['error']['fields'] ?? null), 'fields is present');
});

test('http: URLs that end in index.php are routed, not served by Apache as missing files', function () {
    $http = new Http(api_base());
    foreach (['results/index.php?session=2025%2F2026&semester=First%20Semester', 'transcripts/index.php'] as $path) {
        $r = $http->request('GET', $path);
        assert_true($r['json'] !== null, "$path answers JSON (got HTTP {$r['status']})");
        assert_true(isset($r['json']['error']), "$path answers the error envelope while logged out");
    }
});

test('http: wrong method is a 405 with an Allow header', function () {
    $r = (new Http(api_base()))->request('POST', 'results/options.php', []);
    assert_same(405, $r['status']);
    assert_same('GET', $r['headers']['allow'] ?? null);
    assert_same('method_not_allowed', $r['json']['error']['code'] ?? null);
});

test('http: logged out, results answer 401 (or 501 until Dev B ships auth)', function () {
    $r = (new Http(api_base()))->request('GET', 'results/options.php');
    assert_true(in_array($r['status'], [401, 501], true), "status was {$r['status']}");
});

test('http: results/options for student 1 lists six pairs, newest first', function () {
    $http = new Http(api_base());
    $http->login('CSC/2023/001');
    $r = $http->request('GET', 'results/options.php');
    assert_same(200, $r['status']);
    assert_same(6, count($r['json']['data']['pairs']));
    assert_same('2025/2026', $r['json']['data']['pairs'][0]['session']);
});

test('http: results/index returns the brief\'s example for CSC/2023/001', function () {
    $http = new Http(api_base());
    $http->login('CSC/2023/001');
    $r = $http->request('GET', 'results/index.php?session=' . rawurlencode('2025/2026') . '&semester=' . rawurlencode('Second Semester'));
    assert_same(200, $r['status']);
    $data = $r['json']['data'];
    assert_same('CSC/2023/001', $data['student']);
    assert_same('CSC 204', $data['courses'][0]['code']);
    assert_same('A', $data['courses'][0]['grade']);
    assert_same(4.5, $data['summary']['cgpa']);
});

test('http: results/index validation and 404', function () {
    $http = new Http(api_base());
    $http->login('CSC/2023/002');
    $missing = $http->request('GET', 'results/index.php');
    assert_same(422, $missing['status']);
    assert_true(isset($missing['json']['error']['fields']['session']), 'session field error');
    $none = $http->request('GET', 'results/index.php?session=' . rawurlencode('2025/2026') . '&semester=' . rawurlencode('Second Semester'));
    assert_same(404, $none['status']);
});

test('http: a student only ever sees their own results', function () {
    $http = new Http(api_base());
    $http->login('CSC/2023/002'); // has no 2025/2026 Second Semester results; student 1 does
    $r = $http->request('GET', 'results/index.php?session=' . rawurlencode('2025/2026') . '&semester=' . rawurlencode('Second Semester') . '&student=CSC/2023/001&studentId=1');
    assert_same(404, $r['status']);
});

test('http: student/profile returns the logged-in student only', function () {
    $http = new Http(api_base());
    $http->login('CSC/2023/001');
    $r = $http->request('GET', 'student/profile.php');
    assert_same(200, $r['status']);
    assert_same('CSC/2023/001', $r['json']['data']['student']['matricNo']);
});

test('http: download streams a PDF for a ready personal copy and refuses others', function () {
    $http = new Http(api_base());
    $http->login('CSC/2023/001');
    $ok = $http->request('GET', 'transcripts/download.php?id=tr_1001', null, ['Accept' => 'application/pdf']);
    if ($ok['status'] === 501) {
        skip('download needs Dev B\'s Transcript::findOwned and canDownload');
    }
    assert_same(200, $ok['status']);
    assert_same('application/pdf', $ok['headers']['content-type'] ?? null);
    assert_same('%PDF-', substr($ok['body'], 0, 5));
    assert_true(str_contains($ok['headers']['content-disposition'] ?? '', 'attachment'), 'sent as an attachment');
    $foreign = $http->request('GET', 'transcripts/download.php?id=tr_1005', null, ['Accept' => 'application/pdf']); // belongs to student 4
    assert_same(404, $foreign['status']);
    assert_same('not_found', $foreign['json']['error']['code'] ?? null);
    $notReady = $http->request('GET', 'transcripts/download.php?id=tr_1002', null, ['Accept' => 'application/pdf']); // student 2's, pending payment
    assert_true(in_array($notReady['status'], [404, 409], true), "someone else's or unpaid request is refused (HTTP {$notReady['status']})");
});

// ===== B: state machine, payment verify, password reset =====
section('Security foundation (Dev B)');

function b_cleanup(): void
{
    Database::execute("DELETE FROM login_attempts WHERE identifier LIKE 'TEST/%' OR identifier LIKE 'ADMIN:TEST%'");
    Database::execute("DELETE FROM idempotency_keys WHERE idem_key LIKE 'test-%'");
}

test('security: rate limiter blocks the 6th failure with 429 and retryAfterSeconds', function () {
    b_cleanup();
    for ($i = 0; $i < 5; $i++) {
        RateLimiter::assertAllowed('login', 'test/rl/001');
        RateLimiter::recordFailure('login', 'test/rl/001');
    }
    try {
        RateLimiter::assertAllowed('login', 'test/rl/001');
        throw new AssertionError('expected 429');
    } catch (ApiException $e) {
        assert_same('rate_limited', $e->errorCode());
        assert_same(429, $e->httpStatus());
        assert_true($e->fields()['retryAfterSeconds'] > 0, 'retryAfterSeconds is set');
    }
    RateLimiter::assertAllowed('login', 'test/rl/002');        // another identifier is unaffected
    RateLimiter::assertAllowed('admin', 'test/rl/001');        // admin counter is separate
    RateLimiter::clear('login', 'test/rl/001');
    RateLimiter::assertAllowed('login', 'test/rl/001');        // cleared after a good login
    b_cleanup();
});

test('security: guards accept only real, active accounts', function () {
    $_SESSION = [];
    assert_api_error('session_expired', 401, fn () => Auth::requireStudent());
    assert_api_error('session_expired', 401, fn () => Auth::requireAdmin());
    $_SESSION = ['student_id' => 1];
    assert_same(1, Auth::requireStudent());
    assert_api_error('session_expired', 401, fn () => Auth::requireAdmin()); // student session is not admin
    $_SESSION = ['student_id' => 8];                                         // CSC/2024/003 is suspended
    assert_api_error('session_expired', 401, fn () => Auth::requireStudent());
    $_SESSION = ['admin_id' => 1];
    assert_same(1, Auth::requireAdmin());
    assert_api_error('session_expired', 401, fn () => Auth::requireStudent());
    $_SESSION = ['student_id' => 99999];
    assert_api_error('session_expired', 401, fn () => Auth::requireStudent());
    $_SESSION = [];
});

test('security: idempotency replays, detects conflicts and releases a failed claim', function () {
    b_cleanup();
    $_SESSION = ['student_id' => 1];
    $calls = 0;
    $fn = function () use (&$calls) { $calls++; return ['id' => 'tr_9999', 'run' => $calls]; };
    $first = Idempotency::run('transcripts/create', 'test-key-1', ['copies' => 1, 'a' => 'x'], $fn);
    $again = Idempotency::run('transcripts/create', 'test-key-1', ['a' => 'x', 'copies' => 1], $fn);
    assert_same($first, $again, 'replay returns the stored result');
    assert_same(1, $calls, 'function ran once');
    assert_api_error('idempotency_conflict', 409, fn () => Idempotency::run('transcripts/create', 'test-key-1', ['copies' => 2], $fn));
    assert_api_error('validation_failed', 422, fn () => Idempotency::run('transcripts/create', null, [], $fn));
    assert_api_error('duplicate_open_request', 409, fn () => Idempotency::run('transcripts/create', 'test-key-2', [], function () {
        throw new ApiException('duplicate_open_request', 'x', 409);
    }));
    Idempotency::run('transcripts/create', 'test-key-2', [], $fn); // key was released, so this runs
    assert_same(2, $calls);
    $_SESSION = [];
    b_cleanup();
});

test('http: auth/csrf sets a safe session cookie and a stable token', function () {
    $http = new Http(api_base());
    $a = $http->request('GET', 'auth/csrf.php');
    assert_same(200, $a['status']);
    $token = $a['json']['data']['csrfToken'] ?? '';
    assert_true(preg_match('/^[a-f0-9]{64}$/', $token) === 1, 'token is 64 hex characters');
    $cookie = $a['headers']['set-cookie'] ?? '';
    assert_true(str_contains($cookie, 'UISTUSESS'), 'student cookie name');
    assert_true(stripos($cookie, 'HttpOnly') !== false, 'HttpOnly');
    assert_true(stripos($cookie, 'SameSite=Lax') !== false, 'SameSite=Lax');
    assert_true(str_contains($a['headers']['cache-control'] ?? '', 'no-store'), 'no-store');
    $b = $http->request('GET', 'auth/csrf.php');
    assert_same($token, $b['json']['data']['csrfToken'], 'same session, same token');
    $other = (new Http(api_base()))->request('GET', 'auth/csrf.php');
    assert_true($other['json']['data']['csrfToken'] !== $token, 'new session, new token');
});

test('http: admin URLs use their own cookie', function () {
    $r = (new Http(api_base()))->request('GET', 'admin/auth/csrf.php'); // 501 until Dev C ships it; cookie is set either way
    $cookie = $r['headers']['set-cookie'] ?? '';
    assert_true(str_contains($cookie, 'UIADMINSESS'), 'admin cookie name');
    assert_true(!str_contains($cookie, 'UISTUSESS'), 'not the student cookie');
});

section('Student login (Dev B)');

function b_login_cleanup(): void
{
    Database::execute("DELETE FROM login_attempts WHERE identifier LIKE 'CSC/%' OR identifier LIKE 'NOPE/%'");
}

/** A client that already holds a pre-login CSRF token. */
function b_client(): Http
{
    $http = new Http(api_base());
    $r = $http->request('GET', 'auth/csrf.php');
    if ($r['status'] !== 200) {
        skip('auth/csrf.php not available');
    }
    $http->csrf = $r['json']['data']['csrfToken'];
    return $http;
}

function b_error(array $r): ?string
{
    return $r['json']['error']['code'] ?? null;
}

test('login: POST without a CSRF token is refused', function () {
    $r = (new Http(api_base()))->request('POST', 'auth/login.php', ['matricNo' => 'CSC/2023/001', 'password' => 'Test@1234']);
    assert_same(403, $r['status']);
    assert_same('csrf_invalid', b_error($r));
});

test('login: missing fields give 422 with field messages', function () {
    $r = b_client()->request('POST', 'auth/login.php', []);
    assert_same(422, $r['status']);
    assert_true(isset($r['json']['error']['fields']['matricNo']), 'matricNo message');
    assert_true(isset($r['json']['error']['fields']['password']), 'password message');
});

test('login: wrong password and unknown matric look identical', function () {
    b_login_cleanup();
    $http = b_client();
    $wrong = $http->request('POST', 'auth/login.php', ['matricNo' => 'CSC/2023/001', 'password' => 'nope']);
    $unknown = $http->request('POST', 'auth/login.php', ['matricNo' => 'NOPE/0000/000', 'password' => 'nope']);
    assert_same(401, $wrong['status']);
    assert_same(401, $unknown['status']);
    assert_same('invalid_credentials', b_error($wrong));
    assert_same($wrong['json']['error']['message'], $unknown['json']['error']['message']);
    b_login_cleanup();
});

test('login: suspended account is 403 only with the right password', function () {
    b_login_cleanup();
    $http = b_client();
    $good = $http->request('POST', 'auth/login.php', ['matricNo' => 'CSC/2024/003', 'password' => 'Test@1234']);
    assert_same(403, $good['status']);
    assert_same('account_suspended', b_error($good));
    $bad = $http->request('POST', 'auth/login.php', ['matricNo' => 'CSC/2024/003', 'password' => 'wrong']);
    assert_same(401, $bad['status']);
    b_login_cleanup();
});

test('login: full flow (login, me, bad CSRF on logout, logout, me again)', function () {
    b_login_cleanup();
    $http = b_client();
    $before = $http->csrf;
    $login = $http->request('POST', 'auth/login.php', ['matricNo' => 'csc/2023/001', 'password' => 'Test@1234']); // lowercase on purpose
    assert_same(200, $login['status']);
    $data = $login['json']['data'];
    assert_same('st_01', $data['student']['id']);
    assert_same('CSC/2023/001', $data['student']['matricNo']);
    assert_true(!array_key_exists('password_hash', $data['student']), 'no hash in the response');
    assert_true($data['csrfToken'] !== $before, 'CSRF token rotated on login');
    $http->csrf = $data['csrfToken'];

    $me = $http->request('GET', 'auth/me.php');
    assert_same(200, $me['status']);
    assert_same('CSC/2023/001', $me['json']['data']['student']['matricNo']);
    assert_same($http->csrf, $me['json']['data']['csrfToken']);

    $good = $http->csrf;
    $http->csrf = 'bad-token';
    assert_same(403, $http->request('POST', 'auth/logout.php', [])['status'], 'logout with a bad token');
    $http->csrf = $good;
    $out = $http->request('POST', 'auth/logout.php', []);
    assert_same(200, $out['status']);
    assert_same(true, $out['json']['data']['loggedOut']);

    $after = $http->request('GET', 'auth/me.php');
    assert_same(401, $after['status']);
    assert_same('session_expired', b_error($after));
    b_login_cleanup();
});

test('login: 6th attempt is 429 with Retry-After, even with the right password', function () {
    b_login_cleanup();
    $http = b_client();
    for ($i = 0; $i < 5; $i++) {
        assert_same(401, $http->request('POST', 'auth/login.php', ['matricNo' => 'CSC/2023/003', 'password' => 'wrong'])['status']);
    }
    $r = $http->request('POST', 'auth/login.php', ['matricNo' => 'CSC/2023/003', 'password' => 'Test@1234']);
    assert_same(429, $r['status']);
    assert_same('rate_limited', b_error($r));
    assert_true(($r['json']['error']['fields']['retryAfterSeconds'] ?? 0) > 0, 'retryAfterSeconds');
    assert_true(isset($r['headers']['retry-after']), 'Retry-After header');
    b_login_cleanup();
});

test('login: a successful login clears earlier failures', function () {
    b_login_cleanup();
    $http = b_client();
    for ($i = 0; $i < 3; $i++) {
        $http->request('POST', 'auth/login.php', ['matricNo' => 'CSC/2023/002', 'password' => 'wrong']);
    }
    assert_same(200, $http->request('POST', 'auth/login.php', ['matricNo' => 'CSC/2023/002', 'password' => 'Test@1234'])['status']);
    $row = Database::fetchOne("SELECT COUNT(*) AS c FROM login_attempts WHERE identifier = 'CSC/2023/002'");
    assert_same(0, (int) $row['c']);
    b_login_cleanup();
});

section('Transcript state machine (Dev B)');

/** Inserts a test request (request_no starts with TMP-T). Students 5, 7 and 8 have no open request in the seed. */
function b_make_request(string $status = 'pending_payment', array $o = []): int
{
    $cols = array_merge([
        'request_no' => 'TMP-T' . bin2hex(random_bytes(6)),
        'student_id' => 7, 'status' => $status, 'fee_amount' => 5000,
    ], $o);
    $names = implode(', ', array_keys($cols));
    $marks = implode(', ', array_fill(0, count($cols), '?'));
    Database::execute("INSERT INTO transcript_requests ($names) VALUES ($marks)", array_values($cols));
    return Database::lastInsertId();
}

function b_institution(): array
{
    return ['destination_type' => 'institution', 'institution_name' => 'Test University',
            'delivery_method' => 'email', 'destination_email' => 'records@example.edu'];
}

function b_issue(): array
{
    return ['transcript_no' => 'TMPTN-' . bin2hex(random_bytes(5)), 'total_units' => 30,
            'cgpa' => 4.0, 'ready_at' => gmdate('Y-m-d H:i:s')];
}

function b_requests_cleanup(): void
{
    $where = "request_no LIKE 'TMP-T%' OR (student_id IN (5, 7, 8) AND id > 11)";
    Database::execute("DELETE FROM payments WHERE request_id IN (SELECT id FROM transcript_requests WHERE $where)");
    Database::execute("DELETE FROM transcript_requests WHERE $where");
}

function b_status(int $id): string
{
    return (string) Database::fetchOne('SELECT status FROM transcript_requests WHERE id = ?', [$id])['status'];
}

test('requests: every status pair is allowed or refused exactly as the table says', function () {
    $allowed = [
        'pending_payment>payment_processing', 'pending_payment>cancelled', 'pending_payment>expired',
        'payment_processing>paid', 'payment_processing>failed',
        'failed>payment_processing', 'failed>cancelled', 'failed>expired',
        'paid>processing', 'paid>rejected',
        'processing>rejected', 'processing>ready', 'processing>sent',
    ];
    $all = TranscriptStatus::all();
    assert_same(10, count($all), 'ten statuses');
    foreach ($all as $from) {
        foreach ($all as $to) {
            assert_same(in_array("$from>$to", $allowed, true), TranscriptStatus::canMove($from, $to), "$from to $to");
        }
    }
});

test('requests: a personal request walks to ready and gets its issue columns in one update', function () {
    b_requests_cleanup();
    $id = b_make_request('pending_payment');
    foreach (['payment_processing', 'paid', 'processing'] as $next) {
        assert_same($next, Transcript::transition($id, $next)['status']);
    }
    $row = Transcript::transition($id, 'ready', b_issue());
    assert_same('ready', $row['status']);
    assert_true($row['transcript_no'] !== null && $row['ready_at'] !== null, 'issue columns saved');
    assert_api_error('invalid_transition', 409, fn () => Transcript::transition($id, 'processing'));
    assert_api_error('not_found', 404, fn () => Transcript::transition(999999, 'paid'));
    b_requests_cleanup();
});

test('requests: ready is for personal copies, sent for institution copies', function () {
    b_requests_cleanup();
    $inst = b_make_request('processing', b_institution());
    assert_api_error('invalid_transition', 409, fn () => Transcript::transition($inst, 'ready', b_issue()));
    $row = Transcript::transition($inst, 'sent', b_issue() + ['sent_at' => gmdate('Y-m-d H:i:s')]);
    assert_same('sent', $row['status']);
    $pers = b_make_request('processing');
    assert_api_error('invalid_transition', 409, fn () => Transcript::transition($pers, 'sent', b_issue() + ['sent_at' => gmdate('Y-m-d H:i:s')]));
    b_requests_cleanup();
});

test('requests: reject stores the reason; a bad extra column rolls everything back', function () {
    b_requests_cleanup();
    $id = b_make_request('paid');
    $thrown = false;
    try {
        Transcript::transition($id, 'processing', ['fee_amount' => 1]);
    } catch (LogicException $e) {
        $thrown = true;
    }
    assert_true($thrown, 'column outside the allow-list is refused');
    assert_same('paid', b_status($id), 'status unchanged after the failed attempt');
    $row = Transcript::transition($id, 'rejected', ['rejection_reason' => 'Results are under review.']);
    assert_same('rejected', $row['status']);
    assert_same('Results are under review.', $row['rejection_reason']);
    b_requests_cleanup();
});

test('requests: allowed actions for the student and for Registry', function () {
    $p = 'personal';
    assert_same(['pay', 'cancel'], Transcript::allowedActions(['status' => 'pending_payment', 'destination_type' => $p]));
    assert_same(['retry', 'cancel'], Transcript::allowedActions(['status' => 'failed', 'destination_type' => $p]));
    assert_same([], Transcript::allowedActions(['status' => 'payment_processing', 'destination_type' => $p]));
    assert_same(['receipt'], Transcript::allowedActions(['status' => 'paid', 'destination_type' => $p]));
    assert_same(['download', 'receipt'], Transcript::allowedActions(['status' => 'ready', 'destination_type' => $p]));
    assert_same(['receipt'], Transcript::allowedActions(['status' => 'sent', 'destination_type' => 'institution']));
    foreach (['cancelled', 'expired', 'rejected'] as $closed) {
        assert_same([], Transcript::allowedActions(['status' => $closed, 'destination_type' => $p]), $closed);
    }
    assert_same(['start_processing', 'reject'], Transcript::adminActions(['status' => 'paid', 'destination_type' => $p]));
    assert_same(['mark_ready', 'reject'], Transcript::adminActions(['status' => 'processing', 'destination_type' => $p]));
    assert_same(['mark_sent', 'reject'], Transcript::adminActions(['status' => 'processing', 'destination_type' => 'institution']));
    assert_same([], Transcript::adminActions(['status' => 'ready', 'destination_type' => $p]));
    assert_same([], Transcript::adminActions(['status' => 'pending_payment', 'destination_type' => $p]));
    assert_same(true, Transcript::canDownload(['status' => 'ready', 'destination_type' => $p]));
    assert_same(false, Transcript::canDownload(['status' => 'sent', 'destination_type' => 'institution']));
    assert_same(false, Transcript::canDownload(['status' => 'processing', 'destination_type' => $p]));
});

test('requests: findOwned returns only the student\'s own request', function () {
    $row = Transcript::findOwned('tr_1001', 1);
    assert_same('ready', $row['status']);
    assert_same(1, (int) $row['student_id']);
    assert_api_error('not_found', 404, fn () => Transcript::findOwned('tr_1001', 2));  // someone else's
    assert_api_error('not_found', 404, fn () => Transcript::findOwned('tr_9999', 1));  // does not exist
    assert_api_error('not_found', 404, fn () => Transcript::findOwned('garbage', 1));  // malformed
});

test('requests: expireOverdue expires unpaid requests and fails stale payments', function () {
    b_requests_cleanup();
    $past = gmdate('Y-m-d H:i:s', time() - 3600);
    $future = gmdate('Y-m-d H:i:s', time() + 86400);
    $overdue = b_make_request('pending_payment', ['student_id' => 7, 'expires_at' => $past]);
    $stale = b_make_request('payment_processing', ['student_id' => 8, 'updated_at' => '2020-01-01 00:00:00']);
    Database::execute("INSERT INTO payments (request_id, reference, amount, status) VALUES (?, ?, 5000, 'initiated')", [$stale, 'TMP-P' . bin2hex(random_bytes(6))]);
    $fresh = b_make_request('pending_payment', ['student_id' => 5, 'expires_at' => $future]);

    assert_true(Transcript::expireOverdue() >= 2, 'at least two rows changed');
    assert_same('expired', b_status($overdue));
    assert_same('failed', b_status($stale));
    assert_same('pending_payment', b_status($fresh), 'not overdue, untouched');
    $pay = Database::fetchOne('SELECT status FROM payments WHERE request_id = ?', [$stale]);
    assert_same('failed', $pay['status'], 'the initiated payment is failed too');
    b_requests_cleanup();

    $failedPast = b_make_request('failed', ['student_id' => 7, 'expires_at' => $past]);
    $processingNow = b_make_request('payment_processing', ['student_id' => 8]);
    Transcript::expireOverdue();
    assert_same('expired', b_status($failedPast), 'failed and overdue becomes expired');
    assert_same('payment_processing', b_status($processingNow), 'recent payment_processing is left alone');
    b_requests_cleanup();
});

section('Transcript requests (Dev B)');

function b_session(string $matric): Http
{
    $http = b_client();
    $login = $http->request('POST', 'auth/login.php', ['matricNo' => $matric, 'password' => 'Test@1234']);
    assert_same(200, $login['status'], "login $matric");
    $http->csrf = $login['json']['data']['csrfToken'];
    return $http;
}

function b_key(): string
{
    return 'test-' . bin2hex(random_bytes(6));
}

/** Removes what the create tests made for student 7 (seed requests for 7 are ids 9 and 10). */
function b_create_cleanup(): void
{
    Database::execute('DELETE FROM payments WHERE request_id IN (SELECT id FROM transcript_requests WHERE student_id = 7 AND id > 11)');
    Database::execute('DELETE FROM transcript_requests WHERE student_id = 7 AND id > 11');
    Database::execute('DELETE FROM idempotency_keys WHERE student_id IN (1, 7)');
}

function b_quote(Http $http, string $qs): array
{
    $r = $http->request('GET', 'transcripts/quote.php?' . $qs);
    assert_same(200, $r['status'], 'quote');
    return $r['json']['data'];
}

test('fees: option validation and the fee formula', function () {
    $fee = (int) Config::get('TRANSCRIPT_FEE');
    $courier = (int) Config::get('COURIER_SURCHARGE');
    assert_same($fee, FeeCalculator::fee('download', 1));
    assert_same($fee * 3, FeeCalculator::fee('email', 3));
    assert_same($fee * 2 + $courier, FeeCalculator::fee('courier', 2));

    $ok = FeeCalculator::options(['destinationType' => 'personal', 'copies' => '2']);
    assert_same('download', $ok['deliveryMethod'], 'personal defaults to download');
    assert_same(2, $ok['copies']);

    $bad = [
        [['destinationType' => 'nope'], false, 'destinationType'],
        [['destinationType' => 'institution', 'deliveryMethod' => 'download'], false, 'deliveryMethod'],
        [['destinationType' => 'personal', 'deliveryMethod' => 'courier'], false, 'deliveryMethod'],
        [['destinationType' => 'personal', 'copies' => 4], false, 'copies'],
        [['destinationType' => 'personal', 'copies' => 'abc'], false, 'copies'],
        [['destinationType' => 'institution', 'deliveryMethod' => 'email'], true, 'institutionName'],
        [['destinationType' => 'institution', 'deliveryMethod' => 'email', 'institutionName' => 'X'], true, 'destinationEmail'],
        [['destinationType' => 'institution', 'deliveryMethod' => 'courier', 'institutionName' => 'X'], true, 'destinationAddress'],
    ];
    foreach ($bad as [$input, $full, $field]) {
        try {
            FeeCalculator::options($input, $full);
            throw new AssertionError("expected 422 for $field");
        } catch (ApiException $e) {
            assert_same(422, $e->httpStatus());
            assert_true(isset($e->fields()[$field]), "field message for $field");
        }
    }
});

test('quote: needs login, prices the options, rejects bad combinations', function () {
    assert_same(401, (new Http(api_base()))->request('GET', 'transcripts/quote.php?destinationType=personal')['status']);
    $http = b_session('CSC/2025/002');
    $fee = (int) Config::get('TRANSCRIPT_FEE');
    $p = b_quote($http, 'destinationType=personal&deliveryMethod=download&copies=1');
    assert_same($fee, $p['feeAmount']);
    assert_same((string) Config::get('CURRENCY'), $p['currency']);
    assert_true(strtotime($p['expiresAt']) > time(), 'expires in the future');
    $c = b_quote($http, 'destinationType=institution&deliveryMethod=courier&copies=2');
    assert_same($fee * 2 + (int) Config::get('COURIER_SURCHARGE'), $c['feeAmount']);
    $bad = $http->request('GET', 'transcripts/quote.php?destinationType=institution&deliveryMethod=download&copies=1');
    assert_same(422, $bad['status']);
    assert_true(isset($bad['json']['error']['fields']['deliveryMethod']), 'deliveryMethod message');
    $bad = $http->request('GET', 'transcripts/quote.php?destinationType=personal&copies=4');
    assert_same(422, $bad['status']);
    assert_true(isset($bad['json']['error']['fields']['copies']), 'copies message');
});

test('create: full flow (quote, create, replay, duplicate, ownership, cancel, create again)', function () {
    b_create_cleanup();
    $http = b_session('CSC/2025/002'); // student 7: no open request in the seed
    $fee = (int) Config::get('TRANSCRIPT_FEE');
    $quote = b_quote($http, 'destinationType=personal&deliveryMethod=download&copies=1');
    $body = ['destinationType' => 'personal', 'deliveryMethod' => 'download', 'copies' => 1,
             'quoteExpiresAt' => $quote['expiresAt'], 'feeAmount' => 1]; // the browser's fee is ignored

    assert_same(422, $http->request('POST', 'transcripts/create.php', $body)['status'], 'Idempotency-Key is required');

    $key = b_key();
    $a = $http->request('POST', 'transcripts/create.php', $body, ['Idempotency-Key' => $key]);
    assert_same(201, $a['status']);
    $t = $a['json']['data']['transcript'];
    assert_same('pending_payment', $t['status']);
    assert_same($fee, $t['feeAmount'], 'fee computed on the server');
    assert_same(['pay', 'cancel'], $t['allowedActions']);
    $row = Database::fetchOne('SELECT request_no, expires_at FROM transcript_requests WHERE id = ?', [IdCodec::decode('tr', $t['id'])]);
    assert_true(preg_match('/^TR-\d{4}-\d{6}$/', $row['request_no']) === 1, 'request number format');
    assert_true($row['expires_at'] !== null, 'payment deadline set');

    $replay = $http->request('POST', 'transcripts/create.php', $body, ['Idempotency-Key' => $key]);
    assert_same(201, $replay['status']);
    assert_same($t['id'], $replay['json']['data']['transcript']['id'], 'replay returns the same request');
    $count = Database::fetchOne('SELECT COUNT(*) AS c FROM transcript_requests WHERE student_id = 7 AND id > 11');
    assert_same(1, (int) $count['c'], 'replay made no second row');
    $conflict = $http->request('POST', 'transcripts/create.php', ['copies' => 2] + $body, ['Idempotency-Key' => $key]);
    assert_same(409, $conflict['status']);
    assert_same('idempotency_conflict', $conflict['json']['error']['code'] ?? null);

    $index = $http->request('GET', 'transcripts/index.php');
    assert_same(200, $index['status']);
    $ids = array_column($index['json']['data']['items'], 'id');
    assert_true(in_array($t['id'], $ids, true), 'new request is listed');
    assert_true(!in_array('tr_1001', $ids, true), "another student's request is not listed");

    $quote2 = b_quote($http, 'destinationType=personal&deliveryMethod=download&copies=1');
    $dup = $http->request('POST', 'transcripts/create.php', ['quoteExpiresAt' => $quote2['expiresAt']] + $body, ['Idempotency-Key' => b_key()]);
    assert_same(409, $dup['status']);
    assert_same('duplicate_open_request', $dup['json']['error']['code'] ?? null);

    $other = b_session('CSC/2024/002'); // another student
    assert_same(404, $other->request('POST', 'transcripts/cancel.php?id=' . $t['id'], [])['status'], 'cannot cancel a foreign request');

    $cancel = $http->request('POST', 'transcripts/cancel.php?id=' . $t['id'], []);
    assert_same(200, $cancel['status']);
    assert_same('cancelled', $cancel['json']['data']['status']);
    assert_same(409, $http->request('POST', 'transcripts/cancel.php?id=' . $t['id'], [])['status'], 'cancelling twice');
    assert_same(422, $http->request('POST', 'transcripts/cancel.php', [])['status'], 'missing id');

    $quote3 = b_quote($http, 'destinationType=personal&deliveryMethod=download&copies=1');
    $again = $http->request('POST', 'transcripts/create.php', ['quoteExpiresAt' => $quote3['expiresAt']] + $body, ['Idempotency-Key' => b_key()]);
    assert_same(201, $again['status'], 'a new request is allowed once the old one is cancelled');
    b_create_cleanup();
});

test('create: quote and institution field checks', function () {
    b_create_cleanup();
    $http = b_session('CSC/2025/002');
    $noQuote = $http->request('POST', 'transcripts/create.php', ['destinationType' => 'personal', 'deliveryMethod' => 'download', 'copies' => 1], ['Idempotency-Key' => b_key()]);
    assert_same(422, $noQuote['status']);
    assert_true(isset($noQuote['json']['error']['fields']['quoteExpiresAt']), 'quote required');

    $q = b_quote($http, 'destinationType=personal&deliveryMethod=download&copies=1');
    $mismatch = $http->request('POST', 'transcripts/create.php', ['destinationType' => 'personal', 'deliveryMethod' => 'download', 'copies' => 3, 'quoteExpiresAt' => $q['expiresAt']], ['Idempotency-Key' => b_key()]);
    assert_same(422, $mismatch['status'], 'options must match the quote');

    $cases = [
        [['deliveryMethod' => 'email'], 'institutionName'],
        [['institutionName' => 'Test University', 'deliveryMethod' => 'email'], 'destinationEmail'],
        [['institutionName' => 'Test University', 'deliveryMethod' => 'courier'], 'destinationAddress'],
        [['institutionName' => 'Test University', 'deliveryMethod' => 'email', 'destinationEmail' => 'not-an-email'], 'destinationEmail'],
    ];
    foreach ($cases as [$extra, $field]) {
        $r = $http->request('POST', 'transcripts/create.php', ['destinationType' => 'institution', 'copies' => 1, 'quoteExpiresAt' => $q['expiresAt']] + $extra, ['Idempotency-Key' => b_key()]);
        assert_same(422, $r['status'], $field);
        assert_true(isset($r['json']['error']['fields'][$field]), "message for $field");
    }
    b_create_cleanup();
});

test('create: a student with an open request cannot make another', function () {
    b_create_cleanup();
    $http = b_session('CSC/2023/001'); // seed: tr_1002 is pending payment
    $q = b_quote($http, 'destinationType=personal&deliveryMethod=download&copies=1');
    $r = $http->request('POST', 'transcripts/create.php', ['destinationType' => 'personal', 'deliveryMethod' => 'download', 'copies' => 1, 'quoteExpiresAt' => $q['expiresAt']], ['Idempotency-Key' => b_key()]);
    assert_same(409, $r['status']);
    assert_same('duplicate_open_request', $r['json']['error']['code'] ?? null);
    b_create_cleanup();
});

section('Payments (Dev B)');

function b_future(): string
{
    return gmdate('Y-m-d H:i:s', time() + 86400);
}

function b_payment(string $reference): array
{
    return Database::fetchOne('SELECT * FROM payments WHERE reference = ?', [$reference]);
}

test('payments: initiate then settle marks the request paid; settling twice changes nothing', function () {
    b_requests_cleanup();
    $id = b_make_request('pending_payment', ['expires_at' => b_future()]);
    $pay = Payment::initiate($id, 7);
    assert_same('payment_processing', $pay['status']);
    assert_same('simulated', $pay['mode']);
    assert_same(null, $pay['paymentUrl']);
    assert_same(IdCodec::encode('tr', $id), $pay['transcriptId']);
    assert_true(preg_match('/^PAY-\d{4}-[A-F0-9]{10}$/', $pay['reference']) === 1, 'reference format');
    assert_same('payment_processing', b_status($id));

    $first = Payment::settle($pay['reference']);
    assert_same('success', $first['paymentStatus']);
    assert_same('paid', $first['transcriptStatus']);
    assert_same(['receipt'], $first['allowedActions']);
    assert_same(true, $first['receiptAvailable']);
    assert_true($first['verifiedAt'] !== null, 'verifiedAt set');
    $row = b_payment($pay['reference']);
    assert_true(preg_match('/^RCPT-\d{4}-\d+$/', (string) $row['receipt_no']) === 1, 'receipt number');
    assert_true($row['gateway_txn_id'] !== null && $row['paid_at'] !== null, 'gateway id and paid time saved');

    $second = Payment::settle($pay['reference']);
    assert_same($first, $second, 'repeat gives the same answer');
    assert_same($row['receipt_no'], b_payment($pay['reference'])['receipt_no'], 'receipt number unchanged');
    $count = Database::fetchOne("SELECT COUNT(*) AS c FROM payments WHERE request_id = ? AND status = 'success'", [$id]);
    assert_same(1, (int) $count['c'], 'exactly one successful payment');
    assert_api_error('not_found', 404, fn () => Payment::settle('PAY-NOPE'));
    b_requests_cleanup();
});

test('payments: only the owner can pay, and only from pending_payment or failed', function () {
    b_requests_cleanup();
    $id = b_make_request('pending_payment', ['expires_at' => b_future()]);
    assert_api_error('not_found', 404, fn () => Payment::initiate($id, 8));          // wrong owner
    assert_same('pending_payment', b_status($id), 'wrong owner changed nothing');
    Payment::initiate($id, 7);
    assert_api_error('invalid_transition', 409, fn () => Payment::initiate($id, 7)); // already processing
    foreach (['cancelled', 'expired'] as $closed) {
        $c = b_make_request($closed, ['student_id' => 8]);
        assert_api_error('invalid_transition', 409, fn () => Payment::initiate($c, 8));
    }
    b_requests_cleanup();
});

test('payments: a wrong amount is never marked paid, and the student can retry', function () {
    b_requests_cleanup();
    $id = b_make_request('pending_payment', ['expires_at' => b_future()]);
    $pay = Payment::initiate($id, 7);
    Database::execute('UPDATE payments SET amount = 1 WHERE reference = ?', [$pay['reference']]); // tampered
    $r = Payment::settle($pay['reference']);
    assert_same('failed', $r['paymentStatus']);
    assert_same('failed', $r['transcriptStatus']);
    assert_same(['retry', 'cancel'], $r['allowedActions']);
    $row = b_payment($pay['reference']);
    assert_true(str_contains((string) $row['failure_reason'], 'amount'), 'reason mentions the amount');
    assert_same(null, $row['receipt_no'], 'no receipt');

    $retry = Payment::initiate($id, 7);
    assert_true($retry['reference'] !== $pay['reference'], 'a new attempt has a new reference');
    $ok = Payment::settle($retry['reference']);
    assert_same('paid', $ok['transcriptStatus']);
    $count = Database::fetchOne('SELECT COUNT(*) AS c FROM payments WHERE request_id = ?', [$id]);
    assert_same(2, (int) $count['c'], 'both attempts are kept');
    b_requests_cleanup();
});

test('payments: a declined payment fails the request', function () {
    b_requests_cleanup();
    $id = b_make_request('pending_payment', ['expires_at' => b_future()]);
    $pay = Payment::initiate($id, 7);
    try {
        Config::override(['SIMULATE_PAYMENT_FAILURE' => true]);
        $r = Payment::settle($pay['reference']);
    } finally {
        Config::override(['SIMULATE_PAYMENT_FAILURE' => false]);
    }
    assert_same('failed', $r['paymentStatus']);
    assert_same('failed', $r['transcriptStatus']);
    assert_same('Card declined (simulated)', b_payment($pay['reference'])['failure_reason']);
    b_requests_cleanup();
});

test('payments: simulated payments are refused when PAYMENT_MODE is not simulated', function () {
    b_requests_cleanup();
    $id = b_make_request('pending_payment', ['expires_at' => b_future()]);
    $pay = Payment::initiate($id, 7);
    $original = Config::get('PAYMENT_MODE');
    try {
        Config::override(['PAYMENT_MODE' => 'paystack']);
        assert_api_error('forbidden', 403, fn () => Payment::settle($pay['reference']));
    } finally {
        Config::override(['PAYMENT_MODE' => $original]);
    }
    assert_same('payment_processing', b_status($id), 'nothing changed');
    assert_same('initiated', b_payment($pay['reference'])['status']);
    b_requests_cleanup();
});

test('payments: markRefunded flags a verified payment once', function () {
    b_requests_cleanup();
    $id = b_make_request('pending_payment', ['expires_at' => b_future()]);
    $pay = Payment::initiate($id, 7);
    Payment::settle($pay['reference']);
    assert_same(true, Payment::markRefunded($id));
    assert_same(false, Payment::markRefunded($id), 'second call flags nothing');
    $row = b_payment($pay['reference']);
    assert_same('success', $row['status'], 'status stays success');
    assert_true($row['refunded_at'] !== null, 'refunded_at set');
    $none = b_make_request('cancelled', ['student_id' => 8]);
    assert_same(false, Payment::markRefunded($none), 'no payment to refund');
    b_requests_cleanup();
});

test('payments: full flow over HTTP (create, pay, replay, verify twice, guards)', function () {
    b_create_cleanup();
    $http = b_session('CSC/2025/002');
    $q = b_quote($http, 'destinationType=personal&deliveryMethod=download&copies=1');
    $created = $http->request('POST', 'transcripts/create.php',
        ['destinationType' => 'personal', 'deliveryMethod' => 'download', 'copies' => 1, 'quoteExpiresAt' => $q['expiresAt']],
        ['Idempotency-Key' => b_key()]);
    assert_same(201, $created['status']);
    $tid = $created['json']['data']['transcript']['id'];
    $payUrl = "transcripts/pay.php?id=$tid";
    $verifyUrl = "payments/verify.php?id=$tid";

    assert_same(403, (new Http(api_base()))->request('POST', $payUrl, [])['status'], 'no CSRF token');
    assert_same(422, $http->request('POST', $payUrl, [])['status'], 'Idempotency-Key required');

    $key = b_key();
    $pay = $http->request('POST', $payUrl, [], ['Idempotency-Key' => $key]);
    assert_same(200, $pay['status']);
    $ref = $pay['json']['data']['reference'];
    assert_same('payment_processing', $pay['json']['data']['status']);
    assert_same('simulated', $pay['json']['data']['mode']);
    $replay = $http->request('POST', $payUrl, [], ['Idempotency-Key' => $key]);
    assert_same($ref, $replay['json']['data']['reference'], 'replay returns the same payment');
    assert_same(409, $http->request('POST', $payUrl, [], ['Idempotency-Key' => b_key()])['status'], 'already processing');

    $other = b_session('CSC/2024/002');
    assert_same(404, $other->request('POST', $payUrl, [], ['Idempotency-Key' => b_key()])['status'], 'foreign request: pay');
    assert_same(404, $other->request('POST', $verifyUrl, ['reference' => $ref])['status'], 'foreign request: verify');

    assert_same(422, $http->request('POST', $verifyUrl, [])['status'], 'reference missing');
    assert_same(422, $http->request('POST', $verifyUrl, ['reference' => 'PAY-NOPE'])['status'], 'reference unknown');
    $seedRef = 'PAY-2026-000001'; // belongs to another request
    assert_same(422, $http->request('POST', $verifyUrl, ['reference' => $seedRef])['status'], "someone else's reference");

    $v = $http->request('POST', $verifyUrl, ['reference' => $ref]);
    assert_same(200, $v['status']);
    $d = $v['json']['data'];
    assert_same('success', $d['paymentStatus']);
    assert_same('paid', $d['transcriptStatus']);
    assert_same(['receipt'], $d['allowedActions']);
    assert_same(true, $d['receiptAvailable']);
    assert_same($d, $http->request('POST', $verifyUrl, ['reference' => $ref])['json']['data'], 'verify is repeatable');

    $item = null;
    foreach ($http->request('GET', 'transcripts/index.php')['json']['data']['items'] as $i) {
        if ($i['id'] === $tid) { $item = $i; }
    }
    assert_same('paid', $item['status']);
    assert_same('success', $item['payment']['status']);
    assert_same($ref, $item['payment']['reference']);
    assert_same(409, $http->request('POST', $payUrl, [], ['Idempotency-Key' => b_key()])['status'], 'cannot pay twice');
    b_create_cleanup();
});

section('Receipts (Dev B)');

test('receipt: data for a verified seed payment, and null when there is nothing to show', function () {
    $r = Payment::receiptData(1, 1); // TR-2026-000001, student 1
    assert_same('RCPT-2026-1001', $r['receiptNumber']);
    assert_same(5000, $r['amount']);
    assert_same('NGN', $r['currency']);
    assert_same('2026-09-02T10:15:00Z', $r['paidAt']);
    assert_same('PAY-2026-000001', $r['reference']);
    assert_same('TR-2026-000001', $r['requestNo']);
    assert_same('CSC/2023/001', $r['matricNo']);
    assert_same('Personal copy', $r['destination']);
    assert_same(1, $r['copies']);
    assert_same('University of Ibadan', $r['university']);
    assert_same(null, Payment::receiptData(1, 2), "another student's request");
    assert_same(null, Payment::receiptData(2, 1), 'request with no payment');
    assert_same(null, Payment::receiptData(99999, 1), 'unknown request');
    assert_same('%PDF-', substr(ReceiptPdf::render($r), 0, 5), 'renders with the PDF code');
});

test('receipt: a refunded payment has no receipt', function () {
    b_requests_cleanup();
    $id = b_make_request('pending_payment', ['expires_at' => b_future()]);
    $pay = Payment::initiate($id, 7);
    Payment::settle($pay['reference']);
    assert_true(Payment::receiptData($id, 7) !== null, 'receipt exists after verification');
    Payment::markRefunded($id);
    assert_same(null, Payment::receiptData($id, 7));
    b_requests_cleanup();
});

test('receipt: HTTP, JSON and PDF, owner only', function () {
    assert_same(401, (new Http(api_base()))->request('GET', 'payments/receipt.php?id=tr_1001')['status'], 'logged out');
    $http = b_session('CSC/2023/001');
    $json = $http->request('GET', 'payments/receipt.php?id=tr_1001');
    assert_same(200, $json['status']);
    $d = $json['json']['data'];
    assert_same('RCPT-2026-1001', $d['receiptNumber']);
    assert_same(5000, $d['amount']);
    assert_same('PAY-2026-000001', $d['reference']);
    assert_same('2026-09-02T10:15:00Z', $d['paidAt']);

    $pdf = $http->request('GET', 'payments/receipt.php?id=tr_1001', null, ['Accept' => 'application/pdf']);
    assert_same(200, $pdf['status']);
    assert_same('application/pdf', $pdf['headers']['content-type'] ?? null);
    assert_same('%PDF-', substr($pdf['body'], 0, 5));

    assert_same(404, $http->request('GET', 'payments/receipt.php?id=tr_1002')['status'], 'no verified payment');
    assert_same(404, $http->request('GET', 'payments/receipt.php?id=tr_1005')['status'], "someone else's request");
    assert_same(404, $http->request('GET', 'payments/receipt.php?id=garbage')['status'], 'malformed id');
    assert_same(422, $http->request('GET', 'payments/receipt.php')['status'], 'missing id');
});

section('Password reset (Dev B)');

function b_reset_hash(): string
{
    return (string) Database::fetchOne('SELECT password_hash FROM students WHERE id = 7')['password_hash'];
}

/** Puts student 7's password back and removes what the reset tests created. */
function b_reset_restore(string $hash): void
{
    Database::execute('UPDATE students SET password_hash = ? WHERE id = 7', [$hash]);
    Database::execute('DELETE FROM password_reset_tokens WHERE student_id = 7');
    Database::execute("DELETE FROM login_attempts WHERE identifier IN ('TOKEN-ATTEMPT', 'CSC/2025/002') OR identifier LIKE 'TEST/%'");
}

test('reset: password policy', function () {
    PasswordReset::assertPasswordPolicy('Valid1234'); // passes
    foreach (['short1', 'abcdefghij', '1234567890', str_repeat('a1', 37)] as $bad) {
        try {
            PasswordReset::assertPasswordPolicy($bad);
            throw new AssertionError("expected 422 for '$bad'");
        } catch (ApiException $e) {
            assert_same(422, $e->httpStatus());
            assert_true(isset($e->fields()['newPassword']), 'newPassword message');
        }
    }
});

test('reset: tokens are hashed, single use, expire, and a newer token replaces an older one', function () {
    $orig = b_reset_hash();
    try {
        $t = PasswordReset::issue(7);
        assert_true(preg_match('/^[a-f0-9]{64}$/', $t) === 1, 'token is 64 hex characters');
        $raw = Database::fetchOne('SELECT COUNT(*) AS c FROM password_reset_tokens WHERE token_hash = ?', [$t]);
        assert_same(0, (int) $raw['c'], 'the raw token is never stored');
        $hashed = Database::fetchOne('SELECT COUNT(*) AS c FROM password_reset_tokens WHERE token_hash = ?', [hash('sha256', $t)]);
        assert_same(1, (int) $hashed['c'], 'its hash is');

        PasswordReset::consume($t, 'NewPass123');
        assert_true(password_verify('NewPass123', b_reset_hash()), 'password changed');
        assert_api_error('validation_failed', 422, fn () => PasswordReset::consume($t, 'Another123')); // single use
        assert_true(password_verify('NewPass123', b_reset_hash()), 'the reused token changed nothing');

        assert_api_error('validation_failed', 422, fn () => PasswordReset::consume(str_repeat('a', 64), 'Another123')); // unknown
        assert_api_error('validation_failed', 422, fn () => PasswordReset::consume('nonsense', 'Another123'));

        $old = PasswordReset::issue(7);
        $new = PasswordReset::issue(7);
        assert_api_error('validation_failed', 422, fn () => PasswordReset::consume($old, 'Another123'), 'older token replaced');
        PasswordReset::consume($new, 'Another123');

        $expired = PasswordReset::issue(7);
        Database::execute('UPDATE password_reset_tokens SET expires_at = ? WHERE token_hash = ?',
            [gmdate('Y-m-d H:i:s', time() - 60), hash('sha256', $expired)]);
        assert_api_error('validation_failed', 422, fn () => PasswordReset::consume($expired, 'Another123'));
    } finally {
        b_reset_restore($orig);
    }
});

test('reset: a weak password does not use up the token; a reset clears the login lockout', function () {
    $orig = b_reset_hash();
    try {
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::recordFailure('login', 'CSC/2025/002');
        }
        $t = PasswordReset::issue(7);
        assert_api_error('validation_failed', 422, fn () => PasswordReset::consume($t, 'weak'));
        PasswordReset::consume($t, 'Strong1234'); // the same token still works
        $left = Database::fetchOne("SELECT COUNT(*) AS c FROM login_attempts WHERE kind = 'login' AND identifier = 'CSC/2025/002'");
        assert_same(0, (int) $left['c'], 'lockout cleared');
    } finally {
        b_reset_restore($orig);
    }
});

test('reset: request writes a link for a known account and nothing for an unknown one', function () {
    $orig = b_reset_hash();
    $original = Config::get('RESET_LOG_PATH');
    $log = Config::root() . '/storage/logs/reset-test.log';
    @unlink($log);
    try {
        Config::override(['RESET_LOG_PATH' => 'storage/logs/reset-test.log']);
        PasswordReset::request('NOPE/0000/000');
        PasswordReset::request('   ');
        assert_true(!is_file($log), 'unknown account: no link written');
        $before = Database::fetchOne('SELECT COUNT(*) AS c FROM password_reset_tokens');

        PasswordReset::request('csc/2025/002');                 // by matric number, any case
        PasswordReset::request('kehinde.oladipo@example.com');  // by email (seed email for student 7 may differ; see note)
        assert_true(is_file($log), 'known account: link written');
        $text = (string) file_get_contents($log);
        assert_true(str_contains($text, 'CSC/2025/002'), 'matric number in the log line');
        assert_true(preg_match('/reset-password\?token=[a-f0-9]{64}/', $text) === 1, 'link with a token');

        PasswordReset::request('CSC/2024/003'); // suspended: no token
        $suspended = Database::fetchOne('SELECT COUNT(*) AS c FROM password_reset_tokens WHERE student_id = 8');
        assert_same(0, (int) $suspended['c'], 'suspended account gets no token');
    } finally {
        Config::override(['RESET_LOG_PATH' => $original]);
        @unlink($log);
        b_reset_restore($orig);
    }
});

test('reset: HTTP request endpoint answers the same for known and unknown accounts, and throttles', function () {
    $orig = b_reset_hash();
    $log = Config::root() . '/storage/logs/reset-links.log';
    $size = is_file($log) ? filesize($log) : 0;
    try {
        $none = (new Http(api_base()))->request('POST', 'auth/password-reset-request.php', ['emailOrMatric' => 'x']);
        assert_same(403, $none['status'], 'no CSRF token');

        $http = b_client();
        assert_same(422, $http->request('POST', 'auth/password-reset-request.php', [])['status'], 'missing field');
        $known = $http->request('POST', 'auth/password-reset-request.php', ['emailOrMatric' => 'CSC/2025/002']);
        $unknown = $http->request('POST', 'auth/password-reset-request.php', ['emailOrMatric' => 'TEST/RESET/001']);
        assert_same(200, $known['status']);
        assert_same(200, $unknown['status']);
        assert_same($known['json'], $unknown['json'], 'identical body');
        assert_same('If the account can be found, reset instructions will be sent.', $known['json']['data']['message']);
        $tokens = Database::fetchOne('SELECT COUNT(*) AS c FROM password_reset_tokens WHERE student_id = 7');
        assert_same(1, (int) $tokens['c'], 'the known account got a token');
        clearstatcache();
        assert_true(is_file($log) && filesize($log) > $size, 'a link line was appended to the reset log');

        for ($i = 0; $i < 4; $i++) { // 5 requests allowed, so the 6th is blocked
            assert_same(200, $http->request('POST', 'auth/password-reset-request.php', ['emailOrMatric' => 'TEST/RESET/001'])['status']);
        }
        $blocked = $http->request('POST', 'auth/password-reset-request.php', ['emailOrMatric' => 'TEST/RESET/001']);
        assert_same(429, $blocked['status']);
        assert_true(isset($blocked['headers']['retry-after']), 'Retry-After header');
    } finally {
        b_reset_restore($orig);
    }
});

test('reset: full flow over HTTP, then only the new password works', function () {
    $orig = b_reset_hash();
    try {
        $token = PasswordReset::issue(7);
        $http = b_client();
        assert_same(403, (new Http(api_base()))->request('POST', 'auth/password-reset.php', ['token' => $token, 'newPassword' => 'NewPass123'])['status'], 'no CSRF token');
        assert_same(422, $http->request('POST', 'auth/password-reset.php', [])['status'], 'missing fields');

        $weak = $http->request('POST', 'auth/password-reset.php', ['token' => $token, 'newPassword' => 'weak']);
        assert_same(422, $weak['status']);
        assert_true(isset($weak['json']['error']['fields']['newPassword']), 'newPassword message');

        $ok = $http->request('POST', 'auth/password-reset.php', ['token' => $token, 'newPassword' => 'NewPass123']);
        assert_same(200, $ok['status']);
        assert_same(true, $ok['json']['data']['reset']);

        $reuse = $http->request('POST', 'auth/password-reset.php', ['token' => $token, 'newPassword' => 'Another123']);
        assert_same(422, $reuse['status']);
        assert_true(isset($reuse['json']['error']['fields']['token']), 'token message');

        $newLogin = b_client()->request('POST', 'auth/login.php', ['matricNo' => 'CSC/2025/002', 'password' => 'NewPass123']);
        assert_same(200, $newLogin['status'], 'new password works');
        $oldLogin = b_client()->request('POST', 'auth/login.php', ['matricNo' => 'CSC/2025/002', 'password' => 'Test@1234']);
        assert_same(401, $oldLogin['status'], 'old password no longer works');
    } finally {
        b_reset_restore($orig);
    }
});

section('Security pass (Dev B)');

/** Paths (without .php) of the student routes with this HTTP method, read from the routes files. */
function b_route_paths(string $method): array
{
    $paths = [];
    foreach (['routes_student.php', 'routes_account.php'] as $file) {
        foreach (array_keys(require Config::root() . '/api/' . $file) as $key) {
            if (str_starts_with($key, $method . ' ')) {
                $paths[] = substr($key, strlen($method) + 1);
            }
        }
    }
    return $paths;
}

test('security pass: every student POST route refuses a logged-in request without a CSRF token', function () {
    $paths = b_route_paths('POST');
    assert_true(count($paths) >= 8, 'found the POST routes');
    $http = b_session('CSC/2025/002');
    $http->csrf = null; // keep the session cookie, drop the token
    foreach ($paths as $path) {
        $r = $http->request('POST', $path . '.php?id=tr_1001', []);
        assert_same(403, $r['status'], "POST $path without a CSRF token");
        assert_same('csrf_invalid', $r['json']['error']['code'] ?? null, $path);
    }
    Database::execute('DELETE FROM login_attempts');
});

test('security pass: every student GET route except auth/csrf answers 401 when logged out', function () {
    $http = new Http(api_base());
    foreach (b_route_paths('GET') as $path) {
        if ($path === 'auth/csrf') {
            continue;
        }
        $r = $http->request('GET', $path . '.php?id=tr_1001');
        // A 200 here usually means DEV_STUDENT_ID is still set in your config.php. Remove it.
        assert_same(401, $r['status'], "GET $path logged out");
    }
});

test('security pass: API answers carry no-store and nosniff, errors included', function () {
    $http = new Http(api_base());
    foreach (['auth/csrf.php', 'auth/me.php', 'transcripts/index.php', 'does/not-exist.php'] as $path) {
        $r = $http->request('GET', $path);
        assert_true(str_contains($r['headers']['cache-control'] ?? '', 'no-store'), "$path: no-store");
        assert_same('nosniff', $r['headers']['x-content-type-options'] ?? null, $path);
    }
});

test('security pass: hostile input gets clean errors with no SQL or file paths', function () {
    try {
        $http = b_client();
        $inputs = ["' OR '1'='1", "CSC/2023/001' --", '"; DROP TABLE students; --', str_repeat('A', 5000), "\0"];
        foreach ($inputs as $input) {
            $r = $http->request('POST', 'auth/login.php', ['matricNo' => $input, 'password' => 'x']);
            assert_true(in_array($r['status'], [401, 422, 429], true), 'status was ' . $r['status']);
            assert_true(preg_match('/SQLSTATE|PDO|\.php|xampp|[A-Z]:\\\\/i', $r['body']) !== 1, 'no internals in the body');
        }
        $students = Database::fetchOne('SELECT COUNT(*) AS c FROM students');
        assert_true((int) $students['c'] >= 8, 'students table is intact');
    } finally {
        Database::execute('DELETE FROM login_attempts');
    }
});

test('security pass: login issues a new session id (no session fixation)', function () {
    try {
        $http = new Http(api_base());
        $before = $http->request('GET', 'auth/csrf.php');
        $http->csrf = $before['json']['data']['csrfToken'];
        $login = $http->request('POST', 'auth/login.php', ['matricNo' => 'CSC/2025/002', 'password' => 'Test@1234']);
        assert_same(200, $login['status']);
        preg_match('/UISTUSESS=([^;]+)/', $before['headers']['set-cookie'] ?? '', $a);
        preg_match('/UISTUSESS=([^;]+)/', $login['headers']['set-cookie'] ?? '', $b);
        assert_true(isset($a[1], $b[1]) && $a[1] !== $b[1], 'the session id changed on login');
    } finally {
        Database::execute('DELETE FROM login_attempts');
    }
});

test('security pass: logs hold no passwords', function () {
    foreach (['error.log', 'php-errors.log'] as $name) {
        $file = Config::root() . '/storage/logs/' . $name;
        if (!is_file($file)) {
            continue;
        }
        $text = (string) file_get_contents($file);
        foreach (['Test@1234', 'Admin@1234', 'NewPass123', 'Another123', 'Strong1234', 'Reset12345'] as $secret) {
            assert_true(!str_contains($text, $secret), "$secret found in $name");
        }
    }
});

test('security pass: a student session is refused on admin endpoints', function () {
    $http = b_session('CSC/2025/002');
    $r = $http->request('GET', 'admin/dashboard.php');
    if ($r['status'] === 501) {
        skip("admin endpoints not built yet (Dev C)");
    }
    assert_same(401, $r['status'], 'admin dashboard');
    assert_same(401, $http->request('GET', 'admin/auth/me.php')['status'], 'admin me');
    Database::execute('DELETE FROM login_attempts');
});

// ===== C: admin flow =====
// Dev C: add your tests here with test('name', function () { ... });

// ===================================================================================
// Summary (Dev A)
// ===================================================================================
echo "\n" . sprintf(
    '%d passed, %d failed, %d skipped',
    $TEST_RESULTS['pass'],
    $TEST_RESULTS['fail'],
    $TEST_RESULTS['skip']
) . "\n";
if ($TEST_RESULTS['fail'] > 0) {
    echo 'Failed: ' . implode('; ', $TEST_RESULTS['failures']) . "\n";
    exit(1);
}
exit(0);
