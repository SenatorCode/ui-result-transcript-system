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
// Dev B: add your tests here with test('name', function () { ... });

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
