/* FoundFirst Growth Hub Google reviews: relative dates, Read more, carousel buttons and keys, photo fallback. No dependencies. */
( function () {
	'use strict';

	var DAY = 86400000;

	// "YYYY-MM-DD" as a local calendar date.
	function parseDate( value ) {
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec( value || '' );
		return m ? new Date( +m[ 1 ], +m[ 2 ] - 1, +m[ 3 ] ) : null;
	}

	function formatter( lang, numeric ) {
		try {
			return new Intl.RelativeTimeFormat( lang || undefined, { numeric: numeric } );
		} catch ( e ) {
			return new Intl.RelativeTimeFormat( undefined, { numeric: numeric } );
		}
	}

	// "today", "yesterday", "3 days ago", "2 weeks ago", "5 months ago", "1 year ago"; null if unsupported.
	function relativeDate( iso, now, lang ) {
		var date = parseDate( iso );
		if ( ! date || typeof Intl === 'undefined' || ! Intl.RelativeTimeFormat ) {
			return null;
		}

		var today = new Date( now.getFullYear(), now.getMonth(), now.getDate() );
		var days = Math.max( 0, Math.round( ( today - date ) / DAY ) );

		if ( days < 7 ) {
			return formatter( lang, 'auto' ).format( -days, 'day' );
		}

		var always = formatter( lang, 'always' );
		if ( days < 30 ) {
			return always.format( -Math.floor( days / 7 ), 'week' );
		}

		var months = ( today.getFullYear() - date.getFullYear() ) * 12 + today.getMonth() - date.getMonth();
		if ( today.getDate() < date.getDate() ) {
			months--;
		}
		months = Math.max( 1, months );

		return months < 12 ? always.format( -months, 'month' ) : always.format( -Math.floor( months / 12 ), 'year' );
	}

	function each( root, selector, fn ) {
		Array.prototype.forEach.call( root.querySelectorAll( selector ), fn );
	}

	function dropPhoto( img ) {
		if ( img.parentNode ) {
			img.parentNode.removeChild( img );
		}
	}

	function setupDates( section ) {
		var lang = document.documentElement && document.documentElement.lang;
		var now = new Date();
		each( section, '.ffgh-review__date', function ( time ) {
			var text = relativeDate( time.getAttribute( 'datetime' ), now, lang );
			if ( text ) {
				time.setAttribute( 'title', time.textContent );
				time.textContent = text;
			}
		} );
	}

	function setupMore( section ) {
		each( section, '.ffgh-review__text--long', function ( text ) {
			var button = text.nextElementSibling;
			if ( ! button || ! button.classList.contains( 'ffgh-review__more' ) ) {
				return;
			}
			var label = button.querySelector( '.ffgh-review__more-label' );
			var more = label ? label.textContent : '';
			var less = button.getAttribute( 'data-less' ) || more;

			text.classList.add( 'ffgh-review__text--clamped' );
			if ( text.scrollHeight <= text.clientHeight + 1 ) {
				// Fits in 5 lines after all (wide card): no button needed.
				text.classList.remove( 'ffgh-review__text--clamped' );
				return;
			}

			button.hidden = false;
			button.addEventListener( 'click', function () {
				var open = 'true' !== button.getAttribute( 'aria-expanded' );
				button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				text.classList[ open ? 'remove' : 'add' ]( 'ffgh-review__text--clamped' );
				if ( label ) {
					label.textContent = open ? less : more;
				}
			} );
		} );
	}

	function reducedMotion() {
		return !! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );
	}

	function setupCarousel( section ) {
		var carousel = section.querySelector( '.ffgh-reviews__carousel' );
		var list = carousel && carousel.querySelector( '.ffgh-reviews__list' );
		var controls = carousel && carousel.querySelector( '.ffgh-reviews__controls' );
		if ( ! list || ! controls ) {
			return;
		}
		var prev = controls.querySelector( '.ffgh-reviews__prev' );
		var next = controls.querySelector( '.ffgh-reviews__next' );
		var queued = false;

		function rtl() {
			return !! ( window.getComputedStyle && 'rtl' === window.getComputedStyle( list ).direction );
		}

		function step() {
			var cards = list.querySelectorAll( '.ffgh-review' );
			if ( cards.length > 1 ) {
				return Math.abs( cards[ 1 ].offsetLeft - cards[ 0 ].offsetLeft ) || list.clientWidth;
			}
			return list.clientWidth;
		}

		function go( direction ) {
			// Direction: 1 = towards the next review, in reading order.
			list.scrollBy( {
				left: direction * step() * ( rtl() ? -1 : 1 ),
				behavior: reducedMotion() ? 'auto' : 'smooth',
			} );
		}

		function update() {
			queued = false;
			var max = list.scrollWidth - list.clientWidth;
			var pos = Math.abs( list.scrollLeft );
			// A few pixels of slack for sub-pixel widths and snap rounding.
			controls.hidden = max <= 3;
			prev.setAttribute( 'aria-disabled', pos <= 3 ? 'true' : 'false' );
			next.setAttribute( 'aria-disabled', pos >= max - 3 ? 'true' : 'false' );
		}

		function queue() {
			if ( ! queued ) {
				queued = true;
				( window.requestAnimationFrame || setTimeout )( update );
			}
		}

		// aria-disabled (not disabled) so a focused button keeps focus at the ends.
		prev.addEventListener( 'click', function () {
			if ( 'true' !== prev.getAttribute( 'aria-disabled' ) ) {
				go( -1 );
			}
		} );
		next.addEventListener( 'click', function () {
			if ( 'true' !== next.getAttribute( 'aria-disabled' ) ) {
				go( 1 );
			}
		} );

		list.addEventListener( 'keydown', function ( event ) {
			if ( event.target !== list ) {
				return;
			}
			var flip = rtl() ? -1 : 1;
			if ( 'ArrowRight' === event.key ) {
				go( flip );
			} else if ( 'ArrowLeft' === event.key ) {
				go( -flip );
			} else if ( 'Home' === event.key ) {
				list.scrollTo( { left: 0, behavior: reducedMotion() ? 'auto' : 'smooth' } );
			} else if ( 'End' === event.key ) {
				list.scrollTo( { left: flip * list.scrollWidth, behavior: reducedMotion() ? 'auto' : 'smooth' } );
			} else {
				return;
			}
			event.preventDefault();
		} );

		list.addEventListener( 'scroll', queue );
		window.addEventListener( 'resize', queue );
		update();
	}

	function init( root ) {
		root = root || document;
		var sections = root.classList && root.classList.contains( 'ffgh-reviews' ) ? [ root ] : root.querySelectorAll( '.ffgh-reviews' );

		Array.prototype.forEach.call( sections, function ( section ) {
			if ( section.getAttribute( 'data-ffgh-ready' ) ) {
				return;
			}
			section.setAttribute( 'data-ffgh-ready', '1' );

			// Photos that failed before this footer script ran.
			each( section, '.ffgh-review__photo', function ( img ) {
				if ( img.complete && 0 === img.naturalWidth ) {
					dropPhoto( img );
				}
			} );
			setupDates( section );
			setupMore( section );
			setupCarousel( section );
		} );
	}

	// Image errors don't bubble, so listen in the capture phase. The initials show underneath.
	document.addEventListener(
		'error',
		function ( event ) {
			var target = event.target;
			if ( target && target.classList && target.classList.contains( 'ffgh-review__photo' ) ) {
				dropPhoto( target );
			}
		},
		true
	);

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			init( document );
		} );
	} else {
		init( document );
	}

	// Elementor's editor adds widgets after the page has loaded.
	window.addEventListener( 'elementor/frontend/init', function () {
		var frontend = window.elementorFrontend;
		if ( frontend && frontend.hooks ) {
			frontend.hooks.addAction( 'frontend/element_ready/growthhub_reviews.default', function ( $scope ) {
				init( $scope && $scope[ 0 ] ? $scope[ 0 ] : document );
			} );
		}
	} );

	window.ffghReviews = { init: init, relativeDate: relativeDate };
} )();
