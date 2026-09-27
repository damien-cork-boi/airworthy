// Copies the plugin's listing screenshots (assets/screenshots/) into public/ so the site
// always shows the same images as WordPress.org. Runs before `dev` and `build`.
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve( import.meta.dirname, '..' );
const from = path.resolve( root, '../assets/screenshots' );
const to = path.resolve( root, 'public/screenshots' );

fs.rmSync( to, { recursive: true, force: true } );
fs.mkdirSync( to, { recursive: true } );
// Held screenshots (SITE.heldScreenshots in src/config.ts) aren't copied, so they can't be
// reached by URL either.
const config = fs.readFileSync( path.join( root, 'src/config.ts' ), 'utf8' );
const held = ( config.match( /heldScreenshots:\s*\[([^\]]*)\]/ ) || [ '', '' ] )[ 1 ]
	.split( ',' )
	.map( ( v ) => Number( v.trim() ) )
	.filter( Boolean );

let n = 0;
for ( const file of fs.readdirSync( from ) ) {
	const m = file.match( /^screenshot-(\d+)\.png$/ );
	if ( m && ! held.includes( Number( m[ 1 ] ) ) ) {
		fs.copyFileSync( path.join( from, file ), path.join( to, file ) );
		n++;
	}
}
console.log( `sync-assets: ${ n } screenshots copied, held: ${ held.join( ', ' ) || 'none' }` );
