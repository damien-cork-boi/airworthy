# Airworthy follow-ups

Open items collected during Steps 1–6 (27 Sep 2026). Tick them off here as they are done.

**Step 8 (hardening):**
- Test multisite for real. The network-admin placement (Settings > Airworthy), network-only activation and shared tables are built but untested on a multisite network.
- Test on real MySQL/MariaDB. Every test so far ran on SQLite (the local test site uses the SQLite drop-in).
- Test on an old-PHP host (PHP 7.2, the declared minimum) and on cheap shared hosting.
- Run WordPress coding standards and the WordPress.org Plugin Check.
- Publish a security policy and vulnerability-reporting contact (EU Cyber Resilience Act). Use airworthywp.com / digitalkind.ie.
- Write the full readme.txt description (currently a placeholder).
- Optionally hide Action Scheduler's "Tools > Scheduled Actions" menu, which now shows on sites without WooCommerce.
- Dev builds (version ending "-dev") append the file's modified time to asset versions to avoid caching; release builds use the plain version. Check this before release.

**Before launch / submission:**
- Report the two reversed implode() bugs (fatal on PHP 8) to the authors before any public mention: Essential Addons for Elementor includes/Elements/Advanced_Data_Table.php:1934, and UpdraftPlus's bundled Rackspace library vendor/rackspace/php-opencloud/lib/OpenCloud/ObjectStore/Service.php:180.
- Create the WordPress.org account "digitalkind" (readme Contributors must be a real username); confirm the "airworthy" slug on submission.
- Launch headline, once the bugs are fixed: "We scanned the 30 most popular WordPress plugins for PHP 8.4. A basic scan flags 4 as broken, and 3 of those are false alarms. Airworthy finds the 2 real bugs." Evidence: airworthy/docs/ACCURACY.md.
- Quick legal check that bundling LGPL-3.0 and BSD code in a commercially sold GPL plugin is fine.
- Update Targets::SECURITY_UNTIL (PHP support dates) against php.net at every release.

**Post-launch:**
- Full namespaced class-name resolution in LoadGraph (FQCN keys, `use … as` aliases, `X::class` passed to a container, a hash_algos() capability check). This fixes WooCommerce's false Blocker at target PHP 8.0 only. See docs/ACCURACY.md "Post-launch".
- Write our own sniffs for the static coverage gaps: 8.1 ArrayAccess return types (ReturnTypeWillChange), 8.2 mb_convert_encoding HTML-ENTITIES and "self::" callables, 8.5 `case X;` semicolons, null array offsets and PDO::MYSQL_ATTR_* constants, plus array_is_list() not being flagged at target 8.0.
- Stage 2+ (runtime logging and later stages), per the spec.
