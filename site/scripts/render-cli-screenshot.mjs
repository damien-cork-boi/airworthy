// Renders ../assets/screenshots/screenshot-7.png (the WP-CLI screenshot) from
// ../assets/source/cli-screenshot.html, at 2x like the other listing screenshots.
//
// Usage: node scripts/render-cli-screenshot.mjs   (from site/; needs Google Chrome, or set CHROME_PATH)
import fs from 'node:fs';
import path from 'node:path';
import puppeteer from 'puppeteer-core';

const site = path.resolve( import.meta.dirname, '..' );
const assets = path.resolve( site, '../assets' );
const chrome =
	process.env.CHROME_PATH ||
	[ '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium' ].find( ( p ) => fs.existsSync( p ) );
if ( ! chrome ) {
	throw new Error( 'Google Chrome not found: set CHROME_PATH.' );
}

const browser = await puppeteer.launch( { executablePath: chrome, headless: true } );
const page = await browser.newPage();
await page.setViewport( { width: 1320, height: 800, deviceScaleFactor: 2 } );
await page.goto( 'file://' + path.join( assets, 'source/cli-screenshot.html' ), { waitUntil: 'networkidle0' } );
const height = await page.evaluate( () => Math.ceil( document.querySelector( '.win' ).getBoundingClientRect().bottom + 28 ) );
const out = path.join( assets, 'screenshots/screenshot-7.png' );
await page.screenshot( { path: out, clip: { x: 0, y: 0, width: 1320, height } } );
console.log( `rendered ${ path.relative( path.resolve( site, '..' ), out ) } (2640x${ height * 2 })` );
await browser.close();
