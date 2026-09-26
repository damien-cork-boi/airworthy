# Step 1: Engine test findings

Run 2026-09-26/27 on local PHP 8.5.11 and WordPress 7.1.2 (SQLite). This folder is throwaway test code, not plugin code.

## Verdict: the engine works, but only with PHPCompatibility 10 (alpha)

| | PHPCompatibility 9.3.5 (stable, Dec 2019) | PHPCompatibility 10.0.0-alpha2 on PHPCS 4.0.4 |
|---|---|---|
| PHP 8.0 cases | 15/19, with removals reported only as *warnings* | 19/19 |
| PHP 8.1 | 0/12 | 11/12 |
| PHP 8.2 | 0/7 | 4/7 |
| PHP 8.3 | 0/5 | 5/5 |
| PHP 8.4 | 0/12 | 12/12 |
| PHP 8.5 | 0/14 | 11/14 |
| **Total** | **15/69** | **62/69 (90%)** |

The stable release can't be used for PHP 8. **Use 10.0.0-alpha2** and pin the exact version.

Real cases v10 misses. Each was confirmed by running it on PHP 8.5 (`fixtures/coverage/`):
- 8.1: `ArrayAccess`/`Iterator` etc. methods with no return type ("ReturnTypeWillChange"). **Very common in WP plugins; worth writing our own sniff for.**
- 8.2: `mb_convert_encoding(..., 'HTML-ENTITIES')`, `"self::method"` callables, dynamic properties (runtime-only, so Stage 2's job).
- 8.5: `case X;` (semicolon), `null` array offset, `PDO::MYSQL_ATTR_*` constants.

Answer to the open question on ruleset coverage: coverage is good, with 6 static gaps worth filling. Write them as our own small sniffs first, then offer them upstream. That's cheaper and quicker than waiting on the upstream project, and it strengthens the pitch.

## Accuracy on real code (30 most popular WP.org plugins, target 8.4)

- **198 error-level findings. Almost all are guarded fallback code** that never runs on PHP 8 (behind `function_exists`, `extension_loaded`, engine switches). A plain scan would wrongly label Wordfence, UpdraftPlus, Site Kit and All-in-One WP Migration as **Blockers**. This is the old tool's false-positive problem, reproduced.
- Three cheap signals explain **171 of 198**: an author `phpcs:ignore PHPCompatibility…` comment (66), a guard within the 12 lines above (61), or a known engine-switching library such as phpseclib (104). Findings can match more than one signal (`guards.php`).
- Of the remaining 27, 22 are AIOWPM's MySQL class, which is guarded in *another file*. That's a false positive a line-level check can't see.
- **Real bugs found:** Essential Addons (1M+ installs) and a Rackspace library bundled in UpdraftPlus call `implode()` with reversed arguments, which is a fatal TypeError on PHP 8 (confirmed). The scanner finds real breakage in popular plugins, which is good material for the launch post.
- Verdict prototype (`verdicts.php`): **36/36 fixture verdicts correct** across 8.0–8.5, including guarded code downgraded to Warnings and large or unreadable files marked Unknown.
- CLI parity: **419/420 findings identical** over 1,744 files. The one difference is a cross-file sniff (`RemovedSerializable`) whose result depends on which files it has already seen. See build notes.

## Performance (30 plugins, 22,040 PHP files)

- Standalone: 145 s total, median 2.5 ms/file, p99 62 ms, process peak **27.6 MB**.
- **Inside WordPress, 128 MB limit, 20-second batches:** 9 requests, 167 s, request peak **13–42 MB**, no fatal errors.
- **Inside WordPress, 64 MB limit:** 8 requests, 157 s, no fatal errors (139 large files deferred by the memory check).
- Engine boot costs about 15 ms per request, so frequent small batches are cheap.
- Shared hosting CPUs are maybe 3–5× slower, which means roughly 8–15 minutes in the background for a 30-plugin site. That fits the spec.

## Bugs found and fixed. The plugin must do all of these

1. **Memory leak:** PHPCSUtils' static caches grow by about 55 KB per file (1.2 GB after 22k files). Call `PHPCSUtils\Internal\Cache::clear()` and `NoFileCache::clear()` after every file. With that, 231 MB → 14 MB for WooCommerce.
2. **Peak memory ≈ up to 380× file size** (measured on 22k files). A 363 KB charset table needs 134 MB. A fixed size limit isn't enough. Check memory per file at runtime: estimate `bytes × 450 + 2 MB` against `memory_limit − usage − 16 MB`.
3. **Crash-safe cursor:** a fatal error mid-batch lost the cursor, so the queue would retry the same file forever. Save an "in-flight file" marker before each file. The next batch marks that file Unknown and continues. Tested: 10 forced crashes, scan still completed.
4. **Skip generated data files** (Symfony polyfill charset tables, Composer classmaps). They contain no logic, and they're the heaviest files. That's 172 files on this site.
5. **Check readability first:** the PHPCS 4.0.4 CLI itself crashes on an unreadable file.
6. Register rulesets in code (`setConfigData('installed_paths', …, true)`). It's static in PHPCS 3 and an instance method in 4. Never write `CodeSniffer.conf`.
7. Use `--ignore-annotations` so authors' `phpcs:ignore` comments can't hide issues, but **read those comments ourselves** as the "author knows this is guarded" signal.
8. Scan with a single `testVersion` equal to the target (e.g. `8.4`), not a range.

## Recommendations for the build (Step 2 onwards)

- **Verdict design:** unguarded error → Blocker. Guarded error → a separate "Guarded legacy code (won't run on PHP 8)" count, shown but not treated as a blocker. This is the main accuracy advantage over the old tool.
- **Deferred large files:** instead of making the whole plugin Unknown, retry each deferred file alone in a fresh request at the end of the scan. Only files that still don't fit become "not checked", and the verdict says "partially scanned (N files not checked)". 16 files at 128 MB, and nearly all would fit in a fresh request.
- **Cross-file sniff:** before the batches, do a quick pass per plugin that collects interfaces extending `Serializable`, and pass them in as the sniff's property.

## Open questions this answers

- **Ruleset coverage:** 90% with v10 alpha. Write our own sniffs for the 6 static gaps. The dependency on an alpha release is a risk to monitor.
- **Minimum PHP version:** PHPCS 4 needs **PHP ≥ 7.2**, so the plugin's minimum is PHP 7.2 (PHPCS 3 would allow 5.4, but PHPCompatibility 10 requires PHPCS 4). Sites on PHP < 7.2 are a tiny share and would need the older tool anyway. The WordPress minimum is still open and should come from the WP.org stats at build time.
- **Licences:** PHPCS is BSD-3-Clause; PHPCompatibility, PHPCSUtils and the WP ruleset are LGPL-3.0-or-later. Both are generally considered GPL-compatible, including for commercial sale. With LGPL-3 the combined plugin effectively ships under GPLv3, which is fine with WP.org's "GPLv2 or later". Not legal advice; get a quick check before selling.
- **Local vs remote:** local is fine for Stage 1. Keep remote scanning as a later option only for the rare giant site.
- **Bundle size:** engine is about 7.4 MB unminified (PHPCS 3.8 MB, PHPCompatibility 2.8 MB, PHPCSUtils 0.8 MB). Needs checking against the WP.org 10 MB zip limit after PHP-Scoper.

## Files

- `scan.php`: in-process scanner harness (JSON out; `SCAN_CLEAR=1`, `SCAN_TRACE=1`)
- `coverage.php`: coverage matrix across 8.0–8.5 (`coverage.json`)
- `verdicts.php`: verdict prototype over fixture plugins
- `guards.php`: false-positive signal probe
- `drive.sh` plus `wp/wp-content/mu-plugins/sua-engine-probe.php`: batched scan inside WordPress (server: `sua-engine-wp` in the workspace launch.json)
- `fixtures/`: coverage files and the 6 fixture plugins
- `real-plugins/`: top 30 WP.org plugins (591 MB, delete when done)
