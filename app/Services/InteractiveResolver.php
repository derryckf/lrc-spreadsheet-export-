<?php
declare(strict_types=1);
namespace App\Services;

/**
 * InteractiveResolver — resolves unknown/new runners via interactive CLI prompt.
 *
 * Used when webscorer:resolve is run with --interactive.
 * The flow:
 *   1. Read manifest CSV
 *   2. Identify rows with member_id starting with 'tmp_' (unknown)
 *   3. For each unknown, show WebScorer data + DB candidates
 *   4. Accept user choice: M=match, U=update, C=create, S=skip
 *   5. Persist choice immediately to DB via MemberCreator
 *
 * Usage:
 *   $resolver = new InteractiveResolver($db, $logger, $config);
 *   $result = $resolver->resolveInteractive($manifestPath, $eventId, $eventRows);
 */
class InteractiveResolver
{
    private $db;
    private $logger;
    private array $config;
    private MemberCreator $creator;

    public function __construct(\PDO $db, $logger = null, array $config = [])
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->config = $config;
        $this->creator = new MemberCreator($db, $logger, $config);
    }

    /**
     * Run interactive resolution for a manifest CSV.
     *
     * @param string $manifestPath  Path to manifest.csv (already resolved, with member_id column)
     * @param int    $eventId       Event ID for eventEntry creation
     * @param array  $allRows       All manifest rows (passed from CLI for event context)
     * @return array  [created => int, matched => int, updated => int, skipped => int]
     */
    public function resolveInteractive(string $manifestPath, int $eventId, array $allRows): array
    {
        $stats = ['created' => 0, 'matched' => 0, 'updated' => 0, 'skipped' => 0];
        $unknowns = [];
        $known = [];

        // Separate unknowns from known
        foreach ($allRows as $row) {
            if (str_starts_with($row['member_id'] ?? '', 'tmp_')) {
                $unknowns[] = $row;
            } else {
                $known[] = $row;
            }
        }

        $this->log("─────────────────────────────────────────────────");
        $this->log(sprintf("Processing %d runners: %d known, %d unknown",
            count($allRows), count($known), count($unknowns)));
        $this->log("─────────────────────────────────────────────────");

        if (empty($unknowns)) {
            $this->log("All runners resolved — no interactive resolution needed.");
            return $stats;
        }

        $this->log(sprintf("\n%d unknown runner(s) — interactive resolution required.\n", count($unknowns)));

        foreach ($unknowns as $idx => $row) {
            $rowNum = $idx + 2;
            $this->showUnknownRunner($row, $rowNum, $idx, $stats, $unknowns);
        }

        $this->log("\n─────────────────────────────────────────────────");
        $this->log(sprintf("Summary: %d created, %d matched, %d updated, %d skipped",
            $stats['created'], $stats['matched'], $stats['updated'], $stats['skipped']));

        return $stats;
    }

    private function showUnknownRunner(array $row, int $rowNum, int $idx, array &$stats, array $allUnknowns): void
    {
        $name = trim(($row['firstName'] ?? '') . ' ' . trim($row['lastName'] ?? ''));
        $dob = trim($row['DOB'] ?? '');
        $gender = trim($row['gender'] ?? '');
        $email = trim($row['email'] ?? '');
        $tagNo = trim($row['webscorer_tagNo'] ?? '');
        $category = trim($row['category'] ?? '');
        $distance = trim($row['distance'] ?? '');

        $this->log(sprintf("\n[%d] Row %d: %s (DOB: %s, %s, %s)", $idx ?? 0, $rowNum, $name, $dob, $gender, $category));
        $this->log(sprintf("    Tag: %s | Email: %s | Distance: %s", $tagNo ?: '(none)', $email ?: '(none)', $distance));

        // Find partial matches in DB (by lastName or similar firstName)
        $partialMatches = $this->findPartialMatches($row);
        if (!empty($partialMatches)) {
            $this->log("    Partial DB matches:");
            foreach ($partialMatches as $m) {
                $this->log(sprintf("      ID=%d | %s %s | DOB: %s | sex: %s",
                    $m['id'], $m['firstName'], $m['lastName'], $m['DOB'], $m['sex']));
            }
        }

        // Show options
        $this->log("    [M]atch to existing member ID  [U]pdate existing member");
        $this->log("    [C]reate new member            [S]kip this runner");
        $this->log("    [A]pprove remaining as new      [Q]uit processing");

        $choice = $this->promptChoice("Choice: ");

        $parts = explode(' ', trim($choice), 2);
        $cmd = strtoupper($parts[0]);
        $arg = $parts[1] ?? '';

        switch ($cmd) {
            case 'M':
                $this->handleMatch($row, $arg, $stats);
                break;
            case 'U':
                $this->handleUpdate($row, $arg, $stats);
                break;
            case 'C':
                $this->handleCreate($row, $stats);
                break;
            case 'S':
                $this->log("  → Skipped.");
                $stats['skipped']++;
                break;
            case 'A':
                $this->handleApproveRemaining($row, $idx, $allUnknowns, $stats);
                return; // remaining handled in bulk
            case 'Q':
                $this->log("  → Quitting.");
                throw new \RuntimeException("USER_QUIT");
            default:
                $this->log("  → Unknown command — skipping.");
                $stats['skipped']++;
                break;
        }
    }

    private function handleMatch(array $row, string $memberIdStr, array &$stats): void
    {
        $id = (int)$memberIdStr;
        if ($id <= 0) {
            $this->log("  → Invalid member ID — skipping.");
            $stats['skipped']++;
            return;
        }
        // Verify member exists
        $stmt = $this->db->prepare("SELECT id, firstName, lastName, DOB, sex FROM member WHERE id = ?");
        $stmt->execute([$id]);
        $member = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$member) {
            $this->log("  → Member ID $id not found — skipping.");
            $stats['skipped']++;
            return;
        }
        $this->log(sprintf("  → Linked to existing member %d: %s %s", $id, $member['firstName'], $member['lastName']));
        // Upsert eventEntry for this member (no member table update)
        $this->upsertEventEntryOnly($id, $row);
        $stats['matched']++;
    }

    private function handleUpdate(array $row, string $memberIdStr, array &$stats): void
    {
        $id = (int)$memberIdStr;
        if ($id <= 0) {
            $this->log("  → Invalid member ID — skipping.");
            $stats['skipped']++;
            return;
        }
        $stmt = $this->db->prepare("SELECT id, firstName, lastName, DOB, sex FROM member WHERE id = ?");
        $stmt->execute([$id]);
        $member = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$member) {
            $this->log("  → Member ID $id not found — skipping.");
            $stats['skipped']++;
            return;
        }

        $this->log(sprintf("  Current DB: %d | %s | %s | DOB: %s | sex: %s",
            $member['id'], $member['firstName'], $member['lastName'], $member['DOB'], $member['sex']));
        $this->log(sprintf("  WebScorer:  %s | %s | DOB: %s | sex: %s",
            $row['firstName'] ?? '', $row['lastName'] ?? '', $row['DOB'] ?? '', $row['gender'] ?? ''));

        $this->log("  Update which field?");
        $fields = [
            'firstName' => $row['firstName'] ?? '',
            'lastName'  => $row['lastName'] ?? '',
            'DOB'       => $row['DOB'] ?? '',
            'sex'       => strtoupper(substr($row['gender'] ?? '', 0, 1)) ?: $member['sex'],
        ];
        foreach ($fields as $field => $newVal) {
            $current = $member[$field] ?? '';
            $changed = $current !== $newVal ? " → UPDATE to: $newVal" : " (no change)";
            $this->log(sprintf("    [%s]: current='%s'%s", strtoupper($field[0]), $current, $changed));
        }

        $choice = $this->promptChoice("  [W]rite all changes  [S]kip this runner: ");
        if (strtoupper(trim($choice)) === 'W') {
            $updates = [];
            $params = ['id' => $id];
            if ($fields['firstName'] !== $member['firstName']) {
                $updates[] = 'firstName = :firstName';
                $params['firstName'] = $fields['firstName'];
            }
            if ($fields['lastName'] !== $member['lastName']) {
                $updates[] = 'lastName = :lastName';
                $params['lastName'] = $fields['lastName'];
            }
            if ($fields['DOB'] !== $member['DOB']) {
                $updates[] = 'DOB = :dob';
                $params['dob'] = $this->formatDate($fields['DOB']);
            }
            if ($fields['sex'] !== $member['sex']) {
                $updates[] = 'sex = :sex';
                $params['sex'] = $fields['sex'];
            }
            if (!empty($updates)) {
                $sql = "UPDATE member SET " . implode(', ', $updates) . ", lastModDate = NOW() WHERE id = :id";
                $this->db->prepare($sql)->execute($params);
                $this->log(sprintf("  → Updated member %d", $id));
            } else {
                $this->log("  → No changes needed.");
            }
            // Always create eventEntry
            $this->upsertEventEntryOnly($id, $row);
            $stats['updated']++;
        } else {
            $this->log("  → Skipped.");
            $stats['skipped']++;
        }
    }

    private function handleCreate(array $row, array &$stats): void
    {
        $firstName = trim($row['firstName'] ?? '');
        $lastName  = trim($row['lastName'] ?? '');
        $dob       = trim($row['DOB'] ?? '');
        $gender    = strtoupper(substr(trim($row['gender'] ?? 'U'), 0, 1));
        $email     = trim(strtolower($row['email'] ?? ''));
        $phone     = trim($row['phone'] ?? '');
        $tagNo     = trim($row['webscorer_tagNo'] ?? '');

        $maxRegNo = $this->db->query("SELECT MAX(regNo) FROM member")->fetchColumn();
        $newRegNo = ((int)$maxRegNo) + 1;

        $this->db->prepare(
            "INSERT INTO member (regNo, firstName, lastName, DOB, sex, status, paid, createDate)
             VALUES (:regNo, :firstName, :lastName, :dob, :sex, 'prov', 0, NOW())"
        )->execute([
            'regNo'     => $newRegNo,
            'firstName' => $firstName,
            'lastName'  => $lastName,
            'dob'       => $this->formatDate($dob),
            'sex'       => $gender,
        ]);
        $memberId = (int)$this->db->lastInsertId();
        $this->log(sprintf("  → Created member %d: %s %s", $memberId, $firstName, $lastName));

        // Upsert tagNo
        if ($tagNo !== '') {
            $tagNoId = $this->upsertTagNo($tagNo);
        }
        // Upsert eventEntry
        $this->upsertEventEntryOnly($memberId, $row);
        $stats['created']++;
    }

    private function handleApproveRemaining(array $startRow, int $startIdx, array $allUnknowns, array &$stats): void
    {
        $this->log("  → Bulk creating remaining " . (count($unknowns) - $startIdx) . " runner(s) as new members...");
        for ($i = $startIdx; $i < count($unknowns); $i++) {
            $row = $unknowns[$i];
            $firstName = trim($row['firstName'] ?? '');
            $lastName  = trim($row['lastName'] ?? '');
            $dob       = trim($row['DOB'] ?? '');
            $gender    = strtoupper(substr(trim($row['gender'] ?? 'U'), 0, 1));
            $tagNo     = trim($row['webscorer_tagNo'] ?? '');

            $maxRegNo = $this->db->query("SELECT MAX(regNo) FROM member")->fetchColumn();
            $newRegNo = ((int)$maxRegNo) + 1;

            $this->db->prepare(
                "INSERT INTO member (regNo, firstName, lastName, DOB, sex, status, paid, createDate)
                 VALUES (:regNo, :firstName, :lastName, :dob, :sex, 'prov', 0, NOW())"
            )->execute([
                'regNo'     => $newRegNo,
                'firstName' => $firstName,
                'lastName'  => $lastName,
                'dob'       => $this->formatDate($dob),
                'sex'       => $gender,
            ]);
            $memberId = (int)$this->db->lastInsertId();
            $this->log(sprintf("  Created: member %d: %s %s", $memberId, $firstName, $lastName));
            $this->upsertEventEntryOnly($memberId, $row);
            $stats['created']++;
        }
        $this->log("  → Bulk create complete.");
    }

    /**
     * Find partial matches (by lastName or similar firstName) for a manifest row.
     */
    private function findPartialMatches(array $row): array
    {
        $lastName = trim($row['lastName'] ?? '');
        $firstName = trim($row['firstName'] ?? '');
        if ($lastName === '') {
            return [];
        }
        $stmt = $this->db->prepare(
            "SELECT id, firstName, lastName, DOB, sex
             FROM member
             WHERE LOWER(lastName) = LOWER(:ln)
             LIMIT 10"
        );
        $stmt->execute(['ln' => $lastName]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function upsertEventEntryOnly(int $memberId, array $row): void
    {
        $tagNo = trim($row['webscorer_tagNo'] ?? '');
        $tagNoId = null;
        if ($tagNo !== '') {
            $tagNoId = $this->upsertTagNo($tagNo);
        }
        $eventId = (int)($row['_eventId'] ?? 0);
        if ($eventId <= 0) {
            return;
        }

        // Check if eventEntry already exists
        $stmt = $this->db->prepare("SELECT id FROM eventEntry WHERE event_id = ? AND member_id = ?");
        $stmt->execute([$eventId, $memberId]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

        $paid = (float)($row['eventfee'] ?? 0) > 0 ? 1 : 0;

        if ($existing) {
            $this->db->prepare(
                "UPDATE eventEntry SET tagNo_id = ?, paid = ?, lastModDate = NOW()
                 WHERE event_id = ? AND member_id = ?"
            )->execute([$tagNoId, $paid, $eventId, $memberId]);
        } else {
            $this->db->prepare(
                "INSERT INTO eventEntry (event_id, member_id, tagNo_id, paid, able, createDate, lastModDate)
                 VALUES (:eventId, :memberId, :tagNoId, :paid, null, NOW(), NOW())"
            )->execute([
                'eventId'  => $eventId,
                'memberId' => $memberId,
                'tagNoId'  => $tagNoId,
                'paid'     => $paid,
            ]);
        }
    }

    private function upsertTagNo(string $tagNo): ?int
    {
        if ($tagNo === '') {
            return null;
        }
        $stmt = $this->db->prepare("SELECT id FROM tagNo WHERE tagNo = :tagNo");
        $stmt->execute(['tagNo' => $tagNo]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($existing) {
            return (int)$existing['id'];
        }
        $this->db->prepare("INSERT INTO tagNo (tagNo) VALUES (:tagNo)")->execute(['tagNo' => $tagNo]);
        return (int)$this->db->lastInsertId();
    }

    private function formatDate(string $dob): string
    {
        $dob = trim($dob);
        foreach (['Y-m-d', 'd/m/Y', 'm/d/Y', 'Y/m/d'] as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $dob);
            if ($dt !== false) {
                return $dt->format('Y-m-d');
            }
        }
        return $dob;
    }

    private function log(string $msg): void
    {
        if ($this->logger) {
            $this->logger->info($msg);
        } else {
            echo $msg . "\n";
        }
    }

    private function promptChoice(string $prompt): string
    {
        echo $prompt;
        $handle = fopen('php://stdin', 'r');
        $line = trim(fgets($handle));
        fclose($handle);
        return $line;
    }
}