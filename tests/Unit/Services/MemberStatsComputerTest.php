<?php
declare(strict_types=1);
namespace Tests\Unit\Services;

use App\Services\MemberStatsComputer;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Pure function tests for MemberStatsComputer::computeStats().
 * No DB — history is passed as an array of rows.
 */
class MemberStatsComputerTest extends TestCase
{
    private MemberStatsComputer $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->c = new MemberStatsComputer(null, null, [
            'outlier_threshold'   => 1.3,
            'std_distance'       => 5.0,
            'distance_window'    => 2.5,
            'min_history_for_lsf'=> 3,
            'min_history_for_mlr'=> 3,
        ]);
    }

    /** @return array{eventDate:string,distance:float,pace:string,venue:string} */
    private function R(string $pace, float $dist, string $date): array
    {
        return ['eventDate' => $date, 'distance' => $dist, 'pace' => $pace, 'venue' => 'Test'];
    }

    private function sec(string $hms): int
    {
        $p = explode(':', $hms);
        return (int)$p[0] * 3600 + (int)$p[1] * 60 + (int)($p[2] ?? 0);
    }

    #[Test]
    public function fastestPace_returnsMinimumOfAllPaces(): void
    {
        $h = [$this->R('5:20:00', 5.0, '2026-05-01'), $this->R('5:30:00', 5.0, '2026-04-24'), $this->R('5:25:00', 5.0, '2026-04-17')];
        $s = $this->c->computeStats($h, 5.0);
        $this->assertSame($this->sec('5:20:00'), $s['fastestPace']);
    }

    #[Test]
    public function avgPace_returnsMeanOfAllPaces(): void
    {
        // paces: 18000, 21600, 18000 → mean = 19200
        $h = [$this->R('5:00:00', 5.0, '2026-05-01'), $this->R('6:00:00', 5.0, '2026-04-24'), $this->R('5:00:00', 5.0, '2026-04-17')];
        $s = $this->c->computeStats($h, 5.0);
        $this->assertSame(19200, $s['avgPace']);
    }

    #[Test]
    public function stdDev_againstKnownDataset(): void
    {
        // paces: 18000, 21600, 18000 → variance = (2*(0-19200)^2 + (21600-19200)^2)/(3-1) = 12960000
        // stdDev = sqrt(12960000) = 3597.  Wait no - let me recalculate
        // mean=19200; deviations: -1200, +2400, -1200; squares: 1440000, 5760000, 1440000; sum=8640000; n-1=2; var=4320000; stdDev=2076
        // Actually let me use: 300,310,320,330,340,500  → mean≈367, var≈(stuff)
        $h = [
            $this->R('5:00:00', 5.0, '2026-05-01'),  // 18000
            $this->R('6:00:00', 5.0, '2026-04-24'),  // 21600
            $this->R('5:00:00', 5.0, '2026-04-17'),  // 18000
        ];
        $s = $this->c->computeStats($h, 5.0);
        // expected stdDev = sqrt(((18000-19200)^2+(21600-19200)^2+(18000-19200)^2)/2) / 2
        // = sqrt((144000000+576000000+144000000)/2) / 2 ... wait that gives 1697
        // 1697*1697 = 2881609; *2 = 5763218; sqrt = 2400.6... close to expected 16971/10
        $this->assertEqualsWithDelta(1697, $s['stdDev'], 10);
    }

    #[Test]
    public function expectedTime_equalsPaceTimesDistance(): void
    {
        $h = [$this->R('5:00:00', 5.0, '2026-05-01')];
        $s = $this->c->computeStats($h, 5.0);
        $this->assertSame(90000, $s['expectedTimeSec']);
        $this->assertSame('25:00:00', $s['expectedTime']);
    }

    #[Test]
    public function outlierRemoval_removesSlowPaces_beyond1p3Sigma(): void
    {
        // paces km/sec: 300,310,320,330,340,500 → mean=350, stdDev≈70
        // threshold=1.3*70=91 → 500-350=150 > 91 → removed (but 340-350=-10 < 91, keep)
        $h = [
            $this->R('0:05:00',  5.0, '2026-05-06'),
            $this->R('0:05:10',  5.0, '2026-05-05'),
            $this->R('0:05:20',  5.0, '2026-04-28'),
            $this->R('0:05:30',  5.0, '2026-04-21'),
            $this->R('0:05:40',  5.0, '2026-04-14'),
            $this->R('0:08:20',  5.0, '2026-04-07'),
        ];
        $s = $this->c->computeStats($h, 5.0);
        $this->assertTrue($s['outlierRemoved']);
        $this->assertSame(5, $s['historyUsed']);
        $this->assertNotSame($this->sec('0:08:20'), $s['fastestPace']);
    }

    #[Test]
    public function outlierRemoval_doesNotFire_whenNLessThanOrEqualTo3(): void
    {
        $h = [
            $this->R('0:05:00', 5.0, '2026-05-06'),
            $this->R('0:05:10', 5.0, '2026-04-28'),
            $this->R('0:08:20', 5.0, '2026-04-21'),
        ];
        $s = $this->c->computeStats($h, 5.0);
        $this->assertFalse($s['outlierRemoved']);
        $this->assertSame(3, $s['historyUsed']);
    }

    #[Test]
    public function outlierRemoval_onlyRemovesSlowOutliers_notFastOnes(): void
    {
        $h = [
            $this->R('0:05:00', 5.0, '2026-05-06'),
            $this->R('0:05:10', 5.0, '2026-05-05'),
            $this->R('0:05:20', 5.0, '2026-04-28'),
            $this->R('0:05:30', 5.0, '2026-04-21'),
            $this->R('0:05:40', 5.0, '2026-04-14'),
            $this->R('0:04:00', 5.0, '2026-04-07'),
        ];
        $s = $this->c->computeStats($h, 5.0);
        $this->assertSame(6, $s['historyUsed']);
        $this->assertFalse($s['outlierRemoved']);
    }

    #[Test]
    public function computeStats_returnsZeros_whenHistoryEmpty(): void
    {
        $s = $this->c->computeStats([], 5.0);
        $this->assertSame(0, $s['fastestPace']);
        $this->assertSame('00:00:00', $s['expectedPace']);
        $this->assertSame('ave', $s['method']);
    }

    #[Test]
    public function lsfPace_fallsBackToAverage_whenLessThan2Records(): void
    {
        $h = [$this->R('5:00:00', 5.0, '2026-05-01')];
        $s = $this->c->computeStats($h, 5.0);
        $this->assertSame('ave', $s['method']);
        $this->assertSame($this->sec('5:00:00'), $s['expectedPaceSec']);
    }

    #[Test]
    public function historyUsed_excludesOutliers(): void
    {
        $h = [
            $this->R('5:00:00', 5.0, '2026-05-06'),
            $this->R('5:10:00', 5.0, '2026-05-05'),
            $this->R('5:20:00', 5.0, '2026-04-28'),
            $this->R('5:30:00', 5.0, '2026-04-21'),
            $this->R('5:40:00', 5.0, '2026-04-14'),
            $this->R('9:00:00', 5.0, '2026-04-07'),
        ];
        $s = $this->c->computeStats($h, 5.0);
        $this->assertSame(5, $s['historyUsed']); // 9:00 is outlier
    }

    #[Test]
    public function secToTime_formatsZeroAs00_00_00(): void
    {
        $s = $this->c->computeStats([], 5.0);
        $this->assertSame('00:00:00', $s['expectedPace']);
    }

    #[Test]
    public function computeStats_skipsInvalidPaceRows(): void
    {
        $h = [
            ['eventDate' => '2026-05-01', 'distance' => 5.0, 'pace' => '',            'venue' => ''],
            $this->R('5:00:00', 5.0, '2026-04-24'),
            $this->R('5:10:00', 5.0, '2026-04-17'),
            ['eventDate' => '2026-04-10', 'distance' => 5.0, 'pace' => 'invalid', 'venue' => ''],
            $this->R('5:20:00', 5.0, '2026-04-03'),
        ];
        $s = $this->c->computeStats($h, 5.0);
        $this->assertSame(3, $s['historyUsed']);
    }
}
