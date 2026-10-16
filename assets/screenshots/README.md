# WordPress.org listing screenshots

These go in the plugin's SVN `assets/` folder (not in the plugin zip), and the website shows them too (`site/scripts/sync-assets.mjs`). Captions are in `plugin/readme.txt` under "== Screenshots ==".

| File | View |
|---|---|
| screenshot-1.png | Start: server PHP 7.4 with the support countdown, what will be scanned, WordPress.org consent, target picker (8.2 recommended) |
| screenshot-2.png | Progress: big percentage, stats, verdicts as they come in |
| screenshot-3.png | Results overview: headline, verdict tiles, updates-waiting note, support countdown |
| screenshot-4.png | Results grouped by what to do next, with the Blocker's details open and an update waiting |
| screenshot-5.png | Settings tab (saves as you change it) |
| screenshot-6.png | The Site Health check |
| screenshot-7.png | WP-CLI: `wp airworthy scan` table (rendered, see below) |

Captured 27 Sep 2026 at 1280 px wide (2x) with headless Chrome (puppeteer-core) on the clean local site, renamed "Example Site": popular WordPress.org plugins plus two made-up demo plugins ("Old Widget Pack", which uses code removed in PHP 8, and "Legacy Lightbox", which keeps old code behind a version check). A development build shows the server as PHP 7.4, and a mu-plugin hides the test database drop-in's admin-bar item. Old Widget Pack's "update available" is staged in WordPress's update transient. None of the screenshots show the two unreported bugs (see docs/RELEASE.md).

screenshot-7.png (added 16 Oct 2026 for 1.0.1) is not a browser capture: `assets/source/cli-screenshot.html` holds the real output of `wp airworthy scan --target=8.4 --from=7.4 --wporg --only=…` on the clean demo site in a terminal-style frame (only the prompt is made up), rendered at 2x by `node site/scripts/render-cli-screenshot.mjs`. Re-run the scan and paste the new output when the CLI output changes.
