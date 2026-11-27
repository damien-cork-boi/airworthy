=== Airworthy Pro ===
Contributors: digitalkind
Tags: php, php 8, compatibility, upgrade, report
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.2
Requires Plugins: airworthy
Stable tag: 0.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Monitoring and alerts, an after-the-switch watch, a zip checker, fix briefs, reviews, an upgrade plan and client reports.

== Description ==

Airworthy tells you which plugins and themes will break on a newer PHP version. Airworthy Pro helps you do the upgrade, show your clients, and plan ahead.

* **Monitoring and email alerts.** Airworthy checks the site again on a schedule (daily, weekly or monthly) and after any plugin or theme update, and emails you when something new would break on your PHP version. A history shows what changed since the last check: new Blockers, fixed ones, plugins added or removed. It also emails once when your PHP version is 90, 30 and 7 days from the end of security fixes, or is missing the latest security release.
* **After-the-switch watch.** Start it before your host switches PHP. Airworthy notes how your home page, newest post, login page and REST API answer today (and the cart and checkout with WooCommerce), notices the switch by itself, then for 24, 48 or 72 hours records the PHP errors, warnings and deprecations your site raises, names the plugin or theme behind each, and tells you which are new on the new PHP version and which were already there. It checks your pages again after the switch and emails you if something breaks, and when the watch is over.
* **Check a zip before you install it.** Upload a plugin or theme zip and Airworthy tells you whether it would work on the PHP version you are heading for, and whether it is better or worse than the version you have installed. Nothing is installed or run, and the files are deleted when the check ends.
* **Network overview (multisite).** On a network, one screen shows which sites are ready for the PHP version you checked and which plugin to fix first because it is on the most sites, with a CSV to download. It reads your last scan and each site's list of active plugins and theme; nothing is scanned again.
* **Reviews with notes.** Record what you decided about each plugin or theme with a problem: you are fixing it, waiting for the developer, or accepting it, with a note on why. A review belongs to the version you looked at and is marked out of date when the plugin is updated. You choose, per note, whether the client report shows it.
* **Fix brief.** Pick a plugin or theme with problems and Airworthy writes a plain-text brief for a developer or an AI assistant: each problem with its file, line and the line itself, why PHP objects, how to fix it, and rules that keep the fix small. It is built on your site and nothing is sent anywhere.
* **Chat alerts.** Add a Slack, Discord or Teams incoming-webhook address and every monitoring alert is posted there as well as emailed.
* **Upgrade plan.** A step-by-step checklist built from your results: update these, replace those, back up, try it on staging, switch PHP, check again. Airworthy ticks steps off as it sees them happen; you tick the ones it can't see, like the backup.
* **Every PHP version at once.** One scan against the newest PHP version shows how the site does on every version from PHP 8.0 up, side by side, and the highest version it's ready for today.
* **Client reports.** A plain-words report of any scan with your logo, name and colour, ready to save as a PDF and send to your client.
* **Priority support, with a support file.** One button on the Pro tab builds a plain-text file about the site (versions, settings, plugins, recent scans, waiting jobs) for you to preview and send us. It leaves out passwords, keys, customer and order data, post content and file contents, and masks site addresses, email addresses and IP addresses.

Airworthy Pro needs the free Airworthy plugin (https://airworthywp.com/), installed and active. It adds "Pro", "Upgrade plan", "PHP versions", "Monitoring", "After the switch", "Check a zip", "Fix brief", "Reviews", "Network" (multisite) and "Client report" tabs to Tools > Airworthy.

= Privacy =

If you add a chat webhook address on the Monitoring tab, each alert's text (your site's name and the names of the plugins and themes involved) is posted to that address, and nothing else. Airworthy Pro reads the results the free plugin already has on your site, and the branding you enter is stored on your site. The after-the-switch watch, only while you run one, stores the PHP errors your site raises (with the server's folder names and web-address queries removed) and asks your site for its own pages; none of it leaves your site, and deleting the plugin removes it. The one thing it sends out is the licence check: when you activate your licence, and now and then to look for updates, your licence key, your site's address and the version numbers of Pro and its licensing code go to Freemius (freemius.com), the company that sells and licenses Airworthy Pro for us. Sharing anything more, such as your WordPress and PHP versions or your site title, is off unless you turn it on yourself. Your scan results never leave your site.

= Support =

Email hello@airworthywp.com with your site's PHP version and what you're seeing. The "Download support info" button on the Pro tab builds the file that helps us most; a CSV export of the results helps too.

== Installation ==

1. Install and activate the free Airworthy plugin.
2. Upload Airworthy Pro (Plugins > Add New > Upload Plugin) and activate it.
3. You'll land on the Pro tab, with three steps to get started.

== Changelog ==

= 0.8.0 =
* New: Network overview, on multisite only. A new "Network" tab in Network Admin shows every site's readiness for the PHP version you checked (the share of its plugins and theme with no Blocker and nothing unchecked), what needs attention on each, and a "Fix these first" list of the plugins and themes on the most sites. Download it as a CSV. It reads your last scan and each site's active plugins and theme, and scans nothing again.

= 0.7.0 =
* New: Reviews. A new "Reviews" tab lists the plugins and themes in your last scan that have problems or unchecked files. For each, record what you decided (we are fixing it, waiting for the developer, accepted) with a note, and tick whether the client report shows it. A review is for the version you looked at: after an update it is marked out of date so it is looked at again. Reviews are a record on your site; they never change what Airworthy finds.

= 0.6.0 =
* New: Fix brief. A new "Fix brief" tab writes a ready-to-paste brief for a plugin or theme with problems, for a developer or an AI assistant: each problem with its file, line and the line itself, why PHP objects, how to fix it, and rules that keep the fix small. Built on your site; nothing is sent anywhere.
* New: chat alerts. On the Monitoring tab, add a Slack, Discord or Teams incoming-webhook address (https only) and each alert is posted there as well as emailed. The alert text, which names your site and the plugins involved, goes to that address, so use one you trust. Alerts pause when a licence has ended, as before.

= 0.5.0 =
* After-the-switch watch. A new "After the switch" tab. Start a watch before PHP is switched: Airworthy checks your main pages, notices the switch on its own, records PHP errors for 24, 48 or 72 hours with the plugin or theme behind each, checks the pages again, and says what is new on the new PHP version, what was already there, and whether a page stopped working. Emails you if something breaks, and a summary at the end. "I already switched" starts a watch from now.
* Check a zip. A new "Check a zip" tab: upload a plugin or theme zip, pick the PHP version, and see whether it would work and how it compares with the version you have installed. It needs the next release of the free Airworthy plugin.
* After a licence ends, scheduled monitoring checks and alert emails now pause, as the licence terms say. "Check now", the history, reports, the plan and the watch's own results keep working.

= 0.4.0 =
* Monitoring and email alerts. A new Monitoring tab: choose daily, weekly or monthly checks, which PHP version to check against and who gets the emails. Airworthy also checks right after a plugin or theme update. It emails only when something got worse: a plugin or theme newly becomes a Blocker, optionally new Warnings, or your PHP version is close to the end of security fixes or is missing the latest security release. A check that could not finish is reported too. Checks you start by hand are saved but never emailed.
* History: the last 20 times your results changed, each with what changed since the one before (new Blockers, fixed, got worse, improved, added, removed).
* "Send a test email" and "Check now" buttons.
* Deleting Airworthy Pro now removes its history and monitoring settings.

= 0.3.1 =
* Licensing code (Freemius SDK) updated to 2.13.5, which tightens how a few messages are escaped.
* The Airworthy icon now shows on the licence activation screen.
* The privacy text now says exactly what the licence check sends: your licence key, your site's address and the version numbers of Pro and its licensing code.

= 0.3.0 =
* Licensing through Freemius. Activate your licence on the Pro tab. Until a site has a licence, only the Pro tab loads, with the steps to activate. When a licence ends, Airworthy Pro keeps working; updates, support and monitoring alerts stop. The Pro tab tells you 30 days and 7 days before the end, and after it.
* Local and staging addresses don't use up a site on your licence.

= 0.2.3 =
* Priority support card: a "Download support info" button (with a preview) builds a plain-text file of versions, settings, plugins, recent scans and waiting jobs for you to send us. Passwords, keys, customer and order data, post content and file contents are left out; site addresses, email addresses and IP addresses are masked.
* The Pro tab reads its latest scan through one prepared query.

= 0.2.2 =
* Icons on Pro's tabs, a "Got it" button on the welcome banner, and the feature list's PHP range now follows the free plugin's list of versions (it said 8.0 to 8.5).
* Colour picker swatches are Airworthy's blues.

= 0.2.1 =
* Pro's database reads go through one place; readme added.

= 0.2.0 =
* Upgrade plan: a checklist from your results that ticks itself off.
* Every PHP version at once: verdicts for PHP 8.0 to 8.5 from one scan, and the highest version the site is ready for.

= 0.1.4 =
* Client report: after updating or replacing plugins, the next steps say to run Airworthy again.

= 0.1.3 =
* A Pro tab (what's included, version, support) and a welcome screen after activation.

= 0.1.2 =
* A "Pro" badge next to the Airworthy title.

= 0.1.1 =
* The client report leaves Airworthy itself out, and explains how to hide the browser's own header and footer when saving as PDF.

= 0.1.0 =
* First version: branded client reports.
