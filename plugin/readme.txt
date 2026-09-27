=== Airworthy – PHP Compatibility & Upgrade Checker ===
Contributors: digitalkind
Tags: php, compatibility, upgrade, php 8, health check
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find out which plugins and themes will break on a newer PHP version, and which look abandoned, before you upgrade.

== Description ==

Development build. The full description is written in the hardening step (Step 8).

= Privacy =

This plugin reads your plugin and theme files on your own server; it never runs them and never sends them anywhere.

To show whether a plugin is still maintained, it looks up each plugin's slug (its folder name) in the public WordPress.org plugin directory (api.wordpress.org). Nothing else is sent: not your site's address (the requests use their own "Airworthy" user agent instead of WordPress's default, which includes your site URL), not your plugin list, not your PHP version. Answers are cached for 24 hours.

== Screenshots ==

1. Pick the PHP version to check against. Airworthy recommends the oldest version that still gets security fixes, and shows each version's support dates.
2. Scans run in the background in small batches, so they never time out. You can leave the page.
3. Results: a verdict for every plugin and theme, with a plain explanation, WordPress.org maintenance details, and plugins removed from WordPress.org called out.
4. Details show each finding with its file, line and message. Old code that can't run on the chosen PHP version is marked as guarded, not as a problem.

== Changelog ==

= 0.1.0 =
* Development build.
