<?php
declare(strict_types=1);
namespace App\Services;

use PDO;

/**
 * Creates member records and populates eventEntry records from a manifest CSV.
 *
 * Reads rows from an identity manifest CSV (produced by IdentityResolver):
 * - For rows with tmp_<uuid> member_id: creates member, email, phone, tagNo records
 * - For all rows: upserts tagNo into tagNo table, upserts eventEntry record
 *
 * Uses INSERT ON DUPLICATE KEY UPDATE (upsert) for idempotency.
 *
 * Usage:
 *   $creator = new MemberCreator($pdo, $logger, $config);
 *   $creator->createMembers($manifestPath, $eventId);
 */
class MemberCreator
{
    private PDO $db;
    /** @var object|null */
    private $logger;
    private array $config;

    /** @var array[int] Generated member IDs keyed by tmp_id */
    private array $tmpIdMap = [];

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

    public function __construct(
        PDO $db,
        $logger = null,
        array $config = []
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->config = array_merge([
            'storage_path' => 'handicapping',
        ], $config);
    }

    /**
     * Process a manifest CSV — create members and eventEntry records.
     *
     * @param string $manifestPath  Path to identity manifest CSV
     * @param int    $eventId       Event ID for eventEntry records
     * @return array{total:int, created:int, updated:int, skipped:int}
     */
    public function createMembers(string $manifestPath, int $eventId): array
    {
        if (!file_exists($manifestPath)) {
            throw new \RuntimeException("Manifest not found: {$manifestPath}");
        }

        $this->logger()->info("Creating members from manifest: {$manifestPath}");

        $stats = ['total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];
        $handle = fopen($manifestPath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open manifest: {$manifestPath}");
        }

        $headers = fgetcsv($handle);
        $headers = array_map('trim', $headers);
        $headerMap = array_flip($headers);

        // Validate required headers
        $required = ['member_id', 'firstName', 'lastName', 'DOB', 'gender',
                     'tagNo_resolved', 'webscorer_tagNo', 'eventfee', 'registrationtime',
                     'distance', 'category'];
        foreach ($required as $col) {
            if (!isset($headerMap[$col])) {
                throw new \RuntimeException("Manifest missing required column: {$col}");
            }
        }

        // Pre-check that event_id exists in event table
        $eventStmt = $this->db->prepare("SELECT id, eventDate, division, distance FROM event WHERE id = ?");
        $eventStmt->execute([$eventId]);
        $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
        if (!$event) {
            throw new \RuntimeException("Event not found: {$eventId}");
        }
        $this->logger()->info("Event {$eventId}: {$event['eventDate']}, div {$event['division']}, {$event['distance']}km");

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);
            $stats['total']++;

            try {
                $result = $this->processRow($data, $eventId, $event);
                $stats[$result]++;
            } catch (\Throwable $e) {
                $this->logger()->warning("Row {$stats['total']}: " . $e->getMessage());
                $stats['skipped']++;
            }
        }
        fclose($handle);

        $this->logger()->info("Complete: {$stats['created']} created, {$stats['updated']} updated, {$stats['skipped']} skipped");

        return $stats;
    }

    /**
     * Create/upsert member records from an array of row data.
     * Used by interactive resolve to process known rows.
     *
     * @param array[] $rows  Array of row arrays (with member_id, firstName, etc.)
     * @param int    $eventId
     * @param array  $event   Event row from DB
     * @return array{total:int, created:int, updated:int, skipped:int}
     */
    public function createMembersFromArray(array $rows, int $eventId, array $event): array
    {
        $stats = ['total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];
        foreach ($rows as $data) {
            $data['_eventId'] = $eventId;
            $stats['total']++;
            try {
                $result = $this->processRow($data, $eventId, $event);
                $stats[$result]++;
            } catch (\Throwable $e) {
                $this->logger()->warning("Row: " . $e->getMessage());
                $stats['skipped']++;
            }
        }
        return $stats;
    }

    /**
     * Process a single manifest row.
     */
    private function processRow(array $data, int $eventId, array $event): string
    {
        $isNew = str_starts_with($data['member_id'], 'tmp_');
        $firstName = trim($data['firstName'] ?? '');
        $lastName  = trim($data['lastName'] ?? '');
        $dob       = trim($data['DOB'] ?? '');
        $gender    = strtoupper(substr(trim($data['gender'] ?? 'U'), 0, 1));
        $email     = trim(strtolower($data['email'] ?? ''));
        $phone     = trim($data['phone'] ?? '');
        $tagNo     = trim($data['tagNo_resolved'] ?? '');
        $distance  = $event['distance'] ?? 5.0;
        $paid      = $this->parsePaid($data['eventfee'] ?? '');

        if ($firstName === '' || $lastName === '') {
            throw new \RuntimeException("Missing firstName or lastName");
        }

        if ($isNew) {
            // ── Create new member ──────────────────────────────────────────
            $memberId = $this->insertMember($firstName, $lastName, $dob, $gender);
            $this->logger()->info("Created member {$memberId}: {$firstName} {$lastName} ({$dob})");
        } else {
            $memberId = (int)$data['member_id'];
        }

        // ── Upsert tagNo ──────────────────────────────────────────────────
        if ($tagNo !== '') {
            $tagNoId = $this->upsertTagNo($tagNo);
        }

        // ── Upsert eventEntry ─────────────────────────────────────────────
        $this->upsertEventEntry($memberId, $eventId, $tagNo ?? null, $paid);

        // ── Upsert email ─────────────────────────────────────────────────
        if ($email !== '') {
            $this->upsertEmail($memberId, $email);
        }

        // ── Upsert phone ────────────────────────────────────────────────
        if ($phone !== '') {
            $this->upsertPhone($memberId, $phone);
        }

        return $isNew ? 'created' : 'updated';
    }

    /**
     * Insert a new member record and return the new member_id.
     */
    private function insertMember(string $firstName, string $lastName, string $dob, string $gender): int
    {
        // Get next available regNo
        $maxRegNo = $this->db->query("SELECT MAX(regNo) FROM member")->fetchColumn();
        $newRegNo = ((int)$maxRegNo) + 1;

        $sql = "INSERT INTO member (regNo, firstName, lastName, DOB, sex, status, paid, createDate)
                VALUES (:regNo, :firstName, :lastName, :dob, :sex, 'prov', 0, NOW())";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'regNo'     => $newRegNo,
            'firstName' => $firstName,
            'lastName'  => $lastName,
            'dob'       => $this->formatDate($dob),
            'sex'       => $gender,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Upsert a tagNo into the tagNo table.
     * Returns the tagNo id.
     */
    private function upsertTagNo(string $tagNo): int
    {
        if ($tagNo === '') {
            return -1;
        }

        $stmt = $this->db->prepare("SELECT id FROM tagNo WHERE tagNo = :tagNo");
        $stmt->execute(['tagNo' => $tagNo]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            return (int)$existing['id'];
        }

        $this->db->prepare("INSERT INTO tagNo (tagNo) VALUES (:tagNo)")->execute(['tagNo' => $tagNo]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Upsert an email record and link it to member.
     */
    private function upsertEmail(int $memberId, string $email): void
    {
        if ($email === '') {
            return;
        }

        // Check if email already exists and get its id
        $stmt = $this->db->prepare("SELECT id FROM email WHERE emailAddress = :email");
        $stmt->execute(['email' => $email]);
        $existingEmail = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingEmail) {
            $emailId = (int)$existingEmail['id'];
        } else {
            $this->db->prepare("INSERT INTO email (emailAddress, contact) VALUES (:email, 1)")
                ->execute(['email' => $email]);
            $emailId = (int)$this->db->lastInsertId();
        }

        // Link to member if not already linked
        $this->db->prepare("UPDATE member SET email_id = :emailId WHERE id = :mid AND (email_id IS NULL OR email_id = 0)")
            ->execute(['emailId' => $emailId, 'mid' => $memberId]);
    }

    /**
     * Upsert a phone record and link it to member.
     */
    private function upsertPhone(int $memberId, string $phone): void
    {
        if ($phone === '') {
            return;
        }

        // Check if phone already exists
        $stmt = $this->db->prepare("SELECT id FROM phone WHERE number = :phone");
        $stmt->execute(['phone' => $phone]);
        $existingPhone = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingPhone) {
            $phoneId = (int)$existingPhone['id'];
        } else {
            $this->db->prepare("INSERT INTO phone (number, usage, member_id) VALUES (:phone, 'mobile', :mid)")
                ->execute(['phone' => $phone, 'mid' => $memberId]);
            $phoneId = (int)$this->db->lastInsertId();
        }

        $this->db->prepare("UPDATE member SET phone_id = :phoneId WHERE id = :mid AND (phone_id IS NULL OR phone_id = 0)")
            ->execute(['phoneId' => $phoneId, 'mid' => $memberId]);
    }

    /**
     * Upsert an eventEntry record (member_id + event_id primary key).
     *
     * Fields set: tagNo_id, paid, able=false, handicap=null, createDate, lastModDate
     * Fields left null for Step 3: expectedPace, expectedTime, stdDevTime, daysSince, lastWin, method
     * Fields left null: startPosition (set in Step 5)
     */
    private function upsertEventEntry(int $memberId, int $eventId, ?string $tagNo, bool $paid): void
    {
        // Check if eventEntry already exists for this member + event
        $check = $this->db->prepare(
            "SELECT id FROM eventEntry WHERE member_id = :mid AND event_id = :eid"
        );
        $check->execute(['mid' => $memberId, 'eid' => $eventId]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        $tagNoId = ($tagNo !== null && $tagNo !== '') ? $this->upsertTagNo($tagNo) : null;
        $registrationTime = date('Y-m-d H:i:s');

        if ($existing) {
            // Update only the fields that make sense to refresh
            $update = $this->db->prepare("
                UPDATE eventEntry SET
                    tagNo_id = :tagNoId,
                    paid = :paid,
                    lastModDate = NOW()
                WHERE member_id = :mid AND event_id = :eid
            ");
            $update->execute([
                'tagNoId' => $tagNoId,
                'paid'    => $paid ? 1 : 0,
                'mid'     => $memberId,
                'eid'     => $eventId,
            ]);
        } else {
            $insert = $this->db->prepare("
                INSERT INTO eventEntry
                    (event_id, member_id, tagNo_id, paid, able, handicap, startPosition,
                     expectedPace, expectedTime, stdDevTime, daysSince, lastWin, method,
                     createDate, lastModDate)
                VALUES
                    (:eid, :mid, :tagNoId, :paid, 0, NULL, NULL, NULL, NULL, NULL, -1, -1, NULL, :createDate, NOW())
            ");
            $insert->execute([
                'eid'         => $eventId,
                'mid'         => $memberId,
                'tagNoId'      => $tagNoId,
                'paid'        => $paid ? 1 : 0,
                'createDate'  => $registrationTime,
            ]);
        }
    }

    /**
     * Parse "paid" from eventfee field: any non-empty value > 0 = paid.
     */
    private function parsePaid(string $eventfee): bool
    {
        $fee = trim($eventfee);
        return $fee !== '' && floatval($fee) > 0;
    }

    /**
     * Format a DOB string to Y-m-d for MySQL.
     */
    private function formatDate(string $dob): string
    {
        foreach (['Y-m-d', 'd/m/Y', 'm/d/Y'] as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, trim($dob));
            if ($dt !== false) {
                // Reject absurd DOBs (e.g. future dates or very old)
                if ($dt <= new \DateTime('2050-01-01') && $dt >= new \DateTime('1930-01-01')) {
                    return $dt->format('Y-m-d');
                }
            }
        }
        // Fallback: return as-is and let MySQL reject it
        return $dob;
    }
}
