// Tests for assets/tracking.js. Zero dependencies: node --test tests/js/
// Runs the script in a vm context with a tiny fake DOM, fake jQuery and a controllable clock.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const SOURCE = readFileSync( new URL( '../../assets/tracking.js', import.meta.url ), 'utf8' );

// --- Fake DOM -----------------------------------------------------------------------------

class FakeElement {
	constructor( tag, attrs = {}, parent = null ) {
		this.nodeType = 1;
		this.tagName = tag.toUpperCase();
		this.attrs = attrs;
		this.parentElement = parent;
	}

	getAttribute( name ) {
		return Object.prototype.hasOwnProperty.call( this.attrs, name ) ? String( this.attrs[ name ] ) : null;
	}

	matches( selector ) {
		return selector.split( ',' ).some( ( part ) => matchesSimple( this, part.trim() ) );
	}

	closest( selector ) {
		for ( let el = this; el; el = el.parentElement ) {
			if ( el.matches( selector ) ) {
				return el;
			}
		}
		return null;
	}

	child( tag, attrs = {} ) {
		return new FakeElement( tag, attrs, this );
	}
}

// Supports tag, .class, [attr] and [attr="value"], combined (enough for tracking.js).
function matchesSimple( el, selector ) {
	const re = /^([a-z]+)?((?:\.[\w-]+|\[[\w-]+(?:="[^"]*")?\])*)$/i;
	const m = selector.match( re );
	if ( ! m ) {
		throw new Error( `Unsupported selector in fake DOM: ${ selector }` );
	}
	if ( m[ 1 ] && m[ 1 ].toUpperCase() !== el.tagName ) {
		return false;
	}
	const classes = ( el.getAttribute( 'class' ) || '' ).split( /\s+/ );
	for ( const [ , cls, attr, value ] of m[ 2 ].matchAll( /\.([\w-]+)|\[([\w-]+)(?:="([^"]*)")?\]/g ) ) {
		if ( cls && ! classes.includes( cls ) ) {
			return false;
		}
		if ( attr ) {
			const actual = el.getAttribute( attr );
			if ( null === actual || ( undefined !== value && actual !== value ) ) {
				return false;
			}
		}
	}
	return true;
}

// --- Harness ------------------------------------------------------------------------------

function load( { gtm = false, gtag = false, jquery = true, path = '/contact/' } = {} ) {
	const clock = { now: 1_000_000 };
	const listeners = [];
	const jqHandlers = {};
	const gtagCalls = [];

	const document = {
		body: new FakeElement( 'body' ),
		addEventListener: ( type, fn, capture ) => listeners.push( { type, fn, capture } ),
	};

	const window = {
		location: { pathname: path, search: '?email=jane%40example.com', href: `https://example.test${ path }?email=jane%40example.com` },
	};
	if ( gtm ) {
		window.google_tag_manager = { 'GTM-TEST123': {}, 'G-ABC': {} };
	} else {
		// GTM's object can exist with only gtag.js destinations (G-...): that's not a GTM container.
		window.google_tag_manager = { 'G-ABC': {} };
	}
	if ( gtag ) {
		window.gtag = ( ...args ) => gtagCalls.push( args );
	}
	if ( jquery ) {
		window.jQuery = ( target ) => ( {
			on( type, fn ) {
				assert.equal( target, document, 'submit_success is delegated on document' );
				( jqHandlers[ type ] ||= [] ).push( fn );
			},
		} );
	}

	const context = vm.createContext( { window, document, Date: { now: () => clock.now } } );
	vm.runInContext( SOURCE, context );

	return {
		window,
		document,
		clock,
		// Plain copies: objects created inside the vm context have that realm's prototypes.
		get gtagCalls() {
			return plain( gtagCalls );
		},
		get dataLayer() {
			return plain( window.dataLayer || [] );
		},
		click( target ) {
			for ( const l of listeners.filter( ( x ) => 'click' === x.type ) ) {
				l.fn( { target } );
			}
		},
		listeners,
		submitSuccess( form, response ) {
			for ( const fn of jqHandlers.submit_success || [] ) {
				fn( { target: form }, response );
			}
		},
		jqHandlers,
	};
}

function plain( value ) {
	return JSON.parse( JSON.stringify( value ) );
}

const PHONE = '+17015550100';
const EMAIL = 'jane@example.com';

function forwardedResponse( formName = 'Contact Us' ) {
	return { message: 'Thanks!', data: { ffgh_ga: { form_name: formName } } };
}

function assertNoPii( entries ) {
	const json = JSON.stringify( entries );
	for ( const secret of [ PHONE, '7015550100', '555-0100', EMAIL, 'jane', 'Jane Doe', 'email=' ] ) {
		assert.ok( ! json.includes( secret ), `payload must not contain ${ secret }: ${ json }` );
	}
}

// --- Call clicks --------------------------------------------------------------------------

test( 'tel: click pushes click_to_call once, with location and path only', () => {
	const t = load();
	const header = t.document.body.child( 'header' );
	const link = header.child( 'a', { href: `tel:${ PHONE }` } );
	const span = link.child( 'span' ); // Elementor buttons wrap the text in spans.

	t.click( span );

	assert.deepEqual( t.dataLayer, [ { event: 'click_to_call', link_location: 'header', page_path: '/contact/' } ] );
	assert.ok( t.listeners.some( ( l ) => 'click' === l.type && true === l.capture ), 'listens in the capture phase' );
	assertNoPii( t.dataLayer );
} );

test( 'link_location: footer, Elementor header/footer/popup, labelled section, else page', () => {
	const t = load();
	const body = t.document.body;
	const cases = [
		[ body.child( 'footer' ), 'footer' ],
		[ body.child( 'div', { 'data-elementor-type': 'header' } ), 'header' ],
		[ body.child( 'div', { class: 'elementor elementor-location-footer' } ), 'footer' ],
		[ body.child( 'div', { class: 'elementor-popup-modal' } ), 'popup' ],
		[ body.child( 'section', { 'aria-label': '  Call   us today ' } ), 'Call us today' ],
		[ body.child( 'div' ), 'page' ],
	];

	for ( const [ container, expected ] of cases ) {
		t.click( container.child( 'a', { href: `TEL:${ PHONE }` } ) );
		assert.equal( t.dataLayer.at( -1 ).link_location, expected );
	}
	assert.equal( t.dataLayer.length, cases.length );
	assertNoPii( t.dataLayer );
} );

test( 'non-tel links and non-link clicks send nothing', () => {
	const t = load();
	t.click( t.document.body.child( 'a', { href: 'mailto:jane@example.com' } ) );
	t.click( t.document.body.child( 'a', { href: '/contact' } ) );
	t.click( t.document.body.child( 'button' ) );

	assert.deepEqual( t.dataLayer, [] );
} );

test( 'debounce: same link within 1 s sends once, after 1 s sends again; other links are independent', () => {
	const t = load();
	const link = t.document.body.child( 'a', { href: `tel:${ PHONE }` } );
	const other = t.document.body.child( 'a', { href: `tel:${ PHONE }` } );

	t.click( link );
	t.clock.now += 500;
	t.click( link );
	t.click( other );
	assert.equal( t.dataLayer.length, 2 );

	t.clock.now += 1000;
	t.click( link );
	assert.equal( t.dataLayer.length, 3 );
} );

// --- Form leads ---------------------------------------------------------------------------

test( 'Elementor submit_success on a forwarded form pushes generate_lead', () => {
	const t = load();
	const form = t.document.body.child( 'form' );

	t.submitSuccess( form, forwardedResponse() );

	assert.deepEqual( t.dataLayer, [ { event: 'generate_lead', form_name: 'Contact Us', page_path: '/contact/' } ] );
	assertNoPii( t.dataLayer );
} );

test( 'failure sends nothing: Elementor fires only "error" on a failed submission', () => {
	const t = load();

	// The script never listens for error/submit/click on forms, only submit_success.
	assert.deepEqual( Object.keys( t.jqHandlers ), [ 'submit_success' ] );
	t.click( t.document.body.child( 'form' ).child( 'button', { type: 'submit' } ) );
	assert.deepEqual( t.dataLayer, [] );
} );

test( 'a form without Send to Growth Hub (no ffgh_ga flag) sends nothing', () => {
	const t = load();
	const form = t.document.body.child( 'form' );

	t.submitSuccess( form, { message: 'Thanks!', data: {} } );
	t.submitSuccess( form, { message: 'Thanks!', data: { redirect_url: '/thanks' } } );
	t.submitSuccess( form, undefined );

	assert.deepEqual( t.dataLayer, [] );
} );

test( 'debounce: duplicate submit_success for the same form within 1 s sends once', () => {
	const t = load();
	const form = t.document.body.child( 'form' );

	t.submitSuccess( form, forwardedResponse() );
	t.submitSuccess( form, forwardedResponse() );
	t.clock.now += 1001;
	t.submitSuccess( form, forwardedResponse() );

	assert.equal( t.dataLayer.length, 2 );
} );

test( 'form_name is clipped to 100 characters', () => {
	const t = load();
	t.submitSuccess( t.document.body.child( 'form' ), forwardedResponse( 'x'.repeat( 150 ) ) );

	assert.equal( t.dataLayer[ 0 ].form_name.length, 100 );
} );

test( 'without jQuery, call clicks still work and nothing throws', () => {
	const t = load( { jquery: false } );
	t.click( t.document.body.child( 'a', { href: `tel:${ PHONE }` } ) );

	assert.equal( t.dataLayer.length, 1 );
} );

// --- gtag vs GTM --------------------------------------------------------------------------

test( 'GTM container present: dataLayer only, gtag is not called', () => {
	const t = load( { gtm: true, gtag: true } );
	t.click( t.document.body.child( 'a', { href: `tel:${ PHONE }` } ) );
	t.submitSuccess( t.document.body.child( 'form' ), forwardedResponse() );

	assert.equal( t.dataLayer.length, 2 );
	assert.deepEqual( t.gtagCalls, [] );
} );

test( 'gtag only (no GTM container): gtag is called once per event, dataLayer also gets it', () => {
	const t = load( { gtag: true } );
	t.click( t.document.body.child( 'a', { href: `tel:${ PHONE }` } ) );
	t.submitSuccess( t.document.body.child( 'form' ), forwardedResponse() );

	assert.deepEqual( t.gtagCalls, [
		[ 'event', 'click_to_call', { link_location: 'page', page_path: '/contact/' } ],
		[ 'event', 'generate_lead', { form_name: 'Contact Us', page_path: '/contact/' } ],
	] );
	assert.equal( t.dataLayer.length, 2 );
	assertNoPii( [ t.gtagCalls, t.dataLayer ] );
} );

test( 'GTM detected at send time, so a container that loads later still prevents double sends', () => {
	const t = load( { gtag: true } );
	t.window.google_tag_manager[ 'GTM-LATE' ] = {};
	t.click( t.document.body.child( 'a', { href: `tel:${ PHONE }` } ) );

	assert.deepEqual( t.gtagCalls, [] );
	assert.equal( t.dataLayer.length, 1 );
} );

test( 'keeps an existing dataLayer and loads only once', () => {
	const t = load();
	t.window.dataLayer = [ { existing: true } ];
	vm.runInContext( SOURCE, vm.createContext( { window: t.window, document: t.document, Date } ) );
	t.click( t.document.body.child( 'a', { href: `tel:${ PHONE }` } ) );

	assert.equal( t.dataLayer.length, 2 );
	assert.deepEqual( t.dataLayer[ 0 ], { existing: true } );
} );
