import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	CheckboxControl,
	Dropdown,
	RadioControl,
} from '@wordpress/components';
/** Settings of the library page (the groups come from CRNube Docs Pro). */
const config = window.crndLibrary || {};

const groupNames = ( ids ) =>
	( config.groups || [] )
		.filter( ( g ) => ids.includes( g.id ) )
		.map( ( g ) => g.name );

/** "Public" or "🔒 Junta Directiva", for a document's or category's effective access. */
export function accessLabel( restricted, groups ) {
	if ( ! restricted ) {
		return __( 'Public', 'crnube-docs-pro' );
	}
	const names = groupNames( groups );
	return names.length
		? names.join( ', ' )
		: __( 'Members', 'crnube-docs-pro' );
}

/**
 * The form to choose who may open something: inherit / public / private for groups.
 * `inheritLabel` names the inheriting option ("Same as its category", "Same as the parent").
 */
export function AccessForm( { mode, groups, inheritLabel, onSave, onCancel } ) {
	const [ value, setValue ] = useState( mode );
	const [ checked, setChecked ] = useState( () => new Set( groups ) );
	const all = config.groups || [];

	return (
		<div className="crnd-accesspicker">
			<RadioControl
				label={ __( 'Who can open it', 'crnube-docs-pro' ) }
				selected={ value }
				options={ [
					{ value: 'inherit', label: inheritLabel },
					{
						value: 'public',
						label: __( 'Everyone (public)', 'crnube-docs-pro' ),
					},
					{
						value: 'private',
						label: __( 'Only members', 'crnube-docs-pro' ),
					},
				] }
				onChange={ setValue }
			/>
			{ value === 'private' && (
				<div className="crnd-accesspicker__groups">
					<p>
						{ __(
							'Of these groups (none checked: any member)',
							'crnube-docs-pro'
						) }
					</p>
					{ all.length === 0 && (
						<p className="crnd-muted">
							{ __(
								'There are no groups yet.',
								'crnube-docs-pro'
							) }{ ' ' }
							{ config.accessUrl && (
								<a href={ config.accessUrl }>
									{ __( 'Create one', 'crnube-docs-pro' ) }
								</a>
							) }
						</p>
					) }
					{ all.map( ( g ) => (
						<CheckboxControl
							key={ g.id }
							__nextHasNoMarginBottom
							label={ g.name }
							checked={ checked.has( g.id ) }
							onChange={ ( on ) =>
								setChecked( ( set ) => {
									const next = new Set( set );
									if ( on ) {
										next.add( g.id );
									} else {
										next.delete( g.id );
									}
									return next;
								} )
							}
						/>
					) ) }
				</div>
			) }
			<p className="crnd-accesspicker__note">
				{ value === 'private'
					? __(
							'The file moves to the protected folder; its direct link stops working.',
							'crnube-docs-pro'
					  )
					: '' }
			</p>
			<div className="crnd-catpicker__actions">
				<Button variant="tertiary" onClick={ onCancel }>
					{ __( 'Cancel', 'crnube-docs-pro' ) }
				</Button>
				<Button
					variant="primary"
					onClick={ () => onSave( value, [ ...checked ] ) }
				>
					{ __( 'Apply', 'crnube-docs-pro' ) }
				</Button>
			</div>
		</div>
	);
}

/** The access cell of a document row. */
export default function AccessPicker( { doc, onSave } ) {
	const label = accessLabel( doc.restricted, doc.groups );
	const badge = (
		<span
			className={ `crnd-access-badge ${
				doc.restricted ? 'is-private' : ''
			}` }
		>
			<span
				className={ `dashicons dashicons-${
					doc.restricted ? 'lock' : 'unlock'
				}` }
				aria-hidden="true"
			/>
			{ label }
			{ doc.access === 'inherit' && doc.restricted && (
				<span
					className="crnd-access-badge__inherit"
					title={ __( 'Set by its category', 'crnube-docs-pro' ) }
				>
					*
				</span>
			) }
		</span>
	);

	if ( ! config.canManageAccess ) {
		return badge;
	}

	return (
		<Dropdown
			popoverProps={ { placement: 'bottom-start' } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<button
					type="button"
					className="crnd-access-toggle"
					aria-expanded={ isOpen }
					onClick={ onToggle }
					title={ sprintf(
						/* translators: %s: current access, e.g. Public */
						__( 'Access: %s. Click to change.', 'crnube-docs-pro' ),
						label
					) }
				>
					{ badge }
				</button>
			) }
			renderContent={ ( { onClose } ) => (
				<AccessForm
					mode={ doc.access }
					groups={ doc.access_groups }
					inheritLabel={ __(
						'Same as its category',
						'crnube-docs-pro'
					) }
					onCancel={ onClose }
					onSave={ ( access, groups ) => {
						onClose();
						onSave( doc.id, { access, access_groups: groups } );
					} }
				/>
			) }
		/>
	);
}
