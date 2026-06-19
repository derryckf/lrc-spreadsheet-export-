<?php
/**
 * Migrate everyEvent table:
 *  1. Add season INT column (nullable).
 *  2. Backfill existing rows with YEAR(createDate).
 *  3. Insert 2026 season-pass holders from registrations/all_race_entry.txt.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Load .env
if (is_readable(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') !== false) {
            putenv($line);
            $parts = explode('=', $line, 2);
            $_ENV[$parts[0]] = $parts[1];
        }
    }
}

$host = getenv('LIVE_DB_HOST') ?: '127.0.0.1';
$port = getenv('LIVE_DB_PORT') ?: '3306';
$db   = getenv('LIVE_DB_DATABASE') ?: 'lacsite_deploy';
$user = getenv('LIVE_DB_USERNAME') ?: 'lrcuser';
$pass = getenv('LIVE_DB_PASSWORD') ?: '';

$dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// ── 1. Add season column if missing ──────────────────────────────────────────
$cols = $pdo->query("SHOW COLUMNS FROM everyEvent LIKE 'season'")->fetchAll();
if (empty($cols)) {
    echo "Adding season column...\n";
    $pdo->exec("ALTER TABLE everyEvent ADD COLUMN season INT NULL AFTER lastModDate");
    echo "  done.\n";
} else {
    echo "season column already exists.\n";
}

// ── 2. Backfill existing rows from createDate year ───────────────────────────
$stmt = $pdo->query("SELECT COUNT(*) FROM everyEvent WHERE season IS NULL");
$missing = (int) $stmt->fetchColumn();
if ($missing > 0) {
    echo "Backfilling {$missing} existing rows with YEAR(createDate)...\n";
    $pdo->exec("UPDATE everyEvent SET season = YEAR(createDate) WHERE season IS NULL");
    echo "  done.\n";
} else {
    echo "No existing rows need backfill.\n";
}

// ── 3. Load all_race_entry.txt and insert 2026 season holders ───────────────
$regFile = __DIR__ . '/../registrations/all_race_entry.txt';
if (!is_readable($regFile)) {
    fwrite(STDERR, "Cannot read {$regFile}\n");
    exit(1);
}

$rows = array_map(fn($l) => str_getcsv($l, "\t", '"', "\\"), file($regFile));
$header = array_shift($rows);
$col = array_flip($header);

$divisionMap = [
    '8.0km' => 1,
    '2.5km' => 2,
    '1.5km' => 3,
];

$rankMap = [
    'Senior'            => 'S',
    'Junior - 15+'      => 'S',
    'Junior - 10 to 18 y.o.' => 'K',
    'Junior - Juniors'  => 'K',
];

$inserted = 0;
$skipped  = 0;
$errors   = [];

$insertStmt = $pdo->prepare(<<<'SQL'
    INSERT INTO everyEvent (member_id, rank, division, paid, tagNo_id, createDate, lastModDate, season)
    VALUES (?, ?, ?, ?, ?, CURDATE(), CURDATE(), 2026)
SQL);

$checkStmt = $pdo->prepare("SELECT id FROM everyEvent WHERE member_id = ? AND season = 2026");

foreach ($rows as $row) {
    $first = $row[$col['First name']] ?? '';
    $last  = $row[$col['Last name']] ?? '';
    $dob   = $row[$col['Date of birth']] ?? '';
    $bib   = $row[$col['Bib']] ?? '';
    $dist  = $row[$col['Distance']] ?? '';
    $cat   = $row[$col['Category']] ?? '';

    if ($first === '' || $last === '') continue;

    // Resolve member
    $mStmt = $pdo->prepare("SELECT id FROM member WHERE firstName = ? AND lastName = ? AND DOB = ?");
    $mStmt->execute([$first, $last, $dob]);
    $memberId = $mStmt->fetchColumn();
    if (!$memberId) {
        $errors[] = "Member not found: {$first} {$last} (DOB {$dob})";
        continue;
    }

    // Resolve tagNo
    $tagNoId = null;
    if ($bib !== '' && $bib !== '-') {
        $tStmt = $pdo->prepare("SELECT id FROM tagNo WHERE tagNo = ?");
        $tStmt->execute([$bib]);
        $tagNoId = $tStmt->fetchColumn();
        if (!$tagNoId) {
            $errors[] = "tagNo not found: {$bib} for {$first} {$last}";
            continue;
        }
    }

    // Map division / rank
    $division = $divisionMap[$dist] ?? null;
    $rank     = $rankMap[$cat] ?? null;
    if ($division === null) {
        $errors[] = "Unknown distance '{$dist}' for {$first} {$last}";
        continue;
    }
    if ($rank === null) {
        $errors[] = "Unknown category '{$cat}' for {$first} {$last}";
        continue;
    }

    // Idempotency: skip if already present for season 2026
    $checkStmt->execute([$memberId]);
    if ($checkStmt->fetchColumn()) {
        echo "Skipping {$first} {$last}: already in everyEvent for 2026\n";
        $skipped++;
        continue;
    }

    $insertStmt->execute([$memberId, $rank, $division, 1, $tagNoId]);
    echo "Inserted {$first} {$last} (member {$memberId}, division {$division}, rank {$rank}, bib {$bib})\n";
    $inserted++;
}

echo "\nInserted: {$inserted}, Skipped: {$skipped}, Errors: " . count($errors) . "\n";
if (!empty($errors)) {
    foreach ($errors as $e) echo "  ERROR: {$e}\n";
    exit(1);
}
