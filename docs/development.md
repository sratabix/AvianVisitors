# Development

The tooling is the same idea in every language: a fast Rust-based linter and formatter.
uv + ruff for Python, oxlint + oxfmt for the frontend, mago for PHP.
It all runs in a container, nothing is installed on your host.

There are two separate Python projects that share nothing:

- birdnet/ is the detection runtime (numpy < 2, needs the tflite wheel)
- webui/generate/ is the picture pipeline (numpy >= 2, needs rembg)

Each has its own pyproject.toml, uv.lock and ruff config.

## Python: birdnet

```sh
cd birdnet
uv sync --extra stats --extra notify --group dev
uv run ruff check .
uv run ruff format .
uv run pytest -q
```

pytest needs the tflite runtime, so install the wheel for your arch first:

```sh
uv pip install https://github.com/Nachtzuster/BirdNET-Pi/releases/download/v0.1/tflite_runtime-2.17.1-cp311-cp311-linux_aarch64.whl
```

The extras are stats (the Streamlit page) and notify (Apprise + MQTT).
The container installs both.

## Python: generation

The Gemini + BiRefNet picture pipeline.
See [making more bird pictures](../README.md#making-more-bird-pictures) in the readme for how to actually run it.
To lint it:

```sh
cd webui/generate
uvx ruff check .
uvx ruff format .
```

## Frontend

The JS/CSS tooling lives in webui/.

```sh
cd webui
npm ci
npm run lint
npm run format
```

apt.js is mostly a generated data line (build_masks.py writes the masks into it), so it's linted but not formatted.
oxfmt formats styles.css.

## PHP

webui/api is plain PHP, no Composer.
The config is webui/mago.toml.

```sh
docker run --rm -v "$PWD/webui:/repo" -w /repo ghcr.io/carthage-software/mago:latest fmt
docker run --rm -v "$PWD/webui:/repo" -w /repo ghcr.io/carthage-software/mago:latest lint
```

A few of mago's opinionated rules (the @ error-control operator, empty/isset, named arguments) are turned off in mago.toml on purpose.

## CI

.github/workflows/release.yml runs the four lanes (birdnet, generate, frontend, php) on every push and pull request, then builds the image.
Pushing to avian-visitors publishes ghcr.io/twarner491/avianvisitors:dev; a v\* tag publishes the versioned tags (1.2.3, 1.2, 1, latest).
ghcr-cleanup.yml prunes old untagged images daily, and dependabot keeps the deps and actions current.
