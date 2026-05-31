<?php
/**
 * Quick integration smoke test — runs SpreadsheetExporter against real DB.
 * Usage: php scripts/smoke-test-export.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../tests/bootstrap.php';
require_once __DIR__ . '/../tests/Support/FakePDO.php';

$db = getDbConnection();

echo "=== SpreadsheetExporter smoke test against real DB ===\n\n";

// ── Test 1: SpreadsheetExporter::export CSV for event 1803 ──────────────────────
echo "1. SpreadsheetExporter (event 1803 = 2026-05-23 Div1 5km):\n";

try {
    $exporter = new \App\Services\SpreadsheetExporter($db, null, [
        'history_rows_default' => 8,
        'storage_path'         => 'handicapping',
    ]);

    $csvPath = $exporter->export(1803, 'csv');

    if (file_exists($csvPath)) {
        $size = filesize($csvPath);
        echo "   ✅ CSV created: $csvPath ($size bytes)\n";

        // Read content before unlinking
        $content = file_get_contents($csvPath);
        echo "\n--- CSV Content ---\n";
        echo $content;
        echo "--- End CSV ---\n\n";

        $content = file_get_contents($csvPath);
        $lines = explode("\n", trim($content));
        echo "   Lines: " . count($lines) . "\n";

        // Show header + first few data lines
        foreach (array_slice($lines, 0, 12) as $line) {
            if (trim($line)) echo "   | " . substr($line, 0, 100) . "\n";
        }
    } else {
        echo "   ❌ CSV file not created\n";
    }

    if (file_exists($csvPath)) unlink($csvPath);
} catch (Throwable $e) {
    echo "   ❌ Error: " . $e->getMessage() . "\n";
    echo "   " . $e->getFile() . ":" . $e->getLine() . "\n";
}

// ── Test 2: SpreadsheetExporter for all 3 divisions ────────────────────────────
echo "\n2. SpreadsheetExporter (event 1803 - all divisions):\n";

try {
    $exporter = new \App\Services\SpreadsheetExporter($db, null, [
        'history_rows_default' => 8,
        'storage_path'         => 'handicapping',
    ]);

    // Check how many divisions are in the CSV
    $csvPath = $exporter->export(1803, 'csv');
    $content = file_get_contents($csvPath);
    $divCount = substr_count($content, '=== Division');
    $entryCount = substr_count($content, ' 1,');
    echo "   Division sections: $divCount\n";
    echo "   Total entries: $entryCount\n";

    // Show stats rows
    $lines = explode("\n", trim($content));
    $statsLines = array_filter($lines, fn($l) => strpos($l, '| Stats') !== false);
    echo "   Stats rows found: " . count($statsLines) . "\n";

    unlink($csvPath);
    echo "   ✅ Export successful\n";
} catch (Throwable $e) {
    echo "   ❌ Error: " . $e->getMessage() . "\n";
}

// ── Test 3: HandicapImporter time utilities ───────────────────────────────────
echo "\n3. HandicapImporter time utilities:\n";

try {
    $importer = new \App\Services\HandicapImporter($db, null, [
        'storage_path' => 'handicapping',
    ]);

    $ref = new ReflectionClass($importer);

    foreach (['parseTimeToSeconds', 'formatSecondsToHis', 'isValidPace'] as $methodName) {
        $m = $ref->getMethod($methodName);
        $m->setAccessible(true);

        if ($methodName === 'parseTimeToSeconds') {
            $result = $m->invoke($importer, '0:05:30');
            echo "   parseTimeToSeconds('0:05:30') = $result sec\n";
        } elseif ($methodName === 'formatSecondsToHis') {
            $result = $m->invoke($importer, 330);
            echo "   formatSecondsToHis(330) = $result\n";
        } elseif ($methodName === 'isValidPace') {
            $result = $m->invoke($importer, '0:05:00');
            echo "   isValidPace('0:05:00') = " . ($result ? 'true' : 'false') . "\n";
        }
    }
    echo "   ✅ Time utilities work\n";
} catch (Throwable $e) {
    echo "   ❌ Error: " . $e->getMessage() . "\n";
}

echo "\n=== Done ===\n";