<?php
declare(strict_types=1);
namespace App\Services;

use PDO;

/**
 * Orchestrates per-member processing for an event.
 *
 * For each eventEntry (where able=true):
 *   1. daysSince — LastWinCalculator
 *   2. runsSinceLastWin — LastWinCalculator
 *   3. Collect history events (±2.5km window, or any distance if < x found)
 *   4. Compute pace stats — MemberStatsComputer
 *   5. Write JSON working file per member
 *   6. UPDATE eventEntry with computed fields
 *
 * Usage:
 *   $proc = new MemberProcessor($pdo, $logger, $config);
 *   $proc->process($eventId, $x);
 */
class MemberProcessor
{
    private PDO $db;
    /** @var object|null */
    private $logger;
    private array $config;

    private LastWinCalculator $winCalc;
    private MemberStatsComputer $statsCalc;

    public function __construct(PDO $db, $logger = null, array $config = [])
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->config = array_merge([
            'history_rows_default' => 8,
            'storage_path' => 'handicapping',
            'distance_window' => 2.5,
            'std_distance' => 5.0,
            'outlier_threshold' => 1.3,
        ], $config);

        $this->winCalc = new LastWinCalculator($db, $logger);
        $this->statsCalc = new MemberStatsComputer($db, $logger, $this->config);
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
     * Process all able entries for an event.
     *
     * @param int $eventId
     * @param int $x  Number of historical events to collect
     * @return array{processed:int, skipped:int}
     */
    public function process(int $eventId, int $x): array
    {
        $this->ensureStorageDir($eventId);

        // Load event info
        $event = $this->loadEvent($eventId);
        if (!$event) {
            throw new \RuntimeException("Event not found: {$eventId}");
        }
        $eventDate = new \DateTime($event['eventDate']);
        $targetDistance = (float)$event['distance'];

        $this->logger->info("Processing event {$eventId} ({$event['eventDate']}, {$targetDistance}km) for {$x} history events");

        // Load all able entries
        $entries = $this->loadEntries($eventId);
        $stats = ['processed' => 0, 'skipped' => 0];

        foreach ($entries as $entry) {
            $memberId = (int)$entry['member_id'];
            $memberLabel = "{$entry['firstName']} {$entry['lastName']}";

            $this->logger->info("{$memberLabel} (mem={$memberId}):");

            // Days since last event
            $daysSince = $this->winCalc->daysSinceLastEvent($memberId, $eventDate);
            $this->logger->info("  daysSince={$daysSince}");

            // Runs since last win
            $runsSinceLastWin = $this->winCalc->runsSinceLastWin($memberId, $eventDate);
            $this->logger->info("  lastWin={$runsSinceLastWin}");

            // Collect history: first at similar distance
            $history = $this->statsCalc->collectHistory($memberId, $eventId, $targetDistance, $x);
            $this->logger->info("  history at similar distance: " . count($history) . " records");

            // If not enough records, try expanding to any distance — only replace if results found
            if (count($history) < $x && count($history) > 0) {
                $expanded = $this->statsCalc->collectHistory($memberId, $eventId, 999, $x); // very wide window = any distance
                $this->logger->info("  history expanded (any dist): " . count($expanded) . " records");
                if (!empty($expanded)) {
                    $history = $expanded;
                }
            }

            if (empty($history)) {
                $this->logger->warning("  No eventResult history found for member {$memberId}");
            }

// Compute stats
            $paceStats = $this->statsCalc->computeStats($history, $targetDistance);
            $this->logger->info("  fastestPace={$paceStats['fastestPace']} avgPace={$paceStats['avgPace']} lsfPace={$paceStats['lsfPace']} mlrPace={$paceStats['mlrPace']} method={$paceStats['method']}");

            // Write member JSON working file
            $this->writeMemberJson($eventId, $entry, $paceStats, $history, $daysSince, $runsSinceLastWin, $eventDate);

            // Update eventEntry in DB
            $this->updateEventEntry($entry['id'], $paceStats, $daysSince, $runsSinceLastWin);

            $stats['processed']++;
        }

        $this->logger->info("Processing complete — {$stats['processed']} members processed, {$stats['skipped']} skipped");
        return $stats;
    }

    /**
     * Update eventEntry record with computed values.
     */
    private function updateEventEntry(int $entryId, array $stats, int $daysSince, int $runsSinceLastWin): void
    {
        $sql = "
            UPDATE eventEntry SET
                daysSince = :daysSince,
                lastWin = :runsSinceLastWin,
                expectedPace = :expectedPace,
                expectedTime = :expectedTime,
                stdDevTime = :stdDevTime,
                method = :method,
                lastModDate = NOW()
            WHERE id = :id
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id'           => $entryId,
            'daysSince'     => $daysSince,
            'runsSinceLastWin' => $runsSinceLastWin,
            'expectedPace' => $stats['expectedPace'] ?? '00:00:00',
            'expectedTime' => $stats['expectedTime'] ?? '00:00:00',
            'stdDevTime'   => $stats['stdDevTime'] ?? '00:00:00',
            'method'       => $stats['method'] ?? 'ave',
        ]);
    }

    /**
     * Load event by ID.
     */
    private function loadEvent(int $eventId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM event WHERE id = ?");
        $stmt->execute([$eventId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Load all able entries for an event with member data.
     */
    private function loadEntries(int $eventId): array
    {
        $sql = "
            SELECT ee.id, ee.member_id, ee.expectedPace, ee.expectedTime,
                   m.regNo, m.firstName, m.lastName
            FROM eventEntry ee
            JOIN member m ON ee.member_id = m.id
            WHERE ee.event_id = :eventId
              -- Process all entries regardless of able (CLI has no web UI to set able=true)
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['eventId' => $eventId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Write per-member JSON working file.
     */
    private function writeMemberJson(int $eventId, array $entry, array $stats, array $history, int $daysSince, int $runsSinceLastWin, \DateTime $eventDate): void
    {
        $safeName = preg_replace('/[^a-zA-Z0-9]/', '_', $entry['firstName'] . '_' . $entry['lastName']);
        $filename = "{$entry['member_id']}_{$safeName}.json";
        $path = $this->getMemberDir($eventId) . '/' . $filename;

        $data = [
            'member_id'   => (int)$entry['member_id'],
            'regNo'      => $entry['regNo'] ?? null,
            'firstName'  => $entry['firstName'],
            'lastName'   => $entry['lastName'],
            'eventId'    => $eventId,
            'eventDate'  => $eventDate->format('Y-m-d'),
            'history'    => array_map(fn($h) => [
                'eventDate' => $h['eventDate'] instanceof \DateTime ? $h['eventDate']->format('Y-m-d') : $h['eventDate'],
                'distance' => $h['distance'],
                'actual'   => $h['actual'],
                'pace'     => $h['pace'] ?? '',
                'venue'    => $h['venue'] ?? '',
            ], $history),
            'stats' => $stats,
            'daysSince'    => $daysSince,
            'lastWin'       => $runsSinceLastWin,
        ];

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
        $this->logger->debug("  JSON written: {$filename}");
    }

    private function getMemberDir(int $eventId): string
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $dir = $base . '/storage/app/' . $this->config['storage_path'] . '/' . $eventId . '/members';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private function ensureStorageDir(int $eventId): void
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $dir = $base . '/storage/app/' . $this->config['storage_path'] . '/' . $eventId . '/members';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
