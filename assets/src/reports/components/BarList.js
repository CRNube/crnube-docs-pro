import { __ } from '@wordpress/i18n';
import { formatNumber, formatPercent } from '../format';

/**
 * A ranked list with one horizontal bar per row: the label, the count and its share
 * are always written out, so the bar only reinforces numbers that are already there.
 */
export default function BarList( { title, rows, empty } ) {
	const total = rows.reduce( ( sum, row ) => sum + row.value, 0 );
	const max = Math.max( 1, ...rows.map( ( row ) => row.value ) );

	return (
		<section className="crnd-panel">
			<h2 className="crnd-panel__title">{ title }</h2>
			{ rows.length === 0 ? (
				<p className="crnd-panel__empty">
					{ empty ||
						__( 'No data in this period.', 'crnube-docs-pro' ) }
				</p>
			) : (
				<ul className="crnd-bars">
					{ rows.map( ( row ) => (
						<li
							key={ row.key }
							className="crnd-bars__row"
							title={ `${ row.label }: ${ formatNumber(
								row.value
							) } (${ formatPercent( row.value, total ) })` }
						>
							<span className="crnd-bars__label">
								{ row.label }
							</span>
							<span className="crnd-bars__value">
								{ formatNumber( row.value ) }
								<small>
									{ formatPercent( row.value, total ) }
								</small>
							</span>
							<span
								className="crnd-bars__track"
								aria-hidden="true"
							>
								<span
									className="crnd-bars__fill"
									style={ {
										width: `${ Math.max(
											2,
											( row.value / max ) * 100
										) }%`,
									} }
								/>
							</span>
						</li>
					) ) }
				</ul>
			) }
		</section>
	);
}
