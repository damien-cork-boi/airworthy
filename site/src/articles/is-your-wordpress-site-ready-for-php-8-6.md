---
title: Is your WordPress site ready for PHP 8.6?
slug: is-your-wordpress-site-ready-for-php-8-6
description: PHP 8.6 removes no functions, so most sites will keep working, but it deprecates a number of older features and tightens some behaviour. What changes, and how to check your plugins and theme before you switch.
date: 2026-11-19
draft: true
---

PHP 8.6 comes out today, 19 November 2026. If your host offers it, should you switch? Here is what changes for WordPress sites, in plain English, and how to check yours in a few minutes.

## The short answer

**PHP 8.6 is a gentle release.** It doesn't remove any functions or extensions, so most sites that work on PHP 8.5 will keep working on 8.6. There are two kinds of change to know about:

1. **Deprecations.** A number of older features are marked as on their way out. They still work, but PHP logs a notice each time they run, and a future version will remove them.
2. **Small behaviour changes.** A few functions are stricter or behave slightly differently. These mostly affect unusual code.

The deprecations are the part a tool can find by reading code. The behaviour changes mostly depend on the data a site handles while it runs, so they're the reason to try a new PHP version on a copy of your site first.

## What's deprecated in PHP 8.6

These are the ones most likely to turn up in WordPress plugins and themes:

- `spl_object_hash()`: use `spl_object_id()`.
- `is_long()`, `is_integer()` and `is_double()`, and `doubleval()`: use `is_int()`, `is_float()` and `floatval()`.
- The multibyte regular-expression functions (`mb_ereg()`, `mb_ereg_replace()`, `mb_split()` and friends): use the `preg_*` functions with the `u` modifier.
- `strcoll()`, `metaphone()`, `spl_classes()`, `mysqli_get_charset()`, `mysqli_stmt_init()` and the `SORT_LOCALE_STRING` sort flag.
- `return` inside a `finally` block, and returning a value from a constructor or destructor.
- A class constant named `namespace`, a function named `readonly`, a class, function or constant named `let` or `is`, and a constant named `_`.
- Calling `is_a()` or `is_subclass_of()` with a class name as a string when its `$allow_string` argument is false.

Each of these only produces a notice in your error log. None of them stops a site from running on 8.6.

## Small behaviour changes

These aren't deprecations, so they can't be found by looking for old function names. PHP's own upgrade notes list them, and the ones a WordPress site is most likely to meet are:

- **Stricter input.** Many functions now throw an error when given a value that was always invalid but used to be quietly ignored, such as text containing a NUL byte, or an unknown option flag.
- **`trim()`, `ltrim()` and `rtrim()`** now remove form-feed characters by default as well.
- **`preg_grep()`** now returns `false` when the regular-expression engine fails (for example on badly encoded text), instead of a partial list.
- **PHP sessions** have safer defaults: strict mode on, session cookies hidden from JavaScript, and `SameSite=Lax`. WordPress doesn't use PHP sessions itself, but a few plugins do. If one of them reads its session cookie from JavaScript, or relies on cookies being sent with cross-site form posts, it will need a setting changed.

## What it means for your site

Nothing breaks the day you switch, in most cases. Deprecation notices can fill your error log, and if your site shows PHP notices on screen (it shouldn't, on a live site), visitors could see them. More importantly, each one is a job for the plugin's author before a later PHP version removes the feature.

## How to check your site

1. Install [Airworthy](https://wordpress.org/plugins/airworthy/) (free, on WordPress.org) and choose **PHP 8.6** as the version to check against.
2. Start the scan. It runs in the background and checks every plugin and your theme.
3. Anything that uses an 8.6 deprecation shows as **Warnings**, with the file, the line and the replacement. Anything that would actually break (usually older code hit by changes in PHP 8.0 to 8.4) shows as a **Blocker**.
4. Follow "What to do first": update the plugins that have an update waiting, and send the rest to their developers.

PHPCompatibility, the open-source ruleset most PHP checkers are built on, doesn't cover PHP 8.6 yet, so Airworthy adds its own checks for the deprecations above. It does not check the behaviour changes, because they depend on values only known while the site runs. That is one more reason to test on a staging copy.

## What we found in the 30 most popular plugins

We ran those checks on the [30 most popular WordPress plugins](/findings/top-30-plugins-php-8-4/) in October, using the release candidate. **12 of the 30 use at least one PHP 8.6 deprecation, 48 uses in all, and every one is a Warning.** The most common were `spl_object_hash()` (16), a value returned from a constructor (14) and `is_integer()` (10). All are small fixes for the plugins' authors, and nothing in them stops a site from running on 8.6.

## Should you move to 8.6 straight away?

There's no rush. Moving to a version that still gets security fixes matters far more than being on the newest one.

- **If your site runs PHP 8.1 or older**, that's the upgrade to make now. On 21 October 2026 PHP [released fixes for two critical security vulnerabilities](https://www.php.net/archive/2026.php#2026-10-21-5) as new releases of PHP 8.2 to 8.5 only. PHP 8.1 and older got no release from the PHP project.
- **If your site runs PHP 8.2**, its security fixes end on 31 December 2026, so plan the move soon. Any of 8.3, 8.4, 8.5 or 8.6 will do.
- **If your site runs PHP 8.3 to 8.5**, you can wait until your host offers 8.6 and your plugins have had a few weeks to catch up.

When you do switch, back up first, try it on a staging copy if your host offers one, and keep the way back open. Most hosts let you change the PHP version again from their control panel.

Airworthy is free and runs inside WordPress. It needs no account, and the only thing it ever sends out is an optional look-up of plugin folder names on WordPress.org. There is more in the [docs](/docs/).
