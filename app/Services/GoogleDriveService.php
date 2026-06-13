<?php
declare(strict_types=1);
namespace App\Services;

/**
 * GoogleDriveService — upload/download/list/find files on Google Drive.
 *
 * Supports two auth modes:
 *   - OAuth2 user credentials (GOOGLE_OAUTH_ACCESS/REFRESH/EXPIRES)
 *     Use scripts/google_auth.php to obtain tokens.
 *   - Service account (GOOGLE_DRIVE_CREDENTIALS path to JSON key file)
 *     Requires Drive folder shared with service account email.
 *
 * Credentials are checked in this order: OAuth2 tokens first, then service account.
 */
class GoogleDriveService
{
    private ?string $credentialsPath = null;
    private ?string $accessToken = null;
    private ?string $refreshToken = null;
    private int $tokenExpiresAt = 0;
    private string $apiBase = 'https://www.googleapis.com/drive/v3';
    private string $tokenUri = 'https://oauth2.googleapis.com/token';

    public function __construct(?string $credentialsPath = null)
    {
        // OAuth2 tokens from env (set by google_auth.php)
        $this->accessToken  = getenv('GOOGLE_OAUTH_ACCESS') ?: null;
        $this->refreshToken = getenv('GOOGLE_OAUTH_REFRESH') ?: null;
        $this->tokenExpiresAt = (int)(getenv('GOOGLE_OAUTH_EXPIRES') ?: 0);

        // Service account fallback
        $this->credentialsPath = $credentialsPath
            ?: (getenv('GOOGLE_DRIVE_CREDENTIALS') ?: '');
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Upload a file. If $fileId is given, updates existing file (replace content).
     *
     * @param string      $localPath  absolute path to local file
     * @param string      $folderId   Drive folder ID to place file in
     * @param string|null $fileId     existing file ID to update (or null = create)
     * @return string  the file ID
     */
    public function upload(string $localPath, string $folderId, ?string $fileId = null): string
    {
        $name = basename($localPath);
        $mime = $this->detectMimeType($localPath);

        if ($fileId) {
            return $this->updateFile($fileId, $localPath, $mime);
        }

        return $this->createFile($localPath, $name, $folderId, $mime);
    }

    /**
     * Download a file from Drive to a local path.
     */
    public function download(string $fileId, string $localPath): void
    {
        $token = $this->getAccessToken();
        $url = "{$this->apiBase}/files/{$fileId}?alt=media";

        $hdrFile = tempnam(sys_get_temp_dir(), 'h_');
        $cmd = sprintf(
            'curl -s -D %s -o %s -X GET -H %s %s',
            escapeshellarg($hdrFile),
            escapeshellarg($localPath),
            escapeshellarg("Authorization: Bearer {$token}"),
            escapeshellarg($url)
        );
        exec($cmd);
        $hdrs = file_get_contents($hdrFile);
        @unlink($hdrFile);

        $httpCode = 0;
        if (preg_match('/^HTTP\/[\d.]+\s+(\d+)/m', $hdrs, $m)) {
            $httpCode = (int)$m[1];
        }
        if ($httpCode !== 200) {
            @unlink($localPath);
            throw new \RuntimeException("Drive download failed (HTTP {$httpCode})");
        }
    }

    /**
     * Find a file by name in a folder.
     *
     * @return string|null  fileId if found, null otherwise
     */
    public function findFile(string $name, string $folderId): ?string
    {
        $token = $this->getAccessToken();
        $encodedFolderId = rawurlencode($folderId);
        $q = "name = '" . rawurlencode($name) . "' and '{$encodedFolderId}' in parents and trashed = false";
        $url = "{$this->apiBase}/files?q=" . rawurlencode($q)
            . "&fields=files(id,name)&pageSize=10"
            . "&includeItemsFromAllDrives=true&supportsAllDrives=true";

        $resp = $this->curlGet($url, $token);
        $data = json_decode($resp, true);

        return $data['files'][0]['id'] ?? null;
    }

    /**
     * List all files in a folder.
     *
     * @return array<int, array{id:string, name:string, modifiedTime:string}>
     */
public function listFiles(string $folderId): array
    {
        $token = $this->getAccessToken();
        // Query: folder ID quoted in single quotes, embedded in q parameter
        $q = "'" . rawurlencode($folderId) . "' in parents and trashed = false";
        $url = "{$this->apiBase}/files?q=" . rawurlencode($q)
            . "&fields=files(id,name,modifiedTime)&orderBy=modifiedTime%20desc&pageSize=100"
            . "&includeItemsFromAllDrives=true&supportsAllDrives=true";

        $resp = $this->curlGet($url, $token);
        $data = json_decode($resp, true);

        return $data['files'] ?? [];
    }

    /**
     * Create a folder in Drive. If name exists, returns existing folder ID.
     */
    public function createFolder(string $name, string $parentId): string
    {
        $token = $this->getAccessToken();

        // Check existing — folder ID and name value need encoding; structural ' chars are literal
        $q = "name = '" . rawurlencode($name) . "' and '" . $parentId . "' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
        $url = "{$this->apiBase}/files?q={$q}&fields=files(id)";
        $resp = $this->curlGet("{$this->apiBase}/files?q=" . rawurlencode($q) . "&fields=files(id)", $token);
        $data = json_decode($resp, true);
        if (!empty($data['files'])) {
            return $data['files'][0]['id'];
        }

        // Create
        $body = json_encode([
            'name'     => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents'  => [$parentId],
        ]);
        $resp = $this->curlPost("{$this->apiBase}/files", $body, $token);
        $data = json_decode($resp, true);

        return $data['id'] ?? throw new \RuntimeException(
            "Failed to create Drive folder: " . ($data['error']['message'] ?? 'unknown')
        );
    }

    /**
     * Get the authenticated user's email address (from OAuth2 user info endpoint).
     */
    public function getAuthenticatedUserEmail(): string
    {
        $token = $this->getAccessToken();
        $resp = $this->curlGet('https://www.googleapis.com/oauth2/v1/userinfo', $token);
        $data = json_decode($resp, true);
        return $data['email'] ?? 'unknown';
    }

    // ── Auth ─────────────────────────────────────────────────────────────────

    /**
     * Get a valid access token (OAuth2 or service account JWT).
     */
    private function getAccessToken(): string
    {
        // OAuth2 mode
        if ($this->accessToken !== null && $this->refreshToken !== null) {
            if (time() < $this->tokenExpiresAt - 60) {
                return $this->accessToken;
            }
            // Token expired — refresh
            $newToken = $this->refreshOAuthToken();
            if ($newToken) {
                $this->accessToken = $newToken;
                $this->tokenExpiresAt = time() + 3600 - 60;
                return $this->accessToken;
            }
            // Refresh failed — fall through to service account
        }

        // Service account mode (fallback)
        if ($this->credentialsPath !== '' && is_readable($this->credentialsPath)) {
            return $this->getServiceAccountToken();
        }

        throw new \RuntimeException(
            "No Google Drive credentials configured. "
            . "Run 'php scripts/google_auth.php' for OAuth2, "
            . "or set GOOGLE_DRIVE_CREDENTIALS to a service account JSON path."
        );
    }

    /**
     * Refresh the OAuth2 access token using the refresh token.
     */
    private function refreshOAuthToken(): ?string
    {
        $clientSecretPath = $this->findClientSecretPath();
        if (!$clientSecretPath) {
            return null;
        }

        $clientData = json_decode(file_get_contents($clientSecretPath), true);
        $clientId = $clientData['web']['client_id'] ?? '';
        $clientSecret = $clientData['web']['client_secret'] ?? '';

        $body = http_build_query([
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'grant_type'    => 'refresh_token',
            'refresh_token' => $this->refreshToken,
        ]);

        $hdrFile = tempnam(sys_get_temp_dir(), 'h_');
        $outFile = tempnam(sys_get_temp_dir(), 'o_');
        $cmd = sprintf(
            'curl -s -D %s -o %s -X POST '
            . '-H %s '
            . '-d %s %s',
            escapeshellarg($hdrFile),
            escapeshellarg($outFile),
            escapeshellarg('Content-Type: application/x-www-form-urlencoded'),
            escapeshellarg($body),
            escapeshellarg($this->tokenUri)
        );
        exec($cmd);
        $resp = file_get_contents($outFile);
        @unlink($hdrFile); @unlink($outFile);

        $tokens = json_decode($resp, true);
        if (!isset($tokens['access_token'])) {
            return null;
        }

        $this->persistTokens($tokens);
        return $tokens['access_token'];
    }

    /**
     * Save refreshed tokens back to .env.
     */
    private function persistTokens(array $tokens): void
    {
        $envPath = $this->findEnvPath();
        if (!$envPath || !is_writable($envPath)) {
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $newLines = [];
        $now = time();

        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                $newLines[] = $line;
                continue;
            }
            if (strpos($line, 'GOOGLE_OAUTH_ACCESS=') === 0) {
                $newLines[] = 'GOOGLE_OAUTH_ACCESS=' . ($tokens['access_token'] ?? '');
            } elseif (strpos($line, 'GOOGLE_OAUTH_EXPIRES=') === 0) {
                $newLines[] = 'GOOGLE_OAUTH_EXPIRES=' . ($now + ($tokens['expires_in'] ?? 3600));
            } else {
                $newLines[] = $line;
            }
        }

        file_put_contents($envPath, implode("\n", $newLines) . "\n");
    }

    private function findEnvPath(): ?string
    {
        static $path = null;
        if ($path === null) {
            $path = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
            $path .= '/.env';
            if (!is_file($path)) {
                $path = null;
            }
        }
        return $path;
    }

    private function findClientSecretPath(): ?string
    {
        static $path = null;
        if ($path === null) {
            $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
            $path = $base . '/.config/google_client.json';
            if (!is_readable($path)) {
                $path = null;
            }
        }
        return $path;
    }

    // ── Service Account JWT (fallback) ───────────────────────────────────────

    private function getServiceAccountToken(): string
    {
        $creds = $this->loadServiceAccountCredentials();

        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $now = time();
        $claim = $this->base64UrlEncode(json_encode([
            'iss'   => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/drive.file',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $now + 3600,
            'iat'   => $now,
        ]));

        $signInput = "{$header}.{$claim}";
        $privateKey = $creds['private_key'];
        $signature = '';
        openssl_sign($signInput, $signature, $privateKey, 'sha256WithRSAEncryption');
        $jwt = "{$signInput}." . $this->base64UrlEncode($signature);

        $body = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);

        $hdrFile = tempnam(sys_get_temp_dir(), 'h_');
        $outFile = tempnam(sys_get_temp_dir(), 'o_');
        $cmd = sprintf(
            'curl -s -D %s -o %s -X POST '
            . '-H %s '
            . '-d %s %s',
            escapeshellarg($hdrFile),
            escapeshellarg($outFile),
            escapeshellarg('Content-Type: application/x-www-form-urlencoded'),
            escapeshellarg($body),
            escapeshellarg('https://oauth2.googleapis.com/token')
        );
        exec($cmd);
        $resp = file_get_contents($outFile);
        @unlink($hdrFile); @unlink($outFile);

        $data = json_decode($resp, true);
        if (!isset($data['access_token'])) {
            throw new \RuntimeException(
                "Service account token failed: " . ($data['error']['message'] ?? $resp)
            );
        }
        return $data['access_token'];
    }

    private function loadServiceAccountCredentials(): array
    {
        if ($this->credentialsPath === '' || !is_readable($this->credentialsPath)) {
            throw new \RuntimeException(
                "Service account credentials not found. Set GOOGLE_DRIVE_CREDENTIALS "
                . "to path of service-account JSON file."
            );
        }
        $raw = file_get_contents($this->credentialsPath);
        $data = json_decode($raw, true);
        if (!$data || !isset($data['client_email'])) {
            throw new \RuntimeException("Invalid service account JSON");
        }
        return $data;
    }

    // ── Internal API calls ───────────────────────────────────────────────────

    private function execCurl(string $method, string $url, ?string $body = null, array $headers = [], bool $returnHeaders = false): array
    {
        $headerFile = tempnam(sys_get_temp_dir(), 'curl_hdr_');
        $outFile = tempnam(sys_get_temp_dir(), 'curl_out_');

        $cmdParts = ['curl', '-s', '-D', $headerFile, '-o', $outFile, '-X', $method];
        foreach ($headers as $h) {
            $cmdParts[] = '-H';
            $cmdParts[] = $h;
        }
        if ($body !== null) {
            $cmdParts[] = '-d';
            $cmdParts[] = $body;
        }
        $cmdParts[] = $url;

        $cmdStr = escapeshellcmd($cmdParts[0]) . ' ' . implode(' ', array_map('escapeshellarg', array_slice($cmdParts, 1)));
        exec($cmdStr . ' 2>/dev/null', $out, $exitCode);

        $responseHeaders = @file_get_contents($headerFile) ?: '';
        $responseBody = @file_get_contents($outFile) ?: '';
        @unlink($headerFile);
        @unlink($outFile);

        $httpCode = 0;
        if (preg_match('/^HTTP\/[\d.]+\s+(\d+)/m', $responseHeaders, $m)) {
            $httpCode = (int)$m[1];
        }

        return ['code' => $httpCode, 'body' => $responseBody, 'exit' => $exitCode];
    }


    private function execCurlDownload(string $url, string $token, string $localPath): void
    {
        $cmd = sprintf(
            'curl -s -D /dev/null -o %s -X GET -H "Authorization: Bearer %s" %s',
            escapeshellarg($localPath),
            escapeshellarg($token),
            escapeshellarg($url)
        );
        exec($cmd, $out, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException("Download failed (exit $exitCode)");
        }
    }

    private function createFile(string $localPath, string $name, string $folderId, string $mime): string
    {
        $token = $this->getAccessToken();
        $metadata = json_encode([
            'name'    => $name,
            'parents' => [$folderId],
        ]);

        // Build multipart form using exec curl
        $metaFile = tempnam(sys_get_temp_dir(), 'meta_');
        $dataFile = tempnam(sys_get_temp_dir(), 'data_');
        file_put_contents($metaFile, $metadata);
        file_put_contents($dataFile, file_get_contents($localPath));

        $boundary = 'boundary_' . bin2hex(random_bytes(8));
        $body = "--{$boundary}\r\n"
              . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
              . $metadata . "\r\n"
              . "--{$boundary}\r\n"
              . "Content-Type: {$mime}\r\n\r\n"
              . file_get_contents($localPath) . "\r\n"
              . "--{$boundary}--";

        $headerFile = tempnam(sys_get_temp_dir(), 'hdr_');
        $outFile = tempnam(sys_get_temp_dir(), 'out_');
        $cmd = sprintf(
            'curl -s -D %s -o %s -X POST '
            . '-H "Authorization: Bearer %s" '
            . '-H "Content-Type: multipart/related; boundary=%s" '
            . '%s '
            . '-d @%s '
            . '"%s/files?uploadType=multipart"',
            escapeshellarg($headerFile),
            escapeshellarg($outFile),
            escapeshellarg($token),
            escapeshellarg($boundary),
            '--data-binary',
            escapeshellarg($metaFile),
            escapeshellarg($this->apiBase)
        );

        // Use a temp file for the body
        $bodyFile = tempnam(sys_get_temp_dir(), 'body_');
        file_put_contents($bodyFile, $body);
        $cmd = sprintf(
            'curl -s -D %s -o %s -X POST '
            . '-H "Authorization: Bearer %s" '
            . '-H "Content-Type: multipart/related; boundary=%s" '
            . '--data-binary @%s '
            . '"%s/files?uploadType=multipart"',
            escapeshellarg($headerFile),
            escapeshellarg($outFile),
            escapeshellarg($token),
            escapeshellarg($boundary),
            escapeshellarg($bodyFile),
            escapeshellarg($this->apiBase)
        );

        exec($cmd, $out, $exitCode);
        $resp = file_get_contents($outFile);
        $hdrs = file_get_contents($headerFile);
        @unlink($metaFile);
        @unlink($dataFile);
        @unlink($bodyFile);
        @unlink($headerFile);
        @unlink($outFile);

        $httpCode = 0;
        if (preg_match('/^HTTP\/[\d.]+\s+(\d+)/m', $hdrs, $m)) {
            $httpCode = (int)$m[1];
        }

        if ($httpCode !== 200) {
            $detail = json_decode($resp, true);
            throw new \RuntimeException(
                "Drive upload failed (HTTP {$httpCode}): "
                . ($detail['error']['message'] ?? $resp)
            );
        }

        $data = json_decode($resp, true);
        return $data['id'];
    }

    private function updateFile(string $fileId, string $localPath, string $mime): string
    {
        $token = $this->getAccessToken();

        $headerFile = tempnam(sys_get_temp_dir(), 'hdr_');
        $outFile = tempnam(sys_get_temp_dir(), 'out_');
        $cmd = sprintf(
            'curl -s -D %s -o %s -X PATCH '
            . '-H "Authorization: Bearer %s" '
            . '-H "Content-Type: %s" '
            . '--data-binary @%s '
            . '"%s/files/%s?uploadType=media"',
            escapeshellarg($headerFile),
            escapeshellarg($outFile),
            escapeshellarg($token),
            escapeshellarg($mime),
            escapeshellarg($localPath),
            escapeshellarg($this->apiBase),
            escapeshellarg($fileId)
        );

        exec($cmd, $out, $exitCode);
        $resp = file_get_contents($outFile);
        $hdrs = file_get_contents($headerFile);
        @unlink($headerFile);
        @unlink($outFile);

        $httpCode = 0;
        if (preg_match('/^HTTP\/[\d.]+\s+(\d+)/m', $hdrs, $m)) {
            $httpCode = (int)$m[1];
        }

        if ($httpCode !== 200) {
            $detail = json_decode($resp, true);
            throw new \RuntimeException(
                "Drive update failed (HTTP {$httpCode}): "
                . ($detail['error']['message'] ?? $resp)
            );
        }

        $data = json_decode($resp, true);
        return $data['id'] ?? $fileId;
    }

    private function curlGet(string $url, string $token): string
    {
        $r = $this->execCurl('GET', $url, null, ['Authorization: Bearer ' . $token]);
        if ($r['code'] !== 200) {
            $detail = json_decode($r['body'], true);
            throw new \RuntimeException(
                "Drive GET failed (HTTP {$r['code']}): "
                . ($detail['error']['message'] ?? $r['body'])
            );
        }
        return $r['body'];
    }

    private function curlPost(string $url, string $body, string $token): string
    {
        $r = $this->execCurl('POST', $url, $body, ['Authorization: Bearer ' . $token, 'Content-Type: application/json']);
        if ($r['code'] < 200 || $r['code'] >= 300) {
            $detail = json_decode($r['body'], true);
            throw new \RuntimeException(
                "Drive POST failed (HTTP {$r['code']}): "
                . ($detail['error']['message'] ?? $r['body'])
            );
        }
        return $r['body'];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function detectMimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls'  => 'application/vnd.ms-excel',
            'csv'  => 'text/csv',
            default => mime_content_type($path) ?: 'application/octet-stream',
        };
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
