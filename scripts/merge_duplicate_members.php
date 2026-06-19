<?php
/**
 * Merge duplicate member records.
 *
 * For each pair [keep_id, remove_id, correct_dob]:
 *   - Update keep member's DOB.
 *   - Move eventResult records from remove_id to keep_id (skip duplicates).
 *   - Update eventEntry, everyEvent member_id from remove_id to keep_id.
 *   - Merge email/phone: prefer the remove member's contact info (newer registration),
 *     update keep member's linked email/phone rows in place.
 *   - Null out remove member's email_id/phone_id and delete it.
 *
 * Usage:
 *   php merge_duplicate_members.php <keep_id> <remove_id> <correct_dob> [<keep_id> <remove_id> <correct_dob> ...]
 *
 * Example:
 *   php merge_duplicate_members.php 2877 2882 1998-05-06 2835 2887 2015-08-13
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

$args = array_slice($argv, 1);
if (count($args) % 3 !== 0 || count($args) < 3) {
    fwrite(STDERR, "Usage: php merge_duplicate_members.php <keep_id> <remove_id> <correct_dob> ...\n");
    exit(1);
}

$pairs = [];
for ($i = 0; $i < count($args); $i += 3) {
    $pairs[] = [
        'keep' => (int)$args[$i],
        'remove' => (int)$args[$i + 1],
        'dob' => $args[$i + 2],
    ];
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

$backupDir = __DIR__ . '/../backups';
if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
$ts = date('Ymd_His');

function backupTable(PDO $pdo, string $table, string $backupDir, string $ts): void
{
    $file = "{$backupDir}/{$table}_{$ts}.sql";
    $rows = $pdo->query("SELECT * FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);
    $fh = fopen($file, 'w');
    fwrite($fh, "-- Backup of {$table} before member merge\n");
    if (empty($rows)) {
        fwrite($fh, "-- (empty table)\n");
        fclose($fh);
        return;
    }
    $cols = array_keys($rows[0]);
    fwrite($fh, "INSERT INTO {$table} (" . implode(', ', $cols) . ") VALUES\n");
    $first = true;
    foreach ($rows as $r) {
        if (!$first) fwrite($fh, ",\n");
        $first = false;
        $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $r);
        fwrite($fh, '(' . implode(', ', $vals) . ')');
    }
    fwrite($fh, ";\n");
    fclose($fh);
    echo "Backed up {$table} (" . count($rows) . " rows) to {$file}\n";
}

// Backup affected tables once
foreach (['member', 'eventResult', 'eventEntry', 'everyEvent', 'email', 'phone'] as $table) {
    backupTable($pdo, $table, $backupDir, $ts);
}

foreach ($pairs as $pair) {
    $keepId = $pair['keep'];
    $removeId = $pair['remove'];
    $correctDob = $pair['dob'];

    echo "\n=== Merging: keep={$keepId}, remove={$removeId}, correct DOB={$correctDob} ===\n";

    $pdo->beginTransaction();
    try {
        // Fetch members
        $mStmt = $pdo->prepare("SELECT * FROM member WHERE id IN (?, ?)");
        $mStmt->execute([$keepId, $removeId]);
        $members = [];
        foreach ($mStmt->fetchAll() as $row) {
            $members[(int)$row['id']] = $row;
        }
        if (!isset($members[$keepId])) {
            throw new RuntimeException("Keep member {$keepId} not found");
        }
        if (!isset($members[$removeId])) {
            echo "Remove member {$removeId} already gone, skipping\n";
            $pdo->rollBack();
            continue;
        }

        $keep = $members[$keepId];
        $remove = $members[$removeId];

        // 1. Update keep member DOB
        $upd = $pdo->prepare("UPDATE member SET DOB = ?, lastModDate = CURDATE() WHERE id = ?");
        $upd->execute([$correctDob, $keepId]);
        echo "Updated member {$keepId} DOB to {$correctDob}\n";

        // 2. Merge email: prefer remove member's email (newer registration data)
        if (!empty($remove['email_id'])) {
            $emailStmt = $pdo->prepare("SELECT * FROM email WHERE id = ?");
            $emailStmt->execute([$remove['email_id']]);
            $removeEmail = $emailStmt->fetch();

            if ($removeEmail && !empty($removeEmail['emailAddress'])) {
                if (!empty($keep['email_id'])) {
                    // Update keep member's existing email address in place
                    $updEmail = $pdo->prepare("UPDATE email SET emailAddress = ? WHERE id = ?");
                    $updEmail->execute([$removeEmail['emailAddress'], $keep['email_id']]);
                    echo "Updated keep email (id={$keep['email_id']}) to {$removeEmail['emailAddress']}\n";
                } else {
                    // Adopt remove member's email
                    $updMember = $pdo->prepare("UPDATE member SET email_id = ? WHERE id = ?");
                    $updMember->execute([$remove['email_id'], $keepId]);
                    echo "Adopted remove email (id={$remove['email_id']}) for keep member\n";
                }
            }
        }

        // 3. Merge phone: prefer remove member's phone
        if (!empty($remove['phone_id'])) {
            $phoneStmt = $pdo->prepare("SELECT * FROM phone WHERE id = ?");
            $phoneStmt->execute([$remove['phone_id']]);
            $removePhone = $phoneStmt->fetch();

            if ($removePhone && !empty($removePhone['number'])) {
                if (!empty($keep['phone_id'])) {
                    $updPhone = $pdo->prepare("UPDATE phone SET number = ? WHERE id = ?");
                    $updPhone->execute([$removePhone['number'], $keep['phone_id']]);
                    echo "Updated keep phone (id={$keep['phone_id']}) to {$removePhone['number']}\n";
                } else {
                    $updMember = $pdo->prepare("UPDATE member SET phone_id = ? WHERE id = ?");
                    $updMember->execute([$remove['phone_id'], $keepId]);
                    echo "Adopted remove phone (id={$remove['phone_id']}) for keep member\n";
                }
            }
        }

        // 4. Move eventResult records from remove to keep, skipping duplicates (member_id+event_id)
        $erStmt = $pdo->prepare("SELECT * FROM eventResult WHERE member_id = ?");
        $erStmt->execute([$removeId]);
        $moved = 0;
        $skipped = 0;
        foreach ($erStmt->fetchAll() as $er) {
            $check = $pdo->prepare("SELECT id FROM eventResult WHERE member_id = ? AND event_id = ?");
            $check->execute([$keepId, $er['event_id']]);
            if ($check->fetch()) {
                // Duplicate would be created; delete remove's record instead
                $del = $pdo->prepare("DELETE FROM eventResult WHERE id = ?");
                $del->execute([$er['id']]);
                $skipped++;
            } else {
                $upd = $pdo->prepare("UPDATE eventResult SET member_id = ? WHERE id = ?");
                $upd->execute([$keepId, $er['id']]);
                $moved++;
            }
        }
        echo "Moved {$moved} eventResult records, deleted {$skipped} duplicates\n";

        // 5. Update eventEntry member_id from remove to keep
        $updEe = $pdo->prepare("UPDATE eventEntry SET member_id = ? WHERE member_id = ?");
        $updEe->execute([$keepId, $removeId]);
        echo "Updated {$updEe->rowCount()} eventEntry rows\n";

        // 6. Update everyEvent member_id from remove to keep
        $updEv = $pdo->prepare("UPDATE everyEvent SET member_id = ? WHERE member_id = ?");
        $updEv->execute([$keepId, $removeId]);
        echo "Updated {$updEv->rowCount()} everyEvent rows\n";

        // 7. Null out remove member's email/phone references and delete it
        $nullMember = $pdo->prepare("UPDATE member SET email_id = NULL, phone_id = NULL WHERE id = ?");
        $nullMember->execute([$removeId]);

        $delMember = $pdo->prepare("DELETE FROM member WHERE id = ?");
        $delMember->execute([$removeId]);
        echo "Deleted member {$removeId}\n";

        // 8. Clean up orphaned email/phone rows that are no longer referenced
        $delEmail = $pdo->prepare(<<<'SQL'
            DELETE e FROM email e
            LEFT JOIN member m ON m.email_id = e.id
            WHERE m.id IS NULL
              AND e.id = ?
SQL);
        if (!empty($remove['email_id'])) {
            $delEmail->execute([$remove['email_id']]);
            if ($delEmail->rowCount() > 0) {
                echo "Deleted orphaned email id={$remove['email_id']}\n";
            }
        }

        $delPhone = $pdo->prepare(<<<'SQL'
            DELETE p FROM phone p
            LEFT JOIN member m ON m.phone_id = p.id
            WHERE m.id IS NULL
              AND p.id = ?
SQL);
        if (!empty($remove['phone_id'])) {
            $delPhone->execute([$remove['phone_id']]);
            if ($delPhone->rowCount() > 0) {
                echo "Deleted orphaned phone id={$remove['phone_id']}\n";
            }
        }

        $pdo->commit();
        echo "Merge completed for keep={$keepId}, remove={$removeId}\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "ERROR merging keep={$keepId}, remove={$removeId}: " . $e->getMessage() . "\n");
        throw $e;
    }
}

echo "\nAll merges complete.\n";
