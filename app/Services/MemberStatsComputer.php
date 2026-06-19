<?php
declare(strict_types=1);
namespace App\Services;
use PDO;

require_once __DIR__ . '/../../src/legacy/linearRegression.php';
require_once __DIR__ . '/../../src/legacy/RaceTimeNormalizer.php';

use myTest\utility\linearRegression as LegacyLinearRegression;
use myTest\compute\RaceTimeNormalizer;

/**
 * Computes pace statistics for a member based on historical eventResults.
 *
 * @param PDO $db
 * @param object|null $logger
 * @param array $config  outlier_threshold, std_distance
 */
class MemberStatsComputer
{
    private $db;
    /** @var object|null */
    private $logger;
    private array $config;

    public function __construct($db, $logger = null, array $config = [])
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->config = array_merge([
            'outlier_threshold'  => 1.3,
            'std_distance'       => 5.0,
            'distance_window'    => 2.5,  // km
            'min_history_for_lsf' => 3,
            'min_history_for_mlr' => 3,
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
     * Collect historical eventResult records for a member within the distance window.
     *
     * @param int $memberId
     * @param int $eventId  Event we're computing stats for (to exclude it from history)
     * @param float $targetDistance  km
     * @param int $x  Number of records to collect
     * @return array[]  Rows: {eventDate, distance, actual, pace, venue}
     */
    public function collectHistory(int $memberId, int $eventId, float $targetDistance, int $x, bool $anyDistance = false): array
    {
        if ($anyDistance) {
            $lower = 0.0;
            $upper = 9999.0;
        } else {
            $lower = max(0.5, $targetDistance - $this->config['distance_window']);
            $upper = $targetDistance + $this->config['distance_window'];
        }

        // actual = er.time (HH:MM:SS string, the runner's finish time)
        // pace = er.pace (seconds per km, pre-computed)
        $sql = "
            SELECT
                e.eventDate,
                e.distance,
                er.time as actual,
                er.pace,
                v.name as venue
            FROM eventResult er
            JOIN event e ON er.event_id = e.id
            LEFT JOIN venue v ON e.venue_id = v.id
            WHERE er.member_id = :mid
              AND er.event_id != :eventId
              AND e.distance BETWEEN :lower AND :upper
            ORDER BY e.eventDate DESC
            LIMIT :x
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue('mid', $memberId, \PDO::PARAM_INT);
        $stmt->bindValue('eventId', $eventId, \PDO::PARAM_INT);
        $stmt->bindValue('lower', $lower);
        $stmt->bindValue('upper', $upper);
        $stmt->bindValue('x', $x, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Compute all pace statistics from an array of event history records.
     *
     * @param array[] $history  [{eventDate, distance, actual, pace, venue}]
     * @param float $targetDistance  km
     * @return array{
     *     fastestPace: int,
     *     avgPace: int,
     *     lsfPace: int,
     *     mlrPace: int,
     *     stdDev: int,
     *     expectedPace: string,
     *     expectedPaceSec: int,
     *     expectedTime: string,
     *     expectedTimeSec: int,
     *     stdDevTime: string,
     *     stdDevTimeSec: int,
     *     outlierRemoved: bool,
     *     method: string,
     *     historyUsed: int
     * }
     */
    public function computeStats(array $history, float $targetDistance, ?\DateTime $eventDate = null): array
    {
        $stdDist = $this->config['std_distance'];

        // Parse into records with pace in seconds/km
        $records = [];
        foreach ($history as $ev) {
            $paceSec = $this->parsePace($ev['pace'] ?? '');
            if ($paceSec === null) {
                continue;
            }
            $records[] = [
                'eventDate' => new \DateTime($ev['eventDate']),
                'distance' => (float)$ev['distance'],
                'actual'   => $ev['actual'] ?? '',
                'paceSec'  => $paceSec,
                'venue'    => $ev['venue'] ?? '',
            ];
        }

        if (empty($records)) {
            return $this->nullResult();
        }

        // Extract pace array
        $rawPaces = array_column($records, 'paceSec');
        $n = count($rawPaces);

        // Mean and stdDev on raw paces
        $mean = array_sum($rawPaces) / $n;
        $variance = array_sum(array_map(fn($p) => pow($p - $mean, 2), $rawPaces)) / $n;
        $stdDevRaw = sqrt($variance);

        // Outlier removal: pace > (mean + threshold × stdDev) — biased to slow
        $threshold = $this->config['outlier_threshold'];
        $filtered = $rawPaces;
        $outlierRemoved = false;
        if ($n > 3) {
            $filtered = [];
            foreach ($rawPaces as $p) {
                $zScore = ($p - $mean) / ($stdDevRaw > 0 ? $stdDevRaw : 1);
                if ($zScore > $threshold) {
                    $outlierRemoved = true;
                    continue;
                }
                $filtered[] = $p;
            }
        }

        $fn = count($filtered);
        if ($fn === 0) {
            return $this->nullResult();
        }

        // Recompute mean/stdDev on filtered
        $meanF = array_sum($filtered) / $fn;
        $varianceF = array_sum(array_map(fn($p) => pow($p - $meanF, 2), $filtered)) / $fn;
        $stdDev = (int)round(sqrt($varianceF));

        $fastestPace = (int)min($filtered);
        $avgPace     = (int)round($meanF);

        // LSF pace
        $lsfResult = $this->computeLsf($records, $filtered, $targetDistance, $stdDist, $eventDate);
        $lsfPace = $lsfResult['pace'];
        $lsfSlope = $lsfResult['slope'];

        // MLR pace (Phpml) — falls back to LSF on error
        $mlrPace = $this->computeMlr($records, $filtered, $targetDistance);

        // Choose primary method: LSF if meaningful slope, else average
        $primaryPace = abs($lsfSlope) > 0.001 ? $lsfPace : $avgPace;
        $method     = abs($lsfSlope) > 0.001 ? 'lsf' : 'ave';

        $expectedPaceSec  = $primaryPace;
        $expectedTimeSec   = (int)round($expectedPaceSec * $targetDistance);
        $stdDevTimeSec     = (int)round($stdDev * $targetDistance);

        return [
            'fastestPace'      => $fastestPace,
            'avgPace'          => $avgPace,
            'lsfPace'          => $lsfPace,
            'mlrPace'          => $mlrPace,
            'stdDev'           => $stdDev,
            'expectedPace'      => $this->secToTime($expectedPaceSec),
            'expectedPaceSec'  => $expectedPaceSec,
            'expectedTime'      => $this->secToTime($expectedTimeSec),
            'expectedTimeSec'  => $expectedTimeSec,
            'stdDevTime'        => $this->secToTime($stdDevTimeSec),
            'stdDevTimeSec'    => $stdDevTimeSec,
            'outlierRemoved'   => $outlierRemoved,
            'method'           => $method,
            'historyUsed'      => $fn,
        ];
    }

    /**
     * LSF pace using windowed linear regression on normalised paces.
     * Sorts records by date, builds (days_offset, pace) pairs, extrapolates.
     */
    private function computeLsf(array $records, array $filtered, float $targetDistance, float $stdDist, ?\DateTime $eventDate = null): array
    {
        if (count($records) < 2) {
            $avg = count($filtered) > 0 ? (int)round(array_sum($filtered) / count($filtered)) : 300;
            return ['pace' => $avg, 'slope' => 0.0];
        }

        usort($records, fn($a, $b) => $a['eventDate'] <=> $b['eventDate']);

        $windowSize = max(2, count($records));
        $lr = new LegacyLinearRegression($windowSize);

        $firstDate = $records[0]['eventDate'];
        $lastDate  = end($records)['eventDate'];

        foreach ($records as $rec) {
            // Use raw pace vs. date; LSF captures fitness trend over time at the
            // distances the runner actually raced. Normalising paces across wildly
            // different distances (1.5km vs 8km) makes the regression unstable.
            $days = (int)$firstDate->diff($rec['eventDate'])->days;
            $lr->add($days, (float)$rec['paceSec']);
        }

        $result = $lr->linear_regression();
        $slope     = (float)($result['m'] ?? 0.0);
        $intercept = (float)($result['b'] ?? 0.0);

        if (abs($slope) < 0.001) {
            $avg = count($filtered) > 0 ? (int)round(array_sum($filtered) / count($filtered)) : 300;
            return ['pace' => $avg, 'slope' => 0.0];
        }

        // Extrapolate to event date (or last history date if no event date given)
        $extrapolateTo = $eventDate ?? $lastDate;
        $daysAtEnd = (int)$firstDate->diff($extrapolateTo)->days;
        $pace = (int)round($slope * $daysAtEnd + $intercept);

        // Sanity check: if extrapolated pace is way outside observed range, fall back to average
        $minObserved = min(array_column($records, 'paceSec'));
        $maxObserved = max(array_column($records, 'paceSec'));
        $margin = 0.25; // allow 25% outside observed range
        if ($pace < $minObserved * (1 - $margin) || $pace > $maxObserved * (1 + $margin)) {
            $avg = count($filtered) > 0 ? (int)round(array_sum($filtered) / count($filtered)) : 300;
            return ['pace' => $avg, 'slope' => 0.0];
        }

        return ['pace' => $pace, 'slope' => $slope];
    }

    /**
     * MLR pace using Phpml LeastSquares regression.
     * Falls back to LSF pace on singular matrix or insufficient data.
     */
    private function computeMlr(array $records, array $filtered, float $targetDistance): int
    {
        if (count($records) < 3) {
            return count($filtered) > 0 ? (int)round(array_sum($filtered) / count($filtered)) : 300;
        }

        // MLR needs variation in distance; identical distances produce a singular matrix
        $uniqueDistances = array_unique(array_map(fn($r) => (float)$r['distance'], $records));
        if (count($uniqueDistances) < 2) {
            return count($filtered) > 0 ? (int)round(array_sum($filtered) / count($filtered)) : 300;
        }

        // Build sample/target arrays for Phpml
        // samples: [[distance1], [distance2], ...]
        // targets: [paceSec1, paceSec2, ...]
        $sampleData = [];
        $targetData = [];
        foreach ($records as $rec) {
            $sampleData[] = [(float)$rec['distance']];
            $targetData[] = (float)$rec['paceSec'];
        }

        try {
            $regression = new \Phpml\Regression\LeastSquares();
            $regression->train($sampleData, $targetData);
            $predicted = $regression->predict([[$targetDistance]]);
            $predictedPace = is_array($predicted) ? (int)round($predicted[0]) : (int)round($predicted);
            return max(120, $predictedPace); // sanity floor
        } catch (\Throwable $e) {
            $this->logger()->warning('MLR regression failed: ' . $e->getMessage());
            return count($filtered) > 0 ? (int)round(array_sum($filtered) / count($filtered)) : 300;
        }
    }

    private function nullResult(): array
    {
        return [
            'fastestPace'     => 0,
            'avgPace'         => 0,
            'lsfPace'         => 0,
            'mlrPace'         => 0,
            'stdDev'          => 0,
            'expectedPace'     => '00:00:00',
            'expectedPaceSec' => 0,
            'expectedTime'    => '00:00:00',
            'expectedTimeSec' => 0,
            'stdDevTime'      => '00:00:00',
            'stdDevTimeSec'  => 0,
            'outlierRemoved'  => false,
            'method'          => 'ave',
            'historyUsed'      => 0,
        ];
    }

    /** Parse pace string (H:MM:SS or H:MM:SS.sss) → sec/km int */
    private function parsePace(string $pace): ?int
    {
        $pace = trim($pace);
        if ($pace === '' || $pace === '00:00:00') {
            return null;
        }
        if (preg_match('#^(\d+):(\d\d):(\d\d)(?:\.\d+)?#', $pace, $m)) {
            $sec = (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)round((float)$m[3]); return $sec;
        }
        return null;
    }

    /** Convert seconds → HH:MM:SS string */
    private function secToTime(int $sec): string
    {
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        $s = $sec % 60;
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }
}
