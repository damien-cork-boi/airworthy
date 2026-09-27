# Accuracy: verdicts, guard detection and known gaps

Status as of Step 4 (27 Sep 2026). Engine: PHPCompatibility 10.0.0-alpha2 on PHP_CodeSniffer 4.0.4.

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
1. **Own code:** a removed-extension finding whose flagged calls are all the plugin's own functions. PHPCompatibility matches removed extensions by prefix, so `sqlite_plugin_init()` looks like the old `sqlite` extension. Stored as an informational notice with no verdict impact.
2. **Guarded:** the line is in code that can't run on the target:
   - inside an `if`/`elseif` branch whose condition is false on the target, an `else` branch whose condition is true, code after an early `return`/`exit`/`throw` in an `if` body, or after `condition &&` in the same statement;
   - conditions are version checks (`PHP_VERSION_ID`, `PHP_MAJOR_VERSION`, `version_compare( PHP_VERSION, … )`) evaluated against the target, or capability checks (`function_exists`, `extension_loaded`, `class_exists`, `defined`, `is_callable`, `method_exists`) that name the flagged thing itself;
   - or the file is in a known engine-switching library (phpseclib, Symfony polyfills, paragonie random_compat / sodium_compat).
3. **Suppressed:** an author annotation covers the rule on that line. A PHPCompatibility 9-era annotation on the removed-*extension* rule also covers the version 10 removed-*function* twin on the same line.
4. **Plain:** everything else.

Also:
- Rules ending `.Changed` (behaviour changes such as `func_get_args()`) count as warnings, not errors.
- PHPCS "mixed line endings" messages are dropped.
- "No PHP code found" (short open tags) is a notice.
- Generated data files (charset tables, Composer and Jetpack classmaps, `.l10n.php`) and test suites inside bundled libraries are never scanned.

## Fixtures (`engine-test/fixtures/plugins`): 72/72 correct for 8.0–8.5

| Fixture | Expected | Result |
|---|---|---|
| fx-ready | Ready | ✓ all targets |
| fx-blocker-80 (create_function in vendor/) | Blocker | ✓ |
| fx-guarded (6 real guard forms) | Guarded | ✓ |
| fx-fake-guard (5 fake guards) | **Blocker** | ✓ |
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
| Guarded | 110 |
| Suppressed by author | 61 |
| Plain | 24 |

Plugin verdicts: 3 Blocker, 2 Suppressed, 3 Guarded, 14 Warnings, the rest Ready. A naive scan would call Wordfence, UpdraftPlus, Site Kit and All-in-One WP Migration Blockers, and three of those four are wrong.

### The 24 plain findings, each marked

| Plugin | File:line | Finding | Mark |
|---|---|---|---|
| Essential Addons for Elementor | includes/Elements/Advanced_Data_Table.php:1934 | `implode()` with reversed arguments | **Real bug**: fatal TypeError on PHP 8 |
| UpdraftPlus (bundled Rackspace php-opencloud) | vendor/rackspace/php-opencloud/lib/OpenCloud/ObjectStore/Service.php:180 | `implode()` with reversed arguments | **Real bug**: fatal TypeError on PHP 8 (error path only) |
| All-in-One WP Migration | lib/vendor/servmask/database/class-ai1wm-database-mysql.php: 51, 57, 69, 83, 92, 101, 114, 127, 137, 147, 157 (22 findings: function and extension twins) | `mysql_*` calls | **Detection gap**: the class is only created when `empty( $wpdb->use_mysqli )`, which is never true on PHP 7+. The guard is in another file (class-ai1wm-database-utility.php:85), where per-file analysis can't see it. |

Both real bugs should be reported to the authors, with file and line, before they're mentioned publicly (spec open question).

### Known gaps and options

1. **Guards in another file** (AIOWPM): a class only created behind a dead branch elsewhere. Options: (a) cross-file analysis: find where the class is created, and if every creation is inside a dead branch, treat the class file as guarded; (b) a small curated list of known false positives by plugin, file and version, updated with plugin releases. (a) is general but more work; (b) is quick but needs maintenance.
2. **Runtime-flag guards** such as UpdraftPlus's `if ( $this->use_mysqli ) … else mysql_*()`: can't be proven statically. They're only Suppressed where the author annotated them. Stage 2's runtime logging settles these.
3. **Coverage gaps from Step 1** (static): the 8.1 `ArrayAccess` return types, 8.2 `HTML-ENTITIES` and `"self::"` callables, and the 8.5 `case;`, null offset and `PDO::MYSQL_ATTR_*` cases. Planned as our own sniffs (see ENGINE.md).
