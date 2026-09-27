// Renders the brand images from their sources in ../assets (run by hand after changing them):
//
//   WordPress.org listing (../assets/, uploaded to the plugin's SVN assets/ folder):
//     icon-128x128.png, icon-256x256.png   from icon.svg
//     banner-772x250.png, banner-1544x500.png   from source/banner.html
//   Website (public/):
//     favicon.svg (copy of icon.svg), apple-touch-icon.png (180x180), og.png (1200x630)
//
// Usage: npm run brand   (needs Google Chrome installed; set CHROME_PATH if it isn't found)
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

async function render( url, out, width, height, scale = 1, transparent = false ) {
	await page.setViewport( { width, height, deviceScaleFactor: scale } );
	await page.goto( url, { waitUntil: 'networkidle0' } );
	await page.screenshot( { path: out, omitBackground: transparent, clip: { x: 0, y: 0, width, height } } );
	console.log( `rendered ${ path.relative( path.resolve( site, '..' ), out ) } (${ width * scale }x${ height * scale })` );
}

// Icons: the SVG on an otherwise empty page.
const iconPage = 'file://' + path.join( assets, 'source', 'icon-render.html' ); // Temporary page, deleted below.
fs.writeFileSync(
	path.join( assets, 'source', 'icon-render.html' ),
	'<!doctype html><style>html,body{margin:0;background:transparent}img{display:block;width:100vw;height:100vh}</style><img src="../icon.svg" alt="">\n'
);
await render( iconPage, path.join( assets, 'icon-128x128.png' ), 128, 128, 1, true );
await render( iconPage, path.join( assets, 'icon-256x256.png' ), 256, 256, 1, true );
await render( iconPage, path.join( site, 'public', 'apple-touch-icon.png' ), 180, 180, 1, false );

// Banners and social image.
const banner = 'file://' + path.join( assets, 'source', 'banner.html' );
await render( banner + '?layout=banner', path.join( assets, 'banner-772x250.png' ), 772, 250, 1 );
await render( banner + '?layout=banner', path.join( assets, 'banner-1544x500.png' ), 772, 250, 2 );
await render( banner + '?layout=og', path.join( site, 'public', 'og.png' ), 1200, 630, 1 );

fs.rmSync( path.join( assets, 'source', 'icon-render.html' ) );
fs.copyFileSync( path.join( assets, 'icon.svg' ), path.join( site, 'public', 'favicon.svg' ) );
console.log( 'copied site/public/favicon.svg' );

await browser.close();
