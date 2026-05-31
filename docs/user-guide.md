# User Guide — LRC Spreadsheet Export

## Overview

This tool exports runner data from the LRC MySQL database into a spreadsheet format (CSV or XLSX) for the handicapper to use in their own calculations.

The exported data contains everything from `eventEntry` after pre-handicapping — names, predicted times, prediction methods, days since last race, etc.

---

## Getting Started

### 1. Check Database Connection

Ensure your `config/database.php` has the correct credentials for the LRC database.

### 2. List Available Events

```bash
php scripts/export_event_entry.php --list-events
```

This shows all events in the database with their IDs and dates.

### 3. Export for a Specific Event

```bash
# Export as CSV
php scripts/export_event_entry.php --event-id=123 --format=csv --output=./exports/

# Export as XLSX
php scripts/export_event_entry.php --event-id=123 --format=xlsx --output=./exports/
```

---

## Using the Export

1. Open the exported file in Excel or Google Sheets
2. The handicapper adds their handicap calculations in a new column
3. Return the completed spreadsheet to the race organiser
4. The race organiser imports the handicaps back into the system

---

## Troubleshooting

### "Connection refused" error
- Check MySQL is running
- Verify credentials in `config/database.php`
- Ensure you're on the correct network/VPN

### "Event not found"
- Use `--list-events` to find the correct event ID
- The event may not exist in the database yet

### Empty export
- Check the event has entries in `eventEntry`
- Verify the event ID is correct

---

## File Output

| Format | Extension | Notes |
|--------|-----------|-------|
| CSV | `.csv` | Universal, works in all spreadsheet apps |
| XLSX | `.xlsx` | Native Excel format |

Output files are saved to the path specified by `--output` (defaults to current directory).

---

## Contact

For issues with this tool, contact Simon Frost.
