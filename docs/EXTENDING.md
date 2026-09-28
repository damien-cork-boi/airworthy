# Extending Airworthy

The free plugin has a few hooks for add-ons (Airworthy Pro uses them). They are the only
supported way in; everything else is internal and may change.

| Hook | Type | What it does |
|---|---|---|
| `airworthy_admin_tabs` | filter | Adds tabs to the Airworthy screen after Scan and Settings: return `slug => label`. |
| `airworthy_render_tab_{slug}` | action | Draws that tab's content (inside the Airworthy screen's wrapper, after the tabs). |
| `airworthy_admin_badge` | filter | A short badge after the screen's title (Pro returns "Pro"). |
| `airworthy_results_actions` | filter | Adds buttons next to Export CSV on the results: `array( 'label' => …, 'url' => … )`, where `__SCAN__` in the URL becomes the scan ID. Links open in a new tab. |

Data: add-ons read results through the REST API (`/airworthy/v1/scans/{id}`, `/scans/{id}/components/{component}/issues`), for example with `rest_do_request()`, so they get exactly what the admin screen shows and the same permission checks.

Settings forms on add-on tabs can reuse the `airworthy-card` and `airworthy-settings` classes; only the free Settings form (it has `data-autosave`) saves as you type.

## Airworthy Pro

Lives in `pro/` (private: not in the public repo or on WordPress.org). Build it with `bin/build-pro.sh` → `dist/airworthy-pro-<version>.zip`. It needs the free plugin (`Requires Plugins: airworthy`).

- **Client report** (0.1.0; 0.1.1 leaves Airworthy and Airworthy Pro out of the client's report and tells people to untick the browser's "Headers and footers"): a "Client report" tab (agency name, logo, brand colour) and a "Client report" button on the results. The report is a print-ready page (A4, page 1 summary, details from page 2, agency name and page numbers in the footer) that browsers save as PDF.
- **Pro tab and welcome** (0.1.3): a "Pro" tab listing every Pro feature with its status (included, coming next, coming soon), the version and licence, and priority support; right after activation, wp-admin opens it once with a three-step welcome (branding, scan, report).
- **Upgrade plan** (0.2.0): an "Upgrade plan" tab. Built from the latest scan (or, for a lower version, from the PHP versions matrix): one step per plugin or theme to update, fix, replace or get fully checked, then run Airworthy again, back up, try on staging, switch PHP and a final check. Steps tick themselves off from what Airworthy can see (installed versions, a newer scan with nothing to fix, the server's PHP version); backup and staging are ticked by hand, and any step can be. Stored in the `airworthy_pro_plan` site option.
- **Every PHP version at once** (0.2.0): a "PHP versions" tab. From one scan against the newest version, the verdict on every version from 8.0 up: each finding's message names when its change happened, so a finding counts on a version only from then on, and never more seriously than the newest-version scan rated it (PHPCompatibility rates some removals, like ini settings, as warnings). Counting follows the free plugin's rules exactly (warnings always count as warnings; errors split into existing, guarded, suppressed and real). Approximation: code behind a version check counts as guarded on every version. Checked against real scans at 8.0, 8.2 and 8.4 (see the commit that added it).
