ARG UV_VERSION=0.11.19

FROM ghcr.io/astral-sh/uv:${UV_VERSION} AS uv

FROM debian:bookworm-slim

ARG TARGETARCH
ARG TFLITE_VERSION=2.17.1
ENV DEBIAN_FRONTEND=noninteractive
ENV HOME=/home/birdnet
ENV APP_DIR=/home/birdnet/BirdNET-Pi
ENV UV_PROJECT_ENVIRONMENT=/home/birdnet/BirdNET-Pi/.venv
ENV UV_PYTHON=/usr/bin/python3.11
ENV UV_LINK_MODE=copy

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

RUN apt-get update && apt-get install -y --no-install-recommends \
      ca-certificates curl gnupg apt-transport-https debian-keyring debian-archive-keyring \
 && curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg \
 && curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' -o /etc/apt/sources.list.d/caddy-stable.list \
 && echo "icecast2 icecast2/icecast-setup boolean false" | debconf-set-selections \
 && apt-get update && apt-get install -y --no-install-recommends \
      caddy supervisor \
      sqlite3 php-sqlite3 php-fpm php-curl php-xml php-zip php-mbstring php-cli \
      sox libsox-fmt-mp3 alsa-utils ffmpeg icecast2 \
      python3 \
      libsndfile1 libgomp1 \
      wget unzip bc jq lsof inotify-tools \
 && rm -rf /var/lib/apt/lists/*

COPY --from=uv /uv /uvx /usr/local/bin/

RUN useradd -m -u 1000 -s /bin/bash birdnet \
 && usermod -aG audio birdnet

WORKDIR ${APP_DIR}

COPY birdnet/pyproject.toml birdnet/uv.lock ./birdnet/

RUN uv sync --project birdnet --frozen --no-dev --extra stats --extra notify

RUN set -eux; \
    case "${TARGETARCH}" in \
      arm64) WHL="tflite_runtime-${TFLITE_VERSION}-cp311-cp311-linux_aarch64.whl" ;; \
      amd64) WHL="tflite_runtime-${TFLITE_VERSION}-cp311-cp311-linux_x86_64.whl" ;; \
      *) echo "unsupported TARGETARCH=${TARGETARCH}"; exit 1 ;; \
    esac; \
    curl -fL -o "/tmp/${WHL}" "https://github.com/Nachtzuster/BirdNET-Pi/releases/download/v0.1/${WHL}"; \
    uv pip install --no-cache --python "${UV_PROJECT_ENVIRONMENT}/bin/python3" "/tmp/${WHL}"; \
    rm -f "/tmp/${WHL}"

COPY --chown=birdnet:birdnet . ${APP_DIR}

COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/php-fpm-pool.conf /etc/php/8.2/fpm/pool.d/zz-birdnet.conf
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN rm -f /etc/php/8.2/fpm/pool.d/www.conf \
 && chmod +x /usr/local/bin/entrypoint.sh \
 && sed -i \
      -e 's|^error_log = .*|error_log = /proc/self/fd/2|' \
      -e 's|^pid = .*|pid = /home/birdnet/run/php-fpm.pid|' \
      /etc/php/8.2/fpm/php-fpm.conf \
 && mkdir -p \
      /data/recordings/Extracted/By_Date \
      /data/recordings/Extracted/Charts \
      /data/recordings/Processed \
      /data/recordings/StreamData \
      /data/logs \
      /data/config \
      /run/php /etc/birdnet /home/birdnet/run \
 && ln -s /data/recordings /home/birdnet/BirdSongs \
 && chown -R birdnet:birdnet /data /home/birdnet /run/php /etc/birdnet /etc/icecast2

VOLUME ["/data"]
EXPOSE 8080

USER birdnet
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
