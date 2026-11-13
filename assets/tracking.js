/**
 * FoundFirst Growth Hub: Google Analytics events.
 *
 * - click_to_call: any tel: link (delegated on document, so links added later count too).
 *   Params: link_location, page_path. The phone number is never read.
 * - generate_lead: Elementor Pro's submit_success for forms that have "Send to Growth Hub"
 *   (the server adds data.ffgh_ga to those responses only). Params: form_name, page_path.
 *   Field values are never read.
 *
 * Always pushes to window.dataLayer. Also calls gtag() when the site has gtag but no GTM
 * container, so gtag-only sites record it without double sends on GTM sites.
 * No requests, cookies or storage of its own: the site's consent mode applies as configured.
 */
( function ( window, document ) {
	'use strict';

	if ( window.ffghTracking ) {
		return;
	}
	window.ffghTracking = true;

	var DEBOUNCE_MS = 1000;
	var MAX_LENGTH = 100;

	function now() {
		return Date.now();
	}

	function clip( value ) {
		return String( value ).replace( /\s+/g, ' ' ).trim().slice( 0, MAX_LENGTH );
	}

	// Path only: query strings can carry personal data (e.g. ?email= after a redirect).
	function pagePath() {
		return ( window.location && window.location.pathname ) || '/';
	}

	function hasGtm() {
		var containers = window.google_tag_manager;
		if ( ! containers || 'object' !== typeof containers ) {
			return false;
		}
		for ( var key in containers ) {
			if ( Object.prototype.hasOwnProperty.call( containers, key ) && 0 === key.indexOf( 'GTM-' ) ) {
				return true;
			}
		}
		return false;
	}

	function send( name, params ) {
		try {
			var entry = { event: name };
			for ( var key in params ) {
				entry[ key ] = params[ key ];
			}
			window.dataLayer = window.dataLayer || [];
			window.dataLayer.push( entry );

			if ( ! hasGtm() && 'function' === typeof window.gtag ) {
				window.gtag( 'event', name, params );
			}
		} catch ( e ) {
			// Never break the page.
		}
	}

	// One event per element per second (double taps, duplicate handlers).
	var lastSent = 'function' === typeof WeakMap ? new WeakMap() : null;
	function debounced( element ) {
		if ( ! lastSent || ! element || 'object' !== typeof element ) {
			return false;
		}
		var time = now();
		var last = lastSent.get( element );
		if ( undefined !== last && time - last < DEBOUNCE_MS ) {
			return true;
		}
		lastSent.set( element, time );
		return false;
	}

	function closest( element, selector ) {
		return element && 'function' === typeof element.closest ? element.closest( selector ) : null;
	}

	function linkLocation( link ) {
		if ( closest( link, 'header, [data-elementor-type="header"], .elementor-location-header' ) ) {
			return 'header';
		}
		if ( closest( link, 'footer, [data-elementor-type="footer"], .elementor-location-footer' ) ) {
			return 'footer';
		}
		if ( closest( link, '.elementor-popup-modal' ) ) {
			return 'popup';
		}
		var section = closest( link, 'section[aria-label], [role="region"][aria-label]' );
		var label = section ? clip( section.getAttribute( 'aria-label' ) || '' ) : '';
		return '' !== label ? label : 'page';
	}

	function onClick( event ) {
		try {
			var target = event.target;
			if ( target && 1 !== target.nodeType ) {
				target = target.parentElement;
			}
			var link = closest( target, 'a[href]' );
			if ( ! link ) {
				return;
			}
			var href = String( link.getAttribute( 'href' ) || '' ).trim().toLowerCase();
			if ( 0 !== href.indexOf( 'tel:' ) || debounced( link ) ) {
				return;
			}
			send( 'click_to_call', { link_location: linkLocation( link ), page_path: pagePath() } );
		} catch ( e ) {
			// Never break the page.
		}
	}

	// Capture phase, so handlers that stop propagation don't hide the click.
	document.addEventListener( 'click', onClick, true );

	// Elementor Pro triggers submit_success with jQuery, which never dispatches a native DOM event,
	// so this needs jQuery. Elementor forms always load it; pages without one don't need it.
	var jq = window.jQuery;
	if ( jq && 'function' === typeof jq ) {
		jq( document ).on( 'submit_success', function ( event, response ) {
			try {
				var flag = response && response.data && response.data.ffgh_ga;
				if ( ! flag || 'object' !== typeof flag || debounced( event.target ) ) {
					return;
				}
				send( 'generate_lead', { form_name: clip( flag.form_name || 'elementor-form' ), page_path: pagePath() } );
			} catch ( e ) {
				// Never break the page.
			}
		} );
	}
}( window, document ) );
