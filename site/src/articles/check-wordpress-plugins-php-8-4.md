---
title: Check your WordPress plugins for PHP 8.4
slug: check-wordpress-plugins-php-8-4
description: How to find out whether your WordPress plugins and theme will work on PHP 8.4 before you switch: what to check, what the results mean, and how to test.
date: 2026-11-27
draft: false
---

You want to move your site to PHP 8.4, or your host is about to do it for you. The worry is always the same: will every plugin and the theme still work? You can find out before you switch, and you don't need to read any code yourself. Here is how.

## The short answer

You need two checks, and neither is enough alone:

1. **A scan of the code**, which finds the things PHP 8.4 removed or changed, in every plugin and your theme, in a few minutes.
2. **A test on a copy of your site**, which finds the problems that only show up while the site runs.

The scan tells you where to look. The test tells you whether it matters.

## Why the plugin's page can't tell you

A plugin's page on WordPress.org shows **Requires PHP**, which is the oldest PHP version it supports. It does not say which is the newest one it works on. And **Tested up to** is about WordPress versions, not PHP versions. So "this plugin is up to date" doesn't tell you whether it runs on 8.4. Some authors say so in their changelog. Most don't.

## What changed in PHP 8.4

Most of PHP 8.4 is new features. The changes that can affect an existing WordPress site fall into two groups. Both lists come from [PHP's own migration notes](https://www.php.net/manual/en/migration84.php).

**Deprecated: the site keeps working, but PHP logs a notice.** The ones most likely to turn up in plugins and themes:

- **Implicitly nullable parameters.** A function written as `function save( Post $post = null )` should now say `?Post $post = null`. This is one of the most common ones in older plugins.
- **CSV functions without an `escape` argument.** `fputcsv()`, `fgetcsv()` and `str_getcsv()` now want it passed explicitly. Plugins that export or import CSV files are the usual place to find this.
- **`trigger_error()` with `E_USER_ERROR`**, and the `E_STRICT` constant.
- **`mysqli_ping()`, `mysqli_kill()` and `mysqli_refresh()`.**
- **`lcg_value()`**, and changing session settings such as `session.sid_length` at runtime.

**Changed: code that relied on the old behaviour can now fail.** These are rarer, but they are the ones that stop a page:

- **IMAP, OCI8, PDO_OCI and PSpell are no longer part of PHP.** They moved to a separate download. A plugin that reads email with IMAP, or connects to an Oracle database, may fail with "call to undefined function" if your host's PHP 8.4 doesn't include the extension. Ask your host.
- **Some functions are stricter.** `round()` now throws an error for an invalid rounding mode, where it used to quietly round half up. `str_getcsv()` throws an error for a separator that isn't one byte long.
- **Regular expressions.** PHP's regular-expression library was updated, and `{,3}` is now a repeat count, not literal text.

## Step 1: scan your plugins and theme

1. Install [Airworthy](https://wordpress.org/plugins/airworthy/) (free, on WordPress.org) and open **Tools > Airworthy**.
2. Choose **PHP 8.4** as the version to check against.
3. Start the scan. It runs in the background in short batches, and it reads your plugins and theme without running them. You can leave the page.

Each plugin and theme comes back with one of these results:

- **Ready**: nothing found that would break.
- **Warnings**: works on 8.4 now, but uses something deprecated that will need updating before a later version. This is the implicit-nullable and CSV kind of finding.
- **Blocker**: some of its code will not work on 8.4. Fix these first.
- **Guarded legacy code**: old code that is only reached on old PHP versions, behind a version check, so it never runs on 8.4.
- **Unknown**: some files couldn't be read, so there is no verdict yet.

The "guarded" result matters more than it sounds. Many popular plugins carry old fallback code that a basic scan flags as broken even though it never runs. When we checked the [30 most popular WordPress plugins](/findings/top-30-plugins-php-8-4/) against PHP 8.4, a basic scan flagged 8 as broken. Only 1 really was. Telling the two apart is the difference between an afternoon of worry and a short to-do list.

At the top of the results, Airworthy lists what to do first: update the plugins that have an update waiting (that often clears the finding), and send the rest to their developers. For a plugin that has been **closed on WordPress.org**, plan to replace it, because it won't be fixed.

## Step 2: test on a copy of your site

A scan reads code. It can't see the values your site handles while it runs, which is where the stricter functions bite. So:

1. Put a copy of your site on **staging** and set its PHP version to 8.4. Most managed hosts have a button for this.
2. Turn on the debug log in the staging copy's `wp-config.php` by adding these three lines:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

3. Click through the pages that matter: the home page, a post, the login page, your contact form and, for a shop, the cart and checkout. Run anything that exports a file or sends an email.
4. Open `wp-content/debug.log`. Notices about deprecated features are expected and harmless today. A **fatal error** or a **warning** that shows up on a page you use is the one to chase.

Leave the debug log off on your live site once you've finished.

## If something fails

Update the plugin first, since the fix is often already released. If there is no update, send the developer the file and line from the scan. If neither works, replace the plugin, or stay on your current PHP version a little longer while you do. Don't leave it: your current version will eventually stop getting security fixes.

## Developers and agencies

The same check runs from the terminal, so you can put it in a deploy script: `wp airworthy scan --target=8.4 --fail-on=blocker` exits with an error if any plugin would break on 8.4. There is more in the [docs](/docs/).

If PHP 8.2 is what your site runs today, its security fixes end on 31 December 2026. [What to do about it](/articles/php-8-2-end-of-life-wordpress/) covers which version to move to and how to switch safely.
