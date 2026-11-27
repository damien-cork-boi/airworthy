---
title: PHP 8.2 end of life on 31 Dec 2026: what to do
slug: php-8-2-end-of-life-wordpress
description: PHP 8.2 stops getting security fixes on 31 December 2026. What that means for your WordPress site, which version to move to, and how to do it safely.
date: 2026-11-27
draft: false
---

PHP 8.2 stops getting security fixes on 31 December 2026. If your WordPress site runs on it, you have about five weeks. Here is what that really means, which PHP version to move to, and how to do it without breaking your site.

## The short answer

**Nothing breaks on 1 January.** Your site keeps working. What changes is that the PHP project stops releasing security fixes for 8.2, so any new vulnerability found in it stays open on your server. Moving to a newer PHP version before then is the single most useful thing you can do about it, and it is usually a few clicks in your host's control panel.

## What "end of life" means

Every PHP version goes through two stages. First, two years of bug fixes and security fixes. Then two more years of security fixes only. PHP 8.2 finished its first stage on 31 December 2024. The second one ends on 31 December 2026, and after that the PHP project releases nothing more for it.

That matters because security fixes are not rare. On 21 October 2026, PHP [released fixes for two critical security vulnerabilities](https://www.php.net/archive/2026.php#2026-10-21-5) as new releases of PHP 8.2 to 8.5 (the 8.2 one is 8.2.35). After 31 December, releases like that stop for 8.2. Anyone who finds a flaw in it can use it against every site still running it, and nobody upstream will fix it.

One nuance: some hosts keep patching an old PHP version themselves for a while, and ship the fix under the same version number. Ask yours. Don't rely on it, though, because you can't see from the outside whether it happens.

## Which version to move to

All of these still get security fixes, and the dates are from [php.net](https://www.php.net/supported-versions.php):

| PHP version | Security fixes until |
| --- | --- |
| 8.3 | 31 December 2027 |
| 8.4 | 31 December 2028 |
| 8.5 | 31 December 2029 |
| 8.6 | 31 December 2030 |

The smallest jump is **PHP 8.3**, and WordPress's own requirements page recommends 8.3 or newer. But it ends a year from now, so you would be doing this again in 2027. **PHP 8.4** gives you two more years for roughly the same amount of work. Whichever you pick, the deciding factor is your plugins: the newer the version, the more likely an old plugin hits something it doesn't support.

You are starting from a good place. PHP 8.2 already made one of the biggest recent changes, logging a notice whenever code creates a property on an object that wasn't declared. A site that runs cleanly on 8.2 has already got through that, and most of what is left between 8.2 and 8.4 is small, such as older code that declares a parameter as optional by giving it a `null` default without saying that `null` is allowed. PHP 8.4 logs a notice for that. It doesn't stop the site.

## Step 1: find out what you run now

Your PHP version is in WordPress under **Tools > Site Health > Info > Server**. Your host's control panel shows it too. [Airworthy](https://wordpress.org/plugins/airworthy/) also shows it on its start screen, together with how long that version has left.

## Step 2: check your plugins and theme first

Almost every problem after a PHP upgrade comes from a plugin or theme that hasn't kept up. You can find most of them before you switch, in a few minutes:

1. Install Airworthy (free, on WordPress.org). It suggests the smallest sensible upgrade for your site, and you can pick PHP 8.3, 8.4, 8.5 or 8.6 instead.
2. Start the scan. It runs in the background and reads every plugin and your theme. It doesn't run them.
3. Each one comes back as **Ready**, **Warnings** (works now, will need updating later) or **Blocker** (will not work on the version you picked).
4. Follow "What to do first". Update plugins that have an update waiting. Send the rest to their developers. If a plugin has been **closed on WordPress.org**, it won't be fixed, so plan to replace it.

A scan reads code. It can't see everything that happens while a site runs, which is why the next step matters.

## Step 3: switch carefully

1. **Back up** the whole site, files and database.
2. **Try it on a staging copy** if your host offers one. Most managed hosts do.
3. **Switch the PHP version** in your host's control panel. In cPanel it is usually under *MultiPHP Manager*. In Plesk it is under *PHP Settings*. Managed WordPress hosts have a setting on the site's page.
4. **Click through the pages that matter**: the home page, a post, the login page, your contact form and, for a shop, the cart and checkout. Then look at the PHP error log for new notices.
5. **Keep the way back open.** If something breaks, switch the version back in the control panel, then deactivate plugins in halves until you find the one responsible.

If you run a shop, choose your day with care. Don't switch in the middle of a sales rush without a tested copy. The first half of December leaves you time to fix things before the deadline.

## What if your host does it for you

Many hosts move everyone off an old PHP version around its end of life, sometimes with only an email as warning. If you get one, don't ignore it. Run the check above while you still have time to update or replace plugins, because a host-forced switch lands whether your plugins are ready or not.

## What if you can't move yet

If a plugin you depend on blocks the upgrade, you have three honest options. Ask its developer for a fixed version. Replace it. Or ask your host whether they keep patching PHP 8.2 past its end of life, and treat that as a short bridge, not a plan.

There is more on what each Airworthy result means in the [docs](/docs/), and on the newest PHP version in [Is your WordPress site ready for PHP 8.6?](/articles/is-your-wordpress-site-ready-for-php-8-6/)
