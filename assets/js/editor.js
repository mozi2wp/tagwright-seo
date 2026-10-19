/**
 * Tagwright SEO editor box.
 *
 * Connects the meta box to the block editor, the classic editor or the term
 * screen: live search and social previews, length meters and the content
 * analysis. Works without React so it behaves the same everywhere.
 */
( function ( window, document ) {
	'use strict';

	const data = window.tagwrightSeoEditor;
	const box = document.querySelector( '.tagwright-metabox' );
	if ( ! data || ! box ) {
		return;
	}

	const { __, _n, sprintf } = window.wp.i18n;
	const Analysis = window.TagwrightSEOAnalysis;
	const TagwrightSEO = window.TagwrightSEO || {};

	const $ = ( selector, root = box ) => root.querySelector( selector );
	const $$ = ( selector, root = box ) => Array.from( root.querySelectorAll( selector ) );
	const field = ( name ) => $( `[name="tagwright_seo[${ name }]"]` );

	function debounce( fn, wait ) {
		let timer = null;
		return ( ...args ) => {
			clearTimeout( timer );
			timer = setTimeout( () => fn( ...args ), wait );
		};
	}

	/* ------------------------------------------------------------------
	 * Reading the content from whichever editor is active
	 * --------------------------------------------------------------- */

	const blockEditor = 'block' === data.editor && window.wp.data && window.wp.data.select( 'core/editor' );

	const source = {
		title() {
			if ( 'term' === data.objectType ) {
				const input = document.getElementById( 'name' );
				return input ? input.value : '';
			}
			if ( blockEditor ) {
				return window.wp.data.select( 'core/editor' ).getEditedPostAttribute( 'title' ) || '';
			}
			const input = document.getElementById( 'title' );
			return input ? input.value : '';
		},

		content() {
			if ( 'term' === data.objectType ) {
				const input = document.getElementById( 'description' );
				return input ? input.value : '';
			}
			// Page builders keep the real layout outside the editor; the
			// Integrations feature passes their text in instead.
			if ( data.builderContent ) {
				return data.builderContent;
			}
			if ( blockEditor ) {
				return window.wp.data.select( 'core/editor' ).getEditedPostContent() || '';
			}
			if ( window.tinymce && window.tinymce.get( 'content' ) && ! window.tinymce.get( 'content' ).isHidden() ) {
				return window.tinymce.get( 'content' ).getContent();
			}
			const textarea = document.getElementById( 'content' );
			return textarea ? textarea.value : '';
		},

		excerpt() {
			if ( blockEditor ) {
				return window.wp.data.select( 'core/editor' ).getEditedPostAttribute( 'excerpt' ) || '';
			}
			const textarea = document.getElementById( 'excerpt' );
			return textarea ? textarea.value : '';
		},

		slug() {
			if ( 'term' === data.objectType ) {
				const input = document.getElementById( 'slug' );
				return input ? input.value : '';
			}
			if ( blockEditor ) {
				const editor = window.wp.data.select( 'core/editor' );
				return editor.getEditedPostAttribute( 'slug' ) || ( editor.getEditedPostSlug ? editor.getEditedPostSlug() : '' ) || '';
			}
			const input = document.getElementById( 'post_name' );
			const preview = document.getElementById( 'editable-post-name-full' );
			return ( preview && preview.textContent ) || ( input && input.value ) || '';
		},

		permalink() {
			if ( blockEditor ) {
				return window.wp.data.select( 'core/editor' ).getPermalink() || data.permalink;
			}
			return data.permalink;
		},

		featuredImage() {
			if ( blockEditor ) {
				const id = window.wp.data.select( 'core/editor' ).getEditedPostAttribute( 'featured_media' );
				if ( id && window.wp.data.select( 'core' ) ) {
					const media = window.wp.data.select( 'core' ).getMedia( id );
					if ( media ) {
						return ( media.media_details && media.media_details.sizes && media.media_details.sizes.large && media.media_details.sizes.large.source_url ) || media.source_url;
					}
				}
				return id ? data.featuredImage : '';
			}
			const img = document.querySelector( '#postimagediv img' );
			return img ? img.src : data.featuredImage;
		},

		subscribe( callback ) {
			if ( blockEditor ) {
				let previous = '';
				window.wp.data.subscribe( () => {
					const editor = window.wp.data.select( 'core/editor' );
					const key = [
						editor.getEditedPostAttribute( 'title' ),
						editor.getEditedPostAttribute( 'excerpt' ),
						editor.getEditedPostAttribute( 'slug' ),
						editor.getEditedPostAttribute( 'featured_media' ),
						editor.getEditedPostContent().length,
					].join( '|' );
					if ( key !== previous ) {
						previous = key;
						callback();
					}
				} );
				return;
			}

			[ 'title', 'excerpt', 'content', 'name', 'description', 'slug', 'post_name' ].forEach( ( id ) => {
				const el = document.getElementById( id );
				if ( el ) {
					el.addEventListener( 'input', callback );
				}
			} );

			// TinyMCE fires its own events.
			const hookTiny = () => {
				const editor = window.tinymce && window.tinymce.get( 'content' );
				if ( editor ) {
					editor.on( 'keyup change SetContent', callback );
					return true;
				}
				return false;
			};
			if ( ! hookTiny() && window.jQuery ) {
				window.jQuery( document ).on( 'tinymce-editor-init', hookTiny );
			}

			const permalinkBox = document.getElementById( 'edit-slug-box' );
			if ( permalinkBox && window.MutationObserver ) {
				new window.MutationObserver( callback ).observe( permalinkBox, { childList: true, subtree: true } );
			}
		},
	};

	/* ------------------------------------------------------------------
	 * Variables
	 * --------------------------------------------------------------- */

	function excerptFromContent() {
		const manual = source.excerpt();
		const text = Analysis.stripTags( manual || source.content() ).replace( /\s+/g, ' ' ).trim();
		if ( text.length <= 155 ) {
			return text;
		}
		const cut = text.slice( 0, 155 );
		const space = cut.lastIndexOf( ' ' );
		return ( space > 90 ? cut.slice( 0, space ) : cut ).replace( /[\s,.;:-]+$/, '' ) + '…';
	}

	function liveValues() {
		const values = Object.assign( {}, data.variables );
		const title = source.title();
		values.title = title;
		values.focuskw = field( 'focus_keyphrase' ) ? field( 'focus_keyphrase' ).value : '';

		if ( 'term' === data.objectType ) {
			values.term_title = title;
			values.term_description = Analysis.stripTags( source.content() ).trim().slice( 0, 155 );
			values.excerpt = values.term_description;
		} else {
			values.excerpt = excerptFromContent();
			values.excerpt_only = source.excerpt();
		}

		return values;
	}

	function escapeRegExp( text ) {
		return text.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	}

	function replaceVariables( template ) {
		const values = liveValues();
		let output = String( template || '' ).replace( /%([a-z_]+)(?:\(([^)%]*)\))?%/gi, ( match, name, argument ) => {
			name = name.toLowerCase();
			if ( 'customfield' === name ) {
				// Shows the saved value of the field. New values appear after saving.
				return TagwrightSEO.previewCustomField ? TagwrightSEO.previewCustomField( { type: data.objectType, id: data.objectId }, argument ) : '';
			}
			return Object.prototype.hasOwnProperty.call( values, name ) ? values[ name ] : match;
		} );

		// Same tidy-up as the PHP side: collapse spaces and stray separators.
		const sep = escapeRegExp( values.sep || '-' );
		output = output.replace( /\s+/g, ' ' ).trim();
		output = output.replace( new RegExp( '(' + sep + ')(\\s*' + sep + ')+', 'g' ), '$1' );
		output = output.replace( new RegExp( '^\\s*' + sep + '\\s*|\\s*' + sep + '\\s*$', 'g' ), '' );

		return output.trim();
	}

	function resolved( name ) {
		const input = field( name );
		const value = input ? input.value.trim() : '';
		return replaceVariables( value || ( data.defaults && data.defaults[ name ] ) || '' );
	}

	/* ------------------------------------------------------------------
	 * Length meters
	 * --------------------------------------------------------------- */

	const LIMITS = {
		title: { min: 30, max: 60, hard: 65 },
		description: { min: 120, max: 160, hard: 175 },
	};

	function updateCounters() {
		$$( '.tagwright-template[data-counter]' ).forEach( ( wrapper ) => {
			const type = wrapper.dataset.counter;
			const limits = LIMITS[ type ];
			const counter = $( '.tagwright-counter', wrapper );
			if ( ! limits || ! counter ) {
				return;
			}
			const input = $( '.tagwright-template__input', wrapper );
			const name = ( input.name.match( /\[([^\]]+)\]/ ) || [] )[ 1 ];
			const length = [ ...resolved( name ) ].length;

			let state = 'good';
			if ( ! length || length > limits.hard ) {
				state = 'poor';
			} else if ( length < limits.min || length > limits.max ) {
				state = 'ok';
			}

			counter.classList.remove( 'is-good', 'is-ok', 'is-poor' );
			counter.classList.add( 'is-' + state );
			$( '.tagwright-counter__bar span', counter ).style.width = Math.min( 100, ( length / limits.max ) * 100 ) + '%';
			$( '.tagwright-counter__text', counter ).textContent = sprintf(
				/* translators: 1: number of characters, 2: recommended maximum. */
				__( '%1$d / %2$d characters', 'tagwright-seo' ),
				length,
				limits.max
			);
		} );
	}

	/* ------------------------------------------------------------------
	 * Previews
	 * --------------------------------------------------------------- */

	function setText( element, text ) {
		if ( element ) {
			element.textContent = text;
		}
	}

	function highlight( text, keyphrase ) {
		const escape = ( value ) => ( TagwrightSEO.escapeHtml ? TagwrightSEO.escapeHtml( value ) : value );
		const parts = Analysis.words( keyphrase ).filter( ( word ) => word.length > 2 );
		if ( ! parts.length ) {
			return escape( text );
		}
		// Match on the raw text and escape each piece afterwards, so words
		// like "amp" or "quot" never land inside an HTML entity.
		const pattern = new RegExp( '\\b(' + parts.map( escapeRegExp ).join( '|' ) + ')\\b', 'gi' );
		return String( text ?? '' )
			.split( pattern )
			.map( ( piece, index ) => ( index % 2 ? '<b>' + escape( piece ) + '</b>' : escape( piece ) ) )
			.join( '' );
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

	function currentPermalink() {
		let url = source.permalink() || data.homeUrl;
		if ( 'term' !== data.objectType && ! blockEditor ) {
			const slug = source.slug();
			if ( slug && url.includes( '%postname%' ) ) {
				url = url.replace( '%postname%', slug );
			}
		}
		return url;
	}

	function socialImage() {
		const custom = $( '[data-field="og_image_id"] .tagwright-media__preview img' );
		if ( custom && custom.src ) {
			return custom.src;
		}
		if ( 'term' !== data.objectType ) {
			const featured = source.featuredImage();
			if ( featured ) {
				return featured;
			}
		}
		return data.defaultImage || '';
	}

	function updatePreviews() {
		const title = resolved( 'title' ) || source.title();
		const description = resolved( 'description' );
		const keyphrase = field( 'focus_keyphrase' ) ? field( 'focus_keyphrase' ).value : '';
		const url = currentPermalink();

		const serp = $( '[data-preview="serp"]' );
		if ( serp ) {
			const favicon = $( '.tagwright-serp__favicon', serp );
			if ( favicon && data.favicon && ! favicon.firstChild ) {
				const img = document.createElement( 'img' );
				img.src = data.favicon;
				img.alt = '';
				favicon.appendChild( img );
			}
			setText( $( '.tagwright-serp__name', serp ), data.siteName );
			setText( $( '.tagwright-serp__url', serp ), breadcrumbUrl( url ) );
			setText( $( '.tagwright-serp__title', serp ), title );
			$( '.tagwright-serp__description', serp ).innerHTML = description
				? highlight( description, keyphrase )
				: '<i>' + TagwrightSEO.escapeHtml( __( 'No description yet. Search engines will pick some text from the page.', 'tagwright-seo' ) ) + '</i>';
		}

		const ogTitle = replaceVariables( field( 'og_title' ) ? field( 'og_title' ).value : '' ) || title;
		const ogDescription = replaceVariables( field( 'og_description' ) ? field( 'og_description' ).value : '' ) || description;
		const image = socialImage();
		let domain = '';
		try {
			domain = new URL( url ).host;
		} catch ( e ) {
			domain = '';
		}

		const facebook = $( '[data-preview="facebook"]' );
		if ( facebook ) {
			setText( $( '.tagwright-social-card__domain', facebook ), domain );
			setText( $( '.tagwright-social-card__title', facebook ), ogTitle );
			setText( $( '.tagwright-social-card__desc', facebook ), ogDescription );
			setImage( $( '.tagwright-social-card__image', facebook ), image );
		}

		const x = $( '[data-preview="x"]' );
		if ( x ) {
			setText( $( '.tagwright-social-card__domain', x ), domain );
			setText( $( '.tagwright-social-card__title', x ), replaceVariables( field( 'twitter_title' ) ? field( 'twitter_title' ).value : '' ) || ogTitle );
			setText( $( '.tagwright-social-card__desc', x ), replaceVariables( field( 'twitter_description' ) ? field( 'twitter_description' ).value : '' ) || ogDescription );
			setImage( $( '.tagwright-social-card__image', x ), image );
		}
	}

	function setImage( element, url ) {
		if ( ! element ) {
			return;
		}
		element.style.backgroundImage = url ? 'url("' + url.replace( /"/g, '%22' ) + '")' : '';
		element.classList.toggle( 'has-image', !! url );
	}

	/* ------------------------------------------------------------------
	 * Analysis
	 * --------------------------------------------------------------- */

	let usedBy = [];

	const checkKeyphraseUse = debounce( ( keyphrase ) => {
		if ( ! keyphrase || ! TagwrightSEO.api ) {
			usedBy = [];
			runAnalysis();
			return;
		}
		TagwrightSEO.api( 'keyphrase?' + new URLSearchParams( { keyphrase, exclude: data.objectId || 0, type: data.objectType } ).toString() )
			.then( ( response ) => {
				usedBy = response.items || [];
				runAnalysis();
			} )
			.catch( () => {} );
	}, 800 );

	function scoreState( score ) {
		if ( null === score || undefined === score ) {
			return 'none';
		}
		return score >= 70 ? 'good' : ( score >= 40 ? 'ok' : 'poor' );
	}

	function scorePill( score ) {
		const state = scoreState( score );
		if ( 'none' === state ) {
			return '<span class="tagwright-score-dot is-none"></span>';
		}
		return `<span class="tagwright-score-pill is-${ state }">${ score }</span>`;
	}

	function renderAnalysis( result ) {
		const panel = $( '[data-analysis]' );
		if ( ! panel ) {
			return;
		}

		const keyphrase = field( 'focus_keyphrase' ) ? field( 'focus_keyphrase' ).value.trim() : '';
		const seoScore = result.seo.score;
		const readScore = result.readability.score;

		// Ring.
		const ring = $( '[data-ring="seo"]', panel );
		const circle = $( '.tagwright-ring__value', ring );
		const circumference = 169.6;
		ring.classList.remove( 'is-good', 'is-ok', 'is-poor' );
		ring.classList.add( 'is-' + scoreState( seoScore ) );
		circle.style.strokeDashoffset = circumference - ( circumference * ( seoScore || 0 ) ) / 100;
		$( '.tagwright-ring__label', ring ).textContent = keyphrase ? seoScore : '–';

		const headline = $( '[data-analysis-headline]', panel );
		const sub = $( '[data-analysis-sub]', panel );
		if ( ! keyphrase ) {
			headline.textContent = __( 'Add a focus keyphrase to start', 'tagwright-seo' );
		} else if ( seoScore >= 70 ) {
			headline.textContent = __( 'Great job, this page is well optimized', 'tagwright-seo' );
		} else if ( seoScore >= 40 ) {
			headline.textContent = __( 'Good start, a few things to improve', 'tagwright-seo' );
		} else {
			headline.textContent = __( 'This page needs some SEO work', 'tagwright-seo' );
		}

		const problems = result.seo.checks.filter( ( item ) => 'good' !== item.status ).length;
		sub.textContent = problems
			/* translators: %d: number of suggestions. */
			? sprintf( _n( '%d suggestion below.', '%d suggestions below.', problems, 'tagwright-seo' ), problems )
			: __( 'Every check passed.', 'tagwright-seo' );

		// Check list: problems first.
		const order = { poor: 0, ok: 1, good: 2 };
		const groups = [ [ __( 'SEO', 'tagwright-seo' ), result.seo.checks ] ];
		if ( data.readability && result.readability.checks.length ) {
			groups.push( [ __( 'Readability', 'tagwright-seo' ), result.readability.checks ] );
		}

		$( '[data-checks]', panel ).innerHTML = groups.map( ( [ label, checks ] ) =>
			`<li class="tagwright-checks__group">${ TagwrightSEO.escapeHtml( label ) }</li>` +
			checks
				.slice()
				.sort( ( a, b ) => order[ a.status ] - order[ b.status ] )
				.map( ( item ) => `<li class="tagwright-check-item is-${ item.status }"><span class="tagwright-check-item__dot" aria-hidden="true"></span><span><span class="screen-reader-text">${ TagwrightSEO.escapeHtml( item.status ) }: </span>${ TagwrightSEO.escapeHtml( item.text ) }</span></li>` )
				.join( '' )
		).join( '' );

		// Scores in the header and hidden inputs that are saved with the post.
		const seoInput = $( '[data-score-input="seo"]' );
		const readInput = $( '[data-score-input="readability"]' );
		if ( seoInput ) {
			seoInput.value = keyphrase ? seoScore : '';
		}
		if ( readInput ) {
			readInput.value = null === readScore || undefined === readScore ? '' : readScore;
		}

		const seoBadge = $( '[data-score="seo"]' );
		if ( seoBadge ) {
			seoBadge.innerHTML = scorePill( keyphrase ? seoScore : null );
		}
		const readBadge = $( '[data-score="readability"]' );
		if ( readBadge ) {
			readBadge.innerHTML = scorePill( readScore );
		}
	}

	function runAnalysis() {
		if ( ! data.analysis || ! Analysis ) {
			return;
		}

		const result = Analysis.analyze( {
			keyphrase: field( 'focus_keyphrase' ) ? field( 'focus_keyphrase' ).value : '',
			title: resolved( 'title' ) || source.title(),
			description: resolved( 'description' ),
			slug: source.slug(),
			content: source.content(),
			homeUrl: data.homeUrl,
			locale: data.locale,
			readability: data.readability,
			keyphraseUsedBy: usedBy,
		} );

		renderAnalysis( result );

		box.dispatchEvent( new CustomEvent( 'tagwright-seo:analysis', { detail: result, bubbles: true } ) );
	}

	/* ------------------------------------------------------------------
	 * Wiring
	 * --------------------------------------------------------------- */

	const refresh = () => {
		updateCounters();
		updatePreviews();
	};
	const refreshAll = debounce( () => {
		refresh();
		runAnalysis();
	}, 400 );

	box.addEventListener( 'input', ( event ) => {
		refresh();
		if ( event.target.name === 'tagwright_seo[focus_keyphrase]' ) {
			checkKeyphraseUse( event.target.value.trim() );
		}
		refreshAll();
	} );
	box.addEventListener( 'change', refreshAll );

	$$( 'input[name="tagwright_preview_device"]' ).forEach( ( radio ) => {
		radio.addEventListener( 'change', () => {
			$( '[data-preview="serp"]' ).classList.toggle( 'is-mobile', 'mobile' === radio.value && radio.checked );
		} );
	} );

	// Variable values for the post title change as the editor types.
	source.subscribe( refreshAll );

	// Redraw once a custom field value has been looked up.
	document.addEventListener( 'tagwright-seo:custom-field', refreshAll );

	// Analysis extensions load after this script, and some keep state of
	// their own, so they ask for a fresh run instead of waiting for a keystroke.
	document.addEventListener( 'tagwright-seo:reanalyze', refreshAll );

	// In the block editor the page does not reload on save, so look custom
	// fields up again once a save finishes.
	if ( blockEditor && TagwrightSEO.clearCustomFields ) {
		let wasSaving = false;
		window.wp.data.subscribe( () => {
			const editor = window.wp.data.select( 'core/editor' );
			const saving = editor.isSavingPost() && ! editor.isAutosavingPost();
			if ( wasSaving && ! saving ) {
				TagwrightSEO.clearCustomFields();
				refreshAll();
			}
			wasSaving = saving;
		} );
	}

	// Keep the device radios out of the post form so they are never saved.
	$$( 'input[name="tagwright_preview_device"]' ).forEach( ( radio ) => radio.setAttribute( 'form', 'tagwright-none' ) );

	// Show the template that applies when a field is left empty.
	[ 'title', 'description' ].forEach( ( name ) => {
		const input = field( name );
		if ( input && data.defaults && data.defaults[ name ] ) {
			input.placeholder = data.defaults[ name ];
		}
	} );

	refresh();
	runAnalysis();
	if ( field( 'focus_keyphrase' ) && field( 'focus_keyphrase' ).value.trim() ) {
		checkKeyphraseUse( field( 'focus_keyphrase' ).value.trim() );
	}
}( window, document ) );
