/**
 * The articles section (/articles/): Markdown files in src/articles/, read at build time.
 *
 * Each file starts with a front-matter block of `key: value` lines: title, slug, description,
 * date (YYYY-MM-DD) and draft (true until the article is meant to be public). Drafts are not
 * built, linked or listed in the sitemap; PREVIEW_ARTICLES=1 npm run dev shows them locally.
 * The whole section stays invisible (no nav link, no index page) while it has no live article.
 */
import { marked } from 'marked';

export interface Article {
	title: string;
	slug: string;
	description: string;
	date: string;
	draft: boolean;
	html: string;
}

const files = import.meta.glob( '../articles/*.md', { query: '?raw', import: 'default', eager: true } ) as Record<string, string>;

function parse( raw: string ): Article {
	const m = raw.match( /^---\n([\s\S]*?)\n---\n([\s\S]*)$/ );
	if ( ! m ) {
		throw new Error( 'An article is missing its front matter.' );
	}
	const meta: Record<string, string> = {};
	for ( const line of m[ 1 ].split( '\n' ) ) {
		const i = line.indexOf( ':' );
		if ( i > 0 ) {
			meta[ line.slice( 0, i ).trim() ] = line.slice( i + 1 ).trim();
		}
	}
	for ( const key of [ 'title', 'slug', 'description', 'date' ] ) {
		if ( ! meta[ key ] ) {
			throw new Error( `An article is missing "${ key }" in its front matter.` );
		}
	}
	return {
		title: meta.title,
		slug: meta.slug,
		description: meta.description,
		date: meta.date,
		draft: 'true' === meta.draft,
		html: marked.parse( m[ 2 ], { async: false } ) as string,
	};
}

const preview = '1' === import.meta.env.PREVIEW_ARTICLES;

/** Live articles, newest first (drafts included when previewing). */
export const ARTICLES: Article[] = Object.values( files )
	.map( parse )
	.filter( ( a ) => preview || ! a.draft )
	.sort( ( a, b ) => b.date.localeCompare( a.date ) );

export const ARTICLES_LIVE = ARTICLES.length > 0;
export const articleUrl = ( slug: string ) => `/articles/${ slug }/`;
