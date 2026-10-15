/**
 * Reads a members CSV exported from Excel or Google Sheets: columns for name, email
 * and expiry date, in any order, with ";" or "," as separator. Headers may be in
 * Spanish or English. Dates may be YYYY-MM-DD or DD/MM/YYYY.
 */

const HEADERS = {
	email: [ 'email', 'correo', 'correo electronico', 'e-mail', 'mail' ],
	name: [ 'name', 'nombre', 'nombre completo' ],
	expires: [
		'expires',
		'vence',
		'vencimiento',
		'fecha de vencimiento',
		'hasta',
	],
};

const normalize = ( value ) =>
	String( value || '' )
		.normalize( 'NFD' )
		.replace( /[\u0300-\u036f]/g, '' )
		.toLowerCase()
		.trim();

function splitLine( line, separator ) {
	const cells = [];
	let cell = '';
	let quoted = false;
	for ( let i = 0; i < line.length; i++ ) {
		const char = line[ i ];
		if ( quoted ) {
			if ( char === '"' && line[ i + 1 ] === '"' ) {
				cell += '"';
				i++;
			} else if ( char === '"' ) {
				quoted = false;
			} else {
				cell += char;
			}
		} else if ( char === '"' ) {
			quoted = true;
		} else if ( char === separator ) {
			cells.push( cell );
			cell = '';
		} else {
			cell += char;
		}
	}
	cells.push( cell );
	return cells.map( ( c ) => c.trim() );
}

export function toIsoDate( value ) {
	const text = String( value || '' ).trim();
	if ( text === '' ) {
		return '';
	}
	if ( /^\d{4}-\d{2}-\d{2}$/.test( text ) ) {
		return text;
	}
	const match = text.match( /^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$/ );
	if ( match ) {
		return `${ match[ 3 ] }-${ match[ 2 ].padStart(
			2,
			'0'
		) }-${ match[ 1 ].padStart( 2, '0' ) }`;
	}
	return null;
}

/**
 * @param {string} text CSV content.
 * @return {{rows: Array<{email: string, name: string, expires: string}>, errors: string[]}} Parsed rows.
 */
export function parseMembersCsv( text ) {
	const lines = text
		.replace( /^\uFEFF/, '' )
		.split( /\r?\n/ )
		.filter( ( l ) => l.trim() !== '' );
	if ( lines.length === 0 ) {
		return { rows: [], errors: [] };
	}
	const separator =
		( lines[ 0 ].match( /;/g ) || [] ).length >=
		( lines[ 0 ].match( /,/g ) || [] ).length
			? ';'
			: ',';
	const header = splitLine( lines[ 0 ], separator ).map( normalize );
	const column = {};
	Object.entries( HEADERS ).forEach( ( [ key, names ] ) => {
		column[ key ] = header.findIndex( ( h ) => names.includes( h ) );
	} );

	// No header row: assume "name, email, expires" or a single email column.
	let body = lines.slice( 1 );
	if ( column.email === -1 ) {
		body = lines;
		const first = splitLine( lines[ 0 ], separator );
		column.email = first.findIndex( ( c ) => c.includes( '@' ) );
		column.name = column.email === 0 ? 1 : 0;
		column.expires = first.length > 2 ? 2 : -1;
	}

	const rows = [];
	const errors = [];
	body.forEach( ( line, index ) => {
		const cells = splitLine( line, separator );
		const email = ( cells[ column.email ] || '' ).toLowerCase();
		if ( ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email ) ) {
			errors.push( `${ index + 2 }: ${ email || '—' }` );
			return;
		}
		const expires =
			column.expires >= 0 ? toIsoDate( cells[ column.expires ] ) : '';
		if ( expires === null ) {
			errors.push( `${ index + 2 }: ${ email }` );
			return;
		}
		rows.push( {
			email,
			name:
				column.name >= 0 && column.name !== column.email
					? cells[ column.name ] || ''
					: '',
			expires,
		} );
	} );
	return { rows, errors };
}
