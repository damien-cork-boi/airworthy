# WordPress.org listing screenshots

These go in the plugin's SVN `assets/` folder (not in the plugin zip), and the website shows them too (`site/scripts/sync-assets.mjs`). Captions are in `plugin/readme.txt` under "== Screenshots ==".

| File | View |
|---|---|
| screenshot-1.png | Results overview at PHP 8.4: header and tabs, support countdown, PHP security-fixes note, headline, What to do first, verdict tiles |
| screenshot-2.png | Results grouped by what to do next, with Old Widget Pack's details open (update waiting) and its first finding's explanation open |
| screenshot-3.png | Plugins screen (active plugins) with the verdict badges |
| screenshot-4.png | Start: server PHP 7.4 with the support countdown, what will be scanned, WordPress.org consent, target picker (8.2 recommended) |
| screenshot-5.png | Progress: big percentage, stats, verdicts as they come in |
| screenshot-6.png | Settings tab (saves as you change it) |
| screenshot-7.png | The Site Health check |
| screenshot-8.png | WP-CLI: `wp airworthy scan` table (rendered, see below) |

Captured 27 Sep 2026 at 1280 px wide (2x) with headless Chrome (puppeteer-core) on the clean local site, renamed "Example Site": popular WordPress.org plugins plus two made-up demo plugins ("Old Widget Pack", which uses code removed in PHP 8, and "Legacy Lightbox", which keeps old code behind a version check). A development build shows the server as PHP 7.4, and a mu-plugin hides the test database drop-in's admin-bar item. Old Widget Pack's "update available" is staged in WordPress's update transient. None of the screenshots show the two unreported bugs (see docs/RELEASE.md).

screenshot-7.png (added 16 Oct 2026 for 1.0.1) is not a browser capture: `assets/source/cli-screenshot.html` holds the real output of `wp airworthy scan --target=8.4 --from=7.4 --wporg --only=…` on the clean demo site in a terminal-style frame (only the prompt is made up), rendered at 2x by `node site/scripts/render-cli-screenshot.mjs`. Re-run the scan and paste the new output when the CLI output changes.

Screenshots 3, 4 and 8 were recaptured on 21 Oct 2026 for 1.1.0 with `node scripts/capture-screenshots.mjs` (from `site/`; it lists what the demo site needs). The scan behind them: `wp airworthy scan --target=8.4 --from=7.4 --wporg` on the clean site with a development build (`DEV_BUILD=1 bin/build.sh`) and Airworthy Pro moved out of the plugins folder, so no Pro tabs show. PHP 8.4 rather than the suggested 8.2 because at 8.2 the real Contact Form 7 shows as a Blocker (its latest version needs PHP 8.3), which a listing screenshot shouldn't single out. Added for 1.1.0: a third made-up plugin, "Shop Price Tweaks" (custom code using `money_format()`, removed in PHP 8.0), so What to do first shows the "send the findings to its developer" step; Old Widget Pack's update is now staged by a filter in the site's `mu-plugins/screenshots.php` instead of the update transient, which expires.

Screenshots 3 and 5 were recaptured on 22 Oct 2026 for 1.1.2 (new header with the tab bar inside it): `node scripts/capture-screenshots.mjs 3 5`, same setup as above. Screenshots 1 and 2 still show the older tab style; recapture them when the start or progress screens next change.

**Renumbered 25 Oct 2026** so the listing leads with the results (the first screenshot is the one most people see): old 3→1, 4→2, 8→3, 1→4, 2→5, 5→6, 6→7, 7→8. The dated notes above use the numbers of their day. The website's "How it works" section shows the start screen (`HOW_SHOT = 4` in `site/src/pages/index.astro`). Screenshots 4 (start) and 5 (progress) still show the older tab style.
