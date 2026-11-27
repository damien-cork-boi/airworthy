/**
 * Copy button on the Fix brief tab.
 *
 * @package AirworthyPro
 */

( function () {
	'use strict';

	document.addEventListener(
		'click',
		function ( event ) {
			var button = event.target.closest ? event.target.closest( '.airworthy-pro-copy-brief' ) : null;
			if ( ! button ) {
				return;
			}
			var field = document.getElementById( button.getAttribute( 'data-target' ) );
			if ( ! field ) {
				return;
			}
			var label = button.textContent;
			var done  = function () {
				button.textContent = '\u2713 ' + button.getAttribute( 'data-done' );
				window.setTimeout(
					function () {
						button.textContent = label;
					},
					2000
				);
			};
			field.focus();
			field.select();
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( field.value ).then( done );
				return;
			}
			try {
				if ( document.execCommand( 'copy' ) ) {
					done();
				}
			} catch ( e ) {
				// The text stays selected: Ctrl+C or Cmd+C copies it.
			}
		}
	);
}() );
