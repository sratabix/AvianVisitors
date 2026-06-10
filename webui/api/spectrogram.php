<?php

declare(strict_types=1);

$sci = trim((string) ($_GET['sci'] ?? ''));
$file = trim((string) ($_GET['file'] ?? ''));

if ($sci === '' && $file === '') {
    http_response_code(400);
    echo 'sci or file required';
    exit();
}

if ($sci !== '' && !preg_match('/^[A-Za-z]{2,40}(?:[ ][a-z]{2,40}){1,3}$/', $sci)) {
    http_response_code(400);
    echo 'invalid sci';
    exit();
}

$BY_DATE = dirname(__DIR__, 3) . '/BirdSongs/Extracted/By_Date';

if ($file !== '') {
    if (!preg_match("/^[A-Za-z0-9_.:'-]+\\.(mp3|png)$/", $file)) {
        http_response_code(400);
        echo 'invalid file name';
        exit();
    }

    if (substr($file, -4) === '.png') {
        $png = $file;
    } else {
        $png = $file . '.png';
    }
    $date = null;
    if (preg_match('/(\d{4}-\d{2}-\d{2})/', $png, $m))
        $date = $m[1];
    $candidates = [];
    if ($date) {
        $dayDir = "{$BY_DATE}/{$date}";
        if (is_dir($dayDir)) {
            foreach (scandir($dayDir) as $sub) {
                if ($sub[0] === '.')
                    continue;
                $p = "{$dayDir}/{$sub}/{$png}";
                if (is_file($p)) {
                    $candidates[] = $p;
                    break;
                }
            }
        }
    }
    if (!$candidates) {
        if (is_dir($BY_DATE)) {
            foreach (scandir($BY_DATE) as $d) {
                if ($d[0] === '.')
                    continue;
                $dayDir = "{$BY_DATE}/{$d}";
                if (!is_dir($dayDir))
                    continue;
                foreach (scandir($dayDir) as $sub) {
                    if ($sub[0] === '.')
                        continue;
                    $p = "{$dayDir}/{$sub}/{$png}";
                    if (is_file($p)) {
                        $candidates[] = $p;
                        break 2;
                    }
                }
            }
        }
    }
    if (!$candidates || filesize($candidates[0]) < 64) {
        http_response_code(404);
        echo 'spectrogram not found';
        exit();
    }
    $path = $candidates[0];
    header('Content-Type: image/png');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: public, max-age=86400');
    readfile($path);
    exit();
}

function resolve_common(string $sci): ?string
{
    $f = dirname(__DIR__, 3) . '/BirdNET-Pi/birdnet/birds.json';
    if (is_readable($f)) {
        $list = json_decode((string) file_get_contents($f), true);
        if (is_array($list)) {
            foreach ($list as $row) {
                if (!is_array($row))
                    continue;
                $rowSci = $row['sci'] ?? $row['scientific'] ?? $row['scientificName'] ?? '';
                $rowCom = $row['com'] ?? $row['common'] ?? $row['commonName'] ?? '';
                if (strcasecmp(trim((string) $rowSci), $sci) === 0 && $rowCom) {
                    return str_replace(' ', '_', (string) $rowCom);
                }
            }
        }
    }
    $labels = dirname(__DIR__, 3) . '/BirdNET-Pi/birdnet/model/labels.txt';
    if (is_readable($labels)) {
        foreach (file($labels, FILE_IGNORE_NEW_LINES) as $line) {
            if (strpos($line, '_') === false) {
                continue;
            }

            [$s, $c] = explode('_', $line, 2);
            if (strcasecmp(trim($s), $sci) === 0) {
                return str_replace(' ', '_', trim($c));
            }
        }
    }
    return null;
}

$common = resolve_common($sci) ?? str_replace(' ', '_', $sci);

function newest_spectrogram(string $rootDir, string $common): ?string
{
    if (!is_dir($rootDir))
        return null;

    $norm = function (string $s): string {
        return preg_replace('/[^a-z0-9]/', '', strtolower($s));
    };
    $want = $norm($common);
    $dates = scandir($rootDir, SCANDIR_SORT_DESCENDING);
    if (!$dates)
        return null;
    foreach ($dates as $date) {
        if ($date[0] === '.')
            continue;
        $dayDir = "{$rootDir}/{$date}";
        if (!is_dir($dayDir))
            continue;
        $speciesDir = null;
        foreach (scandir($dayDir) as $sub) {
            if ($sub[0] === '.' || !is_dir("{$dayDir}/{$sub}"))
                continue;
            if ($norm($sub) === $want) {
                $speciesDir = "{$dayDir}/{$sub}";
                break;
            }
        }
        if ($speciesDir === null)
            continue;
        $files = scandir($speciesDir, SCANDIR_SORT_DESCENDING);
        if (!$files)
            continue;
        foreach ($files as $f) {
            if (substr($f, -4) === '.png' && @filesize("{$speciesDir}/{$f}") >= 64) {
                return "{$speciesDir}/{$f}";
            }
        }
    }
    return null;
}

$path = newest_spectrogram($BY_DATE, $common);
if ($path === null || !is_file($path) || filesize($path) < 64) {
    http_response_code(404);
    echo 'no spectrogram for ' . htmlspecialchars($sci);
    exit();
}

header('Content-Type: image/png');
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=60');
readfile($path);
