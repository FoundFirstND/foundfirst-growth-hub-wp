// Tests for assets/reviews.js. Zero dependencies: node --test tests/js/
// Runs the script in a vm context with a tiny fake DOM (single-class selectors only).

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const SOURCE = readFileSync( new URL( '../../assets/reviews.js', import.meta.url ), 'utf8' );

class FakeElement {
	constructor( tag, className = '', props = {} ) {
		this.tagName = tag.toUpperCase();
		this.className = className;
		this.children = [];
		this.parentNode = null;
		this.hidden = false;
		this.attributes = {};
		this.listeners = {};
		this.textContent = '';
		Object.assign( this, props );
		const el = this;
		this.classList = {
			contains: ( c ) => el.className.split( /\s+/ ).includes( c ),
			add: ( c ) => {
				if ( ! el.classList.contains( c ) ) {
					el.className = ( el.className + ' ' + c ).trim();
				}
			},
			remove: ( c ) => {
				el.className = el.className.split( /\s+/ ).filter( ( x ) => x && x !== c ).join( ' ' );
			},
		};
	}

	append( child ) {
		child.parentNode = this;
		this.children.push( child );
		return child;
	}

	removeChild( child ) {
		this.children = this.children.filter( ( c ) => c !== child );
		child.parentNode = null;
	}

	get nextElementSibling() {
		if ( ! this.parentNode ) {
			return null;
		}
		const siblings = this.parentNode.children;
		return siblings[ siblings.indexOf( this ) + 1 ] || null;
	}

	getAttribute( name ) {
		return name in this.attributes ? this.attributes[ name ] : null;
	}

	setAttribute( name, value ) {
		this.attributes[ name ] = String( value );
	}

	addEventListener( type, fn ) {
		( this.listeners[ type ] ||= [] ).push( fn );
	}

	fire( type, props = {} ) {
		const event = { target: this, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...props };
		( this.listeners[ type ] || [] ).forEach( ( fn ) => fn( event ) );
		return event;
	}

	matches( selector ) {
		return this.classList.contains( selector.replace( /^\./, '' ) );
	}

	querySelectorAll( selector ) {
		const all = [];
		for ( const child of this.children ) {
			if ( child.matches( selector ) ) {
				all.push( child );
			}
			all.push( ...child.querySelectorAll( selector ) );
		}
		return all;
	}

	querySelector( selector ) {
		return this.querySelectorAll( selector )[ 0 ] || null;
	}
}

// A section like ReviewsRenderer outputs.
function section( { layout = 'carousel', photo = null, longText = null, dates = [ '2026-11-04' ] } = {} ) {
	const root = new FakeElement( 'section', `ffgh-reviews ffgh-reviews--${ layout } ffgh-reviews--light` );
	let parent = root;
	let controls = null;
	let list;

	if ( 'carousel' === layout ) {
		parent = root.append( new FakeElement( 'div', 'ffgh-reviews__carousel' ) );
		controls = parent.append( new FakeElement( 'div', 'ffgh-reviews__controls', { hidden: true } ) );
		controls.append( new FakeElement( 'button', 'ffgh-reviews__prev' ) );
		controls.append( new FakeElement( 'button', 'ffgh-reviews__next' ) );
	}
	list = parent.append(
		new FakeElement( 'ul', 'ffgh-reviews__list', {
			scrollLeft: 0,
			scrollWidth: 1000,
			clientWidth: 400,
			scrolls: [],
			scrollBy( opts ) {
				this.scrolls.push( [ 'by', { ...opts } ] );
			},
			scrollTo( opts ) {
				this.scrolls.push( [ 'to', { ...opts } ] );
			},
		} )
	);

	const cards = dates.map( ( date, i ) => {
		const card = list.append( new FakeElement( 'li', 'ffgh-review', { offsetLeft: i * 336 } ) );
		const head = card.append( new FakeElement( 'div', 'ffgh-review__head' ) );
		const avatar = head.append( new FakeElement( 'span', 'ffgh-review__avatar' ) );
		avatar.append( new FakeElement( 'span', 'ffgh-review__initials', { textContent: 'SO' } ) );
		if ( photo && 0 === i ) {
			avatar.append( new FakeElement( 'img', 'ffgh-review__photo', photo ) );
		}
		const time = head.append( new FakeElement( 'time', 'ffgh-review__date', { textContent: 'November 4, 2026' } ) );
		time.setAttribute( 'datetime', date );
		return card;
	} );

	let text = null;
	let more = null;
	if ( longText ) {
		text = cards[ 0 ].append( new FakeElement( 'div', 'ffgh-review__text ffgh-review__text--long', longText ) );
		more = cards[ 0 ].append( new FakeElement( 'button', 'ffgh-review__more', { hidden: true } ) );
		more.setAttribute( 'aria-expanded', 'false' );
		more.setAttribute( 'data-less', 'Show less' );
		more.append( new FakeElement( 'span', 'ffgh-review__more-label', { textContent: 'Read more' } ) );
	}

	return { root, list, controls, cards, text, more };
}

function run( page, { now = '2026-11-18T10:00:00', lang = 'en-US', readyState = 'complete', reducedMotion = false, direction = 'ltr' } = {} ) {
	const body = new FakeElement( 'body' );
	body.append( page.root );
	const docListeners = [];
	const winListeners = [];
	const RealDate = Date;
	const fixed = new RealDate( now );

	class FakeDate extends RealDate {
		constructor( ...args ) {
			super( ...( args.length ? args : [ fixed.getTime() ] ) );
		}
	}

	const document = {
		readyState,
		documentElement: { lang },
		addEventListener: ( type, fn, capture ) => docListeners.push( { type, fn, capture: !! capture } ),
		querySelectorAll: ( selector ) => body.querySelectorAll( selector ),
	};
	const window = {
		addEventListener: ( type, fn ) => winListeners.push( { type, fn } ),
		matchMedia: () => ( { matches: reducedMotion } ),
		getComputedStyle: () => ( { direction } ),
		requestAnimationFrame: ( fn ) => fn(),
	};
	const context = { document, window, Date: FakeDate, Intl, setTimeout };
	vm.runInNewContext( SOURCE, context );

	const fireDoc = ( type, target ) => docListeners.filter( ( l ) => l.type === type ).forEach( ( l ) => l.fn( { target } ) );
	const fireWin = ( type ) => winListeners.filter( ( l ) => l.type === type ).forEach( ( l ) => l.fn() );

	return { api: window.ffghReviews, window, docListeners, fireDoc, fireWin };
}

const minimal = () => run( { root: new FakeElement( 'section', 'ffgh-reviews' ) } ).api;

test( 'relative dates', () => {
	const { relativeDate } = minimal();
	const now = new Date( 2026, 10, 18, 22, 30 ); // 18 Nov 2026, late evening.
	const cases = {
		'2026-11-18': 'today',
		'2026-11-17': 'yesterday',
		'2026-11-15': '3 days ago',
		'2026-11-11': '1 week ago',
		'2026-11-04': '2 weeks ago',
		'2026-10-20': '4 weeks ago',
		'2026-10-18': '1 month ago',
		'2026-10-19': '1 month ago',
		'2026-05-01': '6 months ago',
		'2025-11-19': '11 months ago',
		'2025-11-18': '1 year ago',
		'2023-02-01': '3 years ago',
		'2026-11-19': 'today', // A day ahead (business timezone): never "in 1 day".
	};
	for ( const [ date, expected ] of Object.entries( cases ) ) {
		assert.equal( relativeDate( date, now, 'en-US' ), expected, date );
	}
} );

test( 'relative dates follow the page language and survive a bad one', () => {
	const { relativeDate } = minimal();
	const now = new Date( 2026, 10, 18 );

	assert.equal( relativeDate( '2026-11-04', now, 'de-DE' ), 'vor 2 Wochen' );
	assert.equal( relativeDate( '2026-11-04', now, 'not a language!!' ).length > 0, true );
	assert.equal( relativeDate( 'garbage', now, 'en-US' ), null );
	assert.equal( relativeDate( '', now, 'en-US' ), null );
} );

test( 'dates are replaced on load, with the full date kept as a tooltip', () => {
	const page = section( { dates: [ '2026-11-04', '2025-06-01' ] } );
	run( page );

	const times = page.root.querySelectorAll( '.ffgh-review__date' );
	assert.equal( times[ 0 ].textContent, '2 weeks ago' );
	assert.equal( times[ 0 ].getAttribute( 'title' ), 'November 4, 2026' );
	assert.equal( times[ 1 ].textContent, '1 year ago' );
} );

test( 'it waits for DOMContentLoaded while the page is loading, and runs once per section', () => {
	const page = section();
	const { fireDoc, api } = run( page, { readyState: 'loading' } );
	const time = page.root.querySelector( '.ffgh-review__date' );

	assert.equal( time.textContent, 'November 4, 2026' );
	fireDoc( 'DOMContentLoaded' );
	assert.equal( time.textContent, '2 weeks ago' );

	time.textContent = 'changed';
	api.init();
	assert.equal( time.textContent, 'changed', 'a second init leaves a ready section alone' );
} );

test( 'a photo that fails to load is removed so the initials show', () => {
	const page = section( { photo: { complete: false, naturalWidth: 0 } } );
	const { docListeners, fireDoc } = run( page );
	const avatar = page.root.querySelector( '.ffgh-review__avatar' );
	const img = avatar.querySelector( '.ffgh-review__photo' );

	assert.ok( docListeners.some( ( l ) => 'error' === l.type && l.capture ), 'listens for errors in the capture phase' );
	assert.equal( img.parentNode, avatar, 'still loading: kept' );

	fireDoc( 'error', new FakeElement( 'img', 'ffgh-promo__image' ) );
	assert.equal( img.parentNode, avatar, 'other images are left alone' );

	fireDoc( 'error', img );
	assert.equal( avatar.querySelector( '.ffgh-review__photo' ), null );
	assert.ok( avatar.querySelector( '.ffgh-review__initials' ) );
} );

test( 'a photo that already failed before the script ran is removed; a loaded one stays', () => {
	const failed = section( { photo: { complete: true, naturalWidth: 0 } } );
	run( failed );
	assert.equal( failed.root.querySelector( '.ffgh-review__photo' ), null );

	const loaded = section( { photo: { complete: true, naturalWidth: 96 } } );
	run( loaded );
	assert.ok( loaded.root.querySelector( '.ffgh-review__photo' ) );
} );

test( 'Read more clamps a long review and toggles it', () => {
	const page = section( { longText: { scrollHeight: 300, clientHeight: 120 } } );
	run( page );
	const label = page.more.querySelector( '.ffgh-review__more-label' );

	assert.equal( page.more.hidden, false );
	assert.ok( page.text.classList.contains( 'ffgh-review__text--clamped' ) );

	page.more.fire( 'click' );
	assert.equal( page.more.getAttribute( 'aria-expanded' ), 'true' );
	assert.equal( page.text.classList.contains( 'ffgh-review__text--clamped' ), false );
	assert.equal( label.textContent, 'Show less' );

	page.more.fire( 'click' );
	assert.equal( page.more.getAttribute( 'aria-expanded' ), 'false' );
	assert.ok( page.text.classList.contains( 'ffgh-review__text--clamped' ) );
	assert.equal( label.textContent, 'Read more' );
} );

test( 'a long review that fits after all gets no button', () => {
	const page = section( { longText: { scrollHeight: 100, clientHeight: 100 } } );
	run( page );

	assert.equal( page.more.hidden, true );
	assert.equal( page.text.classList.contains( 'ffgh-review__text--clamped' ), false );
} );

test( 'carousel: buttons appear, scroll one card, and are aria-disabled at the ends', () => {
	const page = section( { dates: [ '2026-11-04', '2026-11-03', '2026-11-02' ] } );
	run( page );
	const prev = page.controls.querySelector( '.ffgh-reviews__prev' );
	const next = page.controls.querySelector( '.ffgh-reviews__next' );

	assert.equal( page.controls.hidden, false );
	assert.equal( prev.getAttribute( 'aria-disabled' ), 'true' );
	assert.equal( next.getAttribute( 'aria-disabled' ), 'false' );

	prev.fire( 'click' );
	assert.equal( page.list.scrolls.length, 0, 'a disabled button does nothing' );

	next.fire( 'click' );
	assert.deepEqual( page.list.scrolls[ 0 ], [ 'by', { left: 336, behavior: 'smooth' } ] );

	page.list.scrollLeft = 600;
	page.list.fire( 'scroll' );
	assert.equal( prev.getAttribute( 'aria-disabled' ), 'false' );
	assert.equal( next.getAttribute( 'aria-disabled' ), 'true' );

	prev.fire( 'click' );
	assert.deepEqual( page.list.scrolls[ 1 ], [ 'by', { left: -336, behavior: 'smooth' } ] );
} );

test( 'carousel: arrow keys, Home and End on the focused list', () => {
	const page = section( { dates: [ '2026-11-04', '2026-11-03' ] } );
	run( page, { reducedMotion: true } );

	assert.ok( page.list.fire( 'keydown', { key: 'ArrowRight' } ).defaultPrevented );
	assert.deepEqual( page.list.scrolls[ 0 ], [ 'by', { left: 336, behavior: 'auto' } ] );
	page.list.fire( 'keydown', { key: 'ArrowLeft' } );
	assert.deepEqual( page.list.scrolls[ 1 ], [ 'by', { left: -336, behavior: 'auto' } ] );
	page.list.fire( 'keydown', { key: 'End' } );
	assert.deepEqual( page.list.scrolls[ 2 ], [ 'to', { left: 1000, behavior: 'auto' } ] );
	page.list.fire( 'keydown', { key: 'Home' } );
	assert.deepEqual( page.list.scrolls[ 3 ], [ 'to', { left: 0, behavior: 'auto' } ] );

	assert.equal( page.list.fire( 'keydown', { key: 'Tab' } ).defaultPrevented, false );
	assert.equal( page.list.fire( 'keydown', { key: 'ArrowRight', target: page.more || new FakeElement( 'button', 'x' ) } ).defaultPrevented, false, 'keys inside a card are left alone' );
	assert.equal( page.list.scrolls.length, 4 );
} );

test( 'carousel: right-to-left pages scroll the other way', () => {
	const page = section( { dates: [ '2026-11-04', '2026-11-03' ] } );
	run( page, { direction: 'rtl' } );

	page.list.fire( 'keydown', { key: 'ArrowLeft' } );
	assert.deepEqual( page.list.scrolls[ 0 ], [ 'by', { left: -336, behavior: 'smooth' } ] );
} );

test( 'carousel: no buttons when every card fits', () => {
	const page = section( { dates: [ '2026-11-04' ] } );
	page.list.scrollWidth = 400;
	run( page );

	assert.equal( page.controls.hidden, true );
} );

test( 'grid and list layouts have no carousel and still get dates', () => {
	for ( const layout of [ 'grid', 'list' ] ) {
		const page = section( { layout } );
		assert.doesNotThrow( () => run( page ) );
		assert.equal( page.root.querySelector( '.ffgh-review__date' ).textContent, '2 weeks ago' );
	}
} );

test( 'Elementor editor: widgets added later are set up', () => {
	const page = section();
	const later = section();
	const { window, fireWin } = run( page );
	const actions = {};
	window.elementorFrontend = { hooks: { addAction: ( name, fn ) => ( actions[ name ] = fn ) } };

	fireWin( 'elementor/frontend/init' );
	actions[ 'frontend/element_ready/growthhub_reviews.default' ]( [ later.root ] );

	assert.equal( later.root.querySelector( '.ffgh-review__date' ).textContent, '2 weeks ago' );
} );
