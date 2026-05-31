<?php
/**
 * Export eventEntry data for handicapper's spreadsheet
 *
 * Usage:
 *   php scripts/export_event_entry.php --event-id=123 --format=csv
 *   php scripts/export_event_entry.php --event-id=123 --format=xlsx
 *   php scripts/export_event_entry.php --list-events
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function listEvents(PDO $db): void
{
    $stmt = $db->query("
        SELECT e.id, e.eventDate, e.distance, e.division, s.name as sponsor
        FROM event e
        LEFT JOIN sponsor s ON e.sponsor_id = s.id
        ORDER BY e.eventDate DESC
        LIMIT 50
    ");

    echo "\nRecent events:\n";
    echo str_repeat('-', 70) . "\n";
    printf("%-5s %-12s %-8s %-4s %s\n", "ID", "Date", "Distance", "Div", "Sponsor");
    echo str_repeat('-', 70) . "\n";

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        printf("%-5s %-12s %-8s %-4s %s\n",
            $row['id'],
            $row['eventDate'],
            $row['distance'],
            $row['division'],
            $row['sponsor'] ?? 'N/A'
        );
    }
    echo "\n";
}

function exportEventEntry(PDO $db, int $eventId, string $format, string $outputDir): void
{
    // Get event info
    $eventStmt = $db->prepare("
        SELECT e.*, s.name as sponsor, v.name as venue
        FROM event e
        LEFT JOIN sponsor s ON e.sponsor_id = s.id
        LEFT JOIN venue v ON e.venue_id = v.id
        WHERE e.id = ?
    ");
    $eventStmt->execute([$eventId]);
    $event = $eventStmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        throw new RuntimeException("Event ID $eventId not found");
    }

    // Get event entries with member data
    $stmt = $db->prepare("
        SELECT
            m.regNo,
            m.firstName,
            m.lastName,
            ee.expectedPace,
            ee.expectedTime,
            ee.stdDevTime,
            ee.method,
            ee.daysSince,
            ee.lastWin,
            ee.handicap,
            ee.startPosition,
            ee.paid,
            ee.able,
            t.tagNo as bibNumber
        FROM eventEntry ee
        JOIN member m ON ee.member_id = m.id
        LEFT JOIN tagNo t ON ee.tag_no_id = t.id
        WHERE ee.event_id = ?
        ORDER BY ee.startPosition ASC, m.lastName ASC
    ");
    $stmt->execute([$eventId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        throw new RuntimeException("No entries found for event ID $eventId");
    }

    // Build filename
    $eventDate = date('Y-m-d', strtotime($event['eventDate']));
    $safeSponsor = preg_replace('/[^a-zA-Z0-9]/', '_', $event['sponsor'] ?? 'Unknown');
    $filename = "{$eventDate}_{$safeSponsor}_event{$eventId}.{$format}";

    if ($format === 'csv') {
        exportCsv($rows, $outputDir . '/' . $filename, $event);
    } elseif ($format === 'xlsx') {
        exportXlsx($rows, $outputDir . '/' . $filename, $event);
    }

    echo "Exported {$filename} with " . count($rows) . " entries\n";
}

function exportCsv(array $rows, string $filepath, array $event): void
{
    $fp = fopen($filepath, 'w');

    // Header row
    $headers = [
        'regNo', 'firstName', 'lastName', 'bibNumber',
        'expectedPace', 'expectedTime', 'stdDevTime',
        'method', 'daysSince', 'lastWin',
        'handicap', 'startPosition',
        'paid', 'able'
    ];
    fputcsv($fp, $headers);

    // Data rows
    foreach ($rows as $row) {
        fputcsv($fp, [
            $row['regNo'],
            $row['firstName'],
            $row['lastName'],
            $row['bibNumber'] ?? '',
            $row['expectedPace'],
            $row['expectedTime'],
            $row['stdDevTime'],
            $row['method'],
            $row['daysSince'],
            $row['lastWin'],
            $row['handicap'] ?? '',
            $row['startPosition'] ?? '',
            $row['paid'] ? 'Y' : 'N',
            $row['able'] ? 'Y' : 'N'
        ]);
    }

    fclose($fp);
}

function exportXlsx(array $rows, string $filepath, array $event): void
{
    // For XLSX, we create a simple XML-based file
    // For full XLSX support, consider using phpspreadsheet library

    $headers = [
        'regNo', 'firstName', 'lastName', 'bibNumber',
        'expectedPace', 'expectedTime', 'stdDevTime',
        'method', 'daysSince', 'lastWin',
        'handicap', 'startPosition',
        'paid', 'able'
    ];

    $xml = '<?xml version="1.0" encoding="UTF-8"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
  xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
<Worksheet ss:Name="EventEntries">
<Table>';

    // Header row
    $xml .= '<Row>';
    foreach ($headers as $h) {
        $xml .= "<Cell><Data ss:Type='String'>$h</Data></Cell>";
    }
    $xml .= '</Row>';

    // Data rows
    foreach ($rows as $row) {
        $xml .= '<Row>';
        $values = [
            $row['regNo'],
            $row['firstName'],
            $row['lastName'],
            $row['bibNumber'] ?? '',
            $row['expectedPace'],
            $row['expectedTime'],
            $row['stdDevTime'],
            $row['method'],
            $row['daysSince'],
            $row['lastWin'],
            $row['handicap'] ?? '',
            $row['startPosition'] ?? '',
            $row['paid'] ? 'Y' : 'N',
            $row['able'] ? 'Y' : 'N'
        ];
        foreach ($values as $v) {
            $xml .= "<Cell><Data ss:Type='String'>" . htmlspecialchars((string)$v) . "</Data></Cell>";
        }
        $xml .= '</Row>';
    }

    $xml .= '</Table></Worksheet></Workbook>';

    file_put_contents($filepath, $xml);
}

// CLI handling
$options = getopt('', ['event-id:', 'format:', 'output:', 'list-events']);

if (isset($options['list-events'])) {
    $db = getDbConnection();
    listEvents($db);
    exit;
}

if (!isset($options['event-id'])) {
    echo "Usage: php scripts/export_event_entry.php --event-id=<id> --format=<csv|xlsx> [--output=<dir>]\n";
    echo "       php scripts/export_event_entry.php --list-events\n";
    exit(1);
}

$eventId = (int) $options['event-id'];
$format = $options['format'] ?? 'csv';
$outputDir = $options['output'] ?? __DIR__;

if (!in_array($format, ['csv', 'xlsx'])) {
    echo "Format must be 'csv' or 'xlsx'\n";
    exit(1);
}

try {
    $db = getDbConnection();
    exportEventEntry($db, $eventId, $format, $outputDir);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}