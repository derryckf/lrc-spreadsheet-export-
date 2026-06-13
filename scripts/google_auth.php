#!/usr/bin/env php
<?php
/**
 * Google OAuth2 Authorization Script
 *
 * One-time setup: authorizes Drive access for the LRC Spreadsheet Export app.
 * Steps:
 *   1. Opens browser to Google OAuth consent page
 *   2. You grant access in browser
 *   3. You get redirected to localhost with a code in the URL
 *   4. Paste that full URL back here
 *   5. Script exchanges code for tokens and saves them to .env
 *
 * Usage: php scripts/google_auth.php
 */

declare(strict_types=1);

$clientSecretPath = __DIR__ . '/../.config/google_client.json';
if (!is_readable($clientSecretPath)) {
    echo "ERROR: Google OAuth client JSON not found.\n";
    echo "Expected: .config/google_client.json\n";
    echo "Download from: Google Cloud Console → APIs & Services → Credentials → OAuth Client ID\n";
    exit(1);
}

$clientData = json_decode(file_get_contents($clientSecretPath), true);
if (!$clientData || !isset($clientData['web'])) {
    echo "ERROR: Invalid client JSON format.\n";
    exit(1);
}

$web = $clientData['web'];
$clientId     = $web['client_id'];
$clientSecret = $web['client_secret'];
$redirectUri  = $web['redirect_uris'][0] ?? 'http://localhost:8080/oauth/callback';
$tokenUri     = $web['token_uri'] ?? 'https://oauth2.googleapis.com/token';

$scopes = [
    'https://www.googleapis.com/auth/drive',
    'https://www.googleapis.com/auth/spreadsheets',
];

// Build auth URL
$state = bin2hex(random_bytes(16));
$authUrl = 'https://accounts.google.com/o/oauth2/auth?'
    . http_build_query([
        'client_id'     => $clientId,
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'scope'         => implode(' ', $scopes),
        'access_type'   => 'offline',
        'prompt'        => 'consent',
        'state'         => $state,
    ]);

echo "\n";
echo "=== Google OAuth2 Drive Authorization ===\n\n";
echo "STEP 1: Opening browser for authorization...\n";
echo "(If browser doesn't open, copy the URL below and open it manually)\n\n";
echo "$authUrl\n\n";

// Try to open browser
$opened = false;
if (PHP_OS === 'Linux' && is_executable('/usr/bin/xdg-open')) {
    exec('/usr/bin/xdg-open ' . escapeshellarg($authUrl) . ' > /dev/null 2>&1');
    $opened = true;
} elseif (PHP_OS === 'Darwin') {
    exec('open ' . escapeshellarg($authUrl) . ' > /dev/null 2>&1');
    $opened = true;
}
echo $opened ? "Browser opened.\n\n" : "Manual open required.\n\n";

echo "STEP 2: After granting access in your browser, you will be redirected to:\n";
echo "  $redirectUri\n";
echo "  (Look at the address bar — the URL will contain ?code=...&state=...)\n\n";

echo "STEP 3: Paste the full redirect URL here and press Enter:\n";
echo "  URL: ";

$input = fgets(STDIN);
if ($input === false || trim($input) === '') {
    echo "\nNo URL provided. Exiting.\n";
    exit(1);
}
$redirectUrl = trim($input);

// Extract authorization code
$code = null;
if (preg_match('/[?&]code=([^&\s]+)/', $redirectUrl, $m)) {
    $code = $m[1];
}

if (!$code) {
    echo "ERROR: Could not find 'code' parameter in the URL.\n";
    echo "Make sure you paste the complete URL from the browser address bar.\n";
    exit(1);
}

echo "\nSTEP 4: Exchanging authorization code for access token...\n";

// Exchange code for tokens
$ch = curl_init($tokenUri);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri'  => $redirectUri,
        'grant_type'    => 'authorization_code',
        'code'          => $code,
    ]),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo "ERROR: Token exchange failed (HTTP $httpCode)\n";
    echo "Response: $resp\n";
    exit(1);
}

$tokens = json_decode($resp, true);
if (!isset($tokens['access_token'])) {
    echo "ERROR: No access_token in response.\n";
    exit(1);
}

$accessToken  = $tokens['access_token'];
$refreshToken = $tokens['refresh_token'] ?? '';
$expiresAt    = time() + ($tokens['expires_in'] ?? 3600);

echo "  Access token received.\n";
if ($refreshToken) {
    echo "  Refresh token received.\n";
} else {
    echo "  WARNING: No refresh token returned. Token auto-refresh may not work.\n";
}

// Save to .env
echo "\nSTEP 5: Saving tokens to .env...\n";

$envPath = __DIR__ . '/../.env';
$newLines = [];
if (is_readable($envPath)) {
    $newLines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
}

// Update or add env vars
$addLines = [
    'GOOGLE_OAUTH_ACCESS='  . $accessToken,
    'GOOGLE_OAUTH_REFRESH=' . $refreshToken,
    'GOOGLE_OAUTH_EXPIRES=' . $expiresAt,
    'GOOGLE_DRIVE_FOLDER_ID=1yHGqMyaAp-P0-fMrxaBSdqRHNaqjZ6Xa',
    'GOOGLE_DRIVE_CREDENTIALS=' . realpath($clientSecretPath),
];

// Process existing lines
$resultLines = [];
$added = ['GOOGLE_OAUTH_ACCESS' => false, 'GOOGLE_OAUTH_REFRESH' => false,
          'GOOGLE_OAUTH_EXPIRES' => false, 'GOOGLE_DRIVE_FOLDER_ID' => false,
          'GOOGLE_DRIVE_CREDENTIALS' => false];

foreach ($newLines as $line) {
    $trimmed = trim($line);
    if ($trimmed === '' || $trimmed[0] === '#') {
        $resultLines[] = $line;
        continue;
    }
    $key = strtok($line, '=');
    if (isset($added[$key])) {
        $idx = array_search($key, array_keys($added));
        $resultLines[] = $addLines[$idx];
        $added[$key] = true;
    } else {
        $resultLines[] = $line;
    }
}
foreach ($added as $key => $done) {
    if (!$done) {
        $idx = array_search($key, array_keys($added));
        $resultLines[] = $addLines[$idx];
    }
}

file_put_contents($envPath, implode("\n", $resultLines) . "\n");
echo "  Tokens saved to .env\n";

// Test Drive access
echo "\nSTEP 6: Testing Drive access...\n";
try {
    // Load autoloader
    require_once __DIR__ . '/../vendor/autoload.php';

    $service = new \App\Services\GoogleDriveService();
    $email = $service->getAuthenticatedUserEmail();
    echo "  Authenticated as: $email\n";

    // List files in the folder
    $folderId = '1yHGqMyaAp-P0-fMrxaBSdqRHNaqjZ6Xa';
    $files = $service->listFiles($folderId);
    echo "  Folder has " . count($files) . " file(s).\n";

    echo "\n✅ Authorization complete!\n";
    echo "Run 'php cli.php handicapper:gdrive:list' to verify.\n";
} catch (Throwable $e) {
    echo "  ❌ Drive test failed: " . $e->getMessage() . "\n";
    echo "  (Restart your terminal and try 'php cli.php handicapper:gdrive:list')\n";
}
