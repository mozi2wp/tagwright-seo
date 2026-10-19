/**
 * Tagwright SEO charts.
 *
 * Tiny SVG charts with hover tooltips, built for the dashboard and shared
 * with add-ons through TagwrightSEO.charts:
 *
 *   TagwrightSEO.charts.line( element, { series, format } )
 *   TagwrightSEO.charts.donut( element, { segments, center } )
 *   TagwrightSEO.charts.bars( element, { items, format } )
 *   TagwrightSEO.charts.sparkline( element, values, color )
 *
 * Series colors are assigned in a fixed order and follow the series, never
 * its rank, so a series keeps its color when others are hidden.
 */
( function ( window, document ) {
	'use strict';

	const TagwrightSEO = window.TagwrightSEO || ( window.TagwrightSEO = {} );
	const NS = 'http://www.w3.org/2000/svg';

	// Validated categorical order (light surface): indigo, teal, amber, pink.
	const PALETTE = [ '#4f46e5', '#14b8a6', '#f59e0b', '#ec4899' ];

	const STATUS = {
		good: '#10b981',
		ok: '#f59e0b',
		poor: '#ef4444',
		none: '#cbd5e1',
	};

	function el( name, attrs = {}, parent = null ) {
		const node = document.createElementNS( NS, name );
		Object.keys( attrs ).forEach( ( key ) => node.setAttribute( key, attrs[ key ] ) );
		if ( parent ) {
			parent.appendChild( node );
		}
		return node;
	}

	function escapeHtml( text ) {
		return TagwrightSEO.escapeHtml ? TagwrightSEO.escapeHtml( text ) : String( text );
	}

	function formatDate( iso ) {
		const date = new Date( iso + 'T00:00:00' );
		return date.toLocaleDateString( undefined, { month: 'short', day: 'numeric' } );
	}

	function niceMax( value ) {
		if ( value <= 0 ) {
			return 10;
		}
		const power = Math.pow( 10, Math.floor( Math.log10( value ) ) );
		const steps = [ 1, 2, 2.5, 5, 10 ];
		for ( const step of steps ) {
			if ( value <= step * power ) {
				return step * power;
			}
		}
		return 10 * power;
	}

	function tooltip( container ) {
		let tip = container.querySelector( '.tagwright-chart__tooltip' );
		if ( ! tip ) {
			tip = document.createElement( 'div' );
			tip.className = 'tagwright-chart__tooltip';
			tip.hidden = true;
			tip.setAttribute( 'role', 'status' );
			container.appendChild( tip );
		}
		return tip;
	}

	function empty( container, message ) {
		container.innerHTML = `<div class="tagwright-chart__empty">${ escapeHtml( message ) }</div>`;
	}

	function observeResize( container, draw ) {
		if ( container._tagwrightObserver || ! window.ResizeObserver ) {
			return;
		}
		let width = container.clientWidth;
		container._tagwrightObserver = new window.ResizeObserver( () => {
			if ( Math.abs( container.clientWidth - width ) > 8 ) {
				width = container.clientWidth;
				draw();
			}
		} );
		container._tagwrightObserver.observe( container );
	}

	/* ------------------------------------------------------------------
	 * Line chart with crosshair tooltip
	 * --------------------------------------------------------------- */

	function line( container, options ) {
		const draw = () => drawLine( container, options );
		draw();
		observeResize( container, draw );
	}

	function drawLine( container, options ) {
		const series = ( options.series || [] ).map( ( item, index ) => Object.assign( { color: PALETTE[ index % PALETTE.length ] }, item ) );
		const format = options.format || ( ( value ) => String( Math.round( value * 10 ) / 10 ) );
		const dates = series.length ? series[ 0 ].values.map( ( point ) => point.date ) : [];
		const hasData = series.some( ( item ) => item.values.some( ( point ) => null !== point.value ) );

		container.innerHTML = '';
		if ( ! hasData ) {
			empty( container, options.emptyText || 'No data yet.' );
			return;
		}

		const width = Math.max( 280, container.clientWidth || 600 );
		const height = options.height || 240;
		const pad = { top: 12, right: 12, bottom: 28, left: 40 };
		const innerW = width - pad.left - pad.right;
		const innerH = height - pad.top - pad.bottom;

		const all = series.flatMap( ( item ) => item.values.map( ( point ) => point.value ) ).filter( ( value ) => null !== value );
		const max = options.max || niceMax( Math.max( ...all ) * 1.1 );
		const x = ( i ) => pad.left + ( dates.length > 1 ? ( i / ( dates.length - 1 ) ) * innerW : innerW / 2 );
		const y = ( value ) => pad.top + innerH - ( value / max ) * innerH;

		const svg = el( 'svg', { viewBox: `0 0 ${ width } ${ height }`, role: 'img', 'aria-label': options.label || '' }, container );

		// Grid and y labels: four recessive lines.
		const grid = el( 'g', { class: 'tagwright-chart__grid' }, svg );
		for ( let i = 0; i <= 4; i++ ) {
			const value = ( max / 4 ) * i;
			el( 'line', { x1: pad.left, x2: width - pad.right, y1: y( value ), y2: y( value ) }, grid );
			const label = el( 'text', { x: pad.left - 8, y: y( value ) + 4, 'text-anchor': 'end' }, svg );
			label.textContent = format( value );
		}

		// X labels: first, middle and last only, so they never collide.
		[ 0, Math.floor( ( dates.length - 1 ) / 2 ), dates.length - 1 ].filter( ( v, i, arr ) => arr.indexOf( v ) === i ).forEach( ( index, i, arr ) => {
			const anchor = 0 === i ? 'start' : ( i === arr.length - 1 ? 'end' : 'middle' );
			const label = el( 'text', { x: x( index ), y: height - 8, 'text-anchor': anchor }, svg );
			label.textContent = formatDate( dates[ index ] );
		} );

		// Lines, with gaps where a day has no data.
		series.forEach( ( item ) => {
			let path = '';
			let area = '';
			let started = false;
			let segmentStart = 0;

			item.values.forEach( ( point, i ) => {
				if ( null === point.value ) {
					if ( started && item.area ) {
						area += `L${ x( i - 1 ) },${ y( 0 ) }L${ x( segmentStart ) },${ y( 0 ) }Z`;
					}
					started = false;
					return;
				}
				if ( ! started ) {
					segmentStart = i;
					area += `M${ x( i ) },${ y( point.value ) }`;
				} else {
					area += `L${ x( i ) },${ y( point.value ) }`;
				}
				path += ( started ? 'L' : 'M' ) + x( i ) + ',' + y( point.value );
				started = true;
			} );

			if ( started && item.area ) {
				area += `L${ x( item.values.length - 1 ) },${ y( 0 ) }L${ x( segmentStart ) },${ y( 0 ) }Z`;
			}

			if ( item.area ) {
				const gradientId = 'tagwright-grad-' + Math.random().toString( 36 ).slice( 2 );
				const defs = el( 'defs', {}, svg );
				const gradient = el( 'linearGradient', { id: gradientId, x1: 0, x2: 0, y1: 0, y2: 1 }, defs );
				el( 'stop', { offset: '0%', 'stop-color': item.color, 'stop-opacity': 0.22 }, gradient );
				el( 'stop', { offset: '100%', 'stop-color': item.color, 'stop-opacity': 0 }, gradient );
				el( 'path', { d: area, fill: `url(#${ gradientId })`, stroke: 'none' }, svg );
			}

			el( 'path', { d: path, fill: 'none', stroke: item.color, 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }, svg );

			// Single isolated points would be invisible as a path, so draw a dot.
			item.values.forEach( ( point, i ) => {
				const prev = item.values[ i - 1 ];
				const next = item.values[ i + 1 ];
				if ( null !== point.value && ( ! prev || null === prev.value ) && ( ! next || null === next.value ) ) {
					el( 'circle', { cx: x( i ), cy: y( point.value ), r: 4, fill: item.color, stroke: '#fff', 'stroke-width': 2 }, svg );
				}
			} );
		} );

		// Hover layer.
		const cross = el( 'line', { y1: pad.top, y2: pad.top + innerH, stroke: '#94a3b8', 'stroke-width': 1, visibility: 'hidden' }, svg );
		const dots = series.map( ( item ) => el( 'circle', { r: 5, fill: item.color, stroke: '#fff', 'stroke-width': 2, visibility: 'hidden' }, svg ) );
		const hit = el( 'rect', { x: pad.left, y: pad.top, width: innerW, height: innerH, fill: 'transparent' }, svg );
		const tip = tooltip( container );

		const move = ( event ) => {
			const rect = svg.getBoundingClientRect();
			const scale = width / rect.width;
			const px = ( event.clientX - rect.left ) * scale;
			const index = Math.max( 0, Math.min( dates.length - 1, Math.round( ( ( px - pad.left ) / innerW ) * ( dates.length - 1 ) ) ) );

			cross.setAttribute( 'x1', x( index ) );
			cross.setAttribute( 'x2', x( index ) );
			cross.setAttribute( 'visibility', 'visible' );

			let rows = '';
			let topY = innerH + pad.top;
			series.forEach( ( item, i ) => {
				const value = item.values[ index ].value;
				if ( null === value ) {
					dots[ i ].setAttribute( 'visibility', 'hidden' );
					return;
				}
				dots[ i ].setAttribute( 'cx', x( index ) );
				dots[ i ].setAttribute( 'cy', y( value ) );
				dots[ i ].setAttribute( 'visibility', 'visible' );
				topY = Math.min( topY, y( value ) );
				rows += `<div class="tagwright-chart__tooltip-row"><span class="tagwright-chart__swatch" style="background:${ item.color }"></span>${ escapeHtml( item.name ) }: <b>${ escapeHtml( format( value ) ) }</b></div>`;
			} );

			tip.innerHTML = `<strong>${ escapeHtml( formatDate( dates[ index ] ) ) }</strong>` + ( rows || escapeHtml( options.noValueText || '—' ) );
			tip.style.left = ( x( index ) / scale ) + 'px';
			tip.style.top = ( topY / scale ) + 'px';
			tip.hidden = false;
		};

		const leave = () => {
			tip.hidden = true;
			cross.setAttribute( 'visibility', 'hidden' );
			dots.forEach( ( dot ) => dot.setAttribute( 'visibility', 'hidden' ) );
		};

		hit.addEventListener( 'mousemove', move );
		hit.addEventListener( 'mouseleave', leave );

		// A legend whenever there is more than one series.
		if ( series.length > 1 ) {
			const legend = document.createElement( 'div' );
			legend.className = 'tagwright-chart__legend';
			legend.innerHTML = series.map( ( item ) => `<span><span class="tagwright-chart__swatch" style="background:${ item.color }"></span>${ escapeHtml( item.name ) }</span>` ).join( '' );
			container.appendChild( legend );
		}
	}

	/* ------------------------------------------------------------------
	 * Donut
	 * --------------------------------------------------------------- */

	function donut( container, options ) {
		const segments = ( options.segments || [] ).filter( ( segment ) => segment.value > 0 );
		const total = segments.reduce( ( sum, segment ) => sum + segment.value, 0 );
		const size = options.size || 180;
		const radius = size / 2 - 14;
		const stroke = 22;
		const circumference = 2 * Math.PI * radius;

		container.innerHTML = '';
		const wrap = document.createElement( 'div' );
		wrap.className = 'tagwright-donut-wrap';
		container.appendChild( wrap );

		const holder = document.createElement( 'div' );
		holder.style.position = 'relative';
		wrap.appendChild( holder );

		const svg = el( 'svg', { width: size, height: size, viewBox: `0 0 ${ size } ${ size }`, role: 'img', 'aria-label': options.label || '' }, holder );
		el( 'circle', { cx: size / 2, cy: size / 2, r: radius, fill: 'none', stroke: '#eef2f7', 'stroke-width': stroke }, svg );

		const tip = tooltip( holder );
		let offset = 0;
		const gap = segments.length > 1 ? 2 : 0;

		segments.forEach( ( segment ) => {
			const length = ( segment.value / total ) * circumference;
			const arc = el( 'circle', {
				cx: size / 2,
				cy: size / 2,
				r: radius,
				fill: 'none',
				stroke: segment.color,
				'stroke-width': stroke,
				'stroke-dasharray': `${ Math.max( 0, length - gap ) } ${ circumference }`,
				'stroke-dashoffset': -offset,
				transform: `rotate(-90 ${ size / 2 } ${ size / 2 })`,
				style: 'cursor:pointer;transition:stroke-width .15s',
			}, svg );
			offset += length;

			arc.addEventListener( 'mousemove', ( event ) => {
				const rect = holder.getBoundingClientRect();
				arc.setAttribute( 'stroke-width', stroke + 4 );
				tip.innerHTML = `<strong>${ escapeHtml( segment.label ) }</strong>${ escapeHtml( segment.value.toLocaleString() ) } · ${ Math.round( ( segment.value / total ) * 100 ) }%`;
				tip.style.left = event.clientX - rect.left + 'px';
				tip.style.top = event.clientY - rect.top + 'px';
				tip.hidden = false;
			} );
			arc.addEventListener( 'mouseleave', () => {
				arc.setAttribute( 'stroke-width', stroke );
				tip.hidden = true;
			} );
		} );

		const center = el( 'text', { x: size / 2, y: size / 2 - 2, 'text-anchor': 'middle', style: 'font-size:26px;font-weight:750;fill:#0f172a' }, svg );
		center.textContent = options.center !== undefined ? options.center : total.toLocaleString();
		const caption = el( 'text', { x: size / 2, y: size / 2 + 18, 'text-anchor': 'middle', style: 'font-size:11px;fill:#64748b' }, svg );
		caption.textContent = options.caption || '';

		const legend = document.createElement( 'ul' );
		legend.className = 'tagwright-donut-legend';
		legend.innerHTML = ( options.segments || [] ).map( ( segment ) =>
			`<li><span class="tagwright-chart__swatch" style="background:${ segment.color }"></span>${ escapeHtml( segment.label ) }<b>${ escapeHtml( segment.value.toLocaleString() ) }</b></li>`
		).join( '' );
		wrap.appendChild( legend );
	}

	/* ------------------------------------------------------------------
	 * Horizontal bars
	 * --------------------------------------------------------------- */

	function bars( container, options ) {
		const items = options.items || [];
		const max = options.max || Math.max( 1, ...items.map( ( item ) => item.value || 0 ) );
		const format = options.format || ( ( value ) => value.toLocaleString() );

		container.innerHTML = items.map( ( item ) => {
			const width = item.value ? Math.max( 2, ( item.value / max ) * 100 ) : 0;
			return `<div class="tagwright-bar" title="${ escapeHtml( item.label + ': ' + format( item.value ) ) }">
				<div class="tagwright-bar__label"><span>${ escapeHtml( item.label ) }</span><b>${ escapeHtml( format( item.value ) ) }</b></div>
				<div class="tagwright-bar__track"><span style="width:${ width }%;background:${ item.color || PALETTE[ 0 ] }"></span></div>
			</div>`;
		} ).join( '' );
	}

	/* ------------------------------------------------------------------
	 * Sparkline
	 * --------------------------------------------------------------- */

	function sparkline( container, values, color = PALETTE[ 0 ] ) {
		container.innerHTML = '';
		const points = values.map( ( value, index ) => ( { value, index } ) ).filter( ( point ) => null !== point.value );
		if ( points.length < 2 ) {
			return;
		}

		const width = 200;
		const height = 40;
		const min = Math.min( ...points.map( ( p ) => p.value ) );
		const max = Math.max( ...points.map( ( p ) => p.value ) );
		const range = max - min || 1;
		const x = ( i ) => ( i / ( values.length - 1 ) ) * width;
		const y = ( v ) => height - 4 - ( ( v - min ) / range ) * ( height - 8 );

		const svg = el( 'svg', { viewBox: `0 0 ${ width } ${ height }`, preserveAspectRatio: 'none', 'aria-hidden': 'true', width: '100%', height }, container );
		const d = points.map( ( p, i ) => ( i ? 'L' : 'M' ) + x( p.index ) + ',' + y( p.value ) ).join( '' );
		const gradientId = 'tagwright-spark-' + Math.random().toString( 36 ).slice( 2 );
		const defs = el( 'defs', {}, svg );
		const gradient = el( 'linearGradient', { id: gradientId, x1: 0, x2: 0, y1: 0, y2: 1 }, defs );
		el( 'stop', { offset: '0%', 'stop-color': color, 'stop-opacity': 0.25 }, gradient );
		el( 'stop', { offset: '100%', 'stop-color': color, 'stop-opacity': 0 }, gradient );
		el( 'path', { d: d + `L${ x( points[ points.length - 1 ].index ) },${ height }L${ x( points[ 0 ].index ) },${ height }Z`, fill: `url(#${ gradientId })` }, svg );
		el( 'path', { d, fill: 'none', stroke: color, 'stroke-width': 2, 'vector-effect': 'non-scaling-stroke', 'stroke-linejoin': 'round' }, svg );
	}

	TagwrightSEO.charts = { line, donut, bars, sparkline, PALETTE, STATUS };
}( window, document ) );
