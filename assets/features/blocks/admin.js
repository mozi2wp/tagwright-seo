/**
 * Tagwright SEO Content Blocks: copy buttons on the settings tab.
 */
( function ( window, document ) {
	'use strict';

	const { __ } = window.wp.i18n;
	const TagwrightSEO = window.TagwrightSEO;

	document.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '[data-tagwright-copy]' );
		if ( ! button ) {
			return;
		}

		const field = document.getElementById( button.dataset.tagwrightCopy );
		if ( ! field ) {
			return;
		}

		const done = () => TagwrightSEO.toast( __( 'Copied to the clipboard.', 'tagwright-seo' ) );

		if ( window.navigator.clipboard && window.isSecureContext ) {
			window.navigator.clipboard.writeText( field.value ).then( done ).catch( () => field.select() );
			return;
		}

		// Older browsers and plain-HTTP sites: select the text so Ctrl+C works.
		field.select();
		try {
			if ( document.execCommand( 'copy' ) ) {
				done();
			}
		} catch ( e ) {}
	} );
}( window, document ) );
