#!/bin/sh
# Drives Airworthy's queue through web requests (as WP-Cron would) until the latest scan ends.
# Usage: drive-web.sh [max-requests] [pause-seconds]
MAX=${1:-300}; PAUSE=${2:-0}; start=$(date +%s); i=0; dead=0
while [ $i -lt "$MAX" ]; do
  i=$((i+1))
  r=$(curl -s --max-time 90 "http://localhost:8891/?airworthy_test=run")
  line=$(echo "$r" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(!$d){echo "DIED"; exit;} printf("%-8s %5d/%-5d  req %5dms  peak %5.1fMB/%s", $d["status"], $d["files_done"], $d["files_total"], $d["req_ms"], $d["peak_mb"], $d["memory_limit"]);')
  [ "$line" = "DIED" ] && dead=$((dead+1)) && line="request died (fatal error) - watchdog will resume"
  echo "req $i: $line"
  case "$line" in complete*|failed*|cancelled*) break;; esac
  [ "$PAUSE" -gt 0 ] && sleep "$PAUSE"
done
echo "wall: $(( $(date +%s)-start ))s, requests: $i, died: $dead"
