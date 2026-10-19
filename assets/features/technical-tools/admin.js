/**
 * Technical Tools settings tab: the .htaccess editor and its backups.
 *
 * The textarea has no name, so the main settings form never saves it; it
 * goes through its own REST route with its own permission checks.
 */
( function ( window, document ) {
	'use strict';

	const TagwrightSEO = window.TagwrightSEO;
	const root = document.querySelector( '[data-tagwright-htaccess]' );
	if ( ! TagwrightSEO || ! root ) {
		return;
	}

	const { __ } = window.wp.i18n;
	const $ = ( selector ) => root.querySelector( selector );
	const content = $( '[data-content]' );
	const confirmBox = $( '[data-confirm-remove]' );
	const saveButton = $( '[data-save-htaccess]' );
	const table = $( '[data-backups]' );
	const empty = $( '[data-no-backups]' );

	function failed( error ) {
		TagwrightSEO.toast( ( error && error.message ) || __( 'Something went wrong. Please try again.', 'tagwright-seo' ), 'error' );
	}

	function renderBackups( rows ) {
		const esc = TagwrightSEO.escapeHtml;
		$( 'tbody' ).innerHTML = rows.map( ( row ) =>
			'<tr>' +
			`<td>${ esc( row.date ) }</td>` +
			`<td>${ esc( row.user ) }</td>` +
			`<td class="is-num">${ esc( String( row.lines ) ) }</td>` +
			`<td class="is-num"><button type="button" class="tagwright-btn tagwright-btn--ghost tagwright-btn--sm" data-restore="${ esc( String( row.time ) ) }">${ TagwrightSEO.icon( 'refresh', 14 ) }<span>${ esc( __( 'Restore', 'tagwright-seo' ) ) }</span></button></td>` +
			'</tr>'
		).join( '' );
		table.hidden = ! rows.length;
		empty.hidden = !! rows.length;
	}

	function run( button, promise ) {
		button.classList.add( 'is-busy' );
		button.disabled = true;
		return promise
			.then( ( response ) => {
				if ( 'string' === typeof response.content ) {
					content.value = response.content;
				}
				if ( response.backups ) {
					renderBackups( response.backups );
				}
				confirmBox.checked = false;
				TagwrightSEO.toast( response.message );
			} )
			.catch( failed )
			.finally( () => {
				button.classList.remove( 'is-busy' );
				button.disabled = false;
			} );
	}

	saveButton.addEventListener( 'click', () => {
		run( saveButton, TagwrightSEO.api( 'technical-tools/htaccess', {
			method: 'POST',
			data: { content: content.value, confirm: confirmBox.checked },
		} ) );
	} );

	root.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '[data-restore]' );
		if ( ! button ) {
			return;
		}
		if ( ! window.confirm( __( 'Replace the current .htaccess with this backup? The current file is backed up first.', 'tagwright-seo' ) ) ) {
			return;
		}
		run( button, TagwrightSEO.api( 'technical-tools/htaccess/restore', {
			method: 'POST',
			data: { time: parseInt( button.dataset.restore, 10 ) },
		} ) );
	} );
}( window, document ) );
