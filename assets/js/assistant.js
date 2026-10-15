/**
 * The document assistant: a small chat that asks crnd/v1/assistant/ask and shows the
 * answer with its sources. Answers are rendered node by node (never as HTML), with a
 * small Markdown subset: paragraphs, lists, bold, tables and [n] citations.
 */
( function () {
	'use strict';

	function el( tag, className, text ) {
		const node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}

	/** Bold and [n] citations inside one line of text. */
	function inline( parent, text, sources ) {
		const pattern = /(\*\*[^*]+\*\*|\[\d+\])/g;
		let last = 0;
		let match;
		while ( ( match = pattern.exec( text ) ) !== null ) {
			if ( match.index > last ) {
				parent.appendChild(
					document.createTextNode( text.slice( last, match.index ) )
				);
			}
			const token = match[ 0 ];
			if ( token.charAt( 0 ) === '*' ) {
				parent.appendChild( el( 'strong', '', token.slice( 2, -2 ) ) );
			} else {
				const n = parseInt( token.slice( 1, -1 ), 10 );
				const source = sources.find( ( s ) => s.n === n );
				if ( source ) {
					const sup = el( 'sup', 'crnd-assistant__cite' );
					const link = el( 'a', '', String( n ) );
					link.href = source.url;
					link.target = '_blank';
					link.rel = 'noopener';
					link.title = source.title;
					sup.appendChild( link );
					parent.appendChild( sup );
				}
			}
			last = match.index + token.length;
		}
		if ( last < text.length ) {
			parent.appendChild( document.createTextNode( text.slice( last ) ) );
		}
	}

	function isTableRow( line ) {
		return /^\s*\|.*\|\s*$/.test( line );
	}

	function cells( line ) {
		return line
			.trim()
			.replace( /^\||\|$/g, '' )
			.split( '|' )
			.map( ( c ) => c.trim() );
	}

	function renderAnswer( container, text, sources ) {
		const lines = text.split( /\r?\n/ );
		let i = 0;
		while ( i < lines.length ) {
			const line = lines[ i ];
			if ( line.trim() === '' ) {
				i++;
				continue;
			}
			if ( isTableRow( line ) ) {
				const rows = [];
				while ( i < lines.length && isTableRow( lines[ i ] ) ) {
					rows.push( lines[ i ] );
					i++;
				}
				const wrap = el( 'div', 'crnd-assistant__table' );
				const table = el( 'table' );
				rows.forEach( ( row, index ) => {
					if ( /^\s*\|?[\s:-]+\|[\s|:-]*$/.test( row ) ) {
						return;
					}
					const tr = el( 'tr' );
					cells( row ).forEach( ( cell ) => {
						const td = el( index === 0 ? 'th' : 'td' );
						inline( td, cell, sources );
						tr.appendChild( td );
					} );
					table.appendChild( tr );
				} );
				wrap.appendChild( table );
				container.appendChild( wrap );
				continue;
			}
			const bullet = /^\s*(?:[-*•]|\d+[.)])\s+/;
			if ( bullet.test( line ) ) {
				const ordered = /^\s*\d/.test( line );
				const list = el( ordered ? 'ol' : 'ul' );
				while ( i < lines.length && bullet.test( lines[ i ] ) ) {
					const li = el( 'li' );
					inline( li, lines[ i ].replace( bullet, '' ), sources );
					list.appendChild( li );
					i++;
				}
				container.appendChild( list );
				continue;
			}
			const p = el( 'p' );
			inline( p, line.replace( /^#+\s*/, '' ), sources );
			container.appendChild( p );
			i++;
		}
	}

	function init( root ) {
		let config;
		try {
			config = JSON.parse(
				root.getAttribute( 'data-crnd-assistant' ) || '{}'
			);
		} catch ( e ) {
			return;
		}
		const t = config.i18n || {};
		const log = root.querySelector( '[data-crnd-log]' );
		const form = root.querySelector( '[data-crnd-form]' );
		const input = form.querySelector( 'textarea' );
		const send = form.querySelector( 'button' );
		const chips = root.querySelector( '[data-crnd-suggestions]' );
		const clear = root.querySelector( '[data-crnd-clear]' );
		const left = root.querySelector( '[data-crnd-left]' );
		const history = [];
		let busy = false;
		let widgetId = null;
		let pendingToken = null;

		function turnstileToken() {
			if ( ! config.turnstile ) {
				return Promise.resolve( '' );
			}
			return new Promise( ( resolve ) => {
				const begin = () => {
					const box = root.querySelector( '[data-crnd-turnstile]' );
					if ( widgetId === null ) {
						widgetId = window.turnstile.render( box, {
							sitekey: config.turnstile,
							execution: 'execute',
							appearance: 'interaction-only',
							callback: ( token ) =>
								pendingToken && pendingToken( token ),
							'error-callback': () =>
								pendingToken && pendingToken( '' ),
						} );
					}
					pendingToken = ( token ) => {
						pendingToken = null;
						resolve( token );
					};
					window.turnstile.reset( widgetId );
					window.turnstile.execute( widgetId );
				};
				if ( window.turnstile ) {
					begin();
				} else {
					// The script is deferred; wait a moment for it.
					let tries = 0;
					const timer = setInterval( () => {
						tries++;
						if ( window.turnstile ) {
							clearInterval( timer );
							begin();
						} else if ( tries > 50 ) {
							clearInterval( timer );
							resolve( '' );
						}
					}, 100 );
				}
			} );
		}

		function bubble( role, text ) {
			const item = el(
				'div',
				'crnd-assistant__msg crnd-assistant__msg--' + role
			);
			item.appendChild(
				el(
					'span',
					'screen-reader-text',
					role === 'user' ? t.you : t.assistant
				)
			);
			const body = el( 'div', 'crnd-assistant__body' );
			if ( text !== undefined ) {
				body.textContent = text;
			}
			item.appendChild( body );
			log.appendChild( item );
			log.scrollTop = log.scrollHeight;
			return body;
		}

		function showAnswer( body, data ) {
			body.textContent = '';
			renderAnswer( body, data.answer || '', data.sources || [] );
			if ( data.sources && data.sources.length ) {
				const box = el( 'div', 'crnd-assistant__sources' );
				box.appendChild(
					el( 'span', 'crnd-assistant__sources-title', t.sources )
				);
				const list = el( 'ol' );
				data.sources.forEach( ( s ) => {
					const li = el( 'li' );
					li.value = s.n;
					const a = el( 'a', '', s.title );
					a.href = s.url;
					a.target = '_blank';
					a.rel = 'noopener';
					li.appendChild( a );
					if ( s.page ) {
						li.appendChild(
							el(
								'span',
								'crnd-assistant__page',
								' · ' +
									( t.page || '%s' ).replace( '%s', s.page )
							)
						);
					}
					list.appendChild( li );
				} );
				box.appendChild( list );
				body.appendChild( box );
			}
			log.scrollTop = log.scrollHeight;
		}

		async function ask( question ) {
			question = question.trim().slice( 0, config.maxLength || 300 );
			if ( busy || question.length < 3 ) {
				return;
			}
			busy = true;
			send.disabled = true;
			input.value = '';
			if ( chips ) {
				chips.hidden = true;
			}
			bubble( 'user', question );
			const body = bubble( 'assistant', t.thinking );
			body.parentNode.classList.add( 'is-loading' );

			let data = { answer: t.error, sources: [] };
			try {
				const token = await turnstileToken();
				const response = await fetch( config.endpoint, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					credentials: 'same-origin',
					body: JSON.stringify( {
						question,
						history: history.slice( -3 ),
						categories: config.categories || '',
						turnstile: token,
					} ),
				} );
				data = await response.json();
				if ( data.status === 'ok' ) {
					history.push( { q: question, a: data.answer } );
				}
				if ( typeof data.left === 'number' && left ) {
					left.textContent =
						' ' + ( t.left || '%d' ).replace( '%d', data.left );
				}
			} catch ( e ) {
				data = { answer: t.error, sources: [] };
			}
			body.parentNode.classList.remove( 'is-loading' );
			showAnswer( body, data );
			if ( clear ) {
				clear.hidden = false;
			}
			busy = false;
			send.disabled = false;
			input.focus();
		}

		form.addEventListener( 'submit', ( event ) => {
			event.preventDefault();
			ask( input.value );
		} );
		input.addEventListener( 'keydown', ( event ) => {
			if ( event.key === 'Enter' && ! event.shiftKey ) {
				event.preventDefault();
				ask( input.value );
			}
		} );
		input.addEventListener( 'input', () => {
			input.style.height = 'auto';
			input.style.height = Math.min( input.scrollHeight, 160 ) + 'px';
		} );
		if ( chips ) {
			chips.addEventListener( 'click', ( event ) => {
				const chip = event.target.closest( 'button' );
				if ( chip ) {
					ask( chip.textContent );
				}
			} );
		}
		if ( clear ) {
			clear.addEventListener( 'click', () => {
				history.length = 0;
				log.textContent = '';
				clear.hidden = true;
				if ( chips ) {
					chips.hidden = false;
				}
				input.focus();
			} );
		}
	}

	function start() {
		document
			.querySelectorAll( '[data-crnd-assistant]' )
			.forEach( ( root ) => {
				if ( ! root.dataset.crndReady ) {
					root.dataset.crndReady = '1';
					init( root );
				}
			} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
	// Elementor's editor adds widgets after the page loads.
	if (
		window.MutationObserver &&
		document.body &&
		document.body.classList.contains( 'elementor-editor-active' )
	) {
		new window.MutationObserver( start ).observe( document.body, {
			childList: true,
			subtree: true,
		} );
	}
} )();
