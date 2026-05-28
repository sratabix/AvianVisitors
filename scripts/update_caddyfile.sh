#!/usr/bin/env bash
source /etc/birdnet/birdnet.conf
my_dir=$HOME/BirdNET-Pi/scripts
set -x
[ -d /etc/caddy ] || mkdir /etc/caddy
if [ -f /etc/caddy/Caddyfile ];then
  cp /etc/caddy/Caddyfile{,.original}
fi
if ! [ -z ${CADDY_PWD} ];then
HASHWORD=$(caddy hash-password --plaintext ${CADDY_PWD})
cat << EOF > /etc/caddy/Caddyfile
http:// ${BIRDNETPI_URL} {
  root * ${EXTRACTED}
  file_server browse
  handle /By_Date/* {
    file_server browse
  }
  handle /Charts/* {
    file_server browse
  }
  basicauth /views.php?view=File* {
    birdnet ${HASHWORD}
  }
  basicauth /Processed* {
    birdnet ${HASHWORD}
  }
  basicauth /scripts* {
    birdnet ${HASHWORD}
  }
  basicauth /stream {
    birdnet ${HASHWORD}
  }
  basicauth /phpsysinfo* {
    birdnet ${HASHWORD}
  }
  basicauth /terminal* {
    birdnet ${HASHWORD}
  }
  reverse_proxy /stream localhost:8000
  # AvianVisitors overlay drops an index.html alongside BirdNET-Pi's
  # index.php. The default try_files for php_fastcgi prefers index.php
  # over index.html, so override it - this is a no-op on stock installs
  # since EXTRACTED has no index.html there.
  php_fastcgi unix//run/php/php-fpm.sock {
    try_files {path} {path}/index.html {path}/index.php index.php
  }
  reverse_proxy /log* localhost:8080
  reverse_proxy /stats* localhost:8501
  reverse_proxy /terminal* localhost:8888
}
EOF
else
  cat << EOF > /etc/caddy/Caddyfile
http:// ${BIRDNETPI_URL} {
  root * ${EXTRACTED}
  file_server browse
  handle /By_Date/* {
    file_server browse
  }
  handle /Charts/* {
    file_server browse
  }
  reverse_proxy /stream localhost:8000
  # AvianVisitors overlay drops an index.html alongside BirdNET-Pi's
  # index.php. The default try_files for php_fastcgi prefers index.php
  # over index.html, so override it - this is a no-op on stock installs
  # since EXTRACTED has no index.html there.
  php_fastcgi unix//run/php/php-fpm.sock {
    try_files {path} {path}/index.html {path}/index.php index.php
  }
  reverse_proxy /log* localhost:8080
  reverse_proxy /stats* localhost:8501
  reverse_proxy /terminal* localhost:8888
}
EOF
fi

sudo caddy fmt --overwrite /etc/caddy/Caddyfile
sudo systemctl reload caddy
