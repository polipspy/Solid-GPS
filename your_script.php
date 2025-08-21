<?php
/**
 * your_script.php
 *
 * CLI tool to clean, order, split, and summarize GPS points into trips,
 * then output a GeoJSON FeatureCollection of LineStrings (one per trip).
 *
 * Requirements:
 * - PHP 8+
 * - No external libraries, no databases.
 *
 * Usage examples:
 *   php your_script.php --input=/path/to/points.csv --out=/path/to/trips.geojson
 *   php your_script.php -i points.csv -o trips.geojson --rejects=rejects.log --gap=25 --jump=2
 *
 * Input CSV fields (header recommended):
 *   device_id (string), lat (decimal degrees), lon (decimal degrees), timestamp (ISO 8601)
 *
 * Behavior:
 * - Clean: discard rows with invalid coordinates or bad timestamps; log to rejects.log
 * - Order: sort remaining points by timestamp (per device)
 * - Split: create a new trip when time gap > 25 minutes OR straight-line distance jump > 2 km
 * - Number trips sequentially (trip_1, trip_2, …) globally by trip start time
 * - For each trip compute: total distance (km), duration (min), average speed (km/h), max speed (km/h)
 * - Output: GeoJSON FeatureCollection; each trip is a LineString colored differently (in properties.color)
 */

declare(strict_types=1);

const DEFAULT_GAP_MINUTES = 25;
const DEFAULT_JUMP_KM     = 2.0;
const EARTH_RADIUS_KM     = 6371.0;

function usage(string $msg = ''): void {
    if ($msg !== '') {
        fwrite(STDERR, $msg . PHP_EOL . PHP_EOL);
    }
    $u = <<<TXT
Usage:
  php your_script.php --input=points.csv --out=trips.geojson [--rejects=rejects.log] [--gap=25] [--jump=2]

Options:
  -i, --input      Path to input CSV file (device_id,lat,lon,timestamp)
  -o, --out        Path to output GeoJSON file
  -r, --rejects    Path to rejects log file (default: rejects.log next to output)
      --gap        Max time gap in minutes before starting a new trip (default: 25)
      --jump       Max straight-line jump in km before starting a new trip (default: 2)
  -h, --help       Show this help

Notes:
- Points are processed per device_id (sorted by timestamp), then merged and numbered globally by trip start time.
- Trips with < 2 points are dropped (GeoJSON LineString requires 2+ positions).
TXT;
    fwrite(STDERR, $u . PHP_EOL);
}

function parse_args(array $argv): array {
    $args = [
        'input'   => null,
        'out'     => null,
        'rejects' => null,
        'gap'     => DEFAULT_GAP_MINUTES,
        'jump'    => DEFAULT_JUMP_KM,
    ];

    foreach ($argv as $i => $arg) {
        if ($i === 0) continue;
        if ($arg === '-h' || $arg === '--help') {
            usage();
            exit(0);
        }
        if (str_starts_with($arg, '--input=')) {
            $args['input'] = substr($arg, 8);
        } elseif ($arg === '-i') {
            $args['input'] = $argv[$i+1] ?? null;
        } elseif (str_starts_with($arg, '--out=')) {
            $args['out'] = substr($arg, 6);
        } elseif ($arg === '-o') {
            $args['out'] = $argv[$i+1] ?? null;
        } elseif (str_starts_with($arg, '--rejects=')) {
            $args['rejects'] = substr($arg, 10);
        } elseif ($arg === '-r') {
            $args['rejects'] = $argv[$i+1] ?? null;
        } elseif (str_starts_with($arg, '--gap=')) {
            $args['gap'] = (int)substr($arg, 6);
        } elseif (str_starts_with($arg, '--jump=')) {
            $args['jump'] = (float)substr($arg, 7);
        }
    }

    if ($args['input'] === null || $args['out'] === null) {
        usage("Missing required --input and/or --out.");
        exit(1);
    }

    if ($args['rejects'] === null) {
        // default rejects.log next to output
        $args['rejects'] = dirname($args['out']) . DIRECTORY_SEPARATOR . 'rejects.log';
    }

    return $args;
}

function open_csv(string $path): SplFileObject {
    if (!is_file($path)) {
        fwrite(STDERR, "Input CSV not found: $path" . PHP_EOL);
        exit(1);
    }
    $f = new SplFileObject($path, 'r');
    $f->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
    return $f;
}

function haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $lat1r = deg2rad($lat1);
    $lon1r = deg2rad($lon1);
    $lat2r = deg2rad($lat2);
    $lon2r = deg2rad($lon2);

    $dlat = $lat2r - $lat1r;
    $dlon = $lon2r - $lon1r;

    $a = sin($dlat/2)**2 + cos($lat1r)*cos($lat2r)*sin($dlon/2)**2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return EARTH_RADIUS_KM * $c;
}

function parse_timestamp(string $ts, int $lineNum, $rej): ?DateTimeImmutable {
    try {
        $dt = new DateTimeImmutable($ts);
        return $dt;
    } catch (Throwable $e) {
        $rec = ['line'=>$lineNum,'reason'=>'BAD_TIMESTAMP','value'=>$ts];
        fwrite($rej, json_encode($rec, JSON_UNESCAPED_SLASHES) . PHP_EOL);
        return null;
    }
}

function valid_coords($lat, $lon): bool {
    if (!is_numeric($lat) || !is_numeric($lon)) return false;
    $lat = (float)$lat; $lon = (float)$lon;
    if ($lat < -90.0 || $lat > 90.0) return false;
    if ($lon < -180.0 || $lon > 180.0) return false;
    return true;
}

function detect_header_and_mapping(array $row): array {
    // Returns [hasHeader(bool), map(array field=>index)]
    //$lower = array_map(fn($v) => is_string($v) ? strtolower(trim($v)) : $v, $row);
    $lower = array_map(function($v) {
    return is_string($v) ? strtolower(trim($v)) : $v;
}, $row);
    $map = ['device_id'=>0, 'lat'=>1, 'lon'=>2, 'timestamp'=>3];
    $hasHeader = false;

    $expected = ['device_id','lat','lon','timestamp'];
    $found = 0;
    foreach ($lower as $i => $v) {
        if ($v === 'device_id') { $map['device_id'] = $i; $found++; }
        if ($v === 'lat' || $v === 'latitude') { $map['lat'] = $i; $found++; }
        if ($v === 'lon' || $v === 'lng' || $v === 'longitude') { $map['lon'] = $i; $found++; }
        if ($v === 'timestamp' || $v === 'time' || $v === 'datetime') { $map['timestamp'] = $i; $found++; }
    }
    if ($found >= 3) $hasHeader = true;
    return [$hasHeader, $map];
}

function palette_color(int $idx): string {
    // 20-color pleasant palette, then fall back to HSL golden-angle
    $palette = [
        '#1f77b4','#ff7f0e','#2ca02c','#d62728','#9467bd',
        '#8c564b','#e377c2','#7f7f7f','#bcbd22','#17becf',
        '#393b79','#637939','#8c6d31','#843c39','#7b4173',
        '#5254a3','#6b6ecf','#9c9ede','#8ca252','#bd9e39'
    ];
    if ($idx < count($palette)) return $palette[$idx];
    // Golden angle for hue spacing
    $h = fmod(137.508 * $idx, 360.0);
    $s = 70; $l = 50;
    return hsl_to_hex($h, $s, $l);
}

function hsl_to_hex(float $h, float $s, float $l): string {
    $s /= 100.0; $l /= 100.0;
    $c = (1 - abs(2*$l - 1)) * $s;
    $x = $c * (1 - abs(fmod($h/60.0, 2) - 1));
    $m = $l - $c/2;

    $r = $g = $b = 0.0;
    if ($h < 60) { $r=$c; $g=$x; $b=0; }
    elseif ($h < 120) { $r=$x; $g=$c; $b=0; }
    elseif ($h < 180) { $r=0; $g=$c; $b=$x; }
    elseif ($h < 240) { $r=0; $g=$x; $b=$c; }
    elseif ($h < 300) { $r=$x; $g=0; $b=$c; }
    else { $r=$c; $g=0; $b=$x; }

    $R = (int)round(255*($r+$m));
    $G = (int)round(255*($g+$m));
    $B = (int)round(255*($b+$m));
    return sprintf("#%02x%02x%02x", $R, $G, $B);
}

function main(array $argv): void {
    $args = parse_args($argv);
    $input   = $args['input'];
    $outPath = $args['out'];
    $rejPath = $args['rejects'];
    $gapSec  = (int)$args['gap'] * 60;
    $jumpKm  = (float)$args['jump'];

    // Open rejects log
    $rej = @fopen($rejPath, 'w');
    if ($rej === false) {
        fwrite(STDERR, "Cannot open rejects log for writing: $rejPath" . PHP_EOL);
        exit(1);
    }

    $csv = open_csv($input);

    $byDevice = []; // device_id => list of points: [lat,lon,ts,ts_str,line]
    $lineNum  = 0;
    $headerParsed = false;
    $map = ['device_id'=>0,'lat'=>1,'lon'=>2,'timestamp'=>3];

    foreach ($csv as $row) {
        if ($row === null) continue;
        // Normalize row to at least 4 items
        if (!is_array($row)) continue;
        $lineNum++;

        // Detect header on first non-empty row
        if (!$headerParsed) {
            [$hasHeader, $detectedMap] = detect_header_and_mapping($row);
            $map = $detectedMap;
            if ($hasHeader) { $headerParsed = true; continue; }
            $headerParsed = true; // no header; process this row as data
        }

        // Guard for too-short rows
        if (count($row) < 4) {
            $rec = ['line'=>$lineNum,'reason'=>'TOO_FEW_COLUMNS','row'=>$row];
            fwrite($rej, json_encode($rec, JSON_UNESCAPED_SLASHES) . PHP_EOL);
            continue;
        }

        $deviceId = trim((string)($row[$map['device_id']] ?? ''));
        $lat = $row[$map['lat']] ?? null;
        $lon = $row[$map['lon']] ?? null;
        $tsStr = trim((string)($row[$map['timestamp']] ?? ''));

        if ($deviceId === '' || $tsStr === '' || !valid_coords($lat, $lon)) {
            $rec = ['line'=>$lineNum,'reason'=>'BAD_FIELDS','device_id'=>$deviceId,'lat'=>$lat,'lon'=>$lon,'timestamp'=>$tsStr];
            fwrite($rej, json_encode($rec, JSON_UNESCAPED_SLASHES) . PHP_EOL);
            continue;
        }

        $dt = parse_timestamp($tsStr, $lineNum, $rej);
        if ($dt === null) continue;

        $point = [
            'device_id' => $deviceId,
            'lat' => (float)$lat,
            'lon' => (float)$lon,
            'ts'  => $dt->getTimestamp(),
            'ts_str' => $dt->format(DateTimeInterface::ATOM),
            'line' => $lineNum,
        ];
        $byDevice[$deviceId][] = $point;
    }

    // Sort points per device by timestamp
    foreach ($byDevice as $dev => &$points) {
        usort($points, function($a, $b) {
            if ($a['ts'] === $b['ts']) return 0;
            return ($a['ts'] < $b['ts']) ? -1 : 1;
        });
    }
    unset($points);

    // Build trips per device
    $trips = []; // list of trips
    foreach ($byDevice as $dev => $points) {
        $currTrip = null;
        $prev = null;

        foreach ($points as $p) {
            if ($currTrip === null) {
                $currTrip = [
                    'device_id' => $dev,
                    'points' => [],         // [ [lon, lat], ... ]
                    'timestamps' => [],     // ISO strings aligned to points
                    'start_ts' => $p['ts'],
                    'end_ts'   => $p['ts'],
                    'total_km' => 0.0,
                    'max_speed_kmh' => 0.0,
                    'num_points' => 0,
                ];
                $currTrip['points'][] = [$p['lon'], $p['lat']];
                $currTrip['timestamps'][] = $p['ts_str'];
                $currTrip['num_points'] = 1;
                $prev = $p;
                continue;
            }

            $dt = $p['ts'] - $prev['ts']; // seconds
            $distKm = haversine_km($prev['lat'], $prev['lon'], $p['lat'], $p['lon']);
            $shouldSplit = ($dt > $gapSec) || ($distKm > $jumpKm);

            if ($shouldSplit) {
                // finalize currTrip if it has >= 2 points
                if ($currTrip['num_points'] >= 2) {
                    $trips[] = $currTrip;
                } else {
                    // Drop trips with < 2 points (cannot form LineString)
                    $rec = ['device_id'=>$dev,'reason'=>'TRIP_DROPPED_TOO_FEW_POINTS','start_ts'=>$currTrip['start_ts']];
                    fwrite($rej, json_encode($rec, JSON_UNESCAPED_SLASHES) . PHP_EOL);
                }
                // start new trip with current point
                $currTrip = [
                    'device_id' => $dev,
                    'points' => [[$p['lon'], $p['lat']]],
                    'timestamps' => [$p['ts_str']],
                    'start_ts' => $p['ts'],
                    'end_ts'   => $p['ts'],
                    'total_km' => 0.0,
                    'max_speed_kmh' => 0.0,
                    'num_points' => 1,
                ];
                $prev = $p;
                continue;
            }

            // Add point to current trip
            $currTrip['points'][] = [$p['lon'], $p['lat']];
            $currTrip['timestamps'][] = $p['ts_str'];
            $currTrip['num_points']++;
            $currTrip['end_ts'] = $p['ts'];
            $currTrip['total_km'] += $distKm;

            if ($dt > 0) {
                $speedKmh = $distKm / ($dt / 3600.0);
                if ($speedKmh > $currTrip['max_speed_kmh']) {
                    $currTrip['max_speed_kmh'] = $speedKmh;
                }
            }
            $prev = $p;
        }

        // finalize last trip for this device
        if ($currTrip !== null) {
            if ($currTrip['num_points'] >= 2) {
                $trips[] = $currTrip;
            } else {
                $rec = ['device_id'=>$dev,'reason'=>'TRIP_DROPPED_TOO_FEW_POINTS','start_ts'=>$currTrip['start_ts']];
                fwrite($rej, json_encode($rec, JSON_UNESCAPED_SLASHES) . PHP_EOL);
            }
        }
    }

    // Number trips globally by start time
    usort($trips, function($a, $b){
        if ($a['start_ts'] === $b['start_ts']) return 0;
        return ($a['start_ts'] < $b['start_ts']) ? -1 : 1;
    });

    $features = [];
    $idx = 0;
    foreach ($trips as $t) {
        $idx++;
        $tripId = "trip_" . $idx;
        $durationMin = max(0.0, ($t['end_ts'] - $t['start_ts']) / 60.0);
        $durationHours = $durationMin / 60.0;
        $avgSpeed = ($durationHours > 0.0) ? ($t['total_km'] / $durationHours) : 0.0;

        $color = palette_color($idx - 1);

        $feature = [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'LineString',
                'coordinates' => $t['points'],
            ],
            'properties' => [
                'trip_id' => $tripId,
                'device_id' => $t['device_id'],
                'start_time' => (new DateTimeImmutable('@' . $t['start_ts']))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format(DateTimeInterface::ATOM),
                'end_time'   => (new DateTimeImmutable('@' . $t['end_ts']))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format(DateTimeInterface::ATOM),
                'num_points' => $t['num_points'],
                'total_distance_km' => round($t['total_km'], 3),
                'duration_min'      => round($durationMin, 1),
                'avg_speed_kmh'     => round($avgSpeed, 2),
                'max_speed_kmh'     => round($t['max_speed_kmh'], 2),
                'color' => $color,
            ],
        ];
        $features[] = $feature;
    }

    $geojson = [
        'type' => 'FeatureCollection',
        'features' => $features,
    ];

    $json = json_encode($geojson, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        fwrite(STDERR, "Failed to encode GeoJSON." . PHP_EOL);
        exit(1);
    }

    if (@file_put_contents($outPath, $json) === false) {
        fwrite(STDERR, "Failed to write output: $outPath" . PHP_EOL);
        exit(1);
    }

    // Done
    fclose($rej);

    $summary = [
        'trips' => count($features),
        'output' => $outPath,
        'rejects' => $rejPath,
    ];
    fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_SLASHES) . PHP_EOL);
}

main($argv);
