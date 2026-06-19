<?php
/**
 * Re-run MemberProcessor for given event IDs using LIVE_DB credentials.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/lrc-handicapping.php';

use App\Services\MemberProcessor;

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

$eventIds = array_slice($argv, 1);
if (empty($eventIds)) {
    fwrite(STDERR, "Usage: php reprocess_events.php <eventId> [<eventId> ...]\n");
    exit(1);
}

// Use live DB credentials
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

$config = require __DIR__ . '/../config/lrc-handicapping.php';
$logger = new class {
    public function info(string $m, array $c = []) { echo "[INFO] {$m}\n"; }
    public function warning(string $m, array $c = []) { echo "[WARN] {$m}\n"; }
    public function debug(string $m, array $c = []) {}
    public function error(string $m, array $c = []) { fwrite(STDERR, "[ERROR] {$m}\n"); }
};

$processor = new MemberProcessor($pdo, $logger, $config);

foreach ($eventIds as $eventId) {
    echo "\n=== Reprocessing event {$eventId} ===\n";
    $stats = $processor->process((int)$eventId, $config['history_rows_default']);
    echo "Processed: {$stats['processed']}, Skipped: {$stats['skipped']}\n";
}
