import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import AccessApp from './AccessApp';
import './access.scss';

domReady( () => {
	const root = document.getElementById( 'crnd-access-root' );
	if ( root ) {
		createRoot( root ).render( <AccessApp /> );
	}
} );
