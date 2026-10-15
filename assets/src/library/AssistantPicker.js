import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Dropdown, Modal, RadioControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

/**
 * The "Assistant" cell of a document row: whether the document assistant may use it.
 * All public documents are used unless left out, here or on their category.
 */
export default function AssistantPicker( { doc, onSave } ) {
	const included = doc.ai_included !== false;
	const byCategory = doc.ai_mode === 'inherit' && ! included;
	const label = included
		? __( 'Used', 'crnube-docs-pro' )
		: __( 'Left out', 'crnube-docs-pro' );

	const badge = (
		<span className={ `crnd-ai-badge ${ included ? 'is-on' : 'is-off' }` }>
			<span
				className={ `dashicons dashicons-${
					included ? 'format-chat' : 'hidden'
				}` }
				aria-hidden="true"
			/>
			{ label }
			{ byCategory && (
				<span
					className="crnd-ai-badge__inherit"
					title={ __( 'Set by its category', 'crnube-docs-pro' ) }
				>
					*
				</span>
			) }
		</span>
	);

	return (
		<Dropdown
			popoverProps={ { placement: 'bottom-start' } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<button
					type="button"
					className="crnd-ai-toggle"
					aria-expanded={ isOpen }
					onClick={ onToggle }
					title={ sprintf(
						/* translators: %s: Used / Left out */
						__(
							'Assistant: %s. Click to change.',
							'crnube-docs-pro'
						),
						label
					) }
				>
					{ badge }
				</button>
			) }
			renderContent={ ( { onClose } ) => (
				<ScopeForm
					mode={ doc.ai_mode || 'inherit' }
					inheritLabel={ __(
						'Same as its category',
						'crnube-docs-pro'
					) }
					onCancel={ onClose }
					onSave={ ( mode ) => {
						onClose();
						onSave( doc.id, { ai_mode: mode } );
					} }
				/>
			) }
		/>
	);
}

function ScopeForm( { mode, inheritLabel, onSave, onCancel } ) {
	const [ value, setValue ] = useState( mode );
	return (
		<div className="crnd-ai-scope">
			<RadioControl
				label={ __( 'Use in the assistant', 'crnube-docs-pro' ) }
				selected={ value }
				onChange={ setValue }
				options={ [
					{ label: inheritLabel, value: 'inherit' },
					{
						label: __(
							'Yes, the assistant may use it',
							'crnube-docs-pro'
						),
						value: 'include',
					},
					{
						label: __( 'No, leave it out', 'crnube-docs-pro' ),
						value: 'exclude',
					},
				] }
			/>
			<p className="crnd-ai-scope__note">
				{ __(
					'Left-out documents stay on the site; only the assistant ignores them.',
					'crnube-docs-pro'
				) }
			</p>
			<div className="crnd-ai-scope__actions">
				<Button variant="tertiary" onClick={ onCancel }>
					{ __( 'Cancel', 'crnube-docs-pro' ) }
				</Button>
				<Button variant="primary" onClick={ () => onSave( value ) }>
					{ __( 'Apply', 'crnube-docs-pro' ) }
				</Button>
			</div>
		</div>
	);
}

/** "Use in the assistant…" on a category: for it and everything below it. */
export function CategoryScopeModal( {
	category,
	close,
	setCategoryData,
	reloadDocuments,
	notifySuccess,
	notifyError,
} ) {
	const save = async ( mode ) => {
		try {
			setCategoryData(
				await apiFetch( {
					path: `/crnd/v1/categories/${ category.id }/assistant`,
					method: 'POST',
					data: { mode: mode === 'inherit' ? '' : mode },
				} )
			);
			close();
			notifySuccess(
				mode === 'exclude'
					? __(
							'The assistant will no longer use the documents of this category.',
							'crnube-docs-pro'
					  )
					: __( 'Assistant setting updated.', 'crnube-docs-pro' )
			);
			reloadDocuments();
		} catch ( e ) {
			notifyError(
				e?.message ||
					__( 'Something went wrong. Try again.', 'crnube-docs-pro' )
			);
		}
	};

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: category name */
				__( 'Assistant and "%s"', 'crnube-docs-pro' ),
				category.name
			) }
			onRequestClose={ close }
			size="small"
		>
			<p className="crnd-muted">
				{ __(
					'Applies to every document in this category and its subcategories, unless a document or subcategory has its own setting.',
					'crnube-docs-pro'
				) }
			</p>
			<ScopeForm
				mode={ category.ai_mode || 'inherit' }
				inheritLabel={ __(
					'Same as the parent category (used at the top level)',
					'crnube-docs-pro'
				) }
				onCancel={ close }
				onSave={ save }
			/>
		</Modal>
	);
}
