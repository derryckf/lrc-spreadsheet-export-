<?php
declare(strict_types=1);
namespace App\Services;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Parses a Webscorer tab-delimited TXT registration file.
 *
 * Runs the legacy sed pipeline:
 *   1. fixRegHeader.sh   — rename Webscorer headers to snake_case field names
 *   2. convertToCsv.sh  — strip embedded commas, convert tabs → commas
 *   3. cleaner.sh        — name corrections, DOB corrections, field normalisation
 *
 * Output: a cleaned CSV file at storage/app/handicapping/{eventId}/identity/
 *
 * Usage:
 *   $parser = new WebscorerParser('/path/to/bin', $logger);
 *   $csvPath = $parser->parse('/path/to/input.txt', 'output_identity_name');
 */
class WebscorerParser
{
    private string $binDir;
    /** @var object{log:function, debug:function, info:function, error:function} */
    private $logger;
    private array $config;

    /**
     * @param object|null $logger  Any object with log(), debug(), info(), error() methods
     */
    public function __construct(string $binDir, $logger = null, array $config = [])
    {
        $this->binDir = rtrim($binDir, '/');
        $this->logger = $logger;
        $this->config = array_merge([
            'storage_path' => 'handicapping',
        ], $config);
    }

    private function logger(): object
    {
        return $this->logger ?? new class {
            public function debug(string $m, array $c=[]) {}
            public function info(string $m, array $c=[]) {}
            public function error(string $m, array $c=[]) {}
            public function warning(string $m, array $c=[]) {}
            public function log(string $l, string $m, array $c=[]) {}
        };
    }

    /**
     * Parse a Webscorer TXT file → normalised CSV.
     *
     * @param string $txtPath  Absolute path to input TXT file
     * @param string $identityName  Basename for output (e.g. "123_manifest")
     * @return string  Absolute path to output CSV
     * @throws \RuntimeException if the shell scripts fail or input file not found
     */
    public function parse(string $txtPath, string $identityName): string
    {
        if (!file_exists($txtPath)) {
            throw new \RuntimeException("Input file not found: {$txtPath}");
        }

        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $storageDir = $base . '/storage/app/' . $this->config['storage_path'];
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        $tmpDir = sys_get_temp_dir();
        $outputCsv = $storageDir . '/' . $identityName . '.csv';

        $this->logger()->info("Parsing Webscorer TXT: {$txtPath}");
        $this->logger()->info("Output CSV: {$outputCsv}");

        $generateScript = $this->binDir . '/generateCsvFile.sh';
        if (!file_exists($generateScript)) {
            throw new \RuntimeException("Script not found: {$generateScript}");
        }

        // Run generateCsvFile.sh with paths + filename
        // It reads from $tmpDir/$identityName.txt and writes to $tmpDir/<name>.csv
        // Then cleaner.sh is piped afterwards
        $cleanerScript = $this->binDir . '/cleaner.sh';
        if (!file_exists($cleanerScript)) {
            throw new \RuntimeException("cleaner.sh not found: {$cleanerScript}");
        }

        // Copy input to temp location as generateCsvFile.sh reads from a file path
        $inputCopy = $tmpDir . '/' . $identityName . '_input.txt';
        copy($txtPath, $inputCopy);

        // Run generateCsvFile.sh: path1 path2 filename
        // It writes to path2/<name>.csv
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'r'],
            2 => ['pipe', 'r'],
        ];

        $cmd = "bash " . escapeshellarg($generateScript) . ' ' .
              escapeshellarg($tmpDir) . ' ' .
              escapeshellarg($tmpDir) . ' ' .
              escapeshellarg($identityName . '_input.txt');

        $this->logger()->info("Running: {$cmd}");

        $process = proc_open($cmd, $descriptors, $pipes);
        if ($process === false) {
            throw new \RuntimeException("Failed to start generateCsvFile.sh");
        }
        fclose($pipes[0]);

        // Capture output — suppress PHP notices from pipe read races
        $stdout = '';
        $errLevel = error_reporting(0);
        while (($line = @fgets($pipes[1])) !== false) {
            $stdout .= $line;
            $this->logger()->debug("[generateCsvFile] " . rtrim($line));
        }
        fclose($pipes[1]);
        $stderr = @stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        error_reporting($errLevel);

        $exitCode = proc_close($process);

        // Check if output CSV was created
        $tmpCsv = $tmpDir . '/' . $identityName . '_input.csv';
        if (!file_exists($tmpCsv)) {
            $this->logger()->error("generateCsvFile.sh produced no CSV at: {$tmpCsv}");
            if ($stderr) {
                $this->logger()->error("stderr: " . rtrim($stderr));
            }
            throw new \RuntimeException("generateCsvFile.sh failed to produce output CSV (exit {$exitCode})");
        }

        // Apply cleaner.sh to the output
        $cleanedCsv = $tmpDir . '/' . $identityName . '_cleaned.csv';
        $this->executeScript('bash ' . escapeshellarg($cleanerScript), file_get_contents($tmpCsv), $cleanedCsv, 'cleaner');

        // Move to final destination
        copy($cleanedCsv, $outputCsv);
        @unlink($inputCopy);
        @unlink($tmpCsv);
        @unlink($cleanedCsv);

        if (!file_exists($outputCsv)) {
            throw new \RuntimeException("CSV was not produced: {$outputCsv}");
        }

        $lineCount = count(file($outputCsv));
        $this->logger()->info("Parse complete — {$lineCount} lines in {$outputCsv}");

        return $outputCsv;
    }

    /**
     * Execute a single shell script filter: cat $input | bash $script > $outputFile.
     * Streams stderr to logger (if debug).
     *
     * @param string $script    Absolute path to shell script (reads from stdin)
     * @param string $input    Data to pipe to script stdin
     * @param string $outputFile  Path to write stdout
     * @param string $stageLabel  Label for log messages
     * @throws \RuntimeException on non-zero exit code
     */
    private function executeScript(string $script, string $input, string $outputFile, string $stageLabel): void
    {
        // Write input to a temp file
        $inputFile = tempnam(sys_get_temp_dir(), 'lrc_in_');
        file_put_contents($inputFile, $input);

        $cmd = 'cat ' . escapeshellarg($inputFile) . ' | ' . $script . ' > ' . escapeshellarg($outputFile) . ' 2>/dev/null';

        $this->logger()->debug("[{$stageLabel}] Running: {$cmd}");

        exec($cmd, $stdoutLines, $exitCode);

        @unlink($inputFile);

        if ($exitCode !== 0) {
            $stderr = implode("\n", $stdoutLines);
            $this->logger()->error("[{$stageLabel}] failed with exit code {$exitCode}: {$stderr}");
            @unlink($outputFile);
            throw new \RuntimeException("{$stageLabel} script failed (exit {$exitCode})");
        }
    }

    /**
     * Get the storage directory for handicapping files.
     */
    private function getOutputDir(): string
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        return $base . '/storage/app/' . $this->config['storage_path'];
    }
}