<?php
declare(strict_types=1);
namespace Tests\Unit\Services;

use App\Services\HandicapImporter;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakePDO;

/**
 * Unit tests for HandicapImporter.
 * Uses FakePDO from bootstrap (extends \PDO — passes type hints).
 *
 * Tests focus on time utilities and import() return structure.
 * Complex import flow tests require a real DB connection.
 */
class HandicapImporterTest extends TestCase
{
    private $db;
    private HandicapImporter $im;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new FakePDO();
        // Must set resolver BEFORE creating HandicapImporter (which calls loadAllEntries in import)
        $this->db->setFixtureResolver(function (string $sql): string {
            $u = strtoupper($sql);
            if (str_contains($u, 'SELECT M.REGNO')) return 'buildMemberIdMap';
            if (str_contains($u, 'SELECT EE.ID, EE.MEMBER_ID')) return 'loadAllEntries';
            if (str_contains($u, 'SELECT EE.MEMBER_ID, EE.EXPECTEDPACE')) return 'buildOriginalPaceMap';
            return 'rows';
        });
        $this->db->setFixture('buildMemberIdMap', [['regNo' => '12345', 'member_id' => 10, 'entry_id' => 100]]);
        $this->db->setFixture('loadAllEntries', [['id' => 100, 'member_id' => 10, 'event_id' => 99, 'expectedPace' => '5:00', 'expectedTime' => '25:00', 'distance' => 5.0]]);
        $this->db->setFixture('buildOriginalPaceMap', []);
        $this->im = new HandicapImporter($this->db, null, ['storage_path' => 'handicapping']);
    }

    // ─── Time utilities (public API via reflection) ─────────────

    private function call(string $method, ...$args): mixed
    {
        $ref = new \ReflectionMethod($this->im, $method);
        $ref->setAccessible(true);
        return $ref->invoke($this->im, ...$args);
    }

    #[Test]
    public function parseTimeToSeconds_parsesHHMMSS_and_MMSS(): void
    {
        $this->assertSame(3661,  $this->call('parseTimeToSeconds', '1:01:01'));
        $this->assertSame(3600,  $this->call('parseTimeToSeconds', '1:00:00'));
        $this->assertSame(90,    $this->call('parseTimeToSeconds', '1:30'));
        $this->assertSame(330,   $this->call('parseTimeToSeconds', '5:30'));
        $this->assertSame(0,     $this->call('parseTimeToSeconds', ''));
        $this->assertSame(0,     $this->call('parseTimeToSeconds', 'invalid'));
    }

    #[Test]
    public function formatSecondsToHis_formatsCorrectly(): void
    {
        $this->assertSame('1:00:00', $this->call('formatSecondsToHis', 3600));
        $this->assertSame('0:30:00', $this->call('formatSecondsToHis', 1800));
        $this->assertSame('0:05:30', $this->call('formatSecondsToHis', 330));
        $this->assertSame('0:00:00', $this->call('formatSecondsToHis', 0));
        $this->assertSame('1:01:01', $this->call('formatSecondsToHis', 3661));
    }

    #[Test]
    public function computeExpectedTime_paceTimesDistance(): void
    {
        $this->assertSame('0:27:30', $this->call('computeExpectedTime', '0:05:30', 5.0));
        $this->assertSame('1:00:00', $this->call('computeExpectedTime', '1:00:00', 1.0));
        $this->assertSame('0:00:00', $this->call('computeExpectedTime', '0:00:00', 5.0));
    }

    #[Test]
    public function isValidPace_rejectsInvalid(): void
    {
        $this->assertFalse($this->call('isValidPace', ''));
        $this->assertFalse($this->call('isValidPace', '0:00:00'));
        $this->assertFalse($this->call('isValidPace', 'invalid'));
        $this->assertTrue($this->call('isValidPace', '0:05:00'));
        $this->assertTrue($this->call('isValidPace', '1:30:00'));
    }

    // ─── import() — structure tests ─────────────────────────────────

    private function csvPath(array $rows): string
    {
        $p = sys_get_temp_dir() . '/lrc_imp_' . uniqid() . '.csv';
        $fp = fopen($p, 'w');
        foreach ($rows as $row) { fputcsv($fp, $row); }
        fclose($fp);
        return $p;
    }

    #[Test]
    public function import_throwsWhenFileNotFound(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File not found');
        $this->im->import(99, '/nonexistent/path.csv');
    }

    #[Test]
    public function import_returnsResultArray_withUpdatedSkippedErrors(): void
    {
        $this->db->setFixture('buildMemberIdMap', [['regNo' => '12345', 'member_id' => 10, 'entry_id' => 100]]);
        $this->db->setFixture('loadAllEntries', [['id' => 100, 'member_id' => 10, 'event_id' => 99, 'expectedPace' => '5:00', 'expectedTime' => '25:00', 'distance' => 5.0]]);
        $this->db->setFixture('buildOriginalPaceMap', []);

        // File not found throws; valid file with no data rows (just header) returns result with skip
        $csv = $this->csvPath([['regNo', 'firstName', 'lastName', 'age', 'expectedPace', 'method']]);
        $result = $this->im->import(99, $csv);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('updated', $result);
        $this->assertArrayHasKey('skipped', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertIsArray($result['errors']);
        unlink($csv);
    }

    #[Test]
    public function import_returnsZeroUpdated_whenCsvHasNoDataRows(): void
    {
        $this->db->setFixture('buildMemberIdMap', [['regNo' => '12345', 'member_id' => 10, 'entry_id' => 100]]);
        $this->db->setFixture('loadAllEntries', [['id' => 100, 'member_id' => 10, 'event_id' => 99, 'expectedPace' => '5:00', 'expectedTime' => '25:00', 'distance' => 5.0]]);
        $this->db->setFixture('buildOriginalPaceMap', []);

        $csv = $this->csvPath([
            ['regNo', 'firstName', 'lastName', 'age', 'expectedPace', 'method'],
        ]);
        $result = $this->im->import(99, $csv);
        // No data rows → nothing to update
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['skipped']);
        unlink($csv);
    }
}
