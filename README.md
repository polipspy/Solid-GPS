# Shuffled GPS points → Trips (PHP 8)

This tool ingests a **shuffled CSV** of GPS points and emits a **GeoJSON FeatureCollection** where each **trip** is a `LineString` with summary stats and a distinct color.

- **Input fields (CSV header recommended):** `device_id, lat, lon, timestamp`  
  - `timestamp` must be ISO‑8601 (e.g., `2025-08-21T12:34:56Z`)
- **Cleaning:** Rows with **invalid coordinates** (lat ∉ [-90,90], lon ∉ [-180,180]) or **bad timestamps** are **discarded** and written to `rejects.log` as JSON lines.
- **Ordering:** Points are **sorted per device** by timestamp.
- **Splitting rule (create a new trip when either is true):**
  - time gap **> 25 minutes** (configurable via `--gap`)
  - straight‑line distance jump **> 2 km** (configurable via `--jump`; haversine)
- **Trip stats (per LineString):**
  - `total_distance_km`, `duration_min`, `avg_speed_kmh`, `max_speed_kmh`
- **Styling:** Each trip has a distinct `"color"` string under `properties` (GeoJSON itself doesn’t define styling; viewers may use this).
- **No external libs or DB.** Pure **PHP 8** standard library.
- **Performance:** Single pass + per-device sort; completes well under a minute for typical CSV sizes on a laptop.

---

## Quick start

```bash
php -v   # PHP 8+
php your_script.php --input=/path/to/points.csv --out=/path/to/trips.geojson
```

Optional flags:

- `--rejects=rejects.log` (default: next to `--out`)
- `--gap=25` (minutes)
- `--jump=2` (km)
- short forms: `-i`, `-o`, `-r`

Examples:

```bash
# Basic
php your_script.php -i points.csv -o trips.geojson

# Custom thresholds + custom rejects log
php your_script.php --input=points.csv --out=trips.geojson --gap=20 --jump=1.5 --rejects=bad_rows.log
```

---

## CSV expectations

- **Header row** is recommended; the script will auto-detect columns named (case-insensitive) among:
  - `device_id`, `lat` (or `latitude`), `lon`/`lng` (or `longitude`), `timestamp`/`time`/`datetime`.
- If no header is present, it assumes **column order**: `device_id, lat, lon, timestamp`.
- Extra columns are ignored.

**Rejects:** Each bad row is logged as a JSON line with a reason (`BAD_FIELDS`, `BAD_TIMESTAMP`, `TOO_FEW_COLUMNS`). Very short trips (fewer than 2 points) are also dropped and logged as `TRIP_DROPPED_TOO_FEW_POINTS` (GeoJSON LineString requires ≥2 positions).

---

## Splitting & metrics

- Points are processed **per device** to avoid cross-device jumps.
- A **new trip** starts if the time gap between consecutive points exceeds `--gap` minutes **or** if the great‑circle distance between them exceeds `--jump` km.
- **Distance:** computed with the **haversine** formula (Earth radius 6371 km).
- **Duration:** `end_time - start_time` (minutes).
- **Average speed:** `total_distance_km / duration_hours` (km/h). If duration is 0, avg speed = 0.
- **Max speed:** max per‑segment speed inside the trip. Segments with `dt <= 0` are ignored for speed.

---

## Output

A single file specified by `--out` containing a valid **GeoJSON FeatureCollection**. Each feature:

```json
{
  "type": "Feature",
  "geometry": {
    "type": "LineString",
    "coordinates": [[lon, lat], [lon, lat], ...]
  },
  "properties": {
    "trip_id": "trip_1",
    "device_id": "devA",
    "start_time": "2025-08-21T09:00:00+08:00",
    "end_time": "2025-08-21T10:30:00+08:00",
    "num_points": 42,
    "total_distance_km": 12.345,
    "duration_min": 90.0,
    "avg_speed_kmh": 8.23,
    "max_speed_kmh": 42.7,
    "color": "#1f77b4"
  }
}
```

Trips are globally numbered (`trip_1`, `trip_2`, …) in **ascending start time** across devices.

---

## Notes & assumptions

- **Time zones:** ISO‑8601 offsets are respected when parsing. Output `start_time`/`end_time` adopt your PHP default timezone.
- **Single-point trips:** Dropped (can’t form a LineString) and logged.
- **Memory & speed:** Uses `SplFileObject` (streaming) and per‑device in‑memory arrays with an `O(n log n)` sort per device.
- **Validation:** A row is invalid if: missing critical fields, non‑numeric lat/lon, lat/lon out of range, or timestamp fails to parse.

---

## Troubleshooting

- **“Input CSV not found”** – check `--input` path.
- **“Failed to write output”** – ensure the target directory exists and is writable.
- **Empty output / many rejects** – verify header names and timestamp format. Consider adding a header row.
- **Viewer ignores colors** – some viewers don’t auto-style GeoJSON; use the `properties.color` key in your renderer.

---

## License

MIT (feel free to adapt).
