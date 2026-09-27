# Test-site support files

The local test site (`engine-test/wp/`) is not in git. These files recreate its test hooks:

- `airworthy-test-driver.php`: copy to `wp/wp-content/mu-plugins/`. It runs Action Scheduler's queue from a web request (`/?airworthy_test=run`), as WP-Cron does, and applies test settings from the `airworthy_test_knobs` option.
- `wp-config-memory-cap.php`: paste into `wp/wp-config.php`. It simulates a host with a hard memory cap during test requests (`echo 64M > wp/.aw-mem`; delete the file to turn it off).

Scripts in `engine-test/`:
- `run-queue.sh` runs the queue through WP-CLI.
- `drive-web.sh` runs it through web requests.
- `lock-test.sh` races three runners against one scan.
