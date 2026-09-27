import type { APIRoute } from 'astro';
import { SITE } from '../../config';

// RFC 9116 security contact file. "Expires" must be under a year ahead: it's set from the
// build date, so every deploy refreshes it (rebuild at least once a year).
export const GET: APIRoute = () => {
	const expires = new Date( Date.now() + 330 * 24 * 60 * 60 * 1000 );
	const body = [
		`Contact: mailto:${ SITE.email.security }`,
		`Expires: ${ expires.toISOString().replace( /\.\d{3}Z$/, 'Z' ) }`,
		'Preferred-Languages: en',
		`Canonical: ${ SITE.url }/.well-known/security.txt`,
		`Policy: ${ SITE.url }/security/`,
		'',
	].join( '\n' );
	return new Response( body, { headers: { 'Content-Type': 'text/plain; charset=utf-8' } } );
};
