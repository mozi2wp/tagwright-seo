/**
 * Instant Indexing settings tab: key regeneration, the Google service
 * account box, manual submission and the log table.
 *
 * None of these inputs have a name attribute, so the settings form never
 * picks them up; each action talks to its own REST route.
 */
( function ( window, document ) {
	'use strict';

	const TagwrightSEO = window.TagwrightSEO;
	if ( ! TagwrightSEO || ! document.getElementById( 'tagwright-panel-indexing' ) ) {
		return;
	}

	const { __ } = window.wp.i18n;
	const panel = document.getElementById( 'tagwright-panel-indexing' );
	const $ = ( selector, root = panel ) => root.querySelector( selector );

	function failed( error ) {
		TagwrightSEO.toast( ( error && error.message ) || __( 'Something went wrong. Please try again.', 'tagwright-seo' ), 'error' );
	}

	function busy( button, promise ) {
		button.classList.add( 'is-busy' );
		button.disabled = true;
		return promise.finally( () => {
			button.classList.remove( 'is-busy' );
			button.disabled = false;
		} );
	}

	function confirmed( button ) {
		return ! button.dataset.confirm || window.confirm( button.dataset.confirm );
	}

	/* Log table ------------------------------------------------------- */

	const log = $( '[data-tagwright-indexing-log]' );

	function renderLog( rows ) {
		if ( ! log ) {
			return;
		}
		const table = $( '[data-log-table]', log );
		const body = $( 'tbody', table );
		const esc = TagwrightSEO.escapeHtml;

		body.innerHTML = rows.map( ( row ) =>
			'<tr>' +
			`<td>${ esc( row.time ) }</td>` +
			`<td>${ esc( row.engine ) }</td>` +
			`<td class="is-num">${ esc( String( row.count ) ) }</td>` +
			`<td class="is-num"><span class="tagwright-badge tagwright-badge--${ row.ok ? 'good' : 'bad' }">${ esc( row.status ? String( row.status ) : '—' ) }</span></td>` +
			`<td>${ esc( row.message ) }</td>` +
			'</tr>'
		).join( '' );

		table.hidden = ! rows.length;
		$( '[data-log-empty]', log ).hidden = !! rows.length;
		$( '[data-log-actions]', log ).hidden = ! rows.length;
	}

	const clearLog = log && $( '[data-clear-log]', log );
	if ( clearLog ) {
		clearLog.addEventListener( 'click', () => {
			busy( clearLog, TagwrightSEO.api( 'indexing/log', { method: 'DELETE' } ) )
				.then( ( response ) => {
					renderLog( response.log || [] );
					TagwrightSEO.toast( response.message );
				} )
				.catch( failed );
		} );
	}

	/* IndexNow key ---------------------------------------------------- */

	const keyBox = $( '[data-tagwright-indexing-key]' );
	const regenerate = keyBox && $( '[data-regenerate-key]', keyBox );
	if ( regenerate ) {
		regenerate.addEventListener( 'click', () => {
			if ( ! confirmed( regenerate ) ) {
				return;
			}
			busy( regenerate, TagwrightSEO.api( 'indexing/key', { method: 'POST' } ) )
				.then( ( response ) => {
					$( '[data-key]', keyBox ).textContent = response.key;
					$( '[data-key-url]', keyBox ).href = response.url;
					TagwrightSEO.toast( response.message );
				} )
				.catch( failed );
		} );
	}

	/* Google service account ------------------------------------------ */

	const google = $( '[data-tagwright-indexing-google]' );
	if ( google ) {
		const saved = $( '[data-saved]', google );
		const empty = $( '[data-empty]', google );
		const json = $( '[data-google-json]', google );
		const save = $( '[data-save-google]', google );
		const remove = $( '[data-remove-google]', google );

		save.addEventListener( 'click', () => {
			if ( ! json.value.trim() ) {
				json.focus();
				return;
			}
			busy( save, TagwrightSEO.api( 'indexing/google-key', { method: 'POST', data: { json: json.value } } ) )
				.then( ( response ) => {
					// Clear the private key from the page as soon as it is stored.
					json.value = '';
					$( '[data-email]', google ).textContent = window.wp.i18n.sprintf(
						/* translators: %s: service account email. */
						__( 'for %s', 'tagwright-seo' ),
						response.email
					);
					saved.hidden = false;
					empty.hidden = true;
					TagwrightSEO.toast( response.message );
				} )
				.catch( failed );
		} );

		remove.addEventListener( 'click', () => {
			if ( ! confirmed( remove ) ) {
				return;
			}
			busy( remove, TagwrightSEO.api( 'indexing/google-key', { method: 'DELETE' } ) )
				.then( ( response ) => {
					saved.hidden = true;
					empty.hidden = false;
					TagwrightSEO.toast( response.message );
				} )
				.catch( failed );
		} );
	}

	/* Manual submission ----------------------------------------------- */

	const manual = $( '[data-tagwright-indexing-manual]' );
	if ( manual ) {
		const urls = $( '[data-urls]', manual );
		const submit = $( '[data-submit-urls]', manual );

		submit.addEventListener( 'click', () => {
			if ( ! urls.value.trim() ) {
				urls.focus();
				return;
			}
			busy( submit, TagwrightSEO.api( 'indexing/submit', { method: 'POST', data: { urls: urls.value } } ) )
				.then( ( response ) => {
					renderLog( response.log || [] );
					TagwrightSEO.toast( response.message, response.success ? 'success' : 'error' );
					if ( response.success ) {
						urls.value = '';
					}
				} )
				.catch( failed );
		} );
	}
}( window, document ) );
