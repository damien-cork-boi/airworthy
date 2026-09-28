# Extending Airworthy

The free plugin has a few hooks for add-ons (Airworthy Pro uses them). They are the only
supported way in; everything else is internal and may change.

| Hook | Type | What it does |
|---|---|---|
| `airworthy_admin_tabs` | filter | Adds tabs to the Airworthy screen after Scan and Settings: return `slug => label`. |
| `airworthy_render_tab_{slug}` | action | Draws that tab's content (inside the Airworthy screen's wrapper, after the tabs). |
| `airworthy_results_actions` | filter | Adds buttons next to Export CSV on the results: `array( 'label' => …, 'url' => … )`, where `__SCAN__` in the URL becomes the scan ID. Links open in a new tab. |

Data: add-ons read results through the REST API (`/airworthy/v1/scans/{id}`, `/scans/{id}/components/{component}/issues`), for example with `rest_do_request()`, so they get exactly what the admin screen shows and the same permission checks.

Settings forms on add-on tabs can reuse the `airworthy-card` and `airworthy-settings` classes; only the free Settings form (it has `data-autosave`) saves as you type.

## Airworthy Pro

Lives in `pro/` (private: not in the public repo or on WordPress.org). Build it with `bin/build-pro.sh` → `dist/airworthy-pro-<version>.zip`. It needs the free plugin (`Requires Plugins: airworthy`).

- **Client report** (0.1.0; 0.1.1 leaves Airworthy and Airworthy Pro out of the client's report and tells people to untick the browser's "Headers and footers"): a "Client report" tab (agency name, logo, brand colour) and a "Client report" button on the results. The report is a print-ready page (A4, page 1 summary, details from page 2, agency name and page numbers in the footer) that browsers save as PDF.
