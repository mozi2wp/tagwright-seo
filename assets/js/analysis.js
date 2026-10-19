/**
 * Tagwright SEO content analysis.
 *
 * A dependency-free engine that scores a piece of content for SEO and
 * readability. It runs entirely in the browser while you write; nothing
 * here touches the front end of the site.
 *
 * Usage:
 *   const result = TagwrightSEOAnalysis.analyze( {
 *       keyphrase, title, description, slug, content, url, locale, readability
 *   } );
 *
 * Extensions (Tagwright SEO Pro, add-ons) plug in without copying any of this:
 *   TagwrightSEOAnalysis.setMatcher( fn )        replaces keyphrase matching
 *   TagwrightSEOAnalysis.addInputFilter( fn )    adds data before checks run
 *   TagwrightSEOAnalysis.addCheckProvider( fn )  adds checks that count to a score
 * and checks read their limits from `input.thresholds`. See the add-on
 * developer guide, section "Analysis extensions".
 */
( function ( window ) {
	'use strict';

	const { __, _n, sprintf } = window.wp && window.wp.i18n ? window.wp.i18n : {
		__: ( s ) => s,
		_n: ( s, p, n ) => ( 1 === n ? s : p ),
		sprintf: ( s, ...args ) => {
			let i = 0;
			return s.replace( /%(\d+\$)?[sd]/g, () => args[ i++ ] ).replace( /%%/g, '%' );
		},
	};

	/* ------------------------------------------------------------------
	 * Text helpers
	 * --------------------------------------------------------------- */

	function stripTags( html ) {
		return String( html || '' )
			.replace( /<!--[\s\S]*?-->/g, ' ' )
			.replace( /<(script|style)[\s\S]*?<\/\1>/gi, ' ' )
			.replace( /<\/(p|div|h[1-6]|li|blockquote|figcaption|tr)>/gi, '$&\n' )
			.replace( /<br\s*\/?>/gi, '\n' )
			.replace( /<[^>]+>/g, ' ' )
			.replace( /&nbsp;/g, ' ' )
			.replace( /&amp;/g, '&' )
			.replace( /&#8217;|&rsquo;/g, '\'' )
			.replace( /&[a-z0-9#]+;/gi, ' ' );
	}

	function normalize( text ) {
		return String( text || '' )
			.toLowerCase()
			.normalize( 'NFD' )
			.replace( /[̀-ͯ]/g, '' )
			.replace( /[’‘`]/g, '\'' )
			.replace( /\s+/g, ' ' )
			.trim();
	}

	function words( text ) {
		return normalize( text ).match( /[\p{L}\p{N}'’-]+/gu ) || [];
	}

	function sentences( text ) {
		return String( text || '' )
			.replace( /\n+/g, '. ' )
			.split( /(?<=[.!?…])\s+(?=[\p{Lu}\p{N}"“'(])/u )
			.map( ( s ) => s.trim() )
			.filter( ( s ) => words( s ).length > 0 );
	}

	function paragraphs( html ) {
		const found = String( html || '' ).match( /<p[^>]*>[\s\S]*?<\/p>/gi );
		const list = found && found.length ? found.map( stripTags ) : stripTags( html ).split( /\n{2,}/ );
		return list.map( ( p ) => p.trim() ).filter( Boolean );
	}

	function escapeRegExp( text ) {
		return text.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	}

	/**
	 * Counts how often the keyphrase appears. Every word must be present,
	 * in order, allowing for simple plural and possessive endings.
	 *
	 * @param {string} haystack  Text to search.
	 * @param {string} keyphrase Keyphrase.
	 * @return {number} Matches.
	 */
	function countKeyphrase( haystack, keyphrase ) {
		const parts = words( keyphrase );
		if ( ! parts.length ) {
			return 0;
		}
		const pattern = parts.map( ( word ) => escapeRegExp( word ) + '(?:s|es|\'s)?' ).join( '[\\s\\-]+' );
		const regex = new RegExp( '(^|[^\\p{L}\\p{N}])' + pattern + '(?=$|[^\\p{L}\\p{N}])', 'gu' );
		const matches = normalize( haystack ).match( regex );
		return matches ? matches.length : 0;
	}

	function containsKeyphrase( haystack, keyphrase ) {
		return countKeyphrase( haystack, keyphrase ) > 0;
	}

	/* ------------------------------------------------------------------
	 * Extension points
	 * --------------------------------------------------------------- */

	/**
	 * Limits the checks use. Extensions change them per analysis through
	 * `input.thresholds` (usually from an input filter), never globally, so
	 * one post's stricter rules can't leak into the next analysis.
	 */
	const THRESHOLDS = Object.freeze( {
		textMin: 300,
		textGood: 600,
		densityMin: 0.5,
		densityMax: 3,
		internalLinksMin: 1,
	} );

	const extensions = {
		matcher: null,
		inputFilters: [],
		providers: [],
	};

	/**
	 * Extension code is someone else's code: a bug there should cost that
	 * extension its result, never the core analysis.
	 *
	 * @param {string} where What was running.
	 * @param {Error}  error What it threw.
	 */
	function report( where, error ) {
		if ( 'undefined' !== typeof console && console.error ) {
			console.error( 'Tagwright SEO analysis: ' + where + ' failed.', error );
		}
	}

	function thresholdsFor( input ) {
		const limits = Object.assign( {}, THRESHOLDS );
		const custom = input && input.thresholds;
		if ( custom && 'object' === typeof custom ) {
			Object.keys( THRESHOLDS ).forEach( ( key ) => {
				if ( 'number' === typeof custom[ key ] && isFinite( custom[ key ] ) ) {
					limits[ key ] = custom[ key ];
				}
			} );
		}
		return limits;
	}

	/**
	 * Counts the keyphrase in one part of the page, through the registered
	 * matcher when there is one. `where` tells the matcher which part it is
	 * looking at (title, description, slug, intro, body, headings, alt), so
	 * it can, say, accept synonyms in the body but not in the title.
	 *
	 * @param {string} haystack  Text to search.
	 * @param {string} keyphrase Keyphrase.
	 * @param {string} where     Part of the page.
	 * @param {Object} input     Analysis input.
	 * @return {number} Matches.
	 */
	function matchKeyphrase( haystack, keyphrase, where, input ) {
		if ( extensions.matcher ) {
			try {
				const found = extensions.matcher( haystack, keyphrase, { where, input: input || {}, exact: countKeyphrase } );
				if ( 'number' === typeof found && isFinite( found ) && found >= 0 ) {
					return Math.floor( found );
				}
			} catch ( error ) {
				report( 'matcher', error );
			}
		}
		return countKeyphrase( haystack, keyphrase );
	}

	function setMatcher( fn ) {
		extensions.matcher = 'function' === typeof fn ? fn : null;
	}

	function addInputFilter( fn ) {
		if ( 'function' === typeof fn ) {
			extensions.inputFilters.push( fn );
		}
	}

	function addCheckProvider( fn, options ) {
		if ( 'function' !== typeof fn ) {
			return;
		}
		const group = options && 'readability' === options.group ? 'readability' : 'seo';
		extensions.providers.push( { fn, group } );
	}

	/**
	 * Runs the input filters on a copy, so a filter can't change the object
	 * the caller passed in.
	 *
	 * @param {Object} input Analysis input.
	 * @return {Object} Filtered input.
	 */
	function filterInput( input ) {
		let filtered = Object.assign( {}, input );
		extensions.inputFilters.forEach( ( fn ) => {
			try {
				const result = fn( filtered );
				if ( result && 'object' === typeof result ) {
					filtered = result;
				}
			} catch ( error ) {
				report( 'input filter', error );
			}
		} );
		return filtered;
	}

	function isCheck( item ) {
		return item && 'string' === typeof item.id && 'string' === typeof item.text && [ 'good', 'ok', 'poor' ].includes( item.status );
	}

	function providedChecks( group, input, helpers ) {
		const found = [];
		extensions.providers.forEach( ( provider ) => {
			if ( provider.group !== group ) {
				return;
			}
			try {
				const checks = provider.fn( input, helpers );
				if ( Array.isArray( checks ) ) {
					checks.filter( isCheck ).forEach( ( item ) => found.push( item ) );
				}
			} catch ( error ) {
				report( group + ' check provider', error );
			}
		} );
		return found;
	}

	/**
	 * Rough syllable counter for English, good enough for Flesch.
	 *
	 * @param {string} word Word.
	 * @return {number} Syllables.
	 */
	function syllables( word ) {
		word = word.toLowerCase().replace( /[^a-z]/g, '' );
		if ( word.length <= 3 ) {
			return 1;
		}
		word = word.replace( /(?:[^laeiouy]es|ed|[^laeiouy]e)$/, '' ).replace( /^y/, '' );
		const groups = word.match( /[aeiouy]{1,2}/g );
		return groups ? groups.length : 1;
	}

	/* ------------------------------------------------------------------
	 * Word lists (English)
	 * --------------------------------------------------------------- */

	const TRANSITIONS = [
		'accordingly', 'additionally', 'afterward', 'afterwards', 'also', 'although', 'besides', 'but', 'consequently',
		'conversely', 'finally', 'first', 'firstly', 'furthermore', 'hence', 'however', 'indeed', 'instead', 'later',
		'likewise', 'meanwhile', 'moreover', 'nevertheless', 'next', 'nonetheless', 'otherwise', 'second', 'secondly',
		'similarly', 'since', 'so', 'still', 'subsequently', 'then', 'therefore', 'third', 'thus', 'ultimately',
		'unlike', 'whereas', 'yet', 'because', 'overall', 'specifically', 'notably', 'especially', 'above all',
		'after all', 'all in all', 'as a result', 'as well as', 'at first', 'at last', 'at the same time', 'because of',
		'by contrast', 'even so', 'for example', 'for instance', 'for this reason', 'in addition', 'in conclusion',
		'in contrast', 'in fact', 'in general', 'in other words', 'in short', 'in summary', 'in the end', 'of course',
		'on the other hand', 'such as', 'to begin with', 'to sum up', 'that is', 'in particular', 'as soon as',
		'even though', 'in the meantime', 'first of all', 'as long as', 'so that', 'in order to',
	];

	const IRREGULAR_PARTICIPLES = [
		'been', 'begun', 'bitten', 'blown', 'born', 'bought', 'broken', 'brought', 'built', 'caught', 'chosen', 'done',
		'drawn', 'driven', 'eaten', 'fallen', 'felt', 'found', 'forgotten', 'forgiven', 'frozen', 'given', 'gone',
		'grown', 'heard', 'held', 'hidden', 'hit', 'hurt', 'kept', 'known', 'laid', 'led', 'left', 'lost', 'made', 'meant',
		'met', 'paid', 'put', 'read', 'ridden', 'run', 'said', 'seen', 'sent', 'set', 'shaken', 'shown', 'shut', 'sold',
		'spent', 'spoken', 'stolen', 'struck', 'sung', 'taken', 'taught', 'thought', 'thrown', 'told', 'torn', 'understood',
		'won', 'worn', 'written',
	];

	/* ------------------------------------------------------------------
	 * Checks
	 * --------------------------------------------------------------- */

	function check( id, status, text, group ) {
		return { id, status, text, group };
	}

	function seoChecks( input ) {
		const results = [];
		const limits = thresholdsFor( input );
		const keyphrase = ( input.keyphrase || '' ).trim();
		const has = ( haystack, where ) => matchKeyphrase( haystack, keyphrase, where, input ) > 0;
		const html = input.content || '';
		const text = stripTags( html );
		const textWords = words( text );
		const wordCount = textWords.length;
		const title = input.title || '';
		const description = input.description || '';

		if ( ! keyphrase ) {
			results.push( check( 'keyphrase', 'poor', __( 'No focus keyphrase set. Add the search term you want this page to rank for.', 'tagwright-seo' ), 'basics' ) );
		} else {
			const length = words( keyphrase ).length;
			if ( length > 4 ) {
				results.push( check( 'keyphrase_length', 'ok', sprintf( /* translators: %d: number of words. */ __( 'Your keyphrase has %d words. Shorter keyphrases of up to four words are easier to target.', 'tagwright-seo' ), length ), 'basics' ) );
			} else {
				results.push( check( 'keyphrase_length', 'good', __( 'Keyphrase length looks good.', 'tagwright-seo' ), 'basics' ) );
			}

			// Title.
			const titleNorm = normalize( title );
			const keyNorm = normalize( keyphrase );
			if ( titleNorm.startsWith( keyNorm ) ) {
				results.push( check( 'kw_title', 'good', __( 'The keyphrase starts the SEO title. Nice.', 'tagwright-seo' ), 'basics' ) );
			} else if ( has( title, 'title' ) ) {
				results.push( check( 'kw_title', 'good', __( 'The SEO title contains the keyphrase. Moving it closer to the start can help a little.', 'tagwright-seo' ), 'basics' ) );
			} else {
				results.push( check( 'kw_title', 'poor', __( 'The keyphrase is missing from the SEO title.', 'tagwright-seo' ), 'basics' ) );
			}

			// Description.
			if ( has( description, 'description' ) ) {
				results.push( check( 'kw_description', 'good', __( 'The meta description contains the keyphrase.', 'tagwright-seo' ), 'basics' ) );
			} else {
				results.push( check( 'kw_description', 'poor', __( 'The keyphrase is missing from the meta description.', 'tagwright-seo' ), 'basics' ) );
			}

			// Slug.
			const slug = normalize( decodeURIComponent( input.slug || '' ) ).replace( /[-_]+/g, ' ' );
			if ( slug && has( slug, 'slug' ) ) {
				results.push( check( 'kw_slug', 'good', __( 'The URL contains the keyphrase.', 'tagwright-seo' ), 'basics' ) );
			} else if ( slug ) {
				const parts = words( keyphrase );
				const hits = parts.filter( ( word ) => slug.includes( word ) ).length;
				results.push( check( 'kw_slug', hits >= Math.ceil( parts.length / 2 ) ? 'ok' : 'poor', __( 'Use the keyphrase in the URL slug.', 'tagwright-seo' ), 'basics' ) );
			}

			// Introduction.
			const first = paragraphs( html )[ 0 ] || '';
			if ( has( first, 'intro' ) ) {
				results.push( check( 'kw_intro', 'good', __( 'The keyphrase appears in the first paragraph.', 'tagwright-seo' ), 'content' ) );
			} else {
				results.push( check( 'kw_intro', 'ok', __( 'Mention the keyphrase in your first paragraph so readers and search engines know what the page is about.', 'tagwright-seo' ), 'content' ) );
			}

			// Density.
			if ( wordCount >= 100 ) {
				const count = matchKeyphrase( text, keyphrase, 'body', input );
				const density = ( count * words( keyphrase ).length / wordCount ) * 100;
				const rounded = Math.round( density * 10 ) / 10;
				if ( 0 === count ) {
					results.push( check( 'kw_density', 'poor', __( 'The keyphrase does not appear in the text.', 'tagwright-seo' ), 'content' ) );
				} else if ( density > limits.densityMax ) {
					/* translators: 1: density in percent, 2: number of matches, 3: lowest good density, 4: highest good density. */
					results.push( check( 'kw_density', 'poor', sprintf( __( 'Keyphrase density is %1$s%% (%2$d times). That looks like keyword stuffing; aim for %3$s%% to %4$s%%.', 'tagwright-seo' ), rounded, count, limits.densityMin, limits.densityMax ), 'content' ) );
				} else if ( density < limits.densityMin ) {
					results.push( check( 'kw_density', 'ok', sprintf( /* translators: %1$s: density percentage, %2$d: number of times. %% is a literal percent sign. */ __( 'Keyphrase density is %1$s%% (%2$d times). Using it a little more often would help.', 'tagwright-seo' ), rounded, count ), 'content' ) );
				} else {
					results.push( check( 'kw_density', 'good', sprintf( /* translators: %1$s: density percentage, %2$d: number of times. %% is a literal percent sign. */ __( 'Keyphrase density is %1$s%% (%2$d times). Well balanced.', 'tagwright-seo' ), rounded, count ), 'content' ) );
				}
			}

			// Subheadings.
			const headings = ( html.match( /<h[2-3][^>]*>[\s\S]*?<\/h[2-3]>/gi ) || [] ).map( stripTags );
			if ( headings.length ) {
				const hits = headings.filter( ( heading ) => has( heading, 'headings' ) ).length;
				results.push( hits
					? check( 'kw_headings', 'good', sprintf( /* translators: %d: number of subheadings. */ _n( 'The keyphrase appears in %d subheading.', 'The keyphrase appears in %d subheadings.', hits, 'tagwright-seo' ), hits ), 'content' )
					: check( 'kw_headings', 'ok', __( 'Use the keyphrase in at least one H2 or H3 subheading.', 'tagwright-seo' ), 'content' ) );
			}

			// Image alt text.
			const images = html.match( /<img[^>]*>/gi ) || [];
			if ( images.length ) {
				const alts = images.map( ( img ) => ( img.match( /alt=["']([^"']*)["']/i ) || [ '', '' ] )[ 1 ] );
				if ( alts.some( ( alt ) => has( alt, 'alt' ) ) ) {
					results.push( check( 'kw_alt', 'good', __( 'An image alt text contains the keyphrase.', 'tagwright-seo' ), 'content' ) );
				} else if ( alts.some( ( alt ) => alt.trim() ) ) {
					results.push( check( 'kw_alt', 'ok', __( 'Images have alt text, but none mention the keyphrase.', 'tagwright-seo' ), 'content' ) );
				} else {
					results.push( check( 'kw_alt', 'poor', __( 'Your images have no alt text. Describe them for screen readers and image search.', 'tagwright-seo' ), 'content' ) );
				}
			} else {
				results.push( check( 'kw_alt', 'ok', __( 'This page has no images. Adding one with a descriptive alt text makes it more engaging.', 'tagwright-seo' ), 'content' ) );
			}

			// Used elsewhere?
			if ( input.keyphraseUsedBy && input.keyphraseUsedBy.length ) {
				results.push( check( 'kw_duplicate', 'poor', sprintf( /* translators: %d: number of other pages. */ _n( 'You already use this keyphrase on %d other page. Pick a different one so your pages do not compete.', 'You already use this keyphrase on %d other pages. Pick a different one so your pages do not compete.', input.keyphraseUsedBy.length, 'tagwright-seo' ), input.keyphraseUsedBy.length ), 'basics' ) );
			}
		}

		// Title length.
		const titleLength = [ ...title ].length;
		if ( ! titleLength ) {
			results.push( check( 'title_length', 'poor', __( 'The SEO title is empty.', 'tagwright-seo' ), 'basics' ) );
		} else if ( titleLength < 30 ) {
			results.push( check( 'title_length', 'ok', sprintf( /* translators: %d: number of characters. */ __( 'The SEO title is %d characters. You have room for a few more words.', 'tagwright-seo' ), titleLength ), 'basics' ) );
		} else if ( titleLength > 65 ) {
			results.push( check( 'title_length', 'poor', sprintf( /* translators: %d: number of characters. */ __( 'The SEO title is %d characters and may be cut off. Aim for 30 to 60.', 'tagwright-seo' ), titleLength ), 'basics' ) );
		} else if ( titleLength > 60 ) {
			results.push( check( 'title_length', 'ok', sprintf( /* translators: %d: number of characters. */ __( 'The SEO title is %d characters, slightly long. Aim for up to 60.', 'tagwright-seo' ), titleLength ), 'basics' ) );
		} else {
			results.push( check( 'title_length', 'good', sprintf( /* translators: %d: number of characters. */ __( 'The SEO title is %d characters. Perfect.', 'tagwright-seo' ), titleLength ), 'basics' ) );
		}

		// Description length.
		const descLength = [ ...description ].length;
		if ( ! descLength ) {
			results.push( check( 'description_length', 'poor', __( 'There is no meta description. Search engines will pick a random snippet from the page.', 'tagwright-seo' ), 'basics' ) );
		} else if ( descLength < 120 ) {
			results.push( check( 'description_length', 'ok', sprintf( /* translators: %d: number of characters. */ __( 'The meta description is %d characters. Use up to 160 to make the most of the space.', 'tagwright-seo' ), descLength ), 'basics' ) );
		} else if ( descLength > 160 ) {
			results.push( check( 'description_length', 'ok', sprintf( /* translators: %d: number of characters. */ __( 'The meta description is %d characters and may be cut off. Aim for 120 to 160.', 'tagwright-seo' ), descLength ), 'basics' ) );
		} else {
			results.push( check( 'description_length', 'good', sprintf( /* translators: %d: number of characters. */ __( 'The meta description is %d characters. Perfect.', 'tagwright-seo' ), descLength ), 'basics' ) );
		}

		// Text length.
		if ( wordCount < limits.textMin ) {
			/* translators: 1: number of words, 2: the minimum wanted. */
			results.push( check( 'text_length', 'poor', sprintf( __( 'The text has %1$d words. Pages with fewer than %2$d words rarely rank well.', 'tagwright-seo' ), wordCount, limits.textMin ), 'content' ) );
		} else if ( wordCount < limits.textGood ) {
			results.push( check( 'text_length', 'ok', sprintf( /* translators: %d: number of words. */ __( 'The text has %d words. Good, though longer content often covers a topic better.', 'tagwright-seo' ), wordCount ), 'content' ) );
		} else {
			results.push( check( 'text_length', 'good', sprintf( /* translators: %d: number of words. */ __( 'The text has %d words. Great depth.', 'tagwright-seo' ), wordCount ), 'content' ) );
		}

		// Links.
		const links = ( html.match( /<a\s[^>]*href=["']([^"']+)["']/gi ) || [] ).map( ( tag ) => ( tag.match( /href=["']([^"']+)["']/i ) || [] )[ 1 ] || '' );
		let host = '';
		try {
			host = new URL( input.homeUrl || ( window.location && window.location.origin ) ).host;
		} catch ( e ) {
			host = window.location ? window.location.host : '';
		}
		const internal = links.filter( ( href ) => href.startsWith( '/' ) || href.includes( '//' + host ) ).length;
		const outbound = links.filter( ( href ) => /^https?:\/\//i.test( href ) && ! href.includes( '//' + host ) ).length;

		if ( internal && internal < limits.internalLinksMin ) {
			/* translators: 1: number of internal links, 2: the minimum wanted. */
			results.push( check( 'internal_links', 'ok', sprintf( _n( 'There is %1$d internal link. Aim for at least %2$d.', 'There are %1$d internal links. Aim for at least %2$d.', internal, 'tagwright-seo' ), internal, limits.internalLinksMin ), 'links' ) );
		} else {
			results.push( internal
				? check( 'internal_links', 'good', sprintf( /* translators: %d: number of links. */ _n( 'There is %d internal link. Good for navigation and crawling.', 'There are %d internal links. Good for navigation and crawling.', internal, 'tagwright-seo' ), internal ), 'links' )
				: check( 'internal_links', 'poor', __( 'There are no internal links. Link to related pages on your site.', 'tagwright-seo' ), 'links' ) );
		}

		results.push( outbound
			? check( 'outbound_links', 'good', sprintf( /* translators: %d: number of links. */ _n( 'There is %d link to another site.', 'There are %d links to other sites.', outbound, 'tagwright-seo' ), outbound ), 'links' )
			: check( 'outbound_links', 'ok', __( 'There are no outbound links. Linking to a trusted source can add credibility.', 'tagwright-seo' ), 'links' ) );

		return results;
	}

	function readabilityChecks( input ) {
		const results = [];
		const html = input.content || '';
		const text = stripTags( html );
		const allSentences = sentences( text );
		const allWords = words( text );
		const english = /^en/i.test( input.locale || 'en' );

		if ( allWords.length < 50 ) {
			results.push( check( 'read_short', 'ok', __( 'Write a bit more text to get a readability assessment.', 'tagwright-seo' ), 'readability' ) );
			return results;
		}

		// Flesch reading ease, English only.
		if ( english && allSentences.length ) {
			const syllableCount = allWords.reduce( ( sum, word ) => sum + syllables( word ), 0 );
			const score = Math.round( 206.835 - 1.015 * ( allWords.length / allSentences.length ) - 84.6 * ( syllableCount / allWords.length ) );
			const shown = Math.max( 0, Math.min( 100, score ) );
			if ( score >= 60 ) {
				results.push( check( 'flesch', 'good', sprintf( /* translators: %d: reading ease score from 0 to 100. */ __( 'Flesch reading ease is %d. Easy to read.', 'tagwright-seo' ), shown ), 'readability' ) );
			} else if ( score >= 50 ) {
				results.push( check( 'flesch', 'ok', sprintf( /* translators: %d: reading ease score from 0 to 100. */ __( 'Flesch reading ease is %d. Fairly difficult; shorter sentences and simpler words would help.', 'tagwright-seo' ), shown ), 'readability' ) );
			} else {
				results.push( check( 'flesch', 'poor', sprintf( /* translators: %d: reading ease score from 0 to 100. */ __( 'Flesch reading ease is %d. Hard to read; try shorter sentences and simpler words.', 'tagwright-seo' ), shown ), 'readability' ) );
			}
		}

		// Sentence length.
		const long = allSentences.filter( ( sentence ) => words( sentence ).length > 20 ).length;
		const longShare = allSentences.length ? Math.round( ( long / allSentences.length ) * 100 ) : 0;
		if ( longShare <= 25 ) {
			results.push( check( 'sentence_length', 'good', __( 'Most sentences are a comfortable length.', 'tagwright-seo' ), 'readability' ) );
		} else if ( longShare <= 30 ) {
			results.push( check( 'sentence_length', 'ok', sprintf( /* translators: %d: percentage. %% is a literal percent sign. */ __( '%d%% of sentences have more than 20 words. Try to keep that under 25%%.', 'tagwright-seo' ), longShare ), 'readability' ) );
		} else {
			results.push( check( 'sentence_length', 'poor', sprintf( /* translators: %d: percentage. %% is a literal percent sign. */ __( '%d%% of sentences have more than 20 words. Split some of them up.', 'tagwright-seo' ), longShare ), 'readability' ) );
		}

		// Paragraph length.
		const longParagraphs = paragraphs( html ).filter( ( p ) => words( p ).length > 150 ).length;
		results.push( longParagraphs
			? check( 'paragraph_length', longParagraphs > 1 ? 'poor' : 'ok', sprintf( /* translators: %d: number of paragraphs. */ _n( '%d paragraph is longer than 150 words. Break it up.', '%d paragraphs are longer than 150 words. Break them up.', longParagraphs, 'tagwright-seo' ), longParagraphs ), 'readability' )
			: check( 'paragraph_length', 'good', __( 'Paragraphs are a good length.', 'tagwright-seo' ), 'readability' ) );

		// Subheading distribution.
		if ( allWords.length > 300 ) {
			const sections = html.split( /<h[2-6][^>]*>/i ).map( ( part ) => words( stripTags( part ) ).length );
			const hasHeadings = sections.length > 1;
			const tooLong = sections.filter( ( count ) => count > 300 ).length;
			if ( ! hasHeadings ) {
				results.push( check( 'subheadings', 'poor', __( 'No subheadings. Break the text up with H2 and H3 headings so readers can scan it.', 'tagwright-seo' ), 'readability' ) );
			} else if ( tooLong ) {
				results.push( check( 'subheadings', 'ok', sprintf( /* translators: %d: number of sections. */ _n( '%d section runs longer than 300 words without a subheading.', '%d sections run longer than 300 words without a subheading.', tooLong, 'tagwright-seo' ), tooLong ), 'readability' ) );
			} else {
				results.push( check( 'subheadings', 'good', __( 'Subheadings are well spread through the text.', 'tagwright-seo' ), 'readability' ) );
			}
		}

		if ( english && allSentences.length ) {
			// Passive voice.
			const passiveRegex = new RegExp( '\\b(am|is|are|was|were|be|been|being|get|gets|got|gotten)\\s+(\\w+ly\\s+)?(\\w+ed|' + IRREGULAR_PARTICIPLES.join( '|' ) + ')\\b', 'i' );
			const passive = allSentences.filter( ( sentence ) => passiveRegex.test( sentence ) ).length;
			const passiveShare = Math.round( ( passive / allSentences.length ) * 100 );
			if ( passiveShare <= 10 ) {
				results.push( check( 'passive', 'good', __( 'You mostly use the active voice.', 'tagwright-seo' ), 'readability' ) );
			} else if ( passiveShare <= 15 ) {
				results.push( check( 'passive', 'ok', sprintf( /* translators: %d: percentage. %% is a literal percent sign. */ __( '%d%% of sentences use the passive voice. Try to stay under 10%%.', 'tagwright-seo' ), passiveShare ), 'readability' ) );
			} else {
				results.push( check( 'passive', 'poor', sprintf( /* translators: %d: percentage. %% is a literal percent sign. */ __( '%d%% of sentences use the passive voice. Rewrite some in the active voice.', 'tagwright-seo' ), passiveShare ), 'readability' ) );
			}

			// Transition words.
			const transitionRegex = new RegExp( '\\b(' + TRANSITIONS.map( escapeRegExp ).join( '|' ) + ')\\b', 'i' );
			const withTransitions = allSentences.filter( ( sentence ) => transitionRegex.test( sentence ) ).length;
			const transitionShare = Math.round( ( withTransitions / allSentences.length ) * 100 );
			if ( transitionShare >= 30 ) {
				results.push( check( 'transitions', 'good', sprintf( /* translators: %d: percentage. %% is a literal percent sign. */ __( '%d%% of sentences contain transition words. Great flow.', 'tagwright-seo' ), transitionShare ), 'readability' ) );
			} else if ( transitionShare >= 20 ) {
				results.push( check( 'transitions', 'ok', sprintf( /* translators: %d: percentage. %% is a literal percent sign. */ __( '%d%% of sentences contain transition words. A few more would help the flow.', 'tagwright-seo' ), transitionShare ), 'readability' ) );
			} else {
				results.push( check( 'transitions', 'poor', sprintf( /* translators: %d: percentage. %% is a literal percent sign. */ __( 'Only %d%% of sentences contain transition words such as "however" or "for example".', 'tagwright-seo' ), transitionShare ), 'readability' ) );
			}
		}

		// Repeated sentence openings.
		let streak = 1;
		let worst = 1;
		for ( let i = 1; i < allSentences.length; i++ ) {
			const a = words( allSentences[ i - 1 ] )[ 0 ];
			const b = words( allSentences[ i ] )[ 0 ];
			streak = a && a === b ? streak + 1 : 1;
			worst = Math.max( worst, streak );
		}
		results.push( worst >= 3
			? check( 'openings', 'poor', sprintf( /* translators: %d: number of sentences. */ __( '%d sentences in a row start with the same word. Mix it up.', 'tagwright-seo' ), worst ), 'readability' )
			: check( 'openings', 'good', __( 'Sentence openings are varied.', 'tagwright-seo' ), 'readability' ) );

		return results;
	}

	/**
	 * Turns a list of checks into a 0-100 score.
	 *
	 * @param {Array} checks Checks.
	 * @return {number|null} Score, or null when there is nothing to score.
	 */
	function score( checks ) {
		if ( ! checks.length ) {
			return null;
		}
		const points = { good: 1, ok: 0.5, poor: 0 };
		const total = checks.reduce( ( sum, item ) => sum + ( points[ item.status ] ?? 0 ), 0 );
		return Math.round( ( total / checks.length ) * 100 );
	}

	/**
	 * Everything a check provider needs, so it never has to copy core code.
	 * `extra` is shared by every provider in one analysis and comes back as
	 * `result.extra`.
	 *
	 * @param {Object} input Filtered input.
	 * @param {Object} extra Extension results.
	 * @return {Object} Helpers.
	 */
	function helpersFor( input, extra ) {
		return {
			__,
			_n,
			sprintf,
			check,
			score,
			words,
			sentences,
			paragraphs,
			stripTags,
			normalize,
			syllables,
			escapeRegExp,
			countKeyphrase,
			matchKeyphrase: ( haystack, keyphrase, where ) => matchKeyphrase( haystack, keyphrase, where || 'body', input ),
			seoChecks: ( custom ) => seoChecks( custom || input ),
			thresholds: thresholdsFor( input ),
			extra,
		};
	}

	function analyze( rawInput ) {
		const input = extensions.inputFilters.length ? filterInput( rawInput ) : rawInput;
		const extra = {};
		const helpers = helpersFor( input, extra );

		const seo = seoChecks( input ).concat( providedChecks( 'seo', input, helpers ) );
		const readability = input.readability === false ? [] : readabilityChecks( input ).concat( providedChecks( 'readability', input, helpers ) );

		const result = {
			seo: { score: input.keyphrase ? score( seo ) : Math.min( 39, score( seo ) || 0 ), checks: seo },
			readability: { score: score( readability.filter( ( item ) => 'read_short' !== item.id ) ), checks: readability },
		};

		// Only there when an extension wrote to it, so the result of a site
		// without extensions is exactly what it always was.
		if ( Object.keys( extra ).length ) {
			result.extra = extra;
		}

		return result;
	}

	const api = {
		analyze,
		countKeyphrase,
		matchKeyphrase,
		seoChecks,
		readabilityChecks,
		words,
		sentences,
		paragraphs,
		stripTags,
		normalize,
		syllables,
		score,
		setMatcher,
		addInputFilter,
		addCheckProvider,
		thresholds: THRESHOLDS,
	};

	window.TagwrightSEOAnalysis = api;

	if ( 'undefined' !== typeof module && module.exports ) {
		module.exports = api;
	}
}( 'undefined' !== typeof window ? window : globalThis ) );
