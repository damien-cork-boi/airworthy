# Airworthy follow-ups

Open items collected during Steps 1–8 (27 Sep 2026). Tick them off here as they are done.

**Step 8 (hardening): done 27 Sep 2026**
- [x] Multisite: tested in CI (network activation, site admins refused, network uninstall).
- [x] MySQL/MariaDB: CI runs on MySQL 8.0, and MariaDB 10.6 for PHP 7.2.
- [x] PHP 7.2 (declared minimum): CI runs the full suite on real PHP 7.2 with WordPress 6.5. Cheap shared hosting is still to test with real sites.
- [x] WordPress coding standards 0/0; Plugin Check 0 errors / 0 warnings (see docs/RELEASE.md).
- [x] Security policy (SECURITY.md), vulnerability handling (docs/VULNERABILITY-HANDLING.md), SBOM (docs/sbom.cdx.json).
- [x] Full readme.txt.
- [x] Scheduled Actions menu: kept (it's Action Scheduler's, and WooCommerce sites have it anyway); explained in the readme FAQ.
- [x] Dev asset versioning: release builds are 1.0.0 (plain asset versions); CI checks the version has no "-dev".

**New from Step 8 (before submission):**
- Public source repo: github.com/DigitalKind/airworthy created 27 Sep 2026 from `bin/export-public.sh` (no private history; safe to be public now). Readme and website link to it. Damo to switch it to public.
- Create the security@airworthywp.com mailbox (named in SECURITY.md, readme and SBOM) and test it.
- Confirm the CRA points in docs/VULNERABILITY-HANDLING.md: whether Airworthy counts as "commercial activity", and the declared support period (at least 5 years from 1.0.0).
- Licence: the distributed zip as a whole is GPLv3 (Action Scheduler is GPLv3, PHPCompatibility LGPLv3). The readme says so. Part of the legal check below.
- Optional clean-up: use the %i identifier placeholder (WordPress 6.2+) for our table names instead of the 49 per-line Plugin Check ignores.
- WP-CLI output is English only (like WP-CLI itself); the admin screen is fully translatable. Revisit if users ask.
- Airworthy's own scan shows "Warnings" (5 deprecations, all in bundled Action Scheduler 3.9.3 and PHPCompatibility, none in our code). Update when Action Scheduler fixes them, or move to 4.x once WordPress 6.9+ passes ~90% of sites.

**Website (airworthywp.com, `site/`):** live on 27 Sep 2026. Cloudflare Worker `airworthywp` (static assets), deploys on push to main; nameservers moved from Hosting Ireland to Cloudflare; www redirects to the root; Email Routing forwards security@ and hello@ to Damo's Gmail; SPF and a strict DMARC (p=reject) record in place.
- Done: http redirects to https; test emails to security@ and hello@ arrive at info@digitalkind.ie (Google Workspace). Email Routing forwards there, not to Gmail.
- Lesson for future domains: remove the registrar's own email forwarding (Hosting Ireland had forwarders on for this domain) and its DNS records before or when moving to Cloudflare. Otherwise mail can still end up at the old host, and the Cloudflare Activity log stays empty.
- Optionally set build watch paths on the Worker (site/*, plugin/readme.txt, SECURITY.md, assets/*) so plugin-only commits don't rebuild the site.
- New screenshots in the rc.5+ design once the plugin is signed off: a fresh demo site with a neutral plugin mix (made-up Blocker/guarded plugins, one genuinely closed plugin, none of the plugins with unreported bugs); recapture 1–4 (+ legend or closed box), update readme captions, lift heldScreenshots, show more on the home page (replace the Example panel), check the banner still matches.
- Screenshots 2–4 are held off the site (`heldScreenshots` in site/src/config.ts) until the implode() bugs are reported and fixed, or the screenshots are recaptured without those plugins.
- Set `listed: true` in site/src/config.ts once WordPress.org approves the plugin.
- Launch headline: the UpdraftPlus finding is in library code UpdraftPlus never calls (latent, not reachable). Reword "the 2 real bugs" (readme "Fewer false alarms", docs/ACCURACY.md) before it's used publicly.

**Before launch / submission:**
- Report the two reversed implode() bugs (fatal on PHP 8) to the authors before any public mention: Essential Addons for Elementor includes/Elements/Advanced_Data_Table.php:1934, and UpdraftPlus's bundled Rackspace library vendor/rackspace/php-opencloud/lib/OpenCloud/ObjectStore/Service.php:180.
- Create the WordPress.org account "digitalkind" (readme Contributors must be a real username); confirm the "airworthy" slug on submission.
- Launch headline, once the bugs are fixed: "We scanned the 30 most popular WordPress plugins for PHP 8.4. A basic scan flags 4 as broken, and 3 of those are false alarms. Airworthy finds the 2 real bugs." Evidence: airworthy/docs/ACCURACY.md.
- Quick legal check that bundling LGPL-3.0 and BSD code in a commercially sold GPL plugin is fine.
- Before selling Pro: a solicitor to draft terms of sale (warranty, limitation of liability, refunds, EU consumer rights) and review the website's legal notice. The free plugin relies on the GPL's no-warranty terms plus the in-plugin and website disclaimers (added 27 Sep 2026).
- Update Targets::SECURITY_UNTIL (PHP support dates) against php.net at every release.

**Post-launch:**
- Full namespaced class-name resolution in LoadGraph (FQCN keys, `use … as` aliases, `X::class` passed to a container, a hash_algos() capability check). This fixes WooCommerce's false Blocker at target PHP 8.0 only. See docs/ACCURACY.md "Post-launch".
- Write our own sniffs for the static coverage gaps: 8.1 ArrayAccess return types (ReturnTypeWillChange), 8.2 mb_convert_encoding HTML-ENTITIES and "self::" callables, 8.5 `case X;` semicolons, null array offsets and PDO::MYSQL_ATTR_* constants, plus array_is_list() not being flagged at target 8.0.
- Stage 2+ (runtime logging and later stages), per the spec.
- Pro: its own top-level "Airworthy" admin menu with a count badge for new Blockers, once monitoring makes it something people check regularly. The free plugin stays under Tools (occasional use; WordPress.org reviewers and users dislike sidebar clutter), plus the Site Health check.
- Pro: every PHP version in one scan. PHPCompatibility takes a testVersion range, so one pass can report findings per target (8.0 to 8.5). Show a plugins × versions table, a "highest safe version" headline and the version each plugin starts to break on. The free plugin keeps one version per scan (the Check against PHP picker). See the business plan's Airworthy Pro table.
