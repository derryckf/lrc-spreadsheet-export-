<?php
declare(strict_types=1);
namespace App\Services;

use PDO;

/**
 * Injects season-pass holders from everyEvent into eventEntry.
 *
 * For a given event (which belongs to a specific division), finds all
 * everyEvent members for the current season whose division matches,
 * and creates eventEntry records for any who don't already have one.
 *
 * Run AFTER webscorer:resolve, BEFORE handicapper:process.
 *
 * Idempotent: safe to run multiple times — skips members already in eventEntry.
 */
class SeasonPassInjector
{
    private PDO $db;
    /** @var object|null */
    private $logger;

    public function __construct(PDO $db, $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    private function log(string $level, string $msg): void
    {
        if ($this->logger) {
            $this->logger->log($level, $msg);
        }
    }

    /**
     * Inject season-pass holders into a single event.
     *
     * @param int      $eventId  Target event ID
     * @param int|null $season   Season year (default: current year)
     * @return array{injected:int, skipped:int, total:int}
     */
    public function inject(int $eventId, ?int $season = null): array
    {
        $season ??= (int) date('Y');

        // ── Look up event ──────────────────────────────────────────────
        $evStmt = $this->db->prepare(
            'SELECT id, eventDate, division, distance FROM event WHERE id = ?'
        );
        $evStmt->execute([$eventId]);
        $event = $evStmt->fetch(PDO::FETCH_ASSOC);

        if (!$event) {
            throw new \RuntimeException("Event not found: {$eventId}");
        }

        $division = (int) $event['division'];
        $this->log('info', sprintf(
            'Event %d: div=%d, %skm, %s — injecting season %d pass holders',
            $eventId, $division, $event['distance'], $event['eventDate'], $season
        ));

        // ── Find season-pass holders for this division ────────────────
        $passStmt = $this->db->prepare(<<<'SQL'
            SELECT ee.member_id, ee.tagNo_id, ee.rank, ee.paid,
                   m.firstName, m.lastName, m.regNo
            FROM everyEvent ee
            JOIN member m ON ee.member_id = m.id
            WHERE ee.season = ?
              AND ee.division = ?
            ORDER BY m.lastName, m.firstName
        SQL);
        $passStmt->execute([$season, $division]);
        $passHolders = $passStmt->fetchAll(PDO::FETCH_ASSOC);

        $this->log('info', sprintf('Found %d season-pass holders for division %d', count($passHolders), $division));

        $stats = ['injected' => 0, 'skipped' => 0, 'total' => count($passHolders)];

        // ── Check + insert loop ────────────────────────────────────────
        // Cross-event check: skip if member is already in ANY event on the same
        // race day (they may have registered for a different distance via Webscorer).
        $checkStmt = $this->db->prepare(<<<'SQL'
            SELECT 1 FROM eventEntry ee2
            JOIN event e2 ON ee2.event_id = e2.id
            WHERE ee2.member_id = ?
              AND e2.eventDate = ?
            LIMIT 1
        SQL);
        $insertStmt = $this->db->prepare(<<<'SQL'
            INSERT INTO eventEntry
                (event_id, member_id, tagNo_id, paid, able, handicap, startPosition,
                 expectedPace, expectedTime, stdDevTime, daysSince, lastWin, method,
                 createDate, lastModDate)
            VALUES
                (?, ?, ?, ?, 0, NULL, NULL, NULL, NULL, NULL, -1, -1, NULL, NOW(), NOW())
        SQL);

        $eventDate = $event['eventDate'];

        foreach ($passHolders as $ph) {
            $memberId = (int) $ph['member_id'];

            // Skip if already registered for ANY event on this race day
            $checkStmt->execute([$memberId, $eventDate]);
            if ($checkStmt->fetchColumn()) {
                $this->log('debug', "  Skip {$ph['firstName']} {$ph['lastName']} (already registered for this race day)");
                $stats['skipped']++;
                continue;
            }

            $insertStmt->execute([
                $eventId,
                $memberId,
                $ph['tagNo_id'] ?: null,
                $ph['paid'] ?? 1,
            ]);

            $this->log('info', "  Injected {$ph['firstName']} {$ph['lastName']} (member {$memberId})");
            $stats['injected']++;
        }

        $this->log('info', sprintf(
            'Done: %d injected, %d skipped (already registered)',
            $stats['injected'], $stats['skipped']
        ));

        return $stats;
    }
}
