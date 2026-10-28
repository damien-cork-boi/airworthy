/**
 * Site-wide settings. Product facts (versions, requirements, FAQ, changelog) come from the
 * plugin's readme.txt instead: see src/lib/readme.ts.
 */
export const SITE = {
	url: 'https://airworthywp.com',
	name: 'Airworthy',
	tagline: 'PHP Compatibility & Upgrade Checker for WordPress',
	description:
		'Before you upgrade PHP, see which WordPress plugins and themes are ready, which need fixing, and which look abandoned. Free on WordPress.org.',

	/** True since 30 Sep 2026, when 1.0.0 went live on WordPress.org: turns on the install buttons. */
	listed: true,
	wporgUrl: 'https://wordpress.org/plugins/airworthy/',
	repoUrl: 'https://github.com/DigitalKind/airworthy',

	/**
	 * Listing screenshots to keep off the site (by number). Empty since the 27 Sep 2026
	 * recapture on a neutral demo site; use it if a screenshot ever shows something that
	 * mustn't be public yet (see docs/RELEASE.md).
	 */
	heldScreenshots: [] as number[],
	// The demo video on YouTube (also in the readme as [youtube …]); shown click-to-play.
	demoVideo: 'https://www.youtube.com/watch?v=-Hkm3wv-dJ0',

	/**
	 * The top-30 findings page (/findings/top-30-plugins-php-8-4/). It names UpdraftPlus's
	 * latent bug, which was reported privately on 28 Sep 2026. Published on 28 Oct 2026, at the
	 * end of the 30-day private-disclosure window (UpdraftPlus 1.26.14 still unfixed). Setting it
	 * to false removes the page, its links and its sitemap entry; PREVIEW_FINDINGS=1 npm run dev
	 * still previews it.
	 */
	findingsPublished: true,

	email: {
		hello: 'hello@airworthywp.com',
		security: 'security@airworthywp.com',
	},
};

/** The company behind the product (legal notice, footer, structured data). */
export const COMPANY = {
	name: 'DigitalKind Limited',
	url: 'https://digitalkind.ie',
	cro: '795387',
	vat: 'IE4466450VH',
	address: 'Coolduve, Kilmurry, Lissarda, Cork, Ireland',
	country: 'IE',
};

/** Whether the findings page is built: published, or previewed locally (PREVIEW_FINDINGS=1). */
export const FINDINGS_LIVE = SITE.findingsPublished || '1' === import.meta.env.PREVIEW_FINDINGS;
export const FINDINGS_URL = '/findings/top-30-plugins-php-8-4/';

export const NAV = [
	{ href: '/docs/', label: 'Docs' },
	{ href: '/changelog/', label: 'Changelog' },
	{ href: '/security/', label: 'Security' },
];
