import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { formatNumber } from '../format';

/** Activity by hour of the day (site time): 24 thin columns with a tooltip each. */
export default function HoursChart( { hours } ) {
	const [ hover, setHover ] = useState( null );
	const max = Math.max( 1, ...hours );
	const busiest = hours.indexOf( Math.max( ...hours ) );
	const total = hours.reduce( ( a, b ) => a + b, 0 );

	return (
		<section className="crnd-panel">
			<h2 className="crnd-panel__title">
				{ __( 'Time of day', 'crnube-docs-pro' ) }
			</h2>
			{ total === 0 ? (
				<p className="crnd-panel__empty">
					{ __( 'No data in this period.', 'crnube-docs-pro' ) }
				</p>
			) : (
				<>
					<p className="crnd-panel__hint">
						{ sprintf(
							/* translators: %s: hour range, e.g. 10:00–11:00 */
							__( 'Busiest hour: %s', 'crnube-docs-pro' ),
							`${ busiest }:00–${ busiest + 1 }:00`
						) }
					</p>
					<div
						className="crnd-hours"
						role="img"
						aria-label={ __(
							'Views and downloads by hour of the day',
							'crnube-docs-pro'
						) }
						onMouseLeave={ () => setHover( null ) }
					>
						{ hours.map( ( n, h ) => (
							<div
								key={ h }
								className={ `crnd-hours__col ${
									hover === h ? 'is-hover' : ''
								}` }
								onMouseEnter={ () => setHover( h ) }
							>
								<span
									className="crnd-hours__bar"
									style={ {
										height: `${
											n
												? Math.max(
														3,
														( n / max ) * 100
												  )
												: 0
										}%`,
									} }
								/>
								{ hover === h && (
									<span className="crnd-tooltip crnd-tooltip--small">
										<strong>{ `${ h }:00–${
											h + 1
										}:00` }</strong>
										<span>{ formatNumber( n ) }</span>
									</span>
								) }
							</div>
						) ) }
					</div>
					<div className="crnd-hours__axis" aria-hidden="true">
						<span>0 h</span>
						<span>6 h</span>
						<span>12 h</span>
						<span>18 h</span>
						<span>23 h</span>
					</div>
				</>
			) }
		</section>
	);
}
