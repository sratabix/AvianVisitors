<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (getenv('AV_REQUIRE_AUTH') === '1' && empty($_SERVER['HTTP_AUTHORIZATION'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit();
}

$action = $_GET['action'] ?? 'diag';

$BIRDNETPI_DIR = dirname(__DIR__, 2);
$BIRDSONGS_DIR = dirname(__DIR__, 3) . '/BirdSongs';
$DB_PATH = "{$BIRDNETPI_DIR}/birdnet/birds.db";
$CONF_PATH = "{$BIRDNETPI_DIR}/birdnet.conf";
$STREAM_DIR = "{$BIRDSONGS_DIR}/StreamData";

function shellout(string $cmd): string
{
    $rc = 0;
    $out = [];
    exec($cmd . ' 2>&1', $out, $rc);
    return implode("\n", $out);
}

function read_uptime(): array
{
    $up = @file_get_contents('/proc/uptime');
    $sec = $up ? (float) explode(' ', trim($up))[0] : 0;
    return [
        'seconds' => $sec,
        'pretty' => human_duration((int) $sec),
        'load' => sys_getloadavg(),
        'now' => date('c'),
    ];
}

function human_duration(int $s): string
{
    $d = intdiv($s, 86_400);
    $s -= $d * 86_400;
    $h = intdiv($s, 3600);
    $s -= $h * 3600;
    $m = intdiv($s, 60);
    $parts = [];
    if ($d)
        $parts[] = $d . 'd';
    if ($h)
        $parts[] = $h . 'h';
    if ($m && !$d)
        $parts[] = $m . 'm';
    return $parts ? implode(' ', $parts) : '<1m';
}

function read_mem(): array
{
    $info = @file_get_contents('/proc/meminfo') ?: '';
    preg_match('/MemTotal:\s+(\d+)/', $info, $t);
    preg_match('/MemAvailable:\s+(\d+)/', $info, $a);
    $tot = isset($t[1]) ? (int) $t[1] * 1024 : 0;
    $avail = isset($a[1]) ? (int) $a[1] * 1024 : 0;
    $used = $tot - $avail;
    return [
        'total_bytes' => $tot,
        'used_bytes' => $used,
        'used_pct' => $tot ? round(($used / $tot) * 100, 1) : 0,
    ];
}

function read_disk(string $path): array
{
    if (!is_dir($path))
        return ['path' => $path, 'error' => 'not found'];
    $tot = @disk_total_space($path);
    $free = @disk_free_space($path);
    if (!$tot)
        return ['path' => $path, 'error' => 'stat failed'];
    return [
        'path' => $path,
        'total_bytes' => (int) $tot,
        'free_bytes' => (int) $free,
        'used_pct' => round((($tot - $free) / $tot) * 100, 1),
    ];
}

function read_temp(): ?float
{
    $f = '/sys/class/thermal/thermal_zone0/temp';
    if (!is_readable($f))
        return null;
    $raw = trim((string) @file_get_contents($f));
    return $raw === '' ? null : round((int) $raw / 1000, 1);
}

function read_audio(): array
{
    $raw = @file_get_contents('/proc/asound/cards') ?: '';
    $lines = array_values(array_filter(array_map('rtrim', explode("\n", $raw)), 'strlen'));
    $cards = [];
    for ($i = 0; $i < count($lines); $i += 2) {
        $head = trim($lines[$i]);
        $detail = isset($lines[$i + 1]) ? trim($lines[$i + 1]) : '';
        $cards[] = $detail !== '' ? "{$head} - {$detail}" : $head;
    }
    $usb = shellout('lsusb');
    return [
        'arecord_l' => $cards,
        'usb' => array_values(array_filter(explode("\n", $usb), function ($l) {
            return (
                $l !== ''
                && (
                    stripos($l, 'audio') !== false
                    || stripos($l, 'microphone') !== false
                    || stripos($l, 'mic') !== false
                )
            );
        })),
    ];
}

function read_streamdata(string $dir): array
{
    if (!is_dir($dir))
        return ['exists' => false];
    $files = @scandir($dir, SCANDIR_SORT_DESCENDING) ?: [];
    $wav = array_values(array_filter($files, function ($f) {
        return $f !== '.' && $f !== '..' && preg_match('/\.(wav|mp3|raw)$/i', $f);
    }));
    $newest_age = null;
    if (count($wav) > 0) {
        $newest_age = time() - (int) @filemtime("{$dir}/" . $wav[0]);
    }
    return [
        'exists' => true,
        'file_count' => count($wav),
        'newest_age_s' => $newest_age,
        'newest_name' => $wav[0] ?? null,
    ];
}

function read_db_age(string $db): array
{
    if (!is_file($db))
        return ['exists' => false];
    return [
        'exists' => true,
        'size_bytes' => (int) filesize($db),
        'modified_s' => time() - (int) filemtime($db),
        'mtime' => date('c', (int) filemtime($db)),
    ];
}

function read_conf_summary(string $p): array
{
    if (!is_readable($p))
        return ['readable' => false];
    $keys = [
        'CONFIDENCE',
        'SENSITIVITY',
        'OVERLAP',
        'REC_CARD',
        'LATITUDE',
        'LONGITUDE',
        'MODEL',
        'SITE_NAME',
        'RTSP_STREAM',
    ];
    $vals = [];
    foreach (file($p, FILE_IGNORE_NEW_LINES) as $line) {
        if (!$line || $line[0] === '#')
            continue;
        if (preg_match('/^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$/i', $line, $m)) {
            if (in_array($m[1], $keys, true)) {
                $v = trim($m[2]);
                if (strlen($v) >= 2 && $v[0] === '"' && substr($v, -1) === '"')
                    $v = substr($v, 1, -1);
                $vals[$m[1]] = $v;
            }
        }
    }
    return ['readable' => true, 'values' => $vals];
}

const ALLOWED_UNITS = [
    'recording',
    'analysis',
    'charts',
    'stats',
    'caddy',
    'php-fpm',
    'icecast',
    'livestream',
];

const SUPERVISORCTL = '/usr/bin/supervisorctl -c /etc/supervisor/supervisord.conf';

const LOGS_DIR = '/data/logs';

function services_status(): array
{
    $raw = shellout(SUPERVISORCTL . ' status');
    $out = [];
    foreach (explode("\n", $raw) as $line) {
        if (!preg_match('/^(\S+)\s+(\S+)\s*(.*)$/', $line, $m)) {
            continue;
        }
        if (!in_array($m[1], ALLOWED_UNITS, true)) {
            continue;
        }
        $state = strtoupper($m[2]);
        $out[$m[1]] = [
            'active' => $state === 'RUNNING' ? 'active' : strtolower($state),
            'enabled' => 'managed',
            'since' => $m[3] !== '' ? $m[3] : null,
        ];
    }
    return $out;
}

function logs_for(string $unit, int $lines): array
{
    if (!in_array($unit, ALLOWED_UNITS, true)) {
        http_response_code(400);
        return ['error' => 'unit not allowed', 'allowed' => ALLOWED_UNITS];
    }
    $lines = max(10, min(500, $lines));
    $file = LOGS_DIR . '/' . $unit . '.log';
    $text = is_readable($file)
        ? shellout('tail -n ' . $lines . ' ' . escapeshellarg($file))
        : '(no log yet at ' . $file . ')';
    return [
        'unit' => $unit,
        'lines' => $lines,
        'text' => $text,
    ];
}

switch ($action) {
    case 'system':
        {
            echo
                json_encode([
                    'uptime' => read_uptime(),
                    'mem' => read_mem(),
                    'disk_root' => read_disk('/'),
                    'disk_birds' => read_disk($BIRDSONGS_DIR),
                    'temp_c' => read_temp(),
                    'audio' => read_audio(),
                    'stream_data' => read_streamdata($STREAM_DIR),
                    'birds_db' => read_db_age($DB_PATH),
                    'conf' => read_conf_summary($CONF_PATH),
                    'hostname' => trim(shellout('hostname')),
                    'kernel' => trim(shellout('uname -r')),
                    'as_of' => date('c'),
                ])
            ;
            break;
        }

    case 'services':
        {
            echo json_encode(['services' => services_status(), 'as_of' => date('c')]);
            break;
        }

    case 'logs':
        {
            $unit = (string) ($_GET['unit'] ?? 'birdnet_recording');
            $lines = (int) ($_GET['lines'] ?? 60);
            echo json_encode(logs_for($unit, $lines));
            break;
        }

    case 'restart':
        {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'POST required']);
                break;
            }
            $unit = (string) ($_GET['unit'] ?? '');
            if (!in_array($unit, ALLOWED_UNITS, true)) {
                http_response_code(400);
                echo json_encode(['error' => 'unit not allowed', 'allowed' => ALLOWED_UNITS]);
                break;
            }

            $rc = 0;
            $out = [];
            exec(SUPERVISORCTL . ' restart ' . escapeshellarg($unit) . ' 2>&1', $out, $rc);
            echo
                json_encode([
                    'unit' => $unit,
                    'ok' => $rc === 0,
                    'rc' => $rc,
                    'out' => implode("\n", $out),
                ])
            ;
            break;
        }

    case 'diag':
        {
            $svc = services_status();
            $key_units = ['recording', 'analysis'];
            $recent_logs = [];
            foreach ($key_units as $u) {
                $f = LOGS_DIR . '/' . $u . '.log';
                $recent_logs[$u] = is_readable($f) ? trim(shellout('tail -n 20 ' . escapeshellarg($f))) : '';
            }
            echo
                json_encode([
                    'system' => [
                        'uptime' => read_uptime(),
                        'mem' => read_mem(),
                        'disk_root' => read_disk('/'),
                        'disk_birds' => read_disk($BIRDSONGS_DIR),
                        'temp_c' => read_temp(),
                        'audio' => read_audio(),
                        'stream_data' => read_streamdata($STREAM_DIR),
                        'birds_db' => read_db_age($DB_PATH),
                        'conf' => read_conf_summary($CONF_PATH),
                        'hostname' => trim(shellout('hostname')),
                        'kernel' => trim(shellout('uname -r')),
                    ],
                    'services' => $svc,
                    'recent_logs' => $recent_logs,
                    'as_of' => date('c'),
                ])
            ;
            break;
        }

    default:
        http_response_code(404);
        echo json_encode(['error' => 'unknown action']);
}
