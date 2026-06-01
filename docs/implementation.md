# Implementation Plan — LRC Spreadsheet Export / Handicapping System

> **Status**: Mostly Implemented
> **Date**: 2026-05-31
> **Prerequisite**: `functional_spec.md`

---

## 1. Project Setup

### 1.1 Laravel Application Structure

```
lrc-spreadsheet-export/
├── app/
│   ├── Console/Commands/
│   │   ├── WebscorerParseCommand.php
│   │   ├── WebscorerResolveCommand.php
│   │   ├── HandicapperProcessCommand.php
│   │   ├── HandicapperExportCommand.php
│   │   └── HandicapperImportCommand.php
│   └── Services/
│       ├── WebscorerParser.php
│       ├── IdentityResolver.php
│       ├── MemberCreator.php
│       ├── MemberProcessor.php
│       ├── MemberStatsComputer.php
│       ├── LastWinCalculator.php
│       ├── SpreadsheetExporter.php
│       └── HandicapImporter.php
├── bin/
│   ├── generateCsvFile.sh        # Copied from legacy
│   ├── fixRegHeader.sh           # Copied from legacy
│   ├── convertToCsv.sh          # Copied from legacy
│   ├── cleaner.sh                # Copied from legacy
│   └── cleaner_bin.sh            # Copied from legacy
├── config/
│   └── lrc-handicapping.php      # Configuration (x default, distance window, etc.)
├── docs/
│   ├── functional_spec.md
│   ├── implementation.md
│   ├── test_plan.md
│   └── workflow.md
├── storage/
│   └── app/handicapping/         # Created at runtime
│       └── {eventId}/
├── config/
│   └── database.php              # Existing — LRC MySQL connection
└── tests/
    └── Unit/
```

### 1.2 Dependencies

| Package | Purpose | Source |
|---------|---------|--------|
| `PhpSpreadsheet` | XLSX read/write | `composer require phpoffice/phpspreadsheet` |
| `Phpml` | ML regression | `composer require php-ai/phpml` |
| `phpunit/phpunit` | Testing | `composer require --dev phpunit/phpunit` |
| `mockery/mockery` | Mocking | `composer require --dev mockery/mockery` |

Existing dependencies (`laravel/framework`, etc.) are already in the project.

---

## 2. Implementation Order

### Phase 1 — Infrastructure

#### 2.1.1 Copy legacy bin scripts to `bin/`

Copy from `~/Documents/records.launcestonrunningclub.com.au/test/bin/`:
- `generateCsvFile.sh`
- `fixRegHeader.sh`
- `convertToCsv.sh`
- `cleaner.sh`
- `cleaner_bin.sh`

Make executable. Do not modify — preserve exact legacy behaviour.

#### 2.1.2 Create `config/lrc-handicapping.php`

```php
return [
    'history_rows_default' => 8,
    'distance_window' => 2.5,        // km
    'outlier_threshold' => 1.3,      // stdDev multiplier
    'dob_month_tolerance' => 30,    // days
    'dob_year_tolerance' => 365,    // days
    'storage_path' => 'handicapping',
];
```

#### 2.1.3 Create storage directories

```
storage/app/handicapping/
```

---

### Phase 2 — Step 1: WebscorerParser + IdentityResolver

#### 2.2.1 `WebscorerParser.php`

**Responsibilities**:
- Execute `generateCsvFile.sh` via `proc_open` with `stream_select` (streaming output)
- Return path to cleaned CSV

**Method**:
```php
public function parse(string $txtPath): string
```

**Implementation notes**:
- Use symfony/process or raw `proc_open` with streaming
- Streaming: each line of output from shell script → `$this->logger->info()`
- Capture STDERR separately
- Validate output file exists before returning

**Testing**:
- Run against existing `.txt` files in `~/Documents/.../wizards/` (the `output_file` files)
- Compare output CSV headers against expected snake_case names
- Compare first few data rows against known cleaned values

#### 2.2.2 `IdentityResolver.php`

**Responsibilities**:
- Read CSV from WebscorerParser
- Match each row against `member` table using fuzzy match hierarchy
- Output CSV manifest with `member_id`, `match_type`, `confidence_score`, `tmp_<uuid>` for new members

**Match engine**:
- Tier 1: exact firstName + lastName + DOB → confidence 1.0
- Tier 2: exact firstName + lastName + DOB within ±30 days → confidence 0.85
- Tier 3: exact firstName + lastName + DOB within ±365 days → confidence 0.70
- Tier 4: alias firstName + exact lastName + DOB within ±365 days → confidence 0.65
- Support signals: tagNo match → +0.15, email match → +0.10

**Alias list**: Initial seed from `cleaner.sh` patterns (documented in `functional_spec.md` section 3). Stored as config/service property. Extensible.

**TagNo history**: Query `eventResult` join `tagNo` for recent events to confirm candidate matches.

**Output**: CSV manifest at `storage/app/handicapping/{eventId}/identity/{eventId}_manifest.csv`

**Testing**:
- Construct test CSV with known names/DOBs
- Unit test match tiers against seeded test members in DB
- Test alias resolution

---

### Phase 3 — Step 2: MemberCreator

#### 2.3.1 `MemberCreator.php`

**Responsibilities**:
- Read manifest CSV
- For each `tmp_<uuid>` row: insert `member`, `email`, `phone` records
- For all rows: insert/update `tagNo` records
- Upsert `eventEntry` records (member_id + event_id key)

**Upsert logic** (from legacy `eventEntryLoader.php`):
```
if (member_id + event_id exists in eventEntry):
    update fields
else:
    insert new record
```

**eventEntry fields to set**:
- `event_id`, `member_id`, `tagNo_id`, `paid`, `able=false`, `handicap=null`, `startPosition=null`, `expectedPace=null`, `expectedTime=null`, `stdDevTime=null`, `daysSince=-1`, `lastWin=-1`, `method=null`, `createDate`, `lastModDate`

**Logging**: For each created/updated record → `$logger->info("created member {$regNo}: {$firstName} {$lastName}")`

**Testing**:
- Run against known manifest with mix of direct matches, fuzzy matches, new members
- Verify no duplicate `member` records created
- Verify no duplicate `eventEntry` records
- Check tagNo conflict logging works

---

### Phase 4 — Step 3: MemberProcessor + MemberStatsComputer + LastWinCalculator

#### 2.4.1 `LastWinCalculator.php`

**Copy of legacy logic** (from `member.lastWin()`, `member.lastFirstPlace()`, `member.lastShortCourseWin()`):

```php
public function runsSinceLastWin(PDO $db, int $memberId, DateTime $eventDate): int
```

Logic:
1. Find most recent `eventResult` with `linePosition=1` for member before `$eventDate`
2. Find most recent Division 2 eventResult where member's time was fastest for their rank
3. Whichever is newer → last win date
4. Count eventResults for member after that date

**Testing**:
- Seed DB with known eventResult history
- Assert runsSinceLastWin for member with known win history

#### 2.4.2 `MemberStatsComputer.php`

**Copy of key logic** from `memberStats.php`:

```php
public function computeStats(array $historicalEvents, float $targetDistance): array
```

Returns:
- `fastestPace`: MIN(pace)
- `avgPace`: mean of pace values
- `lsfPace`: via `utility/linearRegression`
- `mlrPace`: via `Phpml\Regression\LeastSquares` (fallback to lsfPace on error)
- `stdDev`: stdDev of pace × distance
- `outlierRemoved`: bool (was any outlier removed)

**Outlier removal**: Remove pace values > 1.3 stdDev from mean (only slower outliers). Only applied if > 3 data points.

**Normalisation**: Use `RaceTimeNormalizer::getNormalizedTime()` and `stdDist=5` (same as legacy).

**Testing**:
- Unit test with known pace values
- Verify outlier removal fires correctly
- Compare lsfPace/mlrPace outputs against legacy `memberStats.estimates()`

#### 2.4.3 `MemberProcessor.php`

**Orchestrator** — loops over all members in `eventEntry` for the event:

```php
public function process(int $eventId, PDO $db, int $x, LoggerInterface $logger): void
```

Per member:
1. Get `daysSince` from `eventResult` join ordered by eventDate desc
2. Get `runsSinceLastWin` from `LastWinCalculator`
3. Collect last `x` historical `eventResult` records at similar distance (±2.5km)
4. Call `MemberStatsComputer.computeStats()`
5. Write working JSON to `storage/app/handicapping/{eventId}/members/`
6. Update `eventEntry` with computed values

**Streaming output**: Each member processed → `$logger->info("{$regNo} {$firstName} {$lastName}: daysSince={$daysSince}, lastWin={$lastWin}, expectedPace={$expectedPace}")`

**Repeatability**: Use upsert — update if exists, recompute if re-run.

**Testing**:
- Run against single known event with 10+ members
- Verify all computed values present in eventEntry after run
- Verify JSON files exist for each member
- Re-run and verify values update (not duplicate)

---

### Phase 5 — Step 4: SpreadsheetExporter

#### 2.5.1 `SpreadsheetExporter.php`

**Uses**: PhpSpreadsheet

**Implemented spreadsheet structure** (per division, 3 sheets):

```
Participants {div} sheet:
  Per-runner block (identity row + 8 history rows + stats rows + manualPace row)
  - method cell pre-populated with Step 3 computed method name
  - manualPace cell (yellow) pre-populated with expectedPace from Step 3
 - formula =IF(method="avg", avgPace, IF(method="lsf", lsfPace, ...)) selects the right pace

Entry {div} sheet:
  Row 1: Date | Division | Distance | ID | entrants | useLift
  Row 2: [data] | [data] | [data] | [data] | [count] | [0/1]
  Row 4: regNo | tagNo | firstName | lastName | age | sex | daysSince | lastWin | lift | expectedPace | expectedTime | handicap
  Rows 5+: data rows with live formulas
  
  Col I (lift):    =IF($F$2=1,IF(OR(H<0,H>=E$2),0,((E$2-H-1)/E$2)*K*0.05*$B$2),0)
  Col J (expPace): =IF('Participants {div}'!I{fr+10}="avg", 'Participants {div}'!I{fr+8}, ...)
  Col K (expTime): =J{row}*C$2
  Col L (handicap): =MAX($K$5:$K$22)-K{row}+I{row}  [pursuit race: slowest=0, faster=+time]

Start {div} Start sheet:
  Live-linked to Entry sheet via INDEX/MATCH/SMALL
  Sorted ascending by handicap (slowest/zero first)
  Cols: First name | Last name | Gender | Distance | Category | Bib | Start time | Handicap
  Start time = MROUND(handicap, 10sec) formatted as +HH:MM:SS for WebScorer
```

**expectedPace cell**: Pre-populated with Step 3 computed value. Yellow highlight indicates editable.

**expectedTime formula**: `=expectedPace * distance` (auto-calculates when expectedPace changes).

**Per-division sheets**: One entry sheet + one participants sheet + one start sheet per division (3 divisions = 9 sheets total).

**Pursuit race handicap logic**:
- Slowest runner: `MAX(all expectedTimes) - MAX(all expectedTimes) + lift = 0` → scratch (starts first)
- Faster runner: `MAX(all expectedTimes) - theirTime + lift > 0` → starts later (positive = behind scratch)
- Lift formula: `((entrants − lastWin − 1) / entrants) × expectedTime × 5% × division`
 - Disabled when `useLift=0` (F2=0)
  - Zero when `lastWin=-1` (never won)
  - Zero when `lastWin >= entrants` (committee rule)

**Testing**:
- Export for known event with 10+ members across 2 divisions
- Open in Excel/Google Sheets, verify:
  - Participants block structure correct (8 history rows, stats rows, manualPace row)
  - Entry sheet column layout correct (I=lift, J=expectedPace, K=expectedTime, L=handicap)
  - Lift formula guards fire correctly (lastWin<0, lastWin>=entrants)
  - Pursuit race handicap: slowest runner has 0, others positive
  - Start List sorted ascending by handicap
  - Start time formatted as +HH:MM:SS for WebScorer

---

### Phase 6 — Step 5: HandicapImporter

#### 2.6.1 `HandicapImporter.php`

**Read completed spreadsheet**:
- Loop through all rows
- Find rows where `expectedPace` column has been edited
- Look up `member_id` via `regNo`

**Update eventEntry**:
```php
$expectedTime = $expectedPace * $distance;
$handicap = $longestExpectedTime - $expectedTime;  // seconds
$startPosition = $position_in_sorted_order;
```

**Handicap format**: `HH:MM:SS` (same as legacy)

**Audit log**: Write `storage/app/handicapping/{eventId}/logs/import_audit.json` with before/after values.

**Testing**:
- Create mock spreadsheet with edited expectedPace values
- Run import
- Verify eventEntry updated correctly
- Verify handicap values in correct order
- Verify startPosition assigned correctly

---

### Phase 7 — CLI Commands

#### 2.7.1 Commands

| Command | Class | Signature |
|---------|-------|-----------|
| `webscorer:parse` | `WebscorerParseCommand` | `{file}` |
| `webscorer:resolve` | `WebscorerResolveCommand` | `{eventId} {csv} [--interactive] [--skip-unknowns]` |
| `handicapper:process` | `HandicapperProcessCommand` | `{eventId} {--x=8}` |
| `handicapper:export` | `HandicapperExportCommand` | `{eventId} {--format=xlsx}` |
| `handicapper:import` | `HandicapperImportCommand` | `{eventId} {file}` |

All support:
- `--dry-run` — no DB writes
- `--verbose` / `-v` — detailed output

#### webscorer:resolve — Interactive Unknown Resolution

```
php cli.php webscorer:resolve <eventId> <csv> [--interactive] [--skip-unknowns]
```

**Flow:**
1. Run identity matching → manifest with known and unknown (tmp_*) rows
2. Known rows → processed immediately via MemberCreator
3. Unknown rows → interactive prompt per runner:

| Choice | Action |
|--------|--------|
| `M <id>` | Match to existing member ID — creates eventEntry only |
| `U <id>` | Update member fields, then create eventEntry — shows DB values, confirm changes |
| `C` | Create new member (status=prov), create eventEntry |
| `S` | Skip this runner — no member, no eventEntry |
| `A` | Approve remaining as new — bulk insert all remaining unknowns |
| `Q` | Quit — stops processing |

**`--skip-unknowns`**: processes known rows only, skips all unknowns  
**No flags (default)**: warns if unknowns found, excludes them from processing

The workflow enables new-member creation to happen race-morning without holding up spreadsheet generation for known runners.

Each command:
1. Loads config (`config/lrc-handicapping.php`)
2. Instantiates service with DB connection + logger
3. Calls service method
4. Formats output to console

---

## 3. Key Implementation Notes

### 3.1 Database Access

- Use raw `PDO` (not Eloquent) for services to avoid Laravel model overhead
- DB config from `config/database.php`
- Single shared `PDO` instance per command run

### 3.2 Logging

- Services receive `?LoggerInterface $logger` (default: `NullLogger`)
- CLI commands inject `ConsoleLogger` which wraps Symfony `OutputInterface`
- Web controllers inject a streaming logger that flushes to SSE/chunked response

### 3.3 Streaming Output

- `generateCsvFile.sh` output streamed line-by-line via `proc_open` + `stream_select`
- Each service method calls `$this->logger->info($line)` — no direct echo
- In CLI: output appears in real time
- In web: SSE delivers each line as an event

### 3.4 Error Handling

- Services throw exceptions on unrecoverable errors (file not found, DB unreachable)
- CLI commands catch exceptions, log to STDERR, return exit code 1
- Do not call `exit` in services — let caller decide

### 3.5 Re-run Safety

- All write operations use upsert (INSERT ON DUPLICATE KEY UPDATE)
- Step 3 recomputes all values from scratch on each run
- No deletion — only insert/update

---

## 4. File Mapping

| Legacy File | New File | Status |
|------------|----------|--------|
| `bin/generateCsvFile.sh` | `bin/generateCsvFile.sh` | ✅ Copied |
| `bin/cleaner.sh` | `bin/cleaner.sh` | ✅ Copied |
| `src/load/loadWebEventEntry.php` | `app/Services/WebscorerParser.php` + `app/Services/IdentityResolver.php` | ✅ Done |
| `src/load/eventEntryLoader.php` | `app/Services/MemberCreator.php` | ✅ Done |
| `src/work/handicapEvent.php` | `app/Services/MemberProcessor.php` + `app/Services/MemberStatsComputer.php` | ✅ Done |
| `src/model/memberStats.php` | `app/Services/MemberStatsComputer.php` | ✅ Done |
| `src/model/member.php` (lastWin, lastFirstPlace, lastShortCourseWin) | `app/Services/LastWinCalculator.php` | ✅ Done |
| `src/utility/linearRegression.php` | `app/Services/MemberStatsComputer.php` (uses directly) | ✅ Done |
| (shell export) | `app/Services/SpreadsheetExporter.php` | ✅ Done (PhpSpreadsheet) |
| (no equivalent) | `app/Services/HandicapImporter.php` | ✅ Done |

---

## 5. Execution Sequence (Happy Path)

```bash
# Step 1: Parse Webscorer TXT → identity CSV
php cli.php webscorer:parse ~/Downloads/webscorer_reg.txt

# Step 2: Create members + eventEntry records
php cli.php webscorer:resolve 456 storage/app/handicapping/456/identity/456_manifest.csv

# Step 3: Compute stats (8 historical events per member)
php cli.php handicapper:process 456 --x=8

# Step 4: Export spreadsheet
php cli.php handicapper:export 456 --all-divisions

# Step 5: Import completed spreadsheet (after handicapper edits)
php cli.php handicapper:import 456 ~/Downloads/handicapper_completed.xlsx
```

Each command is idempotent — can be re-run in sequence if issues found.

---

## 6. Non-Functional Requirements

| Requirement | Target |
|------------|--------|
| CLI response time (Step 1-3) | < 5s for 50 entrants |
| XLSX export time | < 10s for 50 entrants |
| XLSX file size | < 1MB typical |
| Logging verbosity | Configurable per command |
| DB connection reuse | Single PDO per command |

---

## 7. Implementation Status

| Phase | Component | Status |
|-------|-----------|--------|
| 1 | Project setup + bin scripts | ✅ Done |
| 2 | WebscorerParser + IdentityResolver | ✅ Done |
| 3 | MemberCreator | ✅ Done |
| 4 | MemberProcessor + MemberStatsComputer + LastWinCalculator | ✅ Done |
| 5 | SpreadsheetExporter (Participants + Entry + Start sheets) | ✅ Done |
| 6 | HandicapImporter | ✅ Done |
| 7 | CLI Commands | ✅ Done |
| Tests | Unit tests | ✅ 39 tests passing |

**Known remaining issues** (non-blocking):
- `exportStartListCsv` uses XML-based XLSX fallback (not PhpSpreadsheet) — works but not the preferred path
- `sex` column added to entry sheet but not consistently exposed in all export paths
- Some minor formatting edge cases in the XLSX

---

*Implementation order: Phase 1 → 2 → 3 → 4 → 5 → 6 → 7*