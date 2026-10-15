import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	ComboboxControl,
	Notice,
	SelectControl,
	Spinner,
	TreeSelect,
} from '@wordpress/components';
import Kpis from './components/Kpis';
import TrendChart from './components/TrendChart';
import BarList from './components/BarList';
import HoursChart from './components/HoursChart';
import TopDocuments from './components/TopDocuments';
import EventsTable from './components/EventsTable';
import { config, dimensionLabel, presetRange } from './format';

const BASE = '/crnd/v1';
const EVENTS_PER_PAGE = 25;

const query = ( params ) =>
	new URLSearchParams(
		Object.entries( params ).filter( ( [ , v ] ) => v !== '' && v !== 0 )
	).toString();

/** Flat categories to the shape TreeSelect expects. */
function categoryTree( categories ) {
	const byParent = new Map();
	categories.forEach( ( c ) => {
		if ( ! byParent.has( c.parent ) ) {
			byParent.set( c.parent, [] );
		}
		byParent.get( c.parent ).push( c );
	} );
	const make = ( parent ) =>
		( byParent.get( parent ) || [] ).map( ( c ) => ( {
			id: String( c.id ),
			name: c.name,
			children: make( c.id ),
		} ) );
	return make( 0 );
}

export default function ReportsApp() {
	const [ preset, setPreset ] = useState( '30' );
	const [ range, setRange ] = useState( () => presetRange( '30' ) );
	const [ category, setCategory ] = useState( '' );
	const [ document, setDocument ] = useState( config.document || 0 );
	const [ documentOptions, setDocumentOptions ] = useState(
		config.document
			? [
					{
						value: String( config.document ),
						label: config.documentTitle,
					},
			  ]
			: []
	);
	const [ categories, setCategories ] = useState( [] );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ events, setEvents ] = useState( { items: [], pages: 1 } );
	const [ eventsPage, setEventsPage ] = useState( 1 );
	const [ eventsLoading, setEventsLoading ] = useState( false );
	const request = useRef( 0 );

	const filters = useMemo(
		() => ( {
			from: range.from,
			to: range.to,
			category: document ? 0 : Number( category ) || 0,
			document: Number( document ) || 0,
		} ),
		[ range, category, document ]
	);

	useEffect( () => {
		apiFetch( { path: `${ BASE }/categories` } )
			.then( ( res ) => setCategories( res.categories ) )
			.catch( () => {} );
	}, [] );

	useEffect( () => {
		const id = ++request.current;
		setLoading( true );
		setError( '' );
		apiFetch( { path: `${ BASE }/stats?${ query( filters ) }` } )
			.then( ( res ) => id === request.current && setData( res ) )
			.catch(
				( e ) =>
					id === request.current &&
					setError(
						e?.message ||
							__(
								'The report could not be loaded.',
								'crnube-docs-pro'
							)
					)
			)
			.finally( () => id === request.current && setLoading( false ) );
		setEventsPage( 1 );
	}, [ filters ] );

	useEffect( () => {
		let active = true;
		setEventsLoading( true );
		apiFetch( {
			path: `${ BASE }/stats/events?${ query( {
				...filters,
				page: eventsPage,
				per_page: EVENTS_PER_PAGE,
			} ) }`,
			parse: false,
		} )
			.then( async ( res ) => {
				const items = await res.json();
				if ( active ) {
					setEvents( {
						items,
						pages:
							Number( res.headers.get( 'X-WP-TotalPages' ) ) || 1,
					} );
				}
			} )
			.catch( () => {} )
			.finally( () => active && setEventsLoading( false ) );
		return () => {
			active = false;
		};
	}, [ filters, eventsPage ] );

	const searchDocuments = useCallback( ( text ) => {
		if ( ! text || text.length < 2 ) {
			return;
		}
		apiFetch( {
			path: `${ BASE }/documents?${ query( {
				search: text,
				per_page: 20,
			} ) }`,
		} )
			.then( ( docs ) =>
				setDocumentOptions(
					docs.map( ( d ) => ( {
						value: String( d.id ),
						label: d.title,
					} ) )
				)
			)
			.catch( () => {} );
	}, [] );

	const selectDocument = ( id, title ) => {
		if ( title ) {
			setDocumentOptions( ( options ) =>
				options.some( ( o ) => o.value === String( id ) )
					? options
					: [ { value: String( id ), label: title }, ...options ]
			);
		}
		setDocument( Number( id ) || 0 );
		window.scrollTo( { top: 0, behavior: 'smooth' } );
	};

	const exportUrl = ( type ) =>
		`${ config.exportUrl }&${ query( { ...filters, type } ) }`;

	const selectedTitle = documentOptions.find(
		( o ) => o.value === String( document )
	)?.label;

	const breakdown = ( dimension ) =>
		( data?.breakdowns?.[ dimension ] || [] ).map( ( row ) => ( {
			key: row.key || 'unknown',
			label: dimensionLabel( dimension, row.key ),
			value: row.count,
		} ) );

	return (
		<div className="crnd-reports">
			<header className="crnd-reports__header">
				<div>
					<h1>{ __( 'Reports', 'crnube-docs-pro' ) }</h1>
					<p className="crnd-reports__subtitle">
						{ document && selectedTitle
							? sprintf(
									/* translators: %s: document title */
									__( 'Activity of "%s"', 'crnube-docs-pro' ),
									selectedTitle
							  )
							: __(
									'Views and downloads of the documents on the site.',
									'crnube-docs-pro'
							  ) }
					</p>
				</div>
				<div className="crnd-reports__export">
					<Button
						variant="secondary"
						icon="media-spreadsheet"
						href={ exportUrl( 'summary' ) }
					>
						{ __(
							'Summary per document (CSV)',
							'crnube-docs-pro'
						) }
					</Button>
					<Button
						variant="primary"
						icon="download"
						href={ exportUrl( 'events' ) }
					>
						{ __( 'Full detail (CSV)', 'crnube-docs-pro' ) }
					</Button>
				</div>
			</header>

			<div
				className="crnd-filters"
				role="group"
				aria-label={ __( 'Filters', 'crnube-docs-pro' ) }
			>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Period', 'crnube-docs-pro' ) }
					value={ preset }
					options={ [
						{
							value: '7',
							label: __( 'Last 7 days', 'crnube-docs-pro' ),
						},
						{
							value: '30',
							label: __( 'Last 30 days', 'crnube-docs-pro' ),
						},
						{
							value: '90',
							label: __( 'Last 90 days', 'crnube-docs-pro' ),
						},
						{
							value: 'year',
							label: __( 'This year', 'crnube-docs-pro' ),
						},
						{
							value: '365',
							label: __( 'Last 12 months', 'crnube-docs-pro' ),
						},
						{
							value: 'custom',
							label: __( 'Custom…', 'crnube-docs-pro' ),
						},
					] }
					onChange={ ( value ) => {
						setPreset( value );
						if ( value !== 'custom' ) {
							setRange( presetRange( value ) );
						}
					} }
				/>
				{ preset === 'custom' && (
					<div className="crnd-filters__dates">
						<label htmlFor="crnd-report-from">
							<span>{ __( 'From', 'crnube-docs-pro' ) }</span>
							<input
								id="crnd-report-from"
								type="date"
								value={ range.from }
								max={ range.to }
								onChange={ ( e ) =>
									e.target.value &&
									setRange( {
										...range,
										from: e.target.value,
									} )
								}
							/>
						</label>
						<label htmlFor="crnd-report-to">
							<span>{ __( 'To', 'crnube-docs-pro' ) }</span>
							<input
								id="crnd-report-to"
								type="date"
								value={ range.to }
								min={ range.from }
								max={ config.today }
								onChange={ ( e ) =>
									e.target.value &&
									setRange( { ...range, to: e.target.value } )
								}
							/>
						</label>
					</div>
				) }
				<TreeSelect
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Category', 'crnube-docs-pro' ) }
					noOptionLabel={ __( 'All categories', 'crnube-docs-pro' ) }
					tree={ categoryTree( categories ) }
					selectedId={ category }
					onChange={ ( id ) => {
						setCategory( id || '' );
						setDocument( 0 );
					} }
					disabled={ !! document }
				/>
				<div className="crnd-filters__document">
					<ComboboxControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Document', 'crnube-docs-pro' ) }
						placeholder={ __(
							'Type to search…',
							'crnube-docs-pro'
						) }
						value={ document ? String( document ) : null }
						options={ documentOptions }
						onFilterValueChange={ searchDocuments }
						onChange={ ( value ) =>
							setDocument( Number( value ) || 0 )
						}
					/>
				</div>
				{ loading && <Spinner /> }
			</div>

			{ ! config.tracking && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Recording of views and downloads is turned off, so new activity is not being counted.',
						'crnube-docs-pro'
					) }{ ' ' }
					{ config.settingsUrl && (
						<a href={ config.settingsUrl }>
							{ __( 'Open the settings', 'crnube-docs-pro' ) }
						</a>
					) }
				</Notice>
			) }

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ data && (
				<div
					className={ `crnd-reports__body ${
						loading ? 'is-loading' : ''
					}` }
				>
					<Kpis totals={ data.totals } previous={ data.previous } />

					<section className="crnd-panel crnd-panel--wide">
						<h2 className="crnd-panel__title">
							{ __( 'Activity over time', 'crnube-docs-pro' ) }
						</h2>
						<TrendChart series={ data.series } />
					</section>

					{ ! document && (
						<TopDocuments
							rows={ data.top }
							onSelect={ selectDocument }
						/>
					) }

					<div className="crnd-grid-panels">
						{ ! document && (
							<BarList
								title={ __( 'By category', 'crnube-docs-pro' ) }
								rows={ data.categories.map( ( c ) => ( {
									key: String( c.id ),
									label: c.name,
									value: c.downloads + c.views,
								} ) ) }
							/>
						) }
						<BarList
							title={ __( 'Devices', 'crnube-docs-pro' ) }
							rows={ breakdown( 'device' ) }
						/>
						<BarList
							title={ __( 'Browsers', 'crnube-docs-pro' ) }
							rows={ breakdown( 'browser' ) }
						/>
						<BarList
							title={ __(
								'Operating systems',
								'crnube-docs-pro'
							) }
							rows={ breakdown( 'os' ) }
						/>
						<BarList
							title={ __( 'Countries', 'crnube-docs-pro' ) }
							rows={ breakdown( 'country' ) }
							empty={ __(
								'The server does not report the country (it does when the site is behind Cloudflare).',
								'crnube-docs-pro'
							) }
						/>
						<HoursChart hours={ data.hours } />
					</div>

					<EventsTable
						events={ events.items }
						page={ eventsPage }
						pages={ events.pages }
						loading={ eventsLoading }
						onPage={ setEventsPage }
						onDocument={ selectDocument }
					/>

					<p className="crnd-reports__privacy">
						{ __(
							'Privacy: IP addresses are not stored. "Unique visitors" are counted with an anonymous key that changes every day, so the same person on two different days counts twice.',
							'crnube-docs-pro'
						) }
					</p>
				</div>
			) }
		</div>
	);
}
