<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (getenv('AV_REQUIRE_AUTH') === '1' && empty($_SERVER['HTTP_AUTHORIZATION'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit();
}

$BIRDNETPI_DIR = dirname(__DIR__, 2);
$CONF_PATH = "{$BIRDNETPI_DIR}/birdnet.conf";

$ALLOWED = [
    'CONFIDENCE' => ['type' => 'float', 'min' => 0.05, 'max' => 0.99, 'restart' => true],
    'SENSITIVITY' => ['type' => 'float', 'min' => 0.5, 'max' => 1.5, 'restart' => true],
    'SF_THRESH' => ['type' => 'float', 'min' => 0.0, 'max' => 1.0, 'restart' => true],
    'OVERLAP' => ['type' => 'float', 'min' => 0.0, 'max' => 2.5, 'restart' => true],
    'MAX_FILES_SPECIES' => ['type' => 'int', 'min' => 0, 'max' => 100_000],
    'FULL_DISK' => ['type' => 'enum', 'values' => ['purge', 'keep']],
    'PURGE_THRESHOLD' => ['type' => 'int', 'min' => 50, 'max' => 99],
    'LATITUDE' => ['type' => 'float', 'min' => -90, 'max' => 90, 'restart' => true],
    'LONGITUDE' => ['type' => 'float', 'min' => -180, 'max' => 180, 'restart' => true],
    'SITE_NAME' => ['type' => 'string', 'maxlen' => 60],
];

function read_conf(string $path): array
{
    if (!is_readable($path))
        return [];
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        if (!$line || $line[0] === '#')
            continue;
        if (preg_match('/^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$/i', $line, $m)) {
            $val = trim($m[2]);
            if (strlen($val) >= 2 && $val[0] === '"' && substr($val, -1) === '"') {
                $val = substr($val, 1, -1);
            }
            $out[$m[1]] = $val;
        }
    }
    return $out;
}

function write_conf(string $path, array $updates): bool
{
    if (!is_writable($path) && !is_writable(dirname($path)))
        return false;
    $lines = is_readable($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
    $seen = [];
    foreach ($lines as $i => $line) {
        if (!preg_match('/^\s*([A-Z_][A-Z0-9_]*)\s*=/i', $line, $m)) {
            continue;
        }

        $k = $m[1];
        if (array_key_exists($k, $updates)) {
            $lines[$i] = $k . '=' . quote_val($updates[$k]);
            $seen[$k] = true;
        }
    }
    foreach ($updates as $k => $v) {
        if (!empty($seen[$k])) {
            continue;
        }

        $lines[] = $k . '=' . quote_val($v);
    }
    $tmp = $path . '.tmp.' . getmypid();
    if (file_put_contents($tmp, implode("\n", $lines) . "\n") === false)
        return false;
    return rename($tmp, $path);
}

function quote_val($v): string
{
    $s = (string) $v;

    if ($s === '' || preg_match('/[^A-Za-z0-9._\/+-]/', $s)) {
        return '"' . addcslashes($s, "\\\"\$`") . '"';
    }
    return $s;
}

function safe_string_value(string $v): bool
{
    return (bool) preg_match("/^[A-Za-z0-9 _.,'-]*$/u", $v);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $conf = read_conf($CONF_PATH);
    $out = [];
    foreach ($ALLOWED as $k => $spec) {
        if (!array_key_exists($k, $conf))
            continue;
        $v = $conf[$k];
        if ($spec['type'] === 'float')
            $v = (float) $v;
        elseif ($spec['type'] === 'int')
            $v = (int) $v;
        $out[$k] = $v;
    }
    echo
        json_encode([
            'values' => $out,
            'meta' => $ALLOWED,
            'preserve' => (int) ($conf['MAX_FILES_SPECIES'] ?? 0) >= 10_000,
        ])
    ;
    exit();
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode((string) $raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'bad json']);
        exit();
    }
    $updates = [];
    $errors = [];
    foreach ($body as $k => $v) {
        if ($k === 'preserve')
            continue;
        if (!isset($ALLOWED[$k])) {
            $errors[$k] = 'unknown';
            continue;
        }
        $spec = $ALLOWED[$k];
        if ($spec['type'] === 'float') {
            $v = (float) $v;
            if ($v < ($spec['min'] ?? -INF) || $v > ($spec['max'] ?? INF)) {
                $errors[$k] = 'out of range';
                continue;
            }
        } elseif ($spec['type'] === 'int') {
            $v = (int) $v;
            if ($v < ($spec['min'] ?? -PHP_INT_MAX) || $v > ($spec['max'] ?? PHP_INT_MAX)) {
                $errors[$k] = 'out of range';
                continue;
            }
        } elseif ($spec['type'] === 'enum') {
            if (!in_array($v, $spec['values'], true)) {
                $errors[$k] = 'invalid value';
                continue;
            }
        } elseif ($spec['type'] === 'string') {
            $v = (string) $v;
            if (strlen($v) > ($spec['maxlen'] ?? 200)) {
                $errors[$k] = 'too long';
                continue;
            }

            if (!safe_string_value($v)) {
                $errors[$k] = 'invalid characters';
                continue;
            }
        }
        $updates[$k] = $v;
    }
    if ($errors) {
        http_response_code(400);
        echo json_encode(['error' => 'validation', 'fields' => $errors]);
        exit();
    }

    if (isset($body['preserve'])) {
        $updates['MAX_FILES_SPECIES'] = $body['preserve'] ? 99_999 : 50;
    }

    if (!write_conf($CONF_PATH, $updates)) {
        http_response_code(500);
        echo json_encode(['error' => 'write failed (check perms on birdnet.conf)']);
        exit();
    }

    $needsRestart = false;
    foreach (array_keys($updates) as $k) {
        if (empty($ALLOWED[$k]['restart'])) {
            continue;
        }

        $needsRestart = true;
        break;
    }
    $restarted = [];
    if ($needsRestart) {
        foreach (['analysis', 'recording'] as $svc) {
            $rc = 0;
            $out = [];
            exec(
                '/usr/bin/supervisorctl -c /etc/supervisor/supervisord.conf restart ' . escapeshellarg($svc) . ' 2>&1',
                $out,
                $rc,
            );
            $restarted[$svc] = $rc === 0;
        }
    }
    echo json_encode(['ok' => true, 'updates' => $updates, 'restarted' => $restarted]);
    exit();
}

http_response_code(405);
echo json_encode(['error' => 'method not allowed']);
