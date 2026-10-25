// Records the readme demo video (about 40 seconds, 1280x720, silent, with captions) from the clean demo
// site (engine-test/clean), into ../assets/video/airworthy-demo.mp4.
//
// Needs: the demo site running (launch config "airworthy-clean", http://localhost:8892) with a
// development build of Airworthy (DEV_BUILD=1 bin/build.sh, so the server shows as PHP 7.4),
// Airworthy Pro moved out of the plugins folder, the "Shop Price Tweaks" demo plugin, a
// temporary local-only login mu-plugin answering ?aw_dev_login=1 (delete it afterwards), and
// ffmpeg (brew install ffmpeg). The scan runs for real; only the waiting is cut.
// Usage, from site/: node scripts/record-demo.mjs
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import puppeteer from 'puppeteer-core';

const SITE = process.env.SITE || 'http://localhost:8892';
const FFMPEG = process.env.FFMPEG || '/opt/homebrew/bin/ffmpeg';
const outDir = path.resolve( import.meta.dirname, '../../assets/video' );
const work = fs.mkdtempSync( path.join( process.env.TMPDIR || '/tmp', 'aw-demo-' ) );
const chrome =
	process.env.CHROME_PATH ||
	[ '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome' ].find( ( p ) => fs.existsSync( p ) );
const W = 1280;
const H = 720;
const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

// Captions and a visible cursor, drawn into every page (they're part of the recording).
const OVERLAY = `
(() => {
	const css = \`
		#aw-cap { position: fixed; left: 50%; bottom: 36px; transform: translateX(-50%); z-index: 2147483647;
			max-width: 980px; padding: 14px 26px; border-radius: 14px; background: rgba(16, 32, 72, 0.92);
			color: #fff; font: 600 26px/1.3 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
			text-align: center; box-shadow: 0 8px 30px rgba(0,0,0,0.25); opacity: 0; transition: opacity .35s; pointer-events: none; }
		#aw-cap.on { opacity: 1; }
		#aw-cur { position: fixed; z-index: 2147483647; width: 22px; height: 22px; margin: -11px 0 0 -11px; border-radius: 50%;
			background: rgba(31, 95, 208, 0.35); border: 2px solid #1f5fd0; pointer-events: none; left: -40px; top: -40px; transition: transform .15s; }
		#aw-cur.down { transform: scale(0.7); background: rgba(31, 95, 208, 0.6); }
		.notice:not(.airworthy-notice):not(.update-message), .update-nag, #wpfooter { display: none !important; }
		.airworthy-review { display: none !important; }
	\`;
	const add = () => {
		if (document.getElementById('aw-cap')) return;
		const s = document.createElement('style'); s.textContent = css; document.head.appendChild(s);
		const c = document.createElement('div'); c.id = 'aw-cap'; document.body.appendChild(c);
		const u = document.createElement('div'); u.id = 'aw-cur'; document.body.appendChild(u);
		document.addEventListener('mousemove', (e) => { u.style.left = e.clientX + 'px'; u.style.top = e.clientY + 'px'; }, true);
		document.addEventListener('mousedown', () => u.classList.add('down'), true);
		document.addEventListener('mouseup', () => u.classList.remove('down'), true);
	};
	window.awCap = (text) => { add(); const c = document.getElementById('aw-cap'); if (!text) { c.classList.remove('on'); return; }
		c.classList.remove('on'); setTimeout(() => { c.textContent = text; c.classList.add('on'); }, text && c.textContent ? 250 : 0); };
	document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', add) : add();
})();`;

const browser = await puppeteer.launch( { executablePath: chrome, headless: true, defaultViewport: { width: W, height: H, deviceScaleFactor: 1 } } );
const page = await browser.newPage();
await page.evaluateOnNewDocument( OVERLAY );
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
await page.goto( SITE + '/?aw_dev_login=1', { waitUntil: 'networkidle2' } );

let cursor = { x: W / 2, y: H / 2 };
async function moveTo( selector, opts = {} ) {
	// The results redraw when WordPress.org lookups finish, so look the element up again
	// until it holds still.
	let el = await page.waitForSelector( selector, { visible: true } );
	await el.evaluate( ( e ) => e.scrollIntoView( { block: 'center', behavior: 'smooth' } ) );
	await sleep( 600 );
	let b = null;
	for ( let i = 0; i < 10 && ! b; i++ ) {
		el = await page.waitForSelector( selector, { visible: true } );
		b = await el.boundingBox();
		if ( ! b ) {
			await sleep( 300 );
		}
	}
	const x = b.x + ( opts.dx ?? b.width / 2 );
	const y = b.y + b.height / 2;
	await page.mouse.move( x, y, { steps: 25 } );
	cursor = { x, y };
	return el;
}
async function click( selector, opts ) {
	await moveTo( selector, opts );
	await sleep( 250 );
	await page.mouse.down();
	await sleep( 120 );
	await page.mouse.up();
}
const cap = ( text ) => page.evaluate( ( t ) => window.awCap && window.awCap( t ), text );
async function scrollTo( selector, block = 'start', offset = -90 ) {
	await page.evaluate(
		( s, bl, off ) => {
			const e = document.querySelector( s );
			const y = e.getBoundingClientRect().top + window.scrollY + ( 'start' === bl ? off : -window.innerHeight / 2 );
			window.scrollTo( { top: Math.max( 0, y ), behavior: 'smooth' } );
		},
		selector,
		block,
		offset
	);
	await sleep( 900 );
}

const segments = [];
async function record( name, fn ) {
	const file = path.join( work, name + '.webm' );
	const rec = await page.screencast( { path: file, ffmpegPath: FFMPEG } );
	await fn();
	await rec.stop();
	segments.push( file );
	console.log( 'recorded', name );
}

// 1. Start screen: pick a version, start the scan, see it run.
await page.goto( SITE + '/wp-admin/tools.php?page=airworthy', { waitUntil: 'networkidle2' } );
// The screen draws the latest results (or the start card) after it loads.
await page.waitForFunction( () => ! document.getElementById( 'airworthy-start' ).hidden || ! document.getElementById( 'airworthy-results' ).hidden, { timeout: 60000 } );
const startVisible = await page.$eval( '#airworthy-start', ( e ) => ! e.hidden ).catch( () => false );
if ( ! startVisible ) {
	const buttons = await page.$$( '#airworthy-results button' );
	for ( const b of buttons ) {
		if ( ( await b.evaluate( ( e ) => e.textContent.trim() ) ) === 'New scan' ) {
			await b.click();
			break;
		}
	}
}
await page.waitForSelector( '#airworthy-start', { visible: true } );
await page.evaluate( () => window.scrollTo( 0, 0 ) );
await page.mouse.move( W / 2, H / 2 );
await sleep( 500 );

await record( '1-start', async () => {
	await cap( 'Moving your WordPress site to a newer PHP version? Check it first.' );
	await sleep( 3200 );
	await cap( 'Pick the version your host is moving you to.' );
	await moveTo( '#airworthy-target' );
	await sleep( 700 );
	await page.select( '#airworthy-target', '8.4' );
	await sleep( 1600 );
	await click( '#airworthy-start-button' );
	await cap( 'Airworthy checks every plugin and your theme, in the background.' );
	await page.waitForSelector( '#airworthy-progress', { visible: true } );
	await page.evaluate( () => window.scrollTo( 0, 0 ) );
	await sleep( 1500 );
} );

// Not recorded: wait until the scan is visibly moving (some plugins done), then show it.
await page
	.waitForFunction( () => /\b([1-9]\d*) of \d+/.test( document.getElementById( 'airworthy-progress' ).innerText ), { timeout: 60000, polling: 500 } )
	.catch( () => {} );
await record( '1b-progress', async () => {
	await sleep( 4500 );
} );

// Wait for the real scan to finish (not recorded).
const t0 = Date.now();
await page.waitForFunction(
	() => {
		const r = document.getElementById( 'airworthy-results' );
		return r && ! r.hidden && r.querySelector( '.airworthy-headline' ) && document.getElementById( 'airworthy-progress' ).hidden;
	},
	{ timeout: 15 * 60 * 1000, polling: 2000 }
);
console.log( 'scan finished in', Math.round( ( Date.now() - t0 ) / 1000 ), 's' );
await page.goto( SITE + '/wp-admin/tools.php?page=airworthy', { waitUntil: 'networkidle2' } );
await page.waitForSelector( '.airworthy-first-steps' );
await sleep( 6000 ); // Let WordPress.org lookups finish and the results redraw before recording.
await page.evaluate( () => window.scrollTo( 0, 0 ) );
await page.mouse.move( W - 200, 200 );

// 2. Results: the answer, what to do first, then one finding explained.
await record( '2-results', async () => {
	await sleep( 400 );
	await scrollTo( '.airworthy-headline', 'start', -24 );
	await cap( 'One clear answer for the whole site, and what to do first.' );
	await moveTo( '.airworthy-first-steps li', { dx: 120 } );
	await sleep( 3800 );
	await cap( 'Every problem shows the file, what breaks, and how to fix it.' );
	await scrollTo( 'tr[data-component="plugin:old-widget-pack"]', 'start', -70 );
	await click( 'tr[data-component="plugin:old-widget-pack"] .airworthy-actions button' );
	await page.waitForSelector( '.airworthy-explain', { visible: true } );
	await sleep( 900 );
	await click( '.airworthy-explain summary', { dx: 90 } );
	await sleep( 500 );
	await scrollTo( '.airworthy-explain', 'start', -150 );
	await sleep( 4200 );
} );

// 3. Plugins screen badges.
await page.goto( SITE + '/wp-admin/plugins.php?plugin_status=active', { waitUntil: 'networkidle2' } );
await page.addStyleTag( { content: 'tr[data-slug="sqlite-database-integration"], tr[data-slug="wpforms-lite"] { display: none !important; }' } );
await page.mouse.move( W - 200, 200 );
await record( '3-plugins', async () => {
	await cap( 'Each plugin\'s verdict shows on your Plugins screen, too.' );
	await sleep( 600 );
	await moveTo( 'tr[data-slug="old-widget-pack"] .airworthy-plugin-badge' );
	await sleep( 3600 );
} );

// 4. End card.
const logo = '<svg viewBox="0 0 64 64" width="120" height="120" xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#2f74e8"/><stop offset="1" stop-color="#1747a6"/></linearGradient></defs><rect width="64" height="64" rx="15" fill="url(#g)"/><path d="M12 36.5 L23 47 L37.5 29.5" fill="none" stroke="#8fe3a8" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/><g transform="translate(45.5 20) rotate(40) scale(0.5) translate(-32 -32)"><path fill="#ffffff" d="M32 4c2.4 0 3.8 3 3.8 6.5V25l21.2 12.5v5.2L35.8 35.6V48l7.2 5.4V58L32 54.8 21 58v-4.6l7.2-5.4V35.6L7 42.7v-5.2L28.2 25V10.5C28.2 7 29.6 4 32 4z"/></g></svg>';
await page.setContent(
	`<html><body style="margin:0;height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:18px;background:linear-gradient(135deg,#1747a6 0%,#2f74e8 70%,#3fa36b 140%);color:#fff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;text-align:center">
	${ logo }
	<div style="font-size:54px;font-weight:700;letter-spacing:-0.5px">Airworthy</div>
	<div style="font-size:28px;opacity:.95">Check your plugins before you upgrade PHP.</div>
	<div style="font-size:24px;opacity:.85;margin-top:10px">Free on WordPress.org &nbsp;·&nbsp; Nothing leaves your site &nbsp;·&nbsp; airworthywp.com</div>
	</body></html>`
);
// A still page gives the screen recorder no frames, so the end card is an image held for 4 s.
const endCard = path.join( work, '4-end.png' );
await page.screenshot( { path: endCard } );
await browser.close();

// Join the parts into one MP4 (1280x720, H.264, no sound) that YouTube and players accept.
fs.mkdirSync( outDir, { recursive: true } );
const out = path.join( outDir, 'airworthy-demo.mp4' );
const inputs = [ ...segments.flatMap( ( f ) => [ '-i', f ] ), '-loop', '1', '-framerate', '30', '-t', '4', '-i', endCard ];
const count = segments.length + 1;
const scale = Array.from( { length: count }, ( _, i ) => `[${ i }:v]scale=${ W }:${ H }:force_original_aspect_ratio=decrease,pad=${ W }:${ H }:(ow-iw)/2:(oh-ih)/2:white,fps=30,setsar=1,format=yuv420p[v${ i }]` ).join( ';' );
const concat = Array.from( { length: count }, ( _, i ) => `[v${ i }]` ).join( '' ) + `concat=n=${ count }:v=1:a=0[out]`;
execFileSync( FFMPEG, [ '-y', '-loglevel', 'error', ...inputs, '-filter_complex', scale + ';' + concat, '-map', '[out]', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-crf', '20', '-movflags', '+faststart', out ] );
const secs = execFileSync( FFMPEG.replace( /ffmpeg$/, 'ffprobe' ), [ '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', out ] ).toString().trim();
console.log( 'video', path.relative( path.resolve( import.meta.dirname, '../..' ), out ), Math.round( Number( secs ) ), 's' );
if ( errors.length ) {
	console.log( 'page errors:', errors );
}
