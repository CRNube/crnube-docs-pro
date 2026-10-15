/**
 * The protected area inside the CRNube Docs library: an "Access" column and a
 * "Who can open it…" action on each category, through the library's extension hooks.
 */
import { addFilter } from '@wordpress/hooks';
import { __, sprintf } from '@wordpress/i18n';
import { Modal } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import AccessPicker, { AccessForm } from './AccessPicker';
import AssistantPicker, { CategoryScopeModal } from './AssistantPicker';
import './library-pro.scss';

const errorMessage = ( e ) =>
	e?.message || __( 'Something went wrong. Try again.', 'crnube-docs-pro' );

function CategoryAccessModal( {
	category,
	close,
	setCategoryData,
	reloadDocuments,
	notifySuccess,
	notifyError,
} ) {
	const save = async ( access, groups ) => {
		try {
			setCategoryData(
				await apiFetch( {
					path: `/crnd/v1/categories/${ category.id }/access`,
					method: 'POST',
					data: {
						access: access === 'inherit' ? '' : access,
						groups,
					},
				} )
			);
			close();
			notifySuccess(
				access === 'private'
					? __(
							'Category made private. Its files were moved to the protected folder.',
							'crnube-docs-pro'
					  )
					: __( 'Access updated.', 'crnube-docs-pro' )
			);
			reloadDocuments();
		} catch ( e ) {
			notifyError( errorMessage( e ) );
		}
	};

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: category name */
				__( 'Who can open "%s"', 'crnube-docs-pro' ),
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
			<AccessForm
				mode={ category.access || 'inherit' }
				groups={ category.access_groups || [] }
				inheritLabel={ __(
					'Same as the parent category (public at the top level)',
					'crnube-docs-pro'
				) }
				onCancel={ close }
				onSave={ save }
			/>
		</Modal>
	);
}

const config = window.crndLibrary || {};

if ( config.canManageAccess ) {
	addFilter(
		'crnd.library.documentColumns',
		'crnube-docs-pro/access',
		( columns ) => [
			...columns,
			{
				id: 'access',
				label: __( 'Access', 'crnube-docs-pro' ),
				className: 'crnd-col-access',
				render: ( { doc, onSave } ) => (
					<AccessPicker doc={ doc } onSave={ onSave } />
				),
			},
		]
	);

	addFilter(
		'crnd.library.categoryActions',
		'crnube-docs-pro/access',
		( actions ) => [
			...actions,
			{
				id: 'access',
				icon: 'lock',
				label: __( 'Who can open it…', 'crnube-docs-pro' ),
				render: ( props ) => <CategoryAccessModal { ...props } />,
			},
		]
	);
}

if ( config.canManageAssistant ) {
	addFilter(
		'crnd.library.documentColumns',
		'crnube-docs-pro/assistant',
		( columns ) => [
			...columns,
			{
				id: 'assistant',
				label: __( 'Assistant', 'crnube-docs-pro' ),
				className: 'crnd-col-assistant',
				render: ( { doc, onSave } ) => (
					<AssistantPicker doc={ doc } onSave={ onSave } />
				),
			},
		]
	);

	addFilter(
		'crnd.library.categoryActions',
		'crnube-docs-pro/assistant',
		( actions ) => [
			...actions,
			{
				id: 'assistant',
				icon: 'format-chat',
				label: __( 'Use in the assistant…', 'crnube-docs-pro' ),
				render: ( props ) => <CategoryScopeModal { ...props } />,
			},
		]
	);
}
