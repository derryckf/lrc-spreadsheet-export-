#!/usr/bin/env php
<?php
/**
 * LRC Handicapping CLI
 *
 * Usage:
 *   php cli.php webscorer:parse {file} {--name=}
 *   php cli.php webscorer:resolve {eventId} {csv}
 *   php cli.php handicapper:process {eventId} {--x=8}
 *   php cli.php handicapper:export {eventId} [--format=xlsx] [--all-divisions]
 *   php cli.php handicapper:import {eventId} {file}
 *   php cli.php --help
 *
 * Environment variables (or config/database.php):
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
            $_ENV += parse_ini_string($line) ?: [];
        }
    }
}

require_once __DIR__ . '/config/database.php';
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
  php cli.php webscorer:resolve <eventId> <manifest.csv>
  php cli.php handicapper:process <eventId> [--x=8]
  php cli.php handicapper:export <eventId> [--format=xlsx] [--all-divisions]
  php cli.php handicapper:import <eventId> <file.xlsx>
  php cli.php --help

Commands:
  webscorer:parse     Parse Webscorer TXT to normalised CSV
  webscorer:resolve   Resolve identities + create member/eventEntry records
  handicapper:process Compute stats (daysSince, lastWin, pace estimates)
  handicapper:export  Export spreadsheet for handicapper
  handicapper:import  Import completed spreadsheet to update DB

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
        'handicapper:process' => runHandicapperProcess($args, $logger),
        'handicapper:export' => runHandicapperExport($args, $logger),
        'handicapper:import' => runHandicapperImport($args, $logger),
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
        fail("Usage: php cli.php webscorer:resolve <eventId> <manifest.csv>");
    }
    $eventId = (int)$positional[0];
    $manifestCsv = $positional[1];
    $logger->info("Step 2: Resolve identities + create members");
    $logger->info("Event: {$eventId}");
    $logger->info("Manifest: {$manifestCsv}");
    $config = require __DIR__ . '/config/lrc-handicapping.php';
    $resolvedCsv = $manifestCsv;
    if (!str_ends_with($manifestCsv, '_manifest.csv')) {
        $logger->info("Resolving raw webscorer CSV...");
        $resolver = new \App\Services\IdentityResolver(getDb(), $logger, $config);
        $resolvedCsv = $resolver->resolve($manifestCsv, $eventId);
        $logger->info("Manifest created: {$resolvedCsv}");
    }
    $logger->info("Creating/updating member and eventEntry records...");
    $creator = new \App\Services\MemberCreator(getDb(), $logger, $config);
    $stats = $creator->createMembers($resolvedCsv, $eventId);
    echo "\n";
    echo "Total rows:     {$stats['total']}\n";
    echo "Members created: {$stats['created']}\n";
    echo "Updated:        {$stats['updated']}\n";
    echo "Skipped:        {$stats['skipped']}\n";
}

function runHandicapperProcess(array $args, CliLogger $logger): void
{
    $opts = parseOpts($args);
    $positional = $opts['positional'];
    if (count($positional) < 1) {
        fail("Usage: php cli.php handicapper:process <eventId> [--x=8]");
    }
    $eventId = (int)$positional[0];
    $x = (int)($opts['x'] ?? 8);
    $logger->info("Step 3: Process members for event {$eventId} (x={$x})");
    $config = require __DIR__ . '/config/lrc-handicapping.php';
    $config['history_rows_default'] = $x;
    require_once __DIR__ . '/app/Services/LastWinCalculator.php';
    require_once __DIR__ . '/app/Services/MemberStatsComputer.php';
    require_once __DIR__ . '/app/Services/MemberProcessor.php';
    $processor = new \App\Services\MemberProcessor(getDb(), $logger, $config);
    $result = $processor->process($eventId, $x);
    echo "\n";
    echo "Processed: {$result['processed']}\n";
    echo "Skipped:   {$result['skipped']}\n";
}

function runHandicapperExport(array $args, CliLogger $logger): void
{
    $opts = parseOpts($args);
    $positional = $opts['positional'];
    if (count($positional) < 1) {
        fail("Usage: php cli.php handicapper:export <eventId> [--format=xlsx] [--all-divisions]");
    }
    $eventId = (int)$positional[0];
    $format = $opts['format'] ?? 'xlsx';
    $allDivisions = isset($opts['all-divisions']);
    $logger->info("Step 4: Export spreadsheet for event {$eventId}" . ($allDivisions ? ' (all divisions)' : ''));
    $config = require __DIR__ . '/config/lrc-handicapping.php';
    require_once __DIR__ . '/app/Services/SpreadsheetExporter.php';
    $exporter = new \App\Services\SpreadsheetExporter(getDb(), $logger, $config);
    $path = $exporter->export($eventId, $format, 8, $allDivisions);
    $logger->info("Spreadsheet exported: {$path}");
    echo "\n{$path}\n";
}

function runHandicapperImport(array $args, CliLogger $logger): void
{
    $opts = parseOpts($args);
    $positional = $opts['positional'];
    if (count($positional) < 2) {
        fail("Usage: php cli.php handicapper:import <eventId> <file.xlsx>");
    }
    $eventId = (int)$positional[0];
    $file = $positional[1];
    $logger->info("Step 5: Import completed spreadsheet for event {$eventId}");
    $config = require __DIR__ . '/config/lrc-handicapping.php';
    require_once __DIR__ . '/app/Services/HandicapImporter.php';
    $importer = new \App\Services\HandicapImporter(getDb(), $logger, $config);
    $result = $importer->import($eventId, $file);
    $logger->info("Import complete: updated={$result['updated']}, skipped={$result['skipped']}");
    if (!empty($result['errors'])) {
        foreach ($result['errors'] as $err) {
            $logger->warning($err);
        }
    }
    echo "\n";
    echo "Updated: {$result['updated']}\n";
    echo "Skipped: {$result['skipped']}\n";
    if (!empty($result['errors'])) {
        echo "Errors: " . count($result['errors']) . "\n";
    }
}

// Helpers
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
