#!/usr/bin/env bash
set -euo pipefail

APP_DIR=/home/birdnet/BirdNET-Pi
DATA_DIR=/data
RECORDINGS=${DATA_DIR}/recordings
LOGS_DIR=${DATA_DIR}/logs
CFG_DIR=${DATA_DIR}/config
CONF=${DATA_DIR}/birdnet.conf
DB=${DATA_DIR}/birds.db
DBTXT=${DATA_DIR}/BirdDB.txt

UID_IN=$(id -u)
GID_IN=$(id -g)

C_RED=$'\033[31m'; C_YEL=$'\033[33m'; C_GRN=$'\033[32m'; C_DIM=$'\033[2m'; C_RST=$'\033[0m'
[ -t 1 ] || { C_RED=; C_YEL=; C_GRN=; C_DIM=; C_RST=; }

ts() { date -u +%Y-%m-%dT%H:%M:%SZ; }
log()  { printf '%s [avianvisitors] %s%s%s %s\n' "$(ts)" "$2" "$1" "$C_RST" "$3"; }
info() { log INFO  "$C_GRN" "$*"; }
warn() { log WARN  "$C_YEL" "$*"; }
err()  { log ERROR "$C_RED" "$*"; }
note() { printf '%s %s[avianvisitors]      %s%s\n' "$(ts)" "$C_DIM" "$*" "$C_RST"; }

FATAL=0
fail() { err "$*"; FATAL=1; }

map_host_id() {
  local want="$1" mapfile="$2"
  awk -v w="$want" '{ if (w>=$1 && w<$1+$3) { print $2+(w-$1); f=1; exit } } END { if(!f) print "?" }' "$mapfile" 2>/dev/null || echo '?'
}

USERNS=0
HOST_UID="${UID_IN}"
HOST_GID="${GID_IN}"
if [ -r /proc/self/uid_map ]; then
  read -r u0 h0 _ < /proc/self/uid_map
  if [ "${u0:-0}" != "0" ] || [ "${h0:-0}" != "0" ]; then
    USERNS=1
    HOST_UID=$(map_host_id "${UID_IN}" /proc/self/uid_map)
    HOST_GID=$(map_host_id "${GID_IN}" /proc/self/gid_map)
  fi
fi

info "starting preflight checks"
note "all processes run as uid=${UID_IN} gid=${GID_IN} (non-root)"
if [ "${USERNS}" = "1" ]; then
  warn "user namespace remapping is active (rootless docker or userns-remap)"
  note "inside uid=${UID_IN} maps to HOST uid=${HOST_UID}; inside gid=${GID_IN} maps to HOST gid=${HOST_GID}"
  note "when fixing bind-mount ownership on the host, chown to the HOST ids above, not ${UID_IN}:${GID_IN}"
fi

ro_mount() { awk -v p="$1" '$2==p { if ($4 ~ /(^|,)ro(,|$)/) print "ro" }' /proc/mounts 2>/dev/null | head -1; }

check_rw() {
  local dir="$1" label="$2"
  if [ ! -d "$dir" ]; then
    fail "${label}: ${dir} is not present"
    return
  fi
  local owner mode probe
  owner=$(stat -c '%U:%G (%u:%g)' "$dir" 2>/dev/null || echo '?')
  mode=$(stat -c '%a' "$dir" 2>/dev/null || echo '?')
  probe="${dir}/.av-write-test.${RANDOM}"
  if [ -r "$dir" ] && touch "$probe" 2>/dev/null; then
    rm -f "$probe"
    info "${label}: read/write OK (${dir})"
    return
  fi
  fail "${label}: cannot read+write ${dir} as uid=${UID_IN}"
  note "current owner=${owner} mode=${mode}$( [ "$(ro_mount "$dir")" = ro ] && echo ' [mounted read-only]')"
  if [ "${USERNS}" = "1" ]; then
    note "fix on host: chown -R ${HOST_UID}:${HOST_GID} <host-path-mounted-at-${dir}>"
  else
    note "fix on host: chown -R ${UID_IN}:${GID_IN} <host-path-mounted-at-${dir}>"
  fi
}

check_rw "${DATA_DIR}" "data volume"

if [ "${FATAL}" = "1" ]; then
  err "preflight failed: required volumes are not writable; refusing to start"
  note "this container runs as a non-root user and does not change ownership of your mounts"
  note "if you bind-mount host folders, fix their ownership with the chown shown above, then restart"
  note "if you use named docker volumes (the default compose setup), recreate them: docker compose down -v && docker compose up -d"
  exit 1
fi

mkdir -p \
  "${RECORDINGS}/Extracted/By_Date" \
  "${RECORDINGS}/Extracted/Charts" \
  "${RECORDINGS}/Processed" \
  "${RECORDINGS}/StreamData" \
  "${LOGS_DIR}" \
  "${CFG_DIR}"

ln -sf "${CONF}" "${APP_DIR}/birdnet.conf"
ln -sf "${CONF}" /etc/birdnet/birdnet.conf

for f in apprise.txt body.txt IdentifiedSoFar.txt include_species_list.txt exclude_species_list.txt whitelist_species_list.txt; do
  ln -sf "${CFG_DIR}/${f}" "${APP_DIR}/${f}"
done

if [ ! -f "${CONF}" ]; then
  info "no config found; generating ${CONF} (details in ${LOGS_DIR}/install_config.log)"
  if ! HOME=/home/birdnet USER=birdnet HOSTNAME="$(hostname)" my_dir="${APP_DIR}" \
       bash "${APP_DIR}/birdnet/install_config.sh" >"${LOGS_DIR}/install_config.log" 2>&1; then
    err "config generation failed; see ${LOGS_DIR}/install_config.log"
    exit 1
  fi
fi

[ -n "${BIRDNET_REC_CARD:-}" ]   && sed -i "s|^REC_CARD=.*|REC_CARD=${BIRDNET_REC_CARD}|"   "${CONF}"
[ -n "${BIRDNET_CHANNELS:-}" ]   && sed -i "s|^CHANNELS=.*|CHANNELS=${BIRDNET_CHANNELS}|"   "${CONF}"
[ -n "${BIRDNET_LATITUDE:-}" ]   && sed -i "s|^LATITUDE=.*|LATITUDE=${BIRDNET_LATITUDE}|"   "${CONF}"
[ -n "${BIRDNET_LONGITUDE:-}" ]  && sed -i "s|^LONGITUDE=.*|LONGITUDE=${BIRDNET_LONGITUDE}|" "${CONF}"

set -a
source "${CONF}"
set +a

if [ -d "${APP_DIR}/webui" ]; then
  ln -sf "${APP_DIR}/webui"                       "${EXTRACTED}/webui"
  ln -sf "${APP_DIR}/webui/frontend/index.html"   "${EXTRACTED}/index.html"
  ln -sf "${APP_DIR}/webui/frontend/styles.css"   "${EXTRACTED}/styles.css"
  ln -sf "${APP_DIR}/webui/frontend/apt.js"       "${EXTRACTED}/apt.js"
  ln -sf "${APP_DIR}/webui/frontend/masks.json"   "${EXTRACTED}/masks.json"
  ln -sf "${APP_DIR}/webui/frontend/dims.json"    "${EXTRACTED}/dims.json"
  ln -sf "${APP_DIR}/webui/assets/favicon.png"    "${EXTRACTED}/favicon.png"
  ln -sf "${APP_DIR}/webui/assets/favicon.png"    "${EXTRACTED}/favicon.ico"
fi

ln -sf "${APP_DIR}/birdnet/model/labels.txt" "${APP_DIR}/birdnet/labels.txt" 2>/dev/null || true
ln -sf "${APP_DIR}/birdnet/model/labels_nm/labels_en.txt" "${APP_DIR}/birdnet/model/labels_flickr.txt" 2>/dev/null || true

if ls "${APP_DIR}"/birdnet/model/*.tflite >/dev/null 2>&1; then
  info "detection model present"
else
  fail "no .tflite model found under ${APP_DIR}/birdnet/model; detection cannot run"
fi

ln -sf "${DB}" "${APP_DIR}/birdnet/birds.db"
if [ ! -f "${DB}" ]; then
  info "no database found; creating ${DB} (details in ${LOGS_DIR}/createdb.log)"
  if ! HOME=/home/birdnet USER=birdnet bash "${APP_DIR}/birdnet/createdb.sh" >"${LOGS_DIR}/createdb.log" 2>&1; then
    err "database creation failed; see ${LOGS_DIR}/createdb.log"
    exit 1
  fi
fi

ln -sf "${DBTXT}" "${APP_DIR}/BirdDB.txt"
if [ ! -f "${DBTXT}" ]; then
  echo "Date;Time;Sci_Name;Com_Name;Confidence;Lat;Lon;Cutoff;Week;Sens;Overlap" > "${DBTXT}"
fi

REC_CARD_VAL="${REC_CARD:-default}"
AUDIO_OK=false
if [ -n "${RTSP_STREAM:-}" ]; then
  info "audio source: RTSP stream configured; recording will use the network feed"
  AUDIO_OK=true
elif [ ! -d /dev/snd ]; then
  warn "no audio devices: /dev/snd is not present in the container; recording is disabled"
  note "map your sound card in docker-compose.yml: devices: [ \"/dev/snd:/dev/snd\" ]"
else
  snd_gid=$(stat -c '%g' /dev/snd/timer /dev/snd/control* 2>/dev/null | sort -un | head -1 || true)
  audio_gid=$(getent group audio | cut -d: -f3 || true)
  readable=0
  for node in /dev/snd/pcmC*c /dev/snd/controlC*; do
    [ -e "$node" ] || continue
    if [ -r "$node" ]; then readable=1; break; fi
  done
  cards=$(arecord -l 2>/dev/null | grep -c '^card ' || true)
  if [ "${readable}" = "1" ] && [ "${cards:-0}" -ge 1 ]; then
    info "microphone access OK (${cards} capture device(s) visible, configured REC_CARD=${REC_CARD_VAL})"
    AUDIO_OK=true
  elif [ "${cards:-0}" -ge 1 ] && [ "${readable}" != "1" ]; then
    warn "capture devices exist but uid=${UID_IN} cannot read /dev/snd nodes; recording will fail"
    note "/dev/snd is owned by gid=${snd_gid:-?}; container audio gid=${audio_gid:-?}; current groups: $(id -Gn | tr ' ' ',')"
    note "fix in docker-compose.yml by granting the host audio gid: group_add: [ \"${snd_gid:-29}\" ]"
  else
    warn "no usable capture device found (REC_CARD=${REC_CARD_VAL}); recording will fail until a mic is available"
    note "list cards the container can see with: docker compose exec avianvisitors arecord -l"
    note "then set the right one via BIRDNET_REC_CARD (e.g. \"plughw:1,0\") in docker-compose.yml"
  fi
fi

if [ "${AUDIO_OK}" = "true" ] && [ -z "${RTSP_STREAM:-}" ] && [ -d /dev/snd ]; then
  hw=""
  case "${REC_CARD:-default}" in
    plughw:* | hw:*) hw="hw:${REC_CARD#*:}" ;;
    [0-9]*,[0-9]*) hw="hw:${REC_CARD}" ;;
  esac
  if [ -z "${hw}" ]; then
    hw=$(arecord -l 2>/dev/null | sed -n 's/^card \([0-9]\+\):.*device \([0-9]\+\):.*/hw:\1,\2/p' | head -1 || true)
  fi
  if [ -n "${hw}" ]; then
    cat > /home/birdnet/.asoundrc <<EOF
pcm.birdnet_mic {
  type plug
  slave.pcm "dsnoop_birdnet"
}
pcm.dsnoop_birdnet {
  type dsnoop
  ipc_key 2048
  ipc_perm 0660
  slave {
    pcm "${hw}"
    channels ${CHANNELS:-2}
    rate 48000
    format S16_LE
  }
}
EOF
    sed -i "s|^REC_CARD=.*|REC_CARD=birdnet_mic|" "${CONF}"
    info "shared capture enabled: recording + livestream both read ${hw} via dsnoop"
    note "if recording fails to open the mic, your card may not support 48000Hz/${CHANNELS:-2}ch; set BIRDNET_REC_CARD to a raw plughw device and live audio will be disabled"
  else
    warn "could not resolve the capture hw device; recording + livestream cannot share a raw device, so live audio will conflict with recording"
  fi
fi

export AV_AUDIO_AUTOSTART="${AUDIO_OK}"
if [ "${AUDIO_OK}" != "true" ]; then
  warn "recording + analysis will not auto-start without an audio source; the rest of the app runs normally"
  note "connect a mic (or set RTSP_STREAM), then start them from the web UI tools or restart the container"
fi

disk_free_mb() { df -Pm "$1" 2>/dev/null | awk 'NR==2 {print $4}'; }
free_mb=$(disk_free_mb "${DATA_DIR}")
if [ -n "${free_mb:-}" ] && [ "${free_mb}" -lt 500 ]; then
  warn "low disk space: only ${free_mb} MB free on ${DATA_DIR}; recordings/db may fail (FULL_DISK=${FULL_DISK:-purge}, PURGE_THRESHOLD=${PURGE_THRESHOLD:-95})"
fi

if [ -z "${LATITUDE:-}" ] || [ -z "${LONGITUDE:-}" ] || [ "${LATITUDE:-0}" = "0" ]; then
  warn "location not set (LATITUDE/LONGITUDE empty); species range filtering will be inaccurate"
  note "set BIRDNET_LATITUDE / BIRDNET_LONGITUDE in docker-compose.yml"
fi

if [ -f /etc/icecast2/icecast.xml ]; then
  ICE_PWD_VAL="${ICE_PWD:-birdnetpi}"
  sed -i "s/>admin</>birdnet</g" /etc/icecast2/icecast.xml
  sed -i "s|<logdir>.*</logdir>|<logdir>${LOGS_DIR}</logdir>|" /etc/icecast2/icecast.xml
  for prefix in source- relay- admin- master- ""; do
    sed -i "s|<${prefix}password>.*</${prefix}password>|<${prefix}password>${ICE_PWD_VAL}</${prefix}password>|g" /etc/icecast2/icecast.xml
  done
fi

info "preflight complete; launching services"
exec "$@"
