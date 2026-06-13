# LRC Spreadsheet Export

> Exports event entry data from the LRC MySQL database for use in manual handicapping spreadsheets.

**Purpose**: Provide the new handicapper with a spreadsheet of runner data (from `eventEntry` table) that they can use to calculate and input handicaps.

**Status**: Standalone project, separate from the main LRC handicapping migration work.

---

## Quick Start

### Prerequisites
- PHP 8.1+
- Access to LRC MySQL database (same credentials as lrc-handicapper)

### Export to CSV
```bash
php scripts/export_event_entry.php --event-id=123 --format=csv
```

### Export to XLSX
```bash
php scripts/export_event_entry.php --event-id=123 --format=xlsx
```

### List available events
```bash
php scripts/export_event_entry.php --list-events
```

---

## Project Structure

```
lrc-spreadsheet-export/
├── docs/
│   ├── user-guide.md       # How to use this tool
│   └── schema.md           # Field descriptions for eventEntry
├── scripts/
│   ├── export_event_entry.php   # Main export script
│   └── google_sheet_sync.php   # (TBD) Push to Google Sheets
├── config/
│   └── database.php        # DB connection config
└── tests/
    └── export_test.php    # Basic export test
```

---

## What Gets Exported

The script exports fields from `eventEntry` after pre-handicapping calculations:

| Field | Description |
|-------|-------------|
| `regNo` | Member registration number |
| `firstName` | Runner's first name |
| `lastName` | Runner's last name |
| `expectedPace` | Predicted pace (sec/km) |
| `expectedTime` | Predicted finish time |
| `stdDevTime` | Standard deviation of times |
| `method` | Prediction method used (ave/lsf/mlr/man) |
| `daysSince` | Days since last race |
| `lastWin` | Races since last win |
| `handicap` | Calculated handicap (if available) |
| `startPosition` | Start position (if assigned) |
| `paid` | Paid status |
| `able` | Able to run status |

---

## Documentation

- [User Guide](docs/user-guide.md) — How to run exports, troubleshoot
- [Schema Reference](docs/schema.md) — Field descriptions and meanings

---

*This project is maintained by Simon Frost for Launceston Running Club*
