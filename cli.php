#!/usr/bin/env php
<?php
/**
 * LRC Handicapping CLI
 *
 * Usage:
 *   php cli.php webscorer:parse {file} {--name=}
 *   php cli.php webscorer:resolve {eventId} {csv}
 *   php cli.php event:inject-season-pass {eventId} [{eventId2} ...] [--season=YYYY]
 *   php cli.php handicapper:process {eventId} {--x=8}
 *   php cli.php handicapper:export {eventId} [--format=xlsx] [--all-divisions] [--gdrive]
 *   php cli.php handicapper:import {eventId} [{file.xlsx}] [--gdrive[=fileId]]
 *   php cli.php handicapper:gdrive:list
 *   php cli.php --help
 *
 * Environment variables (or config/lrc-db.php):
 *   DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
 */

declare(strict_types=1);

// Load .env if present (defines DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD)
if (is_readable(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') !== false) {
            putenv($line);
            if (strpos($line, '=') !== false) {
                $parts = explode('=', $line, 2);
                $_ENV[$parts[0]] = $parts[1];
            }
        }
    }
}

require_once __DIR__ . '/config/lrc-db.php';
require_once __DIR__ . '/config/lrc-handicapping.php';

// Composer autoload (PhpSpreadsheet, PhpUnit, etc.)
if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Autoload
spl_autoload_register(function ($class) {
    $map = [
        'App\\Services\\' => __DIR__ . '/app/Services/',
        'App\\Console\\Commands\\' => __DIR__ . '/app/Console/Commands/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    }
});

// Logger
interface LrcLoggerInterface
{
    public function log(string $level, string $message, array $context = []): void;
    public function debug(string $message, array $context = []): void;
    public function info(string $message, array $context = []): void;
    public function warning(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
}

class CliLogger implements LrcLoggerInterface
{
    private mixed $out;
    public function __construct($out = STDOUT) { $this->out = $out; }
    public function log(string $level, string $message, array $context = []): void
    {
        $ts = date('H:i:s');
        $prefix = match ($level) {
            'error' => "\033[31mERROR\033[0m",
            'warning' => "\033[33mWARN\033[0m",
            'info' => "\033[32mINFO\033[0m",
            default => '  ' . $level,
        };
        $msg = $message;
        if ($context) {
            foreach ($context as $k => $v) {
                $msg = str_replace('{' . $k . '}', (string)$v, $msg);
            }
        }
        fprintf($this->out, "[%s] %s %s\n", $ts, $prefix, $msg);
    }
    public function debug(string $m, array $c = []): void { $this->log('debug', $m, $c); }
    public function info(string $m, array $c = []): void  { $this->log('info', $m, $c); }
    public function notice(string $m, array $c = []): void { $this->log('notice', $m, $c); }
    public function warning(string $m, array $c = []): void { $this->log('warning', $m, $c); }
    public function error(string $m, array $c = []): void { $this->log('error', $m, $c); }
    public function critical(string $m, array $c = []): void { $this->log('critical', $m, $c); }
    public function alert(string $m, array $c = []): void { $this->log('alert', $m, $c); }
    public function emergency(string $m, array $c = []): void { $this->log('emergency', $m, $c); }
}

// Bootstrap
$logger = new CliLogger();

function getDb(): PDO
{
    static $db = null;
    if ($db === null) {
        $db = getDbConnection();
    }
    return $db;
}

// Command dispatch
$args = $argv;
array_shift($args);

if (empty($args) || in_array('--help', $args) || in_array('-h', $args)) {
    echo <<<'HELP'
LRC Handicapping CLI

Usage:
  php cli.php webscorer:parse <file> [--name=<identity_basename>]
  php cli.php webscorer:resolve <eventId> <manifest.csv> [--interactive] [--skip-unknowns]
  php cli.php webscorer:interactive-resolve <eventId> <manifest.csv>
  php cli.php event:inject-season-pass <eventId> [<eventId2> ...] [--season=YYYY]
  php cli.php handicapper:process <eventId> [--x=8]
  php cli.php handicapper:export <eventId> [--format=xlsx] [--all-divisions] [--gdrive]
  php cli.php handicapper:import <eventId> [<file.xlsx>] [--gdrive[=fileId]]
  php cli.php handicapper:gdrive:list
  php cli.php --help

Commands:
  webscorer:parse     Parse Webscorer TXT to normalised CSV
  webscorer:resolve   Resolve identities + create member/eventEntry records
                        --interactive: prompt for each unknown runner (M=match, U=update, C=create, S=skip, A=approve all)
                        --skip-unknowns: skip all unknown runners without prompting
  webscorer:interactive-resolve  Handle unknown runners from a resolved manifest (interactive, run via gotty)
  event:inject-season-pass  Add season-pass holders (everyEvent) to eventEntry for given events
                        Run AFTER resolve, BEFORE process. Accepts multiple eventIds.
  handicapper:process Compute stats (daysSince, lastWin, pace estimates)
  handicapper:export  Export spreadsheet for handicapper (--gdrive to upload to Drive)
  handicapper:import  Import completed spreadsheet from --gdrive or local file
  handicapper:gdrive:list List files in Drive folder

Environment:
  DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD

HELP;
    exit(0);
}

$command = array_shift($args);
$exitCode = 0;

try {
    match ($command) {
        'webscorer:parse'    => runWebscorerParse($args, $logger),
        'webscorer:resolve'  => runWebscorerResolve($args, $logger),
        'webscorer:interactive-resolve' => runWebscorerInteractiveResolve($args, $logger),
        'event:inject-season-pass' => runEventInjectSeasonPass($args, $logger),
        'handicapper:process' => runHandicapperProcess($args, $logger),
        'handicapper:export' => runHandicapperExport($args, $logger),
        'handicapper:import' => runHandicapperImport($args, $logger),
        'handicapper:gdrive:list' => runHandicapperGdriveList($args, $logger),
        default => fail("Unknown command: {$command}\n"),
    };
} catch (Throwable $e) {
    $logger->error($e->getMessage());
    $exitCode = 1;
}

exit($exitCode);

// Command implementations

function runWebscorerParse(array $args, CliLogger $logger): void
{
    $opts = parseOpts($args);
    $positional = $opts['positional'];
    if (count($positional) < 1) {
        fail("Usage: php cli.php webscorer:parse <file> [--name=<identity_basename>]");
    }
    $txtPath = $positional[0];
    $identityName = $opts['name'] ?? basename($txtPath, '.txt');
    $logger->info("Step 1: Parse Webscorer TXT");
    $logger->info("Input: {$txtPath}");
    $binDir = __DIR__ . '/bin';
    $config = require __DIR__ . '/config/lrc-handicapping.php';
    $parser = new \App\Services\WebscorerParser($binDir, $logger, $config);
    $csvPath = $parser->parse($txtPath, $identityName);
    $logger->info("Output CSV: {$csvPath}");
    echo "\n{$csvPath}\n";
}

function runWebscorerResolve(array $args, CliLogger $logger): void
{
    $opts = parseOpts($args);
    $positional = $opts['positional'];
    if (count($positional) < 2) {
        fail("Usage: php cli.php webscorer:resolve <eventId> <manifest.csv> [--interactive] [--skip-unknowns]");
    }
    $eventId = (int)$positional[0];
    $manifestCsv = $positional[1];
    $interactive = isset($opts['interactive']);
    $skipUnknowns = isset($opts['skip-unknowns']);
    $config = require __DIR__ . '/config/lrc-handicapping.php';

    $logger->info("Step 2: Resolve identities + create members");
    $logger->info("Event: {$eventId}");
    $logger->info("Manifest: {$manifestCsv}");

    // ── Step 2a: Identity resolution (CSV → manifest) ──────────────────────────
    $resolvedCsv = $manifestCsv;
    if (!str_ends_with($manifestCsv, '_manifest.csv')) {
        $logger->info("Resolving raw webscorer CSV...");
        $resolver = new \App\Services\IdentityResolver(getDb(), $logger, $config);
        $resolvedCsv = $resolver->resolve($manifestCsv, $eventId);
        $logger->info("Manifest created: {$resolvedCsv}");
    }

    // ── Step 2b: Read manifest, separate known vs unknown ───────────────────
    $knownRows = [];
    $unknownRows = [];
    $h = fopen($resolvedCsv, 'r');
    $headers = fgetcsv($h);
    $headers = array_map('trim', $headers);
    $headerCount = count($headers);
    while (($row = fgetcsv($h)) !== false) {
        // Defensive: pad or truncate row to match header count
        if (count($row) < $headerCount) {
            $row = array_pad($row, $headerCount, '');
        } elseif (count($row) > $headerCount) {
            $row = array_slice($row, 0, $headerCount);
        }
        $data = array_combine($headers, $row);
        $data['_eventId'] = $eventId;
        if (str_starts_with($data['member_id'] ?? '', 'tmp_')) {
            $unknownRows[] = $data;
        } else {
            $knownRows[] = $data;
        }
    }
    fclose($h);

    $logger->info(sprintf("Known: %d | Unknown: %d", count($knownRows), count($unknownRows)));

    // ── Step 2c: Process known rows ────────────────────────────────────────────
    if (!empty($knownRows)) {
        $logger->info("Creating/updating " . count($knownRows) . " known member records...");
        $creator = new \App\Services\MemberCreator(getDb(), $logger, $config);
        $eventStmt = getDb()->prepare("SELECT id, eventDate, division, distance FROM event WHERE id = ?");
        $eventStmt->execute([$eventId]);
        $event = $eventStmt->fetch(\PDO::FETCH_ASSOC);
        $knownStats = $creator->createMembersFromArray($knownRows, $eventId, $event);
        echo "Known — Created: {$knownStats['created']}, Updated: {$knownStats['updated']}, Skipped: {$knownStats['skipped']}\n";
    }

    // ── Step 2d: Handle unknowns ──────────────────────────────────────────────
    $unknownStats = ['created' => 0, 'matched' => 0, 'updated' => 0, 'skipped' => count($unknownRows)];
    if (!empty($unknownRows)) {
        if ($skipUnknowns) {
            $logger->info("Skipping " . count($unknownRows) . " unknown runners (use --interactive to resolve).");
        } elseif ($interactive) {
            $logger->info("Starting interactive resolution for " . count($unknownRows) . " unknown runner(s)...");
            $iResolver = new \App\Services\InteractiveResolver(getDb(), $logger, $config);
            try {
                $unknownStats = $iResolver->resolveInteractive($resolvedCsv, $eventId, $unknownRows);
            } catch (\RuntimeException $e) {
                if ($e->getMessage() === 'USER_QUIT') {
                    $logger->info("User quit. Known runners processed.");
                    return;
                }
                throw $e;
            }
        } else {
            $msg = sprintf("%d unknown runner(s) found. Run with --interactive to resolve, or --skip-unknowns to exclude.", count($unknownRows));
            $logger->warning($msg);
            echo "WARNING: {$msg}\n";
            echo "Unknown runners NOT added to eventEntries.\n";
        }
    }

    echo "\n";
    echo "Total:       " . (count($knownRows) + count($unknownRows)) . "\n";
    echo "Created:     " . ($unknownStats['created'] ?? 0) . "\n";
    echo "Matched:     " . ($unknownStats['matched'] ?? 0) . "\n";
    echo "Updated:     " . ($unknownStats['updated'] ?? 0) . "\n";
    echo "Skipped:     " . ($unknownStats['skipped'] ?? 0) . "\n";
}

/**
 * Interactive resolve — handles only unknown runners from a previously-resolved manifest.
 * Designed to be run via gotty (web terminal) so the user can interactively answer prompts.
 * Usage: php cli.php webscorer:interactive-resolve <eventId> <manifest.csv>
 */
function runWebscorerInteractiveResolve(array $args, CliLogger $logger): void
{
    $opts = parseOpts($args);
    $positional = $opts['positional'];
    if (count($positional) < 2) {
        fail("Usage: php cli.php webscorer:interactive-resolve <eventId> <manifest.csv>");
    }
    $eventId = (int)$positional[0];
    $manifestCsv = $positional[1];
    $config = require __DIR__ . '/config/lrc-handicapping.php';

    $logger->info("Interactive resolve — event {$eventId}");
    $logger->info("Manifest: {$manifestCsv}");

    // Read manifest and collect unknowns
    $unknownRows = [];
    $h = fopen($manifestCsv, 'r');
    if (!$h) {
        fail("Cannot open manifest: {$manifestCsv}");
    }
    $headers = fgetcsv($h);
    $headers = array_map('trim', $headers);
    $headerCount = count($headers);
    while (($row = fgetcsv($h)) !== false) {
        if (count($row) < $headerCount) {
            $row = array_pad($row, $headerCount, '');
        } elseif (count($row) > $headerCount) {
            $row = array_slice($row, 0, $headerCount);
        }
        $data = array_combine($headers, $row);
        $data['_eventId'] = $eventId;
        if (str_starts_with($data['member_id'] ?? '', 'tmp_')) {
            $unknownRows[] = $data;
        }
    }
    fclose($h);

    $totalUnknowns = count($unknownRows);
    if ($totalUnknowns === 0) {
        $logger->info("No unknown runners found — nothing to do.");
        return;
    }

    $logger->info("Found {$totalUnknowns} unknown runner(s) in manifest.");
    $logger->info("Starting interactive resolution...\n");

    $iResolver = new \App\Services\InteractiveResolver(getDb(), $logger, $config);
    try {
        $stats = $iResolver->resolveInteractive($manifestCsv, $eventId, $unknownRows);
        $logger->info("Interactive resolution complete.");
        echo "\nSummary: ";
        echo "Created: {$stats['created']}, Matched: {$stats['matched']}, ";
        echo "Updated: {$stats['updated']}, Skipped: {$stats['skipped']}\n";
    } catch (\RuntimeException $e) {
        if ($e->getMessage() === 'USER_QUIT') {
            $logger->info("User quit.");
            return;
        }
        throw $e;
    }
}

function fail(string $msg): never
{
    fwrite(STDERR, $msg . "\n");
    exit(1);
}

function parseOpts(array $args): array
{
    $positional = [];
    $named = [];
    foreach ($args as $arg) {
        if (str_starts_with($arg, '--')) {
            $parts = explode('=', substr($arg, 2), 2);
            $named[$parts[0]] = $parts[1] ?? true;
        } else {
            $positional[] = $arg;
        }
    }
    return ['positional' => $positional, ...$named];
}




// ═══════════════════════════════════════════════════════════════════
// event:inject-season-pass — add everyEvent members to eventEntry
// ═══════════════════════════════════════════════════════════════════

function runEventInjectSeasonPass(array $args, CliLogger $logger): void
{
    $opts = parseOpts($args);
    $positional = $opts['positional'];
    if (count($positional) < 1) {
        fail("Usage: php cli.php event:inject-season-pass <eventId> [<eventId2> ...] [--season=YYYY]");
    }

    $season = isset($opts['season']) ? (int)$opts['season'] : null;
    $eventIds = array_map('intval', $positional);

    $logger->info('Injecting season-pass holders into ' . count($eventIds) . ' event(s)');

    $injector = new \App\Services\SeasonPassInjector(getDb(), $logger);

    foreach ($eventIds as $eventId) {
        $result = $injector->inject($eventId, $season);
        echo "Event {$eventId}: {$result['injected']} injected, {$result['skipped']} already registered\n";
    }
}

// ═══════════════════════════════════════════════════════════════════
// handicapper:process — compute stats
// ═══════════════════════════════════════════════════════════════════

function runHandicapperProcess(array $args, CliLogger $logger): void
{
    $opts = parseOpts($args);
    $positional = $opts['positional'];
    if (count($positional) < 1) {
        fail("Usage: php cli.php handicapper:process <eventId> [--x=8]");
    }
    $eventId = (int)$positional[0];
    $x = (int)($opts['x'] ?? 8);
    $config = require __DIR__ . '/config/lrc-handicapping.php';

    $logger->info("Step 3: Compute stats for event {$eventId} (x={$x})");

    $processor = new \App\Services\MemberProcessor(getDb(), $logger, $config);
    $result = $processor->process($eventId, $x);

    $logger->info("Stats computed: {$result['stats']} runners processed");
    echo "Stats computed for {$result['stats']} runners
";
}

// ═══════════════════════════════════════════════════════════════════
// handicapper:export — export spreadsheet
// ═══════════════════════════════════════════════════════════════════

function runHandicapperExport(array $args, CliLogger $logger): void
{
    $opts = parseOpts($args);
    $positional = $opts['positional'];
    if (count($positional) < 1) {
        fail("Usage: php cli.php handicapper:export <eventId> [--format=xlsx] [--all-divisions] [--gdrive]");
    }
    $eventId = (int)$positional[0];
    $format = $opts['format'] ?? 'xlsx';
    $allDivisions = isset($opts['all-divisions']);
    $config = require __DIR__ . '/config/lrc-handicapping.php';

    $logger->info("Step 4: Export spreadsheet for event {$eventId}");
    $exporter = new \App\Services\SpreadsheetExporter(getDb(), $logger, $config);
    $localPath = $exporter->export($eventId, $format, 8, $allDivisions);
    echo "Local file: {$localPath}
";

    // Upload to Google Drive if --gdrive flag is set
    if (isset($opts['gdrive'])) {
        $folderId = getenv('GOOGLE_DRIVE_FOLDER_ID');
        if (!$folderId) {
            fail("GOOGLE_DRIVE_FOLDER_ID env var is not set. Cannot upload to Drive.");
        }
        $logger->info("Uploading to Google Drive...");
        $gdrive = new \App\Services\GoogleDriveService();
        $fileName = basename($localPath);
        $existingId = $gdrive->findFile($fileName, $folderId);
        if ($existingId) {
            $fileId = $gdrive->upload($localPath, $folderId, $existingId);
            $logger->info("Updated existing Drive file (ID: {$fileId})");
        } else {
            $fileId = $gdrive->upload($localPath, $folderId);
            $logger->info("Created new Drive file (ID: {$fileId})");
        }
        echo "Drive file ID: {$fileId}
";
        echo "Drive URL: https://drive.google.com/open?id={$fileId}
";
    }
}

// ═══════════════════════════════════════════════════════════════════
// handicapper:import — import edited spreadsheet
// ═══════════════════════════════════════════════════════════════════

function runHandicapperImport(array $args, CliLogger $logger): void
{
    $opts = parseOpts($args);
    $positional = $opts['positional'];
    $config = require __DIR__ . '/config/lrc-handicapping.php';

    // Resolve the file path
    $filePath = null;
    $eventId = null;

    // Support: handicapper:import <eventId> <file.xlsx>
    // and: handicapper:import <eventId> --gdrive[=fileId]
    if (count($positional) >= 2) {
        $eventId = (int)$positional[0];
        $filePath = $positional[1];
    } elseif (count($positional) === 1) {
        $eventId = (int)$positional[0];
    }

    if (!$eventId) {
        fail("Usage: php cli.php handicapper:import <eventId> [<file.xlsx>] [--gdrive[=fileId]]");
    }

    // Download from Google Drive if --gdrive flag is set
    if (isset($opts['gdrive'])) {
        $gdriveFileId = $opts['gdrive'];
        if ($gdriveFileId === true) {
            // --gdrive without value: find latest file in the folder
            $folderId = getenv('GOOGLE_DRIVE_FOLDER_ID');
            if (!$folderId) {
                fail("GOOGLE_DRIVE_FOLDER_ID env var is not set.");
            }
            $gdrive = new \App\Services\GoogleDriveService();
            $files = $gdrive->listFiles($folderId);
            if (empty($files)) {
                fail("No files found in Drive folder {$folderId}");
            }
            // Pick the most recent
            $gdriveFileId = $files[0]['id'];
            $logger->info("Selected latest file: {$files[0]['name']} (ID: {$gdriveFileId})");
        }

        $gdrive = new \App\Services\GoogleDriveService();
        // Download to a temp path
        $tmpDir = sys_get_temp_dir() . '/lrc-handicapping';
        if (!is_dir($tmpDir)) { mkdir($tmpDir, 0755, true); }
        $tmpPath = $tmpDir . "/event_{$eventId}_imported.xlsx";

        $logger->info("Downloading from Drive file ID: {$gdriveFileId}");
        $gdrive->download($gdriveFileId, $tmpPath);
        $filePath = $tmpPath;
        $logger->info("Downloaded to: {$filePath}");
    }

    if (!$filePath || !file_exists($filePath)) {
        fail("File not found: {$filePath}");
    }

    $logger->info("Step 5: Import handicapping data from {$filePath}");

    $importer = new \App\Services\HandicapImporter(getDb(), $logger, $config);
    $result = $importer->import($eventId, $filePath);

    echo "Updated: {$result['updated']}
";
    echo "Skipped: {$result['skipped']}
";
    if (!empty($result['errors'])) {
        foreach ($result['errors'] as $err) {
            echo "Error: {$err}
";
        }
    }
}

// ═══════════════════════════════════════════════════════════════════
// handicapper:gdrive:list — list files in Drive folder
// ═══════════════════════════════════════════════════════════════════

function runHandicapperGdriveList(array $args, CliLogger $logger): void
{
    $folderId = getenv('GOOGLE_DRIVE_FOLDER_ID');
    if (!$folderId) {
        fail("GOOGLE_DRIVE_FOLDER_ID env var is not set.");
    }

    $gdrive = new \App\Services\GoogleDriveService();
    $files = $gdrive->listFiles($folderId);

    if (empty($files)) {
        echo "No files in Drive folder.
";
        return;
    }

    echo "Files in Google Drive folder:
";
    echo str_repeat('-', 80) . "
";
    printf("%-40s %-33s %s
", "Name", "Modified", "File ID");
    echo str_repeat('-', 80) . "
";
    foreach ($files as $f) {
        $name = $f['name'] ?? '?';
        $modified = $f['modifiedTime'] ?? '?';
        $id = $f['id'] ?? '?';
        if (strlen($name) > 37) $name = substr($name, 0, 34) . '...';
        printf("%-40s %-33s %s
", $name, $modified, $id);
    }
}
