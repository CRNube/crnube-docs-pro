import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import ReportsApp from './ReportsApp';
import './reports.scss';

domReady( () => {
	const root = document.getElementById( 'crnd-reports-root' );
	if ( root ) {
		createRoot( root ).render( <ReportsApp /> );
	}
} );
