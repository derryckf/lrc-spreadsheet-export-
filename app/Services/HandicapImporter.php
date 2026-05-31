<?php
declare(strict_types=1);
namespace App\Services;

/**
 * Reads a completed handicapper spreadsheet (XLSX or CSV) and writes
 * expectedPace, expectedTime, handicap, and startPosition back to the
 * eventEntry table.
 *
 * Flow:
 *   1. Read all member blocks from the spreadsheet
 *   2. Find rows where expectedPace has been edited (changed from pre-populated value)
 *   3. Look up member_id via regNo
 *   4. Update eventEntry.expectedPace, expectedTime
 *   5. Recompute handicap = longestExpectedTime - thisExpectedTime
 *   6. Assign startPosition by expectedTime DESC (1 = first off scratch)
 *   7. Write all four fields to eventEntry
 *
 * Repeatable — last run wins.
 */
class HandicapImporter
{
    private $db;
    private $logger;
    private array $config;

    public function __construct($db, $logger = null, array $config = [])
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->config = array_merge([
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

    /**
     * Import from a completed spreadsheet.
     *
     * @param int $eventId
     * @param string $filePath  absolute path to .xlsx or .csv
     * @param array $originalExpectedPaces  map of regNo => original expectedPace (from Step 3)
     * @return array  ['updated' => int, 'skipped' => int, 'errors' => string[]]
     */
    public function import(int $eventId, string $filePath, array $originalExpectedPaces = []): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        $this->log()->info("Importing {$filePath} for event {$eventId}");

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext === 'xlsx' || $ext === 'xls') {
            $blocks = $this->readBlocksFromXlsx($filePath);
        } else {
            $blocks = $this->readBlocksFromCsv($filePath);
        }

        $this->log()->info('Found ' . count($blocks) . ' member blocks');

        // Build a lookup: regNo => member_id
        $regNoToMemberId = $this->buildMemberIdMap($eventId);

        $updated = 0;
        $skipped = 0;
        $errors = [];

        // Collect all entries for this event for handicap/startPosition computation
        $allEntries = $this->loadAllEntries($eventId);
        $distance = $allEntries[0]['distance'] ?? 5.0;

        // Build member_id => original expectedPace map
        $memberOriginalPace = $this->buildOriginalPaceMap($eventId, $originalExpectedPaces);

        // Apply edited expectedPace from spreadsheet to our entry list
        $editedCount = 0;
        foreach ($blocks as $block) {
            $regNo = $block['regNo'] ?? null;
            $editedPace = $block['expectedPace'] ?? null;

            if (!$regNo) {
                $errors[] = 'Block missing regNo, skipped';
                $skipped++;
                continue;
            }
            if (!$editedPace || $editedPace === '') {
                $this->log()->debug("regNo {$regNo}: empty pace, skip");
                $skipped++;
                continue;
            }

            // Look up member_id
            $memberId = $regNoToMemberId[$regNo] ?? null;
            if (!$memberId) {
                $this->log()->debug("regNo {$regNo}: not in regNoToMemberId map (keys=" . implode(',', array_keys($regNoToMemberId)) . ")");
                $errors[] = "regNo {$regNo}: no member_id found, skipped";
                $skipped++;
                continue;
            }

            // Find the entry for this member+event
            $entryKey = $memberId . '_' . $eventId;
            if (!isset($allEntries[$entryKey])) {
                $errors[] = "regNo {$regNo}: no eventEntry found for event {$eventId}";
                $skipped++;
                continue;
            }

            // Validate pace
            if (!$this->isValidPace($editedPace)) {
                $errors[] = "regNo {$regNo}: invalid expectedPace '{$editedPace}', skipped";
                $skipped++;
                continue;
            }

            // Detect if value changed from original
            $original = $memberOriginalPace[$memberId] ?? null;
            $changed = ($original !== null) && ($original !== $editedPace);
            if ($changed) {
                $this->log()->info("regNo {$regNo}: expectedPace changed from '{$original}' to '{$editedPace}'");
            }

            // Compute expectedTime
            $expectedTime = $this->computeExpectedTime($editedPace, $distance);

            // Store for later
            $allEntries[$entryKey]['editedPace'] = $editedPace;
            $allEntries[$entryKey]['editedTime'] = $expectedTime;
            $allEntries[$entryKey]['wasEdited'] = $changed;
            $editedCount++;
        }

        $this->log()->info("Edited entries: {$editedCount}");

        // Compute handicaps: handicap = longestExpectedTime - eachExpectedTime
        // Sort by expectedTime DESC (slowest first = highest handicap)
        $sorted = $allEntries;
        usort($sorted, function ($a, $b) {
            $ta = $this->parseTimeToSeconds($a['editedTime'] ?? $a['expectedTime'] ?? '00:00:00');
            $tb = $this->parseTimeToSeconds($b['editedTime'] ?? $b['expectedTime'] ?? '00:00:00');
            return $tb <=> $ta; // DESC
        });

        $maxSeconds = 0;
        foreach ($sorted as $entry) {
            $secs = $this->parseTimeToSeconds($entry['editedTime'] ?? $entry['expectedTime'] ?? '00:00:00');
            if ($secs > $maxSeconds) {
                $maxSeconds = $secs;
            }
        }

        $startPosition = 1;
        $results = [];
        foreach ($sorted as $entry) {
            $entryKey = $entry['member_id'] . '_' . $eventId;
            $editedTime = $entry['editedTime'] ?? $entry['expectedTime'] ?? '00:00:00';
            $editedPace = $entry['editedPace'] ?? $entry['expectedPace'] ?? '00:00:00';

            $handicap = $maxSeconds - $this->parseTimeToSeconds($editedTime);
            $handicapStr = $this->formatSecondsToHis($handicap);

            $results[] = [
                'member_id' => $entry['member_id'],
                'entry_id' => $entry['id'],
                'editedPace' => $editedPace,
                'editedTime' => $editedTime,
                'handicap' => $handicapStr,
                'startPosition' => $startPosition,
                'wasEdited' => $entry['wasEdited'] ?? false,
            ];
            $startPosition++;
        }

        // Write back to DB — only entries that were actually edited (from spreadsheet)
        $writeCount = 0;
        foreach ($results as $r) {
            if (!($r['wasEdited'] ?? false)) {
                // Entry was in allEntries but not touched by spreadsheet → skip
                continue;
            }
            $ok = $this->writeEventEntry($r['entry_id'], $r['editedPace'], $r['editedTime'], $r['handicap'], $r['startPosition']);
            if ($ok) {
                $writeCount++;
            } else {
                $errors[] = 'Failed to write entry_id ' . $r['entry_id'];
            }
        }

        $this->log()->info("Wrote {$writeCount} eventEntry records");
        return [
            'updated' => $writeCount,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    // ---------------------------------------------------------------------------
    // Spreadsheet reading
    // ---------------------------------------------------------------------------

    /**
     * Read member blocks from an XLSX file (PhpSpreadsheet or XML fallback).
     * Returns array of ['regNo' => ..., 'expectedPace' => ..., ...]
     */
    private function readBlocksFromXlsx(string $path): array
    {
        if (class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            return $this->readBlocksFromXlsxPhpSpreadsheet($path);
        }
        return $this->readBlocksFromXlsxXmlFallback($path);
    }

    private function readBlocksFromXlsxPhpSpreadsheet(string $path): array
    {
        $ss = \PhpOffice\PhpSpreadsheet\PhpSpreadsheet\IOFactory::load($path);
        $blocks = [];

        foreach ($ss->getAllSheets() as $sheet) {
            $sheetBlocks = $this->extractBlocksFromSheet($sheet);
            $blocks = array_merge($blocks, $sheetBlocks);
        }

        return $blocks;
    }

    private function readBlocksFromXlsxXmlFallback(string $path): array
    {
        // Minimal XML XLSX reader — extracts raw cell values
        $xml = file_get_contents($path);
        $blocks = [];

        if (preg_match_all('/<Row[^>]*>(.*?)<\/Row>/is', $xml, $rowMatches)) {
            foreach ($rowMatches[0] as $rowXml) {
                if (preg_match_all('/<Cell[^>]*>.*?<Data[^>]*>(.*?)<\/Data>.*?<\/Cell>/is', $rowXml, $cellMatches)) {
                    $values = array_map(function ($v) {
                        return trim(strip_tags($v));
                    }, $cellMatches[1]);
                    // Heuristic: first non-empty cell in first row of a block = regNo
                    if (!empty($values) && is_numeric($values[0])) {
                        $blocks[] = [
                            'regNo' => $values[0],
                            'expectedPace' => $values[1] ?? '',
                        ];
                    }
                }
            }
        }

        return $blocks;
    }

    /**
     * Extract member blocks from a single PhpSpreadsheet sheet.
     * Each block spans 8 columns (4 info + 4 history cols).
     * The expectedPace editable cell is at col offset 3, row (4+x).
     */
    private function extractBlocksFromSheet($sheet): array
    {
        $blocks = [];
        $highestCol = $sheet->getHighestColumnIndex();

        // Scan horizontally across columns in groups of 8
        $col = 1;
        while ($col < $highestCol) {
            // Read row 1: regNo, firstName, lastName, age
            $regNo = $this->getCellValue($sheet, $col, 1);
            $firstName = $this->getCellValue($sheet, $col + 1, 1);
            $lastName = $this->getCellValue($sheet, $col + 2, 1);

            if (!$regNo || !is_numeric($regNo)) {
                // No more member blocks in this row
                break;
            }

            // The expectedPace is at (col + 3, row 5+x). We scan from row 1 to
            // find the last row of this block (stats row + expectedPace row).
            // We'll read the expectedPace cell at a fixed offset from the top.
            // Find last used row in this block's columns
            $lastDataRow = 1;
            for ($r = 1; $r <= $sheet->getHighestRow(); $r++) {
                if ($this->getCellValue($sheet, $col, $r) !== null) {
                    $lastDataRow = $r;
                }
            }

            // The expectedPace row is the last row of the block
            // We look for a row that contains "expectedPace:" label in col $col
            // and the value in col $col + 3
            $expectedPace = null;
            $statsRow = null;
            for ($r = 1; $r <= $sheet->getHighestRow(); $r++) {
                $label = $this->getCellValue($sheet, $col, $r);
                if ($label === 'expectedPace:') {
                    $statsRow = $r;
                    $expectedPace = $this->getCellValue($sheet, $col + 3, $r);
                    break;
                }
            }

            // Fallback: infer from structure — if we know x=8, stats row = 3+8=11, expectedPace row = 12
            if ($expectedPace === null) {
                // Try reading row 12 (for x=8) as expectedPace
                $expectedPace = $this->getCellValue($sheet, $col + 3, 12);
            }

            $blocks[] = [
                'regNo' => (int)$regNo,
                'firstName' => $firstName,
                'lastName' => $lastName,
                'expectedPace' => $expectedPace,
            ];

            $col += 8; // advance past this block
        }

        return $blocks;
    }

    private function getCellValue($sheet, int $col, int $row): ?string
    {
        $cell = $sheet->getCellByColumnAndRow($col, $row);
        $val = $cell->getValue();
        if ($val === null) {
            return null;
        }
        return trim((string)$val);
    }

    private function readBlocksFromCsv(string $path): array
    {
        $blocks = [];
        $fp = fopen($path, 'r');
        if (!$fp) {
            throw new \RuntimeException("Cannot open {$path}");
        }

        $currentBlock = null;
        while (($row = fgetcsv($fp)) !== false) {
            // Skip blank rows and section headers
            if (count($row) === 1 && empty(trim($row[0]))) {
                continue;
            }
            if (preg_match('/^==/', $row[0] ?? '')) {
                continue;
            }
            // CSV format: regNo, firstName, lastName, age, expectedPace, method
            if (count($row) >= 5 && is_numeric(trim($row[0]))) {
                $blocks[] = [
                    'regNo' => (int)trim($row[0]),
                    'firstName' => trim($row[1] ?? ''),
                    'lastName' => trim($row[2] ?? ''),
                    'expectedPace' => trim($row[4] ?? ''),
                ];
            }
        }
        fclose($fp);
        return $blocks;
    }

    // ---------------------------------------------------------------------------
    // DB helpers
    // ---------------------------------------------------------------------------

    private function buildMemberIdMap(int $eventId): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.regNo, m.id as member_id, ee.id as entry_id'
            . ' FROM eventEntry ee'
            . ' JOIN member m ON ee.member_id = m.id'
            . ' WHERE ee.event_id = :eid'
        );
        $stmt->execute(['eid' => $eventId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $r) {
            $map[(string)$r['regNo']] = [
                'member_id' => (int)$r['member_id'],
                'entry_id' => (int)$r['entry_id'],
            ];
        }
        return $map;
    }

    private function loadAllEntries(int $eventId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ee.id, ee.member_id, ee.event_id, ee.expectedPace, ee.expectedTime, e.distance'
            . ' FROM eventEntry ee'
            . ' JOIN event e ON ee.event_id = e.id'
            . ' WHERE ee.event_id = :eid AND ee.able = 1'
        );
        $stmt->execute(['eid' => $eventId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            $key = $r['member_id'] . '_' . $r['event_id'];
            $out[$key] = $r;
        }
        return $out;
    }

    private function buildOriginalPaceMap(int $eventId, array $originalExpectedPaces): array
    {
        if (!empty($originalExpectedPaces)) {
            return $originalExpectedPaces;
        }
        // Load from eventEntry for this event
        $stmt = $this->db->prepare(
            'SELECT ee.member_id, ee.expectedPace'
            . ' FROM eventEntry ee'
            . ' WHERE ee.event_id = :eid'
        );
        $stmt->execute(['eid' => $eventId]);
        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $mid = (int)($r['member_id'] ?? 0);
            if ($mid > 0) {
                $map[$mid] = $r['expectedPace'] ?? '';
            }
        }
        return $map;
    }

    private function writeEventEntry(int $entryId, string $expectedPace, string $expectedTime, string $handicap, int $startPosition): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE eventEntry SET'
            . ' expectedPace = :pace,'
            . ' expectedTime = :time,'
            . ' handicap = :handicap,'
            . ' startPosition = :pos,'
            . ' lastModDate = NOW()'
            . ' WHERE id = :id'
        );
        return $stmt->execute([
            'pace' => $expectedPace,
            'time' => $expectedTime,
            'handicap' => $handicap,
            'pos' => $startPosition,
            'id' => $entryId,
        ]);
    }

    // ---------------------------------------------------------------------------
    // Time utilities
    // ---------------------------------------------------------------------------

    /**
     * Parse "HH:MM:SS" or "M:SS" pace/time to total seconds.
     */
    private function parseTimeToSeconds(string $time): int
    {
        $time = trim($time);
        if (!$time) {
            return 0;
        }
        $parts = explode(':', $time);
        if (count($parts) === 2) {
            return (int)$parts[0] * 60 + (int)$parts[1];
        }
        if (count($parts) === 3) {
            return (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2];
        }
        return 0;
    }

    private function formatSecondsToHis(int $seconds): string
    {
        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);
        $s = $seconds % 60;
        return sprintf('%d:%02d:%02d', $h, $m, $s);
    }

    private function computeExpectedTime(string $expectedPace, float $distance): string
    {
        $paceSec = $this->parseTimeToSeconds($expectedPace);
        $totalSec = (int)($paceSec * $distance);
        return $this->formatSecondsToHis($totalSec);
    }

    private function isValidPace(string $pace): bool
    {
        return $this->parseTimeToSeconds($pace) > 0;
    }
}
