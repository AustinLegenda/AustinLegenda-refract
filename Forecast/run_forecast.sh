#!/bin/bash
set -euo pipefail

VENV="/Applications/MAMP/htdocs/normal-surf/.venv/bin/activate"
APP_DIR="/Applications/MAMP/htdocs/normal-surf/Forecast/src"
LOG_DIR="/Applications/MAMP/htdocs/normal-surf/logs"   
LOCK="/tmp/normal_surf_forecast.lock"

mkdir -p "$LOG_DIR"
LOGFILE="$LOG_DIR/forecast_$(date +%Y%m%d).log"

# heartbeat
echo "[$(/bin/date -u '+%Y-%m-%dT%H:%M:%SZ')] runner tick" >> "$LOGFILE"

# prevent overlapping 
lockf -t 0 "$LOCK" /bin/bash -c '
  source "'"$VENV"'"
  cd "'"$APP_DIR"'"
  PYTHONWARNINGS="ignore::FutureWarning:cfgrib.xarray_plugin" /usr/bin/env python -u main.py >> "'"$LOGFILE"'" 2>&1
'
