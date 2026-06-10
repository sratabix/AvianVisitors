# Running in Docker

The whole thing runs in one container on plain Debian.
It captures the mic over ALSA, runs the bird detection, and serves the web page on port 80.
There's no systemd inside the container, so supervisord runs the services instead.

## Contents

- [What runs inside](#what-runs-inside)
- [Start it](#start-it)
- [Microphone](#microphone)
- [Config](#config)
- [Live audio stream](#live-audio-stream)
- [Logs and control](#logs-and-control)
- [What doesn't work in a container](#what-doesnt-work-in-a-container)
- [How it's built](#how-its-built)

## What runs inside

Supervisord keeps these running:

| Service    | What it does                     | Default |
| ---------- | -------------------------------- | ------- |
| caddy      | Web server / proxy on port 80    | on      |
| php-fpm    | The PHP api for the collage      | on      |
| recording  | arecord, captures the mic to wav | on      |
| analysis   | The TFLite detection             | on      |
| charts     | Daily plots                      | on      |
| stats      | The Streamlit page at /stats     | on      |
| icecast    | Live audio stream backend        | off     |
| livestream | ffmpeg feeding icecast (/stream) | off     |

The collage is the web page; its api is under /webui/api/.

## Start it

```sh
docker compose up -d --build
```

Open http://your-pi/.
Recordings, the database and the config file live in two named volumes (birdsongs and data), so they survive rebuilds.

## Microphone

The container already gets the host sound device and the audio group, both set in docker-compose.yml:

```yaml
devices:
  - "/dev/snd:/dev/snd"
group_add:
  - audio
```

BIRDNET_REC_CARD defaults to default.
If capture fails (cannot find card), list the cards and pin one:

```sh
docker compose exec avianvisitors arecord -l
```

Then set, e.g. BIRDNET_REC_CARD=plughw:1,0 in docker-compose.yml and run docker compose up -d again.

If the /dev/snd nodes on your host are owned by a group whose id differs from the container's audio group, add the host group id instead of the name:

```yaml
group_add:
  - "29"
```

## Config

First boot writes /data/birdnet.conf (latitude and longitude are guessed from your ip).
Edit that file for anything the env vars below don't cover, then docker compose restart.
The settings page in the web interface writes to it too.

The env vars in docker-compose.yml are applied to the config on every start:

| Variable          | What it does        |
| ----------------- | ------------------- |
| BIRDNET_REC_CARD  | ALSA capture device |
| BIRDNET_CHANNELS  | Number of channels  |
| BIRDNET_LATITUDE  | Set your latitude   |
| BIRDNET_LONGITUDE | Set your longitude  |

## Live audio stream

Icecast and livestream are off by default.
To turn them on, set autostart=true for both in docker/supervisord.conf and rebuild.
On a single mic this fights the recorder (both open the device), so you need an rtsp source or a shareable ALSA dsnoop device to run both at once.

## Logs and control

Everything logs to the container:

```sh
docker compose logs -f
docker exec avianvisitors supervisorctl status
docker exec avianvisitors supervisorctl restart analysis
```

## What doesn't work in a container

The collage has an admin overlay.
The settings page works and writes the config, but it can't restart services or tail logs the way the original did, because that needed systemd and journald, which aren't in the container.
So:

- After changing settings, restart the container: docker compose restart
- For logs, use docker compose logs -f
- For service control, use docker exec avianvisitors supervisorctl ...

## How it's built

- The base image is Debian bookworm-slim with Python 3.11 (the tflite wheel is built for cp311).
- The Python deps come from birdnet/ and are installed with uv into the venv at /home/birdnet/BirdNET-Pi/.venv, which is where supervisord looks for them.
- The tflite runtime wheel is downloaded in the Dockerfile, picked by arch.
- It builds for both amd64 and arm64; the pi uses arm64.
