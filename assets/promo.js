/* FoundFirst Growth Hub promo banner: if a promo video can't play, show its thumbnail instead. No dependencies. */
( function () {
	'use strict';

	var SELECTOR = 'video.ffgh-promo__video';

	function swap( video ) {
		var box = video.parentNode;
		if ( ! box ) {
			return;
		}
		var fallback = box.querySelector( '.ffgh-promo__video-fallback' );
		if ( fallback ) {
			fallback.hidden = false;
		}
		box.removeChild( video );
	}

	function hasFailed( video ) {
		// 3 = NETWORK_NO_SOURCE.
		return !! video.error || 3 === video.networkState;
	}

	// Media errors don't bubble, so listen in the capture phase.
	document.addEventListener(
		'error',
		function ( event ) {
			var target = event.target;
			if ( target && target.matches && target.matches( SELECTOR ) ) {
				swap( target );
			}
		},
		true
	);

	// A video (e.g. autoplay) that failed before this footer script ran.
	function checkAll() {
		Array.prototype.forEach.call( document.querySelectorAll( SELECTOR ), function ( video ) {
			if ( hasFailed( video ) ) {
				swap( video );
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', checkAll );
	} else {
		checkAll();
	}
} )();
