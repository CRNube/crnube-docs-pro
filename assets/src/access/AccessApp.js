import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	CheckboxControl,
	FormFileUpload,
	Modal,
	Notice,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { parseMembersCsv } from './csv';

const BASE = '/crnd/v1/access';
const config = window.crndAccess || {};

const message = ( e ) =>
	e?.message ||
	__( 'Something went wrong. Please try again.', 'crnube-docs-pro' );

const formatDate = ( iso ) => {
	if ( ! iso ) {
		return '';
	}
	const [ y, m, d ] = iso.split( '-' ).map( Number );
	return new Intl.DateTimeFormat( undefined, {
		day: 'numeric',
		month: 'short',
		year: 'numeric',
	} ).format( new Date( y, m - 1, d ) );
};

function StorageNotice( { storage, onCheck, checking } ) {
	if ( storage?.protected === true ) {
		return (
			<Notice status="success" isDismissible={ false }>
				{ __(
					'Private files are protected: the server refuses direct links to them.',
					'crnube-docs-pro'
				) }
			</Notice>
		);
	}
	return (
		<Notice
			status={ storage?.protected === false ? 'error' : 'warning' }
			isDismissible={ false }
		>
			<p>
				{ storage?.protected === false
					? __(
							'Warning: this server lets anyone open private files by their link. It ignores .htaccess (common with Nginx). Ask the hosting to deny access to the folder uploads/crnd-private-*.',
							'crnube-docs-pro'
					  )
					: __(
							'The protection of private files could not be checked from the server.',
							'crnube-docs-pro'
					  ) }
			</p>
			{ storage?.protected === false && (
				<pre className="crnd-access-code">
					{
						'location ~* /wp-content/uploads/crnd-private- { deny all; }'
					}
				</pre>
			) }
			<Button
				variant="secondary"
				size="small"
				onClick={ onCheck }
				isBusy={ checking }
			>
				{ __( 'Check again', 'crnube-docs-pro' ) }
			</Button>
		</Notice>
	);
}

function GroupModal( { group, onSave, onClose } ) {
	const [ name, setName ] = useState( group?.name || '' );
	const [ description, setDescription ] = useState(
		group?.description || ''
	);
	return (
		<Modal
			title={
				group
					? __( 'Edit group', 'crnube-docs-pro' )
					: __( 'New group', 'crnube-docs-pro' )
			}
			onRequestClose={ onClose }
			size="small"
		>
			<form
				className="crnd-modal-form"
				onSubmit={ ( e ) => {
					e.preventDefault();
					if ( name.trim() ) {
						onSave( { name: name.trim(), description } );
					}
				} }
			>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Name', 'crnube-docs-pro' ) }
					placeholder={ __(
						'e.g. Board of directors',
						'crnube-docs-pro'
					) }
					value={ name }
					onChange={ setName }
				/>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __(
						'Description (only for the admin)',
						'crnube-docs-pro'
					) }
					value={ description }
					onChange={ setDescription }
					rows={ 2 }
				/>
				<div className="crnd-modal-actions">
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Cancel', 'crnube-docs-pro' ) }
					</Button>
					<Button
						variant="primary"
						type="submit"
						disabled={ ! name.trim() }
					>
						{ group
							? __( 'Save', 'crnube-docs-pro' )
							: __( 'Create', 'crnube-docs-pro' ) }
					</Button>
				</div>
			</form>
		</Modal>
	);
}

function AddMembers( { onAdd, busy } ) {
	const [ email, setEmail ] = useState( '' );
	const [ name, setName ] = useState( '' );
	const [ expires, setExpires ] = useState( '' );
	const [ notify, setNotify ] = useState( true );
	const [ csv, setCsv ] = useState( null );

	const submit = ( e ) => {
		e.preventDefault();
		if ( email.trim() ) {
			onAdd(
				[ { email: email.trim(), name: name.trim(), expires } ],
				notify
			).then( ( ok ) => {
				if ( ok ) {
					setEmail( '' );
					setName( '' );
				}
			} );
		}
	};

	return (
		<section className="crnd-access-panel">
			<h3>{ __( 'Add people', 'crnube-docs-pro' ) }</h3>
			<form className="crnd-access-add" onSubmit={ submit }>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="email"
					label={ __( 'Email', 'crnube-docs-pro' ) }
					value={ email }
					onChange={ setEmail }
					required
				/>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Name', 'crnube-docs-pro' ) }
					value={ name }
					onChange={ setName }
				/>
				<div className="crnd-access-date">
					<label htmlFor="crnd-expires">
						{ __( 'Access until', 'crnube-docs-pro' ) }
					</label>
					<input
						id="crnd-expires"
						type="date"
						min={ config.today }
						value={ expires }
						onChange={ ( e ) => setExpires( e.target.value ) }
					/>
					<small>
						{ __( 'Empty: no expiry', 'crnube-docs-pro' ) }
					</small>
				</div>
				<Button
					variant="primary"
					type="submit"
					isBusy={ busy }
					disabled={ busy }
					__next40pxDefaultSize
				>
					{ __( 'Add', 'crnube-docs-pro' ) }
				</Button>
			</form>
			<div className="crnd-access-add__footer">
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __(
						'Email them that they have access, with the link to sign in',
						'crnube-docs-pro'
					) }
					checked={ notify }
					onChange={ setNotify }
				/>
				<FormFileUpload
					accept=".csv,text/csv"
					onChange={ async ( e ) => {
						const file = e.target.files[ 0 ];
						e.target.value = '';
						if ( file ) {
							setCsv( {
								name: file.name,
								...parseMembersCsv( await file.text() ),
							} );
						}
					} }
					render={ ( { openFileDialog } ) => (
						<Button
							variant="secondary"
							icon="media-spreadsheet"
							onClick={ openFileDialog }
						>
							{ __( 'Import CSV…', 'crnube-docs-pro' ) }
						</Button>
					) }
				/>
			</div>

			{ csv && (
				<Modal
					title={ __( 'Import members', 'crnube-docs-pro' ) }
					onRequestClose={ () => setCsv( null ) }
				>
					<p>
						{ sprintf(
							/* translators: 1: number of people, 2: file name */
							_n(
								'%1$d person found in %2$s.',
								'%1$d people found in %2$s.',
								csv.rows.length,
								'crnube-docs-pro'
							),
							csv.rows.length,
							csv.name
						) }
					</p>
					{ csv.rows.length > config.importMax && (
						<Notice status="warning" isDismissible={ false }>
							{ sprintf(
								/* translators: %d: maximum rows */
								__(
									'Only the first %d rows will be imported. Split the file to import the rest.',
									'crnube-docs-pro'
								),
								config.importMax
							) }
						</Notice>
					) }
					{ csv.errors.length > 0 && (
						<Notice status="warning" isDismissible={ false }>
							<p>
								{ __(
									'These rows were skipped (invalid email or date):',
									'crnube-docs-pro'
								) }
							</p>
							<p className="crnd-access-errors">
								{ csv.errors.slice( 0, 20 ).join( ', ' ) }
							</p>
						</Notice>
					) }
					{ csv.rows.length > 0 && (
						<table className="crnd-access-preview">
							<thead>
								<tr>
									<th>{ __( 'Name', 'crnube-docs-pro' ) }</th>
									<th>
										{ __( 'Email', 'crnube-docs-pro' ) }
									</th>
									<th>
										{ __(
											'Access until',
											'crnube-docs-pro'
										) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ csv.rows.slice( 0, 8 ).map( ( r ) => (
									<tr key={ r.email }>
										<td>{ r.name || '—' }</td>
										<td>{ r.email }</td>
										<td>
											{ r.expires
												? formatDate( r.expires )
												: __(
														'No expiry',
														'crnube-docs-pro'
												  ) }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
					<p className="description">
						{ __(
							'Columns: name, email and expiry (YYYY-MM-DD or DD/MM/YYYY), separated by ; or ,',
							'crnube-docs-pro'
						) }
					</p>
					<div className="crnd-modal-actions">
						<Button
							variant="tertiary"
							onClick={ () => setCsv( null ) }
						>
							{ __( 'Cancel', 'crnube-docs-pro' ) }
						</Button>
						<Button
							variant="primary"
							disabled={ csv.rows.length === 0 || busy }
							isBusy={ busy }
							onClick={ () =>
								onAdd(
									csv.rows.slice( 0, config.importMax ),
									notify
								).then( ( ok ) => ok && setCsv( null ) )
							}
						>
							{ __( 'Import', 'crnube-docs-pro' ) }
						</Button>
					</div>
				</Modal>
			) }
		</section>
	);
}

function Members( { members, loading, onExpires, onRemove } ) {
	if ( loading ) {
		return <Spinner />;
	}
	if ( members.length === 0 ) {
		return (
			<p className="crnd-access-empty">
				{ __(
					'Nobody in this group yet. Add people above or import a CSV.',
					'crnube-docs-pro'
				) }
			</p>
		);
	}
	return (
		<table className="crnd-access-members">
			<thead>
				<tr>
					<th>{ __( 'Person', 'crnube-docs-pro' ) }</th>
					<th>{ __( 'Access until', 'crnube-docs-pro' ) }</th>
					<th>{ __( 'Status', 'crnube-docs-pro' ) }</th>
					<th>{ __( 'Last sign-in', 'crnube-docs-pro' ) }</th>
					<th>
						<span className="screen-reader-text">
							{ __( 'Actions', 'crnube-docs-pro' ) }
						</span>
					</th>
				</tr>
			</thead>
			<tbody>
				{ members.map( ( m ) => (
					<tr
						key={ m.user_id }
						className={ m.active ? '' : 'is-expired' }
					>
						<td>
							<strong>{ m.name }</strong>
							<span className="crnd-access-email">
								{ m.email }
							</span>
						</td>
						<td>
							<input
								type="date"
								aria-label={ sprintf(
									/* translators: %s: person's name */
									__(
										'Access until for %s',
										'crnube-docs-pro'
									),
									m.name
								) }
								defaultValue={ m.expires_at }
								onBlur={ ( e ) =>
									e.target.value !== m.expires_at &&
									onExpires( m, e.target.value )
								}
							/>
						</td>
						<td>
							<span
								className={ `crnd-access-status ${
									m.active ? 'is-active' : 'is-expired'
								}` }
							>
								{ m.active
									? __( 'Active', 'crnube-docs-pro' )
									: __( 'Expired', 'crnube-docs-pro' ) }
							</span>
						</td>
						<td className="crnd-access-muted">
							{ m.last_login || __( 'Never', 'crnube-docs-pro' ) }
						</td>
						<td>
							<Button
								size="small"
								icon="no-alt"
								isDestructive
								label={ sprintf(
									/* translators: %s: person's name */
									__(
										'Remove %s from the group',
										'crnube-docs-pro'
									),
									m.name
								) }
								onClick={ () => onRemove( m ) }
							/>
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

export default function AccessApp() {
	const [ data, setData ] = useState( null );
	const [ selected, setSelected ] = useState( null );
	const [ members, setMembers ] = useState( [] );
	const [ loadingMembers, setLoadingMembers ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ modal, setModal ] = useState( null );
	const [ checking, setChecking ] = useState( false );

	const say = ( status, text ) => setNotice( { status, text } );

	const load = useCallback( async () => {
		try {
			const res = await apiFetch( { path: BASE } );
			setData( res );
			setSelected(
				( current ) => current ?? res.groups[ 0 ]?.id ?? null
			);
		} catch ( e ) {
			say( 'error', message( e ) );
		}
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	useEffect( () => {
		if ( ! selected ) {
			setMembers( [] );
			return;
		}
		setLoadingMembers( true );
		apiFetch( { path: `${ BASE }/groups/${ selected }/members` } )
			.then( setMembers )
			.catch( ( e ) => say( 'error', message( e ) ) )
			.finally( () => setLoadingMembers( false ) );
	}, [ selected ] );

	const group = data?.groups.find( ( g ) => g.id === selected );
	const setGroups = ( groups ) => setData( ( d ) => ( { ...d, groups } ) );

	const saveGroup = async ( values ) => {
		try {
			const res = modal.group
				? await apiFetch( {
						path: `${ BASE }/groups/${ modal.group.id }`,
						method: 'PATCH',
						data: values,
				  } )
				: await apiFetch( {
						path: `${ BASE }/groups`,
						method: 'POST',
						data: values,
				  } );
			setGroups( res.groups );
			if ( res.id ) {
				setSelected( res.id );
			}
			setModal( null );
		} catch ( e ) {
			say( 'error', message( e ) );
		}
	};

	const deleteGroup = async () => {
		try {
			const res = await apiFetch( {
				path: `${ BASE }/groups/${ group.id }`,
				method: 'DELETE',
			} );
			setGroups( res.groups );
			setSelected( res.groups[ 0 ]?.id ?? null );
			setModal( null );
			say(
				'success',
				__(
					'Group deleted. Documents that were only for this group are now open only to staff until you give them another group.',
					'crnube-docs-pro'
				)
			);
		} catch ( e ) {
			say( 'error', message( e ) );
		}
	};

	const addMembers = async ( rows, notify ) => {
		setBusy( true );
		try {
			const res = await apiFetch( {
				path: `${ BASE }/groups/${ selected }/members`,
				method: 'POST',
				data: { members: rows, notify },
			} );
			setMembers( res.members );
			setGroups( res.groups );
			say(
				res.failed.length ? 'warning' : 'success',
				[
					sprintf(
						/* translators: %d: number of people */
						_n(
							'%d person added.',
							'%d people added.',
							res.added,
							'crnube-docs-pro'
						),
						res.added
					),
					res.failed.length
						? sprintf(
								/* translators: %s: list of emails with problems */
								__( 'Not added: %s', 'crnube-docs-pro' ),
								res.failed
									.map(
										( f ) =>
											`${ f.email || '—' } (${
												f.reason
											})`
									)
									.join( '; ' )
						  )
						: '',
				]
					.filter( Boolean )
					.join( ' ' )
			);
			return true;
		} catch ( e ) {
			say( 'error', message( e ) );
			return false;
		} finally {
			setBusy( false );
		}
	};

	const changeExpires = async ( member, expires ) => {
		try {
			const res = await apiFetch( {
				path: `${ BASE }/groups/${ selected }/members/${ member.user_id }`,
				method: 'PATCH',
				data: { expires },
			} );
			setMembers( res.members );
			setGroups( res.groups );
		} catch ( e ) {
			say( 'error', message( e ) );
		}
	};

	const removeMember = async ( member ) => {
		try {
			const res = await apiFetch( {
				path: `${ BASE }/groups/${ selected }/members/${ member.user_id }`,
				method: 'DELETE',
			} );
			setMembers( res.members );
			setGroups( res.groups );
		} catch ( e ) {
			say( 'error', message( e ) );
		}
	};

	const checkStorage = async () => {
		setChecking( true );
		try {
			const res = await apiFetch( {
				path: `${ BASE }/storage-check`,
				method: 'POST',
			} );
			setData( ( d ) => ( { ...d, storage: res } ) );
		} finally {
			setChecking( false );
		}
	};

	if ( ! data ) {
		return <Spinner />;
	}

	return (
		<div className="crnd-access">
			<header className="crnd-access__header">
				<div>
					<h1>{ __( 'Protected area', 'crnube-docs-pro' ) }</h1>
					<p>
						{ __(
							'Groups decide who may open private documents. Make a category or a document private from the Library.',
							'crnube-docs-pro'
						) }
					</p>
				</div>
				<div className="crnd-access__links">
					{ data.portal ? (
						<Button
							variant="secondary"
							href={ data.portal }
							target="_blank"
							icon="external"
						>
							{ __( 'Open the portal', 'crnube-docs-pro' ) }
						</Button>
					) : null }
					<Button
						variant="tertiary"
						href={ config.libraryUrl }
						icon="media-document"
					>
						{ __( 'Library', 'crnube-docs-pro' ) }
					</Button>
				</div>
			</header>

			<StorageNotice
				storage={ data.storage }
				onCheck={ checkStorage }
				checking={ checking }
			/>
			{ ! data.portal && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'There is no portal page yet: members are sent to the WordPress login.',
						'crnube-docs-pro'
					) }{ ' ' }
					{ config.settingsUrl && (
						<a href={ `${ config.settingsUrl }#crnd-protected` }>
							{ __(
								'Create it in the settings',
								'crnube-docs-pro'
							) }
						</a>
					) }
				</Notice>
			) }
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }

			<div className="crnd-access__body">
				<nav
					className="crnd-access-groups"
					aria-label={ __( 'Groups', 'crnube-docs-pro' ) }
				>
					<div className="crnd-access-groups__head">
						<h2>{ __( 'Groups', 'crnube-docs-pro' ) }</h2>
						<Button
							size="small"
							variant="tertiary"
							icon="plus-alt2"
							onClick={ () => setModal( { type: 'group' } ) }
						>
							{ __( 'New', 'crnube-docs-pro' ) }
						</Button>
					</div>
					{ data.groups.length === 0 && (
						<p className="crnd-access-empty">
							{ __(
								'Create the first group, for example "Board of directors" or "Delegates 2026".',
								'crnube-docs-pro'
							) }
						</p>
					) }
					{ data.groups.map( ( g ) => (
						<button
							type="button"
							key={ g.id }
							className={ `crnd-access-group ${
								g.id === selected ? 'is-selected' : ''
							}` }
							onClick={ () => setSelected( g.id ) }
							aria-current={
								g.id === selected ? 'true' : undefined
							}
						>
							<span
								className="dashicons dashicons-groups"
								aria-hidden="true"
							/>
							<span className="crnd-access-group__name">
								{ g.name }
							</span>
							<span
								className="crnd-access-group__count"
								title={ __(
									'Active / total',
									'crnube-docs-pro'
								) }
							>
								{ g.active }/{ g.members }
							</span>
						</button>
					) ) }
				</nav>

				<main className="crnd-access-main">
					{ group ? (
						<>
							<div className="crnd-access-main__head">
								<div>
									<h2>{ group.name }</h2>
									{ group.description && (
										<p>{ group.description }</p>
									) }
									<p className="crnd-access-muted">
										{ sprintf(
											/* translators: 1: active members, 2: all members */
											__(
												'%1$d active of %2$d members',
												'crnube-docs-pro'
											),
											group.active,
											group.members
										) }
									</p>
								</div>
								<div>
									<Button
										variant="tertiary"
										icon="edit"
										onClick={ () =>
											setModal( { type: 'group', group } )
										}
									>
										{ __( 'Edit', 'crnube-docs-pro' ) }
									</Button>
									<Button
										variant="tertiary"
										icon="trash"
										isDestructive
										onClick={ () =>
											setModal( { type: 'delete' } )
										}
									>
										{ __( 'Delete', 'crnube-docs-pro' ) }
									</Button>
								</div>
							</div>
							<AddMembers onAdd={ addMembers } busy={ busy } />
							<Members
								members={ members }
								loading={ loadingMembers }
								onExpires={ changeExpires }
								onRemove={ removeMember }
							/>
						</>
					) : (
						<p className="crnd-access-empty">
							{ __(
								'Select or create a group.',
								'crnube-docs-pro'
							) }
						</p>
					) }
				</main>
			</div>

			{ modal?.type === 'group' && (
				<GroupModal
					group={ modal.group }
					onSave={ saveGroup }
					onClose={ () => setModal( null ) }
				/>
			) }
			{ modal?.type === 'delete' && group && (
				<Modal
					title={ sprintf(
						/* translators: %s: group name */
						__( 'Delete "%s"?', 'crnube-docs-pro' ),
						group.name
					) }
					onRequestClose={ () => setModal( null ) }
					size="small"
				>
					<p>
						{ __(
							'Its members lose the access this group gave them. Their accounts and the documents are not deleted.',
							'crnube-docs-pro'
						) }
					</p>
					<div className="crnd-modal-actions">
						<Button
							variant="tertiary"
							onClick={ () => setModal( null ) }
						>
							{ __( 'Cancel', 'crnube-docs-pro' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							onClick={ deleteGroup }
						>
							{ __( 'Delete group', 'crnube-docs-pro' ) }
						</Button>
					</div>
				</Modal>
			) }
		</div>
	);
}
