<?php
/**
 * Database connection configuration for LRC Spreadsheet Export
 *
 * Uses the same credentials as the main LRC handicapping system.
 * These should match the values in your lrc-handicapper/.env file.
 */

function getDbConnection(): PDO
{
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3307';
    $database = getenv('DB_DATABASE') ?: 'lacsite_deploy';
    $username = getenv('DB_USERNAME') ?: 'lrcuser';
    $password = getenv('DB_PASSWORD') ?: 'lrcpassword';

    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    return new PDO($dsn, $username, $password, $options);
}
