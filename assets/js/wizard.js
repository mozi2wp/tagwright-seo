/**
 * Tagwright SEO setup wizard.
 */
( function ( window, document ) {
	'use strict';

	const root = document.getElementById( 'tagwright-wizard' );
	if ( ! root ) {
		return;
	}

	const { __ } = window.wp.i18n;
	const TagwrightSEO = window.TagwrightSEO;
	const form = root.querySelector( '[data-wizard]' );
	const steps = Array.from( root.querySelectorAll( '[data-step]' ) );
	const labels = Array.from( root.querySelectorAll( '[data-step-label]' ) );
	const back = root.querySelector( '[data-wizard-back]' );
	const next = root.querySelector( '[data-wizard-next]' );
	const finish = root.querySelector( '[data-wizard-finish]' );
	const skip = root.querySelector( '[data-wizard-skip]' );
	let current = 0;

	function show( index ) {
		current = Math.max( 0, Math.min( steps.length - 1, index ) );
		steps.forEach( ( step, i ) => {
			step.hidden = i !== current;
		} );
		labels.forEach( ( label, i ) => {
			label.classList.toggle( 'is-done', i < current );
			label.classList.toggle( 'is-current', i === current );
		} );

		back.hidden = 0 === current;
		skip.hidden = 0 !== current;
		next.hidden = current === steps.length - 1;
		finish.hidden = current !== steps.length - 1;
		next.firstChild.textContent = 0 === current ? __( 'Let\'s go', 'tagwright-seo' ) : __( 'Continue', 'tagwright-seo' );

		const focusable = steps[ current ].querySelector( 'input:not([type="hidden"]), textarea, button.tagwright-dropdown__button' );
		if ( focusable && current > 0 ) {
			focusable.focus( { preventScroll: true } );
		}
		window.scrollTo( { top: 0, behavior: 'smooth' } );
	}

	function collect() {
		const settings = {};
		const modules = {};

		for ( const [ name, value ] of new FormData( form ).entries() ) {
			let match = name.match( /^tagwright\[([^\]]+)\]$/ );
			if ( match ) {
				settings[ match[ 1 ] ] = value;
				continue;
			}
			match = name.match( /^tagwright_modules\[([^\]]+)\]$/ );
			if ( match ) {
				modules[ match[ 1 ] ] = '1' === value;
			}
		}

		return { settings, modules };
	}

	// Skipping still counts as "set up", so the reminder notice goes away.
	skip.addEventListener( 'click', ( event ) => {
		event.preventDefault();
		skip.classList.add( 'is-busy' );
		TagwrightSEO.api( 'wizard', { method: 'POST', data: { settings: {}, modules: {} } } )
			.then( ( response ) => {
				window.location.href = response.redirect;
			} )
			.catch( () => {
				window.location.href = skip.href;
			} );
	} );

	back.addEventListener( 'click', () => show( current - 1 ) );
	next.addEventListener( 'click', () => show( current + 1 ) );

	// Keep the feature cards in sync with their switches.
	root.querySelectorAll( 'input[name^="tagwright_modules"][type="checkbox"]' ).forEach( ( input ) => {
		input.addEventListener( 'change', () => input.closest( '.tagwright-module' ).classList.toggle( 'is-active', input.checked ) );
	} );

	// Importers.
	root.querySelectorAll( '[data-wizard-importer]' ).forEach( ( box ) => {
		const button = box.querySelector( '[data-wizard-import]' );
		const progress = box.querySelector( '.tagwright-progress' );
		const bar = progress.querySelector( 'span' );

		button.addEventListener( 'click', () => {
			button.classList.add( 'is-busy' );
			progress.hidden = false;

			const step = ( offset ) => TagwrightSEO.api( 'importers/' + box.dataset.wizardImporter, { method: 'POST', data: { offset, settings: 0 === offset } } )
				.then( ( state ) => {
					bar.style.width = state.percent + '%';
					if ( 'running' === state.status ) {
						return step( state.offset );
					}
					button.textContent = __( 'Imported', 'tagwright-seo' );
					button.disabled = true;
					TagwrightSEO.toast( state.message );
					return state;
				} );

			step( 0 )
				.catch( ( error ) => TagwrightSEO.toast( ( error && error.message ) || __( 'Import failed.', 'tagwright-seo' ), 'error' ) )
				.finally( () => button.classList.remove( 'is-busy' ) );
		} );
	} );

	form.addEventListener( 'submit', ( event ) => {
		event.preventDefault();
		finish.classList.add( 'is-busy' );

		TagwrightSEO.api( 'wizard', { method: 'POST', data: collect() } )
			.then( ( response ) => {
				TagwrightSEO.toast( __( 'All set! Taking you to your dashboard…', 'tagwright-seo' ) );
				window.location.href = response.redirect;
			} )
			.catch( ( error ) => {
				finish.classList.remove( 'is-busy' );
				TagwrightSEO.toast( ( error && error.message ) || __( 'Could not save. Please try again.', 'tagwright-seo' ), 'error' );
			} );
	} );

	show( 0 );
}( window, document ) );
