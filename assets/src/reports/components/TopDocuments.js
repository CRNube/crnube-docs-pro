import { __ } from '@wordpress/i18n';
import { formatDateTime, formatNumber } from '../format';

/**
 * The most consulted documents. Each row has its numbers written out and a bar split
 * into downloads and views (with a 2px gap between the two parts).
 */
export default function TopDocuments( { rows, onSelect } ) {
	const max = Math.max( 1, ...rows.map( ( r ) => r.downloads + r.views ) );

	return (
		<section className="crnd-panel crnd-panel--wide">
			<h2 className="crnd-panel__title">
				{ __( 'Most consulted documents', 'crnube-docs-pro' ) }
			</h2>
			{ rows.length === 0 ? (
				<p className="crnd-panel__empty">
					{ __( 'No data in this period.', 'crnube-docs-pro' ) }
				</p>
			) : (
				<table className="crnd-top">
					<thead>
						<tr>
							<th>{ __( 'Document', 'crnube-docs-pro' ) }</th>
							<th className="crnd-num">
								{ __( 'Downloads', 'crnube-docs-pro' ) }
							</th>
							<th className="crnd-num">
								{ __( 'Views', 'crnube-docs-pro' ) }
							</th>
							<th className="crnd-num">
								{ __( 'Visitors', 'crnube-docs-pro' ) }
							</th>
							<th>
								{ __( 'Last activity', 'crnube-docs-pro' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( r ) => (
							<tr key={ r.id }>
								<td>
									<button
										type="button"
										className="crnd-link"
										title={ __(
											'See only this document',
											'crnube-docs-pro'
										) }
										onClick={ () =>
											onSelect( r.id, r.title )
										}
									>
										{ r.title }
									</button>
									<span
										className="crnd-split"
										aria-hidden="true"
									>
										<span
											className="crnd-split__downloads"
											style={ {
												width: `${
													( r.downloads / max ) * 100
												}%`,
											} }
										/>
										<span
											className="crnd-split__views"
											style={ {
												width: `${
													( r.views / max ) * 100
												}%`,
											} }
										/>
									</span>
								</td>
								<td className="crnd-num">
									{ formatNumber( r.downloads ) }
								</td>
								<td className="crnd-num">
									{ formatNumber( r.views ) }
								</td>
								<td className="crnd-num">
									{ formatNumber( r.visitors ) }
								</td>
								<td className="crnd-nowrap crnd-muted">
									{ formatDateTime( r.last ) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</section>
	);
}
