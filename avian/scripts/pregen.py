#!/usr/bin/env python3
"""AvianVisitors — pre-generate kachō-e illustrations for a region.

Reads a species list (from BirdNET-Pi's labels.txt, eBird, or stdin),
generates an illustration for each via the Gemini 2.5 Flash Image API,
and saves PNGs into avian/assets/illustrations/.

Each species gets two poses: <slug>.png (perched) and <slug>-2.png
(flight). The prompt template lives at avian/scripts/prompt.template.md
— edit it to change the visual style.

Usage:
    # Generate the full BirdNET-Pi label set:
    python3 pregen.py --labels /home/$USER/BirdNET-Pi/model/labels.txt

    # Generate only species observed in eBird region US-CA:
    python3 pregen.py --labels labels.txt --ebird-region US-CA --ebird-key YOUR_KEY

    # Re-render a single species (useful when you tweak the prompt):
    python3 pregen.py --species "Calypte anna|Anna's Hummingbird" --force

    # Re-render everything (after a prompt change you actually want applied):
    python3 pregen.py --labels labels.txt --force

Set GEMINI_API_KEY in the environment or pass --gemini-key.
"""
from __future__ import annotations
import argparse
import base64
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

GEMINI_URL = (
    "https://generativelanguage.googleapis.com/v1beta/models/"
    "gemini-2.5-flash-image:generateContent?key={key}"
)
POSES = {1: "perched", 2: "in flight with wings spread"}


def slugify(sci: str) -> str:
    return re.sub(r"[^a-z0-9]+", "-", sci.lower()).strip("-")


def load_prompt(path: Path) -> str:
    """Pull the prompt body from the markdown template — everything
    after the `## Prompt` heading, stripped."""
    text = path.read_text()
    m = re.search(r"##\s*Prompt\s*\n(.+)$", text, flags=re.DOTALL)
    return (m.group(1) if m else text).strip()


def parse_labels(p: Path) -> list[tuple[str, str]]:
    """BirdNET-Pi labels.txt format: `Sci name_Common Name` per line."""
    out = []
    for line in p.read_text().splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        # Split on first underscore (BirdNET format)
        if "_" in line:
            sci, com = line.split("_", 1)
        elif "," in line:
            sci, com = (s.strip() for s in line.split(",", 1))
        else:
            continue
        sci = sci.strip()
        com = com.strip()
        if sci and com:
            out.append((sci, com))
    return out


def ebird_filter(species: list[tuple[str, str]], region: str, key: str) -> list[tuple[str, str]]:
    """Intersect a label set with the eBird observed-species list for a
    region. Region codes look like US-CA (state) or US-CA-085 (county).
    See https://documenter.getpostman.com/view/664302/S1ENwy59"""
    url = f"https://api.ebird.org/v2/product/spplist/{region}"
    req = urllib.request.Request(url, headers={"X-eBirdApiToken": key})
    with urllib.request.urlopen(req, timeout=30) as r:
        ebird_codes = set(json.loads(r.read()))
    # eBird returns 6-letter species codes — we need sci names. Use the
    # taxonomy endpoint to map.
    tax_url = f"https://api.ebird.org/v2/ref/taxonomy/ebird?fmt=json"
    req2 = urllib.request.Request(tax_url, headers={"X-eBirdApiToken": key})
    with urllib.request.urlopen(req2, timeout=60) as r:
        taxonomy = json.loads(r.read())
    code_to_sci = {t["speciesCode"]: t["sciName"] for t in taxonomy}
    allowed_sci = {code_to_sci[c] for c in ebird_codes if c in code_to_sci}
    return [(s, c) for s, c in species if s in allowed_sci]


def gen_one(api_key: str, prompt: str, sci: str, com: str, pose: int) -> bytes:
    """Single Gemini call. Returns raw PNG bytes."""
    body = prompt.replace("{sci_name}", sci).replace("{com_name}", com).replace(
        "{pose}", POSES[pose]
    )
    payload = {
        "contents": [{"parts": [{"text": body}]}],
        "generationConfig": {"responseModalities": ["IMAGE"]},
    }
    req = urllib.request.Request(
        GEMINI_URL.format(key=urllib.parse.quote(api_key)),
        data=json.dumps(payload).encode(),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=120) as r:
        resp = json.loads(r.read())
    for cand in resp.get("candidates", []):
        for part in cand.get("content", {}).get("parts", []):
            inline = part.get("inlineData") or part.get("inline_data")
            if inline and inline.get("data"):
                return base64.b64decode(inline["data"])
    raise RuntimeError(f"no image in response: {json.dumps(resp)[:300]}")


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    src = ap.add_mutually_exclusive_group(required=True)
    src.add_argument("--labels", type=Path, help="BirdNET-Pi labels.txt path")
    src.add_argument("--species", action="append", default=[],
                     help="Manual species in format 'Sci name|Common Name' (repeatable)")
    src.add_argument("--stdin", action="store_true", help="Read species from stdin (one per line, same format)")
    ap.add_argument("--ebird-region", help="eBird region code (e.g. US-CA, US-CA-085) to filter labels")
    ap.add_argument("--ebird-key", help="eBird API key (or set EBIRD_API_KEY)")
    ap.add_argument("--gemini-key", help="Gemini API key (or set GEMINI_API_KEY)")
    ap.add_argument("--out", type=Path, default=Path(__file__).resolve().parents[1] / "assets" / "illustrations",
                    help="Output directory (default: avian/assets/illustrations/)")
    ap.add_argument("--prompt", type=Path, default=Path(__file__).resolve().parent / "prompt.template.md",
                    help="Prompt template path")
    ap.add_argument("--poses", nargs="+", type=int, default=[1, 2],
                    help="Which poses to render (1=perched, 2=flight). Default: both.")
    ap.add_argument("--force", action="store_true", help="Re-render even if file exists")
    ap.add_argument("--sleep", type=float, default=1.0, help="Seconds between API calls (rate limit)")
    ap.add_argument("--limit", type=int, default=0, help="Cap species count for testing")
    args = ap.parse_args()

    gemini_key = args.gemini_key or os.environ.get("GEMINI_API_KEY", "")
    if not gemini_key:
        print("error: GEMINI_API_KEY required (--gemini-key or env)", file=sys.stderr)
        return 2

    # Build species list
    if args.labels:
        species = parse_labels(args.labels)
    elif args.stdin:
        species = parse_labels_lines(sys.stdin.read().splitlines())
    else:
        species = []
        for s in args.species:
            if "|" in s:
                sci, com = s.split("|", 1)
                species.append((sci.strip(), com.strip()))
    if not species:
        print("error: no species resolved", file=sys.stderr)
        return 2

    if args.ebird_region:
        ek = args.ebird_key or os.environ.get("EBIRD_API_KEY", "")
        if not ek:
            print("error: --ebird-region requires --ebird-key or EBIRD_API_KEY", file=sys.stderr)
            return 2
        print(f"[ebird] filtering {len(species)} species against region {args.ebird_region}…")
        species = ebird_filter(species, args.ebird_region, ek)

    if args.limit:
        species = species[:args.limit]

    prompt = load_prompt(args.prompt)
    args.out.mkdir(parents=True, exist_ok=True)

    total = len(species) * len(args.poses)
    print(f"generating {total} illustrations into {args.out}/")

    done = skipped = failed = 0
    for sci, com in species:
        slug = slugify(sci)
        for pose in args.poses:
            fname = f"{slug}.png" if pose == 1 else f"{slug}-{pose}.png"
            path = args.out / fname
            if path.exists() and not args.force:
                skipped += 1
                continue
            try:
                data = gen_one(gemini_key, prompt, sci, com, pose)
                path.write_bytes(data)
                done += 1
                print(f"  ✓ {fname} ({len(data)//1024} KB)")
            except (urllib.error.HTTPError, urllib.error.URLError, RuntimeError) as e:
                failed += 1
                print(f"  ✗ {fname}: {e}", file=sys.stderr)
            time.sleep(args.sleep)

    print(f"\ngenerated {done} · skipped {skipped} · failed {failed}")
    return 0 if failed == 0 else 1


def parse_labels_lines(lines: list[str]) -> list[tuple[str, str]]:
    out = []
    for line in lines:
        line = line.strip()
        if not line:
            continue
        if "|" in line:
            sci, com = line.split("|", 1)
        elif "_" in line:
            sci, com = line.split("_", 1)
        elif "," in line:
            sci, com = line.split(",", 1)
        else:
            continue
        out.append((sci.strip(), com.strip()))
    return out


if __name__ == "__main__":
    sys.exit(main())
