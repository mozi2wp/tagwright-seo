/**
 * Tagwright SEO admin components.
 *
 * Plain JavaScript, no build step. Everything is exposed on
 * window.TagwrightSEO so add-ons can reuse the same components:
 *
 *   TagwrightSEO.ui.init( element ) - enhance controls inside an element
 *   TagwrightSEO.toast( message, type ) - show a notification
 *   TagwrightSEO.api( path, options ) - call the Tagwright SEO REST API
 */
( function ( window, document ) {
	'use strict';

	const config = window.tagwrightSeoAdmin || {};
	const { __, _n, sprintf } = window.wp && window.wp.i18n ? window.wp.i18n : { __: ( s ) => s, _n: ( s, p, n ) => ( 1 === n ? s : p ), sprintf: ( s ) => s };

	const TagwrightSEO = window.TagwrightSEO || {};
	window.TagwrightSEO = TagwrightSEO;

	/* ------------------------------------------------------------------
	 * Small helpers
	 * --------------------------------------------------------------- */

	const $ = ( selector, root = document ) => root.querySelector( selector );
	const $$ = ( selector, root = document ) => Array.from( root.querySelectorAll( selector ) );

	/**
	 * Builds an icon from the SVG strings passed in by PHP.
	 *
	 * @param {string} name Icon name.
	 * @param {number} size Size in pixels.
	 * @return {string} SVG markup.
	 */
	function icon( name, size = 16 ) {
		const paths = ( config.icons && config.icons[ name ] ) || '';
		return `<svg class="tagwright-icon" width="${ size }" height="${ size }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ paths }</svg>`;
	}

	// Safe for text and for values inside quoted attributes (quotes included).
	const HTML_ENTITIES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

	function escapeHtml( text ) {
		return ( text == null ? '' : String( text ) ).replace( /[&<>"']/g, ( c ) => HTML_ENTITIES[ c ] );
	}

	/**
	 * Scrolls a list so an item is visible, without moving the page.
	 *
	 * @param {HTMLElement} list List with its own scrollbar.
	 * @param {HTMLElement} item Item to reveal.
	 */
	function scrollWithin( list, item ) {
		if ( item.offsetTop < list.scrollTop ) {
			list.scrollTop = item.offsetTop;
		} else if ( item.offsetTop + item.offsetHeight > list.scrollTop + list.clientHeight ) {
			list.scrollTop = item.offsetTop + item.offsetHeight - list.clientHeight;
		}
	}

	TagwrightSEO.icon = icon;
	TagwrightSEO.escapeHtml = escapeHtml;

	/**
	 * Calls the REST API through wp.apiFetch, which handles the nonce.
	 *
	 * @param {string} path    Path below tagwright-seo/v1, e.g. "settings", or a
	 *                         full route starting with "/" for add-on namespaces.
	 * @param {Object} options apiFetch options.
	 * @return {Promise} Response promise.
	 */
	TagwrightSEO.api = function ( path, options = {} ) {
		// "settings" goes to the core namespace; "/tagwright-seo-redirects/v1/rules" is used as is (add-ons).
		const full = path.startsWith( '/' ) ? path : '/tagwright-seo/v1/' + path;
		return window.wp.apiFetch( Object.assign( { path: full }, options ) );
	};

	/* ------------------------------------------------------------------
	 * Toast notifications
	 * --------------------------------------------------------------- */

	TagwrightSEO.toast = function ( message, type = 'success' ) {
		let stack = $( '.tagwright-toasts' );
		if ( ! stack ) {
			stack = document.createElement( 'div' );
			stack.className = 'tagwright-toasts tagwright-scope';
			stack.setAttribute( 'role', 'status' );
			stack.setAttribute( 'aria-live', 'polite' );
			document.body.appendChild( stack );
		}

		const toast = document.createElement( 'div' );
		toast.className = 'tagwright-toast tagwright-toast--' + type;
		toast.innerHTML = icon( 'error' === type ? 'alert' : 'check-circle', 18 ) + '<span>' + escapeHtml( message ) + '</span>';
		stack.appendChild( toast );

		setTimeout( () => {
			toast.classList.add( 'is-leaving' );
			setTimeout( () => toast.remove(), 300 );
		}, 'error' === type ? 6000 : 3200 );
	};

	/* ------------------------------------------------------------------
	 * Custom dropdowns
	 * --------------------------------------------------------------- */

	function enhanceSelect( select ) {
		if ( select.dataset.enhanced ) {
			return;
		}
		select.dataset.enhanced = '1';
		select.classList.add( 'tagwright-select-native' );
		select.tabIndex = -1;
		select.setAttribute( 'aria-hidden', 'true' );

		// Long lists, like a list of users, get a search box.
		const searchable = select.options.length > 10;
		const listId = ( select.id || 'tagwright-select-' + Math.random().toString( 36 ).slice( 2 ) ) + '-list';
		const wrap = document.createElement( 'div' );
		wrap.className = 'tagwright-dropdown';
		wrap.innerHTML =
			`<button type="button" class="tagwright-dropdown__button" aria-haspopup="listbox" aria-expanded="false" aria-controls="${ listId }">` +
			'<span class="tagwright-dropdown__value"></span>' + icon( 'chevron-down', 16 ) + '</button>' +
			'<div class="tagwright-dropdown__menu" hidden>' +
			( searchable ? `<div class="tagwright-dropdown__search"><input type="search" aria-label="${ escapeHtml( __( 'Search options', 'tagwright-seo' ) ) }" placeholder="${ escapeHtml( __( 'Search…', 'tagwright-seo' ) ) }" aria-controls="${ listId }" /></div>` : '' ) +
			`<ul class="tagwright-dropdown__list" id="${ listId }" role="listbox" tabindex="-1"></ul>` +
			( searchable ? '<span class="screen-reader-text" aria-live="polite" data-dropdown-count></span>' : '' ) +
			'</div>';
		select.parentNode.insertBefore( wrap, select );
		wrap.appendChild( select );

		const button = $( '.tagwright-dropdown__button', wrap );
		const valueEl = $( '.tagwright-dropdown__value', wrap );
		const menu = $( '.tagwright-dropdown__menu', wrap );
		const list = $( '.tagwright-dropdown__list', wrap );
		const search = $( '.tagwright-dropdown__search input', wrap );
		const count = $( '[data-dropdown-count]', wrap );
		let focused = -1;
		let typed = '';
		let typedTimer = null;

		// Keep the label: clicking it focuses the button, and the button is announced
		// as "label, current value".
		valueEl.id = listId + '-value';
		if ( select.id ) {
			const label = document.querySelector( `label[for="${ select.id }"]` );
			if ( label ) {
				label.id = label.id || listId + '-label';
				button.setAttribute( 'aria-labelledby', label.id + ' ' + valueEl.id );
				label.addEventListener( 'click', ( event ) => {
					event.preventDefault();
					button.focus();
				} );
			}
		}
		if ( ! button.hasAttribute( 'aria-labelledby' ) && select.getAttribute( 'aria-label' ) ) {
			button.setAttribute( 'aria-label', select.getAttribute( 'aria-label' ) );
		}

		const visibleItems = () => $$( '.tagwright-dropdown__option:not([hidden])', list );

		function build() {
			list.innerHTML = '';
			let lastGroup = null;
			let groupList = null;
			Array.from( select.options ).forEach( ( option, index ) => {
				// Options in an optgroup go under a heading, as a labelled group.
				const group = option.parentElement && 'OPTGROUP' === option.parentElement.tagName ? option.parentElement : null;
				let parent = list;
				if ( group ) {
					if ( group !== lastGroup ) {
						const groupId = listId + '-group-' + index;
						const holder = document.createElement( 'li' );
						holder.className = 'tagwright-dropdown__group';
						holder.setAttribute( 'role', 'presentation' );
						holder.innerHTML = `<span class="tagwright-dropdown__group-label" id="${ groupId }">${ escapeHtml( group.label ) }</span><ul role="group" aria-labelledby="${ groupId }"></ul>`;
						list.appendChild( holder );
						groupList = $( 'ul', holder );
						lastGroup = group;
					}
					parent = groupList;
				}
				const li = document.createElement( 'li' );
				li.className = 'tagwright-dropdown__option';
				li.setAttribute( 'role', 'option' );
				li.id = listId + '-' + index;
				li.dataset.index = index;
				li.setAttribute( 'aria-selected', option.selected ? 'true' : 'false' );
				li.innerHTML = '<span>' + escapeHtml( option.textContent ) + '</span>' + icon( 'check', 14 );
				parent.appendChild( li );
			} );
			sync();
		}

		function sync() {
			const option = select.options[ select.selectedIndex ];
			valueEl.textContent = option ? option.textContent : '';
			$$( '.tagwright-dropdown__option', list ).forEach( ( li ) => {
				li.setAttribute( 'aria-selected', parseInt( li.dataset.index, 10 ) === select.selectedIndex ? 'true' : 'false' );
			} );
		}

		// `position` is an index into the currently visible options.
		function setFocus( position ) {
			const items = visibleItems();
			if ( ! items.length ) {
				focused = -1;
				return;
			}
			focused = Math.max( 0, Math.min( items.length - 1, position ) );
			$$( '.tagwright-dropdown__option', list ).forEach( ( li ) => li.classList.remove( 'is-focused' ) );
			items[ focused ].classList.add( 'is-focused' );
			list.setAttribute( 'aria-activedescendant', items[ focused ].id );
			scrollWithin( list, items[ focused ] );
		}

		function filter( term ) {
			term = term.trim().toLowerCase();
			$$( '.tagwright-dropdown__option', list ).forEach( ( li ) => {
				li.hidden = !! term && ! li.textContent.toLowerCase().includes( term );
			} );
			$$( '.tagwright-dropdown__group', list ).forEach( ( group ) => {
				group.hidden = ! $( '.tagwright-dropdown__option:not([hidden])', group );
			} );
			if ( count ) {
				const found = visibleItems().length;
				count.textContent = term ? sprintf( /* translators: %d: number of matching options. */ _n( '%d result', '%d results', found, 'tagwright-seo' ), found ) : '';
			}
			setFocus( 0 );
		}

		function open() {
			closeAllDropdowns( wrap );
			wrap.classList.add( 'is-open' );
			button.setAttribute( 'aria-expanded', 'true' );
			menu.hidden = false;
			if ( search ) {
				search.value = '';
				filter( '' );
				search.focus();
			} else {
				list.focus();
			}
			setFocus( visibleItems().findIndex( ( li ) => parseInt( li.dataset.index, 10 ) === select.selectedIndex ) );
		}

		function close( returnFocus = true ) {
			wrap.classList.remove( 'is-open' );
			button.setAttribute( 'aria-expanded', 'false' );
			menu.hidden = true;
			if ( returnFocus ) {
				button.focus();
			}
		}

		function choose( optionIndex ) {
			if ( optionIndex > -1 && optionIndex !== select.selectedIndex ) {
				select.selectedIndex = optionIndex;
				select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}
			sync();
			close();
		}

		function chooseFocused() {
			const item = visibleItems()[ focused ];
			if ( item ) {
				choose( parseInt( item.dataset.index, 10 ) );
			}
		}

		function onKeys( event, fromSearch ) {
			switch ( event.key ) {
				case 'ArrowDown':
					event.preventDefault();
					setFocus( focused + 1 );
					break;
				case 'ArrowUp':
					event.preventDefault();
					setFocus( focused - 1 );
					break;
				case 'Home':
					if ( ! fromSearch ) {
						event.preventDefault();
						setFocus( 0 );
					}
					break;
				case 'End':
					if ( ! fromSearch ) {
						event.preventDefault();
						setFocus( visibleItems().length - 1 );
					}
					break;
				case 'Enter':
					event.preventDefault();
					chooseFocused();
					break;
				case ' ':
					if ( ! fromSearch ) {
						event.preventDefault();
						chooseFocused();
					}
					break;
				case 'Escape':
					event.preventDefault();
					close();
					break;
				case 'Tab':
					close( false );
					break;
				default:
					// Type ahead: jump to the first option starting with the typed letters.
					if ( ! fromSearch && 1 === event.key.length ) {
						typed += event.key.toLowerCase();
						clearTimeout( typedTimer );
						typedTimer = setTimeout( () => ( typed = '' ), 600 );
						const match = visibleItems().findIndex( ( li ) => li.textContent.trim().toLowerCase().startsWith( typed ) );
						if ( match > -1 ) {
							setFocus( match );
						}
					}
			}
		}

		button.addEventListener( 'click', () => ( wrap.classList.contains( 'is-open' ) ? close() : open() ) );
		button.addEventListener( 'keydown', ( event ) => {
			if ( [ 'ArrowDown', 'ArrowUp', 'Enter', ' ' ].includes( event.key ) ) {
				event.preventDefault();
				open();
			}
		} );

		list.addEventListener( 'click', ( event ) => {
			const li = event.target.closest( '.tagwright-dropdown__option' );
			if ( li ) {
				choose( parseInt( li.dataset.index, 10 ) );
			}
		} );

		list.addEventListener( 'mousemove', ( event ) => {
			const li = event.target.closest( '.tagwright-dropdown__option' );
			if ( li ) {
				setFocus( visibleItems().indexOf( li ) );
			}
		} );

		list.addEventListener( 'keydown', ( event ) => onKeys( event, false ) );

		if ( search ) {
			search.addEventListener( 'input', () => filter( search.value ) );
			search.addEventListener( 'keydown', ( event ) => onKeys( event, true ) );
		}

		select.addEventListener( 'change', sync );
		build();
	}

	function closeAllDropdowns( except ) {
		$$( '.tagwright-dropdown.is-open' ).forEach( ( dropdown ) => {
			if ( dropdown !== except ) {
				dropdown.classList.remove( 'is-open' );
				$( '.tagwright-dropdown__button', dropdown ).setAttribute( 'aria-expanded', 'false' );
				$( '.tagwright-dropdown__menu', dropdown ).hidden = true;
			}
		} );
	}

	document.addEventListener( 'click', ( event ) => {
		if ( ! event.target.closest( '.tagwright-dropdown' ) ) {
			closeAllDropdowns();
		}
		if ( ! event.target.closest( '.tagwright-popover, .tagwright-var-toggle, .tagwright-chips' ) ) {
			closePopover();
		}
	} );

	/* ------------------------------------------------------------------
	 * Variable chips
	 *
	 * Title and description templates are edited in a small rich field
	 * where variables show up as chips, like Yoast. Type @ or / to open
	 * the variable list, use the arrow keys and press Enter to insert.
	 * The real value (%title% %sep% %sitename%) lives in a hidden input
	 * with the original name, so forms and scripts read it as before.
	 * --------------------------------------------------------------- */

	const VARIABLE_PATTERN = /%([a-z_]+)(?:\(([^)%]*)\))?%/gi;
	const TRIGGERS = [ '@', '/' ];
	let activePopover = null;

	function variableLabel( name, argument ) {
		const labels = config.varLabels || {};
		if ( 'customfield' === name ) {
			/* translators: %s: custom field name. */
			return sprintf( __( 'Field: %s', 'tagwright-seo' ), argument || '…' );
		}
		return labels[ name ] || name.replace( /_/g, ' ' );
	}

	function variableList() {
		return Object.keys( config.variables || {} ).map( ( key ) => {
			const match = key.match( /^([a-z_]+)\((.*)\)$/ );
			// Entries such as customfield(key) need a real argument from the user.
			const name = match ? match[ 1 ] : key;
			return {
				key,
				name,
				argument: '',
				label: match ? ( ( config.varLabels || {} )[ name ] || name ) : variableLabel( name, '' ),
				description: config.variables[ key ],
			};
		} );
	}

	function createChip( name, argument = '' ) {
		const chip = document.createElement( 'span' );
		chip.className = 'tagwright-chip' + ( 'sep' === name ? ' tagwright-chip--sep' : '' );
		chip.contentEditable = 'false';
		chip.dataset.var = name;
		if ( argument ) {
			chip.dataset.arg = argument;
		}
		chip.setAttribute( 'title', '%' + name + ( argument ? '(' + argument + ')' : '' ) + '%' );
		chip.innerHTML = '<span class="tagwright-chip__label"></span><button type="button" class="tagwright-chip__remove" tabindex="-1" aria-label="' + escapeHtml( __( 'Remove variable', 'tagwright-seo' ) ) + '">×</button>';
		chip.firstChild.textContent = variableLabel( name, argument );
		return chip;
	}

	/**
	 * Fills the editor from a template string.
	 *
	 * @param {HTMLElement} editor Editable element.
	 * @param {string}      value  Template.
	 */
	function renderChips( editor, value ) {
		editor.innerHTML = '';
		let last = 0;
		String( value || '' ).replace( VARIABLE_PATTERN, ( match, name, argument, offset ) => {
			if ( offset > last ) {
				editor.appendChild( document.createTextNode( value.slice( last, offset ) ) );
			}
			editor.appendChild( createChip( name.toLowerCase(), argument || '' ) );
			last = offset + match.length;
			return match;
		} );
		if ( last < String( value || '' ).length ) {
			editor.appendChild( document.createTextNode( value.slice( last ) ) );
		}
		ensureEdgeText( editor );
	}

	/**
	 * Reads the template string back out of the editor.
	 *
	 * @param {HTMLElement} editor Editable element.
	 * @return {string} Template.
	 */
	function serializeChips( editor ) {
		let output = '';
		editor.childNodes.forEach( ( node ) => {
			if ( node.nodeType === 3 ) {
				output += node.textContent;
			} else if ( node.classList && node.classList.contains( 'tagwright-chip' ) ) {
				const spacerBefore = output && ! /\s$/.test( output ) ? ' ' : '';
				output += spacerBefore + '%' + node.dataset.var + ( node.dataset.arg ? '(' + node.dataset.arg + ')' : '' ) + '% ';
			} else if ( 'BR' === node.nodeName ) {
				output += ' ';
			} else {
				output += node.textContent;
			}
		} );
		return output.replace( / /g, ' ' ).replace( /[\r\n]+/g, ' ' ).replace( / {2,}/g, ' ' ).trim();
	}

	/**
	 * Browsers struggle to put the caret next to a non-editable chip at the
	 * very start or end, so the editor always keeps text nodes there.
	 *
	 * @param {HTMLElement} editor Editable element.
	 * @return {Text} The trailing text node.
	 */
	function ensureEdgeText( editor ) {
		if ( ! editor.firstChild || editor.firstChild.nodeType !== 3 ) {
			editor.insertBefore( document.createTextNode( '' ), editor.firstChild );
		}
		if ( editor.lastChild.nodeType !== 3 ) {
			editor.appendChild( document.createTextNode( '' ) );
		}
		return editor.lastChild;
	}

	function placeCaretAtEnd( editor ) {
		const last = ensureEdgeText( editor );
		const range = document.createRange();
		range.setStart( last, last.textContent.length );
		range.collapse( true );
		const selection = window.getSelection();
		selection.removeAllRanges();
		selection.addRange( range );
	}

	function placeCaretAtStart( editor ) {
		ensureEdgeText( editor );
		const range = document.createRange();
		range.setStart( editor.firstChild, 0 );
		range.collapse( true );
		const selection = window.getSelection();
		selection.removeAllRanges();
		selection.addRange( range );
	}

	/**
	 * Inserts a chip at the caret, replacing a typed trigger and query if any.
	 *
	 * @param {HTMLElement} editor   Editable element.
	 * @param {Object}      variable Variable from variableList().
	 * @param {Object|null} typed    Range info for "@query" to replace.
	 */
	function insertChip( editor, variable, typed ) {
		editor.focus();
		const selection = window.getSelection();
		let range;

		if ( typed && typed.node && typed.node.parentNode === editor ) {
			range = document.createRange();
			range.setStart( typed.node, typed.start );
			range.setEnd( typed.node, Math.min( typed.node.textContent.length, typed.end ) );
			range.deleteContents();
		} else if ( selection.rangeCount && editor.contains( selection.getRangeAt( 0 ).startContainer ) ) {
			range = selection.getRangeAt( 0 );
			range.deleteContents();
		} else {
			placeCaretAtEnd( editor );
			range = selection.getRangeAt( 0 );
		}

		const chip = createChip( variable.name, variable.argument );
		range.insertNode( chip );
		// A non-breaking space keeps the gap after the chip visible while typing.
		const space = document.createTextNode( '\u00a0' );
		chip.after( space );
		ensureEdgeText( editor );
		const caret = document.createRange();
		caret.setStart( space, 1 );
		caret.collapse( true );
		selection.removeAllRanges();
		selection.addRange( caret );
		syncChips( editor );
	}

	function syncChips( editor ) {
		const input = editor._tagwrightInput;
		const value = serializeChips( editor );
		if ( input.value !== value ) {
			input.value = value;
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
		editor.classList.toggle( 'is-empty', '' === editor.textContent.trim() && ! editor.querySelector( '.tagwright-chip' ) );
	}

	/* The popup with the variable list. */

	function closePopover() {
		if ( activePopover ) {
			if ( activePopover._editor ) {
				activePopover._editor.setAttribute( 'aria-expanded', 'false' );
				activePopover._editor.removeAttribute( 'aria-activedescendant' );
			}
			activePopover.remove();
			activePopover = null;
		}
	}

	/**
	 * Opens the variable list.
	 *
	 * @param {HTMLElement} editor     Editable element.
	 * @param {Object}      options    anchor (element or rect), withSearch, typed.
	 */
	function openPopover( editor, options ) {
		closePopover();

		const popover = document.createElement( 'div' );
		const listId = 'tagwright-vars-' + Math.random().toString( 36 ).slice( 2 );
		popover.className = 'tagwright-popover tagwright-scope';
		popover.innerHTML =
			( options.withSearch ? `<div class="tagwright-popover__search"><input type="search" placeholder="${ escapeHtml( __( 'Search variables…', 'tagwright-seo' ) ) }" aria-label="${ escapeHtml( __( 'Search variables', 'tagwright-seo' ) ) }" aria-controls="${ listId }" /></div>` : '' ) +
			`<ul class="tagwright-popover__list" role="listbox" id="${ listId }" aria-label="${ escapeHtml( __( 'Variables', 'tagwright-seo' ) ) }"></ul>` +
			`<div class="tagwright-popover__foot">${ escapeHtml( __( '↑↓ to move · Enter to insert · Esc to close', 'tagwright-seo' ) ) }</div>`;

		popover._editor = editor;
		popover._typed = options.typed || null;
		popover._index = 0;
		popover._items = [];

		const list = $( '.tagwright-popover__list', popover );

		popover.render = ( term = '' ) => {
			term = term.toLowerCase();
			popover._items = variableList().filter( ( item ) => ! term || item.key.includes( term ) || item.label.toLowerCase().includes( term ) || item.description.toLowerCase().includes( term ) );
			popover._index = 0;
			list.innerHTML = popover._items.length
				? popover._items.map( ( item, i ) => `<li role="option" id="${ listId }-${ i }" data-index="${ i }" aria-selected="${ 0 === i }"><span class="tagwright-chip tagwright-chip--static">${ escapeHtml( item.label ) }</span><small>${ escapeHtml( item.description ) }</small></li>` ).join( '' )
				: `<li class="tagwright-popover__none">${ escapeHtml( __( 'No matching variable', 'tagwright-seo' ) ) }</li>`;
			popover.highlight( 0 );
		};

		popover.highlight = ( index ) => {
			const options = $$( '[role="option"]', list );
			if ( ! options.length ) {
				return;
			}
			popover._index = ( index + options.length ) % options.length;
			options.forEach( ( option, i ) => {
				option.setAttribute( 'aria-selected', i === popover._index ? 'true' : 'false' );
				option.classList.toggle( 'is-active', i === popover._index );
			} );
			scrollWithin( list, options[ popover._index ] );
			editor.setAttribute( 'aria-activedescendant', options[ popover._index ].id );
		};

		popover.choose = ( index = popover._index ) => {
			const item = popover._items[ index ];
			if ( ! item ) {
				return;
			}

			// A custom field needs a field name first; ask for it inside the popup.
			if ( 'customfield' === item.name && ! item.argument ) {
				popover.askFieldName( item );
				return;
			}

			const typed = popover._typed;
			const restore = popover._restore;
			closePopover();
			if ( restore ) {
				editor.focus();
				const selection = window.getSelection();
				selection.removeAllRanges();
				selection.addRange( restore );
			}
			insertChip( editor, item, typed );
		};

		popover.askFieldName = ( item ) => {
			const fieldId = listId + '-field';
			popover.innerHTML =
				`<form class="tagwright-popover__form">` +
				`<label for="${ fieldId }">${ escapeHtml( __( 'Custom field name', 'tagwright-seo' ) ) }</label>` +
				`<input type="text" id="${ fieldId }" class="tagwright-input" placeholder="${ escapeHtml( __( 'e.g. release_year', 'tagwright-seo' ) ) }" autocomplete="off" />` +
				`<p>${ escapeHtml( __( 'The meta key of the custom field whose value should be shown.', 'tagwright-seo' ) ) }</p>` +
				`<div class="tagwright-popover__actions"><button type="button" class="tagwright-btn tagwright-btn--ghost tagwright-btn--sm" data-cancel>${ escapeHtml( __( 'Cancel', 'tagwright-seo' ) ) }</button>` +
				`<button type="submit" class="tagwright-btn tagwright-btn--primary tagwright-btn--sm">${ escapeHtml( __( 'Insert', 'tagwright-seo' ) ) }</button></div>` +
				`</form>`;

			const form = $( 'form', popover );
			const input = $( 'input', form );
			input.focus();

			form.addEventListener( 'submit', ( event ) => {
				event.preventDefault();
				const key = input.value.trim().replace( /[^A-Za-z0-9_\-]/g, '' );
				if ( ! key ) {
					input.focus();
					return;
				}
				popover.choose( popover._items.indexOf( Object.assign( item, { argument: key } ) ) );
			} );

			$( '[data-cancel]', form ).addEventListener( 'click', () => {
				closePopover();
				editor.focus();
			} );

			input.addEventListener( 'keydown', ( event ) => {
				if ( 'Escape' === event.key ) {
					event.preventDefault();
					closePopover();
					editor.focus();
				}
			} );
		};

		list.addEventListener( 'mousedown', ( event ) => {
			const option = event.target.closest( '[role="option"]' );
			if ( option ) {
				event.preventDefault();
				popover.choose( parseInt( option.dataset.index, 10 ) );
			}
		} );

		document.body.appendChild( popover );
		popover.render( options.query || '' );

		// Position below the anchor, kept inside the viewport.
		const rect = options.rect || options.anchor.getBoundingClientRect();
		const width = 340;
		const left = Math.min( window.scrollX + rect.left, window.scrollX + document.documentElement.clientWidth - width - 10 );
		popover.style.left = Math.max( 10, left ) + 'px';
		popover.style.top = window.scrollY + rect.bottom + 6 + 'px';

		editor.setAttribute( 'aria-expanded', 'true' );
		editor.setAttribute( 'aria-controls', listId );
		activePopover = popover;

		if ( options.withSearch ) {
			const search = $( 'input', popover );
			search.focus();
			search.addEventListener( 'input', () => popover.render( search.value ) );
			search.addEventListener( 'keydown', ( event ) => handlePopoverKeys( event ) );
		}
	}

	/**
	 * Keyboard handling shared by the editor and the popup search box.
	 *
	 * @param {KeyboardEvent} event Event.
	 * @return {boolean} Whether the key was handled.
	 */
	function handlePopoverKeys( event ) {
		if ( ! activePopover ) {
			return false;
		}
		switch ( event.key ) {
			case 'ArrowDown':
				event.preventDefault();
				activePopover.highlight( activePopover._index + 1 );
				return true;
			case 'ArrowUp':
				event.preventDefault();
				activePopover.highlight( activePopover._index - 1 );
				return true;
			case 'Enter':
			case 'Tab':
				if ( activePopover._items.length ) {
					event.preventDefault();
					activePopover.choose();
					return true;
				}
				return false;
			case 'Escape':
				event.preventDefault();
				{
					const editor = activePopover._editor;
					closePopover();
					editor.focus();
				}
				return true;
		}
		return false;
	}

	/**
	 * Finds "@query" or "/query" right before the caret.
	 *
	 * @param {HTMLElement} editor Editable element.
	 * @return {Object|null} Text node, start and end offsets and the query.
	 */
	function typedTrigger( editor ) {
		const selection = window.getSelection();
		if ( ! selection.rangeCount || ! selection.isCollapsed ) {
			return null;
		}
		const node = selection.anchorNode;
		if ( ! node || node.nodeType !== 3 || node.parentNode !== editor ) {
			return null;
		}
		const before = node.textContent.slice( 0, selection.anchorOffset );
		const match = before.match( /(^|[\s ])([@/])([a-z_]*)$/i );
		if ( ! match ) {
			return null;
		}
		return {
			node,
			start: before.length - match[ 3 ].length - 1,
			end: selection.anchorOffset,
			query: match[ 3 ],
		};
	}

	function caretRect() {
		const selection = window.getSelection();
		if ( ! selection.rangeCount ) {
			return null;
		}
		const range = selection.getRangeAt( 0 ).cloneRange();
		const rects = range.getClientRects();
		return rects.length ? rects[ rects.length - 1 ] : null;
	}

	/**
	 * Shows a default template in words: "Default: Title | Separator | Site title".
	 *
	 * @param {string} template Placeholder template.
	 * @return {string} Readable placeholder.
	 */
	function friendlyPlaceholder( template ) {
		if ( ! template ) {
			return __( 'Type text, or @ to add a variable', 'tagwright-seo' );
		}
		if ( ! /%[a-z_]+%/i.test( template ) ) {
			return template;
		}
		const words = template.replace( VARIABLE_PATTERN, ( match, name, argument ) => '[' + variableLabel( name.toLowerCase(), argument || '' ) + ']' );
		/* translators: %s: the default template, written out in words. */
		return sprintf( __( 'Default: %s', 'tagwright-seo' ), words.replace( /\s+/g, ' ' ).trim() );
	}

	/**
	 * Turns a plain template input into a chip editor.
	 *
	 * @param {HTMLInputElement|HTMLTextAreaElement} field Original field.
	 */
	function enhanceTemplate( field ) {
		if ( field.dataset.enhanced || 'hidden' === field.type ) {
			return;
		}

		const multiline = 'TEXTAREA' === field.nodeName;
		const hidden = document.createElement( 'input' );
		hidden.type = 'hidden';
		hidden.name = field.name;
		hidden.value = field.value;
		hidden.className = 'tagwright-template__input';
		hidden.dataset.enhanced = '1';

		const editor = document.createElement( 'div' );
		editor.className = 'tagwright-chips' + ( multiline ? ' tagwright-chips--multiline' : '' );
		editor.id = field.id;
		editor.contentEditable = 'true';
		editor.spellcheck = true;
		editor.setAttribute( 'role', 'combobox' );
		editor.setAttribute( 'aria-autocomplete', 'list' );
		editor.setAttribute( 'aria-expanded', 'false' );
		editor.setAttribute( 'aria-haspopup', 'listbox' );
		editor.dataset.placeholder = friendlyPlaceholder( field.placeholder );
		editor._tagwrightInput = hidden;

		const label = field.id ? document.querySelector( `label[for="${ field.id }"]` ) : null;
		if ( label ) {
			editor.setAttribute( 'aria-label', label.textContent.trim() );
			label.addEventListener( 'click', ( event ) => {
				event.preventDefault();
				editor.focus();
				placeCaretAtEnd( editor );
			} );
		}

		field.replaceWith( editor );
		editor.after( hidden );
		renderChips( editor, hidden.value );
		syncChips( editor );

		// Let other scripts (like the editor preview) set the placeholder later.
		Object.defineProperty( hidden, 'placeholder', {
			get: () => editor.dataset.placeholder,
			set: ( value ) => {
				editor.dataset.placeholder = friendlyPlaceholder( value );
			},
		} );

		// External code can set hidden.value and call this to refresh the chips.
		hidden.refreshChips = () => renderChips( editor, hidden.value );

		editor.addEventListener( 'keydown', ( event ) => {
			if ( handlePopoverKeys( event ) ) {
				return;
			}
			if ( 'End' === event.key && ! event.shiftKey ) {
				event.preventDefault();
				placeCaretAtEnd( editor );
				return;
			}
			if ( 'Home' === event.key && ! event.shiftKey ) {
				event.preventDefault();
				placeCaretAtStart( editor );
				return;
			}
			// Titles and descriptions are single paragraphs.
			if ( 'Enter' === event.key ) {
				event.preventDefault();
			}
		} );

		editor.addEventListener( 'input', () => {
			syncChips( editor );

			const typed = typedTrigger( editor );
			if ( typed ) {
				if ( activePopover && activePopover._editor === editor ) {
					activePopover._typed = typed;
					activePopover.render( typed.query );
				} else {
					openPopover( editor, { rect: caretRect() || editor.getBoundingClientRect(), typed, query: typed.query } );
				}
			} else if ( activePopover && activePopover._editor === editor && ! $( '.tagwright-popover__search', activePopover ) ) {
				closePopover();
			}
		} );

		// Paste as plain text so no stray markup ends up in a title.
		editor.addEventListener( 'paste', ( event ) => {
			event.preventDefault();
			const text = ( event.clipboardData || window.clipboardData ).getData( 'text/plain' ).replace( /[\r\n]+/g, ' ' );
			document.execCommand( 'insertText', false, text );
		} );

		// Variables typed by hand (%title%) become chips when leaving the field.
		editor.addEventListener( 'blur', () => {
			setTimeout( () => {
				if ( document.activeElement !== editor && ( ! activePopover || activePopover._editor !== editor ) ) {
					if ( /%[a-z_]+(\([^)%]*\))?%/i.test( Array.from( editor.childNodes ).filter( ( n ) => n.nodeType === 3 ).map( ( n ) => n.textContent ).join( '' ) ) ) {
						renderChips( editor, hidden.value );
					}
				}
			}, 150 );
		} );

		// Clicking the empty space after the last chip should put the caret at the end.
		editor.addEventListener( 'mouseup', ( event ) => {
			if ( event.target !== editor || ! editor.lastChild ) {
				return;
			}
			const chips = editor.querySelectorAll( '.tagwright-chip' );
			const lastChip = chips.length ? chips[ chips.length - 1 ] : null;
			if ( lastChip ) {
				const rect = lastChip.getBoundingClientRect();
				if ( event.clientX > rect.right || event.clientY > rect.bottom ) {
					const trailing = ensureEdgeText( editor );
					const range = document.createRange();
					range.setStart( trailing, trailing.textContent.length );
					// Only move the caret if the trailing text is empty or the click was beyond all text.
					if ( '' === trailing.textContent.trim() || event.clientX > editor.getBoundingClientRect().left + editor.scrollWidth - 20 ) {
						range.collapse( true );
						const selection = window.getSelection();
						selection.removeAllRanges();
						selection.addRange( range );
					}
				}
			}
		} );

		editor.addEventListener( 'click', ( event ) => {
			const remove = event.target.closest( '.tagwright-chip__remove' );
			if ( remove ) {
				event.preventDefault();
				remove.closest( '.tagwright-chip' ).remove();
				syncChips( editor );
				editor.focus();
			}
		} );

		const wrapper = editor.closest( '.tagwright-template' );
		const button = wrapper ? $( '.tagwright-var-toggle', wrapper ) : null;
		if ( button ) {
			button.addEventListener( 'click', ( event ) => {
				event.preventDefault();
				if ( activePopover && activePopover._editor === editor ) {
					closePopover();
					return;
				}
				// Remember where the caret was so the chip lands there.
				const selection = window.getSelection();
				const saved = selection.rangeCount && editor.contains( selection.getRangeAt( 0 ).startContainer ) ? selection.getRangeAt( 0 ).cloneRange() : null;
				openPopover( editor, { anchor: button, withSearch: true } );
				activePopover._typed = null;
				activePopover._restore = saved;
			} );
		}

		field.dataset.enhanced = '1';
	}

	function enhanceTemplates( root ) {
		$$( '.tagwright-template__input', root ).forEach( enhanceTemplate );
	}

	TagwrightSEO.chips = { enhance: enhanceTemplate, render: renderChips, serialize: serializeChips };

	/* ------------------------------------------------------------------
	 * Conditional rows (data-show-if)
	 * --------------------------------------------------------------- */

	function fieldValue( root, name ) {
		const inputs = $$( `[name="${ name }"], [name="${ name }[]"]`, root );
		if ( ! inputs.length ) {
			return undefined;
		}
		const first = inputs[ inputs.length - 1 ];
		if ( 'checkbox' === first.type && ! first.name.endsWith( '[]' ) ) {
			return first.checked;
		}
		if ( 'radio' === first.type ) {
			const checked = inputs.find( ( input ) => input.checked );
			return checked ? checked.value : '';
		}
		if ( first.name.endsWith( '[]' ) ) {
			return inputs.filter( ( input ) => input.checked ).map( ( input ) => input.value );
		}
		return first.value;
	}

	function evaluateConditions( root ) {
		$$( '[data-show-if]', root ).forEach( ( row ) => {
			let rule;
			try {
				rule = JSON.parse( row.dataset.showIf );
			} catch ( e ) {
				return;
			}
			const prefix = rule.prefix || 'tagwright';
			const value = fieldValue( root, `${ prefix }[${ rule.field }]` );
			let visible = true;

			if ( 'checked' in rule ) {
				visible = !! value === !! rule.checked;
			} else if ( Array.isArray( rule.value ) ) {
				visible = rule.value.map( String ).includes( String( value ) );
			} else if ( 'not' in rule ) {
				visible = String( value ) !== String( rule.not );
			} else {
				visible = String( value ) === String( rule.value );
			}

			row.classList.toggle( 'is-hidden', ! visible );
		} );
	}

	/* ------------------------------------------------------------------
	 * Media picker
	 * --------------------------------------------------------------- */

	function bindMedia( root ) {
		$$( '.tagwright-media', root ).forEach( ( box ) => {
			if ( box.dataset.bound ) {
				return;
			}
			box.dataset.bound = '1';
			const input = $( '.tagwright-media__input', box );
			const preview = $( '.tagwright-media__preview', box );
			let frame = null;

			$( '.tagwright-media__choose', box ).addEventListener( 'click', () => {
				if ( ! window.wp || ! window.wp.media ) {
					return;
				}
				if ( ! frame ) {
					frame = window.wp.media( {
						title: box.dataset.title,
						button: { text: box.dataset.button },
						library: { type: 'image' },
						multiple: false,
					} );
					frame.on( 'select', () => {
						const attachment = frame.state().get( 'selection' ).first().toJSON();
						const url = ( attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url );
						input.value = attachment.id;
						let img = $( 'img', preview );
						if ( ! img ) {
							img = document.createElement( 'img' );
							img.alt = '';
							preview.prepend( img );
						}
						img.src = url;
						box.classList.add( 'has-image' );
						input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
					} );
				}
				frame.open();
			} );

			$( '.tagwright-media__remove', box ).addEventListener( 'click', () => {
				input.value = '';
				const img = $( 'img', preview );
				if ( img ) {
					img.remove();
				}
				box.classList.remove( 'has-image' );
				input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			} );
		} );
	}

	/* ------------------------------------------------------------------
	 * Tabs (horizontal, used in the meta box and on cards)
	 * --------------------------------------------------------------- */

	function bindTabs( root ) {
		$$( '[role="tablist"]', root ).forEach( ( list ) => {
			if ( list.dataset.bound ) {
				return;
			}
			list.dataset.bound = '1';
			const tabs = $$( '[role="tab"]', list );

			const activate = ( tab, focus = false ) => {
				tabs.forEach( ( other ) => {
					const selected = other === tab;
					other.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
					other.tabIndex = selected ? 0 : -1;
					const panel = document.getElementById( other.getAttribute( 'aria-controls' ) );
					if ( panel ) {
						panel.hidden = ! selected;
					}
				} );
				if ( focus ) {
					tab.focus();
				}
				list.dispatchEvent( new CustomEvent( 'tagwright:tab', { detail: { tab: tab.dataset.tab }, bubbles: true } ) );
			};

			tabs.forEach( ( tab, index ) => {
				tab.addEventListener( 'click', () => activate( tab ) );
				tab.addEventListener( 'keydown', ( event ) => {
					let next = null;
					if ( 'ArrowRight' === event.key ) {
						next = tabs[ ( index + 1 ) % tabs.length ];
					} else if ( 'ArrowLeft' === event.key ) {
						next = tabs[ ( index - 1 + tabs.length ) % tabs.length ];
					} else if ( 'Home' === event.key ) {
						next = tabs[ 0 ];
					} else if ( 'End' === event.key ) {
						next = tabs[ tabs.length - 1 ];
					}
					if ( next ) {
						event.preventDefault();
						activate( next, true );
						// Tabs without a panel of their own (screens drawn by a script) switch views on click.
						if ( ! next.getAttribute( 'aria-controls' ) ) {
							next.click();
						}
					}
				} );
			} );
		} );
	}

	/* ------------------------------------------------------------------
	 * Rows that don't fit: tab rows scroll without a scrollbar, with
	 * arrows and a fade on the side that has more.
	 * --------------------------------------------------------------- */

	const reducedMotion = () => window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	/**
	 * Scrolls a row just enough to show an item, without moving the page.
	 *
	 * @param {HTMLElement} row  Scrolling row.
	 * @param {HTMLElement} item Item inside it.
	 */
	function revealInRow( row, item ) {
		const pad = 40;
		const rowBox = row.getBoundingClientRect();
		const itemBox = item.getBoundingClientRect();
		const left = itemBox.left - rowBox.left + row.scrollLeft;
		const right = left + itemBox.width;
		let target = null;
		if ( left - pad < row.scrollLeft ) {
			target = Math.max( 0, left - pad );
		} else if ( right + pad > row.scrollLeft + row.clientWidth ) {
			target = right + pad - row.clientWidth;
		}
		if ( null !== target ) {
			row.scrollTo( { left: target, behavior: reducedMotion() ? 'auto' : 'smooth' } );
		}
	}

	function enhanceScroller( row ) {
		if ( row.dataset.scroller || ! row.parentNode ) {
			return;
		}
		row.dataset.scroller = '1';

		const wrap = document.createElement( 'div' );
		wrap.className = 'tagwright-scroller';
		// The wrapper takes over the row's outer spacing.
		const style = window.getComputedStyle( row );
		wrap.style.margin = style.margin;
		row.style.margin = '0';
		row.parentNode.insertBefore( wrap, row );
		wrap.appendChild( row );

		// Arrows are for pointers; keyboard and screen reader users move with the arrow keys.
		const arrow = ( side ) => {
			const button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'tagwright-scroller__arrow is-' + side;
			button.tabIndex = -1;
			button.setAttribute( 'aria-hidden', 'true' );
			button.innerHTML = icon( 'chevron-right', 16 );
			button.addEventListener( 'click', () => {
				const step = Math.max( 120, row.clientWidth * 0.7 ) * ( 'prev' === side ? -1 : 1 );
				row.scrollBy( { left: step, behavior: reducedMotion() ? 'auto' : 'smooth' } );
			} );
			wrap.appendChild( button );
		};
		arrow( 'prev' );
		arrow( 'next' );

		const update = () => {
			const max = row.scrollWidth - row.clientWidth;
			wrap.classList.toggle( 'has-prev', row.scrollLeft > 1 );
			wrap.classList.toggle( 'has-next', row.scrollLeft < max - 1 );
		};

		row.addEventListener( 'scroll', update, { passive: true } );
		row.addEventListener( 'focusin', ( event ) => revealInRow( row, event.target.closest( '[role="tab"], a, button' ) || event.target ) );
		row.addEventListener( 'click', ( event ) => {
			const item = event.target.closest( '[role="tab"], a, button' );
			if ( item ) {
				revealInRow( row, item );
			}
		} );
		if ( window.ResizeObserver ) {
			new ResizeObserver( update ).observe( row );
		}

		const selected = $( '[aria-selected="true"], [aria-current="page"], .is-active', row );
		if ( selected ) {
			const offset = selected.getBoundingClientRect().left - row.getBoundingClientRect().left;
			if ( offset + selected.offsetWidth > row.clientWidth ) {
				row.scrollLeft = Math.max( 0, offset - 40 );
			}
		}
		update();
	}

	/* ------------------------------------------------------------------
	 * Header menu: what doesn't fit goes under "More".
	 * --------------------------------------------------------------- */

	function initTopnav() {
		const nav = $( '.tagwright-topnav' );
		const more = nav ? $( '.tagwright-topnav__more', nav ) : null;
		if ( ! more ) {
			return;
		}

		const button = $( '.tagwright-topnav__more-button', more );
		const menu = $( '.tagwright-topnav__menu', more );
		const countEl = $( '.tagwright-topnav__count', more );
		const links = $$( ':scope > a', nav );
		nav.classList.add( 'is-enhanced' );

		const menuLinks = () => $$( 'a', menu );

		const close = ( returnFocus ) => {
			if ( menu.hidden ) {
				return;
			}
			menu.hidden = true;
			button.setAttribute( 'aria-expanded', 'false' );
			more.classList.remove( 'is-open' );
			if ( returnFocus ) {
				button.focus();
			}
		};

		const open = ( focusFirst ) => {
			menu.hidden = false;
			button.setAttribute( 'aria-expanded', 'true' );
			more.classList.add( 'is-open' );
			if ( focusFirst && menuLinks().length ) {
				menuLinks()[ 0 ].focus();
			}
		};

		const layout = () => {
			close( false );

			// Everything back in the row, in the original order.
			links.forEach( ( link ) => nav.insertBefore( link, more ) );
			menu.innerHTML = '';
			more.hidden = true;
			if ( nav.scrollWidth <= nav.clientWidth + 1 ) {
				return;
			}

			// Move links from the end into the menu until the row fits.
			more.hidden = false;
			const moved = [];
			for ( let i = links.length - 1; i > 0 && nav.scrollWidth > nav.clientWidth + 1; i-- ) {
				moved.unshift( links[ i ] );
				links[ i ].remove();
			}
			moved.forEach( ( link ) => {
				const item = document.createElement( 'li' );
				item.appendChild( link );
				menu.appendChild( item );
			} );

			const current = moved.some( ( link ) => link.classList.contains( 'is-active' ) );
			button.classList.toggle( 'is-active', current );
			countEl.textContent = String( moved.length );
			button.setAttribute(
				'aria-label',
				current
					? sprintf( /* translators: %d: number of pages. */ _n( 'More: %d page, including the current page', 'More: %d pages, including the current page', moved.length, 'tagwright-seo' ), moved.length )
					: sprintf( /* translators: %d: number of pages. */ _n( 'More: %d page', 'More: %d pages', moved.length, 'tagwright-seo' ), moved.length )
			);
		};

		button.addEventListener( 'click', () => ( menu.hidden ? open( false ) : close( false ) ) );
		button.addEventListener( 'keydown', ( event ) => {
			if ( 'ArrowDown' === event.key ) {
				event.preventDefault();
				open( true );
			} else if ( 'Escape' === event.key ) {
				close( true );
			}
		} );

		menu.addEventListener( 'keydown', ( event ) => {
			const items = menuLinks();
			const index = items.indexOf( document.activeElement );
			let next = null;
			if ( 'ArrowDown' === event.key ) {
				next = items[ ( index + 1 ) % items.length ];
			} else if ( 'ArrowUp' === event.key ) {
				next = items[ ( index - 1 + items.length ) % items.length ];
			} else if ( 'Home' === event.key ) {
				next = items[ 0 ];
			} else if ( 'End' === event.key ) {
				next = items[ items.length - 1 ];
			} else if ( 'Escape' === event.key ) {
				event.preventDefault();
				close( true );
				return;
			}
			if ( next ) {
				event.preventDefault();
				next.focus();
			}
		} );

		// Close when focus or a click goes elsewhere.
		more.addEventListener( 'focusout', ( event ) => {
			if ( ! more.contains( event.relatedTarget ) ) {
				close( false );
			}
		} );
		document.addEventListener( 'click', ( event ) => {
			if ( ! more.contains( event.target ) ) {
				close( false );
			}
		} );

		let frame = 0;
		const schedule = () => {
			cancelAnimationFrame( frame );
			frame = requestAnimationFrame( layout );
		};
		if ( window.ResizeObserver ) {
			new ResizeObserver( schedule ).observe( nav.parentNode );
		} else {
			window.addEventListener( 'resize', schedule );
		}
		if ( document.fonts && document.fonts.ready ) {
			document.fonts.ready.then( schedule );
		}
		layout();
	}

	/* ------------------------------------------------------------------
	 * Public init
	 * --------------------------------------------------------------- */

	TagwrightSEO.ui = {
		init( root = document ) {
			$$( 'select.tagwright-select', root ).filter( ( select ) => ! select.closest( 'template' ) ).forEach( enhanceSelect );
			enhanceTemplates( root );
			bindMedia( root );
			bindRepeaters( root );
			bindTabs( root );
			$$( '.tagwright-tabs', root ).forEach( enhanceScroller );
			evaluateConditions( root );

			if ( ! root.dataset || ! root.dataset.tagwrightConditions ) {
				if ( root.dataset ) {
					root.dataset.tagwrightConditions = '1';
				}
				root.addEventListener( 'change', () => evaluateConditions( root ) );
			}
		},
		enhanceSelect,
		enhanceScroller,
		evaluateConditions,
	};

	/* ------------------------------------------------------------------
	 * Settings screen: side navigation and saving
	 * --------------------------------------------------------------- */

	/**
	 * Turns the form into a plain object. Handles tagwright[key], tagwright[key][]
	 * and nested repeater names such as tagwright[key][0][url].
	 *
	 * @param {HTMLFormElement} form Form.
	 * @param {string}          root Name prefix, `tagwright` by default.
	 * @return {Object} Values.
	 */
	function serializeSettings( form, root = 'tagwright' ) {
		const data = {};
		const formData = new FormData( form );
		const pattern = new RegExp( '^' + root + '((?:\\[[^\\]]*\\])+)$' );

		for ( const [ rawName, value ] of formData.entries() ) {
			const match = rawName.match( pattern );
			if ( ! match ) {
				continue;
			}

			const keys = match[ 1 ].slice( 1, -1 ).split( '][' );
			let target = data;

			keys.forEach( ( key, index ) => {
				const last = index === keys.length - 1;
				const nextIsList = ! last && '' === keys[ index + 1 ];

				if ( '' === key ) {
					return;
				}

				if ( last ) {
					// Toggles send a hidden 0 first and then 1 when checked; the last one wins.
					// Repeaters send an empty marker that must not wipe real rows.
					if ( '' === value && target[ key ] && 'object' === typeof target[ key ] ) {
						return;
					}
					target[ key ] = value;
					return;
				}

				if ( nextIsList ) {
					target[ key ] = Array.isArray( target[ key ] ) ? target[ key ] : [];
					if ( '' !== value ) {
						target[ key ].push( value );
					}
					return;
				}

				if ( ! target[ key ] || 'object' !== typeof target[ key ] ) {
					target[ key ] = {};
				}
				target = target[ key ];
			} );
		}

		// Repeater rows arrive as objects keyed by index; send them as lists.
		Object.keys( data ).forEach( ( key ) => {
			const value = data[ key ];
			if ( ! form.querySelector( `.tagwright-repeater[data-name="${ root }[${ key }]"]` ) ) {
				return;
			}
			data[ key ] = value && 'object' === typeof value ? Object.keys( value ).map( ( index ) => value[ index ] ) : [];
		} );

		return data;
	}

	TagwrightSEO.serializeSettings = serializeSettings;


	/* ------------------------------------------------------------------
	 * Template replacement and settings previews
	 * --------------------------------------------------------------- */

	function escapeRegExp( text ) {
		return String( text ).replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	}

	/**
	 * Replaces %variables% with values, then tidies spaces and stray
	 * separators the same way the PHP side does.
	 *
	 * @param {string} template Template.
	 * @param {Object} values   Variable values.
	 * @return {string} Result.
	 */
	/**
	 * Custom field values for previews, fetched once per object and key.
	 * When a value arrives, a `tagwright-seo:custom-field` event fires so
	 * previews can redraw.
	 */
	const customFieldCache = {};

	TagwrightSEO.customField = function ( type, id, key ) {
		if ( ! type || ! id || ! key ) {
			return undefined;
		}
		const cacheKey = type + ':' + id + ':' + key;
		if ( Object.prototype.hasOwnProperty.call( customFieldCache, cacheKey ) ) {
			return customFieldCache[ cacheKey ];
		}
		customFieldCache[ cacheKey ] = undefined;
		TagwrightSEO.api( 'custom-field?' + new URLSearchParams( { type, id, key } ).toString() )
			.then( ( response ) => {
				customFieldCache[ cacheKey ] = response.value || '';
			} )
			.catch( () => {
				customFieldCache[ cacheKey ] = '';
			} )
			.finally( () => document.dispatchEvent( new CustomEvent( 'tagwright-seo:custom-field', { detail: { type, id, key } } ) ) );
		return undefined;
	};

	/**
	 * Text a preview shows for a custom field: its value, or the field name
	 * in brackets while loading or when the example has no value.
	 *
	 * @param {Object} context  { type, id } of the object being previewed.
	 * @param {string} argument Field name.
	 * @return {string} Text.
	 */
	TagwrightSEO.clearCustomFields = function () {
		Object.keys( customFieldCache ).forEach( ( key ) => delete customFieldCache[ key ] );
	};

	TagwrightSEO.previewCustomField = function ( context, argument ) {
		if ( ! argument ) {
			return '';
		}
		const value = context ? TagwrightSEO.customField( context.type, context.id, argument ) : undefined;
		return value ? value : '[' + argument + ']';
	};

	TagwrightSEO.replaceVariables = function ( template, values, context ) {
		let output = String( template || '' ).replace( /%([a-z_]+)(?:\(([^)%]*)\))?%/gi, ( match, name, argument ) => {
			name = name.toLowerCase();
			if ( 'customfield' === name ) {
				return TagwrightSEO.previewCustomField( context, argument );
			}
			return Object.prototype.hasOwnProperty.call( values, name ) ? values[ name ] : match;
		} );

		const sep = escapeRegExp( values.sep || '-' );
		output = output.replace( /\s+/g, ' ' ).trim();
		output = output.replace( new RegExp( '(' + sep + ')(\\s*' + sep + ')+', 'g' ), '$1' );
		output = output.replace( new RegExp( '^\\s*' + sep + '\\s*|\\s*' + sep + '\\s*$', 'g' ), '' );

		return output.trim();
	};

	function settingValue( name ) {
		const fields = $$( `[name="tagwright[${ name }]"]` );
		if ( ! fields.length ) {
			return '';
		}
		const radio = fields.find( ( field ) => 'radio' === field.type && field.checked );
		if ( radio ) {
			return radio.value;
		}
		return fields[ fields.length - 1 ].value || '';
	}

	function currentSeparator( fallback ) {
		const key = settingValue( 'separator' );
		const separators = config.separators || {};
		return key && separators[ key ] ? separators[ key ] : fallback;
	}

	function breadcrumbUrl( url ) {
		try {
			const parsed = new URL( url );
			const segments = parsed.pathname.split( '/' ).filter( Boolean ).map( decodeURIComponent );
			return parsed.origin + ( segments.length ? ' › ' + segments.join( ' › ' ) : '' );
		} catch ( e ) {
			return url;
		}
	}

	function updateSettingsPreview( block ) {
		const cfg = block._tagwrightConfig;
		const values = Object.assign( {}, cfg.values, { sep: currentSeparator( cfg.values.sep ) } );
		const pick = ( keys ) => {
			for ( const key of keys ) {
				const raw = settingValue( key ).trim();
				if ( raw ) {
					return TagwrightSEO.replaceVariables( raw, values, { type: cfg.objectType, id: cfg.objectId } );
				}
			}
			return '';
		};

		const title = pick( cfg.title ) || values.title || cfg.siteName;
		const description = pick( cfg.description );

		if ( 'social' === cfg.type ) {
			let image = '';
			for ( const key of cfg.image ) {
				const img = document.querySelector( `[data-field="${ key }"] .tagwright-media__preview img` );
				if ( img && img.src ) {
					image = img.src;
					break;
				}
			}
			image = image || cfg.imageUrl;
			let domain = '';
			try {
				domain = new URL( cfg.url ).host;
			} catch ( e ) {}
			$( '.tagwright-social-card__domain', block ).textContent = domain;
			$( '.tagwright-social-card__title', block ).textContent = title;
			$( '.tagwright-social-card__desc', block ).textContent = description;
			const box = $( '.tagwright-social-card__image', block );
			box.style.backgroundImage = image ? 'url("' + image.replace( /"/g, '%22' ) + '")' : '';
			box.classList.toggle( 'has-image', !! image );
			return;
		}

		const favicon = $( '.tagwright-serp__favicon', block );
		if ( cfg.favicon && ! favicon.firstChild ) {
			favicon.innerHTML = '<img alt="" src="' + escapeHtml( cfg.favicon ) + '" />';
		}
		$( '.tagwright-serp__name', block ).textContent = cfg.siteName;
		$( '.tagwright-serp__url', block ).textContent = breadcrumbUrl( cfg.url );
		$( '.tagwright-serp__title', block ).textContent = title;
		$( '.tagwright-serp__description', block ).innerHTML = description
			? escapeHtml( description )
			: '<i>' + escapeHtml( __( 'No description. Search engines will pick some text from the page.', 'tagwright-seo' ) ) + '</i>';
	}

	function initSettingsPreviews( root ) {
		const blocks = $$( '[data-settings-preview]', root );
		if ( ! blocks.length ) {
			return;
		}

		blocks.forEach( ( block ) => {
			try {
				block._tagwrightConfig = JSON.parse( block.dataset.settingsPreview );
			} catch ( e ) {
				return;
			}
			$$( '[data-device]', block ).forEach( ( button ) => {
				button.addEventListener( 'click', () => {
					$$( '[data-device]', block ).forEach( ( other ) => {
						other.classList.toggle( 'is-active', other === button );
						other.setAttribute( 'aria-pressed', other === button ? 'true' : 'false' );
					} );
					$( '.tagwright-serp', block ).classList.toggle( 'is-mobile', 'mobile' === button.dataset.device );
				} );
			} );
			updateSettingsPreview( block );
		} );

		const refresh = () => blocks.forEach( ( block ) => block._tagwrightConfig && updateSettingsPreview( block ) );
		root.addEventListener( 'input', refresh );
		root.addEventListener( 'change', refresh );
		document.addEventListener( 'tagwright-seo:custom-field', refresh );
	}

	/* ------------------------------------------------------------------
	 * Repeaters
	 * --------------------------------------------------------------- */

	function bindRepeaters( root ) {
		$$( '.tagwright-repeater', root ).forEach( ( repeater ) => {
			if ( repeater.dataset.bound ) {
				return;
			}
			repeater.dataset.bound = '1';

			const rows = $( '.tagwright-repeater__rows', repeater );
			const template = $( '.tagwright-repeater__template', repeater );
			const emptyNote = $( '.tagwright-repeater__empty', repeater );
			let counter = rows.children.length;

			const refresh = () => {
				emptyNote.hidden = rows.children.length > 0;
				repeater.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			};

			$( '.tagwright-repeater__add', repeater ).addEventListener( 'click', () => {
				// Use a counter that only goes up so removed rows never collide.
				const html = template.innerHTML.replace( /__INDEX__/g, 'n' + counter++ );
				const holder = document.createElement( 'div' );
				holder.innerHTML = html.trim();
				const row = holder.firstElementChild;
				rows.appendChild( row );
				TagwrightSEO.ui.init( row );
				const first = $( 'input:not([type="hidden"])', row );
				if ( first ) {
					first.focus();
				}
				refresh();
			} );

			repeater.addEventListener( 'click', ( event ) => {
				const remove = event.target.closest( '.tagwright-repeater__remove' );
				if ( remove ) {
					remove.closest( '.tagwright-repeater__row' ).remove();
					refresh();
				}
			} );
		} );
	}

	function initSettings() {
		const form = $( 'form[data-tagwright-settings]' );
		if ( ! form ) {
			return;
		}

		initSettingsPreviews( form );

		const nav = $( '.tagwright-sidenav' );
		const picker = $( '[data-section-picker]' );
		const savebar = $( '.tagwright-savebar', form );
		const saveButton = $( '.tagwright-save', form );
		const statusText = $( '.tagwright-savebar__text', form );
		let snapshot = JSON.stringify( serializeSettings( form ) );

		const showPanel = ( id, updateHash = true ) => {
			const panel = document.getElementById( 'tagwright-panel-' + id );
			if ( ! panel ) {
				return;
			}
			$$( '.tagwright-panel', form ).forEach( ( other ) => other.classList.toggle( 'is-active', other === panel ) );
			$$( 'a', nav ).forEach( ( link ) => {
				const active = link.dataset.panel === id;
				link.classList.toggle( 'is-active', active );
				if ( active ) {
					link.setAttribute( 'aria-current', 'page' );
				} else {
					link.removeAttribute( 'aria-current' );
				}
			} );
			if ( picker && picker.value !== id ) {
				picker.value = id;
				picker.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}
			if ( updateHash ) {
				window.history.replaceState( null, '', '#' + id );
			}
		};

		// Narrow screens choose the section from a dropdown instead of a long row.
		if ( picker ) {
			picker.closest( '.tagwright-layout' ).classList.add( 'has-picker' );
			picker.addEventListener( 'change', () => {
				const panel = document.getElementById( 'tagwright-panel-' + picker.value );
				if ( panel && ! panel.classList.contains( 'is-active' ) ) {
					showPanel( picker.value );
					window.scrollTo( { top: 0, behavior: reducedMotion() ? 'auto' : 'smooth' } );
				}
			} );
		}

		$$( 'a[data-panel]', nav ).forEach( ( link ) => {
			link.addEventListener( 'click', ( event ) => {
				event.preventDefault();
				showPanel( link.dataset.panel );
				window.scrollTo( { top: 0, behavior: 'smooth' } );
			} );
		} );

		document.addEventListener( 'click', ( event ) => {
			const link = event.target.closest( '[data-panel-link]' );
			if ( link ) {
				event.preventDefault();
				showPanel( link.dataset.panelLink );
				window.scrollTo( { top: 0, behavior: 'smooth' } );
			}
		} );

		const initial = window.location.hash.replace( '#', '' );
		showPanel( initial && document.getElementById( 'tagwright-panel-' + initial ) ? initial : $( 'a[data-panel]', nav ).dataset.panel, false );

		// Links such as "Settings#redirects" from the admin bar land on the
		// same page, so follow hash changes too.
		window.addEventListener( 'hashchange', () => {
			const id = window.location.hash.replace( '#', '' );
			if ( id && document.getElementById( 'tagwright-panel-' + id ) ) {
				showPanel( id, false );
			}
		} );

		const markDirty = () => {
			const dirty = JSON.stringify( serializeSettings( form ) ) !== snapshot;
			savebar.classList.toggle( 'is-dirty', dirty );
			statusText.textContent = dirty ? __( 'You have unsaved changes', 'tagwright-seo' ) : __( 'All changes saved', 'tagwright-seo' );
		};

		form.addEventListener( 'input', markDirty );
		form.addEventListener( 'change', markDirty );

		window.addEventListener( 'beforeunload', ( event ) => {
			if ( savebar.classList.contains( 'is-dirty' ) ) {
				event.preventDefault();
				event.returnValue = '';
			}
		} );

		form.addEventListener( 'submit', ( event ) => {
			event.preventDefault();
			saveButton.classList.add( 'is-busy' );

			TagwrightSEO.api( 'settings', { method: 'POST', data: { settings: serializeSettings( form ) } } )
				.then( ( response ) => {
					snapshot = JSON.stringify( serializeSettings( form ) );
					markDirty();
					TagwrightSEO.toast( response.message || __( 'Settings saved.', 'tagwright-seo' ) );
				} )
				.catch( ( error ) => {
					TagwrightSEO.toast( ( error && error.message ) || __( 'Saving failed. Please try again.', 'tagwright-seo' ), 'error' );
				} )
				.finally( () => saveButton.classList.remove( 'is-busy' ) );
		} );

		// Ctrl/Cmd + S saves, because muscle memory.
		document.addEventListener( 'keydown', ( event ) => {
			if ( ( event.ctrlKey || event.metaKey ) && 's' === event.key.toLowerCase() ) {
				event.preventDefault();
				form.requestSubmit();
			}
		} );
	}

	/* ------------------------------------------------------------------
	 * Module switches (dashboard and settings)
	 * --------------------------------------------------------------- */

	function initModules() {
		document.addEventListener( 'change', ( event ) => {
			const input = event.target.closest( 'input[data-tagwright-module]' );
			if ( ! input ) {
				return;
			}
			const card = input.closest( '.tagwright-module' );
			input.disabled = true;

			TagwrightSEO.api( 'modules', { method: 'POST', data: { module: input.dataset.tagwrightModule, active: input.checked } } )
				.then( ( response ) => {
					if ( card ) {
						card.classList.toggle( 'is-active', input.checked );
					}
					TagwrightSEO.toast( response.message );
				} )
				.catch( ( error ) => {
					input.checked = ! input.checked;
					TagwrightSEO.toast( ( error && error.message ) || __( 'Could not update the module.', 'tagwright-seo' ), 'error' );
				} )
				.finally( () => {
					input.disabled = false;
				} );
		} );
	}

	/* ------------------------------------------------------------------
	 * Boot
	 * --------------------------------------------------------------- */

	function boot() {
		initTopnav();
		$$( '.tagwright-app, .tagwright-metabox, .tagwright-scope' ).forEach( ( root ) => TagwrightSEO.ui.init( root ) );
		initSettings();
		initModules();
		document.dispatchEvent( new CustomEvent( 'tagwright-seo:ready', { detail: TagwrightSEO } ) );
	}

	TagwrightSEO.sprintf = sprintf;

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}( window, document ) );
