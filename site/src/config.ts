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
	// Left out of the phone header once the Articles link joins it (the footer still has it).
	{ href: '/security/', label: 'Security', phoneHideWithArticles: true },
];

/**
 * Airworthy Pro on this site: the /pro/ page and the Pro paragraph in the privacy notice.
 * Not built, linked or listed in the sitemap until `published` is true (launch: Thu 3 Dec 2026);
 * PREVIEW_PRO=1 npm run build shows it locally. Everything marked PLACEHOLDER is still Damo's
 * decision (plans, prices, refund) and must be settled before `published` goes true.
 */
export const PRO = {
	published: false,
	/** The Freemius checkout link. Empty until the Freemius product exists. */
	buyUrl: '',
	launchDate: '3 December 2026',
	/** Decided 26 Nov 2026 (wiki: Pro pricing research): founding prices, same on renewal; in Freemius as one "Pro" plan with 1, 5 and 25 site prices, yearly. */
	plans: [
		{ name: 'Pro', sites: 1, eur: 39, usd: 45, gbp: 35, for: 'Your own site, or one client\'s.' },
		{ name: 'Freelancer', sites: 5, eur: 99, usd: 109, gbp: 85, for: 'A handful of client sites.' },
		{ name: 'Agency', sites: 25, eur: 199, usd: 219, gbp: 175, for: 'An agency looking after many sites.' },
	],
	/** Set in Freemius 26 Nov 2026: 14 days, Flexible (no questions asked). */
	refundDays: 14,
	/** The Pro features that aren't built yet: they show as "coming" on the Pro tab too. */
	coming: [
		{ title: 'Monitoring and email alerts', text: 'Checks again after every update and every week, and emails you when something changes or your PHP version nears its end.' },
		{ title: 'Report history', text: 'Every client report kept with its date, ready to download again.' },
	],
};

/** Whether the Pro page is built: published, or previewed locally (PREVIEW_PRO=1). */
export const PRO_LIVE = PRO.published || '1' === import.meta.env.PREVIEW_PRO;

