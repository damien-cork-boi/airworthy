#!/bin/sh
# Drive the in-WordPress probe batch by batch until done. Usage: drive.sh <target> <budget-secs> [mem]
T=${1:-8.4}; B=${2:-20}; M=${3:-}
curl -s "http://localhost:8891/?sua_probe=reset" >/dev/null
start=$(date +%s); i=0; bad=0
while [ $i -lt 400 ]; do
  i=$((i+1))
  r=$(curl -s --max-time 60 "http://localhost:8891/?sua_probe=1&target=$T&budget=$B&mem=$M&nobudget=$NB")
  echo "$r" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(!$d){echo "  request died (fatal) -> next batch recovers\n"; exit(2);} printf("batch %3d  %5d/%d  +%4d  req %5dms  peak %5.1fMB/%s  crashed %d  skip-mem %d  skip-data %d\n",$d["batch"],$d["files_done"],$d["files_total"],$d["this_batch"],$d["req_ms"],$d["req_peak_mb"],$d["memory_limit"],$d["crashed"],$d["skipped_mem"],$d["skipped_data"]); exit($d["done"]?1:0);'
  rc=$?; [ $rc -eq 1 ] && break; [ $rc -eq 2 ] && bad=$((bad+1))
done
echo "wall: $(( $(date +%s)-start ))s, requests: $i, fatal requests: $bad"
