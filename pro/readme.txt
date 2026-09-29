=== Airworthy Pro ===
Contributors: digitalkind
Tags: php, php 8, compatibility, upgrade, report
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.2
Requires Plugins: airworthy
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An upgrade plan that ticks itself off, every PHP version at once, and branded client reports. An add-on for the free Airworthy plugin.

== Description ==

Airworthy tells you which plugins and themes will break on a newer PHP version. Airworthy Pro helps you do the upgrade, show your clients, and plan ahead.

* **Upgrade plan.** A step-by-step checklist built from your results: update these, replace those, back up, try it on staging, switch PHP, check again. Airworthy ticks steps off as it sees them happen; you tick the ones it can't see, like the backup.
* **Every PHP version at once.** One scan against the newest PHP version shows how the site does on every version from PHP 8.0 up, side by side, and the highest version it's ready for today.
* **Client reports.** A plain-words report of any scan with your logo, name and colour, ready to save as a PDF and send to your client.

Airworthy Pro needs the free Airworthy plugin (https://airworthywp.com/), installed and active. It adds "Pro", "Upgrade plan", "PHP versions" and "Client report" tabs to Tools > Airworthy.

= Privacy =

Airworthy Pro sends nothing anywhere. It reads the results the free plugin already has on your site, and the branding you enter is stored on your site.

= Support =

Email hello@airworthywp.com with your site's PHP version and what you're seeing; a CSV export of the results helps.

== Installation ==

1. Install and activate the free Airworthy plugin.
2. Upload Airworthy Pro (Plugins > Add New > Upload Plugin) and activate it.
3. You'll land on the Pro tab, with three steps to get started.

== Changelog ==

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
