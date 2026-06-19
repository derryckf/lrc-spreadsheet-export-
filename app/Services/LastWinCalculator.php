<?php
declare(strict_types=1);
namespace App\Services;

use PDO;

/**
 * Calculates runsSinceLastWin and daysSinceLastEvent for a member at a given event.
 *
 * Implements the legacy rules from member.lastWin(), member.lastFirstPlace(),
 * and member.lastShortCourseWin().
 *
 * Usage:
 *   $calc = new LastWinCalculator($pdo, $logger);
 *   $runs = $calc->runsSinceLastWin($memberId, $eventDate);
 *   $days = $calc->daysSinceLastEvent($memberId, $eventDate);
 */
class LastWinCalculator
{
    private $db;
    /** @var object|null */
    private $logger;

    public function __construct($db, $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger;
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
     * Count events since the member's last win (overall or short course).
     *
     * Logic:
     * 1. Find last overall first place eventDate (linePosition = 1)
     * 2. Find last short course win eventDate
     * 3. whichever is newer → lastWinDate
     * 4. runsSinceLastWin = count(eventResult for this member where eventDate > lastWinDate)
     *
     * @param int $memberId
     * @param \DateTime $eventDate  The event we're handicapping FOR
     * @return int  -1 if never won, else count of events since last win
     */
    public function runsSinceLastWin(int $memberId, \DateTime $eventDate): int
    {
        $lastWinDate = $this->lastWinDate($memberId, $eventDate);

        if ($lastWinDate === null) {
            return -1;
        }

        // Count eventResults after the last win date (strictly after, for same event)
        $sql = "
            SELECT COUNT(*) FROM eventResult er
            JOIN event e ON er.event_id = e.id
            WHERE er.member_id = :mid
              AND e.eventDate > :lastWinDate
              AND e.eventDate <= :eventDate
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'mid'        => $memberId,
            'lastWinDate' => $lastWinDate->format('Y-m-d'),
            'eventDate'   => $eventDate->format('Y-m-d'),
        ]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Days since the member's last eventResult event (any result, not just wins).
     *
     * @param int $memberId
     * @param \DateTime $eventDate  The event we're handicapping FOR
     * @return int  -1 if never run before, else days since last event
     */
    public function daysSinceLastEvent(int $memberId, \DateTime $eventDate): int
    {
        $sql = "
            SELECT e.eventDate
            FROM eventResult er
            JOIN event e ON er.event_id = e.id
            WHERE er.member_id = :mid
              AND e.eventDate < :eventDate
            ORDER BY e.eventDate DESC
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'mid'      => $memberId,
            'eventDate' => $eventDate->format('Y-m-d'),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return -1;
        }

        $lastDate = new \DateTime($row['eventDate']);
        $diff = $lastDate->diff($eventDate);
        return (int)$diff->days;
    }

    /**
     * Get the date of the member's most recent win (overall or short course).
     * Returns null if never won.
     */
    public function lastWinDate(int $memberId, \DateTime $beforeDate): ?\DateTime
    {
        $fpDate = $this->lastOverallWinDate($memberId, $beforeDate);
        $scDate = $this->lastShortCourseWinDate($memberId, $beforeDate);

        if ($fpDate === null && $scDate === null) {
            return null;
        }
        if ($fpDate === null) {
            return $scDate;
        }
        if ($scDate === null) {
            return $fpDate;
        }

        // Return whichever is newer
        return $fpDate > $scDate ? $fpDate : $scDate;
    }

    /**
     * Find most recent event where member finished linePosition = 1 (overall first).
     */
    private function lastOverallWinDate(int $memberId, \DateTime $beforeDate): ?\DateTime
    {
        $sql = "
            SELECT e.eventDate
            FROM eventResult er
            JOIN event e ON er.event_id = e.id
            WHERE er.member_id = :mid
              AND er.linePosition = 1
              AND e.eventDate <= :beforeDate
            ORDER BY e.eventDate DESC
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'mid'       => $memberId,
            'beforeDate' => $beforeDate->format('Y-m-d'),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? new \DateTime($row['eventDate']) : null;
    }

    /**
     * Find most recent Division 2 event where this member had the fastest time
     * for their rank (J=Junior, S=Senior, K=Child).
     *
     * Algorithm:
     * 1. Get all eventResult records for this member in Division 2 events
     *    before $beforeDate, with their rank and actual time
     * 2. For each of those events, find the fastest time for the member's rank
     * 3. If this member's time === that fastest time → counts as a short course win
     * 4. Return the most recent such event
     *
     * Note: Uses raw SQL subquery — supported by MySQL 8+, no CTEs needed.
     */
    private function lastShortCourseWinDate(int $memberId, \DateTime $beforeDate): ?\DateTime
    {
        // Single query: find most recent Division 2 event where the member's
        // time equals the fastest time for their rank in that event.
        // TIME() ensures proper time-string comparison.
        $sql = "
            SELECT e.eventDate
            FROM eventResult er
            JOIN event e ON er.event_id = e.id
            WHERE er.member_id = :mid
              AND e.division = 2
              AND e.eventDate <= :beforeDate
              AND er.time = (
                  SELECT MIN(er2.time)
                  FROM eventResult er2
                  WHERE er2.event_id = er.event_id
                    AND er2.rank = er.rank
              )
            ORDER BY e.eventDate DESC
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'mid'       => $memberId,
            'beforeDate' => $beforeDate->format('Y-m-d'),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? new \DateTime($row['eventDate']) : null;
    }

    /**
     * Convert time string HH:MM:SS or H:MM:SS to total seconds.
     * Returns null on parse failure.
     */
    private function timeToSec(?string $time): ?int
    {
        if ($time === null || $time === '') { return null; }
        $time = trim($time);
        if ($time === '' || $time === '00:00:00') { return null; }
        // Match H:MM:SS or HH:MM:SS
        if (preg_match('/^(\d+):([0-5]\d):([0-5]\d)$/', $time, $m)) {
            return (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3];
        }
        // Match H:MM (minutes:seconds)
        if (preg_match('/^(\d+):([0-5]\d)$/', $time, $m)) {
            return (int)$m[1] * 60 + (int)$m[2];
        }
        return null;
    }
}