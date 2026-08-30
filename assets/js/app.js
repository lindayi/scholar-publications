/**
 * Scholar Publications — progressive enhancement.
 *
 * The list is already complete in the DOM when this runs. Nothing here fetches
 * data; it only filters, sorts and reveals what the server rendered, so the page
 * stays usable with JavaScript disabled.
 */
( function () {
	'use strict';

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function debounce( fn, wait ) {
		var timer;
		return function () {
			var args = arguments;
			var self = this;
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				fn.apply( self, args );
			}, wait );
		};
	}

	function toast( message ) {
		var el = document.createElement( 'div' );
		el.className = 'schpub-toast';
		el.setAttribute( 'role', 'status' );
		el.textContent = message;
		document.body.appendChild( el );

		window.requestAnimationFrame( function () {
			el.classList.add( 'is-visible' );
		} );

		window.setTimeout( function () {
			el.classList.remove( 'is-visible' );
			window.setTimeout( function () {
				if ( el.parentNode ) {
					el.parentNode.removeChild( el );
				}
			}, 250 );
		}, 1800 );
	}

	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}

		// Fallback for insecure contexts and older browsers.
		return new Promise( function ( resolve, reject ) {
			var area = document.createElement( 'textarea' );
			area.value = text;
			area.setAttribute( 'readonly', '' );
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild( area );
			area.select();

			try {
				document.execCommand( 'copy' ) ? resolve() : reject();
			} catch ( err ) {
				reject( err );
			} finally {
				document.body.removeChild( area );
			}
		} );
	}

	/**
	 * Count up a number so the metric cards feel alive on first paint.
	 *
	 * @param {HTMLElement} el Element carrying a data-count attribute.
	 */
	function animateCount( el ) {
		var target = parseInt( el.getAttribute( 'data-count' ), 10 );
		if ( isNaN( target ) || target <= 0 ) {
			return;
		}

		var duration = 700;
		var start = null;
		var format = function ( value ) {
			return value.toLocaleString();
		};

		function step( timestamp ) {
			if ( start === null ) {
				start = timestamp;
			}
			var progress = Math.min( ( timestamp - start ) / duration, 1 );
			// Ease-out cubic keeps the last frames from crawling.
			var eased = 1 - Math.pow( 1 - progress, 3 );
			el.textContent = format( Math.round( target * eased ) );

			if ( progress < 1 ) {
				window.requestAnimationFrame( step );
			}
		}

		el.textContent = '0';
		window.requestAnimationFrame( step );
	}

	function initCounters( root ) {
		var values = root.querySelectorAll( '.schpub-stat-value[data-count]' );
		if ( ! values.length ) {
			return;
		}

		if ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
			return;
		}

		if ( ! ( 'IntersectionObserver' in window ) ) {
			Array.prototype.forEach.call( values, animateCount );
			return;
		}

		var observer = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						animateCount( entry.target );
						observer.unobserve( entry.target );
					}
				} );
			},
			{ threshold: 0.4 }
		);

		Array.prototype.forEach.call( values, function ( el ) {
			observer.observe( el );
		} );
	}

	function initBlock( root ) {
		var list = root.querySelector( '.schpub-list' );
		if ( ! list ) {
			return;
		}

		var items = Array.prototype.slice.call( list.querySelectorAll( '.schpub-item' ) );
		var heads = Array.prototype.slice.call( list.querySelectorAll( '.schpub-year-head' ) );
		var search = root.querySelector( '.schpub-input' );
		var clear = root.querySelector( '.schpub-clear' );
		var yearSelect = root.querySelector( '.schpub-year' );
		var venueSelect = root.querySelector( '.schpub-venue' );
		var sortSelect = root.querySelector( '.schpub-sort' );
		var pills = Array.prototype.slice.call( root.querySelectorAll( '.schpub-pill' ) );
		var counter = root.querySelector( '.schpub-count' );
		var noResults = root.querySelector( '.schpub-noresults' );
		var grouped = root.getAttribute( 'data-group' ) === '1';

		var state = {
			query: '',
			// Several types can be active at once; empty means "no restriction".
			types: [],
			venue: '',
			year: '',
			sort: sortSelect ? sortSelect.value : root.getAttribute( 'data-sort' ) || 'year'
		};

		var total = items.length;

		function matches( item ) {
			if ( state.types.length && state.types.indexOf( item.getAttribute( 'data-type' ) ) === -1 ) {
				return false;
			}
			if ( state.venue && item.getAttribute( 'data-venue' ) !== state.venue ) {
				return false;
			}
			if ( state.year && item.getAttribute( 'data-year' ) !== state.year ) {
				return false;
			}
			if ( state.query ) {
				var haystack = item.getAttribute( 'data-search' ) || '';
				var terms = state.query.split( /\s+/ );
				for ( var i = 0; i < terms.length; i++ ) {
					if ( terms[ i ] && haystack.indexOf( terms[ i ] ) === -1 ) {
						return false;
					}
				}
			}
			return true;
		}

		function sortItems() {
			var sorted = items.slice();

			sorted.sort( function ( a, b ) {
				switch ( state.sort ) {
					case 'citations':
						return (
							parseInt( b.getAttribute( 'data-citations' ), 10 ) -
							parseInt( a.getAttribute( 'data-citations' ), 10 )
						);
					case 'title':
						return ( a.getAttribute( 'data-title' ) || '' ).localeCompare(
							b.getAttribute( 'data-title' ) || ''
						);
					case 'oldest':
						return (
							parseInt( a.getAttribute( 'data-year' ), 10 ) -
								parseInt( b.getAttribute( 'data-year' ), 10 ) ||
							parseInt( a.getAttribute( 'data-order' ), 10 ) -
								parseInt( b.getAttribute( 'data-order' ), 10 )
						);
					default:
						return (
							parseInt( a.getAttribute( 'data-order' ), 10 ) -
							parseInt( b.getAttribute( 'data-order' ), 10 )
						);
				}
			} );

			// Year headings only make sense while the list is in year order.
			var useHeadings = grouped && ( state.sort === 'year' || state.sort === 'oldest' );

			// While headings are shown the per-entry year would just repeat them.
			root.classList.toggle( 'schpub-headings-on', useHeadings );

			var fragment = document.createDocumentFragment();

			if ( useHeadings ) {
				var seen = {};
				sorted.forEach( function ( item ) {
					var year = item.getAttribute( 'data-year' );
					if ( ! seen[ year ] ) {
						seen[ year ] = true;
						var head = heads.filter( function ( h ) {
							return h.getAttribute( 'data-year' ) === year;
						} )[ 0 ];
						if ( head ) {
							fragment.appendChild( head );
						}
					}
					fragment.appendChild( item );
				} );
			} else {
				heads.forEach( function ( head ) {
					head.hidden = true;
				} );
				sorted.forEach( function ( item ) {
					fragment.appendChild( item );
				} );
			}

			list.appendChild( fragment );
		}

		function apply() {
			var shown = 0;

			items.forEach( function ( item ) {
				var ok = matches( item );
				item.hidden = ! ok;
				if ( ok ) {
					shown++;
				}
			} );

			// Hide a year heading when every entry beneath it is filtered out.
			var useHeadings = grouped && ( state.sort === 'year' || state.sort === 'oldest' );
			heads.forEach( function ( head ) {
				if ( ! useHeadings ) {
					head.hidden = true;
					return;
				}
				var year = head.getAttribute( 'data-year' );
				var visible = items.some( function ( item ) {
					return ! item.hidden && item.getAttribute( 'data-year' ) === year;
				} );
				head.hidden = ! visible;
			} );

			if ( counter ) {
				var template = counter.getAttribute( 'data-template' ) || '%1$s of %2$s publications';
				counter.textContent = template
					.replace( '%1$s', shown.toLocaleString() )
					.replace( '%2$s', total.toLocaleString() );
			}

			if ( noResults ) {
				noResults.hidden = shown !== 0;
			}
			if ( clear ) {
				clear.hidden = ! state.query;
			}
		}

		if ( search ) {
			search.addEventListener(
				'input',
				debounce( function () {
					state.query = search.value.trim().toLowerCase();
					apply();
				}, 140 )
			);

			search.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Escape' ) {
					search.value = '';
					state.query = '';
					apply();
				}
			} );
		}

		if ( clear ) {
			clear.addEventListener( 'click', function () {
				search.value = '';
				state.query = '';
				search.focus();
				apply();
			} );
		}

		if ( yearSelect ) {
			yearSelect.addEventListener( 'change', function () {
				state.year = yearSelect.value;
				apply();
			} );
		}

		if ( venueSelect ) {
			venueSelect.addEventListener( 'change', function () {
				state.venue = venueSelect.value;
				apply();
			} );
		}

		if ( sortSelect ) {
			sortSelect.addEventListener( 'change', function () {
				state.sort = sortSelect.value;
				sortItems();
				apply();
			} );
		}

		/**
		 * Reflect the active type list back onto the pills.
		 */
		function syncPills() {
			pills.forEach( function ( pill ) {
				var value = pill.getAttribute( 'data-type' ) || '';
				var on = value === ''
					? state.types.length === 0
					: state.types.indexOf( value ) !== -1;

				pill.classList.toggle( 'is-active', on );
				pill.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			} );
		}

		pills.forEach( function ( pill ) {
			pill.addEventListener( 'click', function () {
				var value = pill.getAttribute( 'data-type' ) || '';

				if ( value === '' ) {
					// "All" is a reset rather than a value of its own.
					state.types = [];
				} else {
					var at = state.types.indexOf( value );
					if ( at === -1 ) {
						state.types.push( value );
					} else {
						state.types.splice( at, 1 );
					}
				}

				syncPills();
				apply();
			} );
		} );

		// Details panels and BibTeX copying are delegated so they survive reordering.
		list.addEventListener( 'click', function ( event ) {
			var toggle = event.target.closest( '.schpub-toggle' );
			if ( toggle ) {
				var panel = document.getElementById( toggle.getAttribute( 'aria-controls' ) );
				if ( panel ) {
					var open = panel.hidden;
					panel.hidden = ! open;
					toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				}
				return;
			}

			var bib = event.target.closest( '.schpub-bibtex' );
			if ( bib ) {
				copyText( bib.getAttribute( 'data-bibtex' ) || '' ).then(
					function () {
						toast( 'BibTeX copied to clipboard' );
					},
					function () {
						toast( 'Could not copy BibTeX' );
					}
				);
			}
		} );

		sortItems();
		apply();
		initCounters( root );
		initChartReadout( root );
		initStuckHeadings( root );
	}

	/**
	 * Flag year headings while they are pinned to the top of the viewport.
	 *
	 * A sticky element cannot detect its own pinned state in CSS. Observing it
	 * with a one pixel negative top margin makes it stop intersecting fully at
	 * exactly the moment it sticks.
	 *
	 * @param {HTMLElement} root Block root.
	 */
	function initStuckHeadings( root ) {
		var heads = Array.prototype.slice.call( root.querySelectorAll( '.schpub-year-head' ) );
		if ( ! heads.length || ! ( 'IntersectionObserver' in window ) ) {
			return;
		}

		// Match the CSS offset so the observer trips at the right scroll position.
		var offset = parseInt( window.getComputedStyle( heads[ 0 ] ).top, 10 );
		if ( isNaN( offset ) ) {
			offset = 0;
		}

		var observer = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					// Not fully visible is not enough on its own: a heading
					// scrolling in from the bottom also fails that test. It is
					// only pinned when it has reached its sticky offset.
					var pinned = entry.intersectionRatio < 1 &&
						entry.boundingClientRect.top <= offset + 1;

					entry.target.classList.toggle( 'is-stuck', pinned );
				} );
			},
			{
				threshold: [ 1 ],
				rootMargin: -( offset + 1 ) + 'px 0px 0px 0px'
			}
		);

		heads.forEach( function ( head ) {
			observer.observe( head );
		} );
	}

	/**
	 * Write the hovered or focused bar's exact figure into the chart caption.
	 *
	 * The rail is too narrow to print a label on every bar, so the caption acts
	 * as a single shared readout.
	 *
	 * @param {HTMLElement} root Block root.
	 */
	function initChartReadout( root ) {
		var readout = root.querySelector( '.schpub-chart-readout' );
		if ( ! readout ) {
			return;
		}

		var idle = readout.getAttribute( 'data-idle' ) || '';
		var bars = Array.prototype.slice.call( root.querySelectorAll( '.schpub-bar' ) );

		function show( bar ) {
			readout.textContent = bar.getAttribute( 'data-readout' ) || idle;
		}

		function reset() {
			readout.textContent = idle;
		}

		bars.forEach( function ( bar ) {
			bar.addEventListener( 'mouseenter', function () {
				show( bar );
			} );
			bar.addEventListener( 'focus', function () {
				show( bar );
			} );
			bar.addEventListener( 'blur', reset );
		} );

		var plot = root.querySelector( '.schpub-chart-plot' );
		if ( plot ) {
			plot.addEventListener( 'mouseleave', reset );
		}
	}

	ready( function () {
		// Older Safari lacks Element.closest; the delegated handlers depend on it.
		if ( ! Element.prototype.closest ) {
			Element.prototype.closest = function ( selector ) {
				var node = this;
				while ( node && node.nodeType === 1 ) {
					if ( node.matches( selector ) ) {
						return node;
					}
					node = node.parentElement;
				}
				return null;
			};
		}

		Array.prototype.forEach.call( document.querySelectorAll( '.schpub' ), initBlock );
	} );
} )();
