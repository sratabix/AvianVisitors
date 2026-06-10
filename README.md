# Avian Visitors

A live bird collage from a window microphone.
A small mic listens, BirdNET figures out which bird is calling, and each species shows up as an illustration in the collage, sized by how often it's been heard.

It all runs in one Docker container on a Raspberry Pi (or any Debian box).
The container records the audio, runs the detection, and serves the web page.

## Fork history

This is the far end of a fork chain:

- [BirdNET-Lite](https://github.com/birdnet-team/BirdNET-Lite), the original from the BirdNET team at the Cornell Lab of Ornithology. It is the TFLite sound recognition model and analyzer that identifies which of 6,000+ species is calling. Everything else is built on top of this.
- [BirdNET-Pi](https://github.com/mcguirepr89/BirdNET-Pi) by Patrick McGuire, which turned the model into a full realtime system for the Raspberry Pi. It adds 24/7 recording, automatic clip extraction, a SQLite database, a web interface, a live audio stream and spectrogram, notifications, and BirdWeather integration.
- [the BirdNET-Pi fork](https://github.com/Nachtzuster/BirdNET-Pi) by Nachtzuster, the maintained successor after McGuire's went dormant. It modernizes the stack with newer Raspberry Pi OS (Bookworm and Trixie) and tflite_runtime support, a reworked and more robust analysis pipeline, a more responsive web ui, backup and restore, more notification types, and the V2.4 range model.
- [AvianVisitors](https://github.com/Twarner491/AvianVisitors) by Twarner491, which this repo forked from. It keeps the recording, detection, clip extraction, live audio stream, charts, stats, and BirdWeather features, drops the admin tooling (web terminal, file manager, database admin, system info, FTP), packs it all into a single Docker container, and replaces the old dashboard with the illustrated bird collage as the main page.

Most of the code comes from them, especially the recording and detection under `birdnet/`, which stays under their original license (see `birdnet/LICENSE`).
What this repo adds is a nicer Docker usability layer, a lot of cleanup, and general code improvements run through tools like mago and oxlint.

## Contents

- [What you need](#what-you-need)
- [Install](#install)
- [Opening it](#opening-it)
- [The microphone](#the-microphone)
- [Location and settings](#location-and-settings)
- [Making more bird pictures](#making-more-bird-pictures)
- [Updating](#updating)
- [Where your data lives](#where-your-data-lives)

## What you need

- Any Linux box, preferably a Raspberry Pi (64-bit / arm64)
- A USB microphone plugged in
- Docker and Docker Compose installed

## Install

Clone the repo and bring it up:

```sh
git clone https://github.com/Twarner491/AvianVisitors.git
cd AvianVisitors
docker compose up -d --build
```

That builds the image and starts everything.
The first build takes a while on a pi because it installs the audio and detection bits.
After that it comes back up fast.

To see what it's doing:

```sh
docker compose logs -f
```

## Opening it

Open http://your-pi/ in a browser.
The collage is the main page.
There's also a small stats page at http://your-pi/stats.

It starts empty.
Birds appear as they get heard, so give it some time near a window with the mic.

## The microphone

The container already gets access to the mic through Docker Compose, so usually it just works.
If it can't record, list the sound cards it can see:

```sh
docker compose exec avianvisitors arecord -l
```

Pick your mic from that list and set it in docker-compose.yml, for example:

```yaml
environment:
  BIRDNET_REC_CARD: "plughw:1,0"
```

Then bring it back up:

```sh
docker compose up -d
```

## Location and settings

On the first run it guesses your latitude and longitude from your internet connection, which it uses to know which birds are likely in your area.
To set it yourself, put your coordinates in docker-compose.yml and restart:

```yaml
environment:
  BIRDNET_LATITUDE: "52.3759"
  BIRDNET_LONGITUDE: "4.8975"
```

```sh
docker compose up -d
```

Everything else lives in the config file at /data/birdnet.conf inside the data volume (see below), and the settings page in the web interface can change most of it too.

## Making more bird pictures

The illustrations are generated with Gemini, not drawn by hand.
The repo already ships a full set, but you can make your own, for your region or in a different style.
This part is heavy and downloads a big cutout model, so do it on your computer, not the pi.

You need uv (https://docs.astral.sh/uv/) and a Gemini api key from Google AI Studio.

The three steps are:

- pregen.py draws each bird with Gemini on a flat cream background
- cutout.py removes that background and crops to the bird
- build_masks.py rebuilds the collage shapes from the finished pictures

The easiest way is one bird at a time:

```sh
cd webui/generate
uv sync
export GEMINI_API_KEY=your-key

uv run python pregen.py --species "Calypte anna|Anna's Hummingbird"
uv run python cutout.py
uv run python build_masks.py
```

To do a whole set, make a text file with one `scientific name|common name` per line (commas or underscores work too) and point --labels at it:

```sh
uv run python pregen.py --labels my-birds.txt
uv run python cutout.py
uv run python build_masks.py
```

If you have an eBird api key you can filter a list down to the birds actually seen in your area:

```sh
export EBIRD_API_KEY=your-key
uv run python pregen.py --labels my-birds.txt --ebird-region US-CA
```

Add --force to redo a bird you already have.

The new pictures land in webui/assets/.
To show them in the collage, rebuild the container:

```sh
docker compose up -d --build
```

## Updating

```sh
git pull
docker compose up -d --build
```

## Where your data lives

Two Docker volumes keep your stuff so it survives rebuilds and updates:

- the recordings and detection clips
- a /data volume with the database and the config file

Removing the containers does not touch these.
If you want a clean slate, delete the volumes with docker compose down -v.
