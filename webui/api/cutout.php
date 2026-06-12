<?php

declare(strict_types=1);

$sci = trim((string) ($_GET['sci'] ?? ''));
if ($sci === '') {
    http_response_code(400);
    echo 'sci required';
    exit();
}

if (!preg_match('/^[A-Za-z]{2,40}(?:[ ][a-z]{2,40}){1,3}$/', $sci)) {
    http_response_code(400);
    echo 'invalid sci';
    exit();
}

$slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($sci));
$slug = trim((string) $slug, '-');

$pose = (int) ($_GET['pose'] ?? 1);
if ($pose < 1 || $pose > 99)
    $pose = 1;
$poseSuffix = $pose === 1 ? '' : "-{$pose}";

function serve_png(string $path): void
{
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    header('Content-Length: ' . (string) filesize($path));
    readfile($path);
    exit();
}

$bundled = dirname(__DIR__) . "/assets/illustrations/{$slug}{$poseSuffix}.png";
if (is_file($bundled) && filesize($bundled) > 1024) {
    serve_png($bundled);
}

if ($pose !== 1) {
    $fallback = dirname(__DIR__) . "/assets/illustrations/{$slug}.png";
    if (is_file($fallback) && filesize($fallback) > 1024) {
        serve_png($fallback);
    }
}

$cutout = dirname(__DIR__) . "/assets/cutouts/{$slug}.png";
if (is_file($cutout) && filesize($cutout) > 1024) {
    serve_png($cutout);
}

$cacheDir = dirname(__DIR__, 3) . '/BirdSongs/Extracted/cutouts';
$cachePath = "{$cacheDir}/{$slug}.png";
if (is_file($cachePath) && filesize($cachePath) > 1024) {
    serve_png($cachePath);
}

$label = trim((string) ($_GET['com'] ?? ''));
if ($label === '') {
    $label = $sci;
}
$label = htmlspecialchars($label, ENT_QUOTES);

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Cutout-Status: not-generated');
echo <<<SVG
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 320" width="320" height="320" role="img" aria-label="{$label}: illustration not generated">
      <rect x="10" y="10" width="300" height="300" rx="26" fill="#efe7d8" stroke="#d8ccb4" stroke-width="2"/>
      <text x="160" y="150" text-anchor="middle" font-family="ui-sans-serif,system-ui,sans-serif" font-size="56" fill="#cdbfa3">?</text>
      <text x="160" y="210" text-anchor="middle" font-family="ui-sans-serif,system-ui,sans-serif" font-size="19" font-style="italic" fill="#6b5e44">{$label}</text>
      <text x="160" y="244" text-anchor="middle" font-family="ui-monospace,monospace" font-size="12" fill="#9a8c6e">illustration not generated</text>
    </svg>
    SVG;
exit();
