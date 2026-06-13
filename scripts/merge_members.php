#!/usr/bin/env php
<?php
/**
 * Merge duplicate member records into the canonical (lowest-ID) record.
 *
 * All eventResult, eventEntry, and phone records pointing to the duplicate
 * member IDs are updated to point to the canonical member ID instead.
 * The duplicate member records themselves are left in place (no deletion).
 *
 * Usage:
 *   php scripts/merge_members.php <canonical_id> <dup1_id> [<dup2_id> ...]
 *   php scripts/merge_members.php --list <lastname>   # find duplicate members by surname
 *   php scripts/merge_members.php --check <id>        # show all records sharing a name with member <id>
 *
 * Examples:
 *   php scripts/merge_members.php 534 1800 2762
 *   php scripts/merge_members.php --check 1800
 *   php scripts/merge_members.php --list Smith
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$args = $argv;
array_shift($args); // script name

if (empty($args)) {
    echo "Usage:\n";
    echo "  php scripts/merge_members.php <canonical_id> <dup1_id> [<dup2_id> ...]\n";
    echo "  php scripts/merge_members.php --list <lastname>\n";
    echo "  php scripts/merge_members.php --check <member_id>\n";
    exit(1);
}

// ── --list mode: find members sharing a lastname ────────────────────────────
if ($args[0] === '--list') {
    $lastName = $args[1] ?? '';
    if ($lastName === '') {
        fwrite(STDERR, "Usage: php scripts/merge_members.php --list <lastname>\n");
        exit(1);
    }
    $db = getDbConnection();
    $stmt = $db->prepare("
        SELECT m.id, m.firstName, m.lastName, m.DOB, m.sex,
               COALESCE(erc.cnt, 0) AS event_result_count,
               COALESCE(eec.cnt, 0) AS event_entry_count,
               COALESCE(pc.cnt, 0) AS phone_count
        FROM member m
        LEFT JOIN (SELECT member_id, COUNT(*) AS cnt FROM eventResult GROUP BY member_id) erc ON erc.member_id = m.id
        LEFT JOIN (SELECT member_id, COUNT(*) AS cnt FROM eventEntry GROUP BY member_id) eec ON eec.member_id = m.id
        LEFT JOIN (SELECT member_id, COUNT(*) AS cnt FROM phone GROUP BY member_id) pc ON pc.member_id = m.id
        WHERE LOWER(m.lastName) = LOWER(:lastName)
        ORDER BY m.id ASC
    ");
    $stmt->execute(['lastName' => $lastName]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    if (empty($rows)) {
        echo "No members found with lastName: $lastName\n";
        exit(0);
    }

    echo "\n=== Members with lastName='$lastName' ===\n";
    printf("%-5s %-20s %-12s %-5s %-12s %s\n", 'ID', 'firstName', 'lastName', 'DOB', 'Sex', 'Results/Entries/Phones');
    echo str_repeat('-', 75) . "\n";
    foreach ($rows as $r) {
        printf("%-5s %-20s %-12s %-5s %-12s %s/%s/%s\n",
            $r['id'], $r['firstName'], $r['lastName'], $r['DOB'],
            $r['sex'], $r['event_result_count'], $r['event_entry_count'], $r['phone_count']);
    }
    echo str_repeat('-', 75) . "\n";
    echo count($rows) . " total members with lastName='$lastName'\n\n";

    // Group by firstName to find true name-variant duplicates (same logical person)
    $byFirstName = [];
    foreach ($rows as $r) {
        $key = strtolower($r['firstName']);
        $byFirstName[$key][] = $r;
    }

    echo "Potential duplicate groups (same firstName, need review):\n";
    foreach ($byFirstName as $fn => $group) {
        $withData = array_filter($group, fn($r) => $r['event_result_count'] > 0 || $r['event_entry_count'] > 0);
        if (count($group) > 1) {
            $ids = array_column($group, 'id');
            $canonical = min($ids);
            $dupes = array_diff($ids, [$canonical]);
            echo "  '$fn': " . implode(', ', $ids);
            if (count($withData) > 0) {
                echo "  → merge: php scripts/merge_members.php $canonical " . implode(' ', $dupes);
 }
            echo "\n";
        }
    }
    echo "\nUse --check <id> to see all records with the same firstName+lastName as a specific member.\n";
    exit(0);
}

// ── --check mode: show all members with same firstName+lastName as a given ID ─
if ($args[0] === '--check') {
    $checkId = (int)($args[1] ?? 0);
    if ($checkId === 0) {
        fwrite(STDERR, "Usage: php scripts/merge_members.php --check <member_id>\n");
        exit(1);
    }
    $db = getDbConnection();

    // Get the reference member
    $ref = $db->prepare("SELECT firstName, lastName FROM member WHERE id = ?");
    $ref->execute([$checkId]);
    $refRow = $ref->fetch(\PDO::FETCH_ASSOC);
    if (!$refRow) {
        fwrite(STDERR, "Member ID $checkId not found.\n");
        exit(1);
    }

    $stmt = $db->prepare("
        SELECT m.id, m.firstName, m.lastName, m.DOB, m.sex,
               COALESCE(erc.cnt, 0) AS event_result_count,
               COALESCE(eec.cnt, 0) AS event_entry_count,
               COALESCE(pc.cnt, 0) AS phone_count
        FROM member m
        LEFT JOIN (SELECT member_id, COUNT(*) AS cnt FROM eventResult GROUP BY member_id) erc ON erc.member_id = m.id
        LEFT JOIN (SELECT member_id, COUNT(*) AS cnt FROM eventEntry GROUP BY member_id) eec ON eec.member_id = m.id
        LEFT JOIN (SELECT member_id, COUNT(*) AS cnt FROM phone GROUP BY member_id) pc ON pc.member_id = m.id
        WHERE LOWER(m.firstName) = LOWER(:fn) AND LOWER(m.lastName) = LOWER(:ln)
        ORDER BY m.id ASC
    ");
    $stmt->execute(['fn' => $refRow['firstName'], 'ln' => $refRow['lastName']]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    echo "\n=== Members matching '{$refRow['firstName']} {$refRow['lastName']}' ===\n";
    printf("%-5s %-20s %-12s %-5s %-12s %s\n", 'ID', 'firstName', 'lastName', 'DOB', 'Sex', 'Results/Entries/Phones');
    echo str_repeat('-', 75) . "\n";
    foreach ($rows as $r) {
        $marker = ($r['id'] == $checkId) ? ' [check target]' : '';
        printf("%-5s %-20s %-12s %-5s %-12s %s/%s/%s%s\n",
            $r['id'], $r['firstName'], $r['lastName'], $r['DOB'],
            $r['sex'], $r['event_result_count'], $r['event_entry_count'], $r['phone_count'], $marker);
    }
    echo "\n";
    if (count($rows) > 1) {
        $ids = array_column($rows, 'id');
        $canonical = min($ids);
        $dupes = array_diff($ids, [$canonical]);
        echo "Canonical (lowest ID): $canonical\n";
        echo "Merge command: php scripts/merge_members.php $canonical " . implode(' ', $dupes) . "\n";
    }
    exit(0);
}

// ── Merge mode ───────────────────────────────────────────────────────────────
if (count($args) < 2) {
    fwrite(STDERR, "Usage: php scripts/merge_members.php <canonical_id> <dup1_id> [<dup2_id> ...]\n");
    exit(1);
}

$canonicalId = (int)$args[0];
$duplicateIds = array_map('intval', array_slice($args, 1));
$allIds = array_merge([$canonicalId], $duplicateIds);

$db = getDbConnection();

// Verify canonical exists
$canonCheck = $db->prepare("SELECT id, firstName, lastName FROM member WHERE id = ?");
$canonCheck->execute([$canonicalId]);
$canon = $canonCheck->fetch(\PDO::FETCH_ASSOC);
if (!$canon) {
    fwrite(STDERR, "Canonical member ID $canonicalId not found.\n");
    exit(1);
}

echo "\n=== Merging into canonical: {$canon['firstName']} {$canon['lastName']} (id={$canonicalId}) ===\n";
echo "Duplicate IDs: " . implode(', ', $duplicateIds) . "\n\n";

// Tables to merge
$tables = [
    'eventResult' => 'member_id',
    'eventEntry'  => 'member_id',
    'phone'       => 'member_id',
];

foreach ($tables as $table => $column) {
    $inPlaceholders = implode(',', array_fill(0, count($duplicateIds), '?'));
    $sql = "UPDATE {$table} SET {$column} = ? WHERE {$column} IN ({$inPlaceholders})";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$canonicalId], $duplicateIds));
    $affected = $stmt->rowCount();
    echo "  $table: $affected row(s) updated\n";
}

echo "\nDone. Verify with:\n";
echo "  php scripts/merge_members.php --check $canonicalId\n";