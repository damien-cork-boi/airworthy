// @ts-check
import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';

// Static site for airworthywp.com, served by Cloudflare (Workers static assets, see wrangler.jsonc).
export default defineConfig( {
	site: 'https://airworthywp.com',
	trailingSlash: 'always',
	integrations: [ sitemap() ],
	// Page scripts as files, never inline: the Content Security Policy (public/_headers) allows
	// script-src 'self' only, so inlined scripts would be blocked.
	vite: { build: { assetsInlineLimit: 0 } },
} );
