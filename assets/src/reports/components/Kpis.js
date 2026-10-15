import { __, sprintf } from '@wordpress/i18n';
import { formatNumber } from '../format';

function Delta( { now, before } ) {
	if ( ! before ) {
		return now ? (
			<span className="crnd-kpi__delta is-neutral">
				{ __( 'New in this period', 'crnube-docs-pro' ) }
			</span>
		) : null;
	}
	const change = Math.round( ( ( now - before ) / before ) * 100 );
	if ( change === 0 ) {
		return (
			<span className="crnd-kpi__delta is-neutral">
				{ __( 'Same as the previous period', 'crnube-docs-pro' ) }
			</span>
		);
	}
	const up = change > 0;
	return (
		<span className={ `crnd-kpi__delta ${ up ? 'is-up' : 'is-down' }` }>
			<span aria-hidden="true">{ up ? '▲' : '▼' }</span>{ ' ' }
			{ sprintf(
				/* translators: %s: percentage, e.g. 12% */
				__( '%s vs. previous period', 'crnube-docs-pro' ),
				`${ Math.abs( change ) }%`
			) }
		</span>
	);
}

/** The headline numbers of the period, each compared with the period before. */
export default function Kpis( { totals, previous } ) {
	const tiles = [
		[ 'downloads', __( 'Downloads', 'crnube-docs-pro' ) ],
		[ 'views', __( 'Views', 'crnube-docs-pro' ) ],
		[ 'visitors', __( 'Unique visitors', 'crnube-docs-pro' ) ],
		[ 'documents', __( 'Documents with activity', 'crnube-docs-pro' ) ],
	];
	return (
		<div className="crnd-kpis">
			{ tiles.map( ( [ key, label ] ) => (
				<div key={ key } className={ `crnd-kpi crnd-kpi--${ key }` }>
					<span className="crnd-kpi__label">{ label }</span>
					<span className="crnd-kpi__value">
						{ formatNumber( totals[ key ] ) }
					</span>
					<Delta now={ totals[ key ] } before={ previous[ key ] } />
				</div>
			) ) }
		</div>
	);
}
