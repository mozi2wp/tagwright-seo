/**
 * Tagwright SEO Redirects screen: the redirects table, the 404 log, the add and
 * edit form, bulk actions, CSV import and export, and importing redirects
 * from other plugins.
 *
 * Everything talks to /tagwright-seo/v1/redirects through TagwrightSEO.api(), which
 * takes care of the REST nonce.
 */
( function ( window, document ) {
	'use strict';

	const root = document.getElementById( 'tagwright-redirects' );
	if ( ! root ) {
		return;
	}

	const { __, _n, sprintf } = window.wp.i18n;
	const TagwrightSEO = window.TagwrightSEO;
	const config = window.tagwrightSeoRedirects || {};
	const esc = TagwrightSEO.escapeHtml;
	const icon = TagwrightSEO.icon;
	const IMPORT_BATCH = 500;

	function failed( error ) {
		TagwrightSEO.toast( ( error && error.message ) || __( 'Something went wrong. Please try again.', 'tagwright-seo' ), 'error' );
	}

	function debounce( callback, wait ) {
		let timer = null;
		return ( ...args ) => {
			clearTimeout( timer );
			timer = setTimeout( () => callback( ...args ), wait );
		};
	}

	function query( params ) {
		return Object.keys( params )
			.filter( ( key ) => '' !== params[ key ] && null !== params[ key ] && undefined !== params[ key ] )
			.map( ( key ) => encodeURIComponent( key ) + '=' + encodeURIComponent( params[ key ] ) )
			.join( '&' );
	}

	function checkbox( attrs, label ) {
		return `<label class="tagwright-check"><input type="checkbox" ${ attrs } /><span class="tagwright-check__box">${ icon( 'check', 12 ) }</span><span class="screen-reader-text">${ esc( label ) }</span></label>`;
	}

	function statusBadge( status ) {
		const type = 410 === status || 451 === status ? 'bad' : ( 301 === status ? 'good' : 'info' );
		return `<span class="tagwright-badge tagwright-badge--${ type }" title="${ esc( ( config.statuses || {} )[ status ] || '' ) }">${ esc( String( status ) ) }</span>`;
	}

	function sortHeader( view, key, label, numeric = false ) {
		const active = view.state.orderby === key;
		const arrow = active ? ( 'asc' === view.state.order ? '↑' : '↓' ) : '';
		return `<th class="${ numeric ? 'is-num' : '' }"${ active ? ` aria-sort="${ 'asc' === view.state.order ? 'ascending' : 'descending' }"` : '' }><button type="button" class="tagwright-redirects-sort${ active ? ' is-active' : '' }" data-sort="${ esc( key ) }">${ esc( label ) } <span aria-hidden="true">${ arrow }</span></button></th>`;
	}

	/* ------------------------------------------------------------------
	 * A paged, searchable table bound to one REST endpoint
	 * --------------------------------------------------------------- */

	function createView( name, options ) {
		const panel = root.querySelector( `[data-panel="${ name }"]` );
		const view = {
			name,
			panel,
			loaded: false,
			items: [],
			selected: new Set(),
			state: Object.assign( { page: 1, per_page: 20, search: '', orderby: '', order: 'desc' }, options.state || {} ),
			table: panel.querySelector( '[data-table]' ),
			footer: panel.querySelector( '[data-footer]' ),
			bulk: panel.querySelector( '[data-bulk]' ),
		};

		view.load = function () {
			view.table.setAttribute( 'aria-busy', 'true' );
			return TagwrightSEO.api( options.endpoint + '?' + query( view.state ) )
				.then( ( response ) => {
					// A delete can leave us past the last page; step back once.
					if ( ! response.items.length && view.state.page > 1 && view.state.page > response.pages ) {
						view.state.page = response.pages;
						return view.load();
					}
					view.loaded = true;
					view.items = response.items;
					view.selected.clear();
					view.render( response );
					return response;
				} )
				.catch( failed )
				.finally( () => view.table.removeAttribute( 'aria-busy' ) );
		};

		view.isFiltered = function () {
			return '' !== view.state.search || ( options.filters || [] ).some( ( key ) => view.state[ key ] );
		};

		view.render = function ( response ) {
			if ( ! response.items.length ) {
				view.table.innerHTML = options.empty( view.isFiltered() );
				view.footer.hidden = true;
			} else {
				view.table.innerHTML = `<table class="tagwright-table tagwright-redirects-list">
					<thead><tr>
						<th class="tagwright-redirects-list__check">${ checkbox( 'data-select-all', __( 'Select all', 'tagwright-seo' ) ) }</th>
						${ options.head( view ) }
					</tr></thead>
					<tbody>${ response.items.map( ( item ) => options.row( item ) ).join( '' ) }</tbody>
				</table>`;
				view.footer.hidden = false;
			}

			view.footer.querySelector( '[data-summary]' ).textContent = sprintf( options.summary( response.total ), response.total.toLocaleString() );
			view.footer.querySelector( '[data-page-label]' ).textContent = sprintf(
				/* translators: 1: current page, 2: number of pages. */
				__( 'Page %1$d of %2$d', 'tagwright-seo' ),
				response.page,
				response.pages
			);
			view.footer.querySelector( '[data-page="prev"]' ).disabled = response.page <= 1;
			view.footer.querySelector( '[data-page="next"]' ).disabled = response.page >= response.pages;
			view.footer.querySelector( '.tagwright-redirects-pager' ).hidden = response.pages <= 1;

			if ( ! view.isFiltered() ) {
				const badge = root.querySelector( `[data-count="${ name }"]` );
				badge.textContent = response.total.toLocaleString();
				badge.hidden = 0 === response.total;
			}

			view.updateBulk();
		};

		view.updateBulk = function () {
			const count = view.selected.size;
			view.bulk.hidden = ! count;
			view.bulk.querySelector( '[data-selected]' ).textContent = sprintf(
				/* translators: %d: number of selected rows. */
				_n( '%d selected', '%d selected', count, 'tagwright-seo' ),
				count
			);
			const all = view.table.querySelector( '[data-select-all]' );
			if ( all ) {
				all.checked = count > 0 && count === view.items.length;
				all.indeterminate = count > 0 && count < view.items.length;
			}
		};

		view.item = function ( id ) {
			return view.items.find( ( item ) => item.id === id );
		};

		// Search, filters, sorting, paging and selection.
		const search = panel.querySelector( '[data-search]' );
		search.addEventListener( 'input', debounce( () => {
			view.state.search = search.value.trim();
			view.state.page = 1;
			view.load();
		}, 300 ) );

		panel.querySelectorAll( '[data-filter]' ).forEach( ( select ) => {
			select.addEventListener( 'change', () => {
				view.state[ select.dataset.filter ] = select.value;
				view.state.page = 1;
				view.load();
			} );
		} );

		view.footer.addEventListener( 'click', ( event ) => {
			const button = event.target.closest( '[data-page]' );
			if ( button ) {
				view.state.page += 'next' === button.dataset.page ? 1 : -1;
				view.load();
			}
		} );

		view.table.addEventListener( 'click', ( event ) => {
			const sort = event.target.closest( '[data-sort]' );
			if ( sort ) {
				const key = sort.dataset.sort;
				view.state.order = view.state.orderby === key && 'desc' === view.state.order ? 'asc' : 'desc';
				view.state.orderby = key;
				view.load();
			}
		} );

		view.table.addEventListener( 'change', ( event ) => {
			if ( event.target.matches( '[data-select-all]' ) ) {
				view.selected.clear();
				view.table.querySelectorAll( '[data-select]' ).forEach( ( input ) => {
					input.checked = event.target.checked;
					if ( input.checked ) {
						view.selected.add( parseInt( input.value, 10 ) );
					}
				} );
				view.updateBulk();
			} else if ( event.target.matches( '[data-select]' ) ) {
				const id = parseInt( event.target.value, 10 );
				if ( event.target.checked ) {
					view.selected.add( id );
				} else {
					view.selected.delete( id );
				}
				view.updateBulk();
			}
		} );

		view.bulk.addEventListener( 'click', ( event ) => {
			const button = event.target.closest( '[data-bulk-action]' );
			if ( ! button || ! view.selected.size ) {
				return;
			}
			const action = button.dataset.bulkAction;
			if ( 'delete' === action && ! window.confirm( options.confirmDelete( view.selected.size ) ) ) {
				return;
			}
			button.classList.add( 'is-busy' );
			options.bulk( action, Array.from( view.selected ) )
				.then( ( response ) => {
					TagwrightSEO.toast( response.message );
					return view.load();
				} )
				.catch( failed )
				.finally( () => button.classList.remove( 'is-busy' ) );
		} );

		return view;
	}

	/* ------------------------------------------------------------------
	 * Redirects view
	 * --------------------------------------------------------------- */

	const rules = createView( 'rules', {
		endpoint: 'redirects',
		filters: [ 'match_type', 'status' ],
		state: { match_type: '', status: '' },
		/* translators: %s: number of redirects. */
		summary: ( total ) => _n( '%s redirect', '%s redirects', total, 'tagwright-seo' ),
		confirmDelete: ( count ) => sprintf(
			/* translators: %d: number of redirects. */
			_n( 'Delete %d redirect? This cannot be undone.', 'Delete %d redirects? This cannot be undone.', count, 'tagwright-seo' ),
			count
		),
		bulk: ( action, ids ) => TagwrightSEO.api( 'redirects/bulk', { method: 'POST', data: { action, ids } } ),
		empty: ( filtered ) => filtered
			? `<div class="tagwright-empty">${ icon( 'search', 32 ) }<h3>${ esc( __( 'No redirects match', 'tagwright-seo' ) ) }</h3><p>${ esc( __( 'Try another search or filter.', 'tagwright-seo' ) ) }</p></div>`
			: `<div class="tagwright-empty">${ icon( 'redirect', 32 ) }<h3>${ esc( __( 'No redirects yet', 'tagwright-seo' ) ) }</h3><p>${ esc( __( 'Add one by hand, import a CSV file, or create one straight from the 404 log. Slug changes are redirected for you.', 'tagwright-seo' ) ) }</p><div class="tagwright-redirects-empty-actions"><button type="button" class="tagwright-btn tagwright-btn--primary" data-add>${ icon( 'plus', 16 ) }${ esc( __( 'Add redirect', 'tagwright-seo' ) ) }</button><button type="button" class="tagwright-btn tagwright-btn--secondary" data-migrate-open>${ icon( 'download', 16 ) }${ esc( __( 'Import from another plugin', 'tagwright-seo' ) ) }</button></div></div>`,
		head: ( view ) => [
			sortHeader( view, 'source', __( 'Old address', 'tagwright-seo' ) ),
			sortHeader( view, 'target', __( 'New address', 'tagwright-seo' ) ),
			sortHeader( view, 'status', __( 'Type', 'tagwright-seo' ) ),
			sortHeader( view, 'hits', __( 'Hits', 'tagwright-seo' ), true ),
			sortHeader( view, 'last_hit', __( 'Last hit', 'tagwright-seo' ) ),
			`<th>${ esc( __( 'Active', 'tagwright-seo' ) ) }</th>`,
			`<th><span class="screen-reader-text">${ esc( __( 'Actions', 'tagwright-seo' ) ) }</span></th>`,
		].join( '' ),
		row: ( item ) => {
			const match = 'exact' === item.match_type ? '' : ` <span class="tagwright-badge tagwright-badge--accent">${ esc( ( config.matchTypes || {} )[ item.match_type ] || item.match_type ) }</span>`;
			const source = item.test_url
				? `<a href="${ esc( item.test_url ) }" target="_blank" rel="noopener" title="${ esc( __( 'Test this redirect', 'tagwright-seo' ) ) }"><code>${ esc( item.source ) }</code></a>`
				: `<code>${ esc( item.source ) }</code>`;
			const target = item.target ? `<code>${ esc( item.target ) }</code>` : `<span class="tagwright-muted">${ esc( __( 'No target, answers with the status', 'tagwright-seo' ) ) }</span>`;
			return `<tr class="${ item.enabled ? '' : 'is-disabled' }" data-id="${ item.id }">
				<td class="tagwright-redirects-list__check">${ checkbox( `data-select value="${ item.id }"`, __( 'Select', 'tagwright-seo' ) ) }</td>
				<td class="tagwright-redirects-list__url">${ source }${ match }${ item.notes ? `<small class="tagwright-muted">${ esc( item.notes ) }</small>` : '' }</td>
				<td class="tagwright-redirects-list__url">${ target }</td>
				<td>${ statusBadge( item.status ) }</td>
				<td class="is-num">${ esc( item.hits.toLocaleString() ) }</td>
				<td class="tagwright-muted">${ esc( item.last_hit || '–' ) }</td>
				<td><label class="tagwright-toggle"><input type="checkbox" data-enabled ${ item.enabled ? 'checked' : '' } /><span class="tagwright-toggle__track" aria-hidden="true"><span class="tagwright-toggle__thumb"></span></span><span class="screen-reader-text">${ esc( __( 'Redirect is on', 'tagwright-seo' ) ) }</span></label></td>
				<td class="tagwright-redirects-list__actions">
					<button type="button" class="tagwright-btn tagwright-btn--ghost tagwright-btn--icon tagwright-btn--sm" data-edit title="${ esc( __( 'Edit', 'tagwright-seo' ) ) }" aria-label="${ esc( __( 'Edit', 'tagwright-seo' ) ) }">${ icon( 'pen', 16 ) }</button>
					<button type="button" class="tagwright-btn tagwright-btn--ghost tagwright-btn--icon tagwright-btn--sm" data-delete title="${ esc( __( 'Delete', 'tagwright-seo' ) ) }" aria-label="${ esc( __( 'Delete', 'tagwright-seo' ) ) }">${ icon( 'trash', 16 ) }</button>
				</td>
			</tr>`;
		},
	} );

	rules.table.addEventListener( 'click', ( event ) => {
		const row = event.target.closest( 'tr[data-id]' );
		if ( ! row ) {
			return;
		}
		const item = rules.item( parseInt( row.dataset.id, 10 ) );

		if ( event.target.closest( '[data-edit]' ) ) {
			editor.open( item );
		} else if ( event.target.closest( '[data-delete]' ) ) {
			if ( ! window.confirm( sprintf( /* translators: %s: old address. */ __( 'Delete the redirect for %s?', 'tagwright-seo' ), item.source ) ) ) {
				return;
			}
			TagwrightSEO.api( 'redirects/' + item.id, { method: 'DELETE' } )
				.then( ( response ) => {
					TagwrightSEO.toast( response.message );
					return rules.load();
				} )
				.catch( failed );
		}
	} );

	rules.table.addEventListener( 'change', ( event ) => {
		if ( ! event.target.matches( '[data-enabled]' ) ) {
			return;
		}
		const input = event.target;
		const row = input.closest( 'tr[data-id]' );
		input.disabled = true;
		TagwrightSEO.api( 'redirects/' + row.dataset.id, { method: 'POST', data: { enabled: input.checked } } )
			.then( () => {
				row.classList.toggle( 'is-disabled', ! input.checked );
				const item = rules.item( parseInt( row.dataset.id, 10 ) );
				if ( item ) {
					item.enabled = input.checked;
				}
				TagwrightSEO.toast( input.checked ? __( 'Redirect switched on.', 'tagwright-seo' ) : __( 'Redirect switched off.', 'tagwright-seo' ) );
			} )
			.catch( ( error ) => {
				input.checked = ! input.checked;
				failed( error );
			} )
			.finally( () => {
				input.disabled = false;
			} );
	} );

	/* ------------------------------------------------------------------
	 * 404 log view
	 * --------------------------------------------------------------- */

	const log = createView( '404', {
		endpoint: 'redirects/log',
		state: { orderby: 'last_seen' },
		/* translators: %s: number of logged addresses. */
		summary: ( total ) => _n( '%s missing address', '%s missing addresses', total, 'tagwright-seo' ),
		confirmDelete: ( count ) => sprintf(
			/* translators: %d: number of log entries. */
			_n( 'Delete %d entry from the 404 log?', 'Delete %d entries from the 404 log?', count, 'tagwright-seo' ),
			count
		),
		bulk: ( action, ids ) => TagwrightSEO.api( 'redirects/log/delete', { method: 'POST', data: { ids } } ),
		empty: ( filtered ) => filtered
			? `<div class="tagwright-empty">${ icon( 'search', 32 ) }<h3>${ esc( __( 'Nothing matches', 'tagwright-seo' ) ) }</h3><p>${ esc( __( 'Try another search.', 'tagwright-seo' ) ) }</p></div>`
			: `<div class="tagwright-empty">${ icon( 'check-circle', 32 ) }<h3>${ esc( __( 'No 404 errors logged', 'tagwright-seo' ) ) }</h3><p>${ esc( config.logEnabled ? __( 'When someone lands on a page that does not exist, the address shows up here.', 'tagwright-seo' ) : __( 'Switch the 404 log on in the settings to start recording missing pages.', 'tagwright-seo' ) ) }</p></div>`,
		head: ( view ) => [
			sortHeader( view, 'url', __( 'Address', 'tagwright-seo' ) ),
			sortHeader( view, 'hits', __( 'Hits', 'tagwright-seo' ), true ),
			sortHeader( view, 'last_seen', __( 'Last seen', 'tagwright-seo' ) ),
			sortHeader( view, 'first_seen', __( 'First seen', 'tagwright-seo' ) ),
			`<th><span class="screen-reader-text">${ esc( __( 'Actions', 'tagwright-seo' ) ) }</span></th>`,
		].join( '' ),
		row: ( item ) => {
			const referrer = item.referrer
				? `<small class="tagwright-muted">${ esc( __( 'From', 'tagwright-seo' ) ) } <a href="${ esc( item.referrer ) }" target="_blank" rel="noopener noreferrer">${ esc( item.referrer ) }</a></small>`
				: '';
			return `<tr data-id="${ item.id }">
				<td class="tagwright-redirects-list__check">${ checkbox( `data-select value="${ item.id }"`, __( 'Select', 'tagwright-seo' ) ) }</td>
				<td class="tagwright-redirects-list__url"><a href="${ esc( item.link ) }" target="_blank" rel="noopener"><code>${ esc( item.url ) }</code></a>${ referrer }</td>
				<td class="is-num"><strong>${ esc( item.hits.toLocaleString() ) }</strong></td>
				<td class="tagwright-muted">${ esc( item.last_seen ) }</td>
				<td class="tagwright-muted">${ esc( item.first_seen ) }</td>
				<td class="tagwright-redirects-list__actions">
					<button type="button" class="tagwright-btn tagwright-btn--secondary tagwright-btn--sm" data-redirect>${ icon( 'redirect', 16 ) }${ esc( __( 'Redirect', 'tagwright-seo' ) ) }</button>
					<button type="button" class="tagwright-btn tagwright-btn--ghost tagwright-btn--icon tagwright-btn--sm" data-delete title="${ esc( __( 'Delete', 'tagwright-seo' ) ) }" aria-label="${ esc( __( 'Delete', 'tagwright-seo' ) ) }">${ icon( 'trash', 16 ) }</button>
				</td>
			</tr>`;
		},
	} );

	log.table.addEventListener( 'click', ( event ) => {
		const row = event.target.closest( 'tr[data-id]' );
		if ( ! row ) {
			return;
		}
		const item = log.item( parseInt( row.dataset.id, 10 ) );

		if ( event.target.closest( '[data-redirect]' ) ) {
			editor.open( null, { source: item.url, logUrl: item.url, hits: item.hits } );
		} else if ( event.target.closest( '[data-delete]' ) ) {
			TagwrightSEO.api( 'redirects/log/delete', { method: 'POST', data: { ids: [ item.id ] } } )
				.then( ( response ) => {
					TagwrightSEO.toast( response.message );
					return log.load();
				} )
				.catch( failed );
		}
	} );

	const clearLog = log.panel.querySelector( '[data-clear-log]' );
	clearLog.addEventListener( 'click', () => {
		if ( ! window.confirm( clearLog.dataset.confirm ) ) {
			return;
		}
		clearLog.classList.add( 'is-busy' );
		TagwrightSEO.api( 'redirects/log/clear', { method: 'POST' } )
			.then( ( response ) => {
				TagwrightSEO.toast( response.message );
				return log.load();
			} )
			.catch( failed )
			.finally( () => clearLog.classList.remove( 'is-busy' ) );
	} );

	/* ------------------------------------------------------------------
	 * Add and edit form
	 * --------------------------------------------------------------- */

	const editor = ( function () {
		const form = root.querySelector( '[data-editor]' );
		const field = ( name ) => form.querySelector( `[name="redirect[${ name }]"]:not([type="hidden"])` ) || form.querySelector( `[name="redirect[${ name }]"]` );
		const title = form.querySelector( '[data-editor-title]' );
		const hint = form.querySelector( '[data-editor-hint]' );
		const removeLogWrap = form.querySelector( '[data-remove-log-wrap]' );
		const removeLog = form.querySelector( '[data-remove-log]' );
		const save = form.querySelector( '[data-save]' );
		let editing = 0;

		function setMatch( value ) {
			form.querySelectorAll( '[name="redirect[match_type]"]' ).forEach( ( radio ) => {
				radio.checked = radio.value === value;
			} );
		}

		function setStatus( value ) {
			const select = field( 'status' );
			select.value = String( value );
			select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}

		function open( item, prefill = {} ) {
			editing = item ? item.id : 0;
			title.textContent = item ? __( 'Edit redirect', 'tagwright-seo' ) : __( 'Add redirect', 'tagwright-seo' );
			save.textContent = item ? __( 'Update redirect', 'tagwright-seo' ) : __( 'Save redirect', 'tagwright-seo' );

			field( 'source' ).value = item ? item.source : ( prefill.source || '' );
			field( 'target' ).value = item ? item.target : '';
			field( 'notes' ).value = item ? item.notes : '';
			field( 'enabled' ).checked = item ? item.enabled : true;
			field( 'log_url' ).value = prefill.logUrl || '';
			setMatch( item ? item.match_type : 'exact' );
			setStatus( item ? item.status : config.defaultStatus || '301' );

			removeLogWrap.hidden = ! prefill.logUrl;
			removeLog.checked = true;
			hint.hidden = ! prefill.logUrl;
			hint.textContent = prefill.logUrl
				? sprintf(
					/* translators: %s: number of hits. */
					_n( 'From the 404 log: hit %s time.', 'From the 404 log: hit %s times.', prefill.hits, 'tagwright-seo' ),
					( prefill.hits || 0 ).toLocaleString()
				)
				: '';

			TagwrightSEO.ui.evaluateConditions( form );
			form.hidden = false;
			form.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			( prefill.logUrl ? field( 'target' ) : field( 'source' ) ).focus( { preventScroll: true } );
		}

		function close() {
			form.hidden = true;
			editing = 0;
		}

		function values() {
			const checked = form.querySelector( '[name="redirect[match_type]"]:checked' );
			const data = {
				source: field( 'source' ).value.trim(),
				target: field( 'target' ).value.trim(),
				status: parseInt( field( 'status' ).value, 10 ),
				match_type: checked ? checked.value : 'exact',
				notes: field( 'notes' ).value.trim(),
				enabled: field( 'enabled' ).checked,
			};
			if ( field( 'log_url' ).value && removeLog.checked ) {
				data.log_url = field( 'log_url' ).value;
			}
			return data;
		}

		form.addEventListener( 'submit', ( event ) => {
			event.preventDefault();
			const data = values();

			if ( ! data.source ) {
				field( 'source' ).focus();
				TagwrightSEO.toast( __( 'Enter the old address to redirect.', 'tagwright-seo' ), 'error' );
				return;
			}

			save.classList.add( 'is-busy' );
			const fromLog = !! data.log_url;
			TagwrightSEO.api( editing ? 'redirects/' + editing : 'redirects', { method: 'POST', data } )
				.then( ( response ) => {
					TagwrightSEO.toast( response.message );
					close();
					rules.load();
					if ( fromLog && log.loaded ) {
						log.load();
					}
				} )
				.catch( failed )
				.finally( () => save.classList.remove( 'is-busy' ) );
		} );

		form.querySelectorAll( '[data-cancel]' ).forEach( ( button ) => button.addEventListener( 'click', close ) );
		form.addEventListener( 'keydown', ( event ) => {
			// An open dropdown handles its own Escape and marks it handled.
			if ( 'Escape' === event.key && ! event.defaultPrevented ) {
				close();
			}
		} );

		return { open, close };
	}() );

	root.addEventListener( 'click', ( event ) => {
		if ( event.target.closest( '[data-add]' ) ) {
			editor.open( null );
		} else if ( event.target.closest( '[data-migrate-open]' ) ) {
			migrate.open();
		}
	} );

	/* ------------------------------------------------------------------
	 * CSV export and import
	 * --------------------------------------------------------------- */

	function download( text, filename ) {
		const blob = new Blob( [ '﻿' + text ], { type: 'text/csv;charset=utf-8' } );
		const link = document.createElement( 'a' );
		link.href = URL.createObjectURL( blob );
		link.download = filename;
		document.body.appendChild( link );
		link.click();
		link.remove();
		setTimeout( () => URL.revokeObjectURL( link.href ), 1000 );
	}

	/**
	 * Small CSV parser: quoted cells, doubled quotes, commas and line breaks
	 * inside quotes, CRLF or LF line endings.
	 *
	 * @param {string} text File contents.
	 * @return {Array<Array<string>>} Rows of cells, empty lines dropped.
	 */
	function parseCsv( text ) {
		const rows = [];
		let row = [];
		let cell = '';
		let quoted = false;

		text = text.replace( /^﻿/, '' );

		for ( let i = 0; i < text.length; i++ ) {
			const char = text[ i ];
			if ( quoted ) {
				if ( '"' === char && '"' === text[ i + 1 ] ) {
					cell += '"';
					i++;
				} else if ( '"' === char ) {
					quoted = false;
				} else {
					cell += char;
				}
			} else if ( '"' === char ) {
				quoted = true;
			} else if ( ',' === char || ';' === char ) {
				row.push( cell );
				cell = '';
			} else if ( '\n' === char || '\r' === char ) {
				if ( '\r' === char && '\n' === text[ i + 1 ] ) {
					i++;
				}
				row.push( cell );
				rows.push( row );
				row = [];
				cell = '';
			} else {
				cell += char;
			}
		}

		if ( '' !== cell || row.length ) {
			row.push( cell );
			rows.push( row );
		}

		return rows.filter( ( cells ) => cells.some( ( value ) => '' !== value.trim() ) );
	}

	const exportButton = rules.panel.querySelector( '[data-export]' );
	exportButton.addEventListener( 'click', () => {
		exportButton.classList.add( 'is-busy' );
		TagwrightSEO.api( 'redirects/export' )
			.then( ( response ) => download( response.csv, 'tagwright-seo-redirects-' + new Date().toISOString().slice( 0, 10 ) + '.csv' ) )
			.catch( failed )
			.finally( () => exportButton.classList.remove( 'is-busy' ) );
	} );

	function showImportResult( totals ) {
		let box = rules.panel.querySelector( '[data-import-result]' );
		if ( ! box ) {
			box = document.createElement( 'div' );
			box.className = 'tagwright-redirects-notice';
			box.dataset.importResult = '';
			rules.panel.insertBefore( box, rules.panel.querySelector( '[data-bulk]' ) );
		}

		// The plugin importers send an exact skip count but only a sample of the messages.
		const skipped = undefined === totals.skipped ? totals.errors.length : totals.skipped;
		const type = skipped ? 'warning' : 'success';
		const summary = sprintf(
			/* translators: 1: redirects added, 2: redirects updated, 3: rows skipped. */
			__( 'Import finished: %1$d added, %2$d updated, %3$d skipped.', 'tagwright-seo' ),
			totals.added,
			totals.updated,
			skipped
		);
		const errors = totals.errors.slice( 0, 10 ).map( ( error ) => `<li>${ esc( error ) }</li>` ).join( '' );
		const more = skipped > 10
			? `<li>${ esc( sprintf( /* translators: %d: number of further errors. */ __( 'and %d more', 'tagwright-seo' ), skipped - Math.min( 10, totals.errors.length ) ) ) }</li>`
			: '';

		box.innerHTML = `<div class="tagwright-notice tagwright-notice--${ type }">
			<span class="tagwright-notice__icon">${ icon( skipped ? 'alert' : 'check-circle', 18 ) }</span>
			<div class="tagwright-notice__body" style="flex:1"><strong>${ esc( summary ) }</strong>${ errors ? `<ul>${ errors }${ more }</ul>` : '' }</div>
			<button type="button" class="tagwright-btn tagwright-btn--ghost tagwright-btn--icon tagwright-btn--sm" data-dismiss aria-label="${ esc( __( 'Dismiss', 'tagwright-seo' ) ) }">${ icon( 'x', 16 ) }</button>
		</div>`;
		box.querySelector( '[data-dismiss]' ).addEventListener( 'click', () => box.remove() );
	}

	const importInput = rules.panel.querySelector( '[data-import]' );
	const importLabel = rules.panel.querySelector( `label[for="${ importInput.id }"]` );
	importInput.addEventListener( 'change', () => {
		const file = importInput.files[ 0 ];
		if ( ! file ) {
			return;
		}

		importLabel.classList.add( 'is-busy' );
		file.text()
			.then( ( text ) => {
				let rows = parseCsv( text );
				let line = 0;

				// A header row is optional; skip it when the first cell says "source".
				if ( rows.length && 'source' === String( rows[ 0 ][ 0 ] ).trim().toLowerCase() ) {
					rows = rows.slice( 1 );
					line = 1;
				}

				if ( ! rows.length ) {
					throw new Error( __( 'That file has no redirects in it. Expected columns: source, target, status, match_type.', 'tagwright-seo' ) );
				}

				const totals = { added: 0, updated: 0, errors: [] };
				const step = ( start ) => TagwrightSEO.api( 'redirects/import', {
					method: 'POST',
					data: { rows: rows.slice( start, start + IMPORT_BATCH ).map( ( cells ) => cells.slice( 0, 4 ).map( String ) ), offset: line + start },
				} ).then( ( result ) => {
					totals.added += result.added;
					totals.updated += result.updated;
					totals.errors = totals.errors.concat( result.errors );
					return start + IMPORT_BATCH < rows.length ? step( start + IMPORT_BATCH ) : totals;
				} );

				return step( 0 );
			} )
			.then( ( totals ) => {
				showImportResult( totals );
				rules.state.page = 1;
				return rules.load();
			} )
			.catch( failed )
			.finally( () => {
				importLabel.classList.remove( 'is-busy' );
				importInput.value = '';
			} );
	} );

	/* ------------------------------------------------------------------
	 * Import from another plugin
	 * --------------------------------------------------------------- */

	const migrate = ( function () {
		const card = root.querySelector( '[data-migrate]' );
		const list = card.querySelector( '[data-migrate-list]' );
		let loaded = false;
		let running = false;

		function render( sources ) {
			if ( ! sources.length ) {
				list.innerHTML = `<div class="tagwright-empty">${ icon( 'check-circle', 32 ) }<h3>${ esc( __( 'Nothing to import', 'tagwright-seo' ) ) }</h3><p>${ esc( __( 'There are no redirects from Rank Math, Yoast SEO Premium, Redirection or All in One SEO Pro on this site.', 'tagwright-seo' ) ) }</p></div>`;
				return;
			}

			list.innerHTML = sources.map( ( source ) => `<div class="tagwright-field tagwright-field--wide" data-source="${ esc( source.id ) }">
				<div class="tagwright-redirects-migrate__row">
					<div>
						<strong>${ esc( source.name ) }</strong>
						<span class="tagwright-badge tagwright-badge--good">${ esc( sprintf(
							/* translators: %s: number of redirects found. */
							_n( '%s redirect', '%s redirects', source.count, 'tagwright-seo' ),
							source.count.toLocaleString()
						) ) }</span>
					</div>
					<button type="button" class="tagwright-btn tagwright-btn--secondary tagwright-btn--sm" data-migrate-run>${ esc( __( 'Import', 'tagwright-seo' ) ) }</button>
				</div>
				<div class="tagwright-progress" hidden><span></span></div>
				<p class="tagwright-muted tagwright-redirects-migrate__status" data-migrate-status hidden></p>
			</div>` ).join( '' );
		}

		function load() {
			TagwrightSEO.api( 'redirects/import-sources' )
				.then( ( response ) => {
					loaded = true;
					render( response.sources || [] );
				} )
				.catch( ( error ) => {
					list.innerHTML = `<div class="tagwright-empty"><p>${ esc( ( error && error.message ) || __( 'Something went wrong. Please try again.', 'tagwright-seo' ) ) }</p></div>`;
				} );
		}

		function run( row ) {
			const name = row.querySelector( 'strong' ).textContent;
			const bar = row.querySelector( '.tagwright-progress span' );
			const status = row.querySelector( '[data-migrate-status]' );
			const buttons = list.querySelectorAll( '[data-migrate-run]' );
			const button = row.querySelector( '[data-migrate-run]' );
			const totals = { added: 0, updated: 0, skipped: 0, errors: [] };

			running = true;
			buttons.forEach( ( item ) => {
				item.disabled = true;
			} );
			button.classList.add( 'is-busy' );
			row.querySelector( '.tagwright-progress' ).hidden = false;
			bar.style.width = '0%';
			status.hidden = false;
			status.textContent = __( 'Importing…', 'tagwright-seo' );

			const step = ( offset ) => TagwrightSEO.api( 'redirects/import-from', {
				method: 'POST',
				data: { source: row.dataset.source, offset },
			} ).then( ( state ) => {
				totals.added += state.imported;
				totals.updated += state.updated;
				totals.skipped += state.skipped;
				totals.errors = totals.errors.concat( state.errors || [] );
				bar.style.width = state.percent + '%';
				status.textContent = sprintf(
					/* translators: %d: percentage. */
					__( 'Importing: %d%% done…', 'tagwright-seo' ),
					state.percent
				);
				return 'running' === state.status ? step( state.offset ) : totals;
			} );

			step( 0 )
				.then( () => {
					const summary = sprintf(
						/* translators: 1: plugin name, 2: redirects added, 3: redirects updated, 4: entries skipped. */
						__( '%1$s: %2$d added, %3$d updated, %4$d skipped.', 'tagwright-seo' ),
						name,
						totals.added,
						totals.updated,
						totals.skipped
					);
					bar.style.width = '100%';
					status.textContent = summary;
					TagwrightSEO.toast( summary );
					showImportResult( totals );
					rules.state.page = 1;
					return rules.load();
				} )
				.catch( ( error ) => {
					status.textContent = __( 'The import stopped. Run it again to pick up where it left off; nothing is duplicated.', 'tagwright-seo' );
					failed( error );
				} )
				.finally( () => {
					running = false;
					button.classList.remove( 'is-busy' );
					buttons.forEach( ( item ) => {
						item.disabled = false;
					} );
				} );
		}

		function open() {
			card.hidden = false;
			card.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			if ( ! loaded ) {
				load();
			}
		}

		function close() {
			// Closing mid-import would hide the progress, not stop it.
			if ( ! running ) {
				card.hidden = true;
			}
		}

		list.addEventListener( 'click', ( event ) => {
			const button = event.target.closest( '[data-migrate-run]' );
			if ( button && ! running ) {
				run( button.closest( '[data-source]' ) );
			}
		} );
		card.querySelector( '[data-migrate-close]' ).addEventListener( 'click', close );

		return { open };
	}() );

	// The Tools screen links here with ?import=1 to open the importer straight away.
	const params = new URL( window.location.href );
	if ( params.searchParams.has( 'import' ) ) {
		params.searchParams.delete( 'import' );
		window.history.replaceState( null, '', params.toString() );
		migrate.open();
	}

	/* ------------------------------------------------------------------
	 * Tabs: load each view the first time it is shown
	 * --------------------------------------------------------------- */

	const views = { rules, 404: log };

	root.addEventListener( 'tagwright:tab', ( event ) => {
		const name = event.detail.tab;
		const view = views[ name ];
		if ( view && ! view.loaded ) {
			view.load();
		}
		const url = new URL( window.location.href );
		if ( '404' === name ) {
			url.searchParams.set( 'view', '404' );
		} else {
			url.searchParams.delete( 'view' );
		}
		window.history.replaceState( null, '', url.toString() );
	} );

	views[ root.dataset.view ].load();
	if ( '404' === root.dataset.view ) {
		// The redirect count badge should still show on the first tab.
		rules.load();
	}
}( window, document ) );
