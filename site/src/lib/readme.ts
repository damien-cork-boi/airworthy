/**
 * Reads the plugin's readme.txt (the WordPress.org listing) at build time, so the site's
 * docs, FAQ, changelog and screenshot captions always match the released plugin.
 *
 * readme.txt format: `=== Name ===`, a header of `Key: value` lines and a short description,
 * then `== Section ==` blocks with `= Subsection =` headings. The body text is Markdown.
 */
import fs from 'node:fs';
import path from 'node:path';
import { marked } from 'marked';

const README = path.resolve( process.cwd(), '../plugin/readme.txt' );

export interface Subsection {
	title: string;
	slug: string;
	markdown: string;
	html: string;
}

export interface Section {
	title: string;
	slug: string;
	intro: string; // HTML before the first subsection.
	subsections: Subsection[];
}

export interface Readme {
	name: string;
	headers: Record<string, string>;
	short: string;
	sections: Record<string, Section>;
	screenshots: { n: number; caption: string }[];
}

export function slugify( text: string ): string {
	return text
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-|-$/g, '' );
}

/**
 * A YouTube video that loads nothing from YouTube until it's played: our own poster image
 * and a play button; VideoPlayer.astro swaps in the privacy-enhanced (youtube-nocookie) player
 * on click. The poster is a frame of the demo video, in public/video/.
 */
export function videoHtml( id: string, title = 'Airworthy demo video' ): string {
	const safeId = id.replace( /[^A-Za-z0-9_-]/g, '' );
	return `<figure class="video"><button type="button" class="video-facade" data-yt="${ safeId }" aria-label="Play: ${ title } (plays from YouTube)"><img src="/video/airworthy-demo.jpg" width="1280" height="720" alt="" loading="lazy" /><span class="video-play" aria-hidden="true"></span></button><figcaption>${ title }. Plays from YouTube when you press play. <a href="https://www.youtube.com/watch?v=${ safeId }">Watch on YouTube</a></figcaption></figure>`;
}

/** The YouTube ID in a youtube.com/watch?v= or youtu.be/ link, or ''. */
export function youtubeId( url: string ): string {
	const m = url.match( /(?:youtu\.be\/|[?&]v=)([A-Za-z0-9_-]{6,})/ );
	return m ? m[ 1 ] : '';
}

export function md( text: string ): string {
	// WordPress.org's [youtube URL] embed, as a click-to-play video.
	const withVideo = text.replace( /^\[youtube\s+(\S+)\]\s*$/gm, ( _all, url ) => {
		const id = youtubeId( url );
		return id ? `\n${ videoHtml( id ) }\n` : '';
	} );
	return marked.parse( withVideo.trim(), { async: false, gfm: true } ) as string;
}

/** Inline Markdown only (no wrapping <p>). */
export function mdInline( text: string ): string {
	return marked.parseInline( text.trim(), { async: false, gfm: true } ) as string;
}

let cached: Readme | null = null;

export function readme(): Readme {
	if ( cached ) {
		return cached;
	}
	const raw = fs.readFileSync( README, 'utf8' ).replace( /\r\n?/g, '\n' );
	const [ head, ...rest ] = raw.split( /^==\s*(?!=)(.+?)\s*==\s*$/m );

	// Header: name, "Key: value" lines, then the short description.
	const headLines = head.trim().split( '\n' );
	const name = ( headLines.shift() || '' ).replace( /^===\s*|\s*===$/g, '' );
	const headers: Record<string, string> = {};
	const shortLines: string[] = [];
	for ( const line of headLines ) {
		const m = line.match( /^([A-Za-z][A-Za-z ]+):\s*(.+)$/ );
		if ( m && ! shortLines.length ) {
			headers[ m[ 1 ].trim() ] = m[ 2 ].trim();
		} else if ( line.trim() ) {
			shortLines.push( line.trim() );
		}
	}

	// Sections: split() alternates title, body.
	const sections: Record<string, Section> = {};
	for ( let i = 0; i < rest.length; i += 2 ) {
		const title = rest[ i ].trim();
		const body = ( rest[ i + 1 ] || '' ).trim();
		const parts = body.split( /^=\s*(?!=)(.+?)\s*=\s*$/m );
		const subsections: Subsection[] = [];
		for ( let j = 1; j < parts.length; j += 2 ) {
			const markdown = ( parts[ j + 1 ] || '' ).trim();
			subsections.push( { title: parts[ j ].trim(), slug: slugify( parts[ j ] ), markdown, html: md( markdown ) } );
		}
		sections[ slugify( title ) ] = { title, slug: slugify( title ), intro: md( parts[ 0 ] ), subsections };
	}

	// Screenshots: "1. Caption" lines.
	const screenshots: { n: number; caption: string }[] = [];
	const shotsBody = rest[ rest.findIndex( ( t, i ) => i % 2 === 0 && slugify( t ) === 'screenshots' ) + 1 ] || '';
	for ( const m of shotsBody.matchAll( /^(\d+)\.\s+(.+)$/gm ) ) {
		screenshots.push( { n: Number( m[ 1 ] ), caption: m[ 2 ].trim() } );
	}

	cached = { name, headers, short: shortLines.join( ' ' ), sections, screenshots };
	return cached;
}

/** A subsection by title within a section, e.g. sub( 'description', 'Privacy' ). */
export function sub( section: string, title: string ): Subsection | undefined {
	return readme().sections[ section ]?.subsections.find( ( s ) => s.slug === slugify( title ) );
}

/**
 * A Markdown file from the repository (e.g. SECURITY.md), as HTML. Links relative to the
 * repository point at it on GitHub, and the file's own top heading is dropped (pages set theirs).
 */
export function repoMarkdown( file: string, repoUrl: string ): string {
	const text = fs
		.readFileSync( path.resolve( process.cwd(), '..', file ), 'utf8' )
		.replace( /^#\s.+\n+/, '' )
		.replace( /\]\((?!https?:|mailto:|#)([^)]+)\)/g, `](${ repoUrl }/blob/main/$1)` );
	return md( text );
}

/**
 * A Markdown list of "* **Title.** text" / "* **Title:** text" items, as title + HTML text
 * (the readme's feature list and verdict list use this shape).
 */
export function boldList( markdown: string ): { title: string; html: string }[] {
	const items: { title: string; html: string }[] = [];
	for ( const m of markdown.matchAll( /^\*\s+\*\*(.+?)[.:]?\*\*[.:]?\s*(.+)$/gm ) ) {
		const text = m[ 2 ].charAt( 0 ).toUpperCase() + m[ 2 ].slice( 1 ); // After a colon the readme starts lower-case.
		items.push( { title: m[ 1 ].replace( /[.:]$/, '' ), html: mdInline( text ) } );
	}
	return items;
}
