#!/usr/bin/env bash
# AvianVisitors — install the collage frontend on a BirdNET-Pi host.
#
# Assumes you already ran the BirdNET-Pi installer. Drops the frontend
# into /var/www/avian, mounts it under Caddy at http://birdnet.local/collage,
# and adds the JSON shims that the frontend reads from.
#
# Re-run safely — every step is idempotent.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEB_ROOT="${WEB_ROOT:-/var/www/avian}"
CADDY_SNIPPET="${CADDY_SNIPPET:-/etc/caddy/conf.d/avian.caddy}"
USER_NAME="${USER_NAME:-$USER}"

echo "[avian] repo:   $REPO_ROOT"
echo "[avian] web:    $WEB_ROOT"
echo "[avian] caddy:  $CADDY_SNIPPET"

# 1. Web root with frontend + assets + masks
sudo mkdir -p "$WEB_ROOT"
sudo cp -R "$REPO_ROOT/frontend/." "$WEB_ROOT/"
sudo mkdir -p "$WEB_ROOT/assets"
sudo cp -R "$REPO_ROOT/assets/." "$WEB_ROOT/assets/"
sudo chown -R "$USER_NAME":"$USER_NAME" "$WEB_ROOT"
echo "[avian] frontend deployed → $WEB_ROOT"

# 2. PHP shims (under BirdNET-Pi's web root so PHP-FPM picks them up)
PHP_ROOT="${PHP_ROOT:-/home/$USER_NAME/BirdSongs/Extracted}"
if [ -d "$PHP_ROOT" ]; then
    for f in birdnet-api.php recording.php spectrogram.php cutout.php; do
        if [ -f "$REPO_ROOT/api/$f" ]; then
            sudo cp "$REPO_ROOT/api/$f" "$PHP_ROOT/$f"
            sudo chown "$USER_NAME":"$USER_NAME" "$PHP_ROOT/$f"
            echo "[avian] api/$f → $PHP_ROOT/$f"
        fi
    done
else
    echo "[avian] WARN: $PHP_ROOT not found — copy avian/api/*.php to your BirdNET-Pi PHP root manually."
fi

# 3. Caddy snippet — mounts /collage and proxies /api to PHP
sudo mkdir -p "$(dirname "$CADDY_SNIPPET")"
sudo cp "$REPO_ROOT/caddy/avian.caddy" "$CADDY_SNIPPET"
echo "[avian] caddy snippet → $CADDY_SNIPPET"

# Make sure the main Caddyfile imports the snippet directory
if ! sudo grep -q "import /etc/caddy/conf.d/\*.caddy" /etc/caddy/Caddyfile 2>/dev/null; then
    echo "[avian] adding import to /etc/caddy/Caddyfile"
    echo -e "\nimport /etc/caddy/conf.d/*.caddy" | sudo tee -a /etc/caddy/Caddyfile >/dev/null
fi

# 4. Optional: install Gemini pregen Python deps (none — uses stdlib)
# Just leave a note so users find the regen flow.
echo "[avian] (optional) regenerate illustrations:"
echo "         export GEMINI_API_KEY=your-key"
echo "         python3 $REPO_ROOT/scripts/pregen.py --labels /home/$USER_NAME/BirdNET-Pi/model/labels.txt"

# 5. Reload Caddy
sudo caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
sudo systemctl reload caddy
echo "[avian] caddy reloaded — collage live at http://birdnet.local/collage/"
