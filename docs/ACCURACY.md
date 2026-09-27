# Accuracy: verdicts, guard detection and known gaps

Status as of Step 4 plus the cross-file check (27 Sep 2026). Engine: PHPCompatibility 10.0.0-alpha2 on PHP_CodeSniffer 4.0.4.

## Verdicts

| Verdict | Meaning |
|---|---|
| Blocker | At least one plain error for the target version. Wins over Unknown: a known break is known. |
| Unknown | A file couldn't be checked (unreadable, crashed twice, too large, or unparseable), even after its single retry. |
| Suppressed by author | Errors silenced only by the author's `phpcs:ignore` / `phpcs:disable`. Shown as lower confidence, never hidden. |
| Guarded legacy code | Errors only in code that won't run on the target PHP version. Shown, not a Blocker. |
| Warnings | Deprecations only: works today, will break later. |
| Ready | Nothing found by the static scan. |

Always one exact target version (e.g. 8.4), never a range.

## How a finding is classified (`plugin/src/Scan/Classifier.php`)

In this order:
1. **Own code:** the flagged name is provided by the plugin itself. Stored as an informational notice with no verdict impact.
   - A removed-extension finding whose flagged calls are all the plugin's own functions. PHPCompatibility matches removed extensions by prefix, so `sqlite_plugin_init()` looks like the old `sqlite` extension.
   - A "new in PHP x" / "removed in PHP x" function, constant or class the plugin declares in the global namespace: polyfills such as Symfony's `str_contains()` or `INTL_IDNA_VARIANT_2003`, PHP_CodeSniffer's `defined( 'T_ENUM' ) || define( … )`, or an old plugin's own `each()`. Deprecations and keyword/syntax rules still count.
2. **Guarded:** the line is in code that can't run on the target:
   - inside an `if`/`elseif` branch whose condition is false on the target, an `else` branch whose condition is true, code after an early `return`/`exit`/`throw` in an `if` body, or after `condition &&` in the same statement;
   - conditions are version checks (`PHP_VERSION_ID`, `PHP_MAJOR_VERSION`, `version_compare( PHP_VERSION, … )`) evaluated against the target, or capability checks (`function_exists`, `extension_loaded`, `class_exists`, `defined`, `is_callable`, `method_exists`) that name the flagged thing itself;
   - or the file is in a known engine-switching library (phpseclib, Symfony polyfills, paragonie random_compat / sodium_compat).
   - **Cross-file** (`plugin/src/Scan/LoadGraph.php`, errors only): the whole file's code can't run on the target:
     - *Declaration-only files.* The file only declares classes/interfaces/traits (plus the ABSPATH exit check), so loading it runs nothing, and every use of its classes (`new C`, `C::`) elsewhere in the component is itself in dead code. Example: All-in-One WP Migration's MySQL driver is only created after `if ( PHP_MAJOR_VERSION >= 7 ) { return new …Mysqli; }`.
     - *Conditionally included files.* Every `include`/`require` of the file is in dead code. This only applies when every include in the component resolves to a file: `__DIR__`, `__FILE__`, `dirname()`, `plugin_dir_path()`, and constants built from those.
     - Conservative by design: a class that is extended, implemented, named in a string, never used in the component, or possibly created dynamically (`new $class` plus a string that could build its name) never counts. Chains are followed at most 3 files deep.
3. **Suppressed:** an author annotation covers the rule on that line. A PHPCompatibility 9-era annotation on the removed-*extension* rule also covers the version 10 removed-*function* twin on the same line.
4. **Plain:** everything else.

Also:
- Rules ending `.Changed` (behaviour changes such as `func_get_args()`) count as warnings, not errors.
- PHPCS "mixed line endings" messages are dropped.
- "No PHP code found" (short open tags) is a notice.
- Generated data files (charset tables, Composer and Jetpack classmaps, `.l10n.php`) and test suites inside bundled libraries are never scanned.

## Fixtures (`engine-test/fixtures/plugins`): 84/84 correct for 8.0–8.5

| Fixture | Expected | Result |
|---|---|---|
| fx-ready | Ready | ✓ all targets |
| fx-blocker-80 (create_function in vendor/) | Blocker | ✓ |
| fx-guarded (6 real guard forms) | Guarded | ✓ |
| fx-fake-guard (5 fake guards) | **Blocker** | ✓ |
| fx-cross-file (legacy class created only after an early return on PHP 7+; compat file included only on PHP 5) | Guarded | ✓ |
| fx-cross-fake (class also used in live code; dynamic `new $class`; class used through a subclass; file also included unconditionally) | **Blocker** | ✓ |
| fx-suppressed (ignore, bare ignore, disable/enable) | Suppressed | ✓ |
| fx-implode (Essential Addons pattern) | Blocker | ✓ |
| fx-data (broken code only in data files) | Ready | ✓ |
| fx-own-prefix (own sqlite_/mysql_ functions) | Ready | ✓ |
| fx-unreadable | Unknown | ✓ |
| fx-symlink (links out of the plugins folder) | Ready, links never followed | ✓ |
| fx-large (2 MB) | Ready or Unknown, never a hang | ✓ Ready with 1 GB, Unknown when memory is low |
| fx-warnings | Ready on 8.0, Warnings on 8.1+ | ✓ |

## Top 30 WordPress.org plugins, target 8.4

195 error-level findings (a naive scan counts 198 and calls them all Blockers):

| | Count |
|---|---|
| Guarded (132, including 22 via the cross-file check) | 132 |
| Suppressed by author | 61 |
| Plain | 2 |

Plugin verdicts: **2 Blocker** (both real bugs), 2 Suppressed (Jetpack, Wordfence), 4 Guarded (All-in-One WP Migration, Google Site Kit, WP Mail SMTP, WPForms Lite), 12 Warnings, 10 Ready. A naive scan would call Wordfence, UpdraftPlus, Site Kit and All-in-One WP Migration Blockers (three of those four wrongly) and would miss Essential Addons among the other errors.

### The plain findings, each marked

| Plugin | File:line | Finding | Mark |
|---|---|---|---|
| Essential Addons for Elementor | includes/Elements/Advanced_Data_Table.php:1934 | `implode()` with reversed arguments | **Real bug**: fatal TypeError on PHP 8 |
| UpdraftPlus (bundled Rackspace php-opencloud) | vendor/rackspace/php-opencloud/lib/OpenCloud/ObjectStore/Service.php:180 | `implode()` with reversed arguments | **Real bug**: fatal TypeError on PHP 8 (error path only) |

Fixed since the first Step 4 run: All-in-One WP Migration's 22 `mysql_*` findings (the class is only created behind a dead branch in another file) are now Guarded by the cross-file check.

Both real bugs should be reported to the authors, with file and line, before they're mentioned publicly (spec open question).

### Known gaps and options

1. **Guards in another file**: handled by the cross-file check for declaration-only files and conditional includes (see above). Not covered: inheritance chains, dynamic class names, and includes the check can't resolve. Those are deliberately left as plain.
2. **Runtime-flag guards** such as UpdraftPlus's `if ( $this->use_mysqli ) … else mysql_*()`: can't be proven statically. They're only Suppressed where the author annotated them. Stage 2's runtime logging settles these.
3. **Guards behind a helper function, at target 8.0 only**: WooCommerce ships PHP 8.1 syntax (enums, readonly properties) in its new `src/Api` code, and only switches it on when `Main::is_enabled()` returns `PHP_VERSION_ID >= 80100 && …`. Following a guard through a method call, and through autoloaded classes imported with `use`, isn't supported, so WooCommerce is a false Blocker at target **8.0**. It's correct at 8.1 and above, which is where almost every upgrade goes. Fix option: treat simple predicate methods (`return <version check> && …;`) as conditions at their call sites.
4. **Coverage gaps from Step 1** (static): the 8.1 `ArrayAccess` return types, 8.2 `HTML-ENTITIES` and `"self::"` callables, and the 8.5 `case;`, null offset and `PDO::MYSQL_ATTR_*` cases. Planned as our own sniffs (see ENGINE.md).

## WordPress.org signals (Step 5, `plugin/src/Wporg/`)

| Signal | Rule |
|---|---|
| Closed | "Removed from WordPress.org", with date and reason (e.g. Security Issue); shown prominently |
| Last updated | Abandoned when there's been no update for 2+ years |
| Tested up to | Warning when 3+ WordPress feature releases behind the latest |
| Requires PHP | **Blocker** when above the target PHP version |
| Update available | From core's own update check, or the WordPress.org version |
| Not listed | Premium, custom, `Update URI` elsewhere, or a folder-name clash with a different WordPress.org plugin. Never an error |

Identification: WordPress core's update check (`w.org/plugins/<slug>`) first, then the folder name. The names must match either way, because core itself matches by folder name (a custom plugin in `calendar/` gets WordPress.org's "Calendar"). Name matching compares words: every word of the shorter name appears in the longer one, or at least 2 leading words are shared ("Ultimate Addons for Elementor (UAE)" = "Ultimate Addons for Elementor – Widgets, …"). Result: 30/30 top plugins matched; "calendar", "WP Super Cache" vs "WP Super Edit", "Elementor Pro" vs "Elementor" and similar look-alikes correctly rejected.

Privacy, verified by logging every request: only the slug is sent, the User-Agent is `Airworthy/<version>` (never WordPress's default, which carries the site URL), and 0 of 50 requests contained the site address. Cached 24 hours; a second scan made 0 requests. Offline: the scan completes, reports "offline", and gives up after 2 failed lookups in a row; failures aren't cached.
