<?php
declare(strict_types=1);
namespace Tests\Unit\Services;

use App\Services\SpreadsheetExporter;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakePDO;

/**
 * Unit tests for SpreadsheetExporter.
 * Uses FakePDO with setFixtureResolver.
 *
 * The service loads:
 *   loadEvent          → single event row
 *   loadEntriesByDivision → rows from eventEntry+member+tagNo+event join
 *   resolveMemberId     → per-row member id lookup
 *   loadHistoryForMember → per-row eventResult history
 */
class SpreadsheetExporterTest extends TestCase
{
    private $db;
    private SpreadsheetExporter $ex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new FakePDO();
        $this->ex = new SpreadsheetExporter($this->db, null, [
            'history_rows_default' => 8,
            'storage_path'         => 'handicapping',
        ]);
        $this->db->setFixtureResolver(function (string $sql) use (&$calledKeys) {
            $u = strtoupper($sql);
            // loadEntriesByDivision: FROM eventEntry ee JOIN member m LEFT JOIN tagNo t JOIN event e
            if (str_contains($u, 'SELECT EE.ID') && str_contains($u, 'JOIN EVENT E ')) return 'loadEntriesByDivision';
            // findEventsOnSameDate: WHERE e.eventDate = (SELECT eventDate FROM event WHERE id = :id)
            if (str_contains($u, 'WHERE E.EVENTDATE = (SELECT EVENTDATE')) return 'findEventsOnSameDate';
            // loadEvent: WHERE e.id = :id
            if (str_contains($u, 'WHERE E.ID = :ID')) return 'loadEvent';
            if (str_contains($u, 'SELECT ER.EVENTDATE')) return 'loadHistoryForMember';
            if (str_contains($u, 'SELECT ID FROM MEMBER')) return 'resolveMemberId';
            if (str_contains($u, 'SELECT M.REGNO')) return 'buildMemberIdMap';
            if (str_contains($u, 'SELECT EE.ID, EE.MEMBER_ID')) return 'loadAllEntries';
            if (str_contains($u, 'SELECT EE.MEMBER_ID, EE.EXPECTEDPACE')) return 'buildOriginalPaceMap';
            if (str_contains($u, 'UPDATE EVENTENTRY')) return 'writeEventEntry';
            return 'rows';
        });
    }

    private function calcAge(string $dob, string $eventDate): int
    {
        $ref = new \ReflectionClass($this->ex);
        $m = $ref->getMethod('calcAge');
        $m->setAccessible(true);
        return $m->invoke($this->ex, $dob, $eventDate);
    }

    // ─── calcAge ──────────────────────────────────────────────────

    #[Test]
    public function calcAge_returnsZero_whenDobOrEventDateMissing(): void
    {
        $this->assertSame(0, $this->calcAge('', '2026-05-23'));
        $this->assertSame(0, $this->calcAge('1990-05-15', ''));
        $this->assertSame(0, $this->calcAge('', ''));
    }

    #[Test]
    public function calcAge_returnsCorrectAge_onExactBirthday(): void
    {
        $this->assertSame(36, $this->calcAge('1990-05-15', '2026-05-15'));
    }

    #[Test]
    public function calcAge_returnsCorrectAge_beforeBirthday(): void
    {
        $this->assertSame(35, $this->calcAge('1990-05-15', '2026-05-10'));
    }

    #[Test]
    public function calcAge_returnsCorrectAge_afterBirthday(): void
    {
        $this->assertSame(36, $this->calcAge('1990-05-15', '2026-05-23'));
    }

    #[Test]
    public function calcAge_returnsZero_onInvalidDate(): void
    {
        $this->assertSame(0, $this->calcAge('not-a-date', '2026-05-23'));
    }

    // ─── CSV export ─────────────────────────────────────────────

    /**
     * Fixture row matching loadEntriesByDivision SQL output.
     */
    private function entryRow(int $id, int $regNo, string $fn, string $ln, string $dob, int $div): array
    {
        return [
            'id'    => $id,
            'regNo' => $regNo,
            'firstName' => $fn,
            'lastName'  => $ln,
            'DOB'   => $dob,
            'tagNo'  => '',
            'division' => $div,
            'distance' => 5.0,
            'eventDate' => '2026-05-23',
            'expectedPace' => '00:05:00',
            'expectedTime' => '00:25:00',
            'handicap' => '00:00:00',
            'startPosition' => '',
            'daysSince' => -1,
            'lastWin'   => -1,
            'liftSec'  => 0,
        ];
    }

    #[Test]
    public function exportCsv_createsFileWithCorrectMemberData(): void
    {
        // loadEvent: fetch() accesses $data[0] — must wrap in array
        $this->db->setFixture('loadEvent', [
            ['id' => 99, 'eventDate' => '2026-05-23', 'division' => 1, 'distance' => 5.0, 'safeName' => 'Test'],
        ]);
        $this->db->setFixture('loadEntriesByDivision', [
            $this->entryRow(1, 1042, 'John', 'Smith', '1990-05-15', 1),
        ]);
        $this->db->setFixture('resolveMemberId', [[10]]);
        $this->db->setFixture('loadHistoryForMember', []);

        $result = $this->ex->export(99, 'csv');

        $this->assertFileExists($result);
        $content = file_get_contents($result);
        $this->assertStringContainsString('John', $content);
        $this->assertStringContainsString('Smith', $content);
        $this->assertStringContainsString('1042', $content);
        unlink($result);
    }

    #[Test]
    public function exportCsv_includesShortCourseDivision_whenPresent(): void
    {
        $this->db->setFixture('loadEvent', [
            ['id' => 100, 'eventDate' => '2026-05-23', 'division' => 1, 'distance' => 5.0, 'safeName' => 'Test'],
        ]);
        $this->db->setFixture('loadEntriesByDivision', [
            $this->entryRow(1, 1, 'A', 'B', '1990-01-01', 1),
            $this->entryRow(2, 2, 'C', 'D', '1995-06-15', 2),
        ]);
        $this->db->setFixture('resolveMemberId', [[10], [11]]);
        $this->db->setFixture('loadHistoryForMember', []);

        $result = $this->ex->export(100, 'csv');

        $content = file_get_contents($result);
        $this->assertStringContainsString('Long Course', $content);
        $this->assertStringContainsString('Short Course', $content);
        $this->assertStringNotContainsString('Junior', $content);
        unlink($result);
    }

    // ─── XLSX export ─────────────────────────────────────────────

    #[Test]
    public function exportXlsx_throwsRuntimeException_whenEventNotFound(): void
    {
        $this->db->setFixture('loadEvent', null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Event not found');
        $this->ex->export(99999, 'xlsx');
    }

    #[Test]
    public function exportXlsx_throwsInvalidArgumentException_onUnknownFormat(): void
    {
        $this->db->setFixture('loadEvent', [
            'id' => 1, 'eventDate' => '2026-05-23', 'division' => 1,
            'distance' => 5.0, 'safeName' => 'Test',
        ]);
        // loadEntriesByDivision called → needs stub
        $this->db->setFixture('loadEntriesByDivision', []);
        $this->db->setFixture('resolveMemberId', [[1]]);
        $this->db->setFixture('loadHistoryForMember', []);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown format');
        $this->ex->export(1, 'pdf');
    }
}
