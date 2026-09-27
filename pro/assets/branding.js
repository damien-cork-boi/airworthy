/**
 * Airworthy Pro: the branding form's logo picker (Media Library) and colour picker.
 *
 * @package AirworthyPro
 */

( function ( $ ) {
	'use strict';

	var text = window.airworthyProBranding || {};

	$(
		function () {
			$( '.airworthy-pro-color' ).wpColorPicker();

			var box    = $( '.airworthy-pro-logo' );
			var img    = box.find( 'img' );
			var input  = box.find( 'input[type=hidden]' );
			var remove = box.find( '.airworthy-pro-logo-remove' );
			var frame  = null;

			box.on(
				'click',
				'.airworthy-pro-logo-choose',
				function () {
					if ( ! frame ) {
							frame = wp.media( { title: text.choose, button: { text: text.use }, library: { type: 'image' }, multiple: false } );
							frame.on(
								'select',
								function () {
									var file = frame.state().get( 'selection' ).first().toJSON();
									var size = file.sizes && file.sizes.medium ? file.sizes.medium : file;
									input.val( file.id );
									img.attr( 'src', size.url ).prop( 'hidden', false );
									remove.prop( 'hidden', false );
								}
							);
					}
					frame.open();
				}
			);

			remove.on(
				'click',
				function () {
					input.val( '' );
					img.attr( 'src', '' ).prop( 'hidden', true );
					remove.prop( 'hidden', true );
				}
			);
		}
	);
}( jQuery ) );
