/**
 * Tagwright SEO tools screen: importers, settings backup, index rebuild and
 * maintenance actions.
 */
( function ( window, document ) {
	'use strict';

	const root = document.getElementById( 'tagwright-tools' );
	if ( ! root ) {
		return;
	}

	const { __ } = window.wp.i18n;
	const TagwrightSEO = window.TagwrightSEO;

	function failed( error ) {
		TagwrightSEO.toast( ( error && error.message ) || __( 'Something went wrong. Please try again.', 'tagwright-seo' ), 'error' );
	}

	// Importers run in batches until the server says they are complete.
	root.querySelectorAll( '[data-importer]' ).forEach( ( row ) => {
		const button = row.querySelector( '[data-run-import]' );
		const settings = row.querySelector( '[data-import-settings]' );
		const progress = row.querySelector( '.tagwright-progress' );
		const bar = progress.querySelector( 'span' );

		button.addEventListener( 'click', () => {
			button.classList.add( 'is-busy' );
			progress.hidden = false;
			bar.style.width = '0%';

			const step = ( offset ) => TagwrightSEO.api( 'importers/' + row.dataset.importer, {
				method: 'POST',
				data: { offset, settings: 0 === offset && settings.checked },
			} ).then( ( state ) => {
				bar.style.width = state.percent + '%';
				if ( 'running' === state.status ) {
					return step( state.offset );
				}
				TagwrightSEO.toast( state.message );
				return state;
			} );

			step( 0 ).catch( failed ).finally( () => button.classList.remove( 'is-busy' ) );
		} );
	} );

	// Export: fetch the settings and download them as a JSON file.
	const exportButton = root.querySelector( '[data-export]' );
	exportButton.addEventListener( 'click', () => {
		exportButton.classList.add( 'is-busy' );
		TagwrightSEO.api( 'settings' )
			.then( ( settings ) => {
				const payload = {
					plugin: 'tagwright-seo',
					version: ( window.tagwrightSeoAdmin && window.tagwrightSeoAdmin.version ) || '',
					exported: new Date().toISOString(),
					settings,
				};
				const blob = new Blob( [ JSON.stringify( payload, null, 2 ) ], { type: 'application/json' } );
				const link = document.createElement( 'a' );
				link.href = URL.createObjectURL( blob );
				link.download = 'tagwright-seo-settings-' + new Date().toISOString().slice( 0, 10 ) + '.json';
				document.body.appendChild( link );
				link.click();
				link.remove();
				setTimeout( () => URL.revokeObjectURL( link.href ), 1000 );
			} )
			.catch( failed )
			.finally( () => exportButton.classList.remove( 'is-busy' ) );
	} );

	// Import a settings file.
	const fileInput = root.querySelector( '[data-import-file]' );
	fileInput.addEventListener( 'change', () => {
		const file = fileInput.files[ 0 ];
		if ( ! file ) {
			return;
		}
		file.text()
			.then( ( text ) => {
				let json;
				try {
					json = JSON.parse( text );
				} catch ( e ) {
					throw new Error( __( 'That file is not valid JSON.', 'tagwright-seo' ) );
				}
				return TagwrightSEO.api( 'import-settings', { method: 'POST', data: { settings: json } } );
			} )
			.then( ( response ) => TagwrightSEO.toast( response.message ) )
			.catch( failed )
			.finally( () => {
				fileInput.value = '';
			} );
	} );

	// Rebuild the index with a live progress bar.
	const rebuild = root.querySelector( '[data-rebuild-index]' );
	rebuild.addEventListener( 'click', () => {
		const card = root.querySelector( '[data-index-card]' );
		const bar = card.querySelector( '.tagwright-progress span' );
		const status = card.querySelector( '[data-index-status]' );
		rebuild.classList.add( 'is-busy' );

		const step = ( action ) => TagwrightSEO.api( 'index/' + action, { method: 'POST' } ).then( ( state ) => {
			bar.style.width = state.percent + '%';
			status.textContent = window.wp.i18n.sprintf(
				/* translators: %d: percentage. */
				__( 'Indexing: %d%% done…', 'tagwright-seo' ),
				state.percent
			);
			if ( 'running' === state.status ) {
				return step( 'batch' );
			}
			status.textContent = __( 'Index is up to date.', 'tagwright-seo' );
			TagwrightSEO.toast( __( 'Index rebuilt.', 'tagwright-seo' ) );
			return state;
		} );

		step( 'start' ).catch( failed ).finally( () => rebuild.classList.remove( 'is-busy' ) );
	} );

	// One-click maintenance tools.
	root.querySelectorAll( '[data-tool]' ).forEach( ( button ) => {
		button.addEventListener( 'click', () => {
			if ( button.dataset.confirm && ! window.confirm( button.dataset.confirm ) ) {
				return;
			}
			button.classList.add( 'is-busy' );
			TagwrightSEO.api( 'tools/' + button.dataset.tool, { method: 'POST' } )
				.then( ( response ) => TagwrightSEO.toast( response.message ) )
				.catch( failed )
				.finally( () => button.classList.remove( 'is-busy' ) );
		} );
	} );
}( window, document ) );
