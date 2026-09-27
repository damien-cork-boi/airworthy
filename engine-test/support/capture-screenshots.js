// Captures WordPress.org listing screenshots of the Airworthy admin screen (1280 px wide).
// Usage: node capture.js start   -> screenshot-1 (picker), starts a PHP 8.4 scan, screenshot-2 (progress)
//        node capture.js results -> screenshot-3 (results), screenshot-4 (details)
const puppeteer = require( 'puppeteer-core' );
const fs = require( 'fs' );

const OUT = __dirname + '/../../assets/screenshots'; // Run from engine-test/support after: npm install puppeteer-core
const SITE = 'http://localhost:8891';
const PAGE = SITE + '/wp-admin/tools.php?page=airworthy';
const creds = fs.readFileSync( __dirname + '/../wp/.test-credentials', 'utf8' );
const user = creds.match( /user: (.+)/ )[ 1 ].trim();
const pass = creds.match( /pass: (.+)/ )[ 1 ].trim();
const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

( async () => {
	fs.mkdirSync( OUT, { recursive: true } );
	const browser = await puppeteer.launch( {
		executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
		headless: true,
		defaultViewport: { width: 1280, height: 900, deviceScaleFactor: 1 },
		args: [ '--lang=en-US', '--hide-scrollbars' ],
	} );
	const page = await browser.newPage();
	await page.goto( SITE + '/wp-login.php', { waitUntil: 'networkidle2' } );
	await page.type( '#user_login', user );
	await page.type( '#user_pass', pass );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'networkidle2' } ), page.click( '#wp-submit' ) ] );

	// Test-site artefact (the SQLite database drop-in's admin-bar item): not part of Airworthy.
	const tidy = () => page.addStyleTag( { content: '#wp-admin-bar-sqlite-db-integration{display:none!important}' } );
	page.on( 'load', () => tidy().catch( () => {} ) );

	const shot = async ( name, height ) => {
		await tidy();
		await page.evaluate( () => document.activeElement && document.activeElement.blur() ); // No focus rings in the listing.
		await page.screenshot( { path: OUT + '/' + name, clip: { x: 0, y: 0, width: 1280, height: height || 900 } } );
		console.log( 'saved', name );
	};

	if ( 'start' === process.argv[ 2 ] ) {
		await page.goto( PAGE, { waitUntil: 'networkidle2' } );
		await sleep( 1500 );
		// Results of an earlier scan are shown first: open the start view.
		const newScan = await page.$( '.airworthy-head-actions .button-primary' );
		if ( newScan ) {
			await newScan.click();
		}
		await page.waitForFunction( () => ! document.getElementById( 'airworthy-start' ).hidden );
		await page.waitForFunction( () => ! /Working it out/.test( document.getElementById( 'airworthy-estimate' ).textContent ), { timeout: 30000 } );
		await page.evaluate( () => {
			document.getElementById( 'airworthy-start-cancel' ).hidden = true; // First-run look: no "Back to results".
		} );
		await shot( 'screenshot-1.png', 620 );

		await page.select( '#airworthy-target', '8.4' );
		await page.click( '#airworthy-start-button' );
		await page.waitForFunction( () => {
			const t = document.querySelector( '.airworthy-progress-text' );
			const m = t && t.textContent.match( /^(\d+)%/ );
			return m && +m[ 1 ] >= 30;
		}, { timeout: 240000, polling: 2000 } );
		await sleep( 500 );
		await shot( 'screenshot-2.png', 900 );
	} else {
		await page.goto( PAGE, { waitUntil: 'networkidle2' } );
		await page.waitForSelector( '.airworthy-table tbody tr', { timeout: 30000 } );
		await sleep( 1500 );
		await shot( 'screenshot-3.png', 1000 );

		// Details: expand a Blocker's findings and bring its row into view.
		const clip = await page.evaluate( () => {
			const row = [ ...document.querySelectorAll( 'tr.airworthy-row' ) ].find( ( r ) => r.innerText.includes( 'Essential Addons' ) );
			row.querySelector( 'button[aria-expanded]' ).click();
			return true;
		} );
		await page.waitForFunction( () => document.querySelectorAll( '.airworthy-issue' ).length > 0, { timeout: 15000 } );
		await sleep( 500 );
		const top = await page.evaluate( () => {
			const row = [ ...document.querySelectorAll( 'tr.airworthy-row' ) ].find( ( r ) => r.innerText.includes( 'Essential Addons' ) );
			const table = document.querySelector( '.airworthy-table' );
			return Math.max( 0, table.getBoundingClientRect().top + window.scrollY - 60 );
		} );
		await page.evaluate( ( y ) => window.scrollTo( 0, y ), top );
		await sleep( 400 );
		await tidy();
		await page.evaluate( () => document.activeElement && document.activeElement.blur() );
		await page.screenshot( { path: OUT + '/screenshot-4.png', clip: { x: 0, y: top, width: 1280, height: 760 } } );
		console.log( 'saved screenshot-4.png' );
	}
	await browser.close();
} )().catch( ( e ) => {
	console.error( e.message );
	process.exit( 1 );
} );
