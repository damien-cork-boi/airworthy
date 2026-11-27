=== Airworthy Pro ===
Contributors: digitalkind
Tags: php, php 8, compatibility, upgrade, report
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.2
Requires Plugins: airworthy
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Monitoring with email alerts, an upgrade plan that ticks itself off, every PHP version at once, and branded client reports. An add-on for the free Airworthy plugin.

== Description ==

Airworthy tells you which plugins and themes will break on a newer PHP version. Airworthy Pro helps you do the upgrade, show your clients, and plan ahead.

* **Monitoring and email alerts.** Airworthy checks the site again on a schedule (daily, weekly or monthly) and after any plugin or theme update, and emails you when something new would break on your PHP version. A history shows what changed since the last check: new Blockers, fixed ones, plugins added or removed. It also emails once when your PHP version is 90, 30 and 7 days from the end of security fixes, or is missing the latest security release.
* **Upgrade plan.** A step-by-step checklist built from your results: update these, replace those, back up, try it on staging, switch PHP, check again. Airworthy ticks steps off as it sees them happen; you tick the ones it can't see, like the backup.
* **Every PHP version at once.** One scan against the newest PHP version shows how the site does on every version from PHP 8.0 up, side by side, and the highest version it's ready for today.
* **Client reports.** A plain-words report of any scan with your logo, name and colour, ready to save as a PDF and send to your client.
* **Priority support, with a support file.** One button on the Pro tab builds a plain-text file about the site (versions, settings, plugins, recent scans, waiting jobs) for you to preview and send us. It leaves out passwords, keys, customer and order data, post content and file contents, and masks site addresses, email addresses and IP addresses.

Airworthy Pro needs the free Airworthy plugin (https://airworthywp.com/), installed and active. It adds "Pro", "Upgrade plan", "PHP versions", "Monitoring" and "Client report" tabs to Tools > Airworthy.

= Privacy =

Airworthy Pro reads the results the free plugin already has on your site, and the branding you enter is stored on your site. The one thing it sends out is the licence check: when you activate your licence, and now and then to look for updates, your licence key, your site's address and the version numbers of Pro and its licensing code go to Freemius (freemius.com), the company that sells and licenses Airworthy Pro for us. Sharing anything more, such as your WordPress and PHP versions or your site title, is off unless you turn it on yourself. Your scan results never leave your site.

= Support =

Email hello@airworthywp.com with your site's PHP version and what you're seeing. The "Download support info" button on the Pro tab builds the file that helps us most; a CSV export of the results helps too.

== Installation ==

1. Install and activate the free Airworthy plugin.
2. Upload Airworthy Pro (Plugins > Add New > Upload Plugin) and activate it.
3. You'll land on the Pro tab, with three steps to get started.

== Changelog ==

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
