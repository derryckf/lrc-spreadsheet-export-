<?php
declare(strict_types=1);
namespace App\Services;
use PDO;

/**
 * SpreadsheetExporter — matches Inital_example_sheet.xlsx layout:
 *
 * Per-division workbook:
 *   - Participants {Division} sheet : per-runner history blocks (shared reference data)
 *     Block per runner:
 *       Row: [regNo | tagNo | firstName | lastName | age]
 *       Row: [date | venue | distance | pace]   ← sub-header (pace = label)
 *       Row: eventResult row (date, venue, distance, pace)
 *       ... up to x rows
 *       [blank separator row]
 *   - {Division} entry sheet : eventEntry data (one row per runner)
 *     Row 1: Date | Division | Distance | ID  ← event header
 *     Row 2: {eventDate} | {division} | {distance} | {eventId}
 *     Row 3: blank
 *     Row 4: regNo | tagNo | firstName | lastName | age | sex | daysSince | lastWin | lift | expectedPace | expectedTime | handicap
 *     Row 5+: one row per entrant
 *       Col I: =Participants_LC!J{first_history_row}  (yellow, editable)
 *         User can clear formula and type to override.
 */
class SpreadsheetExporter
{
    private $db;
    private $logger;
    private array $config;

    public function __construct($db, $logger = null, array $config = [])
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->config = array_merge([
            'history_rows_default' => 8,
            'storage_path' => 'handicapping',
        ], $config);
    }

    private function log(): object
    {
        if ($this->logger) {
            return $this->logger;
        }
        return new class {
            public function debug(string $m, array $c = []) {}
            public function info(string $m, array $c = []) {}
            public function error(string $m, array $c = []) {}
            public function warning(string $m, array $c = []) {}
        };
    }

    // ---------------------------------------------------------------------------
    // Public entry point
    // ---------------------------------------------------------------------------

    /**
     * @param int     $eventId       Primary event ID (output path)
     * @param string $format        'xlsx' (default) or 'csv'
     * @param int    $x             history events per runner (default 8)
     * @param bool   $allDivisions  export all divisions for same date
     * @return string  absolute path to output file
     */
    public function export(int $eventId, string $format = 'xlsx', int $x = 8, bool $allDivisions = false): string
    {
        if (!in_array($format, ['xlsx', 'csv'], true)) {
            throw new \InvalidArgumentException("Unknown format: {$format}");
        }

        $event = $this->loadEvent($eventId);
        if (!$event) {
            throw new \RuntimeException("Event not found: {$eventId}");
        }

        if ($allDivisions) {
            $events = $this->findEventsOnSameDate($eventId);
            $this->log()->info("Exporting all divisions for date {$event['eventDate']} (" . count($events['events']) . " events)");
        } else {
            $events = ['events' => [$event]];
        }

        $entriesByDiv = $this->loadEntriesForEvents($events['events'], $x);

        $outputDir = $this->getOutputDir($eventId);
        $suffix    = $allDivisions ? '_all-divisions' : '';
        $filename  = $event['id'] . $suffix . '_' . $event['safeName'] . '.' . $format;
        $path      = $outputDir . '/' . $filename;

if ($format === 'csv') {
            $this->exportCsv($entriesByDiv, $path, $x);
        } else {
            $this->exportXlsx($entriesByDiv, $path, $x);
            // Also export WebScorer start-list CSVs alongside the XLSX
            $this->exportStartListCsv($entriesByDiv, $path, $x);
        }

        $this->log()->info("Export complete: {$path}");

        return $path;
    }

    // ---------------------------------------------------------------------------
    // Data loading
    // ---------------------------------------------------------------------------

    private function loadEvent(int $eventId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT e.id, e.eventDate, e.division, e.distance,'
            . ' s.name as sponsor, v.name as venue'
            . ' FROM event e'
            . ' LEFT JOIN sponsor s ON e.sponsor_id = s.id'
            . ' LEFT JOIN venue v ON e.venue_id = v.id'
            . ' WHERE e.id = :id'
        );
        $stmt->execute(['id' => $eventId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) { return null; }
        $safe = preg_replace('/[^a-zA-Z0-9]+/', '_', trim(($row['sponsor'] ?? '') . '_' . ($row['venue'] ?? '')));
        return [
            'id'        => $row['id'],
            'eventDate' => $row['eventDate'],
            'division'  => $row['division'],
            'distance'  => (float)$row['distance'],
            'safeName'  => $safe,
        ];
    }

    private function findEventsOnSameDate(int $eventId): array
    {
        $stmt = $this->db->prepare(
            'SELECT e.id, e.eventDate, e.division, e.distance,'
            . ' s.name as sponsor, v.name as venue'
            . ' FROM event e'
            . ' LEFT JOIN sponsor s ON e.sponsor_id = s.id'
            . ' LEFT JOIN venue v ON e.venue_id = v.id'
            . ' WHERE e.eventDate = (SELECT eventDate FROM event WHERE id = :id)'
            . ' ORDER BY e.division'
        );
        $stmt->execute(['id' => $eventId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $events = [];
        foreach ($rows as $r) {
            $safe = preg_replace('/[^a-zA-Z0-9]+/', '_', trim(($r['sponsor'] ?? '') . '_' . ($r['venue'] ?? '')));
            $events[] = [
                'id'        => (int)$r['id'],
                'eventDate' => $r['eventDate'],
                'division'  => (int)$r['division'],
                'distance'  => (float)$r['distance'],
                'safeName'  => $safe,
            ];
        }
        return ['events' => $events];
    }

    private function loadEntriesForEvents(array $events, int $x): array
    {
        $merged = [1 => [], 2 => [], 3 => []];
        foreach ($events as $evt) {
            $byDiv = $this->loadEntriesByDivision($evt['id'], $evt, $x);
            foreach ($byDiv as $div => $members) {
                if (!empty($members)) {
                    $merged[$div] = array_merge($merged[$div] ?? [], $members);
                }
            }
        }
        return $merged;
    }

    private function loadEntriesByDivision(int $eventId, array $event, int $x): array
    {
        $stmt = $this->db->prepare(
            'SELECT ee.id, m.regNo, m.firstName, m.lastName, m.DOB, m.sex,'
            . ' t.tagNo,'
            . ' e.division, e.distance, e.eventDate,'
            . ' ee.expectedPace, ee.expectedTime,'
            . ' ee.handicap, ee.startPosition,'
            . ' ee.daysSince, ee.lastWin, ee.liftSec'
            . ' FROM eventEntry ee'
            . ' JOIN member m ON ee.member_id = m.id'
            . ' LEFT JOIN tagNo t ON ee.tagNo_id = t.id'
            . ' JOIN event e ON ee.event_id = e.id'
            . ' WHERE ee.event_id = :eid'
            . ' ORDER BY e.division, ee.startPosition, m.lastName'
        );
        $stmt->execute(['eid' => $eventId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $out = [1 => [], 2 => [], 3 => []];
        foreach ($rows as $r) {
            $div      = (int)($r['division'] ?? 1);
            $memberId = $this->resolveMemberId($r['regNo']);
            $history  = $this->loadHistoryForMember($memberId, (float)$r['distance'], $x);

            $out[$div][] = [
                'regNo'        => $r['regNo'],
                'firstName'    => $r['firstName'],
                'lastName'     => $r['lastName'],
                'DOB'          => $r['DOB'],
                'sex'          => $r['sex'] ?? '',
                'tagNo'        => $r['tagNo'] ?? '',
                'age'          => $this->calcAge($r['DOB'] ?? '', $r['eventDate']),
                'expectedPace' => $r['expectedPace'] ?? '00:00:00',
                'expectedTime' => $r['expectedTime'] ?? '00:00:00',
                'handicap'     => $r['handicap'] ?? '00:00:00',
                'startPosition'=> $r['startPosition'] ?? '',
                'daysSince'    => $r['daysSince'] ?? -1,
                'lastWin'      => $r['lastWin'] ?? -1,
                'lift'         => $r['liftSec'] ?? 0,
                'history'      => $history,
                '_event'       => $event,
            ];
        }
        return $out;
    }

    /** Fetch last-x eventResult rows for a member, closest distance first. */
    private function loadHistoryForMember(int $memberId, float $targetDistance, int $x): array
    {
        $stmt = $this->db->prepare(
            "SELECT e.eventDate, e.distance, v.name as venue,"
            . " er.time as actualTime, er.pace, er.linePosition, er.rank"
            . " FROM eventResult er"
            . " JOIN event e ON er.event_id = e.id"
            . " JOIN venue v ON e.venue_id = v.id"
            . " WHERE er.member_id = :mid AND e.division IN (1,2,3)"
            . " ORDER BY"
            . "   IF(ABS(e.distance - :dist) < 0.01, 0, 1),"
            . "   e.eventDate DESC"
            . " LIMIT :x"
        );
        $stmt->bindValue('mid',  $memberId,        \PDO::PARAM_INT);
        $stmt->bindValue('dist',  (string)$targetDistance, \PDO::PARAM_STR);
        $stmt->bindValue('x',     $x,                \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function resolveMemberId(int $regNo): int
    {
        $stmt = $this->db->prepare('SELECT id FROM member WHERE regNo = :rn LIMIT 1');
        $stmt->execute(['rn' => $regNo]);
        return (int)($stmt->fetchColumn(0) ?: 0);
    }

    // ---------------------------------------------------------------------------
    // Stats
    // ---------------------------------------------------------------------------

    private function computeStats(array $history): array
    {
        if (empty($history)) {
            return [
                'avgPace' => '00:00:00', 'fastestPace' => '00:00:00',
                'lsfPace' => '00:00:00', 'mlrPace' => '00:00:00',
                'stdDev'  => '00:00:00',
                'method' => 'none',
            ];
        }
        $paces = array_filter(array_map(fn($h) => $this->parsePace($h['pace']), $history), fn($p) => $p > 0);
        if (count($paces) < 2) {
            $only = $paces[0] ?? 0;
            return [
                'avgPace' => $this->fmtPace($only),
                'fastestPace' => $this->fmtPace($only),
                'lsfPace' => $this->fmtPace($only),
                'mlrPace' => $this->fmtPace($only),
                'stdDev'  => '00:00:00',
                'method' => count($paces) === 1 ? 'single' : 'none',
            ];
        }
        $avg    = array_sum($paces) / count($paces);
        $mean   = $avg;
        $variance = array_sum(array_map(fn($p) => pow($p - $mean, 2), $paces)) / count($paces);
        $stdDev  = (int)round(sqrt($variance));

        return [
            'avgPace'      => $this->fmtPace($avg),
            'fastestPace'  => $this->fmtPace(min($paces)),
            'lsfPace'      => $this->fmtPace($this->lsfPace($history)),
            'mlrPace'      => $this->fmtPace($this->mlrPace($houses = $history, $paces)),
            'stdDev'       => $this->fmtPace($stdDev),
            'method'       => 'avg',
        ];
    }

    private function lsfPace(array $history): float
    {
        $n = count($history);
        if ($n < 2) { return $this->parsePace($history[0]['pace'] ?? '00:00:00'); }
        $oldest = strtotime($history[$n - 1]['eventDate']);
        $xVals = $yVals = [];
        foreach ($history as $h) {
            $days = $oldest > 0 ? (strtotime($h['eventDate']) - $oldest) / 86400 : 0;
            $p = $this->parsePace($h['pace']);
            if ($p > 0) { $xVals[] = $days; $yVals[] = $p; }
        }
        $n2 = count($xVals);
        if ($n2 < 2) { return $yVals[0] ?? 0; }
        $sumX = $sumY = $sumXY = $sumX2 = 0;
        for ($i = 0; $i < $n2; $i++) {
            $sumX  += $xVals[$i];
            $sumY  += $yVals[$i];
            $sumXY += $xVals[$i] * $yVals[$i];
            $sumX2 += $xVals[$i] * $xVals[$i];
        }
        $denom = $n2 * $sumX2 - $sumX * $sumX;
        $slope = abs($denom) > 1e-6 ? ($n2 * $sumXY - $sumX * $sumY) / $denom : 0;
        $intercept = ($sumY - $slope * $sumX) / $n2;
        return max(0, $intercept + $slope * ((time() - $oldest) / 86400 + 7));
    }

    /** MLR-style predicted pace: simple average of recent runs weighted by recency (exp decay). */
    private function mlrPace(array $history, array $paces): float
    {
        $n = count($paces);
        if ($n < 2) { return $paces[0] ?? 0; }
        // Weighted average: more recent runs get higher weight
        $total = $sumW = 0;
        $now = time();
        for ($i = 0; $i < $n; $i++) {
            $daysAgo = 0;
            if (isset($history[$i]['eventDate'])) {
                $daysAgo = ($now - strtotime($history[$i]['eventDate'])) / 86400;
            }
            $weight = exp(-$daysAgo / 90); // decay with 90-day half-life
            $total += $paces[$i] * $weight;
            $sumW  += $weight;
        }
        return $sumW > 0 ? ($total / $sumW) : array_sum($paces) / $n;
    }

    private function parsePace(string $pace): float
    {
        if (!$pace || $pace === '00:00:00') { return 0; }
        if (preg_match('#^(\d+):(\d\d):(\d\d)$#', $pace, $m)) {
            return (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3];
        }
        if (preg_match('#^(\d+):(\d\d)$#', $pace, $m)) {
            return (int)$m[1] * 60 + (int)$m[2];
        }
        return 0;
    }

    private function fmtPace(float $seconds): string
    {
        if ($seconds <= 0) { return '00:00:00'; }
        $h = intdiv((int)$seconds, 3600);
        $m = intdiv((int)$seconds % 3600, 60);
        $s = (int)round($seconds) % 60;
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }

    /** Format seconds as integer (for numeric cell storage — Excel applies [h]:mm:ss format). */
    private function fmtSeconds(float $seconds): int
    {
        return (int)round($seconds);
    }

    /** Parse "HH:MM:SS" time string to integer seconds. */
    private function parseTimeToSeconds(string $time): int
    {
        if (!$time) { return 0; }
        $parts = explode(':', $time);
        if (count($parts) === 3) {
            return (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2];
        }
        if (count($parts) === 2) {
            return (int)$parts[0] * 60 + (int)$parts[1];
        }
        return 0;
    }

    private function fmtTime(string $time): string
    {
        return preg_match('#^\d{2}:\d{2}:\d{2}$#', $time) ? $time : ($time ?: '00:00:00');
    }

    private function calcAge(string $dob, string $eventDate): int
    {
        if (!$dob || !$eventDate) { return 0; }
        try {
            return (int)(new \DateTime($eventDate))->diff(new \DateTime($dob))->y;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function dateToDisplay(string $date): string
    {
        if (!$date) { return ''; }
        $p = explode('-', $date);
        if (count($p) !== 3) { return $date; }
        return substr($p[0], -2) !== false ? "{$p[2]}/{$p[1]}/" . substr($p[0], -2) : $date;
    }

    // ---------------------------------------------------------------------------
    // CSV export
    // ---------------------------------------------------------------------------

    private function exportCsv(array $entriesByDiv, string $path, int $x): void
    {
        $fp = fopen($path, 'w');
        $divisionNames = [1 => 'Long Course', 2 => 'Short Course', 3 => 'Junior'];

        foreach ($entriesByDiv as $division => $members) {
            if (empty($members)) { continue; }
            fputcsv($fp, ['=== ' . ($divisionNames[$division] ?? "Division{$division}") . ' ===']);
            fputcsv($fp, ['regNo', 'tagNo', 'firstName', 'lastName', 'age', 'daysSince', 'lastWin', 'lift', 'expectedPace', 'expectedTime', 'handicap', 'startPosition']);
            foreach ($members as $m) {
                fputcsv($fp, [
                    $m['regNo'] ?? '', $m['tagNo'] ?? '', $m['firstName'] ?? '', $m['lastName'] ?? '',
                    $m['age'] ?? 0,
                    $m['daysSince'] ?? -1, $m['lastWin'] ?? -1, $m['lift'] ?? 0,
                    $this->fmtTime($m['expectedPace'] ?? '00:00:00'),
                    $this->fmtTime($m['expectedTime'] ?? '00:00:00'),
                    $this->fmtTime($m['handicap'] ?? '00:00:00'),
                    $m['startPosition'] ?? '',
                ]);
            }
            fputcsv($fp, []);
        }
        fclose($fp);
    }

    // ---------------------------------------------------------------------------
    // XLSX export (PhpSpreadsheet or XML fallback)
    // ---------------------------------------------------------------------------

    private function exportXlsx(array $entriesByDiv, string $path, int $x): void
    {
        if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            error_log("SPREADSHEET-EXPORT: using PhpSpreadsheet path");
            $this->exportXlsxPhpSpreadsheet($entriesByDiv, $x, $path);
        } else {
            error_log("SPREADSHEET-EXPORT: using XML fallback path");
            $this->exportXlsxXmlFallback($entriesByDiv, $x, $path);
        }
    }

    private function exportXlsxPhpSpreadsheet(array $entriesByDiv, int $x, string $path): void
    {
        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $ss->removeSheetByIndex(0);
        $divisionNames = [1 => 'Long Course', 2 => 'Short Course', 3 => 'Junior'];

        foreach ($entriesByDiv as $division => $members) {
            if (empty($members)) { continue; }
            $divName = $divisionNames[$division] ?? "Division{$division}";

            // Participants sheet first
            $partSheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($ss);
            $partSheet->setTitle("Participants {$divName}");
            $ss->addSheet($partSheet, $division * 2 - 1);
            $runnerFirstHistRow = $this->fillParticipantsSheet($partSheet, $members, $x);

            // Division entry sheet
            $entrySheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($ss);
            $entrySheet->setTitle($divName);
            $ss->addSheet($entrySheet, $division * 2);
            $this->fillDivisionSheet($entrySheet, $members, $x, $divName, $runnerFirstHistRow);
        }

        // Add Start list sheets after all division entry sheets (positions 6, 7, 8)
        // Also pass runnerFirstHistRow and entry sheet data row range so formulas can reference division entry sheet
        $startSheetIndex = count($entriesByDiv) * 2; // starts at 6 for 3 divisions
        foreach ($entriesByDiv as $division => $members) {
            if (empty($members)) { continue; }
            $divName = $divisionNames[$division] ?? "Division{$division}";
            $startSheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($ss);
            $startSheet->setTitle("{$divName} Start");
            $ss->addSheet($startSheet, $startSheetIndex);

            // Compute entry sheet data rows (same as fillDivisionSheet):
            // entry sheet is at index (division * 2), data rows 5..4+count
            $entrySheetIndex = $division * 2; // 0-based sheet index
            $firstDataRow = 5;
            $lastDataRow  = 4 + count($members);
            $entrySheetName = $divName;

            $this->fillStartListSheet(
                $startSheet, $members, $divName,
                $entrySheetName, $firstDataRow, $lastDataRow
            );
            $startSheetIndex++;
        }

        if ($ss->getSheetCount() > 0) {
            $ss->setActiveSheetIndex(0);
        }
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
        $writer->setPreCalculateFormulas(false); // fullCalcOnLoad=1: Excel recalculates formulas on open
        $writer->save($path);
    }

    /** Convert 1-based column number to Excel letter (1=A, 27=AA, etc.) */
    private function exportStartListCsv(array $entriesByDiv, string $xlsxPath, int $x): void
    {
        // Build a base path from XLSX path: replace .xlsx with start-list CSVs
        $base = preg_replace('/\.xlsx$/', '', $xlsxPath);
        $divisionNames = [1 => 'Long Course', 2 => 'Short Course', 3 => 'Junior'];

        foreach ($entriesByDiv as $division => $members) {
            if (empty($members)) { continue; }
            $divName = $divisionNames[$division] ?? "Division{$division}";

            // Sort by startPosition (slowest/handicap=0 first)
            $sorted = $members;
            usort($sorted, function ($a, $b) {
                $pa = (int)($a['startPosition'] ?? 0);
                $pb = (int)($b['startPosition'] ?? 0);
                return $pa <=> $pb;
            });

            $distance = (float)($members[0]['_event']['distance'] ?? 5.0);
            $eventDate = $members[0]['_event']['eventDate'] ?? date('d-m-Y');
            $eventDateDisplay = date('d-m-Y', strtotime($eventDate));
            $csvPath = "{$base}_{$divName}_{$distance}km_{$eventDateDisplay}.csv";
            $csvPath = str_replace(' ', '_', $csvPath);

            $fp = fopen($csvPath, 'w');

            // Header matching WebScorer CSV format
            fputcsv($fp, ['First name', 'Last name', 'Gender', 'Distance', 'Category', 'Bib', 'Start time', 'Handicap']);

            foreach ($sorted as $m) {
                $handicapSec = $this->parseTimeToSeconds($m['handicap'] ?? '00:00:00');
                $roundedSec = (int)round($handicapSec / 10) * 10;
                $timeStr = $this->formatHandicap($roundedSec);

                fputcsv($fp, [
                    $m['firstName'] ?? '',
                    $m['lastName'] ?? '',
                    $m['sex'] ?? '',
                    $distance,
                    $divName,
                    $m['tagNo'] ?? '',
                    $timeStr,
                    $timeStr,
                ]);
            }
            fclose($fp);
            $this->log()->info("Start-list CSV: {$csvPath}");
        }
    }

    private function colLetter(int $col): string
    {
        $letters = '';
        while ($col > 0) {
            $col--;
            $letters = chr(65 + ($col % 26)) . $letters;
            $col = intdiv($col, 26);
        }
        return $letters;
    }

    /**
     * Fill a Start List sheet — live-linked to division entry sheet.
     *
     * Formula design:
     *   Each row uses INDEX/MATCH to pull from the division entry sheet.
     *   SMALL(K_col, k) picks the row with the k-th smallest handicap (slowest first).
     *   IFERROR wraps everything so blank rows show empty string.
     *
     * Entry sheet column mapping (current):
     *   A=regNo  B=tagNo  C=firstName  D=lastName  E=age  F=sex  G=daysSince
     *   H=lastWin  I=lift  J=expectedPace(formula)  K=expectedTime(formula)  L=handicap(formula)
     *   startPosition is NOT stored; it is implied by sort order of L (handicap) ascending
     *
     * START sheet columns: A=FirstName B=LastName C=Gender D=Distance E=Category F=Bib G=StartTime H=Handicap
     * G and H = MROUND(entryL, TIME(0,0,10)) with TEXT("+",[h]:mm:ss) wrapper for WebScorer +HH:MM:SS format
     */
    private function fillStartListSheet(
        object $sheet,
        array $members,
        string $divName,
        string $entrySheetName,
        int $firstDataRow,
        int $lastDataRow
    ): void {
        $memberCount = count($members);

        // ── Headers ──────────────────────────────────────────────────────────
        $headers = ['First name','Last name','Gender','Distance','Category','Bib','Start time','Handicap'];
        for ($col = 0; $col < 8; $col++) {
            $sheet->setCellValue($this->colLetter($col + 1) . '1', $headers[$col]);
        }
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);

        // ── Data rows ─────────────────────────────────────────────────────────
        // Entry sheet cols: C=firstName, D=lastName, F=sex, B=tagNo, L=handicap(formula)
        for ($i = 0; $i < $memberCount; $i++) {
            $row   = $i + 2;   // start-sheet row (2-based)
            $k     = $i + 1;   // 1-based rank: 1 = slowest/handicap=0
            $r1    = $firstDataRow;
            $r2    = $lastDataRow;
            $s     = $entrySheetName;

            // MATCH position for k-th smallest handicap (SMALL = slowest first in pursuit race)
            // Uses column M (L + ROW()*0.00001 tiebreaker) so identical handicaps sort uniquely
            $mk = "MATCH(SMALL('{$s}'!M\${$r1}:M\${$r2},{$k}),'{$s}'!M\${$r1}:M\${$r2},0)";

            // Col A: First name (entry sheet col C)
            $sheet->getCell("A{$row}")->setValue(
                "=IFERROR(INDEX('{$s}'!C\${$r1}:C\${$r2},{$mk}),\"\")"
            );
            // Col B: Last name (entry sheet col D)
            $sheet->getCell("B{$row}")->setValue(
                "=IFERROR(INDEX('{$s}'!D\${$r1}:D\${$r2},{$mk}),\"\")"
            );
            // Col C: Gender (entry sheet col F)
            $sheet->getCell("C{$row}")->setValue(
                "=IFERROR(INDEX('{$s}'!F\${$r1}:F\${$r2},{$mk}),\"\")"
            );
            // Col D: Distance (entry sheet C2 = event distance header)
            $sheet->getCell("D{$row}")->setValue("='{$s}'!C2");
            // Col E: Category (static division name)
            $sheet->getCell("E{$row}")->setValue($divName);
            // Col F: Bib / tagNo (entry sheet col B)
            $sheet->getCell("F{$row}")->setValue(
                "=IFERROR(INDEX('{$s}'!B\${$r1}:B\${$r2},{$mk}),\"\")"
            );
            // Col G: Start time = MROUND(handicap, 10sec), +HH:MM:SS text format for WebScorer
            // TEXT(value, "+[h]:mm:ss") formats as +HH:MM:SS with leading plus sign
            $sheet->getCell("G{$row}")->setValue(
                "=IFERROR(\"+\"&TEXT(MROUND(INDEX('{$s}'!L\${$r1}:L\${$r2},{$mk}),TIME(0,0,10)),\"[h]:mm:ss\"),\"\")"
            );
            // Col H: Handicap = same as Start time (pursuit race: start time = handicap)
            $sheet->getCell("H{$row}")->setValue(
                "=IFERROR(\"+\"&TEXT(MROUND(INDEX('{$s}'!L\${$r1}:L\${$r2},{$mk}),TIME(0,0,10)),\"[h]:mm:ss\"),\"\")"
            );
        }

        $sheet->freezePane('A2');
    }

    /** Format seconds as +HH:MM:SS for WebScorer start list (pursuit race). */
    private function formatHandicap(int $seconds): string
    {
        if ($seconds < 0) {
            $neg = -$seconds;
            return '-' . sprintf('%02d:%02d:%02d', intdiv($neg, 3600), intdiv($neg % 3600, 60), $neg % 60);
        }
        return '+' . sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

/** Convert pace string "HH:MM:SS" to total seconds (integer). */
    private function paceToSec(string $pace): int
    {
        return (int)$this->parsePace($pace);
    }

    /** Format seconds as integer (for numeric cell storage with [h]:mm:ss format). */
    private function fmtSec(float $seconds): int
    {
        return (int)round($seconds);
    }

/** Apply elapsed-time format (fraction-of-day storage) to a cell coordinate.
     *  All seconds values are pre-converted to Excel fraction (seconds/86400) BEFORE calling
     *  this method. This only applies the [h]:mm:ss format code. */
    private function fmtTimeCell(string $cell, object $sheet): void
    {
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('[h]:mm:ss');
    }

    /** Convert seconds to Excel fractional-day for [h]:mm:ss time storage. */
    private function secToExcelFraction(int $seconds): float
    {
        return $seconds / 86400.0;
    }

    /**
     * Fill Participants sheet — per-runner history blocks.
     * Returns: [$regNo => first_history_row] map.
     * Pace/time stored as integer seconds with [h]:mm:ss format (calculable in Excel).
     */
    private function fillParticipantsSheet($sheet, array $members, int $x): array
    {
        $runnerFirstHistRow = [];

        // Header
        $headers = ['regNo', 'tagNo', 'firstName', 'lastName', 'age', 'date', 'venue', 'distance', 'pace'];
        foreach ($headers as $col => $h) {
            $sheet->setCellValue($this->colLetter($col + 1) . '1', $h);
        }
        $sheet->getStyle('A1:I1')->getFont()->setBold(true);

        $row = 2;
        foreach ($members as $m) {
            $regNo   = (string)($m['regNo'] ?? '');
            $history = $m['history'] ?? [];

            // Block start row
            $blockStart = $row;
            // First history row = blockStart + 2 (identity + sub-header)
            $firstHistRow = $blockStart + 2;
            $runnerFirstHistRow[$regNo] = $firstHistRow;

            // Identity row (cols 1-5)
            $sheet->setCellValue("A{$row}", $m['regNo'] ?? '');
            $sheet->setCellValue("B{$row}", $m['tagNo'] ?? '');
            $sheet->setCellValue("C{$row}", $m['firstName'] ?? '');
            $sheet->setCellValue("D{$row}", $m['lastName'] ?? '');
            $sheet->setCellValue("E{$row}", $m['age'] ?? 0);
            // History cols blank on identity row
            $sheet->setCellValue("F{$row}", '');
            $sheet->setCellValue("G{$row}", '');
            $sheet->setCellValue("H{$row}", '');
            $sheet->setCellValue("I{$row}", '');
            $row++;

            // Sub-header row (cols 6-9: date, venue, distance, pace)
            $sheet->setCellValue("F{$row}", 'date');
            $sheet->setCellValue("G{$row}", 'venue');
            $sheet->setCellValue("H{$row}", 'distance');
            $sheet->setCellValue("I{$row}", 'pace');
            $row++;

            // History rows — pace stored as integer seconds with [h]:mm:ss format
            for ($i = 0; $i < $x; $i++) {
                $h = ($i < count($history)) ? $history[$i] : null;
                $sheet->setCellValue("F{$row}", $this->dateToDisplay($h['eventDate'] ?? ''));
                $sheet->setCellValue("G{$row}", $h['venue'] ?? '');
                $sheet->setCellValue("H{$row}", $h['distance'] ?? '');
                $sec = $this->paceToSec($h['pace'] ?? '');
                $sheet->setCellValue("I{$row}", $sec > 0 ? $this->secToExcelFraction($sec) : $sec);
                if ($sec > 0) { $this->fmtTimeCell("I{$row}", $sheet); }
                $row++;
            }

            // Stat rows: fastestPace/avgPace + stdDev/lsfPace + mlrPace/method
            $stats = $this->computeStats($history);
            // fastestPace | value
            $sheet->setCellValue("F{$row}", 'fastestPace');
            $fPace = $this->paceToSec($stats['fastestPace']);
            $sheet->setCellValue("G{$row}", $fPace > 0 ? $this->secToExcelFraction($fPace) : $fPace);
            if ($fPace > 0) { $this->fmtTimeCell("G{$row}", $sheet); }
            $sheet->setCellValue("H{$row}", 'avgPace');
            $aPace = $this->paceToSec($stats['avgPace']);
            $sheet->setCellValue("I{$row}", $aPace > 0 ? $this->secToExcelFraction($aPace) : $aPace);
            if ($aPace > 0) { $this->fmtTimeCell("I{$row}", $sheet); }
            $row++;

            // stdDev | value
            $sheet->setCellValue("F{$row}", 'stdDev');
            $sd = $this->paceToSec($stats['stdDev']);
            $sheet->setCellValue("G{$row}", $sd > 0 ? $this->secToExcelFraction($sd) : $sd);
            if ($sd > 0) { $this->fmtTimeCell("G{$row}", $sheet); }
            $sheet->setCellValue("H{$row}", 'lsfPace');
            $lsf = $this->paceToSec($stats['lsfPace']);
            $sheet->setCellValue("I{$row}", $lsf > 0 ? $this->secToExcelFraction($lsf) : $lsf);
            if ($lsf > 0) { $this->fmtTimeCell("I{$row}", $sheet); }
            $row++;

            // mlrPace | value
            $sheet->setCellValue("F{$row}", 'mlrPace');
            $mlr = $this->paceToSec($stats['mlrPace']);
            $sheet->setCellValue("G{$row}", $mlr > 0 ? $this->secToExcelFraction($mlr) : $mlr);
            if ($mlr > 0) { $this->fmtTimeCell("G{$row}", $sheet); }
            $sheet->setCellValue("H{$row}", 'method');
            $sheet->setCellValue("I{$row}", $stats['method']);
            $row++;

            // manualPace | value — handicapper enters their own pace here when method="man"
            $sheet->setCellValue("F{$row}", 'manualPace');
            $sheet->getCell("I{$row}")->setValue(0);
            $this->fmtTimeCell("I{$row}", $sheet);
            $sheet->getStyle("I{$row}")->applyFromArray([
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FFFF00']],
            ]);
            $row++;

            // Blank separator
            $row++;
        }

        $sheet->freezePane('A2');
        return $runnerFirstHistRow;
    }

    /**
     * Fill division entry sheet — one row per runner.
     * Col J (expectedPace): dynamic IF formula → Participants!method + manualPace (yellow, [h]:mm:ss)
     * Col K (expectedTime), L (handicap): formulas auto-update via expectedPace.
     */
    private function fillDivisionSheet($sheet, array $members, int $x, string $divName, array $runnerFirstHistRow): void
    {
        $partSheetName = "Participants {$divName}";

        // Row 1: event header
        foreach (['Date', 'Division', 'Distance', 'ID', 'entrants', 'useLift'] as $col => $h) {
            $sheet->setCellValue($this->colLetter($col + 1) . '1', $h);
        }
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);

        // Row 2: event data
        $evt = $members[0]['_event'] ?? [];
        $sheet->setCellValue('A2', $this->dateToDisplay($evt['eventDate'] ?? ''));
        $sheet->setCellValue('B2', $evt['division'] ?? '');
        $sheet->setCellValue('C2', $evt['distance'] ?? '');
        $sheet->setCellValue('D2', $evt['id'] ?? '');
        // E2: entrants count — handicapper adjusts this for the race (number of runners)
        $memberCount = count($members);
        $sheet->setCellValue('E2', $memberCount);
        $sheet->getStyle('E2')->getFont()->setBold(true);
        $sheet->getCell('E2')->getStyle()->getNumberFormat()->setFormatCode('0');
        // F2: useLift toggle — 0=off (no retard), 1=on (apply retard)
        $sheet->setCellValue('F2', 0);
        $sheet->getStyle('F2')->getFont()->setBold(true);

        // Row 3: blank spacer
        // Row 4: column headers
        $colHeaders = ['regNo', 'tagNo', 'firstName', 'lastName', 'age', 'sex',
                       'daysSince', 'lastWin', 'lift',
                       'expectedPace', 'expectedTime', 'handicap'];
        foreach ($colHeaders as $col => $h) {
            $sheet->setCellValue($this->colLetter($col + 1) . '4', $h);
        }
        $sheet->getStyle('A4:L4')->getFont()->setBold(true);

        // Data rows (starting row 5); last data row = 5 + member_count - 1
        $memberCount = count($members);
        $lastDataRow = 4 + $memberCount; // = 5 + memberCount - 1
        $dataRow = 5;
        foreach ($members as $m) {
            $regNo = (string)($m['regNo'] ?? '');
            $firstHistRow = $runnerFirstHistRow[$regNo] ?? ($dataRow + 2);

            $sheet->setCellValue("A{$dataRow}", $m['regNo'] ?? '');
            $sheet->setCellValue("B{$dataRow}", $m['tagNo'] ?? '');
            $sheet->setCellValue("C{$dataRow}", $m['firstName'] ?? '');
            $sheet->setCellValue("D{$dataRow}", $m['lastName'] ?? '');
            $sheet->setCellValue("E{$dataRow}", $m['age'] ?? 0);
            $sheet->setCellValue("F{$dataRow}", $m['sex'] ?? '');
            $sheet->setCellValue("G{$dataRow}", $m['daysSince'] ?? -1);
            $sheet->setCellValue("H{$dataRow}", $m['lastWin'] ?? -1);

            // Col I: lift = IF(useLift=1, IF(lastWin<0 OR lastWin>=entrants, 0, ((entrants-lastWin-1)/entrants)*expectedTime*5pct*division), 0)
            // F$2=useLift toggle, E$2=entrants, H=lastWin, K=expectedTime, B$2=division
            // lastWin=-1 means never won → lift=0; lastWin>=entrants → lift=0 (committee rule)
            $sheet->getCell("I{$dataRow}")->setValue(
                "=IF(\$F\$2=1,IF(OR(H{$dataRow}<0,H{$dataRow}>=E\$2),0,((E\$2-H{$dataRow}-1)/E\$2)*K{$dataRow}*0.05*\$B\$2),0)"
            );
            $this->fmtTimeCell("I{$dataRow}", $sheet);

            // Col J: expectedPace = dynamic IF formula based on method cell in Participants block
            // Stats layout per runner block (firstHistRow = first history row of 8 history rows):
            //   Stats rows = firstHistRow+8, +9, +10
            //   I{firstHistRow+8} = avgPace,   I{firstHistRow+9} = lsfPace
            //   G{firstHistRow+10} = mlrPace,  G{firstHistRow+8} = fastestPace
            //   I{firstHistRow+10} = method (e.g. "avg", "lsf", "mlr", "fastest", "man")
            //   I{firstHistRow+11} = manualPace (handicap-entered value, yellow cell)
            $p = $partSheetName;
            $fr = $firstHistRow;
            $methodCell = "I" . ($fr + 10);
            $formula = "=IF('{$p}'!{$methodCell}=\"avg\",'{$p}'!I" . ($fr + 8)
                . ",IF('{$p}'!{$methodCell}=\"lsf\",'{$p}'!I" . ($fr + 9)
                . ",IF('{$p}'!{$methodCell}=\"mlr\",'{$p}'!G" . ($fr + 10)
                . ",IF('{$p}'!{$methodCell}=\"fastest\",'{$p}'!G" . ($fr + 8)
                . ",IF('{$p}'!{$methodCell}=\"man\",'{$p}'!I" . ($fr + 11)
                . ",'{$p}'!I" . ($fr + 8) . ")))))";
            $sheet->getCell("J{$dataRow}")->setValue($formula);
            $sheet->getStyle("J{$dataRow}")->applyFromArray([
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FFFF00']],
            ]);
            $this->fmtTimeCell("J{$dataRow}", $sheet); // [h]:mm:ss format for formula result

            // Col K: expectedTime = expectedPace × distance (formula, auto-updates when expectedPace changes)
            // C$2 = distance in event header row
            $sheet->getCell("K{$dataRow}")->setValue("=J{$dataRow}*C\$2");
            $this->fmtTimeCell("K{$dataRow}", $sheet);

            // Col L: handicap = maxTotalTime - totalTime (where totalTime = K + I)
            // The scratch runner (largest K+I) gets handicap 0 regardless of lift.
            // Uses helper cell L$3 = MAX(N$5:N$last) where N = K+I (total time).
            // Column N is a hidden helper: N = K + I.
            $sheet->getCell("L{$dataRow}")->setValue(
                "=L\$3-N{$dataRow}"
            );
            $this->fmtTimeCell("L{$dataRow}", $sheet);

            // Col N: totalTime = expectedTime + lift (hidden helper for MAX)
            $sheet->getCell("N{$dataRow}")->setValue(
                "=K{$dataRow}+I{$dataRow}"
            );
            $this->fmtTimeCell("N{$dataRow}", $sheet);

            // Col M: handicap with tiebreaker — L + ROW()*0.00001 for SMALL/MATCH in Start sheets
            // This breaks ties when multiple runners have identical handicaps (e.g. new members with 0:35:50)
            $sheet->getCell("M{$dataRow}")->setValue(
                "=L{$dataRow}" . '+ROW()*0.00001'
            );
            $sheet->getStyle("M{$dataRow}")->getNumberFormat()->setFormatCode('0.000000');

            $dataRow++;
        }

        // L3: helper cell — max total time, computed from N column (K+I).
        // This ensures the scratch runner (largest K+I) always gets handicap 0.
        // Uses MAX(N) which is a simple range MAX, not an array formula.
        if ($lastDataRow >= 5) {
            $sheet->getCell("L3")->setValue("=MAX(\$N\$5:\$N\${$lastDataRow})");
            $sheet->getStyle('L3')->getNumberFormat()->setFormatCode('[h]:mm:ss');
        }

        $sheet->freezePane('A5');
    }

    // ---------------------------------------------------------------------------
    // XML-based XLSX fallback
    // ---------------------------------------------------------------------------

    private function exportXlsxXmlFallback(array $entriesByDiv, int $x, string $path): void
    {
        $divisionNames = [1 => 'Long Course', 2 => 'Short Course', 3 => 'Junior'];
        $styles = '<Styles>'
            . '<Style ss:ID="Header"><Font ss:Bold="1"/></Style>'
            . '<Style ss:ID="Yellow"><Fill ss:PatternType="Solid" ss:FillColor="#FFFF00"/></Style>'
            . '</Styles>';

        // Build Participants sheets + capture runnerFirstHistRow map
        $allPartSheets = '';
        $runnerFirstHistRowAll = []; // div => [regNo => row]

        foreach ($entriesByDiv as $division => $members) {
            if (empty($members)) { continue; }
            $divName = $divisionNames[$division] ?? "Division{$division}";
            $runnerFirstHistRow = [];

            $xml = '<Row>';
            foreach (['regNo','tagNo','firstName','lastName','age','date','venue','distance','pace'] as $h) {
                $xml .= '<Cell><Data ss:Type="String">' . $this->esc($h) . '</Data></Cell>';
            }
            $xml .= '</Row>';

            $row = 2;
            foreach ($members as $m) {
                $regNo   = (string)($m['regNo'] ?? '');
                $history = $m['history'] ?? [];
                $firstHistRow = $row + 2;
                $runnerFirstHistRow[$regNo] = $firstHistRow;

                // Identity row
                $xml .= '<Row>'
                    . '<Cell><Data ss:Type="String">' . $this->esc($m['regNo'] ?? '') . '</Data></Cell>'
                    . '<Cell><Data ss:Type="String">' . $this->esc($m['tagNo'] ?? '') . '</Data></Cell>'
                    . '<Cell><Data ss:Type="String">' . $this->esc($m['firstName'] ?? '') . '</Data></Cell>'
                    . '<Cell><Data ss:Type="String">' . $this->esc($m['lastName'] ?? '') . '</Data></Cell>'
                    . '<Cell><Data ss:Type="Number">' . (int)($m['age'] ?? 0) . '</Data></Cell>'
                    . '<Cell/><Cell/><Cell/><Cell/>'
                    . '</Row>';
                $row++;

                // Sub-header
                $xml .= '<Row>'
                    . '<Cell/><Cell/><Cell/><Cell/><Cell/>'
                    . '<Cell><Data ss:Type="String">date</Data></Cell>'
                    . '<Cell><Data ss:Type="String">venue</Data></Cell>'
                    . '<Cell><Data ss:Type="String">distance</Data></Cell>'
                    . '<Cell><Data ss:Type="String">pace</Data></Cell>'
                    . '</Row>';
                $row++;

                // History rows
                for ($i = 0; $i < $x; $i++) {
                    $h = ($i < count($history)) ? $history[$i] : null;
                    $xml .= '<Row>'
                        . '<Cell/><Cell/><Cell/><Cell/><Cell/>'
                        . '<Cell><Data ss:Type="String">' . $this->esc($this->dateToDisplay($h['eventDate'] ?? '')) . '</Data></Cell>'
                        . '<Cell><Data ss:Type="String">' . $this->esc($h['venue'] ?? '') . '</Data></Cell>'
                        . '<Cell><Data ss:Type="String">' . $this->esc((string)($h['distance'] ?? '')) . '</Data></Cell>'
                        . '<Cell><Data ss:Type="String">' . $this->esc($h['pace'] ?? '') . '</Data></Cell>'
                        . '</Row>';
                    $row++;
                }

                // Stat rows: fastestPace/avgPace + stdDev/lsfPace + mlrPace/method
                $stats = $this->computeStats($history);
                $xml .= '<Row>'
                    . '<Cell/><Cell/><Cell/><Cell/><Cell/>'
                    . '<Cell><Data ss:Type="String">fastestPace</Data></Cell>'
                    . '<Cell><Data ss:Type="String">' . $this->esc($stats['fastestPace']) . '</Data></Cell>'
                    . '<Cell><Data ss:Type="String">avgPace</Data></Cell>'
                    . '<Cell><Data ss:Type="String">' . $this->esc($stats['avgPace']) . '</Data></Cell>'
                    . '</Row>';
                $row++;

                $xml .= '<Row>'
                    . '<Cell/><Cell/><Cell/><Cell/><Cell/>'
                    . '<Cell><Data ss:Type="String">stdDev</Data></Cell>'
                    . '<Cell><Data ss:Type="String">' . $this->esc($stats['stdDev']) . '</Data></Cell>'
                    . '<Cell><Data ss:Type="String">lsfPace</Data></Cell>'
                    . '<Cell><Data ss:Type="String">' . $this->esc($stats['lsfPace']) . '</Data></Cell>'
                    . '</Row>';
                $row++;

                $xml .= '<Row>'
                    . '<Cell/><Cell/><Cell/><Cell/><Cell/>'
                    . '<Cell><Data ss:Type="String">mlrPace</Data></Cell>'
                    . '<Cell><Data ss:Type="String">' . $this->esc($stats['mlrPace']) . '</Data></Cell>'
                    . '<Cell><Data ss:Type="String">method</Data></Cell>'
                    . '<Cell><Data ss:Type="String">' . $this->esc($stats['method']) . '</Data></Cell>'
                    . '</Row>';
                $row++;

                // Blank separator
                $xml .= '<Row/><Cell/><Cell/><Cell/><Cell/><Cell/><Cell/><Cell/><Cell/><Cell/></Row>';
                $row++;
            }

            $runnerFirstHistRowAll[$division] = $runnerFirstHistRow;
            $allPartSheets .= '<Worksheet ss:Name="Participants ' . $this->esc($divName) . '"><Table>' . $xml . '</Table></Worksheet>';
        }

        // Build division entry sheets
        $allEntrySheets = '';
        foreach ($entriesByDiv as $division => $members) {
            if (empty($members)) { continue; }
            $divName = $divisionNames[$division] ?? "Division{$division}";
            $evt = $members[0]['_event'] ?? [];
            $runnerFirstHistRow = $runnerFirstHistRowAll[$division] ?? [];
            $partSheetName = "Participants {$divName}";

            $xml = '';

            // Row 1: event header
            $xml .= '<Row>'
                . '<Cell ss:StyleID="Header"><Data ss:Type="String">Date</Data></Cell>'
                . '<Cell ss:StyleID="Header"><Data ss:Type="String">Division</Data></Cell>'
                . '<Cell ss:StyleID="Header"><Data ss:Type="String">Distance</Data></Cell>'
                . '<Cell ss:StyleID="Header"><Data ss:Type="String">ID</Data></Cell>'
                . '</Row>';

            // Row 2: event data
            $xml .= '<Row>'
                . '<Cell><Data ss:Type="String">' . $this->esc($this->dateToDisplay($evt['eventDate'] ?? '')) . '</Data></Cell>'
                . '<Cell><Data ss:Type="Number">' . (int)($evt['division'] ?? 0) . '</Data></Cell>'
                . '<Cell><Data ss:Type="Number">' . (float)($evt['distance'] ?? 0) . '</Data></Cell>'
                . '<Cell><Data ss:Type="Number">' . (int)($evt['id'] ?? 0) . '</Data></Cell>'
                . '</Row>';

            // Row 3: blank spacer
            $xml .= '<Row/><Row/>';

            // Row 4: column headers
$colHdrs = ['regNo','tagNo','firstName','lastName','age','sex','daysSince','lastWin','lift',
                         'expectedPace','expectedTime','handicap'];
            $xml .= '<Row>';
            foreach ($colHdrs as $h) {
                $xml .= '<Cell ss:StyleID="Header"><Data ss:Type="String">' . $this->esc($h) . '</Data></Cell>';
            }
            $xml .= '</Row>';

            // Data rows
            $dataRow = 5;
            foreach ($members as $m) {
                $regNo = (string)($m['regNo'] ?? '');
                $firstHistRow = $runnerFirstHistRow[$regNo] ?? ($dataRow + 2);
                $formula = "='{$partSheetName}'!J{$firstHistRow}";
                $cachedPace = $this->fmtTime($m['expectedPace'] ?? '00:00:00');

                $xml .= '<Row>'
                    . '<Cell><Data ss:Type="String">' . $this->esc($m['regNo'] ?? '') . '</Data></Cell>'
                    . '<Cell><Data ss:Type="String">' . $this->esc($m['tagNo'] ?? '') . '</Data></Cell>'
                    . '<Cell><Data ss:Type="String">' . $this->esc($m['firstName'] ?? '') . '</Data></Cell>'
                    . '<Cell><Data ss:Type="String">' . $this->esc($m['lastName'] ?? '') . '</Data></Cell>'
                    . '<Cell><Data ss:Type="Number">' . (int)($m['age'] ?? 0) . '</Data></Cell>'
                    . '<Cell><Data ss:Type="String">' . $this->esc($m['sex'] ?? '') . '</Data></Cell>'
                    . '<Cell><Data ss:Type="Number">' . (int)($m['daysSince'] ?? -1) . '</Data></Cell>'
                    . '<Cell><Data ss:Type="Number">' . (int)($m['lastWin'] ?? -1) . '</Data></Cell>'
                    . '<Cell><Data ss:Type="Number">' . (int)($m['lift'] ?? 0) . '</Data></Cell>'
                    // expectedPace: yellow, OOXML formula cell — formula uses literal apostrophes, not XML-escaped
                    . '<Cell ss:StyleID="Yellow"><f>' . $formula . '</f><v>' . $this->esc($cachedPace) . '</v></Cell>'
                    . '<Cell><Data ss:Type="String">' . $this->esc($this->fmtTime($m['expectedTime'] ?? '00:00:00')) . '</Data></Cell>'
                    . '<Cell><Data ss:Type="String">' . $this->esc($this->fmtTime($m['handicap'] ?? '00:00:00')) . '</Data></Cell>'
                    . '</Row>';
                $dataRow++;
            }

            $allEntrySheets .= '<Worksheet ss:Name="' . $this->esc($divName) . '"><Table>' . $xml . '</Table></Worksheet>';
        }

        $xmlOut = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<?mso-application progid="Excel.Sheet"?>'
            . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
            . $styles
            . $allPartSheets
            . $allEntrySheets
            . '</Workbook>';

        file_put_contents($path, $xmlOut);
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    private function esc(mixed $s): string
    {
        return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function getOutputDir(int $eventId): string
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $dir = $base . '/storage/app/' . $this->config['storage_path'] . "/{$eventId}/exports";
        if (!is_dir($dir)) { mkdir($dir, 0755, true); }
        return $dir;
    }
}