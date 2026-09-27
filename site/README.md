# airworthywp.com

The product website for Airworthy: a static [Astro](https://astro.build) site, hosted on Cloudflare Pages.

Most content comes from the plugin itself, so the site can't drift from the release:

- `../plugin/readme.txt`: short description, feature list, verdicts, FAQ, docs, changelog, requirements (`src/lib/readme.ts`).
- `../SECURITY.md`: the security page.
- `../assets/screenshots/`: copied into `public/screenshots/` by `scripts/sync-assets.mjs` before every build.

Site-wide settings are in `src/config.ts`:

- `listed`: set to `true` once the plugin is approved on WordPress.org. This turns the "Coming soon" labels into install buttons.
- `heldScreenshots`: listing screenshots kept off the site (and out of the build). 2–4 show plugins with bugs that haven't been reported to their authors yet; see docs/RELEASE.md.

## Brand

The mark is a tick whose upstroke is a flight path, with a plane at its tip: "approved" and "cleared for take-off". Colours: blue `#2f74e8` to `#1747a6` (gradient), tick green `#8fe3a8`, site blue `#1f5fd0`.

Sources are in `../assets/`: `icon.svg` and `source/banner.html`. `npm run brand` renders the WordPress.org icons and banners into `../assets/` and the favicon, Apple touch icon and social image (`og.png`) into `public/` (needs Google Chrome).

## Commands

```bash
npm install
npm run dev      # http://localhost:4321
npm run build    # static site in dist/
```

## Cloudflare Pages settings

- Framework preset: Astro
- Root directory: `site`
- Build command: `npm run build`
- Build output directory: `dist`
- Environment variable: `NODE_VERSION` = `22`
- Build watch paths (Settings > Builds): `site/*`, `plugin/readme.txt`, `SECURITY.md`, `assets/screenshots/*`

`public/_headers` sets the security headers (strict Content Security Policy: the site has no JavaScript, forms or third-party resources). `/.well-known/security.txt` is generated at build time with a fresh expiry date, so deploy at least once a year.
