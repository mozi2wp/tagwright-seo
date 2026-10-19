/**
 * Tagwright SEO dashboard.
 *
 * Loads the numbers over REST and draws the KPI tiles, charts, issue list
 * and content table. Add-ons listen for the `tagwright-seo:dashboard` event,
 * which carries the same data plus anything they added on the PHP side.
 */
( function ( window, document ) {
	'use strict';

	const root = document.getElementById( 'tagwright-dashboard' );
	if ( ! root ) {
		return;
	}

	const { __, _n, sprintf } = window.wp.i18n;
	const TagwrightSEO = window.TagwrightSEO;
	const charts = TagwrightSEO.charts;
	const $ = ( selector ) => root.querySelector( selector );
	const esc = TagwrightSEO.escapeHtml;
	const editUrl = root.dataset.editUrl;

	let days = 30;

	function stateOf( score ) {
		if ( null === score || undefined === score ) {
			return 'none';
		}
		return score >= 70 ? 'good' : ( score >= 40 ? 'ok' : 'poor' );
	}

	function pill( score ) {
		const state = stateOf( score );
		return 'none' === state ? '<span class="tagwright-muted">–</span>' : `<span class="tagwright-score-pill is-${ state }">${ Math.round( score ) }</span>`;
	}

	function values( series ) {
		return ( series || [] ).map( ( point ) => point.value );
	}

	/**
	 * Change between the first and last known values in a series.
	 *
	 * @param {Array} series Series points.
	 * @return {number|null} Difference.
	 */
	function delta( series ) {
		const known = values( series ).filter( ( value ) => null !== value );
		if ( known.length < 2 ) {
			return null;
		}
		return Math.round( ( known[ known.length - 1 ] - known[ 0 ] ) * 10 ) / 10;
	}

	function deltaBadge( change, lowerIsBetter = false ) {
		if ( null === change ) {
			return '';
		}
		if ( 0 === change ) {
			return `<span class="tagwright-kpi__delta is-flat">±0</span>`;
		}
		const good = lowerIsBetter ? change < 0 : change > 0;
		return `<span class="tagwright-kpi__delta ${ good ? 'is-up' : 'is-down' }" title="${ esc( sprintf( /* translators: %d: number of days. */ __( 'Change over the last %d days', 'tagwright-seo' ), days ) ) }">${ change > 0 ? '+' : '' }${ change }</span>`;
	}

	/* ------------------------------------------------------------------
	 * Sections
	 * --------------------------------------------------------------- */

	function renderHero( totals ) {
		const score = totals.seo_score_avg;
		const ring = $( '[data-hero-ring]' );
		const circumference = 301.6;
		ring.classList.remove( 'is-good', 'is-ok', 'is-poor' );
		ring.classList.add( 'is-' + stateOf( score ) );
		ring.querySelector( '.tagwright-ring__value' ).style.strokeDashoffset = circumference - ( circumference * ( score || 0 ) ) / 100;
		ring.querySelector( '.tagwright-ring__label' ).textContent = null === score ? '–' : Math.round( score );

		let text;
		if ( ! totals.posts_published ) {
			text = __( 'No published content has been indexed yet. Publish something, or let the indexer finish, and your numbers show up here.', 'tagwright-seo' );
		} else if ( null === score ) {
			text = __( 'Add focus keyphrases to your posts and pages to start scoring them.', 'tagwright-seo' );
		} else {
			const needWork = totals.seo_poor + totals.seo_ok;
			text = needWork
				? sprintf(
					/* translators: 1: number of items that need work, 2: total published items. */
					_n( '%1$d of %2$d published item could rank better. The to-do list below shows where to start.', '%1$d of %2$d published items could rank better. The to-do list below shows where to start.', totals.posts_published, 'tagwright-seo' ),
					needWork,
					totals.posts_published
				)
				: __( 'Every analyzed item scores well. Nice work!', 'tagwright-seo' );
		}
		$( '[data-hero-text]' ).textContent = text;
	}

	function renderKpis( data ) {
		const totals = data.summary.totals;
		const series = data.series;
		const tiles = [
			{
				label: __( 'Average SEO score', 'tagwright-seo' ),
				icon: 'analysis',
				value: null === totals.seo_score_avg ? '–' : Math.round( totals.seo_score_avg ),
				delta: deltaBadge( delta( series.seo_score_avg ) ),
				spark: values( series.seo_score_avg ),
				color: charts.PALETTE[ 0 ],
				url: editUrl + '?orderby=tagwright_score&order=asc',
			},
			{
				label: __( 'Average readability', 'tagwright-seo' ),
				icon: 'book',
				value: null === totals.readability_avg ? '–' : Math.round( totals.readability_avg ),
				delta: deltaBadge( delta( series.readability_avg ) ),
				spark: values( series.readability_avg ),
				color: charts.PALETTE[ 1 ],
				url: editUrl + '?tagwright_filter=read_poor',
			},
			{
				label: __( 'Published content', 'tagwright-seo' ),
				icon: 'file',
				value: ( totals.posts_published || 0 ).toLocaleString(),
				delta: deltaBadge( delta( series.posts_published ) ),
				spark: values( series.posts_published ),
				color: charts.PALETTE[ 0 ],
				url: editUrl,
			},
			{
				label: __( 'URLs in sitemap', 'tagwright-seo' ),
				icon: 'sitemap',
				value: ( totals.sitemap_urls || 0 ).toLocaleString(),
				delta: deltaBadge( delta( series.sitemap_urls ) ),
				spark: values( series.sitemap_urls ),
				color: charts.PALETTE[ 1 ],
				url: data.sitemapUrl || '',
			},
		];

		( data.kpis || [] ).forEach( ( kpi ) => tiles.push( kpi ) );

		const container = $( '[data-kpis]' );
		container.innerHTML = tiles.map( ( tile, index ) => {
			const tag = tile.url ? 'a' : 'div';
			const href = tile.url ? ` href="${ esc( tile.url ) }"` + ( /^https?:/.test( tile.url ) && ! tile.url.includes( '/wp-admin/' ) ? ' target="_blank" rel="noopener"' : '' ) : '';
			return `<${ tag } class="tagwright-kpi"${ href }>
				<div class="tagwright-kpi__label">${ TagwrightSEO.icon( tile.icon, 16 ) }${ esc( tile.label ) }</div>
				<div class="tagwright-kpi__value">${ esc( String( tile.value ) ) }${ tile.delta || '' }</div>
				<div class="tagwright-kpi__spark" data-spark="${ index }"></div>
			</${ tag }>`;
		} ).join( '' );

		tiles.forEach( ( tile, index ) => {
			if ( tile.spark ) {
				charts.sparkline( container.querySelector( `[data-spark="${ index }"]` ), tile.spark, tile.color );
			}
		} );
	}

	function renderCharts( data ) {
		const series = data.series;

		charts.line( $( '[data-chart="scores"]' ), {
			label: __( 'Score trends', 'tagwright-seo' ),
			max: 100,
			series: [
				{ name: __( 'SEO score', 'tagwright-seo' ), values: series.seo_score_avg, area: true },
				{ name: __( 'Readability', 'tagwright-seo' ), values: series.readability_avg },
			],
			format: ( value ) => String( Math.round( value ) ),
			emptyText: __( 'Trends appear after the first daily snapshot. Check back tomorrow.', 'tagwright-seo' ),
		} );

		charts.line( $( '[data-chart="issues"]' ), {
			label: __( 'Issues over time', 'tagwright-seo' ),
			series: [
				{ name: __( 'Poor SEO score', 'tagwright-seo' ), values: series.seo_poor },
				{ name: __( 'No focus keyphrase', 'tagwright-seo' ), values: series.no_focus_keyphrase },
				{ name: __( 'No custom description', 'tagwright-seo' ), values: series.no_description },
			],
			format: ( value ) => String( Math.round( value ) ),
			emptyText: __( 'Nothing to show yet.', 'tagwright-seo' ),
		} );

		const totals = data.summary.totals;
		charts.donut( $( '[data-chart="distribution"]' ), {
			label: __( 'Score distribution', 'tagwright-seo' ),
			caption: __( 'published', 'tagwright-seo' ),
			center: ( totals.posts_published || 0 ).toLocaleString(),
			segments: [
				{ label: __( 'Good (70+)', 'tagwright-seo' ), value: totals.seo_good || 0, color: charts.STATUS.good },
				{ label: __( 'Needs work (40–69)', 'tagwright-seo' ), value: totals.seo_ok || 0, color: charts.STATUS.ok },
				{ label: __( 'Poor (below 40)', 'tagwright-seo' ), value: totals.seo_poor || 0, color: charts.STATUS.poor },
				{ label: __( 'Not analyzed', 'tagwright-seo' ), value: totals.seo_none || 0, color: charts.STATUS.none },
			],
		} );
	}

	/**
	 * Link to a post list filtered by an issue.
	 *
	 * @param {string} postType Post type.
	 * @param {string} filter   Tagwright SEO list filter.
	 * @return {string} URL.
	 */
	function filteredListUrl( postType, filter ) {
		return editUrl + '?post_type=' + encodeURIComponent( postType ) + '&tagwright_filter=' + encodeURIComponent( filter );
	}

	function renderIssues( totals, postTypes ) {
		postTypes = postTypes || {};

		const issues = [
			{ key: 'seo_poor', count: totals.seo_poor, state: 'poor', icon: 'alert', filter: 'poor', title: __( 'Poor SEO score', 'tagwright-seo' ), text: __( 'Start here: these have the most to gain.', 'tagwright-seo' ) },
			{ key: 'no_focus_keyphrase', count: totals.no_focus_keyphrase, state: 'ok', icon: 'keyword', filter: 'no_keyphrase', title: __( 'No focus keyphrase', 'tagwright-seo' ), text: __( 'Without one, the content cannot be scored.', 'tagwright-seo' ) },
			{ key: 'no_description', count: totals.no_description, state: 'ok', icon: 'pen', filter: 'no_desc', title: __( 'No custom meta description', 'tagwright-seo' ), text: __( 'They fall back to the template. A hand-written one usually earns more clicks.', 'tagwright-seo' ) },
			{ key: 'read_poor', count: totals.read_poor, state: 'ok', icon: 'book', filter: 'read_poor', title: __( 'Hard to read', 'tagwright-seo' ), text: __( 'Shorter sentences and more subheadings help.', 'tagwright-seo' ) },
			{ key: 'noindex', count: totals.noindex, state: 'good', icon: 'eye-off', filter: 'noindex', title: __( 'Hidden from search', 'tagwright-seo' ), text: __( 'Double-check these are meant to be hidden.', 'tagwright-seo' ) },
		].filter( ( issue ) => issue.count > 0 );

		const list = $( '[data-issues]' );
		if ( ! issues.length ) {
			list.innerHTML = `<li class="tagwright-empty">${ TagwrightSEO.icon( 'check-circle', 32 ) }<h3>${ esc( __( 'All clear', 'tagwright-seo' ) ) }</h3><p>${ esc( __( 'No content issues found. Keep it up.', 'tagwright-seo' ) ) }</p></li>`;
			return;
		}

		list.innerHTML = issues.map( ( issue ) => {
			// Which content types have this issue, biggest first.
			const types = Object.keys( postTypes )
				.map( ( type ) => ( { type, label: postTypes[ type ].label, count: ( postTypes[ type ].issues || {} )[ issue.key ] || 0 } ) )
				.filter( ( row ) => row.count > 0 )
				.sort( ( a, b ) => b.count - a.count );

			const main = types.length ? filteredListUrl( types[ 0 ].type, issue.filter ) : editUrl + '?tagwright_filter=' + issue.filter;
			const chips = types.length > 1
				? `<div class="tagwright-issue__types">${ types.map( ( row ) => `<a href="${ esc( filteredListUrl( row.type, issue.filter ) ) }">${ esc( row.label ) } <b>${ esc( row.count.toLocaleString() ) }</b></a>` ).join( '' ) }</div>`
				: '';

			return `<li class="tagwright-issue is-${ issue.state }">
				<a href="${ esc( main ) }">
					<span class="tagwright-issue__icon">${ TagwrightSEO.icon( issue.icon, 18 ) }</span>
					<span class="tagwright-issue__text"><strong>${ esc( issue.title ) }</strong><small>${ esc( issue.text ) }</small></span>
					<span class="tagwright-issue__count">${ esc( issue.count.toLocaleString() ) }</span>
					${ TagwrightSEO.icon( 'chevron-right', 16 ) }
				</a>
				${ chips }
			</li>`;
		} ).join( '' );
	}

	function renderTypes( postTypes ) {
		const container = $( '[data-types]' );
		const keys = Object.keys( postTypes || {} );
		if ( ! keys.length ) {
			container.innerHTML = `<div class="tagwright-empty"><p>${ esc( __( 'No published content indexed yet.', 'tagwright-seo' ) ) }</p></div>`;
			return;
		}

		container.innerHTML = `<table class="tagwright-table">
			<thead><tr>
				<th>${ esc( __( 'Type', 'tagwright-seo' ) ) }</th>
				<th class="is-num">${ esc( __( 'Published', 'tagwright-seo' ) ) }</th>
				<th class="is-num">${ esc( __( 'SEO', 'tagwright-seo' ) ) }</th>
				<th class="is-num">${ esc( __( 'Readability', 'tagwright-seo' ) ) }</th>
				<th class="is-num">${ esc( __( 'Hidden', 'tagwright-seo' ) ) }</th>
			</tr></thead>
			<tbody>${ keys.map( ( key ) => {
				const row = postTypes[ key ];
				return `<tr>
					<td><a href="${ esc( editUrl + '?post_type=' + encodeURIComponent( key ) ) }"><strong>${ esc( row.label ) }</strong></a></td>
					<td class="is-num">${ esc( row.count.toLocaleString() ) }</td>
					<td class="is-num">${ pill( row.avg_score ) }</td>
					<td class="is-num">${ pill( row.avg_readability ) }</td>
					<td class="is-num">${ row.noindex ? `<a href="${ esc( editUrl + '?post_type=' + encodeURIComponent( key ) + '&tagwright_filter=noindex' ) }">${ esc( String( row.noindex ) ) }</a>` : '<span class="tagwright-muted">0</span>' }</td>
				</tr>`;
			} ).join( '' ) }</tbody>
		</table>`;
	}

	/* ------------------------------------------------------------------
	 * Loading
	 * --------------------------------------------------------------- */

	function load() {
		root.setAttribute( 'aria-busy', 'true' );

		return TagwrightSEO.api( 'dashboard?days=' + days )
			.then( ( data ) => {
				renderHero( data.summary.totals );
				renderKpis( data );
				renderCharts( data );
				renderIssues( data.summary.totals, data.summary.post_types );
				renderTypes( data.summary.post_types );

				document.dispatchEvent( new CustomEvent( 'tagwright-seo:dashboard', { detail: data } ) );
			} )
			.catch( ( error ) => {
				TagwrightSEO.toast( ( error && error.message ) || __( 'Could not load the dashboard data.', 'tagwright-seo' ), 'error' );
			} )
			.finally( () => root.removeAttribute( 'aria-busy' ) );
	}

	root.querySelectorAll( 'input[name="tagwright_range"]' ).forEach( ( radio ) => {
		radio.addEventListener( 'change', () => {
			days = parseInt( radio.value, 10 );
			load();
		} );
	} );

	const refresh = $( '[data-refresh]' );
	refresh.addEventListener( 'click', () => {
		refresh.classList.add( 'is-busy' );
		TagwrightSEO.api( 'tools/snapshot', { method: 'POST' } )
			.then( load )
			.finally( () => refresh.classList.remove( 'is-busy' ) );
	} );

	// Let the site owner speed up the first index from here.
	const runIndex = $( '[data-run-index]' );
	if ( runIndex ) {
		runIndex.addEventListener( 'click', () => {
			runIndex.classList.add( 'is-busy' );
			const bar = root.querySelector( '[data-index-notice] .tagwright-progress span' );
			const step = () => TagwrightSEO.api( 'index/batch', { method: 'POST' } ).then( ( state ) => {
				bar.style.width = state.percent + '%';
				if ( 'running' === state.status ) {
					return step();
				}
				root.querySelector( '[data-index-notice]' ).remove();
				TagwrightSEO.toast( __( 'Indexing complete.', 'tagwright-seo' ) );
				return TagwrightSEO.api( 'tools/snapshot', { method: 'POST' } ).then( load );
			} );
			step().catch( () => runIndex.classList.remove( 'is-busy' ) );
		} );
	}

	load();
}( window, document ) );
