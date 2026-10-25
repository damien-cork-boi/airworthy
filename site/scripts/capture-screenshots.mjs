// Captures listing screenshots 1 (results), 2 (details), 3 (Plugins screen) and 6 (Settings) from the clean demo site (engine-test/clean) at 2x,
// into ../assets/screenshots/. See ../assets/screenshots/README.md for how the site is set up.
//
// Needs: the demo site running (launch config "airworthy-clean", http://localhost:8892), a
// development build of Airworthy on it (DEV_BUILD=1 bin/build.sh, so the server shows as PHP 7.4),
// a finished scan, and a temporary local-only login mu-plugin answering ?aw_dev_login=1 (delete
// it afterwards). Usage, from site/: node scripts/capture-screenshots.mjs [1] [2] [3] [6]
import fs from 'node:fs';
import path from 'node:path';
import puppeteer from 'puppeteer-core';

const SITE = process.env.SITE || 'http://localhost:8892';
const assets = path.resolve( import.meta.dirname, '../../assets/screenshots' );
const only = process.argv.slice( 2 );
const want = ( n ) => ! only.length || only.includes( String( n ) );
const chrome =
	process.env.CHROME_PATH ||
	[ '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium' ].find( ( p ) => fs.existsSync( p ) );
if ( ! chrome ) {
	throw new Error( 'Google Chrome not found: set CHROME_PATH.' );
}

const browser = await puppeteer.launch( { executablePath: chrome, headless: true } );
const page = await browser.newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
await page.setViewport( { width: 1120, height: 900, deviceScaleFactor: 2 } );
await page.goto( SITE + '/?aw_dev_login=1', { waitUntil: 'networkidle2' } );

// Hide what isn't Airworthy's: admin notices from other plugins and the admin bar's extras.
const tidy = () =>
	page.addStyleTag( { content: '.notice:not(.airworthy-notice):not(.update-message), .update-nag, #wpfooter { display: none !important; }' } );

async function clip( selectorFrom, selectorTo, pad = 0 ) {
	return page.evaluate(
		( from, to, padding ) => {
			const a = document.querySelector( from ).getBoundingClientRect();
			const b = document.querySelector( to ).getBoundingClientRect();
			const top = a.top + window.scrollY - padding;
			return { x: Math.max( 0, a.left - padding ), y: top, width: Math.min( document.documentElement.clientWidth, a.width + padding * 2 ), height: b.bottom + window.scrollY - top + padding };
		},
		selectorFrom,
		selectorTo,
		pad
	);
}

async function results( hash = '' ) {
	await page.goto( SITE + '/wp-admin/tools.php?page=airworthy' + hash, { waitUntil: 'networkidle2' } );
	await page.waitForSelector( '.airworthy-first-steps, .airworthy-headline' );
	await tidy();
	await new Promise( ( r ) => setTimeout( r, 800 ) );
}

if ( want( 1 ) ) {
	await results();
	// From the page title to the verdict tiles: answer, what to do first, counts.
	const box = await clip( '.airworthy-masthead', '.airworthy-summary', 20 );
	box.height += 16;
	await page.screenshot( { path: path.join( assets, 'screenshot-1.png' ), clip: box, captureBeyondViewport: true } );
	console.log( 'screenshot-1.png' );
}

if ( want( 6 ) ) {
	// The Settings tab, top of the page: header, tab bar and the first settings.
	await page.setViewport( { width: 1120, height: 820, deviceScaleFactor: 2 } );
	await page.goto( SITE + '/wp-admin/tools.php?page=airworthy&tab=settings', { waitUntil: 'networkidle2' } );
	await tidy();
	const top = await clip( '.airworthy-masthead', '.airworthy-masthead', 20 );
	await page.screenshot( { path: path.join( assets, 'screenshot-6.png' ), clip: { x: top.x, y: top.y, width: top.width, height: 820 }, captureBeyondViewport: true } );
	console.log( 'screenshot-6.png' );
	await page.setViewport( { width: 1120, height: 900, deviceScaleFactor: 2 } );
}

if ( want( 2 ) ) {
	await results( '#plugin:old-widget-pack' );
	await page.waitForSelector( '.airworthy-row-linked' );
	await page.evaluate( () => document.activeElement && document.activeElement.blur() );
	// Open the first finding's explanation ("What this means and how to fix it").
	await page.evaluate( () => {
		const first = document.querySelector( '.airworthy-explain details, details.airworthy-explain' );
		if ( first ) {
			first.open = true;
		}
	} );
	const table = await page.$( 'table.airworthy-table' );
	await table.screenshot( { path: path.join( assets, 'screenshot-2.png' ), captureBeyondViewport: true } );
	console.log( 'screenshot-2.png' );
}

if ( want( 3 ) ) {
	await page.setViewport( { width: 1280, height: 900, deviceScaleFactor: 2 } );
	// Active plugins only, without the test database driver and an upsell-heavy row.
	await page.goto( SITE + '/wp-admin/plugins.php?plugin_status=active', { waitUntil: 'networkidle2' } );
	await tidy();
	await page.addStyleTag( { content: 'tr[data-slug="sqlite-database-integration"], tr[data-slug="wpforms-lite"] { display: none !important; }' } );
	const list = await page.$( '#the-list' );
	const box = await list.boundingBox();
	const head = await ( await page.$( '.wp-list-table thead' ) ).boundingBox();
	await page.screenshot( { path: path.join( assets, 'screenshot-3.png' ), clip: { x: head.x, y: head.y, width: head.width, height: box.y + box.height - head.y }, captureBeyondViewport: true } );
	console.log( 'screenshot-3.png' );
}

if ( errors.length ) {
	console.log( 'page errors:', errors );
}
await browser.close();
