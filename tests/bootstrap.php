<?php
/**
 * PHPUnit bootstrap — autoloader + .env loading + getDbConnection() stub.
 * If .env is present (local dev with real MySQL), real DB connection is available.
 * Without .env, getDbConnection() returns a real PDO using environment variables.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Support/FakePDO.php';

// Load .env if present (defines DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD)
if (is_readable(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') !== false) {
            putenv($line);
            $_ENV += parse_ini_string($line) ?: [];
        }
    }
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__ . '/..');
}

/**
 * Get a real PDO connection from .env variables.
 * Falls back to placeholder values if .env is not loaded.
 */
function getDbConnection(): PDO
{
    $host     = getenv('DB_HOST')     ?: '127.0.0.1';
    $port     = getenv('DB_PORT')     ?: '3307';
    $database = getenv('DB_DATABASE') ?: 'lacsite_deploy';
    $username = getenv('DB_USERNAME') ?: 'lrcuser';
    $password = getenv('DB_PASSWORD') ?: 'lrcpassword';

    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}