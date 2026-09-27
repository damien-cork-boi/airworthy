#!/bin/sh
# Runs Airworthy's queue via WP-CLI (standing in for WP-Cron) until the latest scan ends.
# Usage: run-queue.sh [max-runs]   Stops on the first runner error.
cd "$(dirname "$0")/wp" || exit 1
W=../tools/wp; MAX=${1:-200}; start=$(date +%s); i=0
# After any plugin deactivation Action Scheduler re-checks its storage ("hybrid" mode), and
# WP-CLI's --group filter fails until that finishes. Real sites finish it via WP-Cron.
"$W" action-scheduler run --hooks=action_scheduler/migration_hook --quiet >/dev/null 2>&1
while [ $i -lt "$MAX" ]; do
  i=$((i+1))
  out=$("$W" action-scheduler run --group=airworthy --batches=1 --batch-size=1 2>&1)
  if echo "$out" | grep -q "^Error"; then echo "runner error: $out"; exit 1; fi
  S=$("$W" eval 'global $wpdb; $s=$wpdb->get_row("SELECT * FROM wp_airworthy_scans ORDER BY id DESC LIMIT 1"); printf("%s %d/%d", $s->status, $s->files_done, $s->files_total);')
  echo "run $i: $S"
  case "$S" in complete*|failed*|cancelled*) break;; esac
done
echo "wall: $(( $(date +%s)-start ))s, runs: $i"
