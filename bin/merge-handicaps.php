<?php
/**
 * Merge Pat's handicap edits from Drive spreadsheet into the new 8-runner export.
 *
 * What gets merged:
 *   - Long Course, Short Course, Junior analysis sheets: handicap column (L)
 *     Pat overwrote formulas with static values — we copy those static values over.
 *
 * What is NOT merged (must be regenerated):
 *   - All Start-list sheets (Long Course Start, Short Course Start, Junior Start,
 *     Short+Junior Start) — these contain live INDEX/MATCH formulas referencing
 *     the analysis sheet columns. They auto-update from the analysis data.
 *
 * What stays from the new export (not from Drive):
 *   - All Participants sheets (they're reference data for Pat's analysis)
 *   - All analysis sheet columns OTHER than handicap (L): expectedPace,
 *     expectedTime, avgPace, paceSD, etc.
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

$driveFile = $argv[1] ?? '/tmp/westbury_drive.xlsx';
$newFile   = $argv[2] ?? __DIR__ . '/../storage/app/handicapping/1871/exports/1871_all-divisions_Councillor_Anne_Marie_Loader_Westbury.xlsx';
$outFile   = $argv[3] ?? '/tmp/westbury_merged_final.xlsx';

echo "Drive:  $driveFile\n";
echo "New:    $newFile\n";
echo "Output: $outFile\n\n";

// ──────────────────────────────────────────────────────────────────────────────
// STEP 1: Load Drive spreadsheet and extract handicap edits
// ──────────────────────────────────────────────────────────────────────────────
echo "=== Step 1: Extract Drive handicaps ===\n";
$driveSS = IOFactory::load($driveFile);

$sheetMap = ['Long Course', 'Short Course', 'Junior'];
$driveHC = []; // $driveHC[$sheet][$name] = $handicapValue

foreach ($sheetMap as $sname) {
    $sheet = $driveSS->getSheetByName($sname);
    if (!$sheet) { echo "WARNING: Drive sheet '$sname' not found\n"; continue; }
    $rows = $sheet->toArray();

    $hcIdx = $firstIdx = $lastIdx = null;
    $dataStart = null;
    for ($i = 0; $i < count($rows); $i++) {
        $hcIdx = array_search('handicap', $rows[$i]);
        if ($hcIdx !== false) {
            $firstIdx = array_search('firstName', $rows[$i]);
            $lastIdx  = array_search('lastName',  $rows[$i]);
            $dataStart = $i + 1;
            break;
        }
    }
    if ($dataStart === null) { echo "WARNING: No handicap in Drive '$sname'\n"; continue; }

    for ($i = $dataStart; $i < count($rows); $i++) {
        $row = $rows[$i];
        $first = trim((string)($row[$firstIdx] ?? ''));
        $last  = trim((string)($row[$lastIdx]  ?? ''));
        $hc    = $row[$hcIdx] ?? '';
        if ($first === '' && $last === '') continue;
        $name = "$first $last";
        $driveHC[$sname][$name] = $hc;
    }
    echo "  Drive '$sname': " . count($driveHC[$sname] ?? []) . " runners\n";
}

// ──────────────────────────────────────────────────────────────────────────────
// STEP 2: Load new spreadsheet and apply handicap patches
// ──────────────────────────────────────────────────────────────────────────────
echo "\n=== Step 2: Apply handicap patches to new spreadsheet ===\n";
$newSS = IOFactory::load($newFile);

$changes = 0;
foreach ($sheetMap as $sname) {
    $sheet = $newSS->getSheetByName($sname);
    if (!$sheet) { echo "WARNING: New sheet '$sname' not found\n"; continue; }
    $rows = $sheet->toArray();
    $highestRow = $sheet->getHighestRow();

    // Find header row
    $hcIdx = $firstIdx = $lastIdx = null;
    $dataStart = null;
    for ($r = 0; $r < count($rows) && $r < 10; $r++) {
        $hcIdx = array_search('handicap', $rows[$r]);
        if ($hcIdx !== false) {
            $firstIdx = array_search('firstName', $rows[$r]);
            $lastIdx  = array_search('lastName',  $rows[$r]);
            $dataStart = $r + 1;
            break;
        }
    }
    if ($hcIdx === false) { echo "WARNING: No handicap in new '$sname'\n"; continue; }

    $colLetter = Coordinate::stringFromColumnIndex($hcIdx + 1);
    $drive = $driveHC[$sname] ?? [];
    $sheetChanges = 0;

    for ($r = $dataStart; $r < count($rows); $r++) {
        $row = $rows[$r];
        $first = trim((string)($row[$firstIdx] ?? ''));
        $last  = trim((string)($row[$lastIdx]  ?? ''));
        if ($first === '' && $last === '') continue;
        $name = "$first $last";
        if (isset($drive[$name])) {
            $cellAddr = $colLetter . ($r + 1);
            $oldVal = $sheet->getCell($cellAddr)->getValue();
            $sheet->getCell($cellAddr)->setValue($drive[$name]);
            printf("  %s | %s: '%s' -> '%s'\n", $sname, $name, (string)$oldVal, $drive[$name]);
            $changes++;
            $sheetChanges++;
        }
    }
    echo "  Sheet '$sname': $sheetChanges patches applied\n";
}

// ──────────────────────────────────────────────────────────────────────────────
// STEP 3: Regenerate Start-list sheets from analysis data
// The analysis sheets now have correct handicap values (Pat's + our new runners).
// We rewrite the Start-list formulas from scratch using pre-computed data
// from the analysis sheets (bypassing the broken INDEX/MATCH chains).
// ──────────────────────────────────────────────────────────────────────────────
echo "\n=== Step 3: Regenerate Start-list sheets ===\n";

// Collect analysis data: for each division, build [$name => $runnerData]
$analysisData = [];
foreach ($sheetMap as $sname) {
    $sheet = $newSS->getSheetByName($sname);
    $rows = $sheet->toArray();
    $analysisData[$sname] = [];

    $hcIdx = $firstIdx = $lastIdx = null;
    for ($r = 0; $r < count($rows) && $r < 10; $r++) {
        $hcIdx = array_search('handicap', $rows[$r]);
        if ($hcIdx !== false) {
            $firstIdx = array_search('firstName', $rows[$r]);
            $lastIdx  = array_search('lastName',  $rows[$r]);
            $dataStart = $r + 1;
            break;
        }
    }
    if ($hcIdx === false) continue;

    // Find sex column
    $sexIdx = array_search('sex', $rows[$dataStart - 1] ?? []);

    for ($r = $dataStart; $r < count($rows); $r++) {
        $row = $rows[$r];
        $first = trim((string)($row[$firstIdx] ?? ''));
        $last  = trim((string)($row[$lastIdx]  ?? ''));
        $bib   = $row[1] ?? '';  // col B = tagNo
        $sex   = ($sexIdx !== false) ? ($row[$sexIdx] ?? '') : '';
        $hc    = $row[$hcIdx] ?? '';
        if ($first === '' && $last === '') continue;
        $analysisData[$sname][] = [
            'firstName' => $first,
            'lastName'  => $last,
            'bib'       => $bib,
            'sex'       => $sex,
            'handicap'  => $hc,
        ];
    }
    echo "  $sname: " . count($analysisData[$sname]) . " runners\n";
}

// Helper: parse "+HH:MM:SS" or "HH:MM:SS" string to total seconds
function parseHandicapSec(string $hc): int {
    $hc = ltrim($hc, '+');
    if ($hc === '' || $hc === null) return 0;
    // Handle "1:02:08" format
    $parts = explode(':', $hc);
    if (count($parts) === 3) {
        return (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2];
    } elseif (count($parts) === 2) {
        return (int)$parts[0] * 60 + (int)$parts[1];
    }
    return (int)$hc;
}

// Helper: format seconds to "+HH:MM:SS"
function formatHandicapSec(int $sec): string {
    $neg = $sec < 0;
    $sec = abs($sec);
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    $s = $sec % 60;
    return ($neg ? '-' : '+') . sprintf('%d:%02d:%02d', $h, $m, $s);
}

// Sort runners by handicap ascending (slowest first = pursuit race start order)
foreach ($analysisData as $sname => &$runners) {
    usort($runners, fn($a, $b) => parseHandicapSec($a['handicap']) - parseHandicapSec($b['handicap']));
}
unset($runners);

// Map short sheet names to full names
$shortNames = ['Long Course' => 'Long Course', 'Short Course' => 'Short Course', 'Junior' => 'Junior'];

// Short Course members for Short+Junior
$scMembers = $analysisData['Short Course'] ?? [];
$junMembers = $analysisData['Junior'] ?? [];

// Combined Short+Junior sorted by handicap (slowest first)
$combined = [];
foreach ($scMembers as $m) { $m['category'] = 'Short Course'; $combined[] = $m; }
foreach ($junMembers as $m) { $m['category'] = 'Junior';      $combined[] = $m; }
usort($combined, fn($a, $b) => parseHandicapSec($a['handicap']) - parseHandicapSec($b['handicap']));

// --- Short Course Start ---
echo "  Regenerating Short Course Start...\n";
$scStartSheet = $newSS->getSheetByName('Short Course Start');
// Clear existing content
foreach ($scStartSheet->getRowIterator() as $ri) {
    $scStartSheet->removeRow($ri->getRowIndex());
}
$scStartSheet->setCellValue('A1', 'First name');
$scStartSheet->setCellValue('B1', 'Last name');
$scStartSheet->setCellValue('C1', 'Gender');
$scStartSheet->setCellValue('D1', 'Distance');
$scStartSheet->setCellValue('E1', 'Category');
$scStartSheet->setCellValue('F1', 'Bib');
$scStartSheet->setCellValue('G1', 'Start time');
$scStartSheet->setCellValue('H1', 'Handicap');
$scStartSheet->getStyle('A1:H1')->getFont()->setBold(true);

foreach ($scMembers as $i => $m) {
    $row = $i + 2;
    $hcSec = parseHandicapSec($m['handicap']);
    $rounded = (int)(($hcSec + 5) / 10) * 10; // round to nearest 10 seconds
    $timeStr = formatHandicapSec($rounded);
    $scStartSheet->setCellValue("A{$row}", $m['firstName']);
    $scStartSheet->setCellValue("B{$row}", $m['lastName']);
    $scStartSheet->setCellValue("C{$row}", $m['sex'] ?? '');
    $scStartSheet->setCellValue("D{$row}", '2.5');
    $scStartSheet->setCellValue("E{$row}", 'Short Course');
    $scStartSheet->setCellValue("F{$row}", (string)$m['bib']);
    $scStartSheet->setCellValue("G{$row}", $timeStr);
    $scStartSheet->setCellValue("H{$row}", $timeStr);
}
echo "  Short Course Start: " . count($scMembers) . " runners written\n";

// --- Junior Start ---
echo "  Regenerating Junior Start...\n";
$junStartSheet = $newSS->getSheetByName('Junior Start');
foreach ($junStartSheet->getRowIterator() as $ri) {
    $junStartSheet->removeRow($ri->getRowIndex());
}
$junStartSheet->setCellValue('A1', 'First name');
$junStartSheet->setCellValue('B1', 'Last name');
$junStartSheet->setCellValue('C1', 'Gender');
$junStartSheet->setCellValue('D1', 'Distance');
$junStartSheet->setCellValue('E1', 'Category');
$junStartSheet->setCellValue('F1', 'Bib');
$junStartSheet->setCellValue('G1', 'Start time');
$junStartSheet->setCellValue('H1', 'Handicap');
$junStartSheet->getStyle('A1:H1')->getFont()->setBold(true);

foreach ($junMembers as $i => $m) {
    $row = $i + 2;
    $hcSec = parseHandicapSec($m['handicap']);
    $rounded = (int)(($hcSec + 5) / 10) * 10;
    $timeStr = formatHandicapSec($rounded);
    $junStartSheet->setCellValue("A{$row}", $m['firstName']);
    $junStartSheet->setCellValue("B{$row}", $m['lastName']);
    $junStartSheet->setCellValue("C{$row}", $m['sex'] ?? '');
    $junStartSheet->setCellValue("D{$row}", '1.5');
    $junStartSheet->setCellValue("E{$row}", 'Junior');
    $junStartSheet->setCellValue("F{$row}", (string)$m['bib']);
    $junStartSheet->setCellValue("G{$row}", $timeStr);
    $junStartSheet->setCellValue("H{$row}", $timeStr);
}
echo "  Junior Start: " . count($junMembers) . " runners written\n";

// --- Short+Junior Start ---
echo "  Regenerating Short+Junior Start...\n";
$combStartSheet = $newSS->getSheetByName('Short+Junior Start');
foreach ($combStartSheet->getRowIterator() as $ri) {
    $combStartSheet->removeRow($ri->getRowIndex());
}
$combStartSheet->setCellValue('A1', 'First name');
$combStartSheet->setCellValue('B1', 'Last name');
$combStartSheet->setCellValue('C1', 'Gender');
$combStartSheet->setCellValue('D1', 'Distance');
$combStartSheet->setCellValue('E1', 'Category');
$combStartSheet->setCellValue('F1', 'Bib');
$combStartSheet->setCellValue('G1', 'Start time');
$combStartSheet->setCellValue('H1', 'Handicap');
$combStartSheet->getStyle('A1:H1')->getFont()->setBold(true);

foreach ($combined as $i => $m) {
    $row = $i + 2;
    $hcSec = parseHandicapSec($m['handicap']);
    $rounded = (int)(($hcSec + 5) / 10) * 10;
    $timeStr = formatHandicapSec($rounded);
    $combStartSheet->setCellValue("A{$row}", $m['firstName']);
    $combStartSheet->setCellValue("B{$row}", $m['lastName']);
    $combStartSheet->setCellValue("C{$row}", $m['sex'] ?? '');
    $combStartSheet->setCellValue("D{$row}", $m['category'] === 'Short Course' ? '2.5' : '1.5');
    $combStartSheet->setCellValue("E{$row}", $m['category']);
    $combStartSheet->setCellValue("F{$row}", (string)$m['bib']);
    $combStartSheet->setCellValue("G{$row}", $timeStr);
    $combStartSheet->setCellValue("H{$row}", $timeStr);
}
echo "  Short+Junior Start: " . count($combined) . " runners written\n";

// --- Long Course Start ---
echo "  Regenerating Long Course Start...\n";
$lcMembers = $analysisData['Long Course'] ?? [];
$lcStartSheet = $newSS->getSheetByName('Long Course Start');
foreach ($lcStartSheet->getRowIterator() as $ri) {
    $lcStartSheet->removeRow($ri->getRowIndex());
}
$lcStartSheet->setCellValue('A1', 'First name');
$lcStartSheet->setCellValue('B1', 'Last name');
$lcStartSheet->setCellValue('C1', 'Gender');
$lcStartSheet->setCellValue('D1', 'Distance');
$lcStartSheet->setCellValue('E1', 'Category');
$lcStartSheet->setCellValue('F1', 'Bib');
$lcStartSheet->setCellValue('G1', 'Start time');
$lcStartSheet->setCellValue('H1', 'Handicap');
$lcStartSheet->getStyle('A1:H1')->getFont()->setBold(true);

foreach ($lcMembers as $i => $m) {
    $row = $i + 2;
    $hcSec = parseHandicapSec($m['handicap']);
    $rounded = (int)(($hcSec + 5) / 10) * 10;
    $timeStr = formatHandicapSec($rounded);
    $lcStartSheet->setCellValue("A{$row}", $m['firstName']);
    $lcStartSheet->setCellValue("B{$row}", $m['lastName']);
    $lcStartSheet->setCellValue("C{$row}", $m['sex'] ?? '');
    $lcStartSheet->setCellValue("D{$row}", '8');
    $lcStartSheet->setCellValue("E{$row}", 'Long Course');
    $lcStartSheet->setCellValue("F{$row}", (string)$m['bib']);
    $lcStartSheet->setCellValue("G{$row}", $timeStr);
    $lcStartSheet->setCellValue("H{$row}", $timeStr);
}
echo "  Long Course Start: " . count($lcMembers) . " runners written\n";

// ──────────────────────────────────────────────────────────────────────────────
// STEP 4: Save
// ──────────────────────────────────────────────────────────────────────────────
echo "\n=== Step 4: Saving ===\n";
$writer = IOFactory::createWriter($newSS, 'Xlsx');
$writer->save($outFile);
echo "Saved: $outFile (" . filesize($outFile) . " bytes)\n";

// ──────────────────────────────────────────────────────────────────────────────
// STEP 5: Verify
// ──────────────────────────────────────────────────────────────────────────────
echo "\n=== Step 5: Verify ===\n";
$verifySS = IOFactory::load($outFile);
foreach (['Long Course Start', 'Short Course Start', 'Junior Start'] as $name) {
    $s = $verifySS->getSheetByName($name);
    $r = $s->toArray();
    $nonEmpty = array_filter($r, fn($row) => count(array_filter($row, fn($v) => $v !== null && $v !== '')) > 0);
    echo "  $name: " . count($nonEmpty) . " rows (header + data)\n";
    foreach ($nonEmpty as $i => $row) {
        $filtered = array_filter($row, fn($v) => $v !== null && $v !== '');
        if (!empty($filtered)) {
            echo "    Row $i: " . implode(' | ', array_map(fn($v) => substr((string)$v, 0, 12), array_slice($filtered, 0, 8))) . "\n";
        }
    }
}

echo "\nTotal handicap patches applied: $changes\n";
echo "Done.\n";