<?php
// Owner: Dev A. TranscriptData, TranscriptPdf, ReceiptPdf.
//
// PDFs are made on request with FPDF (classes/vendor/fpdf.php) and never stored, so there
// is no file path or public link to leak. The student download route and Dev C's admin
// document route both call TranscriptData::build() and TranscriptPdf::render().

declare(strict_types=1);

require_once __DIR__ . '/vendor/fpdf.php';

final class TranscriptData
{
    /**
     * Everything a transcript shows, for one request. Always the student's live results;
     * the total units and CGPA come from the issue snapshot once the request is ready or
     * sent. Before that the transcript number is null ("pending") and the numbers are live.
     *
     * Honours the request scope: 'full' shows every session, 'session' only the one named.
     */
    public static function build(int $requestId): array
    {
        $req = Database::fetchOne(
            'SELECT tr.id, tr.request_no, tr.student_id, tr.status, tr.scope, tr.destination_type,
                    tr.institution_name, tr.delivery_method, tr.copies, tr.transcript_no,
                    tr.total_units, tr.cgpa, tr.ready_at, tr.sent_at,
                    s.matric_no, s.full_name, s.date_of_birth, s.level,
                    d.name AS department, d.faculty,
                    es.name AS entry_session, sc.name AS scope_session
               FROM transcript_requests tr
               JOIN students s ON s.id = tr.student_id
               JOIN departments d ON d.id = s.department_id
               LEFT JOIN academic_sessions es ON es.id = s.entry_session_id
               LEFT JOIN academic_sessions sc ON sc.id = tr.session_id
              WHERE tr.id = ?',
            [$requestId]
        );
        if ($req === null) {
            throw new ApiException('not_found', 'We could not find that record.', 404);
        }

        $history = Result::history((int) $req['student_id']);
        $sessionScope = $req['scope'] === 'session' ? $req['scope_session'] : null;
        $shown = $sessionScope === null
            ? $history
            : array_values(array_filter($history, static fn (array $h): bool => $h['session'] === $sessionScope));

        $sessions = [];
        foreach ($shown as $h) {
            $sessions[$h['session']][$h['semester']][] = $h;
        }
        $sessionBlocks = [];
        foreach ($sessions as $sessionName => $semesters) {
            $semesterBlocks = [];
            $sessionRows = [];
            foreach ($semesters as $semesterName => $rows) {
                $sessionRows = array_merge($sessionRows, $rows);
                $semesterBlocks[] = [
                    'semester'        => $semesterName,
                    'courses'         => array_map([Result::class, 'courseView'], $rows),
                    'gpa'             => GpaCalculator::gpaOf($rows),
                    'cgpa'            => GpaCalculator::cgpaUpTo($history, $sessionName, $rows[0]['sortOrder']),
                    'unitsRegistered' => array_sum(array_column($rows, 'units')),
                    'unitsEarned'     => array_sum(array_map(static fn (array $r): int => $r['gp10'] > 0 ? $r['units'] : 0, $rows)),
                ];
            }
            $sessionBlocks[] = ['session' => $sessionName, 'gpa' => GpaCalculator::gpaOf($sessionRows), 'semesters' => $semesterBlocks];
        }

        $issued = in_array($req['status'], ['ready', 'sent'], true) && $req['transcript_no'] !== null;
        if ($issued) {
            $totalUnits = (int) $req['total_units'];
            $cgpa = (float) $req['cgpa'];
        } else {
            $totalUnits = $sessionScope === null ? GpaCalculator::totalUnitsCounted((int) $req['student_id']) : array_sum(array_column($shown, 'units'));
            $cgpa = $sessionScope === null
                ? GpaCalculator::overallCgpa((int) $req['student_id'])
                : GpaCalculator::cgpaUpTo($history, $sessionScope, PHP_INT_MAX);
        }

        return [
            'university'   => Config::get('UNIVERSITY_NAME'),
            'requestNo'    => $req['request_no'],
            'status'       => $req['status'],
            'issued'       => $issued,
            'transcriptNo' => $issued ? $req['transcript_no'] : null,
            'issuedAt'     => Response::iso($req['ready_at'] ?? $req['sent_at']),
            'generatedAt'  => gmdate('Y-m-d\TH:i:s\Z'),
            'scope'        => $req['scope'],
            'destination'  => [
                'type'           => $req['destination_type'],
                'label'          => $req['destination_type'] === 'personal' ? 'Personal copy' : $req['institution_name'],
                'deliveryMethod' => $req['delivery_method'],
                'copies'         => (int) $req['copies'],
            ],
            'student'      => [
                'fullName'     => $req['full_name'],
                'matricNo'     => $req['matric_no'],
                'faculty'      => $req['faculty'],
                'department'   => $req['department'],
                'level'        => (int) $req['level'],
                'entrySession' => $req['entry_session'],
                'dateOfBirth'  => $req['date_of_birth'],
            ],
            'sessions'     => $sessionBlocks,
            'summary'      => [
                'totalUnits'  => $totalUnits,
                'cgpa'        => $cgpa,
                'degreeClass' => $sessionScope === null ? GpaCalculator::degreeClass($cgpa) : null,
            ],
        ];
    }
}

/** Shared look of both PDFs, plus the Latin-1 conversion FPDF's built-in fonts need. */
class PortalPdf extends FPDF
{
    public string $heading = '';
    public string $subheading = '';
    public string $footerNote = '';

    /** Converts UTF-8 text to what FPDF's built-in fonts can print. Unknown characters become "?". */
    public static function latin1($value): string
    {
        $value = (string) $value;
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
            if ($converted !== false) {
                return $converted;
            }
        }
        return (string) preg_replace('/[^\x20-\x7E]/', '?', $value);
    }

    /** Shortens text with "..." so it fits in a cell of the given width. */
    public function fit(string $value, float $width): string
    {
        $value = self::latin1($value);
        if ($this->GetStringWidth($value) <= $width - 2) {
            return $value;
        }
        while ($value !== '' && $this->GetStringWidth($value . '...') > $width - 2) {
            $value = substr($value, 0, -1);
        }
        return $value . '...';
    }

    public function Header(): void
    {
        $this->SetTextColor(12, 44, 107);
        $this->SetFont('Helvetica', 'B', 16);
        $this->Cell(0, 8, self::latin1(strtoupper($this->heading)), 0, 1, 'C');
        $this->SetFont('Helvetica', '', 11);
        $this->Cell(0, 6, self::latin1($this->subheading), 0, 1, 'C');
        $this->SetDrawColor(240, 200, 96);
        $this->SetLineWidth(0.6);
        $this->Line(15, $this->GetY() + 2, 195, $this->GetY() + 2);
        $this->SetLineWidth(0.2);
        $this->Ln(7);
        $this->SetTextColor(0, 0, 0);
    }

    public function Footer(): void
    {
        $this->SetY(-14);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(110, 110, 110);
        $this->Cell(130, 5, self::latin1($this->footerNote), 0, 0, 'L');
        $this->Cell(0, 5, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
    }

    /** Starts a new page when fewer than $needed mm are left. */
    public function ensureSpace(float $needed): void
    {
        if ($this->GetY() + $needed > $this->PageBreakTrigger) {
            $this->AddPage();
        }
    }

    /** "Label: value" row used by the detail blocks. */
    public function detail(string $label, string $value, float $labelWidth = 38): void
    {
        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(100, 100, 100);
        $this->Cell($labelWidth, 5.5, self::latin1($label), 0, 0, 'L');
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Helvetica', 'B', 9);
        $this->Cell(0, 5.5, $this->fit($value, 180 - $labelWidth), 0, 1, 'L');
    }

    /** A Lagos-time date such as "10 Oct 2026, 09:30", or a dash. */
    public static function lagosDate(?string $iso, bool $withTime = false): string
    {
        if ($iso === null || $iso === '') {
            return '-';
        }
        try {
            $date = (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('Africa/Lagos'));
        } catch (Exception $e) {
            return '-';
        }
        return $date->format($withTime ? 'd M Y, H:i' : 'd M Y');
    }
}

final class TranscriptPdf
{
    /** Returns the PDF bytes for the array built by TranscriptData::build(). */
    public static function render(array $data): string
    {
        $pdf = new PortalPdf('P', 'mm', 'A4');
        $pdf->heading = (string) $data['university'];
        $pdf->subheading = 'Academic Transcript';
        $pdf->footerNote = ($data['issued'] ? 'Transcript No. ' . $data['transcriptNo'] : 'Not yet issued')
            . '  |  Request ' . $data['requestNo'];
        $pdf->SetTitle(PortalPdf::latin1('Academic Transcript ' . $data['student']['matricNo']));
        $pdf->SetCreator('UI Result and Transcript Portal');
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AliasNbPages();
        $pdf->AddPage();

        self::banner($pdf, $data);
        self::studentBlock($pdf, $data['student']);

        foreach ($data['sessions'] as $session) {
            $pdf->ensureSpace(40);
            $pdf->SetFont('Helvetica', 'B', 11);
            $pdf->SetFillColor(12, 44, 107);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->Cell(0, 7, PortalPdf::latin1('  Session ' . $session['session']), 0, 1, 'L', true);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Ln(1);
            foreach ($session['semesters'] as $semester) {
                self::semester($pdf, $semester);
            }
        }
        if ($data['sessions'] === []) {
            $pdf->SetFont('Helvetica', 'I', 10);
            $pdf->Cell(0, 8, 'There are no results on record for this transcript.', 0, 1, 'L');
        }

        self::summary($pdf, $data);
        return $pdf->Output('S');
    }

    private static function banner(PortalPdf $pdf, array $data): void
    {
        $pdf->SetFont('Helvetica', 'B', 10);
        if ($data['issued']) {
            $pdf->SetFillColor(232, 244, 236);
            $pdf->SetTextColor(20, 100, 50);
            $pdf->Cell(0, 8, PortalPdf::latin1('  Official copy  |  Transcript No. ' . $data['transcriptNo'] . '  |  Issued ' . PortalPdf::lagosDate($data['issuedAt'])), 0, 1, 'L', true);
        } else {
            $pdf->SetFillColor(253, 240, 224);
            $pdf->SetTextColor(150, 70, 0);
            $pdf->Cell(0, 8, '  PROVISIONAL - transcript number pending. Not valid until Registry issues it.', 0, 1, 'L', true);
        }
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(3);
    }

    private static function studentBlock(PortalPdf $pdf, array $student): void
    {
        $pdf->detail('Name', $student['fullName']);
        $pdf->detail('Matric number', $student['matricNo']);
        $pdf->detail('Faculty', $student['faculty']);
        $pdf->detail('Department', $student['department']);
        $pdf->detail('Level', (string) $student['level']);
        $pdf->detail('Entry session', (string) ($student['entrySession'] ?? '-'));
        $pdf->detail('Date of birth', PortalPdf::lagosDate($student['dateOfBirth']));
        $pdf->Ln(4);
    }

    private static function semester(PortalPdf $pdf, array $semester): void
    {
        $widths = ['code' => 24, 'title' => 76, 'units' => 14, 'earned' => 16, 'grade' => 14, 'points' => 14, 'attempt' => 22];
        $pdf->ensureSpace(14 + 6 * (count($semester['courses']) + 2));

        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 6, PortalPdf::latin1($semester['semester']), 0, 1, 'L');

        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetFillColor(238, 241, 247);
        $pdf->SetDrawColor(200, 205, 215);
        $pdf->Cell($widths['code'], 6, 'Code', 'B', 0, 'L', true);
        $pdf->Cell($widths['title'], 6, 'Course title', 'B', 0, 'L', true);
        $pdf->Cell($widths['units'], 6, 'Units', 'B', 0, 'C', true);
        $pdf->Cell($widths['earned'], 6, 'Earned', 'B', 0, 'C', true);
        $pdf->Cell($widths['grade'], 6, 'Grade', 'B', 0, 'C', true);
        $pdf->Cell($widths['points'], 6, 'Points', 'B', 0, 'C', true);
        $pdf->Cell($widths['attempt'], 6, 'Attempt', 'B', 1, 'C', true);

        $pdf->SetFont('Helvetica', '', 9);
        foreach ($semester['courses'] as $course) {
            $pdf->Cell($widths['code'], 6, PortalPdf::latin1($course['code']), 'B', 0, 'L');
            $pdf->Cell($widths['title'], 6, $pdf->fit($course['title'], $widths['title']), 'B', 0, 'L');
            $pdf->Cell($widths['units'], 6, (string) $course['units'], 'B', 0, 'C');
            $pdf->Cell($widths['earned'], 6, (string) $course['unitsEarned'], 'B', 0, 'C');
            $pdf->Cell($widths['grade'], 6, PortalPdf::latin1($course['grade']), 'B', 0, 'C');
            $pdf->Cell($widths['points'], 6, number_format((float) $course['gradePoint'], 1), 'B', 0, 'C');
            $pdf->Cell($widths['attempt'], 6, $course['attempt'] > 1 ? $course['attempt'] . ' (retake)' : '1', 'B', 1, 'C');
        }

        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(0, 7, sprintf(
            'Units registered %d   Units earned %d   GPA %s   CGPA %s',
            $semester['unitsRegistered'],
            $semester['unitsEarned'],
            number_format((float) $semester['gpa'], 2),
            number_format((float) $semester['cgpa'], 2)
        ), 0, 1, 'R');
        $pdf->Ln(2);
    }

    private static function summary(PortalPdf $pdf, array $data): void
    {
        $pdf->ensureSpace(46);
        $pdf->Ln(2);
        $pdf->SetFillColor(238, 241, 247);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->Cell(0, 8, '  Summary', 0, 1, 'L', true);
        $pdf->Ln(1);
        $pdf->detail('Total units on record', (string) $data['summary']['totalUnits'], 50);
        $pdf->detail('Cumulative GPA (CGPA)', number_format((float) $data['summary']['cgpa'], 2), 50);
        if ($data['summary']['degreeClass'] !== null) {
            $pdf->detail('Standing', $data['summary']['degreeClass'], 50);
        }
        $pdf->detail('Prepared for', (string) $data['destination']['label'], 50);
        $pdf->detail('Generated', PortalPdf::lagosDate($data['generatedAt'], true) . ' (Lagos time)', 50);
        $pdf->Ln(3);
        $pdf->SetFont('Helvetica', 'I', 8);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->MultiCell(0, 4.2, 'Grading scale: A = 5, B = 4, C = 3, D = 2, E = 1, F = 0. GPA is the sum of (units x grade points) divided by the sum of units. '
            . 'The CGPA counts the latest attempt of any course taken more than once; every attempt stays listed above.', 0, 'L');
        $pdf->SetTextColor(0, 0, 0);
    }
}

final class ReceiptPdf
{
    /**
     * Renders the array returned by Payment::receiptData() (Dev B). Keys used, all optional
     * and shown as "-" when missing:
     *   receiptNumber, amount (integer naira), currency, paidAt (ISO 8601), reference,
     *   requestNo, studentName, matricNo, destination, copies, university
     */
    public static function render(array $receipt): string
    {
        $pdf = new PortalPdf('P', 'mm', 'A4');
        $pdf->heading = (string) ($receipt['university'] ?? Config::get('UNIVERSITY_NAME'));
        $pdf->subheading = 'Payment Receipt';
        $pdf->footerNote = 'Receipt ' . ($receipt['receiptNumber'] ?? '-');
        $pdf->SetTitle(PortalPdf::latin1('Receipt ' . ($receipt['receiptNumber'] ?? '')));
        $pdf->SetCreator('UI Result and Transcript Portal');
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AliasNbPages();
        $pdf->AddPage();

        $currency = (string) ($receipt['currency'] ?? Config::get('CURRENCY'));
        $amount = isset($receipt['amount']) ? $currency . ' ' . number_format((float) $receipt['amount']) : '-';

        $pdf->SetFillColor(232, 244, 236);
        $pdf->SetTextColor(20, 100, 50);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->Cell(0, 9, '  PAID - payment verified', 0, 1, 'L', true);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(4);

        $pdf->detail('Receipt number', (string) ($receipt['receiptNumber'] ?? '-'), 50);
        $pdf->detail('Amount paid', $amount, 50);
        $pdf->detail('Paid on', PortalPdf::lagosDate($receipt['paidAt'] ?? null, true) . ' (Lagos time)', 50);
        $pdf->detail('Payment reference', (string) ($receipt['reference'] ?? '-'), 50);
        $pdf->Ln(3);
        $pdf->detail('Transcript request', (string) ($receipt['requestNo'] ?? '-'), 50);
        $pdf->detail('Destination', (string) ($receipt['destination'] ?? '-'), 50);
        $pdf->detail('Copies', (string) ($receipt['copies'] ?? '-'), 50);
        $pdf->Ln(3);
        $pdf->detail('Student', (string) ($receipt['studentName'] ?? '-'), 50);
        $pdf->detail('Matric number', (string) ($receipt['matricNo'] ?? '-'), 50);

        $pdf->Ln(6);
        $pdf->SetFont('Helvetica', 'I', 8);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->MultiCell(0, 4.2, 'This receipt confirms that the payment above was verified by the portal. Keep it for your records.', 0, 'L');
        $pdf->SetTextColor(0, 0, 0);
        return $pdf->Output('S');
    }
}
