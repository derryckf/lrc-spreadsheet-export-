# LRC Handicapping Workflow

> **Source**: ATK4 legacy application (`~/Documents/records.launcestonrunningclub.com.au/test/src/`)  
> **Purpose**: Documents the complete end-to-end flow from Webscorer registration to handicapping — the authoritative reference for the LRC handicapping system.

---

## Overview

The handicapping system operates in four distinct phases:

```
[1. REGISTRATION] → [2. EVENT ENTRY] → [3. VALIDATION] → [4. HANDICAPPING] → [5. RESULTS]
   Webscorer TXT         DB Load           ADM UI             ATK4              Webscorer
   → CSV              → eventEntry    → checkEvent.php   → handicapEvent.php  → eventResult
```

---

## Phase 1 — Registration Download (Webscorer)

**Actor**: Race organiser  
**Input**: Webscorer portal (https://www.webscorer.com)

1. Navigate to Organizers → My Registrations
2. Select the race
3. Click **"Tab-delimited TXT"** under "Download registration data"
4. Save file locally

No database involvement. File contains registrant fields from Webscorer's registration form.

---

## Phase 2 — Event Entry Load (`webEntryWizard.php` + `loadWebEventEntry.php`)

**Actor**: Handicap organiser (via ATK4 UI or CLI)  
**Input**: Webscorer `.txt` file  
**Output**: Rows in `eventEntry` table

### Step 2.1 — File Upload (`webEntryWizard` Step 1-2)
```
Webscorer TXT  →  data/upload/<original_name>.txt
```

Uploaded via ATK4 `Form\Control\Upload` widget.

### Step 2.2 — TXT → CSV Normalisation (`generateCsvFile.sh`)

```
data/upload/<name>.txt
        ↓ generateCsvFile.sh
data/processed/<name>.csv
```

Three sequential sed pipelines:

#### Stage A — Header Rename (`fixRegHeader.sh`)

Converts Webscorer human-readable column headers to snake_case field names:

| Webscorer Header | Internal Field |
|-----------------|---------------|
| `Bib` | `tagNo` |
| `First name` | `firstName` |
| `Last name` | `lastName` |
| `Date of birth` | `DOB` |
| `Email` | `email` |
| `Gender` | `gender` |
| `Distance` | `distance` |
| `Category` | `category` |
| `Registration time` | `registrationtime` |
| `Phone #` | `phone` |
| `Predicted time` | `estimate` |
| `Total fee` | `totalfee` |
| `Event fee` | `eventfee` |
| `Series Discount` | `seriesdiscount` |
| `RacePass Discount` | `racepassdiscount` |
| `RacePass Id` | `racepassid` |

#### Stage B — Delimiter and Garbage Cleanup (`convertToCsv.sh`)

```bash
sed 's/,//g' | sed 's/\t/,/g'
```
- Strips embedded commas from fields (Webscorer CSV uses comma-list values)
- Converts tab delimiters to commas

#### Stage C — Name and Data Corrections (`cleaner.sh` / `cleaner_bin.sh`)

Corrects known data quality issues in registration records before database load:

**Name corrections** (variant → canonical):
```
Jonathon,Hill       → Jono,Hill
Sue,Kerr            → Susan,Kerr
Rob,Miller          → Robert,Miller
Philip,Gregory      ← Phil,Gregory
Colin,Smith         ← Collin,Smith
Katherine,Mills     ← Kathy,Mills
Debbie,Pauna        ← Debra,Pauna / Deborah,Pauna
Annie,Loader        ← Anne-Marie,Loader
Elizabeth,Staak     ← Liz,Staak
Mick,Howard         ← Michael,Howard
Leigh,De-Jong       ← de jong variants
Dee anne,Blackwell   ← Dee,"anne Blackwell"
Daemon,Whish Wilson  ← Damon,Whish-Wilson / Whish Wilson variants
Jozina,Macqueen      ← Jozina,Goedhart
Melissa,Jessup      ← Melissa,Williams
Narelle,Whelan      ← Narelle,Wynwood
...
```

**Suffix standardisation**:
```
Rogers-Snr  ← "Rogers Snr" / "Rogers Sr" / "Rogers Senior"
Rogers-Jnr  ← "Rogers Jnr" / "Rogers Jr"
```

**DOB corrections** (specific known bad data):
```
Scott,Greaves: 1967-10-09 → 1967-10-08
Scott,Greaves: 1967-10-10 → 1967-10-08
Timothy,Reese: 1978-10-05 → 1978-12-05
Barry,Sproston: 1981-08-08 → 1980-09-08 (and other wrong years)
Paige,Wierenga: 1994-09-16 → 1994-11-17
Deb,George: 1964-01-15 → 1964-04-15
Michelle,Barnard: 1970-07-25 → 1970-08-25
```

**Other fixups**:
```
Suburb or Town     → Suburb_or_Town    (strips space in header)
CHIP NUMBER/Chip No → Chip_No          (normalise chip column name)
Full Year Race Entry / Weekly Race Entry → eventEntry
```

> **Note**: `cleaner_bin.sh` is a subset of `cleaner.sh` (61 vs 105 rules) — used for startlist/bin files. Both are applied selectively based on file type.

### Step 2.3 — Parse and Load (`loadWebEventEntry.process()`)

For each CSV row:

**a) Parse& validate fields**
| CSV field | Parsed field | Notes |
|-----------|-------------|-------|
| `Bib` | `tagNo` | Bib/chip number |
| `First name` | `firstName` | Via `utility\parser->name()` |
| `Last name` | `lastName` | Via `utility\parser->name()` |
| `Date of birth` | `DOB` | Via `utility\parser->date()` |
| `Gender` | `sex` | Via `utility\parser->gender()` |
| `Predicted time` | `estimate` | Optional predicted finish time |
| `Email` | `email` | Via `utility\parser->email()` |
| `Phone` | `phone` | Via `utility\parser->phoneNo()` |
| `Distance` | `division` | `>=5km→1`, `<=2km→3`, else `2` |
| `Category` | `rank` | S=Senior, J=Junior, K=Keen (via `parser->rank()`) |
| `Event fee` | `paid` | Non-empty → paid=true |
| `Registration time` | `createDate` | Latest determines event date |

**b) Locate event** (via `loadEventIds()`)
- Latest registration timestamp → event date
- Searches `event` table within ±6 days
- Must find 1 or 3 matching events (multiple divisions on same day)
- Returns `event_ids[division]`

**c) Load related entities** (upsert each, skip on parse failure)
- `email` via `emailLoader` → `email` table
- `phone` via `phoneLoader` → `phone` table
- `tagNo` via `tagNoLoader` → `tagNo` table (creates if new)
- `member` via `memberLoader` → `member` table (lookup by name+DOB, update if exists)

**d) Insert eventEntry**
```php
[
  'member_id'    => $member_id,       // linked member
  'event_id'     => $event_id,        // matched event
  'handicap'     => null,             // set in Phase 4
  'paid'         => $paid,            // from eventfee column
  'tagNo_id'     => $tagNo_id ?? null,
  'expectedTime' => null,             // set in Phase 4
  'daysSince'    => -1,               // set in Phase 4
  'lastWin'      => -1,               // set in Phase 4
  'able'         => false,            // set in Phase 3
  'createDate'   => $createDate,
  'lastModDate'  => $lastModDate,
]
```

---

## Phase 3 — Event Validation (`checkEvent.php`)

**Actor**: Handicap organiser  
**Input**: `eventEntry` rows for event  
**Output**: `able=true` confirmed per runner

Called from `handicapWizard` Step 2 ("Check Event Entries") via `work\checkEvent.checkParticipants()`:

1. Validate each runner is a **current financial member**
2. Check **age validity** (DOB is real and in the past)
3. Confirm **paid** status
4. Set `able = true` for eligible runners

Unpaid or ineligible runners are flagged in the UI; `able` remains `false`.

---

## Phase 4 — Manual Edit (`handicapWizard` "Validate event entries")

**Actor**: Handicap organiser  
**Input**: `eventEntry` CRUD grid

ATK4 `Crud` grid with inline editing of:
- `paid` — toggle payment status
- `able` — toggle eligibility
- `tagNo` — correct bib number

**Important**: The `eventEntry` model has a `BEFORE_SAVE` hook:
- If `tagNo` is changed to a value not in `tagNo` table → **auto-inserts new `tagNo` record**
- Sets `eventEntry.tagNo_id` to the new `tagNo` ID

---

## Phase 5 — Handicap Calculation (`handicapEvent.php`)

**Actor**: ATK4 system (via `handicapWizard` "Calculate Handicaps")  
**Input**: `eventEntry` rows with `able=true`  
**Output**: `handicap`, `startPosition`, `expectedPace`, `expectedTime`, `daysSince`, `lastWin`, `stdDevTime`

### Step 5.1 — Load Participants (`loadParticipants()`)

For each `eventEntry` where `able=true`:

**`daysSince`**
```php
$this->daysSinceLastEvent($m->ref('member_id'))
// → days between this eventDate and member's last eventEntry before it
// → -1 if never run before
```

**`lastWin`**
```php
$this->eventsSinceLastWin($m->ref('member_id'))
// → count of events since member's last eventResult where rank=1
// → -1 if never won
```

**`getEstimate()` → `expectedPace` / `expectedTime` / `stdDevTime`**

Calls `model\memberStats.estimates()`:
- Scope: last 6+ runs, within ±2.5km of current distance
- Methods (selectable in wizard):
  - `ave` — average pace (mean of normalised historical paces)
  - `lsf` — least-squares-fit linear regression over distance (default)
  - `mlr` — multi-linear regression (weighted, experimental)
  - `man` — manual (set by committee)
- Returns: `expectedPace`, `expectedTime`, `stdDevPace`, `stdDevTime` in both seconds and HH:MM:SS

### Step 5.2 — Winner Retardation (`retardWinners()`)

**Goal**: Frequent winners shouldn't always start at scratch and always win.

Algorithm:
1. Sort entries by `expectedTime DESC` (slowest expected first)
2. Calculate `setPoint = stdDev(expectedTime) × 0.6666`
3. For each entry where `lastWin >= 0` (has won before):
   ```
   lift = -(entries - lastWin - 1) / entries × setPoint
   ```
   Example: 20 entries, lastWin=1 (won last time):
   `lift = -(20 - 1 - 1) / 20 × setPoint = -0.9 × setPoint`
   = they start 0.9×setPoint seconds earlier (negative lift = sooner start)

Non-winners with high run counts also get graduated partial lifts.

Result: winners start progressively earlier relative to their ability, giving others a chance.

### Step 5.3 — Calculate Handicap (`calcHandicap()`)

1. `adjustedTime = expectedTime + liftSec`
2. Sort by `adjustedTime DESC`
3. For each runner:
   ```
   handicap = longestAdjustedTime - thisAdjustedTime
   startPosition = sequential position (1 = first off scratch)
   ```

`handicap` is stored as `HH:MM:SS` duration, meaning "seconds after scratch" the runner starts.

### Step 5.4 — Export Start List (`exportStartList()`)

Generates CSV to `data/<eventTitle>.csv`:

| Field | Source |
|-------|--------|
| First name | `member.firstName` |
| Last name | `member.lastName` |
| Gender | `member.sex` |
| Age | `YEAR(now) - YEAR(DOB)` |
| Distance | `event.distance` |
| Category | Division name: Long/Short/Junior |
| Bib | `tagNo.tagNo` |
| Start time | `handicap` (HH:MM:SS) |
| Predicted time | `expectedTime` |
| Handicap | `handicap` (+HH:MM:SS) |

---

## Phase 6 — Results Upload (`webResultsWizard.php` + `loadWebEventResult.php`)

**Actor**: Race organiser  
**Input**: Webscorer race ID  
**Output**: Rows in `eventResult` table

1. Enter `raceid` from Webscorer results URL
2. `work\checkWebScorerResults` fetches + parses JSON from Webscorer API → CSV
3. Operator corrects any errors flagged in the CSV
4. `load\loadWebEventResult.process()` inserts `eventResult` rows, updating `eventEntry` entries as well

Post-race checks run automatically:
- `checkPace` — flag unrealistic pace
- `checkLinePosition` — verify start positions
- `checkRank` — calculate finishing rank
- `checkConsistency` — calculate consistency points

---

## Data Models

| Model | Table | Key Fields |
|-------|-------|-----------|
| `event` | `event` | `eventDate`, `division`, `distance`, `venue_id`, `sponsor_id` |
| `eventEntry` | `eventEntry` | `event_id`, member_id`, `tagNo_id`, `handicap`, `expectedPace`, `expectedTime`, `paid`, `able`, `daysSince`, `lastWin`, `startPosition` |
| `eventResult` | `eventResult` | `event_id`, `member_id`, `actual` (finish time), `handicap`, `pace`, `linePosition`, `rank`, `conPts` (consistency points) |
| `member` | `member` | `firstName`, `lastName`, `DOB`, `sex`, `paceFactor` |
| `tagNo` | `tagNo` | `tagNo` (bib/chip number, unique) |
| `memberStats` | (view/computed) | Historical pace stats per distance range per member |
| `email` | `email` | `emailAddress`, `contact` |
| `phone` | `phone` | `number`, `usage` |

---

## Divison Encoding

| Numeric ID | Name | Distance Typical |
|------------|------|-----------------|
| `1` | Long Course | ≥ 5 km |
| `2` | Short Course | > 2 km and < 5 km |
| `3` | Junior | ≤ 2 km |

---

## Key ATK4 Hook: `eventEntry` BEFORE_SAVE

When saving an `eventEntry` record, if `expectedPace` **or** `expectedTime` is dirty:

```
expectedTime = distance × expectedPace (seconds)
expectedPace = expectedTime / distance  (seconds per km)
```

Both `expectedTime` and `expectedPace` are stored as `HH:MM:SS` strings in the `duration` type field.

---

## Relevant Files

| File | Role |
|------|------|
| `bin/generateCsvFile.sh` | Orchestrates TXT → CSV pipeline (calls fixRegHeader + convertToCsv + cleaner) |
| `bin/fixRegHeader.sh` | Renames Webscorer headers to snake_case field names |
| `bin/convertToCsv.sh` | Strips embedded commas, converts tabs → commas |
| `bin/cleaner.sh` | Full name/DOB/field corrections (105 rules, for registration files) |
| `bin/cleaner_bin.sh` | Subset of cleaner.sh (61 rules, for startlist/bin files) |
| `wizards/webEntryWizard.php` | Registration upload UI |
| `wizards/handicapWizard.php` | Handicap workflow UI |
| `wizards/webResultsWizard.php` | Results upload UI |
| `load/loadWebEventEntry.php` | CSV → eventEntry insertion |
| `load/eventEntryLoader.php` | eventEntry upsert logic |
| `work/handicapEvent.php` | Core handicapping algorithms |
| `work/checkEvent.php` | Eligibility validation |
| `model/eventEntry.php` | eventEntry ATK4 model |
| `model/event.php` | event ATK4 model |
| `utility/division.php` | Division name/number conversion |
| `utility/parser.php` | Field parsing: email, phone, DOB, gender, etc. |
| `utility/preProcessCsv.php` | CSV normalisation before load |

---

*Documented from ATK4 source: `~/Documents/records.launcestonrunningclub.com.au/test/src/wizards/` — May 2026*
