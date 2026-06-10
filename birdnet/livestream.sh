#!/usr/bin/env bash
source /etc/birdnet/birdnet.conf

LOGGING_LEVEL="${LogLevel_LiveAudioStreamService}"
[ -z $LOGGING_LEVEL ] && LOGGING_LEVEL='error'
if [ "$LOGGING_LEVEL" == "info" ] || [ "$LOGGING_LEVEL" == "debug" ];then
  set -x
fi

if [ "$ACTIVATE_FREQSHIFT_IN_LIVESTREAM" == "true" ]; then
  FREQSHIFT_OPT='-af rubberband=pitch='${FREQSHIFT_LO}'/'${FREQSHIFT_HI}
fi

if [ -z ${REC_CARD} ];then
  echo "Stream not supported"
elif [[ ! -z ${RTSP_STREAM} ]];then
  RSTP_STREAMS_EXPLODED_ARRAY=(${RTSP_STREAM//,/ })

  if [[ -z ${RTSP_STREAM_TO_LIVESTREAM} ]];then
    RTSP_STREAM_TO_LIVESTREAM=0
  fi

  SELECTED_RSTP_STREAM=${RSTP_STREAMS_EXPLODED_ARRAY[RTSP_STREAM_TO_LIVESTREAM]}

  if [[ -z ${SELECTED_RSTP_STREAM} ]];then
    SELECTED_RSTP_STREAM=${RSTP_STREAMS_EXPLODED_ARRAY[0]}
  fi

  ffmpeg -nostdin -loglevel $LOGGING_LEVEL -ac ${CHANNELS} -i ${SELECTED_RSTP_STREAM} -acodec libmp3lame \
    -b:a 320k -ac ${CHANNELS} -content_type 'audio/mpeg' \
    ${FREQSHIFT_OPT} \
    -f mp3 icecast://source:${ICE_PWD}@localhost:8000/stream -re
else
	ffmpeg -nostdin -loglevel $LOGGING_LEVEL -ac ${CHANNELS} -f alsa -i ${REC_CARD} -acodec libmp3lame \
    -b:a 320k -ac ${CHANNELS} -content_type 'audio/mpeg' \
    ${FREQSHIFT_OPT} \
    -f mp3 icecast://source:${ICE_PWD}@localhost:8000/stream -re
fi
