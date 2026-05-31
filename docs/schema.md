# EventEntry Schema Reference

> **Source**: `eventEntry` table in LRC MySQL database
> **Purpose**: Documents each field exported for the handicapper's spreadsheet

---

## Fields

### Member Identification

| Field | Type | Description |
|-------|------|-------------|
| `regNo` | string | Club membership registration number |
| `firstName` | string | Runner's first name |
| `lastName` | string | Runner's last name |

### Predicted Performance

| Field | Type | Description |
|-------|------|-------------|
| `expectedPace` | seconds/km | Predicted pace per kilometre |
| `expectedTime` | seconds | Predicted total finish time |
| `stdDevTime` | seconds | Standard deviation of historical times |

### Prediction Method

| Field | Type | Description |
|-------|------|-------------|
| `method` | string | Which calculation method was used |

**Method codes**:

| Code | Name | Meaning |
|------|------|---------|
| `ave` | Average Pace | Based on average of recent paces |
| `lsf` | Least Squares Fit | Linear regression trend analysis |
| `mlr` | Machine Learning | Weighted ML prediction |
| `man` | Manual Override | Set by committee decision |

### Race History

| Field | Type | Description |
|-------|------|-------------|
| `daysSince` | integer | Days since last club race |
| `lastWin` | integer | Races since last division win |

### Handicap

| Field | Type | Description |
|-------|------|-------------|
| `handicap` | seconds | Calculated handicap (seconds after scratch) |
| `startPosition` | integer | Position in start list (1 = first off) |

### Status

| Field | Type | Description |
|-------|------|-------------|
| `paid` | boolean | Entry fee paid |
| `able` | boolean | Able to run this event |

---

## Notes

- `expectedTime` is in seconds (e.g., 1800 = 30:00)
- `handicap` of 90 means the runner starts 90 seconds after scratch
- `lastWin = 0` means the runner won their last race
- `lastWin = 5` means 5 races have passed since their last win
