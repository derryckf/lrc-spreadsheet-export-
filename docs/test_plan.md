# Test Plan — LRC Spreadsheet Export / Handicapping System

> **Date**: 2026-05-26
> **Prerequisites**: `functional_spec.md`, `implementation.md`
> **Test framework**: PHPUnit

---

## 1. Test Structure

```
tests/
├── Unit/
│   ├── Services/
│   │   ├── WebscorerParserTest.php
│   │   ├── IdentityResolverTest.php
│   │   ├── MemberCreatorTest.php
│   │   ├── MemberStatsComputerTest.php
│   │   ├── LastWinCalculatorTest.php
│   │   ├── SpreadsheetExporterTest.php
│   │   └── HandicapImporterTest.php
│   └── Utils/
│       └── LinearRegressionTest.php
├── Feature/
│   ├── Commands/
│   │   ├── WebscorerParseCommandTest.php
│   │   ├── WebscorerResolveCommandTest.php
│   │   ├── HandicapperProcessCommandTest.php
│   │   └── HandicapperImportCommandTest.php
│   └── Integration/
│       ├── FullWorkflowTest.php        # webscorer:parse → import
│       └── ReRunWorkflowTest.php       # Re-process same event
└── Fixtures/
    ├── webscorer_sample.txt
    ├── webscorer_identity_manifest.csv
    ├── event_members.json
    └── completed_spreadsheet.xlsx
```

---

## 2. Unit Test Specifications

### 2.1 WebscorerParserTest

**Class under test**: `WebscorerParser`

**Test cases**:

| # | Description | Input | Expected |
|---|-------------|-------|----------|
| 1 | Parse valid webscorer TXT | Sample `output_file` from legacy | CSV created with correct snake_case headers |
| 2 | Header normalisation applied | TXT with `Bib`, `First name`, `Last name` | CSV has `tagNo`, `firstName`, `lastName` |
| 3 | Cleaner rules applied | Name variants (e.g. `Sue,Kerr`) | Cleaned to canonical form |
| 4 | File not found | Invalid path | Exception thrown |
| 5 | Empty file | Empty TXT | Exception or empty CSV with headers only |
| 6 | Streaming output | Valid TXT | Logger receives info lines per processed row |

**Fixtures needed**: `tests/Fixtures/webscorer_sample.txt` — copy from legacy `output_file` (use one of the larger ones, e.g. `187277output_file` or `188717output_file`).

**Mock dependencies**: None (service reads file + calls shell script).

---

### 2.2 IdentityResolverTest

**Class under test**: `IdentityResolver`

**Test cases**:

| # | Description | Setup | Input | Expected |
|---|-------------|-------|-------|----------|
| 1 | Direct match | Seed member: John Smith DOB 1990-05-15 | CSV row: John Smith 1990-05-15 | member_id assigned, match_type=direct, confidence=1.0 |
| 2 | DOB ±1 month | Seed member: John Smith DOB 1990-05-15 | CSV row: John Smith 1990-05-28 | member_id assigned, match_type=fuzzy.dob_close, confidence=0.85 |
| 3 | DOB ±1 year | Seed member: John Smith DOB 1990-05-15 | CSV row: John Smith 1991-03-10 | member_id assigned, match_type=fuzzy.dob_year, confidence=0.70 |
| 4 | Known alias | Seed member: Tim Smith DOB 1990-05-15 | CSV row: Timothy Smith 1990-07-20 | member_id assigned, match_type=alias, confidence=0.65 |
| 5 | Support signal — tagNo | Member 123: last eventResult tagNo=1042 | CSV: Tim Smith tagNo=1042 | +0.15 to confidence |
| 6 | Support signal — email | Member 123: email=test@example.com | CSV: Tim Smith email=test@example.com | +0.10 to confidence |
| 7 | No match | No members in DB | CSV row: New Person | member_id=`tmp_<uuid>`, match_type=new |
| 8 | FirstName exact required | Seed: John Smith | CSV: Johnny Smith | Not tier 1-3 (Johnny ≠ John) |
| 9 | LastName exact required | Seed: John Smith | CSV: John Smyth | Not tier 1-3 |
| 10 | Multiple candidates | Two members: John Smith DOB 1990-05-15 AND John Smith DOB 1992-08-20 | CSV: John Smith 1990-05-15 | Picks exact DOB match (tier 1) not fuzzy |

**Setup**: Each test seeds the `member` table (and `email`, `tagNo` as needed) using a test database connection. Tests use a dedicated test DB or transactions.

**Mock dependencies**: `PDO` (test DB), `LoggerInterface` (mock).

---

### 2.3 LastWinCalculatorTest

**Class under test**: `LastWinCalculator`

**Test cases**:

| # | Description | Setup | Input | Expected |
|---|-------------|-------|-------|----------|
| 1 | No history | No eventResults for member | member_id=123, eventDate=Sat | lastWin=-1, runsSinceLastWin = total events |
| 2 | Won last event | eventResult: linePosition=1, eventDate=2 weeks ago | member_id=123, eventDate=Sat | lastWin=0 (won this season, 0 races since) |
| 3 | Won 3 races ago | eventResults at events A(win), B, C, D | eventDate=D, lastWin=A | runsSinceLastWin=3 |
| 4 | Short course win (fastest for rank) | Division 2 events: member's time fastest for rank S | eventDate=last short course win | lastWinDate updated |
| 5 | First place beats short course win | Both exist, firstPlace newer | compare dates | Uses newer of two |
| 6 | Short course win only | No overall wins, short course win exists | — | lastWinDate = short course win date |

**Setup**: Seed `event`, `eventResult` tables with known dates, `linePosition`, `rank` values.

**Note**: `lastFirstPlace()` uses `linePosition=1`. `lastShortCourseWin()` uses: for the member's rank (S/J/K), find fastest time in that event — if it's this member → counts as win. Both rules are implemented from `member.php` lines 231-307.

---

### 2.4 MemberStatsComputerTest

**Class under test**: `MemberStatsComputer`

**Test cases**:

| # | Description | Input | Expected |
|---|-------------|-------|----------|
| 1 | fastestPace | paces: [320, 345, 330] | 320 |
| 2 | avgPace | paces: [300, 360, 300] | 320 |
| 3 | stdDev | paces: [300, 360, 300] | stdDev ≈ 28.3 |
| 4 | lsf — sufficient data | 6+ events with trend | regression slope > 0 → extrapolated pace |
| 5 | lsf — insufficient data | < 3 events or flat trend (slope≈0) | falls back to avgPace |
| 6 | mlr — normal | 3+ events | predicts from distance |
| 7 | mlr — singular matrix | < 3 events | falls back to lsfPace |
| 8 | outlier removal — slower paces | paces with one very slow (>1.3σ) | outlier removed, avg recomputed |
| 9 | outlier removal — not enough data | 3 data points, one slow | no removal (need >3 points) |
| 10 | expectedTime calculation | avgPace=300, distance=5 | 1500 seconds |
| 11 | normalise=true | events at different distances | normalises to 5km stdDist via `RaceTimeNormalizer` |
| 12 | normalise=false | events at different distances | uses raw paces within distance window |

**Fixtures**: `tests/Fixtures/event_members.json` — array of member history records `{eventDate, distance, pace, actual}`.

**Mock dependencies**: `LoggerInterface` (mock).

---

### 2.5 SpreadsheetExporterTest

**Class under test**: `SpreadsheetExporter`

**Test cases**:

| # | Description | Input | Expected |
|---|-------------|-------|----------|
| 1 | Single member block — correct cell layout | 1 member, 8 history events | Row 1: regNo, firstName, lastName, age in consecutive cols; Row 2: header; Row 3-10: event data |
| 2 | Multiple members — blocks consecutive | 5 members | 5 blocks, no gaps, one after another |
| 3 | Stats row — correct values | Known member with computed stats | fastestPace, avgPace, lsfPace, mlrPace, stdDev all present |
| 4 | expectedPace cell — editable, pre-populated | Pre-computed value 5:30 | Cell contains 330 (seconds), formatted as time |
| 5 | expectedTime formula | expectedPace=330, distance=5 | Formula `=330*5` or cell with expectedTime |
| 6 | SORT formula applied | Multiple members with different expectedTimes | Rows ordered by expectedTime DESC |
| 7 | Division sheets — separate | Members in div1 and div2 | Sheet1=Long Course, Sheet2=Short Course |
| 8 | Missing history — null handling | Member with < x events | Null rows or empty cells, no error |
| 9 | Output file saved | Valid event with members | File exists at expected path, valid xlsx |
| 10 | Format xlsx | `--format=xlsx` | .xlsx extension |

**Setup**: Seed `eventEntry` for known event with computed stats. Use PhpSpreadsheet to read back the output file and assert structure.

**Fixtures needed**: `tests/Fixtures/completed_spreadsheet.xlsx` — for import tests.

---

### 2.6 HandicapImporterTest

**Class under test**: `HandicapImporter`

**Test cases**:

| # | Description | Setup | Input | Expected |
|---|-------------|-------|-------|----------|
| 1 | Basic import — expectedPace changed | eventEntry: expectedPace=330 | Spreadsheet: cell=360 | eventEntry.expectedPace updated to 360 |
| 2 | Handicap computed — longest first | Members with expectedPace: 300, 360, 420 (slower expected first) | All edited | slowest=420s, handicap for fastest=0, middle=120s, slowest=0 |
| 3 | startPosition assigned | Same as above | All edited | 1, 2, 3 in order of expectedTime DESC |
| 4 | expectedTime recalculated | expectedPace=360, distance=5 | — | expectedTime = 1800 |
| 5 | Unchanged spreadsheet | Spreadsheet = original exports | Import again | No changes to eventEntry |
| 6 | Audit log written | Valid import | — | `import_audit.json` created with before/after |
| 7 | Invalid expectedPace (null) | Cell blank | Import | Skip row, log warning |
| 8 | Member not found (regNo unknown) | regNo not in member table | — | Skip row, log warning |
| 9 | Multiple imports — last wins | First import: pace=330; second import: pace=350 | Import twice | eventEntry shows 350 |

**Setup**: Seed `eventEntry` with base data. Use PhpSpreadsheet to modify xlsx, save to temp, then pass to importer.

**Mock dependencies**: `PDO`, `LoggerInterface`.

---

## 3. Feature Tests

### 3.1 WebscorerParseCommandTest

**Command**: `webscorer:parse {file} {--dry-run}`

| # | Description | Expected |
|---|-------------|----------|
| 1 | Valid file — success | CSV manifest created, exit code 0 |
| 2 | File not found | Error message, exit code 1 |
| 3 | `--dry-run` — no file created | CSV not written, exit code 0 |
| 4 | Output path returned | Command prints output path to stdout |
| 5 | Streaming output | Progress lines visible in test output |

---

### 3.2 WebscorerResolveCommandTest

**Command**: `webscorer:resolve {eventId} {csv}`

| # | Description | Expected |
|---|-------------|----------|
| 1 | Valid manifest — members created | `member` table has new rows |
| 2 | Existing member — not duplicated | `member` count unchanged for known members |
| 3 | eventEntry records created | `eventEntry` rows exist for event |
| 4 | TagNo conflict logged | `tagno_conflicts.json` created if conflicts |
| 5 | `--dry-run` — no DB writes | Tables unchanged after run |
| 6 | Invalid eventId | Error message, exit code 1 |

---

### 3.3 HandicapperProcessCommandTest

**Command**: `handicapper:process {eventId} {--x=8}`

| # | Description | Expected |
|---|-------------|----------|
| 1 | Valid event — stats computed | `eventEntry` has expectedPace, daysSince, lastWin for all |
| 2 | `--x` parameter respected | `x=3` → only 3 history rows used |
| 3 | JSON files created per member | `storage/app/handicapping/{eventId}/members/*.json` |
| 4 | Re-run — values update | eventEntry values change on second run |
| 5 | Member with no history | daysSince=-1, all pace stats null, graceful handling |
| 6 | `--dry-run` — no DB writes | eventEntry unchanged |

---

### 3.4 FullWorkflowTest

**Sequence**: parse → resolve → process → export → import

| # | Description | Expected |
|---|-------------|----------|
| 1 | End-to-end — all steps succeed | Final eventEntry has handicap + startPosition |
| 2 | Output files exist at each step | identity CSV, member JSONs, export XLSX all present |
| 3 | Second run of process — idempotent | No duplicate eventEntry, values updated |
| 4 | import edits visible in DB | After import, eventEntry matches spreadsheet |

---

## 4. Integration Test — Re-run Workflow

**Purpose**: Verify that Steps 3 and 4 can be re-run when `eventResult` data is corrected.

**Sequence**:
1. Run full workflow (Steps 1-5)
2. Simulate `eventResult` correction (update one member's pace in DB)
3. Re-run Step 3: stats should update
4. Re-run Step 4: export should reflect new stats
5. Re-run Step 5: handicap should reflect corrections

**Assertions**:
- `eventEntry.expectedPace` changes after re-run
- Spreadsheet `expectedPace` cell changes
- `handicap` recomputed correctly

---

## 5. Test Data Management

### 5.1 Test Database

- Use a dedicated test database (`lrc_test`) with seed data
- Tests run in transactions (`beginTransaction` / `rollBack`) where possible
- For integration tests that must see real DB state, use the `lacsite_deploy` backup schema but with isolated event IDs (e.g. event_id = 99999 + test number)

### 5.2 Seed Data Conventions

| Test data | Location | Notes |
|-----------|----------|-------|
| Webscorer TXT samples | `tests/Fixtures/webscorer/` | Copy from `wizards/output_file` entries |
| Member seed data | `tests/Fixtures/members.json` | Known names/DOBs for match testing |
| Event seed data | `tests/Fixtures/events.json` | Event dates/distances/divisions for testing |

### 5.3 Isolation

- Unit tests: use mocks for PDO, test with in-memory SQLite or transaction rollback
- Integration tests: use test DB with fixed event IDs at upper end of range

---

## 6. Acceptance Criteria

| Step | Criterion | Verification |
|------|-----------|--------------|
| 1 | Webscorer TXT parsed → CSV with correct headers | Diff against expected CSV |
| 1 | Identity matches are correct (direct, fuzzy, new) | Assert member_id assignments against known truth |
| 2 | New members created with correct data | SELECT from DB, compare to input |
| 2 | No duplicate members | COUNT(*) after run vs expected |
| 2 | eventEntry records created | SELECT count(*) where event_id=X |
| 3 | `expectedPace` populated for all able entrants | SELECT where expectedPace IS NOT NULL |
| 3 | `daysSince` matches last eventResult date diff | Assert against seeded dates |
| 3 | `lastWin` matches seeded win history | Assert against seeded eventResult |
| 4 | XLSX opens without error in Excel/Google Sheets | Manual + automated open-and-parse test |
| 4 | Blocks intact after SORT | Sort by expectedTime DESC, verify blocks preserved |
| 5 | Handicap values computed correctly | Assert: longest expectedTime = scratch (0), others > 0 |
| 5 | startPosition sequential | Assert: startPosition = 1, 2, 3... |
| ALL | CLI commands return 0 on success, 1 on error | Assert exit codes |
| ALL | Re-run produces same result (idempotent) | Run twice, assert DB state identical |

---

*Test execution: `php artisan test` or `./vendor/bin/phpunit`*