# Functional Specification — LRC Spreadsheet Export / Handicapping System

> **Status**: Current
> **Author**: Simon Frost
> **Date**: 2026-05-31
> **Source of truth**: Legacy ATK4 application (`~/Documents/records.launcestonrunningclub.com.au/test/src/`)

---

## 1. Overview

This project replaces the ATK4 web wizard workflow (`webEntryWizard`, `handicapWizard`, `webResultsWizard`) with a CLI-first, Laravel-wrapped tooling set that can also be driven from a web GUI.

The system ingests Webscorer registration TXT files, resolves runner identities against the existing `member` table, populates `eventEntry` records, computes historical pace statistics, produces a formatted XLSX spreadsheet for the handicapper, and reads the completed spreadsheet back into the database.

**Non-negotiable constraint**: The legacy database schema (`lacsite_deploy`) is immutable. No migrations, no schema changes.

---

## 2. Database Schema Reference

### 2.1 Relevant Tables (from `lacsite_deploy`)

| Table | Key Columns | Role |
|-------|-------------|------|
| `member` | `id`, `regNo`, `firstName`, `lastName`, `DOB`, `sex`, `email_id`, `phone_id`, `status`, `paid` | Runner record |
| `email` | `id`, `emailAddress`, `contact` | Contact |
| `phone` | `id`, `number`, `usage` | Mobile contact |
| `tagNo` | `id`, `tagNo` | Bib/chip number registry |
| `event` | `id`, `eventDate`, `division`, `distance`, `venue_id`, `sponsor_id` | Race event |
| `eventEntry` | `id`, `event_id`, `member_id`, `tagNo_id`, `handicap`, `startPosition`, `expectedPace`, `expectedTime`, `stdDevTime`, `paid`, `able`, `daysSince`, `lastWin`, `method`, `createDate`, `lastModDate` | Entry record linking member to event |
| `eventResult` | `id`, `event_id`, `member_id`, `actual` (time), `handicap`, `pace`, `linePosition`, `rank` | Actual race result |
| `venue` | `id`, `name` | Venue name |
| `sponsor` | `id`, `name` | Sponsor name |

### 2.2 Field Type Conventions

- `handicap`, `expectedPace`, `expectedTime`, `stdDevTime` are stored as `HH:MM:SS.sss` strings (MySQL TIME type)
- `actual` and `pace` in `eventResult` stored as `HH:MM:SS` strings
- `DOB` and `eventDate` are MySQL DATE types
- `able` and `paid` are booleans
- `division`: `1`=Long Course, `2`=Short Course, `3`=Junior

---

## 3. Step Definitions

### Step 1 — Webscorer Parse (Identity Resolution)

**Command**: `webscorer:parse {file}`

**Input**: Webscorer tab-delimited TXT file from registrations portal

**Output**: CSV manifest file at `storage/app/handicapping/{eventId}/identity/{eventId}_manifest.csv`

**Process**:

1. **Header normalisation** — rename Webscorer columns to snake_case fields using sed pipeline (same rules as `fixRegHeader.sh`):
   - `Bib` → `tagNo`, `First name` → `firstName`, `Last name` → `lastName`, `Date of birth` → `DOB`, `Email` → `email`, `Gender` → `gender`, `Distance` → `distance`, `Category` → `category`, `Registration time` → `registrationtime`, `Phone #` → `phone`, `Predicted time` → `estimate`, `Event fee` → `eventfee`, etc.

2. **Delimiter conversion** — strip embedded commas, convert tabs to commas

3. **Name and data corrections** — apply `cleaner.sh` / `cleaner_bin.sh` rules:
   - Name alias standardisation (e.g. `Timothy` → `Tim`, `Susan` → `Sue`)
   - Suffix normalisation (`Rogers Snr` → `Rogers-Snr`, `Rogers Jnr` → `Rogers-Jnr`)
   - DOB corrections for known bad data (specific dates corrected)
   - Header fixes (`Chip No` → `Chip_No`, `Suburb or Town` → `Suburb_or_Town`)

4. **Identity matching** — for each CSV row, match against `member` table:

   **Tier 1 — Direct match** (confidence 1.0):
   - `firstName` exact + `lastName` exact + `DOB` exact → assign `member_id`

   **Tier 2 — DOB within ±1 month** (confidence 0.85):
   - `firstName` exact + `lastName` exact + DOB within 30 days

   **Tier 3 — DOB within ±1 year** (confidence 0.70):
   - `firstName` exact + `lastName` exact + DOB within 365 days

   **Tier 4 — Known alias** (confidence 0.65):
   - Alias match on firstName (e.g. `Tim` ↔ `Timothy`) + `lastName` exact + DOB within 1 year

   **Support signals** (stack on Tier 2-4):
   - tagNo matches a past `eventResult.tagNo_id` for this candidate member → +0.15
   - email matches existing `email.emailAddress` for candidate member → +0.10

   **No match** → assign `tmp_<uuid>` as `member_id` placeholder (new member to be created)

5. **TagNo resolution**:
   - Prefer Webscorer-provided tagNo over any other source
   - If Webscorer tagNo conflicts with tagNo history from `eventResult`, flag in manifest

6. **Output CSV columns**:
   ```
   tmp_id, webscorer_tagNo, firstName, lastName, DOB, gender, email, phone,
   distance, category, eventfee, registrationtime,
   webscorer_tagNo_conflict, tagNo_resolved, member_id, match_type, confidence_score,
   notes
   ```

**Identity alias list** (initial seed from `cleaner.sh` patterns):
```php
[
    'Tim'   => ['Timothy', 'Timmy'],
    'Fred'  => ['Freddie', 'Frederick'],
    'Sam'   => ['Samantha', 'Samuel'],
    'Liz'   => ['Elizabeth', 'Lizzy'],
    'Mick'  => ['Michael', 'Mickey'],
    'Rob'   => ['Robert', 'Bob', 'Bobby'],
    'Sue'   => ['Susan', 'Suzanne'],
    'Deb'   => ['Debra', 'Deborah', 'Debbie'],
    'Colin' => ['Collin'],
    'Phil'  => ['Philip'],
    'Steve' => ['Stephen', 'Steven'],
    'Pat'   => ['Patrick', 'Patty'],
    'Jake'  => ['Jacob'],
    'Neil'  => ['Neill', 'Nellie'],
    'Alex'  => ['Alexander', 'Alexandria'],
    'Glen'  => ['Glenn'],
    'Ant'   => ['Anthony', 'Anton'],
    'Rich'  => ['Richard'],
    'Dee'   => ['Diane', 'Diana'],
    'Annie' => ['Anne-Marie'],
    'Daemon'=> ['Damon'],
    'Joseph'=> ['Joe'],
    'William'=> ['Will'],
]
```

**Re-processing**: If a new webscorer TXT is downloaded for the same event, Step 1 can re-run. New entries are added, existing matched members are re-identified.

---

### Step 2 — Member Creation + eventEntry Population

**Command**: `webscorer:resolve {eventId} {manifest.csv}`

**Input**: CSV manifest from Step 1 + confirmed event ID

**Output**: New `member` records created; `eventEntry` records populated for all registrants

**Process** (per member in manifest, member_id loop):

1. **New member creation** — for rows where `member_id` starts with `tmp_`:
   - Insert `member` record (firstName, lastName, DOB, sex, status='prov', paid=false, createDate=now)
   - Insert associated `email` record if email present
   - Insert `phone` record if phone present
   - Assign new `regNo` (next available)
   - Replace `tmp_<uuid>` with real `member_id`

2. **TagNo assignment** — for all entries:
   - Use Webscorer-provided tagNo
   - Insert into `tagNo` table if not already present

3. **eventEntry creation** — for each registrant:
   ```php
   [
       'event_id'    => $eventId,
       'member_id'   => $resolvedMemberId,
       'tagNo_id'    => $tagNoId,
       'paid'        => $eventfee > 0 ? true : false,
       'able'        => false,  // set in Step 3
       'handicap'    => null,
       'startPosition' => null,
       'expectedPace'  => null,
       'expectedTime'  => null,
       'stdDevTime'    => null,
       'daysSince'     => -1,
       'lastWin'       => -1,
       'method'         => null,
       'createDate'    => $registrationTime,
       'lastModDate'   => now,
   ]
   ```
   - Uses `eventEntryLoader`-style upsert: if member_id + event_id already exists, update rather than duplicate

4. **Validation checks** (from `checkEvent.php`):
   - Age-based division correction (member's age at first event of year determines correct division)
   - Provisional members: flag if running without paid membership
   - Flag underage runners

5. **TagNo conflict log** — if Webscorer tagNo differs from last used tagNo in eventResults, log to `storage/app/handicapping/{eventId}/logs/tagno_conflicts.json`

**Repeatability**: Step 2 can re-run if Step 1 is re-run with a new/updated manifest. Existing `member` records are not duplicated. `eventEntry` records are upserted (updated if exist, created if new).

**Error handling**: If a row cannot be processed (missing critical field), log error and continue. Do not exit.

---

### Step 3 — Member Processing (Stats Computation)

**Command**: `handicapper:process {eventId} {--x=8}`

**Input**: `eventEntry` records for the event (from Step 2)

**Output**: `daysSince`, `lastWin`, `expectedPace`, `expectedTime`, `stdDevTime`, `method` populated in `eventEntry`. Working JSON files per member at `storage/app/handicapping/{eventId}/members/`

**Process** (per member in eventEntry):

1. **Days since last run**
   ```
   daysSince = eventDate - last eventResult eventDate for this member (before this event)
   = -1 if never run before
   ```

2. **Runs since last win** (from `member.lastWin()`, `member.lastFirstPlace()`, `member.lastShortCourseWin()`):
   - `lastFirstPlace()`: most recent `eventResult` with `linePosition = 1` for this member
   - `lastShortCourseWin()`: most recent Division 2 eventResult where member had fastest time for their rank (J/S/K), regardless of overall position
   - `lastWin = max(eventDate_of_lastFirstPlace, eventDate_of_lastShortCourseWin)`
   - `runsSinceLastWin = count(eventResults for member where eventDate > lastWinDate)`
   - Special rule: senior/junior running in short course still count short course wins correctly (via `lastShortCourseWin()`)

3. **Historical event data collection** (configurable `x`, default 8):
   - Find last `x` `eventResult` records for this member at similar distance:
     - Distance window: event distance ± 2.5km (from `memberStats.lower/upper`)
     - Ordered by eventDate descending
   - If fewer than `x` found at similar distance, expand to any distance
   - If still no results, output null record

   **Fields collected per historical event**: `eventDate`, `distance`, `actual` (finish time), `pace` (sec/km), `venue.name`, `rank`

4. **Pace computations** (per member, from historical events):

   **fastestPace**: MIN(pace) from selected events

   **avgPace**: mean of pace values

   **lsfPace** (least-squares fit linear regression):
   - Uses `utility/linearRegression` class (existing from legacy)
   - Input: (days_since_first_event, pace) pairs
   - Output: slope + intercept → extrapolated pace for target distance
   - If regression slope ≈ 0 (insufficient data), fall back to avgPace

   **mlrPace** (multi-linear regression via Phpml):
   - Uses `Phpml\Regression\LeastSquares`
   - Input: distance → actual_time mapping
   - Falls back to lsfPace if singular matrix error

   **stdDev**: standard deviation of pace values (distance-adjusted)

   **Outlier removal** (from `memberStats::filterHandicapData`):
   - If more than 3 data points, remove records where pace > 1.3 stdDev from mean (biased toward slower outliers only)
   - Recompute avgPace/stdDev after removal

5. **Expected time calculation**:
   ```
   expectedTime = expectedPace × distance (seconds)
   ```

6. **Working files**: For each member, write JSON to:
   ```
   storage/app/handicapping/{eventId}/members/{regNo}_{firstName}_{lastName}.json
   ```
   Structure:
   ```json
   {
     "member_id": 1234,
     "regNo": 1042,
     "firstName": "John",
     "lastName": "Smith",
     "eventId": 456,
     "history": [
       {"eventDate": "2026-05-23", "distance": 5, "venue": "Tailrace", "pace": 340, "actual": "27:00", "rank": "S"}
     ],
     "stats": {
       "fastestPace": 320,
       "avgPace": 345,
       "lsfPace": 342,
       "mlrPace": 338,
       "stdDev": 12,
       "method": "avg"
     },
     "daysSince": 7,
     "lastWin": 3,
     "expectedPace": 345,
     "expectedTime": "27:30",
     "stdDevTime": "00:57"
   }
   ```

7. **Update eventEntry**: Write `expectedPace`, `expectedTime`, `stdDevTime`, `daysSince`, `lastWin`, `method` back to the `eventEntry` table for each member.

**Repeatability**: Step 3 can re-run if `eventResult` data is corrected. Updates `eventEntry` in place (not delete/recreate). Re-computes all stats from current `eventResult` data.

**Configurable parameter** `--x`: number of historical events to collect (default 8). Also configurable via `config/lrc-handicapping.php`.

---

### Step 4 — Spreadsheet Export

**Command**: `handicapper:export {eventId} {--format=xlsx}`

**Input**: `eventEntry` records with computed stats (from Step 3)

**Output**: Multi-sheet XLSX at `storage/app/handicapping/{eventId}/exports/{eventId}_all-divisions_{venue}_{date}.xlsx`

#### Sheet structure

Each division produces 3 sheets: **Participants** (read-only stats + manualPace row), **Entry** (handicapper works here), and **Start** (sorted output for WebScorer).

Example: Long Course (Division 1, 5km) → sheets: `Participants Long Course`, `Long Course`, `Long Course Start`

---

##### Participants Sheet

Per runner block (8 history rows + stats rows + manualPace row):

```
Row 1:  regNo | firstName | lastName | age |     |      |       |       |
Row 2:  date  | venue     | distance | pace|     |       |       |       |
Rows 3-10: history event data (8 rows)
Row 11: fastestPace | [value] | avgPace | [value] | lsfPace | [value] | mlrPace | [value] | method | [value]
Row 12: stdDev     | [value] | (blank) |         |         |         |        |         |
Row 13: manualPace  | [EDITABLE YELLOW] ← handicapper enters pace when method="man"
```

- Stats rows (11-12) use shared strings for labels, numeric cells for values in [h]:mm:ss format
- `method` cell (I13) is pre-populated with the computed method name from Step 3 (avg/lsf/mlr/fastest)
- `manualPace` cell (I13) is yellow-highlighted, pre-populated with expectedPace from Step 3
- Handicapper sets method="man" to use the manualPace value; otherwise formula selects avg/lsf/mlr/fastest automatically

---

##### Entry Sheet

Layout (3 divisions side by side in one XLSX, one entry sheet per division):

**Row 1 (event header):** `Date | Division | Distance | ID | entrants | useLift`

**Row 2 (event data):** e.g. `23/05/26 | 2 | 2.5 | 1821 | 18 | 0`
- `entrants` (E2): auto-filled with memberCount; handicapper adjusts for actual field size
- `useLift` (F2): toggle, 0=off (no retard), 1=on (apply retard)

**Row 3:** blank spacer

**Row 4 (column headers):** `regNo | tagNo | firstName | lastName | age | sex | daysSince | lastWin | lift | expectedPace | expectedTime | handicap`

**Rows 5+ (data rows, one per runner):**

| Col | Header | Formula / Source |
|-----|--------|-----------------|
| A | regNo | `eventEntry.regNo` |
| B | tagNo | `eventEntry.tagNo` |
| C | firstName | `member.firstName` |
| D | lastName | `member.lastName` |
| E | age | `member.age` (at event date) |
| F | sex | `member.sex` |
| G | daysSince | `eventEntry.daysSince` |
| H | lastWin | `eventEntry.lastWin` |
| I | lift | `=IF($F$2=1,IF(OR(H<0,H>=E$2),0,((E$2-H-1)/E$2)*K*0.05*$B$2),0)` |
| J | expectedPace | 5-method IF formula → Participants block |
| K | expectedTime | `=J{row}*C$2` |
| L | handicap | `=MAX($K$5:$K$22)-K{row}+I{row}` |

**Lift formula (col I):**
- `useLift=0` → `0` (retard disabled)
- `lastWin=-1` (never won) → `0`
- `lastWin >= entrants` → `0` (committee rule)
- `0 ≤ lastWin < entrants` → `((entrants−lastWin−1)/entrants) × expectedTime × 5% × division`

**Handicap formula (col L) — Pursuit race logic:**
- Slowest runner: `MAX(expectedTimes) - MAX(expectedTimes) + lift = 0` → scratch (starts at 0)
- Faster runner: `MAX(expectedTimes) - theirTime + lift` → positive → starts later
- Start List sheet sorts by handicap ascending (smallest/zero first)

**expectedPace formula (col J):**
```
=IF('Participants {div}'!I{fr+10}="avg",  'Participants {div}'!I{fr+8},
 IF('Participants {div}'!I{fr+10}="lsf",   'Participants {div}'!I{fr+9},
 IF('Participants {div}'!I{fr+10}="mlr",   'Participants {div}'!G{fr+10},
 IF('Participants {div}'!I{fr+10}="fastest",'Participants {div}'!G{fr+8},
 IF('Participants {div}'!I{fr+10}="man",   'Participants {div}'!I{fr+11},
                                          'Participants {div}'!I{fr+8})))))
```
Where `fr = firstHistRow` (row of first history event for that runner in the Participants block).

---

##### Start List Sheet

Live-linked to entry sheet via INDEX/MATCH/SMALL. Sorted ascending by handicap (slowest/zero first).

Columns: `First name | Last name | Gender | Distance | Category | Bib | Start time | Handicap`

- `Start time` and `Handicap` (cols G-H): `="+"&TEXT(MROUND(handicap_val, TIME(0,0,10)),"[h]:mm:ss")` → WebScorer `+HH:MM:SS` format, rounded to nearest 10 seconds
- Entry sheet col L (handicap) is referenced by the MATCH/SMALL INDEX formula
- Each row: `=IFERROR(INDEX('EntrySheet'!data_range, MATCH(SMALL('EntrySheet'!L range, k), 'EntrySheet'!L range, 0)), "")`

---

#### Export options

- `--all-divisions`: exports all 3 divisions in one XLSX (default)
- Per-division: sheet ordering is `Participants LC, LC, Participants SC, SC, Participants Jr, Jr, LC Start, SC Start, Jr Start`

---

### Step 5 — Spreadsheet Import

**Command**: `handicapper:import {eventId} {file.xlsx}`

**Input**: Completed spreadsheet from handicapper

**Output**: Updated `eventEntry` records in database: `expectedPace`, `expectedTime`, `handicap`, `startPosition`

**Process**:

1. Read spreadsheet, find all rows where `expectedPace` has been edited
2. For each edited row:
   - Look up `member_id` via `regNo` matching
   - Update `eventEntry.expectedPace = edited_value`
   - Recalculate `eventEntry.expectedTime = expectedPace × distance`
3. **Compute handicaps**:
   - `expectedTime DESC` sort (slowest first)
   - `handicap = longestExpectedTime - thisExpectedTime` (seconds, HH:MM:SS)
   - `startPosition = sequential position in sorted order (1 = first off scratch)`
4. Write `handicap`, `startPosition`, `lastModDate = now` to each `eventEntry` row

**Validation**:
- Check `expectedPace` is not null
- Check `expectedPace` is a positive number
- Flag if `expectedPace` has changed from original Step 3 value (for audit log)

**Repeatability**: Can be run multiple times — last run wins. Supports mid-week corrections.

---

## 4. Service Architecture

All services are plain PHP classes with no framework inheritance. They receive dependencies via constructor.

```
app/Services/
├── WebscorerParser.php
│   └── parse(string $txtFile): string $csvPath
│       └── Uses: bin/generateCsvFile.sh (sed pipelines)
│
├── IdentityResolver.php
│   └── resolve(string $csvPath, PDO $db): string $manifestCsvPath
│       └── Uses: MemberMatcher, AliasList, TagNoHistory
│
├── MemberCreator.php
│   └── createMembers(string $manifestCsvPath, PDO $db, LoggerInterface $logger): void
│
├── MemberProcessor.php
│   └── process(int $eventId, PDO $db, int $x, LoggerInterface $logger): void
│       └── Uses: MemberStatsComputer, LastWinCalculator, DaysSinceCalculator
│
├── MemberStatsComputer.php
│   └── computeStats(array $historicalEvents, float $targetDistance): array
│       └── Computes: fastestPace, avgPace, lsfPace, mlrPace, stdDev, outlier removal
│
├── LastWinCalculator.php
│   └── runsSinceLastWin(PDO $db, int $memberId, DateTime $eventDate): int
│       └── Uses: member.lastFirstPlace(), member.lastShortCourseWin() rules
│
├── SpreadsheetExporter.php
│   └── export(int $eventId, string $format, int $x): string $filePath
│       └── Uses: PhpSpreadsheet
│       └── Produces: Participants sheets + Entry sheets + Start list sheets
│
└── HandicapImporter.php
    └── import(string $xlsxPath, PDO $db, LoggerInterface $logger): ImportResult
```

**DI pattern**:
```php
public function __construct(
    PDO $db,
    ?LoggerInterface $logger = null,
    array $config = []
)
```

All services: no `echo`, no `exit`, no `ob_start`. Return structured data or throw exceptions.

---

## 5. CLI Commands

| Command | Description |
|---------|-------------|
| `webscorer:parse {file}` | TXT → cleaned CSV manifest |
| `webscorer:resolve {eventId} {csv}` | Identity matching + member/eventEntry creation |
| `handicapper:process {eventId} {--x=8}` | Compute stats, update eventEntry |
| `handicapper:export {eventId} {--format=xlsx}` | Produce XLSX spreadsheet (all divisions) |
| `handicapper:import {eventId} {file}` | Read completed spreadsheet → update DB |

All commands support `--dry-run` for testing without DB writes.

---

## 6. File Storage Structure

```
storage/app/handicapping/
└── {eventId}/
    ├── identity/
    │   └── {eventId}_manifest.csv
    ├── members/
    │   └── {regNo}_{firstName}_{lastName}.json   # one per member
    ├── logs/
    │   └── tagno_conflicts.json
    │   └── import_audit.json
    └── exports/
        └── {eventId}_all-divisions_{venue}_{date}.xlsx
```

All working files are JSON or CSV (no MySQL temp tables) for debuggability.

---

## 7. Timeline Constraints

- Race entries close: **7:00pm Thursday** before Saturday race
- Division 3 (Junior) first event: **10:00am Saturday**
- Handicapper window: **7:30pm Thursday → 7:30pm Friday**
- Multiple re-runs of Step 3/4 expected within that window if `eventResult` corrections are needed

---

## 8. Out of Scope

- Webscorer API integration (manual TXT download only)
- Google Sheets sync
- Email/notification automation
- Standalone race result posting (webResultsWizard replacement)
- Changes to legacy database schema

---

## 1. Overview

This project replaces the ATK4 web wizard workflow (`webEntryWizard`, `handicapWizard`, `webResultsWizard`) with a CLI-first, Laravel-wrapped tooling set that can also be driven from a web GUI.

The system ingests Webscorer registration TXT files, resolves runner identities against the existing `member` table, populates `eventEntry` records, computes historical pace statistics, produces a formatted XLSX spreadsheet for the handicapper, and reads the completed spreadsheet back into the database.

**Non-negotiable constraint**: The legacy database schema (`lacsite_deploy`) is immutable. No migrations, no schema changes.

---

## 2. Database Schema Reference

### 2.1 Relevant Tables (from `lacsite_deploy`)

| Table | Key Columns | Role |
|-------|-------------|------|
| `member` | `id`, `regNo`, `firstName`, `lastName`, `DOB`, `sex`, `email_id`, `phone_id`, `status`, `paid` | Runner record |
| `email` | `id`, `emailAddress`, `contact` | Contact |
| `phone` | `id`, `number`, `usage` | Mobile contact |
| `tagNo` | `id`, `tagNo` | Bib/chip number registry |
| `event` | `id`, `eventDate`, `division`, `distance`, `venue_id`, `sponsor_id` | Race event |
| `eventEntry` | `id`, `event_id`, `member_id`, `tagNo_id`, `handicap`, `startPosition`, `expectedPace`, `expectedTime`, `stdDevTime`, `paid`, `able`, `daysSince`, `lastWin`, `method`, `createDate`, `lastModDate` | Entry record linking member to event |
| `eventResult` | `id`, `event_id`, `member_id`, `actual` (time), `handicap`, `pace`, `linePosition`, `rank` | Actual race result |
| `venue` | `id`, `name` | Venue name |
| `sponsor` | `id`, `name` | Sponsor name |

### 2.2 Field Type Conventions

- `handicap`, `expectedPace`, `expectedTime`, `stdDevTime` are stored as `HH:MM:SS.sss` strings (MySQL TIME type)
- `actual` and `pace` in `eventResult` stored as `HH:MM:SS` strings
- `DOB` and `eventDate` are MySQL DATE types
- `able` and `paid` are booleans
- `division`: `1`=Long Course, `2`=Short Course, `3`=Junior

---

## 3. Step Definitions

### Step 1 — Webscorer Parse (Identity Resolution)

**Command**: `webscorer:parse {file}`

**Input**: Webscorer tab-delimited TXT file from registrations portal

**Output**: CSV manifest file at `storage/app/handicapping/{eventId}/identity/{eventId}_manifest.csv`

**Process**:

1. **Header normalisation** — rename Webscorer columns to snake_case fields using sed pipeline (same rules as `fixRegHeader.sh`):
   - `Bib` → `tagNo`, `First name` → `firstName`, `Last name` → `lastName`, `Date of birth` → `DOB`, `Email` → `email`, `Gender` → `gender`, `Distance` → `distance`, `Category` → `category`, `Registration time` → `registrationtime`, `Phone #` → `phone`, `Predicted time` → `estimate`, `Event fee` → `eventfee`, etc.

2. **Delimiter conversion** — strip embedded commas, convert tabs to commas

3. **Name and data corrections** — apply `cleaner.sh` / `cleaner_bin.sh` rules:
   - Name alias standardisation (e.g. `Timothy` → `Tim`, `Susan` → `Sue`)
   - Suffix normalisation (`Rogers Snr` → `Rogers-Snr`, `Rogers Jnr` → `Rogers-Jnr`)
   - DOB corrections for known bad data (specific dates corrected)
   - Header fixes (`Chip No` → `Chip_No`, `Suburb or Town` → `Suburb_or_Town`)

4. **Identity matching** — for each CSV row, match against `member` table:

   **Tier 1 — Direct match** (confidence 1.0):
   - `firstName` exact + `lastName` exact + `DOB` exact → assign `member_id`

   **Tier 2 — DOB within ±1 month** (confidence 0.85):
   - `firstName` exact + `lastName` exact + DOB within 30 days

   **Tier 3 — DOB within ±1 year** (confidence 0.70):
   - `firstName` exact + `lastName` exact + DOB within 365 days

   **Tier 4 — Known alias** (confidence 0.65):
   - Alias match on firstName (e.g. `Tim` ↔ `Timothy`) + `lastName` exact + DOB within 1 year

   **Support signals** (stack on Tier 2-4):
   - tagNo matches a past `eventResult.tagNo_id` for this candidate member → +0.15
   - email matches existing `email.emailAddress` for candidate member → +0.10

   **No match** → assign `tmp_<uuid>` as `member_id` placeholder (new member to be created)

5. **TagNo resolution**:
   - Prefer Webscorer-provided tagNo over any other source
   - If Webscorer tagNo conflicts with tagNo history from `eventResult`, flag in manifest

6. **Output CSV columns**:
   ```
   tmp_id, webscorer_tagNo, firstName, lastName, DOB, gender, email, phone,
   distance, category, eventfee, registrationtime,
   webscorer_tagNo_conflict, tagNo_resolved, member_id, match_type, confidence_score,
   notes
   ```

**Identity alias list** (initial seed from `cleaner.sh` patterns):
```php
[
    'Tim'   => ['Timothy', 'Timmy'],
    'Fred'  => ['Freddie', 'Frederick'],
    'Sam'   => ['Samantha', 'Samuel'],
    'Liz'   => ['Elizabeth', 'Lizzy'],
    'Mick'  => ['Michael', 'Mickey'],
    'Rob'   => ['Robert', 'Bob', 'Bobby'],
    'Sue'   => ['Susan', 'Suzanne'],
    'Deb'   => ['Debra', 'Deborah', 'Debbie'],
    'Colin' => ['Collin'],
    'Phil'  => ['Philip'],
    'Steve' => ['Stephen', 'Steven'],
    'Pat'   => ['Patrick', 'Patty'],
    'Jake'  => ['Jacob'],
    'Neil'  => ['Neill', 'Nellie'],
    'Alex'  => ['Alexander', 'Alexandria'],
    'Glen'  => ['Glenn'],
    'Ant'   => ['Anthony', 'Anton'],
    'Rich'  => ['Richard'],
    'Dee'   => ['Diane', 'Diana'],
    'Annie' => ['Anne-Marie'],
    'Daemon'=> ['Damon'],
    'Joseph'=> ['Joe'],
    'William'=> ['Will'],
]
```

**Re-processing**: If a new webscorer TXT is downloaded for the same event, Step 1 can re-run. New entries are added, existing matched members are re-identified.

---

### Step 2 — Member Creation + eventEntry Population

**Command**: `webscorer:resolve {eventId} {manifest.csv}`

**Input**: CSV manifest from Step 1 + confirmed event ID

**Output**: New `member` records created; `eventEntry` records populated for all registrants

**Process** (per member in manifest, member_id loop):

1. **New member creation** — for rows where `member_id` starts with `tmp_`:
   - Insert `member` record (firstName, lastName, DOB, sex, status='prov', paid=false, createDate=now)
   - Insert associated `email` record if email present
   - Insert `phone` record if phone present
   - Assign new `regNo` (next available)
   - Replace `tmp_<uuid>` with real `member_id`

2. **TagNo assignment** — for all entries:
   - Use Webscorer-provided tagNo
   - Insert into `tagNo` table if not already present

3. **eventEntry creation** — for each registrant:
   ```php
   [
       'event_id'    => $eventId,
       'member_id'   => $resolvedMemberId,
       'tagNo_id'    => $tagNoId,
       'paid'        => $eventfee > 0 ? true : false,
       'able'        => false,  // set in Step 3
       'handicap'    => null,
       'startPosition' => null,
       'expectedPace'  => null,
       'expectedTime'  => null,
       'stdDevTime'    => null,
       'daysSince'     => -1,
       'lastWin'       => -1,
       'method'         => null,
       'createDate'    => $registrationTime,
       'lastModDate'   => now,
   ]
   ```
   - Uses `eventEntryLoader`-style upsert: if member_id + event_id already exists, update rather than duplicate

4. **Validation checks** (from `checkEvent.php`):
   - Age-based division correction (member's age at first event of year determines correct division)
   - Provisional members: flag if running without paid membership
   - Flag underage runners

5. **TagNo conflict log** — if Webscorer tagNo differs from last used tagNo in eventResults, log to `storage/app/handicapping/{eventId}/logs/tagno_conflicts.json`

**Repeatability**: Step 2 can re-run if Step 1 is re-run with a new/updated manifest. Existing `member` records are not duplicated. `eventEntry` records are upserted (updated if exist, created if new).

**Error handling**: If a row cannot be processed (missing critical field), log error and continue. Do not exit.

---

### Step 3 — Member Processing (Stats Computation)

**Command**: `handicapper:process {eventId} {--x=8}`

**Input**: `eventEntry` records for the event (from Step 2)

**Output**: `daysSince`, `lastWin`, `expectedPace`, `expectedTime`, `stdDevTime`, `method` populated in `eventEntry`. Working JSON files per member at `storage/app/handicapping/{eventId}/members/`

**Process** (per member in eventEntry):

1. **Days since last run**
   ```
   daysSince = eventDate - last eventResult eventDate for this member (before this event)
   = -1 if never run before
   ```

2. **Runs since last win** (from `member.lastWin()`, `member.lastFirstPlace()`, `member.lastShortCourseWin()`):
   - `lastFirstPlace()`: most recent `eventResult` with `linePosition = 1` for this member
   - `lastShortCourseWin()`: most recent Division 2 eventResult where member had fastest time for their rank (J/S/K), regardless of overall position
   - `lastWin = max(eventDate_of_lastFirstPlace, eventDate_of_lastShortCourseWin)`
   - `runsSinceLastWin = count(eventResults for member where eventDate > lastWinDate)`
   - Special rule: senior/junior running in short course still count short course wins correctly (via `lastShortCourseWin()`)

3. **Historical event data collection** (configurable `x`, default 8):
   - Find last `x` `eventResult` records for this member at similar distance:
     - Distance window: event distance ± 2.5km (from `memberStats.lower/upper`)
     - Ordered by eventDate descending
   - If fewer than `x` found at similar distance, expand to any distance
   - If still no results, output null record

   **Fields collected per historical event**: `eventDate`, `distance`, `actual` (finish time), `pace` (sec/km), `venue.name`, `rank`

4. **Pace computations** (per member, from historical events):

   **fastestPace**: MIN(pace) from selected events

   **avgPace**: mean of pace values

   **lsfPace** (least-squares fit linear regression):
   - Uses `utility/linearRegression` class (existing from legacy)
   - Input: (days_since_first_event, pace) pairs
   - Output: slope + intercept → extrapolated pace for target distance
   - If regression slope ≈ 0 (insufficient data), fall back to avgPace

   **mlrPace** (multi-linear regression via Phpml):
   - Uses `Phpml\Regression\LeastSquares`
   - Input: distance → actual_time mapping
   - Falls back to lsfPace if singular matrix error

   **stdDev**: standard deviation of pace values (distance-adjusted)

   **Outlier removal** (from `memberStats::filterHandicapData`):
   - If more than 3 data points, remove records where pace > 1.3 stdDev from mean (biased toward slower outliers only)
   - Recompute avgPace/stdDev after removal

5. **Expected time calculation**:
   ```
   expectedTime = expectedPace × distance (seconds)
   ```

6. **Working files**: For each member, write JSON to:
   ```
   storage/app/handicapping/{eventId}/members/{regNo}_{firstName}_{lastName}.json
   ```
   Structure:
   ```json
   {
     "member_id": 1234,
     "regNo": 1042,
     "firstName": "John",
     "lastName": "Smith",
     "eventId": 456,
     "history": [
       {"eventDate": "2026-05-23", "distance": 5, "venue": "Tailrace", "pace": 340, "actual": "27:00", "rank": "S"}
     ],
     "stats": {
       "fastestPace": 320,
       "avgPace": 345,
       "lsfPace": 342,
       "mlrPace": 338,
       "stdDev": 12,
       "method": "ave"
     },
     "daysSince": 7,
     "lastWin": 3,
     "expectedPace": 345,
     "expectedTime": "27:30",
     "stdDevTime": "00:57"
   }
   ```

7. **Update eventEntry**: Write `expectedPace`, `expectedTime`, `stdDevTime`, `daysSince`, `lastWin`, `method` back to the `eventEntry` table for each member.

**Repeatability**: Step 3 can re-run if `eventResult` data is corrected. Updates `eventEntry` in place (not delete/recreate). Re-computes all stats from current `eventResult` data.

**Configurable parameter** `--x`: number of historical events to collect (default 8). Also configurable via `config/lrc-handicapping.php`.

---

### Step 4 — Spreadsheet Export

**Command**: `handicapper:export {eventId} {--format=xlsx}`

**Input**: `eventEntry` records with computed stats (from Step 3)

**Output**: Spreadsheet file (XLSX) at `storage/app/handicapping/{eventId}/exports/`

**Spreadsheet structure** (per division sheet):

**Division sheet columns**:
```
A        B        C       D      E        F      G      H       I       J       K
regNo   firstName lastName age   Date     Distance Venue   Pace    Date    Distance ...
```

**Per-member cell block** (consecutive rows, one block per member):

```
Row 1:  [regNo] [firstName] [lastName] [age]     ← all in one row
Row 2:  Date    Distance    Venue     Pace       ← header row
Row 3:  data    data        data      data       ← event 1
Row 4:  data    data        data      data       ← event 2
...                                     (x rows, configurable, default 8)
Row N-1: fastestPace: X:XX  avgPace: X:XX  lsfPace: X:XX  mlrPace: X:XX  stdDev: M:SS
Row N:   expectedPace: [EDITABLE CELL]             ← ONLY editable cell
```

**expectedPace is pre-populated** with the value computed in Step 3 (fastestPace initially, as requested by handicapper).

**Computed stats row** is read-only — shows `fastestPace`, `avgPace`, `lsfPace`, `mlrPace`, `stdDev`.

**ONLY `expectedPace` column in the stats row is editable.** When the handicapper changes it, `expectedTime` auto-calculates via formula: `=expectedPace * distance`.

**Row ordering**: Sheet uses `SORT()` formula on `expectedTime DESC` so the sheet auto-reorders when `expectedPace` is changed. Blocks remain intact during sort (Google Sheets / Excel handles this natively).

**Multiple divisions**: Each division gets its own sheet (Long Course / Short Course / Junior).

**Library**: PhpSpreadsheet (already available via `Phpml` dependency).

---

### Step 5 — Spreadsheet Import

**Command**: `handicapper:import {eventId} {file.xlsx}`

**Input**: Completed spreadsheet from handicapper

**Output**: Updated `eventEntry` records in database: `expectedPace`, `expectedTime`, `handicap`, `startPosition`

**Process**:

1. Read spreadsheet, find all rows where `expectedPace` has been edited
2. For each edited row:
   - Look up `member_id` via `regNo` matching
   - Update `eventEntry.expectedPace = edited_value`
   - Recalculate `eventEntry.expectedTime = expectedPace × distance`
3. **Compute handicaps**:
   - `expectedTime DESC` sort (slowest first)
   - `handicap = longestExpectedTime - thisExpectedTime` (seconds, HH:MM:SS)
   - `startPosition = sequential position in sorted order (1 = first off scratch)`
4. Write `handicap`, `startPosition`, `lastModDate = now` to each `eventEntry` row

**Validation**:
- Check `expectedPace` is not null
- Check `expectedPace` is a positive number
- Flag if `expectedPace` has changed from original Step 3 value (for audit log)

**Repeatability**: Can be run multiple times — last run wins. Supports mid-week corrections.

---

## 4. Service Architecture

All services are plain PHP classes with no framework inheritance. They receive dependencies via constructor.

```
app/Services/
├── WebscorerParser.php
│   └── parse(string $txtFile): string $csvPath
│       └── Uses: bin/generateCsvFile.sh (sed pipelines)
│
├── IdentityResolver.php
│   └── resolve(string $csvPath, PDO $db): string $manifestCsvPath
│       └── Uses: MemberMatcher, AliasList, TagNoHistory
│
├── MemberCreator.php
│   └── createMembers(string $manifestCsvPath, PDO $db, LoggerInterface $logger): void
│
├── MemberProcessor.php
│   └── process(int $eventId, PDO $db, int $x, LoggerInterface $logger): void
│       └── Uses: MemberStatsComputer, LastWinCalculator, DaysSinceCalculator
│
├── MemberStatsComputer.php
│   └── computeStats(array $historicalEvents, float $targetDistance): array
│       └── Computes: fastestPace, avgPace, lsfPace, mlrPace, stdDev, outlier removal
│
├── LastWinCalculator.php
│   └── runsSinceLastWin(PDO $db, int $memberId, DateTime $eventDate): int
│       └── Uses: member.lastFirstPlace(), member.lastShortCourseWin() rules
│
├── SpreadsheetExporter.php
│   └── export(int $eventId, string $format, int $x): string $filePath
│       └── Uses: PhpSpreadsheet
│
└── HandicapImporter.php
    └── import(string $xlsxPath, PDO $db, LoggerInterface $logger): ImportResult
```

**DI pattern**:
```php
public function __construct(
    PDO $db,
    ?LoggerInterface $logger = null,
    array $config = []
)
```

All services: no `echo`, no `exit`, no `ob_start`. Return structured data or throw exceptions.

---

## 5. CLI Commands

| Command | Description |
|---------|-------------|
| `webscorer:parse {file}` | TXT → cleaned CSV manifest |
| `webscorer:resolve {eventId} {csv}` | Identity matching + member/eventEntry creation |
| `handicapper:process {eventId} {--x=8}` | Compute stats, update eventEntry |
| `handicapper:export {eventId} {--format=xlsx}` | Produce XLSX spreadsheet |
| `handicapper:import {eventId} {file}` | Read completed spreadsheet → update DB |

All commands support `--dry-run` for testing without DB writes.

---

## 6. File Storage Structure

```
storage/app/handicapping/
└── {eventId}/
    ├── identity/
    │   └── {eventId}_manifest.csv
    ├── members/
    │   └── {regNo}_{firstName}_{lastName}.json   # one per member
    ├── logs/
    │   └── tagno_conflicts.json
    │   └── import_audit.json
    └── exports/
        └── {eventId}_{division}_startlist.xlsx
```

All working files are JSON or CSV (no MySQL temp tables) for debuggability.

---

## 7. Timeline Constraints

- Race entries close: **7:00pm Thursday** before Saturday race
- Division 3 (Junior) first event: **10:00am Saturday**
- Handicapper window: **7:30pm Thursday → 7:30pm Friday**
- Multiple re-runs of Step 3/4 expected within that window if `eventResult` corrections are needed

---

## 8. Out of Scope

- Webscorer API integration (manual TXT download only)
- Google Sheets sync
- Email/notification automation
- Standalone race result posting (webResultsWizard replacement)
- Changes to legacy database schema