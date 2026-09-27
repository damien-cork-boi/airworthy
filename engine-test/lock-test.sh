#!/bin/sh
# Lock test: three processes call run_batch() for the same scan at the same moment, bypassing
# Action Scheduler's own claiming. Exactly one may work; the scan must end with no duplicates.
cd "$(dirname "$0")/wp" || exit 1
W=../tools/wp
ID=$("$W" eval 'Airworthy\Scan\Queue::cancel_all(); $id = Airworthy\Scan\Queue::start("8.4", 1); echo $id;')
echo "scan $ID"
for n in 1 2 3; do
  ( "$W" eval "\$t=microtime(true); Airworthy\\Scan\\Queue::run_batch($ID); global \$wpdb; printf(\"runner $n: %.1fs, scan files_done %d\\n\", microtime(true)-\$t, \$wpdb->get_var(\"SELECT files_done FROM wp_airworthy_scans WHERE id=$ID\"));" ) &
done
wait
"$W" eval "global \$wpdb; \$dup = \$wpdb->get_var(\"SELECT COUNT(*) FROM (SELECT component_id, file, line, rule, COUNT(*) n FROM wp_airworthy_issues WHERE scan_id=$ID GROUP BY component_id, file, line, rule, message HAVING n > 1) d\"); echo \"duplicate issue groups after the race: \$dup\\n\"; Airworthy\\Scan\\Queue::cancel($ID);"
