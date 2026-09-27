// @ts-check
import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';

// Static site for airworthywp.com, hosted on Cloudflare Pages.
export default defineConfig( {
	site: 'https://airworthywp.com',
	trailingSlash: 'always',
	integrations: [ sitemap() ],
} );
