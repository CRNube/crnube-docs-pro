import { useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { formatBucket, formatNumber } from '../format';

const W = 760;
const H = 240;
const PAD = { top: 16, right: 16, bottom: 28, left: 44 };

/** A "nice" axis maximum and step for a value (1, 2, 5 × 10^n). */
function niceScale( max ) {
	if ( max <= 4 ) {
		return { top: 4, step: 1 };
	}
	const rough = max / 4;
	const power = 10 ** Math.floor( Math.log10( rough ) );
	const step =
		[ 1, 2, 5, 10 ].map( ( k ) => k * power ).find( ( s ) => s >= rough ) ||
		10 * power;
	return { top: Math.ceil( max / step ) * step, step };
}

/** Last values of both lines, pushed apart when they would overlap. */
function endLabels( geometry, last ) {
	let down = geometry.y( last.downloads ) - 8;
	let view = geometry.y( last.views ) - 8;
	if ( Math.abs( down - view ) < 14 ) {
		const upper = Math.min( down, view );
		if ( last.downloads >= last.views ) {
			down = upper;
			view = upper + 14;
		} else {
			view = upper;
			down = upper + 14;
		}
	}
	return [
		{
			key: 'downloads',
			y: Math.max( 12, down ),
			text: `${ formatNumber( last.downloads ) } ${ __(
				'downloads',
				'crnube-docs-pro'
			) }`,
		},
		{
			key: 'views',
			y: Math.max( 12, view ),
			text: `${ formatNumber( last.views ) } ${ __(
				'views',
				'crnube-docs-pro'
			) }`,
		},
	];
}

/**
 * Downloads and views over time: two thin lines on one axis, a crosshair and tooltip
 * on hover, a legend, and the data as a table for screen readers and printing.
 */
export default function TrendChart( { series } ) {
	const { points, granularity } = series;
	const [ hover, setHover ] = useState( null );
	const svg = useRef();

	const geometry = useMemo( () => {
		const max = Math.max(
			1,
			...points.map( ( p ) => Math.max( p.downloads, p.views ) )
		);
		const { top, step } = niceScale( max );
		const innerW = W - PAD.left - PAD.right;
		const innerH = H - PAD.top - PAD.bottom;
		const x = ( i ) =>
			PAD.left +
			( points.length > 1
				? ( i / ( points.length - 1 ) ) * innerW
				: innerW / 2 );
		const y = ( v ) => PAD.top + innerH - ( v / top ) * innerH;
		const path = ( key ) =>
			points
				.map(
					( p, i ) =>
						`${ i ? 'L' : 'M' }${ x( i ).toFixed( 1 ) },${ y(
							p[ key ]
						).toFixed( 1 ) }`
				)
				.join( '' );
		const ticks = [];
		for ( let v = 0; v <= top; v += step ) {
			ticks.push( v );
		}
		// Around 8 labels on the time axis, whatever the number of points.
		const every = Math.max( 1, Math.ceil( points.length / 8 ) );
		return { x, y, path, ticks, every, innerW };
	}, [ points ] );

	const onMove = ( event ) => {
		const rect = svg.current.getBoundingClientRect();
		const px = ( ( event.clientX - rect.left ) / rect.width ) * W;
		const i = Math.round(
			( ( px - PAD.left ) / geometry.innerW ) * ( points.length - 1 )
		);
		setHover( Math.max( 0, Math.min( points.length - 1, i ) ) );
	};

	const point = hover !== null ? points[ hover ] : null;
	const last = points[ points.length - 1 ];

	return (
		<figure className="crnd-trend">
			<div className="crnd-legend" aria-hidden="true">
				<span className="crnd-legend__item">
					<span className="crnd-swatch crnd-swatch--downloads" />
					{ __( 'Downloads', 'crnube-docs-pro' ) }
				</span>
				<span className="crnd-legend__item">
					<span className="crnd-swatch crnd-swatch--views" />
					{ __( 'Views', 'crnube-docs-pro' ) }
				</span>
			</div>
			<div className="crnd-trend__plot">
				<svg
					ref={ svg }
					viewBox={ `0 0 ${ W } ${ H }` }
					role="img"
					aria-label={ __(
						'Downloads and views over time',
						'crnube-docs-pro'
					) }
					onMouseMove={ onMove }
					onMouseLeave={ () => setHover( null ) }
				>
					{ geometry.ticks.map( ( t ) => (
						<g key={ t }>
							<line
								className="crnd-grid"
								x1={ PAD.left }
								x2={ W - PAD.right }
								y1={ geometry.y( t ) }
								y2={ geometry.y( t ) }
							/>
							<text
								className="crnd-axis"
								x={ PAD.left - 8 }
								y={ geometry.y( t ) + 4 }
								textAnchor="end"
							>
								{ formatNumber( t ) }
							</text>
						</g>
					) ) }
					{ points.map( ( p, i ) =>
						i % geometry.every === 0 ? (
							<text
								key={ p.date }
								className="crnd-axis"
								x={ geometry.x( i ) }
								y={ H - 8 }
								textAnchor="middle"
							>
								{ formatBucket( p.date, granularity ) }
							</text>
						) : null
					) }
					<path
						className="crnd-line crnd-line--views"
						d={ geometry.path( 'views' ) }
					/>
					<path
						className="crnd-line crnd-line--downloads"
						d={ geometry.path( 'downloads' ) }
					/>
					{ point && (
						<g>
							<line
								className="crnd-crosshair"
								x1={ geometry.x( hover ) }
								x2={ geometry.x( hover ) }
								y1={ PAD.top }
								y2={ H - PAD.bottom }
							/>
							<circle
								className="crnd-dot crnd-dot--views"
								cx={ geometry.x( hover ) }
								cy={ geometry.y( point.views ) }
								r="4.5"
							/>
							<circle
								className="crnd-dot crnd-dot--downloads"
								cx={ geometry.x( hover ) }
								cy={ geometry.y( point.downloads ) }
								r="4.5"
							/>
						</g>
					) }
					{ /* Direct labels at the end of each line. */ }
					{ last && ! point && (
						<g className="crnd-endlabels">
							{ endLabels( geometry, last, points.length ).map(
								( label ) => (
									<text
										key={ label.key }
										x={
											geometry.x( points.length - 1 ) - 6
										}
										y={ label.y }
										textAnchor="end"
									>
										{ label.text }
									</text>
								)
							) }
						</g>
					) }
				</svg>
				{ point && (
					<div
						className="crnd-tooltip"
						style={ {
							left: `${ ( geometry.x( hover ) / W ) * 100 }%`,
						} }
					>
						<strong>
							{ formatBucket( point.date, granularity, true ) }
						</strong>
						<span>
							<span className="crnd-swatch crnd-swatch--downloads" />
							{ __( 'Downloads', 'crnube-docs-pro' ) }:{ ' ' }
							<b>{ formatNumber( point.downloads ) }</b>
						</span>
						<span>
							<span className="crnd-swatch crnd-swatch--views" />
							{ __( 'Views', 'crnube-docs-pro' ) }:{ ' ' }
							<b>{ formatNumber( point.views ) }</b>
						</span>
					</div>
				) }
			</div>
			<details className="crnd-datatable">
				<summary>
					{ __( 'See the data as a table', 'crnube-docs-pro' ) }
				</summary>
				<table>
					<thead>
						<tr>
							<th>{ __( 'Date', 'crnube-docs-pro' ) }</th>
							<th>{ __( 'Downloads', 'crnube-docs-pro' ) }</th>
							<th>{ __( 'Views', 'crnube-docs-pro' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ points.map( ( p ) => (
							<tr key={ p.date }>
								<td>
									{ formatBucket(
										p.date,
										granularity,
										true
									) }
								</td>
								<td>{ formatNumber( p.downloads ) }</td>
								<td>{ formatNumber( p.views ) }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</details>
		</figure>
	);
}
