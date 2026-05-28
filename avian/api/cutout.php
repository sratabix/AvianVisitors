<?php
// /home/monalisa/BirdSongs/Extracted/cutout.php — dynamic background-removed
// bird-photo facade. Called by the bird.onethreenine.net Cloudflare Worker
// (which fronts /api/img?sci=<name>).
//
// Lives in the Caddy file-server root so the BirdNET-Pi auto-update doesn't
// touch it. Each new species hits this once: download Wikipedia/Macaulay
// image → rembg via /usr/local/bin/rembg-cli → cache transparent PNG to
// disk under cutouts/. Subsequent requests are instant readfile() from cache.
//
// Auth: the Caddy site block 403s anything missing X-BirdNET-Proxy-Token at
// the top, so this script inherits the Worker-only access guarantee.
//
// Pre-reqs (run once via /install/rembg-setup.sh):
//   - /home/monalisa/rembg-env/bin/rembg            (Python venv)
//   - /usr/local/bin/rembg-cli                      (wrapper)
//   - /home/monalisa/BirdSongs/Extracted/cutouts/   (cache dir, monalisa-owned)

declare(strict_types=1);

$sci = trim((string)($_GET['sci'] ?? ''));
if ($sci === '') {
    http_response_code(400);
    echo 'sci required';
    exit;
}

// Slugify scientific name for the cache filename.
$slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($sci));
$slug = trim((string)$slug, '-');
$cacheDir = '/home/monalisa/BirdSongs/Extracted/cutouts';
$cachePath = "$cacheDir/$slug.png";

function serve_png(string $path): void {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=2592000');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// Cache hit — short-circuit.
if (is_file($cachePath) && filesize($cachePath) > 1024) {
    serve_png($cachePath);
}

// Make sure the cache dir exists (first run).
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);

// Resolve a source image URL. Wikipedia summary first (free, fast,
// no auth, has a clean originalimage for most birds).
$ctx = stream_context_create([
    'http' => [
        'header'  => "User-Agent: apartment-birds/1.0 (twarner491@gmail.com)\r\n",
        'timeout' => 12,
    ],
]);
$wpUrl = 'https://en.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode($sci);
$wpJson = @file_get_contents($wpUrl, false, $ctx);
$srcUrl = null;
if ($wpJson !== false) {
    $j = json_decode($wpJson, true);
    $srcUrl = $j['originalimage']['source'] ?? $j['thumbnail']['source'] ?? null;
}
if (!$srcUrl) {
    http_response_code(404);
    echo 'no upstream image for ' . htmlspecialchars($sci);
    exit;
}

// Download the source image.
$imgBytes = @file_get_contents($srcUrl, false, $ctx);
if (!$imgBytes || strlen($imgBytes) < 1024) {
    http_response_code(503);
    echo 'failed to fetch source image';
    exit;
}

// Run rembg via the wrapper. We use temp files because rembg's CLI prefers
// real paths. u2netp is the lightweight model (~50MB peak RAM) — important
// on the Pi 3B+ (1GB total RAM). Call with --post-process-mask to clean
// up edges.
$tmpIn  = tempnam('/tmp', 'rembg-in-')  . '.jpg';
$tmpOut = tempnam('/tmp', 'rembg-out-') . '.png';
file_put_contents($tmpIn, $imgBytes);

$cmd = sprintf(
    '/usr/local/bin/rembg-cli i -m u2netp -ppm %s %s 2>&1',
    escapeshellarg($tmpIn),
    escapeshellarg($tmpOut)
);
$out = shell_exec($cmd);
@unlink($tmpIn);

if (!is_file($tmpOut) || filesize($tmpOut) < 1024) {
    @unlink($tmpOut);
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "rembg failed:\n" . ($out ?? '(no output)');
    exit;
}

// 1. Alpha-crop to bounding box so each PNG == the bird's actual shape
//    (no transparent padding around it). Lets the layout pack tiles tight.
// 2. Resize down to a max edge of 800px so the cache stays small.
$im = @imagecreatefrompng($tmpOut);
if ($im !== false) {
    $cropped = @imagecropauto($im, IMG_CROP_TRANSPARENT);
    if ($cropped !== false) {
        imagedestroy($im);
        $im = $cropped;
    }
    $w = imagesx($im); $h = imagesy($im);
    $max = 800;
    if ($w > $max || $h > $max) {
        $scale = $max / max($w, $h);
        $nw = (int)($w * $scale); $nh = (int)($h * $scale);
        $resized = imagecreatetruecolor($nw, $nh);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($im);
        $im = $resized;
    }
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagepng($im, $tmpOut, 6);
    imagedestroy($im);
}

// Cache + serve.
copy($tmpOut, $cachePath);
@unlink($tmpOut);
serve_png($cachePath);
