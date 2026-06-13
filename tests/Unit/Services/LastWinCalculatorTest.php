<?php
declare(strict_types=1);
namespace Tests\Unit\Services;

use App\Services\LastWinCalculator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakePDO;

/**
 * Unit tests for LastWinCalculator.
 * Uses FakePDO from bootstrap (extends \PDO — passes type hints).
 */
class LastWinCalculatorTest extends TestCase
{
    private $db;
    private LastWinCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new FakePDO();
        $this->calc = new LastWinCalculator($this->db);
        $this->db->setFixtureResolver(function (string $sql): string {
            $u = strtoupper($sql);
            if (str_contains($u, 'COUNT(*)') && str_contains($u, 'LASTWINDATE')) {
                return 'runsSinceLastWin_countAfter';
            }
            if (str_contains($u, 'LINEPOSITION = 1') && str_contains($u, 'ORDER BY E.EVENTDATE DESC') && str_contains($u, 'LIMIT 1')) {
                return 'lastOverallWinDate';
            }
            if (str_contains($u, 'ORDER BY E.EVENTDATE DESC') && str_contains($u, 'LIMIT 1')
                && !str_contains($u, 'LINEPOSITION') && !str_contains($u, 'DIVISION')) {
                return 'daysSinceLastEvent';
            }
            if (str_contains($u, 'DIVISION = 2') && str_contains($u, 'ORDER BY E.EVENTDATE DESC')
                && !str_contains($u, 'MIN(')) {
                return 'lastShortCourseWinDate_scEvents';
            }
            if (str_contains($u, 'MIN(ER.TIME)')) {
                return 'lastShortCourseWinDate_fastest';
            }
            return 'unknown';
        });
    }

    // ─── runsSinceLastWin ─────────────────────────────────────────

    #[Test]
    public function runsSinceLastWin_returnsNegativeOne_whenNeverWon(): void
    {
        $this->db->setFixture('runsSinceLastWin_countAfter', 3);
        $this->db->setFixture('lastOverallWinDate', []);
        $this->db->setFixture('lastShortCourseWinDate', []);
        $this->assertSame(-1, $this->calc->runsSinceLastWin(123, new \DateTime('2026-05-23')));
    }

    #[Test]
    public function runsSinceLastWin_returnsZero_whenWonLastEvent(): void
    {
        $this->db->setFixture('runsSinceLastWin_countAfter', 0);
        $this->db->setFixture('lastOverallWinDate', [['eventDate' => '2026-05-16']]);
        $this->db->setFixture('lastShortCourseWinDate', []);
        $this->assertSame(0, $this->calc->runsSinceLastWin(123, new \DateTime('2026-05-23')));
    }

    #[Test]
    public function runsSinceLastWin_returnsCountOfEventsSinceLastWin(): void
    {
        $this->db->setFixture('runsSinceLastWin_countAfter', 2);
        $this->db->setFixture('lastOverallWinDate', [['eventDate' => '2026-05-02']]);
        $this->db->setFixture('lastShortCourseWinDate', []);
        $this->assertSame(2, $this->calc->runsSinceLastWin(123, new \DateTime('2026-05-23')));
    }

    // ─── daysSinceLastEvent ──────────────────────────────────────

    #[Test]
    public function daysSinceLastEvent_returnsNegativeOne_whenNeverRun(): void
    {
        $this->db->setFixture('daysSinceLastEvent', []);
        $this->assertSame(-1, $this->calc->daysSinceLastEvent(123, new \DateTime('2026-05-23')));
    }

    #[Test]
    public function daysSinceLastEvent_returnsCorrectDayDifference(): void
    {
        $this->db->setFixture('daysSinceLastEvent', [['eventDate' => '2026-05-16']]);
        $this->assertSame(7, $this->calc->daysSinceLastEvent(123, new \DateTime('2026-05-23')));
    }

    // ─── lastWinDate (public API) ───────────────────────────────

    #[Test]
    public function lastWinDate_returnsNull_whenNoWinsAtAll(): void
    {
        $this->db->setFixture('lastOverallWinDate', []);
        $this->db->setFixture('lastShortCourseWinDate', []);
        $this->assertNull($this->calc->lastWinDate(123, new \DateTime('2026-05-23')));
    }

    #[Test]
    public function lastWinDate_returnsOverallWinDate_whenOnlyOverallWin(): void
    {
        $this->db->setFixture('lastOverallWinDate', [['eventDate' => '2026-05-09']]);
        $this->db->setFixture('lastShortCourseWinDate', []);
        $r = $this->calc->lastWinDate(123, new \DateTime('2026-05-23'));
        $this->assertSame('2026-05-09', $r->format('Y-m-d'));
    }

    #[Test]
    public function lastWinDate_returnsNull_whenOnlySCWinAndMemberNeverFastestForRank(): void
    {
        // lastShortCourseWinDate returns null when member was never fastest for rank.
        // lastOverallWinDate also null. Result: overall null → lastWinDate returns null.
        $this->db->setFixture('lastOverallWinDate', []);
        $this->db->setFixture('lastShortCourseWinDate', []);
        $this->assertNull($this->calc->lastWinDate(123, new \DateTime('2026-05-23')));
    }


    #[Test]
    public function lastWinDate_returnsOverallWin_whenNewer(): void
    {
        $this->db->setFixture('lastOverallWinDate', [['eventDate' => '2026-05-16']]);
        $this->db->setFixture('lastShortCourseWinDate', [['eventDate' => '2026-05-02']]);
        $r = $this->calc->lastWinDate(123, new \DateTime('2026-05-23'));
        $this->assertSame('2026-05-16', $r->format('Y-m-d'));
    }

    // ─── lastOverallWinDate (private — via reflection) ────────────

    #[Test]
    public function lastOverallWinDate_returnsNull_whenNoLinePositionOne(): void
    {
        $this->db->setFixture('lastOverallWinDate', []);
        $ref = new \ReflectionMethod($this->calc, 'lastOverallWinDate');
        $ref->setAccessible(true);
        $result = $ref->invoke($this->calc, 123, new \DateTime('2026-05-23'));
        $this->assertNull($result);
    }

    #[Test]
    public function lastOverallWinDate_returnsDateTime_whenFound(): void
    {
        $this->db->setFixture('lastOverallWinDate', [['eventDate' => '2026-04-18']]);
        $ref = new \ReflectionMethod($this->calc, 'lastOverallWinDate');
        $ref->setAccessible(true);
        $result = $ref->invoke($this->calc, 123, new \DateTime('2026-05-23'));
        $this->assertSame('2026-04-18', $result->format('Y-m-d'));
    }
}
