import { __ } from '@wordpress/i18n';

export const config = window.crndReports || {};

const locale = config.locale || 'es-CR';

const numberFormat = new Intl.NumberFormat( locale );
export const formatNumber = ( n ) => numberFormat.format( n || 0 );

export const formatPercent = ( part, total ) =>
	total
		? new Intl.NumberFormat( locale, {
				style: 'percent',
				maximumFractionDigits: part / total < 0.1 ? 1 : 0,
		  } ).format( part / total )
		: '';

/** "2026-10-05" (or "2026-10") as a short local label. */
export function formatBucket( key, granularity, long = false ) {
	const [ y, m, d ] = key.split( '-' ).map( Number );
	const date = new Date( y, m - 1, d || 1 );
	if ( granularity === 'month' ) {
		return new Intl.DateTimeFormat( locale, {
			month: long ? 'long' : 'short',
			year: long ? 'numeric' : '2-digit',
		} ).format( date );
	}
	return new Intl.DateTimeFormat( locale, {
		day: 'numeric',
		month: 'short',
		...( long ? { weekday: 'short', year: 'numeric' } : {} ),
	} ).format( date );
}

/** "2026-10-05 14:32:10" as a local date and time. */
export function formatDateTime( value ) {
	if ( ! value ) {
		return '';
	}
	const [ date, time ] = value.split( ' ' );
	const [ y, m, d ] = date.split( '-' ).map( Number );
	const [ hh, mm ] = ( time || '0:0' ).split( ':' ).map( Number );
	return new Intl.DateTimeFormat( locale, {
		day: 'numeric',
		month: 'short',
		year: 'numeric',
		hour: '2-digit',
		minute: '2-digit',
	} ).format( new Date( y, m - 1, d, hh, mm ) );
}

let regionNames = null;
try {
	regionNames = new Intl.DisplayNames( [ locale ], { type: 'region' } );
} catch {
	regionNames = null;
}

/** Human name of a value of a dimension (device, browser, os, country). */
export function dimensionLabel( dimension, value ) {
	if ( ! value ) {
		return __( 'Unknown', 'crnube-docs-pro' );
	}
	if ( dimension === 'country' ) {
		try {
			return regionNames?.of( value ) || value;
		} catch {
			return value;
		}
	}
	const names = {
		device: {
			desktop: __( 'Computer', 'crnube-docs-pro' ),
			mobile: __( 'Phone', 'crnube-docs-pro' ),
			tablet: __( 'Tablet', 'crnube-docs-pro' ),
		},
		browser: {
			chrome: 'Chrome',
			safari: 'Safari',
			edge: 'Edge',
			firefox: 'Firefox',
			opera: 'Opera',
			samsung: 'Samsung Internet',
			other: __( 'Other', 'crnube-docs-pro' ),
		},
		os: {
			windows: 'Windows',
			macos: 'macOS',
			ios: 'iOS',
			android: 'Android',
			linux: 'Linux',
			chromeos: 'ChromeOS',
			other: __( 'Other', 'crnube-docs-pro' ),
		},
	};
	return names[ dimension ]?.[ value ] || value;
}

/** Local "Y-m-d" of a Date. */
export const isoDate = ( date ) =>
	`${ date.getFullYear() }-${ String( date.getMonth() + 1 ).padStart(
		2,
		'0'
	) }-${ String( date.getDate() ).padStart( 2, '0' ) }`;

/** The period of a preset, ending today. */
export function presetRange( preset ) {
	const [ y, m, d ] = ( config.today || isoDate( new Date() ) )
		.split( '-' )
		.map( Number );
	const today = new Date( y, m - 1, d );
	const from = new Date( today );
	switch ( preset ) {
		case '7':
			from.setDate( from.getDate() - 6 );
			break;
		case '90':
			from.setDate( from.getDate() - 89 );
			break;
		case '365':
			from.setFullYear( from.getFullYear() - 1 );
			from.setDate( from.getDate() + 1 );
			break;
		case 'year':
			from.setMonth( 0, 1 );
			break;
		default:
			from.setDate( from.getDate() - 29 );
	}
	return { from: isoDate( from ), to: isoDate( today ) };
}
