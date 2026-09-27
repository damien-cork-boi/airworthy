/**
 * Site-wide settings. Product facts (versions, requirements, FAQ, changelog) come from the
 * plugin's readme.txt instead: see src/lib/readme.ts.
 */
export const SITE = {
	url: 'https://airworthywp.com',
	name: 'Airworthy',
	tagline: 'PHP Compatibility & Upgrade Checker for WordPress',
	description:
		'Find out which WordPress plugins and themes will break on a newer PHP version, and which look abandoned, before you upgrade. Free on WordPress.org.',

	/** Flip to true once the plugin is approved on WordPress.org: turns on the install buttons. */
	listed: false,
	wporgUrl: 'https://wordpress.org/plugins/airworthy/',
	repoUrl: 'https://github.com/DigitalKind/airworthy',

	/**
	 * Listing screenshots kept off the site for now. 2–4 name plugins with bugs that haven't
	 * been reported to their authors yet (see docs/RELEASE.md). Empty this once they're fixed,
	 * or once the screenshots are recaptured without them.
	 */
	heldScreenshots: [ 2, 3, 4 ],

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

export const NAV = [
	{ href: '/docs/', label: 'Docs' },
	{ href: '/changelog/', label: 'Changelog' },
	{ href: '/security/', label: 'Security' },
];
