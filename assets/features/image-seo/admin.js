/**
 * Tagwright SEO Image SEO: bulk alt text tool on the settings tab.
 */
( function ( window, document ) {
	'use strict';

	const root = document.querySelector( '[data-tagwright-image-seo-bulk]' );
	if ( ! root ) {
		return;
	}

	const { __, _n, sprintf } = window.wp.i18n;
	const TagwrightSEO = window.TagwrightSEO;
	const ROUTE = 'image-seo/bulk-alt';

	const summary = root.querySelector( '[data-bulk-summary]' );
	const previewBox = root.querySelector( '[data-bulk-preview]' );
	const rows = previewBox.querySelector( 'tbody' );
	const progress = root.querySelector( '[data-bulk-progress]' );
	const bar = progress.querySelector( 'span' );
	const button = root.querySelector( '[data-bulk-run]' );

	function failed( error ) {
		TagwrightSEO.toast( ( error && error.message ) || __( 'Something went wrong. Please try again.', 'tagwright-seo' ), 'error' );
	}

	function cell( content ) {
		const td = document.createElement( 'td' );
		if ( content instanceof window.Node ) {
			td.appendChild( content );
		} else {
			td.textContent = content;
		}
		return td;
	}

	function showStatus( state ) {
		button.disabled = ! state.missing;
		summary.textContent = state.missing
			? sprintf(
				/* translators: %s: number of images. */
				_n( '%s image has no alt text. Here is what it would get:', '%s images have no alt text. Here are a few examples of what they would get:', state.missing, 'tagwright-seo' ),
				state.missing.toLocaleString()
			)
			: __( 'Every image in your media library has alt text.', 'tagwright-seo' );

		rows.textContent = '';
		( state.examples || [] ).forEach( ( example ) => {
			const tr = document.createElement( 'tr' );

			let image = document.createTextNode( '' );
			if ( example.thumb ) {
				image = document.createElement( 'img' );
				image.src = example.thumb;
				image.alt = '';
				image.width = 48;
				image.height = 48;
				image.loading = 'lazy';
			}

			let file = document.createTextNode( example.file );
			if ( example.edit ) {
				file = document.createElement( 'a' );
				file.href = example.edit;
				file.textContent = example.file;
			}

			const alt = example.alt
				? document.createTextNode( example.alt )
				: Object.assign( document.createElement( 'span' ), { className: 'tagwright-muted', textContent: __( 'Nothing: the template and file name are empty', 'tagwright-seo' ) } );

			tr.append( cell( image ), cell( file ), cell( alt ) );
			rows.appendChild( tr );
		} );

		previewBox.hidden = ! ( state.examples && state.examples.length );
	}

	function load() {
		return TagwrightSEO.api( ROUTE ).then( showStatus ).catch( failed );
	}

	// The tab is one of many panels on the settings screen. Count only once
	// it is actually opened, instead of on every visit to the settings.
	if ( 'IntersectionObserver' in window ) {
		const observer = new window.IntersectionObserver( ( entries ) => {
			if ( entries.some( ( entry ) => entry.isIntersecting ) ) {
				observer.disconnect();
				load();
			}
		} );
		observer.observe( root );
	} else {
		load();
	}

	button.addEventListener( 'click', () => {
		let filled = 0;

		button.classList.add( 'is-busy' );
		button.disabled = true;
		progress.hidden = false;
		bar.style.width = '0%';

		const step = ( offset ) => TagwrightSEO.api( ROUTE, { method: 'POST', data: { offset } } ).then( ( state ) => {
			filled += state.filled || 0;
			bar.style.width = state.percent + '%';
			if ( 'running' === state.status ) {
				return step( state.offset );
			}
			TagwrightSEO.toast( sprintf(
				/* translators: %s: number of images. */
				_n( 'Alt text added to %s image.', 'Alt text added to %s images.', filled, 'tagwright-seo' ),
				filled.toLocaleString()
			) );
			return state;
		} );

		step( 0 )
			.then( load )
			.catch( failed )
			.finally( () => {
				button.classList.remove( 'is-busy' );
				setTimeout( () => {
					progress.hidden = true;
				}, 1200 );
			} );
	} );
}( window, document ) );
